<?php
/**
 * API эндпоинт: Получение данных с датчиков для графиков
 * 
 * Ожидает GET запрос с параметрами:
 * - device_id: UUID устройства
 * - hours: количество часов для выборки (по умолчанию 24)
 * - interval: интервал агрегации в минутах (опционально)
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
$deviceId = $_GET['device_id'] ?? '';
$hours = (int)($_GET['hours'] ?? 24);
$interval = (int)($_GET['interval'] ?? 0); // 0 = без агрегации

if (empty($deviceId)) {
    logError('Не указан device_id для получения данных датчиков', 'API');
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Не указан device_id']);
    exit;
}

if ($hours < 1 || $hours > 168) { // Максимум 7 дней
    $hours = 24;
}

try {
    $db = db();

    // Проверка прав доступа к устройству
    $device = $db->fetchOne(
        'SELECT id FROM devices WHERE device_id = ? AND user_id = ?',
    [$deviceId, $userId]
    );

    if (!$device) {
        logWarning("Попытка получения данных без прав: $deviceId пользователем $userId", 'API');
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Устройство не найдено или нет прав доступа']);
        exit;
    }

    // Получение данных с датчиков за указанный период
    $timeLimit = date('Y-m-d H:i:s', strtotime("-$hours hours"));

    if ($interval > 0) {
        // Агрегация данных (усреднение по интервалам)
        $sql = "SELECT 
                    DATE_FORMAT(timestamp, '%%Y-%%m-%%d %%H:%%i:00') as time_group,
                    AVG(temp_chamber) as temp_chamber,
                    AVG(temp_smoke) as temp_smoke,
                    AVG(temp_product) as temp_product,
                    AVG(humidity) as humidity,
                    MAX(heater_active) as heater_active,
                    MAX(smoke_gen_active) as smoke_gen_active,
                    AVG(damper_percent) as damper_percent,
                    MAX(injection_fan) as injection_fan
                FROM sensor_data 
                WHERE device_id = ? AND timestamp >= ?
                GROUP BY time_group
                ORDER BY timestamp ASC";

        $data = $db->fetchAll($sql, [$deviceId, $timeLimit]);
    }
    else {
        // Простая выборка без агрегации
        $data = $db->fetchAll(
            'SELECT timestamp, temp_chamber, temp_smoke, temp_product, humidity, 
                    heater_active, smoke_gen_active, damper_percent, injection_fan
             FROM sensor_data 
             WHERE device_id = ? AND timestamp >= ?
             ORDER BY timestamp ASC
             LIMIT 10000', // Ограничение для защиты от перегрузки
        [$deviceId, $timeLimit]
        );
    }

    // Форматирование данных для графиков
    $formattedData = [
        'timestamps' => [],
        'tempChamber' => [],
        'tempSmoke' => [],
        'tempProduct' => [],
        'humidity' => [],
        'heaterActive' => [],
        'smokeActive' => [],
        'damperPercent' => [],
        'injectionFan' => []
    ];

    foreach ($data as $row) {
        $formattedData['timestamps'][] = $row['timestamp'] ?? $row['time_group'];
        $formattedData['tempChamber'][] = round($row['temp_chamber'], 1);
        $formattedData['tempSmoke'][] = round($row['temp_smoke'], 1);
        $formattedData['tempProduct'][] = round($row['temp_product'], 1);
        $formattedData['humidity'][] = round($row['humidity'], 1);
        $formattedData['heaterActive'][] = (int)$row['heater_active'];
        $formattedData['smokeActive'][] = (int)$row['smoke_gen_active'];
        $formattedData['damperPercent'][] = (int)$row['damper_percent'];
        $formattedData['injectionFan'][] = (int)$row['injection_fan'];
    }

    logInfo("Запрос данных датчиков для устройства $deviceId за $hours часов", 'API');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'device_id' => $deviceId,
        'hours' => $hours,
        'data' => $formattedData,
        'count' => count($data)
    ], JSON_UNESCAPED_UNICODE);


}
catch (Exception $e) {
    logException($e, 'API');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Внутренняя ошибка сервера']);
}
?>