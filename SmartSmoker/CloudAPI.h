/**
 * API эндпоинты для взаимодействия с облачным сервисом
 * 
 * @file CloudAPI.h
 * @version 2.1 - File Delivery Tracking
 */

#ifndef CLOUD_API_H
#define CLOUD_API_H

#include <Arduino.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <WiFi.h>
#include <esp_task_wdt.h>
#include "SystemState.h"
#include "IgniterManager.h"
#include "certs.h"  // ROOT_CA_CERT для TLS

class CloudAPI {
private:
    String baseUrl;
    WiFiClientSecure* secureClient = nullptr;  // TLS-клиент, аллоцируется лениво
    HTTPClient httpClient;
    int lastHttpCode = 0;
    
    // Ленивая аллокация WiFiClientSecure — вызывается только перед первым HTTP-запросом
    void ensureTLS() {
        if (!secureClient) {
            secureClient = new WiFiClientSecure();
            secureClient->setCACert(ROOT_CA_CERT);
            secureClient->setHandshakeTimeout(5);  // 5 секунд макс на TLS handshake
            Serial.println("[INFO] CloudAPI TLS initialized (lazy)");
        }
        esp_task_wdt_reset();  // TLS handshake может занять время
    }
    
    // Освобождаем TLS после использования — не держим mbedTLS контекст в памяти
    void releaseTLS() {
        if (secureClient) {
            delete secureClient;
            secureClient = nullptr;
        }
    }
    
    // Обёртка: инициализирует TLS при необходимости и вызывает httpClient.begin
    bool beginSecure(const String& url) {
        ensureTLS();
        bool ok = httpClient.begin(*secureClient, url);
        // Короткий timeout чтобы не зависать при connection refused
        httpClient.setTimeout(5000);
        return ok;
    }
    
    // Диагностика TLS ошибки после неудачного запроса
    void diagnoseTLSError() {
        if (secureClient) {
            int err = secureClient->lastError(nullptr, 0);
            if (err != 0) {
                char buf[128];
                secureClient->lastError(buf, sizeof(buf));
                Serial.printf("[DIAG] TLS error %d: %s\n", err, buf);
            }
        }
    }

public:
    CloudAPI(const String& url) : baseUrl(url) {
        // WiFiClientSecure создаётся лениво при первом запросе
    }
    
    ~CloudAPI() {
        if (secureClient) {
            delete secureClient;
            secureClient = nullptr;
        }
    }
    
    int getLastHttpCode() const { return lastHttpCode; }
    
    bool getDeviceState(const String& deviceId, SystemState& state) {
        String url = baseUrl + "/api/get-state.php?device_id=" + deviceId;
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        
        int httpCode = httpClient.GET();
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (error) {
                Serial.println("[ERROR] Failed to parse get-state response");
                httpClient.end();
                releaseTLS();
                return false;
            }
            
            if (doc["success"].is<bool>() && doc["success"].as<bool>()) {
                if (doc["mode"].is<String>()) {
                    String mode = doc["mode"].as<String>();
                    state.mode = (mode == "RUNNING") ? SystemState::Mode::RUNNING : SystemState::Mode::IDLE;
                }
                
                if (doc["emergencyStop"].is<bool>()) {
                    state.emergencyStop = doc["emergencyStop"].as<bool>();
                }
                
                httpClient.end();
                releaseTLS();
                return true;
            }
        }
        
        httpClient.end();
        releaseTLS();
        return false;
    }
    
    bool sendSensorData(SystemState& state) {
        String url = baseUrl + "/api/send-data.php";
        
        JsonDocument doc;
        // C-06: api_token передаётся в заголовке Authorization, не в теле
        doc["device_id"] = state.deviceId;
        
        JsonObject sensors = doc["sensors"].to<JsonObject>();
        sensors["temp_chamber"] = state.tempChamber;
        sensors["temp_smoke"] = state.tempSmoke;
        sensors["temp_product"] = state.tempProduct;
        sensors["humidity"] = state.humidity;
        
        JsonObject actuators = doc["actuators"].to<JsonObject>();
        actuators["heater_on"] = state.heaterOn;
        actuators["smoke_pwm"] = state.smokePWM;
        actuators["damper_position"] = state.damperPosition;
        actuators["fan_internal_on"] = state.fanInternalOn;
        actuators["fan_injection_on"] = state.fanInjectionOn;
        
        JsonObject system = doc["system"].to<JsonObject>();
        system["mode"] = state.getSystemModeString();
        system["current_program"] = state.currentProgram ? state.currentProgram->name : "";
        system["current_stage"] = state.currentStepIndex;
        system["stage_progress"] = calculateStageProgress(state);
        system["emergency_stop"] = state.emergencyStop;
        system["run_id"] = state.currentRunId;
        system["uptime"] = millis() / 1000;

        // Телеметрия розжига
        JsonObject igniter = system["igniter"].to<JsonObject>();
        if (state.igniterDone) {
            igniter["result"]       = igniterResultToString(state.igniterLastResult);
            igniter["attempt"]      = state.igniterAttempt;
            igniter["timestamp_ms"] = state.igniterDoneMs - state.igniterStepStartMs;
        } else {
            igniter["result"]       = "PENDING";
            igniter["attempt"]      = state.igniterAttempt;
            igniter["timestamp_ms"] = 0;
        }
        
        if (!state.executedCommandIds.empty()) {
            JsonArray executed = doc["executed_commands"].to<JsonArray>();
            for (int cmdId : state.executedCommandIds) {
                executed.add(cmdId);
            }
            state.executedCommandIds.clear();
        }
        
        String payload;
        serializeJson(doc, payload);
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + state.apiToken);
        
        int httpCode = httpClient.POST(payload);
        lastHttpCode = httpCode;
        bool success = (httpCode == HTTP_CODE_OK);
        
        if (success) {
            String response = httpClient.getString();
            JsonDocument responseDoc;
            
            if (deserializeJson(responseDoc, response) == DeserializationError::Ok) {
                success = responseDoc["success"].is<bool>() && responseDoc["success"].as<bool>();
                
                if (success) {
                    if (responseDoc["server_time"].is<String>()) {
                        state.serverTime = responseDoc["server_time"].as<String>();
                    }
                    
                    if (responseDoc["commands"].is<JsonArray>()) {
                        JsonArray commands = responseDoc["commands"].as<JsonArray>();
                        processCommands(commands, state);
                    }

                    // Сбрасываем флаг после успешной отправки телеметрии розжига
                    state.igniterDone = false;
                } else {
                    Serial.printf("[ERROR] send-data API: %s\n", responseDoc["error"].is<String>() ? responseDoc["error"].as<String>().c_str() : "Unknown error");
                }
            }
        } else {
            Serial.printf("[ERROR] send-data HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
private:
    static const char* igniterResultToString(uint8_t result) {
        switch ((IgniterResult)result) {
            case IgniterResult::SUCCESS:    return "SUCCESS";
            case IgniterResult::NO_GAS:     return "NO_GAS";
            case IgniterResult::GAS_FROZEN: return "GAS_FROZEN";
            case IgniterResult::TIMEOUT:    return "TIMEOUT";
            case IgniterResult::ERROR:      return "ERROR";
            default:                        return "PENDING";
        }
    }

    int calculateStageProgress(const SystemState& state) {
        if (!state.currentProgram || state.currentStepIndex >= state.currentProgram->steps.size()) {
            return 0;
        }
        
        if (state.stepStartTime == 0) {
            return 0;
        }
        
        const auto& step = state.currentProgram->steps[state.currentStepIndex];
        unsigned long elapsed = (millis() - state.stepStartTime) / 1000;
        unsigned long total = step.durationMinutes * 60;
        
        if (total == 0) return 100;
        
        int progress = (elapsed * 100) / total;
        return min(progress, 100);
    }
    
    void processCommands(JsonArray commands, SystemState& state) {
        for (JsonObject cmd : commands) {
            int cmdId = cmd["id"].is<int>() ? cmd["id"].as<int>() : 0;
            String type = cmd["type"].is<String>() ? cmd["type"].as<String>() : "";
            
            bool executed = false;
            
            if (type == "emergency_stop") {
                String reason = "Remote stop";
                if (cmd["params"].is<JsonObject>() && cmd["params"]["reason"].is<String>()) {
                    reason = cmd["params"]["reason"].as<String>();
                }
                
                Serial.printf("⚠️ Emergency stop command: %s\n", reason.c_str());
                state.emergencyStop = true;
                state.emergencyReason = reason;
                state.mode = SystemState::Mode::EMERGENCY_STOP;
                executed = true;
            }
            else if (type == "sync_programs") {
                Serial.println("📥 Sync programs command received");
                state.lastProgramSync = 0;
                executed = true;
            }
            else if (type == "start_program") {
                if (cmd["params"].is<JsonObject>() && cmd["params"]["program_name"].is<String>()) {
                    String programName = cmd["params"]["program_name"].as<String>();
                    Serial.printf("▶️ Start program command: %s\n", programName.c_str());
                    state.pendingProgramStart = programName;
                    executed = true;
                }
            }
            else if (type == "smoke_confirmed") {
                if (state.mode == SystemState::Mode::WAITING_SMOKE_IGNITION ||
                    state.smokePauseActive) {
                    Serial.println("🔥 Smoke ignition confirmed via cloud command");
                    state.smokeIgnitionConfirmed = true;
                    executed = true;
                }
            }
            
            if (executed && cmdId > 0) {
                state.executedCommandIds.push_back(cmdId);
            }
        }
    }
    
public:
    bool getPrograms(const String& deviceId, const String& apiToken, std::vector<String>& programs, const String& lastSync = "") {
        // C-06: токен передаётся в заголовке Authorization, не в URL
        String url = baseUrl + "/api/get-programs.php?device_id=" + deviceId;
        if (!lastSync.isEmpty()) {
            url += "&last_sync=" + lastSync;
        }
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + apiToken);
        
        int httpCode = httpClient.GET();
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (error) {
                Serial.println("[ERROR] Failed to parse programs response");
                httpClient.end();
                releaseTLS();
                return false;
            }
            
            if (doc["success"].is<bool>() && doc["success"].as<bool>()) {
                programs.clear();
                
                JsonArray programsArray = doc["programs"].as<JsonArray>();
                for (JsonObject program : programsArray) {
                    String programJson;
                    serializeJson(program, programJson);
                    programs.push_back(programJson);
                }
                
                httpClient.end();
                releaseTLS();
                return true;
            } else {
                Serial.printf("[ERROR] get-programs API: %s\n", doc["error"].is<String>() ? doc["error"].as<String>().c_str() : "Unknown error");
            }
        } else {
            Serial.printf("[ERROR] get-programs HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return false;
    }
    
    bool confirmProgramReceived(const String& deviceId, const String& apiToken, const String& programName, const String& runId = "") {
        String url = baseUrl + "/api/program-received.php";
        
        JsonDocument doc;
        doc["device_id"] = deviceId;
        doc["program_name"] = programName;
        if (!runId.isEmpty()) {
            doc["run_id"] = runId;
        }
        
        String payload;
        serializeJson(doc, payload);
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + apiToken);
        
        int httpCode = httpClient.POST(payload);
        bool success = false;
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            JsonDocument responseDoc;
            
            if (deserializeJson(responseDoc, response) == DeserializationError::Ok) {
                success = responseDoc["success"].is<bool>() && responseDoc["success"].as<bool>();
                if (!success) {
                    Serial.printf("[ERROR] program-received API: %s\n", responseDoc["error"].is<String>() ? responseDoc["error"].as<String>().c_str() : "Unknown error");
                }
            }
        } else {
            Serial.printf("[ERROR] program-received HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
    bool sendEmergencyStop(const String& deviceId, const String& apiToken, const String& reason) {
        String url = baseUrl + "/api/emergency-stop.php";
        
        JsonDocument doc;
        doc["device_id"] = deviceId;
        doc["reason"] = reason;
        doc["timestamp"] = millis();
        
        String payload;
        serializeJson(doc, payload);
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + apiToken);
        
        int httpCode = httpClient.POST(payload);
        bool success = false;
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            JsonDocument responseDoc;
            
            if (deserializeJson(responseDoc, response) == DeserializationError::Ok) {
                success = responseDoc["success"].is<bool>() && responseDoc["success"].as<bool>();
                if (!success) {
                    Serial.printf("[ERROR] emergency-stop API: %s\n", responseDoc["error"].is<String>() ? responseDoc["error"].as<String>().c_str() : "Unknown error");
                }
            }
        } else {
            Serial.printf("[ERROR] emergency-stop HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
    bool testConnection() {
        String url = baseUrl + "/api/test-basic.php";
        
        beginSecure(url);
        
        int httpCode = httpClient.GET();
        bool success = (httpCode == HTTP_CODE_OK);
        
        if (!success) {
            Serial.printf("[ERROR] API connection test failed: %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
    bool listFiles(const String& deviceId, const String& apiToken, std::vector<JsonObject>& files) {
        // C-06: токен в заголовке Authorization
        String url = baseUrl + "/api/list-files.php?device_id=" + deviceId;
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + apiToken);
        
        int httpCode = httpClient.GET();
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (error) {
                Serial.println("[ERROR] Failed to parse list-files response");
                httpClient.end();
                releaseTLS();
                return false;
            }
            
            if (doc["success"].is<bool>() && doc["success"].as<bool>()) {
                files.clear();
                JsonArray filesArray = doc["files"].as<JsonArray>();
                for (JsonObject file : filesArray) {
                    files.push_back(file);
                }
                httpClient.end();
                releaseTLS();
                return true;
            } else {
                Serial.printf("[ERROR] list-files API: %s\n", doc["error"].is<String>() ? doc["error"].as<String>().c_str() : "Unknown error");
            }
        } else {
            Serial.printf("[ERROR] list-files HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return false;
    }
    
    bool confirmFileReceived(const String& deviceId, const String& apiToken, const String& fileName, 
                            const String& fileType, const String& errorMessage = "") {
        String url = baseUrl + "/api/file-received.php";
        
        JsonDocument doc;
        doc["device_id"] = deviceId;
        doc["file_name"] = fileName;
        doc["file_type"] = fileType;
        doc["status"] = errorMessage.isEmpty() ? "ok" : "error";
        if (!errorMessage.isEmpty()) {
            doc["error"] = errorMessage;
        }
        
        String payload;
        serializeJson(doc, payload);
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + apiToken);
        
        int httpCode = httpClient.POST(payload);
        bool success = false;
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            JsonDocument responseDoc;
            
            if (deserializeJson(responseDoc, response) == DeserializationError::Ok) {
                success = responseDoc["success"].is<bool>() && responseDoc["success"].as<bool>();
                if (!success) {
                    Serial.printf("[ERROR] file-received API: %s\n", responseDoc["error"].is<String>() ? responseDoc["error"].as<String>().c_str() : "Unknown error");
                }
            }
        } else {
            Serial.printf("[ERROR] file-received HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
    bool resetFileDelivery(const String& deviceId, const String& fileName = "") {
        String url = baseUrl + "/api/reset-file-delivery.php";
        
        JsonDocument doc;
        doc["device_id"] = deviceId;
        if (!fileName.isEmpty()) {
            doc["file_name"] = fileName;
        }
        
        String payload;
        serializeJson(doc, payload);
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        
        int httpCode = httpClient.POST(payload);
        bool success = false;
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            JsonDocument responseDoc;
            
            if (deserializeJson(responseDoc, response) == DeserializationError::Ok) {
                success = responseDoc["success"].is<bool>() && responseDoc["success"].as<bool>();
                if (!success) {
                    Serial.printf("[ERROR] reset-file-delivery API: %s\n", responseDoc["error"].is<String>() ? responseDoc["error"].as<String>().c_str() : "Unknown error");
                }
            }
        } else {
            Serial.printf("[ERROR] reset-file-delivery HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
    bool checkPendingFiles(const String& deviceId, const String& apiToken, std::vector<JsonObject>& pendingFiles) {
        // C-06: токен в заголовке Authorization
        String url = baseUrl + "/api/check-pending-files.php?device_id=" + deviceId;
        
        beginSecure(url);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("Authorization", "Bearer " + apiToken);
        
        int httpCode = httpClient.GET();
        
        if (httpCode == HTTP_CODE_OK) {
            String response = httpClient.getString();
            
            JsonDocument doc;
            DeserializationError error = deserializeJson(doc, response);
            
            if (error) {
                Serial.println("[ERROR] Failed to parse check-pending-files response");
                httpClient.end();
                releaseTLS();
                return false;
            }
            
            if (doc["success"].is<bool>() && doc["success"].as<bool>()) {
                pendingFiles.clear();
                JsonArray filesArray = doc["pending_files"].as<JsonArray>();
                for (JsonObject file : filesArray) {
                    pendingFiles.push_back(file);
                }
                httpClient.end();
                releaseTLS();
                return true;
            } else {
                Serial.printf("[ERROR] check-pending-files API: %s\n", doc["error"].is<String>() ? doc["error"].as<String>().c_str() : "Unknown error");
            }
        } else {
            Serial.printf("[ERROR] check-pending-files HTTP %d\n", httpCode);
        }
        
        httpClient.end();
        releaseTLS();
        return false;
    }
    
    /**
     * Универсальный HTTP-запрос через общий TLS-клиент.
     * Используется CloudManager для запросов, не обёрнутых в специализированные методы.
     */
    bool sendRequest(const String& method, const String& url, 
                     const String& payload = "", String* response = nullptr,
                     const String& authToken = "", int timeout = 5000) {
        beginSecure(url);
        httpClient.setTimeout(timeout);
        httpClient.addHeader("Content-Type", "application/json");
        httpClient.addHeader("User-Agent", "SmartSmoker-ESP32/2.0");
        if (!authToken.isEmpty()) {
            httpClient.addHeader("Authorization", "Bearer " + authToken);
        }
        
        int httpCode;
        if (method == "POST") {
            httpCode = httpClient.POST(payload);
        } else {
            httpCode = httpClient.GET();
        }
        
        lastHttpCode = httpCode;
        bool success = false;
        
        if (httpCode >= 200 && httpCode < 300) {
            success = true;
            if (response) {
                *response = httpClient.getString();
            }
        } else if (httpCode > 0 && response) {
            *response = httpClient.getString();
        } else if (httpCode < 0) {
            // HTTP -1 = connection failed / TLS handshake failed
            diagnoseTLSError();
        }
        
        httpClient.end();
        releaseTLS();
        return success;
    }
    
    String getBaseUrl() const { return baseUrl; }
};

#endif // CLOUD_API_H
