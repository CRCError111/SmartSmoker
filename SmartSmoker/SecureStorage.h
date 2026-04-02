#ifndef SECURE_STORAGE_H
#define SECURE_STORAGE_H

#include <Arduino.h>
#include <WiFi.h>
#include "mbedtls/md.h"
#include "mbedtls/aes.h"

/**
 * Шифрование/расшифровка строк для хранения в LittleFS.
 * Ключ AES-256 выводится из MAC-адреса устройства + соли,
 * что делает его уникальным для каждого устройства.
 *
 * Формат зашифрованной строки: Base64(IV[16] + CipherText)
 */
class SecureStorage {
public:
    static void init() {
        if (_initialized) return;
        deriveKey();
        _initialized = true;
        Serial.println("[INFO] SecureStorage инициализирован");
    }

    /**
     * Шифрует строку (AES-256-CBC).
     * Возвращает Base64-строку вида "IV+CipherText".
     */
    static String encrypt(const String& plaintext) {
        if (!_initialized) init();

        // Случайный IV
        uint8_t iv[16];
        for (int i = 0; i < 16; i++) iv[i] = (uint8_t)esp_random();

        // Паддинг PKCS#7
        size_t len = plaintext.length();
        size_t padded = ((len / 16) + 1) * 16;
        uint8_t* buf = new uint8_t[padded]();
        memcpy(buf, plaintext.c_str(), len);
        uint8_t pad = (uint8_t)(padded - len);
        for (size_t i = len; i < padded; i++) buf[i] = pad;

        // Шифрование
        uint8_t* cipher = new uint8_t[padded];
        uint8_t ivCopy[16];
        memcpy(ivCopy, iv, 16);

        mbedtls_aes_context ctx;
        mbedtls_aes_init(&ctx);
        mbedtls_aes_setkey_enc(&ctx, _key, 256);
        mbedtls_aes_crypt_cbc(&ctx, MBEDTLS_AES_ENCRYPT, padded, ivCopy, buf, cipher);
        mbedtls_aes_free(&ctx);

        // IV + CipherText → Base64
        size_t totalLen = 16 + padded;
        uint8_t* combined = new uint8_t[totalLen];
        memcpy(combined, iv, 16);
        memcpy(combined + 16, cipher, padded);

        String result = base64Encode(combined, totalLen);

        delete[] buf;
        delete[] cipher;
        delete[] combined;
        return result;
    }

    /**
     * Расшифровывает строку, зашифрованную через encrypt().
     * При ошибке возвращает пустую строку.
     */
    static String decrypt(const String& ciphertext) {
        if (!_initialized) init();
        if (ciphertext.isEmpty()) return "";

        size_t decodedLen = 0;
        uint8_t* decoded = base64Decode(ciphertext, decodedLen);
        if (!decoded || decodedLen < 17) {
            delete[] decoded;
            return "";
        }

        uint8_t iv[16];
        memcpy(iv, decoded, 16);
        size_t cipherLen = decodedLen - 16;
        uint8_t* plain = new uint8_t[cipherLen];

        mbedtls_aes_context ctx;
        mbedtls_aes_init(&ctx);
        mbedtls_aes_setkey_dec(&ctx, _key, 256);
        mbedtls_aes_crypt_cbc(&ctx, MBEDTLS_AES_DECRYPT, cipherLen, iv, decoded + 16, plain);
        mbedtls_aes_free(&ctx);

        // Убираем PKCS#7 паддинг
        uint8_t pad = plain[cipherLen - 1];
        size_t plainLen = (pad < 16 && pad > 0) ? cipherLen - pad : cipherLen;

        String result((char*)plain, plainLen);
        delete[] decoded;
        delete[] plain;
        return result;
    }

private:
    static uint8_t _key[32];
    static bool _initialized;

    // Ключ = SHA256(MAC + "SmartSmoker_v1")
    static void deriveKey() {
        uint8_t mac[6];
        WiFi.macAddress(mac);

        const char* salt = "SmartSmoker_v1";
        size_t saltLen = strlen(salt);
        size_t inputLen = 6 + saltLen;
        uint8_t* input = new uint8_t[inputLen];
        memcpy(input, mac, 6);
        memcpy(input + 6, salt, saltLen);

        mbedtls_md_context_t ctx;
        mbedtls_md_init(&ctx);
        mbedtls_md_setup(&ctx, mbedtls_md_info_from_type(MBEDTLS_MD_SHA256), 0);
        mbedtls_md_starts(&ctx);
        mbedtls_md_update(&ctx, input, inputLen);
        mbedtls_md_finish(&ctx, _key);
        mbedtls_md_free(&ctx);

        delete[] input;
    }

    static String base64Encode(const uint8_t* data, size_t len) {
        static const char* chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";
        String result;
        result.reserve(((len + 2) / 3) * 4);
        for (size_t i = 0; i < len; i += 3) {
            uint32_t n = ((uint32_t)data[i] << 16)
                       | (i + 1 < len ? (uint32_t)data[i+1] << 8 : 0)
                       | (i + 2 < len ? (uint32_t)data[i+2]      : 0);
            result += chars[(n >> 18) & 0x3F];
            result += chars[(n >> 12) & 0x3F];
            result += (i + 1 < len) ? chars[(n >> 6) & 0x3F] : '=';
            result += (i + 2 < len) ? chars[n & 0x3F]        : '=';
        }
        return result;
    }

    static uint8_t* base64Decode(const String& input, size_t& outLen) {
        static const int8_t table[256] = {
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,62,-1,-1,-1,63,
            52,53,54,55,56,57,58,59,60,61,-1,-1,-1,-1,-1,-1,
            -1, 0, 1, 2, 3, 4, 5, 6, 7, 8, 9,10,11,12,13,14,
            15,16,17,18,19,20,21,22,23,24,25,-1,-1,-1,-1,-1,
            -1,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,
            41,42,43,44,45,46,47,48,49,50,51,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,
            -1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1,-1
        };
        size_t inLen = input.length();
        outLen = (inLen / 4) * 3;
        if (inLen > 0 && input[inLen-1] == '=') outLen--;
        if (inLen > 1 && input[inLen-2] == '=') outLen--;

        uint8_t* out = new uint8_t[outLen];
        size_t j = 0;
        for (size_t i = 0; i < inLen; i += 4) {
            uint32_t n = ((uint32_t)table[(uint8_t)input[i]]   << 18)
                       | ((uint32_t)table[(uint8_t)input[i+1]] << 12)
                       | ((uint32_t)table[(uint8_t)input[i+2]] <<  6)
                       | ((uint32_t)table[(uint8_t)input[i+3]]);
            if (j < outLen) out[j++] = (n >> 16) & 0xFF;
            if (j < outLen) out[j++] = (n >>  8) & 0xFF;
            if (j < outLen) out[j++] =  n        & 0xFF;
        }
        return out;
    }
};

inline uint8_t SecureStorage::_key[32] = {};
inline bool    SecureStorage::_initialized = false;

#endif // SECURE_STORAGE_H
