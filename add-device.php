<?php
/**
 * Страница добавления нового устройства
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
                    // Генерация уникального device_id (UUID)
                    $deviceUuid = generateUuid();
                    
                    // Создание устройства
                    $deviceId = $db->insert('devices', [
                        'user_id' => $user['id'],
                        'device_id' => $deviceUuid,
                        'name' => $name,
                        'description' => $description,
                        'status' => 'pending',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                    
                    logInfo("Устройство #$deviceId создано: $name", 'DEVICES');
                    $success = 'Устройство успешно добавлено!';
                    
                    // Редирект на страницу привязки
                    header('Location: ' . BASE_URL . '/bind-device.php?id=' . $deviceId);
                    exit;
                    
                } catch (Exception $e) {
                    logException($e, 'DEVICES');
                    $error = 'Ошибка при создании устройства: ' . $e->getMessage();
                }
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить устройство - Умная коптильня</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .form-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 600px;
            width: 100%;
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
        
        .form-control {
            border-radius: 8px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
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
        
        .alert {
            border-radius: 8px;
        }
        
        .form-label {
            font-weight: 500;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <div class="form-header">
            <h1><i class="fas fa-plus-circle"></i> Добавить устройство</h1>
            <p>Заполните информацию о новой коптильне</p>
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
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            
            <div class="mb-3">
                <label for="name" class="form-label">Название устройства *</label>
                <input 
                    type="text" 
                    class="form-control" 
                    id="name" 
                    name="name" 
                    placeholder="Например: Коптильня на даче"
                    value="<?php echo e($_POST['name'] ?? ''); ?>"
                    required
                    maxlength="100"
                >
                <div class="form-text">Максимум 100 символов</div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label">Описание (опционально)</label>
                <textarea 
                    class="form-control" 
                    id="description" 
                    name="description" 
                    rows="3"
                    placeholder="Дополнительная информация о устройстве"
                    maxlength="1000"
                ><?php echo e($_POST['description'] ?? ''); ?></textarea>
                <div class="form-text">Максимум 1000 символов</div>
            </div>
            
            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Создать устройство
                </button>
                <a href="<?php echo BASE_URL; ?>/devices.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Отмена
                </a>
            </div>
        </form>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>