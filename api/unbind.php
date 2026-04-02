<?php
/**
 * API endpoint для отвязки устройства с контроллера
 * Принимает POST параметры: uuid, api_token
 * Устанавливает флаг unbound = TRUE вместо полного удаления
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = db();

    // Получение POST параметров из JSON body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Fallback to $_POST if JSON parsing fails
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = $_POST;
    }
    
    $uuid = $data['uuid'] ?? '';
    $apiToken = $data['api_token'] ?? '';

    // Валидация обязательных параметров
    if (empty($uuid)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'UUID обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'API токен обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация формата UUID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный формат UUID'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка существования устройства и получение данных
    $device = $db->fetchOne(
        'SELECT device_id, api_token, unbound FROM devices WHERE device_id = ? LIMIT 1',
        [$uuid]
    );

    // Проверка существования устройства
    if (!$device) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Устройство не найдено'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация API токена
    if ($device['api_token'] !== $apiToken) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный API токен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка, что устройство уже не отвязано
    if ($device['unbound']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Device already unbound'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Установка флага unbound = TRUE
    // НЕ удаляем api_token и user_id - они нужны для финальной синхронизации
    $db->update(
        'devices',
        ['unbound' => 1],
        'device_id = ?',
        [$uuid]
    );

    // Логирование успешной отвязки
    Logger::info('Device unbound', ['device_id' => $uuid]);

    // Возврат JSON ответа
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Device unbound successfully'
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка базы данных'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);
}
