/**
 * Менеджер кнопок согласно ТЗ
 * 
 * @file ButtonManager.h
 * @version 1.0
 */

#ifndef BUTTON_MANAGER_H
#define BUTTON_MANAGER_H

#include <Arduino.h>
#include <sys/time.h>
#include "pins.h"
#include "constants.h"
#include "SystemState.h"
#include "ProgramManager.h"
#include "SettingsMenuDefs.h"
#include "SettingsManager.h"
#include "DisplayManager.h"

// Предварительное объявление
class ActuatorManager;

/**
 * Структура для хранения состояния кнопки
 */
struct ButtonState {
    bool currentState = false;
    bool lastState = false;
    bool pressed = false;
    bool longPressed = false;
    unsigned long pressTime = 0;
};

/**
 * Класс для управления кнопками
 */
class ButtonManager {
private:
    ButtonState menuButton;
    ButtonState upButton;
    ButtonState downButton;
    ButtonState okButton;
    
    unsigned long lastDebounceTime = 0;

public:
    bool init() {
        Serial.println("Initializing buttons...");
        
        pinMode(PIN_BUTTON_MENU, INPUT_PULLUP);
        pinMode(PIN_BUTTON_UP, INPUT_PULLUP);
        pinMode(PIN_BUTTON_DOWN, INPUT_PULLUP);
        pinMode(PIN_BUTTON_OK, INPUT_PULLUP);
        
        Serial.println("✓ Buttons initialized with pull-up resistors");
        return true;
    }
    
    void update(SystemState& state, DisplayManager& display, 
                ProgramManager& programManager, ActuatorManager& actuatorManager) {
        unsigned long currentTime = millis();
        
        // Чтение состояния кнопок (инвертируем из-за pull-up)
        updateButtonState(menuButton, !digitalRead(PIN_BUTTON_MENU), currentTime);
        updateButtonState(upButton, !digitalRead(PIN_BUTTON_UP), currentTime);
        updateButtonState(downButton, !digitalRead(PIN_BUTTON_DOWN), currentTime);
        updateButtonState(okButton, !digitalRead(PIN_BUTTON_OK), currentTime);
        
        // Обработка событий кнопок
        handleButtonEvents(state, display, programManager, actuatorManager, currentTime);
        
        // Сброс флагов событий
        resetButtonFlags();
    }

private:
    void updateButtonState(ButtonState& button, bool currentReading, unsigned long currentTime) {
        if (currentReading != button.lastState) {
            lastDebounceTime = currentTime;
        }
        
        if ((currentTime - lastDebounceTime) > BUTTON_DEBOUNCE_TIME) {
            if (currentReading != button.currentState) {
                button.currentState = currentReading;
                
                if (button.currentState) {
                    button.pressTime = currentTime;
                    button.pressed = true;
                }
            }
        }
        
        // Проверка долгого нажатия
        if (button.currentState && !button.longPressed && 
            (currentTime - button.pressTime) > BUTTON_LONG_PRESS_TIME) {
            button.longPressed = true;
        }
        
        button.lastState = currentReading;
    }
    
    void handleButtonEvents(SystemState& state, DisplayManager& display,
                           ProgramManager& programManager, ActuatorManager& actuatorManager,
                           unsigned long currentTime) {
        
        // Пробуждение дисплея при любом нажатии
        if (menuButton.pressed || upButton.pressed || downButton.pressed || okButton.pressed) {
            if (state.displaySleeping) {
                display.wakeUp(state);
                return;
            }
            state.updateInteraction();
        }
        
        // Аварийная остановка (долгое нажатие MENU)
        if (menuButton.longPressed) {
            if (state.mode == SystemState::Mode::RUNNING ||
                state.mode == SystemState::Mode::WAITING_SMOKE_IGNITION) {
                state.mode = SystemState::Mode::EMERGENCY_STOP;
                state.emergencyStop = true;
                actuatorManager.emergencyStop();
                Serial.println("USER EMERGENCY STOP activated");
            }
        }
        
        // Подтверждение розжига кнопкой OK в состоянии WAITING_SMOKE_IGNITION
        if (state.mode == SystemState::Mode::WAITING_SMOKE_IGNITION) {
            if (okButton.pressed) {
                state.smokeIgnitionConfirmed = true;
                Serial.println("[ButtonManager] Smoke ignition confirmed via OK button");
            }
            return; // Не обрабатываем другие кнопки в этом состоянии
        }
        
        // Обработка навигации в зависимости от режима дисплея
        switch (state.displayMode) {
            case SystemState::DisplayMode::MAIN_SCREEN:
                handleMainScreenButtons(state, display, programManager, actuatorManager);
                break;
                
            case SystemState::DisplayMode::PROGRAM_SELECTION:
                handleProgramSelectionButtons(state, display, programManager);
                break;
                
            case SystemState::DisplayMode::PROGRAM_RUNNING:
                handleProgramRunningButtons(state, display, programManager, actuatorManager);
                break;
                
            case SystemState::DisplayMode::SETTINGS_MENU:
                handleSettingsMenuButtons(state, display);
                break;
                
            case SystemState::DisplayMode::EMERGENCY_STOP:
                handleEmergencyStopButtons(state, display, actuatorManager);
                break;
                
            default:
                handleMainScreenButtons(state, display, programManager, actuatorManager);
                break;
        }
    }
    
    /**
     * Обработка кнопок на главном экране
     */
    void handleMainScreenButtons(SystemState& state, DisplayManager& display,
                                ProgramManager& programManager, ActuatorManager& actuatorManager) {
        if (upButton.pressed) {
            // Переход к меню настроек
            state.displayMode = SystemState::DisplayMode::SETTINGS_MENU;
            state.settingsMenu = SettingsMenuState{}; // сброс к начальному состоянию
        }
        
        if (downButton.pressed) {
            // Переход к выбору программы
            state.displayMode = SystemState::DisplayMode::PROGRAM_SELECTION;
            state.menuIndex = 0;
            state.maxMenuItems = programManager.getProgramCount();
        }
        
        if (okButton.pressed) {
            // Запуск последней программы или переход к выбору
            if (state.currentProgram) {
                // Запуск последней использованной программы
                programManager.startProgram(state.currentProgram->name, state);
                state.displayMode = SystemState::DisplayMode::PROGRAM_RUNNING;
            } else {
                // Переход к выбору программы
                state.displayMode = SystemState::DisplayMode::PROGRAM_SELECTION;
                state.menuIndex = 0;
                state.maxMenuItems = programManager.getProgramCount();
            }
        }
        
        if (menuButton.pressed && !menuButton.longPressed) {
            // Переход к меню управления программой
            state.displayMode = SystemState::DisplayMode::PROGRAM_SELECTION;
            state.menuIndex = 0;
            state.maxMenuItems = programManager.getProgramCount();
        }
    }
    
    /**
     * Обработка кнопок при выборе программы
     */
    void handleProgramSelectionButtons(SystemState& state, DisplayManager& display,
                                      ProgramManager& programManager) {
        // Навигация по списку программ
        if (upButton.pressed) {
            if (state.menuIndex > 0) {
                state.menuIndex--;
            }
        }
        
        if (downButton.pressed) {
            if (state.menuIndex < state.maxMenuItems - 1) {
                state.menuIndex++;
            }
        }
        
        if (okButton.pressed) {
            // Запуск выбранной программы
            auto programNames = programManager.getProgramNames();
            if (state.menuIndex < programNames.size()) {
                String programName = programNames[state.menuIndex];
                if (programManager.startProgram(programName, state)) {
                    state.displayMode = SystemState::DisplayMode::PROGRAM_RUNNING;
                    Serial.printf("Program started from button: %s\n", programName.c_str());
                }
            }
        }
        
        if (menuButton.pressed && !menuButton.longPressed) {
            // Возврат к главному экрану
            state.displayMode = SystemState::DisplayMode::MAIN_SCREEN;
        }
    }
    
    /**
     * Обработка кнопок при выполнении программы
     */
    void handleProgramRunningButtons(SystemState& state, DisplayManager& display,
                                    ProgramManager& programManager, ActuatorManager& actuatorManager) {
        if (okButton.pressed) {
            // Пауза/возобновление программы
            if (state.mode == SystemState::Mode::RUNNING) {
                state.mode = SystemState::Mode::PAUSED;
                Serial.println("Program paused by user");
            } else if (state.mode == SystemState::Mode::PAUSED) {
                state.mode = SystemState::Mode::RUNNING;
                Serial.println("Program resumed by user");
            }
        }
        
        if (menuButton.pressed && !menuButton.longPressed) {
            // Остановка программы
            if (programManager.stopProgram(state)) {
                state.displayMode = SystemState::DisplayMode::MAIN_SCREEN;
                Serial.println("Program stopped from button");
            }
        }
        
        if (upButton.pressed) {
            // Переключение между экранами: детали этапа → прогресс программы → датчики
            state.runningScreenIndex = (state.runningScreenIndex > 0) ? state.runningScreenIndex - 1 : 2;
        }
        
        if (downButton.pressed) {
            // Переключение между экранами в обратном направлении
            state.runningScreenIndex = (state.runningScreenIndex + 1) % 3;
        }
    }
    
    /**
     * Обработка кнопок в меню настроек — конечный автомат навигации
     */
    void handleSettingsMenuButtons(SystemState& state, DisplayManager& display) {
        SettingsMenuState& sm = state.settingsMenu;

        switch (sm.level) {

        // ── SECTION_LIST: верхний уровень, список разделов ──────────────────
        case SettingsMenuState::Level::SECTION_LIST: {
            if (upButton.pressed) {
                if (sm.sectionIndex > 0) sm.sectionIndex--;
                // Корректировка scrollOffset
                if (sm.sectionIndex < sm.scrollOffset)
                    sm.scrollOffset = sm.sectionIndex;
            }
            if (downButton.pressed) {
                if (sm.sectionIndex < MENU_SECTION_COUNT - 1) sm.sectionIndex++;
                // Корректировка scrollOffset
                if (sm.sectionIndex >= sm.scrollOffset + 4)
                    sm.scrollOffset = sm.sectionIndex - 3;
            }
            if (okButton.pressed) {
                sm.level       = SettingsMenuState::Level::ITEM_LIST;
                sm.itemIndex   = 0;
                sm.scrollOffset = 0;
            }
            if (menuButton.pressed && !menuButton.longPressed) {
                state.displayMode = SystemState::DisplayMode::MAIN_SCREEN;
            }
            break;
        }

        // ── ITEM_LIST: список пунктов раздела ────────────────────────────────
        case SettingsMenuState::Level::ITEM_LIST: {
            const MenuSection& section = MENU_SECTIONS[sm.sectionIndex];
            uint8_t itemCount = section.itemCount;

            if (upButton.pressed) {
                if (sm.itemIndex > 0) sm.itemIndex--;
                if (sm.itemIndex < sm.scrollOffset)
                    sm.scrollOffset = sm.itemIndex;
            }
            if (downButton.pressed) {
                if (sm.itemIndex < itemCount - 1) sm.itemIndex++;
                if (sm.itemIndex >= sm.scrollOffset + 4)
                    sm.scrollOffset = sm.itemIndex - 3;
            }
            if (okButton.pressed) {
                const MenuItem& item = section.items[sm.itemIndex];
                switch (item.type) {

                case MenuItem::Type::BOOL: {
                    // Toggle булевого флага оборудования (раздел 1)
                    if (sm.sectionIndex == 1) {
                        switch (sm.itemIndex) {
                        case 0:
                            settingsManager.igniterEnabled = !settingsManager.igniterEnabled;
                            break;
                        case 1:
                            settingsManager.ntcSmokeEnabled = !settingsManager.ntcSmokeEnabled;
                            break;
                        case 2:
                            settingsManager.ntcProductEnabled = !settingsManager.ntcProductEnabled;
                            break;
                        }
                        settingsManager.save();
                    }
                    // Остаёмся в ITEM_LIST
                    break;
                }

                case MenuItem::Type::NUMERIC: {
                    // Определяем текущее значение параметра
                    int32_t currentVal = 0;
                    if (sm.sectionIndex == 0 && sm.itemIndex == 1) {
                        currentVal = settingsManager.utcOffset;
                    } else if (sm.sectionIndex == 2) {
                        switch (sm.itemIndex) {
                        case 0: currentVal = settingsManager.hysteresis;        break;
                        case 1: currentVal = settingsManager.telemetryInterval; break;
                        case 2: currentVal = settingsManager.displayBrightness; break;
                        }
                    }
                    sm.editValue = currentVal;
                    sm.editMin   = item.minVal;
                    sm.editMax   = item.maxVal;
                    sm.editStep  = item.step;
                    sm.level     = SettingsMenuState::Level::NUMERIC_EDIT;
                    break;
                }

                case MenuItem::Type::DATETIME: {
                    // Инициализируем dtBuf текущим временем
                    time_t now;
                    time(&now);
                    localtime_r(&now, &sm.dtBuf);
                    sm.dtField = 0;
                    sm.level   = SettingsMenuState::Level::DATETIME_EDIT;
                    break;
                }

                case MenuItem::Type::ACTION: {
                    sm.confirmYes = false;
                    sm.level      = SettingsMenuState::Level::CONFIRM_DIALOG;
                    break;
                }
                }
            }
            if (menuButton.pressed && !menuButton.longPressed) {
                sm.level        = SettingsMenuState::Level::SECTION_LIST;
                sm.scrollOffset = 0;
            }
            break;
        }

        // ── NUMERIC_EDIT: редактирование числового значения ──────────────────
        case SettingsMenuState::Level::NUMERIC_EDIT: {
            if (upButton.pressed) {
                sm.editValue = min(sm.editValue + sm.editStep, sm.editMax);
            }
            if (downButton.pressed) {
                sm.editValue = max(sm.editValue - sm.editStep, sm.editMin);
            }
            if (okButton.pressed) {
                // Сохраняем значение в соответствующее поле SettingsManager
                if (sm.sectionIndex == 0 && sm.itemIndex == 1) {
                    settingsManager.utcOffset = (int8_t)sm.editValue;
                } else if (sm.sectionIndex == 2) {
                    switch (sm.itemIndex) {
                    case 0: settingsManager.hysteresis        = (uint8_t)sm.editValue;  break;
                    case 1: settingsManager.telemetryInterval = (uint16_t)sm.editValue; break;
                    case 2:
                        settingsManager.displayBrightness = (uint8_t)sm.editValue;
                        display.applyBrightness(sm.editValue);
                        break;
                    }
                }
                settingsManager.save();
                sm.level = SettingsMenuState::Level::ITEM_LIST;
            }
            if (menuButton.pressed && !menuButton.longPressed) {
                // Отмена — без сохранения
                sm.level = SettingsMenuState::Level::ITEM_LIST;
            }
            break;
        }

        // ── DATETIME_EDIT: ввод даты/времени поле за полем ──────────────────
        case SettingsMenuState::Level::DATETIME_EDIT: {
            // Вспомогательная функция: получить/установить текущее поле dtBuf
            auto getField = [&]() -> int {
                switch (sm.dtField) {
                case 0: return sm.dtBuf.tm_year + 1900;
                case 1: return sm.dtBuf.tm_mon + 1;
                case 2: return sm.dtBuf.tm_mday;
                case 3: return sm.dtBuf.tm_hour;
                case 4: return sm.dtBuf.tm_min;
                default: return 0;
                }
            };
            auto setField = [&](int val) {
                switch (sm.dtField) {
                case 0: sm.dtBuf.tm_year = val - 1900; break;
                case 1: sm.dtBuf.tm_mon  = val - 1;    break;
                case 2: sm.dtBuf.tm_mday = val;         break;
                case 3: sm.dtBuf.tm_hour = val;         break;
                case 4: sm.dtBuf.tm_min  = val;         break;
                }
            };
            // Диапазоны полей: год 2024-2099, мес 1-12, день 1-31, час 0-23, мин 0-59
            static const int dtMin[] = { 2024, 1, 1,  0,  0 };
            static const int dtMax[] = { 2099, 12, 31, 23, 59 };

            if (upButton.pressed) {
                int v = getField();
                if (v < dtMax[sm.dtField]) v++; else v = dtMin[sm.dtField];
                setField(v);
            }
            if (downButton.pressed) {
                int v = getField();
                if (v > dtMin[sm.dtField]) v--; else v = dtMax[sm.dtField];
                setField(v);
            }
            if (okButton.pressed) {
                if (sm.dtField < 4) {
                    sm.dtField++;
                } else {
                    // Применяем дату/время через settimeofday
                    sm.dtBuf.tm_sec = 0;
                    struct timeval tv;
                    tv.tv_sec  = mktime(&sm.dtBuf);
                    tv.tv_usec = 0;
                    settimeofday(&tv, nullptr);
                    sm.level = SettingsMenuState::Level::ITEM_LIST;
                }
            }
            if (menuButton.pressed && !menuButton.longPressed) {
                if (sm.dtField > 0) {
                    sm.dtField--;
                } else {
                    // Отмена
                    sm.level = SettingsMenuState::Level::ITEM_LIST;
                }
            }
            break;
        }

        // ── CONFIRM_DIALOG: диалог подтверждения ─────────────────────────────
        case SettingsMenuState::Level::CONFIRM_DIALOG: {
            if (upButton.pressed || downButton.pressed) {
                sm.confirmYes = !sm.confirmYes;
            }
            if (okButton.pressed) {
                if (sm.confirmYes) {
                    // Выполняем действие: раздел 3 (Система)
                    if (sm.sectionIndex == 3) {
                        if (sm.itemIndex == 0) {
                            // Сброс настроек
                            settingsManager.reset();
                        } else if (sm.itemIndex == 1) {
                            // Перезагрузка
                            ESP.restart();
                        }
                    }
                }
                sm.level = SettingsMenuState::Level::ITEM_LIST;
            }
            if (menuButton.pressed && !menuButton.longPressed) {
                sm.level = SettingsMenuState::Level::ITEM_LIST;
            }
            break;
        }

        } // switch (sm.level)
    }
    
    /**
     * Обработка кнопок при аварийной остановке
     */
    void handleEmergencyStopButtons(SystemState& state, DisplayManager& display,
                                   ActuatorManager& actuatorManager) {
        if (okButton.pressed) {
            // Сброс аварийной остановки
            state.emergencyStop = false;
            state.mode = SystemState::Mode::IDLE;
            state.displayMode = SystemState::DisplayMode::MAIN_SCREEN;
            Serial.println("Emergency stop reset");
        }
        
        if (menuButton.pressed && !menuButton.longPressed) {
            // Возврат к главному экрану
            state.displayMode = SystemState::DisplayMode::MAIN_SCREEN;
        }
    }
    
    void resetButtonFlags() {
        menuButton.pressed = false;
        upButton.pressed = false;
        downButton.pressed = false;
        okButton.pressed = false;
        
        if (!menuButton.currentState) menuButton.longPressed = false;
        if (!upButton.currentState) upButton.longPressed = false;
        if (!downButton.currentState) downButton.longPressed = false;
        if (!okButton.currentState) okButton.longPressed = false;
    }
};

#endif // BUTTON_MANAGER_H