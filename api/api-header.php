<?php
// Защита от рекурсивного вызова
if (isset($GLOBALS['api_processing'])) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['success' => false, 'error' => 'Рекурсивный вызов запрещен']);
    exit;
}
$GLOBALS['api_processing'] = true;

// Отключаем вывод ошибок (чтобы не нарушать JSON)
ini_set('display_errors', 0);
error_reporting(E_ALL); // Включаем все ошибки для логирования

// Настройка логирования ошибок
ini_set('log_errors', 1);
if (defined('LOG_FILE')) {
    ini_set('error_log', LOG_FILE);
}

// Разрешаем CORS только если заголовки еще не отправлены
if (!headers_sent()) {
    // Для сессионной аутентификации — ограничиваем origin
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = ['https://crcerror.ru', 'http://crcerror.ru'];
    if (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        // Для API устройств (токен в заголовке) — wildcard допустим
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    header('Content-Type: application/json; charset=utf-8');
}

// Обработка OPTIONS запроса
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    if (!headers_sent()) {
        http_response_code(200);
    }
    exit;
}

// Настройка error handler для логирования PHP ошибок
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Логируем ошибку через error_log
    $errorTypes = [
        E_ERROR => 'ERROR',
        E_WARNING => 'WARNING',
        E_PARSE => 'PARSE',
        E_NOTICE => 'NOTICE',
        E_CORE_ERROR => 'CORE_ERROR',
        E_CORE_WARNING => 'CORE_WARNING',
        E_COMPILE_ERROR => 'COMPILE_ERROR',
        E_COMPILE_WARNING => 'COMPILE_WARNING',
        E_USER_ERROR => 'USER_ERROR',
        E_USER_WARNING => 'USER_WARNING',
        E_USER_NOTICE => 'USER_NOTICE',
        E_STRICT => 'STRICT',
        E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        E_DEPRECATED => 'DEPRECATED',
        E_USER_DEPRECATED => 'USER_DEPRECATED'
    ];
    
    $errorType = $errorTypes[$errno] ?? 'UNKNOWN';
    
    $logMessage = sprintf(
        "[%s] PHP %s: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $errorType,
        $errstr,
        $errfile,
        $errline
    );
    
    error_log($logMessage);
    
    // Если доступен Logger класс, используем его
    if (class_exists('Logger')) {
        Logger::error('PHP Error', [
            'type' => $errorType,
            'message' => $errstr,
            'file' => $errfile,
            'line' => $errline,
            'errno' => $errno
        ]);
    }
    
    // Не выводим в output (чтобы не нарушать JSON)
    return true;
});

// Настройка exception handler для необработанных исключений
set_exception_handler(function($exception) {
    $logMessage = sprintf(
        "[%s] Uncaught Exception: %s in %s on line %d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    
    error_log($logMessage);
    
    // Если доступен Logger класс, используем его
    if (class_exists('Logger')) {
        Logger::error('Uncaught Exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
            'code' => $exception->getCode()
        ]);
    }
    
    // Возврат JSON ошибки клиенту
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    exit;
});
?>