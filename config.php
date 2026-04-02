<?php
/**
 * Конфигурация проекта "Умная коптильня"
 * ИСПРАВЛЕНО: Пути к директориям, защита от рекурсии, правильный путь к .env
 * 
 * @version 1.6
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

// =====================================================
// 1. Настройки базы данных
// =====================================================

// Загрузка переменных окружения из .env файла
$envFile = __DIR__ . '/.env';
if (!file_exists($envFile)) {
    // Попробуем альтернативный путь
    $envFile = '/var/www/u3385152/data/.env';
}
if (file_exists($envFile)) {
    $envContent = file_get_contents($envFile);
    $envLines = explode("\n", $envContent);
    
    foreach ($envLines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Удалить кавычки, если есть
            $value = trim($value, '"\'');
            
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
} else {
    // Файл .env не найден - критическая ошибка
    error_log("CRITICAL: .env file not found at $envFile");
    die("Ошибка конфигурации: файл .env не найден. Обратитесь к администратору.");
}

// Параметры подключения к базе данных
define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? 'u3385152_default');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');
define('DB_CHARSET', $_ENV['DB_CHARSET'] ?? 'utf8mb4');

// Проверка обязательных параметров БД
if (empty(DB_USER) || empty(DB_PASS)) {
    error_log("CRITICAL: Database credentials are missing or empty");
    die("Ошибка конфигурации: отсутствуют учётные данные базы данных. Проверьте файл .env");
}

// =====================================================
// 2. Настройки приложения
// =====================================================

// Базовый путь к проекту (ИСПРАВЛЕНО!)
define('BASE_PATH', __DIR__);

// Определение BASE_URL с учётом протокола
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'crcerror.ru';
define('BASE_URL', $protocol . '://' . $host);

// Домен для кук
define('COOKIE_DOMAIN', $_ENV['COOKIE_DOMAIN'] ?? '.crcerror.ru');

// Сессии
define('SESSION_LIFETIME', (int)($_ENV['SESSION_LIFETIME'] ?? 3600)); // 1 час (как в ТЗ)
define('SESSION_NAME', $_ENV['SESSION_NAME'] ?? 'smart_smoker_session');
define('SESSION_SECURE', (bool)($_ENV['SESSION_SECURE'] ?? true));
define('SESSION_HTTPONLY', true); // Защита от JavaScript

// =====================================================
// 3. Настройки безопасности
// =====================================================

// Ключ для хеширования паролей (должен быть в .env)
define('APP_KEY', $_ENV['APP_KEY'] ?? 'default-key-change-in-production');

// Соль для генерации токенов CSRF
define('CSRF_SALT', $_ENV['CSRF_SALT'] ?? (APP_KEY . 'csrf_salt'));

// Ограничение скорости запросов
define('RATE_LIMIT_REQUESTS', 5); // 5 запросов в секунду (как в ТЗ)
define('RATE_LIMIT_WINDOW', 1); // окно в секундах

// =====================================================
// 4. Настройки файловой системы
// =====================================================

// Директория для логов (ИСПРАВЛЕНО!)
define('LOG_DIR', BASE_PATH . '/logs');
define('LOG_FILE', LOG_DIR . '/app_' . date('Y-m-d') . '.log');

// Максимальный размер лог-файла (10 МБ)
define('LOG_MAX_SIZE', 10 * 1024 * 1024);

// Директория для временных файлов
define('TMP_DIR', BASE_PATH . '/tmp');

// Директория для загрузок
define('UPLOAD_DIR', BASE_PATH . '/uploads');

// =====================================================
// 5. Настройки времени и локали
// =====================================================

// Часовой пояс
define('APP_TIMEZONE', 'Europe/Moscow');
date_default_timezone_set(APP_TIMEZONE);

// Язык по умолчанию
define('APP_LOCALE', 'ru_RU');
setlocale(LC_ALL, APP_LOCALE . '.UTF-8');

// =====================================================
// 6. Константы проекта
// =====================================================

// Версия приложения
define('APP_VERSION', '1.0.0');

// Режим отладки (в продакшене должно быть false)
define('DEBUG_MODE', filter_var($_ENV['DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN)); // Используем значение из .env

// Максимальное количество записей в истории
define('HISTORY_RETENTION_MONTHS', 6); // 6 месяцев (как в ТЗ)

// Максимальный размер загружаемых файлов (10 МБ)
define('MAX_UPLOAD_SIZE', 10 * 1024 * 1024);

// =====================================================
// 7. Настройки OTA обновлений прошивки
// =====================================================

// Директория для хранения файлов прошивки
define('FIRMWARE_DIR', BASE_PATH . '/firmware');

// Максимальный размер файла прошивки (2 МБ)
define('MAX_FIRMWARE_SIZE', 2 * 1024 * 1024);

// Расширение файлов прошивки
define('FIRMWARE_EXTENSION', '.bin');

// =====================================================
// 7.5 VAPID ключи для Web Push
// =====================================================

define('VAPID_PUBLIC_KEY',  $_ENV['VAPID_PUBLIC_KEY']  ?? '');
define('VAPID_PRIVATE_KEY', $_ENV['VAPID_PRIVATE_KEY'] ?? '');
define('VAPID_SUBJECT',     $_ENV['VAPID_SUBJECT']     ?? 'mailto:info@crcerror.ru');

// Ключ для cron-задач
define('CRON_KEY', $_ENV['CRON_KEY'] ?? '');

// =====================================================
// 8. Настройки API
// =====================================================

// Базовый URL API
define('API_BASE_URL', BASE_URL . '/api');

// Таймаут запросов к внешним сервисам (секунды)
define('API_TIMEOUT', 30);

// Максимальное количество попыток запроса
define('API_MAX_RETRIES', 3);

// =====================================================
// 9. Вспомогательные функции конфигурации
// =====================================================

/**
 * Получить значение конфигурации
 * 
 * @param string $key Ключ конфигурации
 * @param mixed $default Значение по умолчанию
 * @return mixed
 */
function config($key, $default = null) {
    $constants = get_defined_constants(true)['user'];
    
    if (isset($constants[$key])) {
        return $constants[$key];
    }
    
    return $default;
}

/**
 * Проверить режим отладки
 * 
 * @return bool
 */
function isDebugMode() {
    return DEBUG_MODE === true;
}

/**
 * Получить полный путь к файлу в проекте
 * 
 * @param string $path Относительный путь
 * @return string
 */
function projectPath($path = '') {
    return BASE_PATH . ($path ? '/' . ltrim($path, '/') : '');
}

/**
 * Получить полный URL к ресурсу
 * 
 * @param string $path Относительный путь
 * @return string
 */
function assetUrl($path = '') {
    return BASE_URL . ($path ? '/' . ltrim($path, '/') : '');
}

// =====================================================
// 10. Инициализация директорий (ИСПРАВЛЕНО: защита от рекурсии)
// =====================================================

/**
 * Создать директорию и .htaccess файл для защиты
 * 
 * @param string $dir Путь к директории
 * @return bool
 */
function createProtectedDirectory($dir) {
    // Создаем директорию, если не существует
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true)) {
            return false;
        }
    }
    
    // Создаем .htaccess файл для защиты
    $htaccessFile = $dir . '/.htaccess';
    if (!file_exists($htaccessFile)) {
        @file_put_contents($htaccessFile, "Deny from all\n");
    }
    
    return true;
}

// Защита от рекурсивного вызова при создании директорий
if (!isset($GLOBALS['dirs_created'])) {
    $GLOBALS['dirs_created'] = true;
    
    // Создать необходимые директории
    $requiredDirs = [
        LOG_DIR,
        TMP_DIR,
        UPLOAD_DIR,
        FIRMWARE_DIR,
        BASE_PATH . '/includes',
        BASE_PATH . '/templates',
        BASE_PATH . '/backups'
    ];
    
    foreach ($requiredDirs as $dir) {
        createProtectedDirectory($dir);
    }
}

// =====================================================
// Завершение конфигурации
// =====================================================

// Включить вывод ошибок в режиме отладки
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    ini_set('error_log', LOG_FILE);
}

// =====================================================
// Конец файла
// =====================================================