#ifndef ROLLBACK_MANAGER_H
#define ROLLBACK_MANAGER_H

#include <Arduino.h>
#include <WiFi.h>
#include <LittleFS.h>
#include <ArduinoJson.h>
#include <esp_ota_ops.h>
#include "UpdateLogger.h"

/**
 * RollbackManager - Управление откатом прошивки
 * 
 * Отвечает за:
 * - Проверку первой загрузки после OTA обновления
 * - Верификацию работоспособности новой прошивки (проверка WiFi)
 * - Откат к предыдущей прошивке при сбое
 * - Отключение автообновлений после отката
 * - Защиту от циклических откатов
 */
class RollbackManager {
public:
    enum class BootStatus {
        FIRST_BOOT,           // Первая загрузка после обновления
        VERIFIED,             // Обновление проверено успешно
        ROLLBACK_REQUIRED,    // Требуется откат
        ROLLBACK_COMPLETE     // Откат выполнен
    };
    
    // Конструктор
    RollbackManager();
    
    // Инициализация
    bool begin();
    
    // Проверка загрузки
    BootStatus checkBootStatus();
    void markBootSuccessful();
    void markBootFailed();
    
    // Операции отката
    bool performRollback();
    bool canRollback() const;
    
    // Статус
    String getCurrentPartition() const;
    String getPreviousPartition() const;
    bool isFirstBootAfterUpdate() const;
    BootStatus getBootStatus() const { return bootStatus; }
    
private:
    static constexpr unsigned long WIFI_VERIFY_TIMEOUT = 60000;  // 60 секунд
    static constexpr int MAX_ROLLBACK_COUNT = 3;
    static constexpr const char* ROLLBACK_COUNT_FILE = "/rollback_count.txt";
    
    BootStatus bootStatus;
    unsigned long bootTime;
    bool wifiConnected;
    UpdateLogger logger;
    
    bool verifyWiFiConnectivity();
    void disableAutoUpdate();
    int getRollbackCount();
    void incrementRollbackCount();
    void resetRollbackCount();
};

#endif // ROLLBACK_MANAGER_H
