<?php
/**
 * Страница управления прошивками
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/admin-auth.php';

// Требуется авторизация администратора
AdminAuth::requireAdmin();

$error = '';
$success = '';
$db = db();

// Обработка загрузки новой прошивки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['firmware_file'])) {
    try {
        $version = trim($_POST['version'] ?? '');
        $releaseNotes = trim($_POST['release_notes'] ?? '');
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        $minVersionRequired = trim($_POST['min_version_required'] ?? '');
        
        // Валидация версии
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            $error = 'Неверный формат версии. Используйте формат X.Y.Z (например, 2.0.1)';
        } elseif (empty($releaseNotes)) {
            $error = 'Заполните описание изменений';
        } else {
            // Проверка, что версия не существует
            $existingVersion = $db->fetchOne(
                'SELECT id FROM firmware_updates WHERE version = ?',
                [$version]
            );
            
            if ($existingVersion) {
                $error = 'Прошивка с версией ' . $version . ' уже существует';
            } else {
                $file = $_FILES['firmware_file'];
                
                // Проверка файла
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $error = 'Ошибка загрузки файла: ' . $file['error'];
                } elseif ($file['size'] > 2 * 1024 * 1024) { // 2MB максимум
                    $error = 'Файл слишком большой. Максимальный размер: 2MB';
                } elseif (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'bin') {
                    $error = 'Файл должен иметь расширение .bin';
                } else {
                    // Создаем папку firmware если её нет
                    $firmwareDir = BASE_PATH . '/firmware';
                    if (!is_dir($firmwareDir)) {
                        mkdir($firmwareDir, 0755, true);
                    }
                    
                    // Генерируем имя файла
                    $filename = 'firmware_' . $version . '.bin';
                    $filePath = $firmwareDir . '/' . $filename;
                    
                    // Перемещаем файл
                    if (move_uploaded_file($file['tmp_name'], $filePath)) {
                        // Вычисляем контрольную сумму
                        $checksum = hash_file('sha256', $filePath);
                        
                        // Сохраняем в базу данных
                        $firmwareId = $db->insert('firmware_updates', [
                            'version' => $version,
                            'filename' => $filename,
                            'file_path' => $filePath,
                            'file_size' => $file['size'],
                            'checksum' => $checksum,
                            'release_notes' => $releaseNotes,
                            'is_required' => $isRequired,
                            'min_version_required' => $minVersionRequired ?: null,
                            'is_active' => 1,
                            'release_date' => date('Y-m-d H:i:s'),
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        logInfo("Прошивка $version загружена администратором", 'FIRMWARE');
                        AdminAuth::logAction('firmware_uploaded', 'firmware', $firmwareId, [
                            'version' => $version,
                            'filename' => $filename,
                            'file_size' => $file['size'],
                            'is_required' => $isRequired
                        ]);
                        $success = 'Прошивка успешно загружена!';
                    } else {
                        $error = 'Ошибка при сохранении файла';
                    }
                }
            }
        }
    } catch (Exception $e) {
        logException($e, 'FIRMWARE');
        $error = 'Ошибка: ' . $e->getMessage();
    }
}

// Обработка удаления прошивки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $firmwareId = (int)$_POST['firmware_id'];
        
        // Получаем информацию о прошивке
        $firmware = $db->fetchOne(
            'SELECT * FROM firmware_updates WHERE id = ?',
            [$firmwareId]
        );
        
        if ($firmware) {
            // Prevent deletion of active firmware
            if ($firmware['is_active'] == 1) {
                $error = '❌ Невозможно удалить активную прошивку! Сначала деактивируйте её или активируйте другую версию.';
                logWarning("Попытка удаления активной прошивки {$firmware['version']} заблокирована", 'FIRMWARE');
            } else {
                // Удаляем файл
                if (file_exists($firmware['file_path'])) {
                    unlink($firmware['file_path']);
                }
                
                // Удаляем из базы
                $db->delete('firmware_updates', 'id = ?', [$firmwareId]);
                
                logInfo("Прошивка {$firmware['version']} удалена администратором", 'FIRMWARE');
                AdminAuth::logAction('firmware_deleted', 'firmware', $firmwareId, [
                    'version' => $firmware['version'],
                    'filename' => $firmware['filename']
                ]);
                $success = 'Прошивка успешно удалена!';
            }
        }
    } catch (Exception $e) {
        logException($e, 'FIRMWARE');
        $error = 'Ошибка при удалении: ' . $e->getMessage();
    }
}

// Обработка изменения статуса
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    try {
        $firmwareId = (int)$_POST['firmware_id'];
        
        $firmware = $db->fetchOne(
            'SELECT * FROM firmware_updates WHERE id = ?',
            [$firmwareId]
        );
        
        if ($firmware) {
            $newStatus = $firmware['is_active'] ? 0 : 1;
            
            // If activating this firmware, deactivate all others first
            if ($newStatus === 1) {
                $db->query('UPDATE firmware_updates SET is_active = 0 WHERE id != ?', [$firmwareId]);
                logInfo("Все прошивки деактивированы перед активацией версии {$firmware['version']}", 'FIRMWARE');
            }
            
            // Update the target firmware status
            $db->update(
                'firmware_updates',
                ['is_active' => $newStatus],
                'id = ?',
                [$firmwareId]
            );
            
            $statusText = $newStatus ? 'активирована' : 'деактивирована';
            $currentUser = Auth::user();
            $adminUsername = $currentUser['username'] ?? $currentUser['email'] ?? 'unknown';
            logInfo("Прошивка {$firmware['version']} $statusText администратором $adminUsername", 'FIRMWARE');
            AdminAuth::logAction('firmware_status_changed', 'firmware', $firmwareId, [
                'version' => $firmware['version'],
                'old_status' => $firmware['is_active'],
                'new_status' => $newStatus,
                'admin_username' => $adminUsername,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            $success = "Прошивка успешно $statusText!";
        }
    } catch (Exception $e) {
        logException($e, 'FIRMWARE');
        $error = 'Ошибка при изменении статуса: ' . $e->getMessage();
    }
}

// Получаем список прошивок
$firmwareList = $db->fetchAll("
    SELECT 
        fw.*,
        (SELECT COUNT(*) FROM firmware_downloads fd WHERE fd.firmware_version = fw.version) as download_count
    FROM firmware_updates fw
    ORDER BY fw.release_date DESC
", []);

$pageTitle = 'Управление прошивками';
require_once __DIR__ . '/../templates/header.php';
?>

<style>
.firmware-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
    padding: 20px;
    margin-bottom: 20px;
}

.firmware-card.active-firmware {
    border: 2px solid #28a745;
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
    background: linear-gradient(to right, #f8fff9, white);
}

.firmware-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #eee;
}

.firmware-version {
    font-size: 1.3rem;
    font-weight: 700;
    color: #333;
}

.firmware-actions {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

/* Modal z-index fix */
.modal-backdrop {
    z-index: 1040 !important;
}

.modal {
    z-index: 1050 !important;
}

.modal-dialog {
    z-index: 1051 !important;
}

/* Ensure buttons are clickable */
.modal-footer {
    position: relative;
    z-index: 1052 !important;
}

.modal-footer button,
.modal-footer .btn {
    position: relative;
    z-index: 1053 !important;
    pointer-events: auto !important;
}

/* Fix inline form layout */
.modal-footer form {
    display: inline-block;
    margin: 0;
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

<div class="row">
    <div class="col-md-5">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">📤 Загрузить новую прошивку</h5>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="version" class="form-label">🏷️ Версия *</label>
                        <input type="text" class="form-control" id="version" name="version" 
                               placeholder="2.0.1" required pattern="\d+\.\d+\.\d+">
                        <div class="form-text">Формат: X.Y.Z (например, 2.0.1)</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="firmware_file" class="form-label">📁 Файл прошивки *</label>
                        <input type="file" class="form-control" id="firmware_file" name="firmware_file" 
                               accept=".bin" required>
                        <div class="form-text">Только .bin файлы, максимум 2MB</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="release_notes" class="form-label">📝 Описание изменений *</label>
                        <textarea class="form-control" id="release_notes" name="release_notes" 
                                  rows="4" required placeholder="Что нового в этой версии..."></textarea>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="is_required" name="is_required">
                        <label class="form-check-label" for="is_required">
                            ⚠️ Обязательное обновление
                        </label>
                        <div class="form-text">Устройства будут принудительно обновляться</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="min_version_required" class="form-label">⚙️ Минимальная версия для обновления</label>
                        <input type="text" class="form-control" id="min_version_required" 
                               name="min_version_required" placeholder="2.0.0" pattern="\d+\.\d+\.\d+">
                        <div class="form-text">Устройства с версией ниже этой не смогут обновиться</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        📤 Загрузить прошивку
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-md-7">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">📋 Доступные прошивки (<?= count($firmwareList) ?>)</h5>
            </div>
            <div class="card-body">
                <?php if (empty($firmwareList)): ?>
                    <div class="alert alert-info">
                        ℹ️ Нет доступных прошивок. Загрузите первую прошивку.
                    </div>
                <?php else: ?>
                    <?php foreach ($firmwareList as $fw): ?>
                    <div class="firmware-card<?= $fw['is_active'] ? ' active-firmware' : '' ?>">
                        <div class="firmware-header">
                            <div>
                                <div class="firmware-version">
                                    🔄 Версия <?= e($fw['version']) ?>
                                    <?php if ($fw['is_active']): ?>
                                        <span class="badge bg-success">⭐ Активна</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">❌ Неактивна</span>
                                    <?php endif; ?>
                                    <?php if ($fw['is_required']): ?>
                                        <span class="badge bg-danger">⚠️ Обязательно</span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small mt-1">
                                    📅 <?= formatDate($fw['release_date']) ?> | 
                                    📦 <?= number_format($fw['file_size'] / 1024, 2) ?> KB | 
                                    ⬇️ <?= $fw['download_count'] ?> скачиваний
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <strong>📝 Описание:</strong><br>
                            <small><?= nl2br(e($fw['release_notes'])) ?></small>
                        </div>
                        
                        <?php if ($fw['min_version_required']): ?>
                        <div class="mb-2">
                            <small class="text-muted">⚙️ Минимальная версия: <?= e($fw['min_version_required']) ?></small>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-2">
                            <small class="text-muted">🔐 SHA256: <?= substr($fw['checksum'], 0, 32) ?>...</small>
                        </div>
                        
                        <div class="firmware-actions">
                            <a href="<?= BASE_URL ?>/firmware/<?= e($fw['filename']) ?>" 
                               class="btn btn-sm btn-primary" download>
                                ⬇️ Скачать
                            </a>
                            
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="firmware_id" value="<?= $fw['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-warning">
                                    <?= $fw['is_active'] ? '❌ Деактивировать' : '✅ Активировать' ?>
                                </button>
                            </form>
                            
                            <?php if ($fw['is_active']): ?>
                                <button type="button" class="btn btn-sm btn-danger" disabled 
                                        title="Невозможно удалить активную прошивку">
                                    🔒 Удалить
                                </button>
                            <?php else: ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Вы уверены, что хотите удалить прошивку версии <?= e($fw['version']) ?>?\n\n⚠️ Внимание: Файл прошивки будет удален с сервера!');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="firmware_id" value="<?= $fw['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        🗑️ Удалить
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Help Section -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">❓ Инструкция по загрузке OTA-обновлений</h5>
    </div>
    <div class="card-body">
        <h6>Как загрузить новую прошивку:</h6>
        <ol>
            <li><strong>Скомпилируйте прошивку</strong> в Arduino IDE или PlatformIO</li>
            <li><strong>Найдите файл .bin</strong> в папке build вашего проекта</li>
            <li><strong>Заполните форму</strong> слева:
                <ul>
                    <li>Укажите версию в формате X.Y.Z (например, 2.0.1)</li>
                    <li>Выберите файл .bin</li>
                    <li>Опишите изменения в этой версии</li>
                    <li>Отметьте "Обязательное", если обновление критическое</li>
                </ul>
            </li>
            <li><strong>Нажмите "Загрузить"</strong> - файл будет сохранен в папке /firmware/</li>
            <li><strong>Устройства автоматически</strong> получат уведомление о новой прошивке</li>
        </ol>
        
        <h6 class="mt-3">Управление прошивками:</h6>
        <ul>
            <li><strong>Активна/Неактивна:</strong> Деактивированные прошивки не показываются устройствам</li>
            <li><strong>Обязательное обновление:</strong> Устройства будут принудительно обновляться</li>
            <li><strong>Минимальная версия:</strong> Ограничивает обновление для старых версий</li>
            <li><strong>Удаление:</strong> Удаляет прошивку из базы и файл с сервера</li>
        </ul>
        
        <div class="alert alert-info mt-3">
            <strong>💡 Совет:</strong> Файлы прошивок хранятся в папке <code>/firmware/</code>. 
            Убедитесь, что у веб-сервера есть права на запись в эту папку.
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>