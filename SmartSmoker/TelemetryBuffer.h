/**
 * Буфер телеметрии — фиксированный кольцевой буфер типизированных записей.
 *
 * @file TelemetryBuffer.h
 * @version 1.0
 */

#ifndef TELEMETRY_BUFFER_H
#define TELEMETRY_BUFFER_H

#include <Arduino.h>
#include "constants.h"

// =====================================================
// СТРУКТУРА ЗАПИСИ ТЕЛЕМЕТРИИ
// =====================================================
struct TelemetryRecord {
    float    tempChamber;   // Температура камеры (°C)
    float    tempSmoke;     // Температура дыма (°C)
    float    tempProduct;   // Температура продукта (°C)
    float    humidity;      // Влажность (%)
    uint32_t timestamp;     // Метка времени millis() в момент захвата
};

// =====================================================
// КОЛЬЦЕВОЙ БУФЕР ТЕЛЕМЕТРИИ
// =====================================================
class TelemetryBuffer {
public:
    TelemetryBuffer() : _head(0), _tail(0), _count(0) {}

    /**
     * Добавить запись в буфер.
     * Если буфер полон — перезаписывает самую старую запись (circular overwrite).
     */
    void push(const TelemetryRecord& rec) {
        _buf[_tail] = rec;
        _tail = (_tail + 1) % TELEMETRY_BUFFER_MAX_RECORDS;
        if (_count == TELEMETRY_BUFFER_MAX_RECORDS) {
            // Буфер полон — сдвигаем голову, вытесняя старейшую запись
            _head = (_head + 1) % TELEMETRY_BUFFER_MAX_RECORDS;
        } else {
            _count++;
        }
    }

    /**
     * Вернуть первую (старейшую) запись без удаления.
     * Возвращает TelemetryRecord{} если буфер пуст.
     */
    TelemetryRecord peek() const {
        if (_count == 0) {
            return TelemetryRecord{};
        }
        return _buf[_head];
    }

    /**
     * Удалить первую (старейшую) запись.
     * Нет операции если буфер пуст.
     */
    void pop() {
        if (_count == 0) return;
        _head = (_head + 1) % TELEMETRY_BUFFER_MAX_RECORDS;
        _count--;
    }

    bool    isEmpty() const { return _count == 0; }
    bool    isFull()  const { return _count == TELEMETRY_BUFFER_MAX_RECORDS; }
    uint8_t size()    const { return _count; }

private:
    TelemetryRecord _buf[TELEMETRY_BUFFER_MAX_RECORDS];
    uint8_t         _head;   // Индекс старейшей записи (следующей для pop)
    uint8_t         _tail;   // Индекс следующего слота записи
    uint8_t         _count;  // Текущее количество записей
};

#endif // TELEMETRY_BUFFER_H
