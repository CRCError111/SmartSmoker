/**
 * Конфигурация системы SmartSmoker
 * 
 * Управляет включением/отключением функций.
 * Для production все функции должны быть включены.
 * Отключайте только при отладке на ПК без железа.
 */

#ifndef CONFIG_H
#define CONFIG_H

// =====================================================
// PRODUCTION РЕЖИМ — все функции включены
// Раскомментируйте нужные строки ТОЛЬКО для отладки
// =====================================================

// #define DISABLE_OLED_DISPLAY       // Отключить OLED дисплей (только для отладки без железа)
// #define DISABLE_BME280_SENSOR      // Отключить датчик BME280 (только для отладки)
// #define DISABLE_SERVO_CONTROL      // Отключить управление сервоприводом
// #define DISABLE_OTA_UPDATES        // Отключить ArduinoOTA (локальное OTA)
// #define DISABLE_SERVER_OTA         // Отключить OTA с сервера (crcerror.ru)
// #define DISABLE_RECOVERY_SYSTEM    // Отключить систему восстановления после перезагрузки
// #define DISABLE_OFFLINE_BUFFER     // Отключить буфер офлайн данных
// #define DISABLE_DETAILED_LOGGING   // Отключить детальное логирование

// =====================================================
// PARTITION SCHEME: huge_app (3MB APP + 1MB SPIFFS)
// FQBN: esp32:esp32:esp32:PartitionScheme=huge_app
// =====================================================

// =====================================================
// OTA UPDATE CONFIGURATION
// =====================================================

// Firmware version (X.Y.Z format)
#define FIRMWARE_VERSION "2.1.0"

// Update check intervals (seconds)
#define DEFAULT_CHECK_INTERVAL 3600    // 1 hour
#define MIN_CHECK_INTERVAL 300          // 5 minutes
#define MAX_CHECK_INTERVAL 86400        // 24 hours

// Update retry configuration
#define UPDATE_RETRY_DELAY 300          // 5 minutes
#define MAX_UPDATE_RETRIES 3            // Maximum retry attempts

// WiFi verification timeout after update (milliseconds)
#define WIFI_VERIFY_TIMEOUT 60000       // 60 seconds

// File paths for OTA system (LittleFS)
#define UPDATE_CONFIG_PATH "/update_config.json"
#define UPDATE_LOG_PATH "/update_log.json"
#define FIRMWARE_TEMP_PATH "/firmware_temp.bin"

#endif // CONFIG_H
