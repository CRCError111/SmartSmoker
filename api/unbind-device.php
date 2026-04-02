<?php
/**
 * API для отвязки устройства пользователем
 * Устанавливает флаг unbound = TRUE для устройства пользователя
 * 
 * @version 1.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Требуется авторизация пользователя
    Auth::requireAuth();
    
    $user = Auth::user();
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        http_response_code(405);
        throw new Exception('Метод не поддерживается');
    }
    
    // Получение данных запроса
    $data = json_decode(file_get_contents('php://input'), true);

    // Проверка CSRF-токена
    $csrfToken = CSRF::getTokenFromRequest($data ?? []);
    if (!CSRF::validateToken($csrfToken)) {
        http_response_code(403);
        throw new Exception('Недействительный CSRF-токен');
    }

    if (!isset($data['device_id'])) {
        http_response_code(400);
        throw new Exception('Не указан Device ID');
    }
    
    $deviceId = $data['device_id'];
    
    // Валидация формата UUID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        http_response_code(400);
        throw new Exception('Неверный формат Device ID');
    }
    
    // Транзакция с блокировкой строки для предотвращения race condition
    $db->beginTransaction();

    $device = $db->fetchOne(
        'SELECT * FROM devices WHERE device_id = ? AND user_id = ? FOR UPDATE',
        [$deviceId, $user['id']]
    );

    if (!$device) {
        $db->rollback();
        http_response_code(404);
        throw new Exception('Устройство не найдено или не принадлежит вам');
    }

    if (empty($device['api_token'])) {
        $db->rollback();
        http_response_code(400);
        throw new Exception('Устройство не привязано');
    }

    if ($device['unbound']) {
        $db->rollback();
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Устройство уже отвязывается',
            'notification_sent' => false
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Устанавливаем unbound = 1 и немедленно инвалидируем токен
    $db->update(
        'devices',
        [
            'unbound'    => 1,
            'api_token'  => null,
            'updated_at' => date('Y-m-d H:i:s')
        ],
        'device_id = ?',
        [$deviceId]
    );

    $db->commit();
    
    // Логирование
    Logger::info('Device unbound by user', [
        'device_id' => $deviceId,
        'device_name' => $device['name'],
        'user_id' => $user['id'],
        'username' => $user['username']
    ]);
    
    // Попытка отправить уведомление на ESP32 (если устройство онлайн)
    $notificationSent = false;
    if (!empty($data['esp32_ip'])) {
        try {
            $esp32Url = 'http://' . $data['esp32_ip'] . '/api/unbind';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $esp32Url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                'reason' => 'user_request'
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5 секунд таймаут
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200) {
                $notificationSent = true;
                Logger::info('Unbind notification sent to ESP32', [
                    'device_id' => $deviceId,
                    'esp32_ip' => $data['esp32_ip']
                ]);
            }
        } catch (Exception $e) {
            // Игнорируем ошибки отправки уведомления
            Logger::warning('Failed to send unbind notification to ESP32', [
                'device_id' => $deviceId,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Устройство будет отвязано при следующем опросе (до 5 минут)',
        'notification_sent' => $notificationSent
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // HTTP код уже установлен в блоках выше, если нет - ставим 500
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    Logger::error('Unbind device API error', [
        'error' => $e->getMessage(),
        'user_id' => Auth::userId() ?? null,
        'trace' => $e->getTraceAsString()
    ]);
}
