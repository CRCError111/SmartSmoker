-- Миграция для добавления типа 'firmware' в таблицу admin_logs
-- Дата: 2026-03-05

-- Проверяем, существует ли таблица admin_logs
CREATE TABLE IF NOT EXISTS `admin_logs` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Логи действий администраторов';

-- Если таблица уже существует, обновляем enum для target_type
-- ВНИМАНИЕ: Эта команда пересоздаст таблицу, поэтому выполняйте её осторожно!
-- Если у вас есть данные в таблице, сначала сделайте резервную копию!

-- Альтернативный способ (безопаснее):
-- 1. Создаём временную таблицу с новой структурой
-- 2. Копируем данные
-- 3. Удаляем старую таблицу
-- 4. Переименовываем временную таблицу

-- Шаг 1: Создаём временную таблицу
CREATE TABLE IF NOT EXISTS `admin_logs_new` (
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
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Шаг 2: Копируем данные из старой таблицы (если она существует)
INSERT INTO `admin_logs_new` 
  (`id`, `admin_id`, `action`, `target_type`, `target_id`, `details`, `ip_address`, `created_at`)
SELECT 
  `id`, `admin_id`, `action`, `target_type`, `target_id`, `details`, `ip_address`, `created_at`
FROM `admin_logs`
WHERE EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admin_logs');

-- Шаг 3: Удаляем старую таблицу
DROP TABLE IF EXISTS `admin_logs`;

-- Шаг 4: Переименовываем новую таблицу
RENAME TABLE `admin_logs_new` TO `admin_logs`;

-- Шаг 5: Восстанавливаем внешний ключ
ALTER TABLE `admin_logs`
ADD CONSTRAINT `fk_admin_logs_user` FOREIGN KEY (`admin_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Готово!
SELECT 'Миграция admin_logs завершена успешно!' as status;
