#include "AutoUpdateClient.h"
#include "BindingManager.h"
#include "constants.h"
#include "esp_task_wdt.h"

AutoUpdateClient::AutoUpdateClient() 
    : systemState(nullptr), programExecutor(nullptr), bindingManager(nullptr),
      state(UpdateState::IDLE), progress(0), lastCheckTime(0), 
      nextCheckTime(0), retryCount(0) {
}

bool AutoUpdateClient::begin(SystemState* state, ProgramExecutor* executor, BindingManager* binding) {
    systemState = state;
    programExecutor = executor;
    bindingManager = binding;
    
    if (!systemState || !programExecutor || !bindingManager) {
        Serial.println("[UPDATE] Error: Invalid dependencies");
        return false;
    }
    
    // Загружаем конфигурацию
    loadConfig();
    
    // Планируем первую проверку через 60 секунд
    nextCheckTime = millis() + FIRST_CHECK_DELAY;
    
    Serial.println("[UPDATE] AutoUpdateClient initialized");
    Serial.printf("[UPDATE] Enabled: %s, Policy: %s, Interval: %d sec\n",
                  config.enabled ? "yes" : "no",
                  config.policy == UpdatePolicy::ALL_UPDATES ? "all" : "required-only",
                  config.checkIntervalSeconds);
    
    return true;
}

void AutoUpdateClient::setConfig(const UpdateConfig& newConfig) {
    config = newConfig;
    saveConfig();
}

void AutoUpdateClient::saveConfig() {
    JsonDocument doc;
    doc["enabled"] = config.enabled;
    doc["policy"] = (config.policy == UpdatePolicy::ALL_UPDATES) ? "all_updates" : "required_only";
    doc["check_interval_seconds"] = config.checkIntervalSeconds;
    doc["last_check_timestamp"] = lastCheckTime;
    doc["last_check_version"] = FIRMWARE_VERSION;
    
    File file = LittleFS.open(CONFIG_FILE, "w");
    if (!file) {
        Serial.println("[UPDATE] Failed to save config");
        return;
    }
    
    serializeJson(doc, file);
    file.close();
    
    logger.log(UpdateLogger::EventType::CONFIG_CHANGED, 
               "Configuration updated", "", true, 0);
}

void AutoUpdateClient::loadConfig() {
    if (!LittleFS.exists(CONFIG_FILE)) {
        Serial.println("[UPDATE] Config file not found, using defaults");
        return;
    }
    
    File file = LittleFS.open(CONFIG_FILE, "r");
    if (!file) {
        Serial.println("[UPDATE] Failed to open config file");
        return;
    }
    
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, file);
    file.close();
    
    if (error) {
        Serial.printf("[UPDATE] Failed to parse config: %s\n", error.c_str());
        return;
    }
    
    config.enabled = doc["enabled"] | true;
    String policyStr = doc["policy"] | "all_updates";
    config.policy = (policyStr == "required_only") ? 
        UpdatePolicy::REQUIRED_ONLY : UpdatePolicy::ALL_UPDATES;
    config.checkIntervalSeconds = doc["check_interval_seconds"] | 3600;
    lastCheckTime = doc["last_check_timestamp"] | 0;
    
    Serial.println("[UPDATE] Config loaded successfully");
}

void AutoUpdateClient::enable() {
    config.enabled = true;
    saveConfig();
    Serial.println("[UPDATE] Auto-update enabled");
}

void AutoUpdateClient::disable() {
    config.enabled = false;
    saveConfig();
    Serial.println("[UPDATE] Auto-update disabled");
}

void AutoUpdateClient::checkForUpdate() {
    if (state != UpdateState::IDLE && state != UpdateState::FAILED) {
        Serial.println("[UPDATE] Check already in progress");
        return;
    }
    
    // Сбрасываем счетчик повторов
    retryCount = 0;
    
    // Запускаем проверку немедленно
    nextCheckTime = millis();
}

bool AutoUpdateClient::forceInstallPendingUpdate() {
    if (!hasPendingUpdate()) {
        Serial.println("[UPDATE] No pending update to install");
        return false;
    }
    
    if (state != UpdateState::IDLE && state != UpdateState::FAILED) {
        Serial.println("[UPDATE] Update already in progress");
        return false;
    }
    
    Serial.printf("[UPDATE] Force installing update: %s\n", pendingUpdate.version.c_str());
    
    logger.log(UpdateLogger::EventType::DOWNLOAD_START, 
               "Manual update installation", pendingUpdate.version, true, 0);
    
    // Trigger download and installation (bypasses policy check)
    return downloadFirmware();
}

bool AutoUpdateClient::checkForUpdateOnly() {
    if (state != UpdateState::IDLE && state != UpdateState::FAILED) {
        Serial.println("[UPDATE] Check already in progress");
        return false;
    }
    
    state = UpdateState::CHECKING;
    progress = 0;
    
    logger.log(UpdateLogger::EventType::CHECK_START, 
               "Manual check for updates", FIRMWARE_VERSION, true, 0);
    
    Serial.println("[UPDATE] Manual check for updates...");
    
    // Формируем URL запроса
    String url = String(CLOUD_BASE_URL) + "/api/check-update.php";
    url += "?device_id=" + bindingManager->getUUID();
    url += "&api_token=" + bindingManager->getAPIToken();
    url += "&current_version=" + String(FIRMWARE_VERSION);
    
    // Выполняем HTTPS запрос
    WiFiClientSecure client;
    client.setInsecure();  // Skip SSL certificate verification
    
    HTTPClient http;
    http.begin(client, url);
    http.setTimeout(15000);
    
    int httpCode = http.GET();
    
    if (httpCode != HTTP_CODE_OK) {
        String error = "HTTP error: " + String(httpCode);
        logger.log(UpdateLogger::EventType::CHECK_FAILED, error, "", false, httpCode);
        handleError(error);
        http.end();
        state = UpdateState::IDLE;
        return false;
    }
    
    String response = http.getString();
    http.end();
    
    // Парсим ответ
    JsonDocument doc;
    DeserializationError jsonErr = deserializeJson(doc, response);
    
    if (jsonErr) {
        String errorMsg = "JSON parse error: " + String(jsonErr.c_str());
        logger.log(UpdateLogger::EventType::CHECK_FAILED, errorMsg, "", false, -1);
        handleError(errorMsg);
        state = UpdateState::IDLE;
        return false;
    }
    
    bool updateAvailable = doc["update_available"] | false;
    
    if (!updateAvailable) {
        logger.log(UpdateLogger::EventType::CHECK_SUCCESS, 
                   "No updates available", FIRMWARE_VERSION, true, 0);
        Serial.println("[UPDATE] No updates available");
        
        // Очищаем информацию о ожидающем обновлении
        pendingUpdate.version = "";
        pendingUpdate.downloadUrl = "";
        pendingUpdate.checksum = "";
        pendingUpdate.fileSize = 0;
        pendingUpdate.isRequired = false;
        pendingUpdate.minVersionRequired = "";
        pendingUpdate.releaseNotes = "";
        
        state = UpdateState::IDLE;
        lastCheckTime = millis();
        return true;
    }
    
    // Сохраняем информацию об обновлении (но НЕ устанавливаем)
    pendingUpdate.version = doc["latest_version"] | "";
    pendingUpdate.downloadUrl = doc["download_url"] | "";
    pendingUpdate.checksum = doc["checksum"] | "";
    pendingUpdate.fileSize = doc["file_size"] | 0;
    pendingUpdate.isRequired = doc["is_required"] | false;
    pendingUpdate.minVersionRequired = doc["min_version_required"] | "";
    pendingUpdate.releaseNotes = doc["release_notes"] | "";
    
    logger.log(UpdateLogger::EventType::CHECK_SUCCESS, 
               "Update available: " + pendingUpdate.version, 
               pendingUpdate.version, true, 0);
    
    Serial.printf("[UPDATE] Update available: %s (required: %s)\n",
                  pendingUpdate.version.c_str(),
                  pendingUpdate.isRequired ? "yes" : "no");
    
    state = UpdateState::IDLE;
    lastCheckTime = millis();
    
    return true;
}

void AutoUpdateClient::update() {
    // Проверяем, пора ли проверять обновления
    if (millis() < nextCheckTime) {
        return;
    }
    
    // Проверяем, включены ли автообновления
    if (!config.enabled) {
        scheduleNextCheck();
        return;
    }
    
    // Проверяем подключение к интернету
    if (!bindingManager || !bindingManager->isBound()) {
        Serial.println("[UPDATE] Device not bound, skipping check");
        scheduleNextCheck();
        return;
    }
    
    // Выполняем проверку обновлений
    if (state == UpdateState::IDLE || state == UpdateState::FAILED) {
        performUpdateCheck();
    }
}

bool AutoUpdateClient::performUpdateCheck() {
    state = UpdateState::CHECKING;
    progress = 0;
    
    logger.log(UpdateLogger::EventType::CHECK_START, 
               "Checking for updates", FIRMWARE_VERSION, true, 0);
    
    Serial.println("[UPDATE] Checking for updates...");
    
    // Формируем URL запроса
    String url = String(CLOUD_BASE_URL) + "/api/check-update.php";
    url += "?device_id=" + bindingManager->getUUID();
    url += "&api_token=" + bindingManager->getAPIToken();
    url += "&current_version=" + String(FIRMWARE_VERSION);
    
    // DEBUG: Выводим параметры аутентификации
    Serial.println("[UPDATE] ========================================");
    Serial.printf("[UPDATE] Device ID: %s\n", bindingManager->getUUID().c_str());
    Serial.printf("[UPDATE] API Token: %s\n", bindingManager->getAPIToken().c_str());
    Serial.printf("[UPDATE] Is Bound: %s\n", bindingManager->isBound() ? "YES" : "NO");
    Serial.printf("[UPDATE] Current Version: %s\n", FIRMWARE_VERSION);
    Serial.printf("[UPDATE] Request URL: %s\n", url.c_str());
    Serial.println("[UPDATE] ========================================");
    
    // Выполняем HTTPS запрос
    WiFiClientSecure client;
    client.setInsecure();  // Skip SSL certificate verification
    
    HTTPClient http;
    http.begin(client, url);
    http.setTimeout(15000);  // 15 секунд таймаут
    
    int httpCode = http.GET();
    
    if (httpCode != HTTP_CODE_OK) {
        String error = "HTTP error: " + String(httpCode);
        
        // DEBUG: Выводим тело ответа при ошибке
        if (httpCode == 401) {
            String errorResponse = http.getString();
            Serial.println("[UPDATE] ========================================");
            Serial.println("[UPDATE] 401 Unauthorized Error Details:");
            Serial.printf("[UPDATE] Response body: %s\n", errorResponse.c_str());
            Serial.println("[UPDATE] ========================================");
            error += " - " + errorResponse;
        }
        
        logger.log(UpdateLogger::EventType::CHECK_FAILED, error, "", false, httpCode);
        handleError(error);
        http.end();
        return false;
    }
    
    String response = http.getString();
    http.end();
    
    // Парсим ответ
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, response);
    
    if (error) {
        String errorMsg = "JSON parse error: " + String(error.c_str());
        logger.log(UpdateLogger::EventType::CHECK_FAILED, errorMsg, "", false, -1);
        handleError(errorMsg);
        return false;
    }
    
    bool updateAvailable = doc["update_available"] | false;
    
    if (!updateAvailable) {
        logger.log(UpdateLogger::EventType::CHECK_SUCCESS, 
                   "No updates available", FIRMWARE_VERSION, true, 0);
        Serial.println("[UPDATE] No updates available");
        state = UpdateState::IDLE;
        lastCheckTime = millis();
        scheduleNextCheck();
        return true;
    }
    
    // Сохраняем информацию об обновлении
    pendingUpdate.version = doc["latest_version"] | "";
    pendingUpdate.downloadUrl = doc["download_url"] | "";
    pendingUpdate.checksum = doc["checksum"] | "";
    pendingUpdate.fileSize = doc["file_size"] | 0;
    pendingUpdate.isRequired = doc["is_required"] | false;
    pendingUpdate.minVersionRequired = doc["min_version_required"] | "";
    pendingUpdate.releaseNotes = doc["release_notes"] | "";
    
    logger.log(UpdateLogger::EventType::CHECK_SUCCESS, 
               "Update available: " + pendingUpdate.version, 
               pendingUpdate.version, true, 0);
    
    Serial.printf("[UPDATE] Update available: %s (required: %s)\n",
                  pendingUpdate.version.c_str(),
                  pendingUpdate.isRequired ? "yes" : "no");
    
    lastCheckTime = millis();
    
    // Проверяем, нужно ли устанавливать это обновление
    if (!shouldInstallUpdate(pendingUpdate.isRequired)) {
        logger.log(UpdateLogger::EventType::CHECK_SUCCESS, 
                   "Update skipped by policy", pendingUpdate.version, true, 0);
        Serial.println("[UPDATE] Update skipped by policy");
        state = UpdateState::IDLE;
        scheduleNextCheck();
        return true;
    }
    
    // Начинаем загрузку
    return downloadFirmware();
}

bool AutoUpdateClient::shouldInstallUpdate(bool isRequired) {
    // Обязательные обновления устанавливаются всегда
    if (isRequired) {
        return true;
    }
    
    // Необязательные обновления зависят от политики
    return config.policy == UpdatePolicy::ALL_UPDATES;
}

bool AutoUpdateClient::isProgramActive() {
    if (!systemState) {
        return false;
    }
    
    return systemState->mode == SystemState::Mode::RUNNING;
}

void AutoUpdateClient::scheduleNextCheck() {
    nextCheckTime = millis() + (config.checkIntervalSeconds * 1000UL);
    Serial.printf("[UPDATE] Next check in %d seconds\n", config.checkIntervalSeconds);
}

void AutoUpdateClient::handleError(const String& error) {
    lastError = error;
    retryCount++;
    
    if (retryCount >= config.maxRetries) {
        Serial.printf("[UPDATE] Max retries (%d) exceeded\n", config.maxRetries);
        state = UpdateState::FAILED;
        retryCount = 0;
        scheduleNextCheck();
    } else {
        // Экспоненциальная задержка
        unsigned long delay = config.retryDelaySeconds * (1 << (retryCount - 1));
        nextCheckTime = millis() + (delay * 1000UL);
        Serial.printf("[UPDATE] Retry %d/%d in %lu seconds\n", 
                     retryCount, config.maxRetries, delay);
        state = UpdateState::IDLE;
    }
}

int AutoUpdateClient::versionCompare(const String& v1, const String& v2) {
    int major1 = 0, minor1 = 0, patch1 = 0;
    int major2 = 0, minor2 = 0, patch2 = 0;
    
    sscanf(v1.c_str(), "%d.%d.%d", &major1, &minor1, &patch1);
    sscanf(v2.c_str(), "%d.%d.%d", &major2, &minor2, &patch2);
    
    if (major1 != major2) return major1 - major2;
    if (minor1 != minor2) return minor1 - minor2;
    return patch1 - patch2;
}

bool AutoUpdateClient::downloadFirmware() {
    state = UpdateState::DOWNLOADING;
    progress = 0;
    
    logger.log(UpdateLogger::EventType::DOWNLOAD_START, 
               "Downloading firmware", pendingUpdate.version, true, 0);
    
    Serial.printf("[UPDATE] Downloading firmware %s...\n", pendingUpdate.version.c_str());
    
    if (WiFi.status() != WL_CONNECTED) {
        String error = "WiFi not connected";
        logger.log(UpdateLogger::EventType::DOWNLOAD_FAILED, error, pendingUpdate.version, false, -10);
        handleError(error);
        return false;
    }
    
    if (isProgramActive()) {
        logger.log(UpdateLogger::EventType::INSTALL_START, 
                   "Installation postponed (program active)", pendingUpdate.version, true, 0);
        Serial.println("[UPDATE] Installation postponed - program is active");
        state = UpdateState::IDLE;
        nextCheckTime = millis() + 300000;
        return true;
    }
    
    Serial.println("[UPDATE] ========================================");
    Serial.printf("[UPDATE] Download URL: %s\n", pendingUpdate.downloadUrl.c_str());
    Serial.printf("[UPDATE] Expected size: %d bytes\n", pendingUpdate.fileSize);
    Serial.printf("[UPDATE] Checksum: %s\n", pendingUpdate.checksum.c_str());
    Serial.printf("[UPDATE] Free heap: %d bytes\n", ESP.getFreeHeap());
    Serial.println("[UPDATE] ========================================");
    
    WiFiClientSecure client;
    client.setInsecure();
    
    HTTPClient http;
    http.setTimeout(60000);  // 60 sec timeout for large file
    http.setFollowRedirects(HTTPC_STRICT_FOLLOW_REDIRECTS);
    http.begin(client, pendingUpdate.downloadUrl);
    
    int httpCode = http.GET();
    
    if (httpCode != HTTP_CODE_OK) {
        String error = "Download HTTP error: " + String(httpCode);
        if (httpCode < 0) {
            error += " (" + HTTPClient::errorToString(httpCode) + ")";
        }
        Serial.printf("[UPDATE] %s\n", error.c_str());
        logger.log(UpdateLogger::EventType::DOWNLOAD_FAILED, error, pendingUpdate.version, false, httpCode);
        handleError(error);
        http.end();
        return false;
    }
    
    int contentLength = http.getSize();
    int expectedSize = (contentLength > 0) ? contentLength : (int)pendingUpdate.fileSize;
    
    Serial.printf("[UPDATE] Content-Length: %d, using expectedSize: %d\n", contentLength, expectedSize);
    
    if (expectedSize <= 0) {
        String error = "Unknown firmware size";
        logger.log(UpdateLogger::EventType::DOWNLOAD_FAILED, error, pendingUpdate.version, false, -2);
        handleError(error);
        http.end();
        return false;
    }
    
    // Begin OTA update - stream directly without saving to LittleFS
    Serial.printf("[UPDATE] Free sketch space: %d bytes\n", ESP.getFreeSketchSpace());
    if (!Update.begin(expectedSize, U_FLASH)) {
        String error = "OTA begin failed: " + String(Update.errorString());
        logger.log(UpdateLogger::EventType::INSTALL_FAILED, error, pendingUpdate.version, false, Update.getError());
        handleError(error);
        http.end();
        return false;
    }
    Serial.println("[UPDATE] OTA begin OK, streaming firmware...");
    
    state = UpdateState::INSTALLING;
    
    // Stream firmware directly into OTA Update + compute SHA256 simultaneously
    WiFiClient* stream = http.getStreamPtr();
    uint8_t buffer[1024];
    int downloaded = 0;
    unsigned long lastDataTime = millis();
    const unsigned long DATA_TIMEOUT = 15000;
    
    mbedtls_sha256_context sha_ctx;
    mbedtls_sha256_init(&sha_ctx);
    mbedtls_sha256_starts(&sha_ctx, 0);
    
    while (downloaded < expectedSize) {
        if (millis() - lastDataTime > DATA_TIMEOUT) {
            Serial.println("[UPDATE] Data timeout!");
            break;
        }
        
        int available = stream->available();
        if (available <= 0) {
            if (!http.connected()) break;
            esp_task_wdt_reset();
            delay(1);
            continue;
        }
        
        int toRead = min(available, (int)sizeof(buffer));
        int c = stream->readBytes(buffer, toRead);
        if (c > 0) {
            // Write to OTA
            size_t written = Update.write(buffer, c);
            if (written != (size_t)c) {
                Serial.printf("[UPDATE] OTA write error: wrote %d of %d\n", written, c);
                break;
            }
            // Update SHA256
            mbedtls_sha256_update(&sha_ctx, buffer, c);
            
            downloaded += c;
            lastDataTime = millis();
            progress = (downloaded * 100) / expectedSize;
            
            // Reset watchdog to prevent reboot during long download
            esp_task_wdt_reset();
            
            if (downloaded % (64 * 1024) == 0) {
                Serial.printf("[UPDATE] Progress: %d/%d bytes (%d%%), free heap: %d\n", 
                             downloaded, expectedSize, progress, ESP.getFreeHeap());
            }
        }
    }
    
    http.end();
    
    Serial.printf("[UPDATE] Stream finished: %d/%d bytes\n", downloaded, expectedSize);
    
    if (downloaded < (int)(expectedSize * 0.99)) {
        String error = "Incomplete download: " + String(downloaded) + "/" + String(expectedSize);
        logger.log(UpdateLogger::EventType::DOWNLOAD_FAILED, error, pendingUpdate.version, false, -4);
        mbedtls_sha256_free(&sha_ctx);
        Update.abort();
        handleError(error);
        return false;
    }
    
    // Verify SHA256
    uint8_t hash[32];
    mbedtls_sha256_finish(&sha_ctx, hash);
    mbedtls_sha256_free(&sha_ctx);
    
    char hashStr[65];
    for (int i = 0; i < 32; i++) sprintf(&hashStr[i*2], "%02x", hash[i]);
    hashStr[64] = '\0';
    
    Serial.printf("[UPDATE] Calculated SHA256: %s\n", hashStr);
    Serial.printf("[UPDATE] Expected  SHA256:  %s\n", pendingUpdate.checksum.c_str());
    
    if (!pendingUpdate.checksum.isEmpty() && String(hashStr) != pendingUpdate.checksum) {
        String error = "Checksum mismatch";
        logger.log(UpdateLogger::EventType::VERIFY_FAILED, error, pendingUpdate.version, false, -8);
        Update.abort();
        handleError(error);
        return false;
    }
    
    logger.log(UpdateLogger::EventType::VERIFY_SUCCESS, "Checksum OK", pendingUpdate.version, true, 0);
    
    // Finalize OTA
    if (!Update.end(true)) {
        String error = "OTA end failed: " + String(Update.errorString());
        logger.log(UpdateLogger::EventType::INSTALL_FAILED, error, pendingUpdate.version, false, Update.getError());
        handleError(error);
        return false;
    }
    
    progress = 100;
    logger.log(UpdateLogger::EventType::INSTALL_SUCCESS, 
               "Firmware installed successfully", pendingUpdate.version, true, 0);
    
    Serial.println("[UPDATE] Installation successful! Rebooting in 3 seconds...");
    state = UpdateState::REBOOTING;
    
    delay(3000);
    ESP.restart();
    
    return true;
}

bool AutoUpdateClient::verifyFirmware(const uint8_t* data, size_t size) {
    state = UpdateState::VERIFYING;
    progress = 0;
    
    logger.log(UpdateLogger::EventType::VERIFY_START, 
               "Verifying firmware", pendingUpdate.version, true, 0);
    
    Serial.println("[UPDATE] Verifying firmware...");
    
    // Проверяем размер
    if (size != pendingUpdate.fileSize) {
        String error = "File size mismatch: expected " + String(pendingUpdate.fileSize) + 
                      ", got " + String(size);
        logger.log(UpdateLogger::EventType::VERIFY_FAILED, error, 
                   pendingUpdate.version, false, -7);
        handleError(error);
        return false;
    }
    
    // Вычисляем SHA256
    mbedtls_sha256_context ctx;
    uint8_t hash[32];
    
    mbedtls_sha256_init(&ctx);
    mbedtls_sha256_starts(&ctx, 0);
    mbedtls_sha256_update(&ctx, data, size);
    mbedtls_sha256_finish(&ctx, hash);
    mbedtls_sha256_free(&ctx);
    
    // Конвертируем в hex строку
    char hashStr[65];
    for (int i = 0; i < 32; i++) {
        sprintf(&hashStr[i*2], "%02x", hash[i]);
    }
    hashStr[64] = '\0';
    
    Serial.printf("[UPDATE] Calculated checksum: %s\n", hashStr);
    Serial.printf("[UPDATE] Expected checksum:   %s\n", pendingUpdate.checksum.c_str());
    
    // Сравниваем контрольные суммы
    if (String(hashStr) != pendingUpdate.checksum) {
        String error = "Checksum mismatch";
        logger.log(UpdateLogger::EventType::VERIFY_FAILED, error, 
                   pendingUpdate.version, false, -8);
        handleError(error);
        return false;
    }
    
    logger.log(UpdateLogger::EventType::VERIFY_SUCCESS, 
               "Firmware verified successfully", pendingUpdate.version, true, 0);
    
    Serial.println("[UPDATE] Verification successful");
    progress = 100;
    
    return true;
}

bool AutoUpdateClient::installFirmware(const uint8_t* data, size_t size) {
    state = UpdateState::INSTALLING;
    progress = 0;
    
    logger.log(UpdateLogger::EventType::INSTALL_START, 
               "Installing firmware", pendingUpdate.version, true, 0);
    
    Serial.println("[UPDATE] Installing firmware...");
    
    // Проверяем совместимость версий
    if (!pendingUpdate.minVersionRequired.isEmpty()) {
        if (versionCompare(FIRMWARE_VERSION, pendingUpdate.minVersionRequired) < 0) {
            String error = "Current version " + String(FIRMWARE_VERSION) + 
                          " is below minimum required " + pendingUpdate.minVersionRequired;
            logger.log(UpdateLogger::EventType::INSTALL_FAILED, error, 
                       pendingUpdate.version, false, -9);
            handleError(error);
            return false;
        }
    }
    
    // Начинаем OTA обновление
    if (!Update.begin(size)) {
        String error = "OTA begin failed: " + String(Update.errorString());
        logger.log(UpdateLogger::EventType::INSTALL_FAILED, error, 
                   pendingUpdate.version, false, Update.getError());
        handleError(error);
        return false;
    }
    
    // Записываем прошивку
    // Note: Update.write() expects non-const pointer, so we need to cast
    size_t written = Update.write(const_cast<uint8_t*>(data), size);
    if (written != size) {
        String error = "OTA write failed: wrote " + String(written) + 
                      " of " + String(size) + " bytes";
        logger.log(UpdateLogger::EventType::INSTALL_FAILED, error, 
                   pendingUpdate.version, false, -10);
        Update.abort();
        handleError(error);
        return false;
    }
    
    progress = 50;
    
    // Завершаем обновление
    if (!Update.end(true)) {
        String error = "OTA end failed: " + String(Update.errorString());
        logger.log(UpdateLogger::EventType::INSTALL_FAILED, error, 
                   pendingUpdate.version, false, Update.getError());
        handleError(error);
        return false;
    }
    
    progress = 100;
    
    logger.log(UpdateLogger::EventType::INSTALL_SUCCESS, 
               "Firmware installed successfully", pendingUpdate.version, true, 0);
    
    Serial.println("[UPDATE] Installation successful");
    Serial.println("[UPDATE] Rebooting in 5 seconds...");
    
    state = UpdateState::REBOOTING;
    
    // Удаляем временный файл
    LittleFS.remove(TEMP_FIRMWARE_FILE);
    
    // Перезагружаемся через 5 секунд
    delay(5000);
    ESP.restart();
    
    return true;
}
