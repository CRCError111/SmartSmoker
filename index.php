<?php
/**
 * Главная страница - перенаправление на авторизацию
 * 
 * @version 1.1 (исправлен бесконечный цикл редиректов)
 * @author Smart Smoker Team
 */

// Определение константы для доступа к файлам
define('SMART_SMOKER', true);

// Подключение конфигурации
require_once __DIR__ . '/config.php';

// Подключение модулей
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Инициализация аутентификации
Auth::init();

// Получаем текущий URI
$currentUri = $_SERVER['REQUEST_URI'] ?? '/';
$currentUri = strtok($currentUri, '?'); // Убираем query string

// Проверяем, не находимся ли мы уже на странице входа или панели
$isLoginPage = strpos($currentUri, '/login.php') !== false;
$isDashboardPage = strpos($currentUri, '/dashboard.php') !== false;

if (Auth::check()) {
    // Пользователь авторизован - перенаправляем на панель, если не там
    if (!$isDashboardPage) {
        redirect(BASE_URL . '/dashboard.php');
    }
} else {
    // Пользователь не авторизован - перенаправляем на вход, если не там
    if (!$isLoginPage) {
        redirect(BASE_URL . '/login.php');
    }
}
?>