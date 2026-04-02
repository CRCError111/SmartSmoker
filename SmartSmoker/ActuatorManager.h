/**
 * Менеджер исполнительных механизмов согласно ТЗ
 * 
 * @file ActuatorManager.h
 * @version 1.0
 */

#ifndef ACTUATOR_MANAGER_H
#define ACTUATOR_MANAGER_H

#include <Arduino.h>
#include <ESP32Servo.h>
#include <driver/ledc.h>
#include "pins.h"
#include "constants.h"
#include "SystemState.h"

/**
 * Класс для управления всеми исполнительными механизмами
 */
class ActuatorManager {
private:
    Servo damperServo;                         // Сервопривод заслонки
    
    // Состояние механизмов
    bool heaterState = false;
    uint8_t smokePWMValue = 0;
    bool fanInternalState = false;
    bool fanInjectionState = false;
    uint8_t damperAngle = 90;                  // Текущий угол заслонки
    
    // Защита ТЭНа
    unsigned long lastHeaterChange = 0;
    bool heaterCooldownActive = false;
    
    // Плавное движение сервопривода
    uint8_t targetDamperAngle = 90;
    unsigned long lastServoMove = 0;
    
    // Таймаут дымогенератора
    unsigned long smokeGeneratorStartTime = 0;
    bool smokeGeneratorTimeout = false;

public:
    /**
     * Инициализация всех исполнительных механизмов
     */
    bool init() {
        // Инициализация GPIO для реле и MOSFET
        pinMode(PIN_HEATER_SSR, OUTPUT);
        pinMode(PIN_FAN_INTERNAL, OUTPUT);
        pinMode(PIN_FAN_INJECTION, OUTPUT);
        
        // Начальное состояние - все выключено
        digitalWrite(PIN_HEATER_SSR, LOW);
        digitalWrite(PIN_FAN_INTERNAL, LOW);
        digitalWrite(PIN_FAN_INJECTION, LOW);
        
        // Инициализация ШИМ для дымогенератора (новый API ESP32 v3.x)
        ledcAttach(PIN_SMOKE_MOSFET, PWM_FREQUENCY, PWM_RESOLUTION);
        ledcWrite(PIN_SMOKE_MOSFET, 0);
        
        // Инициализация сервопривода заслонки
        damperServo.attach(PIN_SERVO_VENT);
        damperServo.write(90);
        delay(500);
        
        return true;
    }
    
    /**
     * Обновление состояния всех исполнительных механизмов
     */
    void update(SystemState& state) {
        unsigned long currentTime = millis();
        
        // Обновление ТЭНа с защитой
        updateHeater(state, currentTime);
        
        // Обновление дымогенератора
        updateSmokeGenerator(state, currentTime);
        
        // Обновление вентиляторов
        updateFans(state);
        
        // Плавное движение заслонки
        updateDamperServo(state, currentTime);
        
        // Проверка таймаутов и защит
        checkSafetyLimits(state, currentTime);
    }
    
    /**
     * Установка состояния ТЭНа
     */
    void setHeater(bool enable, SystemState& state) {
        unsigned long currentTime = millis();
        
        // Проверка минимального времени между переключениями
        if (currentTime - lastHeaterChange < MIN_HEATER_OFF_TIME) {
            heaterCooldownActive = true;
            return;
        }
        
        // Проверка аварийных условий
        if (state.emergencyStop || state.sensorError || state.tempChamber > MAX_TEMP_LIMIT) {
            enable = false;
        }
        
        if (heaterState != enable) {
            heaterState = enable;
            digitalWrite(PIN_HEATER_SSR, enable ? HIGH : LOW);
            lastHeaterChange = currentTime;
            heaterCooldownActive = false;
            
            state.heaterOn = enable;
            state.lastHeaterChange = currentTime;
            
            #if DEBUG_SERIAL_ENABLED
            Serial.printf("Heater %s\n", enable ? "ON" : "OFF");
            #endif
        }
    }
    
    /**
     * Установка мощности дымогенератора (0-100%)
     */
    void setSmokeGenerator(uint8_t pwmPercent, SystemState& state) {
        // Ограничение диапазона с логированием некорректных значений
        if (pwmPercent > 100) {
            Serial.printf("[WARN] setSmokeGenerator: значение %d вне диапазона [0, 100], обрезается\n", pwmPercent);
        }
        pwmPercent = constrain(pwmPercent, 0, 100);
        
        // Проверка аварийных условий
        if (state.emergencyStop || state.sensorError) {
            pwmPercent = 0;
        }
        
        if (smokePWMValue != pwmPercent) {
            smokePWMValue = pwmPercent;
            
            // Преобразование процентов в значение ШИМ (0-255)
            uint8_t pwmValue = map(pwmPercent, 0, 100, 0, 255);
            ledcWrite(PIN_SMOKE_MOSFET, pwmValue);
            
            state.smokePWM = pwmPercent;
            
            // Запуск таймера при включении
            if (pwmPercent > 0 && smokeGeneratorStartTime == 0) {
                smokeGeneratorStartTime = millis();
                smokeGeneratorTimeout = false;
            } else if (pwmPercent == 0) {
                smokeGeneratorStartTime = 0;
                smokeGeneratorTimeout = false;
            }
            
            #if DEBUG_SERIAL_ENABLED
            Serial.printf("Smoke generator: %d%% (PWM: %d)\n", pwmPercent, pwmValue);
            #endif
        }
    }
    
    /**
     * Установка состояния внутреннего вентилятора
     */
    void setInternalFan(bool enable, SystemState& state) {
        // Проверка аварийных условий
        if (state.emergencyStop) {
            enable = false;
        }
        
        if (fanInternalState != enable) {
            fanInternalState = enable;
            digitalWrite(PIN_FAN_INTERNAL, enable ? HIGH : LOW);
            state.fanInternalOn = enable;
            
            #if DEBUG_SERIAL_ENABLED
            Serial.printf("Internal fan %s\n", enable ? "ON" : "OFF");
            #endif
        }
    }
    
    /**
     * Установка состояния вентилятора подачи воздуха
     */
    void setInjectionFan(bool enable, SystemState& state) {
        // Проверка аварийных условий
        if (state.emergencyStop) {
            enable = false;
        }
        
        if (fanInjectionState != enable) {
            fanInjectionState = enable;
            digitalWrite(PIN_FAN_INJECTION, enable ? HIGH : LOW);
            state.fanInjectionOn = enable;
            
            #if DEBUG_SERIAL_ENABLED
            Serial.printf("Injection fan %s\n", enable ? "ON" : "OFF");
            #endif
        }
    }
    
    /**
     * Установка позиции заслонки (0-100%)
     */
    void setDamperPosition(uint8_t percent, SystemState& state) {
        // Ограничение диапазона с логированием некорректных значений
        if (percent > 100) {
            Serial.printf("[WARN] setDamperPosition: значение %d вне диапазона [0, 100], обрезается\n", percent);
        }
        percent = constrain(percent, 0, 100);
        
        // Преобразование процентов в угол (0% = 0°, 100% = 90°)
        uint8_t angle = map(percent, 0, 100, 0, MAX_SERVO_ANGLE);
        
        if (targetDamperAngle != angle) {
            targetDamperAngle = angle;
            state.damperPosition = angle;
            
            #if DEBUG_SERIAL_ENABLED
            Serial.printf("Damper target: %d%% (%d°)\n", percent, angle);
            #endif
        }
    }
    
    /**
     * Аварийная остановка всех механизмов
     */
    void emergencyStop() {
        Serial.println("EMERGENCY STOP: Disabling all actuators");
        
        // Немедленное отключение всех механизмов
        digitalWrite(PIN_HEATER_SSR, LOW);
        digitalWrite(PIN_FAN_INTERNAL, LOW);
        digitalWrite(PIN_FAN_INJECTION, LOW);
        ledcWrite(PIN_SMOKE_MOSFET, 0);
        
        // Заслонка в полностью открытое положение для безопасности
        damperServo.write(MAX_SERVO_ANGLE);
        
        // Сброс состояний
        heaterState = false;
        smokePWMValue = 0;
        fanInternalState = false;
        fanInjectionState = false;
        damperAngle = MAX_SERVO_ANGLE;
        targetDamperAngle = MAX_SERVO_ANGLE;
        
        // Сброс таймеров
        smokeGeneratorStartTime = 0;
        smokeGeneratorTimeout = false;
        heaterCooldownActive = false;
    }
    
    /**
     * Интеллектуальное управление ТЭНом для поддержания целевой температуры
     * Используется ProgramManager для автоматического управления
     */
    void setTargetTemperature(float targetTemp, float currentTemp, float hysteresis, SystemState& state) {
        // Проверка аварийных условий
        if (state.emergencyStop || state.sensorError || state.tempChamber > MAX_TEMP_LIMIT) {
            setHeater(false, state);
            return;
        }
        
        // Логика управления с гистерезисом
        if (currentTemp < targetTemp - hysteresis) {
            // Включить нагрев
            setHeater(true, state);
        } else if (currentTemp > targetTemp + hysteresis) {
            // Выключить нагрев
            setHeater(false, state);
        }
        // Если температура в пределах гистерезиса, оставить текущее состояние
    }
    
    /**
     * Установка заслонки (синоним для setDamperPosition для совместимости с ProgramManager)
     */
    void setDamper(uint8_t percent, SystemState& state) {
        setDamperPosition(percent, state);
    }
    
    /**
     * Установка всех исполнительных механизмов по программе
     * Упрощенный метод для ProgramManager
     */
    void setActuatorsByProgram(float targetTemp, float currentTemp, float hysteresis,
                               bool useSmokeGenerator, int smokePWM,
                               uint8_t damperPercent, bool internalFan, bool injectionFan,
                               SystemState& state) {
        // Управление ТЭНом
        setTargetTemperature(targetTemp, currentTemp, hysteresis, state);
        
        // Управление дымогенератором
        if (useSmokeGenerator) {
            setSmokeGenerator(smokePWM, state);
        } else {
            setSmokeGenerator(0, state);
        }
        
        // Управление заслонкой
        setDamper(damperPercent, state);
        
        // Управление вентиляторами
        setInternalFan(internalFan, state);
        setInjectionFan(injectionFan, state);
    }

private:
    /**
     * Обновление ТЭНа с защитой
     */
    void updateHeater(SystemState& state, unsigned long currentTime) {
        // Проверка окончания периода охлаждения
        if (heaterCooldownActive && (currentTime - lastHeaterChange >= MIN_HEATER_OFF_TIME)) {
            heaterCooldownActive = false;
        }
        
        // Обновление флага в состоянии
        state.heaterCooldown = heaterCooldownActive;
    }
    
    /**
     * Обновление дымогенератора
     */
    void updateSmokeGenerator(SystemState& state, unsigned long currentTime) {
        // Проверка таймаута дымогенератора
        if (smokeGeneratorStartTime > 0 && !smokeGeneratorTimeout) {
            if (currentTime - smokeGeneratorStartTime >= SMOKE_GENERATOR_TIMEOUT) {
                smokeGeneratorTimeout = true;
                setSmokeGenerator(0, state);
                Serial.println("[WARN] Smoke generator timeout - automatically disabled");
            }
        }
    }
    
    /**
     * Обновление вентиляторов
     */
    void updateFans(SystemState& state) {
        // Автоматическое управление внутренним вентилятором при перегреве
        if (!state.emergencyStop && state.tempChamber > (MAX_TEMP_LIMIT - 10)) {
            if (!fanInternalState) {
                setInternalFan(true, state);
                Serial.println("[WARN] Auto-enabling internal fan due to high temperature");
            }
        }
    }
    
    /**
     * Плавное движение сервопривода заслонки
     */
    void updateDamperServo(SystemState& state, unsigned long currentTime) {
        if (damperAngle != targetDamperAngle && 
            (currentTime - lastServoMove >= SERVO_MOVE_DELAY)) {
            
            // Плавное движение с шагом
            if (damperAngle < targetDamperAngle) {
                damperAngle = (damperAngle + SERVO_STEP_ANGLE < targetDamperAngle) ? 
                             damperAngle + SERVO_STEP_ANGLE : targetDamperAngle;
            } else {
                damperAngle = (damperAngle - SERVO_STEP_ANGLE > targetDamperAngle) ? 
                             damperAngle - SERVO_STEP_ANGLE : targetDamperAngle;
            }
            
            damperServo.write(damperAngle);
            lastServoMove = currentTime;
            
            #if DEBUG_SERIAL_ENABLED
            Serial.printf("Damper moving to %d°\n", damperAngle);
            #endif
        }
    }
    
    /**
     * Проверка ограничений безопасности
     */
    void checkSafetyLimits(SystemState& state, unsigned long currentTime) {
        // Проверка критической температуры
        if (state.tempChamber > MAX_TEMP_LIMIT) {
            if (heaterState) {
                setHeater(false, state);
                Serial.println("[ERROR] Heater disabled due to temperature limit exceeded");
            }
            
            if (!fanInternalState) {
                setInternalFan(true, state);
            }
            
            if (targetDamperAngle != MAX_SERVO_ANGLE) {
                setDamperPosition(100, state);
            }
        }
        
        if (state.sensorError) {
            if (heaterState) {
                setHeater(false, state);
            }
            if (smokePWMValue > 0) {
                setSmokeGenerator(0, state);
            }
        }
    }
};

#endif // ACTUATOR_MANAGER_H