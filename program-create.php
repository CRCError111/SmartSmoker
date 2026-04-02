<?php
/**
 * Страница создания новой программы копчения
 * ИСПРАВЛЕНО: Порядок подключения файлов и обработка ошибок БД
 * 
 * @version 1.1
 * @author Smart Smoker Team
 */

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
$error = '';
$success = '';

// Инициализация подключения к БД с обработкой ошибок
try {
    $db = db(); // Получаем экземпляр базы данных
    
    // Проверка, что подключение успешно
    if (!$db || !$db->getConnection()) {
        throw new Exception('Не удалось установить подключение к базе данных');
    }
    
    // Получение списка устройств
    $devices = $db->fetchAll(
        'SELECT id, name FROM devices WHERE user_id = ? AND (unbound IS NULL OR unbound = 0) ORDER BY name',
        [$userId]
    );
    
    // Получение шаблонов для копирования
    $templates = $db->fetchAll(
        'SELECT id, name, category FROM templates WHERE is_public = 1 ORDER BY category, name',
        []
    );
    
} catch (Exception $e) {
    logException($e, 'PROGRAM_CREATE');
    $error = 'Ошибка инициализации базы данных: ' . $e->getMessage();
    $devices = [];
    $templates = [];
}

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
        } else {
            // Проверка уникальности имени программы
            $existingProgram = $db->fetchOne(
                'SELECT id, program_name as name FROM programs WHERE user_id = ? AND program_name = ?',
                [$userId, $name]
            );
            
            if ($existingProgram) {
                $error = 'Программа с таким названием уже существует. Пожалуйста, выберите другое название.';
            } else {
                try {
                    // Начало транзакции
                    $db->beginTransaction();
                    
                    // Создание программы
                    $programId = $db->insert('programs', [
                        'user_id' => $userId,
                        'device_id' => $deviceId,
                        'program_name' => $name,
                        'description' => $description,
                        'category' => $category,
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                
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
                
                logInfo("Программа #$programId создана: $name", 'PROGRAMS');
                $success = 'Программа успешно создана!';
                
                // Редирект на страницу программ
                header('Location: ' . BASE_URL . '/programs.php?success=created');
                exit;
                
            } catch (Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollback();
                }
                logException($e, 'PROGRAMS');
                $error = 'Ошибка при создании программы: ' . $e->getMessage();
            }
            }
        }
    }
}

// Если есть критическая ошибка инициализации БД - показываем сообщение
if (!empty($error) && strpos($error, 'базы данных') !== false) {
    http_response_code(500);
    echo "<!DOCTYPE html>
    <html lang='ru'>
    <head>
        <meta charset='UTF-8'>
        <title>Ошибка базы данных</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 50px; background: #f8d7da; color: #721c24; }
            .error-container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            h1 { color: #dc3545; }
            .btn { display: inline-block; margin-top: 20px; padding: 10px 20px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='error-container'>
            <h1><i class='fas fa-exclamation-triangle'></i> Критическая ошибка</h1>
            <p>{$error}</p>
            <p>Пожалуйста, проверьте:</p>
            <ul>
                <li>Файл <code>/.env</code> содержит корректные настройки базы данных</li>
                <li>Сервер базы данных доступен</li>
                <li>Учётные данные имеют права на подключение</li>
            </ul>
            <a href='" . BASE_URL . "/dashboard.php' class='btn'>Вернуться на главную</a>
        </div>
    </body>
    </html>";
    exit;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Создать программу - Умная коптильня</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 900px;
            margin: 0 auto;
        }
        
        .form-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .form-header h1 {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .form-header p {
            color: #666;
            font-size: 14px;
        }
        
        .alert {
            border-radius: 8px;
        }
        
        .stage-card {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        
        .stage-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .stage-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #667eea;
            color: white;
            border-radius: 50%;
            text-align: center;
            line-height: 30px;
            font-weight: bold;
        }
        
        .btn-add-stage {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            margin: 20px 0;
        }
        
        .btn-add-stage:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.4);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
        }
        
        .template-select {
            background: #e3f2fd;
            border: 1px solid #2196F3;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-header">
            <h1><i class="fas fa-plus-circle"></i> Создать программу копчения</h1>
            <p>Заполните параметры для новой программы</p>
        </div>
        
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
        
        <form method="POST" action="" id="programForm">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            <input type="hidden" name="stage_count" id="stageCount" value="1">
            
            <!-- Основная информация -->
            <div class="mb-3">
                <label for="name" class="form-label">Название программы *</label>
                <input 
                    type="text" 
                    class="form-control" 
                    id="name" 
                    name="name" 
                    placeholder="Например: Сёмга холодного копчения"
                    value="<?php echo e($_POST['name'] ?? ''); ?>"
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
                    placeholder="Дополнительная информация о программе"
                    maxlength="500"
                ><?php echo e($_POST['description'] ?? ''); ?></textarea>
            </div>
            
            <div class="mb-3">
                <label for="category" class="form-label">Категория *</label>
                <select class="form-select" id="category" name="category" required>
                    <option value="custom"<?php echo ($_POST['category'] ?? '') === 'custom' ? ' selected' : ''; ?>>Свои</option>
                    <option value="fish"<?php echo ($_POST['category'] ?? '') === 'fish' ? ' selected' : ''; ?>>Рыба</option>
                    <option value="meat"<?php echo ($_POST['category'] ?? '') === 'meat' ? ' selected' : ''; ?>>Мясо</option>
                    <option value="poultry"<?php echo ($_POST['category'] ?? '') === 'poultry' ? ' selected' : ''; ?>>Птица</option>
                </select>
            </div>
            
            <div class="mb-3">
                <label for="device_id" class="form-label">Устройство (опционально)</label>
                <select class="form-select" id="device_id" name="device_id">
                    <option value="">Общая программа (для всех устройств)</option>
                    <?php foreach ($devices as $device): ?>
                    <option value="<?php echo $device['id']; ?>"<?php echo ($_POST['device_id'] ?? '') == $device['id'] ? ' selected' : ''; ?>>
                        <?php echo e($device['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Если выбрать устройство, программа будет доступна только для него</div>
            </div>
            
            <hr class="my-4">
            
            <!-- Этапы программы -->
            <h4 class="mb-3">
                <i class="fas fa-tasks"></i> Этапы программы
                <button type="button" class="btn btn-sm btn-add-stage" onclick="addStage()">
                    <i class="fas fa-plus"></i> Добавить этап
                </button>
            </h4>
            
            <div id="stagesContainer">
                <!-- Этап 1 -->
                <div class="stage-card" id="stage_0">
                    <div class="stage-header">
                        <div>
                            <span class="stage-number">1</span>
                            <strong>Этап 1</strong>
                        </div>
                        <button type="button" class="btn btn-sm btn-danger" onclick="removeStage(0)"<?php echo count($_POST) > 0 ? ' style="display:none;"' : ''; ?>>
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Название этапа</label>
                            <input type="text" class="form-control" name="stage_name_0" value="Сушка" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Целевая температура (°C)</label>
                            <input type="number" class="form-control" name="target_temp_0" value="25" step="0.1" min="-50" max="200" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Устройство измерения</label>
                            <select class="form-select" name="target_temp_device_0">
                                <option value="0">Камера</option>
                                <option value="1">Продукт</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Влажность (%)</label>
                            <input type="number" class="form-control" name="target_humidity_0" value="60" min="0" max="100" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Длительность (мин)</label>
                            <input type="number" class="form-control" name="duration_minutes_0" value="240" min="1" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Гистерезис (°C)</label>
                            <input type="number" class="form-control" name="hysteresis_0" value="2" min="0" max="10">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Открытие заслонки (%)</label>
                            <input type="number" class="form-control" name="ventilation_percent_0" value="70" min="0" max="100">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="wait_for_temp_0" id="wait_for_temp_0" checked>
                                <label class="form-check-label" for="wait_for_temp_0">
                                    Ждать достижения температуры
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="use_smoke_generator_0" id="use_smoke_generator_0">
                                <label class="form-check-label" for="use_smoke_generator_0">
                                    Использовать дымогенератор
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="internal_fan_on_0" id="internal_fan_on_0" checked>
                                <label class="form-check-label" for="internal_fan_on_0">
                                    Вентилятор в камере
                                </label>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="injection_fan_on_0" id="injection_fan_on_0" checked>
                                <label class="form-check-label" for="injection_fan_on_0">
                                    Вентилятор подачи воздуха
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">ШИМ компрессора (-1 = авто)</label>
                        <input type="number" class="form-control" name="compressor_pwm_0" value="-1" min="-1" max="255">
                    </div>
                </div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Создать программу
                </button>
                <a href="<?php echo BASE_URL; ?>/programs.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Отмена
                </a>
            </div>
        </form>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let stageCount = 1;
        
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
                            <input type="text" class="form-control" name="stage_name_${stageIndex}" value="Копчение" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Целевая температура (°C)</label>
                            <input type="number" class="form-control" name="target_temp_${stageIndex}" value="25" step="0.1" min="-50" max="200" required>
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
                            <input type="number" class="form-control" name="ventilation_percent_${stageIndex}" value="50" min="0" max="100">
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
                                <input class="form-check-input" type="checkbox" name="use_smoke_generator_${stageIndex}" id="use_smoke_generator_${stageIndex}" checked>
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
    </script>
</body>
</html>