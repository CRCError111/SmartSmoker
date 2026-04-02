<?php
/**
 * Выход из системы
 * 
 * @version 1.0
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

// Выход из системы
Auth::logout();

// Перенаправление на страницу входа с сообщением
$_SESSION['logout_message'] = 'Вы успешно вышли из системы';

redirect(BASE_URL . '/login.php');