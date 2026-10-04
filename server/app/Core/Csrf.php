<?php
namespace App\Core;

/** 세션별 CSRF 토큰. 폼에는 csrf_field(), fetch 에는 X-CSRF-Token 헤더로 보낸다. */
class Csrf
{
    public static function token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return '';
        }
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function verify(?string $token): bool
    {
        $expected = session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['_csrf']) ? (string) $_SESSION['_csrf'] : '';

        return $expected !== '' && $token !== null && $token !== '' && hash_equals($expected, $token);
    }
}
