<?php
/**
 * Cron: Отправка Push-уведомлений о поджиге дымогенератора
 * Запускать каждую минуту: * * * * * php /path/to/cron/send-smoke-notifications.php
 */

define('SMART_SMOKER', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/push-manager.php';

$db = db();
$push = new PushManager($db);

// Найти устройства в режиме WAITING_SMOKE_IGNITION
// Берём последнюю запись sensor_data для каждого устройства
$smokingDevices = $db->fetchAll("
    SELECT sd.device_id, sd.mode, sd.timestamp, d.user_id, d.name as device_name
    FROM sensor_data sd
    INNER JOIN (
        SELECT device_id, MAX(id) as max_id
        FROM sensor_data
        WHERE timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        GROUP BY device_id
    ) latest ON sd.id = latest.max_id
    INNER JOIN devices d ON d.device_id = sd.device_id
    WHERE sd.mode = 'WAITING_SMOKE_IGNITION'
      AND d.user_id IS NOT NULL
");

if (empty($smokingDevices)) {
    exit(0);
}

foreach ($smokingDevices as $device) {
    $userId     = $device['user_id'];
    $deviceName = $device['device_name'] ?: $device['device_id'];

    // Проверяем, не отправляли ли уже уведомление за последние 10 минут
    $recentNotif = $db->fetchOne("
        SELECT id FROM push_notification_log
        WHERE device_id = ? AND type = 'smoke_ignition'
          AND sent_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
    ", [$device['device_id']]);

    if ($recentNotif) {
        continue; // Уже отправляли недавно
    }

    $payload = [
        'title'              => '🔥 Требуется поджиг дыма',
        'body'               => "Устройство «$deviceName» ожидает подтверждения поджига дымогенератора",
        'icon'               => '/icons/icon-192x192.png',
        'badge'              => '/icons/badge-72x72.png',
        'tag'                => 'smoke-ignition-' . $device['device_id'],
        'requireInteraction' => true,
        'url'                => '/view-device.php?id=' . urlencode($device['device_id']),
        'actions'            => [
            ['action' => 'confirm', 'title' => '✅ Подтвердить поджиг'],
            ['action' => 'dismiss', 'title' => 'Закрыть'],
        ],
    ];

    $results = $push->sendToUser($userId, $payload);

    // Логируем отправку
    $successCount = count(array_filter($results));
    if ($successCount > 0) {
        $db->insert('push_notification_log', [
            'device_id'  => $device['device_id'],
            'user_id'    => $userId,
            'type'       => 'smoke_ignition',
            'sent_count' => $successCount,
            'sent_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}

exit(0);
