#ifndef UPDATE_LOGGER_H
#define UPDATE_LOGGER_H

#include <Arduino.h>
#include <vector>
#include <LittleFS.h>
#include <ArduinoJson.h>

/**
 * UpdateLogger - Система логирования обновлений прошивки
 * 
 * Отслеживает все события, связанные с автоматическими обновлениями:
 * - Проверки обновлений
 * - Загрузки прошивок
 * - Верификацию файлов
 * - Установку обновлений
 * - Откаты (rollback)
 * 
 * Хранит до 50 последних записей в файле /update_log.json
 */
class UpdateLogger {
public:
    enum class EventType {
        CHECK_START,
        CHECK_SUCCESS,
        CHECK_FAILED,
        DOWNLOAD_START,
        DOWNLOAD_PROGRESS,
        DOWNLOAD_SUCCESS,
        DOWNLOAD_FAILED,
        VERIFY_START,
        VERIFY_SUCCESS,
        VERIFY_FAILED,
        INSTALL_START,
        INSTALL_PROGRESS,
        INSTALL_SUCCESS,
        INSTALL_FAILED,
        ROLLBACK,
        CONFIG_CHANGED
    };
    
    struct LogEntry {
        unsigned long timestamp;  // millis() since boot
        EventType type;
        String message;
        String version;
        bool success;
        int errorCode;
    };
    
    // Конструктор
    UpdateLogger();
    
    // Логирование
    void log(EventType type, const String& message, 
             const String& version = "", bool success = true, int errorCode = 0);
    
    // Получение записей
    std::vector<LogEntry> getEntries(size_t maxCount = 50) const;
    LogEntry getLastEntry() const;
    size_t getEntryCount() const { return entries.size(); }
    
    // Сохранение/загрузка
    bool save();
    bool load();
    
    // Утилиты
    String eventTypeToString(EventType type) const;
    void clear();
    
private:
    static constexpr size_t MAX_ENTRIES = 50;
    static constexpr const char* LOG_FILE = "/update_log.json";
    
    std::vector<LogEntry> entries;
    
    void pruneOldEntries();
};

#endif // UPDATE_LOGGER_H
