<?php
/**
 * Миграция: перехэширование api_token в таблице devices.
 * Запускать ОДНОКРАТНО после деплоя изменений в bind-device.php и auth-device.php.
 *
 * Использование: php migrations/rehash-tokens.php
 */
define('SMART_SMOKER', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';

$db = db();

// Выбираем токены, которые ещё не хэшированы (длина != 64 символа SHA256)
$devices = $db->fetchAll(
    "SELECT device_id, api_token FROM devices WHERE api_token IS NOT NULL AND LENGTH(api_token) != 64"
);

if (empty($devices)) {
    echo "Нет токенов для перехэширования.\n";
    exit(0);
}

$updated = 0;
foreach ($devices as $device) {
    $hash = hash('sha256', $device['api_token']);
    $db->execute(
        "UPDATE devices SET api_token = ? WHERE device_id = ?",
        [$hash, $device['device_id']]
    );
    $updated++;
    echo "Перехэширован токен для устройства: {$device['device_id']}\n";
}

echo "Готово. Обновлено устройств: {$updated}\n";
