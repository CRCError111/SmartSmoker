/**
 * ProgramExecutor.h - Компонент для загрузки и выполнения программ копчения
 * 
 * Этот компонент отвечает за:
 * - Загрузку программ из файловой системы LittleFS
 * - Парсинг и валидацию программ перед выполнением
 * - Подготовку программ к выполнению
 * - Обработку ошибок загрузки
 * 
 * ProgramExecutor является мостом между файловым хранилищем программ
 * и системой выполнения программ копчения.
 * 
 * @file ProgramExecutor.h
 * @version 1.0
 * @author Smart Smoker Team
 */

#ifndef PROGRAM_EXECUTOR_H
#define PROGRAM_EXECUTOR_H

#include <Arduino.h>
#include "StorageManager.h"
#include "ProgramParser.h"
#include "ProgramData.h"

/**
 * Класс для загрузки и подготовки программ к выполнению
 * 
 * ProgramExecutor интегрирует StorageManager и ProgramParser для
 * обеспечения надежной загрузки программ из файловой системы.
 * 
 * Основные функции:
 * - loadProgram(): Загрузка программы по ID с полной валидацией
 * - Обработка ошибок: program_not_found, invalid_data, corrupted_file
 * - Валидация целостности данных перед выполнением
 */
class ProgramExecutor {
private:
    StorageManager* storage;           // Указатель на менеджер хранилища
    
public:
    /**
     * Конструктор
     * 
     * @param storageManager Указатель на инициализированный StorageManager
     */
    ProgramExecutor(StorageManager* storageManager) 
        : storage(storageManager) {}
    
    /**
     * Загрузка программы для выполнения
     * 
     * Выполняет следующие операции:
     * 1. Читает файл программы через StorageManager::loadProgram()
     * 2. Парсит JSON через ProgramParser::parse()
     * 3. Валидирует целостность данных
     * 4. Загружает этапы в последовательность выполнения
     * 
     * Обработка ошибок:
     * - Файл не найден: возвращает false, errorMessage = "program_not_found"
     * - Невалидный JSON: возвращает false, errorMessage = "invalid_json"
     * - Поврежденные данные: возвращает false, errorMessage = "corrupted_data"
     * 
     * @param programId ID программы для загрузки
     * @param program Выходная структура ProgramData для заполнения
     * @param errorMessage Выходной параметр с описанием ошибки
     * @return true если загрузка успешна, false в противном случае
     * 
     * Требования: 10.1, 10.2, 10.3, 10.4, 10.5
     */
    bool loadProgram(int programId, ProgramData& program, String& errorMessage) {
        // Проверка валидности programId
        if (programId <= 0) {
            errorMessage = "invalid_program_id";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Invalid program ID: %d\n", programId);
            return false;
        }
        
        // Проверка инициализации StorageManager
        if (storage == nullptr || !storage->isReady()) {
            errorMessage = "storage_not_ready";
            Serial.println("[ERROR] ProgramExecutor::loadProgram() - Storage manager not ready");
            return false;
        }
        
        // Шаг 1: Чтение файла программы через StorageManager::loadProgram()
        String json;
        if (!storage->loadProgram(programId, json)) {
            errorMessage = "program_not_found";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Program file not found: %d\n", programId);
            return false;
        }
        
        // Проверка что JSON не пустой
        if (json.isEmpty()) {
            errorMessage = "empty_program_file";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Program file is empty: %d\n", programId);
            return false;
        }
        
        // Шаг 2: Парсинг JSON через ProgramParser::parse()
        if (!ProgramParser::parse(json, program)) {
            errorMessage = "invalid_json";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Failed to parse program JSON: %d\n", programId);
            return false;
        }
        
        // Шаг 3: Валидация целостности данных
        if (!program.isValid()) {
            errorMessage = "corrupted_data";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Program data validation failed: %d\n", programId);
            return false;
        }
        
        // Проверка соответствия program_id
        if (program.program_id != programId) {
            errorMessage = "program_id_mismatch";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Program ID mismatch (expected %d, got %d)\n", 
                         programId, program.program_id);
            return false;
        }
        
        // Шаг 4: Загрузка этапов в последовательность выполнения
        // Проверка что все этапы загружены
        if (program.getStageCount() == 0) {
            errorMessage = "no_stages";
            Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Program has no stages: %d\n", programId);
            return false;
        }
        
        // Валидация каждого этапа
        for (size_t i = 0; i < program.getStageCount(); i++) {
            const ProgramStage* stage = program.getStage(i);
            if (stage == nullptr) {
                errorMessage = "invalid_stage";
                Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Invalid stage at index %d\n", i);
                return false;
            }
            
            if (!stage->isValid()) {
                errorMessage = "invalid_stage_data";
                Serial.printf("[ERROR] ProgramExecutor::loadProgram() - Stage %d validation failed\n", 
                             stage->stage_number);
                return false;
            }
        }
        
        // Успешная загрузка
        Serial.printf("[INFO] ProgramExecutor::loadProgram() - Successfully loaded program %d (%s) with %d stages\n",
                     programId, program.program_name.c_str(), program.getStageCount());
        
        return true;
    }
    
    /**
     * Проверка существования программы
     * 
     * Быстрая проверка наличия файла программы без полной загрузки.
     * 
     * @param programId ID программы для проверки
     * @return true если программа существует, false в противном случае
     */
    bool programExists(int programId) {
        if (storage == nullptr || !storage->isReady()) {
            return false;
        }
        
        String path = "/programs/program_" + String(programId) + ".json";
        return storage->fileExists(path);
    }
    
    /**
     * Получение краткой информации о программе
     * 
     * Загружает только метаданные программы без полного парсинга этапов.
     * Полезно для отображения списка программ.
     * 
     * @param programId ID программы
     * @param metadata Выходная структура ProgramMetadata
     * @return true если метаданные получены, false в противном случае
     */
    bool getProgramMetadata(int programId, ProgramMetadata& metadata) {
        if (storage == nullptr || !storage->isReady()) {
            return false;
        }
        
        // Загрузка JSON
        String json;
        if (!storage->loadProgram(programId, json)) {
            return false;
        }
        
        // Парсинг только метаданных (без полной валидации этапов)
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, json);
        if (error) {
            return false;
        }
        
        // Заполнение метаданных
        metadata.program_id = doc["program_id"] | 0;
        metadata.program_name = doc["program_name"] | "";
        metadata.category = doc["category"] | "other";
        metadata.uploaded_at = doc["timestamp"] | "";
        metadata.file_size = json.length();
        
        // Подсчет этапов и общей длительности
        if (doc.containsKey("stages") && doc["stages"].is<JsonArray>()) {
            JsonArray stages = doc["stages"].as<JsonArray>();
            metadata.stage_count = stages.size();
            
            uint32_t totalDuration = 0;
            for (JsonObject stage : stages) {
                totalDuration += stage["duration_minutes"] | 0;
            }
            metadata.total_duration_minutes = (totalDuration > 65535) ? 65535 : totalDuration;
        }
        
        return true;
    }
    
    /**
     * Валидация программы без загрузки
     * 
     * Проверяет корректность программы без загрузки в память.
     * Полезно для диагностики проблем с программами.
     * 
     * @param programId ID программы для валидации
     * @param errorMessage Выходной параметр с описанием ошибки
     * @return true если программа валидна, false в противном случае
     */
    bool validateProgram(int programId, String& errorMessage) {
        if (storage == nullptr || !storage->isReady()) {
            errorMessage = "storage_not_ready";
            return false;
        }
        
        // Загрузка JSON
        String json;
        if (!storage->loadProgram(programId, json)) {
            errorMessage = "program_not_found";
            return false;
        }
        
        // Валидация через ProgramParser
        return ProgramParser::validate(json, errorMessage);
    }
};

#endif // PROGRAM_EXECUTOR_H
