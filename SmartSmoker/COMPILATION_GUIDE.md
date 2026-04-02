# Руководство по компиляции SmartSmoker

## Требования

- Arduino IDE или arduino-cli
- ESP32 board support package v3.3.7 или новее
- Установленные библиотеки (см. список ниже)

## Компиляция через arduino-cli

### Команда компиляции

```bash
arduino-cli compile --fqbn esp32:esp32:esp32:PartitionScheme=huge_app SmartSmoker
```

### Загрузка на устройство

```bash
arduino-cli upload -p COM3 --fqbn esp32:esp32:esp32:PartitionScheme=huge_app SmartSmoker
```

Замените `COM3` на ваш порт (например, `/dev/ttyUSB0` на Linux).

## Компиляция через Arduino IDE

1. Откройте `SmartSmoker/SmartSmoker.ino` в Arduino IDE
2. Выберите плату: **Tools → Board → ESP32 Arduino → ESP32 Dev Module**
3. Выберите схему разделов: **Tools → Partition Scheme → Huge APP (3MB No OTA/1MB SPIFFS)**
4. Нажмите **Verify** для компиляции или **Upload** для загрузки

## Важно: Partition Scheme

⚠️ **Обязательно используйте partition scheme `huge_app`!**

Стандартная схема разделов предоставляет только 1.31MB для приложения, что недостаточно для SmartSmoker (требуется ~1.44MB).

Схема `huge_app` предоставляет:
- 3MB для приложения
- 1MB для SPIFFS
- Без поддержки OTA (используется Server OTA вместо этого)

## Результаты компиляции

После успешной компиляции вы должны увидеть:

```
Sketch uses 1442750 bytes (45%) of program storage space. Maximum is 3145728 bytes.
Global variables use 59056 bytes (18%) of dynamic memory, leaving 268624 bytes for local variables. Maximum is 327680 bytes.
```

## Необходимые библиотеки

Установите следующие библиотеки через Library Manager:

- ArduinoJson (v7.4.2 или новее)
- U8g2 (v2.35.30 или новее)
- Adafruit BME280 Library (v2.3.0 или новее)
- Adafruit BusIO (v1.17.4 или новее)
- Adafruit Unified Sensor (v1.1.15 или новее)
- ESP32Servo (v3.1.3 или новее)

Встроенные библиотеки ESP32 (устанавливаются автоматически):
- WiFi, WebServer, FS, LittleFS
- HTTPClient, NetworkClientSecure
- Update, ArduinoOTA, Hash, ESPmDNS
- Wire, SPI

## Устранение проблем

### Ошибка "Sketch too big"

Если вы видите ошибку:
```
Sketch uses XXXXX bytes (110%) of program storage space. Maximum is 1310720 bytes.
Error during build: text section exceeds available space in board
```

**Решение**: Убедитесь, что используете partition scheme `huge_app`.

### Предупреждения компиляции

Все предупреждения в коде SmartSmoker исправлены. Если вы видите предупреждения, они могут быть из:
- Внешних библиотек (U8g2, ESP32Servo) - это нормально
- ESP32 core (legacy ADC driver) - это нормально

Эти предупреждения не влияют на работу устройства.

## Тестирование

После загрузки прошивки:

1. Откройте Serial Monitor (115200 baud)
2. Проверьте вывод загрузки
3. Подключитесь к WiFi точке доступа "SmartSmoker-XXXXXX"
4. Откройте веб-интерфейс по адресу http://192.168.4.1
5. Настройте WiFi и привяжите устройство к облаку

## Дополнительная информация

- Версия прошивки: см. `constants.h` → `FIRMWARE_VERSION`
- Документация API: см. комментарии в заголовочных файлах
- История изменений: см. `CHANGELOG_FIRMWARE.md`
- Прогресс рефакторинга: см. `REFACTORING_PROGRESS.md`
