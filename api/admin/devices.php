<?php
/**
 * API для управления устройствами (только для админов)
 * 
 * @version 1.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/logger.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin-auth.php';

header('Content-Type: application/json');

try {
    // Требуется авторизация администратора
    AdminAuth::requireAdmin();
    
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'DELETE':
            // Удаление устройства
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['device_id'])) {
                throw new Exception('Не указан ID устройства');
            }
            
            $deviceId = (int)$data['device_id'];
            
            // Получение информации об устройстве
            $device = $db->fetchOne(
                'SELECT d.*, u.full_name as owner_name, u.email as owner_email
                 FROM devices d
                 JOIN users u ON u.id = d.user_id
                 WHERE d.id = ?',
                [$deviceId]
            );
            
            if (!$device) {
                throw new Exception('Устройство не найдено');
            }
            
            // КРИТИЧЕСКАЯ ПРОВЕРКА: Запрет удаления устройства в статусе "отвязывается"
            // Даже администратор не может удалить устройство, пока оно отвязывается
            if ($device['unbound'] == 1) {
                throw new Exception('Невозможно удалить устройство в статусе "Отвязывается". Контроллер должен завершить процесс отвязки (до 5 минут). После этого устройство можно будет удалить.');
            }
            
            // Начало транзакции
            $db->beginTransaction();
            
            try {
                // Подсчёт удаляемых данных
                $programsCount = $db->fetchColumn(
                    'SELECT COUNT(*) FROM programs WHERE device_id = ?',
                    [$deviceId]
                );
                
                $runsCount = $db->fetchColumn(
                    'SELECT COUNT(*) FROM runs WHERE device_id = ?',
                    [$deviceId]
                );
                
                $dataCount = $db->fetchColumn(
                    'SELECT COUNT(*) FROM sensor_data WHERE device_id = ?',
                    [$device['device_id']]
                );
                
                // Удаление связанных данных
                
                // 1. Удалить данные датчиков
                $db->delete('sensor_data', 'device_id = ?', [$device['device_id']]);
                
                // 2. Удалить запуски
                $db->delete('runs', 'device_id = ?', [$deviceId]);
                
                // 3. Удалить этапы программ
                $programIds = $db->fetchAll(
                    'SELECT id FROM programs WHERE device_id = ?',
                    [$deviceId]
                );
                
                foreach ($programIds as $program) {
                    $db->delete('program_stages', 'program_id = ?', [$program['id']]);
                }
                
                // 4. Удалить программы
                $db->delete('programs', 'device_id = ?', [$deviceId]);
                
                // 5. Удалить устройство
                $db->delete('devices', 'id = ?', [$deviceId]);
                
                // Фиксация транзакции
                $db->commit();
                
                AdminAuth::logAction('device_deleted', 'device', $deviceId, [
                    'device_name' => $device['name'],
                    'device_id' => $device['device_id'],
                    'owner' => $device['owner_name'],
                    'owner_email' => $device['owner_email'],
                    'programs_deleted' => $programsCount,
                    'runs_deleted' => $runsCount,
                    'data_deleted' => $dataCount
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Устройство удалено',
                    'deleted' => [
                        'programs' => $programsCount,
                        'runs' => $runsCount,
                        'data_points' => $dataCount
                    ]
                ]);
                
            } catch (Exception $e) {
                $db->rollback();
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Метод не поддерживается');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    
    logException($e, 'ADMIN_API');
}
