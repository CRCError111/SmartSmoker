<?php
/**
 * API эндпоинт: Получение истории запусков программ
 * 
 * Ожидает GET запрос с параметрами:
 * - device_id: UUID устройства (опционально, если не указан - все устройства пользователя)
 * - limit: количество записей (по умолчанию 50)
 * - offset: смещение (по умолчанию 0)
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Защита от рекурсии и настройка окружения
require_once __DIR__ . '/api-header.php';

// api-header.php уже подключен и настраивает заголовки

// Подключение конфигурации и модулей
define('SMART_SMOKER', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Проверка аутентификации
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
    exit;
}
$userId = Auth::userId();

// Получение параметров запроса
$deviceId = $_GET['device_id'] ?? null;
$limit = (int)($_GET['limit'] ?? 50);
$offset = (int)($_GET['offset'] ?? 0);

if ($limit < 1)
    $limit = 50;
if ($limit > 500)
    $limit = 500; // Ограничение для защиты
if ($offset < 0)
    $offset = 0;

try {
    $db = db();

    // Формирование SQL запроса
    $sql = "SELECT r.*, d.name as device_name, p.program_name as program_name
            FROM runs r
            INNER JOIN devices d ON d.device_id = r.device_id
            LEFT JOIN programs p ON p.id = r.program_id
            WHERE d.user_id = ?";

    $params = [$userId];

    // Фильтрация по устройству (если указано)
    if ($deviceId) {
        $deviceCheck = $db->fetchOne(
            'SELECT id, device_id FROM devices WHERE device_id = ? AND user_id = ?',
        [$deviceId, $userId]
        );

        if (!$deviceCheck) {
            logWarning("Попытка получения истории без прав: $deviceId пользователем $userId", 'API');
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Устройство не найдено или нет прав доступа']);
            exit;
        }

        $sql .= " AND r.device_id = ?";
        $params[] = $deviceCheck['device_id'];
    }

    $sql .= " ORDER BY r.start_time DESC LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    // Получение данных
    $runs = $db->fetchAll($sql, $params);

    // Форматирование данных
    $formattedRuns = [];
    foreach ($runs as $run) {
        $formattedRuns[] = [
            'id' => $run['id'],
            'device_id' => $run['device_id'],
            'device_name' => $run['device_name'],
            'program_id' => $run['program_id'],
            'program_name' => $run['program_name'] ?? 'Неизвестная программа',
            'run_id' => $run['run_id'],
            'status' => $run['status'],
            'start_time' => $run['start_time'],
            'end_time' => $run['end_time'],
            'duration_minutes' => $run['end_time'] ? 
            round((strtotime($run['end_time']) - strtotime($run['start_time'])) / 60) : null,
            'stop_reason' => $run['stop_reason'] ?? null
        ];
    }

    // Получение общего количества записей для пагинации
    $countSql = "SELECT COUNT(*) as total 
                 FROM runs r
                 INNER JOIN devices d ON d.device_id = r.device_id
                 WHERE d.user_id = ?";

    $countParams = [$userId];

    if ($deviceId && $deviceCheck) {
        $countSql .= " AND r.device_id = ?";
        $countParams[] = $deviceCheck['device_id'];
    }

    $totalCount = $db->fetchColumn($countSql, $countParams);

    logInfo("Запрос истории запусков (лимит: $limit, смещение: $offset)", 'API');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'runs' => $formattedRuns,
        'total' => (int)$totalCount,
        'limit' => $limit,
        'offset' => $offset
    ], JSON_UNESCAPED_UNICODE);


}
catch (Exception $e) {
    logException($e, 'API');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
?>