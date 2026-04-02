<?php
/**
 * Страница списка программ копчения
 * 
 * @version 1.2 - Разделение программ устройства и общих программ
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAuth();

// Отключаем кеширование для динамической страницы
disableCache();

$user = Auth::user();
$userId = $user['id'];

// Обработка экспорта программы
if (isset($_GET['export']) && isset($_GET['id'])) {
    $programId = (int)$_GET['id'];
    
    try {
        $program = getProgram($programId);
        
        if (!$program || $program['user_id'] != $userId) {
            header('Location: ' . BASE_URL . '/programs.php?error=program_not_found');
            exit;
        }
        
        // Экспорт в JSON
        $jsonData = exportProgramToJson($program);
        $filename = 'program_' . $programId . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $program['name']) . '.json';
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');
        
        echo json_encode($jsonData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
        
    } catch (Exception $e) {
        logException($e, 'EXPORT');
        header('Location: ' . BASE_URL . '/programs.php?error=export_failed');
        exit;
    }
}

$deviceId = isset($_GET['device_id']) ? (int)$_GET['device_id'] : null;

$db = db();

// Получение информации об устройстве (если указан deviceId)
$device = null;
if ($deviceId) {
    $device = $db->fetchOne(
        'SELECT * FROM devices WHERE id = ? AND user_id = ?',
        [$deviceId, $userId]
    );
    
    if (!$device) {
        redirect(BASE_URL . '/devices.php?error=device_not_found');
    }
}

// Получение программ
try {
    if ($deviceId) {
        // Программы, привязанные к устройству
        $devicePrograms = $db->fetchAll(
            'SELECT p.*, d.name as device_name, 
                    (SELECT COUNT(*) FROM program_stages ps WHERE ps.program_id = p.id) as stages_count,
                    (SELECT SUM(duration_minutes) FROM program_stages ps WHERE ps.program_id = p.id) as total_duration
             FROM programs p 
             LEFT JOIN devices d ON d.id = p.device_id
             WHERE p.user_id = ? AND p.device_id = ?
             ORDER BY p.created_at DESC',
            [$userId, $deviceId]
        );
        
        // Общие программы пользователя (без привязки к устройству)
        $generalPrograms = $db->fetchAll(
            'SELECT p.*, d.name as device_name,
                    (SELECT COUNT(*) FROM program_stages ps WHERE ps.program_id = p.id) as stages_count,
                    (SELECT SUM(duration_minutes) FROM program_stages ps WHERE ps.program_id = p.id) as total_duration
             FROM programs p 
             LEFT JOIN devices d ON d.id = p.device_id
             WHERE p.user_id = ? AND p.device_id IS NULL
             ORDER BY p.created_at DESC',
            [$userId]
        );
        
        $programs = []; // Не используется в режиме устройства
    } else {
        // Все программы пользователя + встроенные
        $programs = $db->fetchAll(
            'SELECT p.*, d.name as device_name,
                    (SELECT COUNT(*) FROM program_stages ps WHERE ps.program_id = p.id) as stages_count,
                    (SELECT SUM(duration_minutes) FROM program_stages ps WHERE ps.program_id = p.id) as total_duration
             FROM programs p 
             LEFT JOIN devices d ON d.id = p.device_id
             WHERE (p.user_id = ? OR p.is_built_in = 1)
             ORDER BY p.is_built_in DESC, p.created_at DESC',
            [$userId]
        );
        
        $devicePrograms = [];
        $generalPrograms = [];
    }
} catch (Exception $e) {
    logException($e, 'PROGRAMS');
    $programs = [];
    $devicePrograms = [];
    $generalPrograms = [];
}

// Получение устройств пользователя
$devices = $db->fetchAll(
    'SELECT id, device_id, name FROM devices WHERE user_id = ? AND status = \'active\' ORDER BY name',
    [$userId]
);

$pageTitle = 'Программы копчения';
include __DIR__ . '/templates/header.php';
?>

<!-- Success Messages -->
<?php if (isset($_GET['success'])): ?>
    <?php if ($_GET['success'] === 'created'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Программа успешно создана!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'updated'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Программа успешно обновлена!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'deleted'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Программа успешно удалена!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php elseif ($_GET['success'] === 'assigned'): ?>
        <div class="alert alert-success alert-dismissible fade show">
            ✅ Программа успешно привязана к устройству!
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
<?php endif; ?>

<!-- Кнопки действий -->
<div class="mb-4">
    <a href="program-create.php<?php echo $deviceId ? '?device_id=' . $deviceId : ''; ?>" class="btn btn-primary">
        ➕ Создать программу
    </a>
    <a href="program-import.php" class="btn btn-secondary">
        📥 Импорт
    </a>
</div>

<?php if ($deviceId): ?>
    <!-- Режим просмотра программ устройства -->
    <div class="alert alert-info">
        🖥️ Программы для устройства: <strong><?php echo e($device['name']); ?></strong>
        <a href="programs.php" class="btn btn-sm btn-outline-primary ms-2">
            Показать все программы
        </a>
    </div>
    
    <!-- Программы устройства -->
    <h4 class="mb-3">📋 Программы устройства</h4>
    <?php if (empty($devicePrograms)): ?>
        <div class="alert alert-warning">
            ⚠️ У этого устройства пока нет привязанных программ.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($devicePrograms as $program): ?>
                <?php include __DIR__ . '/templates/program-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <hr class="my-4">
    
    <!-- Общие программы (доступные для привязки) -->
    <h4 class="mb-3">📚 Общие программы (доступны для привязки)</h4>
    <?php if (empty($generalPrograms)): ?>
        <div class="alert alert-info">
            ℹ️ Нет общих программ. <a href="program-create.php">Создайте программу</a> без привязки к устройству.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($generalPrograms as $program): ?>
                <div class="col-md-6 col-lg-4 mb-4">
                    <div class="card h-100 border-secondary">
                        <div class="card-header bg-light">
                            <h5 class="mb-0"><?= htmlspecialchars($program['name']) ?></h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($program['description'])): ?>
                                <p class="card-text"><?= nl2br(htmlspecialchars($program['description'])) ?></p>
                            <?php endif; ?>
                            
                            <div class="mb-3">
                                <?php if (!empty($program['category'])): ?>
                                    <span class="badge bg-secondary me-1">
                                        🏷️ <?= htmlspecialchars($program['category']) ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (isset($program['stages_count']) && $program['stages_count'] > 0): ?>
                                    <span class="badge bg-primary me-1">
                                        📚 <?= $program['stages_count'] ?> <?= declension($program['stages_count'], ['этап', 'этапа', 'этапов']) ?>
                                    </span>
                                <?php endif; ?>
                                
                                <?php if (isset($program['total_duration']) && $program['total_duration'] > 0): ?>
                                    <span class="badge bg-info">
                                        ⏱️ <?= formatDuration($program['total_duration']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="card-footer">
                            <div class="d-grid gap-2">
                                <button type="button" class="btn btn-sm btn-success" 
                                        onclick="assignToDevice(<?= $program['id'] ?>, <?= $deviceId ?>)">
                                    ➕ Привязать к устройству
                                </button>
                                <a href="program-edit.php?id=<?= $program['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    ✏️ Редактировать
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
<?php else: ?>
    <!-- Режим просмотра всех программ -->
    <?php if (empty($programs)): ?>
        <div class="alert alert-warning">
            ⚠️ Программы не найдены. 
            <a href="program-create.php">Создайте первую программу</a>
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($programs as $program): ?>
                <?php include __DIR__ . '/templates/program-card.php'; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
function deleteProgram(programId) {
    if (confirm('Вы уверены, что хотите удалить эту программу?')) {
        fetch('api/programs.php?id=' + programId, {
            method: 'DELETE'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Ошибка удаления: ' + data.error);
            }
        })
        .catch(error => {
            alert('Ошибка: ' + error);
        });
    }
}

function assignToDevice(programId, deviceId) {
    if (confirm('Привязать эту программу к устройству?')) {
        fetch('api/programs.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'assign_to_device',
                program_id: programId,
                device_id: deviceId
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.location.href = 'programs.php?device_id=' + deviceId + '&success=assigned';
            } else {
                alert('Ошибка: ' + data.error);
            }
        })
        .catch(error => {
            alert('Ошибка: ' + error);
        });
    }
}

// Отправка программы на устройство
let _sendProgramId = null;
let _sendProgramName = '';

function sendProgramToDevice(programId, programName) {
    _sendProgramId = programId;
    _sendProgramName = programName;
    document.getElementById('sendProgramModalLabel').textContent = 'Отправить «' + programName + '» на устройство';
    const modal = new bootstrap.Modal(document.getElementById('sendProgramModal'));
    modal.show();
}

function confirmSendProgram() {
    const deviceId = document.getElementById('sendDeviceSelect').value;
    if (!deviceId) {
        alert('Выберите устройство');
        return;
    }

    // Validate UUID format
    const uuidPattern = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
    if (!uuidPattern.test(deviceId)) {
        alert('Пожалуйста, выберите устройство из списка');
        return;
    }

    const btn = document.getElementById('confirmSendBtn');
    btn.disabled = true;
    btn.textContent = 'Отправка...';

    fetch('api/send-program.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({program_id: _sendProgramId, device_id: deviceId})
    })
    .then(r => r.json())
    .then(data => {
        bootstrap.Modal.getInstance(document.getElementById('sendProgramModal')).hide();
        btn.disabled = false;
        btn.textContent = 'Отправить';
        if (data.success) {
            alert('✅ Программа «' + _sendProgramName + '» поставлена в очередь отправки на устройство.');
        } else {
            alert('❌ Ошибка: ' + (data.error || data.message || 'Неизвестная ошибка'));
        }
    })
    .catch(err => {
        bootstrap.Modal.getInstance(document.getElementById('sendProgramModal')).hide();
        btn.disabled = false;
        btn.textContent = 'Отправить';
        alert('❌ Ошибка сети: ' + err.message);
    });
}
</script>

<!-- Модальное окно выбора устройства для отправки программы -->
<div class="modal fade" id="sendProgramModal" tabindex="-1" aria-labelledby="sendProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sendProgramModalLabel"><i class="fas fa-paper-plane"></i> Отправить программу на устройство</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body">
                <label for="sendDeviceSelect" class="form-label">Выберите устройство:</label>
                <select id="sendDeviceSelect" class="form-select">
                    <option value="">-- Выберите устройство --</option>
                    <?php foreach ($devices as $dev): ?>
                        <option value="<?= htmlspecialchars($dev['device_id']) ?>"><?= htmlspecialchars($dev['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-success" id="confirmSendBtn" onclick="confirmSendProgram()">📤 Отправить</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/templates/footer.php'; ?>
