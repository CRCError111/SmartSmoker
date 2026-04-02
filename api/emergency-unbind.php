<?php
/**
 * API для экстренного сброса привязки контроллера
 * Используется когда устройство было удалено с сайта, но на контроллере осталась привязка
 * 
 * @version 1.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Требуется авторизация пользователя
    Auth::requireAuth();
    
    $user = Auth::user();
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        http_response_code(405);
        throw new Exception('Метод не поддерживается');
    }
    
    // Получение данных запроса
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['esp32_ip'])) {
        http_response_code(400);
        throw new Exception('Не указан IP адрес контроллера');
    }
    
    $esp32Ip = trim($data['esp32_ip']);
    
    // Валидация IP адреса
    if (!filter_var($esp32Ip, FILTER_VALIDATE_IP)) {
        http_response_code(400);
        throw new Exception('Неверный формат IP адреса');
    }

    // Защита от SSRF — запрет loopback и metadata-эндпоинтов
    $blockedIps = ['127.0.0.1', '::1', '0.0.0.0', '169.254.169.254'];
    if (in_array($esp32Ip, $blockedIps, true)) {
        http_response_code(400);
        throw new Exception('Недопустимый IP адрес');
    }
    // Запрет loopback-диапазона 127.x.x.x
    if (strpos($esp32Ip, '127.') === 0) {
        http_response_code(400);
        throw new Exception('Недопустимый IP адрес');
    }

    // Rate limiting: не более 5 запросов в минуту на пользователя
    require_once __DIR__ . '/../includes/rate-limiter.php';
    if (!RateLimiter::check('emergency_unbind_' . ($user['id'] ?? 'anon'), 5, 60)) {
        http_response_code(429);
        throw new Exception('Слишком много запросов');
    }
    
    // Попытка отправить команду сброса на контроллер (только порт 80)
    $esp32Url = 'http://' . $esp32Ip . ':80/api/unbind';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $esp32Url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'reason' => 'emergency_reset',
        'force' => true
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 секунд таймаут
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 5 секунд на подключение
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($response === false) {
        throw new Exception('Не удалось подключиться к контроллеру: ' . $curlError);
    }
    
    if ($httpCode == 200) {
        Logger::info('Emergency unbind sent to ESP32', [
            'esp32_ip' => $esp32Ip,
            'user_id' => $user['id'],
            'username' => $user['username']
        ]);
        
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Команда сброса привязки отправлена на контроллер',
            'esp32_response' => json_decode($response, true)
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception('Контроллер вернул ошибку (HTTP ' . $httpCode . '): ' . $response);
    }
    
} catch (Exception $e) {
    // HTTP код уже установлен в блоках выше, если нет - ставим 500
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    
    Logger::error('Emergency unbind API error', [
        'error' => $e->getMessage(),
        'user_id' => Auth::userId() ?? null,
        'trace' => $e->getTraceAsString()
    ]);
}
