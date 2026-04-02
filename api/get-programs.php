<?php
/**
 * API для получения программ копчения (для ESP32)
 * Совместимо с ТЗ Smart Smoker
 * 
 * @version 2.1 - JWT Authentication + Incremental Sync + Delivery Tracking
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth-device.php';

header('Content-Type: application/json');

try {
    $db = db();
    
    // Получение параметров
    $deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : null;
    $lastSync = isset($_GET['last_sync']) ? trim($_GET['last_sync']) : null;
    
    if (empty($deviceId)) {
        throw new Exception('device_id обязателен');
    }
    
    // H-08 / C-06: Аутентификация обязательна.
    // Токен читается из заголовка Authorization: Bearer (приоритет) или GET-параметра api_token
    $apiToken = DeviceAuth::getBearerToken() ?? ($_GET['api_token'] ?? null);
    
    if (empty($apiToken)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Аутентификация обязательна'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    DeviceAuth::authenticate(['device_id' => $deviceId, 'api_token' => $apiToken]);
    
    // Проверка существования устройства
    $device = $db->fetchOne(
        'SELECT device_id, user_id, name FROM devices WHERE device_id = ?',
        [$deviceId]
    );
    
    if (!$device) {
        throw new Exception('Устройство не найдено');
    }
    
    // Получение программ для устройства:
    // 1. Программы привязанные к этому устройству (device_id = X)
    // 2. Общие программы пользователя (user_id = Y AND device_id IS NULL)
    // 3. Публичные шаблоны (is_public = 1)
    // 4. Встроенные программы (is_built_in = 1)
    // Если указан last_sync, получаем только изменённые после этой даты
    $query = 'SELECT 
            p.id,
            p.name,
            p.description,
            p.category,
            p.is_built_in,
            p.device_id,
            p.updated_at
         FROM programs p
         WHERE (
            p.device_id = ? OR 
            (p.user_id = ? AND p.device_id IS NULL) OR 
            p.is_public = 1 OR 
            p.is_built_in = 1
         )';
    
    $params = [$device['device_id'], $device['user_id']];
    
    if ($lastSync) {
        $query .= ' AND p.updated_at > ?';
        $params[] = $lastSync;
    }
    
    $query .= ' ORDER BY p.is_built_in DESC, p.name ASC';
    
    $programs = $db->fetchAll($query, $params);
    
    // Для каждой программы получаем этапы
    $programsWithStages = [];
    $programNames = [];
    
    foreach ($programs as $program) {
        $stages = $db->fetchAll(
            'SELECT 
                stage_order,
                stage_name,
                target_temp,
                target_temp_device,
                target_humidity,
                duration_minutes,
                hysteresis,
                wait_for_temp,
                use_smoke_generator,
                ventilation_percent,
                internal_fan_on,
                injection_fan_on,
                compressor_pwm
             FROM program_stages
             WHERE program_id = ?
             ORDER BY stage_order ASC',
            [$program['id']]
        );
        
        // Преобразуем этапы в формат для ESP32
        $formattedStages = [];
        foreach ($stages as $stage) {
            $formattedStages[] = [
                'stage_name' => $stage['stage_name'],
                'target_temp' => (float)$stage['target_temp'],
                'target_temp_device' => (int)$stage['target_temp_device'],
                'target_humidity' => (float)$stage['target_humidity'],
                'duration_minutes' => (int)$stage['duration_minutes'],
                'hysteresis' => (float)$stage['hysteresis'],
                'wait_for_temp' => (bool)$stage['wait_for_temp'],
                'use_smoke_generator' => (bool)$stage['use_smoke_generator'],
                'ventilation_percent' => (int)$stage['ventilation_percent'],
                'internal_fan_on' => (bool)$stage['internal_fan_on'],
                'injection_fan_on' => (bool)$stage['injection_fan_on'],
                'compressor_pwm' => (int)$stage['compressor_pwm']
            ];
        }
        
        $programName = $program['name'];
        $programNames[] = $programName;
        
        $programsWithStages[] = [
            'name' => $programName,
            'description' => $program['description'] ?? '',
            'category' => $program['category'] ?? 'general',
            'updated_at' => $program['updated_at'],
            'stages' => $formattedStages
        ];
    }
    
    // TODO: Получить список удалённых программ (требует отдельной таблицы deleted_programs)
    $deletedPrograms = [];
    
    logInfo('Programs requested by ESP32: device_id=' . $deviceId . ', count=' . count($programsWithStages), 'GET_PROGRAMS');
    
    // Ответ в формате совместимом с ESP32
    echo json_encode([
        'success' => true,
        'device_id' => $deviceId,
        'programs' => $programsWithStages,
        'deleted_programs' => $deletedPrograms,
        'server_time' => date('Y-m-d H:i:s'),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    logError('Get programs API error: ' . $e->getMessage() . ', device_id=' . ($deviceId ?? 'unknown'), 'GET_PROGRAMS');
}
