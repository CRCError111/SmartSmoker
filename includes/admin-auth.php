<?php
/**
 * Middleware для проверки прав администратора
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

if (!defined('SMART_SMOKER')) {
    die('Direct access not permitted');
}

class AdminAuth {
    /**
     * Требовать права администратора
     * Перенаправляет на dashboard если не админ
     */
    public static function requireAdmin() {
        Auth::requireAuth();
        
        // Перезагружаем данные пользователя из БД (не из сессии)
        $db = db();
        $userId = $_SESSION['user_id'] ?? 0;
        $user = $db->fetchOne('SELECT id, email, full_name, role FROM users WHERE id = ?', [$userId]);
        
        if (!$user) {
            redirect(BASE_URL . '/login.php');
        }
        
        if (!isset($user['role']) || $user['role'] !== 'admin') {
            logWarning('Попытка доступа к админ-панели без прав', 'ADMIN', [
                'user_id' => $user['id'],
                'email' => $user['email'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            redirect(BASE_URL . '/dashboard.php?error=access_denied');
        }
        
        // Обновляем данные в сессии
        $_SESSION['user'] = $user;
    }
    
    /**
     * Проверить является ли текущий пользователь админом
     * 
     * @return bool
     */
    public static function isAdmin() {
        if (!Auth::check()) {
            return false;
        }
        
        // Перезагружаем данные из БД
        $db = db();
        $userId = $_SESSION['user_id'] ?? 0;
        $user = $db->fetchOne('SELECT role FROM users WHERE id = ?', [$userId]);
        
        return $user && isset($user['role']) && $user['role'] === 'admin';
    }
    
    /**
     * Логировать действие администратора
     * 
     * @param string $action Действие (user_created, user_deleted, etc.)
     * @param string $targetType Тип цели (user, device, program, template, system)
     * @param int|null $targetId ID цели
     * @param array $details Дополнительные детали
     */
    public static function logAction($action, $targetType, $targetId = null, $details = []) {
        try {
            $db = db();
            $user = Auth::user();
            
            $db->insert('admin_logs', [
                'admin_id' => $user['id'],
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'details' => json_encode($details, JSON_UNESCAPED_UNICODE),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            logInfo("Admin action: $action", 'ADMIN', [
                'admin_id' => $user['id'],
                'target_type' => $targetType,
                'target_id' => $targetId
            ]);
            
        } catch (Exception $e) {
            logException($e, 'ADMIN');
        }
    }
    
    /**
     * Проверить можно ли удалить пользователя
     * 
     * @param int $userId ID пользователя для удаления
     * @return array ['can_delete' => bool, 'reason' => string]
     */
    public static function canDeleteUser($userId) {
        $currentUser = Auth::user();
        
        // Нельзя удалить себя
        if ($userId === $currentUser['id']) {
            return [
                'can_delete' => false,
                'reason' => 'Нельзя удалить свой аккаунт'
            ];
        }
        
        // Проверить не последний ли это админ
        $db = db();
        $user = $db->fetchOne('SELECT role FROM users WHERE id = ?', [$userId]);
        
        if ($user && $user['role'] === 'admin') {
            $adminCount = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE role = "admin"');
            
            if ($adminCount <= 1) {
                return [
                    'can_delete' => false,
                    'reason' => 'Нельзя удалить последнего администратора'
                ];
            }
        }
        
        return [
            'can_delete' => true,
            'reason' => ''
        ];
    }
    
    /**
     * Проверить можно ли изменить роль пользователя
     * 
     * @param int $userId ID пользователя
     * @param string $newRole Новая роль
     * @return array ['can_change' => bool, 'reason' => string]
     */
    public static function canChangeRole($userId, $newRole) {
        $currentUser = Auth::user();
        $db = db();
        
        $user = $db->fetchOne('SELECT role FROM users WHERE id = ?', [$userId]);
        
        if (!$user) {
            return [
                'can_change' => false,
                'reason' => 'Пользователь не найден'
            ];
        }
        
        // Если понижаем админа до пользователя
        if ($user['role'] === 'admin' && $newRole === 'user') {
            // Нельзя понизить себя
            if ($userId === $currentUser['id']) {
                return [
                    'can_change' => false,
                    'reason' => 'Нельзя изменить свою роль'
                ];
            }
            
            // Проверить не последний ли это админ
            $adminCount = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE role = "admin"');
            
            if ($adminCount <= 1) {
                return [
                    'can_change' => false,
                    'reason' => 'Нельзя понизить последнего администратора'
                ];
            }
        }
        
        return [
            'can_change' => true,
            'reason' => ''
        ];
    }
}
