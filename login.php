<?php
/**
 * Страница входа в систему
 * @version 2.0
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/logger.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::init();

if (Auth::check()) {
    redirect($_GET['redirect'] ?? BASE_URL . '/dashboard.php');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (!Auth::checkRateLimit($ip)) {
        $error = 'Слишком много попыток входа. Попробуйте позже.';
    } else {
        $csrfToken = $_POST['csrf_token'] ?? '';
        if (!Auth::verifyCsrfToken($csrfToken)) {
            $error = 'Неверный токен безопасности';
        } else {
            $email    = $_POST['email']    ?? '';
            $password = $_POST['password'] ?? '';
            $remember = isset($_POST['remember']);

            $userCheck = db()->fetchOne(
                'SELECT id, email_verified_at, is_active FROM users WHERE email = ?',
                [$email]
            );

            if (!$userCheck) {
                $error = 'Неверный email или пароль';
            } elseif (!$userCheck['is_active']) {
                $error = 'Ваш аккаунт деактивирован. Обратитесь в поддержку.';
            } elseif (empty($userCheck['email_verified_at'])) {
                $error = 'Пожалуйста, подтвердите ваш email. Ссылка была отправлена при регистрации.';
            } else {
                if (Auth::login($email, $password, $remember)) {
                    redirect($_POST['redirect'] ?? BASE_URL . '/dashboard.php');
                } else {
                    $error = 'Неверный email или пароль';
                }
            }
        }
    }
}

$redirect = $_GET['redirect'] ?? BASE_URL . '/dashboard.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — Smart Smoker</title>
    <meta name="theme-color" content="#7C3AED">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary-50:  #F3E8FF;
            --color-primary-100: #E9D5FF;
            --color-primary-200: #D8B4FE;
            --color-primary-300: #C084FC;
            --color-primary-400: #A855F7;
            --color-primary-500: #9333EA;
            --color-primary-600: #7C3AED;
            --color-primary-700: #6B21A8;
            --color-primary-800: #581C87;
            --color-primary-900: #3B0764;
            --color-gray-50:  #F9FAFB;
            --color-gray-100: #F3F4F6;
            --color-gray-200: #E5E7EB;
            --color-gray-300: #D1D5DB;
            --color-gray-400: #9CA3AF;
            --color-gray-500: #6B7280;
            --color-gray-600: #4B5563;
            --color-gray-700: #374151;
            --color-gray-800: #1F2937;
            --color-gray-900: #111827;
            --color-success:    #16A34A;
            --color-success-bg: #DCFCE7;
            --color-warning:    #D97706;
            --color-warning-bg: #FEF3C7;
            --color-error:      #DC2626;
            --color-error-bg:   #FEE2E2;
            --color-info:       #2563EB;
            --color-info-bg:    #DBEAFE;
            --font-family: 'Inter', sans-serif;
            --card-radius: 16px;
            --input-radius: 8px;
            --btn-radius: 8px;
            --shadow-lg: 0 20px 40px rgba(0,0,0,.14);
            --transition: all 0.2s cubic-bezier(0.4,0,0.2,1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-family);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: linear-gradient(135deg, var(--color-primary-700) 0%, var(--color-primary-800) 50%, #3B0764 100%);
        }

        /* Декоративные круги на фоне */
        body::before, body::after {
            content: '';
            position: fixed;
            border-radius: 50%;
            opacity: .15;
            pointer-events: none;
        }
        body::before {
            width: 500px; height: 500px;
            background: radial-gradient(circle, #fff, transparent);
            top: -150px; right: -100px;
        }
        body::after {
            width: 400px; height: 400px;
            background: radial-gradient(circle, #fff, transparent);
            bottom: -100px; left: -100px;
        }

        .login-card {
            background: #fff;
            border-radius: var(--card-radius);
            box-shadow: var(--shadow-lg);
            padding: 48px 40px;
            width: 100%;
            max-width: 440px;
            position: relative;
            z-index: 1;
            animation: cardIn 0.4s cubic-bezier(0.4,0,0.2,1);
        }
        @keyframes cardIn {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* Header */
        .login-header {
            text-align: center;
            margin-bottom: 36px;
        }
        .login-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px; height: 64px;
            background: linear-gradient(135deg, var(--color-primary-600), var(--color-primary-800));
            border-radius: 16px;
            font-size: 2rem;
            margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(124,58,237,.35);
        }
        .login-header h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--color-gray-900);
            margin-bottom: 6px;
        }
        .login-header p {
            font-size: 14px;
            color: var(--color-gray-500);
        }

        /* Alert */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 10px;
            font-size: 14px;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .alert-error   { background: var(--color-error-bg);   color: #7F1D1D; }
        .alert-success { background: var(--color-success-bg); color: #14532D; }
        .alert-icon { flex-shrink: 0; font-size: 1em; margin-top: 1px; }

        /* Form */
        .form-group { margin-bottom: 20px; }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: var(--color-gray-700);
            margin-bottom: 6px;
        }
        .input-wrap { position: relative; }
        .input-icon {
            position: absolute;
            left: 12px; top: 50%;
            transform: translateY(-50%);
            font-size: 16px;
            color: var(--color-gray-400);
            pointer-events: none;
        }
        .form-input {
            width: 100%;
            padding: 10px 12px 10px 38px;
            font-family: var(--font-family);
            font-size: 14px;
            color: var(--color-gray-800);
            background: var(--color-gray-50);
            border: 1.5px solid var(--color-gray-200);
            border-radius: var(--input-radius);
            transition: var(--transition);
            outline: none;
        }
        .form-input:focus {
            background: #fff;
            border-color: var(--color-primary-500);
            box-shadow: 0 0 0 3px var(--color-primary-100);
        }

        /* Checkbox */
        .checkbox-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .checkbox-row input[type=checkbox] {
            width: 16px; height: 16px;
            accent-color: var(--color-primary-600);
            cursor: pointer;
        }
        .checkbox-row label {
            font-size: 14px;
            color: var(--color-gray-600);
            cursor: pointer;
        }

        /* Submit button */
        .btn-submit {
            width: 100%;
            padding: 12px;
            font-family: var(--font-family);
            font-size: 15px;
            font-weight: 600;
            color: #fff;
            background: linear-gradient(135deg, var(--color-primary-600), var(--color-primary-700));
            border: none;
            border-radius: var(--btn-radius);
            cursor: pointer;
            transition: var(--transition);
            margin-bottom: 24px;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, var(--color-primary-700), var(--color-primary-800));
            box-shadow: 0 6px 16px rgba(124,58,237,.4);
            transform: translateY(-1px);
        }
        .btn-submit:active { transform: none; }

        /* Links */
        .auth-links {
            display: flex;
            justify-content: space-between;
            padding-top: 20px;
            border-top: 1px solid var(--color-gray-100);
            margin-bottom: 20px;
        }
        .auth-link {
            font-size: 14px;
            font-weight: 500;
            color: var(--color-primary-600);
            text-decoration: none;
            transition: var(--transition);
        }
        .auth-link:hover { color: var(--color-primary-800); text-decoration: underline; }

        /* Info block */
        .info-block {
            background: var(--color-primary-50);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 13px;
            color: var(--color-primary-700);
            text-align: center;
            line-height: 1.5;
        }
        .info-block strong { font-weight: 600; }

        @media (max-width: 480px) {
            .login-card { padding: 32px 24px; }
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="login-logo">🔥</div>
            <h1>Smart Smoker</h1>
            <p>Войдите для управления вашими устройствами</p>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <span class="alert-icon">⚠️</span>
            <span><?= e($error) ?></span>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <span class="alert-icon">✅</span>
            <span><?= e($success) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
            <input type="hidden" name="redirect"   value="<?= e($redirect) ?>">

            <div class="form-group">
                <label class="form-label" for="email">Email</label>
                <div class="input-wrap">
                    <span class="input-icon">✉️</span>
                    <input
                        type="email"
                        class="form-input"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        value="<?= e($_POST['email'] ?? '') ?>"
                        required
                        autofocus
                    >
                </div>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Пароль</label>
                <div class="input-wrap">
                    <span class="input-icon">🔒</span>
                    <input
                        type="password"
                        class="form-input"
                        id="password"
                        name="password"
                        placeholder="Введите пароль"
                        required
                    >
                </div>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Запомнить меня</label>
            </div>

            <button type="submit" class="btn-submit">Войти</button>

            <div class="auth-links">
                <a href="<?= BASE_URL ?>/register.php" class="auth-link">Регистрация</a>
                <a href="<?= BASE_URL ?>/forgot-password.php" class="auth-link">Забыли пароль?</a>
            </div>

            <div class="info-block">
                ℹ️ После регистрации необходимо подтвердить email по ссылке из письма.
            </div>
        </form>
    </div>
</body>
</html>
