<?php
/**
 * API endpoint для получения Device ID из ESP32
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
    
    $device_id = $data['device_id'] ?? '';
    $api_token = $data['api_token'] ?? '';

    // Валидация обязательных параметров
    if (empty($device_id)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'device_id обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($api_token)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'api_token обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка существования устройства и валидность токена
    $device = $db->fetchOne(
        'SELECT id, device_id, api_token FROM devices WHERE device_id = ? LIMIT 1',
        [$device_id]
    );
    
    if (!$device) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Устройство не найдено'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if ($device['api_token'] !== $api_token) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный api_token'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получение Device ID из ESP32 через веб-интерфейс
    // ESP32 должен предоставлять Device ID через API endpoint
    // Для текущей реализации возвращаем Device ID из базы данных
    
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'device_id' => $device['device_id']
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
