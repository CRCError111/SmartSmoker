<?php
/**
 * Очередь передачи файлов — страница администратора
 * Просмотр file_delivery_log и program_transfer_queue по всем устройствам
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

$db = db();
$message = '';
$error = '';

// Удаление записи из file_delivery_log
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // CSRF protection
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
    $action = $_POST['action'];

    if ($action === 'delete_delivery' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $db->execute('DELETE FROM file_delivery_log WHERE id = ?', [$id]);
            $message = 'Запись удалена из журнала доставки.';
        } catch (Exception $e) {
            $error = 'Ошибка удаления: ' . $e->getMessage();
        }
    }

    if ($action === 'delete_transfer' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $db->execute('DELETE FROM program_transfer_queue WHERE id = ?', [$id]);
            $message = 'Запись удалена из очереди передачи.';
        } catch (Exception $e) {
            $error = 'Ошибка удаления: ' . $e->getMessage();
        }
    }

    if ($action === 'clear_delivered') {
        try {
            $db->execute("DELETE FROM file_delivery_log WHERE delivery_status = 'delivered'");
            $message = 'Доставленные записи очищены.';
        } catch (Exception $e) {
            $error = 'Ошибка очистки: ' . $e->getMessage();
        }
    }

    if ($action === 'clear_confirmed') {
        try {
            $db->execute("DELETE FROM program_transfer_queue WHERE status = 'confirmed'");
            $message = 'Подтверждённые передачи очищены.';
        } catch (Exception $e) {
            $error = 'Ошибка очистки: ' . $e->getMessage();
        }
    }
} // end CSRF check
}

// Фильтры
$filterDevice = $_GET['device_id'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$filterType   = $_GET['type'] ?? 'delivery'; // delivery | transfer

// Пагинация
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset  = ($page - 1) * $perPage;

// Список устройств для фильтра
$devices = $db->fetchAll('SELECT device_id, name FROM devices ORDER BY name');

try {
    if ($filterType === 'transfer') {
        // --- program_transfer_queue ---
        $where  = '1=1';
        $params = [];

        if ($filterDevice) {
            $where   .= ' AND ptq.device_id = ?';
            $params[] = $filterDevice;
        }
        if ($filterStatus) {
            $where   .= ' AND ptq.status = ?';
            $params[] = $filterStatus;
        }

        $total = $db->fetchColumn(
            "SELECT COUNT(*) FROM program_transfer_queue ptq WHERE $where",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT ptq.*, p.program_name, d.name AS device_name, u.full_name AS user_name
             FROM program_transfer_queue ptq
             LEFT JOIN programs p ON p.id = ptq.program_id
             LEFT JOIN devices d ON d.device_id = ptq.device_id
             LEFT JOIN users u ON u.id = ptq.user_id
             WHERE $where
             ORDER BY ptq.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $statusOptions = ['pending', 'sent', 'confirmed', 'failed'];

    } else {
        // --- file_delivery_log ---
        $where  = '1=1';
        $params = [];

        if ($filterDevice) {
            $where   .= ' AND fdl.device_id = ?';
            $params[] = $filterDevice;
        }
        if ($filterStatus) {
            $where   .= ' AND fdl.delivery_status = ?';
            $params[] = $filterStatus;
        }

        $total = $db->fetchColumn(
            "SELECT COUNT(*) FROM file_delivery_log fdl WHERE $where",
            $params
        );

        $rows = $db->fetchAll(
            "SELECT fdl.*, d.name AS device_name
             FROM file_delivery_log fdl
             LEFT JOIN devices d ON d.device_id = fdl.device_id
             WHERE $where
             ORDER BY fdl.created_at DESC
             LIMIT $perPage OFFSET $offset",
            $params
        );

        $statusOptions = ['pending', 'sent', 'delivered', 'failed', 'timeout'];
    }

    $totalPages = max(1, ceil($total / $perPage));

} catch (Exception $e) {
    logException($e, 'ADMIN');
    $error = 'Ошибка загрузки данных: ' . $e->getMessage();
    $rows  = [];
    $total = 0;
    $totalPages = 1;
    $statusOptions = [];
}

// Статистика
try {
    $stats = [
        'delivery_pending'   => $db->fetchColumn("SELECT COUNT(*) FROM file_delivery_log WHERE delivery_status = 'pending'"),
        'delivery_failed'    => $db->fetchColumn("SELECT COUNT(*) FROM file_delivery_log WHERE delivery_status = 'failed'"),
        'delivery_timeout'   => $db->fetchColumn("SELECT COUNT(*) FROM file_delivery_log WHERE delivery_status = 'timeout'"),
        'delivery_delivered' => $db->fetchColumn("SELECT COUNT(*) FROM file_delivery_log WHERE delivery_status = 'delivered'"),
        'transfer_pending'   => $db->fetchColumn("SELECT COUNT(*) FROM program_transfer_queue WHERE status = 'pending'"),
        'transfer_failed'    => $db->fetchColumn("SELECT COUNT(*) FROM program_transfer_queue WHERE status = 'failed'"),
        'transfer_confirmed' => $db->fetchColumn("SELECT COUNT(*) FROM program_transfer_queue WHERE status = 'confirmed'"),
    ];
} catch (Exception $e) {
    $stats = array_fill_keys(['delivery_pending','delivery_failed','delivery_timeout','delivery_delivered','transfer_pending','transfer_failed','transfer_confirmed'], 0);
}

$pageTitle = 'Очередь передачи файлов';
include __DIR__ . '/../templates/header.php';

// Вспомогательная функция: бейдж статуса
function statusBadge(string $status): string {
    $map = [
        'pending'   => 'warning',
        'sent'      => 'info',
        'delivered' => 'success',
        'confirmed' => 'success',
        'failed'    => 'danger',
        'timeout'   => 'secondary',
    ];
    $cls = $map[$status] ?? 'secondary';
    return "<span class=\"badge bg-{$cls}\">" . htmlspecialchars($status) . "</span>";
}
?>

<style>
.stat-mini { background:#fff; border-radius:10px; padding:16px 20px; box-shadow:0 2px 6px rgba(0,0,0,.08); }
.stat-mini .val { font-size:1.8rem; font-weight:700; }
.filter-bar { background:#f8f9fa; border-radius:8px; padding:16px; margin-bottom:20px; }
.table td, .table th { vertical-align:middle; }
.retry-badge { font-size:.75rem; }
</style>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">✅ <?= htmlspecialchars($message) ?> <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Статистика -->
<div class="row mb-4">
    <div class="col-6 col-md-3 mb-3">
        <div class="stat-mini">
            <div class="val text-warning"><?= $stats['delivery_pending'] ?></div>
            <div class="text-muted small">Доставка: ожидает</div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="stat-mini">
            <div class="val text-danger"><?= $stats['delivery_failed'] + $stats['delivery_timeout'] ?></div>
            <div class="text-muted small">Доставка: ошибки</div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="stat-mini">
            <div class="val text-warning"><?= $stats['transfer_pending'] ?></div>
            <div class="text-muted small">Передача: ожидает</div>
        </div>
    </div>
    <div class="col-6 col-md-3 mb-3">
        <div class="stat-mini">
            <div class="val text-danger"><?= $stats['transfer_failed'] ?></div>
            <div class="text-muted small">Передача: ошибки</div>
        </div>
    </div>
</div>

<!-- Переключатель таблиц -->
<div class="d-flex gap-2 mb-3 align-items-center flex-wrap">
    <a href="?type=delivery<?= $filterDevice ? '&device_id='.urlencode($filterDevice) : '' ?>"
       class="btn btn-<?= $filterType === 'delivery' ? 'primary' : 'outline-primary' ?>">
        📦 Журнал доставки файлов
    </a>
    <a href="?type=transfer<?= $filterDevice ? '&device_id='.urlencode($filterDevice) : '' ?>"
       class="btn btn-<?= $filterType === 'transfer' ? 'primary' : 'outline-primary' ?>">
        📤 Очередь передачи программ
    </a>

    <?php if ($filterType === 'delivery' && $stats['delivery_delivered'] > 0): ?>
    <form method="post" class="ms-auto" onsubmit="return confirm('Удалить все доставленные записи?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="clear_delivered">
        <button class="btn btn-outline-secondary btn-sm">🗑️ Очистить доставленные (<?= $stats['delivery_delivered'] ?>)</button>
    </form>
    <?php endif; ?>

    <?php if ($filterType === 'transfer' && $stats['transfer_confirmed'] > 0): ?>
    <form method="post" class="ms-auto" onsubmit="return confirm('Удалить все подтверждённые передачи?')">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
        <input type="hidden" name="action" value="clear_confirmed">
        <button class="btn btn-outline-secondary btn-sm">🗑️ Очистить подтверждённые (<?= $stats['transfer_confirmed'] ?>)</button>
    </form>
    <?php endif; ?>
</div>

<!-- Фильтры -->
<form method="get" class="filter-bar">
    <input type="hidden" name="type" value="<?= htmlspecialchars($filterType) ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small mb-1">Устройство</label>
            <select name="device_id" class="form-select form-select-sm">
                <option value="">— Все устройства —</option>
                <?php foreach ($devices as $d): ?>
                    <option value="<?= htmlspecialchars($d['device_id']) ?>"
                        <?= $filterDevice === $d['device_id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($d['name'] ?: $d['device_id']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Статус</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">— Все статусы —</option>
                <?php foreach ($statusOptions as $s): ?>
                    <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">🔍 Фильтр</button>
        </div>
        <div class="col-md-2">
            <a href="?type=<?= htmlspecialchars($filterType) ?>" class="btn btn-outline-secondary btn-sm w-100">✖ Сбросить</a>
        </div>
        <div class="col-md-1 text-end text-muted small">
            <?= $total ?> записей
        </div>
    </div>
</form>

<!-- Таблица -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($rows)): ?>
            <div class="p-4 text-center text-muted">Записей не найдено</div>
        <?php elseif ($filterType === 'delivery'): ?>
        <!-- file_delivery_log -->
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Устройство</th>
                        <th>Файл</th>
                        <th>Тип</th>
                        <th>Размер</th>
                        <th>Статус</th>
                        <th>Попытки</th>
                        <th>Создан</th>
                        <th>Доставлен</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-muted small"><?= $row['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['device_name'] ?: $row['device_id']) ?></strong>
                            <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($row['device_id']) ?></div>
                        </td>
                        <td>
                            <code style="font-size:.8rem"><?= htmlspecialchars($row['file_name']) ?></code>
                            <?php if ($row['error_message']): ?>
                                <div class="text-danger" style="font-size:.75rem" title="<?= htmlspecialchars($row['error_message']) ?>">
                                    ⚠️ <?= htmlspecialchars(mb_strimwidth($row['error_message'], 0, 60, '…')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><span class="badge bg-secondary"><?= htmlspecialchars($row['file_type']) ?></span></td>
                        <td class="text-muted small"><?= $row['file_size'] ? number_format($row['file_size']) . ' B' : '—' ?></td>
                        <td><?= statusBadge($row['delivery_status']) ?></td>
                        <td>
                            <?php if ($row['retry_count'] > 0): ?>
                                <span class="badge bg-warning text-dark retry-badge"><?= $row['retry_count'] ?>x</span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= formatDate($row['created_at'], true) ?></td>
                        <td class="text-muted small"><?= $row['delivered_at'] ? formatDate($row['delivered_at'], true) : '—' ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Удалить запись #<?= $row['id'] ?>?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_delivery">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm py-0 px-1" title="Удалить">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php else: ?>
        <!-- program_transfer_queue -->
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Устройство</th>
                        <th>Программа</th>
                        <th>Пользователь</th>
                        <th>Статус</th>
                        <th>Попытки</th>
                        <th>Создан</th>
                        <th>Подтверждён</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                    <tr>
                        <td class="text-muted small"><?= $row['id'] ?></td>
                        <td>
                            <strong><?= htmlspecialchars($row['device_name'] ?: $row['device_id']) ?></strong>
                            <div class="text-muted" style="font-size:.7rem"><?= htmlspecialchars($row['device_id']) ?></div>
                        </td>
                        <td>
                            <?= htmlspecialchars($row['program_name'] ?: '—') ?>
                            <div class="text-muted" style="font-size:.7rem">
                                <code><?= htmlspecialchars($row['transfer_id']) ?></code>
                            </div>
                            <?php if ($row['error_message']): ?>
                                <div class="text-danger" style="font-size:.75rem">
                                    ⚠️ <?= htmlspecialchars(mb_strimwidth($row['error_message'], 0, 60, '…')) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= htmlspecialchars($row['user_name'] ?? '—') ?></td>
                        <td><?= statusBadge($row['status']) ?></td>
                        <td>
                            <?php if ($row['retry_count'] > 0): ?>
                                <span class="badge bg-warning text-dark retry-badge"><?= $row['retry_count'] ?>x</span>
                            <?php else: ?>
                                <span class="text-muted">0</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= formatDate($row['created_at'], true) ?></td>
                        <td class="text-muted small"><?= $row['confirmed_at'] ? formatDate($row['confirmed_at'], true) : '—' ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Удалить запись #<?= $row['id'] ?>?')">
                                <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                                <input type="hidden" name="action" value="delete_transfer">
                                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                                <button class="btn btn-outline-danger btn-sm py-0 px-1" title="Удалить">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Пагинация -->
<?php if ($totalPages > 1): ?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                <a class="page-link" href="?type=<?= urlencode($filterType) ?>&device_id=<?= urlencode($filterDevice) ?>&status=<?= urlencode($filterStatus) ?>&page=<?= $i ?>">
                    <?= $i ?>
                </a>
            </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>

<?php include __DIR__ . '/../templates/footer.php'; ?>
