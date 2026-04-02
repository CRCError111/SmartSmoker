<?php
/**
 * API для проверки обновлений прошивки
 * GET /api/check-update.php?device_id=...&current_version=...
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth-device.php';

header('Content-Type: application/json');

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Получаем параметры
$deviceId = $_GET['device_id'] ?? '';
$apiToken = $_GET['api_token'] ?? '';
$currentVersion = $_GET['current_version'] ?? '1.0.0';

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
try {
    DeviceAuth::authenticate(['device_id' => $deviceId, 'api_token' => $apiToken]);
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized: ' . $e->getMessage()]);
    exit;
}

// Rate limiting: 10 requests per minute per device
$rateLimitKey = 'update_check_' . $deviceId;
$rateLimitWindow = 60; // 60 seconds
$rateLimitMax = 10; // 10 requests per minute

try {
    $db = db();
    
    // Check rate limit
    $recentRequests = $db->fetchOne("
        SELECT COUNT(*) as count 
        FROM firmware_downloads 
        WHERE device_id = ? 
        AND downloaded_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
    ", [$deviceId, $rateLimitWindow]);
    
    if ($recentRequests && $recentRequests['count'] >= $rateLimitMax) {
        http_response_code(429);
        echo json_encode(['error' => 'Rate limit exceeded. Maximum 10 requests per minute.']);
        exit;
    }
} catch (Exception $e) {
    // Log error but don't block the request
    logMessage('WARNING', 'Rate limit check failed: ' . $e->getMessage(), 'UPDATE');
}

try {
    $db = db();
    
    // Получаем информацию о последней прошивке
    $latestFirmware = $db->fetchOne("
        SELECT 
            fw.id, 
            fw.version, 
            fw.filename, 
            fw.file_size, 
            fw.checksum,
            fw.release_notes,
            fw.release_date,
            fw.is_required,
            fw.min_version_required
        FROM firmware_updates fw
        WHERE fw.is_active = 1 
        AND fw.release_date <= NOW()
        ORDER BY fw.release_date DESC 
        LIMIT 1
    ", []);
    
    if (!$latestFirmware) {
        echo json_encode([
            'update_available' => false,
            'message' => 'No updates available'
        ]);
        exit;
    }
    
    // Проверяем, нужно ли обновление
    $current = versionToInt($currentVersion);
    $latest = versionToInt($latestFirmware['version']);
    
    $updateAvailable = $latest > $current;
    
    $response = [
        'update_available' => $updateAvailable,
        'current_version' => $currentVersion,
        'latest_version' => $latestFirmware['version'],
        'release_notes' => $latestFirmware['release_notes'] ?? '',
        'file_size' => (int)$latestFirmware['file_size'],
        'checksum' => $latestFirmware['checksum'],
        'is_required' => (bool)($latestFirmware['is_required'] ?? 0),
        'min_version_required' => $latestFirmware['min_version_required'] ?? null,
        'download_url' => BASE_URL . '/firmware/' . $latestFirmware['filename']
    ];
    
    if ($updateAvailable) {
        $response['update'] = [
            'version' => $latestFirmware['version'],
            'size' => (int)$latestFirmware['file_size'],
            'checksum' => $latestFirmware['checksum'],
            'release_date' => $latestFirmware['release_date'],
            'release_notes' => $latestFirmware['release_notes'] ?? '',
            'download_url' => BASE_URL . '/firmware/' . $latestFirmware['filename']
        ];
    }
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Конвертирует версию в числовое значение для сравнения
 */
function versionToInt($version) {
    $parts = explode('.', $version);
    $major = isset($parts[0]) ? (int)$parts[0] : 0;
    $minor = isset($parts[1]) ? (int)$parts[1] : 0;
    $patch = isset($parts[2]) ? (int)$parts[2] : 0;
    
    return $major * 1000000 + $minor * 1000 + $patch;
}
?>