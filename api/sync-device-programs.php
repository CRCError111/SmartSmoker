<?php
/**
 * API для синхронизации списка программ устройства
 * 
 * Синхронизирует device_programs на основе program_transfer_queue
 * со статусом 'confirmed' для данного device_id.
 * 
 * HTTP Method: POST
 * 
 * Входные данные (JSON):
 * {
 *   "device_id": "550e8400-e29b-41d4-a716-446655440000"
 * }
 * 
 * Выходные данные (JSON):
 * {
 *   "success": true,
 *   "synced_programs": 3,
 *   "message": "Синхронизация завершена"
 * }
 * 
 * Требования: 11.3, 11.4
 * 
 * @version 2.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json');

try {
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

    $input = json_decode(file_get_contents('php://input'), true);

    if (!isset($input['device_id']) || empty($input['device_id'])) {
        http_response_code(400);
        throw new Exception('Не указан device_id');
    }

    $deviceId = $input['device_id'];
    $db = db();

    // Проверка существования устройства и принадлежности пользователю
    $device = $db->fetchOne(
        'SELECT device_id, status FROM devices WHERE device_id = ? AND user_id = ?',
        [$deviceId, $userId]
    );

    if (!$device) {
        http_response_code(403);
        throw new Exception('Устройство не найдено или не принадлежит вам');
    }

    if ($device['status'] !== 'active') {
        http_response_code(400);
        throw new Exception('Устройство недоступно для синхронизации. Устройство должно быть в статусе active');
    }

    $db->beginTransaction();

    try {
        // Удаляем старые записи для устройства
        $deletedCount = $db->delete('device_programs', 'device_id = ?', [$deviceId]);

        logInfo(sprintf(
            'Deleted old device_programs records: device_id=%s, deleted_count=%d',
            $deviceId,
            $deletedCount
        ), 'SYNC_PROGRAMS');

        // Получаем подтверждённые программы из очереди передачи
        $confirmedPrograms = $db->fetchAll(
            'SELECT ptq.program_id, ptq.confirmed_at
             FROM program_transfer_queue ptq
             INNER JOIN programs p ON ptq.program_id = p.id
             WHERE ptq.device_id = ? AND ptq.status = ?
             ORDER BY ptq.confirmed_at DESC',
            [$deviceId, 'confirmed']
        );

        $insertedCount = 0;

        foreach ($confirmedPrograms as $row) {
            $programId = (int)$row['program_id'];

            // Проверка существования программы
            $programExists = $db->fetchOne('SELECT id FROM programs WHERE id = ?', [$programId]);

            if (!$programExists) {
                logWarning(sprintf(
                    'Program %d not found in programs, skipping: device_id=%s',
                    $programId,
                    $deviceId
                ), 'SYNC_PROGRAMS');
                continue;
            }

            $db->insert('device_programs', [
                'device_id'     => $deviceId,
                'program_id'    => $programId,
                'storage_path'  => "/programs/program_{$programId}.json",
                'uploaded_at'   => $row['confirmed_at'] ?? date('Y-m-d H:i:s'),
                'last_verified' => date('Y-m-d H:i:s'),
                'status'        => 'active'
            ]);

            $insertedCount++;
        }

        $db->commit();

        logInfo(sprintf(
            'Device programs synchronized: device_id=%s, synced=%d, user_id=%d',
            $deviceId,
            $insertedCount,
            $userId
        ), 'SYNC_PROGRAMS');

        echo json_encode([
            'success'         => true,
            'synced_programs' => $insertedCount,
            'message'         => 'Синхронизация завершена'
        ], JSON_UNESCAPED_UNICODE);

    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }

} catch (Exception $e) {
    if (http_response_code() === 200) {
        http_response_code(400);
    }

    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);

    logError(sprintf(
        'API sync-device-programs error: %s | user_id=%s, device_id=%s',
        $e->getMessage(),
        isset($userId) ? $userId : 'null',
        isset($deviceId) ? $deviceId : 'null'
    ), 'SYNC_PROGRAMS');
}
