/**
 * Менеджер файловой системы согласно ТЗ
 * 
 * @file StorageManager.h
 * @version 2.0 - api_token Storage
 */

#ifndef STORAGE_MANAGER_H
#define STORAGE_MANAGER_H

#include <Arduino.h>
#include <LittleFS.h>
#include <ArduinoJson.h>
#include <time.h>
#include "constants.h"
#include "SystemState.h"
#include "ProgramStructures.h"
#include "SecureStorage.h"

/**
 * Класс для управления файловой системой LittleFS
 */
class StorageManager {
private:
    bool filesystemMounted = false;
    
    // Пути к файлам конфигурации
    static constexpr const char* CONFIG_FILE = "/config.json";
    static constexpr const char* CLOUD_FILE = "/cloud.json";
    static constexpr const char* WIFI_FILE = "/wifi.json";
    static constexpr const char* PROGRAMS_FILE = "/programs.json";
    
    // Константы для оптимизации LittleFS
    static constexpr size_t WRITE_BUFFER_SIZE = 512;  // Размер буфера для записи
    static constexpr size_t LOW_SPACE_WARNING = 20480; // 20KB - порог предупреждения
    static constexpr size_t DEFRAG_THRESHOLD = 30720;  // 30KB - порог для дефрагментации

public:
    /**
     * Инициализация файловой системы
     */
    bool init() {
        Serial.println("Initializing LittleFS...");
        
        if (!LittleFS.begin(true)) {  // true = автоформатирование при ошибке
            Serial.println("✗ LittleFS mount failed!");
            return false;
        }
        
        filesystemMounted = true;
        SecureStorage::init();
        Serial.println("✓ LittleFS mounted");
        
        // Вывод информации о файловой системе
        size_t totalBytes = LittleFS.totalBytes();
        size_t usedBytes = LittleFS.usedBytes();
        size_t freeBytes = totalBytes - usedBytes;
        Serial.printf("  Total: %u bytes, Used: %u bytes, Free: %u bytes\n", 
                     totalBytes, usedBytes, freeBytes);
        
        // Проверка свободного места
        if (!checkFreeSpace()) {
            Serial.println("⚠️  WARNING: Storage space is critically low!");
            
            // Попытка дефрагментации для освобождения места
            Serial.println("  Attempting storage optimization...");
            defragmentIfNeeded();
        }
        
        return true;
    }
    
    /**
     * Проверка монтирования файловой системы
     */
    bool isMounted() const {
        return filesystemMounted;
    }
    
    /**
     * Проверка готовности файловой системы
     */
    bool isReady() const {
        return filesystemMounted;
    }
    
    /**
     * Загрузка всех настроек
     */
    bool loadSettings(SystemState& state) {
        if (!filesystemMounted) {
            return false;
        }
        
        bool success = true;
        
        // Загрузка общих настроек
        if (!loadGeneralSettings(state)) {
            Serial.println("⚠️  No general settings found, using defaults");
            success = false;
        }
        
        // Загрузка облачных настроек
        if (!loadCloudSettings(state)) {
            Serial.println("⚠️  No cloud settings found");
            success = false;
        }
        
        // Загрузка WiFi настроек
        if (!loadWiFiSettings(state)) {
            Serial.println("⚠️  No WiFi settings found");
            success = false;
        }
        
        if (success) {
            Serial.println("✓ All settings loaded from LittleFS");
        }
        
        return success;
    }
    
    /**
     * Сохранение всех настроек
     */
    bool saveSettings(const SystemState& state) {
        if (!filesystemMounted) {
            return false;
        }
        
        bool success = true;
        
        // Сохранение общих настроек
        if (!saveGeneralSettings(state)) {
            Serial.println("✗ Failed to save general settings");
            success = false;
        }
        
        // Сохранение облачных настроек
        if (!saveCloudSettings(state)) {
            Serial.println("✗ Failed to save cloud settings");
            success = false;
        }
        
        // Сохранение WiFi настроек
        if (!saveWiFiSettings(state)) {
            Serial.println("✗ Failed to save WiFi settings");
            success = false;
        }
        
        if (success) {
            Serial.println("✓ All settings saved to LittleFS");
        }
        
        return success;
    }
    
    /**
     * Сохранение только облачных настроек
     */
    bool saveCloudSettings(const SystemState& state) {
        JsonDocument doc;

        doc["device_id"]             = state.deviceId;
        doc["api_token"]             = SecureStorage::encrypt(state.apiToken); // хранится зашифрованным
        doc["device_name"]           = state.deviceName;
        doc["device_bound"]          = state.deviceBound;
        doc["cloud_url"]             = state.cloudUrl;
        doc["sync_interval"]         = state.syncInterval;
        doc["program_sync_interval"] = state.programSyncInterval;

        String output;
        serializeJson(doc, output);

        bool result = writeFile(CLOUD_FILE, output);
        if (!result) {
            Serial.println("[ERROR] Failed to save cloud settings");
        }
        return result;
    }
    
    /**
     * Сохранение отдельной программы
     */
    bool saveProgram(const SmokingProgram& program) {
        if (!filesystemMounted) {
            return false;
        }
        
        // Создаем директорию для программ, если не существует
        if (!LittleFS.exists("/programs")) {
            LittleFS.mkdir("/programs");
        }
        
        // Создаем JSON документ в новом формате (совместимом с сайтом)
        JsonDocument doc;
        
        // Метаданные верхнего уровня
        doc["version"] = "1.0";
        doc["type"] = "program";
        doc["program_id"] = program.programId;
        
        // Добавляем timestamp экспорта
        time_t now = time(nullptr);
        struct tm timeinfo;
        localtime_r(&now, &timeinfo);
        char timestamp[32];
        strftime(timestamp, sizeof(timestamp), "%Y-%m-%d %H:%M:%S", &timeinfo);
        doc["exported_at"] = timestamp;
        
        // Данные программы в поле "data"
        JsonObject dataObj = doc["data"].to<JsonObject>();
        dataObj["name"] = program.name;
        dataObj["program_name"] = program.name;  // для совместимости
        dataObj["description"] = program.description;
        dataObj["category"] = program.category;
        dataObj["is_built_in"] = program.isBuiltIn;
        
        // Добавляем этапы
        JsonArray stagesArray = dataObj["stages"].to<JsonArray>();
        int order = 1;
        for (const auto& step : program.steps) {
            JsonObject stepObj = stagesArray.add<JsonObject>();
            stepObj["order"] = order++;
            stepObj["name"] = step.stepName;
            stepObj["stage_name"] = step.stepName;  // для совместимости
            stepObj["target_temp"] = step.targetTemp;
            stepObj["target_temp_device"] = step.targetTempDevice;
            stepObj["target_humidity"] = step.targetHumidity;
            stepObj["duration_minutes"] = step.durationMinutes;
            stepObj["hysteresis"] = step.hysteresis;
            stepObj["wait_for_temp"] = step.waitForTemp;
            stepObj["use_smoke_generator"] = step.useSmokeGenerator;
            stepObj["smoke_intensity"] = 80;  // значение по умолчанию
            stepObj["ventilation_percent"] = step.ventilationPercent;
            stepObj["internal_fan_on"] = step.internalFanOn;
            stepObj["injection_fan_on"] = step.injectionFanOn;
            stepObj["compressor_pwm"] = step.compressorPWM;
        }
        
        // Определяем имя файла
        String filename;
        if (program.isLocalProgram) {
            // Программа создана на контроллере - используем формат program_c{id}.json
            if (program.programId > 0) {
                filename = "/programs/program_c" + String(program.programId) + ".json";
            } else {
                // Генерируем уникальный ID на основе timestamp
                unsigned long uniqueId = millis() / 1000; // секунды с момента запуска
                filename = "/programs/program_c" + String(uniqueId) + ".json";
            }
        } else {
            // Программа импортирована с сайта - используем формат program_{id}.json
            if (program.programId > 0) {
                filename = "/programs/program_" + String(program.programId) + ".json";
            } else {
                // Иначе используем sanitized имя программы
                String sanitized = program.name;
                sanitized.toLowerCase();
                sanitized.replace(" ", "_");
                // Убираем символы, недопустимые в именах файлов
                String safe = "";
                for (int i = 0; i < (int)sanitized.length(); i++) {
                    char c = sanitized[i];
                    if ((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '_' || c == '-') {
                        safe += c;
                    }
                }
                if (safe.isEmpty()) safe = "unnamed";
                filename = "/programs/program_" + safe + ".json";
            }
        }
        
        String output;
        serializeJson(doc, output);
        
        bool success = writeFile(filename, output);
        if (success) {
            Serial.printf("✓ Program saved: %s (format: %s, id: %d, source: %s)\n", 
                program.name.c_str(), 
                program.isLocalProgram ? "controller" : "website",
                program.programId,
                program.isLocalProgram ? "local" : "imported");
        } else {
            Serial.printf("✗ Failed to save program: %s\n", program.name.c_str());
        }
        
        return success;
    }
    
    /**
     * Сохранение состояния выполнения программы (Recovery)
     */
    bool saveRunningState(const SystemState& state) {
        if (!filesystemMounted) return false;
        
        if (state.mode != SystemState::Mode::RUNNING || !state.currentProgram) {
            if (LittleFS.exists("/recovery.json")) {
                LittleFS.remove("/recovery.json");
            }
            return true;
        }
        
        JsonDocument doc;
        doc["program_name"] = state.currentProgram->name;
        doc["step_index"] = (int)state.currentStepIndex;
        doc["run_id"] = state.currentRunId;
        doc["step_elapsed"] = (unsigned long)(millis() - state.stepStartTime);
        doc["program_elapsed"] = (unsigned long)(millis() - state.programStartTime);
        doc["timestamp"] = (unsigned long)millis();
        
        String output;
        serializeJson(doc, output);
        return writeFile("/recovery.json", output);
    }
    
    /**
     * Загрузка облачных настроек (api_token, device_id, интервалы)
     */
    bool loadCloudSettings(SystemState& state) {
        String content = readFile(CLOUD_FILE);
        if (content.isEmpty()) {
            return false;
        }
        
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, content);
        
        if (error) {
            Serial.printf("[ERROR] Failed to parse cloud settings: %s\n", error.c_str());
            return false;
        }
        
        state.deviceId   = doc["device_id"]   | "";
        // api_token хранится зашифрованным — расшифровываем при загрузке
        String encToken  = doc["api_token"]   | "";
        state.apiToken   = encToken.isEmpty() ? "" : SecureStorage::decrypt(encToken);
        state.deviceName = doc["device_name"] | "Smart Smoker";
        state.deviceBound = doc["device_bound"] | false;
        state.cloudUrl = doc["cloud_url"] | "https://crcerror.ru";
        state.syncInterval = doc["sync_interval"] | 60;
        state.programSyncInterval = doc["program_sync_interval"] | 300;
        
        return true;
    }
    
private:
    /**
     * Загрузка WiFi настроек
     */
    bool loadWiFiSettings(SystemState& state) {
        String content = readFile(WIFI_FILE);
        if (content.isEmpty()) {
            return false;
        }
        
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, content);
        
        if (error) {
            Serial.printf("✗ Failed to parse WiFi settings: %s\n", error.c_str());
            return false;
        }
        
        // Загрузка данных
        state.ssid = doc["ssid"] | "";
        state.wifiPassword = doc["password"] | "";
        state.networkMode = static_cast<SystemState::NetworkMode>(doc["mode"] | 0);
        
        Serial.println("✓ WiFi settings loaded");
        Serial.printf("  SSID: %s\n", state.ssid.c_str());
        Serial.printf("  Mode: %s\n", state.networkMode == SystemState::NetworkMode::AP ? "AP" : "STA");
        
        return true;
    }
    
    /**
     * Сохранение WiFi настроек
     */
    bool saveWiFiSettings(const SystemState& state) {
        JsonDocument doc;
        
        doc["ssid"] = state.ssid;
        doc["password"] = state.wifiPassword;
        doc["mode"] = static_cast<int>(state.networkMode);
        
        String output;
        serializeJson(doc, output);
        return writeFile(WIFI_FILE, output);
    }
    
    /**
     * Загрузка общих настроек
     */
    bool loadGeneralSettings(SystemState& state) {
        String content = readFile(CONFIG_FILE);
        if (content.isEmpty()) {
            return false;
        }
        
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, content);
        
        if (error) {
            Serial.printf("✗ Failed to parse general settings: %s\n", error.c_str());
            return false;
        }
        
        // Загрузка данных
        state.displayBrightness = doc["display_brightness"] | 100;
        state.firmwareVersion = FIRMWARE_VERSION;  // Always use compiled version, not stored
        
        Serial.println("✓ General settings loaded");
        
        return true;
    }
    
    /**
     * Сохранение общих настроек
     */
    bool saveGeneralSettings(const SystemState& state) {
        JsonDocument doc;
        
        doc["display_brightness"] = state.displayBrightness;
        doc["firmware_version"] = state.firmwareVersion;
        doc["uptime"] = millis();
        
        String output;
        serializeJson(doc, output);
        return writeFile(CONFIG_FILE, output);
    }
    
public:
    /**
     * Чтение файла из файловой системы
     */
    String readFile(const String& path) {
        if (!filesystemMounted) {
            return "";
        }
        
        File file = LittleFS.open(path, "r");
        if (!file) {
            return "";
        }
        
        String content = file.readString();
        file.close();
        
        return content;
    }
    
    /**
     * Запись файла в файловую систему с буферизацией для больших файлов
     * 
     * @param path Путь к файлу
     * @param content Содержимое для записи
     * @return true если запись успешна, false в противном случае
     */
    bool writeFile(const String& path, const String& content) {
        if (!filesystemMounted) {
            return false;
        }
        
        // Проверка свободного места перед записью
        if (!checkFreeSpace()) {
            Serial.printf("⚠️  Low storage space warning before writing: %s\n", path.c_str());
        }
        
        // Для больших файлов (> 1KB) используем буферизованную запись
        if (content.length() > 1024) {
            return writeFileBuffered(path, content);
        }
        
        // Для маленьких файлов используем обычную запись
        File file = LittleFS.open(path, "w");
        if (!file) {
            Serial.printf("✗ Failed to open file for writing: %s\n", path.c_str());
            return false;
        }
        
        size_t bytesWritten = file.print(content);
        file.close();
        
        if (bytesWritten != content.length()) {
            Serial.printf("✗ Failed to write file: %s (expected %u, got %u)\n", 
                         path.c_str(), content.length(), bytesWritten);
            return false;
        }
        
        return true;
    }
    
    /**
     * Буферизованная запись файла для больших данных
     * 
     * @param path Путь к файлу
     * @param content Содержимое для записи
     * @return true если запись успешна, false в противном случае
     */
    bool writeFileBuffered(const String& path, const String& content) {
        File file = LittleFS.open(path, "w");
        if (!file) {
            Serial.printf("✗ Failed to open file for buffered writing: %s\n", path.c_str());
            return false;
        }
        
        size_t totalLength = content.length();
        size_t bytesWritten = 0;
        size_t offset = 0;
        
        // Записываем данные блоками по WRITE_BUFFER_SIZE байт
        while (offset < totalLength) {
            size_t chunkSize = min(WRITE_BUFFER_SIZE, totalLength - offset);
            String chunk = content.substring(offset, offset + chunkSize);
            
            size_t written = file.print(chunk);
            if (written != chunkSize) {
                Serial.printf("✗ Failed to write chunk at offset %u: %s\n", offset, path.c_str());
                file.close();
                LittleFS.remove(path); // Удаляем частично записанный файл
                return false;
            }
            
            bytesWritten += written;
            offset += chunkSize;
            
            // Даем возможность другим задачам выполниться
            yield();
        }
        
        file.close();
        
        if (bytesWritten != totalLength) {
            Serial.printf("✗ Failed to write complete file: %s (expected %u, wrote %u)\n", 
                         path.c_str(), totalLength, bytesWritten);
            LittleFS.remove(path); // Удаляем поврежденный файл
            return false;
        }
        
        Serial.printf("✓ Buffered write completed: %s (%u bytes in %u chunks)\n", 
                     path.c_str(), bytesWritten, (bytesWritten + WRITE_BUFFER_SIZE - 1) / WRITE_BUFFER_SIZE);
        
        return true;
    }
    
    /**
     * Удаление файла из файловой системы
     */
    bool deleteFile(const String& path) {
        if (!filesystemMounted) {
            return false;
        }
        
        return LittleFS.remove(path);
    }
    
    /**
     * Проверка существования файла
     */
    bool fileExists(const String& path) {
        if (!filesystemMounted) {
            return false;
        }
        
        return LittleFS.exists(path);
    }
    
    /**
     * Получение списка файлов в директории
     */
    std::vector<String> listFiles(const String& directory) {
        std::vector<String> files;
        
        if (!filesystemMounted) {
            return files;
        }
        
        File dir = LittleFS.open(directory);
        if (!dir || !dir.isDirectory()) {
            return files;
        }
        
        File file = dir.openNextFile();
        while (file) {
            files.push_back(String(file.name()));
            file = dir.openNextFile();
        }
        
        dir.close();
        return files;
    }
    
    /**
     * Получение списка файлов программ
     */
    std::vector<String> listProgramFiles() {
        std::vector<String> programs;
        
        if (!filesystemMounted) {
            return programs;
        }
        
        // Создаем директорию если не существует
        if (!LittleFS.exists("/programs")) {
            LittleFS.mkdir("/programs");
        }
        
        File dir = LittleFS.open("/programs");
        if (!dir || !dir.isDirectory()) {
            return programs;
        }
        
        File file = dir.openNextFile();
        while (file) {
            if (!file.isDirectory() && String(file.name()).endsWith(".json")) {
                programs.push_back(String(file.name()));
            }
            file = dir.openNextFile();
        }
        
        dir.close();
        return programs;
    }
    
    /**
     * Удаление файла программы
     */
    bool deleteProgramFile(const String& filename) {
        if (!filesystemMounted) {
            return false;
        }
        
        String fullPath = filename;
        if (!filename.startsWith("/programs/")) {
            fullPath = "/programs/" + filename;
        }
        
        if (!fullPath.endsWith(".json")) {
            fullPath += ".json";
        }
        
        bool success = deleteFile(fullPath);
        
        if (success) {
            Serial.printf("✓ Program file deleted: %s\n", fullPath.c_str());
        } else {
            Serial.printf("✗ Failed to delete program file: %s\n", fullPath.c_str());
        }
        
        return success;
    }
    
    /**
     * Получение информации о свободном месте
     */
    void getStorageInfo(size_t& totalBytes, size_t& usedBytes, size_t& freeBytes) {
        if (!filesystemMounted) {
            totalBytes = usedBytes = freeBytes = 0;
            return;
        }
        
        totalBytes = LittleFS.totalBytes();
        usedBytes = LittleFS.usedBytes();
        freeBytes = totalBytes - usedBytes;
    }
    
    /**
     * Получение свободного места в байтах
     */
    size_t getFreeBytes() {
        if (!filesystemMounted) {
            return 0;
        }
        
        return LittleFS.totalBytes() - LittleFS.usedBytes();
    }
    
    /**
     * Проверка свободного места и вывод предупреждения
     * 
     * @return true если места достаточно, false если < 20KB
     */
    bool checkFreeSpace() {
        if (!filesystemMounted) {
            return false;
        }
        
        size_t freeBytes = getFreeBytes();
        
        if (freeBytes < LOW_SPACE_WARNING) {
            Serial.printf("⚠️  WARNING: Low storage space! Free: %u bytes (< 20KB)\n", freeBytes);
            return false;
        }
        
        return true;
    }
    
    /**
     * Дефрагментация файловой системы при необходимости
     * 
     * Примечание: LittleFS не требует явной дефрагментации, но мы можем
     * оптимизировать использование места путем пересоздания файлов
     * 
     * @return true если дефрагментация выполнена или не требуется
     */
    bool defragmentIfNeeded() {
        if (!filesystemMounted) {
            return false;
        }
        
        size_t freeBytes = getFreeBytes();
        size_t usedBytes = LittleFS.usedBytes();
        
        // Проверяем, нужна ли дефрагментация
        // Если свободного места меньше порога, выполняем оптимизацию
        if (freeBytes < DEFRAG_THRESHOLD) {
            Serial.printf("ℹ️  Storage optimization needed. Free: %u bytes, Used: %u bytes\n", 
                         freeBytes, usedBytes);
            
            // LittleFS автоматически управляет блоками, но мы можем
            // удалить временные файлы и оптимизировать структуру
            
            // Удаление временных файлов (если есть)
            File root = LittleFS.open("/");
            File file = root.openNextFile();
            while (file) {
                String filename = String(file.name());
                if (filename.endsWith(".tmp") || filename.endsWith(".bak")) {
                    String path = "/" + filename;
                    file.close();
                    LittleFS.remove(path);
                    Serial.printf("  Removed temporary file: %s\n", path.c_str());
                    file = root.openNextFile();
                } else {
                    file = root.openNextFile();
                }
            }
            root.close();
            
            Serial.println("✓ Storage optimization completed");
            
            // Проверяем результат
            size_t newFreeBytes = getFreeBytes();
            Serial.printf("  Free space after optimization: %u bytes\n", newFreeBytes);
            
            return true;
        }
        
        return true; // Дефрагментация не требуется
    }
    
    /**
     * Сохранение программы в файл (для передачи с веб-сайта)
     * 
     * Требования: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6
     * 
     * @param programId ID программы
     * @param json JSON string программы
     * @return true если сохранение успешно, false в противном случае
     */
    bool saveProgram(int programId, const String& json) {
        if (!filesystemMounted) {
            Serial.println("✗ LittleFS not mounted");
            return false;
        }
        
        // Формирование пути: /programs/program_{programId}.json
        String path = "/programs/program_" + String(programId) + ".json";
        
        // Проверка свободного места (минимум 10KB = 10240 байт)
        const size_t MIN_FREE_SPACE = 10240;
        size_t freeBytes = getFreeBytes();
        
        if (freeBytes < MIN_FREE_SPACE) {
            Serial.printf("✗ Insufficient storage space (need %u bytes, have %u bytes)\n", 
                         MIN_FREE_SPACE, freeBytes);
            
            // Попытка дефрагментации для освобождения места
            Serial.println("  Attempting storage optimization...");
            if (defragmentIfNeeded()) {
                freeBytes = getFreeBytes();
                if (freeBytes < MIN_FREE_SPACE) {
                    Serial.printf("✗ Still insufficient space after optimization: %u bytes\n", freeBytes);
                    return false;
                }
                Serial.println("✓ Storage optimization successful, proceeding with save");
            } else {
                return false;
            }
        }
        
        // Создание директории /programs если не существует
        if (!LittleFS.exists("/programs")) {
            LittleFS.mkdir("/programs");
        }
        
        // Запись файла с буферизацией для больших файлов
        bool success = writeFile(path, json);
        
        if (!success) {
            Serial.printf("✗ Failed to write program file: %s\n", path.c_str());
            return false;
        }
        
        // Верификация записи: чтение файла обратно и сравнение
        File verifyFile = LittleFS.open(path, "r");
        if (!verifyFile) {
            Serial.printf("✗ Failed to open file for verification: %s\n", path.c_str());
            // Удаление поврежденного файла
            LittleFS.remove(path);
            return false;
        }
        
        String readBack = verifyFile.readString();
        verifyFile.close();
        
        if (readBack != json) {
            Serial.printf("✗ Verification failed for file: %s\n", path.c_str());
            // Удаление поврежденного файла
            LittleFS.remove(path);
            return false;
        }
        
        Serial.printf("✓ Program %d saved successfully to %s (%u bytes)\n", 
                     programId, path.c_str(), json.length());
        
        // Проверка свободного места после сохранения
        checkFreeSpace();
        
        return true;
    }
    
    /**
     * Загрузка программы из файла
     * 
     * Требования: 10.1, 10.3
     * 
     * @param programId ID программы
     * @param json Выходной параметр с JSON программы
     * @return true если загрузка успешна, false в противном случае
     */
    bool loadProgram(int programId, String& json) {
        if (!filesystemMounted) {
            Serial.println("✗ LittleFS not mounted");
            return false;
        }
        
        // Формирование пути: /programs/program_{programId}.json
        String path = "/programs/program_" + String(programId) + ".json";
        
        // Проверка существования файла
        if (!LittleFS.exists(path)) {
            Serial.printf("✗ Program file not found: %s\n", path.c_str());
            return false;
        }
        
        // Чтение файла
        File file = LittleFS.open(path, "r");
        if (!file) {
            Serial.printf("✗ Failed to open file for reading: %s\n", path.c_str());
            return false;
        }
        
        json = file.readString();
        file.close();
        
        if (json.isEmpty()) {
            Serial.printf("✗ File is empty: %s\n", path.c_str());
            return false;
        }
        
        Serial.printf("✓ Program %d loaded successfully from %s (%u bytes)\n", 
                     programId, path.c_str(), json.length());
        
        return true;
    }
    
    /**
     * Удаление программы из файловой системы
     * 
     * Требования: 12.3
     * 
     * @param programId ID программы
     * @return true если удаление успешно, false в противном случае
     */
    bool deleteProgram(int programId) {
        if (!filesystemMounted) {
            Serial.println("✗ LittleFS not mounted");
            return false;
        }
        
        // Формирование пути: /programs/program_{programId}.json
        String path = "/programs/program_" + String(programId) + ".json";
        
        // Проверка существования файла
        if (!LittleFS.exists(path)) {
            Serial.printf("⚠️  Program file not found: %s\n", path.c_str());
            // Возвращаем true, так как цель достигнута (файла нет)
            return true;
        }
        
        // Удаление файла
        bool success = LittleFS.remove(path);
        
        if (success) {
            Serial.printf("✓ Program %d deleted successfully: %s\n", programId, path.c_str());
        } else {
            Serial.printf("✗ Failed to delete program file: %s\n", path.c_str());
        }
        
        return success;
    }
    
    /**
     * Форматирование файловой системы (опасно!)
     */
    bool formatStorage() {
        Serial.println("⚠️  WARNING: Formatting LittleFS will erase ALL data!");
        Serial.println("Type 'FORMAT' to confirm:");
        
        // В реальном коде здесь была бы проверка подтверждения
        // Для простоты всегда возвращаем false
        Serial.println("❌ Format cancelled (safety)");
        return false;
    }
};

#endif // STORAGE_MANAGER_H