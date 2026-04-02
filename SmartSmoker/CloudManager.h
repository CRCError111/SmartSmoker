/**
 * Менеджер облачных сервисов согласно ТЗ
 * 
 * @file CloudManager.h
 * @version 2.1 - File Delivery Tracking
 */

#ifndef CLOUD_MANAGER_H
#define CLOUD_MANAGER_H

#include <Arduino.h>
#include <ArduinoJson.h>
#include <esp_task_wdt.h>
#include "constants.h"
#include "SystemState.h"
#include "CloudAPI.h"
#include "StorageManager.h"
#include "ProgramManager.h"
#include "BindingManager.h"
#include "TelemetryBuffer.h"

/**
 * Структура для хранения информации о файле
 */
struct FileInfo {
    String name;
    String type;
    size_t size;
};

/**
 * Структура для хранения данных запроса к облаку
 */
struct CloudRequest {
    String url;
    String method;
    String payload;
    unsigned long timestamp;
    uint8_t retryCount;
};

/**
 * Класс для взаимодействия с облачными сервисами
 */
class CloudManager {
private:
    CloudAPI* cloudAPI;
    StorageManager* storageManager = nullptr;
    
    std::vector<CloudRequest> requestQueue;
    
    bool cloudConnected = false;
    unsigned long lastSuccessfulRequest = 0;
    unsigned long lastConnectionAttempt = 0;
    uint8_t consecutiveFailures = 0;
    
    uint32_t totalRequests = 0;
    uint32_t successfulRequests = 0;
    uint32_t failedRequests = 0;
    
    TelemetryBuffer _telemetryBuffer;

    // Уровни логирования
    enum class LogLevel {
        ERROR,
        WARNING,
        INFO,
        DEBUG
    };

    // Функции логирования
    void log(LogLevel level, const String& message) {
        // Выводим только ERROR и WARNING — INFO/DEBUG слишком шумные
        if (level == LogLevel::ERROR || level == LogLevel::WARNING) {
            String prefix = (level == LogLevel::ERROR) ? "[ERROR] " : "[WARN] ";
            Serial.println(prefix + message);
        }
    }

    void logError(const String& message) {
        log(LogLevel::ERROR, message);
    }

    void logWarning(const String& message) {
        log(LogLevel::WARNING, message);
    }

    void logInfo(const String& message) {
        log(LogLevel::INFO, message);
    }

    void logDebug(const String& message) {
        log(LogLevel::DEBUG, message);
    }

public:
    bool init(SystemState& state, StorageManager* storage = nullptr) {
        logInfo("Initializing cloud manager...");
        
        storageManager = storage;
        // C-05: CloudAPI сам управляет WiFiClientSecure и CA-сертификатом
        cloudAPI = new CloudAPI(state.cloudUrl);
        
        logInfo("Cloud manager initialized");
        return true;
    }
    
    bool sendSensorData(SystemState& state) {
        // Проверяем, не пришло ли время для следующей попытки отправки
        unsigned long currentTime = millis();
        if (currentTime < state.nextTelemetryRetry) {
            // Время для отправки еще не пришло, пропускаем
            uint32_t remainingTime = state.nextTelemetryRetry - currentTime;
            logDebug("Telemetry delayed: waiting " + String(remainingTime) + " ms (retry count: " + String(state.telemetryRetryCount) + ")");
            return false;
        }
        
        if (!isCloudAvailable(state)) {
            if (!state.deviceBound) {
                logWarning("Telemetry blocked: device not bound");
            } else if (state.deviceId.isEmpty()) {
                logWarning("Telemetry blocked: device ID not set");
            } else if (!state.wifiConnected) {
                logWarning("Telemetry blocked: WiFi not connected");
            } else if (state.networkMode != SystemState::NetworkMode::STA) {
                logWarning("Telemetry blocked: not in STA mode");
            }
            bufferSensorData(state);
            return false;
        }
        
        totalRequests++;
        bool success = cloudAPI->sendSensorData(state);
        
        if (success) {
            successfulRequests++;
            consecutiveFailures = 0;
            lastSuccessfulRequest = millis();
            cloudConnected = true;
            state.authErrorCount = 0;
            
            // Сбрасываем счетчик retry при успешной отправке
            state.telemetryRetryCount = 0;
            state.nextTelemetryRetry = 0;
            
            logInfo("Sensor data sent to cloud successfully");
            flushTelemetryBuffer(state);
        } else {
            failedRequests++;
            consecutiveFailures++;
            bufferSensorData(state);
            
            // Применяем экспоненциальную задержку для следующей попытки
            uint32_t delay = calculateExponentialDelay(state.telemetryRetryCount);
            state.nextTelemetryRetry = currentTime + delay;
            state.telemetryRetryCount++;
            
            logError("Telemetry failed, next retry in " + String(delay) + " ms (retry count: " + String(state.telemetryRetryCount) + ")");
            
            int lastCode = cloudAPI->getLastHttpCode();
            if (lastCode == 401) {
                logError("HTTP 401 — Authentication failed. Please re-bind device via web interface.");
                // Clear binding state to force re-binding
                state.deviceBound = false;
                state.authErrorCount = 0;
                state.telemetryRetryCount = 0;
                state.nextTelemetryRetry = 0;
                cloudConnected = false;
                
                // Save cleared binding state to storage
                if (storageManager) {
                    storageManager->saveCloudSettings(state);
                    logInfo("Cleared binding state saved to LittleFS after HTTP 401");
                }
            } else if (lastCode == 404) {
                state.authErrorCount++;
                logError("AUTH ERROR (404): Device ID not recognized. Attempt " + String(state.authErrorCount) + "/5");
                if (state.authErrorCount >= 5) {
                    logError("AUTH ERROR limit reached — clearing device binding!");
                    state.deviceBound = false;
                    state.deviceId = "";
                    state.apiToken = "";
                    state.authErrorCount = 0;
                    state.telemetryRetryCount = 0; // Сбрасываем retry счетчик
                    state.nextTelemetryRetry = 0;
                    cloudConnected = false;
                    
                    // Save cleared binding state to storage
                    if (storageManager) {
                        storageManager->saveCloudSettings(state);
                        logInfo("Cleared binding state saved to LittleFS");
                    }
                }
            }
        }
        
        if (consecutiveFailures >= 3) {
            cloudConnected = false;
        }
        
        return success;
    }
    
    
    bool confirmProgramReceived(const SystemState& state, const String& programName) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        return cloudAPI->confirmProgramReceived(state.deviceId, state.apiToken, programName, state.currentRunId);
    }
    
    bool getPrograms(const SystemState& state, std::vector<String>& programs) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        bool success = cloudAPI->getPrograms(state.deviceId, state.apiToken, programs, state.serverTime);
        
        if (success) {
            successfulRequests++;
        } else {
            failedRequests++;
        }
        
        return success;
    }
    
    bool sendEmergencyStop(const SystemState& state, const String& reason) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        bool success = cloudAPI->sendEmergencyStop(state.deviceId, state.apiToken, reason);
        
        if (success) {
            Serial.printf("[ERROR] Emergency stop sent: %s\n", reason.c_str());
        }
        
        return success;
    }
    
    void checkCloudConnection(SystemState& state) {
        unsigned long currentTime = millis();
        
        if (currentTime - lastConnectionAttempt >= 300000) {
            if (isCloudAvailable(state)) {
                pingCloudService(state);
            }
            lastConnectionAttempt = currentTime;
        }
        
        state.cloudConnected = cloudConnected;
        state.lastCloudSync = lastSuccessfulRequest;
    }
    
    void getCloudStats(uint32_t& total, uint32_t& successful, uint32_t& failed, 
                      uint8_t& queueSize, uint8_t& bufferSize) const {
        total = totalRequests;
        successful = successfulRequests;
        failed = failedRequests;
        queueSize = requestQueue.size();
        bufferSize = _telemetryBuffer.size();
    }

private:
    bool isCloudAvailable(const SystemState& state) const {
        return state.networkMode == SystemState::NetworkMode::STA &&
               state.wifiConnected &&
               !state.deviceId.isEmpty() &&
               state.deviceBound;
    }
    
    /**
     * Вычисляет экспоненциальную задержку для retry
     * @param retryCount Текущее количество попыток
     * @return Задержка в миллисекундах (ограничена 60 секундами)
     */
    uint32_t calculateExponentialDelay(uint8_t retryCount) const {
        // Базовый расчет: 1000 * (2 ^ retryCount) миллисекунд
        // Пример: 0 -> 1000ms, 1 -> 2000ms, 2 -> 4000ms, 3 -> 8000ms и т.д.
        
        // Ограничиваем retryCount для предотвращения переполнения
        if (retryCount > 6) { // 2^6 = 64 секунд, что близко к максимальному лимиту
            retryCount = 6;
        }
        
        uint32_t delay = 1000 * (1 << retryCount); // 2^retryCount
        
        // Максимальная задержка: 60 секунд (60000 мс)
        const uint32_t MAX_DELAY = 60000;
        if (delay > MAX_DELAY) {
            delay = MAX_DELAY;
        }
        
        return delay;
    }
    
    bool sendHTTPRequest(const String& method, const String& url, 
                        const String& payload, String* response = nullptr) {
        totalRequests++;
        
        bool success = cloudAPI->sendRequest(method, url, payload, response);
        
        if (success) {
            successfulRequests++;
            consecutiveFailures = 0;
            lastSuccessfulRequest = millis();
            cloudConnected = true;
        } else {
            int httpCode = cloudAPI->getLastHttpCode();
            if (httpCode <= 0) {
                logError("HTTP Error: code " + String(httpCode));
            }
            failedRequests++;
            consecutiveFailures++;
            if (httpCode >= 500 || httpCode <= 0) {
                addToRetryQueue(method, url, payload);
            }
        }
        
        if (consecutiveFailures >= 3) {
            cloudConnected = false;
        }
        
        return success;
    }
    
    void addToRetryQueue(const String& method, const String& url, const String& payload) {
        if (requestQueue.size() >= 10) {
            requestQueue.erase(requestQueue.begin());
        }
        
        CloudRequest request;
        request.url = url;
        request.method = method;
        request.payload = payload;
        request.timestamp = millis();
        request.retryCount = 0;
        
        requestQueue.push_back(request);
    }
    
    void processRetryQueue() {
        if (requestQueue.empty() || !cloudConnected) {
            return;
        }
        
        auto it = requestQueue.begin();
        while (it != requestQueue.end()) {
            unsigned long currentTime = millis();
            
            if (currentTime - it->timestamp >= 30000) {
                if (it->retryCount < 3) {
                    if (sendHTTPRequest(it->method, it->url, it->payload)) {
                        it = requestQueue.erase(it);
                        continue;
                    } else {
                        it->retryCount++;
                        it->timestamp = currentTime;
                    }
                } else {
                    it = requestQueue.erase(it);
                    continue;
                }
            }
            ++it;
        }
    }
    
    void bufferSensorData(const SystemState& state) {
        if (_telemetryBuffer.size() >= TELEMETRY_BUFFER_MAX_SIZE) {
            _telemetryBuffer.pop(); // FIFO-вытеснение: удаляем самую старую запись
            logWarning("Telemetry buffer full, dropping oldest record");
        }
        _telemetryBuffer.push(makeRecord(state));
    }

    TelemetryRecord makeRecord(const SystemState& state) {
        TelemetryRecord rec;
        rec.tempChamber  = state.tempChamber;
        rec.tempSmoke    = state.tempSmoke;
        rec.tempProduct  = state.tempProduct;
        rec.humidity     = state.humidity;
        rec.timestamp    = millis();
        return rec;
    }
    
    void flushTelemetryBuffer(SystemState& state) {
        if (_telemetryBuffer.isEmpty()) {
            return;
        }

        logInfo("Flushing " + String(_telemetryBuffer.size()) + " buffered telemetry records");

        uint8_t sent = 0;
        while (!_telemetryBuffer.isEmpty() && sent < TELEMETRY_FLUSH_BATCH_SIZE) {
            esp_task_wdt_reset(); // сброс watchdog в длительном цикле сброса буфера

            TelemetryRecord rec = _telemetryBuffer.peek();

            JsonDocument doc;
            doc["device_id"]    = state.deviceId;
            doc["api_token"]    = state.apiToken;
            doc["temp_chamber"] = rec.tempChamber;
            doc["temp_smoke"]   = rec.tempSmoke;
            doc["temp_product"] = rec.tempProduct;
            doc["humidity"]     = rec.humidity;
            doc["timestamp"]    = rec.timestamp;

            String payload;
            serializeJson(doc, payload);

            String url = state.cloudUrl + "/api/send-data.php";
            bool ok = cloudAPI->sendRequest("POST", url, payload);

            if (ok) {
                _telemetryBuffer.pop();
                sent++;
            } else {
                break;  // Stop on first failure; remaining records stay in buffer
            }
        }
    }
    
    bool pingCloudService(const SystemState& state) {
        String url = state.cloudUrl + "/api/get-state.php?device_id=" + state.deviceId;
        
        String response;
        bool success = sendHTTPRequest("GET", url, "", &response);
        
        if (success) {
            cloudConnected = true;
        } else {
            cloudConnected = false;
        }
        
        return success;
    }

public:
    void update(SystemState& state, ProgramManager* programManager = nullptr) {
        processRetryQueue();
        checkCloudConnection(state);
        
        unsigned long currentTime = millis();
        static unsigned long lastCloudRequest = 0;
        
        if (currentTime - lastCloudRequest >= (state.syncInterval * 1000)) {
            if (state.isCloudReady()) {
                sendSensorData(state);
            }
            lastCloudRequest = currentTime;
        }
        
        static unsigned long lastProgramSync = 0;
        
        if (currentTime - lastProgramSync >= (state.programSyncInterval * 1000)) {
            if (state.isCloudReady() && programManager) {
                std::vector<String> programs;
                if (getPrograms(state, programs)) {
                    programManager->syncProgramsFromCloud(programs);
                    state.lastProgramSync = currentTime;
                }
            }
            lastProgramSync = currentTime;
        }
        
        // Program polling every 5 minutes (300000 ms)
        static unsigned long lastProgramPollTime = 0;
        
        if (currentTime - lastProgramPollTime >= 300000) {
            if (state.deviceBound && state.wifiConnected) {
                logInfo("Polling for pending programs...");
                checkPendingPrograms(state);
            }
            lastProgramPollTime = currentTime;
        }
    }
    
    bool forceSyncWithCloud(SystemState& state) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        // Принудительная синхронизация игнорирует retry задержку
        // Сбрасываем retry счетчик для немедленной отправки
        state.telemetryRetryCount = 0;
        state.nextTelemetryRetry = 0;
        
        bool success = sendSensorData(state);
        std::vector<String> programs;
        if (getPrograms(state, programs)) {
            Serial.println("Programs synchronized from cloud");
            state.lastProgramSync = millis();
        }
        
        return success;
    }
    
    bool uploadProgramToCloud(const SystemState& state, const SmokingProgram& program) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        JsonDocument doc;
        doc["device_id"] = state.deviceId;
        doc["program_name"] = program.name;
        doc["description"] = program.description;
        doc["category"] = program.category;
        doc["is_built_in"] = program.isBuiltIn;
        
        JsonArray stagesArray = doc["stages"].to<JsonArray>();
        for (const auto& step : program.steps) {
            JsonObject stepObj = stagesArray.add<JsonObject>();
            stepObj["stage_name"] = step.stepName;
            stepObj["target_temp"] = step.targetTemp;
            stepObj["target_temp_device"] = step.targetTempDevice;
            stepObj["target_humidity"] = step.targetHumidity;
            stepObj["duration_minutes"] = step.durationMinutes;
            stepObj["hysteresis"] = step.hysteresis;
            stepObj["wait_for_temp"] = step.waitForTemp;
            stepObj["use_smoke_generator"] = step.useSmokeGenerator;
            stepObj["ventilation_percent"] = step.ventilationPercent;
            stepObj["internal_fan_on"] = step.internalFanOn;
            stepObj["injection_fan_on"] = step.injectionFanOn;
            stepObj["compressor_pwm"] = step.compressorPWM;
        }
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/upload-program.php";
        bool success = sendHTTPRequest("POST", url, payload);
        
        if (success) {
            Serial.printf("Program uploaded to cloud: %s\n", program.name.c_str());
        }
        
        return success;
    }
    
    bool deleteProgramFromCloud(const SystemState& state, const String& programName) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        JsonDocument doc;
        doc["device_id"] = state.deviceId;
        doc["program_name"] = programName;
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/delete-program.php";
        bool success = sendHTTPRequest("POST", url, payload);
        
        if (success) {
            Serial.printf("Program deleted from cloud: %s\n", programName.c_str());
        }
        
        return success;
    }
    
    bool getProgramList(const SystemState& state, std::vector<String>& programNames) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        String url = state.cloudUrl + "/api/list-programs.php?device_id=" + state.deviceId;
        
        String response;
        bool success = sendHTTPRequest("GET", url, "", &response);
        
        if (success) {
            successfulRequests++;
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (!error) {
                JsonArray programs = doc["programs"];
                for (JsonObject prog : programs) {
                    programNames.push_back(prog["name"] | "");
                }
                Serial.printf("Program list received from cloud: %d programs\n", programNames.size());
            }
        } else {
            failedRequests++;
        }
        
        return success;
    }
    
    bool sendProgramCompletion(const SystemState& state, const String& programName, 
                               unsigned long durationSeconds) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        JsonDocument doc;
        doc["device_id"] = state.deviceId;
        doc["api_token"] = state.apiToken;
        doc["program_name"] = programName;
        doc["run_id"] = state.currentRunId;
        doc["duration_seconds"] = durationSeconds;
        doc["timestamp"] = millis();
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/program-completed.php";
        bool success = sendHTTPRequest("POST", url, payload);
        
        if (success) {
            Serial.printf("Program completion notification sent: %s\n", programName.c_str());
        }
        
        return success;
    }
    
    bool sendDeviceAddedNotification(const SystemState& state) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        JsonDocument doc;
        doc["device_id"] = state.deviceId;
        doc["api_token"] = state.apiToken;
        doc["device_name"] = state.deviceName;
        doc["timestamp"] = millis();
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/device-added.php";
        bool success = sendHTTPRequest("POST", url, payload);
        
        if (success) {
            Serial.printf("Device added notification sent: %s\n", state.deviceId.c_str());
        }
        
        return success;
    }
    
    bool listFiles(const SystemState& state, std::vector<JsonObject>& files) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        String url = state.cloudUrl + "/api/list-files.php?device_id=" + state.deviceId;
        
        String response;
        bool success = sendHTTPRequest("GET", url, "", &response);
        
        if (success) {
            successfulRequests++;
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (!error) {
                JsonArray filesArray = doc["files"];
                for (JsonObject file : filesArray) {
                    files.push_back(file);
                }
                Serial.printf("File list received from cloud: %d files\n", files.size());
            }
        } else {
            failedRequests++;
        }
        
        return success;
    }
    
    bool listLocalFiles(const SystemState& state, StorageManager& storageManager, std::vector<FileInfo>& files) {
        if (!storageManager.isReady()) {
            return false;
        }
        
        std::vector<String> fileNames = storageManager.listFiles("/");
        
        for (const String& fileName : fileNames) {
            if (fileName == "config.json" || fileName == "cloud.json" || 
                fileName == "wifi.json" || fileName == "programs.json") {
                File file = LittleFS.open("/" + fileName, "r");
                if (file) {
                    FileInfo info;
                    info.name = fileName;
                    info.size = file.size();
                    info.type = getFileType(fileName);
                    files.push_back(info);
                    file.close();
                }
            }
        }
        
        std::vector<String> programFiles = storageManager.listProgramFiles();
        for (const String& programFile : programFiles) {
            File file = LittleFS.open(programFile, "r");
            if (file) {
                FileInfo info;
                info.name = programFile.substring(10);
                info.size = file.size();
                info.type = "json";
                files.push_back(info);
                file.close();
            }
        }
        
        Serial.printf("Local file list: %d files\n", files.size());
        return true;
    }
    
    String getFileType(const String& filename) {
        if (filename.endsWith(".json")) return "json";
        if (filename.endsWith(".bin")) return "bin";
        if (filename.endsWith(".txt")) return "txt";
        if (filename.endsWith(".md")) return "md";
        return "other";
    }
    
    bool confirmFileReceived(const SystemState& state, const String& fileName, 
                            const String& fileType, const String& errorMessage = "") {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        JsonDocument doc;
        doc["device_id"] = state.deviceId;
        doc["api_token"] = state.apiToken;
        doc["file_name"] = fileName;
        doc["file_type"] = fileType;
        doc["status"] = errorMessage.isEmpty() ? "ok" : "error";
        if (!errorMessage.isEmpty()) {
            doc["error"] = errorMessage;
        }
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/file-received.php";
        bool success = sendHTTPRequest("POST", url, payload);
        
        if (success) {
            Serial.printf("File confirmation sent: %s\n", fileName.c_str());
        }
        
        return success;
    }
    
    bool resetFileDelivery(const SystemState& state, const String& fileName = "") {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        JsonDocument doc;
        doc["device_id"] = state.deviceId;
        if (!fileName.isEmpty()) {
            doc["file_name"] = fileName;
        }
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/reset-file-delivery.php";
        bool success = sendHTTPRequest("POST", url, payload);
        
        if (success) {
            Serial.printf("File delivery reset: %s\n", fileName.isEmpty() ? "all files" : fileName.c_str());
        }
        
        return success;
    }
    
    bool checkPendingFiles(const SystemState& state, std::vector<JsonObject>& pendingFiles) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        String url = state.cloudUrl + "/api/check-pending-files.php?device_id=" + state.deviceId + "&api_token=" + state.apiToken;
        
        String response;
        bool success = sendHTTPRequest("GET", url, "", &response);
        
        if (success) {
            successfulRequests++;
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (!error) {
                JsonArray filesArray = doc["pending_files"];
                for (JsonObject file : filesArray) {
                    pendingFiles.push_back(file);
                }
                Serial.printf("Pending files check: %d files\n", pendingFiles.size());
            }
        } else {
            failedRequests++;
        }
        
        return success;
    }
    
    bool checkPendingFiles(const SystemState& state) {
        std::vector<JsonObject> pendingFiles;
        return checkPendingFiles(state, pendingFiles);
    }
    
    bool checkPendingPrograms(SystemState& state) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        String url = state.cloudUrl + "/api/file-list.php?uuid=" + state.deviceId + "&api_token=" + state.apiToken;
        
        String response;
        bool success = sendHTTPRequest("GET", url, "", &response);
        
        if (success) {
            successfulRequests++;
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (!error && doc["files"].is<JsonArray>()) {
                JsonArray filesArray = doc["files"];
                
                if (filesArray.size() > 0) {
                    logInfo("Found " + String(filesArray.size()) + " pending program(s)");
                    
                    for (JsonObject file : filesArray) {
                        String transferId = file["transfer_id"] | "";
                        String filename = file["filename"] | "";
                        String downloadToken = file["token"] | "";
                        int programId = file["program_id"] | 0;
                        
                        if (!transferId.isEmpty() && !filename.isEmpty() && !downloadToken.isEmpty()) {
                            logInfo("Downloading program: " + filename + " (transfer_id=" + transferId + ")");
                            downloadProgram(state, transferId, filename, downloadToken, programId);
                        }
                    }
                    return true;
                }
            }
        } else {
            failedRequests++;
        }
        
        return false;
    }
    
    bool downloadProgram(SystemState& state, const String& transferId, const String& filename, const String& downloadToken, int programId = 0) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        String url = state.cloudUrl + "/api/download-program.php?transfer_id=" + transferId + "&token=" + downloadToken;
        
        String response;
        bool success = sendHTTPRequest("GET", url, "", &response);
        
        if (success) {
            successfulRequests++;
            
            if (storageManager && storageManager->isReady()) {
                // Если program_id не передан — пробуем извлечь из JSON ответа
                if (programId == 0) {
                    JsonDocument doc;
                    if (deserializeJson(doc, response) == DeserializationError::Ok) {
                        programId = doc["program_id"] | 0;
                    }
                }
                
                bool saved = false;
                if (programId > 0) {
                    // Сохраняем как program_{id}.json — унифицированное именование
                    saved = storageManager->saveProgram(programId, response);
                } else {
                    // Fallback: сохраняем по имени файла
                    String filepath = "/programs/" + filename + ".json";
                    File file = LittleFS.open(filepath, "w");
                    if (file) {
                        file.print(response);
                        file.close();
                        saved = true;
                    }
                }
                
                if (saved) {
                    confirmProgramTransfer(state, transferId);
                    return true;
                } else {
                    logError("Failed to save program to LittleFS: " + filename);
                }
            } else {
                logError("StorageManager not available for saving program");
            }
        } else {
            failedRequests++;
            logError("Failed to download program: " + filename);
        }
        
        return false;
    }
    
    bool confirmProgramTransfer(SystemState& state, const String& transferId) {
        if (!isCloudAvailable(state)) {
            return false;
        }
        
        totalRequests++;
        
        JsonDocument doc;
        doc["transfer_id"] = transferId;
        doc["uuid"] = state.deviceId;
        doc["api_token"] = state.apiToken;
        doc["status"] = "confirmed";
        
        String payload;
        serializeJson(doc, payload);
        
        String url = state.cloudUrl + "/api/confirm-program-transfer.php";
        
        bool success = cloudAPI->sendRequest("POST", url, payload, nullptr, state.apiToken);
        
        if (success) {
            successfulRequests++;
            logInfo("Program transfer confirmed: " + transferId);
        } else {
            failedRequests++;
            logError("Program transfer confirmation failed with HTTP " + String(cloudAPI->getLastHttpCode()));
        }
        
        return success;
    }
};

#endif // CLOUD_MANAGER_H