#ifndef AUTO_UPDATE_CLIENT_H
#define AUTO_UPDATE_CLIENT_H

#include <Arduino.h>
#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>
#include <Update.h>
#include <LittleFS.h>
#include <ArduinoJson.h>
#include <mbedtls/sha256.h>
#include "UpdateLogger.h"
#include "SystemState.h"
#include "ProgramExecutor.h"

// Forward declaration
class BindingManager;

/**
 * AutoUpdateClient - Клиент автоматических обновлений прошивки
 * 
 * Отвечает за:
 * - Периодическую проверку обновлений на сервере
 * - Загрузку и верификацию прошивок
 * - Установку обновлений с учетом политики
 * - Интеграцию с BindingManager для использования API токена
 */
class AutoUpdateClient {
public:
    // Политика обновлений
    enum class UpdatePolicy {
        ALL_UPDATES,      // Устанавливать все обновления
        REQUIRED_ONLY     // Устанавливать только обязательные
    };
    
    // Состояние процесса обновления
    enum class UpdateState {
        IDLE,
        CHECKING,
        DOWNLOADING,
        VERIFYING,
        INSTALLING,
        REBOOTING,
        FAILED
    };
    
    // Конфигурация обновлений
    struct UpdateConfig {
        bool enabled = true;
        UpdatePolicy policy = UpdatePolicy::ALL_UPDATES;
        uint32_t checkIntervalSeconds = 3600;  // 1 час по умолчанию
        uint32_t retryDelaySeconds = 300;      // 5 минут
        uint8_t maxRetries = 3;
    };
    
    // Информация о ожидающем обновлении
    struct PendingUpdate {
        String version;
        String downloadUrl;
        String checksum;
        uint32_t fileSize;
        bool isRequired;
        String minVersionRequired;
        String releaseNotes;
    };
    
    // Конструктор
    AutoUpdateClient();
    
    // Инициализация
    bool begin(SystemState* state, ProgramExecutor* executor, BindingManager* binding);
    
    // Конфигурация
    void setConfig(const UpdateConfig& config);
    UpdateConfig getConfig() const { return config; }
    void saveConfig();
    void loadConfig();
    
    // Операции обновления
    void checkForUpdate();           // Запустить проверку немедленно
    bool checkForUpdateOnly();       // Только проверить, без установки
    bool forceInstallPendingUpdate(); // Принудительно установить ожидающее обновление
    void update();                   // Вызывается из main loop
    
    // Статус
    UpdateState getState() const { return state; }
    int getProgress() const { return progress; }
    String getLastError() const { return lastError; }
    unsigned long getLastCheckTime() const { return lastCheckTime; }
    unsigned long getNextCheckTime() const { return nextCheckTime; }
    
    // Управление
    void enable();
    void disable();
    bool isEnabled() const { return config.enabled; }
    
    // Доступ к логгеру
    UpdateLogger& getLogger() { return logger; }
    
    // Информация о ожидающем обновлении
    bool hasPendingUpdate() const { return !pendingUpdate.version.isEmpty(); }
    PendingUpdate getPendingUpdate() const { return pendingUpdate; }
    
private:
    static constexpr const char* CONFIG_FILE = "/update_config.json";
    static constexpr const char* TEMP_FIRMWARE_FILE = "/firmware_temp.bin";
    static constexpr unsigned long FIRST_CHECK_DELAY = 60000;  // 60 секунд
    
    // Зависимости
    SystemState* systemState;
    ProgramExecutor* programExecutor;
    BindingManager* bindingManager;
    UpdateLogger logger;
    
    // Состояние
    UpdateConfig config;
    UpdateState state;
    int progress;
    String lastError;
    unsigned long lastCheckTime;
    unsigned long nextCheckTime;
    uint8_t retryCount;
    
    // Информация о текущем обновлении
    PendingUpdate pendingUpdate;
    
    // Внутренние методы
    bool performUpdateCheck();
    bool downloadFirmware();
    bool verifyFirmware(const uint8_t* data, size_t size);
    bool installFirmware(const uint8_t* data, size_t size);
    bool shouldInstallUpdate(bool isRequired);
    bool isProgramActive();
    void scheduleNextCheck();
    void handleError(const String& error);
    int versionCompare(const String& v1, const String& v2);
};

#endif // AUTO_UPDATE_CLIENT_H
