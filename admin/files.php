<?php
/**
 * Страница управления файлами на ESP32
 * Отображает список файлов на устройстве
 */

require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::requireAuth();

// Требуется авторизация администратора
if (!Auth::isAdmin()) {
    header('Location: /dashboard.php');
    exit;
}

// Получение device_id из параметров
$deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : null;

// Получение списка файлов
$files = [];
$error = '';

if ($deviceId) {
    // Получить токен устройства из базы
    $device = $db->fetchOne(
        'SELECT device_token FROM devices WHERE device_id = ?',
        [$deviceId]
    );
    
    $apiUrl = BASE_URL . '/api/list-files.php?device_id=' . urlencode($deviceId);
    if ($device && !empty($device['device_token'])) {
        $apiUrl .= '&device_token=' . urlencode($device['device_token']);
    }
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $data = json_decode($response, true);
        if ($data && $data['success']) {
            $files = $data['files'];
        } else {
            $error = $data['error'] ?? 'Не удалось получить список файлов';
        }
    } else {
        $error = 'Ошибка подключения к устройству (HTTP ' . $httpCode . ')';
    }
}

// Получение списка устройств
$db = db();
$devices = $db->fetchAll('SELECT device_id, name, status FROM devices ORDER BY name ASC');

// Заголовок страницы
$pageTitle = 'Файлы на устройстве';

require_once __DIR__ . '/template/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3">Файлы на устройстве</h1>
        <a href="devices.php" class="btn btn-secondary">Назад к устройствам</a>
    </div>

    <!-- Выбор устройства -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">Выберите устройство</h5>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <form class="form-inline">
                        <div class="form-group mb-2">
                            <label for="deviceSelect" class="sr-only">Устройство:</label>
                            <select id="deviceSelect" class="form-control">
                                <option value="">-- Выберите устройство --</option>
                                <?php foreach ($devices as $device): ?>
                                    <option value="<?= htmlspecialchars($device['device_id']) ?>">
                                        <?= htmlspecialchars($device['name']) ?> (<?= htmlspecialchars($device['device_id']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="button" id="loadFilesBtn" class="btn btn-primary mb-2 ml-2">
                            Загрузить файлы
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Отображение файлов -->
    <?php if ($error): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($files)): ?>
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Файлы на устройстве (<?= count($files) ?>)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="thead-dark">
                            <tr>
                                <th>Имя файла</th>
                                <th>Тип</th>
                                <th>Размер</th>
                                <th>Изменен</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($files as $file): ?>
                                <?php 
                                // Skip system files (*.json)
                                if (pathinfo($file['name'], PATHINFO_EXTENSION) === 'json') {
                                    continue;
                                }
                                ?>
                                <tr>
                                    <td><code><?= htmlspecialchars($file['name']) ?></code></td>
                                    <td><span class="badge badge-secondary"><?= htmlspecialchars($file['type']) ?></span></td>
                                    <td><?= formatSize($file['size']) ?></td>
                                    <td><?= formatDate($file['modified']) ?></td>
                                    <td>
                                        <button class="btn btn-sm btn-info view-file" 
                                                data-file="<?= htmlspecialchars($file['name']) ?>"
                                                data-device="<?= htmlspecialchars($deviceId) ?>">
                                            Просмотр
                                        </button>
                                        <?php if ($file['type'] === 'file'): ?>
                                        <button class="btn btn-sm btn-danger delete-file" 
                                                data-file="<?= htmlspecialchars($file['name']) ?>"
                                                data-device="<?= htmlspecialchars($deviceId) ?>">
                                            Удалить
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php elseif ($deviceId): ?>
        <div class="alert alert-info">
            На устройстве нет файлов или не удалось получить список файлов
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const deviceSelect = document.getElementById('deviceSelect');
    const loadFilesBtn = document.getElementById('loadFilesBtn');
    
    // Загрузка файлов при выборе устройства
    loadFilesBtn.addEventListener('click', function() {
        const deviceId = deviceSelect.value;
        if (!deviceId) {
            alert('Пожалуйста, выберите устройство');
            return;
        }
        
        window.location.href = 'files.php?device_id=' + encodeURIComponent(deviceId);
    });
    
    // Обработка кнопок просмотра и удаления
    document.querySelectorAll('.view-file').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const fileName = this.dataset.file;
            const deviceId = this.dataset.device;
            alert('Просмотр файла: ' + fileName + ' на устройстве ' + deviceId);
            // Здесь можно добавить логику просмотра содержимого файла
        });
    });
    
    document.querySelectorAll('.delete-file').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const fileName = this.dataset.file;
            const deviceId = this.dataset.device;
            
            // Check if file is a system file (*.json)
            if (fileName.endsWith('.json')) {
                alert('Нельзя удалить системный файл (*.json)');
                return;
            }
            
            if (confirm('Вы уверены, что хотите удалить файл ' + fileName + '?')) {
                // Здесь можно добавить логику удаления файла
                alert('Удаление файла: ' + fileName + ' на устройстве ' + deviceId);
            }
        });
    });
});

// Форматирование размера файла
function formatSize(bytes) {
    if (bytes === 0) return '0 Б';
    const k = 1024;
    const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
}
</script>

<?php require_once __DIR__ . '/template/footer.php'; ?>
