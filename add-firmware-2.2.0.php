<?php
define('SMART_SMOKER', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = db();

// Show current state
$rows = $db->fetchAll("SELECT id, version, filename, file_size, is_active, is_required, release_date FROM firmware_updates ORDER BY id");
echo "<h3>Все записи firmware_updates:</h3><pre>" . print_r($rows, true) . "</pre>";

$now = $db->fetchOne("SELECT NOW() as now");
echo "<p>Server NOW(): " . $now['now'] . "</p>";

// Activate 2.2.0
$db->query("UPDATE firmware_updates SET is_active = 1 WHERE version = '2.2.0'");
echo "<p style='color:green'>✅ Запись 2.2.0 активирована (is_active=1)</p>";

// Verify
$latest = $db->fetchOne("SELECT id, version, is_active, release_date FROM firmware_updates WHERE is_active = 1 AND release_date <= NOW() ORDER BY release_date DESC LIMIT 1");
echo "<h3>Результат запроса check-update.php:</h3><pre>" . print_r($latest, true) . "</pre>";
