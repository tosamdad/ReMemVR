<?php
namespace App\Core;

/**
 * 파일 세션. 공유 호스팅의 기본 세션 폴더(다른 계정과 공유, 짧은 정리 주기)를 피하려고
 * 저장 폴더(rememvr_data/sessions/영역)에 따로 둔다.
 * 회원 영역은 30일 유지, 관리자 영역(/admin)은 12시간 유지하며 쿠키 이름도 다르다.
 */
class Session
{
    /** @var string */
    private static $area = '';

    public static function start(string $area): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        self::$area = $area;
        $admin = $area === 'admin';
        $lifetime = $admin ? 43200 : 2592000;
        $dir = ensure_dir(storage_path('sessions/' . ($admin ? 'admin' : 'user')));
        if (is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name($admin ? 'RMADMIN' : 'RMSESS');
        session_set_cookie_params([
            'lifetime' => $admin ? 0 : $lifetime,
            'path' => base_path() . ($admin ? '/admin' : '/'),
            'secure' => Request::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        // 쿠키 만료를 매 요청마다 연장한다(회원 영역).
        if (!$admin && !headers_sent()) {
            setcookie(session_name(), session_id(), [
                'expires' => time() + $lifetime,
                'path' => base_path() . '/',
                'secure' => Request::isHttps(),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
        // 이전 요청에서 넘어온 1회성 값(old, errors)은 이번 요청에서만 보인다.
        $_SESSION['_old_now'] = isset($_SESSION['_old_next']) ? $_SESSION['_old_next'] : [];
        $_SESSION['_errors_now'] = isset($_SESSION['_errors_next']) ? $_SESSION['_errors_next'] : [];
        unset($_SESSION['_old_next'], $_SESSION['_errors_next']);
    }

    public static function area(): string
    {
        return self::$area;
    }

    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function get(string $key, $default = null)
    {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }

    public static function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        if (!headers_sent()) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Lax',
            ]);
        }
        session_destroy();
    }

    public static function flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        $items = isset($_SESSION['_flash']) ? $_SESSION['_flash'] : [];
        unset($_SESSION['_flash']);

        return $items;
    }

    public static function setOldInput(array $input, array $errors): void
    {
        $_SESSION['_old_next'] = $input;
        $_SESSION['_errors_next'] = $errors;
    }

    public static function old(string $key, $default = '')
    {
        $old = isset($_SESSION['_old_now']) ? $_SESSION['_old_now'] : [];

        return array_key_exists($key, $old) ? $old[$key] : $default;
    }

    public static function errors(): array
    {
        return isset($_SESSION['_errors_now']) ? $_SESSION['_errors_now'] : [];
    }
}
