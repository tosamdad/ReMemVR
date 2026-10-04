<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Services\SocialLogin;

/** 이메일 로그인, 회원가입, 로그아웃 */
class AuthController
{
    // ───────────────────────── 공통 도우미 ─────────────────────────

    /** 로그인 직후 갈 곳. 자녀가 없으면 먼저 첫 자녀 등록으로 보낸다. */
    public static function afterLoginPath(?string $next = null): string
    {
        if (!Auth::children()) {
            return '/onboarding';
        }

        return safe_next($next, '/home');
    }

    /** 비밀번호 규칙: 8자 이상, 영문과 숫자를 함께. 문제가 없으면 null */
    public static function passwordError(string $password): ?string
    {
        if ($password === '') {
            return '비밀번호를 입력해 주세요.';
        }
        if (mb_strlen($password) < 8) {
            return '비밀번호는 8자 이상으로 만들어 주세요.';
        }
        if (strlen($password) > 72) {
            return '비밀번호는 72자 이하로 만들어 주세요.';
        }
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            return '비밀번호에 영문과 숫자를 함께 넣어 주세요.';
        }

        return null;
    }

    /** 이메일 형식 검사. 문제가 없으면 null */
    public static function emailError(string $email): ?string
    {
        if ($email === '') {
            return '이메일을 입력해 주세요.';
        }
        if (strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return '올바른 이메일 주소를 입력해 주세요.';
        }

        return null;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** 간편 로그인 버튼에 필요한 정보 */
    public static function socialButtons(string $next = ''): array
    {
        $out = [];
        foreach (SocialLogin::PROVIDERS as $p) {
            $out[$p] = [
                'ready' => SocialLogin::ready($p),
                'url' => url('/auth/' . $p, $next !== '' ? ['next' => $next] : []),
            ];
        }

        return $out;
    }

    /** 로그아웃, 탈퇴 뒤 새 세션을 열어 안내 문구를 남긴다. */
    public static function restartSessionWithFlash(string $type, string $message): void
    {
        Session::start('user');
        flash($type, $message);
    }

    // ───────────────────────── 로그인 ─────────────────────────

    public function showLogin(): string
    {
        $next = (string) Request::query('next', '');
        if (Auth::user()) {
            redirect(self::afterLoginPath($next));
        }
        $next = $next !== '' ? safe_next($next, '') : (string) old('next', '');

        return view('user/auth/login', [
            'next' => $next,
            'social' => self::socialButtons($next),
        ]);
    }

    public function login(): void
    {
        $email = self::normalizeEmail(Request::str('email'));
        $password = (string) input('password', '');
        $next = safe_next(Request::str('next'), '');

        $errors = [];
        $emailError = self::emailError($email);
        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }
        if ($password === '') {
            $errors['password'] = '비밀번호를 입력해 주세요.';
        }
        if ($errors) {
            back_with_errors($errors, '/login');
        }

        // IP+이메일, IP 하나(여러 계정 대입), 이메일 하나(여러 IP 에서 한 계정 대입) 세 가지로 제한한다.
        $key = 'login:' . client_ip() . ':' . $email;
        $failKey = 'login:fail:' . $email;
        if (!RateLimiter::hit('login:ip:' . client_ip(), 30, 600) || !RateLimiter::hit($key, 10, 600)
            || RateLimiter::count($failKey) >= 20) {
            back_with_errors(['email' => '로그인 시도가 너무 많아요. 10분 뒤에 다시 시도해 주세요.'], '/login');
        }

        $user = db_one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [$email]);
        if ($user && ($user['password_hash'] === null || $user['password_hash'] === '')) {
            $via = SocialLogin::label((string) $user['signup_provider']);
            back_with_errors(['email' => ($via !== 'email' ? $via . ' ' : '') . '간편 로그인으로 가입한 계정이에요. 아래 간편 로그인 버튼을 이용해 주세요.'], '/login');
        }
        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            RateLimiter::hit($failKey, 20, 3600);
            back_with_errors(['password' => '이메일 또는 비밀번호가 맞지 않아요.'], '/login');
        }
        if ($user['status'] !== 'active') {
            back_with_errors(['email' => '이용이 제한된 계정이에요. 고객 센터로 문의해 주세요.'], '/login');
        }

        RateLimiter::clear($key);
        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        }
        Auth::login($user);
        redirect(self::afterLoginPath($next));
    }

    // ───────────────────────── 회원가입 ─────────────────────────

    public function showSignup(): string
    {
        if (Auth::user()) {
            redirect(self::afterLoginPath());
        }

        return view('user/auth/signup', [
            'social' => self::socialButtons(),
        ]);
    }

    public function signup(): void
    {
        $data = [
            'name' => Request::str('name'),
            'email' => self::normalizeEmail(Request::str('email')),
            'password' => (string) input('password', ''),
        ];
        $errors = [];
        if ($data['name'] === '') {
            $errors['name'] = '이름을 입력해 주세요.';
        } elseif (mb_strlen($data['name']) > 30) {
            $errors['name'] = '이름은 30자 이하로 입력해 주세요.';
        }
        $emailError = self::emailError($data['email']);
        if ($emailError !== null) {
            $errors['email'] = $emailError;
        }
        $pwError = self::passwordError($data['password']);
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        }
        foreach (['agree_terms', 'agree_privacy', 'agree_age'] as $k) {
            if (!input($k)) {
                $errors['agree'] = '필수 항목에 모두 동의해 주세요.';
            }
        }
        if (!isset($errors['email']) && db_value('SELECT id FROM users WHERE email = ?', [$data['email']])) {
            $errors['email'] = '이미 가입된 이메일이에요. 로그인하거나 비밀번호 찾기를 이용해 주세요.';
        }
        if ($errors) {
            back_with_errors($errors, '/signup');
        }
        if (!RateLimiter::hit('signup:' . client_ip(), 20, 3600)) {
            back_with_errors(['email' => '가입 요청이 너무 많아요. 잠시 후 다시 시도해 주세요.'], '/signup');
        }

        $now = now();
        try {
            $id = db_insert('users', [
                'email' => $data['email'],
                'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'name' => $data['name'],
                'signup_provider' => 'email',
                'terms_agreed_at' => $now,
                'privacy_agreed_at' => $now,
                'marketing_agreed_at' => input('agree_marketing') ? $now : null,
            ]);
        } catch (\PDOException $e) {
            // 동시에 같은 이메일로 가입한 경우(고유 키 충돌)
            if ((string) $e->getCode() === '23000') {
                back_with_errors(['email' => '이미 가입된 이메일이에요. 로그인하거나 비밀번호 찾기를 이용해 주세요.'], '/signup');
            }
            throw $e;
        }

        Auth::login(['id' => $id]);
        flash('success', '가입을 환영해요! 먼저 아이 프로필을 만들어 볼까요?');
        redirect('/onboarding');
    }

    // ───────────────────────── 로그아웃 ─────────────────────────

    public function logout(): void
    {
        Auth::logout();
        self::restartSessionWithFlash('info', '로그아웃했어요. 또 만나요!');
        redirect('/login');
    }
}
