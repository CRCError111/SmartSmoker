<?php
/**
 * API: Удаление Push-подписки
 * POST /api/push-unsubscribe.php
 * Body: {"endpoint": "..."}
 */

define('SMART_SMOKER', true);
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

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

$body = json_decode(file_get_contents('php://input'), true);
$endpoint = $body['endpoint'] ?? '';

if (!$endpoint) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing endpoint']);
    exit;
}

$userId = $_SESSION['user_id'];
$db = db();

$db->query(
    'DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?',
    [$endpoint, $userId]
);

echo json_encode(['success' => true]);
