<?php
/**
 * API endpoint для получения результата обработки запроса привязки
 * Принимает GET параметр: request_id
 * Возвращает статус обработки запроса и API токен при успехе
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

    // Получение GET параметра
    $requestId = $_GET['request_id'] ?? '';

    // Валидация обязательного параметра
    if (empty($requestId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'request_id обязателен'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Валидация формата request_id (UUID v4)
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $requestId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Неверный формат request_id'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Получение запроса из таблицы binding_requests
    $request = $db->fetchOne(
        'SELECT request_id, uuid, status, api_token, message, created_at FROM binding_requests WHERE request_id = ? LIMIT 1',
        [$requestId]
    );

    // Проверка существования запроса
    if (!$request) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Запрос не найден'
        ], JSON_UNESCAPED_UNICODE);
        
        // Logger::warning('Bind result request with invalid request_id', [
        //     'request_id' => $requestId
        // ]);
        exit;
    }

    // Проверка таймаута (1 минута)
    $createdAt = strtotime($request['created_at']);
    $currentTime = time();
    $elapsedTime = $currentTime - $createdAt;
    
    if ($elapsedTime > 60 && $request['status'] === 'pending') {
        // Таймаут истек, обновляем статус на failed
        $db->update(
            'binding_requests',
            [
                'status' => 'failed',
                'message' => 'Таймаут ожидания результата (1 минута)',
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'request_id = ?',
            [$requestId]
        );
        
        $request['status'] = 'failed';
        $request['message'] = 'Таймаут ожидания результата (1 минута)';
        
        // Logger::warning('Binding request timeout', [
        //     'request_id' => $requestId,
        //     'uuid' => $request['uuid']
        // ]);
    }

    // Формирование ответа в зависимости от статуса
    $response = [
        'status' => $request['status']
    ];

    // Добавление сообщения, если есть
    if (!empty($request['message'])) {
        $response['message'] = $request['message'];
    }

    // Добавление API токена при успешной привязке
    if ($request['status'] === 'completed' && !empty($request['api_token'])) {
        $response['api_token'] = $request['api_token'];
        
        // Logger::info('Binding result retrieved successfully', [
        //     'request_id' => $requestId,
        //     'uuid' => $request['uuid']
        // ]);
    }

    // Удаление записи после получения результата (completed или failed)
    if ($request['status'] === 'completed' || $request['status'] === 'failed') {
        $db->delete('binding_requests', 'request_id = ?', [$requestId]);
        
        // Logger::info('Binding request record deleted', [
        //     'request_id' => $requestId,
        //     'status' => $request['status']
        // ]);
    }

    // Возврат JSON ответа
    http_response_code(200);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка базы данных'
    ], JSON_UNESCAPED_UNICODE);

    // Logger::error('Database error in bind-result API', [
    //     'error' => $e->getMessage(),
    //     'request_id' => $_GET['request_id'] ?? 'unknown'
    // ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Внутренняя ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);

    // Logger::error('Error in bind-result API', [
    //     'error' => $e->getMessage(),
    //     'request_id' => $_GET['request_id'] ?? 'unknown'
    // ]);
}
