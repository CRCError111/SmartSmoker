<?php
/**
 * Утилита для проверки и установки роли администратора
 * Используйте этот скрипт для диагностики проблем с доступом к админ-панели
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Проверка авторизации
Auth::init();
if (!Auth::check()) {
    die('Вы не авторизованы. Пожалуйста, войдите в систему.');
}

$userId = $_SESSION['user_id'];
$db = db();

// Получение текущих данных пользователя
$user = $db->fetchOne('SELECT id, email, full_name, role FROM users WHERE id = ?', [$userId]);

if (!$user) {
    die('Пользователь не найден в базе данных.');
}

echo "<h1>Проверка роли администратора</h1>";
echo "<hr>";
echo "<h2>Текущие данные пользователя:</h2>";
echo "<ul>";
echo "<li><strong>ID:</strong> {$user['id']}</li>";
echo "<li><strong>Email:</strong> {$user['email']}</li>";
echo "<li><strong>Имя:</strong> {$user['full_name']}</li>";
echo "<li><strong>Роль:</strong> <span style='color: " . ($user['role'] === 'admin' ? 'green' : 'red') . "'>{$user['role']}</span></li>";
echo "</ul>";

// Проверка метода Auth::isAdmin()
$isAdmin = Auth::isAdmin();
echo "<h2>Результат проверки Auth::isAdmin():</h2>";
echo "<p style='color: " . ($isAdmin ? 'green' : 'red') . "'>";
echo $isAdmin ? "✓ Вы являетесь администратором" : "✗ Вы НЕ являетесь администратором";
echo "</p>";

// Если пользователь не админ, предлагаем установить роль
if ($user['role'] !== 'admin') {
    echo "<hr>";
    echo "<h2>Установить роль администратора?</h2>";
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_admin'])) {
        try {
            $db->update('users', ['role' => 'admin'], 'id = ?', [$userId]);
            echo "<p style='color: green;'>✓ Роль администратора успешно установлена!</p>";
            echo "<p><a href='firmware.php'>Перейти к управлению прошивками</a></p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Ошибка: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    } else {
        echo "<form method='POST'>";
        echo "<button type='submit' name='set_admin' style='padding: 10px 20px; background: #007bff; color: white; border: none; cursor: pointer;'>";
        echo "Установить роль администратора для текущего пользователя";
        echo "</button>";
        echo "</form>";
        echo "<p style='color: #666; font-size: 0.9em;'>Это установит роль 'admin' для вашего аккаунта ({$user['email']})</p>";
    }
} else {
    echo "<hr>";
    echo "<h2>Доступ к админ-панели:</h2>";
    echo "<ul>";
    echo "<li><a href='index.php'>Главная админ-панели</a></li>";
    echo "<li><a href='firmware.php'>Управление прошивками</a></li>";
    echo "<li><a href='users.php'>Управление пользователями</a></li>";
    echo "<li><a href='devices.php'>Управление устройствами</a></li>";
    echo "</ul>";
}

echo "<hr>";
echo "<p><a href='../dashboard.php'>← Вернуться на главную</a></p>";
