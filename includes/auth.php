<?php
/**
 * Система аутентификации и авторизации
 * Использует сессии с таймаутом 1 час (как в ТЗ)
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

/**
 * Класс аутентификации
 */
class Auth {
    /**
     * Инициализация сессии
     */
    public static function init() {
        if (session_status() === PHP_SESSION_NONE) {
            // Настройка параметров сессии
            ini_set('session.cookie_httponly', SESSION_HTTPONLY ? 1 : 0);
            ini_set('session.cookie_secure', SESSION_SECURE ? 1 : 0);
            ini_set('session.cookie_domain', COOKIE_DOMAIN);
            ini_set('session.cookie_samesite', 'Strict');
            ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
            
            // Имя сессии
            session_name(SESSION_NAME);
            
            // Запуск сессии
            session_start();
            
            // Регенерация ID сессии для предотвращения fixation
            if (!isset($_SESSION['initialized'])) {
                session_regenerate_id(true);
                $_SESSION['initialized'] = true;
            }
            
            // Обновление времени последней активности
            $_SESSION['last_activity'] = time();
        }
    }
    
    /**
     * Проверка, авторизован ли пользователь
     * 
     * @return bool
     */
    public static function check() {
        self::init();
        
        // Проверка таймаута сессии
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > SESSION_LIFETIME) {
                self::logout();
                return false;
            }
            
            // Обновление времени активности
            $_SESSION['last_activity'] = time();
        }
        
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Авторизация пользователя
     * 
     * @param string $email Email пользователя
     * @param string $password Пароль
     * @param bool $remember Запомнить пользователя
     * @return bool|array Возвращает данные пользователя при успехе, иначе false
     */
    public static function login($email, $password, $remember = false) {
        self::init();
        
        // Очистка входных данных
        $email = filter_var(trim($email), FILTER_SANITIZE_EMAIL);
        
        // Проверка формата email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        // Получение пользователя из БД
        $db = db();
        $user = $db->fetchOne(
            'SELECT id, email, password_hash, full_name, is_active FROM users WHERE email = ? LIMIT 1',
            [$email]
        );
        
        if (!$user) {
            // logMessage('WARNING', "Попытка входа с несуществующим email: $email", 'AUTH');
            return false;
        }
        
        // Проверка активности пользователя
        if (!$user['is_active']) {
            // logMessage('WARNING', "Попытка входа неактивного пользователя: {$user['email']}", 'AUTH');
            return false;
        }
        
        // Проверка пароля
        if (!password_verify($password, $user['password_hash'])) {
            // logMessage('WARNING', "Неверный пароль для пользователя: {$user['email']}", 'AUTH');
            return false;
        }
        
        // Обновление хеша пароля, если используется старый алгоритм
        if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
            $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->update('users', ['password_hash' => $newHash], 'id = ?', [$user['id']]);
        }
        
        // Сохранение данных пользователя в сессии
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Генерация и сохранение токена для "Запомнить меня"
        if ($remember) {
            $rememberToken = bin2hex(random_bytes(32));
            $expiresAt = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 дней
            
            $db->update(
                'users',
                ['remember_token' => hash('sha256', $rememberToken), 'remember_token_expires' => $expiresAt],
                'id = ?',
                [$user['id']]
            );
            
            // Сохранение токена в куки
            setcookie(
                'remember_token',
                $rememberToken,
                time() + (86400 * 30),
                '/',
                COOKIE_DOMAIN,
                SESSION_SECURE,
                true
            );
        }
        
        // Обновление времени последнего входа
        $db->update(
            'users',
            ['last_login' => date('Y-m-d H:i:s')],
            'id = ?',
            [$user['id']]
        );
        
        // Логирование успешного входа
        // logMessage('INFO', "Успешный вход пользователя: {$user['email']} (IP: {$_SESSION['ip_address']})", 'AUTH');
        
        return [
            'id' => $user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name']
        ];
    }
    
    /**
     * Выход из системы
     */
    public static function logout() {
        self::init();
        
        // Удаление токена "Запомнить меня" из БД
        if (isset($_SESSION['user_id'])) {
            $db = db();
            $db->update(
                'users',
                ['remember_token' => null, 'remember_token_expires' => null],
                'id = ?',
                [$_SESSION['user_id']]
            );
        }
        
        // Удаление куки
        if (isset($_COOKIE['remember_token'])) {
            setcookie('remember_token', '', time() - 3600, '/', COOKIE_DOMAIN, SESSION_SECURE, true);
        }
        
        // Уничтожение сессии
        $_SESSION = [];
        
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/', COOKIE_DOMAIN, SESSION_SECURE, true);
        }
        
        session_destroy();
        
        // Логирование выхода
        // logMessage('INFO', 'Пользователь вышел из системы', 'AUTH');
    }
    
    /**
     * Получить данные текущего пользователя
     * 
     * @return array|null
     */
    public static function user() {
        if (!self::check()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'] ?? null,
            'email' => $_SESSION['email'] ?? null,
            'full_name' => $_SESSION['full_name'] ?? null,
            'login_time' => $_SESSION['login_time'] ?? null
        ];
    }
    
    /**
     * Получить ID текущего пользователя
     * 
     * @return int|null
     */
    public static function userId() {
        return self::check() ? ($_SESSION['user_id'] ?? null) : null;
    }
    
    /**
     * Проверка роли пользователя (админ/пользователь)
     * 
     * @return bool
     */
    public static function isAdmin() {
        if (!self::check()) {
            return false;
        }
        
        $db = db();
        $role = $db->fetchColumn(
            'SELECT role FROM users WHERE id = ?',
            [$_SESSION['user_id']]
        );
        
        return $role === 'admin';
    }
    
    /**
     * Генерация токена CSRF
     * 
     * @return string
     */
    public static function generateCsrfToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Проверка токена CSRF
     * 
     * @param string $token Токен из запроса
     * @return bool
     */
    public static function verifyCsrfToken($token) {
        if (!isset($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }
    
    /**
     * Проверка защиты от брутфорса
     * 
     * @param string $ip IP-адрес
     * @return bool true если можно продолжить, false если заблокирован
     */
    public static function checkRateLimit($ip) {
        $db = db();
        
        // Получение попыток за последнюю минуту
        $attempts = $db->fetchColumn(
            'SELECT COUNT(*) FROM logs 
             WHERE level = "WARNING" 
             AND message LIKE ? 
             AND created_at > NOW() - INTERVAL 1 MINUTE',
            ["%Попытка входа с несуществующим email%"]
        );
        
        // Ограничение: 5 попыток в минуту (как в ТЗ)
        if ($attempts >= RATE_LIMIT_REQUESTS) {
            // logMessage('WARNING', "Превышен лимит попыток входа с IP: $ip", 'AUTH');
            return false;
        }
        
        return true;
    }
    
    /**
     * Проверка "Запомнить меня"
     * 
     * @return bool
     */
    public static function checkRememberMe() {
        if (!isset($_COOKIE['remember_token'])) {
            return false;
        }
        
        $token = $_COOKIE['remember_token'];
        
        $db = db();
        $user = $db->fetchOne(
            'SELECT id, email, full_name FROM users 
             WHERE remember_token = ? 
             AND remember_token_expires > NOW() 
             LIMIT 1',
            [hash('sha256', $token)]
        );
        
        if (!$user) {
            return false;
        }
        
        // Авторизация пользователя
        self::init();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['login_time'] = time();
        $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // logMessage('INFO', "Автоматический вход через 'Запомнить меня': {$user['email']}", 'AUTH');
        
        return true;
    }
    
    /**
     * Требует авторизации
     * Перенаправляет на страницу входа, если пользователь не авторизован
     */
    public static function requireAuth() {
        if (!self::check()) {
            header('Location: ' . BASE_URL . '/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
            exit;
        }
    }
    
    /**
     * Требует права администратора
     * Перенаправляет на страницу входа, если пользователь не админ
     */
    public static function requireAdmin() {
        self::requireAuth();
        
        if (!self::isAdmin()) {
            header('Location: ' . BASE_URL . '/dashboard.php');
            exit;
        }
    }
}

/**
 * Удобные функции для работы с аутентификацией
 */

/**
 * Проверить авторизацию
 */
function authCheck() {
    return Auth::check();
}

/**
 * Получить данные текущего пользователя
 */
function authUser() {
    return Auth::user();
}

/**
 * Получить ID текущего пользователя
 */
function authUserId() {
    return Auth::userId();
}

/**
 * Требует авторизации
 */
function requireAuth() {
    Auth::requireAuth();
}

/**
 * Требует права администратора
 */
function requireAdmin() {
    Auth::requireAdmin();
}

/**
 * Генерация токена CSRF
 */
function csrfToken() {
    return Auth::generateCsrfToken();
}

/**
 * Проверка токена CSRF
 */
function verifyCsrf($token) {
    return Auth::verifyCsrfToken($token);
}

// Автоматическая проверка "Запомнить меня" при инициализации
if (session_status() === PHP_SESSION_NONE && isset($_COOKIE['remember_token'])) {
    Auth::checkRememberMe();
}

// =====================================================
// Завершение файла
// =====================================================