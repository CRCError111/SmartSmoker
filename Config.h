#pragma once

constexpr int MAX_TEMP_LIMIT = 100;
constexpr unsigned long MIN_HEATER_OFF_TIME = 30000;

constexpr uint8_t PIN_NTC_SMOKE = 34;
constexpr uint8_t PIN_NTC_PRODUCT = 35;
constexpr uint8_t PIN_HEATER_SSR = 25;
constexpr uint8_t PIN_SMOKE_MOSFET = 26;
constexpr uint8_t PIN_FAN_MIXER = 27; // ← новое
constexpr uint8_t PIN_BTN_UP = 12;
constexpr uint8_t PIN_BTN_DOWN = 13;
constexpr uint8_t PIN_BTN_OK = 14;
constexpr uint8_t PIN_BTN_BACK = 15;
constexpr uint8_t I2C_SDA = 21;
constexpr uint8_t I2C_SCL = 22;