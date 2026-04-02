/**
 * Smart Smoker - ИСПРАВЛЕННАЯ ВЕРСИЯ
 * 
 * Основной файл проекта согласно ТЗ
 * 
 * @version 2.0
 * @author Smart Smoker Team
 * @date 2026-02-12
 * 
 * ИСПРАВЛЕНИЯ:
 * - Добавлена передача NetworkManager в WebServerManager
 * - Исправлена инициализация всех модулей
 * - Добавлено управление программами в веб-интерфейсе
 */

// Увеличиваем стек loopTask: mbedTLS handshake требует ~12-15KB,
// дефолтные 8KB вызывают stack overflow → "Stack canary watchpoint triggered"
SET_LOOP_TASK_STACK_SIZE(16384);

// =====================================================
// ПОДКЛЮЧЕНИЕ БИБЛИОТЕК (согласно ТЗ)
// =====================================================
#include <WiFi.h>
#include <WebServer.h>
#include <LittleFS.h>
#include <ArduinoJson.h>
#define LGFX_USE_V1
#include <LovyanGFX.hpp>
#include <Adafruit_BME280.h>
#include <driver/adc.h>
#include <driver/ledc.h>
#include <HTTPClient.h>
#include <ESP32Servo.h>
#include <esp_task_wdt.h>

// =====================================================
// ПОДКЛЮЧЕНИЕ ЛОКАЛЬНЫХ МОДУЛЕЙ
// =====================================================
#include "pins.h"
#include "constants.h"
#include "SystemState.h"
#include "ProgramStructures.h"
#include "SensorManager.h"
#include "ActuatorManager.h"
#include "DisplayManager.h"
#include "ButtonManager.h"
#include "SmartSmokerNetworkManager.h"
#include "CloudManager.h"
#include "CloudAPI.h"
#include "ProgramManager.h"
#include "StorageManager.h"
#include "WebServerManager.h"
#include "WebStyles.h"
#include "OTAManager.h"
#include "ServerOTAManager.h"
#include "UUIDGenerator.h"
#include "BindingManager.h"
#include "AutoUpdateClient.h"
#include "RollbackManager.h"
#include "UpdateLogger.h"
#include "DeviceIdentity.h"
#include "IgniterManager.h"
#include "SettingsManager.h"

// =====================================================
// ГЛОБАЛЬНЫЕ ОБЪЕКТЫ
// =====================================================
DeviceIdentity deviceIdentity;
SystemState systemState;
SensorManager sensorManager;
ActuatorManager actuatorManager;
DisplayManager displayManager;
ButtonManager buttonManager;
SmartSmokerNetworkManager networkManager;
CloudManager cloudManager;
ProgramManager programManager;
WebServerManager webServerManager;
StorageManager storageManager;
OTAManager otaManager;
ServerOTAManager serverOtaManager;
BindingManager bindingManager;
AutoUpdateClient autoUpdateClient;
RollbackManager rollbackManager;
IgniterManager igniterManager;

// =====================================================
// ФУНКЦИЯ ИНИЦИАЛИЗАЦИИ DEVICE_ID
// =====================================================
// SETUP - ИНИЦИАЛИЗАЦИЯ СИСТЕМЫ
// =====================================================
void setup() {
  // Инициализация Serial для отладки
  Serial.begin(115200);
  delay(1000); // Даем время на инициализацию Serial
  
  // Инициализация watchdog ПЕРВЫМ — до любых долгих операций (WiFi, TLS, NTP).
  // Системный TWDT в ESP32 Arduino Core 3.x включён по умолчанию (~5с),
  // перенастраиваем на 30с и регистрируем loopTask, чтобы setup() не крэшился.
  #if ESP_ARDUINO_VERSION_MAJOR >= 3
    esp_task_wdt_config_t wdt_config = { .timeout_ms = WATCHDOG_TIMEOUT, .idle_core_mask = 0, .trigger_panic = true };
    esp_err_t wdt_err = esp_task_wdt_reconfigure(&wdt_config);
  #else
    esp_err_t wdt_err = esp_task_wdt_init(WATCHDOG_TIMEOUT / 1000, true);
  #endif
  if (wdt_err != ESP_OK) { Serial.printf("[ERROR] Watchdog init failed: %d\n", wdt_err); }
  esp_task_wdt_add(NULL);
  
  Serial.println("\n\n");
  Serial.println("========================================");
  Serial.println("   Smart Smoker " + String(FIRMWARE_VERSION));
  Serial.println("========================================");
  Serial.println();
  
  // Инициализация DeviceIdentity ПЕРВОЙ (до всего остального)
  if (!deviceIdentity.begin()) {
    Serial.println("[ERROR] DeviceIdentity initialization failed!");
  } else {
    systemState.deviceId = deviceIdentity.getDeviceId();
    Serial.printf("[DEVICE] Device ID: %s\n", systemState.deviceId.c_str());
  }
  
  // Инициализация SettingsManager ПЕРВЫМ (до всех остальных менеджеров)
  settingsManager.begin();
  
  // C-04: Передаём пароль веб-сервера из NVS в SystemState
  systemState.webServerPassword = settingsManager.webServerPassword;

  // Инициализация хранилища и загрузка настроек
  storageManager.init();
  storageManager.loadSettings(systemState);
  
  // Инициализация RollbackManager РАНО (до всего остального)
  if (!rollbackManager.begin()) {
    Serial.println("[WARN] Rollback verification in progress...");
    // Если rollback нужен, устройство перезагрузится автоматически
  }
  
  // Инициализация BindingManager
  if (!bindingManager.begin()) {
    Serial.println("[ERROR] BindingManager initialization failed!");
  } else {
    bindingManager.setSystemState(&systemState);
    bindingManager.setProgramManager(&programManager);
    // Device ID уже установлен из DeviceIdentity
    bindingManager.setUUID(systemState.deviceId);
    
    if (!storageManager.loadCloudSettings(systemState)) {
      // нет сохранённых настроек — устройство не привязано
    }
    
    // Синхронизация состояния привязки
    bool needsReconciliation = false;
    
    if (bindingManager.isBound()) {
      if (!systemState.deviceBound || 
          systemState.apiToken != bindingManager.getAPIToken()) {
        systemState.deviceBound = true;
        systemState.apiToken = bindingManager.getAPIToken();
        systemState.apiToken = bindingManager.getAPIToken();
        needsReconciliation = true;
      }
    } else {
      if (systemState.deviceBound) {
        systemState.deviceBound = false;
        needsReconciliation = true;
      }
    }
    
    if (needsReconciliation) {
      if (!storageManager.saveCloudSettings(systemState)) {
        Serial.println("[ERROR] Failed to save reconciled binding state");
      }
    }
  }
  
  // Инициализация AutoUpdateClient
  ProgramExecutor programExecutor(&storageManager);
  if (!autoUpdateClient.begin(&systemState, &programExecutor, &bindingManager)) {
    Serial.println("[ERROR] AutoUpdateClient initialization failed!");
  } else {
    autoUpdateClient.loadConfig();
    bindingManager.setAutoUpdateClient(&autoUpdateClient);
    Serial.println("[INFO] AutoUpdateClient initialized successfully");
    
    // Проверка обновлений при загрузке (если устройство привязано и подключено к WiFi)
    if (systemState.networkMode == SystemState::NetworkMode::STA && 
        systemState.wifiConnected && 
        bindingManager.isBound()) {
      Serial.println("[INFO] Checking for updates on boot...");
      autoUpdateClient.checkForUpdateOnly();
    }
  }
  
  // Инициализация дисплея
  Serial.println("[DIAG] displayManager.init...");
  #ifndef DISABLE_OLED_DISPLAY
  displayManager.init();
  displayManager.showBootScreen();
  #endif
  
  // Инициализация кнопок
  Serial.println("[DIAG] buttonManager.init...");
  buttonManager.init();
  
  // Инициализация датчиков
  Serial.println("[DIAG] sensorManager.init...");
  sensorManager.init();
  
  // Инициализация исполнительных механизмов
  Serial.println("[DIAG] actuatorManager.init...");
  actuatorManager.init();
  
  // Инициализация сети
  Serial.println("[DIAG] networkManager.init...");
  esp_task_wdt_reset();  // WiFi connect может занять время
  networkManager.init(systemState);
  
  // Автоматическая проверка интернета при запуске (Task 3.12.2)
  // Проверка интернета при переключении в режим STA (Task 3.12.1)
  if (systemState.networkMode == SystemState::NetworkMode::STA && systemState.wifiConnected) {
    Serial.println("[DIAG] configTime...");
    configTime(3 * 3600, 0, "pool.ntp.org", "time.nist.gov");
    Serial.println("[DIAG] getLocalTime...");
    esp_task_wdt_reset();  // NTP sync может занять время
    struct tm timeinfo;
    if (getLocalTime(&timeinfo, 3000)) {
      Serial.printf("[DIAG] NTP time: %04d-%02d-%02d %02d:%02d:%02d\n",
                    timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday,
                    timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec);
    } else {
      Serial.println("[WARN] NTP sync failed — TLS может не работать (проверка сертификата требует правильного времени)");
    }
    getLocalTime(&timeinfo, 3000);  // 3 сек макс
    
    // Пропускаем checkInternetAccess при старте — TLS handshake слишком тяжёлый.
    // Интернет проверится при первом облачном запросе.
    Serial.println("[DIAG] Skipping internet check at boot (deferred)");
    if (bindingManager.isBound()) {
      bindingManager.startFilePolling();
    }
  }
  
  // Инициализация облачного менеджера
  Serial.println("[DIAG] cloudManager.init...");
  cloudManager.init(systemState, &storageManager);
  
  // Инициализация веб-сервера
  Serial.println("[DIAG] webServerManager.init...");
  webServerManager.init(systemState, storageManager, programManager, networkManager, cloudManager, bindingManager);
  webServerManager.setAutoUpdateClient(&autoUpdateClient);
  
  // Инициализация OTA (только в режиме STA)
  if (systemState.networkMode == SystemState::NetworkMode::STA && systemState.wifiConnected) {
    #ifndef DISABLE_OTA_UPDATES
    Serial.println("[DIAG] otaManager.init...");
    otaManager.init(systemState);
    #endif
    
    #ifndef DISABLE_SERVER_OTA
    Serial.println("[DIAG] serverOtaManager.init...");
    String serverUrl = "https://crcerror.ru";
    String deviceId = systemState.deviceId;
    serverOtaManager.init(serverUrl, deviceId, FIRMWARE_VERSION, systemState.apiToken);
    #endif
  }
  
  // Список файлов на LittleFS (только при наличии ошибок)
  Serial.println("[DIAG] listLocalFiles...");
  std::vector<FileInfo> files;
  cloudManager.listLocalFiles(systemState, storageManager, files);
  
  // Инициализация менеджера программ
  Serial.println("[DIAG] programManager.init...");
  programManager.init(storageManager);

  // Инициализация автомата розжига
  igniterManager.begin();
  
  // Восстановление состояния программы после перезагрузки отключено
  
  systemState.lastInteraction = millis();
  #ifndef DISABLE_OLED_DISPLAY
  displayManager.updateDisplay(systemState);
  #endif
  
  // Setup complete — no INFO log per production rules (ТЗ п.9.3)
}

// =====================================================
// LOOP - ОСНОВНОЙ ЦИКЛ
// =====================================================
void loop() {
  esp_task_wdt_reset();
  unsigned long currentTime = millis();
  
  // Обработка OTA (приоритет)
  #ifndef DISABLE_OTA_UPDATES
  if (otaManager.isEnabled()) {
    otaManager.handle();
    
    // Если идёт обновление, показываем прогресс и пропускаем остальное
    if (otaManager.isOTAInProgress()) {
      displayManager.showOTAUpdate(otaManager.getOTAProgress(), otaManager.getOTAError());
      delay(100);
      return;
    }
  }
  #endif
  
  // Обработка кнопок (каждые 50мс)
  static unsigned long lastButtonCheck = 0;
  if (currentTime - lastButtonCheck >= 50) {
    buttonManager.update(systemState, displayManager, programManager, actuatorManager);
    lastButtonCheck = currentTime;
  }
  
  // Обновление автомата розжига (каждую итерацию)
  igniterManager.update();

  // Обновление датчиков (каждые 2 секунды)
  static unsigned long lastSensorUpdate = 0;
  if (currentTime - lastSensorUpdate >= 2000) {
    sensorManager.updateSensors(systemState);
    lastSensorUpdate = currentTime;
  }
  
  // Обновление исполнительных механизмов (каждые 100мс)
  static unsigned long lastActuatorUpdate = 0;
  if (currentTime - lastActuatorUpdate >= 100) {
    actuatorManager.update(systemState);
    lastActuatorUpdate = currentTime;
  }
  
  // Обновление дисплея (каждые 500мс)
  #ifndef DISABLE_OLED_DISPLAY
  static unsigned long lastDisplayUpdate = 0;
  if (currentTime - lastDisplayUpdate >= 500) {
    displayManager.updateDisplay(systemState);
    lastDisplayUpdate = currentTime;
  }
  #endif
  
  // Обработка сетевых запросов
  webServerManager.handleClient();
  
  // Обновление BindingManager для периодического опроса файлов (каждые 5 минут)
  bindingManager.updateFilePolling();
  
  // Обновление state machine привязки (неблокирующий polling bind-result.php)
  bindingManager.updateBindingProcess();
  
  // Обновление сетевого менеджера (каждые 10 секунд)
  static unsigned long lastNetworkUpdate = 0;
  if (currentTime - lastNetworkUpdate >= 10000) {
    networkManager.update(systemState);
    lastNetworkUpdate = currentTime;
  }
  
  // Обновление облачного менеджера (каждые 60 секунд)
  static unsigned long lastCloudUpdate = 0;
  if (currentTime - lastCloudUpdate >= (unsigned long)(settingsManager.telemetryInterval) * 1000UL) {
    cloudManager.update(systemState, &programManager);
    
    // Примечание: Автоматическая привязка убрана.
    // Привязка теперь выполняется только по желанию пользователя через веб-интерфейс /bind
    // где пользователь вводит логин и пароль от сайта.
    
    lastCloudUpdate = currentTime;
  }
  
  // Проверка неподтвержденных файлов при старте (однократно)
  static bool pendingFilesChecked = false;
  if (!pendingFilesChecked && systemState.networkMode == SystemState::NetworkMode::STA && 
      systemState.wifiConnected && !systemState.deviceId.isEmpty() && systemState.deviceBound) {
    cloudManager.checkPendingFiles(systemState);
    pendingFilesChecked = true;
  }
  
  // Примечание: Автоматическая привязка при подключении к WiFi убрана.
  // Привязка теперь выполняется только по желанию пользователя через веб-интерфейс /bind
  // где пользователь вводит логин и пароль от сайта.
  
  // Проверка обновлений с сервера (каждые 4 часа)
  #ifndef DISABLE_SERVER_OTA
  static unsigned long lastServerOTACheck = 0;
  if (currentTime - lastServerOTACheck >= 14400000) { // 4 часа
    if (systemState.networkMode == SystemState::NetworkMode::STA && systemState.wifiConnected) {
      Serial.println("🔍 Checking for server updates...");
      serverOtaManager.checkForUpdates();
    }
    lastServerOTACheck = currentTime;
  }
  #endif
  
  if (!systemState.pendingProgramStart.isEmpty()) {
    if (systemState.mode == SystemState::Mode::IDLE) {
      String progName = systemState.pendingProgramStart;
      systemState.pendingProgramStart = "";
      
      if (!programManager.startProgram(progName, systemState)) {
        Serial.printf("[ERROR] Failed to start program via cloud command: %s\n", progName.c_str());
      }
    }
  }
  
  // Обновление программы копчения (каждую секунду)
  static unsigned long lastProgramUpdate = 0;
  if (currentTime - lastProgramUpdate >= 1000) {
    if (systemState.mode == SystemState::Mode::RUNNING ||
        systemState.mode == SystemState::Mode::WAITING_SMOKE_IGNITION ||
        (systemState.mode == SystemState::Mode::PAUSED && systemState.smokePauseActive)) {
      programManager.updateProgram(systemState, actuatorManager, igniterManager);
    }
    lastProgramUpdate = currentTime;
  }
  
  // Периодическое сохранение состояния для восстановления (каждые 30 сек)
  static unsigned long lastRecoverySave = 0;
  if (currentTime - lastRecoverySave >= 30000) {
    if (systemState.mode == SystemState::Mode::RUNNING) {
      storageManager.saveRunningState(systemState);
    }
    lastRecoverySave = currentTime;
  }
  
  // Проверка аварийных ситуаций (каждые 100мс)
  static unsigned long lastSafetyCheck = 0;
  if (currentTime - lastSafetyCheck >= 100) {
    checkSafety();
    lastSafetyCheck = currentTime;
  }
  
  // Энергосбережение дисплея
  displayManager.handlePowerSaving(systemState, currentTime);
  
  // Вывод статистики каждые 4 часа
  static unsigned long lastStatsOutput = 0;
  if (currentTime - lastStatsOutput >= 14400000) {
    printSystemInfo();
    lastStatsOutput = currentTime;
  }
  
  // Обработка Serial команд
  processSerialCommands();
  
}

// =====================================================
// ФУНКЦИИ БЕЗОПАСНОСТИ
// =====================================================
void checkSafety() {
  // Проверки безопасности только при выполнении программы
  if (systemState.mode != SystemState::Mode::RUNNING && 
      systemState.mode != SystemState::Mode::WAITING_SMOKE_IGNITION) {
    return;
  }
  
  if (systemState.tempChamber > MAX_TEMP_LIMIT) {
    Serial.println("[ERROR] Temperature limit exceeded!");
    emergencyStop("Превышение температуры");
    return;
  }
  
  if (systemState.sensorError) {
    Serial.println("[ERROR] Sensor error detected!");
    emergencyStop("Ошибка датчиков");
    return;
  }
  
  static unsigned long lastValidSensorData = millis();
  if (!isnan(systemState.tempChamber)) {
    lastValidSensorData = millis();
  } else if (millis() - lastValidSensorData > 30000) {
    Serial.println("[ERROR] No sensor data for 30 seconds!");
    emergencyStop("Потеря данных датчиков");
    return;
  }
}

void emergencyStop(const String& reason) {
  systemState.emergencyStop = true;
  systemState.mode = SystemState::Mode::EMERGENCY_STOP;
  systemState.displayMode = SystemState::DisplayMode::EMERGENCY_STOP;
  systemState.lastEmergencyTime = millis();
  
  actuatorManager.emergencyStop();
  
  if (systemState.networkMode == SystemState::NetworkMode::STA && 
      !systemState.deviceId.isEmpty() &&
      systemState.deviceBound) {
    cloudManager.sendEmergencyStop(systemState, reason);
  }
  
  displayManager.showEmergencyStop(reason);
  
  Serial.println("[ERROR] EMERGENCY STOP: " + reason);
}

// =====================================================
// ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
// =====================================================
void processSerialCommands() {
  if (Serial.available() > 0) {
    String command = Serial.readStringUntil('\n');
    command.trim();
    
    if (command.length() == 0) return;
    
    Serial.printf("> %s\n", command.c_str());
    
    if (command == "help" || command == "?") {
      Serial.println("Available commands:");
      Serial.println("  help, ?          - Show this help");
      Serial.println("  info             - Show system information");
      Serial.println("  files            - List files on LittleFS");
      Serial.println("  wifi             - Show WiFi status");
      Serial.println("  cloud            - Show cloud status");
      Serial.println("  bind <device_id> - Bind device to cloud");
      Serial.println("  reset            - Reset device binding");
      Serial.println("  reboot           - Reboot ESP32");
      Serial.println("  test             - Test cloud connection");
      Serial.println("  pending          - Check pending files");
    }
    else if (command == "info") {
      printSystemInfo();
    }
    else if (command == "files") {
      Serial.println("📁 Files on ESP32:");
      std::vector<FileInfo> files;
      if (cloudManager.listLocalFiles(systemState, storageManager, files)) {
        for (const auto& f : files) {
          Serial.printf("  - %s (%s, %lu bytes)\n", 
                       f.name.c_str(),
                       f.type.c_str(),
                       (unsigned long)f.size);
        }
        Serial.printf("Total: %d files\n", files.size());
      } else {
        Serial.println("Failed to list files");
      }
    }
    else if (command == "wifi") {
      Serial.println("WiFi Status:");
      Serial.printf("  Mode: %s\n", systemState.networkMode == SystemState::NetworkMode::AP ? "Access Point" : "Station");
      Serial.printf("  SSID: %s\n", systemState.ssid.c_str());
      Serial.printf("  IP: %s\n", systemState.ip.c_str());
      Serial.printf("  Connected: %s\n", systemState.wifiConnected ? "Yes" : "No");
      if (systemState.wifiConnected) {
        Serial.printf("  Signal: %d dBm\n", WiFi.RSSI());
        Serial.printf("  MAC: %s\n", WiFi.macAddress().c_str());
      }
    }
    else if (command == "cloud") {
      Serial.println("Cloud Status:");
      Serial.printf("  Device ID: %s\n", systemState.deviceId.isEmpty() ? "Not bound" : systemState.deviceId.c_str());
      Serial.printf("  Bound: %s\n", systemState.deviceBound ? "Yes" : "No");
      Serial.printf("  Cloud URL: %s\n", systemState.cloudUrl.c_str());
      Serial.printf("  Token: %s...\n", systemState.apiToken.substring(0, 20).c_str());
      Serial.printf("  Connected: %s\n", systemState.cloudConnected ? "Yes" : "No");
    }
    else if (command.startsWith("bind ")) {
      String deviceId = command.substring(5);
      deviceId.trim();
      if (deviceId.length() > 0) {
        Serial.printf("Initiating binding for: %s\n", deviceId.c_str());
        bindingManager.initiateBinding(deviceId, "");
        Serial.println("Binding initiated. Use web interface to complete binding.");
      } else {
        Serial.println("Usage: bind <login>");
      }
    }
    else if (command == "reset") {
      Serial.println("Resetting device binding...");
      systemState.deviceId = "";
      systemState.apiToken = "";
      systemState.deviceBound = false;
      storageManager.saveCloudSettings(systemState);
      Serial.println("Device binding reset");
    }
    else if (command == "reboot") {
      Serial.println("Rebooting ESP32...");
      delay(1000);
      ESP.restart();
    }
    else if (command == "test") {
      Serial.println("Testing cloud connection...");
      if (cloudManager.forceSyncWithCloud(systemState)) {
        Serial.println("Cloud connection test: OK");
      } else {
        Serial.println("Cloud connection test: FAILED");
      }
    }
    else if (command == "pending") {
      Serial.println("Checking pending files...");
      if (cloudManager.checkPendingFiles(systemState)) {
        Serial.println("Pending files check completed");
      } else {
        Serial.println("Failed to check pending files");
      }
    }
    else {
      Serial.printf("Unknown command: %s\n", command.c_str());
      Serial.println("Type 'help' for available commands");
    }
  }
}

void printSystemInfo() {
  #ifdef DEBUG_MODE
  Serial.println();
  Serial.println("========================================");
  Serial.println("   System Information");
  Serial.println("========================================");
  Serial.printf("Free heap: %lu bytes\n", ESP.getFreeHeap());
  Serial.printf("Flash size: %lu bytes\n", ESP.getFlashChipSize());
  Serial.printf("CPU frequency: %lu MHz\n", ESP.getCpuFreqMHz());
  Serial.printf("Uptime: %lu seconds\n", millis() / 1000);
  Serial.println();
  
  Serial.println("Network Status:");
  Serial.printf("  Mode: %s\n", systemState.networkMode == SystemState::NetworkMode::AP ? "Access Point" : "Station");
  Serial.printf("  SSID: %s\n", systemState.ssid.c_str());
  Serial.printf("  IP: %s\n", systemState.ip.c_str());
  
  if (systemState.networkMode == SystemState::NetworkMode::STA) {
    Serial.printf("  Connected: %s\n", systemState.wifiConnected ? "Yes" : "No");
    if (systemState.wifiConnected) {
      Serial.printf("  Signal: %d dBm\n", WiFi.RSSI());
    }
  }
  Serial.println();
  
  Serial.println("Device Status:");
  Serial.printf("  Device ID: %s\n", systemState.deviceId.isEmpty() ? "Not bound" : systemState.deviceId.c_str());
  Serial.printf("  Bound: %s\n", systemState.deviceBound ? "Yes" : "No");
  Serial.printf("  Cloud connected: %s\n", systemState.cloudConnected ? "Yes" : "No");
  Serial.println();
  
  Serial.println("System Mode:");
  Serial.printf("  Mode: ");
  switch(systemState.mode) {
    case SystemState::Mode::IDLE: Serial.println("IDLE"); break;
    case SystemState::Mode::RUNNING: Serial.println("RUNNING"); break;
    case SystemState::Mode::EMERGENCY_STOP: Serial.println("EMERGENCY STOP"); break;
    default: Serial.println("UNKNOWN"); break;
  }
  Serial.println();
  
  Serial.println("Sensors:");
  Serial.printf("  Chamber temp: %.1f°C\n", systemState.tempChamber);
  Serial.printf("  Smoke temp: %.1f°C\n", systemState.tempSmoke);
  Serial.printf("  Product temp: %.1f°C\n", systemState.tempProduct);
  Serial.printf("  Humidity: %.0f%%\n", systemState.humidity);
  Serial.println();
  
  Serial.println("Actuators:");
  Serial.printf("  Heater: %s\n", systemState.heaterOn ? "ON" : "OFF");
  Serial.printf("  Smoke generator: %d%%\n", systemState.smokePWM);
  Serial.printf("  Damper: %d°\n", systemState.damperPosition);
  Serial.printf("  Injection fan: %s\n", systemState.fanInjectionOn ? "ON" : "OFF");
  Serial.printf("  Internal fan: %s\n", systemState.fanInternalOn ? "ON" : "OFF");
  Serial.println();
  
  Serial.println("========================================");
  Serial.println();
  #else
  // Production: log only critical stats as WARN
  Serial.printf("[WARN] Periodic stats: heap=%lu uptime=%lus bound=%s\n",
    ESP.getFreeHeap(), millis() / 1000, systemState.deviceBound ? "yes" : "no");
  #endif
}
