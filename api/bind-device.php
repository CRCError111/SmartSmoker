<?php
/**
 * API для привязки устройства ESP32
 * Совместимо с ТЗ Smart Smoker
 * 
 * @version 3.0 - api_token (JWT removed)
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/error-codes.php';
require_once __DIR__ . '/../includes/push-manager.php';

require_once __DIR__ . '/api-header.php';

try {
    $db = db();

    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);

    if (!$data) {
        throw new Exception('Неверный формат JSON');
    }

    if (!isset($data['device_id']) || empty($data['device_id'])) {
        throw new Exception('device_id обязателен');
    }

    $deviceId = trim($data['device_id']);
    $esp32Ip  = isset($data['esp32_ip']) ? trim($data['esp32_ip']) : null;

    // Авторизация: сессия пользователя (с сайта) или без (от контроллера)
    $currentUserId = null;
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['user_id'])) {
        $currentUserId = $_SESSION['user_id'];
    }

    $device = $db->fetchOne(
        'SELECT id, user_id, name, status FROM devices WHERE device_id = ?',
        [$deviceId]
    );

    if (!$device) {
        if (!$currentUserId) {
            throw new Exception('Для привязки нового устройства требуется авторизация');
        }

        $deviceName = 'Smart Smoker #' . substr($deviceId, 0, 8);
        $db->insert('devices', [
            'device_id'  => $deviceId,
            'user_id'    => $currentUserId,
            'name'       => $deviceName,
            'status'     => 'pending',
            'ip_address' => $esp32Ip,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        $device = $db->fetchOne(
            'SELECT id, user_id, name, status FROM devices WHERE device_id = ?',
            [$deviceId]
        );

        Logger::info('New device created during bind', [
            'device_id' => $deviceId,
            'user_id'   => $currentUserId,
        ]);
    } else {
        $deviceStatus = $device['status'] ?? 'pending';

        if ($deviceStatus !== 'inactive' && $device['user_id'] && $currentUserId && $device['user_id'] != $currentUserId) {
            http_response_code(409);
            echo json_encode([
                'success'   => false,
                'error'     => 'Это устройство уже привязано к другой учетной записи',
                'timestamp' => date('Y-m-d H:i:s')
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    // Генерация постоянного api_token (64 hex-символа, криптографически безопасный) (64 hex-символа, криптографически безопасный)
    $apiToken = bin2hex(random_bytes(32));

    $updateData = [
        'ip_address'       => $esp32Ip,
        'last_seen'        => date('Y-m-d H:i:s'),
        'status'           => 'active',
        'api_token'        => $apiToken,
        'token_issued_at'  => date('Y-m-d H:i:s'),
        'token_expires_at' => null,  // токен бессрочный (ТЗ: api_token не истекает)
        'updated_at'       => date('Y-m-d H:i:s')
    ];

    if ($device['status'] === 'pending' || $device['status'] === 'inactive') {
        $updateData['bound_at'] = date('Y-m-d H:i:s');
    }

    $db->update('devices', $updateData, 'device_id = ?', [$deviceId]);

    Logger::info('Device bound with api_token', [
        'device_id'       => $deviceId,
        'esp32_ip'        => $esp32Ip,
        'user_id'         => $device['user_id'],
        'previous_status' => $device['status'] ?? 'pending'
    ]);

    // Push-уведомление о привязке устройства
    $bindUserId = $device['user_id'] ?? ($currentUserId ?? null);
    if ($bindUserId) {
        try {
            $pushManager = new PushManager($db);
            $pushManager->sendToUser($bindUserId, [
                'title' => 'Устройство привязано',
                'body'  => 'Устройство «' . ($device['name'] ?? $deviceId) . '» успешно привязано к вашему аккаунту.',
                'icon'  => '/icons/icon-192.png',
                'data'  => ['device_id' => $deviceId],
            ]);
        } catch (Exception $pushEx) {
            Logger::warning('Push notification failed on device bind', [
                'device_id' => $deviceId,
                'error'     => $pushEx->getMessage(),
            ]);
        }
    }

    echo json_encode([
        'success'               => true,
        'message'               => 'Устройство успешно привязано',
        'device_id'             => $deviceId,
        'device_token'          => $apiToken,   // поле device_token для совместимости с ESP32
        'api_token'             => $apiToken,
        'device_name'           => $device['name'],
        'cloud_url'             => BASE_URL,
        'sync_interval'         => 60,
        'program_sync_interval' => 300,
        'server_time'           => date('Y-m-d H:i:s'),
        'timestamp'             => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success'    => false,
        'error'      => 'Ошибка базы данных. Пожалуйста, попробуйте позже.',
        'error_code' => ErrorCodes::DB_ERROR,
        'timestamp'  => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

    Logger::error('Database error in bind device API', [
        'error' => $e->getMessage(),
        'data'  => $jsonData ?? 'no data'
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success'    => false,
        'error'      => $e->getMessage(),
        'error_code' => ErrorCodes::INTERNAL_ERROR,
        'timestamp'  => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);

    Logger::error('Bind device API error', [
        'error' => $e->getMessage(),
        'data'  => $jsonData ?? 'no data'
    ]);
}
