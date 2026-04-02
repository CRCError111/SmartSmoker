<?php
/**
 * Rate Limiter — ограничение частоты запросов
 * Реализация через таблицу БД (без Redis)
 *
 * @version 1.0
 */

defined('SMART_SMOKER') or die('Прямой доступ запрещён');

require_once __DIR__ . '/db.php';

class RateLimiter {
    /**
     * Проверить и инкрементировать счётчик запросов.
     * Возвращает true если лимит не превышен, false если превышен.
     *
     * @param string $key          Уникальный ключ (например 'telemetry_<device_id>')
     * @param int    $maxRequests  Максимальное количество запросов за окно
     * @param int    $windowSeconds Размер окна в секундах
     * @return bool
     */
    public static function check(string $key, int $maxRequests, int $windowSeconds): bool {
        $db = db();
        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);

        // Считаем запросы в текущем окне
        $count = (int)$db->fetchColumn(
            'SELECT COALESCE(SUM(requests), 0) FROM rate_limits WHERE rate_key = ? AND window_start >= ?',
            [$key, $windowStart]
        );

        if ($count >= $maxRequests) {
            return false;
        }

        // Текущий слот окна (округляем до начала окна)
        $slotStart = date('Y-m-d H:i:s', (int)(floor(time() / $windowSeconds) * $windowSeconds));

        // Пробуем обновить существующий слот
        $existing = $db->fetchOne(
            'SELECT id FROM rate_limits WHERE rate_key = ? AND window_start = ?',
            [$key, $slotStart]
        );

        if ($existing) {
            $db->query(
                'UPDATE rate_limits SET requests = requests + 1 WHERE id = ?',
                [$existing['id']]
            );
        } else {
            try {
                $db->insert('rate_limits', [
                    'rate_key'     => $key,
                    'requests'     => 1,
                    'window_start' => $slotStart
                ]);
            } catch (Exception $e) {
                // Race condition — слот уже создан другим запросом, обновляем
                $db->query(
                    'UPDATE rate_limits SET requests = requests + 1 WHERE rate_key = ? AND window_start = ?',
                    [$key, $slotStart]
                );
            }
        }

        return true;
    }

    /**
     * Очистка устаревших записей (вызывать периодически из cron)
     */
    public static function cleanup(): void {
        $db = db();
        $db->query(
            'DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 HOUR)'
        );
    }
}
