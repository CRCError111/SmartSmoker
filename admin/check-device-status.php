<?php
/**
 * Диагностика состояния устройства в базе данных
 * Используйте для проверки проблем с аутентификацией устройств
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

// Проверка авторизации
Auth::init();
if (!Auth::check()) {
    die('Вы не авторизованы. Пожалуйста, войдите в систему.');
}

$db = db();
$error = '';
$deviceInfo = null;

// Обработка формы поиска
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['device_id'])) {
    $deviceId = trim($_POST['device_id']);
    
    try {
        $deviceInfo = $db->fetchOne(
            'SELECT * FROM devices WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        if (!$deviceInfo) {
            $error = "Устройство с ID '$deviceId' не найдено в базе данных";
        }
    } catch (Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Обработка исправления статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fix_status']) && isset($_POST['device_id_fix'])) {
    $deviceId = trim($_POST['device_id_fix']);
    
    try {
        $db->update(
            'devices',
            ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')],
            'device_id = ?',
            [$deviceId]
        );
        
        $deviceInfo = $db->fetchOne(
            'SELECT * FROM devices WHERE device_id = ? LIMIT 1',
            [$deviceId]
        );
        
        $error = "<span style='color: green;'>✓ Статус устройства успешно изменен на 'active'</span>";
    } catch (Exception $e) {
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Получение всех устройств текущего пользователя
$userDevices = [];
try {
    $userId = $_SESSION['user_id'];
    $userDevices = $db->fetchAll(
        'SELECT device_id, device_name, status, created_at, last_seen FROM devices WHERE user_id = ? ORDER BY created_at DESC',
        [$userId]
    );
} catch (Exception $e) {
    // Игнорируем ошибку
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Диагностика устройства</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 20px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #007bff;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #555;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        button {
            padding: 10px 20px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        button:hover {
            background: #0056b3;
        }
        .error {
            padding: 15px;
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            border-radius: 4px;
            color: #721c24;
            margin-bottom: 20px;
        }
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .info-table th,
        .info-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .info-table th {
            background: #f8f9fa;
            font-weight: bold;
            color: #555;
        }
        .status-active {
            color: green;
            font-weight: bold;
        }
        .status-pending {
            color: orange;
            font-weight: bold;
        }
        .status-inactive {
            color: red;
            font-weight: bold;
        }
        .fix-button {
            background: #28a745;
            padding: 8px 16px;
            font-size: 13px;
        }
        .fix-button:hover {
            background: #218838;
        }
        .device-list {
            margin-top: 20px;
        }
        .device-item {
            padding: 10px;
            background: #f8f9fa;
            border-left: 4px solid #007bff;
            margin-bottom: 10px;
            cursor: pointer;
        }
        .device-item:hover {
            background: #e9ecef;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #007bff;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Диагностика устройства</h1>
        
        <?php if ($error): ?>
        <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="form-group">
            <form method="POST">
                <label for="device_id">Device ID (UUID):</label>
                <input type="text" id="device_id" name="device_id" 
                       placeholder="Например: 8ab8a9d3-b2d5-49ab-9bfb-8bd6ed784938"
                       value="<?php echo htmlspecialchars($_POST['device_id'] ?? ''); ?>" required>
                <button type="submit" style="margin-top: 10px;">Проверить устройство</button>
            </form>
        </div>
        
        <?php if ($deviceInfo): ?>
        <h2>📊 Информация об устройстве</h2>
        <table class="info-table">
            <tr>
                <th>Параметр</th>
                <th>Значение</th>
            </tr>
            <tr>
                <td>Device ID</td>
                <td><code><?php echo htmlspecialchars($deviceInfo['device_id']); ?></code></td>
            </tr>
            <tr>
                <td>Название</td>
                <td><?php echo htmlspecialchars($deviceInfo['device_name'] ?? 'Не указано'); ?></td>
            </tr>
            <tr>
                <td>Статус</td>
                <td>
                    <span class="status-<?php echo $deviceInfo['status']; ?>">
                        <?php echo strtoupper($deviceInfo['status']); ?>
                    </span>
                    <?php if ($deviceInfo['status'] !== 'active'): ?>
                        <br><small style="color: #dc3545;">⚠️ Устройство не активно! Это причина ошибки 404.</small>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <td>API Token</td>
                <td>
                    <code><?php echo htmlspecialchars(substr($deviceInfo['api_token'], 0, 16)); ?>...</code>
                    <br><small style="color: #666;">Длина: <?php echo strlen($deviceInfo['api_token']); ?> символов</small>
                </td>
            </tr>
            <tr>
                <td>User ID</td>
                <td><?php echo htmlspecialchars($deviceInfo['user_id']); ?></td>
            </tr>
            <tr>
                <td>Отвязано (unbound)</td>
                <td>
                    <?php echo $deviceInfo['unbound'] ? '<span style="color: red;">Да</span>' : '<span style="color: green;">Нет</span>'; ?>
                </td>
            </tr>
            <tr>
                <td>Создано</td>
                <td><?php echo htmlspecialchars($deviceInfo['created_at']); ?></td>
            </tr>
            <tr>
                <td>Обновлено</td>
                <td><?php echo htmlspecialchars($deviceInfo['updated_at']); ?></td>
            </tr>
            <tr>
                <td>Последняя активность</td>
                <td><?php echo htmlspecialchars($deviceInfo['last_seen'] ?? 'Никогда'); ?></td>
            </tr>
        </table>
        
        <?php if ($deviceInfo['status'] !== 'active'): ?>
        <h2>🔧 Исправление</h2>
        <p>Устройство имеет статус "<strong><?php echo $deviceInfo['status']; ?></strong>" вместо "active".</p>
        <p>Это объясняет ошибку <code>AUTH ERROR (404): Device ID not recognized</code>.</p>
        <form method="POST">
            <input type="hidden" name="device_id_fix" value="<?php echo htmlspecialchars($deviceInfo['device_id']); ?>">
            <button type="submit" name="fix_status" class="fix-button">
                ✓ Установить статус "active"
            </button>
        </form>
        <?php else: ?>
        <h2>✅ Диагностика</h2>
        <p style="color: green;">Устройство имеет правильный статус "active".</p>
        <p>Если ошибка 404 все еще возникает, проверьте:</p>
        <ul>
            <li>Правильно ли устройство отправляет Device ID в запросе</li>
            <li>Совпадает ли API токен на устройстве с токеном в базе данных</li>
            <li>Синхронизирован ли файл cloud.json на устройстве</li>
        </ul>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if (!empty($userDevices)): ?>
        <h2>📱 Ваши устройства</h2>
        <div class="device-list">
            <?php foreach ($userDevices as $device): ?>
            <div class="device-item" onclick="document.getElementById('device_id').value='<?php echo htmlspecialchars($device['device_id']); ?>'; window.scrollTo(0, 0);">
                <strong><?php echo htmlspecialchars($device['device_name'] ?? 'Без названия'); ?></strong>
                <br>
                <small>ID: <?php echo htmlspecialchars(substr($device['device_id'], 0, 20)); ?>...</small>
                <br>
                <small>Статус: <span class="status-<?php echo $device['status']; ?>"><?php echo strtoupper($device['status']); ?></span></small>
                <br>
                <small>Последняя активность: <?php echo htmlspecialchars($device['last_seen'] ?? 'Никогда'); ?></small>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <a href="../dashboard.php" class="back-link">← Вернуться на главную</a>
    </div>
</body>
</html>
