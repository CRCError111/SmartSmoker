/**
 * SettingsManager — реализация хранения и загрузки настроек через NVS (Preferences.h)
 *
 * NVS namespace: "settings"
 * Device_ID хранится в отдельном namespace "device" и не затрагивается при reset().
 *
 * @file SettingsManager.cpp
 * @version 1.0
 */

#include "SettingsManager.h"
#include <LittleFS.h>

// Глобальный экземпляр
SettingsManager settingsManager;

// =====================================================
// Public Methods
// =====================================================

/**
 * Инициализация: открывает NVS namespace "settings" и загружает все значения.
 * Должен вызываться первым в setup(), до остальных менеджеров.
 */
void SettingsManager::begin() {
    Serial.println("[SETTINGS] Initializing SettingsManager...");
    _prefs.begin("settings", false);
    _load();
    Serial.println("[SETTINGS] Settings loaded.");
}

/**
 * Сохранение всех полей настроек в NVS.
 */
void SettingsManager::save() {
    Serial.println("[SETTINGS] Saving settings to NVS...");
    _prefs.putBool("ign_en",     igniterEnabled);
    _prefs.putBool("ntc_smk",    ntcSmokeEnabled);
    _prefs.putBool("ntc_prd",    ntcProductEnabled);
    _prefs.putUChar("hysteresis", hysteresis);
    _prefs.putUShort("telem_iv", telemetryInterval);
    _prefs.putUChar("brightness", displayBrightness);
    _prefs.putChar("utc_offset", utcOffset);
    _prefs.putString("web_pass",  webServerPassword);
    Serial.println("[SETTINGS] Settings saved.");
}

/**
 * Сброс настроек: очищает namespace "settings" (не трогает "device"),
 * форматирует LittleFS и перезагружает ESP32.
 */
void SettingsManager::reset() {
    Serial.println("[SETTINGS] Resetting settings...");

    // Очистить только namespace "settings", не трогая "device"
    _prefs.clear();

    // Форматировать файловую систему
    Serial.println("[SETTINGS] Formatting LittleFS...");
    LittleFS.format();

    // Перезагрузить устройство
    Serial.println("[SETTINGS] Restarting ESP...");
    ESP.restart();
}

// =====================================================
// Private Methods
// =====================================================

/**
 * Загрузка значений из NVS. При отсутствии ключа используются значения по умолчанию.
 */
void SettingsManager::_load() {
    igniterEnabled    = _prefs.getBool("ign_en",      IGNITER_ENABLED);
    ntcSmokeEnabled   = _prefs.getBool("ntc_smk",     true);
    ntcProductEnabled = _prefs.getBool("ntc_prd",     true);
    hysteresis        = _prefs.getUChar("hysteresis", DEFAULT_HYSTERESIS);
    telemetryInterval = _prefs.getUShort("telem_iv",  CLOUD_SEND_INTERVAL / 1000);
    displayBrightness = _prefs.getUChar("brightness", 100);
    utcOffset         = _prefs.getChar("utc_offset",  0);
    webServerPassword = _prefs.getString("web_pass",  "");

    Serial.printf("[SETTINGS] igniterEnabled=%d ntcSmoke=%d ntcProduct=%d\n",
                  igniterEnabled, ntcSmokeEnabled, ntcProductEnabled);
    Serial.printf("[SETTINGS] hysteresis=%u telemetryInterval=%u brightness=%u utcOffset=%d\n",
                  hysteresis, telemetryInterval, displayBrightness, utcOffset);
}
