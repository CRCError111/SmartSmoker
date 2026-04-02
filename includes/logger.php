<?php
/**
 * Система логирования событий и ошибок
 * ИСПРАВЛЕНА: Защита от бесконечного цикла логирования
 * 
 * @version 1.1
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

/**
 * Класс логирования
 */
class Logger {
    /**
     * @var string Путь к файлу лога
     */
    private $logFile;
    
    /**
     * @var int Максимальный размер файла (в байтах)
     */
    private $maxSize;
    
    /**
     * @var array Уровни логирования
     */
    private $levels = [
        'DEBUG' => 100,
        'INFO' => 200,
        'WARNING' => 300,
        'ERROR' => 400,
        'CRITICAL' => 500
    ];
    
    /**
     * @var string Минимальный уровень логирования
     */
    private $minLevel = 'INFO';
    
    /**
     * @var bool Флаг, чтобы избежать рекурсии при ошибках логирования
     */
    private $isLogging = false;
    
    /**
     * Конструктор
     * 
     * @param string $logFile Путь к файлу лога
     * @param int $maxSize Максимальный размер файла
     * @param string $minLevel Минимальный уровень логирования
     */
    public function __construct($logFile = LOG_FILE, $maxSize = LOG_MAX_SIZE, $minLevel = 'INFO') {
        $this->logFile = $logFile;
        $this->maxSize = $maxSize;
        $this->minLevel = $minLevel;
        
        // Создание директории для логов, если не существует
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
    }
    
    /**
     * Запись сообщения в лог
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param string $context Контекст (опционально)
     * @return bool
     */
    public function log($level, $message, $context = '') {
        // Защита от рекурсии
        if ($this->isLogging) {
            return false;
        }
        
        $this->isLogging = true;
        
        try {
            // Проверка уровня логирования
            if (!$this->shouldLog($level)) {
                $this->isLogging = false;
                return false;
            }
            
            // Форматирование сообщения
            $timestamp = date('Y-m-d H:i:s');
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'CLI';
            $script = $_SERVER['SCRIPT_NAME'] ?? 'CLI';
            
            $logMessage = sprintf(
                "[%s] [%s] [%s] [%s] %s | Context: %s\n",
                $timestamp,
                $level,
                $ip,
                $script,
                $message,
                $context
            );
            
            // Проверка размера файла и ротация при необходимости
            $this->rotateLogFile();
            
            // Запись в файл (с подавлением ошибок)
            @file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
            
            // Запись в БД для критических ошибок (только если не ошибка БД)
            if (in_array($level, ['ERROR', 'CRITICAL']) && $context !== 'DATABASE') {
                $this->logToDatabase($level, $message, $context);
            }
            
            $this->isLogging = false;
            return true;
            
        } catch (Exception $e) {
            $this->isLogging = false;
            return false;
        }
    }
    
    /**
     * Проверка, должен ли сообщение быть залогировано
     * 
     * @param string $level Уровень сообщения
     * @return bool
     */
    private function shouldLog($level) {
        return $this->levels[$level] >= $this->levels[$this->minLevel];
    }
    
    /**
     * Ротация лог-файла при превышении размера
     */
    private function rotateLogFile() {
        if (!file_exists($this->logFile)) {
            return;
        }
        
        $fileSize = filesize($this->logFile);
        
        if ($fileSize > $this->maxSize) {
            $archiveFile = $this->logFile . '.' . date('Y-m-d-H-i-s') . '.gz';
            
            // Сжатие и архивация старого файла
            $content = @file_get_contents($this->logFile);
            if ($content !== false) {
                $compressed = gzencode($content);
                
                if (@file_put_contents($archiveFile, $compressed)) {
                    // Очистка старого файла
                    @file_put_contents($this->logFile, '');
                    
                    // Удаление файлов старше 30 дней
                    $this->cleanupOldLogs();
                }
            }
        }
    }
    
    /**
     * Очистка старых лог-файлов (старше 30 дней)
     */
    private function cleanupOldLogs() {
        $logDir = dirname($this->logFile);
        $files = glob($logDir . '/*.gz');
        
        foreach ($files as $file) {
            $fileTime = @filemtime($file);
            if ($fileTime !== false) {
                $age = (time() - $fileTime) / (60 * 60 * 24); // возраст в днях
                
                if ($age > 30) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * Запись критической ошибки в базу данных
     * 
     * @param string $level Уровень логирования
     * @param string $message Сообщение
     * @param string $context Контекст
     */
    private function logToDatabase($level, $message, $context) {
        try {
            // Проверка, доступна ли функция db()
            if (!function_exists('db')) {
                return;
            }
            
            $db = db();
            
            // Подготовка контекста как JSON
            $contextData = [
                'message' => $message,
                'context' => $context,
                'server' => [
                    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
                    'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? null,
                    'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ],
                'session' => isset($_SESSION) ? $_SESSION : null
            ];
            
            $db->insert('logs', [
                'level' => $level,
                'message' => $message,
                'context' => json_encode($contextData, JSON_UNESCAPED_UNICODE),
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
        } catch (Exception $e) {
            // Если не удалось записать в БД, просто игнорируем
            // (иначе может возникнуть бесконечная рекурсия)
        }
    }
    
    /**
     * Логирование отладочной информации
     * 
     * @param string $message Сообщение
     * @param string $context Контекст
     */
    public function writeDebug($message, $context = '') {
        $this->log('DEBUG', $message, $context);
    }
    
    /**
     * Логирование информационного сообщения
     * 
     * @param string $message Сообщение
     * @param string $context Контекст
     */
    public function writeInfo($message, $context = '') {
        $this->log('INFO', $message, $context);
    }
    
    /**
     * Логирование предупреждения
     * 
     * @param string $message Сообщение
     * @param string $context Контекст
     */
    public function writeWarning($message, $context = '') {
        $this->log('WARNING', $message, $context);
    }
    
    /**
     * Логирование ошибки
     * 
     * @param string $message Сообщение
     * @param string $context Контекст
     */
    public function writeError($message, $context = '') {
        $this->log('ERROR', $message, $context);
    }
    
    /**
     * Логирование критической ошибки
     * 
     * @param string $message Сообщение
     * @param string $context Контекст
     */
    public function writeCritical($message, $context = '') {
        $this->log('CRITICAL', $message, $context);
    }
    
    /**
     * Логирование исключения
     * 
     * @param Exception $e Исключение
     * @param string $context Контекст
     */
    public function exception($e, $context = '') {
        $message = sprintf(
            "Exception: %s | File: %s | Line: %d | Trace: %s",
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        $this->log('CRITICAL', $message, $context);
    }

    // =====================================================
    // Статические методы-обёртки (для совместимости)
    // =====================================================

    public static function info($message, $context = []) {
        $msg = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        $ctx = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : (string)$context;
        logMessage('INFO', $msg . ($ctx !== '[]' ? ' | ' . $ctx : ''), 'SYSTEM');
    }

    public static function warning($message, $context = []) {
        $msg = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        $ctx = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : (string)$context;
        logMessage('WARNING', $msg . ($ctx !== '[]' ? ' | ' . $ctx : ''), 'SYSTEM');
    }

    public static function error($message, $context = []) {
        $msg = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        $ctx = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : (string)$context;
        logMessage('ERROR', $msg . ($ctx !== '[]' ? ' | ' . $ctx : ''), 'SYSTEM');
    }

    public static function debug($message, $context = []) {
        $msg = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        $ctx = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : (string)$context;
        logMessage('DEBUG', $msg . ($ctx !== '[]' ? ' | ' . $ctx : ''), 'SYSTEM');
    }

    public static function critical($message, $context = []) {
        $msg = is_string($message) ? $message : json_encode($message, JSON_UNESCAPED_UNICODE);
        $ctx = is_array($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : (string)$context;
        logMessage('CRITICAL', $msg . ($ctx !== '[]' ? ' | ' . $ctx : ''), 'SYSTEM');
    }
    
    /**
     * Получить путь к файлу лога
     * 
     * @return string
     */
    public function getLogFile() {
        return $this->logFile;
    }
    
    /**
     * Установить минимальный уровень логирования
     * 
     * @param string $level
     */
    public function setMinLevel($level) {
        if (isset($this->levels[$level])) {
            $this->minLevel = $level;
        }
    }
}

/**
 * Глобальная функция логирования
 * 
 * @param string $level Уровень логирования
 * @param string $message Сообщение
 * @param string $component Компонент системы
 * @return bool
 */
function logMessage($level, $message, $component = 'SYSTEM') {
    static $logger = null;
    
    // Защита от рекурсии при инициализации логгера
    static $initializing = false;
    
    if ($initializing) {
        return false;
    }
    
    if ($logger === null) {
        $initializing = true;
        $logger = new Logger();
        $initializing = false;
    }
    
    $fullMessage = "[$component] $message";
    
    return $logger->log($level, $fullMessage);
}

/**
 * Логирование отладочной информации
 */
function logDebug($message, $component = 'SYSTEM') {
    return logMessage('DEBUG', $message, $component);
}

/**
 * Логирование информационного сообщения
 */
function logInfo($message, $component = 'SYSTEM') {
    return logMessage('INFO', $message, $component);
}

/**
 * Логирование предупреждения
 */
function logWarning($message, $component = 'SYSTEM') {
    return logMessage('WARNING', $message, $component);
}

/**
 * Логирование ошибки
 */
function logError($message, $component = 'SYSTEM') {
    return logMessage('ERROR', $message, $component);
}

/**
 * Логирование критической ошибки
 */
function logCritical($message, $component = 'SYSTEM') {
    return logMessage('CRITICAL', $message, $component);
}

/**
 * Логирование исключения
 */
function logException($e, $component = 'SYSTEM') {
    static $logger = null;
    
    if ($logger === null) {
        $logger = new Logger();
    }
    
    $logger->exception($e, $component);
}

// =====================================================
// Регистрация обработчика ошибок
// =====================================================

/**
 * Обработчик ошибок PHP
 */
function errorHandler($errno, $errstr, $errfile, $errline) {
    // Игнорировать подавленные ошибки (@)
    if (error_reporting() === 0) {
        return false;
    }
    
    // Преобразование уровня ошибки в строку
    $errorType = '';
    switch ($errno) {
        case E_ERROR:
        case E_CORE_ERROR:
        case E_COMPILE_ERROR:
        case E_USER_ERROR:
            $errorType = 'CRITICAL';
            break;
        case E_WARNING:
        case E_CORE_WARNING:
        case E_COMPILE_WARNING:
        case E_USER_WARNING:
            $errorType = 'ERROR';
            break;
        case E_NOTICE:
        case E_USER_NOTICE:
            $errorType = 'WARNING';
            break;
        case E_DEPRECATED:
        case E_USER_DEPRECATED:
            $errorType = 'INFO';
            break;
        default:
            $errorType = 'ERROR';
    }
    
    $message = sprintf('PHP Error [%s]: %s in %s on line %d', $errorType, $errstr, $errfile, $errline);
    logMessage($errorType, $message, 'PHP');
    
    // Не прерывать выполнение для не-критических ошибок
    return ($errno !== E_ERROR && $errno !== E_CORE_ERROR && $errno !== E_COMPILE_ERROR);
}

/**
 * Обработчик фатальных ошибок
 */
function fatalErrorHandler() {
    $error = error_get_last();
    
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $message = sprintf(
            'Fatal Error: %s in %s on line %d',
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        // Логируем напрямую в файл, чтобы избежать рекурсии
        $logFile = LOG_DIR . '/app_' . date('Y-m-d') . '.log';
        $logMessage = sprintf(
            "[%s] [CRITICAL] [CLI] [CLI] Fatal Error: %s in %s on line %d\n",
            date('Y-m-d H:i:s'),
            $error['message'],
            $error['file'],
            $error['line']
        );
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}

// Регистрация обработчиков
set_error_handler('errorHandler');
register_shutdown_function('fatalErrorHandler');

// =====================================================
// Завершение файла
// =====================================================