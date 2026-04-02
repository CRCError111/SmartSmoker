/**
 * ProgramIndex.h - Управление индексным файлом программ
 * 
 * Этот компонент отвечает за управление файлом /programs/index.json,
 * который содержит список всех программ на устройстве с метаданными.
 * 
 * Индексный файл используется для:
 * - Быстрого получения списка программ без чтения всех файлов
 * - Синхронизации с веб-сайтом (endpoint /api/programs/list)
 * - Отображения информации о программах в UI
 * 
 * Структура индексного файла:
 * {
 *   "programs": [
 *     {
 *       "program_id": 1,
 *       "program_name": "Копчение рыбы",
 *       "category": "fish",
 *       "stage_count": 3,
 *       "total_duration_minutes": 180,
 *       "uploaded_at": "2026-02-10T14:30:25Z",
 *       "file_size": 2048
 *     }
 *   ],
 *   "last_updated": "2026-02-10T14:30:25Z",
 *   "total_programs": 1
 * }
 * 
 * @file ProgramIndex.h
 * @version 1.0
 * @author Smart Smoker Team
 */

#ifndef PROGRAM_INDEX_H
#define PROGRAM_INDEX_H

#include <Arduino.h>
#include <ArduinoJson.h>
#include <LittleFS.h>
#include "ProgramData.h"

/**
 * Класс для управления индексным файлом программ
 * 
 * ProgramIndex обеспечивает атомарные операции с индексом программ:
 * - Добавление/обновление программы в индексе
 * - Удаление программы из индекса
 * - Получение списка всех программ
 * 
 * Все операции автоматически обновляют поля last_updated и total_programs.
 */
class ProgramIndex {
private:
    static constexpr const char* INDEX_FILE = "/programs/index.json";
    
    /**
     * Загрузка текущего индекса из файла
     * 
     * @param doc JSON документ для заполнения
     * @return true если загрузка успешна, false если файл не существует или поврежден
     */
    bool loadIndex(JsonDocument& doc) {
        // Проверка существования файла
        if (!LittleFS.exists(INDEX_FILE)) {
            // Создание пустого индекса
            doc["programs"].to<JsonArray>();
            doc["last_updated"] = "";
            doc["total_programs"] = 0;
            return true;
        }
        
        // Чтение файла
        File file = LittleFS.open(INDEX_FILE, "r");
        if (!file) {
            Serial.printf("[ERROR] ProgramIndex::loadIndex() - Failed to open index file\n");
            return false;
        }
        
        // Парсинг JSON
        DeserializationError error = deserializeJson(doc, file);
        file.close();
        
        if (error) {
            Serial.printf("[ERROR] ProgramIndex::loadIndex() - JSON parse error: %s\n", error.c_str());
            return false;
        }
        
        return true;
    }
    
    /**
     * Сохранение индекса в файл
     * 
     * @param doc JSON документ для сохранения
     * @return true если сохранение успешно, false в противном случае
     */
    bool saveIndex(const JsonDocument& doc) {
        // Создание директории /programs если не существует
        if (!LittleFS.exists("/programs")) {
            LittleFS.mkdir("/programs");
        }
        
        // Открытие файла для записи
        File file = LittleFS.open(INDEX_FILE, "w");
        if (!file) {
            Serial.printf("[ERROR] ProgramIndex::saveIndex() - Failed to open index file for writing\n");
            return false;
        }
        
        // Сериализация JSON в файл
        size_t bytesWritten = serializeJson(doc, file);
        file.close();
        
        if (bytesWritten == 0) {
            Serial.printf("[ERROR] ProgramIndex::saveIndex() - Failed to write index file\n");
            return false;
        }
        
        Serial.printf("[INFO] ProgramIndex::saveIndex() - Index saved (%u bytes)\n", bytesWritten);
        return true;
    }
    
    /**
     * Получение текущего времени в формате ISO 8601
     * 
     * @return Строка с текущим временем
     */
    String getCurrentTimestamp() {
        // В реальной системе здесь должно быть получение времени из RTC или NTP
        // Для простоты используем millis()
        unsigned long ms = millis();
        unsigned long seconds = ms / 1000;
        unsigned long minutes = seconds / 60;
        unsigned long hours = minutes / 60;
        
        char timestamp[32];
        snprintf(timestamp, sizeof(timestamp), "2026-01-01T%02lu:%02lu:%02luZ", 
                hours % 24, minutes % 60, seconds % 60);
        
        return String(timestamp);
    }

public:
    /**
     * Добавление или обновление программы в индексе
     * 
     * Если программа с таким program_id уже существует, она будет обновлена.
     * Если программы нет, она будет добавлена в конец списка.
     * 
     * Автоматически обновляет:
     * - last_updated: текущее время
     * - total_programs: количество программ в индексе
     * 
     * @param metadata Метаданные программы для добавления/обновления
     * @return true если операция успешна, false в противном случае
     * 
     * Требования: 6.7, 11.6
     */
    bool addProgram(const ProgramMetadata& metadata) {
        Serial.printf("[INFO] ProgramIndex::addProgram() - Adding/updating program %d\n", 
                     metadata.program_id);
        
        // Загрузка текущего индекса
        JsonDocument doc; // 16KB для индекса
        if (!loadIndex(doc)) {
            Serial.println("[ERROR] ProgramIndex::addProgram() - Failed to load index");
            return false;
        }
        
        // Получение массива программ
        JsonArray programs = doc["programs"].as<JsonArray>();
        
        // Поиск существующей программы с таким program_id
        bool found = false;
        for (JsonObject program : programs) {
            if (program["program_id"] == metadata.program_id) {
                // Обновление существующей программы
                program["program_name"] = metadata.program_name;
                program["category"] = metadata.category;
                program["stage_count"] = metadata.stage_count;
                program["total_duration_minutes"] = metadata.total_duration_minutes;
                program["uploaded_at"] = metadata.uploaded_at;
                program["file_size"] = metadata.file_size;
                
                found = true;
                Serial.printf("[INFO] ProgramIndex::addProgram() - Updated existing program %d\n", 
                            metadata.program_id);
                break;
            }
        }
        
        // Если программа не найдена, добавляем новую
        if (!found) {
            JsonObject newProgram = programs.add<JsonObject>();
            newProgram["program_id"] = metadata.program_id;
            newProgram["program_name"] = metadata.program_name;
            newProgram["category"] = metadata.category;
            newProgram["stage_count"] = metadata.stage_count;
            newProgram["total_duration_minutes"] = metadata.total_duration_minutes;
            newProgram["uploaded_at"] = metadata.uploaded_at;
            newProgram["file_size"] = metadata.file_size;
            
            Serial.printf("[INFO] ProgramIndex::addProgram() - Added new program %d\n", 
                        metadata.program_id);
        }
        
        // Обновление last_updated и total_programs
        doc["last_updated"] = getCurrentTimestamp();
        doc["total_programs"] = programs.size();
        
        // Сохранение индекса
        if (!saveIndex(doc)) {
            Serial.println("[ERROR] ProgramIndex::addProgram() - Failed to save index");
            return false;
        }
        
        Serial.printf("[INFO] ProgramIndex::addProgram() - Success (total programs: %d)\n", 
                     programs.size());
        return true;
    }
    
    /**
     * Удаление программы из индекса
     * 
     * Находит и удаляет запись с указанным program_id.
     * Если программа не найдена, операция считается успешной (идемпотентность).
     * 
     * Автоматически обновляет:
     * - last_updated: текущее время
     * - total_programs: количество программ в индексе
     * 
     * @param programId ID программы для удаления
     * @return true если операция успешна, false в противном случае
     * 
     * Требования: 11.6, 12.4
     */
    bool removeProgram(int programId) {
        Serial.printf("[INFO] ProgramIndex::removeProgram() - Removing program %d\n", programId);
        
        // Загрузка текущего индекса
        JsonDocument doc; // 16KB для индекса
        if (!loadIndex(doc)) {
            Serial.println("[ERROR] ProgramIndex::removeProgram() - Failed to load index");
            return false;
        }
        
        // Получение массива программ
        JsonArray programs = doc["programs"].as<JsonArray>();
        
        // Поиск и удаление программы
        bool found = false;
        size_t indexToRemove = 0;
        
        for (size_t i = 0; i < programs.size(); i++) {
            JsonObject program = programs[i];
            if (program["program_id"] == programId) {
                indexToRemove = i;
                found = true;
                break;
            }
        }
        
        if (found) {
            // Удаление элемента из массива
            programs.remove(indexToRemove);
            Serial.printf("[INFO] ProgramIndex::removeProgram() - Program %d removed from index\n", 
                        programId);
        } else {
            Serial.printf("[INFO] ProgramIndex::removeProgram() - Program %d not found in index (already removed)\n", 
                        programId);
        }
        
        // Обновление last_updated и total_programs
        doc["last_updated"] = getCurrentTimestamp();
        doc["total_programs"] = programs.size();
        
        // Сохранение индекса
        if (!saveIndex(doc)) {
            Serial.println("[ERROR] ProgramIndex::removeProgram() - Failed to save index");
            return false;
        }
        
        Serial.printf("[INFO] ProgramIndex::removeProgram() - Success (total programs: %d)\n", 
                     programs.size());
        return true;
    }
    
    /**
     * Получение списка всех программ
     * 
     * Читает индексный файл и возвращает его содержимое в виде JSON string.
     * Используется для endpoint /api/programs/list.
     * 
     * @param json Выходной параметр с JSON списка программ
     * @return true если операция успешна, false в противном случае
     * 
     * Требования: 11.2
     */
    bool getList(String& json) {
        Serial.println("[INFO] ProgramIndex::getList() - Getting program list");
        
        // Проверка существования файла
        if (!LittleFS.exists(INDEX_FILE)) {
            // Возврат пустого индекса
            json = "{\"programs\":[],\"last_updated\":\"\",\"total_programs\":0}";
            Serial.println("[INFO] ProgramIndex::getList() - Index file not found, returning empty list");
            return true;
        }
        
        // Чтение файла
        File file = LittleFS.open(INDEX_FILE, "r");
        if (!file) {
            Serial.println("[ERROR] ProgramIndex::getList() - Failed to open index file");
            return false;
        }
        
        json = file.readString();
        file.close();
        
        if (json.isEmpty()) {
            Serial.println("[ERROR] ProgramIndex::getList() - Index file is empty");
            return false;
        }
        
        Serial.printf("[INFO] ProgramIndex::getList() - Success (%u bytes)\n", json.length());
        return true;
    }
    
    /**
     * Получение количества программ в индексе
     * 
     * @return Количество программ или 0 в случае ошибки
     */
    int getProgramCount() {
        JsonDocument doc;
        if (!loadIndex(doc)) {
            return 0;
        }
        
        return doc["total_programs"] | 0;
    }
    
    /**
     * Проверка существования программы в индексе
     * 
     * @param programId ID программы для проверки
     * @return true если программа существует в индексе, false в противном случае
     */
    bool programExists(int programId) {
        JsonDocument doc;
        if (!loadIndex(doc)) {
            return false;
        }
        
        JsonArray programs = doc["programs"].as<JsonArray>();
        for (JsonObject program : programs) {
            if (program["program_id"] == programId) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Получение метаданных программы из индекса
     * 
     * @param programId ID программы
     * @param metadata Выходной параметр с метаданными
     * @return true если программа найдена, false в противном случае
     */
    bool getProgramMetadata(int programId, ProgramMetadata& metadata) {
        JsonDocument doc;
        if (!loadIndex(doc)) {
            return false;
        }
        
        JsonArray programs = doc["programs"].as<JsonArray>();
        for (JsonObject program : programs) {
            if (program["program_id"] == programId) {
                metadata.program_id = program["program_id"];
                metadata.program_name = program["program_name"].as<String>();
                metadata.category = program["category"].as<String>();
                metadata.stage_count = program["stage_count"];
                metadata.total_duration_minutes = program["total_duration_minutes"];
                metadata.uploaded_at = program["uploaded_at"].as<String>();
                metadata.file_size = program["file_size"];
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Очистка индекса (удаление всех программ)
     * 
     * Используется для тестирования или полной очистки устройства.
     * 
     * @return true если операция успешна, false в противном случае
     */
    bool clear() {
        Serial.println("[INFO] ProgramIndex::clear() - Clearing index");
        
        JsonDocument doc;
        doc["programs"].to<JsonArray>();
        doc["last_updated"] = getCurrentTimestamp();
        doc["total_programs"] = 0;
        
        if (!saveIndex(doc)) {
            Serial.println("[ERROR] ProgramIndex::clear() - Failed to save empty index");
            return false;
        }
        
        Serial.println("[INFO] ProgramIndex::clear() - Index cleared successfully");
        return true;
    }
};

#endif // PROGRAM_INDEX_H
