<?php
// Direct DB check - no auth required
$pdo = new PDO('mysql:host=localhost;dbname=u3385152_default;charset=utf8', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$all = $pdo->query("SELECT id, version, filename, file_size, is_active, is_required, release_date FROM firmware_updates ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
echo json_encode(['all' => $all, 'now' => date('Y-m-d H:i:s')], JSON_PRETTY_PRINT);

$latest = $pdo->query("SELECT id, version, is_active, release_date FROM firmware_updates WHERE is_active = 1 AND release_date <= NOW() ORDER BY release_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
echo "\n\nLatest active: " . json_encode($latest);
