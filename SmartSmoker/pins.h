/**
 * Конфигурация пинов ESP32 согласно ТЗ
 * 
 * @file pins.h
 * @version 1.0
 */

#ifndef PINS_H
#define PINS_H

#include <Arduino.h>
#include <driver/adc.h>

// =====================================================
// ДАТЧИКИ
// =====================================================
constexpr uint8_t PIN_NTC_SMOKE = 34;    // NTC датчик дыма (GPIO34)
constexpr uint8_t PIN_NTC_PRODUCT = 35;  // NTC датчик продукта (GPIO35)
constexpr uint8_t I2C_SDA = 21;          // I2C для BME280 и OLED (GPIO21)
constexpr uint8_t I2C_SCL = 22;          // I2C для BME280 и OLED (GPIO22)

// =====================================================
// ИСПОЛНИТЕЛЬНЫЕ МЕХАНИЗМЫ
// =====================================================
constexpr uint8_t PIN_HEATER_SSR = 4;    // Твердотельное реле ТЭНа (GPIO4)
constexpr uint8_t PIN_SMOKE_MOSFET = 16; // MOSFET для дымогенератора (GPIO16) — перенесён с GPIO5 (strapping pin)
constexpr uint8_t PIN_FAN_INTERNAL = 18; // Вентилятор в камере (GPIO18)
constexpr uint8_t PIN_FAN_INJECTION = 19;// Вентилятор подачи воздуха (GPIO19)
constexpr uint8_t PIN_SERVO_VENT = 13;   // Сервопривод заслонки (GPIO13)

// =====================================================
// TFT-ДИСПЛЕЙ ST7789V (SPI)
// =====================================================
constexpr uint8_t TFT_MOSI = 23;   // SPI MOSI (GPIO23)
constexpr uint8_t TFT_MISO = 12;   // SPI MISO (GPIO12)
constexpr uint8_t TFT_SCK  = 14;   // SPI CLK  (GPIO14)
constexpr uint8_t TFT_CS   = 15;   // Chip Select (GPIO15)
constexpr uint8_t TFT_DC   =  2;   // Data/Command (GPIO2)
constexpr uint8_t TFT_RST  =  0;   // Reset (GPIO0)
constexpr int8_t  TFT_BL   = -1;   // Backlight — управляется через LovyanGFX setBrightness()

// =====================================================
// КНОПКИ УПРАВЛЕНИЯ
// =====================================================
constexpr uint8_t PIN_BUTTON_MENU = 32;  // Кнопка MENU (GPIO32)
constexpr uint8_t PIN_BUTTON_UP = 33;    // Кнопка UP (GPIO33)
constexpr uint8_t PIN_BUTTON_DOWN = 25;  // Кнопка DOWN (GPIO25)
constexpr uint8_t PIN_BUTTON_OK = 26;    // Кнопка OK (GPIO26)

// =====================================================
// АВТОМАТ РОЗЖИГА ДЫМОГЕНЕРАТОРА
// =====================================================
constexpr uint8_t PIN_IGNITER_CMD    = 27;  // Команда на автомат розжига (GPIO27, выход)
constexpr uint8_t PIN_IGNITER_STATUS = 36;  // Статус от автомата розжига (GPIO36 VP, вход)

// =====================================================
// ШИМ КАНАЛЫ
// =====================================================
constexpr uint8_t PWM_CHANNEL_SMOKE = 0;     // Канал ШИМ для дымогенератора
constexpr uint8_t PWM_CHANNEL_SERVO = 1;     // Канал ШИМ для сервопривода

// =====================================================
// ADC НАСТРОЙКИ
// =====================================================
constexpr adc1_channel_t ADC_CHANNEL_SMOKE = ADC1_CHANNEL_6;    // GPIO34
constexpr adc1_channel_t ADC_CHANNEL_PRODUCT = ADC1_CHANNEL_7;  // GPIO35
constexpr adc_atten_t ADC_ATTENUATION = ADC_ATTEN_DB_12;        // 0-3.3V (обновлено с ADC_ATTEN_DB_11)
constexpr adc_bits_width_t ADC_WIDTH = ADC_WIDTH_BIT_12;        // 12-bit resolution

#endif // PINS_H