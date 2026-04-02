/**
 * Менеджер программ копчения согласно ТЗ
 * 
 * @file ProgramManager.h
 * @version 2.0 - Cloud Sync
 */

#ifndef PROGRAM_MANAGER_H
#define PROGRAM_MANAGER_H

#include <Arduino.h>
#include <vector>
#include <memory>
#include <ArduinoJson.h>
#include <LittleFS.h>
#include "ProgramStructures.h"
#include "constants.h"
#include "SystemState.h"
#include "StorageManager.h"
#include "ActuatorManager.h"
#include "IgniterManager.h"
#include "SettingsManager.h"

/**
 * Класс для управления программами копчения
 */
class ProgramManager {
private:
    std::vector<std::shared_ptr<SmokingProgram>> programs;
    StorageManager* storageManager = nullptr;
    
    // Состояние выполнения программы
    bool programRunning = false;
    unsigned long stepStartTime = 0;
    unsigned long lastStepUpdate = 0;
    bool temperatureReached = false;
    
    // Путь к файлу программ
    static constexpr const char* PROGRAMS_FILE = "/programs.json";

public:
    bool init(StorageManager& storage) {
        storageManager = &storage;
        
        if (!loadProgramsFromStorage()) {
            createBuiltInPrograms();
            saveProgramsToStorage();
        }
        
        return true;
    }
    
    /**
     * Синхронизация программ с облаком
     */
    bool syncProgramsFromCloud(const std::vector<String>& cloudPrograms) {
        int added = 0;
        int updated = 0;
        
        for (const String& programJson : cloudPrograms) {
            auto program = parseProgramFromJson(programJson);
            if (program && program->isValid()) {
                bool exists = (findProgramByName(program->name) != nullptr);
                if (addOrUpdateProgram(program)) {
                    exists ? updated++ : added++;
                }
            }
        }
        
        if (added > 0 || updated > 0) {
            saveProgramsToStorage();
        }
        
        return true;
    }
    
    /**
     * Парсинг программы из JSON
     */
    std::shared_ptr<SmokingProgram> parseProgramFromJson(const String& jsonStr) {
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, jsonStr);
        
        if (error) {
            Serial.printf("[ERROR] Failed to parse program JSON: %s\n", error.c_str());
            return nullptr;
        }
        
        auto program = std::make_shared<SmokingProgram>();
        program->name = doc["name"] | "";
        program->description = doc["description"] | "";
        program->category = doc["category"] | "custom";
        
        // Парсинг этапов
        JsonArray stages = doc["stages"];
        for (JsonObject stageObj : stages) {
            ProgramStep step;
            step.stepName = stageObj["stage_name"] | "Этап";
            step.targetTemp = stageObj["target_temp"] | 30.0f;
            step.targetTempDevice = stageObj["target_temp_device"] | 0;
            step.targetHumidity = stageObj["target_humidity"] | 70.0f;
            step.durationMinutes = stageObj["duration_minutes"] | 60;
            step.hysteresis = stageObj["hysteresis"] | 2.0f;
            step.waitForTemp = stageObj["wait_for_temp"] | true;
            step.useSmokeGenerator = stageObj["use_smoke_generator"] | false;
            step.ventilationPercent = stageObj["ventilation_percent"] | 100;
            step.internalFanOn = stageObj["internal_fan_on"] | false;
            step.injectionFanOn = stageObj["injection_fan_on"] | false;
            step.compressorPWM = stageObj["compressor_pwm"] | -1;
            
            if (step.isValid()) {
                program->addStep(step);
            }
        }
        
        return program;
    }
    
    /**
     * Добавление или обновление программы
     */
    bool addOrUpdateProgram(std::shared_ptr<SmokingProgram> program) {
        if (!program || !program->isValid()) {
            return false;
        }
        
        for (size_t i = 0; i < programs.size(); i++) {
            if (programs[i]->name == program->name) {
                programs[i] = program;
                return true;
            }
        }
        
        programs.push_back(program);
        return true;
    }
    
    /**
     * Удаление программы по имени
     */
    bool deleteProgram(const String& programName) {
        for (auto it = programs.begin(); it != programs.end(); ++it) {
            if ((*it)->name == programName) {
                programs.erase(it);
                saveProgramsToStorage();
                return true;
            }
        }
        return false;
    }
    
    /**
     * Поиск программы по имени
     */
    std::shared_ptr<SmokingProgram> findProgramByName(const String& name) {
        for (auto& program : programs) {
            if (program->name == name) {
                return program;
            }
        }
        return nullptr;
    }
    
    /**
     * Получение программы по индексу
     */
    std::shared_ptr<SmokingProgram> getProgram(size_t index) {
        if (index < programs.size()) {
            return programs[index];
        }
        return nullptr;
    }
    
    /**
     * Получение списка имён программ
     */
    std::vector<String> getProgramNames() const {
        std::vector<String> names;
        for (const auto& program : programs) {
            names.push_back(program->name);
        }
        return names;
    }
    
    size_t getProgramCount() const {
        return programs.size();
    }
    
    /**
     * Сохранение программ в LittleFS — каждая в отдельный файл (ТЗ п.9.2)
     */
    bool saveProgramsToStorage() {
        if (!storageManager || !storageManager->isReady()) {
            return false;
        }
        
        bool allOk = true;
        for (const auto& program : programs) {
            if (!storageManager->saveProgram(*program)) {
                allOk = false;
            }
        }
        return allOk;
    }
    
    /**
     * Загрузка программ из LittleFS — читаем все файлы из /programs/
     */
    bool loadProgramsFromStorage() {
        if (!storageManager || !storageManager->isReady()) {
            Serial.println("[ProgramManager] Storage manager not ready");
            return false;
        }
        
        programs.clear();
        Serial.println("[ProgramManager] Loading programs from /programs/");
        
        // Читаем все файлы program_*.json из директории /programs/
        File dir = LittleFS.open("/programs");
        if (!dir || !dir.isDirectory()) {
            Serial.println("[ProgramManager] Failed to open /programs/ directory");
            return false;
        }
        
        File file = dir.openNextFile();
        int loaded = 0;
        int skipped = 0;
        while (file) {
            String fname = String(file.name());
            Serial.printf("[ProgramManager] Found file: %s\n", fname.c_str());
            
            // Принимаем файлы с префиксом program_ или program_c (контроллер)
            bool isLocalProgram = fname.startsWith("program_c");
            bool isWebsiteProgram = fname.startsWith("program_") && !isLocalProgram;
            
            if ((isLocalProgram || isWebsiteProgram) && fname.endsWith(".json")) {
                String fullPath = "/programs/" + fname;
                String content = storageManager->readFile(fullPath);
                if (!content.isEmpty()) {
                    JsonDocument doc;
                    DeserializationError error = deserializeJson(doc, content);
                    if (error == DeserializationError::Ok) {
                        auto program = std::make_shared<SmokingProgram>();
                        
                        // Проверяем формат файла: новый формат с сайта (с полем "data") или старый локальный формат
                        JsonObject dataObj;
                        int programId = 0;
                        if (doc["data"].is<JsonObject>()) {
                            // Новый формат с сайта: данные в поле "data"
                            dataObj = doc["data"].as<JsonObject>();
                            programId = doc["program_id"] | 0;
                            Serial.printf("[ProgramManager] Detected new format (with 'data' field) for %s, program_id=%d\n", 
                                fname.c_str(), programId);
                        } else {
                            // Старый формат: данные на верхнем уровне
                            dataObj = doc.as<JsonObject>();
                            Serial.printf("[ProgramManager] Detected old format (flat structure) for %s\n", fname.c_str());
                        }
                        
                        // Парсим данные программы из соответствующего объекта
                        program->name = dataObj["program_name"] | dataObj["name"] | "";
                        program->description = dataObj["description"] | "";
                        program->category = dataObj["category"] | "custom";
                        program->isBuiltIn = dataObj["is_built_in"] | false;
                        program->programId = programId;
                        program->isLocalProgram = isLocalProgram; // Устанавливаем источник программы
                        
                        Serial.printf("[ProgramManager] Parsing program: %s (source: %s)\n", 
                            program->name.c_str(), isLocalProgram ? "controller" : "website");
                        
                        JsonArray stagesArray = dataObj["stages"];
                        for (JsonObject stepObj : stagesArray) {
                            ProgramStep step;
                            // Поддерживаем оба варианта названия поля: "stage_name" и "name"
                            step.stepName = stepObj["stage_name"] | stepObj["name"] | "Этап";
                            step.targetTemp = stepObj["target_temp"] | 30.0f;
                            step.targetTempDevice = stepObj["target_temp_device"] | 0;
                            step.targetHumidity = stepObj["target_humidity"] | 70.0f;
                            step.durationMinutes = stepObj["duration_minutes"] | 60;
                            step.hysteresis = stepObj["hysteresis"] | 2.0f;
                            step.waitForTemp = stepObj["wait_for_temp"] | true;
                            step.useSmokeGenerator = stepObj["use_smoke_generator"] | false;
                            step.ventilationPercent = stepObj["ventilation_percent"] | 100;
                            step.internalFanOn = stepObj["internal_fan_on"] | false;
                            step.injectionFanOn = stepObj["injection_fan_on"] | false;
                            step.compressorPWM = stepObj["compressor_pwm"] | -1;
                            program->addStep(step);
                        }
                        
                        if (program->isValid()) {
                            programs.push_back(program);
                            loaded++;
                            Serial.printf("[ProgramManager] ✓ Loaded program: %s (%d stages, source: %s)\n", 
                                program->name.c_str(), program->steps.size(), 
                                isLocalProgram ? "controller" : "website");
                        } else {
                            skipped++;
                            Serial.printf("[ProgramManager] ✗ Skipped invalid program: %s (name empty: %d, steps: %d)\n", 
                                program->name.c_str(), program->name.isEmpty(), program->steps.size());
                        }
                    } else {
                        skipped++;
                        Serial.printf("[ProgramManager] ✗ JSON parse error for %s: %s\n", 
                            fname.c_str(), error.c_str());
                    }
                } else {
                    skipped++;
                    Serial.printf("[ProgramManager] ✗ Empty content for %s\n", fname.c_str());
                }
            } else {
                skipped++;
                Serial.printf("[ProgramManager] ✗ Skipped file (wrong pattern): %s\n", fname.c_str());
            }
            file = dir.openNextFile();
        }
        
        Serial.printf("[ProgramManager] Load complete: %d loaded, %d skipped\n", loaded, skipped);
        return loaded > 0;
    }
    
    /**
     * Запуск программы по имени
     */
    bool startProgram(const String& programName, SystemState& state) {
        auto program = findProgramByName(programName);
        if (!program) {
            Serial.printf("[ERROR] Program not found: %s\n", programName.c_str());
            return false;
        }
        
        if (!program->isValid()) {
            Serial.printf("[ERROR] Program invalid: %s\n", programName.c_str());
            return false;
        }
        
        state.currentProgram = program;
        state.currentStepIndex = 0;
        state.stepStartTime = millis();
        state.programStartTime = millis();
        state.waitingForTemp = true;
        state.mode = SystemState::Mode::RUNNING;
        programRunning = true;
        temperatureReached = false;
        
        // Сброс состояния ожидания розжига
        state.smokeIgnitionConfirmed = false;
        state.smokeIgnitionStartTime = 0;
        state.smokeBaselineTemp = NAN;
        state.smokePauseStartTime = 0;
        state.smokePauseActive = false;
        state.lastSmokeNotificationTime = 0;
        
        state.generateRunId();
        program->updateUsageStats();
        
        return true;
    }
    
    /**
     * Остановка текущей программы
     */
    bool stopProgram(SystemState& state) {
        if (!programRunning) {
            return false;
        }
        
        programRunning = false;
        state.mode = SystemState::Mode::IDLE;
        state.currentProgram = nullptr;
        state.currentStepIndex = 0;
        state.waitingForTemp = false;
        
        return true;
    }
    
    /**
     * Обновление выполнения программы
     */
    void updateProgram(SystemState& state, ActuatorManager& actuatorManager,
                       IgniterManager& igniterManager) {
        if (!programRunning || !state.currentProgram) {
            return;
        }
        
        unsigned long currentTime = millis();
        
        // Обновление каждую секунду
        if (currentTime - lastStepUpdate < 1000) {
            return;
        }
        lastStepUpdate = currentTime;
        
        // -------------------------------------------------------
        // Обработка активного автомата розжига (только если подключён)
        // -------------------------------------------------------
        if (settingsManager.igniterEnabled) {
            if (igniterManager.isBusy()) {
                // Держим дымогенератор выключенным, таймер не продвигаем
                actuatorManager.setSmokeGenerator(0, state);
                state.smokePWM = 0;
                state.igniterDisplayState = SystemState::IgniterDisplayState::ACTIVE;
                return;
            }

            if (igniterManager.isDone()) {
                handleIgniterResult(state, actuatorManager, igniterManager);
                return;
            }
        }

        // -------------------------------------------------------
        // Обработка состояния WAITING_SMOKE_IGNITION
        // -------------------------------------------------------
        if (state.mode == SystemState::Mode::WAITING_SMOKE_IGNITION) {
            handleSmokeIgnitionWait(state, actuatorManager, currentTime);
            return;
        }
        
        // -------------------------------------------------------
        // Обработка паузы после таймаута розжига
        // -------------------------------------------------------
        if (state.smokePauseActive) {
            handleSmokePause(state, actuatorManager, currentTime);
            return;
        }
        
        // Получение текущего этапа
        if (state.currentStepIndex >= state.currentProgram->steps.size()) {
            // Программа завершена
            stopProgram(state);
            state.completedPrograms++;
            return;
        }
        
        const ProgramStep& currentStep = state.currentProgram->steps[state.currentStepIndex];
        
        // Проверка достижения температуры (если требуется)
        if (state.waitingForTemp && currentStep.waitForTemp) {
            if (checkTemperatureReached(state, currentStep)) {
                state.waitingForTemp = false;
                state.stepStartTime = currentTime;
            } else {
                return;
            }
        }
        
        // Если этап использует дымогенератор и розжиг ещё не подтверждён — входим в ожидание
        if (currentStep.useSmokeGenerator && !state.smokeIgnitionConfirmed && !state.waitingForTemp) {
            enterSmokeIgnitionWait(state, actuatorManager, currentStep, currentTime);
            // Запускаем аппаратный розжиг (если подключён)
            if (settingsManager.igniterEnabled && igniterManager.isIdle()) {
                igniterManager.startIgnition();
            }
            return;
        }
        
        // Проверка времени этапа
        unsigned long elapsed = currentTime - state.stepStartTime;
        unsigned long stageDuration = currentStep.durationMinutes * 60000UL;
        
        if (elapsed >= stageDuration) {
            // Переход к следующему этапу
            moveToNextStage(state);
            return;
        }
        
        // Управление исполнительными механизмами по программе
        updateActuators(state, currentStep, actuatorManager);
    }
    
    /**
     * Вход в состояние ожидания розжига дымогенератора
     */
    void enterSmokeIgnitionWait(SystemState& state, ActuatorManager& actuatorManager,
                                 const ProgramStep& step, unsigned long currentTime) {
        Serial.println("[SmokeIgnition] Entering igniter start sequence");

        // Запоминаем базовую температуру дыма и время старта этапа
        state.smokeBaselineTemp = isnan(state.tempSmoke) ? 20.0f : state.tempSmoke;
        state.smokeIgnitionStartTime = currentTime;
        state.smokeIgnitionConfirmed = false;
        state.lastSmokeNotificationTime = currentTime;
        state.igniterStepStartMs = currentTime;

        // Дымогенератор выключен до подтверждения розжига
        actuatorManager.setSmokeGenerator(0, state);
        state.smokePWM = 0;

        // Поддерживаем температуру камеры
        float currentTemp = getCurrentTemperature(state, step);
        actuatorManager.setTargetTemperature(step.targetTemp, currentTemp, step.hysteresis, state);

        if (settingsManager.igniterEnabled) {
            // Аппаратный розжиг: запуск через igniterManager (вызывается из updateProgram)
            state.igniterAttempt = 1;
            state.igniterDisplayState = SystemState::IgniterDisplayState::ACTIVE;
            Serial.printf("[SmokeIgnition] Hardware igniter: baseline smoke temp: %.1f°C, attempt 1/%d\n",
                          state.smokeBaselineTemp, IGNITER_MAX_RETRIES + 1);
        } else {
            // Без аппаратного розжига: сразу переходим в ручное ожидание
            state.mode = SystemState::Mode::WAITING_SMOKE_IGNITION;
            state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
            Serial.println("[SmokeIgnition] No hardware igniter — waiting for manual confirmation");
        }
    }
    
    /**
     * Обработка состояния ожидания розжига (ручное подтверждение после TIMEOUT)
     */
    void handleSmokeIgnitionWait(SystemState& state, ActuatorManager& actuatorManager,
                                  unsigned long currentTime) {
        if (!state.currentProgram || state.currentStepIndex >= state.currentProgram->steps.size()) {
            return;
        }
        const ProgramStep& step = state.currentProgram->steps[state.currentStepIndex];

        // Поддерживаем температуру
        float currentTemp = getCurrentTemperature(state, step);
        actuatorManager.setTargetTemperature(step.targetTemp, currentTemp, step.hysteresis, state);

        // Ручное подтверждение через кнопку OK или облако
        if (state.smokeIgnitionConfirmed) {
            Serial.println("[SmokeIgnition] Manual confirmation received");
            state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
            confirmSmokeIgnition(state, currentTime);

            // Включаем дымогенератор
            int pwm = (step.compressorPWM >= 0) ? step.compressorPWM : 70;
            actuatorManager.setSmokeGenerator(pwm, state);
            state.smokePWM = pwm;
            return;
        }

        // Уведомление каждые 10 минут
        if (currentTime - state.lastSmokeNotificationTime >= SystemState::SMOKE_NOTIFY_INTERVAL_MS) {
            state.lastSmokeNotificationTime = currentTime;
            Serial.println("[SmokeIgnition] Waiting for manual ignition confirmation...");
        }
    }
    
    /**
     * Обработка результата аппаратного розжига
     */
    void handleIgniterResult(SystemState& state, ActuatorManager& actuatorManager,
                              IgniterManager& igniterManager) {
        IgniterResult result = igniterManager.getResult();
        state.igniterLastResult = (uint8_t)result;
        state.igniterDoneMs = millis();
        state.igniterDone = true;

        Serial.printf("[Igniter] Handling result: %s (attempt %d)\n",
                      IgniterManager::resultText(result), state.igniterAttempt);

        switch (result) {
            case IgniterResult::SUCCESS: {
                igniterManager.reset();
                state.igniterDisplayState = SystemState::IgniterDisplayState::SUCCESS;
                state.igniterSuccessDisplayStart = millis();
                state.smokeIgnitionConfirmed = true;
                state.smokePauseActive = false;
                state.mode = SystemState::Mode::RUNNING;
                state.stepStartTime = millis();

                // Включаем дымогенератор по параметрам этапа
                if (state.currentProgram && state.currentStepIndex < state.currentProgram->steps.size()) {
                    const ProgramStep& step = state.currentProgram->steps[state.currentStepIndex];
                    int pwm = (step.compressorPWM >= 0) ? step.compressorPWM : 70;
                    actuatorManager.setSmokeGenerator(pwm, state);
                    state.smokePWM = pwm;
                }
                Serial.println("[Igniter] SUCCESS — smoke generator started, stage timer running");
                break;
            }

            case IgniterResult::TIMEOUT: {
                igniterManager.reset();
                // Retry если включён и попытки не исчерпаны
                if (IGNITER_RETRY_ENABLED && state.igniterAttempt <= IGNITER_MAX_RETRIES) {
                    state.igniterAttempt++;
                    state.igniterDisplayState = SystemState::IgniterDisplayState::ACTIVE;
                    igniterManager.startIgnition();
                    Serial.printf("[Igniter] TIMEOUT — retry %d/%d\n",
                                  state.igniterAttempt, IGNITER_MAX_RETRIES + 1);
                } else {
                    // Retry исчерпан — ждём ручного подтверждения
                    state.igniterDisplayState = SystemState::IgniterDisplayState::TIMEOUT;
                    state.mode = SystemState::Mode::WAITING_SMOKE_IGNITION;
                    state.smokeIgnitionStartTime = millis();
                    Serial.println("[Igniter] TIMEOUT — entering WAITING_SMOKE_IGNITION");
                }
                break;
            }

            case IgniterResult::NO_GAS:
                igniterManager.reset();
                state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
                triggerIgniterEmergencyStop(state, actuatorManager, ERR_IGNITER_NO_GAS);
                break;

            case IgniterResult::GAS_FROZEN:
                igniterManager.reset();
                state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
                triggerIgniterEmergencyStop(state, actuatorManager, ERR_IGNITER_GAS_FROZEN);
                break;

            default: // ERROR или неожиданное значение
                igniterManager.reset();
                state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
                triggerIgniterEmergencyStop(state, actuatorManager, ERR_IGNITER_UNEXPECTED);
                break;
        }
    }

    /**
     * Аварийная остановка по причине ошибки розжига
     */
    void triggerIgniterEmergencyStop(SystemState& state, ActuatorManager& actuatorManager,
                                      const char* errorCode) {
        actuatorManager.emergencyStop();
        state.emergencyStop = true;
        state.emergencyReason = errorCode;
        state.mode = SystemState::Mode::EMERGENCY_STOP;
        state.displayMode = SystemState::DisplayMode::EMERGENCY_STOP;
        state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
        Serial.printf("[Igniter] EMERGENCY STOP: %s\n", errorCode);
    }

    /**
     * Подтверждение розжига — переход к выполнению этапа
     */
    void confirmSmokeIgnition(SystemState& state, unsigned long currentTime) {
        state.smokeIgnitionConfirmed = true;
        state.smokePauseActive = false;
        state.mode = SystemState::Mode::RUNNING;
        state.stepStartTime = currentTime; // Таймер этапа стартует с момента подтверждения
        Serial.println("[SmokeIgnition] Ignition confirmed, starting stage timer");
    }
    
    /**
     * Вход в паузу после таймаута розжига
     */
    void enterSmokePause(SystemState& state, unsigned long currentTime) {
        state.smokePauseActive = true;
        state.smokePauseStartTime = currentTime;
        state.lastSmokeNotificationTime = currentTime;
        state.mode = SystemState::Mode::PAUSED;
        Serial.println("[SmokeIgnition] Smoke pause started, maintaining temperature");
    }
    
    /**
     * Обработка паузы ожидания розжига (до 30 минут)
     */
    void handleSmokePause(SystemState& state, ActuatorManager& actuatorManager,
                           unsigned long currentTime) {
        if (!state.currentProgram || state.currentStepIndex >= state.currentProgram->steps.size()) {
            return;
        }
        const ProgramStep& step = state.currentProgram->steps[state.currentStepIndex];
        
        // Поддерживаем температуру
        float currentTemp = getCurrentTemperature(state, step);
        actuatorManager.setTargetTemperature(step.targetTemp, currentTemp, step.hysteresis, state);
        
        // Ручное подтверждение во время паузы
        if (state.smokeIgnitionConfirmed) {
            Serial.println("[SmokeIgnition] Manual confirmation during pause");
            state.smokePauseActive = false;
            state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
            confirmSmokeIgnition(state, currentTime);

            // Включаем дымогенератор
            int pwm = (step.compressorPWM >= 0) ? step.compressorPWM : 70;
            actuatorManager.setSmokeGenerator(pwm, state);
            state.smokePWM = pwm;
            return;
        }
        
        // Уведомление каждые 10 минут
        if (currentTime - state.lastSmokeNotificationTime >= SystemState::SMOKE_NOTIFY_INTERVAL_MS) {
            state.lastSmokeNotificationTime = currentTime;
            unsigned long pauseElapsed = (currentTime - state.smokePauseStartTime) / 60000;
            Serial.printf("[SmokeIgnition] Pause notification: %lu min elapsed\n", pauseElapsed);
        }
        
        // Аварийная остановка через 30 минут паузы
        unsigned long pauseElapsed = currentTime - state.smokePauseStartTime;
        if (pauseElapsed >= SystemState::SMOKE_PAUSE_MAX_MS) {
            Serial.println("[SmokeIgnition] Pause timeout (30 min)! Emergency stop");
            state.smokePauseActive = false;
            triggerIgniterEmergencyStop(state, actuatorManager, ERR_IGNITER_TIMEOUT);
        }
    }
    
    /**
     * Переход к следующему этапу
     */
    void moveToNextStage(SystemState& state) {
        if (!state.currentProgram) return;
        
        state.currentStepIndex++;
        state.stepStartTime = millis();
        state.waitingForTemp = true;
        
        // Сброс состояния розжига для нового этапа
        state.smokeIgnitionConfirmed = false;
        state.smokeIgnitionStartTime = 0;
        state.smokeBaselineTemp = NAN;
        state.smokePauseActive = false;
        state.lastSmokeNotificationTime = 0;

        // Сброс состояния аппаратного розжига
        state.igniterAttempt      = 0;
        state.igniterLastResult   = 0;
        state.igniterDone         = false;
        state.igniterStepStartMs  = 0;
        state.igniterDoneMs       = 0;
        state.igniterDisplayState = SystemState::IgniterDisplayState::NONE;
        state.igniterSuccessDisplayStart = 0;
    }
    
    /**
     * Проверка достижения целевой температуры
     */
    bool checkTemperatureReached(const SystemState& state, const ProgramStep& step) {
        float currentTemp = getCurrentTemperature(state, step);
        
        if (isnan(currentTemp)) {
            return false; // Нет данных с датчика
        }
        
        // Проверка с учетом гистерезиса
        float lowerBound = step.targetTemp - step.hysteresis;
        float upperBound = step.targetTemp + step.hysteresis;
        
        return currentTemp >= lowerBound && currentTemp <= upperBound;
    }
    
    /**
     * Получение текущей температуры в зависимости от настроек этапа
     */
    float getCurrentTemperature(const SystemState& state, const ProgramStep& step) {
        if (step.targetTempDevice == 1) {
            return state.tempProduct; // Температура продукта
        } else {
            return state.tempChamber; // Температура камеры
        }
    }
    
    /**
     * Управление исполнительными механизмами по программе
     */
    void updateActuators(SystemState& state, const ProgramStep& step, ActuatorManager& actuatorManager) {
        // Получение текущей температуры
        float currentTemp = getCurrentTemperature(state, step);
        
        if (isnan(currentTemp)) {
            // Нет данных с датчика, отключаем все для безопасности
            actuatorManager.setHeater(false, state);
            actuatorManager.setSmokeGenerator(0, state);
            return;
        }
        
        // Использование интеллектуального метода ActuatorManager для управления ТЭНом
        actuatorManager.setTargetTemperature(step.targetTemp, currentTemp, step.hysteresis, state);
        
        // Управление дымогенератором
        if (step.useSmokeGenerator) {
            if (step.compressorPWM >= 0) {
                // Ручной режим ШИМ
                actuatorManager.setSmokeGenerator(step.compressorPWM, state);
                state.smokePWM = step.compressorPWM;
            } else {
                // Автоматический режим (по умолчанию 70%)
                actuatorManager.setSmokeGenerator(70, state);
                state.smokePWM = 70;
            }
        } else {
            actuatorManager.setSmokeGenerator(0, state);
            state.smokePWM = 0;
        }
        
        // Управление заслонкой
        actuatorManager.setDamper(step.ventilationPercent, state);
        state.damperPosition = step.ventilationPercent;
        
        // Управление вентиляторами
        actuatorManager.setInternalFan(step.internalFanOn, state);
        actuatorManager.setInjectionFan(step.injectionFanOn, state);
        state.fanInternalOn = step.internalFanOn;
        state.fanInjectionOn = step.injectionFanOn;
        
        // Альтернативно можно использовать единый метод:
        // actuatorManager.setActuatorsByProgram(
        //     step.targetTemp, currentTemp, step.hysteresis,
        //     step.useSmokeGenerator, 
        //     step.compressorPWM >= 0 ? step.compressorPWM : 70,
        //     step.ventilationPercent,
        //     step.internalFanOn,
        //     step.injectionFanOn,
        //     state
        // );
    }
    
    /**
     * Получение текущего этапа
     */
    const ProgramStep* getCurrentStage(const SystemState& state) const {
        if (!state.currentProgram || state.currentStepIndex >= state.currentProgram->steps.size()) {
            return nullptr;
        }
        return &state.currentProgram->steps[state.currentStepIndex];
    }
    
    /**
     * Получение прогресса выполнения в процентах
     */
    int getProgress(const SystemState& state) const {
        if (!state.currentProgram || state.currentProgram->steps.empty()) {
            return 0;
        }
        
        if (state.currentStepIndex >= state.currentProgram->steps.size()) {
            return 100; // Программа завершена
        }
        
        // Прогресс текущего этапа
        unsigned long elapsed = millis() - state.stepStartTime;
        const ProgramStep& currentStep = state.currentProgram->steps[state.currentStepIndex];
        unsigned long stageDuration = currentStep.durationMinutes * 60000UL;
        
        int stageProgress = 0;
        if (stageDuration > 0) {
            stageProgress = (elapsed * 100) / stageDuration;
            stageProgress = constrain(stageProgress, 0, 100);
        }
        
        // Общий прогресс программы
        int totalStages = state.currentProgram->steps.size();
        int progressPerStage = 100 / totalStages;
        int completedStagesProgress = state.currentStepIndex * progressPerStage;
        int currentStageProgress = (stageProgress * progressPerStage) / 100;
        
        return completedStagesProgress + currentStageProgress;
    }
    
    /**
     * Получение оставшегося времени в секундах
     */
    unsigned long getTimeLeft(const SystemState& state) const {
        if (!state.currentProgram) {
            return 0;
        }
        
        unsigned long totalLeft = 0;
        
        // Время оставшихся этапов
        for (size_t i = state.currentStepIndex; i < state.currentProgram->steps.size(); i++) {
            const ProgramStep& step = state.currentProgram->steps[i];
            unsigned long stageDuration = step.durationMinutes * 60000UL;
            
            if (i == state.currentStepIndex) {
                // Текущий этап
                unsigned long elapsed = millis() - state.stepStartTime;
                if (elapsed < stageDuration) {
                    totalLeft += stageDuration - elapsed;
                }
            } else {
                // Будущие этапы
                totalLeft += stageDuration;
            }
        }
        
        return totalLeft / 1000; // в секундах
    }

private:
    void createBuiltInPrograms() {
        auto fishProgram = std::make_shared<SmokingProgram>("Холодное копчение рыбы", "Классическая программа для рыбы");
        fishProgram->category = "fish";
        fishProgram->isBuiltIn = true;
        fishProgram->addStep(ProgramStep("Подсушка", 25.0f, 60));
        ProgramStep step2("Копчение", 30.0f, 180);
        step2.useSmokeGenerator = true;
        fishProgram->addStep(step2);
        programs.push_back(fishProgram);
        
        auto meatProgram = std::make_shared<SmokingProgram>("Горячее копчение мяса", "Программа для мяса");
        meatProgram->category = "meat";
        meatProgram->isBuiltIn = true;
        meatProgram->addStep(ProgramStep("Разогрев", 60.0f, 30));
        ProgramStep step4("Копчение", 80.0f, 120);
        step4.useSmokeGenerator = true;
        meatProgram->addStep(step4);
        programs.push_back(meatProgram);
        
        auto dryingProgram = std::make_shared<SmokingProgram>("Сушка", "Сушка без копчения");
        dryingProgram->category = "custom";
        dryingProgram->isBuiltIn = true;
        dryingProgram->addStep(ProgramStep("Сушка", 40.0f, 240));
        programs.push_back(dryingProgram);
    }
};

#endif // PROGRAM_MANAGER_H