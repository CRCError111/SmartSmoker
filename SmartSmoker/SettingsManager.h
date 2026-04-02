/**
 * SettingsManager — хранение и загрузка настроек через NVS (Preferences.h)
 *
 * Пространство имён NVS: "settings"
 * Device_ID хранится в отдельном namespace "device" и не затрагивается при reset().
 *
 * Ключи NVS:
 *   ign_en     (Bool)   — igniterEnabled
 *   ntc_smk    (Bool)   — ntcSmokeEnabled
 *   ntc_prd    (Bool)   — ntcProductEnabled
 *   hysteresis (UInt8)  — hysteresis
 *   telem_iv   (UInt16) — telemetryInterval
 *   brightness (UInt8)  — displayBrightness
 *   utc_offset (Int8)   — utcOffset
 *
 * @file SettingsManager.h
 * @version 1.0
 */

#ifndef SETTINGS_MANAGER_H
#define SETTINGS_MANAGER_H

#include <Arduino.h>
#include <Preferences.h>
#include "constants.h"

class SettingsManager {
public:
    // Инициализация: открывает NVS namespace "settings", загружает все значения
    void begin();

    // Сохранение всех полей в NVS
    void save();

    // Сброс namespace "settings" (не трогает "device"), форматирует LittleFS, перезагружает
    void reset();

    // --- Поля настроек (публичные, читаются напрямую) ---
    bool     igniterEnabled    = IGNITER_ENABLED;
    bool     ntcSmokeEnabled   = true;
    bool     ntcProductEnabled = true;
    uint8_t  hysteresis        = DEFAULT_HYSTERESIS;              // 1–10 °C
    uint16_t telemetryInterval = CLOUD_SEND_INTERVAL / 1000;      // 30–3600 с
    uint8_t  displayBrightness = 100;                             // 10–100 %
    int8_t   utcOffset         = 0;                               // -12..+14
    String   webServerPassword = "";                              // C-04: пароль локального веб-сервера

private:
    Preferences _prefs;

    // Загрузка значений из NVS (вызывается из begin())
    void _load();
};

extern SettingsManager settingsManager;

#endif // SETTINGS_MANAGER_H
