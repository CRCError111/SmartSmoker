<?php
/**
 * Sync All Devices - Периодическая синхронизация программ для всех активных устройств
 * 
 * Синхронизирует device_programs на основе подтверждённых записей
 * в program_transfer_queue (status='confirmed').
 * 
 * Запускается каждые 5 минут через cron.
 * 
 * Требования: 11.3
 * 
 * @version 2.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';

class DeviceSyncProcessor {

    private $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function syncAllDevices() {
        $stats = [
            'total_devices' => 0,
            'synced'        => 0,
            'failed'        => 0
        ];

        logMessage('INFO', 'Starting device programs synchronization', 'DEVICE_SYNC');

        try {
            $devices = $this->getActiveDevices();

            if (empty($devices)) {
                logMessage('INFO', 'No active devices found for synchronization', 'DEVICE_SYNC');
                return $stats;
            }

            $stats['total_devices'] = count($devices);
            logMessage('INFO', sprintf('Found %d active devices to sync', count($devices)), 'DEVICE_SYNC');

            foreach ($devices as $device) {
                try {
                    $this->syncDevice($device);
                    $stats['synced']++;
                } catch (Exception $e) {
                    logMessage('ERROR', sprintf(
                        'Failed to sync device %s (%s): %s',
                        $device['device_id'],
                        $device['name'],
                        $e->getMessage()
                    ), 'DEVICE_SYNC');
                    $stats['failed']++;
                }
            }

            logMessage('INFO', sprintf(
                'Device synchronization completed: total=%d, synced=%d, failed=%d',
                $stats['total_devices'],
                $stats['synced'],
                $stats['failed']
            ), 'DEVICE_SYNC');

        } catch (Exception $e) {
            logMessage('ERROR', sprintf('Device synchronization error: %s', $e->getMessage()), 'DEVICE_SYNC');
        }

        return $stats;
    }

    private function getActiveDevices() {
        return $this->db->fetchAll(
            "SELECT device_id, name, user_id FROM devices WHERE status = 'active' ORDER BY name ASC"
        );
    }

    private function syncDevice($device) {
        $deviceId = $device['device_id'];

        logMessage('INFO', sprintf('Syncing device %s (%s)', $deviceId, $device['name']), 'DEVICE_SYNC');

        $this->db->beginTransaction();

        try {
            // Удаляем старые записи
            $deleted = $this->db->delete('device_programs', 'device_id = ?', [$deviceId]);

            logMessage('DEBUG', sprintf(
                'Deleted %d old device_programs records for device %s',
                $deleted,
                $deviceId
            ), 'DEVICE_SYNC');

            // Получаем подтверждённые программы из очереди
            $confirmedPrograms = $this->db->fetchAll(
                'SELECT ptq.program_id, ptq.confirmed_at
                 FROM program_transfer_queue ptq
                 INNER JOIN programs p ON ptq.program_id = p.id
                 WHERE ptq.device_id = ? AND ptq.status = ?
                 ORDER BY ptq.confirmed_at DESC',
                [$deviceId, 'confirmed']
            );

            $insertedCount = 0;

            foreach ($confirmedPrograms as $row) {
                $programId = (int)$row['program_id'];

                $programExists = $this->db->fetchOne('SELECT id FROM programs WHERE id = ?', [$programId]);

                if (!$programExists) {
                    logMessage('WARNING', sprintf(
                        'Program %d not found in programs for device %s, skipping',
                        $programId,
                        $deviceId
                    ), 'DEVICE_SYNC');
                    continue;
                }

                $this->db->insert('device_programs', [
                    'device_id'     => $deviceId,
                    'program_id'    => $programId,
                    'storage_path'  => "/programs/program_{$programId}.json",
                    'uploaded_at'   => $row['confirmed_at'] ?? date('Y-m-d H:i:s'),
                    'last_verified' => date('Y-m-d H:i:s'),
                    'status'        => 'active'
                ]);

                $insertedCount++;
            }

            $this->db->commit();

            logMessage('INFO', sprintf(
                'Device %s synced: %d programs',
                $deviceId,
                $insertedCount
            ), 'DEVICE_SYNC');

        } catch (Exception $e) {
            $this->db->rollback();
            throw $e;
        }
    }
}

// Точка входа для cron
if (php_sapi_name() !== 'cli') {
    if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== CRON_KEY) {
        http_response_code(403);
        exit('Access denied');
    }
}

$processor = new DeviceSyncProcessor();
$stats = $processor->syncAllDevices();

echo sprintf(
    "Device synchronization completed:\n" .
    "  Total devices: %d\n" .
    "  Synced: %d\n" .
    "  Failed: %d\n",
    $stats['total_devices'],
    $stats['synced'],
    $stats['failed']
);

exit(0);
