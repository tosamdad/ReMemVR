<?php
namespace App\Core;

/**
 * 시도 횟수 제한(rate_limits 테이블).
 * if (!RateLimiter::hit('login:' . client_ip(), 10, 600)) { 너무 많은 시도 }
 */
class RateLimiter
{
    /** 이번 시도를 기록하고, 제한 안이면 true */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = substr($key, 0, 191);
        try {
            db_exec('DELETE FROM rate_limits WHERE k = ? AND reset_at < NOW()', [$key]);
            db_query(
                'INSERT INTO rate_limits (k, hits, reset_at) VALUES (?, 1, DATE_ADD(NOW(), INTERVAL ? SECOND)) ON DUPLICATE KEY UPDATE hits = hits + 1',
                [$key, $windowSeconds]
            );
            $hits = (int) db_value('SELECT hits FROM rate_limits WHERE k = ?', [$key]);

            return $hits <= $max;
        } catch (\Throwable $e) {
            app_log('error', 'rate limit 오류: ' . $e->getMessage());

            return true;
        }
    }

    /** 지금 창에서 쌓인 횟수(늘리지 않고 확인만) */
    public static function count(string $key): int
    {
        try {
            return (int) db_value('SELECT hits FROM rate_limits WHERE k = ? AND reset_at >= NOW()', [substr($key, 0, 191)]);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public static function clear(string $key): void
    {
        db_exec('DELETE FROM rate_limits WHERE k = ?', [substr($key, 0, 191)]);
    }
}
