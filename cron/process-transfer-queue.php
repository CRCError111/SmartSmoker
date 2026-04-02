<?php
/**
 * Process Transfer Queue - Обслуживание очереди передачи программ
 * 
 * Pull-модель: контроллер сам забирает программы через file-list.php + download-program.php.
 * Этот скрипт только помечает зависшие записи как failed (timeout).
 * 
 * Логика:
 * 1. Записи со статусом 'pending' старше TIMEOUT_MINUTES → status='failed'
 * 2. Записи со статусом 'sent' старше SENT_TIMEOUT_MINUTES → status='failed'
 * 3. Логирование результатов
 * 
 * Запускается каждые 5 минут через cron.
 * 
 * @version 2.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';

// Таймаут для pending записей (минуты)
define('PENDING_TIMEOUT_MINUTES', 60);
// Таймаут для sent записей (минуты) — контроллер скачал, но не подтвердил
define('SENT_TIMEOUT_MINUTES', 30);

if (php_sapi_name() !== 'cli') {
    if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== CRON_KEY) {
        http_response_code(403);
        exit('Access denied');
    }
}

$db = Database::getInstance();
$stats = ['pending_expired' => 0, 'sent_expired' => 0];

try {
    logMessage('INFO', 'Starting transfer queue maintenance', 'TRANSFER_QUEUE');

    // Помечаем зависшие pending записи как failed
    $sql = "UPDATE program_transfer_queue
            SET status = 'failed',
                failed_at = NOW(),
                error_code = 'timeout',
                error_message = 'Запись не была обработана в течение " . PENDING_TIMEOUT_MINUTES . " минут'
            WHERE status = 'pending'
              AND created_at < DATE_SUB(NOW(), INTERVAL " . PENDING_TIMEOUT_MINUTES . " MINUTE)";

    $stmt = $db->query($sql);
    $stats['pending_expired'] = $stmt->rowCount();

    // Помечаем зависшие sent записи как failed (контроллер скачал, но не подтвердил)
    $sql = "UPDATE program_transfer_queue
            SET status = 'failed',
                failed_at = NOW(),
                error_code = 'confirmation_timeout',
                error_message = 'Контроллер не подтвердил получение в течение " . SENT_TIMEOUT_MINUTES . " минут'
            WHERE status = 'sent'
              AND sent_at < DATE_SUB(NOW(), INTERVAL " . SENT_TIMEOUT_MINUTES . " MINUTE)";

    $stmt = $db->query($sql);
    $stats['sent_expired'] = $stmt->rowCount();

    logMessage('INFO', sprintf(
        'Transfer queue maintenance completed: pending_expired=%d, sent_expired=%d',
        $stats['pending_expired'],
        $stats['sent_expired']
    ), 'TRANSFER_QUEUE');

} catch (Exception $e) {
    logMessage('ERROR', 'Transfer queue maintenance error: ' . $e->getMessage(), 'TRANSFER_QUEUE');
}

echo sprintf(
    "Transfer queue maintenance completed:\n" .
    "  Pending expired: %d\n" .
    "  Sent expired: %d\n",
    $stats['pending_expired'],
    $stats['sent_expired']
);

exit(0);
