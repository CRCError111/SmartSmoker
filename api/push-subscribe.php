<?php
/**
 * API: Сохранение Push-подписки
 * POST /api/push-subscribe.php
 * Body: JSON объект PushSubscription
 */

define('SMART_SMOKER', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json');

// Только авторизованные пользователи
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$body = file_get_contents('php://input');
$subscription = json_decode($body, true);

if (!$subscription || empty($subscription['endpoint'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid subscription data']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = db();

// Upsert подписки
$endpoint = $subscription['endpoint'];
$p256dh   = $subscription['keys']['p256dh'] ?? '';
$auth     = $subscription['keys']['auth'] ?? '';

if (!$p256dh || !$auth) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing keys']);
    exit;
}

$existing = $db->fetchOne('SELECT id FROM push_subscriptions WHERE endpoint = ?', [$endpoint]);

if ($existing) {
    $db->update('push_subscriptions', [
        'user_id' => $userId,
        'p256dh'  => $p256dh,
        'auth'    => $auth,
    ], 'id = ?', [$existing['id']]);
} else {
    $db->insert('push_subscriptions', [
        'user_id'  => $userId,
        'endpoint' => $endpoint,
        'p256dh'   => $p256dh,
        'auth'     => $auth,
    ]);
}

echo json_encode(['success' => true]);
