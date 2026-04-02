<?php
/**
 * ProgramBuilder - Формирование JSON программ для передачи на контроллер
 * 
 * Этот класс отвечает за:
 * - Загрузку программы и этапов из БД
 * - Формирование JSON структуры согласно схеме
 * - Генерацию уникального transfer_id
 * - Добавление timestamp в ISO 8601 формате
 * - Валидацию JSON против схемы
 * 
 * @version 1.1
 * @author Smart Smoker Team
 */

// Запрет прямого доступа
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

// Попытка загрузить composer autoloader если доступен
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

class ProgramBuilder {
    
    /**
     * @var Database Экземпляр подключения к БД
     */
    private $db;
    
    /**
     * @var string Путь к файлу JSON схемы
     */
    private $schemaPath;
    
    /**
     * @var bool Доступна ли библиотека JSON Schema
     */
    private $validatorAvailable;
    
    /**
     * Конструктор
     * 
     * @param string|null $schemaPath Путь к файлу JSON схемы (опционально)
     */
    public function __construct($schemaPath = null) {
        $this->db = Database::getInstance();
        
        // Установка пути к схеме
        if ($schemaPath === null) {
            $this->schemaPath = __DIR__ . '/../schemas/program-schema.json';
        } else {
            $this->schemaPath = $schemaPath;
        }
        
        // Проверка доступности библиотеки JSON Schema
        $this->validatorAvailable = class_exists('JsonSchema\Validator');
    }
    
    /**
     * Построить JSON программы по ID
     * 
     * @param int $programId ID программы в БД
     * @return string JSON string программы
     * @throws Exception Если программа не найдена или ошибка формирования
     */
    public function buildProgramJson($programId) {
        // 1. Загрузка программы из БД
        $program = $this->loadProgram($programId);
        
        if (!$program) {
            logWarning(sprintf(
                'Program not found: program_id=%d',
                $programId
            ), 'PROGRAM_BUILDER');
            throw new Exception("Программа с ID {$programId} не найдена");
        }
        
        // 2. Загрузка этапов программы
        $stages = $this->loadStages($programId);
        
        if (empty($stages)) {
            logWarning(sprintf(
                'Program has no stages: program_id=%d',
                $programId
            ), 'PROGRAM_BUILDER');
            throw new Exception("Программа с ID {$programId} не содержит этапов");
        }
        
        // 3. Формирование JSON структуры
        $jsonData = $this->buildJsonStructure($program, $stages);
        
        // 4. Валидация JSON против схемы
        $validationErrors = $this->validateJsonData($jsonData);
        
        if (!empty($validationErrors)) {
            logWarning(sprintf(
                'JSON validation failed for program_id=%d: %s',
                $programId,
                implode('; ', $validationErrors)
            ), 'PROGRAM_BUILDER');
            throw new Exception("Ошибка валидации JSON: " . implode('; ', $validationErrors));
        }
        
        // 5. Конвертация в JSON string (минимизированный для передачи)
        $json = json_encode($jsonData, JSON_UNESCAPED_UNICODE);
        
        if ($json === false) {
            logError(sprintf(
                'JSON encoding failed for program_id=%d: %s',
                $programId,
                json_last_error_msg()
            ), 'PROGRAM_BUILDER');
            throw new Exception("Ошибка формирования JSON: " . json_last_error_msg());
        }
        
        logInfo(sprintf(
            'Successfully built program JSON: program_id=%d, transfer_id=%s, stages=%d, size=%d bytes',
            $programId,
            $jsonData['transfer_id'],
            count($stages),
            strlen($json)
        ), 'PROGRAM_BUILDER');
        
        return $json;
    }
    
    /**
     * Загрузить программу из БД
     * 
     * @param int $programId ID программы
     * @return array|false Данные программы или false
     */
    private function loadProgram($programId) {
        $sql = "SELECT 
                    id,
                    program_name,
                    description,
                    category
                FROM programs 
                WHERE id = ?";
        
        return $this->db->fetchOne($sql, [$programId]);
    }
    
    /**
     * Загрузить этапы программы из БД
     * 
     * @param int $programId ID программы
     * @return array Массив этапов
     */
    private function loadStages($programId) {
        $sql = "SELECT 
                    stage_order,
                    stage_name,
                    target_temp,
                    target_humidity,
                    duration_minutes,
                    use_smoke_generator,
                    smoke_intensity,
                    ventilation_percent,
                    internal_fan_on,
                    injection_fan_on,
                    compressor_pwm
                FROM program_stages 
                WHERE program_id = ? 
                ORDER BY stage_order ASC";
        
        return $this->db->fetchAll($sql, [$programId]);
    }
    
    /**
     * Сформировать JSON структуру программы
     * 
     * @param array $program Данные программы
     * @param array $stages Массив этапов
     * @return array Структура для JSON
     */
    private function buildJsonStructure($program, $stages) {
        // Генерация уникального transfer_id
        $transferId = $this->generateTransferId();
        
        // Текущее время в ISO 8601
        $timestamp = $this->getCurrentTimestamp();
        
        // Формирование структуры
        $jsonData = [
            'transfer_id' => $transferId,
            'program_id' => (int)$program['id'],
            'program_name' => $program['program_name'],
            'description' => $program['description'] ?? '',
            'category' => $program['category'] ?? 'other',
            'timestamp' => $timestamp,
            'stages' => []
        ];
        
        // Добавление этапов
        foreach ($stages as $stage) {
            $jsonData['stages'][] = [
                'stage_order' => (int)$stage['stage_order'],
                'stage_name' => $stage['stage_name'],
                'target_temp' => (float)($stage['target_temp'] ?? 0.0),
                'target_humidity' => (float)($stage['target_humidity'] ?? 0.0),
                'duration_minutes' => (int)($stage['duration_minutes'] ?? 0),
                'use_smoke_generator' => (bool)$stage['use_smoke_generator'],
                'smoke_intensity' => (int)($stage['smoke_intensity'] ?? 0),
                'ventilation_percent' => (int)($stage['ventilation_percent'] ?? 100),
                'internal_fan_on' => (bool)$stage['internal_fan_on'],
                'injection_fan_on' => (bool)$stage['injection_fan_on'],
                'compressor_pwm' => (int)($stage['compressor_pwm'] ?? 0)
            ];
        }
        
        return $jsonData;
    }
    
    /**
     * Генерировать уникальный transfer_id
     * 
     * Формат: tr_YYYYMMDD_HHMMSS_random
     * Пример: tr_20260210_143022_abc123
     * 
     * @return string Уникальный transfer_id
     */
    private function generateTransferId() {
        // Дата и время
        $dateTime = date('Ymd_His');
        
        // Случайная строка (6 символов)
        $random = substr(md5(uniqid(mt_rand(), true)), 0, 6);
        
        return "tr_{$dateTime}_{$random}";
    }
    
    /**
     * Получить текущее время в ISO 8601 формате
     * 
     * @return string Timestamp в формате ISO 8601
     */
    private function getCurrentTimestamp() {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
    
    /**
     * Валидировать JSON данные против схемы
     * 
     * @param array $jsonData Данные для валидации
     * @return array Массив ошибок валидации (пустой если валидация успешна)
     */
    protected function validateJsonData($jsonData) {
        // Проверка существования файла схемы
        if (!file_exists($this->schemaPath)) {
            return ["Файл схемы не найден: {$this->schemaPath}"];
        }
        
        // Если библиотека JSON Schema доступна, используем её
        if ($this->validatorAvailable) {
            return $this->validateWithJsonSchema($jsonData);
        }
        
        // Иначе используем базовую валидацию
        return $this->validateBasic($jsonData);
    }
    
    /**
     * Валидация с использованием библиотеки JSON Schema
     * 
     * @param array $jsonData Данные для валидации
     * @return array Массив ошибок валидации
     */
    private function validateWithJsonSchema($jsonData) {
        // Загрузка схемы
        $schema = json_decode(file_get_contents($this->schemaPath));
        
        if ($schema === null) {
            return ["Ошибка загрузки схемы: " . json_last_error_msg()];
        }
        
        // Конвертация данных в объект для валидации
        $data = json_decode(json_encode($jsonData));
        
        // Создание валидатора
        $validator = new \JsonSchema\Validator();
        
        // Валидация
        $validator->validate(
            $data,
            $schema,
            \JsonSchema\Constraints\Constraint::CHECK_MODE_NORMAL
        );
        
        // Сбор ошибок
        $errors = [];
        
        if (!$validator->isValid()) {
            foreach ($validator->getErrors() as $error) {
                $errors[] = sprintf(
                    "[%s] %s",
                    $error['property'],
                    $error['message']
                );
            }
        }
        
        return $errors;
    }
    
    /**
     * Базовая валидация без библиотеки JSON Schema
     * Проверяет основные требования к структуре программы
     * 
     * @param array $jsonData Данные для валидации
     * @return array Массив ошибок валидации
     */
    private function validateBasic($jsonData) {
        $errors = [];
        
        // Проверка обязательных полей
        $requiredFields = ['transfer_id', 'program_id', 'program_name', 'stages'];
        foreach ($requiredFields as $field) {
            if (!isset($jsonData[$field])) {
                $errors[] = "Отсутствует обязательное поле: {$field}";
            }
        }
        
        // Проверка transfer_id формата
        if (isset($jsonData['transfer_id'])) {
            if (!preg_match('/^tr_[0-9]{8}_[0-9]{6}_[a-z0-9]+$/', $jsonData['transfer_id'])) {
                $errors[] = "Неверный формат transfer_id";
            }
        }
        
        // Проверка program_id
        if (isset($jsonData['program_id'])) {
            if (!is_int($jsonData['program_id']) || $jsonData['program_id'] < 1) {
                $errors[] = "program_id должен быть целым числом >= 1";
            }
        }
        
        // Проверка program_name
        if (isset($jsonData['program_name'])) {
            $len = strlen($jsonData['program_name']);
            if ($len < 1 || $len > 255) {
                $errors[] = "program_name должно быть от 1 до 255 символов";
            }
        }
        
        // Проверка category
        if (isset($jsonData['category'])) {
            $validCategories = ['fish', 'meat', 'poultry', 'cheese', 'vegetables', 'other'];
            if (!in_array($jsonData['category'], $validCategories)) {
                $errors[] = "Недопустимое значение category";
            }
        }
        
        // Проверка stages
        if (isset($jsonData['stages'])) {
            if (!is_array($jsonData['stages']) || empty($jsonData['stages'])) {
                $errors[] = "stages должен быть непустым массивом";
            } else {
                // Валидация каждого этапа
                foreach ($jsonData['stages'] as $index => $stage) {
                    $stageErrors = $this->validateStage($stage, $index);
                    $errors = array_merge($errors, $stageErrors);
                }
            }
        }
        
        return $errors;
    }
    
    /**
     * Валидация одного этапа программы
     * 
     * @param array $stage Данные этапа
     * @param int $index Индекс этапа в массиве
     * @return array Массив ошибок валидации
     */
    private function validateStage($stage, $index) {
        $errors = [];
        $prefix = "stages[{$index}]";
        
        // Проверка обязательных полей этапа
        $requiredFields = ['stage_order', 'stage_name', 'target_temp', 'duration_minutes'];
        foreach ($requiredFields as $field) {
            if (!isset($stage[$field])) {
                $errors[] = "{$prefix}: отсутствует обязательное поле {$field}";
            }
        }
        
        // Проверка stage_order
        if (isset($stage['stage_order'])) {
            if (!is_int($stage['stage_order']) || $stage['stage_order'] < 1) {
                $errors[] = "{$prefix}.stage_order должен быть >= 1";
            }
        }
        
        // Проверка stage_name
        if (isset($stage['stage_name'])) {
            $len = strlen($stage['stage_name']);
            if ($len < 1 || $len > 100) {
                $errors[] = "{$prefix}.stage_name должно быть от 1 до 100 символов";
            }
        }
        
        // Проверка target_temp (0-100°C)
        if (isset($stage['target_temp'])) {
            if (!is_numeric($stage['target_temp']) || $stage['target_temp'] < 0 || $stage['target_temp'] > 100) {
                $errors[] = "{$prefix}.target_temp должна быть в диапазоне 0-100";
            }
        }
        
        // Проверка target_humidity (0-100%)
        if (isset($stage['target_humidity'])) {
            if (!is_numeric($stage['target_humidity']) || $stage['target_humidity'] < 0 || $stage['target_humidity'] > 100) {
                $errors[] = "{$prefix}.target_humidity должна быть в диапазоне 0-100";
            }
        }
        
        // Проверка duration_minutes
        if (isset($stage['duration_minutes'])) {
            if (!is_int($stage['duration_minutes']) || $stage['duration_minutes'] < 1) {
                $errors[] = "{$prefix}.duration_minutes должна быть >= 1";
            }
        }
        
        // Проверка smoke_intensity (0-100)
        if (isset($stage['smoke_intensity'])) {
            if (!is_int($stage['smoke_intensity']) || $stage['smoke_intensity'] < 0 || $stage['smoke_intensity'] > 100) {
                $errors[] = "{$prefix}.smoke_intensity должна быть в диапазоне 0-100";
            }
        }
        
        // Проверка ventilation_percent (0-100)
        if (isset($stage['ventilation_percent'])) {
            if (!is_int($stage['ventilation_percent']) || $stage['ventilation_percent'] < 0 || $stage['ventilation_percent'] > 100) {
                $errors[] = "{$prefix}.ventilation_percent должна быть в диапазоне 0-100";
            }
        }
        
        // Проверка compressor_pwm (0-100)
        if (isset($stage['compressor_pwm'])) {
            if (!is_int($stage['compressor_pwm']) || $stage['compressor_pwm'] < 0 || $stage['compressor_pwm'] > 100) {
                $errors[] = "{$prefix}.compressor_pwm должна быть в диапазоне 0-100";
            }
        }
        
        return $errors;
    }
}

// =====================================================
// Завершение файла
// =====================================================
