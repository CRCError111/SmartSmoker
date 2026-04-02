<?php
/**
 * API endpoint для подтверждения получения программы контроллером
 * Принимает POST с JSON: {transfer_id, uuid, api_token, status}
 * Обновляет статус передачи на 'confirmed' или 'failed'
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
    // Проверка метода запроса
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'error' => 'Метод не поддерживается. Используйте POST'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $db = db();

    // Получение и валидация входных данных
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['transfer_id']) || empty($input['transfer_id'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'transfer_id обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($input['uuid']) || empty($input['uuid'])) {
        http_response_code(400);
        echo json_encode([
            'error' => 'uuid обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!isset($input['api_token']) || empty($input['api_token'])) {
        http_response_code(401);
        echo json_encode([
            'error' => 'api_token обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $transferId = $input['transfer_id'];
    $uuid = $input['uuid'];
    $apiToken = $input['api_token'];
    $status = $input['status'] ?? 'success';
    $errorMessage = $input['error_message'] ?? null;

    // Whitelist допустимых статусов
    if (!in_array($status, ['success', 'error'], true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Недопустимое значение status. Допустимые: success, error'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Транзакция с блокировкой строки для предотвращения race condition
    $db->beginTransaction();

    // Получение записи из очереди передачи с проверкой устройства (FOR UPDATE)
    $transfer = $db->fetchOne(
        'SELECT ptq.*, d.api_token as device_token
         FROM program_transfer_queue ptq
         INNER JOIN devices d ON ptq.device_id = d.device_id
         WHERE ptq.transfer_id = ? AND ptq.device_id = ?
         FOR UPDATE',
        [$transferId, $uuid]
    );

    if (!$transfer) {
        $db->rollback();
        http_response_code(404);
        echo json_encode(['error' => 'Запись передачи не найдена'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Проверка токена через hash_equals (в БД хранится SHA256-хэш)
    if (!$transfer['device_token'] || !hash_equals($transfer['device_token'], hash('sha256', $apiToken))) {
        $db->rollback();
        http_response_code(401);
        echo json_encode(['error' => 'Неверный API токен'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Идемпотентность: если статус уже финальный — возвращаем без изменений
    if (in_array($transfer['status'], ['confirmed', 'failed'], true)) {
        $db->rollback();
        http_response_code(200);
        echo json_encode([
            'success'     => true,
            'message'     => 'Статус передачи уже установлен',
            'transfer_id' => $transferId,
            'status'      => $transfer['status']
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Определение нового статуса
    $newStatus = ($status === 'success') ? 'confirmed' : 'failed';

    // Обновление статуса передачи
    $updateData = [
        'status' => $newStatus,
        'confirmed_at' => date('Y-m-d H:i:s')
    ];

    if ($newStatus === 'failed' && $errorMessage) {
        $updateData['error_message'] = $errorMessage;
        $updateData['error_code'] = $input['error_code'] ?? null;
    }

    $db->update(
        'program_transfer_queue',
        $updateData,
        'transfer_id = ?',
        [$transferId]
    );

    // При успешном подтверждении — создаём/обновляем запись в device_programs
    if ($newStatus === 'confirmed') {
        $existing = $db->fetchOne(
            'SELECT id FROM device_programs WHERE device_id = ? AND program_id = ?',
            [$uuid, $transfer['program_id']]
        );

        if ($existing) {
            $db->update('device_programs', [
                'status'        => 'active',
                'uploaded_at'   => date('Y-m-d H:i:s'),
                'last_verified' => date('Y-m-d H:i:s')
            ], 'id = ?', [$existing['id']]);
        } else {
            $db->insert('device_programs', [
                'device_id'     => $uuid,
                'program_id'    => $transfer['program_id'],
                'storage_path'  => '/programs/program_' . $transfer['program_id'] . '.json',
                'uploaded_at'   => date('Y-m-d H:i:s'),
                'last_verified' => date('Y-m-d H:i:s'),
                'status'        => 'active'
            ]);
        }
    }

    $db->commit();

    Logger::info('Program transfer confirmed', ['transfer_id' => $transferId, 'status' => $newStatus]);
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Статус передачи обновлен',
        'transfer_id' => $transferId,
        'status' => $newStatus
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    if (isset($db)) $db->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Ошибка базы данных'], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    if (isset($db)) $db->rollback();
    http_response_code(500);
    echo json_encode(['error' => 'Внутренняя ошибка сервера: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
