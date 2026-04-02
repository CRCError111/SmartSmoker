<?php
/**
 * Генератор хеша пароля для пользователя test_user
 * 
 * Использование:
 * php database/generate_password_hash.php
 * 
 * Или откройте в браузере:
 * http://your-domain.com/database/generate_password_hash.php
 */

// Пароль для хеширования
$password = 'Test_User_12!@';

// Генерируем хеш
$hash = password_hash($password, PASSWORD_DEFAULT);

// Выводим результат
echo "==============================================\n";
echo "Генератор хеша пароля\n";
echo "==============================================\n\n";
echo "Пароль: {$password}\n\n";
echo "Хеш пароля:\n";
echo "{$hash}\n\n";
echo "==============================================\n";
echo "Скопируйте этот хеш и используйте его в SQL-скрипте\n";
echo "в поле password_hash при создании пользователя.\n";
echo "==============================================\n";

// Проверяем, что хеш работает
if (password_verify($password, $hash)) {
    echo "\n✅ Проверка: Хеш корректен!\n";
} else {
    echo "\n❌ Ошибка: Хеш некорректен!\n";
}

// Если запущено в браузере, форматируем вывод
if (php_sapi_name() !== 'cli') {
    echo "<pre>";
    echo "\n\n";
    echo "SQL для вставки:\n";
    echo "UPDATE users SET password_hash = '{$hash}' WHERE username = 'test_user';\n";
    echo "</pre>";
}
?>
