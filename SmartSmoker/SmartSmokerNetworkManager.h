/**
 * ОТЛАДОЧНАЯ ВЕРСИЯ - Менеджер сети с прямым подключением
 * 
 * @file SmartSmokerNetworkManager_DEBUG.h
 * @version 1.0-DEBUG
 * 
 * ИЗМЕНЕНИЯ:
 * - Прямое подключение к WiFi сети CRCError111
 * - Закомментирован режим AP для отладки
 * - После отладки вернуть оригинальный код
 */

#ifndef SMART_SMOKER_NETWORK_MANAGER_H
#define SMART_SMOKER_NETWORK_MANAGER_H

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiAP.h>
#include <ArduinoJson.h>
#include "constants.h"
#include "SystemState.h"

// ========================================
// НАСТРОЙКИ ОТЛАДКИ
// ========================================
#define DEBUG_WIFI_SSID ""
#define DEBUG_WIFI_PASSWORD ""
#define DEBUG_MODE false  // Установите false для возврата к AP режиму

/**
 * Класс для управления сетевыми подключениями
 */
class SmartSmokerNetworkManager {
private:
    // Состояние сети
    bool apModeActive = false;
    bool staModeActive = false;
    unsigned long lastConnectionAttempt = 0;
    unsigned long lastStatusCheck = 0;
    uint8_t connectionAttempts = 0;
    
    // Настройки точки доступа
    String apSSID;
    String apPassword;
    
    // Настройки клиента WiFi
    String staSSID;
    String staPassword;
    
    // Статистика
    uint32_t totalConnections = 0;
    uint32_t connectionFailures = 0;
    unsigned long totalUptime = 0;

public:
    /**
     * Инициализация сетевого менеджера
     * РЕЖИМ ОТЛАДКИ: Прямое подключение к WiFi
     */
    bool init(SystemState& state) {
        Serial.println("========================================");
        Serial.println("   Network Manager - DEBUG MODE");
        Serial.println("========================================");
        
        // Генерация уникального SSID для точки доступа
        uint64_t chipid = ESP.getEfuseMac();
        apSSID = "SmartSmoker_" + String((uint32_t)(chipid >> 32), HEX);

        // Генерация уникального пароля AP из MAC-адреса (H-06)
        // Последние 8 символов CRC32 от MAC — уникально для каждого устройства
        uint8_t mac[6];
        WiFi.macAddress(mac);
        uint32_t hash = 0x811c9dc5u; // FNV-1a offset basis
        for (int i = 0; i < 6; i++) {
            hash ^= mac[i];
            hash *= 0x01000193u; // FNV prime
        }
        char pwdBuf[9];
        snprintf(pwdBuf, sizeof(pwdBuf), "%08X", hash);
        apPassword = String(pwdBuf);
        
        if (DEBUG_MODE) {
            // ========================================
            // РЕЖИМ ОТЛАДКИ: Прямое подключение к WiFi
            // ========================================
            Serial.println("DEBUG MODE ENABLED");
            Serial.printf("Connecting to: %s\n", DEBUG_WIFI_SSID);
            Serial.println("Password: ********");
            Serial.println();
            
            if (connectToWiFi(DEBUG_WIFI_SSID, DEBUG_WIFI_PASSWORD)) {
                state.networkMode = SystemState::NetworkMode::STA;
                state.wifiConnected = true;
                state.ssid = DEBUG_WIFI_SSID;
                state.wifiPassword = DEBUG_WIFI_PASSWORD;
                state.ip = WiFi.localIP().toString();
                
                Serial.println("========================================");
                Serial.println("   WiFi Connection Successful!");
                Serial.println("========================================");
                Serial.printf("SSID: %s\n", DEBUG_WIFI_SSID);
                Serial.printf("IP Address: %s\n", state.ip.c_str());
                Serial.printf("Signal Strength: %d dBm\n", WiFi.RSSI());
                Serial.printf("MAC Address: %s\n", WiFi.macAddress().c_str());
                Serial.println("========================================");
                Serial.println();
                
                return true;
            } else {
                Serial.println("========================================");
                Serial.println("   WiFi Connection FAILED!");
                Serial.println("========================================");
                Serial.println("Falling back to Access Point mode...");
                Serial.println();
                
                startAccessPoint(state);
            }
        } else {
            // ========================================
            // ПРОДАКШЕН РЕЖИМ: AP или сохраненная сеть
            // ========================================
            Serial.println("PRODUCTION MODE");
            
            // Загрузка сохраненных настроек WiFi
            loadWiFiSettings(state);
            
            // Попытка подключения к сохраненной сети
            if (!state.ssid.isEmpty() && !state.wifiPassword.isEmpty()) {
                if (connectToWiFi(state.ssid, state.wifiPassword)) {
                    state.networkMode = SystemState::NetworkMode::STA;
                    state.wifiConnected = true;
                    state.ip = WiFi.localIP().toString();
                    Serial.printf("✓ Connected to WiFi: %s\n", state.ssid.c_str());
                    return true;
                }
            }
            
            // Если не удалось подключиться, запускаем точку доступа
            startAccessPoint(state);
        }
        
        Serial.println("✓ Network manager initialized");
        return true;
    }
    
    /**
     * Обновление состояния сети
     */
    void update(SystemState& state) {
        unsigned long currentTime = millis();
        
        // Проверка состояния каждые 10 секунд
        if (currentTime - lastStatusCheck >= 10000) {
            checkNetworkStatus(state);
            lastStatusCheck = currentTime;
        }
        
        // Попытка переподключения при потере связи
        if (state.networkMode == SystemState::NetworkMode::STA && !state.wifiConnected) {
            if (currentTime - lastConnectionAttempt >= 30000) { // Каждые 30 секунд
                attemptReconnection(state);
                lastConnectionAttempt = currentTime;
            }
        }
    }

private:
    /**
     * Подключение к WiFi сети
     */
    bool connectToWiFi(const String& ssid, const String& password) {
        Serial.printf("Connecting to WiFi: %s\n", ssid.c_str());
        
        WiFi.mode(WIFI_STA);
        WiFi.begin(ssid.c_str(), password.c_str());
        
        int attempts = 0;
        while (WiFi.status() != WL_CONNECTED && attempts < 20) {
            delay(500);
            yield();  // Даём время фоновым задачам
            Serial.print(".");
            attempts++;
        }
        Serial.println();
        
        if (WiFi.status() == WL_CONNECTED) {
            Serial.println("✓ WiFi connected");
            Serial.printf("IP address: %s\n", WiFi.localIP().toString().c_str());
            Serial.printf("Signal: %d dBm\n", WiFi.RSSI());
            return true;
        } else {
            Serial.println("✗ WiFi connection failed");
            Serial.printf("Status code: %d\n", WiFi.status());
            return false;
        }
    }
    
    /**
     * Запуск точки доступа
     */
    bool startAccessPoint(SystemState& state) {
        Serial.printf("Starting Access Point: %s\n", apSSID.c_str());
        
        WiFi.mode(WIFI_AP);
        bool success = WiFi.softAP(apSSID.c_str(), apPassword.c_str());
        
        if (success) {
            state.networkMode = SystemState::NetworkMode::AP;
            state.ssid = apSSID;
            state.ip = WiFi.softAPIP().toString();
            apModeActive = true;
            
            Serial.println("========================================");
            Serial.println("   Access Point Started");
            Serial.println("========================================");
            Serial.printf("SSID: %s\n", apSSID.c_str());
            Serial.printf("Password: %s\n", apPassword.c_str());
            Serial.printf("IP: %s\n", state.ip.c_str());
            Serial.println("========================================");

            // Сохраняем пароль AP в state для отображения на дисплее
            state.apPassword = apPassword;

            return true;
        } else {
            Serial.println("✗ Failed to start Access Point");
            return false;
        }
    }
    
    /**
     * Проверка состояния сети
     */
    void checkNetworkStatus(SystemState& state) {
        if (state.networkMode == SystemState::NetworkMode::STA) {
            bool connected = (WiFi.status() == WL_CONNECTED);
            
            if (connected != state.wifiConnected) {
                state.wifiConnected = connected;
                
                if (connected) {
                    state.ip = WiFi.localIP().toString();
                    Serial.println("✓ WiFi reconnected");
                    Serial.printf("IP: %s, Signal: %d dBm\n", state.ip.c_str(), WiFi.RSSI());
                } else {
                    Serial.println("✗ WiFi disconnected");
                }
            }
        }
    }
    
    /**
     * Попытка переподключения
     */
    void attemptReconnection(SystemState& state) {
        if (connectionAttempts < 5) { // Максимум 5 попыток
            Serial.printf("Reconnection attempt %d/5\n", connectionAttempts + 1);
            
            if (connectToWiFi(state.ssid, state.wifiPassword)) {
                state.wifiConnected = true;
                state.ip = WiFi.localIP().toString();
                connectionAttempts = 0;
            } else {
                connectionAttempts++;
            }
        } else {
            Serial.println("Max reconnection attempts reached");
            if (DEBUG_MODE) {
                Serial.println("Staying in disconnected state (DEBUG MODE)");
            } else {
                Serial.println("Switching to AP mode");
                startAccessPoint(state);
            }
            connectionAttempts = 0;
        }
    }
    
    /**
     * Загрузка настроек WiFi
     */
    void loadWiFiSettings(SystemState& state) {
        // Здесь должна быть загрузка из LittleFS
        Serial.println("WiFi settings loaded from storage");
    }

public:
    /**
     * Настройка WiFi подключения
     */
    bool configureWiFi(SystemState& state, const String& ssid, const String& password) {
        Serial.printf("Configuring WiFi: %s\n", ssid.c_str());
        
        // Сохранение настроек
        state.ssid = ssid;
        state.wifiPassword = password;
        
        // Попытка подключения
        if (connectToWiFi(ssid, password)) {
            state.networkMode = SystemState::NetworkMode::STA;
            state.wifiConnected = true;
            state.ip = WiFi.localIP().toString();
            
            // Остановка точки доступа если была активна
            if (apModeActive) {
                WiFi.softAPdisconnect(true);
                apModeActive = false;
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * Получение информации о сети
     */
    void printNetworkInfo() {
        Serial.println();
        Serial.println("========================================");
        Serial.println("   Network Information");
        Serial.println("========================================");
        
        if (WiFi.getMode() == WIFI_STA) {
            Serial.println("Mode: Station (STA)");
            Serial.printf("SSID: %s\n", WiFi.SSID().c_str());
            Serial.printf("IP: %s\n", WiFi.localIP().toString().c_str());
            Serial.printf("Gateway: %s\n", WiFi.gatewayIP().toString().c_str());
            Serial.printf("DNS: %s\n", WiFi.dnsIP().toString().c_str());
            Serial.printf("Signal: %d dBm\n", WiFi.RSSI());
            Serial.printf("MAC: %s\n", WiFi.macAddress().c_str());
        } else if (WiFi.getMode() == WIFI_AP) {
            Serial.println("Mode: Access Point (AP)");
            Serial.printf("SSID: %s\n", apSSID.c_str());
            Serial.printf("IP: %s\n", WiFi.softAPIP().toString().c_str());
            Serial.printf("Clients: %d\n", WiFi.softAPgetStationNum());
        }
        
        Serial.println("========================================");
        Serial.println();
    }
};

#endif // SMART_SMOKER_NETWORK_MANAGER_H
