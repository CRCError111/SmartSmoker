<?php
/**
 * Страница ошибок
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Определение константы для доступа к файлам
define('SMART_SMOKER', true);

// Подключение конфигурации
require_once __DIR__ . '/config.php';

// Подключение модулей
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/functions.php';

// Получение кода ошибки
$code = $_GET['code'] ?? 500;
$message = '';

// Определение сообщения в зависимости от кода ошибки
switch ($code) {
    case '403':
        $title = 'Доступ запрещен';
        $message = 'У вас нет прав для доступа к этой странице.';
        $icon = 'fa-lock';
        break;
    case '404':
        $title = 'Страница не найдена';
        $message = 'Запрашиваемая страница не существует.';
        $icon = 'fa-search';
        break;
    case '500':
        $title = 'Внутренняя ошибка сервера';
        $message = 'Произошла ошибка при обработке вашего запроса.';
        $icon = 'fa-exclamation-triangle';
        break;
    default:
        $title = 'Ошибка';
        $message = 'Произошла неизвестная ошибка.';
        $icon = 'fa-times-circle';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($title); ?> - Умная коптильня</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .error-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 60px 40px;
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        
        .error-icon {
            font-size: 8rem;
            margin-bottom: 20px;
            color: #667eea;
        }
        
        .error-code {
            font-size: 3rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 10px;
        }
        
        .error-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #333;
            margin-bottom: 20px;
        }
        
        .error-message {
            color: #666;
            font-size: 1.1rem;
            margin-bottom: 30px;
        }
        
        .btn-home {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 8px;
            padding: 12px 30px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s;
        }
        
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-icon">
            <i class="fas <?php echo $icon; ?>"></i>
        </div>
        
        <div class="error-code"><?php echo e($code); ?></div>
        
        <div class="error-title"><?php echo e($title); ?></div>
        
        <div class="error-message"><?php echo e($message); ?></div>
        
        <a href="<?php echo BASE_URL; ?>" class="btn-home">
            <i class="fas fa-home"></i> На главную
        </a>
    </div>
</body>
</html>