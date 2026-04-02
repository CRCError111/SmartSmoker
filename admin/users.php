<?php
/**
 * Управление пользователями
 * Список всех пользователей с возможностью редактирования
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
$roleFilter = $_GET['role'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Построение SQL запроса
$sql = 'SELECT 
    u.*,
    COUNT(DISTINCT d.id) as devices_count,
    COUNT(DISTINCT p.id) as programs_count
FROM users u
LEFT JOIN devices d ON d.user_id = u.id
LEFT JOIN programs p ON p.user_id = u.id
WHERE 1=1';

$params = [];

if ($roleFilter !== 'all') {
    $sql .= ' AND u.role = ?';
    $params[] = $roleFilter;
}

if ($statusFilter === 'blocked') {
    $sql .= ' AND u.is_blocked = 1';
} elseif ($statusFilter === 'active') {
    $sql .= ' AND u.is_blocked = 0';
}

if (!empty($searchQuery)) {
    $sql .= ' AND (u.full_name LIKE ? OR u.email LIKE ?)';
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= ' GROUP BY u.id ORDER BY u.created_at DESC';

try {
    $users = $db->fetchAll($sql, $params);
    
    // Статистика
    $totalUsers = $db->fetchColumn('SELECT COUNT(*) FROM users');
    $adminCount = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE role = "admin"');
    $blockedCount = $db->fetchColumn('SELECT COUNT(*) FROM users WHERE is_blocked = 1');
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки пользователей: ' . $e->getMessage();
    $users = [];
}

$pageTitle = 'Управление пользователями';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 1rem;
    }
    
    .admin-badge {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .blocked-badge {
        background: #dc3545;
        color: white;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
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

<?php if (isset($_GET['success'])): ?>
    <?php if ($_GET['success'] === 'user_updated'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Пользователь успешно обновлён!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'user_deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Пользователь успешно удалён!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'user_blocked'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Пользователь заблокирован!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'user_unblocked'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Пользователь разблокирован!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">👥</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalUsers ?></div>
                <div style="font-size: 0.85rem; color: #666;">Всего пользователей</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">🔐</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $adminCount ?></div>
                <div style="font-size: 0.85rem; color: #666;">Администраторов</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">🚫</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $blockedCount ?></div>
                <div style="font-size: 0.85rem; color: #666;">Заблокировано</div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры и поиск -->
<div class="filter-card">
    <form method="GET" action="" class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Роль</label>
            <select name="role" class="form-select">
                <option value="all" <?= $roleFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="admin" <?= $roleFilter === 'admin' ? 'selected' : '' ?>>Администраторы</option>
                <option value="user" <?= $roleFilter === 'user' ? 'selected' : '' ?>>Пользователи</option>
            </select>
        </div>
        
        <div class="col-md-3">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Активные</option>
                <option value="blocked" <?= $statusFilter === 'blocked' ? 'selected' : '' ?>>Заблокированные</option>
            </select>
        </div>
        
        <div class="col-md-4">
            <label class="form-label">Поиск</label>
            <input type="text" name="search" class="form-control" placeholder="Имя или email..." value="<?= htmlspecialchars($searchQuery) ?>">
        </div>
        
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">🔍 Найти</button>
        </div>
    </form>
</div>

<!-- Список пользователей -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">👥 Пользователи (<?= count($users) ?>)</h5>
        <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-sm btn-outline-secondary">
            ⬅️ Назад
        </a>
    </div>
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
            <div class="p-4 text-center text-muted">
                Пользователи не найдены
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Пользователь</th>
                            <th>Email</th>
                            <th>Роль</th>
                            <th>Статус</th>
                            <th>Устройств</th>
                            <th>Программ</th>
                            <th>Регистрация</th>
                            <th>Последний вход</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td><?= $u['id'] ?></td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="user-avatar">
                                            <?= strtoupper(substr($u['full_name'] ?? $u['email'], 0, 1)) ?>
                                        </div>
                                        <strong><?= htmlspecialchars($u['full_name']) ?></strong>
                                    </div>
                                </td>
                                <td><?= htmlspecialchars($u['email']) ?></td>
                                <td>
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="admin-badge">ADMIN</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">USER</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($u['is_blocked']): ?>
                                        <span class="blocked-badge">ЗАБЛОКИРОВАН</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Активен</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $u['devices_count'] ?></td>
                                <td><?= $u['programs_count'] ?></td>
                                <td class="text-muted small"><?= formatDate($u['created_at']) ?></td>
                                <td class="text-muted small">
                                    <?= $u['last_login'] ? formatDate($u['last_login'], true) : 'Никогда' ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <a href="<?= BASE_URL ?>/admin/user-edit.php?id=<?= $u['id'] ?>" 
                                           class="btn btn-outline-primary" 
                                           title="Редактировать">
                                            ✏️
                                        </a>
                                        <?php if ($u['id'] !== $user['id']): ?>
                                            <?php if ($u['is_blocked']): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-success" 
                                                        onclick="unblockUser(<?= $u['id'] ?>)"
                                                        title="Разблокировать">
                                                    ✅
                                                </button>
                                            <?php else: ?>
                                                <button type="button" 
                                                        class="btn btn-outline-warning" 
                                                        onclick="blockUser(<?= $u['id'] ?>)"
                                                        title="Заблокировать">
                                                    🚫
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($u['role'] !== 'admin' || $adminCount > 1): ?>
                                                <button type="button" 
                                                        class="btn btn-outline-danger" 
                                                        onclick="deleteUser(<?= $u['id'] ?>)"
                                                        title="Удалить">
                                                    🗑️
                                                </button>
                                            <?php endif; ?>
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
function blockUser(userId) {
    const reason = prompt('Укажите причину блокировки:');
    if (!reason) return;
    
    if (!confirm('Заблокировать этого пользователя?')) return;
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('<?= BASE_URL ?>/api/admin/users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            action: 'block',
            user_id: userId,
            reason: reason
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=user_blocked';
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function unblockUser(userId) {
    if (!confirm('Разблокировать этого пользователя?')) return;
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('<?= BASE_URL ?>/api/admin/users.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            action: 'unblock',
            user_id: userId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=user_unblocked';
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function deleteUser(userId) {
    if (!confirm('ВЫ УВЕРЕНЫ? Это действие нельзя отменить!\n\nБудут удалены:\n- Пользователь\n- Все его устройства\n- Все его программы\n- Вся история')) {
        return;
    }
    
    const confirmation = prompt('Для подтверждения введите "УДАЛИТЬ":');
    if (confirmation !== 'УДАЛИТЬ') {
        alert('Удаление отменено');
        return;
    }
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    fetch('<?= BASE_URL ?>/api/admin/users.php', {
        method: 'DELETE',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken
        },
        body: JSON.stringify({
            user_id: userId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=user_deleted';
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
