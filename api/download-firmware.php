<?php
/**
 * API для загрузки прошивки
 * GET /api/download-firmware.php?device_id=...&api_token=...&version=...
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth-device.php';

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Method not allowed';
    exit;
}

// Получаем параметры
$version = $_GET['version'] ?? '';
$deviceId = $_GET['device_id'] ?? '';
$apiToken = $_GET['api_token'] ?? '';

if (empty($version)) {
    http_response_code(400);
    echo json_encode(['error' => 'Version parameter is required']);
    exit;
}

if (empty($deviceId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Device ID is required']);
    exit;
}

if (!isValidUUIDv4($deviceId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Неверный формат device_id']);
    exit;
}

// Аутентификация устройства
try {
    DeviceAuth::authenticate(['device_id' => $deviceId, 'api_token' => $apiToken]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: ' . $e->getMessage()]);
    exit;
}

try {
    $db = db();

    // Rate limiting: не более 3 загрузок в час с одного устройства
    require_once __DIR__ . '/../includes/rate-limiter.php';
    if (!RateLimiter::check('firmware_dl_' . $deviceId, 3, 3600)) {
        http_response_code(429);
        echo json_encode(['error' => 'Превышен лимит загрузок (3 в час)']);
        exit;
    }

    // Получаем информацию о прошивке
    $firmware = $db->fetchOne("
        SELECT 
            fw.filename, 
            fw.file_path, 
            fw.file_size, 
            fw.checksum,
            fw.mime_type
        FROM firmware_updates fw
        WHERE fw.version = ? 
        AND fw.is_active = 1 
        AND fw.release_date <= NOW()
    ", [$version]);

    if (!$firmware) {
        http_response_code(404);
        echo json_encode(['error' => 'Firmware not found']);
        exit;
    }

    $filePath = $firmware['file_path'];

    // Защита от path traversal
    $firmwareDir = defined('FIRMWARE_DIR') ? realpath(FIRMWARE_DIR) : realpath(__DIR__ . '/../build');
    $realFilePath = realpath($filePath);
    if ($realFilePath === false || $firmwareDir === false || strpos($realFilePath, $firmwareDir) !== 0) {
        Logger::warning('Path traversal attempt in firmware download', [
            'device_id' => $deviceId,
            'file_path' => $filePath,
            'ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ]);
        http_response_code(403);
        echo json_encode(['error' => 'Доступ запрещён']);
        exit;
    }

    // Проверяем существование файла
    if (!file_exists($realFilePath)) {
        logMessage('ERROR', "Firmware file missing: $realFilePath", 'FIRMWARE');
        http_response_code(500);
        echo json_encode(['error' => 'Firmware file not found on server']);
        exit;
    }
    
    // Логируем скачивание
    $db->insert('firmware_downloads', [
        'firmware_version' => $version,
        'device_id' => $deviceId,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'downloaded_at' => date('Y-m-d H:i:s')
    ]);
    
    logMessage('INFO', "Firmware download started: version=$version, device=$deviceId", 'FIRMWARE');
    
    // Отправляем файл
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $firmware['filename'] . '"');
    header('Content-Length: ' . $firmware['file_size']);
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');
    header('X-Checksum: ' . $firmware['checksum']);
    
    // Читаем и отправляем файл
    readfile($realFilePath);
    
} catch (Exception $e) {
    logMessage('ERROR', 'Firmware download error: ' . $e->getMessage(), 'FIRMWARE');
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?>