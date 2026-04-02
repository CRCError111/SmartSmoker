<?php
/**
 * API endpoint для приема запросов привязки устройства от контроллера
 * Принимает POST параметры: uuid, login, password
 * Валидирует учетные данные и создает запрос привязки
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
    $db = db();

    // Получение POST параметров из JSON body
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Fallback to $_POST if JSON parsing fails
    if (json_last_error() !== JSON_ERROR_NONE) {
        $data = $_POST;
    }
    
    $uuid = $data['uuid'] ?? '';
    $login = $data['login'] ?? '';
    $password = $data['password'] ?? '';

    // Валидация обязательных параметров
    if (empty($uuid)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'UUID обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($login)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Логин обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (empty($password)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Пароль обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация формата UUID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный формат UUID'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация учетных данных пользователя
    // Логин может быть email или username
    $user = null;
    
    // Попытка найти по email
    if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
        $user = $db->fetchOne(
            'SELECT id, email, password_hash, is_active FROM users WHERE email = ? LIMIT 1',
            [$login]
        );
    }
    
    // Если не найден по email, попробовать по username
    if (!$user) {
        $user = $db->fetchOne(
            'SELECT id, email, password_hash, is_active FROM users WHERE username = ? LIMIT 1',
            [$login]
        );
    }

    // Проверка существования пользователя
    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Неверные учетные данные'
        ], JSON_UNESCAPED_UNICODE);
        
        // Logger::warning('Bind request with invalid login', [
        //     'uuid' => $uuid,
        //     'login' => $login
        // ]);
        exit;
    }

    // Проверка активности пользователя
    if (!$user['is_active']) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Учетная запись неактивна'
        ], JSON_UNESCAPED_UNICODE);
        
        // Logger::warning('Bind request with inactive user', [
        //     'uuid' => $uuid,
        //     'user_id' => $user['id']
        // ]);
        exit;
    }

    // Проверка пароля
    if (!password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => 'Неверные учетные данные'
        ], JSON_UNESCAPED_UNICODE);
        
        // Logger::warning('Bind request with invalid password', [
        //     'uuid' => $uuid,
        //     'user_id' => $user['id']
        // ]);
        exit;
    }

    // Генерация уникального request_id (UUID v4)
    $requestId = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    // Генерация уникального API токена (64 символа, криптографически безопасный)
    $apiToken = bin2hex(random_bytes(32));

    // СИНХРОННАЯ ОБРАБОТКА: сразу создаем/обновляем устройство
    // Проверка существования UUID в базе данных
    $existingDevice = $db->fetchOne(
        'SELECT id, device_id, user_id FROM devices WHERE device_id = ? LIMIT 1',
        [$uuid]
    );
    
    if ($existingDevice) {
        // UUID exists - check if it belongs to the same user
        if ($existingDevice['user_id'] == $user['id']) {
            // Same user - just update binding
            $db->update(
                'devices',
                [
                    'api_token' => $apiToken,
                    'unbound' => 0,
                    'status' => 'active',
                    'bound_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ],
                'device_id = ?',
                [$uuid]
            );
            logInfo("Device re-bound (same user): device_id=$uuid, user_id=$user[id]", 'BINDING');
        } else {
            // Different user - delete all data and create new binding
            $db->beginTransaction();
            
            try {
                // Delete all programs for this device_id
                $programsDeleted = $db->delete('programs', 'device_id = ?', [$uuid]);
                
                // Delete all runs for this device_id
                $runsDeleted = $db->delete('runs', 'device_id = ?', [$uuid]);
                
                // Delete all sensor_data for this device_id
                $sensorDataDeleted = $db->delete('sensor_data', 'device_id = ?', [$uuid]);
                
                // Update device record with new user
                $db->update(
                    'devices',
                    [
                        'user_id' => $user['id'],
                        'api_token' => $apiToken,
                        'unbound' => 0,
                        'status' => 'active',
                        'bound_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    'device_id = ?',
                    [$uuid]
                );
                
                $db->commit();
                
                logInfo("Device transferred to new user: device_id=$uuid, old_user_id=$existingDevice[user_id], new_user_id=$user[id], programs_deleted=$programsDeleted, runs_deleted=$runsDeleted, sensor_data_deleted=$sensorDataDeleted", 'BINDING');
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
        }
    } else {
        // UUID doesn't exist - insert new record
        $db->insert('devices', [
            'device_id' => $uuid,
            'user_id' => $user['id'],
            'api_token' => $apiToken,
            'unbound' => 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
        logInfo("New device created: device_id=$uuid, user_id=$user[id]", 'BINDING');
    }

    // Сохранение запроса в таблицу binding_requests со статусом "completed"
    $db->insert('binding_requests', [
        'request_id' => $requestId,
        'uuid' => $uuid,
        'user_id' => $user['id'],
        'status' => 'completed',
        'api_token' => $apiToken,
        'message' => 'Устройство успешно привязано',
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ]);

    // Логирование успешного создания запроса
    // Logger::info('Binding request created', [
    //     'request_id' => $requestId,
    //     'uuid' => $uuid,
    //     'user_id' => $user['id']
    // ]);

    // Возврат JSON ответа с request_id
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'request_id' => $requestId
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка базы данных'
    ], JSON_UNESCAPED_UNICODE);

    // Logger::error('Database error in bind-request API', [
    //     'error' => $e->getMessage(),
    //     'uuid' => $_POST['uuid'] ?? 'unknown'
    // ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);

    // Logger::error('Error in bind-request API', [
    //     'error' => $e->getMessage(),
    //     'uuid' => $_POST['uuid'] ?? 'unknown'
    // ]);
}
