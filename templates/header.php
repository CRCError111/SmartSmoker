<?php
/**
 * Шаблон шапки сайта
 * Дизайн-система: фиолетовая палитра, Inter, CSS-переменные
 * @version 3.0
 */

if (!defined('SMART_SMOKER')) {
    die('Direct access not permitted');
}

$pageTitle = $pageTitle ?? 'Smart Smoker';
$user = Auth::user();
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($pageTitle) ?> - Smart Smoker</title>
    <meta name="description" content="Умная система управления коптильней с удалённым мониторингом и автоматизацией">
    <meta name="theme-color" content="#7C3AED">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Smart Smoker">
    <?php if (defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY && VAPID_PUBLIC_KEY !== 'REPLACE_WITH_GENERATED_KEY'): ?>
    <meta name="vapid-public-key" content="<?= htmlspecialchars(VAPID_PUBLIC_KEY) ?>">
    <?php endif; ?>
    <?php if (function_exists('csrfToken')): ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(csrfToken()) ?>">
    <?php endif; ?>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192x192.png">
    <link rel="apple-touch-icon" href="/icons/icon-192x192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Bootstrap (для совместимости с существующими страницами) -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* ============================================
           CSS ПЕРЕМЕННЫЕ — ДИЗАЙН-СИСТЕМА
        ============================================ */
        :root {
            /* Основная палитра */
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

            /* Семантические цвета */
            --color-success:     #16A34A;
            --color-success-bg:  #DCFCE7;
            --color-warning:     #D97706;
            --color-warning-bg:  #FEF3C7;
            --color-error:       #DC2626;
            --color-error-bg:    #FEE2E2;
            --color-info:        #2563EB;
            --color-info-bg:     #DBEAFE;

            /* Нейтральные */
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

            /* Типографика */
            --font-family:     'Inter', sans-serif;
            --text-xs:   12px;
            --text-sm:   14px;
            --text-base: 16px;
            --text-lg:   18px;
            --text-xl:   20px;
            --text-2xl:  24px;
            --text-3xl:  30px;
            --leading-tight:   1.25;
            --leading-normal:  1.5;
            --leading-relaxed: 1.625;

            /* Размеры */
            --sidebar-width:          260px;
            --sidebar-collapsed-width: 72px;
            --header-height:           64px;
            --card-border-radius:      12px;
            --button-border-radius:     8px;
            --input-border-radius:      6px;

            /* Тени */
            --shadow-sm:   0 1px 2px 0 rgba(0,0,0,.05);
            --shadow-md:   0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06);
            --shadow-lg:   0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05);
            --shadow-card: 0 1px 3px rgba(0,0,0,.1), 0 1px 2px rgba(0,0,0,.06);

            /* Отступы */
            --space-1:  4px;
            --space-2:  8px;
            --space-3: 12px;
            --space-4: 16px;
            --space-5: 20px;
            --space-6: 24px;
            --space-8: 32px;
            --space-10: 40px;

            /* Переходы */
            --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);

            /* Sidebar gradient */
            --sidebar-gradient: linear-gradient(180deg, var(--color-primary-700) 0%, var(--color-primary-900) 100%);
        }

        /* ============================================
           RESET & BASE
        ============================================ */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--font-family);
            font-size: var(--text-base);
            line-height: var(--leading-normal);
            color: var(--color-gray-800);
            background: var(--color-gray-50);
            overflow-x: hidden;
        }

        /* ============================================
           SIDEBAR
        ============================================ */
        .sidebar {
            position: fixed;
            left: 0; top: 0;
            height: 100vh;
            width: var(--sidebar-width);
            background: var(--sidebar-gradient);
            box-shadow: var(--shadow-lg);
            transition: var(--transition);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .sidebar.collapsed { width: var(--sidebar-collapsed-width); }

        .sidebar-header {
            height: var(--header-height);
            padding: 0 var(--space-5);
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid rgba(255,255,255,.1);
            flex-shrink: 0;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            color: #fff;
            font-size: var(--text-lg);
            font-weight: 700;
            text-decoration: none;
            overflow: hidden;
            white-space: nowrap;
        }

        .logo-icon { font-size: 1.6rem; flex-shrink: 0; line-height: 1; }

        .logo-text { transition: var(--transition); opacity: 1; }
        .sidebar.collapsed .logo-text { opacity: 0; width: 0; overflow: hidden; }

        .toggle-btn {
            background: rgba(255,255,255,.1);
            border: none;
            width: 36px; height: 36px;
            border-radius: var(--button-border-radius);
            cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
            color: #fff;
        }
        .toggle-btn:hover { background: rgba(255,255,255,.2); }
        .toggle-btn svg { width: 20px; height: 20px; fill: currentColor; }

        /* Nav */
        .sidebar-nav {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: var(--space-4) 0;
        }
        .sidebar-nav::-webkit-scrollbar { width: 4px; }
        .sidebar-nav::-webkit-scrollbar-thumb { background: rgba(255,255,255,.2); border-radius: 2px; }

        .nav-section { margin-bottom: var(--space-6); }

        .nav-section.admin-section {
            background: rgba(255,255,255,.05);
            border-radius: 8px;
            margin: 0 var(--space-3) var(--space-6);
            padding: var(--space-2) 0;
        }

        .section-title {
            padding: var(--space-2) var(--space-5);
            font-size: var(--text-xs);
            font-weight: 600;
            color: rgba(255,255,255,.45);
            text-transform: uppercase;
            letter-spacing: .08em;
            display: flex;
            align-items: center;
            gap: var(--space-2);
            white-space: nowrap;
            overflow: hidden;
        }
        .sidebar.collapsed .section-title { opacity: 0; height: 0; padding: 0; margin: 0; }

        .nav-link {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            padding: 10px var(--space-5);
            color: rgba(255,255,255,.7);
            text-decoration: none;
            transition: var(--transition);
            position: relative;
            white-space: nowrap;
            overflow: hidden;
            font-size: var(--text-sm);
            font-weight: 500;
            border-radius: 0;
        }
        .nav-link::before {
            content: '';
            position: absolute;
            left: 0; top: 50%;
            transform: translateY(-50%);
            width: 3px; height: 0;
            background: #fff;
            border-radius: 0 3px 3px 0;
            transition: var(--transition);
        }
        .nav-link:hover, .nav-link.active {
            color: #fff;
            background: rgba(255,255,255,.1);
        }
        .nav-link.active::before { height: 60%; }

        .nav-link .icon {
            font-size: 1.1em;
            flex-shrink: 0;
            width: 22px;
            text-align: center;
            line-height: 1;
        }
        .nav-link .text { transition: var(--transition); }
        .sidebar.collapsed .nav-link .text { opacity: 0; width: 0; overflow: hidden; }
        .sidebar.collapsed .nav-link { justify-content: center; padding: 10px; }
        .sidebar.collapsed .nav-section.admin-section { margin: 0 var(--space-2) var(--space-6); }

        /* Sidebar Footer */
        .sidebar-footer {
            padding: var(--space-4) var(--space-5);
            border-top: 1px solid rgba(255,255,255,.1);
            flex-shrink: 0;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: var(--space-3);
            color: #fff;
            overflow: hidden;
        }
        .avatar {
            width: 38px; height: 38px;
            border-radius: 50%;
            background: rgba(255,255,255,.15);
            border: 2px solid rgba(255,255,255,.25);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700;
            font-size: var(--text-sm);
            flex-shrink: 0;
            color: #fff;
        }
        .user-details { flex: 1; overflow: hidden; transition: var(--transition); }
        .sidebar.collapsed .user-details { opacity: 0; width: 0; }
        .user-details .name {
            font-weight: 600;
            font-size: var(--text-sm);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .user-details .role {
            font-size: var(--text-xs);
            color: rgba(255,255,255,.6);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* ============================================
           MAIN CONTENT
        ============================================ */
        .main-content {
            margin-left: var(--sidebar-width);
            min-height: 100vh;
            padding: var(--space-8);
            transition: var(--transition);
        }
        .sidebar.collapsed ~ .main-content { margin-left: var(--sidebar-collapsed-width); }

        /* Page Header */
        .page-header {
            background: #fff;
            padding: var(--space-5) var(--space-6);
            border-radius: var(--card-border-radius);
            box-shadow: var(--shadow-card);
            margin-bottom: var(--space-6);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--space-4);
        }
        .page-header h1 {
            margin: 0;
            font-size: var(--text-2xl);
            font-weight: 700;
            color: var(--color-gray-900);
            line-height: var(--leading-tight);
        }
        .header-actions { display: flex; align-items: center; gap: var(--space-3); }

        /* ============================================
           КОМПОНЕНТЫ — КНОПКИ
        ============================================ */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            font-family: var(--font-family);
            font-weight: 500;
            border: none;
            cursor: pointer;
            text-decoration: none;
            transition: var(--transition);
            border-radius: var(--button-border-radius);
            white-space: nowrap;
        }
        .btn:focus-visible { outline: 2px solid var(--color-primary-500); outline-offset: 2px; }

        .btn-sm  { padding: 6px 12px;  font-size: var(--text-sm); }
        .btn-md  { padding: 10px 18px; font-size: var(--text-sm); }
        .btn-lg  { padding: 12px 24px; font-size: var(--text-base); }

        .btn-primary {
            background: var(--color-primary-600);
            color: #fff;
        }
        .btn-primary:hover { background: var(--color-primary-700); color: #fff; box-shadow: var(--shadow-md); transform: translateY(-1px); }

        .btn-secondary {
            background: var(--color-gray-100);
            color: var(--color-gray-700);
        }
        .btn-secondary:hover { background: var(--color-gray-200); color: var(--color-gray-800); }

        .btn-success { background: var(--color-success); color: #fff; }
        .btn-success:hover { background: #15803D; color: #fff; }

        .btn-danger { background: var(--color-error); color: #fff; }
        .btn-danger:hover { background: #B91C1C; color: #fff; }

        .btn-outline {
            background: transparent;
            color: var(--color-primary-600);
            border: 1.5px solid var(--color-primary-300);
        }
        .btn-outline:hover { background: var(--color-primary-50); border-color: var(--color-primary-500); }

        /* Bootstrap override */
        .btn-primary.btn:not(.btn-sm):not(.btn-md):not(.btn-lg) { padding: 10px 18px; font-size: var(--text-sm); }

        /* ============================================
           КОМПОНЕНТЫ — КАРТОЧКИ
        ============================================ */
        .card {
            background: #fff;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--card-border-radius);
            box-shadow: var(--shadow-card);
            overflow: hidden;
            transition: var(--transition);
        }
        .card:hover { box-shadow: var(--shadow-md); }
        .card-header {
            padding: var(--space-4) var(--space-5);
            background: linear-gradient(135deg, var(--color-primary-600), var(--color-primary-800));
            color: #fff;
            font-weight: 600;
            font-size: var(--text-base);
            border-bottom: none;
        }
        .card-body { padding: var(--space-5); }
        .card-footer {
            padding: var(--space-4) var(--space-5);
            background: var(--color-gray-50);
            border-top: 1px solid var(--color-gray-200);
        }

        /* ============================================
           КОМПОНЕНТЫ — БЕЙДЖИ
        ============================================ */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            font-size: var(--text-xs);
            font-weight: 600;
            border-radius: 999px;
            line-height: 1.6;
        }
        .badge-primary   { background: var(--color-primary-100); color: var(--color-primary-700); }
        .badge-secondary { background: var(--color-gray-100);    color: var(--color-gray-600); }
        .badge-success   { background: var(--color-success-bg);  color: var(--color-success); }
        .badge-warning   { background: var(--color-warning-bg);  color: var(--color-warning); }
        .badge-error, .badge-danger { background: var(--color-error-bg); color: var(--color-error); }
        .badge-info      { background: var(--color-info-bg);     color: var(--color-info); }
        .badge-gray      { background: var(--color-gray-100);    color: var(--color-gray-500); }
        .badge-outline   { background: transparent; color: var(--color-primary-600); border: 1.5px solid var(--color-primary-300); }

        /* ============================================
           КОМПОНЕНТЫ — АЛЕРТЫ
        ============================================ */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: var(--space-3);
            padding: var(--space-4) var(--space-5);
            border-radius: var(--card-border-radius);
            border: none;
            font-size: var(--text-sm);
            box-shadow: var(--shadow-sm);
        }
        .alert-success { background: var(--color-success-bg); color: #14532D; }
        .alert-warning { background: var(--color-warning-bg); color: #78350F; }
        .alert-danger, .alert-error { background: var(--color-error-bg); color: #7F1D1D; }
        .alert-info    { background: var(--color-info-bg);    color: #1E3A8A; }
        .alert-icon { flex-shrink: 0; font-size: 1.1em; margin-top: 1px; }

        /* ============================================
           КОМПОНЕНТЫ — ФОРМЫ
        ============================================ */
        .form-group { margin-bottom: var(--space-5); }
        .form-label {
            display: block;
            font-size: var(--text-sm);
            font-weight: 500;
            color: var(--color-gray-700);
            margin-bottom: var(--space-2);
        }
        .form-label-required::after { content: ' *'; color: var(--color-error); }
        .form-input, .form-select, .form-textarea,
        .form-control, .form-select.form-control {
            width: 100%;
            padding: 9px var(--space-3);
            font-family: var(--font-family);
            font-size: var(--text-sm);
            color: var(--color-gray-800);
            background: #fff;
            border: 1.5px solid var(--color-gray-300);
            border-radius: var(--input-border-radius);
            transition: var(--transition);
            outline: none;
            line-height: var(--leading-normal);
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus,
        .form-control:focus {
            border-color: var(--color-primary-500);
            box-shadow: 0 0 0 3px var(--color-primary-100);
        }
        .form-input.is-invalid, .form-control.is-invalid { border-color: var(--color-error); }
        .form-hint { font-size: var(--text-xs); color: var(--color-gray-500); margin-top: var(--space-1); }
        .invalid-feedback { font-size: var(--text-xs); color: var(--color-error); margin-top: var(--space-1); }

        /* ============================================
           КОМПОНЕНТЫ — ТАБЛИЦЫ
        ============================================ */
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: var(--text-sm);
        }
        .table th {
            padding: var(--space-3) var(--space-4);
            text-align: left;
            font-size: var(--text-xs);
            font-weight: 600;
            color: var(--color-gray-500);
            text-transform: uppercase;
            letter-spacing: .05em;
            background: var(--color-gray-50);
            border-bottom: 1px solid var(--color-gray-200);
        }
        .table td {
            padding: var(--space-3) var(--space-4);
            border-bottom: 1px solid var(--color-gray-100);
            color: var(--color-gray-700);
            vertical-align: middle;
        }
        .table tbody tr:hover { background: var(--color-gray-50); }
        .table tbody tr:last-child td { border-bottom: none; }

        /* ============================================
           КОМПОНЕНТЫ — ИКОНКИ
        ============================================ */
        .icon-sm { width: 16px; height: 16px; }
        .icon-md { width: 20px; height: 20px; }
        .icon-lg { width: 24px; height: 24px; }
        .icon-xl { width: 32px; height: 32px; }

        /* ============================================
           КОМПОНЕНТЫ — STAT CARDS
        ============================================ */
        .stat-card {
            background: #fff;
            border: 1px solid var(--color-gray-200);
            border-radius: var(--card-border-radius);
            box-shadow: var(--shadow-card);
            padding: var(--space-5) var(--space-6);
        }
        .stat-value {
            font-size: var(--text-3xl);
            font-weight: 700;
            color: var(--color-gray-900);
            line-height: var(--leading-tight);
        }
        .stat-label { font-size: var(--text-sm); color: var(--color-gray-500); margin-top: var(--space-1); }
        .stat-change { font-size: var(--text-xs); color: var(--color-success); margin-top: var(--space-2); }

        /* ============================================
           КОМПОНЕНТЫ — EMPTY STATE
        ============================================ */
        .empty-state {
            text-align: center;
            padding: var(--space-10) var(--space-8);
            color: var(--color-gray-500);
        }
        .empty-state-icon { font-size: 3rem; margin-bottom: var(--space-4); opacity: .5; }
        .empty-state h3 { font-size: var(--text-lg); font-weight: 600; color: var(--color-gray-700); margin-bottom: var(--space-2); }
        .empty-state p { font-size: var(--text-sm); max-width: 360px; margin: 0 auto var(--space-5); }

        /* ============================================
           TOAST
        ============================================ */
        .toast-container {
            position: fixed;
            bottom: var(--space-6);
            right: var(--space-6);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: var(--space-3);
        }
        .toast {
            display: flex;
            align-items: flex-start;
            gap: var(--space-3);
            padding: var(--space-4);
            background: #fff;
            border-radius: var(--card-border-radius);
            box-shadow: var(--shadow-lg);
            border-left: 4px solid var(--color-primary-500);
            min-width: 280px;
            max-width: 360px;
            animation: toastIn .25s ease;
        }
        .toast-success { border-color: var(--color-success); }
        .toast-error   { border-color: var(--color-error); }
        .toast-warning { border-color: var(--color-warning); }
        .toast-title   { font-size: var(--text-sm); font-weight: 600; color: var(--color-gray-900); }
        .toast-body    { font-size: var(--text-xs); color: var(--color-gray-500); margin-top: 2px; }
        @keyframes toastIn { from { opacity: 0; transform: translateX(20px); } to { opacity: 1; transform: none; } }

        /* ============================================
           RESPONSIVE
        ============================================ */
        @media (max-width: 768px) {
            .sidebar { transform: translateX(-100%); }
            .sidebar.mobile-open { transform: translateX(0); }
            .main-content { margin-left: 0 !important; padding: var(--space-4); }
            .mobile-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 999; }
            .mobile-overlay.active { display: block; }
            .mobile-menu-btn {
                position: fixed;
                bottom: var(--space-5);
                right: var(--space-5);
                width: 52px; height: 52px;
                border-radius: 50%;
                background: var(--color-primary-600);
                border: none;
                box-shadow: var(--shadow-lg);
                display: flex; align-items: center; justify-content: center;
                z-index: 998;
                cursor: pointer;
                color: #fff;
            }
            .mobile-menu-btn svg { width: 22px; height: 22px; fill: currentColor; }
            .page-header h1 { font-size: var(--text-xl); }
        }
        @media (min-width: 769px) { .mobile-menu-btn { display: none; } }

        /* ============================================
           УТИЛИТЫ
        ============================================ */
        .text-primary { color: var(--color-primary-600); }
        .text-muted   { color: var(--color-gray-500); }
        .text-success { color: var(--color-success); }
        .text-danger  { color: var(--color-error); }
        .text-warning { color: var(--color-warning); }

        .bg-primary-soft { background: var(--color-primary-50); }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: none; }
        }
        .content-wrapper > * { animation: slideIn .3s ease-out; }
    </style>
</head>
<body>
    <div class="mobile-overlay" id="mobileOverlay" onclick="toggleMobileSidebar()"></div>

    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="<?= BASE_URL ?>/dashboard.php" class="logo">
                <span class="logo-icon">🔥</span>
                <span class="logo-text">Smart Smoker</span>
            </a>
            <button class="toggle-btn" onclick="toggleSidebar()" title="Свернуть меню">
                <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
            </button>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="section-title">
                    <span class="icon">📊</span>
                    <span class="text">Мониторинг</span>
                </div>
                <a href="<?= BASE_URL ?>/dashboard.php" class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                    <span class="icon">🏠</span><span class="text">Панель управления</span>
                </a>
                <a href="<?= BASE_URL ?>/devices.php" class="nav-link <?= in_array($currentPage, ['devices.php','view-device.php','add-device.php','edit-device.php']) ? 'active' : '' ?>">
                    <span class="icon">🖥️</span><span class="text">Устройства</span>
                </a>
            </div>

            <div class="nav-section">
                <div class="section-title">
                    <span class="icon">⚙️</span>
                    <span class="text">Управление</span>
                </div>
                <a href="<?= BASE_URL ?>/programs.php" class="nav-link <?= in_array($currentPage, ['programs.php','program-import.php','program-create.php','program-edit.php']) ? 'active' : '' ?>">
                    <span class="icon">📋</span><span class="text">Программы</span>
                </a>
                <a href="<?= BASE_URL ?>/templates.php" class="nav-link <?= $currentPage === 'templates.php' ? 'active' : '' ?>">
                    <span class="icon">📄</span><span class="text">Шаблоны</span>
                </a>
                <a href="<?= BASE_URL ?>/check-update.php" class="nav-link <?= $currentPage === 'check-update.php' ? 'active' : '' ?>">
                    <span class="icon">🔄</span><span class="text">Обновления</span>
                </a>
            </div>

            <?php
            $isAdmin = false;
            if (Auth::check()) {
                $db = db();
                $userId = $_SESSION['user_id'] ?? 0;
                $userRole = $db->fetchOne('SELECT role FROM users WHERE id = ?', [$userId]);
                $isAdmin = $userRole && isset($userRole['role']) && $userRole['role'] === 'admin';
            }
            if ($isAdmin):
            ?>
            <div class="nav-section admin-section">
                <div class="section-title">
                    <span class="icon">🔐</span>
                    <span class="text">Администрирование</span>
                </div>
                <a href="<?= BASE_URL ?>/admin/index.php" class="nav-link <?= $currentPage === 'index.php' && strpos($_SERVER['REQUEST_URI'], 'admin') !== false ? 'active' : '' ?>">
                    <span class="icon">📊</span><span class="text">Панель админа</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/users.php" class="nav-link <?= in_array($currentPage, ['users.php','user-edit.php']) ? 'active' : '' ?>">
                    <span class="icon">👥</span><span class="text">Пользователи</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/devices.php" class="nav-link <?= $currentPage === 'devices.php' && strpos($_SERVER['REQUEST_URI'], 'admin') !== false ? 'active' : '' ?>">
                    <span class="icon">🖥️</span><span class="text">Все устройства</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/templates.php" class="nav-link <?= $currentPage === 'templates.php' && strpos($_SERVER['REQUEST_URI'], 'admin') !== false ? 'active' : '' ?>">
                    <span class="icon">📋</span><span class="text">Шаблоны</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/firmware.php" class="nav-link <?= $currentPage === 'firmware.php' ? 'active' : '' ?>">
                    <span class="icon">📦</span><span class="text">Прошивки</span>
                </a>
                <a href="<?= BASE_URL ?>/admin/logs.php" class="nav-link <?= $currentPage === 'logs.php' ? 'active' : '' ?>">
                    <span class="icon">📝</span><span class="text">Логи</span>
                </a>
            </div>
            <?php endif; ?>

            <div class="nav-section">
                <div class="section-title">
                    <span class="icon">👤</span>
                    <span class="text">Профиль</span>
                </div>
                <a href="<?= BASE_URL ?>/logout.php" class="nav-link">
                    <span class="icon">🚪</span><span class="text">Выход</span>
                </a>
            </div>
        </nav>

        <div class="sidebar-footer">
            <div class="user-info">
                <div class="avatar"><?= strtoupper(substr($user['full_name'] ?? $user['email'], 0, 1)) ?></div>
                <div class="user-details">
                    <div class="name"><?= htmlspecialchars($user['full_name'] ?? 'Пользователь') ?></div>
                    <div class="role"><?= htmlspecialchars($user['email']) ?></div>
                </div>
            </div>
            <?php if (defined('VAPID_PUBLIC_KEY') && VAPID_PUBLIC_KEY && VAPID_PUBLIC_KEY !== 'REPLACE_WITH_GENERATED_KEY'): ?>
            <div id="push-notify-wrap" style="margin-top:10px;display:none;">
                <button onclick="window.PWA && window.PWA.requestNotifications()" 
                        class="btn btn-sm" 
                        style="width:100%;background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);font-size:12px;">
                    🔔 Включить уведомления
                </button>
            </div>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                if ('Notification' in window && Notification.permission === 'default') {
                    document.getElementById('push-notify-wrap').style.display = 'block';
                }
            });
            </script>
            <?php endif; ?>
        </div>
    </div>

    <button class="mobile-menu-btn" onclick="toggleMobileSidebar()" title="Открыть меню">
        <svg viewBox="0 0 24 24"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>
    </button>

    <div class="main-content">
        <div class="page-header">
            <h1><?= htmlspecialchars($pageTitle) ?></h1>
            <div class="header-actions"></div>
        </div>
        <div class="content-wrapper">

<script>
function toggleSidebar() {
    const s = document.getElementById('sidebar');
    s.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', s.classList.contains('collapsed'));
}
function toggleMobileSidebar() {
    document.getElementById('sidebar').classList.toggle('mobile-open');
    document.getElementById('mobileOverlay').classList.toggle('active');
}
document.addEventListener('DOMContentLoaded', function() {
    const s = document.getElementById('sidebar');
    if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 768) {
        s.classList.add('collapsed');
    }
    s.querySelectorAll('.nav-link').forEach(l => l.addEventListener('click', () => {
        if (window.innerWidth <= 768) toggleMobileSidebar();
    }));
});
</script>
<script src="/pwa-register.js"></script>
