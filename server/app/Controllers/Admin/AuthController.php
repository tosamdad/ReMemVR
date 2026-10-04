<?php
namespace App\Controllers\Admin;

use App\Core\AdminAuth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Validator;

/**
 * 관리자 진입(/admin), 최초 설정(/admin/setup), 로그인, 로그아웃.
 * 최초 설정은 관리자 계정이 하나도 없을 때만 열리고, 배포 때 쓰는 OPS_TOKEN 을 알아야 한다.
 */
class AuthController
{
    /** 로그인 실패 허용 횟수(10분) */
    const LOGIN_MAX_FAILS = 5;
    const LOGIN_WINDOW = 600;

    /** 최초 설정 시도 허용 횟수(10분) */
    const SETUP_MAX_TRIES = 5;
    const SETUP_WINDOW = 600;

    /** GET /admin */
    public function index(): void
    {
        if (AdminAuth::needsSetup()) {
            redirect('/admin/setup');
        }
        require_admin();
        redirect('/admin/dashboard');
    }

    // ───────────────────────── 최초 설정 ─────────────────────────

    /** GET /admin/setup */
    public function setupForm(): string
    {
        if (!AdminAuth::needsSetup()) {
            abort(404);
        }

        return view('admin/auth/setup', [
            'tokenConfigured' => self::opsTokenConfigured(),
        ]);
    }

    /** POST /admin/setup */
    public function setup(): void
    {
        if (!AdminAuth::needsSetup()) {
            abort(404);
        }
        // 시도마다 센다(토큰 무차별 대입 방지).
        if (!RateLimiter::hit('admin-setup:' . client_ip(), self::SETUP_MAX_TRIES, self::SETUP_WINDOW)) {
            back_with_errors(['ops_token' => '시도가 너무 많습니다. 10분 뒤에 다시 시도하세요.'], '/admin/setup');
        }

        $v = Validator::make($_POST, [
            'ops_token' => 'required',
            'login_id' => 'required|min:4|max:50',
            'name' => 'required|max:50',
            'email' => 'required|email|max:191',
            'password' => 'required|min:10|max:200|confirmed',
        ], [
            'ops_token' => 'OPS_TOKEN',
            'login_id' => '아이디',
            'name' => '이름',
            'email' => '이메일',
            'password' => '비밀번호',
        ]);
        $errors = $v->errors();
        $data = $v->validated();

        if (!isset($errors['ops_token'])) {
            if (!self::opsTokenConfigured()) {
                $errors['ops_token'] = '서버 설정에 OPS_TOKEN 이 없거나 32자보다 짧습니다. 배포 설정을 먼저 확인하세요.';
            } elseif (!hash_equals((string) config('ops_token'), (string) $data['ops_token'])) {
                $errors['ops_token'] = 'OPS_TOKEN 이 일치하지 않습니다.';
            }
        }
        if (!isset($errors['login_id']) && !preg_match('/^[A-Za-z0-9._-]+$/', (string) $data['login_id'])) {
            $errors['login_id'] = '아이디는 영문, 숫자, 점(.), 밑줄(_), 하이픈(-)만 쓸 수 있습니다.';
        }
        if ($errors) {
            back_with_errors($errors, '/admin/setup');
        }

        // 두 요청이 동시에 들어와도 관리자는 한 명만 만든다.
        $adminId = db_tx(static function () use ($data) {
            $count = (int) db_value('SELECT COUNT(*) FROM admins FOR UPDATE');
            if ($count > 0) {
                return 0;
            }

            return db_insert('admins', [
                'login_id' => (string) $data['login_id'],
                'password_hash' => password_hash((string) $_POST['password'], PASSWORD_DEFAULT),
                'name' => (string) $data['name'],
                'email' => (string) $data['email'],
                'role' => 'super',
                'status' => 'active',
            ]);
        });
        if (!$adminId) {
            abort(404);
        }
        $admin = db_one('SELECT * FROM admins WHERE id = ?', [$adminId]);
        AdminAuth::login($admin);
        RateLimiter::clear('admin-setup:' . client_ip());
        admin_audit('admin.setup', 'admin', $adminId, ['login_id' => $admin['login_id']]);
        flash('success', '최고 관리자 계정을 만들었습니다. 환영합니다, ' . $admin['name'] . '님.');
        redirect('/admin/dashboard');
    }

    // ───────────────────────── 로그인, 로그아웃 ─────────────────────────

    /** GET /admin/login */
    public function loginForm(): string
    {
        if (AdminAuth::needsSetup()) {
            redirect('/admin/setup');
        }
        $next = self::nextPath(Request::query('next'));
        if (AdminAuth::admin()) {
            redirect($next);
        }

        return view('admin/auth/login', [
            'next' => $next,
            'loggedOut' => (bool) Request::query('bye'),
        ]);
    }

    /** POST /admin/login */
    public function login(): void
    {
        $loginId = Request::str('login_id');
        $password = (string) input('password', '');
        $next = self::nextPath(Request::str('next'));
        $fallback = '/admin/login' . ($next !== '/admin/dashboard' ? '?' . http_build_query(['next' => $next]) : '');

        $errors = [];
        if ($loginId === '') {
            $errors['login_id'] = '아이디를 입력하세요.';
        }
        if ($password === '') {
            $errors['password'] = '비밀번호를 입력하세요.';
        }
        if ($errors) {
            back_with_errors($errors, $fallback);
        }

        $key = 'admin-login:' . client_ip() . ':' . strtolower(mb_substr($loginId, 0, 50));
        if (self::recentFailures($key) >= self::LOGIN_MAX_FAILS) {
            back_with_errors(['login_id' => '로그인 실패가 너무 많습니다. 10분 뒤에 다시 시도하세요.'], $fallback);
        }

        $admin = db_one('SELECT * FROM admins WHERE login_id = ?', [$loginId]);
        // 계정이 없어도 같은 시간이 걸리도록 비교는 항상 한다.
        $hash = $admin ? (string) $admin['password_hash'] : '$2y$10$dQ2lJjMS0BoklVSITCPpJu3kkWXCqLxEHoXa14gU2NjZNNvM3C8KW';
        $ok = password_verify($password, $hash) && $admin !== null;
        if (!$ok) {
            $within = RateLimiter::hit($key, self::LOGIN_MAX_FAILS, self::LOGIN_WINDOW);
            back_with_errors(['password' => $within
                ? '아이디 또는 비밀번호가 올바르지 않습니다.'
                : '로그인 실패가 너무 많습니다. 10분 뒤에 다시 시도하세요.'], $fallback);
        }
        if (isset($admin['status']) && $admin['status'] !== 'active') {
            back_with_errors(['login_id' => '사용이 중지된 관리자 계정입니다. 최고 관리자에게 문의하세요.'], $fallback);
        }

        if (password_needs_rehash((string) $admin['password_hash'], PASSWORD_DEFAULT)) {
            db_exec('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
        }
        RateLimiter::clear($key);
        AdminAuth::login($admin);
        admin_audit('admin.login', 'admin', (int) $admin['id']);
        redirect($next);
    }

    /** POST /admin/logout */
    public function logout(): void
    {
        $admin = AdminAuth::admin();
        if ($admin) {
            admin_audit('admin.logout', 'admin', (int) $admin['id']);
        }
        AdminAuth::logout();
        redirect('/admin/login?bye=1');
    }

    // ───────────────────────── 내부 ─────────────────────────

    /** 배포 설정에 쓸 만한 OPS_TOKEN 이 있는지(32자 이상) */
    private static function opsTokenConfigured(): bool
    {
        return strlen((string) config('ops_token', '')) >= 32;
    }

    /** 로그인 후 돌아갈 곳. 관리자 영역 안의 경로만 허용한다. */
    private static function nextPath($next): string
    {
        $path = safe_next(is_string($next) ? $next : '', '/admin/dashboard');
        if ($path !== '/admin' && strpos($path, '/admin/') !== 0) {
            return '/admin/dashboard';
        }
        if (strpos($path, '/admin/login') === 0 || strpos($path, '/admin/logout') === 0 || strpos($path, '/admin/api/') === 0) {
            return '/admin/dashboard';
        }

        return $path;
    }

    /** 제한 창 안의 실패 횟수(기록하지 않고 읽기만 한다) */
    private static function recentFailures(string $key): int
    {
        try {
            return (int) db_value('SELECT hits FROM rate_limits WHERE k = ? AND reset_at >= NOW()', [substr($key, 0, 191)]);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
