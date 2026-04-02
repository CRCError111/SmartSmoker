#include "UpdateLogger.h"

UpdateLogger::UpdateLogger() {
    // Загружаем существующие записи при создании
    load();
}

void UpdateLogger::log(EventType type, const String& message, 
                       const String& version, bool success, int errorCode) {
    LogEntry entry;
    entry.timestamp = millis();
    entry.type = type;
    entry.message = message;
    entry.version = version;
    entry.success = success;
    entry.errorCode = errorCode;
    
    entries.push_back(entry);
    pruneOldEntries();
    
    // Логируем в Serial для отладки
    Serial.printf("[UPDATE] %s: %s", 
                  eventTypeToString(type).c_str(), 
                  message.c_str());
    if (!version.isEmpty()) {
        Serial.printf(" (v%s)", version.c_str());
    }
    Serial.println();
    
    // Сохраняем в файл
    save();
}

std::vector<UpdateLogger::LogEntry> UpdateLogger::getEntries(size_t maxCount) const {
    if (entries.size() <= maxCount) {
        return entries;
    }
    
    // Возвращаем последние maxCount записей
    return std::vector<LogEntry>(
        entries.end() - maxCount,
        entries.end()
    );
}

UpdateLogger::LogEntry UpdateLogger::getLastEntry() const {
    if (entries.empty()) {
        return LogEntry{0, EventType::CHECK_START, "", "", false, 0};
    }
    return entries.back();
}

void UpdateLogger::pruneOldEntries() {
    if (entries.size() > MAX_ENTRIES) {
        entries.erase(
            entries.begin(), 
            entries.begin() + (entries.size() - MAX_ENTRIES)
        );
    }
}

bool UpdateLogger::save() {
    File file = LittleFS.open(LOG_FILE, "w");
    if (!file) {
        Serial.println("[UPDATE] Failed to open log file for writing");
        return false;
    }
    
    JsonDocument doc;
    JsonArray arr = doc.to<JsonArray>();
    
    for (const auto& entry : entries) {
        JsonObject obj = arr.add<JsonObject>();
        obj["timestamp"] = entry.timestamp;
        obj["type"] = static_cast<int>(entry.type);
        obj["message"] = entry.message;
        obj["version"] = entry.version;
        obj["success"] = entry.success;
        obj["error_code"] = entry.errorCode;
    }
    
    if (serializeJson(doc, file) == 0) {
        Serial.println("[UPDATE] Failed to serialize log");
        file.close();
        return false;
    }
    
    file.close();
    return true;
}

bool UpdateLogger::load() {
    if (!LittleFS.exists(LOG_FILE)) {
        Serial.println("[UPDATE] Log file does not exist, starting fresh");
        return true;
    }
    
    File file = LittleFS.open(LOG_FILE, "r");
    if (!file) {
        Serial.println("[UPDATE] Failed to open log file for reading");
        return false;
    }
    
    JsonDocument doc;
    DeserializationError error = deserializeJson(doc, file);
    file.close();
    
    if (error) {
        Serial.printf("[UPDATE] Failed to parse log file: %s\n", error.c_str());
        return false;
    }
    
    entries.clear();
    
    JsonArray arr = doc.as<JsonArray>();
    for (JsonObject obj : arr) {
        LogEntry entry;
        entry.timestamp = obj["timestamp"] | 0;
        entry.type = static_cast<EventType>(obj["type"] | 0);
        entry.message = obj["message"] | "";
        entry.version = obj["version"] | "";
        entry.success = obj["success"] | false;
        entry.errorCode = obj["error_code"] | 0;
        
        entries.push_back(entry);
    }
    
    Serial.printf("[UPDATE] Loaded %d log entries\n", entries.size());
    return true;
}

String UpdateLogger::eventTypeToString(EventType type) const {
    switch (type) {
        case EventType::CHECK_START: return "Check Started";
        case EventType::CHECK_SUCCESS: return "Check Success";
        case EventType::CHECK_FAILED: return "Check Failed";
        case EventType::DOWNLOAD_START: return "Download Started";
        case EventType::DOWNLOAD_PROGRESS: return "Download Progress";
        case EventType::DOWNLOAD_SUCCESS: return "Download Success";
        case EventType::DOWNLOAD_FAILED: return "Download Failed";
        case EventType::VERIFY_START: return "Verification Started";
        case EventType::VERIFY_SUCCESS: return "Verification Success";
        case EventType::VERIFY_FAILED: return "Verification Failed";
        case EventType::INSTALL_START: return "Installation Started";
        case EventType::INSTALL_PROGRESS: return "Installation Progress";
        case EventType::INSTALL_SUCCESS: return "Installation Success";
        case EventType::INSTALL_FAILED: return "Installation Failed";
        case EventType::ROLLBACK: return "Rollback";
        case EventType::CONFIG_CHANGED: return "Config Changed";
        default: return "Unknown";
    }
}

void UpdateLogger::clear() {
    entries.clear();
    save();
    Serial.println("[UPDATE] Log cleared");
}
