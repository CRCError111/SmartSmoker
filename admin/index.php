<?php
/**
 * Главная страница админ-панели
 * Статистика и обзор системы
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

// Получение статистики
try {
    // Пользователи
    $totalUsers = $db->fetchColumn('SELECT COUNT(*) FROM users');
    $adminUsers = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE role = "admin"');
    $blockedUsers = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE is_blocked = 1');
    $newUsersWeek = $db->fetchColumn(
        'SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)'
    );
    
    // Устройства
    $totalDevices = $db->fetchColumn('SELECT COUNT(*) FROM devices');
    $activeDevices = $db->fetchColumn(
        'SELECT COUNT(*) FROM devices WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $pendingDevices = $db->fetchColumn('SELECT COUNT(*) FROM devices WHERE status = "pending"');
    
    // Программы
    $totalPrograms = $db->fetchColumn('SELECT COUNT(*) FROM programs');
    $userPrograms = $db->fetchColumn('SELECT COUNT(*) FROM programs WHERE is_built_in = 0');
    $builtInPrograms = $db->fetchColumn('SELECT COUNT(*) FROM programs WHERE is_built_in = 1');
    
    // Шаблоны
    $totalTemplates = $db->fetchColumn('SELECT COUNT(*) FROM templates');
    $publicTemplates = $db->fetchColumn('SELECT COUNT(*) FROM templates WHERE is_public = 1');
    
    // Запуски
    $totalRuns = $db->fetchColumn('SELECT COUNT(*) FROM runs');
    $runningNow = $db->fetchColumn('SELECT COUNT(*) FROM runs WHERE status = "running"');
    $runsToday = $db->fetchColumn(
        'SELECT COUNT(*) FROM runs WHERE DATE(started_at) = CURDATE()'
    );
    
    // Последние пользователи
    $recentUsers = $db->fetchAll(
        'SELECT id, full_name, email, role, created_at 
         FROM users 
         ORDER BY created_at DESC 
         LIMIT 5'
    );
    
    // Последние действия админов
    $recentAdminActions = $db->fetchAll(
        'SELECT al.*, u.full_name, u.email 
         FROM admin_logs al
         JOIN users u ON u.id = al.admin_id
         ORDER BY al.created_at DESC 
         LIMIT 10'
    );
    
    // Активные устройства
    $activeDevicesList = $db->fetchAll(
        'SELECT d.id, d.name, d.ip_address, d.last_seen, u.full_name as owner
         FROM devices d
         JOIN users u ON u.id = d.user_id
         WHERE d.last_seen >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
         ORDER BY d.last_seen DESC
         LIMIT 5'
    );
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки статистики: ' . $e->getMessage();
}

$pageTitle = 'Панель администратора';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 2px 8px rgba(0,0,0,.1);
        transition: all 0.3s;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
    }
    
    .stat-icon {
        font-size: 2.5rem;
        margin-bottom: 10px;
    }
    
    .stat-value {
        font-size: 2.5rem;
        font-weight: 700;
        color: #333;
        margin-bottom: 5px;
    }
    
    .stat-label {
        font-size: 0.9rem;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-change {
        font-size: 0.85rem;
        margin-top: 8px;
    }
    
    .stat-change.positive {
        color: #28a745;
    }
    
    .stat-change.negative {
        color: #dc3545;
    }
    
    .admin-badge {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .activity-item {
        padding: 15px;
        border-bottom: 1px solid #eee;
        transition: background 0.2s;
    }
    
    .activity-item:hover {
        background: #f8f9fa;
    }
    
    .activity-item:last-child {
        border-bottom: none;
    }
    
    .activity-time {
        font-size: 0.85rem;
        color: #999;
    }
</style>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    ⚠️ <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Приветствие -->
<div class="alert alert-info mb-4">
    <h5 class="mb-2">👋 Добро пожаловать, <?= htmlspecialchars($user['full_name']) ?>!</h5>
    <p class="mb-0">Вы вошли как <span class="admin-badge">АДМИНИСТРАТОР</span></p>
</div>

<!-- Основная статистика -->
<h4 class="mb-3">📊 Общая статистика</h4>
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?= $totalUsers ?></div>
            <div class="stat-label">Всего пользователей</div>
            <div class="stat-change positive">
                +<?= $newUsersWeek ?> за неделю
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">🖥️</div>
            <div class="stat-value"><?= $totalDevices ?></div>
            <div class="stat-label">Всего устройств</div>
            <div class="stat-change">
                <?= $activeDevices ?> активных
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">📋</div>
            <div class="stat-value"><?= $totalPrograms ?></div>
            <div class="stat-label">Всего программ</div>
            <div class="stat-change">
                <?= $userPrograms ?> пользовательских
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">▶️</div>
            <div class="stat-value"><?= $runningNow ?></div>
            <div class="stat-label">Запущено сейчас</div>
            <div class="stat-change">
                <?= $runsToday ?> запусков сегодня
            </div>
        </div>
    </div>
</div>

<!-- Дополнительная статистика -->
<div class="row mb-4">
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">🔐</div>
            <div class="stat-value"><?= $adminUsers ?></div>
            <div class="stat-label">Администраторов</div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">🚫</div>
            <div class="stat-value"><?= $blockedUsers ?></div>
            <div class="stat-label">Заблокировано</div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">📄</div>
            <div class="stat-value"><?= $totalTemplates ?></div>
            <div class="stat-label">Шаблонов</div>
            <div class="stat-change">
                <?= $publicTemplates ?> публичных
            </div>
        </div>
    </div>
    
    <div class="col-md-3 mb-3">
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?= $pendingDevices ?></div>
            <div class="stat-label">Ожидают привязки</div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Последние пользователи -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">👥 Последние пользователи</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentUsers)): ?>
                    <div class="p-3 text-center text-muted">
                        Нет пользователей
                    </div>
                <?php else: ?>
                    <?php foreach ($recentUsers as $u): ?>
                        <div class="activity-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?= htmlspecialchars($u['full_name']) ?></strong>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="admin-badge ms-2">ADMIN</span>
                                    <?php endif; ?>
                                    <div class="text-muted small"><?= htmlspecialchars($u['email']) ?></div>
                                </div>
                                <div class="activity-time">
                                    <?= formatDate($u['created_at'], true) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card-footer text-center">
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-sm btn-primary">
                    Все пользователи →
                </a>
            </div>
        </div>
    </div>
    
    <!-- Активные устройства -->
    <div class="col-md-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">🖥️ Активные устройства</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activeDevicesList)): ?>
                    <div class="p-3 text-center text-muted">
                        Нет активных устройств
                    </div>
                <?php else: ?>
                    <?php foreach ($activeDevicesList as $d): ?>
                        <div class="activity-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <strong><?= htmlspecialchars($d['name']) ?></strong>
                                    <div class="text-muted small">
                                        Владелец: <?= htmlspecialchars($d['owner']) ?>
                                    </div>
                                    <div class="text-muted small">
                                        IP: <?= htmlspecialchars($d['ip_address'] ?? 'не указан') ?>
                                    </div>
                                </div>
                                <div class="activity-time">
                                    <?= formatDate($d['last_seen'], true) ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="card-footer text-center">
                <a href="<?= BASE_URL ?>/admin/devices.php" class="btn btn-sm btn-primary">
                    Все устройства →
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Последние действия админов -->
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0">📝 Последние действия администраторов</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($recentAdminActions)): ?>
            <div class="p-3 text-center text-muted">
                Нет записей
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Администратор</th>
                            <th>Действие</th>
                            <th>Тип</th>
                            <th>ID цели</th>
                            <th>IP</th>
                            <th>Время</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentAdminActions as $log): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($log['full_name']) ?></strong>
                                    <div class="text-muted small"><?= htmlspecialchars($log['email']) ?></div>
                                </td>
                                <td><code><?= htmlspecialchars($log['action']) ?></code></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($log['target_type']) ?></span></td>
                                <td><?= $log['target_id'] ?? '—' ?></td>
                                <td class="text-muted small"><?= htmlspecialchars($log['ip_address']) ?></td>
                                <td class="text-muted small"><?= formatDate($log['created_at'], true) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-footer text-center">
        <a href="<?= BASE_URL ?>/admin/logs.php" class="btn btn-sm btn-primary">
            Все логи →
        </a>
    </div>
</div>

<!-- Быстрые действия -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">⚡ Быстрые действия</h5>
    </div>
    <div class="card-body">
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline-primary">
                👥 Управление пользователями
            </a>
            <a href="<?= BASE_URL ?>/admin/devices.php" class="btn btn-outline-primary">
                🖥️ Все устройства
            </a>
            <a href="<?= BASE_URL ?>/admin/files.php" class="btn btn-outline-primary">
                📁 Файлы на устройствах
            </a>
            <a href="<?= BASE_URL ?>/admin/templates.php" class="btn btn-outline-primary">
                📋 Шаблоны программ
            </a>
            <a href="<?= BASE_URL ?>/admin/firmware.php" class="btn btn-outline-primary">
                📦 Управление прошивками
            </a>
            <a href="<?= BASE_URL ?>/admin/transfer-queue.php" class="btn btn-outline-primary">
                📤 Очередь передачи программ
            </a>
            <a href="<?= BASE_URL ?>/admin/logs.php" class="btn btn-outline-secondary">
                📝 Просмотр логов
            </a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
