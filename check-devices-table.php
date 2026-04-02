<?php
/**
 * Проверка структуры таблицы devices
 */

define('SMART_SMOKER', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

$db = db();

echo "<h1>🔍 Структура таблицы devices</h1>";

// Получаем структуру таблицы
$columns = $db->fetchAll("DESCRIBE devices", []);

echo "<h2>📋 Колонки таблицы:</h2>";
echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($columns as $col) {
    echo "<tr>";
    echo "<td><strong>{$col['Field']}</strong></td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "<td>{$col['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// Получаем все устройства
echo "<h2>📱 Все устройства в таблице:</h2>";
$devices = $db->fetchAll("SELECT * FROM devices LIMIT 10", []);

if (count($devices) > 0) {
    echo "<p>Найдено устройств: " . count($devices) . "</p>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    
    // Заголовки
    echo "<tr>";
    foreach (array_keys($devices[0]) as $key) {
        echo "<th>{$key}</th>";
    }
    echo "</tr>";
    
    // Данные
    foreach ($devices as $device) {
        echo "<tr>";
        foreach ($device as $value) {
            $displayValue = is_string($value) && strlen($value) > 50 
                ? substr($value, 0, 50) . '...' 
                : $value;
            echo "<td>{$displayValue}</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>❌ Нет устройств в таблице</p>";
}

// Проверяем таблицу device_bindings
echo "<h2>🔗 Структура таблицы device_bindings:</h2>";
$bindingColumns = $db->fetchAll("DESCRIBE device_bindings", []);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
foreach ($bindingColumns as $col) {
    echo "<tr>";
    echo "<td><strong>{$col['Field']}</strong></td>";
    echo "<td>{$col['Type']}</td>";
    echo "<td>{$col['Null']}</td>";
    echo "<td>{$col['Key']}</td>";
    echo "<td>{$col['Default']}</td>";
    echo "<td>{$col['Extra']}</td>";
    echo "</tr>";
}
echo "</table>";

// Получаем все привязки
echo "<h2>🔗 Все привязки в таблице device_bindings:</h2>";
$bindings = $db->fetchAll("SELECT * FROM device_bindings ORDER BY created_at DESC LIMIT 10", []);

if (count($bindings) > 0) {
    echo "<p>Найдено привязок: " . count($bindings) . "</p>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
    
    // Заголовки
    echo "<tr>";
    foreach (array_keys($bindings[0]) as $key) {
        echo "<th>{$key}</th>";
    }
    echo "</tr>";
    
    // Данные
    foreach ($bindings as $binding) {
        echo "<tr>";
        foreach ($binding as $value) {
            $displayValue = is_string($value) && strlen($value) > 50 
                ? substr($value, 0, 50) . '...' 
                : $value;
            echo "<td>{$displayValue}</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>❌ Нет привязок в таблице</p>";
}
?>
