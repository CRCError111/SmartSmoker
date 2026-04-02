<?php
/**
 * API для удаления устройства пользователем
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

    if (!$data || !isset($data['device_id'])) {
        throw new Exception('Параметр device_id обязателен');
    }

    $deviceId = trim($data['device_id']);

    // Проверка владения устройством
    $device = $db->fetchOne('SELECT id, name FROM devices WHERE device_id = ? AND user_id = ?', [$deviceId, $userId]);
    if (!$device) {
        throw new Exception('Устройство не найдено или у вас нет прав на его удаление');
    }

    // Удаление связанных данных
    $db->beginTransaction();
    try {
        // Удаляем данные, привязанные по MAC-адресу (varchar device_id)
        $db->delete('sensor_data', 'device_id = ?', [$deviceId]);
        $db->delete('device_commands', 'device_id = ?', [$deviceId]);
        $db->delete('sync_history', 'device_id = ?', [$deviceId]);
        $db->delete('runs', 'device_id = ?', [$deviceId]);

        // Удаляем программы, привязанные по ID устройства (INT device_id)
        // Для этого сначала удаляем этапы программ (хотя там CASCADE должен быть)
        $db->delete('programs', 'device_id = ?', [$device['id']]);

        // Само устройство
        $db->delete('devices', 'id = ?', [$device['id']]);

        $db->commit();
        logInfo("User $userId deleted device: $deviceId ({$device['name']})", 'API');

    }
    catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Устройство успешно удалено'
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
