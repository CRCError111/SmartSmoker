-- =====================================================
-- Диагностика привязки устройства для OTA-обновлений
-- =====================================================
-- Этот скрипт помогает проверить, почему устройство
-- получает ошибку 401 при проверке обновлений
-- =====================================================

-- ИНСТРУКЦИЯ:
-- 1. Замените 'YOUR_DEVICE_UUID' на реальный UUID вашего устройства
-- 2. Выполните этот скрипт в phpMyAdmin или MySQL CLI
-- 3. Проверьте результаты каждого запроса

-- =====================================================
-- Шаг 1: Найти устройство по UUID
-- =====================================================
SELECT 
    device_id,
    user_id,
    api_token,
    unbound,
    created_at,
    last_seen,
    CASE 
        WHEN unbound = 1 THEN '❌ Устройство отвязано'
        WHEN api_token IS NULL OR api_token = '' THEN '❌ API токен отсутствует'
        WHEN user_id IS NULL THEN '❌ Пользователь не назначен'
        ELSE '✅ Устройство привязано корректно'
    END as status
FROM devices 
WHERE device_id = 'YOUR_DEVICE_UUID';

-- =====================================================
-- Шаг 2: Проверить пользователя устройства
-- =====================================================
SELECT 
    u.id,
    u.username,
    u.email,
    u.role,
    u.is_active,
    d.device_id,
    d.api_token,
    d.unbound,
    CASE 
        WHEN u.is_active = 0 THEN '❌ Пользователь неактивен'
        WHEN d.unbound = 1 THEN '❌ Устройство отвязано'
        WHEN d.api_token IS NULL OR d.api_token = '' THEN '❌ API токен отсутствует'
        ELSE '✅ Всё в порядке'
    END as status
FROM devices d
LEFT JOIN users u ON d.user_id = u.id
WHERE d.device_id = 'YOUR_DEVICE_UUID';

-- =====================================================
-- Шаг 3: Проверить все устройства пользователя
-- =====================================================
-- Замените 'YOUR_USERNAME' на имя пользователя
SELECT 
    d.device_id,
    d.api_token,
    d.unbound,
    d.created_at,
    d.last_seen,
    CASE 
        WHEN d.unbound = 1 THEN '❌ Отвязано'
        WHEN d.api_token IS NULL OR d.api_token = '' THEN '❌ Нет токена'
        ELSE '✅ OK'
    END as status
FROM devices d
JOIN users u ON d.user_id = u.id
WHERE u.username = 'YOUR_USERNAME'
ORDER BY d.created_at DESC;

-- =====================================================
-- Шаг 4: Проверить активные прошивки
-- =====================================================
SELECT 
    id,
    version,
    filename,
    file_size,
    is_active,
    is_required,
    release_date,
    created_at,
    CASE 
        WHEN is_active = 1 THEN '✅ Активна'
        ELSE '⚪ Неактивна'
    END as status
FROM firmware_updates
ORDER BY release_date DESC
LIMIT 5;

-- =====================================================
-- Шаг 5: Проверить запросы на привязку
-- =====================================================
-- Последние 10 запросов на привязку
SELECT 
    request_id,
    uuid,
    user_id,
    status,
    created_at,
    processed_at,
    CASE 
        WHEN status = 'completed' THEN '✅ Завершено'
        WHEN status = 'pending' THEN '⏳ Ожидание'
        WHEN status = 'failed' THEN '❌ Ошибка'
        WHEN status = 'expired' THEN '⏰ Истекло'
        ELSE status
    END as status_display
FROM bind_requests
ORDER BY created_at DESC
LIMIT 10;

-- =====================================================
-- РЕШЕНИЕ ПРОБЛЕМ
-- =====================================================

-- Если устройство отвязано (unbound = 1), привяжите его заново:
-- UPDATE devices SET unbound = 0 WHERE device_id = 'YOUR_DEVICE_UUID';

-- Если API токен отсутствует, сгенерируйте новый:
-- UPDATE devices 
-- SET api_token = MD5(CONCAT(device_id, NOW(), RAND()))
-- WHERE device_id = 'YOUR_DEVICE_UUID';

-- Если пользователь неактивен, активируйте его:
-- UPDATE users SET is_active = 1 WHERE username = 'YOUR_USERNAME';

-- =====================================================
-- ПОЛНАЯ ИНФОРМАЦИЯ ОБ УСТРОЙСТВЕ
-- =====================================================
-- Замените 'YOUR_DEVICE_UUID' на реальный UUID
SELECT 
    d.device_id as 'UUID устройства',
    d.api_token as 'API токен',
    d.unbound as 'Отвязано (0=нет, 1=да)',
    u.username as 'Пользователь',
    u.email as 'Email',
    u.role as 'Роль',
    u.is_active as 'Активен (0=нет, 1=да)',
    d.created_at as 'Создано',
    d.last_seen as 'Последняя активность',
    CASE 
        WHEN d.unbound = 1 THEN '❌ ПРОБЛЕМА: Устройство отвязано'
        WHEN d.api_token IS NULL OR d.api_token = '' THEN '❌ ПРОБЛЕМА: API токен отсутствует'
        WHEN d.user_id IS NULL THEN '❌ ПРОБЛЕМА: Пользователь не назначен'
        WHEN u.is_active = 0 THEN '❌ ПРОБЛЕМА: Пользователь неактивен'
        ELSE '✅ Всё в порядке - проверьте логи сервера'
    END as 'Диагностика'
FROM devices d
LEFT JOIN users u ON d.user_id = u.id
WHERE d.device_id = 'YOUR_DEVICE_UUID';
