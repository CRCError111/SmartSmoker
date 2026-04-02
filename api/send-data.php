<?php
/**
 * API для приема данных от ESP32
 * Совместимо с ТЗ Smart Smoker
 * 
 * @version 2.0 - JWT Authentication + Commands
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth-device.php';
require_once __DIR__ . '/../includes/command-manager.php';
require_once __DIR__ . '/../includes/telemetry-validator.php';
require_once __DIR__ . '/../includes/rate-limiter.php';
require_once __DIR__ . '/../includes/error-codes.php';

header('Content-Type: application/json');

try {
    $db = db();
    
    // Получение JSON данных
    $jsonData = file_get_contents('php://input');
    $data = json_decode($jsonData, true);
    
    if (!$data) {
        throw new Exception('Неверный формат JSON');
    }
    
    // Аутентификация устройства
    DeviceAuth::authenticate($data);

    $deviceId = trim($data['device_id'] ?? '');

    // Rate limiting: не более 120 запросов в минуту с одного устройства (~2/сек)
    if (!RateLimiter::check('telemetry_' . $deviceId, 120, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Слишком много запросов', 'error_code' => ErrorCodes::TELEMETRY_RATE_LIMITED], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Replay-защита: проверка временно́й метки (если передана)
    if (isset($data['timestamp'])) {
        $requestTime = (int)$data['timestamp'];
        $delta = abs(time() - $requestTime);
        if ($delta > 300) {
            Logger::warning('Telemetry replay attack detected', [
                'device_id'    => $deviceId,
                'request_time' => $requestTime,
                'server_time'  => time(),
                'delta'        => $delta,
                'ip'           => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            http_response_code(400);
            throw new Exception('Временна́я метка запроса устарела (допустимо ±5 минут)');
        }
    }

    // Строгая валидация данных телеметрии — отклоняем при ошибке
    try {
        $data = TelemetryValidator::validate($data);
    } catch (Exception $validationError) {
        Logger::warning('Telemetry rejected: invalid sensor data', [
            'device_id' => $deviceId,
            'error'     => $validationError->getMessage(),
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        http_response_code(422);
        throw new Exception('Данные телеметрии вне допустимого диапазона: ' . $validationError->getMessage());
    }
    
    // Проверка существования устройства и его статуса (должен быть 'active')
    $device = $db->fetchOne(
        'SELECT id, status FROM devices WHERE device_id = ?',
        [$deviceId]
    );
    
    if (!$device) {
        throw new Exception('Устройство не найдено');
    }
    
    // Проверка статуса устройства (должно быть 'active')
    if ($device['status'] !== 'active') {
        throw new Exception('Устройство не привязано или неактивно');
    }
    
    // Подготовка данных для вставки
    // Поддержка как старого, так и нового формата
    $sensorData = [
        'device_id' => $deviceId
    ];
    
    // Новый формат (структурированный)
    if (isset($data['sensors'])) {
        $sensorData['temp_chamber'] = isset($data['sensors']['temp_chamber']) ? (float)$data['sensors']['temp_chamber'] : null;
        $sensorData['temp_smoke'] = isset($data['sensors']['temp_smoke']) ? (float)$data['sensors']['temp_smoke'] : null;
        $sensorData['temp_product'] = isset($data['sensors']['temp_product']) ? (float)$data['sensors']['temp_product'] : null;
        $sensorData['humidity'] = isset($data['sensors']['humidity']) ? (float)$data['sensors']['humidity'] : null;
    } else {
        // Старый формат (обратная совместимость)
        $sensorData['temp_chamber'] = isset($data['temp_chamber']) ? (float)$data['temp_chamber'] : null;
        $sensorData['temp_smoke'] = isset($data['temp_smoke']) ? (float)$data['temp_smoke'] : null;
        $sensorData['temp_product'] = isset($data['temp_product']) ? (float)$data['temp_product'] : null;
        $sensorData['humidity'] = isset($data['humidity']) ? (float)$data['humidity'] : null;
    }
    
    // Исполнительные механизмы
    if (isset($data['actuators'])) {
        $sensorData['heater_active'] = isset($data['actuators']['heater_on']) ? (int)$data['actuators']['heater_on'] : 0;
        $sensorData['smoke_gen_active'] = isset($data['actuators']['smoke_pwm']) && $data['actuators']['smoke_pwm'] > 0 ? 1 : 0;
        $sensorData['smoke_pwm'] = isset($data['actuators']['smoke_pwm']) ? (int)$data['actuators']['smoke_pwm'] : 0;
        $sensorData['damper_percent'] = isset($data['actuators']['damper_position']) ? (int)$data['actuators']['damper_position'] : 100;
        $sensorData['injection_fan'] = isset($data['actuators']['fan_injection_on']) ? (int)$data['actuators']['fan_injection_on'] : 0;
        $sensorData['fan_internal_on'] = isset($data['actuators']['fan_internal_on']) ? (int)$data['actuators']['fan_internal_on'] : 0;
    } else {
        // Старый формат
        $sensorData['heater_active'] = isset($data['heater_active']) ? (int)$data['heater_active'] : 0;
        $sensorData['smoke_gen_active'] = isset($data['smoke_gen_active']) ? (int)$data['smoke_gen_active'] : 0;
        $sensorData['damper_percent'] = isset($data['damper_percent']) ? (int)$data['damper_percent'] : 100;
        $sensorData['injection_fan'] = isset($data['injection_fan']) ? (int)$data['injection_fan'] : 0;
    }
    
    // Системная информация
    if (isset($data['system'])) {
        $sensorData['mode'] = isset($data['system']['mode']) ? $data['system']['mode'] : null;
        $sensorData['current_program'] = isset($data['system']['current_program']) ? $data['system']['current_program'] : null;
        $sensorData['current_stage'] = isset($data['system']['current_stage']) ? (int)$data['system']['current_stage'] : null;
        $sensorData['stage_progress'] = isset($data['system']['stage_progress']) ? (int)$data['system']['stage_progress'] : null;
        $sensorData['uptime'] = isset($data['system']['uptime']) ? (int)$data['system']['uptime'] : null;
        // run_id — привязка телеметрии к конкретному запуску программы
        $sensorData['run_id'] = isset($data['system']['run_id']) && !empty($data['system']['run_id'])
            ? $data['system']['run_id'] : null;
    }
    
    // Вставка данных
    $insertId = $db->insert('sensor_data', $sensorData);
    
    // Обновление статуса устройства
    $db->update('devices', [
        'last_seen' => date('Y-m-d H:i:s'),
        'status' => 'active'
    ], 'device_id = ?', [$deviceId]);
    
    // Получение команд для устройства
    $commandManager = new CommandManager($db);
    $commands = $commandManager->getPendingCommands($deviceId);
    
    // Обработка подтверждений выполненных команд от ESP32 (ТЗ п.2.4.2)
    if (!empty($data['executed_commands']) && is_array($data['executed_commands'])) {
        foreach ($data['executed_commands'] as $cmdId) {
            $cmdId = (int)$cmdId;
            if ($cmdId > 0) {
                $commandManager->markAsExecuted($cmdId);
            }
        }
    }
    
    // Логирование
    Logger::debug('Sensor data received', [
        'device_id' => $deviceId,
        'insert_id' => $insertId,
        'commands_sent' => count($commands)
    ]);
    
    // Ответ с командами
    echo json_encode([
        'success' => true,
        'message' => 'Данные успешно сохранены',
        'timestamp' => date('Y-m-d H:i:s'),
        'server_time' => date('Y-m-d H:i:s'),
        'commands' => $commands
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    $errorMessage = $e->getMessage();
    $statusCode = 400; // По умолчанию Bad Request
    
    // Определить код ответа на основе сообщения об ошибке
    if (strpos($errorMessage, 'Невалидный или истёкший токен') !== false || 
        strpos($errorMessage, 'device_token или api_token обязателен') !== false ||
        strpos($errorMessage, 'device_id не совпадает с токеном') !== false ||
        strpos($errorMessage, 'Неверный API токен') !== false ||
        strpos($errorMessage, 'Устройство отвязано') !== false) {
        $statusCode = 401; // Unauthorized
    } elseif (strpos($errorMessage, 'Устройство не найдено') !== false ||
              strpos($errorMessage, 'Устройство не привязано или неактивно') !== false) {
        $statusCode = 404; // Not Found
    }
    
    http_response_code($statusCode);
    
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'error_code' => ErrorCodes::INTERNAL_ERROR,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
    Logger::error('Send data API error', [
        'error' => $errorMessage,
        'status_code' => $statusCode,
        'data' => $jsonData ?? 'no data'
    ]);
}
