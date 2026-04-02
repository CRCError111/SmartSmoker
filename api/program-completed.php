<?php
/**
 * API endpoint: Уведомление о завершении программы копчения
 *
 * Принимает POST запрос с JSON данными:
 * {
 *   "device_id": "uuid",
 *   "api_token": "<token>",
 *   "program_name": "Горячее копчение мяса",
 *   "run_id": "uuid-run",
 *   "duration_seconds": 7200
 * }
 *
 * @version 1.0
 */

require_once __DIR__ . '/api-header.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не разрешен']);
    exit;
}

define('SMART_SMOKER', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';

$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

if (!is_array($data) || empty($data['device_id']) || empty($data['api_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Обязательные поля: device_id, api_token']);
    exit;
}

try {
    $db = db();

    // Аутентификация устройства
    $device = $db->fetchOne(
        'SELECT id, device_id, user_id FROM devices WHERE device_id = ? AND api_token = ? AND status = "active"',
        [$data['device_id'], $data['api_token']]
    );

    if (!$device) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Неверный api_token или устройство не активно']);
        Logger::warning('program-completed: auth failed', ['device_id' => $data['device_id']]);
        exit;
    }

    $programName    = trim($data['program_name'] ?? '');
    $runId          = trim($data['run_id'] ?? '');
    $durationSec    = intval($data['duration_seconds'] ?? 0);

    if (empty($programName)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'program_name обязателен']);
        exit;
    }

    if (empty($runId)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'run_id обязателен']);
        exit;
    }

    // Обновляем запись run_id в sensor_data — помечаем завершение
    // (run_id уже присутствует в sensor_data, просто логируем факт завершения)

    // Ищем программу в БД для получения program_id
    $program = $db->fetchOne(
        'SELECT id FROM programs WHERE program_name = ? AND (user_id = ? OR is_public = 1) LIMIT 1',
        [$programName, $device['user_id']]
    );

    $programId = $program ? $program['id'] : null;

    // Записываем в лог завершения программ (если таблица существует)
    try {
        $db->insert('program_run_log', [
            'device_id'       => $device['device_id'],
            'program_id'      => $programId,
            'program_name'    => $programName,
            'run_id'          => $runId,
            'duration_seconds'=> $durationSec,
            'completed_at'    => date('Y-m-d H:i:s'),
            'created_at'      => date('Y-m-d H:i:s')
        ]);
    } catch (Exception $logEx) {
        // Таблица может не существовать — не фатально
        Logger::debug('program_run_log insert skipped: ' . $logEx->getMessage());
    }

    Logger::info('Program completed', [
        'device_id'    => $data['device_id'],
        'program_name' => $programName,
        'run_id'       => $runId,
        'duration_sec' => $durationSec,
    ]);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Завершение программы зафиксировано',
        'run_id'  => $runId,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    Logger::error('program-completed error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
