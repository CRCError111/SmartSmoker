/**
 * IgniterManager — управление автоматом розжига дымогенератора
 *
 * Выход PIN_IGNITER_CMD  (GPIO27): подаём импульс для запуска розжига
 * Вход  PIN_IGNITER_STATUS (GPIO36): анализируем ответ автомата
 *
 * Протокол ответа (окно IGNITER_SIGNAL_WINDOW = 5000 мс,
 * отсчёт от первого перехода 0→1):
 *   1 переход → успешный розжиг
 *   2 перехода → газ закончился
 *   3 перехода → газ замёрз
 *
 * @file IgniterManager.h
 * @version 1.0
 */

#ifndef IGNITER_MANAGER_H
#define IGNITER_MANAGER_H

#include <Arduino.h>
#include "pins.h"
#include "constants.h"

enum class IgniterState : uint8_t {
    IDLE,           // Ожидание
    CMD_SENT,       // Команда отправлена, ждём первого сигнала
    COUNTING,       // Первый сигнал получен, считаем переходы в окне
    DONE            // Анализ завершён, результат готов
};

enum class IgniterResult : uint8_t {
    NONE            = 0,
    SUCCESS         = IGNITER_RESULT_SUCCESS,
    NO_GAS          = IGNITER_RESULT_NO_GAS,
    GAS_FROZEN      = IGNITER_RESULT_GAS_FROZEN,
    TIMEOUT         = 4,  // Нет ответа от автомата
    ERROR           = 5   // Неожиданное количество сигналов
};

class IgniterManager {
public:
    void begin() {
        pinMode(PIN_IGNITER_CMD,    OUTPUT);
        pinMode(PIN_IGNITER_STATUS, INPUT);
        digitalWrite(PIN_IGNITER_CMD, LOW);
        _state       = IgniterState::IDLE;
        _result      = IgniterResult::NONE;
        _lastPinState = LOW;
        _pulseCount  = 0;
        _windowStart = 0;
        _cmdStart    = 0;
    }

    // Запустить розжиг — вызвать один раз
    void startIgnition() {
        if (_state != IgniterState::IDLE) return;

        // Проверка cooldown между попытками
        if (_lastIgnitionTime > 0 && (millis() - _lastIgnitionTime) < IGNITER_COOLDOWN_MS) {
            Serial.printf("[Igniter] Cooldown active, %lu ms remaining\n",
                IGNITER_COOLDOWN_MS - (millis() - _lastIgnitionTime));
            return;
        }

        // Проверка максимального количества последовательных попыток
        if (_consecutiveAttempts >= IGNITER_MAX_ATTEMPTS) {
            Serial.println("[WARN] [Igniter] Max consecutive attempts reached, reset required");
            _result = IgniterResult::ERROR;
            _state  = IgniterState::DONE;
            return;
        }

        Serial.printf("[Igniter] Sending ignition command (attempt %d/%d)\n",
            _consecutiveAttempts + 1, IGNITER_MAX_ATTEMPTS);
        digitalWrite(PIN_IGNITER_CMD, HIGH);
        _cmdStart    = millis();
        _lastIgnitionTime = _cmdStart;
        _consecutiveAttempts++;
        _state       = IgniterState::CMD_SENT;
        _result      = IgniterResult::NONE;
        _pulseCount  = 0;
        _lastPinState = digitalRead(PIN_IGNITER_STATUS);
        _windowStart = 0;
    }

    // Вызывать в каждой итерации loop()
    void update() {
        switch (_state) {

            case IgniterState::CMD_SENT:
                // Снимаем командный импульс после IGNITER_CMD_PULSE_MS
                if (millis() - _cmdStart >= IGNITER_CMD_PULSE_MS) {
                    digitalWrite(PIN_IGNITER_CMD, LOW);
                }
                // Следим за первым переходом 0→1
                _detectRisingEdge();
                // Таймаут ожидания первого сигнала — 30 сек
                if (_windowStart == 0 && millis() - _cmdStart > 30000) {
                    _state  = IgniterState::DONE;
                    _result = IgniterResult::TIMEOUT;
                    Serial.println("[Igniter] Timeout waiting for first signal");
                }
                break;

            case IgniterState::COUNTING:
                _detectRisingEdge();
                // Окно анализа истекло
                if (millis() - _windowStart >= IGNITER_SIGNAL_WINDOW) {
                    _state = IgniterState::DONE;
                    _decodeResult();
                }
                break;

            case IgniterState::IDLE:
            case IgniterState::DONE:
            default:
                break;
        }
    }

    bool isDone()    const { return _state == IgniterState::DONE; }
    bool isIdle()    const { return _state == IgniterState::IDLE; }
    bool isBusy()    const { return _state == IgniterState::CMD_SENT || _state == IgniterState::COUNTING; }

    IgniterResult getResult() const { return _result; }

    // Сбросить в IDLE после обработки результата
    void reset() {
        digitalWrite(PIN_IGNITER_CMD, LOW);
        _state      = IgniterState::IDLE;
        // Сбрасываем счётчик последовательных попыток только при успехе
        if (_result == IgniterResult::SUCCESS) {
            _consecutiveAttempts = 0;
        }
        _result     = IgniterResult::NONE;
        _pulseCount = 0;
        _windowStart = 0;
    }

    // Текстовое описание результата
    static const char* resultText(IgniterResult r) {
        switch (r) {
            case IgniterResult::SUCCESS:    return "Розжиг успешен";
            case IgniterResult::NO_GAS:     return "Газ закончился в баллоне";
            case IgniterResult::GAS_FROZEN: return "Газ замёрз";
            case IgniterResult::TIMEOUT:    return "Нет ответа от автомата розжига";
            case IgniterResult::ERROR:      return "Неожиданный сигнал от автомата";
            default:                        return "Нет данных";
        }
    }

private:
    IgniterState  _state       = IgniterState::IDLE;
    IgniterResult _result      = IgniterResult::NONE;
    uint8_t       _lastPinState = LOW;
    uint8_t       _pulseCount  = 0;
    uint32_t      _windowStart = 0;
    uint32_t      _cmdStart    = 0;
    uint32_t      _lastIgnitionTime   = 0;
    uint8_t       _consecutiveAttempts = 0;

    static constexpr uint32_t IGNITER_COOLDOWN_MS  = 30000; // 30 сек между попытками
    static constexpr uint8_t  IGNITER_MAX_ATTEMPTS = 5;     // Макс. попыток подряд

    void _detectRisingEdge() {
        uint8_t current = digitalRead(PIN_IGNITER_STATUS);
        if (_lastPinState == LOW && current == HIGH) {
            // Переход 0→1
            _pulseCount++;
            if (_pulseCount == 1) {
                // Первый переход — запускаем окно
                _windowStart = millis();
                _state = IgniterState::COUNTING;
                Serial.printf("[Igniter] First rising edge, window started\n");
            } else {
                Serial.printf("[Igniter] Rising edge #%d at +%lu ms\n",
                    _pulseCount, millis() - _windowStart);
            }
        }
        _lastPinState = current;
    }

    void _decodeResult() {
        Serial.printf("[Igniter] Window closed, pulse count = %d\n", _pulseCount);
        switch (_pulseCount) {
            case IGNITER_RESULT_SUCCESS:
                _result = IgniterResult::SUCCESS;    break;
            case IGNITER_RESULT_NO_GAS:
                _result = IgniterResult::NO_GAS;     break;
            case IGNITER_RESULT_GAS_FROZEN:
                _result = IgniterResult::GAS_FROZEN; break;
            default:
                _result = IgniterResult::ERROR;      break;
        }
        Serial.printf("[Igniter] Result: %s\n", resultText(_result));
    }
};

#endif // IGNITER_MANAGER_H
