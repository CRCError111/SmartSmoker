<?php
/**
 * Cleanup Transfer Queue - Автоматическая очистка старых записей очереди передачи
 * 
 * Этот скрипт запускается ежедневно через cron и удаляет старые подтвержденные
 * записи из очереди передачи программ (старше 30 дней).
 * 
 * Логика:
 * 1. DELETE FROM program_transfer_queue 
 *    WHERE status='confirmed' AND created_at < NOW() - INTERVAL 30 DAY
 * 2. Логирование количества удаленных записей
 * 
 * Требования: 13.6
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Определение константы для защиты от прямого доступа
define('SMART_SMOKER', true);

// Подключение конфигурации
require_once __DIR__ . '/../config.php';

// Подключение необходимых классов
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';

/**
 * Класс для очистки очереди передачи программ
 */
class TransferQueueCleanup {
    
    /**
     * @var Database Экземпляр БД
     */
    private $db;
    
    /**
     * @var int Количество дней для хранения подтвержденных записей
     */
    private $retentionDays = 30;
    
    /**
     * Конструктор
     */
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Выполнить очистку старых записей
     * 
     * Требование 13.6: Автоматически очищать записи старше 30 дней со статусом "confirmed"
     * 
     * @return array Статистика очистки
     */
    public function cleanup() {
        $stats = [
            'deleted' => 0,
            'retention_days' => $this->retentionDays
        ];
        
        logMessage('INFO', 'Starting transfer queue cleanup', 'TRANSFER_QUEUE_CLEANUP');
        
        try {
            // Удаление старых подтвержденных записей
            $sql = "DELETE FROM program_transfer_queue 
                    WHERE status = 'confirmed' 
                    AND created_at < NOW() - INTERVAL ? DAY";
            
            $result = $this->db->query($sql, [$this->retentionDays]);
            
            // Получение количества удаленных записей
            $stats['deleted'] = $result->rowCount();
            
            // Логирование результата
            logMessage('INFO', sprintf(
                'Transfer queue cleanup completed: deleted %d records older than %d days',
                $stats['deleted'],
                $this->retentionDays
            ), 'TRANSFER_QUEUE_CLEANUP');
            
        } catch (Exception $e) {
            logMessage('ERROR', sprintf(
                'Transfer queue cleanup error: %s',
                $e->getMessage()
            ), 'TRANSFER_QUEUE_CLEANUP');
            
            throw $e;
        }
        
        return $stats;
    }
    
    /**
     * Получить статистику очереди передачи
     * 
     * @return array Статистика по статусам
     */
    public function getQueueStats() {
        $sql = "SELECT 
                    status,
                    COUNT(*) as count,
                    MIN(created_at) as oldest,
                    MAX(created_at) as newest
                FROM program_transfer_queue
                GROUP BY status";
        
        $results = $this->db->fetchAll($sql);
        
        $stats = [];
        foreach ($results as $row) {
            $stats[$row['status']] = [
                'count' => (int)$row['count'],
                'oldest' => $row['oldest'],
                'newest' => $row['newest']
            ];
        }
        
        return $stats;
    }
}

// =====================================================
// Точка входа для cron
// =====================================================

// Проверка, что скрипт запущен из командной строки
if (php_sapi_name() !== 'cli') {
    // Если запущен через веб, проверяем наличие специального ключа
    if (!isset($_GET['cron_key']) || $_GET['cron_key'] !== CRON_KEY) {
        http_response_code(403);
        exit('Access denied');
    }
}

// Создание процессора и запуск очистки
$cleanup = new TransferQueueCleanup();

// Получение статистики до очистки
echo "Transfer queue statistics before cleanup:\n";
$statsBefore = $cleanup->getQueueStats();
foreach ($statsBefore as $status => $data) {
    echo sprintf(
        "  %s: %d records (oldest: %s, newest: %s)\n",
        $status,
        $data['count'],
        $data['oldest'],
        $data['newest']
    );
}
echo "\n";

// Выполнение очистки
$stats = $cleanup->cleanup();

// Вывод результата
echo sprintf(
    "Transfer queue cleanup completed:\n" .
    "  Deleted: %d confirmed records older than %d days\n",
    $stats['deleted'],
    $stats['retention_days']
);

// Получение статистики после очистки
echo "\nTransfer queue statistics after cleanup:\n";
$statsAfter = $cleanup->getQueueStats();
foreach ($statsAfter as $status => $data) {
    echo sprintf(
        "  %s: %d records (oldest: %s, newest: %s)\n",
        $status,
        $data['count'],
        $data['oldest'],
        $data['newest']
    );
}

exit(0);

// =====================================================
// Завершение файла
// =====================================================
