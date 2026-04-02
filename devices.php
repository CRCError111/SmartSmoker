<?php
/**
 * Страница списка устройств
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

// Требуется авторизация
Auth::requireAuth();

// Отключаем кеширование для динамической страницы
disableCache();

// Получение данных пользователя
$user = Auth::user();
$userId = $user['id'];

// Получение списка устройств пользователя
$db = db();
$devices = $db->fetchAll(
    'SELECT * FROM devices WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) ORDER BY created_at DESC',
    [$userId]
);

// Обработка удаления устройства
if (isMethod('POST') && isset($_POST['action']) && $_POST['action'] === 'delete') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        jsonError('Неверный токен безопасности');
    }
    
    $deviceId = (int)$_POST['device_id'];
    
    // Проверка принадлежности устройства
    if (!checkDeviceOwnership($deviceId)) {
        jsonError('Устройство не принадлежит вам');
    }
    
    // Получение информации об устройстве для проверки статуса
    $device = $db->fetchOne(
        'SELECT * FROM devices WHERE id = ? AND user_id = ?',
        [$deviceId, $userId]
    );
    
    if (!$device) {
        jsonError('Устройство не найдено');
    }
    
    // КРИТИЧЕСКАЯ ПРОВЕРКА: Запрет удаления устройства в статусе "отвязывается"
    if ($device['unbound'] == 1) {
        jsonError('Невозможно удалить устройство в статусе "Отвязывается". Дождитесь завершения процесса отвязки (до 5 минут), затем попробуйте снова.');
    }
    
    // Удаление связанных программ
    $db->delete('programs', 'device_id = ?', [$deviceId]);
    
    // Удаление связанных запусков
    $db->delete('runs', 'device_id = ?', [$deviceId]);
    
    // Удаление устройства
    $db->delete('devices', 'id = ? AND user_id = ?', [$deviceId, $userId]);
    
    logInfo("Устройство #$deviceId удалено", 'DEVICES');
    jsonSuccess(null, 'Устройство успешно удалено');
}

// Обработка отвязки устройства
if (isMethod('POST') && isset($_POST['action']) && $_POST['action'] === 'unbind') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        jsonError('Неверный токен безопасности');
    }
    
    $deviceId = (int)$_POST['device_id'];
    
    // Проверка принадлежности устройства
    if (!checkDeviceOwnership($deviceId)) {
        jsonError('Устройство не принадлежит вам');
    }
    
    // Получение информации об устройстве
    $device = $db->fetchOne(
        'SELECT * FROM devices WHERE id = ? AND user_id = ?',
        [$deviceId, $userId]
    );
    
    if (!$device) {
        jsonError('Устройство не найдено');
    }
    
    // Проверка, что устройство активно
    if ($device['status'] !== 'active') {
        jsonError('Устройство уже неактивно или не привязано');
    }
    
    // Вызов API для отвязки устройства
    $apiUrl = BASE_URL . '/api/unbind-device.php';
    $payload = json_encode([
        'device_id' => $device['device_id'],
        'esp32_ip' => $device['ip_address']
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Передача сессии для авторизации
    $cookieFile = tempnam(sys_get_temp_dir(), 'cookie');
    curl_setopt($ch, CURLOPT_COOKIE, session_name() . '=' . session_id());
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode == 200) {
        $responseData = json_decode($response, true);
        if ($responseData && $responseData['success']) {
            logInfo("Устройство #$deviceId отвязано через API", 'DEVICES');
            jsonSuccess(['notification_sent' => $responseData['notification_sent'] ?? false], 'Устройство успешно отвязано');
        } else {
            jsonError('Ошибка при отвязке устройства: ' . ($responseData['error'] ?? 'Неизвестная ошибка'));
        }
    } else {
        jsonError('Ошибка API при отвязке устройства: HTTP ' . $httpCode);
    }
}

$pageTitle = 'Устройства';
include __DIR__ . '/templates/header.php';
?>

<style>
.device-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
    padding: 20px;
    transition: all 0.3s ease;
}

.device-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,.15);
}

.device-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.device-name {
    font-size: 1.3rem;
    font-weight: 700;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.device-status {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.device-status.bg-success {
    background: linear-gradient(135deg, #11998e, #38ef7d);
    color: white;
}

.device-status.bg-warning {
    background: linear-gradient(135deg, #f093fb, #f5576c);
    color: white;
}

.device-status.bg-secondary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.device-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.info-item {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.info-label {
    font-size: 0.85rem;
    color: #666;
    font-weight: 500;
}

.info-value {
    font-size: 1rem;
    color: #333;
    font-weight: 600;
}

.device-stats {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    padding: 15px;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05), rgba(118, 75, 162, 0.05));
    border-radius: 8px;
}

.stat-item {
    text-align: center;
    flex: 1;
}

.stat-value {
    font-size: 1.8rem;
    font-weight: 700;
    background: linear-gradient(135deg, #667eea, #764ba2);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

.stat-label {
    font-size: 0.85rem;
    color: #666;
    margin-top: 5px;
}

.device-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
}

.empty-state i {
    font-size: 4rem;
    color: #ddd;
    margin-bottom: 20px;
}

.empty-state p {
    font-size: 1.2rem;
    color: #666;
    margin-bottom: 20px;
}
</style>

<!-- Add Device Button -->
<div class="mb-4">
    <a href="<?= BASE_URL ?>/add-device.php" class="btn btn-primary">
        ➕ Добавить устройство
    </a>
</div>

<!-- Devices List -->
<?php if (empty($devices)): ?>
<div class="empty-state">
    <p style="font-size:3rem;margin-bottom:20px">📦</p>
    <p>У вас пока нет устройств</p>
    <a href="<?= BASE_URL ?>/add-device.php" class="btn btn-primary">
        ➕ Добавить первое устройство
    </a>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($devices as $device): ?>
    <?php 
        $statusInfo = formatDeviceStatus($device['status']);
        $deviceUuid = $device['device_id'];
    ?>
    <div class="col-12 mb-3">
        <div class="device-card">
            <div class="device-header">
                <div>
                    <div class="device-name">
                        🖥️ <?= e($device['name']) ?>
                    </div>
                    <div class="text-muted small mt-1">
                        🔑 ID: <?= $deviceUuid ?>
                    </div>
                </div>
                <span class="device-status <?= $statusInfo[1] ?>">
                    <?= $statusInfo[0] ?>
                </span>
            </div>
            
            <?php if ($device['description']): ?>
            <div class="alert alert-info mb-3">
                ℹ️ <?= e($device['description']) ?>
            </div>
            <?php endif; ?>
            
            <div class="device-info">
                <div class="info-item">
                    <span class="info-label">📡 Статус</span>
                    <span class="info-value"><?= $statusInfo[0] ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">🕐 Последняя активность</span>
                    <span class="info-value">
                        <?= $device['last_seen'] ? formatDate($device['last_seen']) : 'Никогда' ?>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">🌐 IP адрес</span>
                    <span class="info-value"><?= $device['ip_address'] ?? '—' ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">📅 Дата добавления</span>
                    <span class="info-value"><?= formatDate($device['created_at']) ?></span>
                </div>
            </div>
            
            <div class="device-stats">
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $programCount = $db->fetchColumn(
                            'SELECT COUNT(*) FROM programs WHERE device_id = ?',
                            [$device['id']]
                        );
                        echo $programCount;
                        ?>
                    </div>
                    <div class="stat-label">📋 Программ</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $runCount = $db->fetchColumn(
                            'SELECT COUNT(*) FROM runs WHERE device_id = ?',
                            [$device['device_id']]
                        );
                        echo $runCount;
                        ?>
                    </div>
                    <div class="stat-label">▶️ Запусков</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value">
                        <?php 
                        $activeRun = $db->fetchColumn(
                            'SELECT COUNT(*) FROM runs WHERE device_id = ? AND status = "running"',
                            [$device['device_id']]
                        );
                        echo $activeRun > 0 ? 'Да' : 'Нет';
                        ?>
                    </div>
                    <div class="stat-label">🔥 В работе</div>
                </div>
            </div>
            
            <div class="device-actions">
                <a href="<?= BASE_URL ?>/view-device.php?id=<?= $device['id'] ?>" class="btn btn-primary btn-sm">
                    👁️ Просмотр
                </a>
                <a href="<?= BASE_URL ?>/edit-device.php?id=<?= $device['id'] ?>" class="btn btn-outline-primary btn-sm">
                    ✏️ Редактировать
                </a>
                <?php if ($device['status'] === 'active'): ?>
                <?php if (!$device['unbound']): ?>
                <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#unbindModal<?= $device['id'] ?>">
                    🔓 Отвязать
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-warning btn-sm" disabled title="Устройство отвязывается...">
                    ⏳ Отвязывается...
                </button>
                <?php endif; ?>
                <?php elseif ($device['status'] === 'inactive'): ?>
                <a href="<?= BASE_URL ?>/bind-device.php?id=<?= $device['id'] ?>" class="btn btn-outline-primary btn-sm">
                    🔗 Привязать
                </a>
                <?php endif; ?>
                <?php if ($device['unbound']): ?>
                <button type="button" class="btn btn-secondary btn-sm" disabled title="Невозможно удалить устройство в статусе 'Отвязывается'. Дождитесь завершения процесса (до 5 минут).">
                    🗑️ Удалить (недоступно)
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $device['id'] ?>">
                    🗑️ Удалить
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Unbind Device Modal -->
    <div class="modal fade" id="unbindModal<?= $device['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">🔓 Отвязать устройство</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Вы уверены, что хотите отвязать устройство "<strong><?= e($device['name']) ?></strong>"?</p>
                    <p class="text-warning"><strong>⚠️ Внимание:</strong> После отвязки устройство перестанет отправлять телеметрию, но останется в вашем списке устройств.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="button" class="btn btn-warning" onclick="unbindDevice(<?= $device['id'] ?>, '<?= e($device['name']) ?>')">
                        🔓 Отвязать устройство
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Delete Device Modal -->
    <div class="modal fade" id="deleteModal<?= $device['id'] ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">⚠️ Подтверждение удаления</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Вы уверены, что хотите удалить устройство "<strong><?= e($device['name']) ?></strong>"?</p>
                    <p class="text-danger"><strong>⚠️ Внимание:</strong> Будут удалены все связанные программы и история запусков!</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <form method="POST" action="" id="deleteForm<?= $device['id'] ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="device_id" value="<?= $device['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <button type="submit" class="btn btn-danger">
                            🗑️ Удалить
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
// AJAX удаление устройства
document.querySelectorAll('form[id^="deleteForm"]').forEach(form => {
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!confirm('Вы уверены? Все связанные данные будут удалены!')) {
            return;
        }
        
        const formData = new FormData(this);
        
        fetch('<?= BASE_URL ?>/devices.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(data.message);
                location.reload();
            } else {
                alert('Ошибка: ' + data.error);
            }
        })
        .catch(error => {
            alert('Произошла ошибка при удалении');
        });
    });
});

// Функция для отвязки устройства
function unbindDevice(deviceId, deviceName) {
    if (!confirm(`Вы уверены, что хотите отвязать устройство "${deviceName}"?`)) {
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'unbind');
    formData.append('device_id', deviceId);
    formData.append('csrf_token', '<?= csrfToken() ?>');
    
    fetch('<?= BASE_URL ?>/devices.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Произошла ошибка при отвязке устройства');
    });
}
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>