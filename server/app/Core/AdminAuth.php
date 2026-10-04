<?php
namespace App\Core;

/** 관리자 로그인 상태. 관리자 영역 세션(RMADMIN, /admin 경로)에 admin_id 를 둔다. */
class AdminAuth
{
    /** @var array|null|false */
    private static $admin = false;

    public static function id(): ?int
    {
        $id = Session::get('admin_id');

        return $id ? (int) $id : null;
    }

    public static function admin(): ?array
    {
        if (self::$admin === false) {
            self::$admin = null;
            $id = self::id();
            if ($id) {
                $row = db_one('SELECT * FROM admins WHERE id = ?', [$id]);
                if ($row && (!isset($row['status']) || $row['status'] === 'active')) {
                    self::$admin = $row;
                } else {
                    Session::forget('admin_id');
                }
            }
        }

        return self::$admin;
    }

    public static function login(array $admin): void
    {
        Session::regenerate();
        Session::set('admin_id', (int) $admin['id']);
        db_exec('UPDATE admins SET last_login_at = NOW() WHERE id = ?', [(int) $admin['id']]);
        self::$admin = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$admin = false;
    }

    /** 관리자 계정이 하나도 없으면 최초 설정 화면을 연다. */
    public static function needsSetup(): bool
    {
        return (int) db_value('SELECT COUNT(*) FROM admins') === 0;
    }
}
