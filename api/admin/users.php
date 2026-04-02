<?php
/**
 * API для управления пользователями (только для админов)
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
    
    // H-04: Проверка CSRF-токена для AJAX-запросов
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrf($csrfHeader)) {
        http_response_code(403);
        echo json_encode(['error' => 'Неверный CSRF-токен']);
        exit;
    }
    
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($method) {
        case 'POST':
            // Блокировка/разблокировка пользователя
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['action']) || !isset($data['user_id'])) {
                throw new Exception('Не указаны обязательные параметры');
            }
            
            $userId = (int)$data['user_id'];
            $action = $data['action'];
            
            // Проверка существования пользователя
            $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
            
            if (!$user) {
                throw new Exception('Пользователь не найден');
            }
            
            if ($action === 'block') {
                // Блокировка пользователя
                $reason = $data['reason'] ?? 'Не указана';
                
                // Нельзя заблокировать себя
                if ($userId === Auth::userId()) {
                    throw new Exception('Нельзя заблокировать свой аккаунт');
                }
                
                $db->update('users', [
                    'is_blocked' => 1,
                    'blocked_reason' => $reason,
                    'blocked_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$userId]);
                
                AdminAuth::logAction('user_blocked', 'user', $userId, [
                    'reason' => $reason,
                    'email' => $user['email']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Пользователь заблокирован'
                ]);
                
            } elseif ($action === 'unblock') {
                // Разблокировка пользователя
                $db->update('users', [
                    'is_blocked' => 0,
                    'blocked_reason' => null,
                    'blocked_at' => null
                ], 'id = ?', [$userId]);
                
                AdminAuth::logAction('user_unblocked', 'user', $userId, [
                    'email' => $user['email']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Пользователь разблокирован'
                ]);
                
            } else {
                throw new Exception('Неизвестное действие');
            }
            break;
            
        case 'DELETE':
            // Удаление пользователя
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['user_id'])) {
                throw new Exception('Не указан ID пользователя');
            }
            
            $userId = (int)$data['user_id'];
            
            // Проверка возможности удаления
            $canDelete = AdminAuth::canDeleteUser($userId);
            
            if (!$canDelete['can_delete']) {
                throw new Exception($canDelete['reason']);
            }
            
            // Получение информации о пользователе
            $user = $db->fetchOne('SELECT * FROM users WHERE id = ?', [$userId]);
            
            if (!$user) {
                throw new Exception('Пользователь не найден');
            }
            
            // Начало транзакции
            $db->beginTransaction();
            
            try {
                // Удаление связанных данных (CASCADE должен сработать автоматически)
                // Но на всякий случай удаляем вручную
                
                // Получить ID устройств пользователя
                $deviceIds = $db->fetchAll(
                    'SELECT id FROM devices WHERE user_id = ?',
                    [$userId]
                );
                
                foreach ($deviceIds as $device) {
                    // Удалить данные датчиков
                    $db->delete('sensor_data', 'device_id = (SELECT device_id FROM devices WHERE id = ?)', [$device['id']]);
                    
                    // Удалить запуски
                    $db->delete('runs', 'device_id = ?', [$device['id']]);
                }
                
                // Удалить устройства
                $db->delete('devices', 'user_id = ?', [$userId]);
                
                // Удалить программы
                $programIds = $db->fetchAll(
                    'SELECT id FROM programs WHERE user_id = ?',
                    [$userId]
                );
                
                foreach ($programIds as $program) {
                    $db->delete('program_stages', 'program_id = ?', [$program['id']]);
                }
                
                $db->delete('programs', 'user_id = ?', [$userId]);
                
                // Удалить пользователя
                $db->delete('users', 'id = ?', [$userId]);
                
                // Фиксация транзакции
                $db->commit();
                
                AdminAuth::logAction('user_deleted', 'user', $userId, [
                    'email' => $user['email'],
                    'full_name' => $user['full_name'],
                    'devices_deleted' => count($deviceIds),
                    'programs_deleted' => count($programIds)
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Пользователь удалён'
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
