<?php
/**
 * API для получения шаблонов программ
 */
define('SMART_SMOKER', true);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json');

try {
    Auth::init();
    // Шаблоны публичные, но API доступно авторизованным пользователям
    if (!Auth::check()) {
        http_response_code(401);
        throw new Exception('Авторизация обязательна');
    }

    $db = db();

    // Получение всех публичных или встроенных шаблонов
    $templates = $db->fetchAll(
        'SELECT id, name, description, category, is_built_in 
         FROM templates 
         WHERE is_public = 1 OR is_built_in = 1 
         ORDER BY is_built_in DESC, name ASC'
    );

    $result = [];
    foreach ($templates as $template) {
        $stages = $db->fetchAll(
            'SELECT 
                stage_name, 
                target_temp, 
                target_temp_device, 
                target_humidity, 
                duration_minutes, 
                hysteresis, 
                wait_for_temp, 
                use_smoke_generator, 
                ventilation_percent, 
                internal_fan_on, 
                injection_fan_on, 
                compressor_pwm 
             FROM template_stages 
             WHERE template_id = ? 
             ORDER BY stage_order ASC',
        [$template['id']]
        );

        $template['stages'] = $stages;
        $result[] = $template;
    }

    echo json_encode([
        'success' => true,
        'templates' => $result
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

}
catch (Exception $e) {
    if (http_response_code() === 200)
        http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
