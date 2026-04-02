<?php
/**
 * Check and create device_id index
 */

define('SMART_SMOKER', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = db();

$result = $db->query('SHOW INDEX FROM devices WHERE Column_name = "device_id"');
$indexes = $result->fetchAll();

echo "Indexes on device_id:\n";
print_r($indexes);

if (empty($indexes)) {
    echo "\nNo index found. Creating unique index...\n";
    try {
        $db->query('ALTER TABLE devices ADD UNIQUE INDEX idx_device_id (device_id)');
        echo "Index created successfully!\n";
    } catch (Exception $e) {
        echo "Error creating index: " . $e->getMessage() . "\n";
    }
} else {
    echo "\nIndex already exists.\n";
}
