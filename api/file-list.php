<?php
/**
 * API endpoint для получения списка файлов для контроллера
 * Принимает GET параметры: uuid, api_token
 * Проверяет привязку устройства, валидирует токен и возвращает список файлов
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

    // Получение GET параметров
    $uuid = $_GET['uuid'] ?? '';
    $apiToken = $_GET['api_token'] ?? '';

    // Валидация обязательных параметров
    if (empty($uuid)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'UUID обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode([
            'error' => 'API токен обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация формата UUID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Неверный формат UUID'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка существования устройства и получение данных
    $device = $db->fetchOne(
        'SELECT device_id, api_token, unbound, user_id FROM devices WHERE device_id = ? LIMIT 1',
        [$uuid]
    );

    // Проверка существования устройства
    if (!$device) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Устройство не привязано'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка флага unbound
    if ($device['unbound']) {
        // Возврат флага отвязки
        echo json_encode([
            'unbound' => true,
            'files' => []
        ], JSON_UNESCAPED_UNICODE);
        
        // Удаление записи о UUID после возврата флага
        $db->delete('devices', 'device_id = ?', [$uuid]);
        
        exit;
    }

    // Валидация API токена
    if ($device['api_token'] !== $apiToken) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Неверный API токен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получение списка файлов для данного UUID из таблицы program_transfer_queue
    $files = $db->fetchAll(
        'SELECT 
            ptq.transfer_id as transfer_id,
            ptq.program_id as program_id,
            CONCAT("program_", ptq.program_id, ".json") as filename,
            CONCAT(?, "/api/download-program.php?transfer_id=", ptq.transfer_id, "&token=", ?) as url
        FROM program_transfer_queue ptq
        INNER JOIN programs p ON ptq.program_id = p.id
        WHERE ptq.device_id = ? AND ptq.status = "pending"
        ORDER BY ptq.created_at ASC',
        [
            BASE_URL,
            $apiToken,
            $uuid
        ]
    );

    // Логирование запроса
    Logger::info('File list requested', ['uuid' => $uuid, 'files_count' => count($files)]);

    // Возврат JSON ответа
    http_response_code(200);
    echo json_encode([
        'unbound' => false,
        'files' => $files
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка базы данных'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);
}
