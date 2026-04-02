<?php
/**
 * API для получения состояния устройства (для ESP32)
 * Совместимо с ТЗ Smart Smoker
 * 
 * @version 1.1 - ИСПРАВЛЕНО
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

try {
    $db = db();
    
    // Получение device_id из параметров
    $deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : null;
    
    if (empty($deviceId)) {
        http_response_code(400);
        throw new Exception('device_id обязателен');
    }

    if (!isValidUUIDv4($deviceId)) {
        http_response_code(400);
        throw new Exception('Неверный формат device_id');
    }

    // Получение информации об устройстве
    $device = $db->fetchOne(
        'SELECT 
            d.id,
            d.device_id,
            d.name,
            d.status,
            d.ip_address,
            d.last_seen
         FROM devices d
         WHERE d.device_id = ?',
        [$deviceId]
    );

    if (!$device) {
        http_response_code(404);
        throw new Exception('Устройство не найдено или недоступно');
    }
    
    // Получение последних данных датчиков
    $latestData = $db->fetchOne(
        'SELECT 
            temp_chamber,
            temp_smoke,
            temp_product,
            humidity,
            heater_active,
            smoke_gen_active,
            damper_percent,
            injection_fan,
            mode,
            timestamp
         FROM sensor_data
         WHERE device_id = ?
         ORDER BY timestamp DESC
         LIMIT 1',
        [$deviceId]
    );
    
    // Получение активного запуска программы
    $activeRun = $db->fetchOne(
        'SELECT 
            r.id,
            r.program_name,
            r.run_id,
            r.status,
            r.start_time
         FROM runs r
         WHERE r.device_id = ? AND r.status = "running"
         ORDER BY r.start_time DESC
         LIMIT 1',
        [$device['device_id']]
    );
    
    // Формирование ответа
    // Определяем mode: приоритет у реального mode из телеметрии
    $deviceMode = 'IDLE';
    if ($latestData && !empty($latestData['mode'])) {
        $deviceMode = $latestData['mode'];
    } elseif ($activeRun) {
        $deviceMode = 'RUNNING';
    }
    
    $response = [
        'success' => true,
        'device_id' => $deviceId,
        'device_name' => $device['name'],
        'status' => $device['status'],
        'ip_address' => $device['ip_address'],
        'last_seen' => $device['last_seen'],
        'mode' => $deviceMode
    ];
    
    // Добавляем данные датчиков если есть
    if ($latestData) {
        $response['temp_chamber'] = (float)$latestData['temp_chamber'];
        $response['temp_smoke'] = (float)$latestData['temp_smoke'];
        $response['temp_product'] = (float)$latestData['temp_product'];
        $response['humidity'] = (float)$latestData['humidity'];
        $response['heater_active'] = (bool)$latestData['heater_active'];
        $response['smoke_gen_active'] = (bool)$latestData['smoke_gen_active'];
        $response['damper_percent'] = (int)$latestData['damper_percent'];
        $response['injection_fan'] = (bool)$latestData['injection_fan'];
        $response['last_data_time'] = $latestData['timestamp'];
    }
    
    // Добавляем информацию о программе если запущена
    if ($activeRun) {
        $response['current_program'] = $activeRun['program_name'];
        $response['run_id'] = $activeRun['run_id'];
        $response['program_start_time'] = $activeRun['start_time'];
    }
    
    $response['timestamp'] = date('Y-m-d H:i:s');
    
    // Логирование
    Logger::debug('State requested', [
        'device_id' => $deviceId
    ]);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    Logger::error('Get state API error', [
        'error' => $e->getMessage(),
        'device_id' => $deviceId ?? 'unknown'
    ]);
}
