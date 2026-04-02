/**
 * ИСПРАВЛЕННЫЙ Менеджер веб-сервера с полным функционалом
 * 
 * @file WebServerManager_FIXED.h
 * @version 2.0
 * 
 * ИСПРАВЛЕНИЯ:
 * - Добавлено управление программами
 * - Добавлена интеграция с NetworkManager
 * - Добавлены все страницы из ТЗ
 * - Исправлено подключение к WiFi
 */

#ifndef WEB_SERVER_MANAGER_FIXED_H
#define WEB_SERVER_MANAGER_FIXED_H

#include <Arduino.h>
#include <WebServer.h>
#include <ArduinoJson.h>
#include <Update.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <map>
#include "constants.h"
#include "SystemState.h"
#include "WebStyles.h"
#include "WebPages.h"  // HTML pages with proper encoding
#include "OTAWebPage.h"  // OTA update page
#include "ProgramParser.h"  // JSON validation and parsing
#include "ProgramIndex.h"   // Program index management
#include "BindingManager.h"  // Binding manager for device binding
#include "AutoUpdateClient.h"  // Include full definition for AutoUpdateClient
#include "SettingsManager.h"  // C-04: для сохранения пароля веб-сервера в NVS

// Предварительные объявления
class StorageManager;
class ProgramManager;
class SmartSmokerNetworkManager;

class WebServerManager {
private:
    WebServer server;
    StorageManager* storageManager = nullptr;
    ProgramManager* programManager = nullptr;
    SmartSmokerNetworkManager* networkManager = nullptr;
    CloudManager* cloudManager = nullptr;
    BindingManager* bindingManager = nullptr;
    SystemState* systemState = nullptr;
    AutoUpdateClient* autoUpdateClient = nullptr;
    
    uint32_t totalRequests = 0;
    
    // C-04: Пароль для HTTP Basic Auth на критических эндпоинтах
    String _webPassword = "";
    
    // Rate limiting для безопасности
    struct RateLimitEntry {
        uint8_t requestCount;
        unsigned long windowStartTime;
    };
    std::map<String, RateLimitEntry> rateLimitMap;
    const uint8_t MAX_REQUESTS_PER_MINUTE = 10;
    const unsigned long RATE_LIMIT_WINDOW_MS = 60000; // 1 минута

public:
    WebServerManager() : server(80) {}
    
    /**
     * Инициализация с NetworkManager
     */
    bool init(SystemState& state, StorageManager& storage, ProgramManager& programs, 
              SmartSmokerNetworkManager& network, CloudManager& cloud, BindingManager& binding) {
        Serial.println("Initializing web server...");
        
        systemState = &state;
        storageManager = &storage;
        programManager = &programs;
        networkManager = &network;
        cloudManager = &cloud;
        bindingManager = &binding;
        
        // C-04: Загружаем пароль веб-сервера из состояния системы
        _webPassword = state.webServerPassword;
        
        setupRoutes();
        server.begin();
        
        Serial.println("✓ Web server started on port 80");
        return true;
    }
    
    /**
     * Set AutoUpdateClient pointer for update settings
     */
    void setAutoUpdateClient(AutoUpdateClient* client) {
        autoUpdateClient = client;
    }
    
    void handleClient() {
        server.handleClient();
    }

private:
    /**
     * C-04: HTTP Basic Auth для критических эндпоинтов.
     * Если пароль не задан — пропускаем (первый запуск).
     * Возвращает false и отправляет 401 если аутентификация не прошла.
     */
    bool requireAuth() {
        // Если пароль не задан — доступ открыт (режим первой настройки)
        if (_webPassword.isEmpty()) {
            return true;
        }
        
        if (server.authenticate("admin", _webPassword.c_str())) {
            return true;
        }
        
        server.requestAuthentication(BASIC_AUTH, "Smart Smoker", "Требуется авторизация");
        return false;
    }
    
    void setupRoutes() {
        // Главная страница
        server.on("/", HTTP_GET, [this]() { handleRoot(); });
        
        // Стили
        server.on("/style.css", HTTP_GET, [this]() { handleCSS(); });
        
        // Страницы (без аутентификации — информационные)
        server.on("/wifi", HTTP_GET, [this]() { handleWiFiPage(); });
        server.on("/bind", HTTP_GET, [this]() { handleBindPage(); });
        server.on("/programs", HTTP_GET, [this]() { handleProgramsPage(); });
        server.on("/program/create", HTTP_GET, [this]() { handleProgramCreatePage(); });
        server.on("/program/edit", HTTP_GET, [this]() { handleProgramEditPage(); });
        server.on("/files", HTTP_GET, [this]() { handleFilesPage(); });
        server.on("/settings", HTTP_GET, [this]() { handleSettingsPage(); });
        
        // API - Состояние (без аутентификации — только чтение)
        server.on("/api/state", HTTP_GET, [this]() { handleGetState(); });
        
        // API - Устройство (критические — требуют аутентификации)
        server.on("/api/bind-device", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleBindDevice();
        });
        server.on("/api/unbind", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleUnbindDevice();
        });
        server.on("/api/file-received", HTTP_POST, [this]() { handleFileReceived(); });
        server.on("/api/file-content", HTTP_GET, [this]() { handleFileContent(); });
        server.on("/api/file-delete", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleFileDelete();
        });
        server.on("/api/force-check-files", HTTP_POST, [this]() { handleForceCheckFiles(); });
        
        // API - WiFi (критический — требует аутентификации)
        server.on("/api/wifi", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleWiFiConnect();
        });
        server.on("/api/wifi-status", HTTP_GET, [this]() { handleWiFiStatus(); });
        server.on("/api/scan-networks", HTTP_GET, [this]() { handleScanNetworks(); });
        
        // API - Программы
        server.on("/api/programs", HTTP_GET, [this]() { handleGetPrograms(); });
        server.on("/api/programs/list", HTTP_GET, [this]() { handleProgramsList(); });
        server.on("/api/program/create", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleCreateProgram();
        });
        server.on("/api/program/update", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleUpdateProgram();
        });
        server.on("/api/program/delete", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleDeleteProgram();
        });
        server.on("/api/program/start", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleStartProgram();
        });
        server.on("/api/program/stop", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleStopProgram();
        });
        server.on("/api/program/current", HTTP_GET, [this]() { handleGetCurrentProgram(); });
        server.on("/api/program/upload", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleProgramUpload();
        });
        
        // API - Управление (критические)
        server.on("/api/emergency-stop", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleEmergencyStop();
        });
        server.on("/api/smoke-ready", HTTP_POST, [this]() { handleSmokeReady(); });
        
        // API - Установка пароля (только если пароль ещё не задан)
        server.on("/api/set-password", HTTP_POST, [this]() { handleSetPassword(); });
        
        // API - Update Settings
        server.on("/api/update-settings", HTTP_GET, [this]() { handleGetUpdateSettings(); });
        server.on("/api/update-settings", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handlePostUpdateSettings();
        });
        server.on("/api/check-update-now", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleCheckUpdateNow();
        });
        server.on("/api/install-update", HTTP_POST, [this]() {
            if (!requireAuth()) return;
            handleInstallUpdate();
        });
        server.on("/api/update-log", HTTP_GET, [this]() { handleGetUpdateLog(); });
        
        // OTA Update (критический)
        server.on("/ota", HTTP_GET, [this]() { handleOTAPage(); });
        server.on("/update", HTTP_POST, 
            [this]() {
                if (!requireAuth()) return;
                handleOTAUpdateEnd();
            },
            [this]() { handleOTAUpdateProgress(); }
        );
        
        server.onNotFound([this]() { handleNotFound(); });
        server.enableCORS(true);
    }
    
    // ========================================
    // СТРАНИЦЫ
    // ========================================
    
    void handleRoot() {
        totalRequests++;
        String html = generateMainPage();
        server.send(200, "text/html", html);
    }
    
    void handleCSS() {
        server.send(200, "text/css", CSS_STYLES);
    }
    
    /**
     * C-04: Установка пароля веб-сервера (только если пароль ещё не задан)
     * POST /api/set-password {"password": "..."}
     */
    void handleSetPassword() {
        // Разрешаем только если пароль ещё не задан
        if (!_webPassword.isEmpty()) {
            server.send(403, "application/json", "{\"error\":\"Пароль уже установлен\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"error\":\"Неверный JSON\"}");
            return;
        }
        
        String newPassword = doc["password"].as<String>();
        if (newPassword.length() < 4) {
            server.send(400, "application/json", "{\"error\":\"Пароль должен быть не менее 4 символов\"}");
            return;
        }
        
        _webPassword = newPassword;
        if (systemState) {
            systemState->webServerPassword = newPassword;
        }
        
        // Сохраняем в NVS через SettingsManager
        settingsManager.webServerPassword = newPassword;
        settingsManager.save();
        
        Serial.println("[INFO] Пароль веб-сервера установлен");
        server.send(200, "application/json", "{\"success\":true}");
    }
    
    void handleWiFiPage() {
        String html = "<!DOCTYPE html><html><head>";
        html += "<title>Настройка WiFi - Smart Smoker</title>";
        html += "<meta charset='UTF-8'>";
        html += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        html += "<link rel='stylesheet' href='/style.css'>";
        html += "</head><body>";
        html += "<div class='container'>";
        html += "<h1>🌐 Настройка WiFi</h1>";
        
        // Текущее подключение
        html += "<div class='status-card'>";
        html += "<h3>📡 Текущее подключение</h3>";
        html += "<p><strong>Режим:</strong> ";
        html += (systemState->networkMode == SystemState::NetworkMode::AP) ? "Точка доступа" : "Клиент WiFi";
        html += "</p>";
        html += "<p><strong>SSID:</strong> " + systemState->ssid + "</p>";
        html += "<p><strong>IP адрес:</strong> " + systemState->ip + "</p>";
        html += "<p><strong>Сигнал:</strong> <span id='wifi-rssi'>Загрузка...</span></p>";
        html += "</div>";
        
        html += "<div class='controls'>";
        html += "<h3>Подключение к сети</h3>";
        html += "<div id='networks-list'></div>";
        html += "<form id='wifi-form'>";
        html += "<div class='form-group'>";
        html += "<label>SSID:</label>";
        html += "<input type='text' id='ssid' class='form-control' required>";
        html += "</div>";
        html += "<div class='form-group'>";
        html += "<label>Пароль:</label>";
        html += "<input type='password' id='password' class='form-control' required>";
        html += "</div>";
        html += "<button type='submit' class='btn btn-primary'>Подключиться</button>";
        html += "<a href='/' class='btn btn-secondary'>Назад</a>";
        html += "</form>";
        html += "<div id='status'></div>";
        html += "</div>";
        
        html += "</div>";
        
        // JavaScript
        html += "<script>";
        html += "function scanNetworks() {";
        html += "  fetch('/api/scan-networks')";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      let html = '<h4>Доступные сети:</h4><ul>';";
        html += "      data.networks.forEach(n => {";
        html += "        html += '<li onclick=\"document.getElementById(\\'ssid\\').value=\\''+n.ssid+'\\'\" style=\"cursor:pointer\">';";
        html += "        html += n.ssid + ' (' + n.rssi + ' dBm)';";
        html += "        if(n.encryption) html += ' 🔒';";
        html += "        html += '</li>';";
        html += "      });";
        html += "      html += '</ul>';";
        html += "      document.getElementById('networks-list').innerHTML = html;";
        html += "    });";
        html += "}";
        html += "scanNetworks();";
        html += "function updateWiFiStatus() {";
        html += "  fetch('/api/state')";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      if(data.wifi_rssi) {";
        html += "        document.getElementById('wifi-rssi').textContent = data.wifi_rssi + ' dBm';";
        html += "      }";
        html += "    });";
        html += "}";
        html += "updateWiFiStatus();";
        html += "setInterval(updateWiFiStatus, 5000);";
        html += "document.getElementById('wifi-form').onsubmit = function(e) {";
        html += "  e.preventDefault();";
        html += "  const ssid = document.getElementById('ssid').value;";
        html += "  const password = document.getElementById('password').value;";
        html += "  document.getElementById('status').innerHTML = '<p>Подключение...</p>';";
        html += "  fetch('/api/wifi', {";
        html += "    method: 'POST',";
        html += "    headers: {'Content-Type': 'application/json'},";
        html += "    body: JSON.stringify({ssid, password})";
        html += "  })";
        html += "  .then(r => r.json())";
        html += "  .then(data => {";
        html += "    if(data.success) {";
        html += "      document.getElementById('status').innerHTML = '<p class=\"success\">✓ Подключено! Перезагрузка...</p>';";
        html += "      setTimeout(() => window.location.href='/', 3000);";
        html += "    } else {";
        html += "      document.getElementById('status').innerHTML = '<p class=\"error\">✗ Ошибка: '+data.error+'</p>';";
        html += "    }";
        html += "  });";
        html += "};";
        html += "</script>";
        
        html += "</body></html>";
        server.send(200, "text/html", html);
    }
    
    void handleProgramsPage() {
        String html = "<!DOCTYPE html><html><head>";
        html += "<title>Программы - Smart Smoker</title>";
        html += "<meta charset='UTF-8'>";
        html += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        html += "<link rel='stylesheet' href='/style.css'>";
        html += "<style>";
        html += ".program-local { border-left: 4px solid #4CAF50; }";
        html += ".program-website { border-left: 4px solid #2196F3; }";
        html += ".program-source { font-size: 0.9em; color: #666; margin-top: 5px; }";
        html += ".programs-section { margin-bottom: 30px; }";
        html += ".programs-section h2 { color: #333; border-bottom: 2px solid #ddd; padding-bottom: 10px; }";
        html += "</style>";
        html += "</head><body>";
        html += "<div class='container'>";
        html += "<h1>📋 Программы копчения</h1>";
        
        html += "<div class='controls'>";
        html += "<a href='/program/create' class='btn btn-primary'>➕ Создать программу</a>";
        html += "<a href='/' class='btn btn-secondary'>Назад</a>";
        html += "</div>";
        
        html += "<div class='programs-section'>";
        html += "<h2>🖥️ Программы контроллера</h2>";
        html += "<div id='local-programs-list'></div>";
        html += "</div>";
        
        html += "<div class='programs-section'>";
        html += "<h2>🌐 Программы с сайта</h2>";
        html += "<div id='website-programs-list'></div>";
        html += "</div>";
        
        html += "</div>";
        
        // JavaScript
        html += "<script>";
        html += "function loadPrograms() {";
        html += "  fetch('/api/programs')";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      let localHtml = '';";
        html += "      let websiteHtml = '';";
        html += "      let localCount = 0;";
        html += "      let websiteCount = 0;";
        html += "      if(data.programs.length === 0) {";
        html += "        localHtml = '<p>Нет программ, созданных на контроллере</p>';";
        html += "        websiteHtml = '<p>Нет программ, импортированных с сайта</p>';";
        html += "      } else {";
        html += "        data.programs.forEach(p => {";
        html += "          let cardClass = p.is_local ? 'program-local' : 'program-website';";
        html += "          let sourceLabel = p.is_local ? '🖥️ Контроллер' : '🌐 Сайт';";
        html += "          let card = '<div class=\"status-card '+cardClass+'\">';";
        html += "          card += '<h3>'+p.name+'</h3>';";
        html += "          card += '<p>'+p.description+'</p>';";
        html += "          card += '<p>Этапов: '+p.stages_count+'</p>';";
        html += "          card += '<p class=\"program-source\">Источник: '+sourceLabel+'</p>';";
        html += "          card += '<button onclick=\"startProgram(\\''+p.name+'\\')\" class=\"btn btn-primary\">▶️ Запустить</button> ';";
        html += "          card += '<a href=\"/program/edit?name='+encodeURIComponent(p.name)+'\" class=\"btn btn-secondary\">✏️ Редактировать</a> ';";
        html += "          card += '<button onclick=\"deleteProgram(\\''+p.name+'\\')\" class=\"btn btn-danger\">🗑️ Удалить</button>';";
        html += "          card += '</div>';";
        html += "          if(p.is_local) { localHtml += card; localCount++; }";
        html += "          else { websiteHtml += card; websiteCount++; }";
        html += "        });";
        html += "        if(localCount === 0) localHtml = '<p>Нет программ, созданных на контроллере</p>';";
        html += "        if(websiteCount === 0) websiteHtml = '<p>Нет программ, импортированных с сайта</p>';";
        html += "      }";
        html += "      document.getElementById('local-programs-list').innerHTML = localHtml;";
        html += "      document.getElementById('website-programs-list').innerHTML = websiteHtml;";
        html += "    });";
        html += "}";
        html += "function startProgram(name) {";
        html += "  if(confirm('Запустить программу \"'+name+'\"?')) {";
        html += "    fetch('/api/program/start', {";
        html += "      method: 'POST',";
        html += "      headers: {'Content-Type': 'application/json'},";
        html += "      body: JSON.stringify({name})";
        html += "    })";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      alert(data.success ? 'Программа запущена!' : 'Ошибка: '+data.error);";
        html += "      if(data.success) window.location.href='/';";
        html += "    });";
        html += "  }";
        html += "}";
        html += "function deleteProgram(name) {";
        html += "  if(confirm('Удалить программу \"'+name+'\"?')) {";
        html += "    fetch('/api/program/delete', {";
        html += "      method: 'POST',";
        html += "      headers: {'Content-Type': 'application/json'},";
        html += "      body: JSON.stringify({name})";
        html += "    })";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      alert(data.success ? 'Программа удалена!' : 'Ошибка: '+data.error);";
        html += "      loadPrograms();";
        html += "    });";
        html += "  }";
        html += "}";
        html += "loadPrograms();";
        html += "</script>";
        
        html += "</body></html>";
        server.send(200, "text/html", html);
    }
    
    void handleProgramCreatePage() {
        // Use pre-compiled HTML page with proper encoding
        server.send_P(200, "text/html", PROGRAM_CREATE_PAGE);
    }
    
    void handleProgramEditPage() {
        // Get program name from URL parameter
        if (!server.hasArg("name")) {
            String html = "<!DOCTYPE html><html><head><title>Ошибка</title><meta charset='UTF-8'><link rel='stylesheet' href='/style.css'></head><body>";
            html += "<div class='container'><h1>Ошибка</h1><p>Не указано имя программы</p>";
            html += "<a href='/programs' class='btn btn-secondary'>К списку программ</a></div></body></html>";
            server.send(400, "text/html", html);
            return;
        }
        
        String name = server.arg("name");
        
        if (!programManager) {
            String html = "<!DOCTYPE html><html><head><title>Ошибка</title><meta charset='UTF-8'><link rel='stylesheet' href='/style.css'></head><body>";
            html += "<div class='container'><h1>Ошибка</h1><p>Менеджер программ не инициализирован</p>";
            html += "<a href='/programs' class='btn btn-secondary'>К списку программ</a></div></body></html>";
            server.send(500, "text/html", html);
            return;
        }
        
        auto program = programManager->findProgramByName(name);
        if (!program) {
            String html = "<!DOCTYPE html><html><head><title>Ошибка</title><meta charset='UTF-8'><link rel='stylesheet' href='/style.css'></head><body>";
            html += "<div class='container'><h1>Ошибка</h1><p>Программа &quot;" + name + "&quot; не найдена</p>";
            html += "<a href='/programs' class='btn btn-secondary'>К списку программ</a></div></body></html>";
            server.send(404, "text/html", html);
            return;
        }
        
        String html = "<!DOCTYPE html><html><head>";
        html += "<title>Редактирование: " + program->name + " - Smart Smoker</title>";
        html += "<meta charset='UTF-8'>";
        html += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        html += "<link rel='stylesheet' href='/style.css'>";
        html += "</head><body>";
        html += "<div class='container'>";
        html += "<h1>✏️ Редактирование программы</h1>";
        
        html += "<form id='edit-form'>";
        html += "<div class='form-group'>";
        html += "<label>Название (нельзя изменить):</label>";
        html += "<input type='text' name='name' value='" + program->name + "' class='form-control' readonly>";
        html += "</div>";
        html += "<div class='form-group'>";
        html += "<label>Описание:</label>";
        html += "<input type='text' name='description' id='description' value='" + program->description + "' class='form-control'>";
        html += "</div>";
        
        html += "<h3>Этапы программы</h3>";
        html += "<div id='stages'>";
        
        for (size_t i = 0; i < program->steps.size(); i++) {
            const auto& step = program->steps[i];
            html += "<div class='status-card' id='stage-" + String(i) + "'>";
            html += "<h4>Этап " + String(i + 1);
            html += " <button type='button' onclick='removeStage(" + String(i) + ")' class='btn btn-danger' style='float:right;padding:5px 10px'>🗑️ Удалить</button>";
            html += "</h4>";
            
            html += "<div class='form-group'><label>Название этапа:</label>";
            html += "<input type='text' class='form-control stage-stepName' value='" + step.stepName + "'></div>";
            
            html += "<div style='display:flex;gap:10px;flex-wrap:wrap'>";
            
            html += "<div style='flex:1;min-width:150px'><label>Целевая температура (°C):</label>";
            html += "<input type='number' step='0.1' class='form-control stage-targetTemp' value='" + String(step.targetTemp, 1) + "'></div>";
            
            html += "<div style='flex:1;min-width:150px'><label>Датчик температуры:</label>";
            html += "<select class='form-control stage-targetTempDevice'>";
            html += "<option value='0'" + String(step.targetTempDevice == 0 ? " selected" : "") + ">Камера</option>";
            html += "<option value='1'" + String(step.targetTempDevice == 1 ? " selected" : "") + ">Продукт</option>";
            html += "</select></div>";
            
            html += "<div style='flex:1;min-width:150px'><label>Влажность (%):</label>";
            html += "<input type='number' step='1' min='0' max='100' class='form-control stage-targetHumidity' value='" + String((int)step.targetHumidity) + "'></div>";
            
            html += "</div>"; // end flex row
            
            html += "<div style='display:flex;gap:10px;flex-wrap:wrap;margin-top:10px'>";
            
            html += "<div style='flex:1;min-width:120px'><label>Длительность (мин):</label>";
            html += "<input type='number' min='1' max='1440' class='form-control stage-durationMinutes' value='" + String(step.durationMinutes) + "'></div>";
            
            html += "<div style='flex:1;min-width:120px'><label>Гистерезис (°C):</label>";
            html += "<input type='number' step='0.1' min='0' max='10' class='form-control stage-hysteresis' value='" + String(step.hysteresis, 1) + "'></div>";
            
            html += "<div style='flex:1;min-width:120px'><label>Заслонка (%):</label>";
            html += "<input type='number' min='0' max='100' class='form-control stage-ventilationPercent' value='" + String(step.ventilationPercent) + "'></div>";
            
            html += "<div style='flex:1;min-width:120px'><label>ШИМ компрессора:</label>";
            html += "<input type='number' min='-1' max='255' class='form-control stage-compressorPWM' value='" + String(step.compressorPWM) + "' title='-1 = авто'></div>";
            
            html += "</div>"; // end flex row
            
            html += "<div style='margin-top:10px'>";
            html += "<label><input type='checkbox' class='stage-waitForTemp'" + String(step.waitForTemp ? " checked" : "") + "> Ждать достижения температуры</label><br>";
            html += "<label><input type='checkbox' class='stage-useSmokeGenerator'" + String(step.useSmokeGenerator ? " checked" : "") + "> Использовать дымогенератор</label><br>";
            html += "<label><input type='checkbox' class='stage-internalFanOn'" + String(step.internalFanOn ? " checked" : "") + "> Вентилятор в камере</label><br>";
            html += "<label><input type='checkbox' class='stage-injectionFanOn'" + String(step.injectionFanOn ? " checked" : "") + "> Вентилятор подачи воздуха</label>";
            html += "</div>";
            
            html += "</div>"; // end stage card
        }
        
        html += "</div>"; // end #stages
        
        html += "<div style='margin:20px 0;text-align:center'>";
        html += "<button type='button' onclick='addStage()' class='btn btn-success'>➕ Добавить этап</button>";
        html += "</div>";
        
        html += "<div class='controls'>";
        html += "<button type='button' onclick='submitForm()' class='btn btn-primary'>💾 Сохранить</button> ";
        html += "<a href='/programs' class='btn btn-secondary'>Отмена</a>";
        html += "</div>";
        html += "</form>";
        
        html += "<div id='status' style='margin-top:20px'></div>";
        html += "</div>";
        
        // JavaScript
        html += "<script>";
        html += "var stageCounter = " + String(program->steps.size()) + ";";
        html += "function addStage() {";
        html += "  var stagesDiv = document.getElementById('stages');";
        html += "  var newStage = document.createElement('div');";
        html += "  newStage.className = 'status-card';";
        html += "  newStage.id = 'stage-' + stageCounter;";
        html += "  var stageNum = stageCounter + 1;";
        html += "  newStage.innerHTML = '<h4>Этап ' + stageNum + ' <button type=\\\"button\\\" onclick=\\\"removeStage(' + stageCounter + ')\\\" class=\\\"btn btn-danger\\\" style=\\\"float:right;padding:5px 10px\\\">🗑️ Удалить</button></h4>';";
        html += "  newStage.innerHTML += '<div class=\\\"form-group\\\"><label>Название этапа:</label><input type=\\\"text\\\" class=\\\"form-control stage-stepName\\\" value=\\\"Этап ' + stageNum + '\\\"></div>';";
        html += "  newStage.innerHTML += '<div style=\\\"display:flex;gap:10px;flex-wrap:wrap\\\">';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:150px\\\"><label>Целевая температура (°C):</label><input type=\\\"number\\\" step=\\\"0.1\\\" class=\\\"form-control stage-targetTemp\\\" value=\\\"30.0\\\"></div>';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:150px\\\"><label>Датчик температуры:</label><select class=\\\"form-control stage-targetTempDevice\\\"><option value=\\\"0\\\" selected>Камера</option><option value=\\\"1\\\">Продукт</option></select></div>';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:150px\\\"><label>Влажность (%):</label><input type=\\\"number\\\" step=\\\"1\\\" min=\\\"0\\\" max=\\\"100\\\" class=\\\"form-control stage-targetHumidity\\\" value=\\\"70\\\"></div>';";
        html += "  newStage.innerHTML += '</div>';";
        html += "  newStage.innerHTML += '<div style=\\\"display:flex;gap:10px;flex-wrap:wrap;margin-top:10px\\\">';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:120px\\\"><label>Длительность (мин):</label><input type=\\\"number\\\" min=\\\"1\\\" max=\\\"1440\\\" class=\\\"form-control stage-durationMinutes\\\" value=\\\"60\\\"></div>';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:120px\\\"><label>Гистерезис (°C):</label><input type=\\\"number\\\" step=\\\"0.1\\\" min=\\\"0\\\" max=\\\"10\\\" class=\\\"form-control stage-hysteresis\\\" value=\\\"2.0\\\"></div>';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:120px\\\"><label>Заслонка (%):</label><input type=\\\"number\\\" min=\\\"0\\\" max=\\\"100\\\" class=\\\"form-control stage-ventilationPercent\\\" value=\\\"100\\\"></div>';";
        html += "  newStage.innerHTML += '<div style=\\\"flex:1;min-width:120px\\\"><label>ШИМ компрессора:</label><input type=\\\"number\\\" min=\\\"-1\\\" max=\\\"255\\\" class=\\\"form-control stage-compressorPWM\\\" value=\\\"-1\\\" title=\\\"-1 = авто\\\"></div>';";
        html += "  newStage.innerHTML += '</div>';";
        html += "  newStage.innerHTML += '<div style=\\\"margin-top:10px\\\">';";
        html += "  newStage.innerHTML += '<label><input type=\\\"checkbox\\\" class=\\\"stage-waitForTemp\\\"> Ждать достижения температуры</label><br>';";
        html += "  newStage.innerHTML += '<label><input type=\\\"checkbox\\\" class=\\\"stage-useSmokeGenerator\\\"> Использовать дымогенератор</label><br>';";
        html += "  newStage.innerHTML += '<label><input type=\\\"checkbox\\\" class=\\\"stage-internalFanOn\\\"> Вентилятор в камере</label><br>';";
        html += "  newStage.innerHTML += '<label><input type=\\\"checkbox\\\" class=\\\"stage-injectionFanOn\\\"> Вентилятор подачи воздуха</label>';";
        html += "  newStage.innerHTML += '</div>';";
        html += "  stagesDiv.appendChild(newStage);";
        html += "  stageCounter++;";
        html += "}";
        html += "function removeStage(id) {";
        html += "  if (confirm('Удалить этот этап?')) {";
        html += "    var stage = document.getElementById('stage-' + id);";
        html += "    if (stage) stage.remove();";
        html += "  }";
        html += "}";
        html += "function submitForm() {";
        html += "  var stages = [];";
        html += "  var cards = document.querySelectorAll('#stages .status-card');";
        html += "  cards.forEach(function(card) {";
        html += "    stages.push({";
        html += "      stage_name: card.querySelector('.stage-stepName').value,";
        html += "      target_temp: parseFloat(card.querySelector('.stage-targetTemp').value),";
        html += "      target_temp_device: parseInt(card.querySelector('.stage-targetTempDevice').value),";
        html += "      target_humidity: parseFloat(card.querySelector('.stage-targetHumidity').value),";
        html += "      duration_minutes: parseInt(card.querySelector('.stage-durationMinutes').value),";
        html += "      hysteresis: parseFloat(card.querySelector('.stage-hysteresis').value),";
        html += "      ventilation_percent: parseInt(card.querySelector('.stage-ventilationPercent').value),";
        html += "      compressor_pwm: parseInt(card.querySelector('.stage-compressorPWM').value),";
        html += "      wait_for_temp: card.querySelector('.stage-waitForTemp').checked,";
        html += "      use_smoke_generator: card.querySelector('.stage-useSmokeGenerator').checked,";
        html += "      internal_fan_on: card.querySelector('.stage-internalFanOn').checked,";
        html += "      injection_fan_on: card.querySelector('.stage-injectionFanOn').checked";
        html += "    });";
        html += "  });";
        html += "  var data = {";
        html += "    name: document.querySelector('input[name=name]').value,";
        html += "    description: document.getElementById('description').value,";
        html += "    stages: stages";
        html += "  };";
        html += "  var statusDiv = document.getElementById('status');";
        html += "  statusDiv.innerHTML = '<p>Сохранение...</p>';";
        html += "  fetch('/api/program/update', {";
        html += "    method: 'POST',";
        html += "    headers: {'Content-Type': 'application/json'},";
        html += "    body: JSON.stringify(data)";
        html += "  })";
        html += "  .then(function(r) { return r.json(); })";
        html += "  .then(function(data) {";
        html += "    if (data.success) {";
        html += "      statusDiv.innerHTML = '<p class=\"success\">✓ Программа сохранена!</p>';";
        html += "      setTimeout(function() { window.location.href='/programs'; }, 1500);";
        html += "    } else {";
        html += "      statusDiv.innerHTML = '<p class=\"error\">✗ Ошибка: ' + (data.error || 'Неизвестная ошибка') + '</p>';";
        html += "    }";
        html += "  })";
        html += "  .catch(function(err) {";
        html += "    statusDiv.innerHTML = '<p class=\"error\">✗ Ошибка сети: ' + err.message + '</p>';";
        html += "  });";
        html += "}";
        html += "</script>";
        
        html += "</body></html>";
        server.send(200, "text/html", html);
    }
    
    void handleBindPage() {
        String html = "<!DOCTYPE html><html><head>";
        html += "<title>Привязка устройства - Smart Smoker</title>";
        html += "<meta charset='UTF-8'>";
        html += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        html += "<link rel='stylesheet' href='/style.css'>";
        html += "</head><body>";
        html += "<div class='container'>";
        
        // Check WiFi connectivity
        if (!systemState->wifiConnected || systemState->networkMode == SystemState::NetworkMode::AP) {
            html += "<h1>🔗 Привязка устройства</h1>";
            html += "<div class='warning-box'>";
            html += "<strong>⚠️ WiFi не подключен</strong><br>";
            html += "Пожалуйста, подключитесь к WiFi перед привязкой устройства.<br>";
            html += "<a href='/wifi' class='btn btn-primary' style='margin-top:10px'>Перейти к настройкам WiFi</a>";
            html += "</div>";
        }
        
        if (systemState->wifiConnected && systemState->networkMode != SystemState::NetworkMode::AP) {
            // Check if device is already bound
            bool isBound = bindingManager && bindingManager->isBound();
            
            if (isBound) {
                // Device is bound - show device settings
                html += "<h1>⚙️ Настройки устройства</h1>";
                
                html += "<div class='status-card'>";
                html += "<h3>Информация об устройстве</h3>";
                
                // Username
                String username = bindingManager->getUsername();
                if (!username.isEmpty()) {
                    html += "<p><strong>Пользователь:</strong> " + username + "</p>";
                }
                
                // UUID
                String uuid = bindingManager->getUUID();
                if (!uuid.isEmpty()) {
                    html += "<p><strong>UUID устройства:</strong> " + uuid + "</p>";
                }
                
                // Binding date/time
                String timestamp = bindingManager->getTimestamp();
                html += "<p><strong>Привязано:</strong> ";
                if (!timestamp.isEmpty()) {
                    html += timestamp;
                } else {
                    html += "Дата привязки неизвестна";
                }
                html += "</p>";
                
                html += "</div>";
                
                html += "<div class='controls' style='margin-top:20px'>";
                html += "<button onclick='unbindDevice()' class='btn btn-danger'>Отвязать устройство</button>";
                html += "<a href='/' class='btn btn-secondary'>На главную</a>";
                html += "</div>";
            } else {
                // Device is not bound - show login/password form
                html += "<h1>🔗 Привязка устройства</h1>";
                
                html += "<div class='info-box'>";
                html += "<strong>Устройство не привязано</strong><br>";
                html += "Введите учетные данные вашего аккаунта на сайте для привязки устройства.";
                html += "</div>";
                
                html += "<form id='bind-form' style='margin-top:20px'>";
                html += "<div class='form-group'>";
                html += "<label>Логин:</label>";
                html += "<input type='text' id='login' class='form-control' required>";
                html += "</div>";
                html += "<div class='form-group'>";
                html += "<label>Пароль:</label>";
                html += "<input type='password' id='password' class='form-control' required>";
                html += "</div>";
                html += "<button type='submit' class='btn btn-primary'>Привязать устройство</button>";
                html += "<a href='/' class='btn btn-secondary'>На главную</a>";
                html += "</form>";
            }
            
            // Status message area
            html += "<div id='status' style='margin-top:20px'></div>";
        }
        
        html += "</div>";
        
        // JavaScript for form handling
        html += "<script>";
        
        // Bind form submission
        html += "const bindForm = document.getElementById('bind-form');";
        html += "if (bindForm) {";
        html += "  bindForm.onsubmit = function(e) {";
        html += "    e.preventDefault();";
        html += "    const login = document.getElementById('login').value;";
        html += "    const password = document.getElementById('password').value;";
        html += "    const statusDiv = document.getElementById('status');";
        html += "    statusDiv.innerHTML = '<p>Привязка устройства, пожалуйста подождите...</p>';";
        html += "    fetch('/api/bind-device', {";
        html += "      method: 'POST',";
        html += "      headers: {'Content-Type': 'application/json'},";
        html += "      body: JSON.stringify({login: login, password: password})";
        html += "    })";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      if(data.success) {";
        html += "        statusDiv.innerHTML = '<p class=\"success\">✓ Устройство успешно привязано! Перенаправление...</p>';";
        html += "        setTimeout(() => window.location.reload(), 2000);";
        html += "      } else {";
        html += "        statusDiv.innerHTML = '<p class=\"error\">✗ Ошибка: '+(data.message || data.error)+'</p>';";
        html += "      }";
        html += "    })";
        html += "    .catch(err => {";
        html += "      statusDiv.innerHTML = '<p class=\"error\">✗ Ошибка сети: '+err.message+'</p>';";
        html += "    });";
        html += "  };";
        html += "}";
        
        // Unbind function
        html += "function unbindDevice() {";
        html += "  if(!confirm('Вы уверены, что хотите отвязать это устройство?')) return;";
        html += "  const statusDiv = document.getElementById('status');";
        html += "  statusDiv.innerHTML = '<p>Отвязка устройства, пожалуйста подождите...</p>';";
        html += "  fetch('/api/unbind', {";
        html += "    method: 'POST',";
        html += "    headers: {'Content-Type': 'application/json'},";
        html += "    body: JSON.stringify({})";
        html += "  })";
        html += "  .then(r => r.json())";
        html += "  .then(data => {";
        html += "    if(data.success) {";
        html += "      statusDiv.innerHTML = '<p class=\"success\">✓ Устройство успешно отвязано! Перенаправление...</p>';";
        html += "      setTimeout(() => window.location.reload(), 2000);";
        html += "    } else {";
        html += "      statusDiv.innerHTML = '<p class=\"error\">✗ Ошибка: '+(data.message || data.error)+'</p>';";
        html += "    }";
        html += "  })";
        html += "  .catch(err => {";
        html += "    statusDiv.innerHTML = '<p class=\"error\">✗ Ошибка сети: '+err.message+'</p>';";
        html += "  });";
        html += "}";
        
        html += "</script>";
        html += "</body></html>";
        
        server.send(200, "text/html", html);
    }

    
    String checkInternetConnectivity() {
        // Check if we're in STA mode and WiFi is connected
        if (systemState->networkMode != SystemState::NetworkMode::STA || !systemState->wifiConnected) {
            return "Не подключен";
        }
        
        // Check if cloud is connected (this is updated by CloudManager)
        if (systemState->cloudConnected) {
            return "✓ Онлайн";
        }
        
        // If WiFi is connected but cloud is not, try a quick connectivity check
        HTTPClient http;
        WiFiClientSecure client;
        client.setInsecure();
        
        String url = systemState->cloudUrl;
        http.begin(client, url);
        http.setTimeout(5000); // 5 second timeout
        
        int httpCode = http.GET();
        http.end();
        
        if (httpCode > 0 && httpCode < 400) {
            return "✓ Онлайн";
        } else {
            return "⚠️ Нет доступа к интернету";
        }
    }
    
    // ========================================
    // API - СОСТОЯНИЕ
    // ========================================
    
    void handleGetState() {
        totalRequests++;
        
        JsonDocument doc;
        doc["networkMode"] = (systemState->networkMode == SystemState::NetworkMode::AP) ? "AP" : "STA";
        doc["ssid"] = systemState->ssid;
        doc["ip"] = systemState->ip;
        doc["wifi_rssi"] = WiFi.RSSI();  // Добавляем уровень сигнала WiFi
        doc["mode"] = getModeString(systemState->mode);
        doc["tempChamber"] = systemState->tempChamber;
        doc["tempSmoke"] = systemState->tempSmoke;
        doc["tempProduct"] = systemState->tempProduct;
        doc["humidity"] = systemState->humidity;
        doc["heaterOn"] = systemState->heaterOn;
        doc["smokePWM"] = systemState->smokePWM;
        doc["damperPosition"] = systemState->damperPosition;
        doc["fanInjectionOn"] = systemState->fanInjectionOn;
        doc["emergencyStop"] = systemState->emergencyStop;
        doc["deviceBound"] = systemState->deviceBound;
        doc["cloudConnected"] = systemState->cloudConnected;
        doc["firmware_version"] = systemState->firmwareVersion;
        
        // Состояние ожидания розжига
        if (systemState->mode == SystemState::Mode::WAITING_SMOKE_IGNITION) {
            unsigned long elapsed = millis() - systemState->smokeIgnitionStartTime;
            doc["smoke_ignition_wait"] = true;
            doc["smoke_ignition_elapsed_sec"] = elapsed / 1000;
            doc["smoke_ignition_timeout_sec"] = SystemState::SMOKE_IGNITION_TIMEOUT_MS / 1000;
        } else {
            doc["smoke_ignition_wait"] = false;
        }
        
        if (systemState->mode == SystemState::Mode::RUNNING && systemState->currentProgram) {
            doc["currentProgram"] = systemState->currentProgram->name;
            doc["currentStep"] = systemState->currentStepIndex + 1;
            
            if (programManager) {
                doc["progress"] = programManager->getProgress(*systemState);
                doc["time_left"] = programManager->getTimeLeft(*systemState);
            }
        } else {
            doc["currentProgram"] = "";
            doc["progress"] = 0;
            doc["time_left"] = 0;
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    // ========================================
    // API - WIFI
    // ========================================
    
    void handleWiFiConnect() {
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Нет данных\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Неверный JSON\"}");
            return;
        }
        
        if (!doc["ssid"].is<const char*>() || !doc["password"].is<const char*>()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Отсутствует SSID или пароль\"}");
            return;
        }
        
        String ssid = doc["ssid"];
        String password = doc["password"];
        
        // ИСПРАВЛЕНИЕ: Вызов NetworkManager для подключения
        bool success = networkManager->configureWiFi(*systemState, ssid, password);
        
        // Сохранение настроек в файловую систему, если подключение успешно и storageManager доступен
        if (success && storageManager) {
            storageManager->saveSettings(*systemState);
        }
        
        JsonDocument response;
        response["success"] = success;
        response["message"] = success ? "Подключено к WiFi" : "Ошибка подключения";
        
        String responseStr;
        serializeJson(response, responseStr);
        server.send(200, "application/json", responseStr);
        
        Serial.printf("WiFi connection %s: %s\n", success ? "successful" : "failed", ssid.c_str());
    }
    
    void handleWiFiStatus() {
        JsonDocument doc;
        
        doc["ssid"] = systemState->ssid;
        doc["ip"] = systemState->ip;
        doc["connected"] = systemState->wifiConnected;
        doc["rssi"] = WiFi.RSSI();
        doc["mode"] = (systemState->networkMode == SystemState::NetworkMode::AP) ? "AP" : "STA";
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    void handleScanNetworks() {
        int networksFound = WiFi.scanNetworks();
        
        JsonDocument doc;
        doc["success"] = true;
        
        JsonArray networks = doc["networks"].to<JsonArray>();
        
        for (int i = 0; i < networksFound; i++) {
            JsonObject network = networks.add<JsonObject>();
            network["ssid"] = WiFi.SSID(i);
            network["rssi"] = WiFi.RSSI(i);
            network["encryption"] = (WiFi.encryptionType(i) != WIFI_AUTH_OPEN);
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
        
        WiFi.scanDelete();
    }
    
    // ========================================
    // API - ПРОГРАММЫ
    // ========================================
    
    void handleGetPrograms() {
        if (!programManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Program manager not initialized\"}");
            return;
        }
        
        JsonDocument doc;
        doc["success"] = true;
        
        JsonArray programsArray = doc["programs"].to<JsonArray>();
        
        // Получение списка программ
        size_t programCount = programManager->getProgramCount();
        for (size_t i = 0; i < programCount; i++) {
            auto program = programManager->getProgram(i);
            if (program) {
                JsonObject progObj = programsArray.add<JsonObject>();
                progObj["name"] = program->name;
                progObj["description"] = program->description;
                progObj["category"] = program->category;
                progObj["is_built_in"] = program->isBuiltIn;
                progObj["stages_count"] = program->steps.size();
                progObj["total_duration"] = program->getTotalDuration();
                progObj["usage_count"] = program->usageCount;
                progObj["last_used"] = program->lastUsed;
                progObj["is_local"] = program->isLocalProgram; // Добавляем источник программы
                progObj["source"] = program->isLocalProgram ? "controller" : "website"; // Текстовое описание источника
            }
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    void handleCreateProgram() {
        if (!programManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Менеджер программ не инициализирован\"}");
            return;
        }
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Нет данных\"}");
            return;
        }
        
        String body = server.arg("plain");
        
        // Парсинг программы
        auto program = programManager->parseProgramFromJson(body);
        if (!program) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Неверные данные программы\"}");
            return;
        }
        
        // Помечаем программу как созданную на контроллере
        program->isLocalProgram = true;
        
        // Генерируем уникальный ID для локальной программы на основе timestamp
        if (program->programId == 0) {
            program->programId = millis() / 1000; // секунды с момента запуска
        }
        
        // Добавление программы
        if (programManager->addOrUpdateProgram(program)) {
            // Сохранение в StorageManager
            if (storageManager) {
                storageManager->saveProgram(*program);
            }
            
            server.send(200, "application/json", "{\"success\":true,\"message\":\"Программа создана\"}");
        } else {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Не удалось создать программу\"}");
        }
    }
    
    void handleUpdateProgram() {
        if (!programManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Менеджер программ не инициализирован\"}");
            return;
        }
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Нет данных\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Неверный JSON\"}");
            return;
        }
        
        String programName = doc["name"] | "";
        if (programName.isEmpty()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Требуется имя программы\"}");
            return;
        }
        
        // Поиск существующей программы
        auto existingProgram = programManager->findProgramByName(programName);
        if (!existingProgram) {
            server.send(404, "application/json", "{\"success\":false,\"error\":\"Программа не найдена\"}");
            return;
        }
        
        // Обновление программы
        auto updatedProgram = programManager->parseProgramFromJson(body);
        if (!updatedProgram) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Неверные данные программы\"}");
            return;
        }
        
        // Сохраняем источник программы (isLocalProgram) и programId из существующей программы
        updatedProgram->isLocalProgram = existingProgram->isLocalProgram;
        updatedProgram->programId = existingProgram->programId;
        
        if (programManager->addOrUpdateProgram(updatedProgram)) {
            // Сохранение в StorageManager
            if (storageManager) {
                storageManager->saveProgram(*updatedProgram);
            }
            
            server.send(200, "application/json", "{\"success\":true,\"message\":\"Программа обновлена\"}");
        } else {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Не удалось обновить программу\"}");
        }
    }
    
    void handleDeleteProgram() {
        if (!programManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Менеджер программ не инициализирован\"}");
            return;
        }
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Нет данных\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Неверный JSON\"}");
            return;
        }
        
        String programName = doc["name"] | "";
        if (programName.isEmpty()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Требуется имя программы\"}");
            return;
        }
        
        // Удаление программы
        if (programManager->deleteProgram(programName)) {
            // Удаление файла из StorageManager — используем ту же sanitize-логику что и saveProgram()
            if (storageManager) {
                String sanitized = programName;
                sanitized.toLowerCase();
                sanitized.replace(" ", "_");
                String safe = "";
                for (int i = 0; i < (int)sanitized.length(); i++) {
                    char c = sanitized[i];
                    if ((c >= 'a' && c <= 'z') || (c >= '0' && c <= '9') || c == '_' || c == '-') {
                        safe += c;
                    }
                }
                if (safe.isEmpty()) safe = "unnamed";
                storageManager->deleteProgramFile("/programs/program_" + safe + ".json");
            }
            
            server.send(200, "application/json", "{\"success\":true,\"message\":\"Программа удалена\"}");
        } else {
            server.send(404, "application/json", "{\"success\":false,\"error\":\"Программа не найдена\"}");
        }
    }
    
    void handleStartProgram() {
        if (!programManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Менеджер программ не инициализирован\"}");
            return;
        }
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Нет данных\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Неверный JSON\"}");
            return;
        }
        
        // Fix 3: принимаем оба варианта поля — "name" и "program" (совместимость с ТЗ)
        String programName = doc["name"] | doc["program"] | "";
        if (programName.isEmpty()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Требуется имя программы\"}");
            return;
        }
        
        // Запуск программы
        if (programManager->startProgram(programName, *systemState)) {
            server.send(200, "application/json", "{\"success\":true,\"message\":\"Программа запущена\",\"run_id\":\"" + systemState->currentRunId + "\"}");
        } else {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Не удалось запустить программу\"}");
        }
    }
    
    void handleStopProgram() {
        if (!programManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Менеджер программ не инициализирован\"}");
            return;
        }
        
        // Остановка программы
        if (programManager->stopProgram(*systemState)) {
            server.send(200, "application/json", "{\"success\":true,\"message\":\"Программа остановлена\"}");
        } else {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Нет запущенной программы\"}");
        }
    }
    
    void handleGetCurrentProgram() {
        JsonDocument doc;
        doc["success"] = true;
        doc["running"] = (systemState->mode == SystemState::Mode::RUNNING);
        
        if (systemState->mode == SystemState::Mode::RUNNING && systemState->currentProgram) {
            doc["program_name"] = systemState->currentProgram->name;
            doc["current_stage"] = systemState->currentStepIndex + 1;
            doc["total_stages"] = systemState->currentProgram->steps.size();
            
            if (programManager) {
                doc["progress"] = programManager->getProgress(*systemState);
                doc["time_left"] = programManager->getTimeLeft(*systemState);
            }
            
            // Информация о текущем этапе
            if (systemState->currentStepIndex < systemState->currentProgram->steps.size()) {
                const auto& currentStep = systemState->currentProgram->steps[systemState->currentStepIndex];
                doc["stage_name"] = currentStep.stepName;
                doc["target_temp"] = currentStep.targetTemp;
                doc["target_temp_device"] = currentStep.targetTempDevice;
                doc["duration_minutes"] = currentStep.durationMinutes;
                doc["waiting_for_temp"] = systemState->waitingForTemp;
            }
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    void handleProgramUpload() {
        totalRequests++;
        
        // Rate limiting
        String deviceId = systemState->deviceId.isEmpty() ? "unknown" : systemState->deviceId;
        if (!checkRateLimit(deviceId)) {
            Serial.printf("[WARN] Program upload: Rate limit exceeded for device %s\n", deviceId.c_str());
            
            JsonDocument errorDoc;
            errorDoc["success"] = false;
            errorDoc["error"] = "Too many requests";
            errorDoc["message"] = "Rate limit exceeded. Maximum 10 program uploads per minute allowed.";
            errorDoc["retry_after"] = 60;
            
            String errorResponse;
            serializeJson(errorDoc, errorResponse);
            server.send(429, "application/json", errorResponse);
            return;
        }
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"No data received\"}");
            return;
        }
        
        String body = server.arg("plain");
        
        // Валидация JSON через ProgramParser
        ProgramParser parser;
        String errorMessage;
        if (!parser.validate(body, errorMessage)) {
            Serial.printf("[WARN] Program upload: JSON validation failed - %s\n", errorMessage.c_str());
            
            JsonDocument errorDoc;
            errorDoc["success"] = false;
            errorDoc["error"] = "Invalid program data";
            errorDoc["details"] = errorMessage;
            
            String errorResponse;
            serializeJson(errorDoc, errorResponse);
            server.send(400, "application/json", errorResponse);
            return;
        }
        
        // Парсинг JSON в структуру ProgramData
        ProgramData program;
        if (!parser.parse(body, program)) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Failed to parse program data\"}");
            return;
        }
        
        if (!storageManager) {
            Serial.println("[ERROR] Program upload: StorageManager not initialized");
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Storage manager not initialized\"}");
            return;
        }
        
        size_t freeBytes = storageManager->getFreeBytes();
        if (freeBytes < 10240) {
            Serial.printf("[ERROR] Program upload: Insufficient storage - %d bytes free\n", freeBytes);
            server.send(507, "application/json", "{\"success\":false,\"error\":\"Insufficient storage space\"}");
            return;
        }
        
        if (!storageManager->saveProgram(program.program_id, body)) {
            Serial.printf("[ERROR] Program upload: Failed to save program %d\n", program.program_id);
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Failed to save program\"}");
            return;
        }
        
        // Верификация записи
        String verifyJson;
        if (!storageManager->loadProgram(program.program_id, verifyJson)) {
            Serial.printf("[ERROR] Program upload: Verification failed for program %d\n", program.program_id);
            storageManager->deleteProgram(program.program_id);
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Failed to verify saved program\"}");
            return;
        }
        
        // Обновление индекса через ProgramIndex
        ProgramIndex programIndex;
        ProgramMetadata metadata;
        metadata.program_id = program.program_id;
        metadata.program_name = program.program_name;
        metadata.category = program.category;
        metadata.file_size = body.length();
        metadata.uploaded_at = program.timestamp;
        
        if (!programIndex.addProgram(metadata)) {
            Serial.printf("[WARN] Program upload: Failed to update index for program %d\n", program.program_id);
        }
        
        JsonDocument responseDoc;
        responseDoc["success"] = true;
        responseDoc["transfer_id"] = program.transfer_id;
        responseDoc["program_id"] = program.program_id;
        responseDoc["status"] = "confirmed";
        responseDoc["saved_at"] = program.timestamp;
        responseDoc["storage_path"] = "/programs/program_" + String(program.program_id) + ".json";
        responseDoc["file_size"] = body.length();
        
        String response;
        serializeJson(responseDoc, response);
        server.send(200, "application/json", response);
    }
    
    void handleProgramsList() {
        totalRequests++;
        
        ProgramIndex programIndex;
        String indexJson;
        
        if (!programIndex.getList(indexJson)) {
            Serial.println("[ERROR] Programs list: Failed to get program list from index");
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Failed to get program list\"}");
            return;
        }
        
        server.send(200, "application/json", indexJson);
    }
    
    // ========================================
    // API - УПРАВЛЕНИЕ
    // ========================================
    
    void handleBindDevice() {
        totalRequests++;
        
        // Check if request has body
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"message\":\"No data received\"}");
            return;
        }
        
        // Parse JSON body
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"message\":\"Invalid JSON format\"}");
            return;
        }
        
        // Extract login and password from request body
        if (!doc["login"].is<const char*>() || !doc["password"].is<const char*>()) {
            server.send(400, "application/json", "{\"success\":false,\"message\":\"Missing login or password\"}");
            return;
        }
        
        String login = doc["login"].as<String>();
        String password = doc["password"].as<String>();
        
        // Validate that login and password are not empty
        if (login.isEmpty() || password.isEmpty()) {
            server.send(400, "application/json", "{\"success\":false,\"message\":\"Login and password cannot be empty\"}");
            return;
        }
        
        // Check if BindingManager is available
        if (!bindingManager) {
            server.send(500, "application/json", "{\"success\":false,\"message\":\"Binding manager not initialized\"}");
            return;
        }
        
        // Check internet connectivity
        if (!bindingManager->checkInternetAccess()) {
            server.send(503, "application/json", "{\"success\":false,\"message\":\"No internet connection. Please check WiFi connection.\"}");
            return;
        }
        
        Serial.printf("[Bind] Initiating binding with login: %s\n", login.c_str());
        
        // Call BindingManager to initiate binding
        bool bindingSuccess = bindingManager->initiateBinding(login, password);
        
        // Prepare response
        JsonDocument response;
        response["success"] = bindingSuccess;
        
        if (bindingSuccess) {
            // On success: update systemState atomically
            systemState->deviceId = bindingManager->getUUID();  // ВАЖНО: сохраняем UUID как deviceId
            systemState->deviceBound = true;
            systemState->apiToken = bindingManager->getAPIToken();
            
            Serial.println("[Bind] Device bound successfully");
            Serial.printf("[Bind] Device ID (UUID): %s\n", systemState->deviceId.c_str());
            Serial.printf("[Bind] API Token: %s...\n", systemState->apiToken.substring(0, 20).c_str());
            
            // Immediately save to cloud.json to ensure atomic state update
            if (storageManager) {
                bool saveSuccess = storageManager->saveCloudSettings(*systemState);
                if (!saveSuccess) {
                    Serial.println("[Bind] ❌ ERROR: Failed to save cloud settings to cloud.json");
                    response["success"] = false;
                    response["message"] = "Binding succeeded but failed to save settings. Please try again.";
                    
                    // Clear the binding state since we couldn't persist it
                    systemState->deviceBound = false;
                    systemState->deviceId = "";
                    systemState->apiToken = "";
                    bindingManager->unbind();
                    
                    String responseStr;
                    serializeJson(response, responseStr);
                    server.send(500, "application/json", responseStr);
                    return;
                }
                Serial.println("[Bind] ✓ Cloud settings saved to cloud.json");
                
                // Verify consistency: read back both files and compare states
                Serial.println("[Bind] Verifying file consistency...");
                bool bindingJsonBound = bindingManager->isBound();
                bool cloudJsonBound = systemState->deviceBound;
                String bindingJsonUUID = bindingManager->getUUID();
                String cloudJsonDeviceId = systemState->deviceId;
                String bindingJsonToken = bindingManager->getAPIToken();
                String cloudJsonToken = systemState->apiToken;
                
                if (bindingJsonBound == cloudJsonBound && 
                    bindingJsonUUID == cloudJsonDeviceId && 
                    bindingJsonToken == cloudJsonToken) {
                    Serial.println("[Bind] ✓ File consistency verified: binding.json and cloud.json are synchronized");
                } else {
                    Serial.println("[Bind] ⚠ WARNING: File inconsistency detected!");
                    Serial.printf("[Bind]   binding.json: bound=%s, uuid=%s, token=%s...\n", 
                                  bindingJsonBound ? "true" : "false", 
                                  bindingJsonUUID.c_str(), 
                                  bindingJsonToken.substring(0, 20).c_str());
                    Serial.printf("[Bind]   cloud.json: bound=%s, deviceId=%s, token=%s...\n", 
                                  cloudJsonBound ? "true" : "false", 
                                  cloudJsonDeviceId.c_str(), 
                                  cloudJsonToken.substring(0, 20).c_str());
                }
            } else {
                Serial.println("[Bind] ⚠ WARNING: StorageManager not available, cloud.json not saved");
            }
            
            response["message"] = "Device bound successfully";
        } else {
            response["message"] = "Binding failed. Please check your credentials.";
            Serial.println("[Bind] Binding failed");
        }
        
        String responseStr;
        serializeJson(response, responseStr);
        server.send(bindingSuccess ? 200 : 400, "application/json", responseStr);
    }
    
    /**
     * Обработчик отвязки устройства
     * Вызывает BindingManager.unbind() для отправки запроса на сайт и удаления binding.json
     */
    void handleUnbindDevice() {
        totalRequests++;
        
        // Check if BindingManager is available
        if (!bindingManager) {
            server.send(500, "application/json", "{\"success\":false,\"message\":\"BindingManager not initialized\"}");
            return;
        }
        
        Serial.println("[Unbind] Processing unbind request");
        
        // Parse JSON body to check for force parameter
        bool force = false;
        if (server.hasArg("plain")) {
            String body = server.arg("plain");
            JsonDocument doc;
            
            if (deserializeJson(doc, body) == DeserializationError::Ok) {
                force = doc["force"] | false;
                Serial.printf("[Unbind] Force parameter from request: %s\n", force ? "true" : "false");
            }
        }
        
        // Call BindingManager.unbind() with force parameter
        bool success = bindingManager->unbind(force);
        
        if (success) {
            // Update systemState to reflect unbinding
            systemState->deviceId = "";  // Очищаем device ID
            systemState->deviceBound = false;
            systemState->apiToken = "";
            
            // Save changes through StorageManager
            if (storageManager) {
                storageManager->saveCloudSettings(*systemState);
                Serial.println("[Unbind] Unbind state saved to storage");
                
                // Verify both files are consistent after unbinding
                Serial.println("[Unbind] Verifying file consistency...");
                Serial.print("[Unbind] systemState.deviceBound: ");
                Serial.println(systemState->deviceBound ? "true" : "false");
                Serial.print("[Unbind] bindingManager->isBound(): ");
                Serial.println(bindingManager->isBound() ? "true" : "false");
                
                // Both should be false after unbinding
                if (!systemState->deviceBound && !bindingManager->isBound()) {
                    Serial.println("[Unbind] Verification passed: Both files cleared consistently");
                } else {
                    Serial.println("[Unbind] WARNING: Inconsistent state detected after unbinding!");
                }
            }
            
            JsonDocument response;
            response["success"] = true;
            response["message"] = force ? "Device unbound locally (forced)" : "Device unbound successfully";
            
            String responseStr;
            serializeJson(response, responseStr);
            server.send(200, "application/json", responseStr);
            
            Serial.println("[Unbind] Device unbound successfully");
        } else {
            // Unbinding failed
            JsonDocument response;
            response["success"] = false;
            response["message"] = "Failed to unbind device";
            
            String responseStr;
            serializeJson(response, responseStr);
            server.send(500, "application/json", responseStr);
            
            Serial.println("[Unbind] Unbind failed");
        }
    }
    
    /**
     * Обработчик подтверждения получения файла
     * Используется для подтверждения получения программ, настроек и других файлов
     */
    void handleFileReceived() {
        totalRequests++;
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"No data\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Invalid JSON\"}");
            return;
        }
        
        String fileName = doc["file_name"] | "";
        String fileType = doc["file_type"] | "";
        String status = doc["status"] | "";
        String errorMessage = doc["error"] | "";
        
        if (fileName.isEmpty()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Missing file_name\"}");
            return;
        }
        
        // Логирование подтверждения
        if (status == "ok") {
            Serial.printf("[FileReceived] File confirmed: %s (type: %s)\n", fileName.c_str(), fileType.c_str());
        } else {
            Serial.printf("[FileReceived] File confirmation failed: %s (type: %s) - %s\n", 
                         fileName.c_str(), fileType.c_str(), errorMessage.c_str());
        }
        
        JsonDocument response;
        response["success"] = true;
        response["message"] = "Confirmation received";
        
        String responseStr;
        serializeJson(response, responseStr);
        server.send(200, "application/json", responseStr);
    }
    
    /**
     * API endpoint для получения содержимого файла из LittleFS
     * GET /api/file-content?name=filename
     */
    void handleFileContent() {
        totalRequests++;
        
        if (!server.hasArg("name")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Missing file name\"}");
            return;
        }
        
        String fileName = server.arg("name");
        
        // Проверка безопасности: файл должен начинаться с /
        if (!fileName.startsWith("/")) {
            fileName = "/" + fileName;
        }
        
        // Открытие файла
        File file = LittleFS.open(fileName, "r");
        if (!file) {
            JsonDocument response;
            response["success"] = false;
            response["error"] = "File not found";
            
            String responseStr;
            serializeJson(response, responseStr);
            server.send(404, "application/json", responseStr);
            return;
        }
        
        // Чтение содержимого файла
        String content = "";
        while (file.available()) {
            content += (char)file.read();
        }
        file.close();
        
        // Отправка содержимого
        JsonDocument response;
        response["success"] = true;
        response["filename"] = fileName;
        response["size"] = content.length();
        response["content"] = content;
        
        String responseStr;
        serializeJson(response, responseStr);
        server.send(200, "application/json", responseStr);
    }
    
    /**
     * API endpoint для удаления файла из LittleFS
     * POST /api/file-delete
     * Body: {"filename": "/program_5.json"}
     */
    void handleFileDelete() {
        totalRequests++;
        
        // Получение JSON из тела запроса
        String body = server.arg("plain");
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, body);
        
        if (error) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Invalid JSON\"}");
            return;
        }
        
        String fileName = doc["filename"] | "";
        
        if (fileName.isEmpty()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Missing filename\"}");
            return;
        }
        
        // Проверка безопасности: файл должен начинаться с /
        if (!fileName.startsWith("/")) {
            fileName = "/" + fileName;
        }
        
        // Проверка, что это не системный файл
        if (fileName == "/binding.json" || 
            fileName == "/cloud.json" || 
            fileName == "/wifi.json" ||
            fileName == "/config.json" ||
            fileName.startsWith("/system")) {
            server.send(403, "application/json", "{\"success\":false,\"error\":\"Cannot delete system file\"}");
            return;
        }
        
        // Проверка существования файла
        if (!LittleFS.exists(fileName)) {
            server.send(404, "application/json", "{\"success\":false,\"error\":\"File not found\"}");
            return;
        }
        
        // Удаление файла
        if (LittleFS.remove(fileName)) {
            Serial.printf("File deleted: %s\n", fileName.c_str());
            server.send(200, "application/json", "{\"success\":true,\"message\":\"File deleted\"}");
        } else {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"Failed to delete file\"}");
        }
    }
    
    /**
     * API endpoint для принудительной проверки файлов с сервера
     * POST /api/force-check-files
     */
    void handleForceCheckFiles() {
        totalRequests++;
        
        // Проверка, что BindingManager инициализирован
        if (!bindingManager) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"BindingManager not initialized\"}");
            return;
        }
        
        // Проверка, что устройство привязано
        if (!bindingManager->isBound()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Device not bound\"}");
            return;
        }
        
        // Вызов проверки файлов
        Serial.println("Force checking files from web interface...");
        bool result = bindingManager->checkForFiles();
        
        if (result) {
            server.send(200, "application/json", "{\"success\":true,\"message\":\"File check completed\"}");
        } else {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"File check failed\"}");
        }
    }
    
    void handleEmergencyStop() {
        systemState->emergencyStop = true;
        systemState->mode = SystemState::Mode::EMERGENCY_STOP;
        
        server.send(200, "application/json", "{\"success\":true}");
        
        Serial.println("Emergency stop triggered via API");
    }
    
    /**
     * POST /api/smoke-ready — ручное подтверждение розжига дымогенератора
     * Переводит устройство из WAITING_SMOKE_IGNITION в RUNNING
     */
    void handleSmokeReady() {
        if (systemState->mode != SystemState::Mode::WAITING_SMOKE_IGNITION) {
            server.send(400, "application/json", 
                "{\"success\":false,\"error\":\"Device is not waiting for smoke ignition\"}");
            return;
        }
        
        systemState->smokeIgnitionConfirmed = true;
        Serial.println("[SmokeReady] Manual smoke ignition confirmed via API");
        
        server.send(200, "application/json", "{\"success\":true,\"message\":\"Smoke ignition confirmed\"}");
    }
    
    void handleNotFound() {
        server.send(404, "text/plain", "Not Found");
    }
    
    // ========================================
    // ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
    // ========================================
    
    /**
     * Проверка rate limiting для загрузки программ
     * Ограничение: 10 запросов в минуту на device_id
     * 
     * @param deviceId ID устройства для отслеживания
     * @return true если запрос разрешен, false если превышен лимит
     */
    bool checkRateLimit(const String& deviceId) {
        unsigned long currentTime = millis();
        
        // Очистка старых записей (старше 2 минут)
        cleanupRateLimitMap(currentTime);
        
        // Проверка существующей записи
        auto it = rateLimitMap.find(deviceId);
        
        if (it != rateLimitMap.end()) {
            RateLimitEntry& entry = it->second;
            
            // Проверка, прошла ли минута с начала окна
            if (currentTime - entry.windowStartTime >= RATE_LIMIT_WINDOW_MS) {
                // Начать новое окно
                entry.requestCount = 1;
                entry.windowStartTime = currentTime;
                return true;
            }
            
            // В пределах текущего окна
            if (entry.requestCount >= MAX_REQUESTS_PER_MINUTE) {
                // Превышен лимит
                Serial.printf("[WARNING] Rate limit exceeded for device: %s (requests: %d)\n", 
                             deviceId.c_str(), entry.requestCount);
                return false;
            }
            
            // Увеличить счетчик
            entry.requestCount++;
            return true;
        } else {
            // Новая запись
            RateLimitEntry newEntry;
            newEntry.requestCount = 1;
            newEntry.windowStartTime = currentTime;
            rateLimitMap[deviceId] = newEntry;
            return true;
        }
    }
    
    /**
     * Очистка старых записей из rate limit map
     * Удаляет записи старше 2 минут
     * 
     * @param currentTime Текущее время в миллисекундах
     */
    void cleanupRateLimitMap(unsigned long currentTime) {
        const unsigned long CLEANUP_THRESHOLD = RATE_LIMIT_WINDOW_MS * 2; // 2 минуты
        
        auto it = rateLimitMap.begin();
        while (it != rateLimitMap.end()) {
            if (currentTime - it->second.windowStartTime >= CLEANUP_THRESHOLD) {
                it = rateLimitMap.erase(it);
            } else {
                ++it;
            }
        }
    }
    
    String getModeString(SystemState::Mode mode) {
        switch(mode) {
            case SystemState::Mode::IDLE: return "IDLE";
            case SystemState::Mode::RUNNING: return "RUNNING";
            case SystemState::Mode::PAUSED: return "PAUSED";
            case SystemState::Mode::WAITING_SMOKE_IGNITION: return "WAITING_SMOKE_IGNITION";
            case SystemState::Mode::EMERGENCY_STOP: return "EMERGENCY_STOP";
            default: return "UNKNOWN";
        }
    }
    
    String generateMainPage() {
        String html = "<!DOCTYPE html><html><head>";
        html += "<title>Smart Smoker</title>";
        html += "<meta charset='UTF-8'>";
        html += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        html += "<link rel='stylesheet' href='/style.css'>";
        html += "</head><body>";
        html += "<div class='container'>";
        html += "<h1>🔥 Smart Smoker</h1>";
        
        // Навигация
        html += "<div class='controls'>";
        html += "<a href='/programs' class='btn btn-primary'>Программы</a> ";
        html += "<a href='/wifi' class='btn btn-secondary'>WiFi</a> ";
        html += "<a href='/files' class='btn btn-secondary'>Файлы</a> ";
        html += "<a href='/settings' class='btn btn-secondary'>Обновления</a> ";
        
        // Change link text based on binding status
        bool isBound = bindingManager && bindingManager->isBound();
        if (isBound) {
            html += "<a href='/bind' class='btn btn-info'>Настройки устройства</a>";
        } else {
            html += "<a href='/bind' class='btn btn-info'>Привязка</a>";
        }
        
        html += "</div>";
        
        // Update notification banner (checked on page load via JavaScript)
        html += "<div id='update-banner' style='display:none; background:#fff3cd; border:1px solid #ffc107; border-radius:5px; padding:15px; margin:15px 0;'>";
        html += "<strong>📦 Доступно обновление прошивки!</strong><br>";
        html += "<span id='update-banner-info'></span><br>";
        html += "<a href='/settings' class='btn btn-primary' style='margin-top:10px;'>Перейти к обновлениям</a>";
        html += "</div>";
        
        // Статус датчиков - Температурные датчики
        html += "<div class='status-card sensor-group'>";
        html += "<h3 class='sensor-group-title'>🌡️ Температурные датчики</h3>";
        html += "<div class='status-grid'>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Камера</h4>";
        html += "<div class='value'>";
        html += !isnan(systemState->tempChamber) ? String(systemState->tempChamber, 1) + "°C" : "--°C";
        html += "</div></div>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Дым</h4>";
        html += "<div class='value'>";
        html += !isnan(systemState->tempSmoke) ? String(systemState->tempSmoke, 1) + "°C" : "--°C";
        html += "</div></div>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Продукт</h4>";
        html += "<div class='value'>";
        html += !isnan(systemState->tempProduct) ? String(systemState->tempProduct, 1) + "°C" : "--°C";
        html += "</div></div>";
        
        html += "</div></div>"; // end temperature sensors group
        
        // Влажность
        html += "<div class='status-card sensor-group'>";
        html += "<h3 class='sensor-group-title'>💧 Влажность</h3>";
        html += "<div class='value' style='text-align:center'>";
        html += !isnan(systemState->humidity) ? String(systemState->humidity, 0) + "%" : "--%";
        html += "</div></div>";
        
        // Исполнительные устройства
        html += "<div class='status-card sensor-group'>";
        html += "<h3 class='sensor-group-title'>⚙️ Исполнительные устройства</h3>";
        html += "<div class='status-grid'>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Нагреватель</h4>";
        html += "<div class='value' style='font-size:1.5rem;color:" + String(systemState->heaterOn ? "#28a745" : "#6c757d") + "'>";
        html += systemState->heaterOn ? "Вкл" : "Выкл";
        html += "</div></div>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Дымогенератор</h4>";
        html += "<div class='value' style='font-size:1.5rem;color:" + String(systemState->smokePWM > 0 ? "#28a745" : "#6c757d") + "'>";
        html += systemState->smokePWM > 0 ? String(systemState->smokePWM) + "%" : "Выкл";
        html += "</div></div>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Заслонка</h4>";
        html += "<div class='value' style='font-size:1.5rem'>";
        html += String(systemState->damperPosition) + "°";
        html += "</div></div>";
        
        html += "<div class='status-card-inner'>";
        html += "<h4>Вентилятор</h4>";
        html += "<div class='value' style='font-size:1.5rem;color:" + String(systemState->fanInjectionOn ? "#28a745" : "#6c757d") + "'>";
        html += systemState->fanInjectionOn ? "Вкл" : "Выкл";
        html += "</div></div>";
        
        html += "</div></div>"; // end actuators group
        
        // Статус программы
        html += "<div class='controls'>";
        html += "<h3>📋 Статус программы</h3>";
        html += "<p><strong>Режим:</strong> " + getModeString(systemState->mode) + "</p>";
        
        if (systemState->emergencyStop) {
            html += "<div class='alert alert-danger'>⚠️ АВАРИЙНАЯ ОСТАНОВКА</div>";
        }
        
        // Блок ожидания розжига дымогенератора
        if (systemState->mode == SystemState::Mode::WAITING_SMOKE_IGNITION) {
            unsigned long elapsed = (millis() - systemState->smokeIgnitionStartTime) / 1000;
            unsigned long remaining = 0;
            if (SystemState::SMOKE_IGNITION_TIMEOUT_MS / 1000 > elapsed) {
                remaining = SystemState::SMOKE_IGNITION_TIMEOUT_MS / 1000 - elapsed;
            }
            html += "<div style='background:#fff3cd;border:2px solid #ffc107;border-radius:8px;padding:16px;margin:10px 0;text-align:center'>";
            html += "<div style='font-size:2rem'>🔥</div>";
            html += "<h3 style='color:#856404;margin:8px 0'>Подожгите щепу!</h3>";
            html += "<p style='color:#856404'>Компрессор запущен. Розжигайте дымогенератор и нажмите кнопку готовности.</p>";
            html += "<p style='color:#856404'>Осталось времени: <strong id='smoke-countdown'>" + String(remaining) + "</strong> сек</p>";
            html += "<button onclick='confirmSmokeReady()' style='background:#28a745;color:white;border:none;padding:12px 24px;border-radius:6px;font-size:1rem;cursor:pointer;margin-top:8px'>✅ Дымогенератор готов</button>";
            html += "</div>";
            html += "<script>";
            html += "var smokeCountdown = " + String(remaining) + ";";
            html += "var smokeTimer = setInterval(function(){";
            html += "  if(smokeCountdown > 0) { smokeCountdown--; }";
            html += "  var el = document.getElementById('smoke-countdown');";
            html += "  if(el) el.textContent = smokeCountdown;";
            html += "}, 1000);";
            html += "function confirmSmokeReady() {";
            html += "  fetch('/api/smoke-ready', {method:'POST',headers:{'Content-Type':'application/json'},body:'{}'})";
            html += "  .then(r=>r.json())";
            html += "  .then(d=>{ if(d.success){ clearInterval(smokeTimer); location.reload(); } else { alert('Ошибка: '+d.error); } })";
            html += "  .catch(e=>alert('Ошибка сети: '+e.message));";
            html += "}";
            html += "</script>";
        }
        
        html += "</div>";
        
        html += "</div>";
        
        // JavaScript для автообновления и проверки обновлений
        html += "<script>";
        
        // Check for updates on page load
        html += "function checkForUpdates() {";
        html += "  fetch('/api/update-settings')";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      if(data.has_pending_update) {";
        html += "        const banner = document.getElementById('update-banner');";
        html += "        const info = document.getElementById('update-banner-info');";
        html += "        let msg = 'Версия ' + data.pending_version;";
        html += "        if(data.pending_is_required) msg += ' (обязательное обновление)';";
        html += "        info.textContent = msg;";
        html += "        banner.style.display = 'block';";
        html += "      }";
        html += "    })";
        html += "    .catch(e => console.error('Update check error:', e));";
        html += "}";
        html += "checkForUpdates();";
        
        html += "setInterval(function() {";
        html += "  fetch('/api/state')";
        html += "    .then(response => response.json())";
        html += "    .then(data => console.log('Status updated:', data))";
        html += "    .catch(error => console.error('Error:', error));";
        html += "}, 5000);";
        html += "</script>";
        
        html += "</body></html>";
        
        return html;
    }
    
    // =====================================================
    // UPDATE SETTINGS HANDLERS
    // =====================================================
    
    /**
     * Страница настроек обновлений
     */
    void handleSettingsPage() {
        // Send response in chunks to avoid buffer overflow
        server.setContentLength(CONTENT_LENGTH_UNKNOWN);
        server.send(200, "text/html", "");
        
        server.sendContent("<!DOCTYPE html><html><head>");
        server.sendContent("<title>⚙️ Настройки обновлений - Smart Smoker</title>");
        server.sendContent("<meta charset='UTF-8'>");
        server.sendContent("<meta name='viewport' content='width=device-width, initial-scale=1.0'>");
        server.sendContent("<link rel='stylesheet' href='/style.css'>");
        server.sendContent("</head><body>");
        server.sendContent("<div class='container'>");
        server.sendContent("<h1>⚙️ Настройки обновлений</h1>");
        
        // Current Status
        server.sendContent("<div class='status-card'>");
        server.sendContent("<h3>📊 Текущее состояние</h3>");
        server.sendContent("<p>Версия прошивки: <strong id='current-version'>Загрузка...</strong></p>");
        server.sendContent("<p>Последняя проверка: <strong id='last-check'>Загрузка...</strong></p>");
        server.sendContent("<p>Статус: <strong id='update-status'>Загрузка...</strong></p>");
        server.sendContent("<div id='update-info' style='display:none; background:#fff3cd; padding:10px; border-radius:5px; margin:10px 0;'></div>");
        server.sendContent("<button onclick='checkNow()' class='btn btn-primary'>🔍 Проверить сейчас</button> ");
        server.sendContent("<button id='download-btn' onclick='downloadAndInstall()' class='btn btn-success' style='display:none;'>⬇️ Скачать и обновить</button>");
        server.sendContent("</div>");
        
        // Settings Form
        server.sendContent("<div class='status-card'>");
        server.sendContent("<h3>⚙️ Настройки</h3>");
        server.sendContent("<form id='settings-form'>");
        server.sendContent("<div class='form-group'>");
        server.sendContent("<label><input type='checkbox' id='auto-update-enabled' checked> Автоматические обновления</label>");
        server.sendContent("</div>");
        server.sendContent("<div class='form-group'>");
        server.sendContent("<label>Политика обновлений:</label>");
        server.sendContent("<select id='update-policy' class='form-control'>");
        server.sendContent("<option value='all_updates'>Все обновления</option>");
        server.sendContent("<option value='required_only'>Только обязательные</option>");
        server.sendContent("</select>");
        server.sendContent("</div>");
        server.sendContent("<div class='form-group'>");
        server.sendContent("<label>Интервал проверки (секунды):</label>");
        server.sendContent("<input type='number' id='check-interval' class='form-control' min='300' max='86400' value='3600'>");
        server.sendContent("<small>Минимум: 300 (5 мин), Максимум: 86400 (24 часа)</small>");
        server.sendContent("</div>");
        server.sendContent("<button type='submit' class='btn btn-success'>💾 Сохранить</button>");
        server.sendContent("</form>");
        server.sendContent("<div id='settings-status' style='margin-top:10px;'></div>");
        server.sendContent("</div>");
        
        // Update Log
        server.sendContent("<div class='status-card'>");
        server.sendContent("<h3>📋 История обновлений</h3>");
        server.sendContent("<div id='update-log'>Загрузка...</div>");
        server.sendContent("</div>");
        server.sendContent("<div class='controls'><a href='/' class='btn btn-secondary'>На главную</a></div>");
        server.sendContent("</div>");
        
        // JavaScript - send in chunks
        server.sendContent("<script>");
        server.sendContent("function loadSettings(){fetch('/api/update-settings').then(r=>r.json()).then(data=>{");
        server.sendContent("document.getElementById('current-version').textContent=data.current_version||'Неизвестно';");
        server.sendContent("document.getElementById('last-check').textContent=data.last_check_formatted||'Никогда';");
        server.sendContent("document.getElementById('update-status').textContent=data.status||'Неизвестно';");
        server.sendContent("document.getElementById('auto-update-enabled').checked=data.enabled;");
        server.sendContent("document.getElementById('update-policy').value=data.policy;");
        server.sendContent("document.getElementById('check-interval').value=data.check_interval;");
        server.sendContent("const updateInfoEl=document.getElementById('update-info');");
        server.sendContent("const downloadBtn=document.getElementById('download-btn');");
        server.sendContent("if(data.has_pending_update){");
        server.sendContent("updateInfoEl.innerHTML='<strong>📦 Новая версия: '+data.pending_version+'</strong>';");
        server.sendContent("if(data.pending_release_notes){const notes=data.pending_release_notes.split('\\n').join('<br>');");
        server.sendContent("updateInfoEl.innerHTML+='<br><small>'+notes+'</small>';}");
        server.sendContent("updateInfoEl.style.display='block';downloadBtn.style.display='inline-block';");
        server.sendContent("}else{updateInfoEl.style.display='none';downloadBtn.style.display='none';}");
        server.sendContent("}).catch(e=>console.error('Error:',e));}");
        
        server.sendContent("function loadUpdateLog(){fetch('/api/update-log').then(r=>r.json()).then(data=>{");
        server.sendContent("let html='';if(data.entries&&data.entries.length>0){");
        server.sendContent("html='<table style=\"width:100%;border-collapse:collapse\">';");
        server.sendContent("html+='<tr style=\"background:#f0f0f0\"><th style=\"padding:8px;text-align:left;border:1px solid #ddd\">Время</th>");
        server.sendContent("<th style=\"padding:8px;text-align:left;border:1px solid #ddd\">Событие</th>");
        server.sendContent("<th style=\"padding:8px;text-align:left;border:1px solid #ddd\">Сообщение</th>");
        server.sendContent("<th style=\"padding:8px;text-align:center;border:1px solid #ddd\">Статус</th></tr>';");
        server.sendContent("data.entries.forEach(e=>{html+='<tr><td style=\"padding:8px;border:1px solid #ddd\">'+e.timestamp+'</td>");
        server.sendContent("<td style=\"padding:8px;border:1px solid #ddd\">'+e.type+'</td>");
        server.sendContent("<td style=\"padding:8px;border:1px solid #ddd\">'+e.message+'</td>");
        server.sendContent("<td style=\"padding:8px;text-align:center;border:1px solid #ddd\">'+e.icon+'</td></tr>';});");
        server.sendContent("html+='</table>';}else{html='<p>Нет записей</p>';}");
        server.sendContent("document.getElementById('update-log').innerHTML=html;");
        server.sendContent("}).catch(e=>{document.getElementById('update-log').innerHTML='<p>Ошибка</p>';});}");
        
        server.sendContent("document.getElementById('settings-form').onsubmit=function(e){e.preventDefault();");
        server.sendContent("const data={enabled:document.getElementById('auto-update-enabled').checked,");
        server.sendContent("policy:document.getElementById('update-policy').value,");
        server.sendContent("check_interval:parseInt(document.getElementById('check-interval').value)};");
        server.sendContent("const statusDiv=document.getElementById('settings-status');");
        server.sendContent("statusDiv.innerHTML='<p>Сохранение...</p>';");
        server.sendContent("fetch('/api/update-settings',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)})");
        server.sendContent(".then(r=>r.json()).then(data=>{if(data.success){");
        server.sendContent("statusDiv.innerHTML='<p class=\"success\">✓ Сохранено!</p>';");
        server.sendContent("setTimeout(()=>statusDiv.innerHTML='',3000);}else{");
        server.sendContent("statusDiv.innerHTML='<p class=\"error\">✗ Ошибка: '+(data.error||'Неизвестная ошибка')+'</p>';}");
        server.sendContent("}).catch(e=>{statusDiv.innerHTML='<p class=\"error\">✗ Ошибка: '+e.message+'</p>';});};");
        
        server.sendContent("function checkNow(){const statusEl=document.getElementById('update-status');");
        server.sendContent("const updateInfoEl=document.getElementById('update-info');");
        server.sendContent("const downloadBtn=document.getElementById('download-btn');");
        server.sendContent("const btn=event.target;btn.disabled=true;statusEl.textContent='Проверка...';");
        server.sendContent("updateInfoEl.style.display='none';downloadBtn.style.display='none';");
        server.sendContent("fetch('/api/check-update-now',{method:'POST'}).then(r=>r.json()).then(data=>{");
        server.sendContent("btn.disabled=false;if(data.success){if(data.update_available){");
        server.sendContent("let msg='Доступно: v'+data.version;");
        server.sendContent("if(data.is_required)msg+=' (обязательное)';");
        server.sendContent("if(data.file_size)msg+=' - '+(data.file_size/1024/1024).toFixed(2)+' MB';");
        server.sendContent("statusEl.innerHTML=msg;");
        server.sendContent("updateInfoEl.innerHTML='<strong>📦 Новая версия: '+data.version+'</strong>';");
        server.sendContent("if(data.release_notes){const notes=data.release_notes.split('\\n').join('<br>');");
        server.sendContent("updateInfoEl.innerHTML+='<br><small>'+notes+'</small>';}");
        server.sendContent("updateInfoEl.style.display='block';downloadBtn.style.display='inline-block';");
        server.sendContent("}else{statusEl.textContent='Обновлений нет';}loadSettings();loadUpdateLog();}else{");
        server.sendContent("statusEl.textContent='Ошибка: '+(data.error||'Неизвестная ошибка');}");
        server.sendContent("}).catch(e=>{btn.disabled=false;statusEl.textContent='Ошибка: '+e.message;});}");
        
        server.sendContent("function downloadAndInstall(){");
        server.sendContent("if(!confirm('Начать загрузку и установку? Контроллер перезагрузится.'))return;");
        server.sendContent("const statusEl=document.getElementById('update-status');");
        server.sendContent("const downloadBtn=document.getElementById('download-btn');");
        server.sendContent("downloadBtn.disabled=true;statusEl.textContent='Загрузка...';");
        server.sendContent("fetch('/api/install-update',{method:'POST'}).then(r=>r.json()).then(data=>{");
        server.sendContent("if(data.success){statusEl.textContent='Установка...';");
        server.sendContent("setTimeout(()=>{statusEl.textContent='Перезагрузка...';},2000);}else{");
        server.sendContent("downloadBtn.disabled=false;statusEl.textContent='Ошибка: '+(data.error||'Неизвестная ошибка');}");
        server.sendContent("}).catch(e=>{downloadBtn.disabled=false;statusEl.textContent='Ошибка: '+e.message;});}");
        
        server.sendContent("loadSettings();loadUpdateLog();");
        server.sendContent("</script></body></html>");
        server.sendContent("");
    }
    
    /**
     * GET /api/update-settings - Получить текущие настройки обновлений
     */
    void handleGetUpdateSettings() {
        if (!autoUpdateClient) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"AutoUpdateClient not initialized\"}");
            return;
        }
        
        JsonDocument doc;
        
        auto config = autoUpdateClient->getConfig();
        doc["enabled"] = config.enabled;
        doc["policy"] = (config.policy == AutoUpdateClient::UpdatePolicy::ALL_UPDATES) ? "all_updates" : "required_only";
        doc["check_interval"] = config.checkIntervalSeconds;
        doc["current_version"] = systemState->firmwareVersion;
        
        unsigned long lastCheck = autoUpdateClient->getLastCheckTime();
        doc["last_check"] = lastCheck;
        
        // Format last check time
        if (lastCheck > 0) {
            unsigned long secondsAgo = (millis() - lastCheck) / 1000;
            doc["last_check_formatted"] = formatTimeAgo(secondsAgo) + " назад";
        } else {
            doc["last_check_formatted"] = "Никогда";
        }
        
        // Get status
        auto state = autoUpdateClient->getState();
        String statusStr;
        switch(state) {
            case AutoUpdateClient::UpdateState::IDLE: statusStr = "Ожидание"; break;
            case AutoUpdateClient::UpdateState::CHECKING: statusStr = "Проверка обновлений"; break;
            case AutoUpdateClient::UpdateState::DOWNLOADING: statusStr = "Загрузка"; break;
            case AutoUpdateClient::UpdateState::VERIFYING: statusStr = "Проверка целостности"; break;
            case AutoUpdateClient::UpdateState::INSTALLING: statusStr = "Установка"; break;
            case AutoUpdateClient::UpdateState::REBOOTING: statusStr = "Перезагрузка"; break;
            case AutoUpdateClient::UpdateState::FAILED: statusStr = "Ошибка"; break;
            default: statusStr = "Неизвестно"; break;
        }
        doc["status"] = statusStr;
        
        // Add pending update information
        bool hasPending = autoUpdateClient->hasPendingUpdate();
        doc["has_pending_update"] = hasPending;
        
        if (hasPending) {
            auto pending = autoUpdateClient->getPendingUpdate();
            doc["pending_version"] = pending.version;
            doc["pending_is_required"] = pending.isRequired;
            doc["pending_file_size"] = pending.fileSize;
            doc["pending_release_notes"] = pending.releaseNotes;
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    /**
     * POST /api/update-settings - Сохранить настройки обновлений
     */
    void handlePostUpdateSettings() {
        if (!autoUpdateClient) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"AutoUpdateClient not initialized\"}");
            return;
        }
        
        if (!server.hasArg("plain")) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"No data\"}");
            return;
        }
        
        String body = server.arg("plain");
        JsonDocument doc;
        
        if (deserializeJson(doc, body) != DeserializationError::Ok) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Invalid JSON\"}");
            return;
        }
        
        // Validate check_interval
        int checkInterval = doc["check_interval"] | 3600;
        if (checkInterval < 300 || checkInterval > 86400) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"Check interval must be between 300 and 86400 seconds\"}");
            return;
        }
        
        // Update configuration
        AutoUpdateClient::UpdateConfig config;
        config.enabled = doc["enabled"] | true;
        
        String policyStr = doc["policy"] | "all_updates";
        config.policy = (policyStr == "required_only") ? 
            AutoUpdateClient::UpdatePolicy::REQUIRED_ONLY : 
            AutoUpdateClient::UpdatePolicy::ALL_UPDATES;
        
        config.checkIntervalSeconds = checkInterval;
        config.retryDelaySeconds = 300;
        config.maxRetries = 3;
        
        autoUpdateClient->setConfig(config);
        autoUpdateClient->saveConfig();
        
        server.send(200, "application/json", "{\"success\":true,\"message\":\"Settings saved\"}");
    }
    
    /**
     * POST /api/check-update-now - Проверить обновления сейчас
     */
    void handleCheckUpdateNow() {
        if (!autoUpdateClient) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"AutoUpdateClient not initialized\"}");
            return;
        }
        
        // Trigger immediate check (without auto-install)
        bool success = autoUpdateClient->checkForUpdateOnly();
        
        JsonDocument doc;
        doc["success"] = success;
        
        if (success) {
            // Check if update is available
            bool hasUpdate = autoUpdateClient->hasPendingUpdate();
            doc["update_available"] = hasUpdate;
            
            if (hasUpdate) {
                auto pending = autoUpdateClient->getPendingUpdate();
                doc["version"] = pending.version;
                doc["is_required"] = pending.isRequired;
                doc["file_size"] = pending.fileSize;
                doc["release_notes"] = pending.releaseNotes;
            } else {
                doc["version"] = "";
            }
        } else {
            doc["error"] = autoUpdateClient->getLastError();
            doc["update_available"] = false;
            doc["version"] = "";
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    /**
     * POST /api/install-update - Скачать и установить обновление
     */
    void handleInstallUpdate() {
        if (!autoUpdateClient) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"AutoUpdateClient not initialized\"}");
            return;
        }
        
        // Check if update is available
        if (!autoUpdateClient->hasPendingUpdate()) {
            server.send(400, "application/json", "{\"success\":false,\"error\":\"No pending update\"}");
            return;
        }
        
        // Trigger the download and installation process
        bool success = autoUpdateClient->forceInstallPendingUpdate();
        
        JsonDocument doc;
        doc["success"] = success;
        
        if (success) {
            doc["message"] = "Update installation started";
        } else {
            doc["error"] = autoUpdateClient->getLastError();
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
        
        if (success) {
            Serial.println("[UPDATE] Manual update installation triggered from web interface");
        }
    }
    
    /**
     * GET /api/update-log - Получить журнал обновлений
     */
    void handleGetUpdateLog() {
        if (!autoUpdateClient) {
            server.send(500, "application/json", "{\"success\":false,\"error\":\"AutoUpdateClient not initialized\"}");
            return;
        }
        
        JsonDocument doc;
        JsonArray entries = doc["entries"].to<JsonArray>();
        
        auto logEntries = autoUpdateClient->getLogger().getEntries(50);
        
        for (const auto& entry : logEntries) {
            JsonObject obj = entries.add<JsonObject>();
            
            // Convert timestamp to readable format
            unsigned long secondsAgo = (millis() - entry.timestamp) / 1000;
            obj["timestamp"] = formatTimeAgo(secondsAgo) + " назад";
            
            obj["type"] = autoUpdateClient->getLogger().eventTypeToString(entry.type);
            obj["message"] = entry.message;
            obj["version"] = entry.version;
            obj["success"] = entry.success;
            obj["icon"] = entry.success ? "✅" : "❌";
        }
        
        String response;
        serializeJson(doc, response);
        server.send(200, "application/json", response);
    }
    
    // =====================================================
    // OTA UPDATE HANDLERS
    // =====================================================
    
    /**
     * Страница OTA обновления
     */
    void handleOTAPage() {
        String html = FPSTR(OTA_UPDATE_PAGE);
        
        // Замена плейсхолдеров
        html.replace("%FIRMWARE_VERSION%", systemState->firmwareVersion);
        html.replace("%DEVICE_ID%", systemState->deviceId.isEmpty() ? "Не привязано" : systemState->deviceId);
        html.replace("%FREE_SPACE%", String(ESP.getFreeSketchSpace() / 1024));
        
        server.send(200, "text/html", html);
    }
    
    /**
     * Обработка прогресса загрузки OTA
     */
    void handleOTAUpdateProgress() {
        HTTPUpload& upload = server.upload();
        
        if (upload.status == UPLOAD_FILE_START) {
            Serial.printf("OTA Update Start: %s\n", upload.filename.c_str());
            
            // Остановка программы копчения
            if (systemState->mode == SystemState::Mode::RUNNING) {
                systemState->mode = SystemState::Mode::IDLE;
            }
            
            if (!Update.begin(UPDATE_SIZE_UNKNOWN)) {
                Update.printError(Serial);
            }
        } 
        else if (upload.status == UPLOAD_FILE_WRITE) {
            if (Update.write(upload.buf, upload.currentSize) != upload.currentSize) {
                Update.printError(Serial);
            } else {
                // Вывод прогресса каждые 10%
                static int lastProgress = 0;
                int progress = (Update.progress() * 100) / Update.size();
                if (progress - lastProgress >= 10) {
                    Serial.printf("OTA Progress: %d%%\n", progress);
                    lastProgress = progress;
                }
            }
        } 
        else if (upload.status == UPLOAD_FILE_END) {
            if (Update.end(true)) {
                Serial.printf("OTA Update Success: %u bytes\n", upload.totalSize);
            } else {
                Update.printError(Serial);
            }
        }
    }
    
    /**
     * Завершение OTA обновления
     */
    void handleOTAUpdateEnd() {
        if (Update.hasError()) {
            server.send(500, "text/plain", "Update Failed");
        } else {
            server.send(200, "text/plain", "Update Success! Rebooting...");
            delay(1000);
            ESP.restart();
        }
    }
    
    /**
     * Форматирование времени в человекочитаемый формат "сколько времени назад"
     */
    String formatTimeAgo(unsigned long seconds) {
        if (seconds < 60) {
            return String(seconds) + " секунд";
        } else if (seconds < 3600) {
            unsigned long minutes = seconds / 60;
            return String(minutes) + " минут";
        } else if (seconds < 86400) {
            unsigned long hours = seconds / 3600;
            return String(hours) + " часов";
        } else {
            unsigned long days = seconds / 86400;
            return String(days) + " дней";
        }
    }
    
    /**
     * Страница просмотра файлов LittleFS
     */
    void handleFilesPage() {
        totalRequests++;
        
        String html = "<!DOCTYPE html><html><head>";
        html += "<title>Файлы LittleFS - Smart Smoker</title>";
        html += "<meta charset='UTF-8'>";
        html += "<meta name='viewport' content='width=device-width, initial-scale=1.0'>";
        html += "<link rel='stylesheet' href='/style.css'>";
        html += "</head><body>";
        html += "<div class='container'>";
        html += "<h1>📁 Файлы LittleFS</h1>";
        
        // Информация о файловой системе
        html += "<div class='info-box'>";
        html += "<h3>Информация о файловой системе</h3>";
        html += "<p>Всего: " + String(LittleFS.totalBytes()) + " байт</p>";
        html += "<p>Использовано: " + String(LittleFS.usedBytes()) + " байт</p>";
        html += "<p>Свободно: " + String(LittleFS.totalBytes() - LittleFS.usedBytes()) + " байт</p>";
        html += "</div>";
        
        // Кнопка принудительной проверки файлов
        html += "<div class='controls' style='margin-bottom:20px;'>";
        html += "<button onclick='forceCheckFiles()' class='btn btn-primary'>🔄 Проверить файлы сейчас</button>";
        html += "<span id='check-status' style='margin-left:10px;'></span>";
        html += "</div>";
        
        // Функция для добавления файлов из директории с заголовком секции
        auto addFilesFromDir = [&](const char* dirPath, const char* dirLabel) {
            File dir = LittleFS.open(dirPath);
            if (!dir || !dir.isDirectory()) {
                return;
            }
            
            // Добавляем заголовок секции директории
            html += "<div class='controls' style='margin-top:20px; margin-bottom:10px;'>";
            html += "<h3 style='margin:0; padding:10px; background:#f8f9fa; border-left:4px solid #007bff;'>";
            html += String(dirLabel);
            html += "</h3>";
            html += "</div>";
            
            // Таблица файлов для этой директории
            html += "<table style='width:100%; border-collapse: collapse; margin-bottom:20px;'>";
            html += "<tr style='background:#f0f0f0'>";
            html += "<th style='padding:8px; text-align:left; border:1px solid #ddd;'>Имя файла</th>";
            html += "<th style='padding:8px; text-align:right; border:1px solid #ddd;'>Размер</th>";
            html += "<th style='padding:8px; text-align:center; border:1px solid #ddd;'>Действия</th>";
            html += "</tr>";
            
            int fileCount = 0;
            File file = dir.openNextFile();
            while (file) {
                // Пропускаем директории - показываем только файлы
                if (file.isDirectory()) {
                    file = dir.openNextFile();
                    continue;
                }
                
                String fileName = String(file.name());
                size_t fileSize = file.size();
                
                // Полный путь к файлу
                String fullPath = String(dirPath) + "/" + fileName;
                if (String(dirPath) == "/") {
                    fullPath = "/" + fileName;
                }
                
                // Проверка, является ли файл системным (нельзя удалять)
                // Системные файлы: все *.json в корне, device_id.txt, rollback_count.txt, firmware_temp.bin
                bool isSystemFile = false;
                if (String(dirPath) == "/") {
                    // В корневой директории защищаем все .json файлы и системные файлы
                    isSystemFile = (fullPath.endsWith(".json") || 
                                   fullPath == "/device_id.txt" ||
                                   fullPath == "/rollback_count.txt" ||
                                   fullPath == "/firmware_temp.bin");
                }
                
                html += "<tr>";
                html += "<td style='padding:8px; border:1px solid #ddd;'>" + fileName;
                // Добавляем метку для системных файлов
                if (isSystemFile) {
                    html += " <span style='color:#6c757d; font-size:11px;'>(системный)</span>";
                }
                html += "</td>";
                html += "<td style='padding:8px; text-align:right; border:1px solid #ddd;'>" + String(fileSize) + " байт</td>";
                html += "<td style='padding:8px; text-align:center; border:1px solid #ddd;'>";
                html += "<button onclick='viewFile(\"" + fullPath + "\")' class='btn btn-primary' style='padding:4px 8px; font-size:12px; margin-right:5px;'>Просмотр</button>";
                // Показываем кнопку удаления только для несистемных файлов
                if (!isSystemFile) {
                    html += "<button onclick='deleteFile(\"" + fullPath + "\")' class='btn btn-danger' style='padding:4px 8px; font-size:12px;'>Удалить</button>";
                }
                html += "</td>";
                html += "</tr>";
                
                fileCount++;
                file = dir.openNextFile();
            }
            
            // Если нет файлов в директории
            if (fileCount == 0) {
                html += "<tr>";
                html += "<td colspan='3' style='padding:8px; text-align:center; color:#999; border:1px solid #ddd;'>Нет файлов в этой директории</td>";
                html += "</tr>";
            }
            
            html += "</table>";
        };
        
        // Добавляем файлы из корневой директории
        addFilesFromDir("/", "📂 Корневая директория (/)");
        
        // Добавляем файлы из директории /programs/
        if (LittleFS.exists("/programs")) {
            addFilesFromDir("/programs", "📋 Директория программ (/programs/)");
        }
        
        html += "<div style='margin-top:20px;'>";
        html += "<a href='/' class='btn btn-secondary'>На главную</a>";
        html += "</div>";
        html += "</div>";
        
        // Модальное окно для просмотра содержимого файла
        html += "<div id='fileModal' style='display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000;'>";
        html += "<div style='position:relative; margin:50px auto; width:80%; max-width:800px; background:white; padding:20px; border-radius:8px; max-height:80vh; overflow:auto;'>";
        html += "<button onclick='closeModal()' style='position:absolute; top:10px; right:10px; background:#dc3545; color:white; border:none; padding:5px 10px; cursor:pointer; border-radius:4px;'>✕</button>";
        html += "<h3 id='fileName'></h3>";
        html += "<pre id='fileContent' style='background:#f5f5f5; padding:15px; border-radius:4px; overflow-x:auto; white-space:pre-wrap; word-wrap:break-word;'></pre>";
        html += "</div>";
        html += "</div>";
        
        // JavaScript
        html += "<script>";
        html += "function viewFile(filename) {";
        html += "  fetch('/api/file-content?name=' + encodeURIComponent(filename))";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      if(data.success) {";
        html += "        document.getElementById('fileName').textContent = filename;";
        html += "        document.getElementById('fileContent').textContent = data.content;";
        html += "        document.getElementById('fileModal').style.display = 'block';";
        html += "      } else {";
        html += "        alert('Ошибка: ' + data.error);";
        html += "      }";
        html += "    })";
        html += "    .catch(e => alert('Ошибка загрузки файла: ' + e));";
        html += "}";
        html += "function closeModal() {";
        html += "  document.getElementById('fileModal').style.display = 'none';";
        html += "}";
        html += "function deleteFile(filename) {";
        html += "  if(!confirm('Вы уверены, что хотите удалить файл \"' + filename + '\"?')) return;";
        html += "  fetch('/api/file-delete', {";
        html += "    method: 'POST',";
        html += "    headers: {'Content-Type': 'application/json'},";
        html += "    body: JSON.stringify({filename: filename})";
        html += "  })";
        html += "  .then(r => r.json())";
        html += "  .then(data => {";
        html += "    if(data.success) {";
        html += "      alert('Файл удален!');";
        html += "      window.location.reload();";
        html += "    } else {";
        html += "      alert('Ошибка: ' + data.error);";
        html += "    }";
        html += "  })";
        html += "  .catch(e => alert('Ошибка удаления файла: ' + e));";
        html += "}";
        html += "function forceCheckFiles() {";
        html += "  var statusSpan = document.getElementById('check-status');";
        html += "  statusSpan.textContent = 'Проверка...';";
        html += "  statusSpan.style.color = '#007bff';";
        html += "  fetch('/api/force-check-files', {method: 'POST'})";
        html += "    .then(r => r.json())";
        html += "    .then(data => {";
        html += "      if(data.success) {";
        html += "        statusSpan.textContent = '✓ Проверка выполнена';";
        html += "        statusSpan.style.color = '#28a745';";
        html += "        setTimeout(() => { statusSpan.textContent = ''; }, 3000);";
        html += "      } else {";
        html += "        statusSpan.textContent = '✗ Ошибка: ' + data.error;";
        html += "        statusSpan.style.color = '#dc3545';";
        html += "      }";
        html += "    })";
        html += "    .catch(e => {";
        html += "      statusSpan.textContent = '✗ Ошибка: ' + e.message;";
        html += "      statusSpan.style.color = '#dc3545';";
        html += "    });";
        html += "}";
        html += "</script>";
        
        html += "</body></html>";
        
        server.send(200, "text/html", html);
    }
};

#endif // WEB_SERVER_MANAGER_FIXED_H
