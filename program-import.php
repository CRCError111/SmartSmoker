<?php
/**
 * Страница импорта программы из JSON файла
 * 
 * @version 1.1 - ИСПРАВЛЕНО
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::requireAuth();

$user = Auth::user();
$userId = $user['id'];
$error = '';
$success = '';
$previewData = null;

$db = db();

// Получение списка устройств
$devices = $db->fetchAll(
    'SELECT id, name FROM devices WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) ORDER BY name',
    [$userId]
);

// Обработка загрузки файла
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['json_file'])) {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Неверный токен безопасности';
    } else {
        $file = $_FILES['json_file'];
        
        // Проверка типа файла
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($fileExtension !== 'json') {
            $error = 'Файл должен быть в формате JSON';
        } else {
            // Чтение файла
            $jsonContent = file_get_contents($file['tmp_name']);
            $jsonData = json_decode($jsonContent, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $error = 'Неверный формат JSON файла: ' . json_last_error_msg();
            } else {
                // Проверка структуры
                if (!isset($jsonData['data']) || !isset($jsonData['data']['stages'])) {
                    $error = 'Неверная структура файла программы';
                } else {
                    $previewData = $jsonData;
                    
                    // Если нажата кнопка импорта
                    if (isset($_POST['action']) && $_POST['action'] === 'import') {
                        $deviceId = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;
                        $programName = trim($_POST['program_name'] ?? $jsonData['data']['name']);
                        
                        // Импорт программы
                        $programId = importProgramFromJson($jsonData, $userId);
                        
                        if ($programId) {
                            // Обновление имени и устройства
                            if ($programName !== $jsonData['data']['name'] || $deviceId) {
                                $db->update('programs', [
                                    'program_name' => $programName,
                                    'device_id' => $deviceId
                                ], ['id' => $programId]);
                            }
                            
                            Logger::info("Program imported", ['program_id' => $programId, 'user_id' => $userId]);
                            $success = 'Программа успешно импортирована!';
                            
                            // Редирект на страницу программы
                            header('Location: program-edit.php?id=' . $programId);
                            exit;
                        } else {
                            $error = 'Ошибка при импорте программы';
                        }
                    }
                }
            }
        }
    }
}

$pageTitle = 'Импорт программы';
include __DIR__ . '/templates/header.php';
?>
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="" enctype="multipart/form-data" id="importForm">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">

            <!-- Выбор файла -->
            <div class="card mb-4">
                <div class="card-header">📂 Выбор файла программы</div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label form-label-required" for="json_file">JSON файл программы</label>
                        <input type="file" id="json_file" name="json_file" accept=".json" class="form-input" style="padding:8px 12px" required>
                        <p class="form-hint">Поддерживаемый формат: .json (экспорт из Smart Smoker)</p>
                    </div>
                    <button type="submit" class="btn btn-md btn-primary" name="upload_only" value="1">
                        📤 Загрузить и просмотреть
                    </button>
                </div>
            </div>
            
            <?php if ($previewData): ?>
            <!-- Preview Section -->
            <div class="preview-card">
                <div class="preview-header">
                    <div class="preview-title">
                        <i class="fas fa-eye"></i> Предпросмотр программы
                    </div>
                </div>
                
                <div class="mb-3">
                    <strong>Название:</strong> <?php echo e($previewData['data']['name']); ?>
                </div>
                
                <div class="mb-3">
                    <strong>Описание:</strong> <?php echo e($previewData['data']['description'] ?? '—'); ?>
                </div>
                
                <div class="mb-3">
                    <strong>Категория:</strong> 
                    <?php 
                    $category = $previewData['data']['category'] ?? 'custom';
                    echo $category === 'fish' ? 'Рыба' : ($category === 'meat' ? 'Мясо' : ($category === 'poultry' ? 'Птица' : 'Свои'));
                    ?>
                </div>
                
                <div class="mb-3">
                    <strong>Этапов:</strong> <?php echo count($previewData['data']['stages']); ?>
                </div>
                
                <div class="mb-3">
                    <strong>Этапы:</strong>
                    <?php foreach ($previewData['data']['stages'] as $index => $stage): ?>
                    <div class="stage-item">
                        <div class="stage-header">
                            <span><?php echo e($stage['name'] ?? 'Этап ' . ($index + 1)); ?></span>
                            <span class="text-muted"><?php echo formatDuration($stage['duration_minutes'] ?? 0); ?></span>
                        </div>
                        <div class="stage-details">
                            Температура: <?php echo formatTemperature($stage['target_temp'] ?? 0); ?>, 
                            Влажность: <?php echo formatHumidity($stage['target_humidity'] ?? 70); ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <hr class="my-3">
                
                <!-- Additional Settings -->
                <div class="mb-3">
                    <label for="program_name" class="form-label">Название программы (можно изменить)</label>
                    <input 
                        type="text" 
                        class="form-control" 
                        id="program_name" 
                        name="program_name" 
                        value="<?php echo e($previewData['data']['name']); ?>"
                        required
                    >
                </div>
                
                <div class="mb-3">
                    <label for="device_id" class="form-label">Устройство (опционально)</label>
                    <select class="form-select" id="device_id" name="device_id">
                        <option value="">Общая программа (для всех устройств)</option>
                        <?php foreach ($devices as $device): ?>
                        <option value="<?php echo $device['id']; ?>">
                            <?php echo e($device['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <input type="hidden" name="action" value="import">
                
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary">
                        ✅ Импортировать программу
                    </button>
                    <a href="programs.php" class="btn btn-secondary">
                        ← Отмена
                    </a>
                </div>
            </div>
            <?php endif; ?>
        </form>
    </div>
    
    <script>
        // Показываем имя выбранного файла
        document.getElementById('json_file').addEventListener('change', function() {
            const name = this.files[0]?.name;
            if (name) this.closest('.form-group').querySelector('.form-hint').textContent = 'Выбран файл: ' + name;
        });
    </script>

<?php include __DIR__ . '/templates/footer.php'; ?>