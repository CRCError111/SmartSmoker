<?php
/**
 * Вспомогательные функции проекта
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

// =====================================================
// Функции форматирования данных
// =====================================================

/**
 * Форматирование температуры
 * 
 * @param float $temp Температура
 * @param bool $withUnit Показывать единицу измерения
 * @return string
 */
function formatTemperature($temp, $withUnit = true) {
    if ($temp === null) {
        return '—';
    }
    
    $formatted = number_format($temp, 1, ',', ' ');
    
    return $withUnit ? $formatted . ' °C' : $formatted;
}

/**
 * Форматирование влажности
 * 
 * @param float $humidity Влажность
 * @param bool $withUnit Показывать единицу измерения
 * @return string
 */
function formatHumidity($humidity, $withUnit = true) {
    if ($humidity === null) {
        return '—';
    }
    
    $formatted = number_format($humidity, 1, ',', ' ');
    
    return $withUnit ? $formatted . ' %' : $formatted;
}

/**
 * Форматирование времени
 * 
 * @param int $minutes Время в минутах
 * @return string
 */
function formatDuration($minutes) {
    if ($minutes === null) {
        return '—';
    }
    
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    if ($hours > 0) {
        if ($mins > 0) {
            return sprintf('%d ч %d мин', $hours, $mins);
        }
        return sprintf('%d ч', $hours);
    }
    
    return sprintf('%d мин', $mins);
}

/**
 * Склонение слов в зависимости от числа
 * 
 * @param int $number Число
 * @param array $forms Формы слова [1, 2, 5] например ['этап', 'этапа', 'этапов']
 * @return string
 */
function declension($number, $forms) {
    $cases = [2, 0, 1, 1, 1, 2];
    return $forms[($number % 100 > 4 && $number % 100 < 20) ? 2 : $cases[min($number % 10, 5)]];
}

/**
 * Форматирование даты и времени
 * 
 * @param string $datetime Дата и время
 * @param bool $withTime Показывать время
 * @return string
 */
function formatDate($datetime, $withTime = true) {
    if (empty($datetime)) {
        return '—';
    }
    
    $timestamp = strtotime($datetime);
    
    if ($withTime) {
        return date('d.m.Y H:i', $timestamp);
    }
    
    return date('d.m.Y', $timestamp);
}

/**
 * Форматирование статуса устройства
 * 
 * @param string $status Статус
 * @return array ['text', 'class']
 */
function formatDeviceStatus($status) {
    $statuses = [
        'pending' => ['Требует настройки', 'warning'],
        'active' => ['Активно', 'success'],
        'inactive' => ['Неактивно', 'secondary'],
        'error' => ['Ошибка', 'danger']
    ];
    
    return $statuses[$status] ?? ['Неизвестно', 'secondary'];
}

/**
 * Форматирование статуса запуска программы
 * 
 * @param string $status Статус
 * @return array ['text', 'class']
 */
function formatRunStatus($status) {
    $statuses = [
        'running' => ['Выполняется', 'info'],
        'completed' => ['Завершено', 'success'],
        'stopped' => ['Остановлено', 'warning'],
        'error' => ['Ошибка', 'danger']
    ];
    
    return $statuses[$status] ?? ['Неизвестно', 'secondary'];
}

// =====================================================
// Функции валидации
// =====================================================

/**
 * Валидация email
 * 
 * @param string $email Email
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Валидация пароля
 * 
 * @param string $password Пароль
 * @param int $minLength Минимальная длина
 * @return bool|array [success, message]
 */
function validatePassword($password, $minLength = 8) {
    if (strlen($password) < $minLength) {
        return [false, "Пароль должен содержать не менее $minLength символов"];
    }
    
    // Проверка на наличие букв и цифр
    if (!preg_match('/[a-zA-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return [false, 'Пароль должен содержать буквы и цифры'];
    }
    
    return [true, ''];
}

/**
 * Валидация имени устройства
 * 
 * @param string $name Имя
 * @return bool|array [success, message]
 */
function validateDeviceName($name) {
    if (empty($name)) {
        return [false, 'Имя устройства не может быть пустым'];
    }
    
    if (strlen($name) > 100) {
        return [false, 'Имя устройства не должно превышать 100 символов'];
    }
    
    return [true, ''];
}

/**
 * Валидация описания
 * 
 * @param string $description Описание
 * @return bool|array [success, message]
 */
function validateDescription($description) {
    if (strlen($description) > 1000) {
        return [false, 'Описание не должно превышать 1000 символов'];
    }
    
    return [true, ''];
}

/**
 * Валидация UUID v4
 * 
 * Проверяет, соответствует ли строка формату UUID версии 4.
 * Формат: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 * где x - шестнадцатеричная цифра (0-9, a-f)
 * 4 - версия UUID (должна быть 4)
 * y - вариант UUID (должен быть 8, 9, a или b)
 * 
 * @param string $uuid UUID для проверки
 * @return bool true если UUID валиден, false в противном случае
 */
function isValidUUIDv4($uuid) {
    // Проверка на null или пустую строку
    if (empty($uuid)) {
        return false;
    }
    
    // Регулярное выражение для UUID v4:
    // - 8 шестнадцатеричных символов
    // - дефис
    // - 4 шестнадцатеричных символа
    // - дефис
    // - символ '4' (версия) + 3 шестнадцатеричных символа
    // - дефис
    // - символ из набора [89ab] (вариант) + 3 шестнадцатеричных символа
    // - дефис
    // - 12 шестнадцатеричных символов
    $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    
    return preg_match($pattern, $uuid) === 1;
}

// =====================================================
// Функции генерации
// =====================================================

/**
 * Генерация UUID v4
 * 
 * @return string
 */
function generateUuid() {
    return sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0x0fff) | 0x4000,
        random_int(0, 0x3fff) | 0x8000,
        random_int(0, 0xffff),
        random_int(0, 0xffff),
        random_int(0, 0xffff)
    );
}

/**
 * Генерация случайной строки
 * 
 * @param int $length Длина строки
 * @param string $characters Допустимые символы
 * @return string
 */
function generateRandomString($length = 32, $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ') {
    $string = '';
    $max = strlen($characters) - 1;
    
    for ($i = 0; $i < $length; $i++) {
        $string .= $characters[random_int(0, $max)];
    }
    
    return $string;
}

/**
 * Генерация безопасного имени файла
 * 
 * @param string $filename Исходное имя файла
 * @return string
 */
function sanitizeFilename($filename) {
    $filename = basename($filename);
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
    $filename = preg_replace('/_{2,}/', '_', $filename);
    
    return $filename;
}

// =====================================================
// Функции работы с файлами
// =====================================================

/**
 * Загрузка файла
 * 
 * @param string $inputName Имя поля ввода
 * @param array $allowedExtensions Разрешенные расширения
 * @param int $maxSize Максимальный размер в байтах
 * @return array|false ['path', 'filename', 'size', 'mime']
 */
function uploadFile($inputName, $allowedExtensions = [], $maxSize = MAX_UPLOAD_SIZE) {
    if (!isset($_FILES[$inputName]) || $_FILES[$inputName]['error'] !== UPLOAD_ERR_OK) {
        return false;
    }
    
    $file = $_FILES[$inputName];
    
    // Проверка размера
    if ($file['size'] > $maxSize) {
        return [false, 'Файл слишком большой'];
    }
    
    // Проверка расширения
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!empty($allowedExtensions) && !in_array($extension, $allowedExtensions)) {
        return [false, 'Недопустимый тип файла'];
    }
    
    // Генерация безопасного имени
    $safeName = generateUuid() . '_' . sanitizeFilename($file['name']);
    $uploadPath = UPLOAD_DIR . '/' . $safeName;
    
    // Перемещение файла
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return [false, 'Ошибка загрузки файла'];
    }
    
    // Получение MIME-типа
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $uploadPath);
    finfo_close($finfo);
    
    return [
        'path' => $uploadPath,
        'filename' => $file['name'],
        'safe_filename' => $safeName,
        'size' => $file['size'],
        'mime' => $mime,
        'extension' => $extension
    ];
}

/**
 * Скачивание файла
 * 
 * @param string $filePath Путь к файлу
 * @param string $downloadName Имя для скачивания
 * @return void
 */
function downloadFile($filePath, $downloadName = null) {
    if (!file_exists($filePath)) {
        header('HTTP/1.0 404 Not Found');
        exit('Файл не найден');
    }
    
    $downloadName = $downloadName ?? basename($filePath);
    $fileSize = filesize($filePath);
    $mime = mime_content_type($filePath);
    
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . $fileSize);
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile($filePath);
    exit;
}

/**
 * Форматирование размера файла
 * 
 * @param int $bytes Размер в байтах
 * @param int $decimals Количество знаков после запятой
 * @return string
 */
function formatSize($bytes, $decimals = 2) {
    $bytes = (int)$bytes;
    if ($bytes === 0) return '0 Б';
    
    $k = 1024;
    $sizes = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
    $i = $bytes > 0 ? floor(log($bytes, $k)) : 0;
    
    return round($bytes / pow($k, $i), $decimals) . ' ' . $sizes[$i];
}

// =====================================================
// Функции безопасности
// =====================================================

/**
 * Экранирование для вывода в HTML
 * 
 * @param string $string Строка
 * @return string
 */
function e($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Экранирование для вывода в атрибуты HTML
 * 
 * @param string $string Строка
 * @return string
 */
function ea($string) {
    return htmlspecialchars($string ?? '', ENT_COMPAT, 'UTF-8');
}

/**
 * Проверка метода запроса
 * 
 * @param string $method Метод (GET, POST, PUT, DELETE)
 * @return bool
 */
function isMethod($method) {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === strtoupper($method);
}

/**
 * Проверка AJAX-запроса
 * 
 * @return bool
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Получение данных из POST/GET
 * 
 * @param string $key Ключ
 * @param mixed $default Значение по умолчанию
 * @param string $source Источник (post, get, request)
 * @return mixed
 */
function input($key, $default = null, $source = 'request') {
    $source = strtolower($source);
    
    switch ($source) {
        case 'post':
            $value = $_POST[$key] ?? $default;
            break;
        case 'get':
            $value = $_GET[$key] ?? $default;
            break;
        default:
            $value = $_REQUEST[$key] ?? $default;
    }
    
    // Очистка от потенциально опасных символов
    if (is_string($value)) {
        $value = trim($value);
    }
    
    return $value;
}

/**
 * Получение данных из JSON-запроса
 * 
 * @return array
 */
function getJsonInput() {
    $json = file_get_contents('php://input');
    return json_decode($json, true) ?? [];
}

// =====================================================
// Функции кеширования
// =====================================================

/**
 * Отключение кеширования для динамических страниц
 * 
 * Устанавливает заголовки для предотвращения кеширования браузером
 */
function disableCache() {
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}

// =====================================================
// Функции редиректа и ответов
// =====================================================

/**
 * Редирект на другой URL
 * 
 * @param string $url URL
 * @param int $code HTTP-код
 */
function redirect($url, $code = 302) {
    if (!headers_sent()) {
        header('Location: ' . $url, true, $code);
        exit;
    }
    
    echo '<script>window.location.href="' . htmlspecialchars($url) . '";</script>';
    exit;
}

/**
 * Отправка JSON-ответа
 * 
 * @param mixed $data Данные
 * @param int $code HTTP-код
 */
function jsonResponse($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Отправка ошибки в формате JSON
 * 
 * @param string $message Сообщение об ошибке
 * @param int $code HTTP-код
 */
function jsonError($message, $code = 400) {
    jsonResponse(['success' => false, 'error' => $message], $code);
}

/**
 * Отправка успеха в формате JSON
 * 
 * @param mixed $data Данные
 * @param string $message Сообщение
 */
function jsonSuccess($data = null, $message = 'Успешно') {
    $response = ['success' => true, 'message' => $message];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    jsonResponse($response);
}

// =====================================================
// Функции работы с устройствами
// =====================================================

/**
 * Получение устройства по ID
 * 
 * @param int $deviceId ID устройства
 * @return array|null
 */
function getDevice($deviceId) {
    $db = db();
    $device = $db->fetchOne(
        'SELECT * FROM devices WHERE id = ? AND user_id = ?',
        [$deviceId, authUserId()]
    );
    
    return $device;
}

/**
 * Получение устройства по device_id (UUID)
 * 
 * @param string $deviceUuid UUID устройства
 * @return array|null
 */
function getDeviceByUuid($deviceUuid) {
    $db = db();
    $device = $db->fetchOne(
        'SELECT * FROM devices WHERE device_id = ?',
        [$deviceUuid]
    );
    
    return $device;
}

/**
 * Проверка принадлежности устройства пользователю
 * 
 * @param int $deviceId ID устройства
 * @return bool
 */
function checkDeviceOwnership($deviceId) {
    $db = db();
    $count = $db->fetchColumn(
        'SELECT COUNT(*) FROM devices WHERE id = ? AND user_id = ?',
        [$deviceId, authUserId()]
    );
    
    return $count > 0;
}

// =====================================================
// Функции работы с программами
// =====================================================

/**
 * Получение программы по ID
 * 
 * @param int $programId ID программы
 * @return array|null
 */
function getProgram($programId, $userId = null) {
    $db = db();
    
    // Если userId не передан, пытаемся получить из Auth
    if ($userId === null) {
        if (class_exists('Auth') && method_exists('Auth', 'userId')) {
            $userId = Auth::userId();
        }
    }
    
    // Если userId все еще null, получаем программу без проверки владельца
    if ($userId === null) {
        $program = $db->fetchOne(
            'SELECT * FROM programs WHERE id = ?',
            [$programId]
        );
    } else {
        $program = $db->fetchOne(
            'SELECT * FROM programs WHERE id = ? AND (user_id = ? OR user_id IS NULL)',
            [$programId, $userId]
        );
    }
    
    if (!$program) {
        return null;
    }
    
    // Добавляем алиас 'name' для 'program_name' для обратной совместимости
    if (isset($program['program_name'])) {
        $program['name'] = $program['program_name'];
    }
    
    // Получение этапов программы
    $stages = $db->fetchAll(
        'SELECT * FROM program_stages WHERE program_id = ? ORDER BY stage_order ASC',
        [$programId]
    );
    
    $program['stages'] = $stages;
    
    return $program;
}

/**
 * Получение шаблона по ID
 * 
 * @param int $templateId ID шаблона
 * @return array|null
 */
function getTemplate($templateId) {
    $db = db();
    $template = $db->fetchOne(
        'SELECT * FROM templates WHERE id = ?',
        [$templateId]
    );
    
    if (!$template) {
        return null;
    }
    
    // Получение этапов шаблона
    $stages = $db->fetchAll(
        'SELECT * FROM template_stages WHERE template_id = ? ORDER BY stage_order ASC',
        [$templateId]
    );
    
    $template['stages'] = $stages;
    
    return $template;
}

// =====================================================
// Функции преобразования данных
// =====================================================

/**
 * Преобразование программы в формат для экспорта в JSON
 * 
 * @param array $program Программа
 * @return array
 */
function exportProgramToJson($program) {
    return [
        'version' => '1.0',
        'type' => 'program',
        'program_id' => intval($program['id']),
        'program_name' => $program['program_name'] ?? $program['name'],
        'exported_at' => date('Y-m-d H:i:s'),
        'data' => [
            'name' => $program['program_name'] ?? $program['name'],
            'description' => $program['description'] ?? '',
            'category' => $program['category'] ?? '',
            'stages' => array_map(function($stage) {
                return [
                    'order' => intval($stage['stage_order']),
                    'name' => $stage['stage_name'] ?? '',
                    'target_temp' => floatval($stage['target_temp'] ?? 0),
                    'target_temp_device' => $stage['target_temp_device'] ?? 'chamber',
                    'target_humidity' => floatval($stage['target_humidity'] ?? 0),
                    'duration_minutes' => intval($stage['duration_minutes'] ?? 0),
                    'hysteresis' => floatval($stage['hysteresis'] ?? 2.0),
                    'wait_for_temp' => boolval($stage['wait_for_temp'] ?? true),
                    'use_smoke_generator' => boolval($stage['use_smoke_generator'] ?? false),
                    'smoke_intensity' => intval($stage['smoke_intensity'] ?? 0),
                    'ventilation_percent' => intval($stage['ventilation_percent'] ?? 100),
                    'internal_fan_on' => boolval($stage['internal_fan_on'] ?? true),
                    'injection_fan_on' => boolval($stage['injection_fan_on'] ?? false),
                    'compressor_pwm' => intval($stage['compressor_pwm'] ?? 0)
                ];
            }, $program['stages'] ?? [])
        ]
    ];
}

/**
 * Импорт программы из JSON
 * 
 * @param array $jsonData Данные JSON
 * @param int $userId ID пользователя
 * @return int|null ID созданной программы
 */
function importProgramFromJson($jsonData, $userId) {
    if (!isset($jsonData['data']) || !isset($jsonData['data']['stages'])) {
        return null;
    }
    
    $db = db();
    
    try {
        $db->beginTransaction();
        
        // Создание программы
        $programId = $db->insert('programs', [
            'user_id' => $userId,
            'program_name' => $jsonData['data']['name'] ?? 'Импортированная программа',
            'description' => $jsonData['data']['description'] ?? '',
            'category' => $jsonData['data']['category'] ?? 'custom',
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        // Создание этапов
        $stages = $jsonData['data']['stages'];
        foreach ($stages as $stage) {
            $db->insert('program_stages', [
                'program_id' => $programId,
                'stage_order' => $stage['order'] ?? 0,
                'stage_name' => $stage['name'] ?? 'Этап',
                'target_temp' => $stage['target_temp'] ?? 0,
                'target_temp_device' => $stage['target_temp_device'] ?? 0,
                'target_humidity' => $stage['target_humidity'] ?? 70,
                'duration_minutes' => $stage['duration_minutes'] ?? 0,
                'hysteresis' => $stage['hysteresis'] ?? 2,
                'wait_for_temp' => $stage['wait_for_temp'] ?? true,
                'use_smoke_generator' => $stage['use_smoke_generator'] ?? true,
                'ventilation_percent' => $stage['ventilation_percent'] ?? 100,
                'internal_fan_on' => $stage['internal_fan_on'] ?? false,
                'injection_fan_on' => $stage['injection_fan_on'] ?? false,
                'compressor_pwm' => $stage['compressor_pwm'] ?? -1
            ]);
        }
        
        $db->commit();
        
        return $programId;
        
    } catch (Exception $e) {
        $db->rollback();
        logException($e, 'IMPORT');
        return null;
    }
}

// =====================================================
// Функции для веб-интерфейса
// =====================================================

/**
 * Подключение шаблона
 * 
 * @param string $template Имя шаблона
 * @param array $data Данные для шаблона
 */
function view($template, $data = []) {
    extract($data);
    require BASE_PATH . '/templates/' . $template . '.php';
}

/**
 * Обновление статуса online для устройства
 * Устанавливает is_online = 1 и обновляет last_seen
 * 
 * @param string $deviceId UUID устройства
 * @return bool
 */
function updateDeviceOnlineStatus($deviceId) {
    $db = db();
    
    try {
        $db->update(
            'devices',
            [
                'is_online' => 1,
                'last_seen' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ],
            'device_id = ?',
            [$deviceId]
        );
        
        return true;
    } catch (Exception $e) {
        error_log("Failed to update device online status: " . $e->getMessage());
        return false;
    }
}

/**
 * Проверка, является ли текущая страница активной
 * 
 * @param string $path Путь
 * @return string 'active' или ''
 */
function isActive($path) {
    $currentPath = $_SERVER['SCRIPT_NAME'] ?? '';
    return strpos($currentPath, $path) !== false ? 'active' : '';
}

// =====================================================
// Завершение файла
// =====================================================