-- Добавление прошивки версии 2.2.0
-- Дата: 2026-03-07

-- Деактивируем предыдущие версии
UPDATE firmware_updates SET is_active = 0 WHERE is_active = 1;

-- Добавляем новую версию 2.2.0
INSERT INTO firmware_updates (
    version,
    filename,
    file_path,
    file_size,
    checksum,
    release_notes,
    is_required,
    min_version_required,
    is_active,
    release_date,
    created_at,
    updated_at
) VALUES (
    '2.2.0',
    'firmware_2.2.0.bin',
    '/var/www/u3385152/data/www/crcerror.ru/firmware/firmware_2.2.0.bin',
    1507286,
    SHA2(LOAD_FILE('/var/www/u3385152/data/www/crcerror.ru/firmware/firmware_2.2.0.bin'), 256),
    'Версия 2.2.0:\n- Исправлена страница "Файлы" на контроллере: скрыты системные файлы и директории\n- Улучшена стабильность OTA-обновлений: добавлен retry механизм с 3 попытками\n- Увеличен HTTP timeout до 30 секунд\n- Добавлена проверка WiFi подключения перед загрузкой\n- Добавлено подробное логирование для отладки',
    0,
    '2.0.0',
    1,
    NOW(),
    NOW(),
    NOW()
);

-- Проверяем результат
SELECT 
    version,
    filename,
    file_size,
    is_active,
    release_date
FROM firmware_updates
ORDER BY release_date DESC
LIMIT 5;
