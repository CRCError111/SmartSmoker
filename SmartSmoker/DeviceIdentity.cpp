/**
 * Device Identity Manager Implementation
 * 
 * Manages persistent Device ID storage in ESP32 NVS
 */

#include "DeviceIdentity.h"
#include "UUIDGenerator.h"

// =====================================================
// Private Methods
// =====================================================

/**
 * Load Device ID from NVS
 */
String DeviceIdentity::loadFromNVS() {
    preferences.begin("device", false);  // read-only
    
    String id = preferences.getString("device_id", "");
    
    preferences.end();
    
    if (id.isEmpty()) {
        Serial.println("[DEVICE] No Device ID found in NVS");
    } else {
        Serial.println("[DEVICE] Device ID loaded from NVS");
    }
    
    return id;
}

/**
 * Load Device ID from LittleFS
 */
String DeviceIdentity::loadFromLittleFS() {
    File file = LittleFS.open("/device_id.txt", "r");
    
    if (!file) {
        Serial.println("[DEVICE] No device_id.txt found in LittleFS");
        return "";
    }
    
    String id = file.readString();
    file.close();
    
    // Trim whitespace
    id.trim();
    
    if (id.isEmpty()) {
        Serial.println("[DEVICE] Empty device_id.txt in LittleFS");
    } else {
        Serial.println("[DEVICE] Device ID loaded from LittleFS");
    }
    
    return id;
}

/**
 * Save Device ID to NVS
 */
bool DeviceIdentity::saveToNVS(const String& id) {
    preferences.begin("device", false);  // read-write
    
    if (preferences.putString("device_id", id)) {
        Serial.println("[DEVICE] Device ID saved to NVS");
        preferences.end();
        return true;
    }
    
    Serial.println("[DEVICE] Failed to save Device ID to NVS");
    preferences.end();
    return false;
}

/**
 * Save Device ID to LittleFS
 */
bool DeviceIdentity::saveToLittleFS(const String& id) {
    File file = LittleFS.open("/device_id.txt", "w");
    
    if (!file) {
        Serial.println("[DEVICE] Failed to open device_id.txt for writing");
        return false;
    }
    
    file.println(id);
    file.close();
    
    Serial.println("[DEVICE] Device ID saved to LittleFS");
    return true;
}

/**
 * Validate UUID v4 format
 */
bool DeviceIdentity::isValidUUIDv4(const String& uuid) {
    // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
    // where x is hex digit, y is 8, 9, a, or b
    
    if (uuid.length() != 36) {
        return false;
    }
    
    // Check format
    if (uuid[8] != '-' || uuid[13] != '-' || uuid[18] != '-' || uuid[23] != '-') {
        return false;
    }
    
    // Check version (position 14 should be '4')
    if (uuid[14] != '4') {
        return false;
    }
    
    // Check variant (position 19 should be 8, 9, a, or b)
    char variant = uuid[19];
    if (variant != '8' && variant != '9' && variant != 'a' && variant != 'b') {
        return false;
    }
    
    // Check all other characters are hex digits
    for (int i = 0; i < 36; i++) {
        if (i == 8 || i == 13 || i == 18 || i == 23) {
            continue;  // Skip dashes
        }
        
        char c = uuid[i];
        if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F'))) {
            return false;
        }
    }
    
    return true;
}

// =====================================================
// Public Methods
// =====================================================

/**
 * Initialize Device Identity module
 */
bool DeviceIdentity::begin() {
    Serial.println("[DEVICE] Initializing DeviceIdentity...");
    
    // Step 1: Try to load from NVS
    deviceId = loadFromNVS();
    
    if (!deviceId.isEmpty() && isValidUUIDv4(deviceId)) {
        Serial.println("[DEVICE] Device ID valid from NVS");
        return true;
    }
    
    // Step 2: Try to load from LittleFS (migration)
    if (deviceId.isEmpty() || !isValidUUIDv4(deviceId)) {
        Serial.println("[DEVICE] Trying LittleFS fallback...");
        deviceId = loadFromLittleFS();
        
        if (!deviceId.isEmpty() && isValidUUIDv4(deviceId)) {
            Serial.println("[DEVICE] Device ID loaded from LittleFS, migrating to NVS...");
            
            if (saveToNVS(deviceId)) {
                Serial.println("[DEVICE] Migration successful");
            } else {
                Serial.println("[DEVICE] Migration failed, continuing with LittleFS");
            }
            
            return true;
        }
    }
    
    // Step 3: Generate new Device ID
    if (deviceId.isEmpty() || !isValidUUIDv4(deviceId)) {
        Serial.println("[DEVICE] Generating new Device ID...");
        deviceId = generateUUID();
        
        if (saveToNVS(deviceId)) {
            saveToLittleFS(deviceId);  // Save backup to LittleFS
        }
        
        Serial.printf("[DEVICE] New Device ID generated: %s\n", deviceId.c_str());
        return true;
    }
    
    // Step 4: Invalid Device ID - regenerate
    Serial.println("[DEVICE] Invalid Device ID, regenerating...");
    deviceId = generateUUID();
    
    if (saveToNVS(deviceId)) {
        saveToLittleFS(deviceId);
    }
    
    Serial.printf("[DEVICE] New Device ID generated: %s\n", deviceId.c_str());
    return true;
}

/**
 * Get current Device ID
 */
String DeviceIdentity::getDeviceId() const {
    return deviceId;
}

/**
 * Reset Device ID (factory reset)
 */
void DeviceIdentity::resetDeviceId() {
    Serial.println("[DEVICE] Resetting Device ID...");
    
    preferences.begin("device", false);
    preferences.remove("device_id");
    preferences.end();
    
    LittleFS.remove("/device_id.txt");
    
    deviceId = "";
    Serial.println("[DEVICE] Device ID reset");
}
