<?php
/**
 * API: Подтверждение розжига дымогенератора
 * 
 * Пользователь нажимает кнопку "Дымогенератор готов" в веб-интерфейсе/PWA.
 * Сервер ставит команду smoke_confirmed в очередь для устройства.
 * Устройство получит её при следующей отправке телеметрии.
 * 
 * POST /api/smoke-confirmed.php
 * Body: { "device_id": "...", "csrf_token": "..." }
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/command-manager.php';

header('Content-Type: application/json');

try {
    Auth::requireAuth();
    $user = Auth::user();
    $db = db();

    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        throw new Exception('Неверный формат JSON');
    }

    // CSRF
    if (empty($data['csrf_token']) || !verifyCsrfToken($data['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $deviceId = trim($data['device_id'] ?? '');
    if (empty($deviceId)) {
        throw new Exception('device_id обязателен');
    }

    // Проверяем, что устройство принадлежит пользователю
    $device = $db->fetchOne(
        'SELECT id, device_id, status FROM devices WHERE device_id = ? AND user_id = ?',
        [$deviceId, $user['id']]
    );

    if (!$device) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Устройство не найдено']);
        exit;
    }

    if ($device['status'] !== 'active') {
        throw new Exception('Устройство не активно');
    }

    // Ставим команду smoke_confirmed в очередь
    $commandManager = new CommandManager($db);
    $commandId = $commandManager->addCommand($deviceId, 'smoke_confirmed', [
        'confirmed_by' => 'user',
        'user_id'      => $user['id'],
        'timestamp'    => date('Y-m-d H:i:s'),
    ]);

    Logger::info('Smoke ignition confirmed by user', [
        'device_id' => $deviceId,
        'user_id'   => $user['id'],
        'command_id' => $commandId,
    ]);

    echo json_encode([
        'success'    => true,
        'message'    => 'Подтверждение отправлено устройству',
        'command_id' => $commandId,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    Logger::error('smoke-confirmed API error', ['error' => $e->getMessage()]);
}
