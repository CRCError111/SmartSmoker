<?php
/**
 * Управление шаблонами программ
 * Создание, редактирование, публикация шаблонов
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
$categoryFilter = $_GET['category'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$searchQuery = $_GET['search'] ?? '';

// Построение SQL запроса
$sql = 'SELECT 
    t.*,
    u.full_name as author_name,
    u.email as author_email,
    (SELECT COUNT(*) FROM template_stages ts WHERE ts.template_id = t.id) as stages_count,
    (SELECT COUNT(*) FROM programs p WHERE p.program_name = t.name) as usage_count
FROM templates t
LEFT JOIN users u ON u.id = t.created_by
WHERE 1=1';

$params = [];

if ($categoryFilter !== 'all') {
    $sql .= ' AND t.category = ?';
    $params[] = $categoryFilter;
}

if ($statusFilter === 'public') {
    $sql .= ' AND t.is_public = 1';
} elseif ($statusFilter === 'private') {
    $sql .= ' AND t.is_public = 0';
}

if (!empty($searchQuery)) {
    $sql .= ' AND (t.name LIKE ? OR t.description LIKE ?)';
    $params[] = "%$searchQuery%";
    $params[] = "%$searchQuery%";
}

$sql .= ' ORDER BY t.created_at DESC';

try {
    $templates = $db->fetchAll($sql, $params);
    
    // Статистика
    $totalTemplates = $db->fetchColumn('SELECT COUNT(*) FROM templates');
    $publicTemplates = $db->fetchColumn('SELECT COUNT(*) FROM templates WHERE is_public = 1');
    $privateTemplates = $db->fetchColumn('SELECT COUNT(*) FROM templates WHERE is_public = 0');
    
    // Категории
    $categories = [
        'fish' => 'Рыба',
        'meat' => 'Мясо',
        'poultry' => 'Птица',
        'cheese' => 'Сыр',
        'vegetables' => 'Овощи',
        'other' => 'Другое'
    ];
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки шаблонов: ' . $e->getMessage();
    $templates = [];
}

$pageTitle = 'Управление шаблонами';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .template-card {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 2px 8px rgba(0,0,0,.1);
        margin-bottom: 20px;
        transition: all 0.3s;
    }
    
    .template-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,.15);
    }
    
    .template-header {
        display: flex;
        justify-content: between;
        align-items: start;
        margin-bottom: 15px;
    }
    
    .category-badge {
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 0.75rem;
        font-weight: 600;
    }
    
    .category-badge.fish { background: #d1ecf1; color: #0c5460; }
    .category-badge.meat { background: #f8d7da; color: #721c24; }
    .category-badge.poultry { background: #fff3cd; color: #856404; }
    .category-badge.cheese { background: #fff3cd; color: #856404; }
    .category-badge.vegetables { background: #d4edda; color: #155724; }
    .category-badge.other { background: #e2e3e5; color: #383d41; }
    
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
    <?php if ($_GET['success'] === 'template_created'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Шаблон успешно создан!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'template_updated'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Шаблон успешно обновлён!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'template_deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Шаблон успешно удалён!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'template_published'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Шаблон опубликован!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'template_unpublished'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Шаблон снят с публикации!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">📄</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $totalTemplates ?></div>
                <div style="font-size: 0.85rem; color: #666;">Всего шаблонов</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">🌐</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $publicTemplates ?></div>
                <div style="font-size: 0.85rem; color: #666;">Публичных</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-badge">
            <span style="font-size: 1.5rem;">🔒</span>
            <div>
                <div style="font-size: 1.5rem; font-weight: 700;"><?= $privateTemplates ?></div>
                <div style="font-size: 0.85rem; color: #666;">Приватных</div>
            </div>
        </div>
    </div>
</div>

<!-- Фильтры и поиск -->
<div class="filter-card">
    <form method="GET" action="" class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Категория</label>
            <select name="category" class="form-select">
                <option value="all" <?= $categoryFilter === 'all' ? 'selected' : '' ?>>Все категории</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= $key ?>" <?= $categoryFilter === $key ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="col-md-3">
            <label class="form-label">Статус</label>
            <select name="status" class="form-select">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Все</option>
                <option value="public" <?= $statusFilter === 'public' ? 'selected' : '' ?>>Публичные</option>
                <option value="private" <?= $statusFilter === 'private' ? 'selected' : '' ?>>Приватные</option>
            </select>
        </div>
        
        <div class="col-md-4">
            <label class="form-label">Поиск</label>
            <input type="text" 
                   name="search" 
                   class="form-control" 
                   placeholder="Название или описание..." 
                   value="<?= htmlspecialchars($searchQuery) ?>">
        </div>
        
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">🔍 Найти</button>
        </div>
    </form>
</div>

<!-- Кнопка создания -->
<div class="mb-4">
    <a href="<?= BASE_URL ?>/admin/template-create.php" class="btn btn-success">
        ➕ Создать шаблон
    </a>
    <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-outline-secondary">
        ⬅️ Назад
    </a>
</div>

<!-- Список шаблонов -->
<?php if (empty($templates)): ?>
    <div class="alert alert-warning">
        Шаблоны не найдены
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($templates as $t): ?>
            <div class="col-md-6 mb-4">
                <div class="template-card">
                    <div class="template-header">
                        <div class="flex-grow-1">
                            <h5 class="mb-2">
                                <?= htmlspecialchars($t['name']) ?>
                                <?php if ($t['is_public']): ?>
                                    <span class="badge bg-success ms-2">🌐 Публичный</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary ms-2">🔒 Приватный</span>
                                <?php endif; ?>
                            </h5>
                            <span class="category-badge <?= $t['category'] ?>">
                                <?= $categories[$t['category']] ?? $t['category'] ?>
                            </span>
                        </div>
                    </div>
                    
                    <?php if ($t['description']): ?>
                        <p class="text-muted mb-3">
                            <?= htmlspecialchars($t['description']) ?>
                        </p>
                    <?php endif; ?>
                    
                    <div class="row mb-3">
                        <div class="col-6">
                            <small class="text-muted">
                                <strong>Этапов:</strong> <?= $t['stages_count'] ?>
                            </small>
                        </div>
                        <div class="col-6">
                            <small class="text-muted">
                                <strong>Использований:</strong> <?= $t['usage_count'] ?>
                            </small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <small class="text-muted">
                            <strong>Автор:</strong> <?= htmlspecialchars($t['author_name'] ?? 'Система') ?>
                        </small>
                        <br>
                        <small class="text-muted">
                            <strong>Создан:</strong> <?= formatDate($t['created_at']) ?>
                        </small>
                    </div>
                    
                    <div class="d-flex gap-2">
                        <a href="<?= BASE_URL ?>/admin/template-edit.php?id=<?= $t['id'] ?>" 
                           class="btn btn-sm btn-outline-primary">
                            ✏️ Редактировать
                        </a>
                        
                        <?php if ($t['is_public']): ?>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-warning" 
                                    onclick="unpublishTemplate(<?= $t['id'] ?>)">
                                🔒 Снять с публикации
                            </button>
                        <?php else: ?>
                            <button type="button" 
                                    class="btn btn-sm btn-outline-success" 
                                    onclick="publishTemplate(<?= $t['id'] ?>)">
                                🌐 Опубликовать
                            </button>
                        <?php endif; ?>
                        
                        <button type="button" 
                                class="btn btn-sm btn-outline-danger" 
                                onclick="deleteTemplate(<?= $t['id'] ?>, '<?= htmlspecialchars($t['name'], ENT_QUOTES) ?>')">
                            🗑️ Удалить
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
function publishTemplate(templateId) {
    if (!confirm('Опубликовать этот шаблон?\n\nОн станет доступен всем пользователям.')) {
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/admin/templates.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'publish',
            template_id: templateId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=template_published';
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function unpublishTemplate(templateId) {
    if (!confirm('Снять шаблон с публикации?\n\nОн станет недоступен пользователям.')) {
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/admin/templates.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'unpublish',
            template_id: templateId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=template_unpublished';
        } else {
            alert('Ошибка: ' + data.error);
        }
    })
    .catch(error => {
        alert('Ошибка: ' + error);
    });
}

function deleteTemplate(templateId, templateName) {
    if (!confirm(`Удалить шаблон "${templateName}"?\n\nЭто действие нельзя отменить!`)) {
        return;
    }
    
    fetch('<?= BASE_URL ?>/api/admin/templates.php', {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            template_id: templateId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = '?success=template_deleted';
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
