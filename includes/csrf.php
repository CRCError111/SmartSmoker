<?php
defined('SMART_SMOKER') or die('Direct access denied');

class CSRF {
    private static string $sessionKey = 'csrf_token';

    /**
     * Генерирует CSRF-токен и сохраняет в сессии.
     * Если токен уже существует — возвращает его.
     */
    public static function generateToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION[self::$sessionKey])) {
            $_SESSION[self::$sessionKey] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::$sessionKey];
    }

    /**
     * Проверяет CSRF-токен через hash_equals (защита от timing-атак).
     */
    public static function validateToken(string $token): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $stored = $_SESSION[self::$sessionKey] ?? '';
        return !empty($stored) && !empty($token) && hash_equals($stored, $token);
    }

    /**
     * Возвращает HTML hidden-поле с CSRF-токеном.
     */
    public static function getTokenField(): string {
        $token = htmlspecialchars(self::generateToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="_csrf" value="' . $token . '">';
    }

    /**
     * Читает токен из заголовка X-CSRF-Token или поля тела запроса _csrf.
     */
    public static function getTokenFromRequest(array $input = []): string {
        return $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $input['_csrf']
            ?? '';
    }
}
