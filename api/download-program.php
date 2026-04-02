<?php
/**
 * API endpoint для скачивания программы контроллером
 * Принимает GET параметры: transfer_id, token
 * Проверяет токен, возвращает программу в JSON формате и обновляет статус передачи
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = db();

    // Получение GET параметров
    $transferId = $_GET['transfer_id'] ?? '';
    $apiToken = $_GET['token'] ?? '';

    // Валидация обязательных параметров
    if (empty($transferId)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'transfer_id обязателен'
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

    // Получение записи из очереди передачи
    $transfer = $db->fetchOne(
        'SELECT ptq.*, d.api_token, d.user_id
         FROM program_transfer_queue ptq
         INNER JOIN devices d ON ptq.device_id = d.device_id
         WHERE ptq.transfer_id = ?
         LIMIT 1',
        [$transferId]
    );

    // Проверка существования записи
    if (!$transfer) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Запись передачи не найдена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация API токена
    if ($transfer['api_token'] !== $apiToken) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Неверный API токен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка статуса (должен быть pending или sent для повторной попытки)
    if (!in_array($transfer['status'], ['pending', 'sent'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Программа уже обработана или отменена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получение программы с этапами
    $program = getProgram($transfer['program_id'], $transfer['user_id']);

    if (!$program) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Программа не найдена'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Экспорт программы в JSON формат
    $programData = exportProgramToJson($program);
    
    // Преобразуем в JSON строку
    $jsonString = json_encode($programData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
    // Проверка размера JSON (максимум 1MB)
    $jsonSize = strlen($jsonString);
    if ($jsonSize > 1048576) {
        http_response_code(413); // Payload Too Large
        echo json_encode([
            'error' => 'Программа слишком большая для передачи',
            'size' => $jsonSize,
            'max_size' => 1048576
        ], JSON_UNESCAPED_UNICODE);
        
        Logger::error('Program too large', [
            'transfer_id' => $transferId,
            'program_id' => $transfer['program_id'],
            'size' => $jsonSize
        ]);
        exit;
    }
    
    // Обновление статуса передачи на 'sent' ПЕРЕД отправкой
    $db->update(
        'program_transfer_queue',
        [
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
            'retry_count' => $transfer['retry_count'] + 1
        ],
        'transfer_id = ?',
        [$transferId]
    );
    
    // CRITICAL: Set Content-Length header BEFORE any output
    // Проверяем, что заголовки еще не отправлены
    if (headers_sent($file, $line)) {
        // Если заголовки уже отправлены - логируем критическую ошибку
        error_log("CRITICAL: Headers already sent in $file on line $line before Content-Length could be set");
        Logger::error('Headers already sent', [
            'file' => $file,
            'line' => $line,
            'transfer_id' => $transferId
        ]);
    } else {
        // Устанавливаем Content-Length ДО любого вывода
        header('Content-Length: ' . $jsonSize);
    }
    
    // Логирование ПОСЛЕ установки заголовков (но до вывода)
    Logger::info('Program download', [
        'transfer_id' => $transferId, 
        'program_id' => $transfer['program_id'],
        'size' => $jsonSize
    ]);
    
    // Возврат программы
    http_response_code(200);
    echo $jsonString;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Ошибка базы данных'
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
