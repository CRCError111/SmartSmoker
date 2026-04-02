/**
 * Менеджер датчиков согласно ТЗ
 * 
 * @file SensorManager.h
 * @version 1.0
 */

#ifndef SENSOR_MANAGER_H
#define SENSOR_MANAGER_H

#include <Arduino.h>
#include <Adafruit_BME280.h>
#include <driver/adc.h>
#include "pins.h"
#include "constants.h"
#include "SystemState.h"
#include "SettingsManager.h"

/**
 * Класс для управления всеми датчиками системы
 */
class SensorManager {
private:
    Adafruit_BME280 bme280;                    // Датчик BME280
    bool bme280Available = false;              // Доступность BME280
    
    // Счетчики ошибок для каждого датчика
    uint8_t bme280ErrorCount = 0;
    uint8_t ntcSmokeErrorCount = 0;
    uint8_t ntcProductErrorCount = 0;
    
    // Последние валидные значения
    float lastValidTempChamber = NAN;
    float lastValidTempSmoke = NAN;
    float lastValidTempProduct = NAN;
    float lastValidHumidity = NAN;
    
    // Буферы для усреднения
    float tempChamberBuffer[SENSOR_SAMPLES];
    float tempSmokeBuffer[SENSOR_SAMPLES];
    float tempProductBuffer[SENSOR_SAMPLES];
    float humidityBuffer[SENSOR_SAMPLES];
    uint8_t bufferIndex = 0;
    bool bufferFilled = false;
    
public:
    /**
     * Инициализация всех датчиков
     */
    bool init() {
        // Инициализация I2C
        Wire.begin(I2C_SDA, I2C_SCL);
        Wire.setClock(100000); // 100 кГц для стабильности
        
        // Инициализация BME280
        if (bme280.begin(0x76, &Wire)) {
            bme280Available = true;
        } else if (bme280.begin(0x77, &Wire)) {
            bme280Available = true;
        } else {
            bme280Available = false;
            Serial.println("[ERROR] BME280 not found!");
        }
        
        if (bme280Available) {
            bme280.setSampling(Adafruit_BME280::MODE_FORCED,
                              Adafruit_BME280::SAMPLING_X1,
                              Adafruit_BME280::SAMPLING_X1,
                              Adafruit_BME280::SAMPLING_X1,
                              Adafruit_BME280::FILTER_OFF);
        }
        
        // Инициализация ADC для NTC датчиков
        adc1_config_width(ADC_WIDTH);
        adc1_config_channel_atten(ADC_CHANNEL_SMOKE, ADC_ATTENUATION);
        adc1_config_channel_atten(ADC_CHANNEL_PRODUCT, ADC_ATTENUATION);
        
        // Инициализация буферов
        clearBuffers();
        
        return bme280Available;
    }
    
    /**
     * Обновление всех датчиков
     */
    void updateSensors(SystemState& state) {
        unsigned long currentTime = millis();
        
        // Чтение BME280 (температура камеры и влажность)
        float tempChamber = readBME280Temperature();
        float humidity = readBME280Humidity();
        
        // Чтение NTC датчиков
        float tempSmoke = settingsManager.ntcSmokeEnabled ? readNTCTemperature(ADC_CHANNEL_SMOKE) : NAN;
        float tempProduct = settingsManager.ntcProductEnabled ? readNTCTemperature(ADC_CHANNEL_PRODUCT) : NAN;
        
        // Добавление в буферы для усреднения
        addToBuffer(tempChamber, humidity, tempSmoke, tempProduct);
        
        // Получение усредненных значений
        if (bufferFilled) {
            state.tempChamber = getAverageValue(tempChamberBuffer);
            state.humidity = getAverageValue(humidityBuffer);
            state.tempSmoke = getAverageValue(tempSmokeBuffer);
            state.tempProduct = getAverageValue(tempProductBuffer);
        } else {
            // Если буфер еще не заполнен, используем текущие значения
            state.tempChamber = tempChamber;
            state.humidity = humidity;
            state.tempSmoke = tempSmoke;
            state.tempProduct = tempProduct;
        }
        
        // Валидация и обработка ошибок
        validateSensorData(state);
        
        // Обновление времени последнего обновления
        state.lastSensorUpdate = currentTime;
        
        #if DEBUG_SENSORS
        printSensorDebug(state);
        #endif
    }
    
    /**
     * Проверка доступности датчиков
     */
    bool isBME280Available() const {
        return bme280Available;
    }

private:
    /**
     * Чтение температуры с BME280
     */
    float readBME280Temperature() {
        if (!bme280Available) {
            bme280ErrorCount++;
            return NAN;
        }
        
        // Принудительное измерение
        bme280.takeForcedMeasurement();
        delay(10); // Небольшая задержка для стабилизации
        
        float temp = bme280.readTemperature();
        
        // Валидация диапазона
        if (isnan(temp) || temp < BME280_MIN_TEMP || temp > BME280_MAX_TEMP) {
            bme280ErrorCount++;
            if (bme280ErrorCount >= MAX_SENSOR_ERRORS) {
                Serial.println("[ERROR] BME280 temperature sensor failure!");
            }
            return lastValidTempChamber;
        }
        
        bme280ErrorCount = 0; // Сброс счетчика при успешном чтении
        lastValidTempChamber = temp;
        return temp;
    }
    
    /**
     * Чтение влажности с BME280
     */
    float readBME280Humidity() {
        if (!bme280Available) {
            return NAN;
        }
        
        float hum = bme280.readHumidity();
        
        // Валидация диапазона
        if (isnan(hum) || hum < BME280_MIN_HUMIDITY || hum > BME280_MAX_HUMIDITY) {
            return lastValidHumidity;
        }
        
        lastValidHumidity = hum;
        return hum;
    }
    
    /**
     * Чтение температуры с NTC датчика
     */
    float readNTCTemperature(adc1_channel_t channel) {
        // Множественные измерения для усреднения
        uint32_t adcSum = 0;
        uint8_t validReadings = 0;
        
        for (int i = 0; i < SENSOR_SAMPLES; i++) {
            int adcValue = adc1_get_raw(channel);
            if (adcValue >= 0) {
                adcSum += adcValue;
                validReadings++;
            }
            delay(SENSOR_SAMPLE_DELAY);
        }
        
        if (validReadings == 0) {
            if (channel == ADC_CHANNEL_SMOKE) {
                ntcSmokeErrorCount++;
            } else {
                ntcProductErrorCount++;
            }
            return NAN;
        }
        
        // Усреднение
        float adcAverage = (float)adcSum / validReadings;
        
        // Преобразование ADC в напряжение (0-3.3V для 12-bit ADC)
        float voltage = (adcAverage / 4095.0f) * 3.3f;
        
        // Валидация напряжения
        if (voltage < NTC_MIN_VOLTAGE || voltage > NTC_MAX_VOLTAGE) {
            if (channel == ADC_CHANNEL_SMOKE) {
                ntcSmokeErrorCount++;
            } else {
                ntcProductErrorCount++;
            }
            return NAN;
        }
        
        // Преобразование напряжения в температуру (упрощенная формула)
        float temperature = ntcVoltageToTemperature(voltage);
        
        // Валидация диапазона температуры
        if (temperature < NTC_MIN_TEMP || temperature > NTC_MAX_TEMP) {
            if (channel == ADC_CHANNEL_SMOKE) {
                ntcSmokeErrorCount++;
            } else {
                ntcProductErrorCount++;
            }
            return NAN;
        }
        
        // Сброс счетчика ошибок при успешном чтении
        if (channel == ADC_CHANNEL_SMOKE) {
            ntcSmokeErrorCount = 0;
            lastValidTempSmoke = temperature;
        } else {
            ntcProductErrorCount = 0;
            lastValidTempProduct = temperature;
        }
        
        return temperature;
    }
    
    /**
     * Преобразование напряжения NTC в температуру (Уравнение Стейнхарта-Харта)
     * Параметры: NTC 10k при 25C, Beta = 3950
     * Схема: VCC(3.3V) -> R_pullup(10k) -> Vout (ADC) -> NTC -> GND
     */
    float ntcVoltageToTemperature(float voltage) {
        if (voltage <= 0.05f || voltage >= 3.25f) return NAN; // Защита от КЗ и обрыва
        
        // 1. Расчет сопротивления NTC (R = R_pullup * (Vout / (Vcc - Vout)))
        float r_ntc = 10000.0f * (voltage / (3.3f - voltage));
        
        // 2. Расчет температуры по модели Beta
        // 1/T = 1/T25 + (1/Beta) * ln(R/R25)
        const float T25 = 298.15f;    // 25°C в Кельвинах
        const float R25 = 10000.0f;   // Сопротивление при 25°C
        const float BETA = 3950.0f;   // Коэффициент Beta из ТЗ
        
        float steinhart;
        steinhart = r_ntc / R25;             // (R/R25)
        steinhart = log(steinhart);          // ln(R/R25)
        steinhart /= BETA;                   // 1/B * ln(R/R25)
        steinhart += 1.0f / T25;             // + (1/T25)
        steinhart = 1.0f / steinhart;        // 1 / (1/T)
        
        steinhart -= 273.15f;                 // Перевод в Цельсии
        
        return steinhart;
    }
    
    /**
     * Добавление значений в буферы для усреднения
     */
    void addToBuffer(float tempChamber, float humidity, float tempSmoke, float tempProduct) {
        tempChamberBuffer[bufferIndex] = tempChamber;
        humidityBuffer[bufferIndex] = humidity;
        tempSmokeBuffer[bufferIndex] = tempSmoke;
        tempProductBuffer[bufferIndex] = tempProduct;
        
        bufferIndex = (bufferIndex + 1) % SENSOR_SAMPLES;
        if (bufferIndex == 0) {
            bufferFilled = true;
        }
    }
    
    /**
     * Получение среднего значения из буфера
     */
    float getAverageValue(const float* buffer) const {
        float sum = 0.0f;
        uint8_t validCount = 0;
        
        uint8_t samples = bufferFilled ? SENSOR_SAMPLES : bufferIndex;
        
        for (uint8_t i = 0; i < samples; i++) {
            if (!isnan(buffer[i])) {
                sum += buffer[i];
                validCount++;
            }
        }
        
        return (validCount > 0) ? (sum / validCount) : NAN;
    }
    
    /**
     * Очистка буферов
     */
    void clearBuffers() {
        for (uint8_t i = 0; i < SENSOR_SAMPLES; i++) {
            tempChamberBuffer[i] = NAN;
            humidityBuffer[i] = NAN;
            tempSmokeBuffer[i] = NAN;
            tempProductBuffer[i] = NAN;
        }
        bufferIndex = 0;
        bufferFilled = false;
    }
    
    /**
     * Валидация данных датчиков и обновление флагов ошибок
     */
    void validateSensorData(SystemState& state) {
        // Подсчет общего количества ошибок
        uint8_t totalErrors = 0;
        
        if (bme280ErrorCount >= MAX_SENSOR_ERRORS) totalErrors++;
        if (ntcSmokeErrorCount >= MAX_SENSOR_ERRORS) totalErrors++;
        if (ntcProductErrorCount >= MAX_SENSOR_ERRORS) totalErrors++;
        
        // Обновление флага ошибки датчиков
        bool previousSensorError = state.sensorError;
        state.sensorError = (totalErrors >= 2); // Ошибка если 2+ датчика не работают
        
        // Логируем переходы, но не чаще раза в 30 секунд (защита от спама)
        static unsigned long lastSensorLog = 0;
        unsigned long now = millis();
        if (state.sensorError && !previousSensorError) {
            state.sensorErrorCount++;
            if (now - lastSensorLog > 30000) {
                Serial.println("[ERROR] Multiple sensor failures detected!");
                lastSensorLog = now;
            }
        } else if (!state.sensorError && previousSensorError) {
            if (now - lastSensorLog > 30000) {
                Serial.println("[WARN] Sensor recovery: sensors working normally");
                lastSensorLog = now;
            }
        }
    }
    
    /**
     * Отладочный вывод данных датчиков
     */
    void printSensorDebug(const SystemState& state) const {
        Serial.printf("Sensors - Chamber: %.1f°C, Smoke: %.1f°C, Product: %.1f°C, Humidity: %.1f%%\n",
                     state.tempChamber, state.tempSmoke, state.tempProduct, state.humidity);
        Serial.printf("Errors - BME280: %d, Smoke NTC: %d, Product NTC: %d\n",
                     bme280ErrorCount, ntcSmokeErrorCount, ntcProductErrorCount);
    }
};

#endif // SENSOR_MANAGER_H