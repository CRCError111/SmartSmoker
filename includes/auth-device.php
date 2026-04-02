<?php
/**
 * Device Authentication Middleware
 * Проверка аутентификации устройств ESP32
 * 
 * @version 1.0
 */

if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

require_once __DIR__ . '/jwt-helper.php';
require_once __DIR__ . '/error-codes.php';

class DeviceAuth {
    /**
     * Аутентификация устройства по JWT токену или API токену
     * 
     * @param array $data Данные запроса (должны содержать device_id и device_token или api_token)
     * @return array Декодированный payload токена или device info
     * @throws Exception При ошибке аутентификации
     */
    public static function authenticate($data) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

        // Проверка наличия device_id
        if (!isset($data['device_id']) || empty($data['device_id'])) {
            Logger::warning('Auth failed: device_id missing', [
                'ip'        => $ip,
                'device_id' => null,
                'reason'    => 'device_id_missing',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
            throw new Exception('device_id обязателен');
        }
        
        $deviceId = $data['device_id'];
        
        // Проверка наличия токена (поддержка обоих форматов)
        $token = null;
        $isApiToken = false;
        
        if (isset($data['api_token']) && !empty($data['api_token'])) {
            $token = $data['api_token'];
            $isApiToken = true;
        } elseif (isset($data['device_token']) && !empty($data['device_token'])) {
            $token = $data['device_token'];
            $isApiToken = false;
        } else {
            // C-06: проверяем заголовок Authorization: Bearer
            $bearerToken = self::getBearerToken();
            if ($bearerToken !== null) {
                $token = $bearerToken;
                $isApiToken = true;
            } else {
                Logger::warning('Auth failed: token missing', [
                    'ip'        => $ip,
                    'device_id' => $deviceId,
                    'reason'    => 'token_missing',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
                throw new Exception('device_token или api_token обязателен');
            }
        }
        
        // Аутентификация через API токен (новый способ)
        if ($isApiToken) {
            $db = db();
            $device = $db->fetchOne(
                'SELECT device_id, api_token, user_id, unbound FROM devices WHERE device_id = ? LIMIT 1',
                [$deviceId]
            );
            
            if (!$device) {
                Logger::warning('Auth failed: device not found', [
                    'ip'        => $ip,
                    'device_id' => $deviceId,
                    'reason'    => 'device_not_found',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
                throw new Exception('Устройство не найдено');
            }
            
            if ($device['unbound']) {
                Logger::warning('Auth failed: device unbound', [
                    'ip'        => $ip,
                    'device_id' => $deviceId,
                    'reason'    => 'device_unbound',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
                throw new Exception('Устройство отвязано');
            }
            
            if (!hash_equals($device['api_token'], hash('sha256', $token))) {
                Logger::warning('Auth failed: invalid api_token', [
                    'ip'        => $ip,
                    'device_id' => $deviceId,
                    'reason'    => 'invalid_api_token',
                    'timestamp' => date('Y-m-d H:i:s'),
                ]);
                throw new Exception('Неверный API токен');
            }
            
            return [
                'device_id' => $device['device_id'],
                'user_id'   => $device['user_id'],
                'auth_type' => 'api_token',
            ];
        }
        
        // Аутентификация через JWT токен (старый способ, для обратной совместимости)
        $payload = JWTHelper::validateToken($token);
        
        if (!$payload) {
            Logger::warning('Auth failed: invalid JWT token', [
                'ip'        => $ip,
                'device_id' => $deviceId,
                'reason'    => 'invalid_jwt',
                'timestamp' => date('Y-m-d H:i:s'),
            ]);
            throw new Exception('Невалидный или истёкший токен');
        }
        
        if ($payload['device_id'] !== $deviceId) {
            Logger::warning('Auth failed: device_id mismatch', [
                'ip'             => $ip,
                'device_id'      => $deviceId,
                'token_device_id' => $payload['device_id'],
                'reason'         => 'device_id_mismatch',
                'timestamp'      => date('Y-m-d H:i:s'),
            ]);
            throw new Exception('device_id не совпадает с токеном');
        }
        
        return $payload;
    }
    
    /**
     * Проверка токена без исключений
     * Полезно для опциональной аутентификации
     * 
     * @param array $data Данные запроса
     * @return array|false Декодированный payload или false
     */
    public static function check($data) {
        try {
            return self::authenticate($data);
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Извлечение токена из заголовка Authorization: Bearer
     * Используется для C-06: токен передаётся в заголовке, не в URL
     * 
     * @return string|null Токен или null если заголовок отсутствует
     */
    public static function getBearerToken(): ?string {
        $headers = getallheaders();
        
        // Проверяем стандартный заголовок Authorization
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (empty($authHeader)) {
            return null;
        }
        
        if (strncasecmp($authHeader, 'Bearer ', 7) === 0) {
            return trim(substr($authHeader, 7));
        }
        
        return null;
    }
    
    /**
     * Извлечение device_id из токена
     * 
     * @param string $token JWT токен
     * @return string|null device_id или null
     */
    public static function getDeviceId($token) {
        return JWTHelper::getDeviceIdFromToken($token);
    }
}
