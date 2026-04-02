/**
 * Константы системы согласно ТЗ
 * 
 * @file constants.h
 * @version 1.0
 */

#ifndef CONSTANTS_H
#define CONSTANTS_H

#include <Arduino.h>

// =====================================================
// СИСТЕМНЫЕ КОНСТАНТЫ
// =====================================================
constexpr uint8_t MAX_SERVO_ANGLE = 90;              // Максимальный угол сервопривода
constexpr uint8_t MAX_TEMP_LIMIT = 100;              // Максимальная безопасная температура
constexpr uint32_t MIN_HEATER_OFF_TIME = 5000;       // Минимальное время выключения ТЭНа (мс)
constexpr uint16_t REQUEST_BODY_BUFFER_SIZE = 2048;  // Размер буфера запросов
constexpr uint8_t MAX_STAGES_PER_PROGRAM = 10;       // Максимум этапов в программе

// =====================================================
// СЕТЕВЫЕ КОНСТАНТЫ
// =====================================================
constexpr uint16_t WEB_SERVER_PORT = 80;             // Порт веб-сервера
constexpr uint32_t WIFI_CONNECT_TIMEOUT = 15000;     // Таймаут подключения к WiFi (мс)
constexpr uint32_t CLOUD_SEND_INTERVAL = 60000;      // Интервал отправки данных в облако (мс)
constexpr uint32_t CLOUD_RETRY_INTERVAL = 30000;     // Интервал повтора при ошибке (мс)
constexpr uint8_t MAX_CLOUD_RETRIES = 5;             // Максимум попыток отправки в облако

// Облачные URL
constexpr const char* CLOUD_BASE_URL = "https://crcerror.ru";  // Базовый URL облачного сервиса

// =====================================================
// ДАТЧИКИ
// =====================================================
constexpr uint32_t SENSOR_READ_INTERVAL = 2000;      // Интервал чтения датчиков (мс)
constexpr uint8_t SENSOR_SAMPLES = 8;                // Количество измерений для усреднения
constexpr uint8_t SENSOR_SAMPLE_DELAY = 2;           // Задержка между измерениями (мс)
constexpr uint8_t MAX_SENSOR_ERRORS = 3;             // Максимум ошибок датчика подряд

// Диапазоны NTC датчиков
constexpr float NTC_MIN_TEMP = -50.0f;               // Минимальная температура NTC
constexpr float NTC_MAX_TEMP = 200.0f;               // Максимальная температура NTC
constexpr float NTC_MIN_VOLTAGE = 0.01f;             // Минимальное напряжение NTC
constexpr float NTC_MAX_VOLTAGE = 3.62f;             // Максимальное напряжение NTC

// Диапазоны BME280
constexpr float BME280_MIN_TEMP = -40.0f;            // Минимальная температура BME280
constexpr float BME280_MAX_TEMP = 85.0f;             // Максимальная температура BME280
constexpr float BME280_MIN_HUMIDITY = 0.0f;          // Минимальная влажность
constexpr float BME280_MAX_HUMIDITY = 100.0f;        // Максимальная влажность

// =====================================================
// ИСПОЛНИТЕЛЬНЫЕ МЕХАНИЗМЫ
// =====================================================
constexpr uint8_t DEFAULT_HYSTERESIS = 2;            // Гистерезис по умолчанию (°C)
constexpr uint32_t SERVO_MOVE_DELAY = 100;           // Задержка движения сервопривода (мс)
constexpr uint8_t SERVO_STEP_ANGLE = 5;              // Шаг движения сервопривода (градусы)
constexpr uint32_t SMOKE_GENERATOR_TIMEOUT = 600000; // Таймаут дымогенератора (10 мин)

// ШИМ константы
constexpr uint32_t PWM_FREQUENCY = 1000;             // Частота ШИМ (Гц)
constexpr uint8_t PWM_RESOLUTION = 8;                // Разрешение ШИМ (бит)

// =====================================================
// ДИСПЛЕЙ И ИНТЕРФЕЙС
// =====================================================
constexpr uint32_t DISPLAY_UPDATE_INTERVAL = 500;    // Интервал обновления дисплея (мс)
constexpr uint32_t DISPLAY_TIMEOUT = 30000;          // Таймаут подсветки дисплея (мс)
constexpr uint32_t DISPLAY_DIM_TIME = 5000;          // Время затемнения перед выключением (мс)
constexpr uint8_t DISPLAY_DIM_BRIGHTNESS = 30;       // Яркость при затемнении (%)

// Кнопки
constexpr uint32_t BUTTON_DEBOUNCE_TIME = 50;        // Антидребезг кнопок (мс)
constexpr uint32_t BUTTON_LONG_PRESS_TIME = 1000;    // Время долгого нажатия (мс)
constexpr uint32_t BUTTON_DOUBLE_CLICK_TIME = 300;   // Время двойного клика (мс)

// =====================================================
// ФАЙЛОВАЯ СИСТЕМА
// =====================================================
constexpr const char* WIFI_CONFIG_FILE = "/wifi.json";
constexpr const char* DEVICE_CONFIG_FILE = "/device.json";
constexpr const char* SITE_CONFIG_FILE = "/site.json";
constexpr const char* SETTINGS_CONFIG_FILE = "/config/settings.json";
constexpr const char* PROGRAMS_DIR = "/programs";

// =====================================================
// ТОЧКА ДОСТУПА (AP MODE)
// =====================================================
constexpr const char* AP_SSID_PREFIX = "SmartSmoker_AP_";
constexpr const char* AP_PASSWORD = "12345678";       // Пароль точки доступа
constexpr const char* AP_IP = "192.168.4.1";         // IP адрес в режиме AP
constexpr const char* AP_GATEWAY = "192.168.4.1";    // Шлюз в режиме AP
constexpr const char* AP_SUBNET = "255.255.255.0";   // Маска подсети в режиме AP

// =====================================================
// ОБЛАЧНЫЙ СЕРВИС (API endpoints)
// =====================================================
// CLOUD_BASE_URL уже определён в разделе "СЕТЕВЫЕ КОНСТАНТЫ" (строка 32)
constexpr const char* CLOUD_API_SEND_DATA = "/api/send-data.php";
constexpr const char* CLOUD_API_BIND_DEVICE = "/api/bind-device.php";
constexpr const char* CLOUD_API_PROGRAM_RECEIVED = "/api/program-received.php";
constexpr const char* CLOUD_API_GET_PROGRAMS = "/api/get-programs.php";
constexpr const char* CLOUD_API_GET_STATE = "/api/get-state.php";

// =====================================================
// АВТОМАТ РОЗЖИГА ДЫМОГЕНЕРАТОРА
// =====================================================
constexpr bool     IGNITER_ENABLED          = true;  // Аппаратный автомат розжига подключён
constexpr uint32_t IGNITER_SIGNAL_WINDOW    = 5000;  // Окно анализа сигналов (мс)
constexpr uint32_t IGNITER_CMD_PULSE_MS     = 500;   // Длительность командного импульса (мс)

// Декодирование ответа автомата розжига:
// 1 переход 0→1 за окно  → успешный розжиг (горелка отработала)
// 2 перехода 0→1 за окно → газ закончился в баллоне
// 3 перехода 0→1 за окно → газ замёрз
constexpr uint8_t IGNITER_RESULT_SUCCESS    = 1;     // Розжиг успешен
constexpr uint8_t IGNITER_RESULT_NO_GAS     = 2;     // Газ закончился
constexpr uint8_t IGNITER_RESULT_GAS_FROZEN = 3;     // Газ замёрз

// Retry-логика розжига
constexpr bool    IGNITER_RETRY_ENABLED     = true;  // Включить повторные попытки
constexpr uint8_t IGNITER_MAX_RETRIES       = 2;     // Максимум повторных попыток при TIMEOUT

// Коды ошибок аварийной остановки по причине розжига
constexpr const char* ERR_IGNITER_NO_GAS      = "Газ закончился в баллоне";
constexpr const char* ERR_IGNITER_GAS_FROZEN  = "Газ замёрз";
constexpr const char* ERR_IGNITER_TIMEOUT     = "Розжиг отменён оператором";
constexpr const char* ERR_IGNITER_UNEXPECTED  = "Неожиданный сигнал автомата розжига";

// Длительность экрана "Розжиг успешен" (мс)
constexpr uint32_t IGNITER_SUCCESS_DISPLAY_MS = 2000;

// =====================================================
// БЕЗОПАСНОСТЬ
// =====================================================
constexpr uint32_t EMERGENCY_COOLDOWN = 5000;        // Время между аварийными остановками (мс)
constexpr uint32_t WATCHDOG_TIMEOUT = 30000;         // Таймаут watchdog (мс)
constexpr uint8_t MAX_FAILED_REQUESTS = 10;          // Максимум неудачных запросов подряд

// =====================================================
// ОТЛАДКА
// =====================================================
#define DEBUG_SERIAL_ENABLED 1                        // Включить отладку через Serial
#define DEBUG_SENSORS 0                               // Отладка датчиков
#define DEBUG_NETWORK 0                               // Отладка сети
#define DEBUG_CLOUD 1                                 // Отладка облака
#define DEBUG_PROGRAMS 1                              // Отладка программ

// =====================================================
// ТЕЛЕМЕТРИЯ
// =====================================================
constexpr uint8_t TELEMETRY_BUFFER_MAX_RECORDS = 120; // Максимум записей в буфере телеметрии
constexpr size_t  TELEMETRY_BUFFER_MAX_SIZE    = 100; // Жёсткий лимит буфера (FIFO-вытеснение)
constexpr uint8_t TELEMETRY_FLUSH_BATCH_SIZE   = 10;  // Максимум записей за один сброс

// =====================================================
// ВЕРСИЯ ПРОШИВКИ
// =====================================================
constexpr const char* FIRMWARE_VERSION = "2.2.3";
constexpr const char* BUILD_DATE = __DATE__ " " __TIME__;

#endif // CONSTANTS_H