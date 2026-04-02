/**
 * ProgramParser.h - Парсер и валидатор JSON программ копчения
 * 
 * Этот компонент отвечает за:
 * - Валидацию JSON программ против схемы
 * - Парсинг JSON в структуры данных ProgramData
 * - Форматирование структур обратно в JSON
 * 
 * Используется в WebServerManager для обработки программ, получаемых с веб-сайта.
 * 
 * @file ProgramParser.h
 * @version 1.0
 * @author Smart Smoker Team
 */

#ifndef PROGRAM_PARSER_H
#define PROGRAM_PARSER_H

#include <Arduino.h>
#include <ArduinoJson.h>
#include "ProgramData.h"

/**
 * Класс для парсинга и валидации JSON программ
 * 
 * ProgramParser - это stateless утилитный класс, который предоставляет
 * методы для работы с JSON представлением программ копчения.
 * 
 * Основные функции:
 * - validate(): Проверка JSON на соответствие схеме
 * - parse(): Преобразование JSON в структуру ProgramData
 * - format(): Преобразование структуры ProgramData в JSON
 */
class ProgramParser {
public:
    /**
     * Валидация JSON программы против схемы
     * 
     * Проверяет:
     * - Валидность JSON синтаксиса
     * - Наличие обязательных полей (program_id, program_name, stages)
     * - Непустой массив stages
     * - Диапазоны значений: target_temp (0-100°C), проценты (0-100)
     * - Последовательность stage_order (1, 2, 3...)
     * 
     * @param json JSON string программы для валидации
     * @param errorMessage Выходной параметр с описанием ошибки (если есть)
     * @return true если валидация успешна, false в противном случае
     * 
     * Требования: 5.3, 5.4, 5.5, 5.6, 5.7, 5.8
     */
    static bool validate(const String& json, String& errorMessage) {
        // Создаем JSON документ для парсинга
        JsonDocument doc; // 8KB для программы
        
        // Парсинг JSON
        DeserializationError error = deserializeJson(doc, json);
        if (error) {
            errorMessage = "Невалидный JSON формат: ";
            errorMessage += error.c_str();
            return false;
        }
        
        // Проверка обязательных полей метаданных
        if (!doc["program_id"].is<int>()) {
            errorMessage = "Отсутствует обязательное поле: program_id";
            return false;
        }
        if (!doc["program_name"].is<const char*>()) {
            errorMessage = "Отсутствует обязательное поле: program_name";
            return false;
        }
        if (!doc["stages"].is<JsonArray>()) {
            errorMessage = "Отсутствует обязательное поле: stages";
            return false;
        }
        
        // Проверка типов обязательных полей
        if (!doc["program_id"].is<int>()) {
            errorMessage = "Поле program_id должно быть целым числом";
            return false;
        }
        if (!doc["program_name"].is<const char*>()) {
            errorMessage = "Поле program_name должно быть строкой";
            return false;
        }
        if (!doc["stages"].is<JsonArray>()) {
            errorMessage = "Поле stages должно быть массивом";
            return false;
        }
        
        // Проверка program_id > 0
        int programId = doc["program_id"];
        if (programId <= 0) {
            errorMessage = "Значение program_id должно быть больше 0";
            return false;
        }
        
        // Проверка program_name не пустое
        String programName = doc["program_name"].as<String>();
        if (programName.isEmpty()) {
            errorMessage = "Поле program_name не может быть пустым";
            return false;
        }
        
        // Проверка массива stages
        JsonArray stages = doc["stages"].as<JsonArray>();
        if (stages.size() == 0) {
            errorMessage = "Программа должна содержать хотя бы один этап";
            return false;
        }
        
        if (stages.size() > MAX_PROGRAM_STAGES) {
            errorMessage = "Превышено максимальное количество этапов: ";
            errorMessage += String(MAX_PROGRAM_STAGES);
            return false;
        }
        
        // Валидация каждого этапа
        for (size_t i = 0; i < stages.size(); i++) {
            JsonObject stage = stages[i];
            
            // Проверка обязательных полей этапа
            if (!stage["stage_order"].is<int>()) {
                errorMessage = "Этап " + String(i + 1) + ": отсутствует поле stage_order";
                return false;
            }
            if (!stage["stage_name"].is<const char*>()) {
                errorMessage = "Этап " + String(i + 1) + ": отсутствует поле stage_name";
                return false;
            }
            if (!stage["target_temp"].is<float>()) {
                errorMessage = "Этап " + String(i + 1) + ": отсутствует поле target_temp";
                return false;
            }
            if (!stage["duration_minutes"].is<int>()) {
                errorMessage = "Этап " + String(i + 1) + ": отсутствует поле duration_minutes";
                return false;
            }
            
            // Проверка последовательности stage_order (1, 2, 3...)
            int stageOrder = stage["stage_order"];
            if (stageOrder != (int)(i + 1)) {
                errorMessage = "Этап " + String(i + 1) + ": неверная последовательность stage_order (ожидается " + 
                              String(i + 1) + ", получено " + String(stageOrder) + ")";
                return false;
            }
            
            // Проверка stage_name не пустое
            String stageName = stage["stage_name"].as<String>();
            if (stageName.isEmpty()) {
                errorMessage = "Этап " + String(i + 1) + ": stage_name не может быть пустым";
                return false;
            }
            
            // Валидация target_temp (0-300°C)
            float targetTemp = stage["target_temp"];
            if (targetTemp < 0.0f || targetTemp > 300.0f) {
                errorMessage = "Этап " + String(i + 1) + ": target_temp вне диапазона (0-300°C): " + String(targetTemp);
                return false;
            }
            
            // Валидация target_humidity (0-100%) если присутствует
            if (stage["target_humidity"].is<float>()) {
                float targetHumidity = stage["target_humidity"];
                if (targetHumidity < 0.0f || targetHumidity > 100.0f) {
                    errorMessage = "Этап " + String(i + 1) + ": target_humidity вне диапазона (0-100%): " + String(targetHumidity);
                    return false;
                }
            }
            
            // Валидация duration_minutes > 0
            int durationMinutes = stage["duration_minutes"];
            if (durationMinutes <= 0) {
                errorMessage = "Этап " + String(i + 1) + ": duration_minutes должно быть больше 0";
                return false;
            }
            
            // Валидация smoke_intensity (0-100) если присутствует
            if (stage["smoke_intensity"].is<int>()) {
                int smokeIntensity = stage["smoke_intensity"];
                if (smokeIntensity < 0 || smokeIntensity > 100) {
                    errorMessage = "Этап " + String(i + 1) + ": smoke_intensity вне диапазона (0-100): " + String(smokeIntensity);
                    return false;
                }
            }
            
            // Валидация ventilation_percent (0-100) если присутствует
            if (stage["ventilation_percent"].is<int>()) {
                int ventilationPercent = stage["ventilation_percent"];
                if (ventilationPercent < 0 || ventilationPercent > 100) {
                    errorMessage = "Этап " + String(i + 1) + ": ventilation_percent вне диапазона (0-100): " + String(ventilationPercent);
                    return false;
                }
            }
            
            // Валидация compressor_pwm (0-100) если присутствует
            if (stage["compressor_pwm"].is<int>()) {
                int compressorPwm = stage["compressor_pwm"];
                if (compressorPwm < 0 || compressorPwm > 100) {
                    errorMessage = "Этап " + String(i + 1) + ": compressor_pwm вне диапазона (0-100): " + String(compressorPwm);
                    return false;
                }
            }
        }
        
        // Все проверки пройдены
        return true;
    }
    
    /**
     * Парсинг JSON в структуру ProgramData
     * 
     * Извлекает:
     * - Метаданные программы (transfer_id, program_id, program_name и т.д.)
     * - Массив этапов с полными параметрами
     * 
     * Примечание: Перед вызовом parse() рекомендуется вызвать validate()
     * для проверки корректности JSON.
     * 
     * @param json JSON string программы
     * @param program Выходная структура ProgramData для заполнения
     * @return true если парсинг успешен, false в противном случае
     * 
     * Требования: 5.3, 10.2, 14.3
     */
    static bool parse(const String& json, ProgramData& program) {
        // Создаем JSON документ для парсинга
        JsonDocument doc; // 8KB для программы
        
        // Парсинг JSON
        DeserializationError error = deserializeJson(doc, json);
        if (error) {
            Serial.printf("[ERROR] ProgramParser::parse() - JSON parse error: %s\n", error.c_str());
            return false;
        }
        
        // Очистка структуры программы
        program.clear();
        
        // Извлечение метаданных передачи
        if (doc["transfer_id"].is<const char*>()) {
            program.transfer_id = doc["transfer_id"].as<String>();
        }
        if (doc["timestamp"].is<const char*>()) {
            program.timestamp = doc["timestamp"].as<String>();
        }
        
        // Извлечение метаданных программы
        program.program_id = doc["program_id"];
        program.program_name = doc["program_name"].as<String>();
        
        if (doc["description"].is<const char*>()) {
            program.description = doc["description"].as<String>();
        }
        if (doc["category"].is<const char*>()) {
            program.category = doc["category"].as<String>();
        }
        if (doc["estimated_duration"].is<int>()) {
            program.estimated_duration = doc["estimated_duration"];
        }
        if (doc["target_product"].is<const char*>()) {
            program.target_product = doc["target_product"].as<String>();
        }
        if (doc["wood_type"].is<const char*>()) {
            program.wood_type = doc["wood_type"].as<String>();
        }
        
        // Парсинг массива этапов
        JsonArray stages = doc["stages"].as<JsonArray>();
        for (JsonObject stageObj : stages) {
            ProgramStage stage;
            
            // Обязательные поля
            stage.stage_number = stageObj["stage_order"];
            stage.stage_name = stageObj["stage_name"].as<String>();
            stage.target_temp = stageObj["target_temp"];
            stage.duration_minutes = stageObj["duration_minutes"];
            
            // Опциональные поля с дефолтными значениями
            stage.target_humidity = stageObj["target_humidity"].is<float>() ? 
                                   stageObj["target_humidity"].as<float>() : 0.0f;
            stage.use_smoke_generator = stageObj["use_smoke_generator"].is<bool>() ? 
                                       stageObj["use_smoke_generator"].as<bool>() : false;
            stage.smoke_intensity = stageObj["smoke_intensity"].is<int>() ? 
                                   stageObj["smoke_intensity"].as<uint8_t>() : 0;
            stage.ventilation_percent = stageObj["ventilation_percent"].is<int>() ? 
                                       stageObj["ventilation_percent"].as<uint8_t>() : 100;
            stage.internal_fan_on = stageObj["internal_fan_on"].is<bool>() ? 
                                   stageObj["internal_fan_on"].as<bool>() : false;
            stage.injection_fan_on = stageObj["injection_fan_on"].is<bool>() ? 
                                    stageObj["injection_fan_on"].as<bool>() : false;
            stage.compressor_pwm = stageObj["compressor_pwm"].is<int>() ? 
                                  stageObj["compressor_pwm"].as<uint8_t>() : 0;
            
            // Добавление этапа в программу
            if (!program.addStage(stage)) {
                Serial.printf("[ERROR] ProgramParser::parse() - Failed to add stage %d (max stages reached)\n", 
                            stage.stage_number);
                return false;
            }
        }
        
        Serial.printf("[INFO] ProgramParser::parse() - Successfully parsed program %d with %d stages\n", 
                     program.program_id, program.getStageCount());
        
        return true;
    }
    
    /**
     * Форматирование структуры ProgramData в JSON
     * 
     * Создает JSON представление программы с форматированием (pretty print).
     * Полезно для:
     * - Сохранения программ в файлы
     * - Отладки и логирования
     * - Отправки программ обратно на веб-сайт
     * 
     * @param program Структура ProgramData для форматирования
     * @return JSON string программы с форматированием
     * 
     * Требования: 14.4
     */
    static String format(const ProgramData& program) {
        // Создаем JSON документ
        JsonDocument doc; // 8KB для программы
        
        // Заполнение метаданных передачи
        doc["transfer_id"] = program.transfer_id;
        doc["timestamp"] = program.timestamp;
        
        // Заполнение метаданных программы
        doc["program_id"] = program.program_id;
        doc["program_name"] = program.program_name;
        doc["description"] = program.description;
        doc["category"] = program.category;
        doc["estimated_duration"] = program.estimated_duration;
        doc["target_product"] = program.target_product;
        doc["wood_type"] = program.wood_type;
        
        // Формирование массива этапов
        JsonArray stages = doc["stages"].to<JsonArray>();
        for (size_t i = 0; i < program.getStageCount(); i++) {
            const ProgramStage* stage = program.getStage(i);
            if (stage == nullptr) {
                continue;
            }
            
            JsonObject stageObj = stages.add<JsonObject>();
            stageObj["stage_order"] = stage->stage_number;
            stageObj["stage_name"] = stage->stage_name;
            stageObj["target_temp"] = stage->target_temp;
            stageObj["target_humidity"] = stage->target_humidity;
            stageObj["duration_minutes"] = stage->duration_minutes;
            stageObj["use_smoke_generator"] = stage->use_smoke_generator;
            stageObj["smoke_intensity"] = stage->smoke_intensity;
            stageObj["ventilation_percent"] = stage->ventilation_percent;
            stageObj["internal_fan_on"] = stage->internal_fan_on;
            stageObj["injection_fan_on"] = stage->injection_fan_on;
            stageObj["compressor_pwm"] = stage->compressor_pwm;
        }
        
        // Сериализация в string с форматированием
        String output;
        serializeJsonPretty(doc, output);
        
        return output;
    }
    
    /**
     * Форматирование структуры ProgramData в компактный JSON (без форматирования)
     * 
     * Создает минимизированное JSON представление программы.
     * Используется для передачи по сети для экономии трафика.
     * 
     * @param program Структура ProgramData для форматирования
     * @return Компактный JSON string программы
     */
    static String formatCompact(const ProgramData& program) {
        // Создаем JSON документ
        JsonDocument doc; // 8KB для программы
        
        // Заполнение метаданных передачи
        doc["transfer_id"] = program.transfer_id;
        doc["timestamp"] = program.timestamp;
        
        // Заполнение метаданных программы
        doc["program_id"] = program.program_id;
        doc["program_name"] = program.program_name;
        doc["description"] = program.description;
        doc["category"] = program.category;
        doc["estimated_duration"] = program.estimated_duration;
        doc["target_product"] = program.target_product;
        doc["wood_type"] = program.wood_type;
        
        // Формирование массива этапов
        JsonArray stages = doc["stages"].to<JsonArray>();
        for (size_t i = 0; i < program.getStageCount(); i++) {
            const ProgramStage* stage = program.getStage(i);
            if (stage == nullptr) {
                continue;
            }
            
            JsonObject stageObj = stages.add<JsonObject>();
            stageObj["stage_order"] = stage->stage_number;
            stageObj["stage_name"] = stage->stage_name;
            stageObj["target_temp"] = stage->target_temp;
            stageObj["target_humidity"] = stage->target_humidity;
            stageObj["duration_minutes"] = stage->duration_minutes;
            stageObj["use_smoke_generator"] = stage->use_smoke_generator;
            stageObj["smoke_intensity"] = stage->smoke_intensity;
            stageObj["ventilation_percent"] = stage->ventilation_percent;
            stageObj["internal_fan_on"] = stage->internal_fan_on;
            stageObj["injection_fan_on"] = stage->injection_fan_on;
            stageObj["compressor_pwm"] = stage->compressor_pwm;
        }
        
        // Сериализация в string без форматирования
        String output;
        serializeJson(doc, output);
        
        return output;
    }
};

#endif // PROGRAM_PARSER_H
