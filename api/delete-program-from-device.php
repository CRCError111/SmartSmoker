<?php
/**
 * API endpoint для удаления программы с устройства
 * 
 * Обновляет статус в device_programs на 'deleted'.
 * Контроллер синхронизируется самостоятельно через pull-модель.
 * 
 * @version 2.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = Auth::user();
$userId = $user['id'];

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception('Невалидный JSON в запросе');
    }

    $programId = (int)($input['program_id'] ?? 0);
    $deviceId  = trim($input['device_id'] ?? '');

    if ($programId <= 0) {
        throw new Exception('Невалидный ID программы');
    }

    if (empty($deviceId)) {
        throw new Exception('Не указан ID устройства');
    }

    $db = db();

    // Проверка программы
    $program = $db->fetchOne(
        'SELECT id, program_name FROM programs WHERE id = ? AND user_id = ?',
        [$programId, $userId]
    );

    if (!$program) {
        throw new Exception('Программа не найдена или не принадлежит пользователю');
    }

    // Проверка устройства
    $device = $db->fetchOne(
        'SELECT device_id, name, status FROM devices WHERE device_id = ? AND user_id = ?',
        [$deviceId, $userId]
    );

    if (!$device) {
        throw new Exception('Устройство не найдено или не принадлежит пользователю');
    }

    if ($device['status'] !== 'active') {
        throw new Exception('Устройство неактивно. Невозможно удалить программу.');
    }

    // Проверка что программа загружена на устройство
    $deviceProgram = $db->fetchOne(
        'SELECT id, status FROM device_programs WHERE device_id = ? AND program_id = ? AND status = ?',
        [$deviceId, $programId, 'active']
    );

    if (!$deviceProgram) {
        throw new Exception('Программа не найдена на указанном устройстве');
    }

    // Обновляем статус — контроллер синхронизируется сам через pull-модель
    $db->update(
        'device_programs',
        [
            'status'        => 'deleted',
            'last_verified' => date('Y-m-d H:i:s')
        ],
        'id = ?',
        [$deviceProgram['id']]
    );

    logInfo(sprintf(
        'Program deleted from device: program_id=%d, program_name=%s, device_id=%s, device_name=%s, user_id=%d',
        $programId,
        $program['program_name'],
        $deviceId,
        $device['name'],
        $userId
    ), 'DELETE_PROGRAM');

    echo json_encode([
        'success'    => true,
        'message'    => 'Программа успешно удалена с устройства',
        'program_id' => $programId,
        'device_id'  => $deviceId
    ]);

} catch (Exception $e) {
    logError(sprintf(
        'Exception in delete-program-from-device.php: %s',
        $e->getMessage()
    ), 'DELETE_PROGRAM');

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ]);
}
