-- Миграция: создание таблицы rate_limits для RateLimiter
-- Выполнить один раз на сервере

CREATE TABLE IF NOT EXISTS rate_limits (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    rate_key     VARCHAR(255) NOT NULL,
    requests     INT          NOT NULL DEFAULT 1,
    window_start DATETIME     NOT NULL,
    INDEX idx_key_window (rate_key, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
