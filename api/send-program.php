<?php
/**
 * API для инициации передачи программы на устройство
 * 
 * HTTP Method: POST
 * 
 * Входные данные (JSON):
 * {
 *   "program_id": 1,
 *   "device_id": "550e8400-e29b-41d4-a716-446655440000"
 * }
 * 
 * Выходные данные (JSON):
 * {
 *   "success": true,
 *   "message": "Программа добавлена в очередь передачи",
 *   "transfer_id": "tr_20260210_143022_abc123",
 *   "status": "pending"
 * }
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';

header('Content-Type: application/json');

try {
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        throw new Exception('Метод не поддерживается. Используйте POST');
    }

    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $user = Auth::user();
    $userId = $user['id'];

    // Проверка CSRF-токена
    $csrfToken = CSRF::getTokenFromRequest($input ?? []);
    if (!CSRF::validateToken($csrfToken)) {
        http_response_code(403);
        throw new Exception('Недействительный CSRF-токен');
    }
    
    // Получение и валидация входных данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['program_id']) || !is_numeric($input['program_id'])) {
        http_response_code(400);
        throw new Exception('Не указан или невалиден program_id');
    }
    
    if (!isset($input['device_id']) || empty($input['device_id'])) {
        http_response_code(400);
        throw new Exception('Не указан device_id');
    }
    
    $programId = (int)$input['program_id'];
    $deviceId = $input['device_id'];
    
    $db = db();
    
    // Проверка существования программы и принадлежности пользователю
    $program = $db->fetchOne(
        'SELECT id, program_name 
         FROM programs
         WHERE id = ? AND user_id = ?',
        [$programId, $userId]
    );
    
    if (!$program) {
        http_response_code(404);
        throw new Exception('Программа не найдена или у вас нет доступа к ней');
    }

    // Валидация этапов программы
    $stages = $db->fetchAll(
        'SELECT stage_name, target_temp, duration_minutes FROM program_stages WHERE program_id = ? ORDER BY stage_order',
        [$programId]
    );
    if (empty($stages)) {
        http_response_code(422);
        throw new Exception('Программа не содержит этапов');
    }
    if (count($stages) > 20) {
        http_response_code(422);
        throw new Exception('Программа содержит более 20 этапов');
    }
    foreach ($stages as $stage) {
        $temp = (float)$stage['target_temp'];
        if ($temp < 0 || $temp > 300) {
            http_response_code(422);
            throw new Exception("Этап '{$stage['stage_name']}': температура вне диапазона 0–300°C");
        }
        if ((int)$stage['duration_minutes'] <= 0) {
            http_response_code(422);
            throw new Exception("Этап '{$stage['stage_name']}': длительность должна быть > 0 минут");
        }
    }
    
    // Проверка существования устройства и принадлежности пользователю
    $device = $db->fetchOne(
        'SELECT device_id, status
         FROM devices
         WHERE device_id = ? AND user_id = ?',
        [$deviceId, $userId]
    );
    
    if (!$device) {
        http_response_code(403);
        throw new Exception('Устройство не найдено или не принадлежит вам');
    }
    
    // Проверка статуса устройства
    if ($device['status'] !== 'active') {
        http_response_code(400);
        throw new Exception('Устройство недоступно для передачи программ. Устройство должно быть в статусе active');
    }
    
    // Генерация уникального transfer_id
    // Формат: tr_YYYYMMDD_HHMMSS_random
    $transferId = 'tr_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
    
    // Добавление записи в очередь передачи
    $queueId = $db->insert('program_transfer_queue', [
        'transfer_id' => $transferId,
        'program_id' => $programId,
        'device_id' => $deviceId,
        'user_id' => $userId,
        'status' => 'pending',
        'retry_count' => 0
    ]);
    
    // Логирование успешного добавления в очередь (INFO level)
    logInfo(sprintf(
        'Program added to transfer queue: transfer_id=%s, program_id=%d, program_name=%s, device_id=%s, user_id=%d',
        $transferId,
        $programId,
        $program['program_name'],
        $deviceId,
        $userId
    ), 'SEND_PROGRAM');
    
    // Возврат успешного ответа
    echo json_encode([
        'success' => true,
        'message' => 'Программа добавлена в очередь передачи',
        'transfer_id' => $transferId,
        'status' => 'pending',
        'queue_id' => $queueId
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    // HTTP код уже установлен в блоках выше, если не установлен - ставим 400
    if (http_response_code() === 200) {
        http_response_code(400);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    // Логирование ошибки (ERROR level)
    logError(sprintf(
        'API send-program error: %s | user_id=%s, program_id=%s, device_id=%s',
        $e->getMessage(),
        isset($userId) ? $userId : 'null',
        isset($programId) ? $programId : 'null',
        isset($deviceId) ? $deviceId : 'null'
    ), 'SEND_PROGRAM');
}
