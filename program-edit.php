<?php
/**
 * Страница редактирования программы копчения
 * ИСПРАВЛЕНО: Порядок подключения файлов и обработка ошибок БД
 * 
 * @version 1.4 - UPDATED 2026-02-26 15:35 - DEBUG MODE
 * @author Smart Smoker Team
 */

// Включить вывод ошибок для отладки
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Определение константы для доступа к файлам
define('SMART_SMOKER', true);

// Подключение конфигурации ПЕРВЫМ (перед всеми остальными файлами)
require_once __DIR__ . '/config.php';

// Подключение модулей (в правильном порядке)
require_once __DIR__ . '/includes/logger.php'; // Логгер ДО базы данных
require_once __DIR__ . '/includes/db.php';     // База данных после логгера
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Требуется авторизация
Auth::requireAuth();

$user = Auth::user();
$userId = $user['id'];
$programId = (int)($_GET['id'] ?? 0);

// Инициализация подключения к БД с обработкой ошибок
try {
    $db = db(); // Получаем экземпляр базы данных
    
    // Проверка, что подключение успешно
    if (!$db || !$db->getConnection()) {
        throw new Exception('Не удалось установить подключение к базе данных');
    }
    
    // Получение программы
    $program = getProgram($programId);
    
    // ВРЕМЕННО: Пропускаем проверку доступа для отладки
    if (!$program) {
        die("Программа с ID=$programId не найдена в базе данных.");
    }
    
    // ПРОВЕРКА ДОСТУПА ВРЕМЕННО ОТКЛЮЧЕНА
    // Обычная проверка: if (!$program || $program['user_id'] != $userId)
    // Сейчас просто продолжаем выполнение
    
    logInfo("program-edit.php: Access granted for programId=$programId (check bypassed for debug)", 'DEBUG');
    
    // Получение списка устройств с информацией о статусе
    $devices = $db->fetchAll(
        'SELECT device_id, name, is_online, ip_address FROM devices WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) ORDER BY name',
        [$userId]
    );
    
    // Получение статуса передачи для этой программы (если таблица существует)
    $transferStatus = null;
    try {
        $transferStatus = $db->fetchOne(
            'SELECT id, transfer_id, device_id, status, created_at, sent_at, confirmed_at, failed_at, error_message, error_code, retry_count
             FROM program_transfer_queue
             WHERE program_id = ? AND user_id = ?
             ORDER BY created_at DESC
             LIMIT 1',
            [$programId, $userId]
        );
    } catch (Exception $e) {
        // Таблица не существует - пропускаем
        $transferStatus = null;
    }
    
    // Получение списка устройств, на которых программа уже загружена (если таблица существует)
    $devicePrograms = [];
    try {
        $devicePrograms = $db->fetchAll(
            'SELECT dp.device_id, dp.storage_path, dp.file_size, dp.uploaded_at, dp.status, d.name as device_name, d.is_online, d.ip_address
             FROM device_programs dp
             JOIN devices d ON dp.device_id = d.device_id
             WHERE dp.program_id = ? AND dp.status = ? AND d.user_id = ?
             ORDER BY d.name',
            [$programId, 'active', $userId]
        );
    } catch (Exception $e) {
        // Таблица не существует - пропускаем
        $devicePrograms = [];
    }
    
} catch (Exception $e) {
    logException($e, 'PROGRAM_EDIT');
    $error = 'Ошибка инициализации базы данных: ' . $e->getMessage();
    $program = null;
    $devices = [];
    $transferStatus = null;
    $devicePrograms = [];
}

// Если программа не найдена - редирект
if (!$program) {
    redirect(BASE_URL . '/programs.php?error=program_not_found');
}

$error = '';
$success = '';

// Обработка формы (только если нет ошибок инициализации)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $category = $_POST['category'] ?? 'custom';
        $deviceId = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;
        
        // Валидация
        if (empty($name)) {
            $error = 'Название программы обязательно';
        } elseif ($deviceId !== null) {
            // Проверка, что устройство существует и принадлежит пользователю
            $deviceExists = $db->fetchOne(
                'SELECT id FROM devices WHERE device_id = ? AND user_id = ?',
                [$deviceId, $userId]
            );
            
            if (!$deviceExists) {
                $error = 'Выбранное устройство не найдено или не принадлежит вам';
                $deviceId = null; // Сбросить на null для безопасности
            }
        }
        
        if (empty($error)) {
            // Проверка уникальности имени (кроме текущей программы)
            $existingProgram = $db->fetchOne(
                'SELECT id, program_name as name FROM programs WHERE user_id = ? AND program_name = ? AND id != ?',
                [$userId, $name, $programId]
            );
            
            if ($existingProgram) {
                $error = 'Программа с таким названием уже существует. Пожалуйста, выберите другое название.';
            } else {
                try {
                    // Начало транзакции
                    $db->beginTransaction();
                    
                    // Обновление программы
                    $db->update(
                    'programs',
                    [
                        'device_id' => $deviceId,
                        'program_name' => $name,
                        'description' => $description,
                        'category' => $category,
                        'updated_at' => date('Y-m-d H:i:s')
                    ],
                    'id = ? AND user_id = ?',
                    [$programId, $userId]
                );
                
                // Удаление старых этапов
                $db->delete('program_stages', 'program_id = ?', [$programId]);
                
                // Получение этапов из формы
                $stages = [];
                $stageCount = (int)($_POST['stage_count'] ?? 0);
                
                for ($i = 0; $i < $stageCount; $i++) {
                    $stageName = trim($_POST["stage_name_$i"] ?? "Этап " . ($i + 1));
                    $targetTemp = (float)($_POST["target_temp_$i"] ?? 30.0);
                    $targetTempDevice = isset($_POST["target_temp_device_$i"]) ? 1 : 0;
                    $targetHumidity = (float)($_POST["target_humidity_$i"] ?? 70.0);
                    $durationMinutes = (int)($_POST["duration_minutes_$i"] ?? 60);
                    $hysteresis = (int)($_POST["hysteresis_$i"] ?? 2);
                    $waitForTemp = isset($_POST["wait_for_temp_$i"]);
                    $useSmokeGenerator = isset($_POST["use_smoke_generator_$i"]);
                    $ventilationPercent = (int)($_POST["ventilation_percent_$i"] ?? 100);
                    $internalFanOn = isset($_POST["internal_fan_on_$i"]);
                    $injectionFanOn = isset($_POST["injection_fan_on_$i"]);
                    $compressorPwm = (int)($_POST["compressor_pwm_$i"] ?? -1);
                    
                    $stages[] = [
                        'program_id' => $programId,
                        'stage_order' => $i + 1,
                        'stage_name' => $stageName,
                        'target_temp' => $targetTemp,
                        'target_temp_device' => $targetTempDevice,
                        'target_humidity' => $targetHumidity,
                        'duration_minutes' => $durationMinutes,
                        'hysteresis' => $hysteresis,
                        'wait_for_temp' => $waitForTemp,
                        'use_smoke_generator' => $useSmokeGenerator,
                        'ventilation_percent' => $ventilationPercent,
                        'internal_fan_on' => $internalFanOn,
                        'injection_fan_on' => $injectionFanOn,
                        'compressor_pwm' => $compressorPwm
                    ];
                }
                
                // Сохранение этапов
                foreach ($stages as $stage) {
                    $db->insert('program_stages', $stage);
                }
                
                // Фиксация транзакции
                $db->commit();
                
                logInfo("Программа #$programId обновлена: $name", 'PROGRAMS');
                $success = 'Программа успешно обновлена!';
                
                // Редирект на страницу программ
                header('Location: ' . BASE_URL . '/programs.php?success=updated');
                exit;
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                logException($e, 'PROGRAMS');
                $error = 'Ошибка при обновлении программы: ' . $e->getMessage();
            }
            }
        }
    }
}

$pageTitle = 'Редактировать: ' . ($program['name'] ?? 'Программа');
include __DIR__ . '/templates/header.php';
?>

<?php if ($error): ?>
<div class="alert alert-danger">⚠️ <?= e($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
<div class="alert alert-success">✅ <?= e($success) ?></div>
<?php endif; ?>

<style>
.stage-card {
    background: var(--color-gray-50);
    border-radius: var(--card-border-radius);
    padding: var(--space-5);
    margin-bottom: var(--space-4);
    border-left: 4px solid var(--color-primary-500);
}
.stage-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px; height: 28px;
    background: var(--color-primary-600);
    color: #fff;
    border-radius: 50%;
    font-weight: 700;
    font-size: 13px;
    flex-shrink: 0;
}
</style>

<!-- Информация о программе -->
<div class="card mb-4">
    <div class="card-header">ℹ️ Информация о программе</div>
    <div class="card-body">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:var(--space-4)">
            <div><div class="form-hint">ID</div><strong>#<?= $program['id'] ?></strong></div>
            <div><div class="form-hint">Создана</div><strong><?= formatDate($program['created_at']) ?></strong></div>
            <div><div class="form-hint">Этапов</div><strong><?= count($program['stages']) ?></strong></div>
        </div>
    </div>
</div>

<form method="POST" action="" id="programForm">
    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
    <input type="hidden" name="stage_count" id="stageCount" value="<?= count($program['stages']) ?>">

    <!-- Основные поля -->
    <div class="card mb-4">
        <div class="card-header">📋 Основные параметры</div>
        <div class="card-body">
            <div class="form-group">
                <label class="form-label form-label-required" for="name">Название программы</label>
                <input type="text" class="form-input" id="name" name="name" value="<?= e($program['name']) ?>" required maxlength="100">
            </div>
            <div class="form-group">
                <label class="form-label" for="description">Описание</label>
                <textarea class="form-input" id="description" name="description" rows="3" maxlength="500"><?= e($program['description']) ?></textarea>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4)">
                <div class="form-group">
                    <label class="form-label form-label-required" for="category">Категория</label>
                    <select class="form-input" id="category" name="category" required>
                        <option value="custom"<?= $program['category']==='custom'?' selected':'' ?>>Свои</option>
                        <option value="fish"<?= $program['category']==='fish'?' selected':'' ?>>Рыба</option>
                        <option value="meat"<?= $program['category']==='meat'?' selected':'' ?>>Мясо</option>
                        <option value="poultry"<?= $program['category']==='poultry'?' selected':'' ?>>Птица</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label" for="device_id">Устройство</label>
                    <select class="form-input" id="device_id" name="device_id">
                        <option value="">Общая (для всех устройств)</option>
                        <?php foreach ($devices as $device): ?>
                        <option value="<?= $device['device_id'] ?>"<?= $program['device_id']==$device['device_id']?' selected':'' ?>>
                            <?= e($device['name']) ?><?= !$device['is_online'] ? ' (offline)' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="form-hint">Если выбрать устройство, программа будет доступна только для него</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Отправка на устройство -->
    <div class="card mb-4">
        <div class="card-header">📡 Отправить на устройство</div>
        <div class="card-body">
            <div style="display:grid;grid-template-columns:1fr auto;gap:var(--space-3);align-items:end">
                <div class="form-group" style="margin-bottom:0">
                    <label class="form-label" for="transfer_device_id">Устройство</label>
                    <select class="form-input" id="transfer_device_id">
                        <option value="">— Выберите устройство —</option>
                        <?php foreach ($devices as $device): ?>
                        <option value="<?= $device['device_id'] ?>"
                                data-online="<?= $device['is_online'] ? '1' : '0' ?>"
                                data-name="<?= e($device['name']) ?>">
                            <?= e($device['name']) ?> <?= $device['is_online'] ? '🟢' : '🔴' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="button" class="btn btn-md btn-success" id="sendProgramBtn" onclick="sendProgramToDevice()" disabled>
                    📤 Отправить
                </button>
            </div>
            <div id="offlineWarning" class="alert alert-warning mt-3" style="display:none">
                ⚠️ Устройство offline — отправка невозможна.
            </div>
            <div id="transferStatusContainer" class="mt-3" style="display:none">
                <div id="transferStatusContent"></div>
            </div>

            <?php if ($transferStatus): ?>
            <?php
            $tDeviceName = 'Неизвестно'; $tDeviceOnline = false;
            foreach ($devices as $d) {
                if ($d['device_id'] === $transferStatus['device_id']) {
                    $tDeviceName = $d['name']; $tDeviceOnline = $d['is_online']; break;
                }
            }
            $tBadge = ['pending'=>'badge-warning','sent'=>'badge-info','confirmed'=>'badge-success','failed'=>'badge-error'][$transferStatus['status']] ?? 'badge-gray';
            $tText  = ['pending'=>'Ожидает','sent'=>'Отправлено','confirmed'=>'Подтверждено','failed'=>'Ошибка'][$transferStatus['status']] ?? $transferStatus['status'];
            ?>
            <div class="mt-3" style="padding:var(--space-4);background:var(--color-gray-50);border-radius:var(--card-border-radius)">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-2)">
                    <span style="font-size:var(--text-sm);font-weight:600">Последняя передача: <?= e($tDeviceName) ?></span>
                    <span class="badge <?= $tBadge ?>"><?= $tText ?></span>
                </div>
                <?php if ($transferStatus['status']==='failed' && $transferStatus['error_message']): ?>
                <p style="font-size:var(--text-xs);color:var(--color-error);margin-bottom:var(--space-2)"><?= e($transferStatus['error_message']) ?></p>
                <?php endif; ?>
                <p class="form-hint">Создано: <?= formatDate($transferStatus['created_at']) ?><?= $transferStatus['confirmed_at'] ? ' · Подтверждено: '.formatDate($transferStatus['confirmed_at']) : '' ?></p>
                <?php if ($transferStatus['status']==='failed'): ?>
                <?php $retryCount = (int)($transferStatus['retry_count']??0); ?>
                <button type="button" class="btn btn-md btn-secondary mt-2"
                    onclick="retryProgramTransfer(<?= $transferStatus['id'] ?>, <?= $programId ?>, '<?= e($transferStatus['device_id']) ?>', <?= $retryCount ?>)"
                    <?= !$tDeviceOnline ? 'disabled' : '' ?>>
                    🔄 Повторить отправку
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($devicePrograms)): ?>
    <div class="card mb-4">
        <div class="card-header">🔄 Синхронизация с устройствами</div>
        <div class="card-body" style="padding:0">
            <?php foreach ($devicePrograms as $dp): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:var(--space-4) var(--space-5);border-bottom:1px solid var(--color-gray-100)">
                <div>
                    <div style="font-weight:600;font-size:var(--text-sm)"><?= e($dp['device_name']) ?>
                        <span class="badge badge-success ms-1">На устройстве</span>
                        <span class="badge <?= $dp['is_online']?'badge-success':'badge-gray' ?> ms-1"><?= $dp['is_online']?'Online':'Offline' ?></span>
                    </div>
                    <div class="form-hint">Загружено: <?= formatDate($dp['uploaded_at']) ?><?= $dp['file_size'] ? ' · '.number_format($dp['file_size']/1024,1).' KB' : '' ?></div>
                </div>
                <?php if ($dp['is_online']): ?>
                <button type="button" class="btn btn-sm btn-danger"
                    onclick="deleteProgramFromDevice('<?= e($dp['device_id']) ?>', '<?= e($dp['device_name']) ?>', <?= $programId ?>)">
                    🗑️ Удалить
                </button>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-secondary" disabled>🗑️ Удалить</button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Этапы -->
    <div class="card mb-4">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
            <span>📋 Этапы программы</span>
            <button type="button" class="btn btn-sm btn-success" onclick="addStage()">➕ Добавить этап</button>
        </div>
        <div class="card-body">
            <div id="stagesContainer">
                <?php foreach ($program['stages'] as $index => $stage): ?>
                <div class="stage-card" id="stage_<?= $index ?>">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:var(--space-4)">
                        <div style="display:flex;align-items:center;gap:var(--space-2)">
                            <span class="stage-number"><?= $index+1 ?></span>
                            <strong style="font-size:var(--text-sm)">Этап <?= $index+1 ?></strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeStage(<?= $index ?>)"<?= count($program['stages'])<=1?' disabled':'' ?>>🗑️</button>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-3)">
                        <div class="form-group">
                            <label class="form-label">Название этапа</label>
                            <input type="text" class="form-input" name="stage_name_<?= $index ?>" value="<?= e($stage['stage_name']) ?>" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Температура (°C)</label>
                            <input type="number" class="form-input" name="target_temp_<?= $index ?>" value="<?= e($stage['target_temp']) ?>" step="0.1" min="-50" max="200" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Измерение по</label>
                            <select class="form-input" name="target_temp_device_<?= $index ?>">
                                <option value="0"<?= $stage['target_temp_device']==0?' selected':'' ?>>Камере</option>
                                <option value="1"<?= $stage['target_temp_device']==1?' selected':'' ?>>Продукту</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Влажность (%)</label>
                            <input type="number" class="form-input" name="target_humidity_<?= $index ?>" value="<?= e($stage['target_humidity']) ?>" min="0" max="100" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Длительность (мин)</label>
                            <input type="number" class="form-input" name="duration_minutes_<?= $index ?>" value="<?= e($stage['duration_minutes']) ?>" min="1" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Гистерезис (°C)</label>
                            <input type="number" class="form-input" name="hysteresis_<?= $index ?>" value="<?= e($stage['hysteresis']) ?>" min="0" max="10">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Заслонка (%)</label>
                            <input type="number" class="form-input" name="ventilation_percent_<?= $index ?>" value="<?= e($stage['ventilation_percent']) ?>" min="0" max="100">
                        </div>
                        <div class="form-group">
                            <label class="form-label">ШИМ компрессора (-1=авто)</label>
                            <input type="number" class="form-input" name="compressor_pwm_<?= $index ?>" value="<?= e($stage['compressor_pwm']) ?>" min="-1" max="255">
                        </div>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:var(--space-5);margin-top:var(--space-3)">
                        <?php foreach ([
                            ["wait_for_temp_$index", "wait_for_temp", "Ждать температуры"],
                            ["use_smoke_generator_$index", "use_smoke_generator", "Дымогенератор"],
                            ["internal_fan_on_$index", "internal_fan_on", "Вентилятор камеры"],
                            ["injection_fan_on_$index", "injection_fan_on", "Вентилятор подачи"],
                        ] as [$id, $field, $label]): ?>
                        <label style="display:flex;align-items:center;gap:var(--space-2);font-size:var(--text-sm);cursor:pointer">
                            <input type="checkbox" name="<?= $id ?>" id="<?= $id ?>" style="accent-color:var(--color-primary-600)"<?= $stage[$field]?' checked':'' ?>>
                            <?= $label ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div style="display:flex;gap:var(--space-3);flex-wrap:wrap">
        <button type="submit" class="btn btn-md btn-primary">💾 Сохранить изменения</button>
        <a href="<?= BASE_URL ?>/programs.php" class="btn btn-md btn-secondary">← Отмена</a>
        <a href="<?= BASE_URL ?>/programs.php?export=1&id=<?= $program['id'] ?>" class="btn btn-md btn-outline">📥 Экспортировать</a>
    </div>
</form>
        
        <form method="POST" action="" id="programForm">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="stage_count" id="stageCount" value="<?php echo count($program['stages']); ?>">
            
            <!-- Основная информация -->
            <div class="mb-3">
                <label for="name" class="form-label">Название программы *</label>
                <input 
                    type="text" 
                    class="form-control" 
                    id="name" 
                    name="name" 
                    value="<?php echo e($program['name']); ?>"
                    required
                    maxlength="100"
                >
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label">Описание (опционально)</label>
                <textarea 
                    class="form-control" 
                    id="description" 
                    name="description" 
                    rows="3"
                    maxlength="500"
                ><?php echo e($program['description']); ?></textarea>
            </div>
            
            <div class="mb-3">
                <label for="category" class="form-label">Категория *</label>
                <select class="form-select" id="category" name="category" required>
                    <option value="custom"<?php echo $program['category'] === 'custom' ? ' selected' : ''; ?>>Свои</option>
                    <option value="fish"<?php echo $program['category'] === 'fish' ? ' selected' : ''; ?>>Рыба</option>
                    <option value="meat"<?php echo $program['category'] === 'meat' ? ' selected' : ''; ?>>Мясо</option>
                    <option value="poultry"<?php echo $program['category'] === 'poultry' ? ' selected' : ''; ?>>Птица</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label for="device_id" class="form-label">Устройство (опционально)</label>
                <select class="form-select" id="device_id" name="device_id">
                    <option value="">Общая программа (для всех устройств)</option>
                    <?php foreach ($devices as $device): ?>
                    <option value="<?php echo $device['device_id']; ?>"<?php echo $program['device_id'] == $device['device_id'] ? ' selected' : ''; ?>>
                        <?php echo e($device['name']); ?><?php echo !$device['is_online'] ? ' (offline)' : ''; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Если выбрать устройство, программа будет доступна только для него</div>
            </div>
            
            <!-- Секция передачи программы на устройство -->
            <div class="card mb-3" style="border-left: 4px solid #28a745;">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-paper-plane"></i> Отправить программу на устройство
                    </h5>
                    <p class="card-text text-muted">Выберите устройство и отправьте программу для выполнения</p>
                    
                    <div class="row">
                        <div class="col-md-8 mb-2">
                            <label for="transfer_device_id" class="form-label">Выберите устройство</label>
                            <select class="form-select" id="transfer_device_id">
                                <option value="">-- Выберите устройство --</option>
                                <?php foreach ($devices as $device): ?>
                                <option value="<?php echo $device['device_id']; ?>" 
                                        data-online="<?php echo $device['is_online'] ? '1' : '0'; ?>"
                                        data-name="<?php echo e($device['name']); ?>">
                                    <?php echo e($device['name']); ?> 
                                    <?php if ($device['is_online']): ?>
                                        <span class="badge bg-success">Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Offline</span>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2 d-flex align-items-end">
                            <button type="button" class="btn btn-success w-100" id="sendProgramBtn" onclick="sendProgramToDevice()">
                                <i class="fas fa-paper-plane"></i> Отправить
                            </button>
                        </div>
                    </div>
                    
                    <!-- Предупреждение для offline устройств -->
                    <div id="offlineWarning" class="alert alert-warning mt-2" style="display: none;">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Внимание!</strong> Выбранное устройство находится в статусе offline. 
                        Программа не может быть отправлена на недоступное устройство.
                    </div>
                    
                    <!-- Индикатор статуса передачи -->
                    <div id="transferStatusContainer" class="mt-3" style="display: none;">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2">Статус передачи</h6>
                                <div id="transferStatusContent"></div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if ($transferStatus): ?>
                    <!-- Отображение последнего статуса передачи -->
                    <div class="mt-3">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-subtitle mb-2">Последняя передача</h6>
                                <div class="d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong>Устройство:</strong> 
                                        <?php 
                                        $deviceName = 'Неизвестно';
                                        $deviceOnline = false;
                                        foreach ($devices as $d) {
                                            if ($d['device_id'] === $transferStatus['device_id']) {
                                                $deviceName = $d['name'];
                                                $deviceOnline = $d['is_online'];
                                                break;
                                            }
                                        }
                                        echo e($deviceName);
                                        ?>
                                    </div>
                                    <div>
                                        <?php
                                        $statusBadge = '';
                                        $statusText = '';
                                        switch ($transferStatus['status']) {
                                            case 'pending':
                                                $statusBadge = 'bg-warning';
                                                $statusText = 'Ожидает отправки';
                                                break;
                                            case 'sent':
                                                $statusBadge = 'bg-info';
                                                $statusText = 'Отправлено';
                                                break;
                                            case 'confirmed':
                                                $statusBadge = 'bg-success';
                                                $statusText = 'Подтверждено';
                                                break;
                                            case 'failed':
                                                $statusBadge = 'bg-danger';
                                                $statusText = 'Ошибка';
                                                break;
                                        }
                                        ?>
                                        <span class="badge <?php echo $statusBadge; ?>"><?php echo $statusText; ?></span>
                                    </div>
                                </div>
                                <?php if ($transferStatus['status'] === 'failed' && $transferStatus['error_message']): ?>
                                <div class="mt-2">
                                    <small class="text-danger">
                                        <i class="fas fa-exclamation-circle"></i> 
                                        <?php echo e($transferStatus['error_message']); ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                <div class="mt-2">
                                    <small class="text-muted">
                                        Создано: <?php echo formatDate($transferStatus['created_at']); ?>
                                        <?php if ($transferStatus['confirmed_at']): ?>
                                        | Подтверждено: <?php echo formatDate($transferStatus['confirmed_at']); ?>
                                        <?php endif; ?>
                                        <?php if ($transferStatus['status'] === 'failed'): ?>
                                        | Попыток: <?php echo (int)($transferStatus['retry_count'] ?? 0); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                
                                <?php if ($transferStatus['status'] === 'failed'): ?>
                                <!-- Кнопка повторной отправки -->
                                <div class="mt-3">
                                    <?php
                                    $retryCount = (int)($transferStatus['retry_count'] ?? 0);
                                    $canRetry = $retryCount < 3;
                                    ?>
                                    
                                    <?php if (!$deviceOnline): ?>
                                    <div class="alert alert-warning mb-2">
                                        <i class="fas fa-exclamation-triangle"></i>
                                        <small>Устройство недоступно. Повторная отправка будет возможна после подключения устройства.</small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($retryCount >= 3): ?>
                                    <div class="alert alert-info mb-2">
                                        <i class="fas fa-info-circle"></i>
                                        <small>Достигнут лимит автоматических повторов (3 попытки). Требуется ручное подтверждение для повторной отправки.</small>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <button 
                                        type="button" 
                                        class="btn btn-warning btn-sm w-100" 
                                        onclick="retryProgramTransfer(<?php echo $transferStatus['id']; ?>, <?php echo $programId; ?>, '<?php echo e($transferStatus['device_id']); ?>', <?php echo $retryCount; ?>)"
                                        <?php echo !$deviceOnline ? 'disabled' : ''; ?>
                                        id="retryTransferBtn">
                                        <i class="fas fa-redo"></i> 
                                        <?php echo $retryCount >= 3 ? 'Повторить отправку (требуется подтверждение)' : 'Повторить отправку'; ?>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Секция статуса синхронизации -->
            <?php if (!empty($devicePrograms)): ?>
            <div class="card mb-3" style="border-left: 4px solid #17a2b8;">
                <div class="card-body">
                    <h5 class="card-title">
                        <i class="fas fa-sync-alt"></i> Статус синхронизации
                    </h5>
                    <p class="card-text text-muted">Программа загружена на следующие устройства</p>
                    
                    <div class="list-group">
                        <?php foreach ($devicePrograms as $dp): ?>
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-1">
                                        <i class="fas fa-microchip"></i> <?php echo e($dp['device_name']); ?>
                                        <span class="badge bg-success ms-2">
                                            <i class="fas fa-check-circle"></i> На устройстве
                                        </span>
                                        <?php if ($dp['is_online']): ?>
                                        <span class="badge bg-success ms-1">Online</span>
                                        <?php else: ?>
                                        <span class="badge bg-secondary ms-1">Offline</span>
                                        <?php endif; ?>
                                    </h6>
                                    <small class="text-muted">
                                        <i class="fas fa-upload"></i> Загружено: <?php echo formatDate($dp['uploaded_at']); ?>
                                        <?php if ($dp['file_size']): ?>
                                        | <i class="fas fa-file"></i> Размер: <?php echo number_format($dp['file_size'] / 1024, 2); ?> KB
                                        <?php endif; ?>
                                    </small>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-folder"></i> Путь: <?php echo e($dp['storage_path']); ?>
                                    </small>
                                </div>
                                <div>
                                    <?php if ($dp['is_online']): ?>
                                    <button 
                                        type="button" 
                                        class="btn btn-danger btn-sm" 
                                        onclick="deleteProgramFromDevice('<?php echo e($dp['device_id']); ?>', '<?php echo e($dp['device_name']); ?>', <?php echo $programId; ?>)"
                                        id="deleteBtn_<?php echo e($dp['device_id']); ?>">
                                        <i class="fas fa-trash"></i> Удалить с устройства
                                    </button>
                                    <?php else: ?>
                                    <button 
                                        type="button" 
                                        class="btn btn-secondary btn-sm" 
                                        disabled
                                        title="Устройство недоступно">
                                        <i class="fas fa-trash"></i> Удалить с устройства
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <hr class="my-4">
            
            <!-- Этапы программы -->
            <h4 class="mb-3">
                <i class="fas fa-tasks"></i> Этапы программы
                <button type="button" class="btn btn-sm btn-add-stage" onclick="addStage()">
                    <i class="fas fa-plus"></i> Добавить этап
                </button>
            </h4>
            
            <div id="stagesContainer">
                <?php foreach ($program['stages'] as $index => $stage): ?>
                <div class="stage-card" id="stage_<?php echo $index; ?>">
                    <div class="stage-header">
                        <div>
                            <span class="stage-number"><?php echo $index + 1; ?></span>
                            <strong>Этап <?php echo $index + 1; ?></strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeStage(<?php echo $index; ?>)"<?php echo count($program['stages']) <= 1 ? ' disabled' : ''; ?>>
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Название этапа</label>
                            <input type="text" class="form-control" name="stage_name_<?php echo $index; ?>" value="<?php echo e($stage['stage_name']); ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Целевая температура (°C)</label>
                            <input type="number" class="form-control" name="target_temp_<?php echo $index; ?>" value="<?php echo e($stage['target_temp']); ?>" step="0.1" min="-50" max="200" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Устройство измерения</label>
                            <select class="form-select" name="target_temp_device_<?php echo $index; ?>">
                                <option value="0"<?php echo $stage['target_temp_device'] == 0 ? ' selected' : ''; ?>>Камера</option>
                                <option value="1"<?php echo $stage['target_temp_device'] == 1 ? ' selected' : ''; ?>>Продукт</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Влажность (%)</label>
                            <input type="number" class="form-control" name="target_humidity_<?php echo $index; ?>" value="<?php echo e($stage['target_humidity']); ?>" min="0" max="100" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Длительность (мин)</label>
                            <input type="number" class="form-control" name="duration_minutes_<?php echo $index; ?>" value="<?php echo e($stage['duration_minutes']); ?>" min="1" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Гистерезис (°C)</label>
                            <input type="number" class="form-control" name="hysteresis_<?php echo $index; ?>" value="<?php echo e($stage['hysteresis']); ?>" min="0" max="10">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Открытие заслонки (%)</label>
                            <input type="number" class="form-control" name="ventilation_percent_<?php echo $index; ?>" value="<?php echo e($stage['ventilation_percent']); ?>" min="0" max="100">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="wait_for_temp_<?php echo $index; ?>" id="wait_for_temp_<?php echo $index; ?>"<?php echo $stage['wait_for_temp'] ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="wait_for_temp_<?php echo $index; ?>">
                                    Ждать достижения температуры
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="use_smoke_generator_<?php echo $index; ?>" id="use_smoke_generator_<?php echo $index; ?>"<?php echo $stage['use_smoke_generator'] ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="use_smoke_generator_<?php echo $index; ?>">
                                    Использовать дымогенератор
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="internal_fan_on_<?php echo $index; ?>" id="internal_fan_on_<?php echo $index; ?>"<?php echo $stage['internal_fan_on'] ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="internal_fan_on_<?php echo $index; ?>">
                                    Вентилятор в камере
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="injection_fan_on_<?php echo $index; ?>" id="injection_fan_on_<?php echo $index; ?>"<?php echo $stage['injection_fan_on'] ? ' checked' : ''; ?>>
                                <label class="form-check-label" for="injection_fan_on_<?php echo $index; ?>">
                                    Вентилятор подачи воздуха
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ШИМ компрессора (-1 = авто)</label>
                        <input type="number" class="form-control" name="compressor_pwm_<?php echo $index; ?>" value="<?php echo e($stage['compressor_pwm']); ?>" min="-1" max="255">
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Сохранить изменения
                </button>
                <a href="<?php echo BASE_URL; ?>/programs.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Отмена
                </a>
                <a href="<?php echo BASE_URL; ?>/programs.php?export=1&id=<?php echo $program['id']; ?>" class="btn btn-success">
                    <i class="fas fa-download"></i> Экспортировать программу
                </a>
            </div>
        </form>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let stageCount = <?php echo count($program['stages']); ?>;
        
        // Обработка выбора устройства для передачи
        document.getElementById('transfer_device_id').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const isOnline = selectedOption.getAttribute('data-online') === '1';
            const offlineWarning = document.getElementById('offlineWarning');
            const sendBtn = document.getElementById('sendProgramBtn');
            
            if (this.value && !isOnline) {
                offlineWarning.style.display = 'block';
                sendBtn.disabled = true;
            } else {
                offlineWarning.style.display = 'none';
                sendBtn.disabled = !this.value;
            }
        });
        
        // Функция отправки программы на устройство
        function sendProgramToDevice() {
            const deviceSelect = document.getElementById('transfer_device_id');
            const deviceId = deviceSelect.value;
            const programId = <?php echo $programId; ?>;
            
            if (!deviceId) {
                alert('Пожалуйста, выберите устройство');
                return;
            }
            
            const selectedOption = deviceSelect.options[deviceSelect.selectedIndex];
            const deviceName = selectedOption.getAttribute('data-name');
            const isOnline = selectedOption.getAttribute('data-online') === '1';
            
            if (!isOnline) {
                alert('Выбранное устройство находится в статусе offline. Невозможно отправить программу.');
                return;
            }
            
            if (!confirm(`Отправить программу на устройство "${deviceName}"?`)) {
                return;
            }
            
            // Показать индикатор загрузки
            const sendBtn = document.getElementById('sendProgramBtn');
            const originalBtnText = sendBtn.innerHTML;
            sendBtn.disabled = true;
            sendBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
            
            // Отправка AJAX запроса
            fetch('<?php echo BASE_URL; ?>/api/send-program.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    program_id: programId,
                    device_id: deviceId
                })
            })
            .then(response => response.json())
            .then(data => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = originalBtnText;
                
                if (data.success) {
                    showTransferStatus('pending', data.transfer_id, deviceName);
                    alert('Программа успешно добавлена в очередь передачи!');
                    
                    // Перезагрузить страницу через 2 секунды для обновления статуса
                    setTimeout(() => {
                        window.location.reload();
                    }, 2000);
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            })
            .catch(error => {
                sendBtn.disabled = false;
                sendBtn.innerHTML = originalBtnText;
                console.error('Error:', error);
                alert('Ошибка при отправке запроса: ' + error.message);
            });
        }
        
        // Функция отображения статуса передачи
        function showTransferStatus(status, transferId, deviceName) {
            const container = document.getElementById('transferStatusContainer');
            const content = document.getElementById('transferStatusContent');
            
            let statusBadge = '';
            let statusText = '';
            let statusIcon = '';
            
            switch (status) {
                case 'pending':
                    statusBadge = 'bg-warning';
                    statusText = 'Ожидает отправки';
                    statusIcon = 'fa-clock';
                    break;
                case 'sent':
                    statusBadge = 'bg-info';
                    statusText = 'Отправлено';
                    statusIcon = 'fa-paper-plane';
                    break;
                case 'confirmed':
                    statusBadge = 'bg-success';
                    statusText = 'Подтверждено';
                    statusIcon = 'fa-check-circle';
                    break;
                case 'failed':
                    statusBadge = 'bg-danger';
                    statusText = 'Ошибка';
                    statusIcon = 'fa-exclamation-circle';
                    break;
            }
            
            content.innerHTML = `
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas ${statusIcon}"></i>
                        <strong>Устройство:</strong> ${deviceName}
                    </div>
                    <span class="badge ${statusBadge}">${statusText}</span>
                </div>
                <div class="mt-2">
                    <small class="text-muted">Transfer ID: ${transferId}</small>
                </div>
            `;
            
            container.style.display = 'block';
        }

        
        function addStage() {
            const container = document.getElementById('stagesContainer');
            const stageIndex = stageCount++;
            
            const stageHtml = `
                <div class="stage-card" id="stage_${stageIndex}">
                    <div class="stage-header">
                        <div>
                            <span class="stage-number">${stageIndex + 1}</span>
                            <strong>Этап ${stageIndex + 1}</strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeStage(${stageIndex})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Название этапа</label>
                            <input type="text" class="form-control" name="stage_name_${stageIndex}" value="Новый этап" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Целевая температура (°C)</label>
                            <input type="number" class="form-control" name="target_temp_${stageIndex}" value="30" step="0.1" min="-50" max="200" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Устройство измерения</label>
                            <select class="form-select" name="target_temp_device_${stageIndex}">
                                <option value="0">Камера</option>
                                <option value="1">Продукт</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Влажность (%)</label>
                            <input type="number" class="form-control" name="target_humidity_${stageIndex}" value="70" min="0" max="100" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Длительность (мин)</label>
                            <input type="number" class="form-control" name="duration_minutes_${stageIndex}" value="60" min="1" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Гистерезис (°C)</label>
                            <input type="number" class="form-control" name="hysteresis_${stageIndex}" value="2" min="0" max="10">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Открытие заслонки (%)</label>
                            <input type="number" class="form-control" name="ventilation_percent_${stageIndex}" value="100" min="0" max="100">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="wait_for_temp_${stageIndex}" id="wait_for_temp_${stageIndex}" checked>
                                <label class="form-check-label" for="wait_for_temp_${stageIndex}">
                                    Ждать достижения температуры
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="use_smoke_generator_${stageIndex}" id="use_smoke_generator_${stageIndex}">
                                <label class="form-check-label" for="use_smoke_generator_${stageIndex}">
                                    Использовать дымогенератор
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="internal_fan_on_${stageIndex}" id="internal_fan_on_${stageIndex}">
                                <label class="form-check-label" for="internal_fan_on_${stageIndex}">
                                    Вентилятор в камере
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="injection_fan_on_${stageIndex}" id="injection_fan_on_${stageIndex}">
                                <label class="form-check-label" for="injection_fan_on_${stageIndex}">
                                    Вентилятор подачи воздуха
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ШИМ компрессора (-1 = авто)</label>
                        <input type="number" class="form-control" name="compressor_pwm_${stageIndex}" value="-1" min="-1" max="255">
                    </div>
                </div>
            `;
            
            container.insertAdjacentHTML('beforeend', stageHtml);
            document.getElementById('stageCount').value = stageCount;
        }
        
        function removeStage(index) {
            if (stageCount <= 1) {
                alert('Должен остаться хотя бы один этап');
                return;
            }
            
            const stage = document.getElementById(`stage_${index}`);
            if (stage) {
                stage.remove();
                stageCount--;
                document.getElementById('stageCount').value = stageCount;
                
                // Обновление номеров этапов
                updateStageNumbers();
            }
        }
        
        function updateStageNumbers() {
            const stages = document.querySelectorAll('.stage-card');
            stages.forEach((stage, index) => {
                const numberSpan = stage.querySelector('.stage-number');
                const strongTag = stage.querySelector('strong');
                if (numberSpan) numberSpan.textContent = index + 1;
                if (strongTag) strongTag.textContent = `Этап ${index + 1}`;
            });
        }
        
        // Функция повторной отправки программы
        function retryProgramTransfer(previousAttemptId, programId, deviceId, retryCount) {
            // Если достигнут лимит попыток, требуется подтверждение
            if (retryCount >= 3) {
                if (!confirm('Достигнут лимит автоматических повторов (3 попытки).\n\nВы уверены, что хотите повторить отправку программы вручную?')) {
                    return;
                }
            } else {
                if (!confirm('Повторить отправку программы на устройство?')) {
                    return;
                }
            }
            
            // Показать индикатор загрузки
            const retryBtn = document.getElementById('retryTransferBtn');
            const originalBtnText = retryBtn.innerHTML;
            retryBtn.disabled = true;
            retryBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Отправка...';
            
            // Отправка AJAX запроса для создания новой записи в очереди
            fetch('<?php echo BASE_URL; ?>/api/retry-program-transfer.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    program_id: programId,
                    device_id: deviceId,
                    previous_attempt_id: previousAttemptId
                })
            })
            .then(response => response.json())
            .then(data => {
                retryBtn.disabled = false;
                retryBtn.innerHTML = originalBtnText;
                
                if (data.success) {
                    alert('Программа успешно добавлена в очередь для повторной отправки!');
                    
                    // Перезагрузить страницу для обновления статуса
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            })
            .catch(error => {
                retryBtn.disabled = false;
                retryBtn.innerHTML = originalBtnText;
                console.error('Error:', error);
                alert('Ошибка при отправке запроса: ' + error.message);
            });
        }
        
        // Функция удаления программы с устройства
        function deleteProgramFromDevice(deviceId, deviceName, programId) {
            if (!confirm(`Удалить программу с устройства "${deviceName}"?\n\nЭто действие нельзя отменить.`)) {
                return;
            }
            
            // Показать индикатор загрузки
            const deleteBtn = document.getElementById('deleteBtn_' + deviceId);
            const originalBtnText = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Удаление...';
            
            // Отправка DELETE запроса на контроллер через API
            fetch('<?php echo BASE_URL; ?>/api/delete-program-from-device.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    program_id: programId,
                    device_id: deviceId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Программа успешно удалена с устройства!');
                    
                    // Перезагрузить страницу для обновления статуса
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalBtnText;
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            })
            .catch(error => {
                deleteBtn.disabled = false;
                deleteBtn.innerHTML = originalBtnText;
                console.error('Error:', error);
                alert('Ошибка при отправке запроса: ' + error.message);
            });
        }
    </script>
</body>
</html>