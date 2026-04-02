<?php
/**
 * API endpoint для повторной отправки программы на устройство
 * 
 * Создает новую запись в очереди передачи со ссылкой на предыдущую попытку
 * Ограничивает автоматические повторы до 3 попыток
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Определение константы для доступа к файлам
define('SMART_SMOKER', true);

// Подключение необходимых модулей
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = Auth::user();
$userId = $user['id'];

try {
    // Получение данных из запроса
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Невалидный JSON в запросе');
    }
    
    $programId = (int)($input['program_id'] ?? 0);
    $deviceId = trim($input['device_id'] ?? '');
    $previousAttemptId = (int)($input['previous_attempt_id'] ?? 0);
    
    // Валидация входных данных
    if (!$programId) {
        throw new Exception('Не указан ID программы');
    }
    
    if (!$deviceId) {
        throw new Exception('Не указан ID устройства');
    }
    
    if (!$previousAttemptId) {
        throw new Exception('Не указан ID предыдущей попытки');
    }
    
    $db = db();
    
    // Проверка существования программы и принадлежности пользователю
    $program = $db->fetchOne(
        'SELECT id, program_name FROM programs WHERE id = ? AND user_id = ?',
        [$programId, $userId]
    );
    
    if (!$program) {
        throw new Exception('Программа не найдена или не принадлежит пользователю');
    }
    
    // Проверка существования устройства и принадлежности пользователю
    $device = $db->fetchOne(
        'SELECT device_id, name, status FROM devices WHERE device_id = ? AND user_id = ?',
        [$deviceId, $userId]
    );
    
    if (!$device) {
        throw new Exception('Устройство не найдено или не принадлежит пользователю');
    }
    
    // Проверка статуса устройства
    if ($device['status'] !== 'active') {
        throw new Exception('Устройство неактивно. Невозможно отправить программу.');
    }
    
    // Проверка существования предыдущей попытки
    $previousAttempt = $db->fetchOne(
        'SELECT id, retry_count, status FROM program_transfer_queue WHERE id = ? AND user_id = ?',
        [$previousAttemptId, $userId]
    );
    
    if (!$previousAttempt) {
        throw new Exception('Предыдущая попытка передачи не найдена');
    }
    
    // Проверка статуса предыдущей попытки
    if ($previousAttempt['status'] !== 'failed') {
        throw new Exception('Повторная отправка возможна только для неудачных попыток');
    }
    
    // Получение retry_count из предыдущей попытки
    $retryCount = (int)($previousAttempt['retry_count'] ?? 0);
    
    // Генерация уникального transfer_id
    $transferId = 'tr_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
    
    // Начало транзакции
    $db->beginTransaction();
    
    try {
        // Создание новой записи в очереди передачи
        $queueId = $db->insert('program_transfer_queue', [
            'transfer_id' => $transferId,
            'program_id' => $programId,
            'device_id' => $deviceId,
            'user_id' => $userId,
            'status' => 'pending',
            'retry_count' => $retryCount + 1,
            'previous_attempt_id' => $previousAttemptId
        ]);
        
        // Фиксация транзакции
        $db->commit();
        
        logInfo(sprintf(
            'Program retry queued: program_id=%d, device_id=%s, transfer_id=%s, retry_count=%d, previous_attempt_id=%d',
            $programId,
            $deviceId,
            $transferId,
            $retryCount + 1,
            $previousAttemptId
        ), 'PROGRAM_TRANSFER');
        
        // Успешный ответ
        echo json_encode([
            'success' => true,
            'message' => 'Программа успешно добавлена в очередь для повторной отправки',
            'transfer_id' => $transferId,
            'queue_id' => $queueId,
            'status' => 'pending',
            'retry_count' => $retryCount + 1
        ]);
        
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        throw $e;
    }
    
} catch (Exception $e) {
    logException($e, 'PROGRAM_TRANSFER');
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
