/**
 * Менеджер OTA обновлений с сервера.
 * Поддерживает TLS (Let's Encrypt), SHA256-верификацию прошивки
 * и защиту от даунгрейда версии.
 */

#ifndef SERVER_OTA_MANAGER_H
#define SERVER_OTA_MANAGER_H

#include <Arduino.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Update.h>
#include <ArduinoJson.h>
#include <esp_task_wdt.h>
#include "mbedtls/md.h"
#include "SystemState.h"
#include "certs.h"

class ServerOTAManager {
private:
    String serverUrl;
    String deviceId;
    String apiToken;
    String currentVersion;
    bool updateInProgress = false;
    int updateProgress = 0;
    String lastError = "";
    unsigned long lastCheckTime = 0;
    unsigned long checkInterval = 3600000; // 1 час

    WiFiClientSecure* secureClient = nullptr;
    
    void ensureTLS() {
        if (!secureClient) {
            secureClient = new WiFiClientSecure();
            secureClient->setCACert(ROOT_CA_CERT);
            secureClient->setHandshakeTimeout(5);  // 5 секунд макс на TLS handshake
            Serial.println("[INFO] ServerOTAManager TLS initialized (lazy)");
        }
        esp_task_wdt_reset();  // TLS handshake может занять время
    }
    
    void releaseTLS() {
        if (secureClient) {
            delete secureClient;
            secureClient = nullptr;
        }
    }

    // Преобразование строки версии в число для сравнения
    int versionToInt(const String& version) {
        int major = 0, minor = 0, patch = 0;
        sscanf(version.c_str(), "%d.%d.%d", &major, &minor, &patch);
        return major * 10000 + minor * 100 + patch;
    }

public:
    // Проверка наличия обновлений на сервере
    bool checkForUpdates() {
        if (updateInProgress) { Serial.println("[WARN] OTA уже выполняется"); return false; }
        if (WiFi.status() != WL_CONNECTED) { Serial.println("[WARN] WiFi не подключён"); return false; }

        unsigned long now = millis();
        if (lastCheckTime != 0 && (now - lastCheckTime) < checkInterval) return false;
        lastCheckTime = now;

        Serial.println("[INFO] Проверка обновлений...");

        ensureTLS();
        HTTPClient http;
        String url = serverUrl + "/api/check-update.php?device_id=" + deviceId
                   + "&api_token=" + apiToken + "&current_version=" + currentVersion;
        http.begin(*secureClient, url);
        http.setTimeout(10000);

        int httpCode = http.GET();
        if (httpCode != HTTP_CODE_OK) {
            lastError = "HTTP " + String(httpCode);
            Serial.printf("[ERROR] Проверка обновлений: %s\n", lastError.c_str());
            http.end();
            releaseTLS();
            return false;
        }

        String payload = http.getString();
        http.end();
        releaseTLS();

        JsonDocument doc;
        if (deserializeJson(doc, payload)) {
            lastError = "JSON parse error";
            Serial.println("[ERROR] " + lastError);
            return false;
        }

        if (!doc["update_available"].as<bool>()) {
            Serial.println("[INFO] Обновлений нет");
            return false;
        }

        String newVersion  = doc["latest_version"].as<String>();
        String downloadUrl = doc["download_url"].as<String>();
        int    fileSize    = doc["file_size"].as<int>();
        String checksum    = doc["checksum"].as<String>();
        bool   isRequired  = doc["is_required"].as<bool>();

        Serial.printf("[INFO] Доступна версия %s (текущая: %s)\n", newVersion.c_str(), currentVersion.c_str());

        if (!isRequired) {
            Serial.println("[INFO] Необязательное обновление — пропускаем");
            return false;
        }

        return downloadAndApplyUpdate(downloadUrl, fileSize, checksum, newVersion);
    }

private:
    // Загрузка и применение обновления: TLS, SHA256-верификация, downgrade-защита, watchdog
    bool downloadAndApplyUpdate(const String& url, int expectedSize,
                                const String& expectedChecksum,
                                const String& newVersion = "") {
        if (updateInProgress) { Serial.println("[WARN] OTA уже выполняется"); return false; }

        // Защита от даунгрейда
        if (!newVersion.isEmpty() && versionToInt(newVersion) <= versionToInt(currentVersion)) {
            lastError = "Даунгрейд отклонён: " + newVersion + " <= " + currentVersion;
            Serial.println("[WARN] " + lastError);
            return false;
        }

        Serial.println("[INFO] Загрузка прошивки: " + url);

        ensureTLS();
        HTTPClient http;
        http.begin(*secureClient, url);
        http.setTimeout(60000);

        int httpCode = http.GET();
        if (httpCode != HTTP_CODE_OK) {
            lastError = "Ошибка загрузки: HTTP " + String(httpCode);
            Serial.println("[ERROR] " + lastError);
            http.end();
            releaseTLS();
            return false;
        }

        int fileSize = http.getSize();
        if (fileSize <= 0) fileSize = expectedSize;
        if (expectedSize > 0 && fileSize != expectedSize) {
            Serial.printf("[WARN] Размер: ожидался %d, получен %d\n", expectedSize, fileSize);
        }

        if (!Update.begin(fileSize)) {
            lastError = "Update.begin: " + String(Update.errorString());
            Serial.println("[ERROR] " + lastError);
            http.end();
            return false;
        }

        updateInProgress = true;
        updateProgress = 0;

        // Инициализация потокового SHA256
        mbedtls_md_context_t mdCtx;
        mbedtls_md_init(&mdCtx);
        mbedtls_md_setup(&mdCtx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
        mbedtls_md_starts(&mdCtx);

        WiFiClient* stream = http.getStreamPtr();
        uint8_t buffer[512];
        size_t totalRead = 0;

        while (http.connected() && (int)totalRead < fileSize) {
            esp_task_wdt_reset(); // сброс watchdog в длительном цикле
            size_t available = stream->available();
            if (available > 0) {
                int read = stream->readBytes(buffer, min(available, sizeof(buffer)));
                if (read > 0) {
                    Update.write(buffer, read);
                    mbedtls_md_update(&mdCtx, buffer, read);
                    totalRead += read;
                    int pct = (int)((totalRead * 100) / fileSize);
                    if (pct - updateProgress >= 5) {
                        updateProgress = pct;
                        Serial.printf("[INFO] OTA прогресс: %d%%\n", pct);
                    }
                }
            } else {
                delay(1);
            }
        }

        http.end();
        releaseTLS();

        if ((int)totalRead != fileSize) {
            lastError = "Загрузка неполная: " + String(totalRead) + "/" + String(fileSize);
            Serial.println("[ERROR] " + lastError);
            mbedtls_md_free(&mdCtx);
            Update.end(false);
            updateInProgress = false;
            return false;
        }

        // Финализация SHA256 и сравнение с ожидаемым
        uint8_t hash[32];
        mbedtls_md_finish(&mdCtx, hash);
        mbedtls_md_free(&mdCtx);

        char hashHex[65];
        for (int i = 0; i < 32; i++) sprintf(hashHex + i * 2, "%02x", hash[i]);
        hashHex[64] = '\0';

        if (!expectedChecksum.isEmpty() && expectedChecksum != String(hashHex)) {
            lastError = "SHA256 не совпадает";
            Serial.printf("[ERROR] %s: ожидался %s, получен %s\n",
                          lastError.c_str(), expectedChecksum.c_str(), hashHex);
            Update.end(false);
            updateInProgress = false;
            return false;
        }

        if (!Update.end()) {
            lastError = "Update.end: " + String(Update.errorString());
            Serial.println("[ERROR] " + lastError);
            updateInProgress = false;
            return false;
        }

        Serial.println("[INFO] OTA обновление успешно! Перезагрузка...");
        updateInProgress = false;
        updateProgress = 100;
        delay(1000);
        ESP.restart();
        return true; // не достигается после restart
    }

public:
    // Инициализация: настройка TLS с корневым CA-сертификатом
    void init(const String& url, const String& devId, const String& version, const String& token = "") {
        serverUrl = url;
        deviceId = devId;
        currentVersion = version;
        apiToken = token;
        // TLS инициализируется лениво при первом запросе (ensureTLS)
        Serial.println("[INFO] ServerOTAManager инициализирован");
        Serial.printf("[INFO]   Сервер: %s, версия: %s\n", serverUrl.c_str(), currentVersion.c_str());
    }

    bool manualCheck() { lastCheckTime = 0; return checkForUpdates(); }

    void setCheckInterval(unsigned long interval) { checkInterval = interval; }
    int  getProgress()        const { return updateProgress; }
    bool isUpdateInProgress() const { return updateInProgress; }
    String getLastError()     const { return lastError; }
};

#endif // SERVER_OTA_MANAGER_H
