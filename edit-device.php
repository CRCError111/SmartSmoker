<?php
/**
 * Страница редактирования устройства
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

$user = Auth::user();
$deviceId = (int)($_GET['id'] ?? 0);

// Получение устройства
$device = getDevice($deviceId);

if (!$device) {
    redirect(BASE_URL . '/devices.php?error=device_not_found');
}

$error = '';
$success = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        
        // Валидация
        list($valid, $message) = validateDeviceName($name);
        if (!$valid) {
            $error = $message;
        } else {
            list($valid, $message) = validateDescription($description);
            if (!$valid) {
                $error = $message;
            } else {
                $db = db();
                
                try {
                    // Обновление устройства
                    $db->update(
                        'devices',
                        [
                            'name' => $name,
                            'description' => $description,
                            'updated_at' => date('Y-m-d H:i:s')
                        ],
                        'id = ? AND user_id = ?',
                        [$deviceId, $user['id']]
                    );
                    
                    logInfo("Устройство #$deviceId обновлено: $name", 'DEVICES');
                    $success = 'Устройство успешно обновлено!';
                    
                    // Редирект на страницу устройств
                    redirect(BASE_URL . '/devices.php?success=device_updated');
                    
                } catch (Exception $e) {
                    logException($e, 'DEVICES');
                    $error = 'Ошибка при обновлении устройства: ' . $e->getMessage();
                }
            }
        }
    }
}

$pageTitle = 'Редактировать устройство';
include __DIR__ . '/templates/header.php';
?>

<style>
.device-info {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 12px 0;
    border-bottom: 1px solid #eee;
}

.info-row:last-child {
    border-bottom: none;
}

.info-label {
    font-weight: 500;
    color: #666;
    display: flex;
    align-items: center;
    gap: 8px;
}

.info-value {
    font-weight: 600;
    color: #333;
}
</style>

<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    ⚠️ <?= e($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    ✅ <?= e($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Информация об устройстве -->
<div class="device-info">
    <div class="info-row">
        <span class="info-label">🔑 ID устройства:</span>
        <span class="info-value"><?= $device['device_id'] ?></span>
    </div>
    <div class="info-row">
        <span class="info-label">📡 Статус:</span>
        <span class="info-value">
            <?php 
            $statusInfo = formatDeviceStatus($device['status']);
            echo '<span class="badge ' . $statusInfo[1] . '">' . $statusInfo[0] . '</span>';
            ?>
        </span>
    </div>
    <div class="info-row">
        <span class="info-label">📅 Дата добавления:</span>
        <span class="info-value"><?= formatDate($device['created_at']) ?></span>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            
            <div class="mb-3">
                <label for="name" class="form-label">🏷️ Название устройства *</label>
                <input 
                    type="text" 
                    class="form-control" 
                    id="name" 
                    name="name" 
                    placeholder="Например: Коптильня на даче"
                    value="<?= e($device['name']) ?>"
                    required
                    maxlength="100"
                >
                <div class="form-text">Максимум 100 символов</div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label">📝 Описание (опционально)</label>
                <textarea 
                    class="form-control" 
                    id="description" 
                    name="description" 
                    rows="3"
                    placeholder="Дополнительная информация о устройстве"
                    maxlength="1000"
                ><?= e($device['description']) ?></textarea>
                <div class="form-text">Максимум 1000 символов</div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    💾 Сохранить изменения
                </button>
                <a href="<?= BASE_URL ?>/devices.php" class="btn btn-secondary">
                    ← Отмена
                </a>
                <a href="<?= BASE_URL ?>/bind-device.php?id=<?= $device['id'] ?>" class="btn btn-outline-primary">
                    🔗 Привязать устройство
                </a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>