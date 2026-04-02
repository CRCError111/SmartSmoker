<?php
/**
 * Command Manager - Управление командами для устройств
 * Система отправки команд на ESP32 через API
 * 
 * @version 1.0
 */

if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

class CommandManager
{
    private $db;

    /**
     * Конструктор
     * 
     * @param object $db Объект базы данных
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Добавить команду для устройства
     * 
     * @param string $deviceId ID устройства
     * @param string $type Тип команды (emergency_stop, sync_programs, start_program)
     * @param array|null $data Дополнительные данные команды
     * @return int ID созданной команды
     */
    public function addCommand($deviceId, $type, $data = null)
    {
        return $this->db->insert('device_commands', [
            'device_id' => $deviceId,
            'command_type' => $type,
            'command_data' => $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null,
            'status' => 'pending',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }

    /**
     * Получить все ожидающие команды для устройства
     * После получения команды помечаются как отправленные
     * 
     * @param string $deviceId ID устройства
     * @return array Массив команд
     */
    public function getPendingCommands($deviceId)
    {
        // Получаем все ожидающие команды
        $commands = $this->db->fetchAll(
            'SELECT id, command_type, command_data, created_at
             FROM device_commands 
             WHERE device_id = ? AND status = "pending"
             ORDER BY created_at ASC',
        [$deviceId]
        );

        if (empty($commands)) {
            return [];
        }

        $result = [];
        $commandIds = [];

        foreach ($commands as $cmd) {
            $commandIds[] = $cmd['id'];

            $commandData = [
                'id' => (int)$cmd['id'],
                'type' => $cmd['command_type'],
                'timestamp' => $cmd['created_at']
            ];

            // Добавляем дополнительные данные если есть
            if (!empty($cmd['command_data'])) {
                $data = json_decode($cmd['command_data'], true);
                if ($data) {
                    $commandData['data'] = $data;
                }
            }

            $result[] = $commandData;
        }

        // Отмечаем команды как отправленные
        if (!empty($commandIds)) {
            $placeholders = implode(',', array_fill(0, count($commandIds), '?'));
            $this->db->query(
                "UPDATE device_commands 
                 SET status = 'sent', sent_at = ? 
                 WHERE id IN ($placeholders)",
                array_merge([date('Y-m-d H:i:s')], $commandIds)
            );
        }

        return $result;
    }

    /**
     * Отметить команду как выполненную
     * 
     * @param int $commandId ID команды
     * @return bool Успех операции
     */
    public function markAsExecuted($commandId)
    {
        return $this->db->update('device_commands', [
            'status' => 'executed',
            'executed_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$commandId]);
    }

    /**
     * Получить историю команд для устройства
     * 
     * @param string $deviceId ID устройства
     * @param int $limit Количество записей
     * @return array Массив команд
     */
    public function getCommandHistory($deviceId, $limit = 50)
    {
        return $this->db->fetchAll(
            'SELECT command_type, command_data, status, created_at, sent_at, executed_at
             FROM device_commands 
             WHERE device_id = ?
             ORDER BY created_at DESC
             LIMIT ?',
        [$deviceId, $limit]
        );
    }

    /**
     * Очистить старые выполненные команды
     * 
     * @param int $daysOld Удалить команды старше N дней
     * @return int Количество удалённых записей
     */
    public function cleanupOldCommands($daysOld = 7)
    {
        $date = date('Y-m-d H:i:s', strtotime("-$daysOld days"));

        return $this->db->query(
            'DELETE FROM device_commands 
             WHERE status IN ("executed", "sent") AND created_at < ?',
        [$date]
        );
    }

    /**
     * Отменить все ожидающие команды для устройства
     * 
     * @param string $deviceId ID устройства
     * @param string|null $commandType Тип команды (опционально)
     * @return int Количество отменённых команд
     */
    public function cancelPendingCommands($deviceId, $commandType = null)
    {
        if ($commandType) {
            return $this->db->query(
                'UPDATE device_commands 
                 SET status = "cancelled" 
                 WHERE device_id = ? AND command_type = ? AND status = "pending"',
            [$deviceId, $commandType]
            );
        }
        else {
            return $this->db->query(
                'UPDATE device_commands 
                 SET status = "cancelled" 
                 WHERE device_id = ? AND status = "pending"',
            [$deviceId]
            );
        }
    }
}
