/**
 * Менеджер OTA обновлений
 * 
 * @file OTAManager.h
 * @version 1.0
 */

#ifndef OTA_MANAGER_H
#define OTA_MANAGER_H

#include <Arduino.h>
#include <ArduinoOTA.h>
#include <WiFi.h>
#include "SystemState.h"

/**
 * Класс для управления OTA обновлениями
 */
class OTAManager {
private:
    bool otaEnabled = false;
    bool otaInProgress = false;
    int otaProgress = 0;
    String otaError = "";

public:
    /**
     * Инициализация OTA
     */
    bool init(SystemState& state) {
        if (state.networkMode != SystemState::NetworkMode::STA || !state.wifiConnected) {
            Serial.println("⚠️  OTA requires WiFi STA mode");
            return false;
        }
        
        Serial.println("Initializing OTA...");
        
        // Настройка имени устройства для OTA
        String hostname = "SmartSmoker-" + state.deviceId.substring(0, 8);
        ArduinoOTA.setHostname(hostname.c_str());
        
        // Пароль для OTA — берём из apiToken (первые 16 символов) или дефолтный
        String otaPassword = state.apiToken.length() >= 16 
            ? state.apiToken.substring(0, 16) 
            : "SmartSmoker2024!";
        ArduinoOTA.setPassword(otaPassword.c_str());
        
        // Порт OTA (по умолчанию 3232)
        ArduinoOTA.setPort(3232);
        
        // Callback при старте обновления
        ArduinoOTA.onStart([this, &state]() {
            String type;
            if (ArduinoOTA.getCommand() == U_FLASH) {
                type = "sketch";
            } else {  // U_SPIFFS
                type = "filesystem";
            }
            
            Serial.println("OTA Update Start: " + type);
            otaInProgress = true;
            otaProgress = 0;
            
            // Остановка программы копчения
            if (state.mode == SystemState::Mode::RUNNING) {
                state.mode = SystemState::Mode::IDLE;
                Serial.println("⚠️  Program stopped for OTA update");
            }
        });
        
        // Callback при завершении обновления
        ArduinoOTA.onEnd([this]() {
            Serial.println("\nOTA Update Complete!");
            otaInProgress = false;
            otaProgress = 100;
        });
        
        // Callback прогресса обновления
        ArduinoOTA.onProgress([this](unsigned int progress, unsigned int total) {
            otaProgress = (progress * 100) / total;
            
            // Вывод прогресса каждые 10%
            static int lastProgress = 0;
            if (otaProgress - lastProgress >= 10) {
                Serial.printf("OTA Progress: %u%%\n", otaProgress);
                lastProgress = otaProgress;
            }
        });
        
        // Callback при ошибке
        ArduinoOTA.onError([this](ota_error_t error) {
            Serial.printf("OTA Error[%u]: ", error);
            
            switch (error) {
                case OTA_AUTH_ERROR:
                    otaError = "Auth Failed";
                    Serial.println("Auth Failed");
                    break;
                case OTA_BEGIN_ERROR:
                    otaError = "Begin Failed";
                    Serial.println("Begin Failed");
                    break;
                case OTA_CONNECT_ERROR:
                    otaError = "Connect Failed";
                    Serial.println("Connect Failed");
                    break;
                case OTA_RECEIVE_ERROR:
                    otaError = "Receive Failed";
                    Serial.println("Receive Failed");
                    break;
                case OTA_END_ERROR:
                    otaError = "End Failed";
                    Serial.println("End Failed");
                    break;
                default:
                    otaError = "Unknown Error";
                    Serial.println("Unknown Error");
                    break;
            }
            
            otaInProgress = false;
        });
        
        // Запуск OTA
        ArduinoOTA.begin();
        otaEnabled = true;
        
        Serial.println("✓ OTA initialized");
        Serial.printf("  Hostname: %s\n", hostname.c_str());
        Serial.printf("  IP: %s\n", WiFi.localIP().toString().c_str());
        Serial.printf("  Port: 3232\n");
        
        return true;
    }
    
    /**
     * Обработка OTA (вызывать в loop)
     */
    void handle() {
        if (otaEnabled) {
            ArduinoOTA.handle();
        }
    }
    
    /**
     * Проверка активности OTA
     */
    bool isOTAInProgress() const {
        return otaInProgress;
    }
    
    /**
     * Получение прогресса OTA
     */
    int getOTAProgress() const {
        return otaProgress;
    }
    
    /**
     * Получение ошибки OTA
     */
    String getOTAError() const {
        return otaError;
    }
    
    /**
     * Проверка доступности OTA
     */
    bool isEnabled() const {
        return otaEnabled;
    }
    
    /**
     * Остановка OTA
     */
    void stop() {
        if (otaEnabled) {
            // ArduinoOTA не имеет метода stop, просто отключаем флаг
            otaEnabled = false;
            Serial.println("✓ OTA disabled");
        }
    }
    
    /**
     * Получение информации об OTA
     */
    void printInfo() {
        if (!otaEnabled) {
            Serial.println("OTA: Disabled");
            return;
        }
        
        Serial.println("OTA Info:");
        Serial.printf("  Status: %s\n", otaInProgress ? "In Progress" : "Ready");
        Serial.printf("  Progress: %d%%\n", otaProgress);
        Serial.printf("  Hostname: SmartSmoker-*\n");
        Serial.printf("  Port: 3232\n");
        
        if (!otaError.isEmpty()) {
            Serial.printf("  Last Error: %s\n", otaError.c_str());
        }
    }
};

#endif // OTA_MANAGER_H
