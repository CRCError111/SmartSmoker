<?php
/**
 * Редактирование пользователя
 * Изменение данных, роли, сброс пароля
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

$currentUser = Auth::user();
$db = db();

// Получение ID пользователя
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$userId) {
    redirect(BASE_URL . '/admin/users.php?error=user_not_found');
}

// Получение данных пользователя
try {
    $user = $db->fetchOne(
        'SELECT * FROM users WHERE id = ?',
        [$userId]
    );
    
    if (!$user) {
        redirect(BASE_URL . '/admin/users.php?error=user_not_found');
    }
    
    // Получение устройств пользователя
    $devices = $db->fetchAll(
        'SELECT d.*, 
                (SELECT COUNT(*) FROM sensor_data sd WHERE sd.device_id = d.device_id) as data_count,
                (SELECT COUNT(*) FROM runs r WHERE r.device_id = d.device_id) as runs_count
         FROM devices d
         WHERE d.user_id = ?
         ORDER BY d.created_at DESC',
        [$userId]
    );
    
    // Получение программ пользователя
    $programs = $db->fetchAll(
        'SELECT p.*,
                (SELECT COUNT(*) FROM program_stages ps WHERE ps.program_id = p.id) as stages_count
         FROM programs p
         WHERE p.user_id = ?
         ORDER BY p.created_at DESC',
        [$userId]
    );
    
    // Статистика
    $stats = [
        'devices' => count($devices),
        'programs' => count($programs),
        'runs' => $db->fetchColumn('SELECT COUNT(*) FROM runs r JOIN devices d ON d.device_id = r.device_id WHERE d.user_id = ?', [$userId]),
        'data_points' => $db->fetchColumn('SELECT COUNT(*) FROM sensor_data sd JOIN devices d ON d.device_id = sd.device_id WHERE d.user_id = ?', [$userId])
    ];
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки данных: ' . $e->getMessage();
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
        $action = $_POST['action'] ?? '';
        
        try {
            if ($action === 'update_info') {
                // Обновление основной информации
                $fullName = trim($_POST['full_name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                
                if (empty($fullName) || empty($email)) {
                    throw new Exception('Имя и email обязательны');
                }
                
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Неверный формат email');
                }
                
                // Проверка уникальности email
                $existingUser = $db->fetchOne(
                    'SELECT id FROM users WHERE email = ? AND id != ?',
                    [$email, $userId]
                );
                
                if ($existingUser) {
                    throw new Exception('Пользователь с таким email уже существует');
                }
                
                $db->update('users', [
                    'full_name' => $fullName,
                    'email' => $email
                ], 'id = ?', [$userId]);
                
                AdminAuth::logAction('user_updated', 'user', $userId, [
                    'changes' => ['full_name', 'email'],
                    'old_email' => $user['email'],
                    'new_email' => $email
                ]);
                
                redirect(BASE_URL . '/admin/user-edit.php?id=' . $userId . '&success=info_updated');
                
            } elseif ($action === 'change_role') {
                // Изменение роли
                $newRole = $_POST['role'] ?? '';
                
                if (!in_array($newRole, ['user', 'admin'])) {
                    throw new Exception('Неверная роль');
                }
                
                // Проверка возможности изменения роли
                $canChange = AdminAuth::canChangeRole($userId, $newRole);
                
                if (!$canChange['can_change']) {
                    throw new Exception($canChange['reason']);
                }
                
                $db->update('users', [
                    'role' => $newRole
                ], 'id = ?', [$userId]);
                
                AdminAuth::logAction('role_changed', 'user', $userId, [
                    'old_role' => $user['role'],
                    'new_role' => $newRole,
                    'email' => $user['email']
                ]);
                
                redirect(BASE_URL . '/admin/user-edit.php?id=' . $userId . '&success=role_changed');
                
            } elseif ($action === 'reset_password') {
                // Сброс пароля
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($newPassword)) {
                    throw new Exception('Введите новый пароль');
                }
                
                if (strlen($newPassword) < 6) {
                    throw new Exception('Пароль должен быть не менее 6 символов');
                }
                
                if ($newPassword !== $confirmPassword) {
                    throw new Exception('Пароли не совпадают');
                }
                
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                
                $db->update('users', [
                    'password' => $hashedPassword
                ], 'id = ?', [$userId]);
                
                AdminAuth::logAction('password_reset', 'user', $userId, [
                    'email' => $user['email']
                ]);
                
                redirect(BASE_URL . '/admin/user-edit.php?id=' . $userId . '&success=password_reset');
            }
            
        } catch (Exception $e) {
            $error = $e->getMessage();
            logException($e, 'ADMIN');
        }
    }
}

$pageTitle = 'Редактирование пользователя';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .user-header {
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,.1);
        margin-bottom: 20px;
    }
    
    .user-avatar-large {
        width: 80px;
        height: 80px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 700;
        font-size: 2rem;
    }
    
    .admin-badge {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        padding: 6px 16px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 600;
    }
    
    .stat-box {
        background: #f8f9fa;
        border-radius: 8px;
        padding: 15px;
        text-align: center;
    }
    
    .stat-box .value {
        font-size: 2rem;
        font-weight: 700;
        color: #333;
    }
    
    .stat-box .label {
        font-size: 0.85rem;
        color: #666;
        text-transform: uppercase;
    }
    
    .danger-zone {
        border: 2px solid #dc3545;
        border-radius: 12px;
        padding: 20px;
        background: #fff5f5;
    }
</style>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show">
    ⚠️ <?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($_GET['success'])): ?>
    <?php if ($_GET['success'] === 'info_updated'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Информация обновлена!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'role_changed'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Роль изменена!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'password_reset'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Пароль сброшен!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Шапка пользователя -->
<div class="user-header">
    <div class="d-flex align-items-center gap-3 mb-3">
        <div class="user-avatar-large">
            <?= strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)) ?>
        </div>
        <div class="flex-grow-1">
            <h3 class="mb-1"><?= htmlspecialchars($user['full_name']) ?></h3>
            <div class="text-muted"><?= htmlspecialchars($user['email']) ?></div>
            <div class="mt-2">
                <?php if ($user['role'] === 'admin'): ?>
                    <span class="admin-badge">АДМИНИСТРАТОР</span>
                <?php else: ?>
                    <span class="badge bg-secondary">ПОЛЬЗОВАТЕЛЬ</span>
                <?php endif; ?>
                
                <?php if ($user['is_blocked']): ?>
                    <span class="badge bg-danger ms-2">ЗАБЛОКИРОВАН</span>
                <?php else: ?>
                    <span class="badge bg-success ms-2">АКТИВЕН</span>
                <?php endif; ?>
                
                <?php if ($userId === $currentUser['id']): ?>
                    <span class="badge bg-info ms-2">ЭТО ВЫ</span>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline-secondary">
                ⬅️ Назад к списку
            </a>
        </div>
    </div>
    
    <!-- Статистика -->
    <div class="row mt-4">
        <div class="col-md-3">
            <div class="stat-box">
                <div class="value"><?= $stats['devices'] ?></div>
                <div class="label">Устройств</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="value"><?= $stats['programs'] ?></div>
                <div class="label">Программ</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="value"><?= $stats['runs'] ?></div>
                <div class="label">Запусков</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="stat-box">
                <div class="value"><?= number_format($stats['data_points']) ?></div>
                <div class="label">Точек данных</div>
            </div>
        </div>
    </div>
    
    <div class="row mt-3">
        <div class="col-md-6">
            <small class="text-muted">
                <strong>Регистрация:</strong> <?= formatDate($user['created_at']) ?>
            </small>
        </div>
        <div class="col-md-6 text-end">
            <small class="text-muted">
                <strong>Последний вход:</strong> <?= $user['last_login'] ? formatDate($user['last_login'], true) : 'Никогда' ?>
            </small>
        </div>
    </div>
</div>

<div class="row">
    <!-- Левая колонка -->
    <div class="col-md-6">
        <!-- Основная информация -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">📝 Основная информация</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="update_info">
                    
                    <div class="mb-3">
                        <label class="form-label">Полное имя</label>
                        <input type="text" 
                               name="full_name" 
                               class="form-control" 
                               value="<?= htmlspecialchars($user['full_name']) ?>"
                               required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" 
                               name="email" 
                               class="form-control" 
                               value="<?= htmlspecialchars($user['email']) ?>"
                               required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        💾 Сохранить изменения
                    </button>
                </form>
            </div>
        </div>
        
        <!-- Изменение роли -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">🔐 Роль пользователя</h5>
            </div>
            <div class="card-body">
                <?php if ($userId === $currentUser['id']): ?>
                    <div class="alert alert-warning mb-0">
                        ⚠️ Вы не можете изменить свою роль
                    </div>
                <?php else: ?>
                    <form method="POST" action="" onsubmit="return confirmRoleChange()">
                        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                        <input type="hidden" name="action" value="change_role">
                        
                        <div class="mb-3">
                            <label class="form-label">Текущая роль</label>
                            <select name="role" class="form-select">
                                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>
                                    Пользователь
                                </option>
                                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>
                                    Администратор
                                </option>
                            </select>
                        </div>
                        
                        <div class="alert alert-info small">
                            <strong>Администратор</strong> имеет полный доступ к системе, включая управление пользователями и устройствами.
                        </div>
                        
                        <button type="submit" class="btn btn-warning w-100">
                            🔄 Изменить роль
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Сброс пароля -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">🔑 Сброс пароля</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="" onsubmit="return confirmPasswordReset()">
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <input type="hidden" name="action" value="reset_password">
                    
                    <div class="mb-3">
                        <label class="form-label">Новый пароль</label>
                        <input type="password" 
                               name="new_password" 
                               class="form-control" 
                               minlength="6"
                               required>
                        <div class="form-text">Минимум 6 символов</div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Подтвердите пароль</label>
                        <input type="password" 
                               name="confirm_password" 
                               class="form-control" 
                               minlength="6"
                               required>
                    </div>
                    
                    <button type="submit" class="btn btn-warning w-100">
                        🔑 Сбросить пароль
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Правая колонка -->
    <div class="col-md-6">
        <!-- Устройства -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">🖥️ Устройства (<?= count($devices) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($devices)): ?>
                    <div class="p-3 text-center text-muted">
                        Нет устройств
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($devices as $device): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($device['name']) ?></strong>
                                        <div class="text-muted small">
                                            Device ID: <?= substr($device['device_id'], 0, 8) ?>...
                                        </div>
                                        <div class="text-muted small">
                                            Запусков: <?= $device['runs_count'] ?> | 
                                            Данных: <?= number_format($device['data_count']) ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?= $device['status'] === 'active' ? 'success' : 'secondary' ?>">
                                        <?= $device['status'] ?>
                                    </span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Программы -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">📋 Программы (<?= count($programs) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($programs)): ?>
                    <div class="p-3 text-center text-muted">
                        Нет программ
                    </div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach (array_slice($programs, 0, 5) as $program): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><?= htmlspecialchars($program['name']) ?></strong>
                                        <div class="text-muted small">
                                            Этапов: <?= $program['stages_count'] ?> | 
                                            Категория: <?= $program['category'] ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (count($programs) > 5): ?>
                            <div class="list-group-item text-center text-muted small">
                                И ещё <?= count($programs) - 5 ?> программ...
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Опасная зона -->
        <?php if ($userId !== $currentUser['id']): ?>
        <div class="danger-zone">
            <h5 class="text-danger mb-3">⚠️ Опасная зона</h5>
            
            <?php if ($user['is_blocked']): ?>
                <button type="button" 
                        class="btn btn-success w-100 mb-2" 
                        onclick="unblockUser(<?= $userId ?>)">
                    ✅ Разблокировать пользователя
                </button>
            <?php else: ?>
                <button type="button" 
                        class="btn btn-warning w-100 mb-2" 
                        onclick="blockUser(<?= $userId ?>)">
                    🚫 Заблокировать пользователя
                </button>
            <?php endif; ?>
            
            <?php 
            $canDelete = AdminAuth::canDeleteUser($userId);
            if ($canDelete['can_delete']): 
            ?>
                <button type="button" 
                        class="btn btn-danger w-100" 
                        onclick="deleteUser(<?= $userId ?>)">
                    🗑️ Удалить пользователя
                </button>
            <?php else: ?>
                <button type="button" 
                        class="btn btn-danger w-100" 
                        disabled
                        title="<?= htmlspecialchars($canDelete['reason']) ?>">
                    🗑️ Удалить пользователя
                </button>
                <div class="alert alert-warning mt-2 mb-0 small">
                    <?= htmlspecialchars($canDelete['reason']) ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmRoleChange() {
    return confirm('Вы уверены, что хотите изменить роль этого пользователя?');
}

function confirmPasswordReset() {
    return confirm('Вы уверены, что хотите сбросить пароль этого пользователя?');
}

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
            window.location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
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
            window.location.reload();
        } else {
            alert('Ошибка: ' + data.error);
        }
    });
}

function deleteUser(userId) {
    if (!confirm('ВЫ УВЕРЕНЫ? Это действие нельзя отменить!')) return;
    
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
            window.location.href = '<?= BASE_URL ?>/admin/users.php?success=user_deleted';
        } else {
            alert('Ошибка: ' + data.error);
        }
    });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
