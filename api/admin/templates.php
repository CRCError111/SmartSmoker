<?php
/**
 * API для управления шаблонами (только для админов)
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
        case 'POST':
            // Публикация/снятие с публикации
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['action']) || !isset($data['template_id'])) {
                throw new Exception('Не указаны обязательные параметры');
            }
            
            $templateId = (int)$data['template_id'];
            $action = $data['action'];
            
            // Проверка существования шаблона
            $template = $db->fetchOne('SELECT * FROM templates WHERE id = ?', [$templateId]);
            
            if (!$template) {
                throw new Exception('Шаблон не найден');
            }
            
            if ($action === 'publish') {
                // Публикация шаблона
                $db->update('templates', [
                    'is_public' => 1
                ], 'id = ?', [$templateId]);
                
                AdminAuth::logAction('template_published', 'template', $templateId, [
                    'name' => $template['name']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Шаблон опубликован'
                ]);
                
            } elseif ($action === 'unpublish') {
                // Снятие с публикации
                $db->update('templates', [
                    'is_public' => 0
                ], 'id = ?', [$templateId]);
                
                AdminAuth::logAction('template_unpublished', 'template', $templateId, [
                    'name' => $template['name']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Шаблон снят с публикации'
                ]);
                
            } else {
                throw new Exception('Неизвестное действие');
            }
            break;
            
        case 'DELETE':
            // Удаление шаблона
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!isset($data['template_id'])) {
                throw new Exception('Не указан ID шаблона');
            }
            
            $templateId = (int)$data['template_id'];
            
            // Получение информации о шаблоне
            $template = $db->fetchOne('SELECT * FROM templates WHERE id = ?', [$templateId]);
            
            if (!$template) {
                throw new Exception('Шаблон не найден');
            }
            
            // Начало транзакции
            $db->beginTransaction();
            
            try {
                // Удалить этапы шаблона
                $db->delete('template_stages', 'template_id = ?', [$templateId]);
                
                // Удалить шаблон
                $db->delete('templates', 'id = ?', [$templateId]);
                
                // Фиксация транзакции
                $db->commit();
                
                AdminAuth::logAction('template_deleted', 'template', $templateId, [
                    'name' => $template['name'],
                    'category' => $template['category']
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Шаблон удалён'
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
