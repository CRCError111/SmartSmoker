/**
 * Продвинутая калибровка датчиков NTC
 * 
 * @file NTCCalibration.h
 * @version 1.0
 */

#ifndef NTC_CALIBRATION_H
#define NTC_CALIBRATION_H

#include <Arduino.h>
#include <vector>
#include <cmath>

/**
 * Структура для хранения калибровочных коэффициентов Стейнхарта-Харта
 */
struct SteinhartHartCoefficients {
    float A = 0.001129148f;    // Коэффициент A
    float B = 0.000234125f;    // Коэффициент B
    float C = 0.0000000876741f; // Коэффициент C
    
    // Коэффициенты для NTC 10k при 25°C (стандартные)
    static SteinhartHartCoefficients defaultNTC10K() {
        return {0.001129148f, 0.000234125f, 0.0000000876741f};
    }
};

/**
 * Структура для калибровочной точки
 */
struct CalibrationPoint {
    float ntc_resistance;      // Сопротивление NTC (Ом)
    float reference_temp;      // Эталонная температура (°C)
    unsigned long timestamp;   // Время измерения
    
    CalibrationPoint(float r, float t) 
        : ntc_resistance(r), reference_temp(t), timestamp(millis()) {}
};

/**
 * Структура для калибровки саморазогрева
 */
struct SelfHeatingCalibration {
    float thermal_resistance = 0.5f;    // Термическое сопротивление (°C/Вт)
    float current = 0.0001f;            // Ток через NTC (А)
    float voltage = 3.3f;               // Напряжение питания (В)
    
    // Расчет мощности саморазогрева
    float calculatePower(float resistance) const {
        if (resistance <= 0) return 0;
        float power = (voltage * voltage) / resistance;
        return power * 0.001f; // В мВт
    }
    
    // Расчет температуры саморазогрева
    float calculateTemperatureRise(float resistance) const {
        float power = calculatePower(resistance);
        return power * thermal_resistance;
    }
};

/**
 * Класс для продвинутой калибровки NTC датчиков
 */
class NTCCalibration {
private:
    SteinhartHartCoefficients coefficients;
    SelfHeatingCalibration selfHeating;
    std::vector<CalibrationPoint> calibrationPoints;
    
    // Калибровочные смещения
    float temperature_offset = 0.0f;
    float gain_correction = 1.0f;
    
    // Статистика калибровки
    unsigned long lastCalibrationTime = 0;
    uint8_t calibrationCount = 0;
    bool isCalibrated = false;
    
public:
    /**
     * Инициализация с коэффициентами по умолчанию
     */
    NTCCalibration() {
        coefficients = SteinhartHartCoefficients::defaultNTC10K();
    }
    
    /**
     * Инициализация с пользовательскими коэффициентами
     */
    NTCCalibration(float A, float B, float C) {
        coefficients.A = A;
        coefficients.B = B;
        coefficients.C = C;
    }
    
    /**
     * Полная формула Стейнхарта-Харта
     */
    float steinhartHartTemperature(float resistance) const {
        if (resistance <= 0) return NAN;
        
        float logR = log(resistance);
        float invT = coefficients.A + 
                    coefficients.B * logR + 
                    coefficients.C * pow(logR, 3);
        
        if (invT <= 0) return NAN;
        
        float tempK = 1.0f / invT;
        return tempK - 273.15f; // Конвертация в °C
    }
    
    /**
     * Добавление калибровочной точки
     */
    void addCalibrationPoint(float ntc_resistance, float reference_temp) {
        calibrationPoints.push_back(CalibrationPoint(ntc_resistance, reference_temp));
        calibrationCount++;
        lastCalibrationTime = millis();
        
        // Пересчет коэффициентов при наличии 3+ точек
        if (calibrationPoints.size() >= 3) {
            recalculateCoefficients();
            isCalibrated = true;
        }
    }
    
    /**
     * Автоматическая калибровка по эталонной температуре
     */
    bool autoCalibrate(float ntc_resistance, float reference_temp) {
        // Проверка валидности данных
        if (isnan(ntc_resistance) || isnan(reference_temp) || 
            ntc_resistance <= 0 || reference_temp < -50 || reference_temp > 200) {
            return false;
        }
        
        // Добавление калибровочной точки
        addCalibrationPoint(ntc_resistance, reference_temp);
        
        // Если есть достаточно точек, рассчитываем смещение
        if (calibrationPoints.size() >= 2) {
            calculateOffsetAndGain();
            return true;
        }
        
        return false;
    }
    
    /**
     * Получение калиброванной температуры
     */
    float getCalibratedTemperature(float resistance) const {
        if (!isCalibrated || resistance <= 0) {
            return steinhartHartTemperature(resistance);
        }
        
        // Базовая температура по Стейнхарту-Харту
        float base_temp = steinhartHartTemperature(resistance);
        
        if (isnan(base_temp)) return NAN;
        
        // Применение калибровочных поправок
        float calibrated_temp = (base_temp + temperature_offset) * gain_correction;
        
        // Компенсация саморазогрева
        float self_heating = selfHeating.calculateTemperatureRise(resistance);
        calibrated_temp -= self_heating;
        
        return calibrated_temp;
    }
    
    /**
     * Калибровка по BME280 (автоматическая)
     */
    bool calibrateAgainstBME280(float ntc_resistance, float bme280_temp) {
        return autoCalibrate(ntc_resistance, bme280_temp);
    }
    
    /**
     * Многоточечная калибровка
     */
    bool multiPointCalibration(const std::vector<std::pair<float, float>>& points) {
        if (points.size() < 3) return false;
        
        calibrationPoints.clear();
        for (const auto& point : points) {
            addCalibrationPoint(point.first, point.second);
        }
        
        return isCalibrated;
    }
    
    /**
     * Сброс калибровки
     */
    void resetCalibration() {
        calibrationPoints.clear();
        coefficients = SteinhartHartCoefficients::defaultNTC10K();
        temperature_offset = 0.0f;
        gain_correction = 1.0f;
        isCalibrated = false;
        calibrationCount = 0;
    }
    
    /**
     * Получение статуса калибровки
     */
    bool isCalibrationValid() const {
        return isCalibrated && calibrationCount >= 3;
    }
    
    /**
     * Получение калибровочных коэффициентов
     */
    SteinhartHartCoefficients getCoefficients() const {
        return coefficients;
    }
    
    /**
     * Установка коэффициентов саморазогрева
     */
    void setSelfHeatingParams(float thermal_resistance, float current, float voltage = 3.3f) {
        selfHeating.thermal_resistance = thermal_resistance;
        selfHeating.current = current;
        selfHeating.voltage = voltage;
    }
    
    /**
     * Сохранение калибровки в JSON
     */
    String toJson() const {
        String json = "{";
        json += "\"calibrated\":" + String(isCalibrated ? "true" : "false") + ",";
        json += "\"points\":" + String(calibrationPoints.size()) + ",";
        json += "\"A\":" + String(coefficients.A, 10) + ",";
        json += "\"B\":" + String(coefficients.B, 10) + ",";
        json += "\"C\":" + String(coefficients.C, 10) + ",";
        json += "\"offset\":" + String(temperature_offset, 3) + ",";
        json += "\"gain\":" + String(gain_correction, 3);
        json += "}";
        return json;
    }
    
    /**
     * Загрузка калибровки из JSON
     */
    bool fromJson(const String& json) {
        // Простая реализация парсинга JSON
        // В реальном проекте нужно использовать ArduinoJson
        int start = json.indexOf("\"A\":");
        if (start == -1) return false;
        
        // Парсинг коэффициентов (упрощенно)
        // В реальном проекте нужен полноценный парсер JSON
        return true;
    }
    
private:
    /**
     * Пересчет коэффициентов Стейнхарта-Харта
     */
    void recalculateCoefficients() {
        if (calibrationPoints.size() < 3) return;
        
        // Упрощенный метод наименьших квадратов для 3 точек
        // В реальном проекте можно использовать более сложные алгоритмы
        
        // Берем 3 последние точки
        size_t n = calibrationPoints.size();
        const auto& p1 = calibrationPoints[n-3];
        const auto& p2 = calibrationPoints[n-2];
        const auto& p3 = calibrationPoints[n-1];
        
        // Преобразование температур в Кельвины
        float T1 = p1.reference_temp + 273.15f;
        float T2 = p2.reference_temp + 273.15f;
        float T3 = p3.reference_temp + 273.15f;
        
        float L1 = log(p1.ntc_resistance);
        float L2 = log(p2.ntc_resistance);
        float L3 = log(p3.ntc_resistance);
        
        // Решение системы уравнений
        float Y1 = 1.0f / T1;
        float Y2 = 1.0f / T2;
        float Y3 = 1.0f / T3;
        
        float gamma2 = (Y2 - Y1) / (L2 - L1);
        float gamma3 = (Y3 - Y1) / (L3 - L1);
        
        coefficients.C = (gamma3 - gamma2) / (L3 - L2) / 
                        (L1 + L2 + L3);
        coefficients.B = gamma2 - coefficients.C * (L1*L1 + L1*L2 + L2*L2);
        coefficients.A = Y1 - (coefficients.B + L1*L1*coefficients.C) * L1;
    }
    
    /**
     * Расчет смещения и усиления
     */
    void calculateOffsetAndGain() {
        if (calibrationPoints.size() < 2) return;
        
        float sum_temp_diff = 0;
        float sum_ntc_temp = 0;
        float sum_ref_temp = 0;
        
        for (const auto& point : calibrationPoints) {
            float ntc_temp = steinhartHartTemperature(point.ntc_resistance);
            if (!isnan(ntc_temp)) {
                sum_temp_diff += (point.reference_temp - ntc_temp);
                sum_ntc_temp += ntc_temp;
                sum_ref_temp += point.reference_temp;
            }
        }
        
        if (calibrationPoints.size() > 0) {
            temperature_offset = sum_temp_diff / calibrationPoints.size();
            
            if (sum_ntc_temp > 0) {
                gain_correction = sum_ref_temp / sum_ntc_temp;
            }
        }
    }
};

#endif // NTC_CALIBRATION_H