<?php
/**
 * Страница списка шаблонов программ
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
$userId = $user['id'];

// Получение списка шаблонов
$db = db();
$templates = $db->fetchAll(
    'SELECT * FROM templates WHERE is_public = 1 ORDER BY category, name',
[]
);

// Фильтрация по категории
$categoryFilter = $_GET['category'] ?? '';
if ($categoryFilter) {
    $templates = array_filter($templates, function ($t) use ($categoryFilter) {
        return $t['category'] === $categoryFilter;
    });
}

// Обработка создания программы из шаблона
if (isMethod('POST') && isset($_POST['action']) && $_POST['action'] === 'create_from_template') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        jsonError('Неверный токен безопасности');
    }

    $templateId = (int)$_POST['template_id'];
    $deviceId = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;

    // Получение шаблона
    $template = getTemplate($templateId);

    if (!$template) {
        jsonError('Шаблон не найден');
    }

    try {
        // Начало транзакции
        $db->beginTransaction();

        // Создание программы из шаблона
        $programId = $db->insert('programs', [
            'user_id' => $userId,
            'device_id' => $deviceId,
            'program_name' => $template['name'] . ' (копия)',
            'description' => $template['description'],
            'category' => $template['category'],
            'created_at' => date('Y-m-d H:i:s')
        ]);

        // Копирование этапов шаблона
        foreach ($template['stages'] as $stage) {
            $db->insert('program_stages', [
                'program_id' => $programId,
                'stage_order' => $stage['stage_order'],
                'stage_name' => $stage['stage_name'],
                'target_temp' => $stage['target_temp'],
                'target_temp_device' => $stage['target_temp_device'],
                'target_humidity' => $stage['target_humidity'],
                'duration_minutes' => $stage['duration_minutes'],
                'hysteresis' => $stage['hysteresis'],
                'wait_for_temp' => $stage['wait_for_temp'],
                'use_smoke_generator' => $stage['use_smoke_generator'],
                'ventilation_percent' => $stage['ventilation_percent'],
                'internal_fan_on' => $stage['internal_fan_on'],
                'injection_fan_on' => $stage['injection_fan_on'],
                'compressor_pwm' => $stage['compressor_pwm']
            ]);
        }

        // Фиксация транзакции
        $db->commit();

        logInfo("Программа #$programId создана из шаблона #$templateId", 'PROGRAMS');
        jsonSuccess(['program_id' => $programId], 'Программа успешно создана из шаблона!');

    }
    catch (Exception $e) {
        $db->rollback();
        logException($e, 'PROGRAMS');
        jsonError('Ошибка при создании программы: ' . $e->getMessage());
    }
}

// Получение списка устройств для привязки
$devices = $db->fetchAll(
    'SELECT id, name FROM devices WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) ORDER BY name',
[$userId]
);

$pageTitle = 'Шаблоны программ';
include __DIR__ . '/templates/header.php';
?>

<!-- Filter Bar -->
<div class="mb-4">
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?php echo BASE_URL; ?>/templates.php" class="btn btn-sm <?php echo !$categoryFilter ? 'btn-primary' : 'btn-outline-primary'; ?>">
            Все
        </a>
        <a href="<?php echo BASE_URL; ?>/templates.php?category=fish" class="btn btn-sm <?php echo $categoryFilter === 'fish' ? 'btn-primary' : 'btn-outline-primary'; ?>">
            🐟 Рыба
        </a>
        <a href="<?php echo BASE_URL; ?>/templates.php?category=meat" class="btn btn-sm <?php echo $categoryFilter === 'meat' ? 'btn-primary' : 'btn-outline-primary'; ?>">
            🥩 Мясо
        </a>
        <a href="<?php echo BASE_URL; ?>/templates.php?category=poultry" class="btn btn-sm <?php echo $categoryFilter === 'poultry' ? 'btn-primary' : 'btn-outline-primary'; ?>">
            🍗 Птица
        </a>
    </div>
</div>

<!-- Templates List -->
<?php if (empty($templates)): ?>
<div class="empty-state">
    <p style="font-size: 3rem; margin-bottom: 20px;">📋</p>
    <p>Шаблоны программ не найдены</p>
    <a href="<?php echo BASE_URL; ?>/programs.php" class="btn btn-primary">
        ⬅️ К программам
    </a>
</div>
<?php
else: ?>
<div class="row">
    <?php foreach ($templates as $template): ?>
    <?php
        $category = $template['category'] ?? 'custom';
        $stagesCount = count($template['stages'] ?? []);
        $totalDuration = 0;
        foreach ($template['stages'] ?? [] as $stage) {
            $totalDuration += $stage['duration_minutes'];
        }

        $emojis = [
            'fish' => '🐟',
            'meat' => '🥩',
            'poultry' => '🍗'
        ];
        $categoryEmoji = $emojis[$category] ?? '📋';

        $names = [
            'fish' => 'Рыба',
            'meat' => 'Мясо',
            'poultry' => 'Птица'
        ];
        $categoryName = $names[$category] ?? 'Свои';
?>
    <div class="col-12 mb-3">
        <div class="card">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0"><?php echo $categoryEmoji . ' ' . e($template['name']); ?></h5>
                        <small class="text-white-50"><?php echo $categoryName; ?></small>
                    </div>
                    <span class="badge bg-light text-dark">Публичный</span>
                </div>
            </div>
            <div class="card-body">
                <?php if ($template['description']): ?>
                <div class="alert alert-info mb-3">
                    ℹ️ <?php echo e($template['description']); ?>
                </div>
                <?php
        endif; ?>
                
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Этапов:</strong> <?php echo $stagesCount; ?>
                    </div>
                    <div class="col-md-6">
                        <strong>Общая длительность:</strong> <?php echo formatDuration($totalDuration); ?>
                    </div>
                </div>
                
                <?php if (!empty($template['stages'])): ?>
                <div class="mb-3">
                    <strong>Этапы программы:</strong>
                    <?php foreach ($template['stages'] as $stage): ?>
                    <div class="border-start border-3 border-primary ps-3 py-2 mb-2">
                        <div class="d-flex justify-content-between">
                            <strong><?php echo e($stage['stage_name']); ?></strong>
                            <span class="text-muted"><?php echo formatDuration($stage['duration_minutes']); ?></span>
                        </div>
                        <small class="text-muted">
                            <?php echo $stage['target_temp_device'] == 0 ? 'Камера' : 'Продукт'; ?>: 
                            <?php echo formatTemperature($stage['target_temp']); ?>, 
                            Влажность: <?php echo formatHumidity($stage['target_humidity']); ?>
                            <?php if ($stage['wait_for_temp']): ?>, ждать температуру<?php
                endif; ?>
                        </small>
                    </div>
                    <?php
            endforeach; ?>
                </div>
                <?php
        endif; ?>
                
                <div class="d-flex justify-content-end">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createModal<?php echo $template['id']; ?>">
                        ➕ Создать программу
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal для создания программы -->
    <div class="modal fade" id="createModal<?php echo $template['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-import"></i> Создать программу из шаблона</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="createForm<?php echo $template['id']; ?>" method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
                    <input type="hidden" name="action" value="create_from_template">
                    <input type="hidden" name="template_id" value="<?php echo $template['id']; ?>">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Шаблон</label>
                            <input type="text" class="form-control" value="<?php echo e($template['name']); ?>" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="device_id_<?php echo $template['id']; ?>" class="form-label">Устройство (опционально)</label>
                            <select class="form-select" id="device_id_<?php echo $template['id']; ?>" name="device_id">
                                <option value="">Общая программа (для всех устройств)</option>
                                <?php foreach ($devices as $device): ?>
                                <option value="<?php echo $device['id']; ?>"><?php echo e($device['name']); ?></option>
                                <?php
        endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">➕ Создать</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php
    endforeach; ?>
</div>
<?php
endif; ?>

<script>
    // Обработка создания программы из шаблона
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form[id^="createForm"]').forEach(function(form) {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                
                const formData = new FormData(this);
                
                fetch('<?php echo BASE_URL; ?>/templates.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                        window.location.href = '<?php echo BASE_URL; ?>/program-edit.php?id=' + data.data.program_id;
                    } else {
                        alert(data.error || 'Произошла ошибка');
                    }
                })
                .catch(error => {
                    alert('Произошла ошибка при создании программы');
                });
            });
        });
    });
</script>

<?php include __DIR__ . '/templates/footer.php'; ?>
