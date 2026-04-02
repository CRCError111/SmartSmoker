/**
 * Структуры программ копчения согласно ТЗ
 * 
 * @file ProgramStructures.h
 * @version 1.0
 */

#ifndef PROGRAM_STRUCTURES_H
#define PROGRAM_STRUCTURES_H

#include <Arduino.h>
#include <vector>
#include <ArduinoJson.h>

/**
 * Структура этапа программы копчения
 */
struct ProgramStep {
    String stepName = "Этап";                    // Название этапа
    float targetTemp = 30.0f;                    // Целевая температура (°C)
    int targetTempDevice = 0;                    // 0 - камера, 1 - продукт
    float targetHumidity = 70.0f;                // Целевая влажность в камере (%)
    int durationMinutes = 60;                    // Длительность этапа (минуты)
    float hysteresis = 2.0f;                     // Гистерезис для нагрева (°C)
    bool waitForTemp = true;                     // Ждать достижения температуры перед началом таймера
    bool useSmokeGenerator = true;               // Использовать дымогенератор
    bool smokeIgnitionRequired = true;           // Требовать подтверждения розжига (если useSmokeGenerator=true)
    int ventilationPercent = 100;                // Процент открытия заслонки вентиляции (0-100%)
    bool internalFanOn = false;                  // Вентилятор в камере
    bool injectionFanOn = false;                 // Вентилятор подачи воздуха
    int compressorPWM = -1;                      // ШИМ для компрессора (-1 = автоматический)
    
    /**
     * Конструктор по умолчанию
     */
    ProgramStep() = default;
    
    /**
     * Конструктор с параметрами
     */
    ProgramStep(const String& name, float temp, int duration) 
        : stepName(name), targetTemp(temp), durationMinutes(duration) {}
    
    /**
     * Валидация параметров этапа
     */
    bool isValid() const {
        return !stepName.isEmpty() &&
               targetTemp >= -50.0f && targetTemp <= 200.0f &&
               targetHumidity >= 0.0f && targetHumidity <= 100.0f &&
               durationMinutes > 0 && durationMinutes <= 1440 && // Максимум 24 часа
               hysteresis >= 1.0f && hysteresis <= 10.0f &&
               ventilationPercent >= 0 && ventilationPercent <= 100 &&
               compressorPWM >= -1 && compressorPWM <= 100 &&
               targetTempDevice >= 0 && targetTempDevice <= 1;
    }
    
    /**
     * Получение строкового описания этапа
     */
    String getDescription() const {
        String desc = stepName + ": ";
        desc += String(targetTemp, 1) + "°C";
        if (targetTempDevice == 1) desc += " (продукт)";
        else desc += " (камера)";
        desc += ", " + String(durationMinutes) + " мин";
        if (useSmokeGenerator) desc += ", дым";
        return desc;
    }
};

/**
 * Структура программы копчения
 */
struct SmokingProgram {
    String name;                                 // Название программы
    std::vector<ProgramStep> steps;              // Этапы программы
    bool isBuiltIn = false;                      // Встроенная программа
    String description = "";                     // Описание программы
    String category = "custom";                  // Категория (fish, meat, custom)
    String author = "";                          // Автор программы
    unsigned long createdAt = 0;                 // Время создания (timestamp)
    unsigned long lastUsed = 0;                  // Время последнего использования
    uint32_t usageCount = 0;                     // Количество использований
    int programId = 0;                           // ID программы (для совместимости с форматом сайта)
    bool isLocalProgram = false;                 // true = создана на контроллере, false = импортирована с сайта
    
    /**
     * Конструктор по умолчанию
     */
    SmokingProgram() = default;
    
    /**
     * Конструктор с параметрами
     */
    SmokingProgram(const String& programName, const String& desc = "") 
        : name(programName), description(desc), createdAt(millis()) {}
    
    /**
     * Добавление этапа в программу
     */
    void addStep(const ProgramStep& step) {
        if (steps.size() < 10) { // Максимум 10 этапов согласно ТЗ
            steps.push_back(step);
        }
    }
    
    /**
     * Получение общей длительности программы в минутах
     */
    int getTotalDuration() const {
        int total = 0;
        for (const auto& step : steps) {
            total += step.durationMinutes;
        }
        return total;
    }
    
    /**
     * Получение текущего этапа
     */
    const ProgramStep* getCurrentStep(size_t stepIndex) const {
        if (stepIndex < steps.size()) {
            return &steps[stepIndex];
        }
        return nullptr;
    }
    
    /**
     * Проверка завершения программы
     */
    bool isCompleted(size_t currentStepIndex) const {
        return currentStepIndex >= steps.size();
    }
    
    /**
     * Валидация программы
     */
    bool isValid() const {
        if (name.isEmpty() || steps.empty() || steps.size() > 10) {
            return false;
        }
        
        for (const auto& step : steps) {
            if (!step.isValid()) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Получение имени файла для сохранения
     */
    String getFileName() const {
        String fileName = name;
        fileName.replace(" ", "_");
        fileName.replace("/", "_");
        fileName.replace("\\", "_");
        fileName.toLowerCase();
        return "/programs/" + fileName + ".json";
    }
    
    /**
     * Получение краткого описания программы
     */
    String getSummary() const {
        String summary = name;
        if (!steps.empty()) {
            summary += " (" + String(steps.size()) + " этапов, ";
            summary += String(getTotalDuration()) + " мин)";
        }
        return summary;
    }
    
    /**
     * Обновление статистики использования
     */
    void updateUsageStats() {
        lastUsed = millis();
        usageCount++;
    }
};

#endif // PROGRAM_STRUCTURES_H