<?php
/**
 * API эндпоинт: Аварийная остановка программы с сайта
 * 
 * Ожидает POST запрос с JSON данными:
 * {
 *   "device_id": "uuid",
 *   "reason": "Причина остановки (опционально)"
 * }
 * 
 * @version 2.0 - Commands System
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/api-header.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/command-manager.php';

// Проверка метода запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не разрешен']);
    exit;
}

// Чтение и декодирование JSON данных
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

// Попытка аутентификации (либо пользователь, либо устройство)
$isDevice = isset($data['device_token']) && !empty($data['device_token']);
$userId = null;
$deviceId = $data['device_id'] ?? null;

if (!$deviceId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'device_id обязателен']);
    exit;
}

try {
    if ($isDevice) {
        // Аутентификация устройства (ТЗ п.2.4.3)
        $payload = DeviceAuth::authenticate($data);
        $db = db();
        $device = $db->fetchOne('SELECT id, user_id FROM devices WHERE device_id = ?', [$deviceId]);
        if (!$device) {
            throw new Exception('Устройство не найдено');
        }
        $userId = $device['user_id'];
        $dbDeviceId = $device['id'];
    }
    else {
        // Аутентификация пользователя (веб-интерфейс)
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $userId = Auth::userId();
        $db = db();
        $device = $db->fetchOne(
            'SELECT id FROM devices WHERE device_id = ? AND user_id = ?',
        [$deviceId, $userId]
        );
        if (!$device) {
            throw new Exception('Устройство не найдено или нет прав доступа');
        }
        $dbDeviceId = $device['id'];
    }
}
catch (Exception $e) {
    logWarning("Ошибка аутентификации при аварийной остановке: " . $e->getMessage(), 'API');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

try {
    $reason = $data['reason'] ?? 'Пользовательская аварийная остановка';

    // Создание команды аварийной остановки
    $commandManager = new CommandManager($db);
    $commandId = $commandManager->addCommand(
        $data['device_id'],
        'emergency_stop',
    ['reason' => $reason]
    );

    // Остановка всех активных запусков для устройства
    $db->update(
        'runs',
        [
            'status' => 'stopped',
            'end_time' => date('Y-m-d H:i:s'),
            'stop_reason' => $reason
        ],
        'device_id = ? AND status = "running"',
        [$data['device_id']]
    );

    // Логирование аварийной остановки
    logCritical("Аварийная остановка устройства {$data['device_id']}: $reason", 'API');

    // Отправка уведомления на почту
    $user = $db->fetchOne('SELECT email, full_name FROM users WHERE id = ?', [$userId]);
    if ($user) {
        require_once __DIR__ . '/../includes/mail.php';

        // Отправка email уведомления о аварийной остановке с использованием системы уведомлений (ТЗ п.4.2.3)
        $deviceName = $db->fetchColumn('SELECT name FROM devices WHERE device_id = ?', [$data['device_id']]);
        if (!$deviceName) {
            $deviceName = $data['device_id'];
        }

        sendEmergencyStopNotification($user['email'], $user['full_name'], $deviceName, $reason);
        logInfo("Email уведомление об аварийной остановке отправлено на {$user['email']}", 'EMERGENCY');
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Команда аварийной остановки отправлена',
        'device_id' => $data['device_id'],
        'command_id' => $commandId,
        'note' => 'Команда будет выполнена при следующем запросе устройства'
    ]);

}
catch (Exception $e) {
    logException($e, 'API');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
?>