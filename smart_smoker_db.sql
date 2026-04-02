-- ============================================
-- Smart Smoker Database Schema
-- Version: 2.1.0
-- Date: 2026-02-28
-- ============================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ============================================
-- 1. USERS TABLE
-- Пользователи системы
-- ============================================

CREATE TABLE IF NOT EXISTS `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL COMMENT 'Полное имя пользователя',
  `role` enum('user','admin') NOT NULL DEFAULT 'user' COMMENT 'Роль пользователя',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Активен',
  `is_blocked` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Заблокирован',
  `blocked_reason` varchar(255) DEFAULT NULL COMMENT 'Причина блокировки',
  `blocked_at` timestamp NULL DEFAULT NULL COMMENT 'Дата блокировки',
  `verification_token` varchar(64) DEFAULT NULL COMMENT 'Токен подтверждения email',
  `email_verified_at` timestamp NULL DEFAULT NULL COMMENT 'Дата подтверждения email',
  `remember_token` varchar(100) DEFAULT NULL COMMENT 'Токен "Запомнить меня"',
  `remember_token_expires` timestamp NULL DEFAULT NULL COMMENT 'Срок действия токена "Запомнить меня"',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_active` (`is_active`),
  KEY `idx_role` (`role`),
  KEY `idx_blocked` (`is_blocked`),
  KEY `idx_verification_token` (`verification_token`),
  KEY `idx_remember_token` (`remember_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 2. DEVICES TABLE
-- Устройства (коптильни)
-- ============================================

CREATE TABLE IF NOT EXISTS `devices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'MAC адрес ESP32',
  `name` varchar(100) DEFAULT NULL COMMENT 'Название устройства',
  `description` TEXT DEFAULT NULL COMMENT 'Описание устройства',
  `user_id` int(11) DEFAULT NULL COMMENT 'Владелец устройства',
  `status` enum('pending','active','inactive') NOT NULL DEFAULT 'pending' COMMENT 'Статус устройства',
  `device_token` varchar(500) DEFAULT NULL COMMENT 'JWT токен (устарело)',
  `api_token` varchar(64) DEFAULT NULL COMMENT 'API токен для аутентификации устройства',
  `unbound` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Флаг двустороннего отвязывания',
  `token_issued_at` timestamp NULL DEFAULT NULL COMMENT 'Дата выдачи токена',
  `token_expires_at` timestamp NULL DEFAULT NULL COMMENT 'Срок действия токена',
  `last_seen` timestamp NULL DEFAULT NULL COMMENT 'Последняя активность',
  `bound_at` timestamp NULL DEFAULT NULL COMMENT 'Дата привязки',
  `firmware_version` varchar(20) DEFAULT NULL COMMENT 'Версия прошивки',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP адрес устройства',
  `device_ip` varchar(45) GENERATED ALWAYS AS (`ip_address`) VIRTUAL COMMENT 'Алиас для ip_address (совместимость)',
  `is_online` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Онлайн статус',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_id` (`device_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_online` (`is_online`),
  KEY `idx_last_seen` (`last_seen`),
  KEY `idx_api_token` (`api_token`),
  KEY `idx_unbound` (`unbound`),
  CONSTRAINT `fk_devices_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 3. PROGRAMS TABLE
-- Программы копчения
-- ============================================

CREATE TABLE IF NOT EXISTS `programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_name` varchar(100) NOT NULL COMMENT 'Название программы',
  `name` varchar(100) GENERATED ALWAYS AS (`program_name`) VIRTUAL COMMENT 'Алиас для program_name (совместимость)',
  `description` text DEFAULT NULL COMMENT 'Описание программы',
  `category` varchar(50) DEFAULT NULL COMMENT 'Категория (fish, meat, other)',
  `user_id` int(11) DEFAULT NULL COMMENT 'Автор программы (NULL = системная)',
  `device_id` int(11) DEFAULT NULL COMMENT 'Устройство (NULL = общая программа)',
  `is_public` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Доступна всем',
  `is_system` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Системная программа',
  `is_built_in` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Встроенная программа',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `device_id` (`device_id`),
  KEY `idx_public` (`is_public`),
  KEY `idx_system` (`is_system`),
  KEY `idx_category` (`category`),
  CONSTRAINT `fk_programs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_programs_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 4. PROGRAM_STAGES TABLE
-- Этапы программ копчения
-- ============================================

CREATE TABLE IF NOT EXISTS `program_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `program_id` int(11) NOT NULL COMMENT 'ID программы',
  `stage_order` int(11) NOT NULL COMMENT 'Порядковый номер этапа',
  `stage_name` varchar(100) NOT NULL COMMENT 'Название этапа',
  `target_temp` float NOT NULL COMMENT 'Целевая температура (°C)',
  `target_temp_device` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Устройство измерения (0=камера, 1=дым, 2=продукт)',
  `target_humidity` float DEFAULT NULL COMMENT 'Целевая влажность (%)',
  `duration_minutes` int(11) NOT NULL COMMENT 'Длительность (минуты)',
  `hysteresis` float DEFAULT 2.0 COMMENT 'Гистерезис температуры (°C)',
  `wait_for_temp` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Ждать достижения температуры',
  `use_smoke_generator` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Использовать дымогенератор',
  `smoke_intensity` int(11) DEFAULT 80 COMMENT 'Интенсивность дыма (0-100%)',
  `ventilation_percent` int(11) DEFAULT 50 COMMENT 'Вентиляция (0-100%)',
  `internal_fan_on` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Внутренний вентилятор',
  `injection_fan_on` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Вентилятор подачи',
  `compressor_pwm` int(11) DEFAULT -1 COMMENT 'ШИМ компрессора (-1=авто, 0-100)',
  PRIMARY KEY (`id`),
  KEY `program_id` (`program_id`),
  KEY `idx_stage_order` (`stage_order`),
  CONSTRAINT `fk_stages_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 5. SENSOR_DATA TABLE
-- Телеметрия с датчиков
-- ============================================

CREATE TABLE IF NOT EXISTS `sensor_data` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `run_id` varchar(36) DEFAULT NULL COMMENT 'UUID запуска программы (из currentRunId ESP32)',
  `temp_chamber` float DEFAULT NULL COMMENT 'Температура камеры (°C)',
  `temp_smoke` float DEFAULT NULL COMMENT 'Температура дыма (°C)',
  `temp_product` float DEFAULT NULL COMMENT 'Температура продукта (°C)',
  `humidity` float DEFAULT NULL COMMENT 'Влажность (%)',
  `heater_active` tinyint(1) DEFAULT NULL COMMENT 'Состояние ТЭНа',
  `smoke_gen_active` tinyint(1) DEFAULT NULL COMMENT 'Дымогенератор активен',
  `smoke_pwm` int(11) DEFAULT NULL COMMENT 'ШИМ дымогенератора (0-100)',
  `damper_percent` int(11) DEFAULT NULL COMMENT 'Позиция заслонки (%)',
  `injection_fan` tinyint(1) DEFAULT NULL COMMENT 'Вентилятор подачи',
  `fan_internal_on` tinyint(1) DEFAULT NULL COMMENT 'Внутренний вентилятор',
  `mode` varchar(20) DEFAULT NULL COMMENT 'Режим (IDLE, RUNNING, EMERGENCY_STOP)',
  `current_program` varchar(100) DEFAULT NULL COMMENT 'Текущая программа',
  `current_stage` int(11) DEFAULT NULL COMMENT 'Текущий этап',
  `stage_progress` int(11) DEFAULT NULL COMMENT 'Прогресс этапа (%)',
  `uptime` int(11) DEFAULT NULL COMMENT 'Время работы (секунды)',
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_device_time` (`device_id`, `timestamp`),
  KEY `idx_run_id` (`run_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 6. DEVICE_COMMANDS TABLE
-- Команды для устройств
-- ============================================

CREATE TABLE IF NOT EXISTS `device_commands` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `command_type` varchar(50) NOT NULL COMMENT 'Тип команды',
  `command_data` text DEFAULT NULL COMMENT 'Данные команды (JSON)',
  `status` varchar(20) NOT NULL DEFAULT 'pending' COMMENT 'pending, sent, executed, failed',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` timestamp NULL DEFAULT NULL,
  `executed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `idx_status` (`status`),
  KEY `idx_device_status` (`device_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 7. SYNC_HISTORY TABLE
-- История синхронизации программ
-- ============================================

CREATE TABLE IF NOT EXISTS `sync_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `sync_type` varchar(20) NOT NULL COMMENT 'programs, settings, firmware',
  `programs_synced` int(11) DEFAULT 0 COMMENT 'Количество синхронизированных программ',
  `sync_status` varchar(20) NOT NULL DEFAULT 'success' COMMENT 'success, partial, failed',
  `error_message` text DEFAULT NULL,
  `synced_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `idx_sync_type` (`sync_type`),
  KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 8. RUNS TABLE
-- История запусков программ
-- ============================================

CREATE TABLE IF NOT EXISTS `runs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `program_id` int(11) DEFAULT NULL COMMENT 'ID программы',
  `program_name` varchar(100) NOT NULL COMMENT 'Название программы',
  `run_id` varchar(50) DEFAULT NULL COMMENT 'Уникальный ID запуска',
  `start_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время начала',
  `end_time` timestamp NULL DEFAULT NULL COMMENT 'Время окончания',
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время начала (алиас)',
  `finished_at` timestamp NULL DEFAULT NULL COMMENT 'Время окончания (алиас)',
  `status` varchar(20) NOT NULL DEFAULT 'running' COMMENT 'running, completed, stopped, emergency',
  `stop_reason` text DEFAULT NULL COMMENT 'Причина остановки',
  `total_stages` int(11) DEFAULT NULL COMMENT 'Всего этапов',
  `completed_stages` int(11) DEFAULT 0 COMMENT 'Завершено этапов',
  `avg_temp` float DEFAULT NULL COMMENT 'Средняя температура',
  `avg_humidity` float DEFAULT NULL COMMENT 'Средняя влажность',
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `program_id` (`program_id`),
  KEY `idx_status` (`status`),
  KEY `idx_started` (`started_at`),
  KEY `idx_start_time` (`start_time`),
  CONSTRAINT `fk_runs_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 9. TEMPLATES TABLE
-- Шаблоны программ (публичные)
-- ============================================

CREATE TABLE IF NOT EXISTS `templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL COMMENT 'Название шаблона',
  `description` text DEFAULT NULL COMMENT 'Описание шаблона',
  `category` varchar(50) DEFAULT NULL COMMENT 'Категория (fish, meat, poultry, cheese, vegetables, other)',
  `is_public` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Публичный шаблон',
  `is_built_in` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Встроенный шаблон',
  `created_by` int(11) DEFAULT NULL COMMENT 'Автор шаблона',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_public` (`is_public`),
  KEY `idx_built_in` (`is_built_in`),
  KEY `idx_category` (`category`),
  CONSTRAINT `fk_templates_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 10. TEMPLATE_STAGES TABLE
-- Этапы шаблонов
-- ============================================

CREATE TABLE IF NOT EXISTS `template_stages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `template_id` int(11) NOT NULL COMMENT 'ID шаблона',
  `stage_order` int(11) NOT NULL COMMENT 'Порядковый номер этапа',
  `stage_name` varchar(100) NOT NULL COMMENT 'Название этапа',
  `target_temp` float NOT NULL COMMENT 'Целевая температура камеры (°C)',
  `target_temp_device` int(11) NOT NULL DEFAULT 0 COMMENT 'Устройство измерения (0=камера, 1=дым, 2=продукт)',
  `target_humidity` float DEFAULT NULL COMMENT 'Целевая влажность (%)',
  `duration_minutes` int(11) NOT NULL COMMENT 'Длительность (минуты)',
  `hysteresis` float DEFAULT 2.0 COMMENT 'Гистерезис температуры (°C)',
  `wait_for_temp` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Ждать достижения температуры',
  `use_smoke_generator` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Использовать дымогенератор',
  `ventilation_percent` int(11) DEFAULT 50 COMMENT 'Вентиляция (0-100%)',
  `internal_fan_on` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Внутренний вентилятор',
  `injection_fan_on` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Вентилятор подачи',
  `compressor_pwm` int(11) DEFAULT -1 COMMENT 'ШИМ компрессора (-1=авто, 0-100)',
  PRIMARY KEY (`id`),
  KEY `template_id` (`template_id`),
  KEY `idx_stage_order` (`stage_order`),
  CONSTRAINT `fk_template_stages` FOREIGN KEY (`template_id`) REFERENCES `templates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 11. ADMIN_LOGS TABLE
-- Логи действий администраторов
-- ============================================

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 12. LOGS TABLE
-- Системные логи (для rate limiting и отладки)
-- ============================================

CREATE TABLE IF NOT EXISTS `logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `level` varchar(20) NOT NULL COMMENT 'Уровень (DEBUG, INFO, WARNING, ERROR, CRITICAL)',
  `message` text NOT NULL COMMENT 'Сообщение',
  `context` text DEFAULT NULL COMMENT 'Контекст (JSON)',
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP адрес',
  `user_agent` varchar(255) DEFAULT NULL COMMENT 'User Agent',
  `user_id` int(11) DEFAULT NULL COMMENT 'ID пользователя',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_level` (`level`),
  KEY `idx_created` (`created_at`),
  KEY `idx_level_created` (`level`, `created_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 13. USER_SETTINGS TABLE
-- Настройки пользователей
-- ============================================

CREATE TABLE IF NOT EXISTS `user_settings` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя',
  `timezone` varchar(50) NOT NULL DEFAULT 'Europe/Moscow' COMMENT 'Часовой пояс',
  `language` varchar(10) NOT NULL DEFAULT 'ru' COMMENT 'Язык интерфейса',
  `notifications_enabled` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Включить уведомления',
  `email_notifications` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Email уведомления',
  `dashboard_layout` varchar(50) DEFAULT 'default' COMMENT 'Макет дашборда',
  `theme` varchar(20) DEFAULT 'light' COMMENT 'Тема (light/dark)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  KEY `idx_timezone` (`timezone`),
  KEY `idx_language` (`language`),
  CONSTRAINT `fk_user_settings_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 14. BINDING_REQUESTS TABLE
-- Асинхронные запросы на привязку устройств
-- ============================================

CREATE TABLE IF NOT EXISTS `binding_requests` (
  `request_id` varchar(36) NOT NULL COMMENT 'Уникальный идентификатор запроса (UUID)',
  `uuid` varchar(36) NOT NULL COMMENT 'UUID устройства',
  `user_id` int(11) DEFAULT NULL COMMENT 'ID пользователя',
  `status` enum('pending','completed','failed') NOT NULL DEFAULT 'pending' COMMENT 'Статус обработки',
  `api_token` varchar(64) DEFAULT NULL COMMENT 'Сгенерированный API токен',
  `message` text DEFAULT NULL COMMENT 'Статусное сообщение или описание ошибки',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_uuid` (`uuid`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_binding_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Асинхронные запросы на привязку контроллеров';

-- ============================================
-- 15. FILE_DELIVERY_LOG TABLE
-- Отслеживание доставки файлов на устройства
-- ============================================

CREATE TABLE IF NOT EXISTS `file_delivery_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `file_name` varchar(255) NOT NULL COMMENT 'Имя файла',
  `file_type` varchar(50) NOT NULL COMMENT 'Тип файла (program, firmware, config)',
  `file_checksum` varchar(64) DEFAULT NULL COMMENT 'Контрольная сумма файла',
  `file_size` int(11) DEFAULT NULL COMMENT 'Размер файла в байтах',
  `delivery_status` enum('pending','sent','delivered','failed','timeout') NOT NULL DEFAULT 'pending' COMMENT 'Статус доставки',
  `sent_at` timestamp NULL DEFAULT NULL COMMENT 'Время отправки',
  `delivered_at` timestamp NULL DEFAULT NULL COMMENT 'Время подтверждения получения',
  `error_message` text DEFAULT NULL COMMENT 'Сообщение об ошибке',
  `retry_count` int(11) NOT NULL DEFAULT 0 COMMENT 'Количество попыток',
  `last_retry_at` timestamp NULL DEFAULT NULL COMMENT 'Время последней попытки',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `idx_device_status` (`device_id`, `delivery_status`),
  KEY `idx_pending_delivery` (`delivery_status`, `retry_count`, `last_retry_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 16. PROGRAM_TRANSFER_QUEUE TABLE
-- Очередь передачи программ на устройства
-- ============================================

CREATE TABLE IF NOT EXISTS `program_transfer_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `transfer_id` varchar(50) NOT NULL COMMENT 'Уникальный ID передачи',
  `program_id` int(11) NOT NULL COMMENT 'ID программы',
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя',
  `status` enum('pending','sent','confirmed','failed') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `failed_at` timestamp NULL DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `error_code` varchar(50) DEFAULT NULL,
  `retry_count` int(11) NOT NULL DEFAULT 0,
  `previous_attempt_id` int(11) DEFAULT NULL COMMENT 'ID предыдущей попытки передачи',
  `controller_response` text DEFAULT NULL COMMENT 'Ответ контроллера (JSON)',
  PRIMARY KEY (`id`),
  UNIQUE KEY `transfer_id` (`transfer_id`),
  KEY `program_id` (`program_id`),
  KEY `device_id` (`device_id`),
  KEY `user_id` (`user_id`),
  KEY `status` (`status`),
  KEY `created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 17. DEVICE_PROGRAMS TABLE
-- Программы, хранящиеся на устройствах
-- ============================================

CREATE TABLE IF NOT EXISTS `device_programs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `program_id` int(11) NOT NULL COMMENT 'ID программы',
  `storage_path` varchar(255) NOT NULL COMMENT 'Путь к файлу на устройстве',
  `file_size` int(11) DEFAULT NULL COMMENT 'Размер файла в байтах',
  `uploaded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_verified` timestamp NULL DEFAULT NULL COMMENT 'Время последней проверки',
  `status` enum('active','deleted','error') NOT NULL DEFAULT 'active',
  `last_run_at` timestamp NULL DEFAULT NULL,
  `run_count` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_program` (`device_id`, `program_id`, `status`),
  KEY `device_id` (`device_id`),
  KEY `program_id` (`program_id`),
  KEY `status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 18. FIRMWARE_UPDATES TABLE
-- Прошивки для OTA-обновлений
-- ============================================

CREATE TABLE IF NOT EXISTS `firmware_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `version` varchar(20) NOT NULL COMMENT 'Версия прошивки',
  `filename` varchar(255) NOT NULL COMMENT 'Имя файла',
  `file_path` varchar(500) NOT NULL COMMENT 'Путь к файлу',
  `file_size` int(11) NOT NULL COMMENT 'Размер файла в байтах',
  `checksum` varchar(64) NOT NULL COMMENT 'Контрольная сумма',
  `file_hash` varchar(128) DEFAULT NULL COMMENT 'Хеш файла для проверки целостности',
  `release_notes` text DEFAULT NULL COMMENT 'Описание изменений',
  `is_required` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Обязательное обновление',
  `min_version_required` varchar(20) DEFAULT NULL COMMENT 'Минимальная версия для обновления',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT 'Активная прошивка',
  `release_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `version` (`version`),
  KEY `idx_version` (`version`),
  KEY `idx_firmware_active` (`is_active`, `release_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 19. FIRMWARE_DOWNLOADS TABLE
-- История скачиваний прошивок
-- ============================================

CREATE TABLE IF NOT EXISTS `firmware_downloads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(100) NOT NULL COMMENT 'ID устройства',
  `firmware_version` varchar(20) NOT NULL COMMENT 'Версия прошивки',
  `download_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device` (`device_id`),
  KEY `idx_version` (`firmware_version`),
  KEY `idx_download_time` (`download_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 20. DEVICE_UPDATES TABLE
-- История обновлений прошивок на устройствах
-- ============================================

CREATE TABLE IF NOT EXISTS `device_updates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(100) NOT NULL COMMENT 'ID устройства',
  `firmware_version` varchar(20) NOT NULL COMMENT 'Новая версия прошивки',
  `previous_version` varchar(20) DEFAULT NULL COMMENT 'Предыдущая версия',
  `update_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `update_status` enum('success','failed','in_progress') NOT NULL DEFAULT 'in_progress',
  `error_message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_device` (`device_id`),
  KEY `idx_update_time` (`update_time`),
  KEY `idx_status` (`update_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- INITIAL DATA
-- Начальные данные
-- ============================================

-- Демо-пользователи
-- Пароль для обоих: demo123
-- Хеш сгенерирован с помощью password_hash('demo123', PASSWORD_DEFAULT)
INSERT INTO `users` (`username`, `email`, `password_hash`, `full_name`, `role`, `is_active`, `created_at`) VALUES
('admin', 'admin@smartsmoker.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Администратор', 'admin', 1, NOW()),
('demo', 'demo@smartsmoker.local', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Демо Пользователь', 'user', 1, NOW());

-- Системные программы копчения
INSERT INTO `programs` (`program_name`, `description`, `category`, `user_id`, `device_id`, `is_public`, `is_system`, `is_built_in`, `created_at`) VALUES
('Холодное копчение рыбы', 'Классическая программа холодного копчения для рыбы', 'fish', NULL, NULL, 1, 1, 1, NOW()),
('Горячее копчение мяса', 'Программа горячего копчения для мяса и птицы', 'meat', NULL, NULL, 1, 1, 1, NOW()),
('Сушка', 'Программа сушки без копчения', 'other', NULL, NULL, 1, 1, 1, NOW());

-- Этапы для программы "Холодное копчение рыбы"
INSERT INTO `program_stages` (`program_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `smoke_intensity`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(1, 1, 'Подсушка', 25.0, 0, 70.0, 60, 2.0, 1, 0, 0, 100, 1, 1, -1),
(1, 2, 'Копчение', 30.0, 0, 65.0, 180, 2.0, 1, 1, 80, 50, 1, 1, -1);

-- Этапы для программы "Горячее копчение мяса"
INSERT INTO `program_stages` (`program_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `smoke_intensity`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(2, 1, 'Разогрев', 60.0, 0, 60.0, 30, 3.0, 1, 0, 0, 80, 1, 1, -1),
(2, 2, 'Копчение', 80.0, 0, 55.0, 120, 3.0, 1, 1, 90, 40, 1, 1, -1);

-- Этапы для программы "Сушка"
INSERT INTO `program_stages` (`program_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `smoke_intensity`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(3, 1, 'Сушка', 40.0, 0, 50.0, 240, 2.0, 1, 0, 0, 100, 1, 1, -1);

-- Публичные шаблоны программ
INSERT INTO `templates` (`name`, `description`, `category`, `is_public`, `is_built_in`, `created_at`) VALUES
('Сёмга холодного копчения', 'Классический рецепт холодного копчения сёмги', 'fish', 1, 1, NOW()),
('Грудинка горячего копчения', 'Сочная грудинка с дымком', 'meat', 1, 1, NOW()),
('Куриные крылышки', 'Ароматные крылышки с хрустящей корочкой', 'poultry', 1, 1, NOW()),
('Сыр копчёный', 'Домашний копчёный сыр', 'cheese', 1, 1, NOW());

-- Этапы для шаблона "Сёмга холодного копчения"
INSERT INTO `template_stages` (`template_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(1, 1, 'Сушка', 25.0, 0, 60.0, 240, 2.0, 1, 0, 70, 1, 1, -1),
(1, 2, 'Копчение', 25.0, 0, 70.0, 480, 2.0, 1, 1, 50, 0, 1, -1),
(1, 3, 'Проветривание', 20.0, 0, 50.0, 60, 2.0, 0, 0, 100, 1, 1, -1);

-- Этапы для шаблона "Грудинка горячего копчения"
INSERT INTO `template_stages` (`template_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(2, 1, 'Разогрев', 60.0, 0, 60.0, 30, 3.0, 1, 0, 80, 1, 1, -1),
(2, 2, 'Копчение', 80.0, 0, 55.0, 240, 3.0, 1, 1, 40, 1, 1, 80);

-- Этапы для шаблона "Куриные крылышки"
INSERT INTO `template_stages` (`template_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(3, 1, 'Подготовка', 70.0, 0, 60.0, 30, 3.0, 1, 0, 70, 1, 1, -1),
(3, 2, 'Копчение', 90.0, 0, 50.0, 120, 3.0, 1, 1, 50, 1, 1, 85);

-- Этапы для шаблона "Сыр копчёный"
INSERT INTO `template_stages` (`template_id`, `stage_order`, `stage_name`, `target_temp`, `target_temp_device`, `target_humidity`, `duration_minutes`, `hysteresis`, `wait_for_temp`, `use_smoke_generator`, `ventilation_percent`, `internal_fan_on`, `injection_fan_on`, `compressor_pwm`) VALUES
(4, 1, 'Копчение', 30.0, 0, 65.0, 180, 2.0, 1, 1, 60, 0, 1, 70);

-- ============================================
-- 21. PROGRAM_DELIVERY_LOG TABLE
-- Лог подтверждений доставки программ устройствами
-- ============================================

CREATE TABLE IF NOT EXISTS `program_delivery_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` int(11) NOT NULL COMMENT 'ID устройства (числовой)',
  `program_id` int(11) NOT NULL COMMENT 'ID программы',
  `program_name` varchar(100) NOT NULL COMMENT 'Название программы',
  `status` varchar(20) NOT NULL DEFAULT 'delivered' COMMENT 'delivered, error',
  `error_message` text DEFAULT NULL COMMENT 'Сообщение об ошибке',
  `confirmed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время подтверждения',
  PRIMARY KEY (`id`),
  KEY `device_id` (`device_id`),
  KEY `program_id` (`program_id`),
  KEY `idx_confirmed_at` (`confirmed_at`),
  CONSTRAINT `fk_pdl_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог подтверждений доставки программ от устройств';

-- ============================================
-- 22. PROGRAM_RUN_LOG TABLE
-- Лог завершённых запусков программ (с run_id)
-- ============================================

CREATE TABLE IF NOT EXISTS `program_run_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'UUID устройства',
  `program_id` int(11) DEFAULT NULL COMMENT 'ID программы (если найдена в БД)',
  `program_name` varchar(100) NOT NULL COMMENT 'Название программы',
  `run_id` varchar(36) NOT NULL COMMENT 'Уникальный ID запуска (UUID)',
  `duration_seconds` int(11) NOT NULL DEFAULT 0 COMMENT 'Длительность запуска в секундах',
  `completed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время завершения',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_run_id` (`run_id`),
  KEY `idx_device_id` (`device_id`),
  KEY `idx_program_id` (`program_id`),
  KEY `idx_completed_at` (`completed_at`),
  CONSTRAINT `fk_prl_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог завершённых запусков программ с привязкой к run_id';

-- ============================================
-- INDEXES FOR OPTIMIZATION
-- Дополнительные индексы для оптимизации
-- ============================================

-- Оптимизация запросов телеметрии
ALTER TABLE `sensor_data` 
  ADD INDEX `idx_device_mode` (`device_id`, `mode`),
  ADD INDEX `idx_recent_data` (`device_id`, `timestamp` DESC);

-- Оптимизация запросов команд
ALTER TABLE `device_commands`
  ADD INDEX `idx_pending_commands` (`device_id`, `status`, `created_at`);

-- ============================================
-- VIEWS
-- Представления для удобных запросов
-- ============================================

-- Последние данные с каждого устройства
CREATE OR REPLACE VIEW `latest_sensor_data` AS
SELECT 
  sd.*,
  d.name as device_name,
  d.is_online
FROM `sensor_data` sd
INNER JOIN (
  SELECT device_id, MAX(timestamp) as max_timestamp
  FROM sensor_data
  GROUP BY device_id
) latest ON sd.device_id = latest.device_id AND sd.timestamp = latest.max_timestamp
LEFT JOIN `devices` d ON sd.device_id = d.device_id;

-- Активные устройства
CREATE OR REPLACE VIEW `active_devices` AS
SELECT 
  d.*,
  COUNT(DISTINCT sd.id) as data_points_count,
  MAX(sd.timestamp) as last_data_timestamp
FROM `devices` d
LEFT JOIN `sensor_data` sd ON d.device_id = sd.device_id 
  AND sd.timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
WHERE d.is_online = 1
GROUP BY d.id;

-- Статистика программ
CREATE OR REPLACE VIEW `program_statistics` AS
SELECT 
  p.id,
  p.program_name,
  p.category,
  COUNT(DISTINCT r.id) as total_runs,
  COUNT(DISTINCT CASE WHEN r.status = 'completed' THEN r.id END) as completed_runs,
  COUNT(DISTINCT CASE WHEN r.status = 'emergency' THEN r.id END) as emergency_stops,
  AVG(CASE WHEN r.status = 'completed' THEN r.avg_temp END) as avg_completion_temp,
  AVG(CASE WHEN r.status = 'completed' THEN r.avg_humidity END) as avg_completion_humidity
FROM `programs` p
LEFT JOIN `runs` r ON p.id = r.program_id
GROUP BY p.id;

-- ============================================
-- STORED PROCEDURES
-- Хранимые процедуры
-- ============================================

DELIMITER //

-- Процедура очистки старых данных телеметрии
CREATE PROCEDURE `cleanup_old_sensor_data`(IN days_to_keep INT)
BEGIN
  DELETE FROM `sensor_data` 
  WHERE `timestamp` < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
  
  SELECT ROW_COUNT() as deleted_rows;
END //

-- Процедура очистки старых логов
CREATE PROCEDURE `cleanup_old_logs`(IN days_to_keep INT)
BEGIN
  DELETE FROM `logs` 
  WHERE `created_at` < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
  
  SELECT ROW_COUNT() as deleted_rows;
END //

-- Процедура получения статистики устройства
CREATE PROCEDURE `get_device_statistics`(IN p_device_id VARCHAR(50))
BEGIN
  SELECT 
    COUNT(*) as total_data_points,
    MIN(timestamp) as first_seen,
    MAX(timestamp) as last_seen,
    AVG(temp_chamber) as avg_temp_chamber,
    AVG(humidity) as avg_humidity,
    COUNT(DISTINCT DATE(timestamp)) as active_days
  FROM `sensor_data`
  WHERE device_id = p_device_id;
END //

DELIMITER ;

-- ============================================
-- TRIGGERS
-- Триггеры
-- ============================================

DELIMITER //

-- Обновление статуса устройства при получении данных
CREATE TRIGGER `update_device_last_seen` 
AFTER INSERT ON `sensor_data`
FOR EACH ROW
BEGIN
  UPDATE `devices` 
  SET 
    `last_seen` = NEW.timestamp,
    `is_online` = 1
  WHERE `device_id` = NEW.device_id;
END //

-- Автоматическое завершение запуска при аварийной остановке
CREATE TRIGGER `handle_emergency_stop`
AFTER INSERT ON `device_commands`
FOR EACH ROW
BEGIN
  IF NEW.command_type = 'emergency_stop' THEN
    UPDATE `runs`
    SET 
      `status` = 'emergency',
      `finished_at` = NOW(),
      `stop_reason` = COALESCE(NEW.command_data, 'Emergency stop triggered')
    WHERE `device_id` = NEW.device_id 
      AND `status` = 'running';
  END IF;
END //

DELIMITER ;

-- ============================================
-- GRANTS
-- Права доступа (настроить под свои нужды)
-- ============================================

-- Создание пользователя для приложения (раскомментировать и настроить)
-- CREATE USER 'smartsmoker_app'@'localhost' IDENTIFIED BY 'your_secure_password';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON smart_smoker.* TO 'smartsmoker_app'@'localhost';
-- FLUSH PRIVILEGES;

-- ============================================
-- MAINTENANCE
-- Рекомендации по обслуживанию
-- ============================================

-- Запускать еженедельно для очистки старых данных (старше 90 дней)
-- CALL cleanup_old_sensor_data(90);
-- CALL cleanup_old_logs(30);

-- Оптимизация таблиц (запускать ежемесячно)
-- OPTIMIZE TABLE sensor_data, device_commands, sync_history, logs;

-- Анализ таблиц для обновления статистики
-- ANALYZE TABLE sensor_data, devices, programs, logs;

-- ============================================
-- DEMO CREDENTIALS
-- Демо учётные данные
-- ============================================

-- ✅ АДМИНИСТРАТОР:
-- Email: admin@smartsmoker.local
-- Пароль: demo123
-- Роль: admin
-- Доступ: Полный (включая админ-панель)

-- ✅ ОБЫЧНЫЙ ПОЛЬЗОВАТЕЛЬ:
-- Email: demo@smartsmoker.local
-- Пароль: demo123
-- Роль: user
-- Доступ: Устройства и программы

-- ⚠️ ВАЖНО: 
-- 1. После первого входа ОБЯЗАТЕЛЬНО смените пароли!
-- 2. Для продакшена удалите эти учётные записи:
--    DELETE FROM users WHERE email LIKE '%@smartsmoker.local';
-- 3. Создайте реальные учётные записи с безопасными паролями

-- 🔐 Генерация нового пароля:
-- php -r "echo password_hash('ваш_пароль', PASSWORD_DEFAULT);"

-- ============================================
-- END OF SCHEMA
-- ============================================

-- ============================================
-- 23. PUSH_SUBSCRIPTIONS TABLE
-- Web Push подписки пользователей
-- ============================================

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя',
  `endpoint` varchar(500) NOT NULL COMMENT 'Push endpoint URL',
  `p256dh` varchar(200) NOT NULL COMMENT 'Публичный ключ клиента (base64url)',
  `auth` varchar(100) NOT NULL COMMENT 'Auth secret (base64url)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_endpoint` (`endpoint`(191)),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `fk_push_sub_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Web Push подписки для PWA уведомлений';

-- ============================================
-- 24. PUSH_NOTIFICATION_LOG TABLE
-- Лог отправленных Push-уведомлений
-- ============================================

CREATE TABLE IF NOT EXISTS `push_notification_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `device_id` varchar(50) NOT NULL COMMENT 'ID устройства',
  `user_id` int(11) NOT NULL COMMENT 'ID пользователя',
  `type` varchar(50) NOT NULL COMMENT 'Тип уведомления (smoke_ignition, program_complete, etc.)',
  `sent_count` int(11) NOT NULL DEFAULT 1 COMMENT 'Количество отправленных подписок',
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_device_type` (`device_id`, `type`),
  KEY `idx_sent_at` (`sent_at`),
  KEY `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Лог отправленных Push-уведомлений';
