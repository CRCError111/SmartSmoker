<?php
/**
 * API для создания нового устройства
 */
define('SMART_SMOKER', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json');

try {
    Auth::init();
    if (!Auth::check()) {
        http_response_code(401);
        throw new Exception('Авторизация обязательна');
    }

    $db = db();
    $userId = Auth::userId();

    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);

    if (!$data || !isset($data['name']) || !isset($data['device_id'])) {
        throw new Exception('Параметры name и device_id обязательны');
    }

    $name = trim($data['name']);
    $deviceId = trim($data['device_id']);

    if (empty($name) || empty($deviceId)) {
        throw new Exception('Название и ID устройства не могут быть пустыми');
    }

    // Валидация формата device_id (UUID v4)
    require_once __DIR__ . '/../includes/functions.php';
    if (!isValidUUIDv4($deviceId)) {
        http_response_code(400);
        throw new Exception('Неверный формат device_id (ожидается UUID v4)');
    }

    // Проверка, не занято ли уже это устройство
    $existing = $db->fetchOne('SELECT id FROM devices WHERE device_id = ?', [$deviceId]);
    if ($existing) {
        throw new Exception('Устройство с таким ID уже зарегистрировано');
    }

    $db->insert('devices', [
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $name,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    logInfo("User $userId created new device: $deviceId ($name)", 'API');

    echo json_encode([
        'success' => true,
        'message' => 'Устройство успешно добавлено. Теперь вы можете привязать вашу коптильню.',
        'device_id' => $deviceId
    ], JSON_UNESCAPED_UNICODE);

}
catch (Exception $e) {
    if (http_response_code() === 200)
        http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
