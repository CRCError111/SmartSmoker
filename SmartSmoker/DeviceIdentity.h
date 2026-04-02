/**
 * Device Identity Manager
 * 
 * Manages persistent Device ID storage in ESP32 NVS
 * Device ID is generated once on first boot and persists through reflashes
 * 
 * @file DeviceIdentity.h
 * @version 1.0
 */

#ifndef DEVICE_IDENTITY_H
#define DEVICE_IDENTITY_H

#include <Arduino.h>
#include <Preferences.h>
#include <LittleFS.h>
#include "constants.h"

/**
 * Class to manage Device ID persistence
 * 
 * Device ID is stored in:
 * 1. NVS (primary) - persists through reflashes
 * 2. LittleFS (fallback) - backup if NVS fails
 * 
 * If both fail, generates new Device ID
 */
class DeviceIdentity {
private:
    Preferences preferences;
    String deviceId;
    
    /**
     * Load Device ID from NVS
     * @return Device ID string, empty if not found
     */
    String loadFromNVS();
    
    /**
     * Load Device ID from LittleFS
     * @return Device ID string, empty if not found
     */
    String loadFromLittleFS();
    
    /**
     * Save Device ID to NVS
     * @param id Device ID to save
     * @return true if successful
     */
    bool saveToNVS(const String& id);
    
    /**
     * Save Device ID to LittleFS
     * @param id Device ID to save
     * @return true if successful
     */
    bool saveToLittleFS(const String& id);
    
    /**
     * Validate UUID v4 format
     * @param uuid UUID to validate
     * @return true if valid
     */
    bool isValidUUIDv4(const String& uuid);

public:
    /**
     * Initialize Device Identity module
     * 
     * 1. Load Device ID from NVS
     * 2. If not found, load from LittleFS
     * 3. If still not found, generate new UUID
     * 4. Save to NVS (if migrated from LittleFS)
     * 
     * @return true if successful
     */
    bool begin();
    
    /**
     * Get current Device ID
     * @return Device ID string
     */
    String getDeviceId() const;
    
    /**
     * Reset Device ID (factory reset)
     * Clears NVS and LittleFS copies
     */
    void resetDeviceId();
};

#endif // DEVICE_IDENTITY_H