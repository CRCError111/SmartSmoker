/**
 * Глобальное состояние системы согласно ТЗ
 * 
 * @file SystemState.h
 * @version 1.0
 */

#ifndef SYSTEM_STATE_H
#define SYSTEM_STATE_H

#include <Arduino.h>
#include <memory>
#include <vector>
#include <time.h>
#include "constants.h"
#include "UUIDGenerator.h"

// Предварительное объявление
struct SmokingProgram;

/**
 * Состояние меню настроек (конечный автомат навигации)
 */
struct SettingsMenuState {
    enum class Level : uint8_t {
        SECTION_LIST,   // верхний уровень: список разделов
        ITEM_LIST,      // список пунктов раздела
        NUMERIC_EDIT,   // редактирование числового значения
        DATETIME_EDIT,  // ввод даты/времени (поле за полем)
        CONFIRM_DIALOG  // диалог подтверждения (Да/Нет)
    };

    Level    level          = Level::SECTION_LIST;
    uint8_t  sectionIndex   = 0;   // 0–3
    uint8_t  itemIndex      = 0;   // индекс пункта внутри раздела
    int32_t  editValue      = 0;   // временное значение при редактировании
    int32_t  editMin        = 0;
    int32_t  editMax        = 0;
    int32_t  editStep       = 1;
    uint8_t  dtField        = 0;   // 0=год,1=мес,2=день,3=час,4=мин
    struct tm dtBuf         = {};  // буфер ввода даты/времени
    bool     confirmYes     = false; // выбор в диалоге подтверждения
    uint8_t  scrollOffset   = 0;   // смещение прокрутки списка
};

/**
 * Класс для хранения глобального состояния системы
 */
class SystemState {
public:
    // =====================================================
    // ПЕРЕЧИСЛЕНИЯ СОСТОЯНИЙ
    // =====================================================
    
    enum class NetworkMode { 
        AP,     // Режим точки доступа
        STA     // Режим клиента WiFi
    };
    
    enum class Mode { 
        IDLE,                       // Ожидание
        RUNNING,                    // Выполнение программы
        PAUSED,                     // Пауза программы
        WAITING_SMOKE_IGNITION,     // Ожидание розжига дымогенератора
        EMERGENCY_STOP              // Аварийная остановка
    };
    
    enum class DisplayMode { 
        MAIN_SCREEN,        // Основной экран с параметрами
        PROGRAM_SELECTION,  // Выбор программы из списка
        PROGRAM_RUNNING,    // Выполнение программы
        WIFI_SETUP,         // Настройка WiFi
        SETTINGS_MENU,      // Меню настроек
        BIND_DEVICE,        // Экран привязки к облаку
        SYSTEM_INFO,        // Экран информации о системе
        EMERGENCY_STOP      // Экран аварийной остановки
    };
    
    // =====================================================
    // СЕТЕВЫЕ ПАРАМЕТРЫ
    // =====================================================
    NetworkMode networkMode = NetworkMode::AP;
    String ssid = "";
    String wifiPassword = "";
    String ip = "0.0.0.0";
    bool wifiConnected = false;
    int wifiSignalStrength = 0;
    String apPassword = "";         // Пароль точки доступа (генерируется из MAC)
    String webServerPassword = "";  // Пароль локального веб-сервера (C-04)
    
    // =====================================================
    // ДАННЫЕ ДЛЯ ДОСТУПА К САЙТУ
    // =====================================================
    String cloudLogin = "";         // Логин на crcerror.ru
    String cloudPassword = "";      // Пароль на crcerror.ru
    String cloudUrl = "https://crcerror.ru";
    bool cloudConnected = false;
    unsigned long lastCloudSync = 0;
    
    // =====================================================
    // ИНФОРМАЦИЯ ОБ УСТРОЙСТВЕ
    // =====================================================
    String deviceId = "";           // Уникальный идентификатор с сайта
    String apiToken = "";           // Permanent API token для аутентификации (не истекает)
    String deviceName = "Smart Smoker";
    String firmwareVersion = FIRMWARE_VERSION;
    bool deviceBound = false;       // Привязано ли устройство к сайту
    int syncInterval = 60;          // Интервал отправки телеметрии (секунды)
    int programSyncInterval = 300;  // Интервал синхронизации программ (секунды)
    unsigned long lastProgramSync = 0; // Время последней синхронизации программ
    String serverTime = "";         // Серверное время для инкрементальной синхронизации (ТЗ п.4.1.2)
    
    // =====================================================
    // СТАТУС ПРИВЯЗКИ УСТРОЙСТВА
    // =====================================================
    enum class BindStatus {
        PENDING,    // Ожидание привязки
        SUCCESS,    // Успешная привязка
        FAILURE     // Ошибка привязки
    };
    
    BindStatus lastBindStatus = BindStatus::PENDING;  // Статус последней попытки привязки
    unsigned long lastBindAttemptTime = 0;            // Время последней попытки привязки (millis)
    String lastBindErrorMessage = "";                 // Сообщение об ошибке при неудачной привязке
    String lastStatusMessage = "";                    // Последнее сообщение для отображения пользователю
    
    // =====================================================
    // RETRY ЛОГИКА ДЛЯ ТЕЛЕМЕТРИИ
    // =====================================================
    uint8_t telemetryRetryCount = 0;    // Счетчик неудачных попыток отправки телеметрии
    uint32_t nextTelemetryRetry = 0;    // Время следующей попытки отправки (millis)
    
    // =====================================================
    // РЕЖИМ РАБОТЫ
    // =====================================================
    Mode mode = Mode::IDLE;
    DisplayMode displayMode = DisplayMode::MAIN_SCREEN;
    
    // =====================================================
    // ОЖИДАНИЕ РОЗЖИГА ДЫМОГЕНЕРАТОРА
    // =====================================================
    unsigned long smokeIgnitionStartTime = 0;   // millis() когда вошли в WAITING_SMOKE_IGNITION
    float smokeBaselineTemp = NAN;              // Базовая температура дыма при старте этапа
    bool smokeIgnitionConfirmed = false;        // Подтверждение розжига (авто или вручную)
    unsigned long smokePauseStartTime = 0;      // millis() когда перешли в паузу ожидания
    bool smokePauseActive = false;              // Флаг: находимся в паузе ожидания розжига
    unsigned long lastSmokeNotificationTime = 0; // millis() последнего уведомления пользователю

    // Состояние аппаратного автомата розжига (IgniterManager)
    uint8_t  igniterAttempt      = 0;   // Номер текущей попытки (1-based, 0 = не запускался)
    uint8_t  igniterLastResult   = 0;   // Последний IgniterResult (для телеметрии)
    uint32_t igniterStepStartMs  = 0;   // millis() старта этапа с дымогенератором
    uint32_t igniterDoneMs       = 0;   // millis() завершения розжига
    bool     igniterDone         = false; // Флаг: есть несохранённый результат для телеметрии

    // Состояние экрана розжига для DisplayManager
    enum class IgniterDisplayState : uint8_t {
        NONE,       // Не показывать экран розжига
        ACTIVE,     // Розжиг в процессе
        SUCCESS,    // Успех (показывать IGNITER_SUCCESS_DISPLAY_MS)
        TIMEOUT,    // Ожидание ручного подтверждения
    };
    IgniterDisplayState igniterDisplayState = IgniterDisplayState::NONE;
    uint32_t igniterSuccessDisplayStart = 0; // millis() начала показа экрана SUCCESS
    
    // Константы (можно вынести в config)
    static constexpr float SMOKE_DETECTION_DELTA = 10.0f;  // +10°C для авто-детекции
    static constexpr unsigned long SMOKE_IGNITION_TIMEOUT_MS = 10UL * 60 * 1000;  // 10 минут
    static constexpr unsigned long SMOKE_PAUSE_MAX_MS = 30UL * 60 * 1000;         // 30 минут
    static constexpr unsigned long SMOKE_NOTIFY_INTERVAL_MS = 10UL * 60 * 1000;   // каждые 10 мин
    
    // =====================================================
    // ТЕКУЩАЯ ПРОГРАММА
    // =====================================================
    std::shared_ptr<SmokingProgram> currentProgram = nullptr;
    size_t currentStepIndex = 0;
    unsigned long stepStartTime = 0;
    unsigned long programStartTime = 0;
    unsigned long lastInteraction = 0;
    bool waitingForTemp = false;
    String currentRunId = "";       // UUID для отслеживания запусков
    String pendingProgramStart = ""; // Имя программы, которую нужно запустить по команде из облака
    
    // =====================================================
    // ПАРАМЕТРЫ ДАТЧИКОВ
    // =====================================================
    float tempChamber = NAN;        // Температура в камере (BME280)
    float tempSmoke = NAN;          // Температура дыма (NTC)
    float tempProduct = NAN;        // Температура продукта (NTC)
    float humidity = NAN;           // Влажность в камере (BME280)
    
    // Статистика датчиков
    unsigned long lastSensorUpdate = 0;
    uint8_t sensorErrorCount = 0;
    
    // =====================================================
    // СОСТОЯНИЕ ИСПОЛНИТЕЛЬНЫХ МЕХАНИЗМОВ
    // =====================================================
    bool heaterOn = false;          // Состояние ТЭНа
    int smokePWM = 0;              // ШИМ дымогенератора (0-100%)
    bool fanInternalOn = false;     // Вентилятор в камере
    bool fanInjectionOn = false;    // Вентилятор подачи воздуха
    int damperPosition = 90;        // Позиция заслонки (0-90 градусов)
    
    // Защита ТЭНа
    unsigned long lastHeaterChange = 0;
    bool heaterCooldown = false;
    
    // =====================================================
    // ЗАЩИТА ОТ АВАРИЙ
    // =====================================================
    bool emergencyStop = false;
    bool sensorError = false;
    unsigned long lastEmergencyTime = 0;
    String emergencyReason = "";
    
    // =====================================================
    // OLED И КНОПКИ
    // =====================================================
    bool displayBacklight = true;
    bool buttonPressDetected = false;
    unsigned long lastButtonPress = 0;
    uint8_t displayBrightness = 100;    // Яркость дисплея (0-100%)
    bool displaySleeping = false;
    int runningScreenIndex = 0;         // Индекс экрана во время выполнения программы (0-2)
    bool cloudSyncRequested = false;    // Флаг запроса синхронизации программ из облака
    
    // Навигация в меню
    int menuIndex = 0;
    int maxMenuItems = 0;
    bool inSubMenu = false;
    
    // Состояние меню настроек
    SettingsMenuState settingsMenu;
    
    // =====================================================
    // СТАТИСТИКА И МОНИТОРИНГ
    // =====================================================
    unsigned long uptime = 0;
    unsigned long totalRunTime = 0;     // Общее время работы программ
    uint32_t completedPrograms = 0;     // Количество завершенных программ
    uint32_t emergencyStops = 0;        // Количество аварийных остановок
    
    // Сетевая статистика
    uint32_t cloudRequestsSent = 0;
    uint32_t cloudRequestsFailed = 0;
    unsigned long lastSuccessfulCloudRequest = 0;
    
    // =====================================================
    // ПОДТВЕРЖДЕНИЕ КОМАНД (ТЗ п.2.4.2)
    // =====================================================
    // Список ID команд, выполненных ESP32 и ожидающих подтверждения серверу
    std::vector<int> executedCommandIds;
    uint8_t authErrorCount = 0;  // Счётчик ошибок 401/404 для Fix 5
    
    // =====================================================
    // МЕТОДЫ
    // =====================================================
    
    /**
     * Сброс состояния к начальным значениям
     */
    void reset() {
        mode = Mode::IDLE;
        displayMode = DisplayMode::MAIN_SCREEN;
        currentProgram = nullptr;
        currentStepIndex = 0;
        stepStartTime = 0;
        programStartTime = 0;
        waitingForTemp = false;
        currentRunId = "";
        
        // Сброс исполнительных механизмов
        heaterOn = false;
        smokePWM = 0;
        fanInternalOn = false;
        fanInjectionOn = false;
        damperPosition = 90;
        
        // Сброс аварийного состояния
        emergencyStop = false;
        emergencyReason = "";
        
        // Сброс retry логики
        telemetryRetryCount = 0;
        nextTelemetryRetry = 0;
        
        // Сброс состояния ожидания розжига
        smokeIgnitionStartTime = 0;
        smokeBaselineTemp = NAN;
        smokeIgnitionConfirmed = false;
        smokePauseStartTime = 0;
        smokePauseActive = false;
        lastSmokeNotificationTime = 0;

        // Сброс состояния аппаратного розжига
        igniterAttempt      = 0;
        igniterLastResult   = 0;
        igniterStepStartMs  = 0;
        igniterDoneMs       = 0;
        igniterDone         = false;
        igniterDisplayState = IgniterDisplayState::NONE;
        igniterSuccessDisplayStart = 0;
        
        lastInteraction = millis();
    }
    
    /**
     * Проверка валидности данных датчиков
     */
    bool hasValidSensorData() const {
        return !isnan(tempChamber) && !isnan(humidity) && !sensorError;
    }
    
    /**
     * Получение времени работы программы в секундах
     */
    unsigned long getProgramRunTime() const {
        if (programStartTime == 0) return 0;
        return (millis() - programStartTime) / 1000;
    }
    
    /**
     * Получение времени текущего этапа в секундах
     */
    unsigned long getStepRunTime() const {
        if (stepStartTime == 0) return 0;
        return (millis() - stepStartTime) / 1000;
    }
    
    /**
     * Проверка готовности к работе с облаком
     */
    bool isCloudReady() const {
        return networkMode == NetworkMode::STA && 
               wifiConnected && 
               !deviceId.isEmpty() && 
               deviceBound;
    }
    
    /**
     * Обновление времени последнего взаимодействия
     */
    void updateInteraction() {
        lastInteraction = millis();
        buttonPressDetected = true;
        lastButtonPress = millis();
    }
    
    /**
     * Генерация уникального Run ID для программы
     */
    void generateRunId() {
        currentRunId = generateUUID();
    }
    
    /**
     * Получение строкового представления режима сети
     */
    String getNetworkModeString() const {
        return (networkMode == NetworkMode::AP) ? "AP" : "STA";
    }
    
    /**
     * Получение строкового представления системного режима
     */
    String getSystemModeString() const {
        switch (mode) {
            case Mode::IDLE: return "IDLE";
            case Mode::RUNNING: return "RUNNING";
            case Mode::PAUSED: return "PAUSED";
            case Mode::WAITING_SMOKE_IGNITION: return "WAITING_SMOKE_IGNITION";
            case Mode::EMERGENCY_STOP: return "EMERGENCY_STOP";
            default: return "UNKNOWN";
        }
    }
};

#endif // SYSTEM_STATE_H