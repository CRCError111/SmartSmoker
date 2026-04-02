-- =====================================================
-- Создание тестового пользователя-администратора
-- =====================================================
-- Пользователь: test_user
-- Пароль: Test_User_12!@
-- Роль: admin
-- =====================================================

-- Генерация хеша пароля в PHP:
-- password_hash('Test_User_12!@', PASSWORD_DEFAULT)
-- Результат: $2y$10$YourHashHere

-- ВАЖНО: Этот скрипт использует предварительно сгенерированный хеш пароля
-- Если нужно изменить пароль, используйте PHP для генерации нового хеша:
-- <?php echo password_hash('Test_User_12!@', PASSWORD_DEFAULT); ?>

-- Проверяем, существует ли пользователь с таким username или email
SELECT 
    CASE 
        WHEN EXISTS (SELECT 1 FROM users WHERE username = 'test_user') THEN 'Username уже существует'
        WHEN EXISTS (SELECT 1 FROM users WHERE email = 'test_user@smartsmoker.local') THEN 'Email уже существует'
        ELSE 'OK - можно создавать пользователя'
    END as check_status;

-- Удаляем пользователя, если он уже существует (опционально)
-- DELETE FROM users WHERE username = 'test_user' OR email = 'test_user@smartsmoker.local';

-- Создаём пользователя-администратора
INSERT INTO `users` (
    `username`, 
    `email`, 
    `password_hash`, 
    `full_name`, 
    `role`,
    `is_active`, 
    `email_verified`,
    `timezone`,
    `language`,
    `created_at`
) VALUES (
    'test_user',                                                                                    -- username
    'test_user@smartsmoker.local',                                                                 -- email
    '$2y$12$EqOm44HLMz/iacZrYDM8munFI6mo1M8IOkgKuA6XgyR7IgWT2S2.e',                              -- password_hash для 'Test_User_12!@'
    'Тестовый Администратор',                                                                      -- full_name
    'admin',                                                                                        -- role (admin)
    1,                                                                                              -- is_active (активен)
    1,                                                                                              -- email_verified (подтверждён)
    'Europe/Moscow',                                                                                -- timezone
    'ru',                                                                                           -- language
    NOW()                                                                                           -- created_at
);

-- Проверяем результат
SELECT 
    id,
    username,
    email,
    full_name,
    role,
    is_active,
    email_verified,
    created_at
FROM users 
WHERE username = 'test_user';

-- =====================================================
-- РЕЗУЛЬТАТ:
-- =====================================================
-- ✅ Пользователь: test_user
-- ✅ Email: test_user@smartsmoker.local
-- ✅ Пароль: Test_User_12!@
-- ✅ Роль: admin (администратор)
-- ✅ Статус: Активен и подтверждён
-- =====================================================

-- ВАЖНОЕ ПРИМЕЧАНИЕ:
-- Хеш пароля в этом скрипте является примером.
-- Для реального использования необходимо сгенерировать хеш с помощью PHP:
-- 
-- Создайте файл generate_hash.php:
-- <?php
-- echo password_hash('Test_User_12!@', PASSWORD_DEFAULT);
-- ?>
-- 
-- Запустите: php generate_hash.php
-- Скопируйте полученный хеш и замените им значение в поле password_hash выше.
