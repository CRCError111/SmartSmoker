# Cron Jobs для SmartSmoker

Этот каталог содержит скрипты для автоматического выполнения фоновых задач.

## Список cron задач

### 1. process-transfer-queue.php
**Назначение**: Обработка очереди передачи программ на контроллеры  
**Частота**: Каждую минуту  
**Требования**: 4.1, 4.5, 4.6, 4.7, 8.1, 8.2, 8.3, 9.4

```bash
* * * * * /usr/bin/php /path/to/project/cron/process-transfer-queue.php >> /path/to/project/logs/cron.log 2>&1
```

### 2. cleanup-transfer-queue.php
**Назначение**: Автоматическая очистка старых записей очереди передачи (старше 30 дней)  
**Частота**: Ежедневно в 2:00  
**Требования**: 13.6

```bash
0 2 * * * /usr/bin/php /path/to/project/cron/cleanup-transfer-queue.php >> /path/to/project/logs/cron.log 2>&1
```

### 3. resend-pending-files.php
**Назначение**: Повторная отправка неподтвержденных файлов  
**Частота**: Каждые 5 минут

```bash
*/5 * * * * /usr/bin/php /path/to/project/cron/resend-pending-files.php >> /path/to/project/logs/cron.log 2>&1
```

### 4. sync-all-devices.php
**Назначение**: Синхронизация списка программ со всех онлайн устройств  
**Частота**: Каждые 5 минут

```bash
*/5 * * * * /usr/bin/php /path/to/project/cron/sync-all-devices.php >> /path/to/project/logs/cron.log 2>&1
```

## Установка cron задач

### Linux/Unix

1. Откройте редактор crontab:
```bash
crontab -e
```

2. Добавьте все необходимые строки из списка выше

3. Замените `/path/to/project` на реальный путь к проекту

4. Сохраните и закройте редактор

5. Проверьте установленные задачи:
```bash
crontab -l
```

### Windows (Task Scheduler)

1. Откройте "Планировщик заданий" (Task Scheduler)

2. Создайте новую задачу для каждого скрипта

3. Настройте триггеры согласно частоте выполнения

4. В действии укажите:
   - Программа: `C:\path\to\php.exe`
   - Аргументы: `C:\path\to\project\cron\script-name.php`

## Проверка работы

### Просмотр логов

```bash
tail -f /path/to/project/logs/cron.log
```

### Ручной запуск для тестирования

```bash
php /path/to/project/cron/cleanup-transfer-queue.php
```

### Проверка статуса очереди

```sql
SELECT status, COUNT(*) as count 
FROM program_transfer_queue 
GROUP BY status;
```

## Устранение неполадок

### Cron не запускается

1. Проверьте права доступа к файлам:
```bash
chmod +x /path/to/project/cron/*.php
```

2. Проверьте путь к PHP:
```bash
which php
```

3. Проверьте логи системы:
```bash
grep CRON /var/log/syslog
```

### Ошибки выполнения

1. Проверьте логи приложения:
```bash
tail -f /path/to/project/logs/app_$(date +%Y-%m-%d).log
```

2. Проверьте подключение к БД

3. Проверьте права доступа к каталогам

## Мониторинг

Рекомендуется настроить мониторинг выполнения cron задач:

1. Проверка последнего времени выполнения
2. Отслеживание ошибок в логах
3. Мониторинг размера очереди передачи
4. Алерты при превышении порогов

## Безопасность

- Все скрипты защищены от прямого веб-доступа
- Требуется специальный ключ `CRON_KEY` для веб-запуска
- Рекомендуется запускать только через CLI
