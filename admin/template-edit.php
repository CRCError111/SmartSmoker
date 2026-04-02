<?php
/**
 * Редактирование шаблона программы
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
$templateId = (int)($_GET['id'] ?? 0);

if (!$templateId) {
    redirect(BASE_URL . '/admin/templates.php?error=template_not_found');
}

// Получение шаблона
try {
    $template = $db->fetchOne('SELECT * FROM templates WHERE id = ?', [$templateId]);
    
    if (!$template) {
        redirect(BASE_URL . '/admin/templates.php?error=template_not_found');
    }
    
    $stages = $db->fetchAll(
        'SELECT * FROM template_stages WHERE template_id = ? ORDER BY stage_order',
        [$templateId]
    );
    
} catch (Exception $e) {
    logException($e, 'ADMIN');
    redirect(BASE_URL . '/admin/templates.php?error=load_failed');
}

$error = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'other';
        $isPublic = isset($_POST['is_public']) ? 1 : 0;
        
        if (empty($name)) {
            $error = 'Название шаблона обязательно';
        } else {
            try {
                $db->beginTransaction();
                
                // Обновление шаблона
                $db->update('templates', [
                    'name' => $name,
                    'description' => $description,
                    'category' => $category,
                    'is_public' => $isPublic,
                    'updated_at' => date('Y-m-d H:i:s')
                ], 'id = ?', [$templateId]);
                
                // Удаление старых этапов
                $db->delete('template_stages', 'template_id = ?', [$templateId]);
                
                // Добавление новых этапов
                $stageCount = (int)($_POST['stage_count'] ?? 0);
                
                for ($i = 0; $i < $stageCount; $i++) {
                    $db->insert('template_stages', [
                        'template_id' => $templateId,
                        'stage_order' => $i + 1,
                        'stage_name' => trim($_POST["stage_name_$i"] ?? "Этап " . ($i + 1)),
                        'target_temp' => (float)($_POST["target_temp_$i"] ?? 30.0),
                        'target_temp_device' => isset($_POST["target_temp_device_$i"]) ? 1 : 0,
                        'target_humidity' => (float)($_POST["target_humidity_$i"] ?? 70.0),
                        'duration_minutes' => (int)($_POST["duration_minutes_$i"] ?? 60),
                        'hysteresis' => (int)($_POST["hysteresis_$i"] ?? 2),
                        'wait_for_temp' => isset($_POST["wait_for_temp_$i"]) ? 1 : 0,
                        'use_smoke_generator' => isset($_POST["use_smoke_generator_$i"]) ? 1 : 0,
                        'ventilation_percent' => (int)($_POST["ventilation_percent_$i"] ?? 100),
                        'internal_fan_on' => isset($_POST["internal_fan_on_$i"]) ? 1 : 0,
                        'injection_fan_on' => isset($_POST["injection_fan_on_$i"]) ? 1 : 0,
                        'compressor_pwm' => (int)($_POST["compressor_pwm_$i"] ?? -1)
                    ]);
                }
                
                $db->commit();
                
                AdminAuth::logAction('template_updated', 'template', $templateId, [
                    'name' => $name,
                    'stages' => $stageCount
                ]);
                
                redirect(BASE_URL . '/admin/templates.php?success=template_updated');
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                logException($e, 'ADMIN');
                $error = 'Ошибка при обновлении шаблона: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Редактировать шаблон';
include __DIR__ . '/../templates/header.php';
?>

<style>
    .stage-card {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        border-left: 4px solid #667eea;
    }
</style>

<?php if ($error): ?>
<div class="alert alert-danger">⚠️ <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">✏️ Редактирование шаблона</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="stage_count" id="stageCount" value="<?= count($stages) ?>">
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Название шаблона *</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($template['name']) ?>" required>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Категория *</label>
                    <select name="category" class="form-select" required>
                        <option value="fish" <?= $template['category'] === 'fish' ? 'selected' : '' ?>>Рыба</option>
                        <option value="meat" <?= $template['category'] === 'meat' ? 'selected' : '' ?>>Мясо</option>
                        <option value="poultry" <?= $template['category'] === 'poultry' ? 'selected' : '' ?>>Птица</option>
                        <option value="cheese" <?= $template['category'] === 'cheese' ? 'selected' : '' ?>>Сыр</option>
                        <option value="vegetables" <?= $template['category'] === 'vegetables' ? 'selected' : '' ?>>Овощи</option>
                        <option value="other" <?= $template['category'] === 'other' ? 'selected' : '' ?>>Другое</option>
                    </select>
                </div>
                
                <div class="col-md-3 mb-3">
                    <label class="form-label">Публичность</label>
                    <div class="form-check mt-2">
                        <input type="checkbox" name="is_public" class="form-check-input" id="isPublic" <?= $template['is_public'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isPublic">Публичный</label>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label class="form-label">Описание</label>
                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($template['description']) ?></textarea>
            </div>
            
            <hr class="my-4">
            
            <h5 class="mb-3">
                📋 Этапы шаблона
                <button type="button" class="btn btn-sm btn-success" onclick="addStage()">➕ Добавить этап</button>
            </h5>
            
            <div id="stagesContainer">
                <?php foreach ($stages as $index => $stage): ?>
                <div class="stage-card" id="stage_<?= $index ?>">
                    <div class="d-flex justify-content-between mb-3">
                        <strong>Этап <?= $index + 1 ?></strong>
                        <?php if (count($stages) > 1): ?>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeStage(<?= $index ?>)">🗑️</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label class="form-label">Название</label>
                            <input type="text" class="form-control" name="stage_name_<?= $index ?>" value="<?= htmlspecialchars($stage['stage_name']) ?>" required>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label">Температура (°C)</label>
                            <input type="number" class="form-control" name="target_temp_<?= $index ?>" value="<?= $stage['target_temp'] ?>" step="0.1" required>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label">Влажность (%)</label>
                            <input type="number" class="form-control" name="target_humidity_<?= $index ?>" value="<?= $stage['target_humidity'] ?>" min="0" max="100" required>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label">Длительность (мин)</label>
                            <input type="number" class="form-control" name="duration_minutes_<?= $index ?>" value="<?= $stage['duration_minutes'] ?>" min="1" required>
                        </div>
                        <div class="col-md-2 mb-2">
                            <label class="form-label">Заслонка (%)</label>
                            <input type="number" class="form-control" name="ventilation_percent_<?= $index ?>" value="<?= $stage['ventilation_percent'] ?>" min="0" max="100">
                        </div>
                    </div>
                    
                    <div class="row mt-2">
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="wait_for_temp_<?= $index ?>" <?= $stage['wait_for_temp'] ? 'checked' : '' ?>>
                                <label class="form-check-label">Ждать температуры</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="use_smoke_generator_<?= $index ?>" <?= $stage['use_smoke_generator'] ? 'checked' : '' ?>>
                                <label class="form-check-label">Дымогенератор</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="internal_fan_on_<?= $index ?>" <?= $stage['internal_fan_on'] ? 'checked' : '' ?>>
                                <label class="form-check-label">Вентилятор камеры</label>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="injection_fan_on_<?= $index ?>" <?= $stage['injection_fan_on'] ? 'checked' : '' ?>>
                                <label class="form-check-label">Вентилятор подачи</label>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="d-grid gap-2 mt-4">
                <button type="submit" class="btn btn-primary">💾 Сохранить изменения</button>
                <a href="<?= BASE_URL ?>/admin/templates.php" class="btn btn-secondary">⬅️ Отмена</a>
            </div>
        </form>
    </div>
</div>

<script>
let stageCount = <?= count($stages) ?>;

function addStage() {
    const container = document.getElementById('stagesContainer');
    const stageIndex = stageCount++;
    
    const stageHtml = `
        <div class="stage-card" id="stage_${stageIndex}">
            <div class="d-flex justify-content-between mb-3">
                <strong>Этап ${stageIndex + 1}</strong>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeStage(${stageIndex})">🗑️</button>
            </div>
            
            <div class="row">
                <div class="col-md-4 mb-2">
                    <label class="form-label">Название</label>
                    <input type="text" class="form-control" name="stage_name_${stageIndex}" value="Этап ${stageIndex + 1}" required>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Температура (°C)</label>
                    <input type="number" class="form-control" name="target_temp_${stageIndex}" value="30" step="0.1" required>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Влажность (%)</label>
                    <input type="number" class="form-control" name="target_humidity_${stageIndex}" value="70" min="0" max="100" required>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Длительность (мин)</label>
                    <input type="number" class="form-control" name="duration_minutes_${stageIndex}" value="60" min="1" required>
                </div>
                <div class="col-md-2 mb-2">
                    <label class="form-label">Заслонка (%)</label>
                    <input type="number" class="form-control" name="ventilation_percent_${stageIndex}" value="100" min="0" max="100">
                </div>
            </div>
            
            <div class="row mt-2">
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="wait_for_temp_${stageIndex}" checked>
                        <label class="form-check-label">Ждать температуры</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="use_smoke_generator_${stageIndex}">
                        <label class="form-check-label">Дымогенератор</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="internal_fan_on_${stageIndex}" checked>
                        <label class="form-check-label">Вентилятор камеры</label>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="injection_fan_on_${stageIndex}" checked>
                        <label class="form-check-label">Вентилятор подачи</label>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    container.insertAdjacentHTML('beforeend', stageHtml);
    document.getElementById('stageCount').value = stageCount;
    updateDeleteButtons();
}

function removeStage(index) {
    const container = document.getElementById('stagesContainer');
    const stages = container.querySelectorAll('.stage-card');
    
    if (stages.length <= 1) {
        alert('Должен остаться хотя бы один этап');
        return;
    }
    
    const stageElement = document.getElementById(`stage_${index}`);
    if (stageElement) {
        stageElement.remove();
        
        // Пересчитываем количество оставшихся этапов
        const remainingStages = container.querySelectorAll('.stage-card');
        stageCount = remainingStages.length;
        document.getElementById('stageCount').value = stageCount;
        
        // Обновляем кнопки удаления
        updateDeleteButtons();
    }
}

function updateDeleteButtons() {
    const container = document.getElementById('stagesContainer');
    const stages = container.querySelectorAll('.stage-card');
    
    stages.forEach((stage, idx) => {
        const deleteBtn = stage.querySelector('.btn-danger');
        if (deleteBtn) {
            if (stages.length <= 1) {
                deleteBtn.style.display = 'none';
            } else {
                deleteBtn.style.display = 'inline-block';
            }
        }
    });
}
</script>

<?php include __DIR__ . '/../templates/footer.php'; ?>
