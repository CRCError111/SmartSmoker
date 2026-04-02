<?php
/**
 * Просмотр логов администратора
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

AdminAuth::requireAdmin();

$user = Auth::user();
$db = db();

// Фильтры
$adminFilter = $_GET['admin_id'] ?? 'all';
$actionFilter = $_GET['action'] ?? 'all';
$targetFilter = $_GET['target_type'] ?? 'all';
$dateFilter = $_GET['date'] ?? 'all';
$limit = (int)($_GET['limit'] ?? 50);

// Построение SQL запроса
$sql = 'SELECT 
    al.*,
    u.full_name as admin_name,
    u.email as admin_email
FROM admin_logs al
JOIN users u ON u.id = al.admin_id
WHERE 1=1';

$params = [];

if ($adminFilter !== 'all') {
    $sql .= ' AND al.admin_id = ?';
    $params[] = (int)$adminFilter;
}

if ($actionFilter !== 'all') {
    $sql .= ' AND al.action = ?';
    $params[] = $actionFilter;
}

if ($targetFilter !== 'all') {
    $sql .= ' AND al.target_type = ?';
    $params[] = $targetFilter;
}

if ($dateFilter !== 'all') {
    switch ($dateFilter) {
        case 'today':
            $sql .= ' AND DATE(al.created_at) = CURDATE()';
            break;
        case 'week':
            $sql .= ' AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)';
            break;
        case 'month':
            $sql .= ' AND al.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
            break;
    }
}

$sql .= ' ORDER BY al.created_at DESC LIMIT ?';
$params[] = $limit;

try {
    $logs = $db->fetchAll($sql, $params);
    
    // Статистика
    $totalLogs = $db->fetchColumn('SELECT COUNT(*) FROM admin_logs');
    $todayLogs = $db->fetchColumn('SELECT COUNT(*) FROM admin_logs WHERE DATE(created_at) = CURDATE()');
    $weekLogs = $db->fetchColumn('SELECT COUNT(*) FROM admin_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
    
    // Список админов для фильтра
    $admins = $db->fetchAll(
        'SELECT u.id, u.full_name, u.email, COUNT(al.id) as logs_count
         FROM users u
         LEFT JOIN admin_logs al ON al.admin_id = u.id
         WHERE u.role = "admin"
         GROUP BY u.id
         ORDER BY u.full_name'
    );
    
    // Типы действий
    $actions = $db->fetchAll(
        'SELECT action, COUNT(*) as count
         FROM admin_logs
         GROUP BY action
         ORDER BY count DESC'
    );
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки логов: ' . $e->getMessage();
    $logs = [];
    $totalLogs = 0;
    $todayLogs = 0;
    $weekLogs = 0;
    $admins = [];
    $actions = [];
}

$pageTitle = 'Логи администратора';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .log-item {
        padding: 15px;
        border-bottom: 1px solid #eee;
        transition: background 0.2s;
    }
    
    .log-item:hover {
        background: #f8f9fa;
    }
    
    .log-item:last-child {
        border-bottom: none;
    }
    
    .action-badge {
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 0.75rem;
        font-weight: 600;
        font-family: monospace;
    }
    
    .action-badge.user { background: #e3f2fd; color: #1565c0; }
    .action-badge.device { background: #f3e5f5; color: #7b1fa2; }
    .action-badge.template { background: #e8f5e8; color: #2e7d32; }
    .action-badge.program { background: #fff3e0; color: #ef6c00; }
    .action-badge.firmware { background: #e0f2f1; color: #00695c; }
    .action-badge.system { background: #fce4ec; color: #c2185b; }
    
    .details-json {
        background: #f8f9fa;
        border-radius: 6px;
        padding: 10px;
        font-family: monospace;
        font-size: 0.85rem;
        max-height: 200px;
        overflow-y: auto;
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
</style>

<?php if (isset($error)): ?>
<div class="alert alert-danger">
    ⚠️ <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">📝</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= number_format($totalLogs) ?></div>
                <div style="font-size: 0.85rem; color: #666;">Всего записей</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">📅</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $todayLogs ?></div>
                <div style="font-size: 0.85rem; color: #666;">Сегодня</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">📊</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $weekLogs ?></div>
                <div style="font-size: 0.85rem; color: #666;">За неделю</div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры -->
<div class="filter-card">
    <form method="GET" action="" class="row g-3">
        <div class="col-md-2">
            <label class="form-label">Администратор</label>
            <select name="admin_id" class="form-select">
                <option value="all" <?= $adminFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <?php foreach ($admins as $admin): ?>
                    <option value="<?= $admin['id'] ?>" <?= $adminFilter == $admin['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($admin['full_name']) ?> (<?= $admin['logs_count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-2">
            <label class="form-label">Действие</label>
            <select name="action" class="form-select">
                <option value="all" <?= $actionFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <?php foreach ($actions as $action): ?>
                    <option value="<?= $action['action'] ?>" <?= $actionFilter === $action['action'] ? 'selected' : '' ?>>
                        <?= $action['action'] ?> (<?= $action['count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-2">
            <label class="form-label">Тип</label>
            <select name="target_type" class="form-select">
                <option value="all" <?= $targetFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="user" <?= $targetFilter === 'user' ? 'selected' : '' ?>>Пользователи</option>
                <option value="device" <?= $targetFilter === 'device' ? 'selected' : '' ?>>Устройства</option>
                <option value="template" <?= $targetFilter === 'template' ? 'selected' : '' ?>>Шаблоны</option>
                <option value="program" <?= $targetFilter === 'program' ? 'selected' : '' ?>>Программы</option>
                <option value="firmware" <?= $targetFilter === 'firmware' ? 'selected' : '' ?>>Прошивки</option>
                <option value="system" <?= $targetFilter === 'system' ? 'selected' : '' ?>>Система</option>
            </select>
        </div>
        
        <div class="col-md-2">
            <label class="form-label">Период</label>
            <select name="date" class="form-select">
                <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>Все время</option>
                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Сегодня</option>
                <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>Неделя</option>
                <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>Месяц</option>
            </select>
        </div>
        
        <div class="col-md-2">
            <label class="form-label">Лимит</label>
            <select name="limit" class="form-select">
                <option value="50" <?= $limit === 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $limit === 100 ? 'selected' : '' ?>>100</option>
                <option value="200" <?= $limit === 200 ? 'selected' : '' ?>>200</option>
                <option value="500" <?= $limit === 500 ? 'selected' : '' ?>>500</option>
            </select>
        </div>
        
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">🔍 Найти</button>
        </div>
    </form>
</div>

<!-- Кнопка назад -->
<div class="mb-3">
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">
        ⬅️ Назад
    </a>
</div>

<!-- Логи -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">📝 Логи администратора (<?= count($logs) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="p-4 text-center text-muted">
                Логи не найдены
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-item">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="action-badge <?= $log['target_type'] ?>">
                                    <?= htmlspecialchars($log['action']) ?>
                                </span>
                                <span class="badge bg-secondary">
                                    <?= htmlspecialchars($log['target_type']) ?>
                                </span>
                                <?php if ($log['target_id']): ?>
                                    <span class="text-muted small">
                                        ID: <?= $log['target_id'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mb-2">
                                <strong>Администратор:</strong> <?= htmlspecialchars($log['admin_name']) ?>
                                <span class="text-muted">(<?= htmlspecialchars($log['admin_email']) ?>)</span>
                            </div>
                            
                            <?php if ($log['details']): ?>
                                <?php
                                $details = json_decode($log['details'], true);
                                $readableDetails = [];
                                if (is_array($details)) {
                                    $labels = [
                                        'name'       => 'Имя',
                                        'email'      => 'Email',
                                        'role'       => 'Роль',
                                        'status'     => 'Статус',
                                        'device_id'  => 'ID устройства',
                                        'device_name'=> 'Устройство',
                                        'user_id'    => 'ID пользователя',
                                        'user_name'  => 'Пользователь',
                                        'version'    => 'Версия',
                                        'filename'   => 'Файл',
                                        'reason'     => 'Причина',
                                        'ip'         => 'IP',
                                        'changes'    => 'Изменения',
                                        'old_value'  => 'Было',
                                        'new_value'  => 'Стало',
                                        'message'    => 'Сообщение',
                                        'error'      => 'Ошибка',
                                    ];
                                    foreach ($details as $k => $v) {
                                        $label = $labels[$k] ?? $k;
                                        if (is_array($v)) {
                                            $v = implode(', ', array_map(fn($kk, $vv) => "$kk: $vv", array_keys($v), $v));
                                        }
                                        $readableDetails[] = '<span style="color:var(--color-gray-500)">' . htmlspecialchars($label) . ':</span> ' . htmlspecialchars((string)$v);
                                    }
                                }
                                ?>
                                <?php if ($readableDetails): ?>
                                <div class="mb-2" style="font-size:13px;line-height:1.7">
                                    <?= implode(' &nbsp;·&nbsp; ', $readableDetails) ?>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-md-4 text-end">
                            <div class="text-muted small mb-1">
                                <strong>IP:</strong> <?= htmlspecialchars($log['ip_address']) ?>
                            </div>
                            <div class="text-muted small">
                                <strong>Время:</strong><br>
                                <?= formatDate($log['created_at'], true) ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <?php if (count($logs) >= $limit): ?>
        <div class="card-footer text-center">
            <div class="alert alert-info mb-0">
                Показано <?= $limit ?> записей. Используйте фильтры для уточнения поиска.
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../templates/footer.php'; ?>
