<?php
/**
 * Страница восстановления пароля
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

// Определение константы для доступа к файлам
define('SMART_SMOKER', true);

// Подключение конфигурации
require_once __DIR__ . '/config.php';

// Подключение модулей (в правильном порядке)
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

// Если пользователь уже авторизован — перенаправляем в панель
if (Auth::check()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';
$success = '';
$step = 'request'; // request | reset

// Шаг 1: Запрос на восстановление пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request') {
    // Проверка защиты от брутфорса
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!Auth::checkRateLimit($ip)) {
        $error = 'Слишком много попыток. Попробуйте позже.';
    } else {
        // Проверка CSRF-токена
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Auth::verifyCsrfToken($csrfToken)) {
            $error = 'Неверный токен безопасности';
        } else {
            $email = trim($_POST['email'] ?? '');
            
            if (!validateEmail($email)) {
                $error = 'Неверный формат email адреса';
            } else {
                // Проверка существования пользователя
                $user = db()->fetchOne(
                    'SELECT id, email, full_name FROM users WHERE email = ? AND is_active = 1',
                    [$email]
                );
                
                if (!$user) {
                    // Не сообщаем, что пользователь не найден (защита от перебора)
                    $success = 'Если такой email существует в системе, на него будет отправлена инструкция для восстановления пароля.';
                } else {
                    // Генерация токена восстановления
                    $resetToken = bin2hex(random_bytes(32));
                    $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 час
                    
                    // Сохранение токена в сессии (для простоты; в продакшене — в БД)
                    $_SESSION['reset_token'] = $resetToken;
                    $_SESSION['reset_email'] = $email;
                    $_SESSION['reset_expires'] = $expiresAt;
                    
                    // В реальном приложении здесь отправлялось бы письмо с ссылкой
                    // Для демо-версии просто показываем токен на экране
                    
                    $success = 'Токен для восстановления пароля: <strong>' . substr($resetToken, 0, 8) . '...</strong><br>
                                Скопируйте его и введите на следующем шаге.<br>
                                <small class="text-muted">В продакшен-версии токен приходит на email.</small>';
                    $step = 'reset';
                }
                
                logInfo("Запрос восстановления пароля для: $email", 'AUTH');
            }
        }
    }
}

// Шаг 2: Сброс пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset') {
    // Проверка токена
    $token = $_POST['token'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $newPasswordConfirm = $_POST['new_password_confirm'] ?? '';
    
    // Проверка валидности токена
    if (!isset($_SESSION['reset_token']) || 
        !hash_equals($_SESSION['reset_token'], $token) || 
        strtotime($_SESSION['reset_expires']) < time()) {
        $error = 'Неверный или просроченный токен восстановления';
    } 
    // Валидация пароля
    elseif ($newPassword !== $newPasswordConfirm) {
        $error = 'Пароли не совпадают';
    } else {
        list($valid, $message) = validatePassword($newPassword);
        if (!$valid) {
            $error = $message;
        } else {
            try {
                // Хеширование нового пароля
                $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
                
                // Обновление пароля в БД
                $result = db()->update(
                    'users',
                    ['password_hash' => $passwordHash],
                    'email = ? AND is_active = 1',
                    [$_SESSION['reset_email']]
                );
                
                if ($result > 0) {
                    // Очистка сессии
                    unset($_SESSION['reset_token']);
                    unset($_SESSION['reset_email']);
                    unset($_SESSION['reset_expires']);
                    
                    $success = 'Пароль успешно изменён! Теперь вы можете войти с новым паролем.';
                    $step = 'request';
                    
                    logInfo("Пароль успешно сброшен для: " . $_SESSION['reset_email'], 'AUTH');
                } else {
                    $error = 'Ошибка при сбросе пароля';
                }
            } catch (Exception $e) {
                logException($e, 'AUTH');
                $error = 'Ошибка при сбросе пароля: ' . $e->getMessage();
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
    <title>Восстановление пароля - Умная коптильня</title>
    
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
        
        .recovery-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 450px;
            width: 100%;
        }
        
        .recovery-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .recovery-header h1 {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .recovery-header p {
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
        
        .alert {
            border-radius: 8px;
        }
        
        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-link:hover {
            text-decoration: underline;
        }
        
        .password-requirements {
            font-size: 0.85rem;
            color: #666;
            margin-top: 5px;
            padding-left: 20px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .step {
            text-align: center;
        }
        
        .step-number {
            display: inline-block;
            width: 30px;
            height: 30px;
            background: #e9ecef;
            color: #6c757d;
            border-radius: 50%;
            line-height: 30px;
            font-weight: bold;
        }
        
        .step.active .step-number {
            background: #667eea;
            color: white;
        }
        
        .step.completed .step-number {
            background: #28a745;
            color: white;
        }
        
        .step-label {
            font-size: 0.9rem;
            margin-top: 5px;
            color: #6c757d;
        }
        
        .step.active .step-label,
        .step.completed .step-label {
            color: #333;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="recovery-container">
        <div class="recovery-header">
            <h1><i class="fas fa-key"></i> Восстановление пароля</h1>
            <p>Введите данные для восстановления доступа к аккаунту</p>
        </div>
        
        <div class="step-indicator">
            <div class="step <?php echo $step === 'request' ? 'active' : ($step === 'reset' ? 'completed' : ''); ?>">
                <div class="step-number">1</div>
                <div class="step-label">Email</div>
            </div>
            <div class="step <?php echo $step === 'reset' ? 'active' : ''; ?>">
                <div class="step-number">2</div>
                <div class="step-label">Новый пароль</div>
            </div>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle"></i> <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo $success; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($step === 'request'): ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="request">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            
            <div class="mb-4">
                <label for="email" class="form-label">Email *</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                    <input 
                        type="email" 
                        class="form-control" 
                        id="email" 
                        name="email" 
                        placeholder="Введите ваш email"
                        value="<?php echo e($_POST['email'] ?? ''); ?>"
                        required
                    >
                </div>
                <div class="form-text">
                    На этот email будет отправлена инструкция для восстановления пароля
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3">
                <i class="fas fa-paper-plane"></i> Отправить инструкцию
            </button>
        </form>
        <?php else: ?>
        <form method="POST" action="">
            <input type="hidden" name="action" value="reset">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            
            <div class="mb-3">
                <label for="token" class="form-label">Токен восстановления *</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-key"></i></span>
                    <input 
                        type="text" 
                        class="form-control" 
                        id="token" 
                        name="token" 
                        placeholder="Введите токен из письма"
                        required
                    >
                </div>
            </div>
            
            <div class="mb-3">
                <label for="new_password" class="form-label">Новый пароль *</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input 
                        type="password" 
                        class="form-control" 
                        id="new_password" 
                        name="new_password" 
                        placeholder="Введите новый пароль"
                        required
                        minlength="8"
                    >
                </div>
                <ul class="password-requirements">
                    <li>Минимум 8 символов</li>
                    <li>Должны быть буквы и цифры</li>
                </ul>
            </div>
            
            <div class="mb-4">
                <label for="new_password_confirm" class="form-label">Подтверждение пароля *</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input 
                        type="password" 
                        class="form-control" 
                        id="new_password_confirm" 
                        name="new_password_confirm" 
                        placeholder="Повторите новый пароль"
                        required
                        minlength="8"
                    >
                </div>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3">
                <i class="fas fa-save"></i> Сохранить новый пароль
            </button>
            
            <div class="text-center">
                <a href="<?php echo BASE_URL; ?>/forgot-password.php" class="login-link">
                    <i class="fas fa-arrow-left"></i> Вернуться к шагу 1
                </a>
            </div>
        </form>
        <?php endif; ?>
        
        <div class="text-center mt-4">
            <a href="<?php echo BASE_URL; ?>/login.php" class="login-link">
                <i class="fas fa-sign-in-alt"></i> Вернуться на страницу входа
            </a>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>