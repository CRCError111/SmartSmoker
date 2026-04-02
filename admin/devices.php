<?php
/**
 * Управление всеми устройствами
 * Список устройств всех пользователей
 * 
 * @version 1.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/admin-auth.php';

// Требуется авторизация администратора
AdminAuth::requireAdmin();

$user = Auth::user();
$db = db();

// Фильтры
$userFilter = $_GET['user_id'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Построение SQL запроса
$sql = 'SELECT 
    d.*,
    u.full_name as owner_name,
    u.email as owner_email,
    (SELECT COUNT(*) FROM programs p WHERE p.device_id = d.id) as programs_count,
    (SELECT COUNT(*) FROM runs r WHERE r.device_id = d.device_id) as runs_count,
    (SELECT COUNT(*) FROM sensor_data sd WHERE sd.device_id = d.device_id) as data_count,
    (SELECT COUNT(*) FROM runs r WHERE r.device_id = d.device_id AND r.status = "running") as active_runs,
    d.unbound,
    d.api_token
FROM devices d
JOIN users u ON u.id = d.user_id
WHERE 1=1';

$params = [];

if ($userFilter !== 'all') {
    $sql .= ' AND d.user_id = ?';
    $params[] = (int)$userFilter;
}

if ($statusFilter !== 'all') {
    $sql .= ' AND d.status = ?';
    $params[] = $statusFilter;
}

if (!empty($searchQuery)) {
    $sql .= ' AND (d.name LIKE ? OR d.device_id LIKE ? OR d.ip_address LIKE ?)';
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= ' ORDER BY d.last_seen DESC';

try {
    $devices = $db->fetchAll($sql, $params);
    
    // Статистика
    $totalDevices = $db->fetchColumn('SELECT COUNT(*) FROM devices');
    $activeDevices = $db->fetchColumn(
        'SELECT COUNT(*) FROM devices WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $pendingDevices = $db->fetchColumn('SELECT COUNT(*) FROM devices WHERE status = "pending"');
    $offlineDevices = $db->fetchColumn(
        'SELECT COUNT(*) FROM devices WHERE last_seen < DATE_SUB(NOW(), INTERVAL 1 HOUR) OR last_seen IS NULL'
    );
    
    // Список пользователей для фильтра
    $users = $db->fetchAll(
        'SELECT u.id, u.full_name, u.email, COUNT(d.id) as devices_count
         FROM users u
         LEFT JOIN devices d ON d.user_id = u.id
         GROUP BY u.id
         HAVING devices_count > 0
         ORDER BY u.full_name'
    );
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки устройств: ' . $e->getMessage();
    $devices = [];
}

$pageTitle = 'Управление устройствами';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .device-status-badge {
        padding: 6px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .device-status-badge.active {
        background: #d4edda;
        color: #155724;
    }
    
    .device-status-badge.pending {
        background: #fff3cd;
        color: #856404;
    }
    
    .device-status-badge.offline {
        background: #f8d7da;
        color: #721c24;
    }
    
    .device-status-badge.unbound {
        background: #e2e3e5;
        color: #383d41;
    }
    
    .filter-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,.1);
        margin-bottom: 20px;
    }
    
    .stat-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 8px 16px;
        background: #f8f9fa;
        border-radius: 8px;
        font-weight: 500;
    }
    
    .device-id-short {
        font-family: monospace;
        font-size: 0.85rem;
        color: #666;
    }
</style>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    ⚠️ <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
    <?php if ($_GET['success'] === 'device_deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Устройство успешно удалено!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'device_unbound'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Устройство будет отвязано при следующем опросе (до 5 минут)
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">🖥️</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalDevices ?></div>
                <div style="font-size: 0.85rem; color: #666;">Всего устройств</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">✅</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $activeDevices ?></div>
                <div style="font-size: 0.85rem; color: #666;">Активных</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">⏳</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $pendingDevices ?></div>
                <div style="font-size: 0.85rem; color: #666;">Ожидают привязки</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">📴</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $offlineDevices ?></div>
                <div style="font-size: 0.85rem; color: #666;">Оффлайн</div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры и поиск -->
<div class="filter-card">
    <form method="GET" action="" class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Пользователь</label>
            <select name="user_id" class="form-select">
                <option value="all" <?= $userFilter === 'all' ? 'selected' : '' ?>>Все пользователи</option>
                <?php foreach ($users as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $userFilter == $u['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($u['full_name']) ?> (<?= $u['devices_count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-3">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                <option value="pending" <?= $statusFilter === 'pending' ? 'selected' : '' ?>>Ожидают</option>
                <option value="offline" <?= $statusFilter === 'offline' ? 'selected' : '' ?>>Оффлайн</option>
            </select>
        </div>
        
        <div class="col-md-4">
            <label class="form-label">Поиск</label>
            <input type="text" 
                   name="search" 
                   class="form-control" 
                   placeholder="Название, Device ID, IP..." 
                   value="<?= htmlspecialchars($searchQuery) ?>">
        </div>
        
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">🔍 Найти</button>
        </div>
    </form>
</div>

<!-- Список устройств -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">🖥️ Устройства (<?= count($devices) ?>)</h5>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">
            ⬅️ Назад
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($devices)): ?>
            <div class="p-4 text-center text-muted">
                Устройства не найдены
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Владелец</th>
                            <th>Device ID</th>
                            <th>IP адрес</th>
                            <th>Статус</th>
                            <th>Привязка</th>
                            <th>Программ</th>
                            <th>Запусков</th>
                            <th>Данных</th>
                            <th>Последняя активность</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $d): ?>
                            <?php
                            // Определение статуса
                            $isActive = $d['last_seen'] && strtotime($d['last_seen']) > strtotime('-1 hour');
                            $statusClass = $d['status'] === 'pending' ? 'pending' : ($isActive ? 'active' : 'offline');
                            $statusText = $d['status'] === 'pending' ? 'Ожидает' : ($isActive ? 'Активен' : 'Оффлайн');
                            
                            // Определение статуса привязки
                            $isBound = !empty($d['api_token']) && !$d['unbound'];
                            $bindingStatusClass = $d['unbound'] ? 'unbound' : ($isBound ? 'active' : 'pending');
                            $bindingStatusText = $d['unbound'] ? 'Отвязывается' : ($isBound ? 'Привязано' : 'Не привязано');
                            ?>
                            <tr>
                                <td><?= $d['id'] ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($d['name']) ?></strong>
                                    <?php if ($d['active_runs'] > 0): ?>
                                        <span class="badge bg-success ms-1">▶️ Работает</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= BASE_URL ?>/admin/user-edit.php?id=<?= $d['user_id'] ?>">
                                        <?= htmlspecialchars($d['owner_name']) ?>
                                    </a>
                                    <div class="text-muted small"><?= htmlspecialchars($d['owner_email']) ?></div>
                                </td>
                                <td>
                                    <span class="device-id-short" title="<?= htmlspecialchars($d['device_id']) ?>">
                                        <?= substr($d['device_id'], 0, 8) ?>...
                                    </span>
                                </td>
                                <td class="text-muted small">
                                    <?= htmlspecialchars($d['ip_address'] ?? '—') ?>
                                </td>
                                <td>
                                    <span class="device-status-badge <?= $statusClass ?>">
                                        <?= $statusText ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="device-status-badge <?= $bindingStatusClass ?>">
                                        <?= $bindingStatusText ?>
                                    </span>
                                </td>
                                <td><?= $d['programs_count'] ?></td>
                                <td><?= $d['runs_count'] ?></td>
                                <td><?= number_format($d['data_count']) ?></td>
                                <td class="text-muted small">
                                    <?= $d['last_seen'] ? formatDate($d['last_seen'], true) : 'Никогда' ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= BASE_URL ?>/view-device.php?id=<?= $d['id'] ?>" 
                                           class="btn btn-outline-primary" 
                                           title="Просмотр"
                                           target="_blank">
                                            👁️
                                        </a>
                                        <a href="<?= BASE_URL ?>/admin/files.php?device_id=<?= htmlspecialchars($d['device_id']) ?>" 
                                           class="btn btn-outline-success" 
                                           title="Файлы на устройстве"
                                           target="_blank">
                                            📁
                                        </a>
                                        <a href="<?= BASE_URL ?>/edit-device.php?id=<?= $d['id'] ?>" 
                                           class="btn btn-outline-secondary" 
                                           title="Редактировать"
                                           target="_blank">
                                            ✏️
                                        </a>
                                        <?php if ($isBound && !$d['unbound']): ?>
                                        <button type="button" 
                                                class="btn btn-outline-warning" 
                                                onclick="unbindDevice('<?= htmlspecialchars($d['device_id'], ENT_QUOTES) ?>', '<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>')"
                                                title="Отвязать устройство">
                                            🔓
                                        </button>
                                        <?php endif; ?>
                                        <?php if ($d['unbound']): ?>
                                        <button type="button" 
                                                class="btn btn-outline-secondary" 
                                                disabled
                                                title="Невозможно удалить устройство в статусе 'Отвязывается'. Дождитесь завершения процесса (до 5 минут).">
                                            🗑️ (недоступно)
                                        </button>
                                        <?php else: ?>
                                        <button type="button" 
                                                class="btn btn-outline-danger" 
                                                onclick="deleteDevice(<?= $d['id'] ?>, '<?= htmlspecialchars($d['name'], ENT_QUOTES) ?>', <?= $d['unbound'] ? 'true' : 'false' ?>)"
                                                title="Удалить">
                                            🗑️
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function unbindDevice(deviceId, deviceName) {
    if (!confirm(`ВЫ УВЕРЕНЫ?\n\nБудет отвязано устройство: ${deviceName}\n\nУстройство получит уведомление об отвязке при следующем опросе (до 5 минут).\nВсе данные устройства останутся в системе до финальной синхронизации.\n\nПродолжить?`)) {
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/admin/unbind-device.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            device_id: deviceId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=device_unbound';
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function deleteDevice(deviceId, deviceName, isUnbinding) {
    if (isUnbinding) {
        alert('Невозможно удалить устройство в статусе "Отвязывается".\n\nКонтроллер должен завершить процесс отвязки (до 5 минут).\nПосле этого устройство можно будет удалить.');
        return;
    }
    
    if (!confirm(`ВЫ УВЕРЕНЫ?\n\nБудет удалено устройство: ${deviceName}\n\nВместе с ним будут удалены:\n- Все программы устройства\n- Вся история запусков\n- Все данные датчиков\n\nЭто действие нельзя отменить!`)) {
        return;
    }
    
    const confirmation = prompt('Для подтверждения введите название устройства:\n' + deviceName);
    if (confirmation !== deviceName) {
        alert('Удаление отменено - название не совпадает');
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/admin/devices.php', {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            device_id: deviceId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=device_deleted';
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
