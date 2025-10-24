#include <Arduino.h>
#include <WiFi.h>
#include <Wire.h>
#include <LittleFS.h>
#include <ESPAsyncWebServer.h>
#include <ArduinoJson.h>
#include <driver/adc.h>
#include <U8g2lib.h>
#include <Adafruit_Sensor.h>
#include <Adafruit_BME280.h>
#include <math.h>
#include "Config.h"
#include "ProgramTypes.h"
#include "SystemState.h"
#include "OLEDHandler.h"
#include "ButtonHandler.h"

AsyncWebServer server(80);
OLEDHandler oled;
ButtonHandler buttons;

String requestBody;

// === SystemState singleton ===
SystemState& SystemState::getInstance() {
  static SystemState instance;
  return instance;
}
SystemState& systemState = SystemState::getInstance();

// === Датчики ===
Adafruit_BME280 bme;
bool bmeFound = false;

// === NTC параметры ===
constexpr float R_REF = 10000.0f;
constexpr float NTC_BETA = 3950.0f;
constexpr float NTC_R0 = 10000.0f;
constexpr float T0_KELVIN = 298.15f;

// === Управление ===
unsigned long lastSensorRead = 0;
unsigned long lastHeaterToggle = 0;
bool heaterState = false;
int targetSmokePWM = 0;

// === Чтение NTC ===
float readNTC(uint8_t pin) {
  adc1_config_width(ADC_WIDTH_BIT_12);
  adc1_channel_t channel;
  if (pin == 36) channel = ADC1_CHANNEL_0;
  else if (pin == 37) channel = ADC1_CHANNEL_1;
  else if (pin == 38) channel = ADC1_CHANNEL_2;
  else if (pin == 39) channel = ADC1_CHANNEL_3;
  else if (pin == 32) channel = ADC1_CHANNEL_4;
  else if (pin == 33) channel = ADC1_CHANNEL_5;
  else if (pin == 34) channel = ADC1_CHANNEL_6;
  else if (pin == 35) channel = ADC1_CHANNEL_7;
  else return -1000;

  adc1_config_channel_atten(channel, ADC_ATTEN_DB_11);
  uint32_t sum = 0;
  for (int i = 0; i < 8; i++) {
    sum += adc1_get_raw(channel);
    delay(2);
  }
  int raw = sum / 8;
  float voltage = raw * (3.3f * 1.1f) / 4095.0f;
  if (voltage <= 0 || voltage >= 3.63f) return -999;
  float R_ntc = R_REF * (3.63f - voltage) / voltage;
  float lnR = log(R_ntc / NTC_R0);
  float T_inv = (1.0f / T0_KELVIN) + (1.0f / NTC_BETA) * lnR;
  float T_celsius = (1.0f / T_inv) - 273.15f;
  if (T_celsius < -20 || T_celsius > 150) return -999;
  return T_celsius;
}

// === Управление ТЭН ===
void updateHeater(float currentTemp, float targetTemp, int hysteresis = 2) {
  if (systemState.emergencyStop || currentTemp >= MAX_TEMP_LIMIT) {
    digitalWrite(PIN_HEATER_SSR, LOW);
    heaterState = false;
    systemState.heaterOn = false;
    if (currentTemp >= MAX_TEMP_LIMIT) {
      systemState.emergencyStop = true;
      systemState.mode = SystemState::SystemMode::IDLE;
      Serial.println("EMERGENCY STOP: T >= 100C");
    }
    return;
  }

  bool shouldHeat = (currentTemp < (targetTemp - hysteresis));
  bool canToggle = (millis() - lastHeaterToggle > MIN_HEATER_OFF_TIME);

  if (shouldHeat && !heaterState) {
    digitalWrite(PIN_HEATER_SSR, HIGH);
    heaterState = true;
    lastHeaterToggle = millis();
  } else if (!shouldHeat && heaterState && canToggle) {
    digitalWrite(PIN_HEATER_SSR, LOW);
    heaterState = false;
    lastHeaterToggle = millis();
  }
  systemState.heaterOn = heaterState;
}

// === Загрузка пользовательских программ ===
std::vector<SmokingProgram> loadUserPrograms() {
  std::vector<SmokingProgram> progs;
  if (!LittleFS.exists("/programs")) LittleFS.mkdir("/programs");
  File root = LittleFS.open("/programs");
  File file = root.openNextFile();
  while (file) {
    if (String(file.name()).endsWith(".json")) {
      StaticJsonDocument<1024> doc;
      DeserializationError error = deserializeJson(doc, file);
      if (!error && doc["name"].is<String>()) {
        SmokingProgram prog;
        prog.name = doc["name"].as<String>();
        if (doc["steps"].is<JsonArray>()) {
          for (JsonObject stepObj : doc["steps"].as<JsonArray>()) {
            ProgramStep step;
            step.targetTemp = stepObj["targetTemp"] | 30;
            step.targetHumidity = stepObj["targetHumidity"] | 70;
            step.durationMinutes = stepObj["durationMinutes"] | 60;
            step.hysteresis = stepObj["hysteresis"] | 2;
            step.waitForTemp = stepObj["waitForTemp"] | true;
            step.waitForHumidity = stepObj["waitForHumidity"] | false; // ← новое
            step.compressorPWM = stepObj["compressorPWM"] | -1;
            step.fanPWM = stepObj["fanPWM"] | 50; // ← новое
          }
        }
        progs.push_back(prog);
      }
    }
    file = root.openNextFile();
  }
  return progs;
}

// === Сохранение программы ===
bool saveProgramToFile(const SmokingProgram& prog) {
  if (prog.isBuiltIn) return false;
  String filename = "/programs/user_" + prog.name + ".json";
  filename.replace(" ", "_");
  filename.replace("/", "_");
  File tmpFile = LittleFS.open(filename + ".tmp", "w");
  if (!tmpFile) return false;
  StaticJsonDocument<1024> doc;
  doc["name"] = prog.name;
  JsonArray steps = doc.createNestedArray("steps");
  for (const auto& step : prog.steps) {
    JsonObject s = steps.createNestedObject();
    s["targetTemp"] = step.targetTemp;
    s["targetHumidity"] = step.targetHumidity;
    s["durationMinutes"] = step.durationMinutes;
    s["hysteresis"] = step.hysteresis;
    s["waitForTemp"] = step.waitForTemp;
    s["waitForHumidity"] = step.waitForHumidity; // ← новое
    s["compressorPWM"] = step.compressorPWM;
    s["fanPWM"] = step.fanPWM; // ← новое
  }
  serializeJson(doc, tmpFile);
  tmpFile.close();
  if (LittleFS.exists(filename)) LittleFS.remove(filename);
  return LittleFS.rename(filename + ".tmp", filename);
}

// === Настройка Wi-Fi ===
void setupWiFi() {
  if (!LittleFS.begin(true)) {
    Serial.println("FATAL: LittleFS");
    return;
  }

  bool useSTA = false;
  if (LittleFS.exists("/wifi.json")) {
    File file = LittleFS.open("/wifi.json", "r");
    if (file) {
      StaticJsonDocument<300> doc;
      DeserializationError error = deserializeJson(doc, file);
      file.close();
      if (!error && doc["ssid"].is<String>() && doc["pass"].is<String>()) {
        String ssid = doc["ssid"].as<String>();
        String pass = doc["pass"].as<String>();
        if (ssid.length() > 0 && pass.length() >= 8) {
          WiFi.mode(WIFI_STA);
          WiFi.begin(ssid.c_str(), pass.c_str());
          unsigned long start = millis();
          while (WiFi.status() != WL_CONNECTED && millis() - start < 12000) delay(500);
          if (WiFi.status() == WL_CONNECTED) {
            systemState.networkMode = SystemState::NetworkMode::STA;
            systemState.ssid = ssid;
            systemState.ip = WiFi.localIP().toString();
            useSTA = true;
          }
        }
      }
    }
  }

  if (!useSTA) {
    String apName = "SmartSmoker_AP_";
    uint64_t chipid = ESP.getEfuseMac();
    apName += String((uint32_t)(chipid >> 32), HEX);
    WiFi.mode(WIFI_AP);
    WiFi.softAP(apName.c_str());
    systemState.networkMode = SystemState::NetworkMode::AP;
    systemState.ssid = apName;
    systemState.ip = WiFi.softAPIP().toString();
  }
}

void setup() {
  Serial.begin(115200);
  pinMode(PIN_HEATER_SSR, OUTPUT);
  pinMode(PIN_SMOKE_MOSFET, OUTPUT);
  pinMode(PIN_FAN_MIXER, OUTPUT); // ← новое
  digitalWrite(PIN_HEATER_SSR, LOW);
  analogWrite(PIN_SMOKE_MOSFET, 0);
  analogWrite(PIN_FAN_MIXER, 0); // ← новое

  Wire.begin(I2C_SDA, I2C_SCL);
  if (!bme.begin(0x76, &Wire) && !bme.begin(0x77, &Wire)) {
    bmeFound = false;
  } else {
    bmeFound = true;
    bme.setSampling(Adafruit_BME280::MODE_NORMAL,
                    Adafruit_BME280::SAMPLING_X2,
                    Adafruit_BME280::SAMPLING_X16,
                    Adafruit_BME280::SAMPLING_X1,
                    Adafruit_BME280::FILTER_X16);
  }

  setupWiFi();
  buttons.begin();
  oled.begin();

  // === Web Server ===
  server.on("/api/state", HTTP_GET, [](AsyncWebServerRequest *request) {
    StaticJsonDocument<2048> doc;
    doc["networkMode"] = (systemState.networkMode == SystemState::NetworkMode::STA) ? "STA" : "AP";
    doc["ssid"] = systemState.ssid;
    doc["ip"] = systemState.ip;
    doc["mode"] = (systemState.mode == SystemState::SystemMode::RUNNING) ? "RUNNING" : "IDLE";
    doc["emergencyStop"] = systemState.emergencyStop;

    if (systemState.mode == SystemState::SystemMode::RUNNING && systemState.currentProgram) {
      doc["currentProgramName"] = systemState.currentProgram->name;
      doc["currentStepIndex"] = systemState.currentStepIndex;
      const ProgramStep& step = systemState.currentProgram->steps[systemState.currentStepIndex];
      unsigned long elapsed = systemState.stepStartTime ? (millis() - systemState.stepStartTime) : 0;
      unsigned long remaining = (step.durationMinutes * 60000UL > elapsed) ? (step.durationMinutes * 60000UL - elapsed) : 0;
      int totalSec = remaining / 1000;
      int mins = totalSec / 60;
      int secs = totalSec % 60;
      char buf[10];
      snprintf(buf, sizeof(buf), "%02d:%02d", mins, secs);
      doc["stepTimeLeft"] = String(buf);
    } else {
      doc["currentProgramName"] = "";
      doc["currentStepIndex"] = -1;
      doc["stepTimeLeft"] = "00:00";
    }

    doc["tempChamber"] = systemState.tempChamber;
    doc["tempSmoke"] = systemState.tempSmoke;
    doc["tempProduct"] = systemState.tempProduct;
    doc["humidity"] = systemState.humidity;
    doc["heaterOn"] = systemState.heaterOn;
    doc["smokePWM"] = systemState.smokePWM;
    doc["fanPWM"] = systemState.fanPWM; // ← новое
    doc["fs_free"] = LittleFS.totalBytes() - LittleFS.usedBytes(); // ← новое

    JsonArray programs = doc.createNestedArray("programs");
    for (const auto& p : builtInPrograms) {
      JsonObject obj = programs.createNestedObject();
      obj["name"] = p.name;
      obj["isBuiltIn"] = true;
      obj["steps"] = p.steps.size();
    }
    auto userProgs = loadUserPrograms();
    for (const auto& p : userProgs) {
      JsonObject obj = programs.createNestedObject();
      obj["name"] = p.name;
      obj["isBuiltIn"] = false;
      obj["steps"] = p.steps.size();
    }

    String json;
    serializeJson(doc, json);
    request->send(200, "application/json", json);
  });

  // === Аварийная остановка ===
  server.on("/api/stop", HTTP_POST, [](AsyncWebServerRequest *request) {
    systemState.emergencyStop = true;
    systemState.mode = SystemState::SystemMode::IDLE;
    digitalWrite(PIN_HEATER_SSR, LOW);
    analogWrite(PIN_SMOKE_MOSFET, 0);
    analogWrite(PIN_FAN_MIXER, 0); // ← новое
    request->send(200, "text/plain", "OK");
  });

  // === Обработчики POST с телом ===
  auto onBody = [](AsyncWebServerRequest *request, uint8_t *data, size_t len, size_t index, size_t total) {
    if (index == 0) requestBody = "";
    requestBody += String((char*)data, len);
  };

  server.on("/api/wifi", HTTP_POST, [](AsyncWebServerRequest *request) {
    StaticJsonDocument<300> doc;
    DeserializationError error = deserializeJson(doc, requestBody);
    requestBody = "";
    if (error || !doc["ssid"].is<String>() || !doc["pass"].is<String>()) {
      return request->send(400, "text/plain", "Invalid JSON");
    }
    String ssid = doc["ssid"].as<String>();
    String pass = doc["pass"].as<String>();
    if (ssid.length() == 0 || pass.length() < 8) {
      return request->send(400, "text/plain", "Too short");
    }
    File tmpFile = LittleFS.open("/wifi.json.tmp", "w");
    if (!tmpFile) return request->send(500, "text/plain", "FS error");
    serializeJson(doc, tmpFile);
    tmpFile.close();
    if (LittleFS.exists("/wifi.json")) LittleFS.remove("/wifi.json");
    if (!LittleFS.rename("/wifi.json.tmp", "/wifi.json")) return request->send(500, "text/plain", "Rename failed");
    request->send(200, "text/plain", "OK");
    delay(1000);
    ESP.restart();
  }, nullptr, onBody);

  server.on("/api/programs", HTTP_POST, [](AsyncWebServerRequest *request) {
    StaticJsonDocument<2048> doc;
    DeserializationError error = deserializeJson(doc, requestBody);
    requestBody = "";
    if (error || !doc["program"]["name"].is<String>()) return request->send(400, "text/plain", "Invalid JSON");
    SmokingProgram prog;
    prog.name = doc["program"]["name"].as<String>();
    if (doc["program"]["steps"].is<JsonArray>()) {
      for (JsonObject stepObj : doc["program"]["steps"].as<JsonArray>()) {
        ProgramStep step;
        step.targetTemp = stepObj["targetTemp"] | 30;
        step.targetHumidity = stepObj["targetHumidity"] | 70;
        step.durationMinutes = stepObj["durationMinutes"] | 60;
        step.hysteresis = stepObj["hysteresis"] | 2;
        step.waitForTemp = stepObj["waitForTemp"] | true;
        step.waitForHumidity = stepObj["waitForHumidity"] | false;
        step.compressorPWM = stepObj["compressorPWM"] | -1;
        step.fanPWM = stepObj["fanPWM"] | 50;
        prog.steps.push_back(step);
      }
    }
    if (prog.steps.empty()) return request->send(400, "text/plain", "No steps");
    String oldName = doc["oldName"] | "";
    if (!oldName.isEmpty() && oldName != prog.name) {
      String oldPath = "/programs/user_" + oldName + ".json";
      oldPath.replace(" ", "_");
      if (LittleFS.exists(oldPath)) LittleFS.remove(oldPath);
    }
    if (saveProgramToFile(prog)) request->send(200, "text/plain", "OK");
    else request->send(500, "text/plain", "Save failed");
  }, nullptr, onBody);

  server.on("/api/start", HTTP_POST, [](AsyncWebServerRequest *request) {
    StaticJsonDocument<200> doc;
    DeserializationError error = deserializeJson(doc, requestBody);
    requestBody = "";
    if (error || !doc["program"].is<String>()) return request->send(400, "text/plain", "Invalid JSON");
    String progName = doc["program"].as<String>();
    std::unique_ptr<SmokingProgram> selected = nullptr;
    for (const auto& p : builtInPrograms) {
      if (p.name == progName) { selected = std::make_unique<SmokingProgram>(p); break; }
    }
    if (!selected) {
      auto userProgs = loadUserPrograms();
      for (const auto& p : userProgs) {
        if (p.name == progName) { selected = std::make_unique<SmokingProgram>(p); break; }
      }
    }
    if (!selected) return request->send(404, "text/plain", "Not found");
    systemState.mode = SystemState::SystemMode::RUNNING;
    systemState.currentProgram = std::move(selected);
    systemState.currentStepIndex = 0;
    systemState.stepStartTime = 0;
    systemState.emergencyStop = false;
    request->send(200, "application/json", "{\"status\":\"started\"}");
  }, nullptr, onBody);

  // === Остальные обработчики ===
  server.on("/api/programs", HTTP_GET, [](AsyncWebServerRequest *request) {
    StaticJsonDocument<2048> doc;
    JsonArray progs = doc.createNestedArray("programs");
    for (const auto& p : builtInPrograms) {
      JsonObject obj = progs.createNestedObject();
      obj["name"] = p.name;
      obj["isBuiltIn"] = true;
      obj["steps"] = p.steps.size();
    }
    auto userProgs = loadUserPrograms();
    for (const auto& p : userProgs) {
      JsonObject obj = progs.createNestedObject();
      obj["name"] = p.name;
      obj["isBuiltIn"] = false;
      obj["steps"] = p.steps.size();
    }
    String json;
    serializeJson(doc, json);
    request->send(200, "application/json", json);
  });

  server.on("/api/programs/:name", HTTP_DELETE, [](AsyncWebServerRequest *request) {
    String name = request->pathArg(0);
    String path = "/programs/user_" + name + ".json";
    path.replace(" ", "_");
    if (LittleFS.exists(path)) {
      LittleFS.remove(path);
      request->send(200, "text/plain", "OK");
    } else {
      request->send(404, "text/plain", "Not found");
    }
  });

  server.on("/api/wifi/reset", HTTP_POST, [](AsyncWebServerRequest *request) {
    if (LittleFS.exists("/wifi.json")) LittleFS.remove("/wifi.json");
    request->send(200, "text/plain", "OK");
    delay(1000);
    ESP.restart();
  });

  server.serveStatic("/", LittleFS, "/").setDefaultFile("index.html");
  server.serveStatic("/wifi", LittleFS, "/wifi.html");
  server.serveStatic("/programs", LittleFS, "/programs.html");
  server.serveStatic("/wifi-icon.png", LittleFS, "/wifi-icon.png");
  server.serveStatic("/program-icon.png", LittleFS, "/program-icon.png");

  server.onNotFound([](AsyncWebServerRequest *request) {
    request->send(404, "text/plain", "Not found");
  });

  server.begin();
}

void loop() {
  if (millis() - lastSensorRead > 2000) {
    lastSensorRead = millis();
    float tSmoke = readNTC(PIN_NTC_SMOKE);
    float tProduct = readNTC(PIN_NTC_PRODUCT);
    systemState.tempSmoke = (tSmoke > -50) ? tSmoke : 0.0f;
    systemState.tempProduct = (tProduct > -50) ? tProduct : 0.0f;
    if (bmeFound) {
      systemState.tempChamber = bme.readTemperature();
      systemState.humidity = bme.readHumidity();
      if (isnan(systemState.tempChamber)) systemState.tempChamber = 0.0f;
      if (isnan(systemState.humidity)) systemState.humidity = 0.0f;
    }
  }

  if (systemState.mode == SystemState::SystemMode::RUNNING && !systemState.emergencyStop && systemState.currentProgram) {
    if (systemState.currentStepIndex >= systemState.currentProgram->steps.size()) {
      systemState.mode = SystemState::SystemMode::IDLE;
      systemState.currentProgram.reset();
      digitalWrite(PIN_HEATER_SSR, LOW);
      analogWrite(PIN_SMOKE_MOSFET, 0);
      analogWrite(PIN_FAN_MIXER, 0); // ← новое
    } else {
      const ProgramStep& step = systemState.currentProgram->steps[systemState.currentStepIndex];
      
      // Управление ТЭН
      bool skipHeating = false;
      if (!systemState.waitingForTemp && step.waitForTemp) {
        if (systemState.tempChamber >= (step.targetTemp - step.hysteresis)) {
          systemState.stepStartTime = millis();
          systemState.waitingForTemp = false;
        } else {
          systemState.waitingForTemp = true;
          updateHeater(systemState.tempChamber, step.targetTemp, step.hysteresis);
          skipHeating = true;
        }
      }

      // Управление дымом и вентилятором
      targetSmokePWM = (step.compressorPWM == -1) ? 70 : step.compressorPWM;
      int fanPWM = step.fanPWM; // ← новое
      analogWrite(PIN_FAN_MIXER, (fanPWM * 255) / 100); // ← новое
      systemState.fanPWM = fanPWM; // ← новое

      if (!skipHeating) {
        updateHeater(systemState.tempChamber, step.targetTemp, step.hysteresis);
        unsigned long elapsed = millis() - systemState.stepStartTime;
        
        // Завершение по времени ИЛИ по влажности
        bool timeElapsed = (elapsed >= (step.durationMinutes * 60000UL));
        bool humidityReached = (!step.waitForHumidity) || (systemState.humidity <= step.targetHumidity);
        
        if (timeElapsed || humidityReached) {
          systemState.currentStepIndex++;
          systemState.stepStartTime = 0;
          systemState.waitingForTemp = false;
        }
      }
    }
  }

  analogWrite(PIN_SMOKE_MOSFET, (targetSmokePWM * 255) / 100);
  ButtonHandler::Event evt = buttons.readEvent();
  if (evt != ButtonHandler::Event::NONE) {
    oled.handleButtonEvent(static_cast<int>(evt));
  }
  oled.update();
  delay(10);
}