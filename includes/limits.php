<?php
/**
 * Системные ограничения и константы безопасности
 */
if (!defined('SMART_SMOKER')) {
    exit('Доступ запрещен');
}

// Температурные ограничения (Цельсий)
define('LIMIT_TEMP_CHAMBER_MAX', 110.0); // Макс. температура в камере (ТЗ 2.2.4)
define('LIMIT_TEMP_SMOKE_MAX', 120.0); // Макс. температура дыма (ТЗ 2.2.3)
define('LIMIT_TEMP_PRODUCT_MAX', 100.0); // Макс. температура продукта
define('LIMIT_TEMP_MIN', -40.0); // Мин. рабочая температура (ТЗ 2.2.3)

// Ограничения влажности (%)
define('LIMIT_HUMIDITY_MIN', 0.0);
define('LIMIT_HUMIDITY_MAX', 100.0);

// Ограничения программ
define('LIMIT_MAX_STAGES', 20); // Макс. кол-во этапов в программе
define('LIMIT_STAGE_DURATION_MAX', 1440); // Макс. длительность этапа (24ч в минутах)
define('LIMIT_PROGRAM_NAME_MAX', 100); // Макс. длина названия программы

// Ограничения устройств
define('LIMIT_MAX_DEVICES_PER_USER', 5); // Макс. кол-во устройств на одного пользователя

// Ограничения API
define('LIMIT_SENSORS_STALE_MINUTES', 5); // Через сколько минут данные считаются устаревшими (offline)

/**
 * Проверка значения на нахождение в диапазоне
 */
function checkLimit($value, $min, $max)
{
    return $value >= $min && $value <= $max;
}
