-- Таблица для хранения информации о прошивках
CREATE TABLE IF NOT EXISTS `firmware_updates` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Прошивки для OTA-обновлений';

-- Таблица для отслеживания скачиваний прошивок
CREATE TABLE IF NOT EXISTS `firmware_downloads` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `firmware_version` varchar(20) NOT NULL COMMENT 'Версия прошивки',
  `device_id` varchar(36) NOT NULL COMMENT 'UUID устройства',
  `downloaded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL COMMENT 'IP адрес устройства',
  PRIMARY KEY (`id`),
  KEY `firmware_version` (`firmware_version`),
  KEY `device_id` (`device_id`),
  KEY `downloaded_at` (`downloaded_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='История скачиваний прошивок';
