# Smart Smoker ESP32 Firmware

Прошивка для ESP32 контроллера умной коптильни согласно техническому заданию.

## 🚀 Возможности

### Аппаратная поддержка
- **Датчики:** BME280 (I2C), 2x NTC термисторы (ADC)
- **Исполнительные механизмы:** SSR ТЭН, MOSFET дымогенератор, 2x реле вентиляторов, сервопривод заслонки
- **Интерфейс:** OLED дисплей 128x64 (I2C), 4 кнопки управления
- **Связь:** WiFi, веб-сервер, облачная синхронизация

### Программные функции
- Автоматические программы копчения с этапами
- Контроль температуры с гистерезисом
- Система безопасности и аварийных остановок
- Энергосбережение дисплея
- Буферизация данных при отсутствии связи
- Веб-интерфейс для локального управления

## 📁 Структура проекта

```
ESP32/
├── SmartSmoker.ino              # Главный файл Arduino
├── src/                         # Исходный код модулей
│   ├── config/                  # Конфигурация
│   │   ├── pins.h              # Назначение пинов
│   │   └── constants.h         # Системные константы
│   ├── system/
│   │   └── SystemState.h       # Глобальное состояние
│   ├── sensors/
│   │   └── SensorManager.h     # Управление датчиками
│   ├── actuators/
│   │   └── ActuatorManager.h   # Исполнительные механизмы
│   ├── display/
│   │   └── DisplayManager.h    # OLED дисплей
│   ├── buttons/
│   │   └── ButtonManager.h     # Кнопки управления
│   ├── network/
│   │   └── NetworkManager.h    # WiFi и сеть
│   ├── web/
│   │   └── WebServerManager.h  # Веб-сервер ESP32
│   ├── cloud/
│   │   ├── CloudManager.h      # Облачная синхронизация
│   │   └── CloudAPI.h          # API эндпоинты
│   ├── programs/
│   │   ├── ProgramStructures.h # Структуры программ
│   │   └── ProgramManager.h    # Управление программами
│   └── storage/
│       └── StorageManager.h    # Файловая система
├── examples/
│   └── CloudAPITest.ino        # Тест API совместимости
├── libraries.txt               # Список библиотек
├── API_COMPATIBILITY.md        # Документация API
└── README.md                   # Этот файл
```

## 🔧 Установка и настройка

### 1. Требования
- Arduino IDE 2.x или PlatformIO
- ESP32 плата (рекомендуется ESP32 DevKit v1)
- Библиотеки из файла `libraries.txt`

### 2. Установка библиотек

**Через Arduino IDE:**
1. Откройте Library Manager (Ctrl+Shift+I)
2. Установите библиотеки:
   - ArduinoJson (версия 6.x)
   - U8g2
   - Adafruit BME280 Library
   - ESP32Servo

**Через Arduino CLI:**
```bash
arduino-cli lib install "ArduinoJson"
arduino-cli lib install "U8g2"
arduino-cli lib install "Adafruit BME280 Library"
arduino-cli lib install "ESP32Servo"
```

### 3. Настройка платы
В Arduino IDE выберите:
- **Плата:** ESP32 Dev Module
- **CPU Frequency:** 240MHz (WiFi/BT)
- **Flash Size:** 4MB (32Mb)
- **Partition Scheme:** Default 4MB with spiffs

### 4. Подключение оборудования

Подключите компоненты согласно файлу `src/config/pins.h`:

```cpp
// Датчики
#define BME280_SDA_PIN    21    // I2C данные
#define BME280_SCL_PIN    22    // I2C тактирование
#define NTC_SMOKE_PIN     34    // ADC1_CH6
#define NTC_PRODUCT_PIN   35    // ADC1_CH7

// Исполнительные механизмы
#define SSR_HEATER_PIN    25    // SSR ТЭНа
#define MOSFET_SMOKE_PIN  26    // MOSFET дымогенератора
#define RELAY_FAN1_PIN    27    // Реле вентилятора 1
#define RELAY_FAN2_PIN    14    // Реле вентилятора 2
#define SERVO_DAMPER_PIN  13    // Сервопривод заслонки

// Интерфейс
#define OLED_SDA_PIN      21    // Общий I2C с BME280
#define OLED_SCL_PIN      22    // Общий I2C с BME280
#define BUTTON_UP_PIN     18    // Кнопка "Вверх"
#define BUTTON_DOWN_PIN   19    // Кнопка "Вниз"
#define BUTTON_SELECT_PIN 5     // Кнопка "Выбор"
#define BUTTON_BACK_PIN   17    // Кнопка "Назад"
```

## 🌐 Настройка облачной синхронизации

### 1. Настройка WiFi
При первом запуске ESP32 создаст точку доступа:
- **SSID:** SmartSmoker_XXXXXX
- **Пароль:** 12345678
- **IP:** 192.168.4.1

Подключитесь и настройте WiFi через веб-интерфейс.

### 2. Привязка к облаку
1. Создайте устройство в панели управления сайта
2. Скопируйте Device ID
3. Введите его в веб-интерфейсе ESP32
4. Устройство автоматически привяжется к облаку

### 3. Проверка API
Используйте `examples/CloudAPITest.ino` для проверки совместимости с API.

## 📊 Мониторинг и отладка

### Serial Monitor
Подключитесь к Serial Monitor (115200 baud) для просмотра логов:

```
=== Smart Smoker ESP32 v1.0 ===
✓ LittleFS mounted (Used: 1024/1572864 bytes)
✓ BME280 sensor initialized
✓ NTC sensors calibrated
✓ OLED display initialized
✓ Button manager initialized
✓ WiFi connected to: MyNetwork
✓ Web server started on: 192.168.1.100
✓ Cloud manager initialized
✓ Device bound to cloud: abc123-def456
=== System Ready ===
```

### Веб-интерфейс
Откройте в браузере IP адрес ESP32 для доступа к:
- Текущие показания датчиков
- Управление исполнительными механизмами
- Настройки WiFi и облака
- Загрузка и запуск программ
- Просмотр логов системы

### Отладочные флаги
В `src/config/constants.h` можно включить отладку:

```cpp
#define DEBUG_SENSORS     1    // Отладка датчиков
#define DEBUG_ACTUATORS   1    // Отладка исполнительных механизмов
#define DEBUG_NETWORK     1    // Отладка сети
#define DEBUG_CLOUD       1    // Отладка облачных запросов
#define DEBUG_PROGRAMS    1    // Отладка программ
```

## 🔒 Безопасность

### Аварийные остановки
Система автоматически останавливается при:
- Превышении максимальной температуры (>100°C)
- Ошибке датчиков (нет данных >30 сек)
- Потере связи с облаком (>10 мин)
- Ручной аварийной остановке

### Защита данных
- Пароли шифруются перед сохранением
- HTTPS для всех облачных запросов
- Локальное резервное копирование настроек

## 🔄 Обновление прошивки

### OTA обновления
ESP32 поддерживает обновления по воздуху:
1. Загрузите новую прошивку в панель управления
2. Устройство автоматически скачает и установит обновление
3. После перезагрузки проверьте версию в Serial Monitor

### Ручное обновление
1. Подключите ESP32 к компьютеру
2. Откройте проект в Arduino IDE
3. Нажмите Upload (Ctrl+U)

## 🐛 Устранение неполадок

### ESP32 не подключается к WiFi
- Проверьте правильность SSID и пароля
- Убедитесь, что сеть работает на 2.4 ГГц
- Сбросьте настройки через веб-интерфейс

### Ошибки датчиков
- Проверьте подключение I2C (SDA/SCL)
- Убедитесь в правильности адресов I2C
- Проверьте питание датчиков (3.3V)

### Проблемы с облаком
- Проверьте интернет-соединение
- Убедитесь в правильности Device ID
- Проверьте статус API в панели управления

### Сброс к заводским настройкам
Удерживайте кнопку "Назад" при включении ESP32 в течение 10 секунд.

## 📈 Производительность

### Потребление памяти
- **Flash:** ~800KB из 4MB
- **RAM:** ~150KB из 320KB
- **LittleFS:** ~100KB для настроек и логов

### Время отклика
- Чтение датчиков: 100ms
- Обновление дисплея: 50ms
- Отправка в облако: 1-3 секунды
- Веб-интерфейс: <500ms

## 🤝 Поддержка

### Документация
- [API Compatibility](API_COMPATIBILITY.md) - совместимость с облачным API
- [Техническое задание](../ТЗ/) - полные требования к системе

### Контакты
- GitHub Issues для багов и предложений
- Email поддержки: support@smartsmoker.com

## 📄 Лицензия

MIT License - см. файл LICENSE для деталей.

---

**Smart Smoker ESP32 Firmware v1.0**  
*Умная коптильня - технологии на службе вкуса* 🔥