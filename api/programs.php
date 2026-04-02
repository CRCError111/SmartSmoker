<?php
/**
 * API для работы с программами копчения
 * 
 * @version 1.1 - ИСПРАВЛЕНО
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/logger.php';

header('Content-Type: application/json');

try {
    $db = db();
    $method = $_SERVER['REQUEST_METHOD'];

    // Проверка авторизации для всех методов кроме GET для публичных программ
    if ($method !== 'GET' || !isset($_GET['public'])) {
        if (!Auth::check()) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Требуется авторизация'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $user = Auth::user();
        $userId = $user['id'];
    }

    switch ($method) {
        case 'GET':
            // Получение списка программ или одной программы
            if (isset($_GET['id'])) {
                $programId = (int)$_GET['id'];
                $program = $db->fetchOne(
                    'SELECT p.*, d.name as device_name 
                     FROM programs p 
                     LEFT JOIN devices d ON d.id = p.device_id
                     WHERE p.id = ?',
                [$programId]
                );

                if (!$program) {
                    throw new Exception('Программа не найдена');
                }

                // Получение этапов программы
                $stages = $db->fetchAll(
                    'SELECT * FROM program_stages 
                     WHERE program_id = ? 
                     ORDER BY stage_order',
                [$programId]
                );

                $program['stages'] = $stages;

                echo json_encode([
                    'success' => true,
                    'program' => $program
                ]);

            }
            else {
                // Список программ
                $deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;
                $public = isset($_GET['public']) && $_GET['public'] === 'true';

                if ($public) {
                    // Публичные программы (для ESP32)
                    $programs = $db->fetchAll(
                        'SELECT p.*, COUNT(ps.id) as stages_count
                         FROM programs p
                         LEFT JOIN program_stages ps ON ps.program_id = p.id
                         WHERE p.is_built_in = 1
                         GROUP BY p.id
                         ORDER BY p.name'
                    );
                }
                elseif ($deviceId) {
                    // Программы для конкретного устройства
                    $programs = $db->fetchAll(
                        'SELECT p.*, d.name as device_name, COUNT(ps.id) as stages_count
                         FROM programs p
                         LEFT JOIN devices d ON d.id = p.device_id
                         LEFT JOIN program_stages ps ON ps.program_id = p.id
                         WHERE p.device_id = ?
                         GROUP BY p.id
                         ORDER BY p.created_at DESC',
                    [$deviceId]
                    );
                }
                else {
                    // Все программы пользователя
                    $programs = $db->fetchAll(
                        'SELECT p.*, d.name as device_name, COUNT(ps.id) as stages_count
                         FROM programs p
                         LEFT JOIN devices d ON d.id = p.device_id
                         LEFT JOIN program_stages ps ON ps.program_id = p.id
                         WHERE (d.user_id = ? OR p.user_id = ? OR p.is_built_in = 1)
                         GROUP BY p.id
                         ORDER BY p.is_built_in DESC, p.created_at DESC',
                    [$userId, $userId]
                    );
                }

                echo json_encode([
                    'success' => true,
                    'programs' => $programs
                ]);
            }
            break;

        case 'POST':
            // Создание новой программы или привязка к устройству
            $data = json_decode(file_get_contents('php://input'), true);

            // Проверка на действие привязки к устройству
            if (isset($data['action']) && $data['action'] === 'assign_to_device') {
                if (!isset($data['program_id']) || !isset($data['device_id'])) {
                    throw new Exception('Не указаны program_id или device_id');
                }

                $programId = (int)$data['program_id'];
                $deviceId = (int)$data['device_id'];

                // Проверка прав на программу
                $program = $db->fetchOne(
                    'SELECT * FROM programs WHERE id = ? AND user_id = ?',
                [$programId, $userId]
                );

                if (!$program) {
                    throw new Exception('Программа не найдена или нет доступа');
                }

                // Проверка прав на устройство
                $device = $db->fetchOne(
                    'SELECT id FROM devices WHERE id = ? AND user_id = ?',
                [$deviceId, $userId]
                );

                if (!$device) {
                    throw new Exception('Устройство не найдено или нет доступа');
                }

                // Привязка программы к устройству
                $db->update('programs',
                ['device_id' => $deviceId],
                    'id = ?',
                [$programId]
                );

                logInfo("Программа #$programId привязана к устройству #$deviceId", 'PROGRAMS');

                echo json_encode([
                    'success' => true,
                    'message' => 'Программа успешно привязана к устройству'
                ]);
                break;
            }

            // Создание новой программы
            if (!isset($data['name']) || empty($data['name'])) {
                throw new Exception('Название программы обязательно');
            }

            if (!isset($data['device_id']) || empty($data['device_id'])) {
                throw new Exception('Устройство обязательно');
            }

            // Проверка прав на устройство
            $device = $db->fetchOne(
                'SELECT id FROM devices WHERE id = ? AND user_id = ?',
            [$data['device_id'], $userId]
            );

            if (!$device) {
                throw new Exception('Устройство не найдено или нет доступа');
            }

            // Создание программы
            $programId = $db->insert('programs', [
                'device_id' => $data['device_id'],
                'program_name' => $data['name'],
                'description' => $data['description'] ?? null,
                'category' => $data['category'] ?? null,
                'is_built_in' => 0
            ]);

            // Добавление этапов
            if (isset($data['stages']) && is_array($data['stages'])) {
                foreach ($data['stages'] as $index => $stage) {
                    $db->insert('program_stages', [
                        'program_id' => $programId,
                        'stage_order' => $index + 1,
                        'stage_name' => $stage['stage_name'],
                        'target_temp' => $stage['target_temp'] ?? null,
                        'target_temp_device' => $stage['target_temp_device'] ?? 'chamber',
                        'target_humidity' => $stage['target_humidity'] ?? null,
                        'duration_minutes' => $stage['duration_minutes'] ?? null,
                        'hysteresis' => $stage['hysteresis'] ?? 2.0,
                        'wait_for_temp' => $stage['wait_for_temp'] ?? 1,
                        'use_smoke_generator' => $stage['use_smoke_generator'] ?? 0,
                        'ventilation_percent' => $stage['ventilation_percent'] ?? 100,
                        'internal_fan_on' => $stage['internal_fan_on'] ?? 1,
                        'injection_fan_on' => $stage['injection_fan_on'] ?? 0,
                        'compressor_pwm' => $stage['compressor_pwm'] ?? 0
                    ]);
                }
            }

            Logger::info('Program created', ['program_id' => $programId, 'user_id' => $userId]);

            echo json_encode([
                'success' => true,
                'program_id' => $programId,
                'message' => 'Программа создана успешно'
            ]);
            break;

        case 'PUT':
            // Обновление программы
            $programId = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$programId) {
                throw new Exception('ID программы не указан');
            }

            $data = json_decode(file_get_contents('php://input'), true);

            // Проверка прав
            $program = $db->fetchOne(
                'SELECT p.* FROM programs p
                 JOIN devices d ON d.id = p.device_id
                 WHERE p.id = ? AND d.user_id = ?',
            [$programId, $userId]
            );

            if (!$program) {
                throw new Exception('Программа не найдена или нет доступа');
            }

            if ($program['is_built_in']) {
                throw new Exception('Встроенные программы нельзя редактировать');
            }

            // Обновление программы
            $updateData = [];
            if (isset($data['name']))
                $updateData['program_name'] = $data['name'];
            if (isset($data['description']))
                $updateData['description'] = $data['description'];
            if (isset($data['category']))
                $updateData['category'] = $data['category'];

            if (!empty($updateData)) {
                $db->update('programs', $updateData, 'id = ?', [$programId]);
            }

            // Обновление этапов
            if (isset($data['stages']) && is_array($data['stages'])) {
                // Удаляем старые этапы
                $db->delete('program_stages', 'program_id = ?', [$programId]);

                // Добавляем новые
                foreach ($data['stages'] as $index => $stage) {
                    $db->insert('program_stages', [
                        'program_id' => $programId,
                        'stage_order' => $index + 1,
                        'stage_name' => $stage['stage_name'],
                        'target_temp' => $stage['target_temp'] ?? null,
                        'target_temp_device' => $stage['target_temp_device'] ?? 'chamber',
                        'target_humidity' => $stage['target_humidity'] ?? null,
                        'duration_minutes' => $stage['duration_minutes'] ?? null,
                        'hysteresis' => $stage['hysteresis'] ?? 2.0,
                        'wait_for_temp' => $stage['wait_for_temp'] ?? 1,
                        'use_smoke_generator' => $stage['use_smoke_generator'] ?? 0,
                        'ventilation_percent' => $stage['ventilation_percent'] ?? 100,
                        'internal_fan_on' => $stage['internal_fan_on'] ?? 1,
                        'injection_fan_on' => $stage['injection_fan_on'] ?? 0,
                        'compressor_pwm' => $stage['compressor_pwm'] ?? 0
                    ]);
                }
            }

            Logger::info('Program updated', ['program_id' => $programId, 'user_id' => $userId]);

            echo json_encode([
                'success' => true,
                'message' => 'Программа обновлена успешно'
            ]);
            break;

        case 'DELETE':
            // Удаление программы
            $programId = isset($_GET['id']) ? (int)$_GET['id'] : null;
            if (!$programId) {
                throw new Exception('ID программы не указан');
            }

            // Проверка прав
            $program = $db->fetchOne(
                'SELECT p.* FROM programs p
                 JOIN devices d ON d.id = p.device_id
                 WHERE p.id = ? AND d.user_id = ?',
            [$programId, $userId]
            );

            if (!$program) {
                throw new Exception('Программа не найдена или нет доступа');
            }

            if ($program['is_built_in']) {
                throw new Exception('Встроенные программы нельзя удалять');
            }

            // Удаление программы (этапы удалятся автоматически по CASCADE)
            $db->delete('programs', 'id = ?', [$programId]);

            Logger::info('Program deleted', ['program_id' => $programId, 'user_id' => $userId]);

            echo json_encode([
                'success' => true,
                'message' => 'Программа удалена успешно'
            ]);
            break;

        default:
            throw new Exception('Метод не поддерживается');
    }


}
catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);

    Logger::error('API programs error', [
        'error' => $e->getMessage(),
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
}
