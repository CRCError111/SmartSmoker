/**
 * Хранение и загрузка калибровочных данных
 * 
 * @file SensorCalibrationStorage.h
 * @version 1.0
 */

#ifndef SENSOR_CALIBRATION_STORAGE_H
#define SENSOR_CALIBRATION_STORAGE_H

#include <Arduino.h>
#include <LittleFS.h>
#include <ArduinoJson.h>
#include "NTCCalibration.h"

/**
 * Структура для хранения калибровочных данных в EEPROM
 */
struct CalibrationStorage {
    // Коэффициенты Стейнхарта-Харта для дыма
    float smoke_A;
    float smoke_B;
    float smoke_C;
    float smoke_offset;
    float smoke_gain;
    
    // Коэффициенты Стейнхарта-Харта для продукта
    float product_A;
    float product_B;
    float product_C;
    float product_offset;
    float product_gain;
    
    // Смещения BME280
    float bme280_temp_offset;
    float bme280_humidity_offset;
    
    // Флаги и метаданные
    bool smoke_calibrated;
    bool product_calibrated;
    bool bme280_calibrated;
    uint16_t calibration_count;
    unsigned long last_calibration_time;
    uint32_t crc32; // Контрольная сумма
    
    /**
     * Расчет контрольной суммы
     */
    uint32_t calculateCRC() const {
        // Простая реализация CRC32
        uint32_t crc = 0xFFFFFFFF;
        const uint8_t* data = (const uint8_t*)this;
        size_t data_size = sizeof(CalibrationStorage) - sizeof(crc32);
        
        for (size_t i = 0; i < data_size; i++) {
            crc ^= data[i];
            for (int j = 0; j < 8; j++) {
                if (crc & 1) {
                    crc = (crc >> 1) ^ 0xEDB88320;
                } else {
                    crc >>= 1;
                }
            }
        }
        
        return ~crc;
    }
    
    /**
     * Проверка валидности данных
     */
    bool isValid() const {
        return crc32 == calculateCRC();
    }
    
    /**
     * Обновление контрольной суммы
     */
    void updateCRC() {
        crc32 = calculateCRC();
    }
    
    /**
     * Сброс к значениям по умолчанию
     */
    void reset() {
        smoke_A = 0.001129148f;
        smoke_B = 0.000234125f;
        smoke_C = 0.0000000876741f;
        smoke_offset = 0.0f;
        smoke_gain = 1.0f;
        
        product_A = 0.001129148f;
        product_B = 0.000234125f;
        product_C = 0.0000000876741f;
        product_offset = 0.0f;
        product_gain = 1.0f;
        
        bme280_temp_offset = 0.0f;
        bme280_humidity_offset = 0.0f;
        
        smoke_calibrated = false;
        product_calibrated = false;
        bme280_calibrated = false;
        calibration_count = 0;
        last_calibration_time = 0;
        
        updateCRC();
    }
};

/**
 * Класс для работы с калибровочными данными
 */
class SensorCalibrationStorage {
private:
    static constexpr const char* CALIBRATION_FILE = "/calibration.dat";
    static constexpr size_t EEPROM_ADDRESS = 0;
    
    CalibrationStorage storage;
    bool storageLoaded = false;
    
public:
    /**
     * Конструктор
     */
    SensorCalibrationStorage() {
        storage.reset();
    }
    
    /**
     * Загрузка калибровочных данных
     */
    bool load() {
        // Сначала пробуем загрузить из файла
        if (loadFromFile()) {
            storageLoaded = true;
            return true;
        }
        
        // Если файла нет, пробуем загрузить из EEPROM
        if (loadFromEEPROM()) {
            storageLoaded = true;
            return true;
        }
        
        // Если ничего не загрузилось, используем значения по умолчанию
        storage.reset();
        storageLoaded = true;
        return false;
    }
    
    /**
     * Сохранение калибровочных данных
     */
    bool save() {
        storage.updateCRC();
        
        // Сохраняем в файл
        if (saveToFile()) {
            // Также сохраняем в EEPROM для резервирования
            saveToEEPROM();
            return true;
        }
        
        return false;
    }
    
    /**
     * Обновление калибровки NTC датчика
     */
    void updateNTCCalibration(const NTCCalibration& ntcCal, bool isSmoke) {
        auto coeffs = ntcCal.getCoefficients();
        
        if (isSmoke) {
            storage.smoke_A = coeffs.A;
            storage.smoke_B = coeffs.B;
            storage.smoke_C = coeffs.C;
            // Здесь нужно получить offset и gain из NTCCalibration
            // В текущей реализации NTCCalibration не предоставляет эти методы
            storage.smoke_calibrated = ntcCal.isCalibrationValid();
        } else {
            storage.product_A = coeffs.A;
            storage.product_B = coeffs.B;
            storage.product_C = coeffs.C;
            storage.product_calibrated = ntcCal.isCalibrationValid();
        }
        
        storage.calibration_count++;
        storage.last_calibration_time = millis();
    }
    
    /**
     * Обновление калибровки BME280
     */
    void updateBME280Calibration(float temp_offset, float humidity_offset) {
        storage.bme280_temp_offset = temp_offset;
        storage.bme280_humidity_offset = humidity_offset;
        storage.bme280_calibrated = true;
    }
    
    /**
     * Применение калибровки к NTCCalibration объекту
     */
    void applyToNTCCalibration(NTCCalibration& ntcCal, bool isSmoke) const {
        if (isSmoke && storage.smoke_calibrated) {
            // Здесь нужно установить коэффициенты в NTCCalibration
            // В текущей реализации NTCCalibration не имеет метода setCoefficients
        } else if (!isSmoke && storage.product_calibrated) {
            // Аналогично для продукта
        }
    }
    
    /**
     * Получение калибровочных данных BME280
     */
    void getBME280Calibration(float& temp_offset, float& humidity_offset) const {
        temp_offset = storage.bme280_temp_offset;
        humidity_offset = storage.bme280_humidity_offset;
    }
    
    /**
     * Получение статуса калибровки
     */
    bool isSmokeCalibrated() const { return storage.smoke_calibrated; }
    bool isProductCalibrated() const { return storage.product_calibrated; }
    bool isBME280Calibrated() const { return storage.bme280_calibrated; }
    
    /**
     * Получение количества калибровок
     */
    uint16_t getCalibrationCount() const { return storage.calibration_count; }
    
    /**
     * Получение времени последней калибровки
     */
    unsigned long getLastCalibrationTime() const { return storage.last_calibration_time; }
    
    /**
     * Сброс калибровки
     */
    void reset() {
        storage.reset();
        save();
    }
    
    /**
     * Экспорт калибровки в JSON
     */
    String toJson() const {
        JsonDocument doc;
        
        doc["smoke_calibrated"] = storage.smoke_calibrated;
        doc["product_calibrated"] = storage.product_calibrated;
        doc["bme280_calibrated"] = storage.bme280_calibrated;
        doc["calibration_count"] = storage.calibration_count;
        doc["last_calibration_time"] = storage.last_calibration_time;
        
        JsonObject smoke = doc["smoke"].to<JsonObject>();
        smoke["A"] = storage.smoke_A;
        smoke["B"] = storage.smoke_B;
        smoke["C"] = storage.smoke_C;
        smoke["offset"] = storage.smoke_offset;
        smoke["gain"] = storage.smoke_gain;
        
        JsonObject product = doc["product"].to<JsonObject>();
        product["A"] = storage.product_A;
        product["B"] = storage.product_B;
        product["C"] = storage.product_C;
        product["offset"] = storage.product_offset;
        product["gain"] = storage.product_gain;
        
        JsonObject bme280 = doc["bme280"].to<JsonObject>();
        bme280["temp_offset"] = storage.bme280_temp_offset;
        bme280["humidity_offset"] = storage.bme280_humidity_offset;
        
        doc["crc32"] = storage.crc32;
        doc["valid"] = storage.isValid();
        
        String output;
        serializeJson(doc, output);
        return output;
    }
    
    /**
     * Импорт калибровки из JSON
     */
    bool fromJson(const String& json) {
        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, json);
        
        if (error) {
            Serial.printf("Failed to parse calibration JSON: %s\n", error.c_str());
            return false;
        }
        
        storage.smoke_calibrated = doc["smoke_calibrated"] | false;
        storage.product_calibrated = doc["product_calibrated"] | false;
        storage.bme280_calibrated = doc["bme280_calibrated"] | false;
        storage.calibration_count = doc["calibration_count"] | 0;
        storage.last_calibration_time = doc["last_calibration_time"] | 0;
        
        if (doc["smoke"].is<JsonObject>()) {
            JsonObject smoke = doc["smoke"];
            storage.smoke_A = smoke["A"] | 0.001129148f;
            storage.smoke_B = smoke["B"] | 0.000234125f;
            storage.smoke_C = smoke["C"] | 0.0000000876741f;
            storage.smoke_offset = smoke["offset"] | 0.0f;
            storage.smoke_gain = smoke["gain"] | 1.0f;
        }
        
        if (doc["product"].is<JsonObject>()) {
            JsonObject product = doc["product"];
            storage.product_A = product["A"] | 0.001129148f;
            storage.product_B = product["B"] | 0.000234125f;
            storage.product_C = product["C"] | 0.0000000876741f;
            storage.product_offset = product["offset"] | 0.0f;
            storage.product_gain = product["gain"] | 1.0f;
        }
        
        if (doc["bme280"].is<JsonObject>()) {
            JsonObject bme280 = doc["bme280"];
            storage.bme280_temp_offset = bme280["temp_offset"] | 0.0f;
            storage.bme280_humidity_offset = bme280["humidity_offset"] | 0.0f;
        }
        
        storage.updateCRC();
        return save();
    }
    
private:
    /**
     * Загрузка из файла
     */
    bool loadFromFile() {
        if (!LittleFS.exists(CALIBRATION_FILE)) {
            return false;
        }
        
        File file = LittleFS.open(CALIBRATION_FILE, "r");
        if (!file) {
            return false;
        }
        
        size_t bytesRead = file.read((uint8_t*)&storage, sizeof(storage));
        file.close();
        
        if (bytesRead != sizeof(storage)) {
            return false;
        }
        
        if (!storage.isValid()) {
            Serial.println("Calibration data corrupted, using defaults");
            return false;
        }
        
        Serial.println("Calibration loaded from file");
        return true;
    }
    
    /**
     * Сохранение в файл
     */
    bool saveToFile() {
        File file = LittleFS.open(CALIBRATION_FILE, "w");
        if (!file) {
            Serial.println("Failed to open calibration file for writing");
            return false;
        }
        
        size_t bytesWritten = file.write((uint8_t*)&storage, sizeof(storage));
        file.close();
        
        if (bytesWritten != sizeof(storage)) {
            Serial.println("Failed to write calibration file");
            return false;
        }
        
        Serial.println("Calibration saved to file");
        return true;
    }
    
    /**
     * Загрузка из EEPROM
     */
    bool loadFromEEPROM() {
        // В ESP32 используем Preferences вместо EEPROM
        // Это упрощенная реализация
        return false;
    }
    
    /**
     * Сохранение в EEPROM
     */
    bool saveToEEPROM() {
        // В ESP32 используем Preferences вместо EEPROM
        // Это упрощенная реализация
        return false;
    }
};

#endif // SENSOR_CALIBRATION_STORAGE_H