<?php
/**
 * API endpoint для подтверждения получения файла контроллером
 * Принимает POST параметры: uuid, api_token, file_name
 * Обновляет статус передачи на 'completed'
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

    // Получение POST данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    $uuid = $input['uuid'] ?? '';
    $apiToken = $input['api_token'] ?? '';
    $fileName = $input['file_name'] ?? '';

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

    if (empty($fileName)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Имя файла обязательно'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка существования устройства
    $device = $db->fetchOne(
        'SELECT device_id, api_token FROM devices WHERE device_id = ? LIMIT 1',
        [$uuid]
    );

    if (!$device) {
        http_response_code(404);
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

    // Извлечение program_id из имени файла (формат: program_5.json или program_c123.json)
    if (preg_match('/program_c?(\d+)\.json/', $fileName, $matches)) {
        $programId = (int)$matches[1];
        
        // Проверка существования таблицы program_transfer_queue
        try {
            $tableExists = $db->fetchOne(
                "SELECT COUNT(*) as count FROM information_schema.tables 
                 WHERE table_schema = DATABASE() AND table_name = 'program_transfer_queue'"
            );
            
            if ($tableExists && $tableExists['count'] > 0) {
                // Обновление статуса передачи на 'completed'
                $updated = $db->update(
                    'program_transfer_queue',
                    [
                        'status' => 'completed',
                        'completed_at' => date('Y-m-d H:i:s')
                    ],
                    'device_id = ? AND program_id = ? AND status IN ("pending", "sent")',
                    [$uuid, $programId]
                );

                if ($updated > 0) {
                    logInfo("File received confirmation: $fileName for device $uuid", 'FILE_RECEIVED');
                }
            }
        } catch (Exception $e) {
            // Таблица не существует - это нормально, просто пропускаем
            logInfo("File received (no transfer queue): $fileName for device $uuid", 'FILE_RECEIVED');
        }
        
        // Всегда возвращаем успех, даже если таблица не существует
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Получение файла подтверждено'
        ], JSON_UNESCAPED_UNICODE);
        
    } else {
        // Неверный формат имени файла
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный формат имени файла'
        ], JSON_UNESCAPED_UNICODE);
    }

} catch (PDOException $e) {
    logException($e, 'FILE_RECEIVED_DB');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка базы данных: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    logException($e, 'FILE_RECEIVED');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
