<?php
/**
 * JWT Helper — обёртка над firebase/php-jwt.
 * Публичный интерфейс сохранён для обратной совместимости.
 */
defined('SMART_SMOKER') or die('Direct access denied');

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTHelper {
    private static string $algorithm = 'HS256';

    private static function getSecret(): string {
        return $_ENV['JWT_SECRET'] ?? (defined('APP_KEY') ? APP_KEY . '_jwt_secret' : '');
    }

    /**
     * Генерирует JWT-токен для устройства.
     */
    public static function generateToken(string $deviceId, int $expiresIn = 31536000): string {
        $payload = [
            'device_id' => $deviceId,
            'iat'       => time(),
            'exp'       => time() + $expiresIn,
        ];
        return JWT::encode($payload, self::getSecret(), self::$algorithm);
    }

    /**
     * Валидирует и декодирует JWT-токен.
     * Возвращает массив payload или false при ошибке.
     */
    public static function validateToken(string $token): array|false {
        try {
            $decoded = JWT::decode($token, new Key(self::getSecret(), self::$algorithm));
            return (array)$decoded;
        } catch (\Exception $e) {
            Logger::warning('JWT validation failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Извлекает device_id из токена без полной валидации (для логирования).
     */
    public static function getDeviceIdFromToken(string $token): ?string {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) return null;
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
            return $payload['device_id'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
