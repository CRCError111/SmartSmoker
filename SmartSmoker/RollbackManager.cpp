#include "RollbackManager.h"

RollbackManager::RollbackManager() 
    : bootStatus(BootStatus::FIRST_BOOT), bootTime(0), wifiConnected(false) {
}

bool RollbackManager::begin() {
    bootTime = millis();
    
    // Проверяем, это первая загрузка после OTA
    const esp_partition_t* running = esp_ota_get_running_partition();
    const esp_partition_t* boot = esp_ota_get_boot_partition();
    
    if (running != boot) {
        Serial.println("⚠️ First boot after OTA update");
        bootStatus = BootStatus::FIRST_BOOT;
        
        // Запускаем верификацию WiFi
        return verifyWiFiConnectivity();
    }
    
    bootStatus = BootStatus::VERIFIED;
    Serial.println("✅ Normal boot (not after OTA)");
    return true;
}

bool RollbackManager::verifyWiFiConnectivity() {
    Serial.println("🔍 Verifying WiFi connectivity...");
    
    unsigned long startTime = millis();
    
    while (millis() - startTime < WIFI_VERIFY_TIMEOUT) {
        if (WiFi.status() == WL_CONNECTED) {
            Serial.println("✅ WiFi connected, update verified");
            markBootSuccessful();
            return true;
        }
        delay(1000);
        Serial.print(".");
    }
    
    Serial.println("\n❌ WiFi connection failed, initiating rollback");
    markBootFailed();
    return false;
}

void RollbackManager::markBootSuccessful() {
    // Помечаем раздел как валидный
    esp_ota_mark_app_valid_cancel_rollback();
    bootStatus = BootStatus::VERIFIED;
    
    // Сбрасываем счетчик откатов
    resetRollbackCount();
    
    logger.log(UpdateLogger::EventType::CHECK_SUCCESS, 
               "Boot verified successfully", "", true, 0);
    
    Serial.println("✅ Boot marked as successful");
}

void RollbackManager::markBootFailed() {
    bootStatus = BootStatus::ROLLBACK_REQUIRED;
    
    logger.log(UpdateLogger::EventType::CHECK_FAILED, 
               "Boot verification failed", "", false, -1);
    
    performRollback();
}

bool RollbackManager::performRollback() {
    Serial.println("🔄 Performing rollback...");
    
    // Проверяем счетчик откатов
    int rollbackCount = getRollbackCount();
    if (rollbackCount >= MAX_ROLLBACK_COUNT) {
        Serial.println("❌ CRITICAL: Rollback loop detected (3+ rollbacks)");
        logger.log(UpdateLogger::EventType::ROLLBACK, 
                   "Rollback loop detected", "", false, -7);
        
        // Отключаем автообновления
        disableAutoUpdate();
        
        // Сбрасываем счетчик
        resetRollbackCount();
        
        return false;
    }
    
    const esp_partition_t* running = esp_ota_get_running_partition();
    const esp_partition_t* last_valid = esp_ota_get_last_invalid_partition();
    
    if (last_valid == NULL) {
        Serial.println("❌ No valid partition to rollback to");
        logger.log(UpdateLogger::EventType::ROLLBACK, 
                   "No valid partition available", "", false, -6);
        
        // Отключаем автообновления
        disableAutoUpdate();
        
        return false;
    }
    
    // Увеличиваем счетчик откатов
    incrementRollbackCount();
    
    // Устанавливаем загрузочный раздел на предыдущий
    esp_err_t err = esp_ota_set_boot_partition(last_valid);
    if (err != ESP_OK) {
        Serial.printf("❌ Rollback failed: %s\n", esp_err_to_name(err));
        logger.log(UpdateLogger::EventType::ROLLBACK, 
                   "Rollback operation failed", "", false, err);
        return false;
    }
    
    // Отключаем автообновления
    disableAutoUpdate();
    
    logger.log(UpdateLogger::EventType::ROLLBACK, 
               "Rollback successful", "", true, 0);
    
    bootStatus = BootStatus::ROLLBACK_COMPLETE;
    
    Serial.println("✅ Rollback complete, rebooting...");
    delay(1000);
    ESP.restart();
    
    return true;
}

void RollbackManager::disableAutoUpdate() {
    // Отключаем автообновления, чтобы предотвратить цикл обновлений
    if (!LittleFS.exists("/update_config.json")) {
        Serial.println("⚠️ Update config file not found");
        return;
    }
    
    File file = LittleFS.open("/update_config.json", "r");
    if (!file) {
        Serial.println("⚠️ Failed to open update config");
        return;
    }
    
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, file);
    file.close();
    
    if (error) {
        Serial.println("⚠️ Failed to parse update config");
        return;
    }
    
    doc["enabled"] = false;
    doc["auto_update_disabled_reason"] = "rollback_occurred";
    
    file = LittleFS.open("/update_config.json", "w");
    if (!file) {
        Serial.println("⚠️ Failed to save update config");
        return;
    }
    
    serializeJson(doc, file);
    file.close();
    
    Serial.println("⚠️ Auto-update disabled after rollback");
}

bool RollbackManager::canRollback() const {
    const esp_partition_t* last_valid = esp_ota_get_last_invalid_partition();
    return last_valid != NULL;
}

String RollbackManager::getCurrentPartition() const {
    const esp_partition_t* running = esp_ota_get_running_partition();
    if (running) {
        return String(running->label);
    }
    return "unknown";
}

String RollbackManager::getPreviousPartition() const {
    const esp_partition_t* last_valid = esp_ota_get_last_invalid_partition();
    if (last_valid) {
        return String(last_valid->label);
    }
    return "none";
}

bool RollbackManager::isFirstBootAfterUpdate() const {
    return bootStatus == BootStatus::FIRST_BOOT;
}

RollbackManager::BootStatus RollbackManager::checkBootStatus() {
    return bootStatus;
}

int RollbackManager::getRollbackCount() {
    if (!LittleFS.exists(ROLLBACK_COUNT_FILE)) {
        return 0;
    }
    
    File file = LittleFS.open(ROLLBACK_COUNT_FILE, "r");
    if (!file) {
        return 0;
    }
    
    int count = file.parseInt();
    file.close();
    
    return count;
}

void RollbackManager::incrementRollbackCount() {
    int count = getRollbackCount() + 1;
    
    File file = LittleFS.open(ROLLBACK_COUNT_FILE, "w");
    if (file) {
        file.println(count);
        file.close();
        Serial.printf("Rollback count: %d\n", count);
    }
}

void RollbackManager::resetRollbackCount() {
    if (LittleFS.exists(ROLLBACK_COUNT_FILE)) {
        LittleFS.remove(ROLLBACK_COUNT_FILE);
        Serial.println("Rollback count reset");
    }
}
