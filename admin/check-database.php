<?php
/**
 * Проверка и создание таблиц базы данных
 */

define('SMART_SMOKER', true);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logger.php';
require_once __DIR__ . '/../includes/admin-auth.php';

// Требуется авторизация администратора
AdminAuth::requireAdmin();

$db = db();
$messages = [];

// Проверка таблицы firmware_updates
try {
    $result = $db->query("SHOW TABLES LIKE 'firmware_updates'");
    if ($result->rowCount() === 0) {
        $messages[] = ['type' => 'warning', 'text' => 'Таблица firmware_updates не найдена. Создаём...'];
        
        // Создание таблицы
        $sql = "CREATE TABLE IF NOT EXISTS `firmware_updates` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `version` varchar(20) NOT NULL COMMENT 'Версия прошивки (X.Y.Z)',
          `filename` varchar(255) NOT NULL COMMENT 'Имя файла прошивки',
          `file_path` varchar(500) NOT NULL COMMENT 'Полный путь к файлу',
          `file_size` int(11) NOT NULL COMMENT 'Размер файла в байтах',
          `checksum` varchar(64) NOT NULL COMMENT 'SHA256 контрольная сумма',
          `release_notes` text NOT NULL COMMENT 'Описание изменений',
          `is_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Обязательное обновление',
          `min_version_required` varchar(20) DEFAULT NULL COMMENT 'Минимальная версия для обновления',
          `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Активна ли прошивка',
          `release_date` datetime NOT NULL COMMENT 'Дата выпуска',
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `version` (`version`),
          KEY `is_active` (`is_active`),
          KEY `release_date` (`release_date`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Прошивки для OTA-обновлений'";
        
        $db->query($sql);
        $messages[] = ['type' => 'success', 'text' => '✅ Таблица firmware_updates создана успешно'];
    } else {
        $messages[] = ['type' => 'success', 'text' => '✅ Таблица firmware_updates существует'];
    }
} catch (Exception $e) {
    $messages[] = ['type' => 'danger', 'text' => '❌ Ошибка при проверке firmware_updates: ' . $e->getMessage()];
}

// Проверка таблицы firmware_downloads
try {
    $result = $db->query("SHOW TABLES LIKE 'firmware_downloads'");
    if ($result->rowCount() === 0) {
        $messages[] = ['type' => 'warning', 'text' => 'Таблица firmware_downloads не найдена. Создаём...'];
        
        // Создание таблицы
        $sql = "CREATE TABLE IF NOT EXISTS `firmware_downloads` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `firmware_version` varchar(20) NOT NULL COMMENT 'Версия прошивки',
          `device_id` varchar(36) NOT NULL COMMENT 'UUID устройства',
          `downloaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP адрес устройства',
          PRIMARY KEY (`id`),
          KEY `firmware_version` (`firmware_version`),
          KEY `device_id` (`device_id`),
          KEY `downloaded_at` (`downloaded_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='История скачиваний прошивок'";
        
        $db->query($sql);
        $messages[] = ['type' => 'success', 'text' => '✅ Таблица firmware_downloads создана успешно'];
    } else {
        $messages[] = ['type' => 'success', 'text' => '✅ Таблица firmware_downloads существует'];
    }
} catch (Exception $e) {
    $messages[] = ['type' => 'danger', 'text' => '❌ Ошибка при проверке firmware_downloads: ' . $e->getMessage()];
}

// Проверка таблицы admin_logs и добавление типа 'firmware'
try {
    $result = $db->query("SHOW TABLES LIKE 'admin_logs'");
    if ($result->rowCount() === 0) {
        $messages[] = ['type' => 'warning', 'text' => 'Таблица admin_logs не найдена. Создаём...'];
        
        // Создание таблицы
        $sql = "CREATE TABLE IF NOT EXISTS `admin_logs` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `admin_id` int(11) NOT NULL COMMENT 'ID администратора',
          `action` varchar(50) NOT NULL COMMENT 'Действие',
          `target_type` enum('user','device','program','template','system','firmware') NOT NULL COMMENT 'Тип объекта',
          `target_id` int(11) DEFAULT NULL COMMENT 'ID объекта',
          `details` text DEFAULT NULL COMMENT 'Детали действия (JSON)',
          `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP адрес',
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `admin_id` (`admin_id`),
          KEY `idx_action` (`action`),
          KEY `idx_target` (`target_type`, `target_id`),
          KEY `idx_created` (`created_at`),
          CONSTRAINT `fk_admin_logs_user` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Логи действий администраторов'";
        
        $db->query($sql);
        $messages[] = ['type' => 'success', 'text' => '✅ Таблица admin_logs создана успешно'];
    } else {
        $messages[] = ['type' => 'success', 'text' => '✅ Таблица admin_logs существует'];
        
        // Проверяем, есть ли тип 'firmware' в enum
        $columnInfo = $db->fetchOne("SHOW COLUMNS FROM admin_logs WHERE Field = 'target_type'");
        if ($columnInfo && strpos($columnInfo['Type'], 'firmware') === false) {
            $messages[] = ['type' => 'warning', 'text' => 'Тип "firmware" отсутствует в admin_logs. Обновляем...'];
            
            // Обновляем enum (безопасный способ через ALTER TABLE)
            try {
                $sql = "ALTER TABLE `admin_logs` 
                        MODIFY COLUMN `target_type` enum('user','device','program','template','system','firmware') NOT NULL COMMENT 'Тип объекта'";
                $db->query($sql);
                $messages[] = ['type' => 'success', 'text' => '✅ Тип "firmware" добавлен в admin_logs'];
            } catch (Exception $e) {
                $messages[] = ['type' => 'danger', 'text' => '❌ Не удалось обновить admin_logs: ' . $e->getMessage()];
                $messages[] = ['type' => 'info', 'text' => 'ℹ️ Попробуйте выполнить SQL-скрипт вручную: database/admin_logs_migration.sql'];
            }
        } else {
            $messages[] = ['type' => 'success', 'text' => '✅ Тип "firmware" уже есть в admin_logs'];
        }
    }
} catch (Exception $e) {
    $messages[] = ['type' => 'danger', 'text' => '❌ Ошибка при проверке admin_logs: ' . $e->getMessage()];
}

// Проверка папки firmware
$firmwareDir = BASE_PATH . '/firmware';
if (!is_dir($firmwareDir)) {
    $messages[] = ['type' => 'warning', 'text' => 'Папка /firmware не найдена. Создаём...'];
    if (mkdir($firmwareDir, 0755, true)) {
        $messages[] = ['type' => 'success', 'text' => '✅ Папка /firmware создана успешно'];
    } else {
        $messages[] = ['type' => 'danger', 'text' => '❌ Не удалось создать папку /firmware'];
    }
} else {
    $messages[] = ['type' => 'success', 'text' => '✅ Папка /firmware существует'];
    
    // Проверка прав на запись
    if (is_writable($firmwareDir)) {
        $messages[] = ['type' => 'success', 'text' => '✅ Папка /firmware доступна для записи'];
    } else {
        $messages[] = ['type' => 'danger', 'text' => '❌ Папка /firmware недоступна для записи. Установите права 755 или 777'];
    }
}

$pageTitle = 'Проверка базы данных';
require_once __DIR__ . '/../templates/header.php';
?>

<div class="card">
    <div class="card-header">
        <h5 class="mb-0">🔍 Результаты проверки</h5>
    </div>
    <div class="card-body">
        <?php foreach ($messages as $msg): ?>
            <div class="alert alert-<?= $msg['type'] ?> mb-2">
                <?= $msg['text'] ?>
            </div>
        <?php endforeach; ?>
        
        <hr>
        
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/admin/firmware.php" class="btn btn-primary">
                📦 Перейти к управлению прошивками
            </a>
            <a href="<?= BASE_URL ?>/admin/index.php" class="btn btn-secondary">
                ← Назад в админ-панель
            </a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../templates/footer.php'; ?>
