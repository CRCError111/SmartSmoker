<?php
/**
 * API для отвязки устройства с сайта (только для админов)
 * Устанавливает флаг unbound = TRUE для выбранного устройства
 * 
 * @version 1.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Требуется авторизация администратора
    AdminAuth::requireAdmin();
    
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }
    
    // Получение данных запроса
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['device_id'])) {
        throw new Exception('Не указан Device ID');
    }
    
    $deviceId = $data['device_id'];
    
    // Валидация формата UUID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $deviceId)) {
        throw new Exception('Неверный формат Device ID');
    }
    
    // Получение информации об устройстве
    $device = $db->fetchOne(
        'SELECT d.*, u.full_name as owner_name, u.email as owner_email
         FROM devices d
         JOIN users u ON u.id = d.user_id
         WHERE d.device_id = ?',
        [$deviceId]
    );
    
    if (!$device) {
        throw new Exception('Устройство не найдено');
    }
    
    // Проверка, что устройство привязано
    if (empty($device['api_token'])) {
        throw new Exception('Устройство не привязано');
    }
    
    // Проверка, что устройство уже не отвязывается
    if ($device['unbound']) {
        echo json_encode([
            'success' => true,
            'message' => 'Устройство уже отвязывается'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Установка флага unbound = TRUE
    // НЕ удаляем api_token и user_id - они нужны для финальной синхронизации
    $db->update(
        'devices',
        ['unbound' => 1],
        'device_id = ?',
        [$deviceId]
    );
    
    // Логирование действия администратора
    AdminAuth::logAction('device_unbound', 'device', $device['id'], [
        'device_name' => $device['name'],
        'device_id' => $device['device_id'],
        'owner' => $device['owner_name'],
        'owner_email' => $device['owner_email']
    ]);
    
    Logger::info('Device unbound by admin', [
        'device_id' => $deviceId,
        'device_name' => $device['name'],
        'admin_id' => Auth::user()['id']
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Устройство будет отвязано при следующем опросе (до 5 минут)'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    logException($e, 'ADMIN_API');
}
