/**
 * ProgramData.h - Структуры данных для передачи программ с веб-сайта
 * 
 * Этот файл определяет структуры данных для программ, которые передаются
 * с веб-сайта на контроллер ESP32 через HTTP API.
 * 
 * Структуры соответствуют JSON схеме, определенной в program-schema.json
 * и формируемой классом ProgramBuilder.php на стороне веб-сайта.
 * 
 * @file ProgramData.h
 * @version 1.0
 * @author Smart Smoker Team
 */

#ifndef PROGRAM_DATA_H
#define PROGRAM_DATA_H

#include <Arduino.h>
#include <vector>

// Максимальное количество этапов в программе
#define MAX_PROGRAM_STAGES 20

/**
 * Структура этапа программы (Stage)
 * 
 * Соответствует структуре этапа в JSON, отправляемом с веб-сайта.
 * Все поля соответствуют полям в таблице program_stages БД.
 */
struct ProgramStage {
    uint8_t stage_number;              // Номер этапа (1, 2, 3...)
    String stage_name;                 // Название этапа
    float target_temp;                 // Целевая температура (°C, 0-100)
    float target_humidity;             // Целевая влажность (%, 0-100)
    uint16_t duration_minutes;         // Длительность этапа (минуты)
    bool use_smoke_generator;          // Использовать дымогенератор
    uint8_t smoke_intensity;           // Интенсивность дыма (0-100)
    uint8_t ventilation_percent;       // Процент вентиляции (0-100)
    bool internal_fan_on;              // Внутренний вентилятор включен
    bool injection_fan_on;             // Вентилятор подачи включен
    uint8_t compressor_pwm;            // ШИМ компрессора (0-100)
    
    /**
     * Конструктор по умолчанию
     */
    ProgramStage() 
        : stage_number(1),
          stage_name(""),
          target_temp(0.0f),
          target_humidity(0.0f),
          duration_minutes(0),
          use_smoke_generator(false),
          smoke_intensity(0),
          ventilation_percent(100),
          internal_fan_on(false),
          injection_fan_on(false),
          compressor_pwm(0) {}
    
    /**
     * Валидация параметров этапа
     * 
     * @return true если все параметры в допустимых диапазонах
     */
    bool isValid() const {
        return stage_number >= 1 &&
               !stage_name.isEmpty() &&
               target_temp >= 0.0f && target_temp <= 100.0f &&
               target_humidity >= 0.0f && target_humidity <= 100.0f &&
               duration_minutes > 0 &&
               smoke_intensity <= 100 &&
               ventilation_percent <= 100 &&
               compressor_pwm <= 100;
    }
};

/**
 * Структура метаданных программы
 * 
 * Используется для индексного файла /programs/index.json
 * и для списка программ в API endpoint /api/programs/list
 */
struct ProgramMetadata {
    int program_id;                    // ID программы из БД
    String program_name;               // Название программы
    String category;                   // Категория (fish, meat, poultry, cheese, vegetables, other)
    uint8_t stage_count;               // Количество этапов
    uint16_t total_duration_minutes;   // Общая длительность (минуты)
    String uploaded_at;                // Время загрузки (ISO 8601)
    size_t file_size;                  // Размер файла (байты)
    
    /**
     * Конструктор по умолчанию
     */
    ProgramMetadata()
        : program_id(0),
          program_name(""),
          category("other"),
          stage_count(0),
          total_duration_minutes(0),
          uploaded_at(""),
          file_size(0) {}
};

/**
 * Структура полной программы
 * 
 * Соответствует JSON структуре, отправляемой с веб-сайта.
 * Содержит все метаданные и массив этапов.
 */
struct ProgramData {
    // Метаданные передачи
    String transfer_id;                // Уникальный ID передачи (формат: tr_YYYYMMDD_HHMMSS_random)
    String timestamp;                  // Время формирования (ISO 8601)
    
    // Метаданные программы
    int program_id;                    // ID программы из БД
    String program_name;               // Название программы
    String description;                // Описание программы
    String category;                   // Категория (fish, meat, poultry, cheese, vegetables, other)
    uint16_t estimated_duration;       // Расчетная длительность (минуты)
    String target_product;             // Целевой продукт (например, "Рыба (скумбрия, форель)")
    String wood_type;                  // Тип древесины (например, "Ольха, яблоня")
    
    // Этапы программы
    std::vector<ProgramStage> stages;  // Массив этапов
    
    /**
     * Конструктор по умолчанию
     */
    ProgramData()
        : transfer_id(""),
          timestamp(""),
          program_id(0),
          program_name(""),
          description(""),
          category("other"),
          estimated_duration(0),
          target_product(""),
          wood_type("") {
        stages.reserve(MAX_PROGRAM_STAGES);
    }
    
    /**
     * Добавление этапа в программу
     * 
     * @param stage Этап для добавления
     * @return true если этап добавлен, false если достигнут лимит
     */
    bool addStage(const ProgramStage& stage) {
        if (stages.size() < MAX_PROGRAM_STAGES) {
            stages.push_back(stage);
            return true;
        }
        return false;
    }
    
    /**
     * Получение количества этапов
     * 
     * @return Количество этапов в программе
     */
    size_t getStageCount() const {
        return stages.size();
    }
    
    /**
     * Получение этапа по индексу
     * 
     * @param index Индекс этапа (0-based)
     * @return Указатель на этап или nullptr если индекс вне диапазона
     */
    const ProgramStage* getStage(size_t index) const {
        if (index < stages.size()) {
            return &stages[index];
        }
        return nullptr;
    }
    
    /**
     * Вычисление общей длительности программы
     * 
     * @return Общая длительность всех этапов в минутах
     */
    uint16_t calculateTotalDuration() const {
        uint32_t total = 0;
        for (const auto& stage : stages) {
            total += stage.duration_minutes;
        }
        // Ограничение до uint16_t максимума
        return (total > 65535) ? 65535 : static_cast<uint16_t>(total);
    }
    
    /**
     * Валидация программы
     * 
     * Проверяет:
     * - Наличие обязательных полей
     * - Наличие хотя бы одного этапа
     * - Валидность всех этапов
     * - Последовательность номеров этапов
     * 
     * @return true если программа валидна
     */
    bool isValid() const {
        // Проверка обязательных полей
        if (transfer_id.isEmpty() || 
            timestamp.isEmpty() ||
            program_id <= 0 || 
            program_name.isEmpty() ||
            stages.empty()) {
            return false;
        }
        
        // Проверка количества этапов
        if (stages.size() > MAX_PROGRAM_STAGES) {
            return false;
        }
        
        // Проверка валидности и последовательности этапов
        for (size_t i = 0; i < stages.size(); i++) {
            // Проверка валидности этапа
            if (!stages[i].isValid()) {
                return false;
            }
            
            // Проверка последовательности номеров (1, 2, 3...)
            if (stages[i].stage_number != i + 1) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Получение имени файла для сохранения
     * 
     * @return Путь к файлу программы в LittleFS
     */
    String getFilePath() const {
        return "/programs/program_" + String(program_id) + ".json";
    }
    
    /**
     * Получение метаданных программы
     * 
     * @return Структура ProgramMetadata с заполненными данными
     */
    ProgramMetadata getMetadata() const {
        ProgramMetadata metadata;
        metadata.program_id = program_id;
        metadata.program_name = program_name;
        metadata.category = category;
        metadata.stage_count = static_cast<uint8_t>(stages.size());
        metadata.total_duration_minutes = calculateTotalDuration();
        metadata.uploaded_at = timestamp;
        metadata.file_size = 0; // Будет установлен при сохранении
        return metadata;
    }
    
    /**
     * Получение краткого описания программы
     * 
     * @return Строка с кратким описанием
     */
    String getSummary() const {
        String summary = program_name;
        summary += " (ID: " + String(program_id) + ")";
        summary += " - " + String(stages.size()) + " этапов";
        summary += ", " + String(calculateTotalDuration()) + " мин";
        if (!category.isEmpty() && category != "other") {
            summary += ", " + category;
        }
        return summary;
    }
    
    /**
     * Очистка данных программы
     */
    void clear() {
        transfer_id = "";
        timestamp = "";
        program_id = 0;
        program_name = "";
        description = "";
        category = "other";
        estimated_duration = 0;
        target_product = "";
        wood_type = "";
        stages.clear();
    }
};

#endif // PROGRAM_DATA_H
