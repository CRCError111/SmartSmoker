/**
 * UUID v4 Generator for ESP32
 * 
 * @file UUIDGenerator.h
 * @version 1.0
 * 
 * Generates RFC 4122 compliant UUID v4 identifiers using ESP32's hardware RNG.
 * Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 * - Version nibble (4) is set in the 3rd group
 * - Variant bits (10xx) are set in the 4th group, making first hex digit 8/9/a/b
 */

#ifndef UUID_GENERATOR_H
#define UUID_GENERATOR_H

#include <Arduino.h>
#include <esp_random.h>

/**
 * Generates a UUID v4 string using ESP32's hardware random number generator
 * 
 * @return String containing UUID v4 in format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
 * 
 * Example output: "550e8400-e29b-41d4-a716-446655440000"
 * 
 * UUID v4 Specification:
 * - 8-4-4-4-12 hexadecimal pattern (36 characters total including dashes)
 * - Version field (bits 12-15 of time_hi_and_version) set to 0100 (4)
 * - Variant field (bits 6-7 of clock_seq_hi_and_reserved) set to 10xx
 * 
 * Implementation details:
 * - Uses esp_random() for cryptographically secure random numbers
 * - Version nibble is hardcoded to '4' in the 3rd group
 * - Variant bits ensure first hex digit of 4th group is 8, 9, a, or b
 */
inline String generateUUID() {
    char uuid[37];  // 36 characters + null terminator
    
    // Generate random values for each section
    uint32_t random1 = esp_random();  // First 8 hex digits
    uint32_t random2 = esp_random();  // Next sections
    uint32_t random3 = esp_random();  // More sections
    uint32_t random4 = esp_random();  // Final sections
    
    // Format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
    // Where:
    // - 4 is the version (UUID v4)
    // - y is 8, 9, a, or b (variant bits: 10xx)
    
    sprintf(uuid, "%08lx-%04lx-4%03lx-%04lx-%012llx",
            (unsigned long)random1,                           // 8 hex digits
            (unsigned long)((random2 >> 16) & 0xFFFF),       // 4 hex digits
            (unsigned long)(random2 & 0x0FFF),               // 3 hex digits (version 4 is prepended)
            (unsigned long)(((random3 >> 16) & 0x3FFF) | 0x8000),  // 4 hex digits with variant bits (10xx)
            (unsigned long long)(((uint64_t)(random3 & 0xFFFF) << 32) | random4)  // 12 hex digits
    );
    
    return String(uuid);
}

/**
 * Validates if a string is a properly formatted UUID v4
 * 
 * @param uuid String to validate
 * @return true if the string matches UUID v4 format, false otherwise
 * 
 * Validation checks:
 * - Length is exactly 36 characters
 * - Dashes are at positions 8, 13, 18, 23
 * - Version nibble (position 14) is '4'
 * - Variant nibble (position 19) is '8', '9', 'a', 'b', 'A', or 'B'
 * - All other characters are valid hexadecimal digits (0-9, a-f, A-F)
 */
inline bool isValidUUIDv4(const String& uuid) {
    // Check length
    if (uuid.length() != 36) {
        return false;
    }
    
    // Check dash positions
    if (uuid.charAt(8) != '-' || uuid.charAt(13) != '-' || 
        uuid.charAt(18) != '-' || uuid.charAt(23) != '-') {
        return false;
    }
    
    // Check version (must be 4)
    if (uuid.charAt(14) != '4') {
        return false;
    }
    
    // Check variant (must be 8, 9, a, b, A, or B)
    char variant = uuid.charAt(19);
    if (variant != '8' && variant != '9' && 
        variant != 'a' && variant != 'b' &&
        variant != 'A' && variant != 'B') {
        return false;
    }
    
    // Check all other characters are valid hex digits
    for (int i = 0; i < 36; i++) {
        if (i == 8 || i == 13 || i == 18 || i == 23) {
            continue;  // Skip dashes
        }
        
        char c = uuid.charAt(i);
        if (!((c >= '0' && c <= '9') || 
              (c >= 'a' && c <= 'f') || 
              (c >= 'A' && c <= 'F'))) {
            return false;
        }
    }
    
    return true;
}

#endif // UUID_GENERATOR_H
