<?php
/**
 * Страница подтверждения email
 * 
 * @version 1.0
 * @author Smart Smoker Team
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$error = '';
$success = '';
$showLoginButton = false;

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['token']) && isset($_GET['email'])) {
    $token = $_GET['token'];
    $email = filter_var($_GET['email'], FILTER_SANITIZE_EMAIL);

    // Проверка токена и email
    $user = db()->fetchOne(
        'SELECT id, email, verification_token, email_verified_at, created_at 
         FROM users 
         WHERE email = ? AND verification_token = ?',
        [$email, $token]
    );

    if (!$user) {
        $error = 'Неверный или просроченный токен подтверждения. Пожалуйста, проверьте ссылку или повторите регистрацию.';
        logWarning("Попытка подтверждения с неверным токеном: $email", 'AUTH');
    } else {
        // Проверка срока действия токена (7 дней)
        $createdAt = new DateTime($user['created_at']);
        $expiresAt = clone $createdAt;
        $expiresAt->modify('+7 days');
        $now = new DateTime();
        
        if ($now > $expiresAt) {
            $error = 'Срок действия ссылки подтверждения истёк. Пожалуйста, повторите регистрацию.';
            logWarning("Просроченный токен подтверждения: $email", 'AUTH');
        } elseif (!empty($user['email_verified_at'])) {
            $success = 'Ваш email уже подтверждён. Вы можете войти в систему.';
            $showLoginButton = true;
        } else {
            try {
                // Подтверждаем email
                db()->update(
                    'users',
                    [
                        'email_verified_at' => date('Y-m-d H:i:s'),
                        'verification_token' => null
                    ],
                    'id = ?',
                    [$user['id']]
                );
                $success = 'Email успешно подтверждён! Теперь вы можете войти в систему.';
                $showLoginButton = true;
                logInfo("Email подтверждён: $email (ID: {$user['id']})", 'AUTH');
            } catch (Exception $e) {
                logException($e, 'AUTH');
                $error = 'Ошибка при подтверждении email. Пожалуйста, попробуйте позже.';
            }
        }
    }
} else {
    $error = 'Неверные параметры запроса.';
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Подтверждение email - Умная коптильня</title>
    
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
        .container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        .container h1 {
            color: #333;
            margin-bottom: 20px;
        }
        .success {
            color: #28a745;
            font-size: 1.2rem;
            margin: 20px 0;
        }
        .error {
            color: #dc3545;
            font-size: 1.2rem;
            margin: 20px 0;
        }
        .btn {
            display: inline-block;
            margin-top: 20px;
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-secondary {
            background: #6c757d;
        }
        .info-box {
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
            padding: 15px;
            border-radius: 5px;
            margin-top: 20px;
            text-align: left;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📧 Подтверждение email</h1>
        
        <?php if ($success): ?>
            <div class="alert alert-success">
                <p style="font-size: 3rem; margin-bottom: 20px;">✅</p>
                <div class="success"><?php echo $success; ?></div>
            </div>
            <?php if ($showLoginButton): ?>
            <a href="<?php echo BASE_URL; ?>/login.php" class="btn">
                🚪 Перейти на страницу входа
            </a>
            <?php endif; ?>
        <?php elseif ($error): ?>
            <div class="alert alert-danger">
                <p style="font-size: 3rem; margin-bottom: 20px;">⚠️</p>
                <div class="error"><?php echo $error; ?></div>
            </div>
            <a href="<?php echo BASE_URL; ?>/register.php" class="btn btn-secondary">
                👤 Повторить регистрацию
            </a>
            <div class="info-box mt-3">
                <strong>Важно:</strong> Ссылка для подтверждения действительна 7 дней. 
                После этого аккаунт будет автоматически удалён.
            </div>
        <?php else: ?>
            <p class="text-muted">Обработка подтверждения...</p>
        <?php endif; ?>
    </div>
</body>
</html>