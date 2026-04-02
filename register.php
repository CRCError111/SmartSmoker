<?php
/**
 * Страница регистрации нового пользователя
 * ИСПРАВЛЕНО: Интеграция с почтовой системой для отправки подтверждения
 * 
 * @version 1.1
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
require_once __DIR__ . '/includes/mail.php'; // Подключение почтовой системы

// Если пользователь уже авторизован — перенаправляем в панель
if (Auth::check()) {
    redirect(BASE_URL . '/dashboard.php');
}

$error = '';
$success = '';
$showVerificationLink = false;
$verificationUrl = '';

// Обработка формы регистрации
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Проверка защиты от брутфорса
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!Auth::checkRateLimit($ip)) {
        $error = 'Слишком много попыток регистрации. Попробуйте позже.';
    } else {
        // Проверка CSRF-токена
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Auth::verifyCsrfToken($csrfToken)) {
            $error = 'Неверный токен безопасности';
        } else {
            // Получение данных формы
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $passwordConfirm = $_POST['password_confirm'] ?? '';
            $fullName = trim($_POST['full_name'] ?? '');
            
            // Валидация email
            if (!validateEmail($email)) {
                $error = 'Неверный формат email адреса';
            } 
            // Проверка уникальности email
            elseif (db()->fetchColumn('SELECT COUNT(*) FROM users WHERE email = ?', [$email]) > 0) {
                $error = 'Пользователь с таким email уже существует';
            }
            // Валидация пароля
            elseif ($password !== $passwordConfirm) {
                $error = 'Пароли не совпадают';
            } else {
                list($valid, $message) = validatePassword($password);
                if (!$valid) {
                    $error = $message;
                }
                // Валидация имени
                elseif (empty($fullName) || strlen($fullName) > 100) {
                    $error = 'Имя должно содержать от 1 до 100 символов';
                } else {
                    try {
                        // Хеширование пароля
                        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                        
                        // Создание пользователя
                        $userId = db()->insert('users', [
                            'email' => $email,
                            'password_hash' => $passwordHash,
                            'full_name' => $fullName,
                            'is_active' => true,
                            'created_at' => date('Y-m-d H:i:s')
                        ]);
                        
                        // Создание настроек пользователя
                        db()->insert('user_settings', [
                            'user_id' => $userId,
                            'timezone' => 'Europe/Moscow',
                            'language' => 'ru',
                            'notifications_enabled' => true,
                            'email_notifications' => true
                        ]);
                        
                        // Генерация токена подтверждения
                        $verificationToken = bin2hex(random_bytes(32));
                        $expiresAt = date('Y-m-d H:i:s', time() + 604800); // 7 дней
                        
                        // Сохранение токена в БД
                        db()->update(
                            'users',
                            [
                                'verification_token' => $verificationToken,
                                'email_verified_at' => null
                            ],
                            'id = ?',
                            [$userId]
                        );
                        
                        // Формирование ссылки для подтверждения
                        $verifyUrl = BASE_URL . '/verify-email.php?token=' . $verificationToken . '&email=' . urlencode($email);
                        
                        // Отправка письма подтверждения
                        $mailEnabled = $_ENV['MAIL_ENABLED'] ?? 'false';
                        logInfo("MAIL_ENABLED=$mailEnabled, отправка письма на $email", 'AUTH');
                        
                        if ($mailEnabled === 'true') {
                            $emailResult = sendVerificationEmail($email, $fullName, $verifyUrl);
                            logInfo("Результат отправки email: " . ($emailResult ? 'success' : 'failed'), 'AUTH');
                            if ($emailResult) {
                                $success = 'Регистрация успешно завершена! На ваш email отправлена ссылка для подтверждения.';
                                $showVerificationLink = false;
                            } else {
                                $success = 'Регистрация успешно завершена! Не удалось отправить письмо подтверждения.';
                                $showVerificationLink = true;
                                $verificationUrl = $verifyUrl;
                            }
                        } else {
                            // Для демо-режима: показываем ссылку на экране
                            $success = 'Регистрация успешно завершена! Подтвердите email по ссылке ниже.';
                            $showVerificationLink = true;
                            $verificationUrl = $verifyUrl;
                        }
                        
                        // Логирование успешной регистрации
                        logInfo("Новый пользователь зарегистрирован: $email (ID: $userId)", 'AUTH');
                        
                    } catch (Exception $e) {
                        logException($e, 'AUTH');
                        $error = 'Ошибка при регистрации: ' . $e->getMessage();
                    }
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
    <title>Регистрация - Умная коптильня</title>
    
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
        
        .register-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 450px;
            width: 100%;
        }
        
        .register-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .register-header h1 {
            color: #333;
            font-weight: 600;
            margin-bottom: 10px;
        }
        
        .register-header p {
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
        
        .form-check-label {
            font-size: 14px;
            color: #555;
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
        
        .password-requirements li {
            margin-bottom: 3px;
        }
        
        .verification-section {
            background: #e7f3ff;
            border: 1px solid #2196F3;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .verification-section code {
            background: white;
            padding: 5px 10px;
            border-radius: 4px;
            display: block;
            margin: 10px 0;
            word-break: break-all;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1>👤 Регистрация</h1>
            <p>Создайте аккаунт для управления вашими коптильнями</p>
        </div>
        
        <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            ⚠️ <?php echo e($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo e($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        
        <?php if ($showVerificationLink && $verificationUrl): ?>
        <div class="verification-section">
            <strong>Ссылка для подтверждения:</strong>
            <code><?php echo htmlspecialchars($verificationUrl); ?></code>
            <small class="text-muted">Скопируйте и вставьте в адресную строку браузера</small>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo csrfToken(); ?>">
            
            <div class="mb-3">
                <label for="full_name" class="form-label">Полное имя *</label>
                <div class="input-group">
                    <span class="input-group-text">👤</span>
                    <input 
                        type="text" 
                        class="form-control" 
                        id="full_name" 
                        name="full_name" 
                        placeholder="Введите ваше имя"
                        value="<?php echo e($_POST['full_name'] ?? ''); ?>"
                        required
                        maxlength="100"
                    >
                </div>
            </div>
            
            <div class="mb-3">
                <label for="email" class="form-label">Email *</label>
                <div class="input-group">
                    <span class="input-group-text">📧</span>
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
            </div>
            
            <div class="mb-3">
                <label for="password" class="form-label">Пароль *</label>
                <div class="input-group">
                    <span class="input-group-text">🔒</span>
                    <input 
                        type="password" 
                        class="form-control" 
                        id="password" 
                        name="password" 
                        placeholder="Введите пароль"
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
                <label for="password_confirm" class="form-label">Подтверждение пароля *</label>
                <div class="input-group">
                    <span class="input-group-text">🔒</span>
                    <input 
                        type="password" 
                        class="form-control" 
                        id="password_confirm" 
                        name="password_confirm" 
                        placeholder="Повторите пароль"
                        required
                        minlength="8"
                    >
                </div>
            </div>
            
            <div class="mb-3 form-check">
                <input type="checkbox" class="form-check-input" id="agree" name="agree" required>
                <label class="form-check-label" for="agree">
                    Я согласен с <a href="#" class="text-decoration-none">условиями использования</a> и <a href="#" class="text-decoration-none">политикой конфиденциальности</a>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary w-100 mb-3">
                👤 Зарегистрироваться
            </button>
            
            <div class="text-center">
                <a href="<?php echo BASE_URL; ?>/login.php" class="login-link">
                    🚪 Уже есть аккаунт? Войти
                </a>
            </div>
        </form>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Простая валидация пароля в реальном времени
        document.getElementById('password').addEventListener('input', function() {
            const password = this.value;
            const requirements = document.querySelector('.password-requirements');
            
            // Проверка длины
            const hasMinLength = password.length >= 8;
            // Проверка наличия букв и цифр
            const hasLettersAndDigits = /[a-zA-Z]/.test(password) && /[0-9]/.test(password);
            
            if (hasMinLength && hasLettersAndDigits) {
                requirements.style.color = '#28a745';
            } else {
                requirements.style.color = '#666';
            }
        });
    </script>
</body>
</html>