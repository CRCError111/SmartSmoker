# Database Scripts

## Файлы в этой папке

### firmware_updates_table.sql
SQL-скрипт для создания таблиц управления прошивками:
- `firmware_updates` - хранение информации о прошивках
- `firmware_downloads` - история скачиваний прошивок

**Использование:**
```bash
mysql -u username -p database_name < firmware_updates_table.sql
```

Или используйте веб-интерфейс: `https://your-domain.com/admin/check-database.php`

## Основная схема БД

Полная схема базы данных находится в файле:
`SmartSmoker/database/smart_smoker_db.sql`

Этот файл содержит все таблицы, включая таблицы для управления прошивками.

## Автоматическое создание таблиц

Для автоматического создания недостающих таблиц используйте:
`https://your-domain.com/admin/check-database.php`

Эта страница проверит наличие всех необходимых таблиц и создаст их при необходимости.
