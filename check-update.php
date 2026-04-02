<?php
/**
 * Страница проверки обновлений прошивки
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
$db = db();

// Получение списка доступных обновлений прошивки
$firmwareUpdates = $db->fetchAll(
    'SELECT * FROM firmware_updates 
     WHERE is_active = 1 
     ORDER BY release_date DESC',
    []
);

// Получение списка устройств пользователя
$devices = $db->fetchAll(
    'SELECT * FROM devices 
     WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) 
     ORDER BY name',
    [$user['id']]
);

$pageTitle = 'Обновления прошивки';
include __DIR__ . '/templates/header.php';
?>

<style>
.firmware-card {
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
    padding: 20px;
    margin-bottom: 20px;
    transition: all 0.3s ease;
}

.firmware-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,.15);
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
    font-size: 1.5rem;
    font-weight: 700;
    color: #333;
}

.firmware-badge {
    padding: 6px 12px;
    border-radius: 20px;
    font-size: 0.85rem;
    font-weight: 600;
}

.firmware-info {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
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

.device-list {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
}

.device-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px;
    background: white;
    border-radius: 6px;
    margin-bottom: 10px;
}

.device-item:last-child {
    margin-bottom: 0;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,.1);
}

.empty-state p {
    font-size: 1.2rem;
    color: #666;
    margin: 20px 0;
}
</style>

<!-- Info Alert -->
<div class="alert alert-info">
    <strong>ℹ️ Информация:</strong> Здесь вы можете проверить наличие обновлений прошивки для ваших устройств и установить их.
</div>

<!-- Firmware Updates List -->
<?php if (empty($firmwareUpdates)): ?>
<div class="empty-state">
    <p style="font-size:3rem;margin-bottom:20px">📦</p>
    <p>Нет доступных обновлений прошивки</p>
</div>
<?php else: ?>
<div class="row">
    <?php foreach ($firmwareUpdates as $firmware): ?>
    <div class="col-12 mb-3">
        <div class="firmware-card">
            <div class="firmware-header">
                <div>
                    <div class="firmware-version">
                        🔄 Версия <?= e($firmware['version']) ?>
                    </div>
                    <div class="text-muted small mt-1">
                        📅 Дата выпуска: <?= formatDate($firmware['release_date']) ?>
                    </div>
                </div>
                <?php if ($firmware['is_required']): ?>
                <span class="firmware-badge bg-danger text-white">
                    ⚠️ Обязательное
                </span>
                <?php else: ?>
                <span class="firmware-badge bg-success text-white">
                    ✅ Доступно
                </span>
                <?php endif; ?>
            </div>
            
            <div class="firmware-info">
                <div class="info-item">
                    <span class="info-label">📦 Размер файла</span>
                    <span class="info-value"><?= number_format($firmware['file_size'] / 1024, 2) ?> KB</span>
                </div>
                <div class="info-item">
                    <span class="info-label">🔐 Контрольная сумма</span>
                    <span class="info-value"><?= substr($firmware['checksum'], 0, 16) ?>...</span>
                </div>
                <?php if ($firmware['min_version_required']): ?>
                <div class="info-item">
                    <span class="info-label">⚙️ Минимальная версия</span>
                    <span class="info-value"><?= e($firmware['min_version_required']) ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($firmware['release_notes']): ?>
            <div class="alert alert-light mb-3">
                <strong>📝 Примечания к выпуску:</strong><br>
                <?= nl2br(e($firmware['release_notes'])) ?>
            </div>
            <?php endif; ?>
            
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/firmware/<?= e($firmware['filename']) ?>" 
                   class="btn btn-primary btn-sm" 
                   download>
                    ⬇️ Скачать прошивку
                </a>
                <button type="button" 
                        class="btn btn-outline-primary btn-sm" 
                        data-bs-toggle="modal" 
                        data-bs-target="#devicesModal<?= $firmware['id'] ?>">
                    🖥️ Показать устройства
                </button>
            </div>
        </div>
    </div>
    
    <!-- Devices Modal -->
    <div class="modal fade" id="devicesModal<?= $firmware['id'] ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">🖥️ Ваши устройства</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($devices)): ?>
                    <p class="text-muted">У вас нет устройств</p>
                    <?php else: ?>
                    <div class="device-list">
                        <?php foreach ($devices as $device): ?>
                        <div class="device-item">
                            <div>
                                <strong><?= e($device['name']) ?></strong><br>
                                <small class="text-muted">
                                    Текущая версия: <?= e($device['firmware_version'] ?? 'Неизвестно') ?>
                                </small>
                            </div>
                            <div>
                                <?php
                                $statusInfo = formatDeviceStatus($device['status']);
                                ?>
                                <span class="badge <?= $statusInfo[1] ?>">
                                    <?= $statusInfo[0] ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="alert alert-info mt-3">
                        <strong>ℹ️ Как обновить:</strong><br>
                        1. Скачайте файл прошивки<br>
                        2. Подключитесь к устройству через веб-интерфейс<br>
                        3. Загрузите файл прошивки через раздел "Обновление"<br>
                        4. Дождитесь завершения обновления и перезагрузки устройства
                    </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Закрыть</button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Help Section -->
<div class="card mt-4">
    <div class="card-header">
        <h5 class="mb-0">❓ Помощь по обновлению</h5>
    </div>
    <div class="card-body">
        <h6>Как обновить прошивку устройства:</h6>
        <ol>
            <li>Убедитесь, что устройство подключено к сети и доступно</li>
            <li>Скачайте файл прошивки нужной версии</li>
            <li>Подключитесь к веб-интерфейсу устройства (обычно по IP-адресу устройства)</li>
            <li>Перейдите в раздел "Обновление прошивки" или "Firmware Update"</li>
            <li>Выберите скачанный файл и нажмите "Обновить"</li>
            <li>Дождитесь завершения процесса (обычно 1-2 минуты)</li>
            <li>Устройство автоматически перезагрузится с новой прошивкой</li>
        </ol>
        
        <div class="alert alert-warning">
            <strong>⚠️ Важно:</strong>
            <ul class="mb-0">
                <li>Не отключайте питание устройства во время обновления</li>
                <li>Убедитесь в стабильном подключении к сети</li>
                <li>Сделайте резервную копию настроек перед обновлением</li>
                <li>Обязательные обновления содержат критические исправления безопасности</li>
            </ul>
        </div>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
