<?php
namespace App\Controllers\Admin;

use App\Core\RateLimiter;
use App\Core\Session;

/**
 * 관리자 계정. 목록은 모든 관리자가 보고, 만들기, 사용 중지, 비밀번호 초기화는 최고 관리자(super)만 한다.
 * 자기 비밀번호는 누구나 /admin/account/password 에서 바꾼다.
 */
class AdminAccountController
{
    const ROLES = ['super' => '최고 관리자', 'admin' => '운영자'];
    const PASSWORD_MIN = 10;
    const PASSWORD_MAX = 200;
    // 초기화 비밀번호를 한 번만 보여 주려고 세션에 잠시 둔다.
    const TEMP_KEY = 'admin_temp_password';

    /** GET /admin/admins */
    public function index(): string
    {
        $me = require_admin();
        $rows = db_all(
            "SELECT a.id, a.login_id, a.name, a.email, a.role, a.status, a.last_login_at, a.created_at,
                    (SELECT COUNT(*) FROM admin_audit_logs l WHERE l.admin_id = a.id AND l.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS actions_30d
             FROM admins a ORDER BY a.status = 'active' DESC, a.role = 'super' DESC, a.id"
        );
        $temp = Session::get(self::TEMP_KEY);
        Session::forget(self::TEMP_KEY);

        return view('admin/admins/index', [
            'rows' => $rows,
            'me' => $me,
            'isSuper' => $me['role'] === 'super',
            'temp' => is_array($temp) ? $temp : null,
        ]);
    }

    /** POST /admin/admins (super) */
    public function store(): void
    {
        require_admin('super');
        $str = static function (string $k): string {
            $v = input($k, '');

            return is_string($v) ? $v : '';
        };
        $d = [
            'login_id' => trim($str('login_id')),
            'name' => trim(preg_replace('/\s+/u', ' ', $str('name'))),
            'email' => trim($str('email')),
            'role' => $str('role'),
            'password' => $str('password'),
            'password_confirmation' => $str('password_confirmation'),
        ];
        $errors = [];
        $len = mb_strlen($d['login_id']);
        if ($len === 0) {
            $errors['login_id'] = '아이디를 입력해 주세요.';
        } elseif ($len < 4 || $len > 50) {
            $errors['login_id'] = '아이디는 4자 이상 50자 이하로 입력해 주세요.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $d['login_id'])) {
            $errors['login_id'] = '아이디는 영문, 숫자, 점(.), 밑줄(_), 하이픈(-)만 쓸 수 있습니다.';
        } elseif (db_value('SELECT id FROM admins WHERE login_id = ?', [$d['login_id']])) {
            $errors['login_id'] = '이미 쓰고 있는 아이디입니다.';
        }
        if ($d['name'] === '') {
            $errors['name'] = '이름을 입력해 주세요.';
        } elseif (mb_strlen($d['name']) > 50) {
            $errors['name'] = '이름은 50자 이하로 입력해 주세요.';
        }
        if ($d['email'] !== '' && (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 191)) {
            $errors['email'] = '올바른 이메일 주소를 입력해 주세요.';
        }
        if (!isset(self::ROLES[$d['role']])) {
            $errors['role'] = '권한을 골라 주세요.';
        }
        $pwError = self::passwordError($d['password'], $d['password_confirmation']);
        if ($pwError) {
            $errors[$pwError[0]] = $pwError[1];
        }
        if ($errors) {
            back_with_errors($errors, '/admin/admins');
        }
        $id = db_insert('admins', [
            'login_id' => $d['login_id'],
            'password_hash' => password_hash($d['password'], PASSWORD_DEFAULT),
            'name' => $d['name'],
            'email' => $d['email'] !== '' ? $d['email'] : null,
            'role' => $d['role'],
            'status' => 'active',
        ]);
        admin_audit('admin.create', 'admin', $id, ['login_id' => $d['login_id'], 'role' => $d['role']]);
        flash('success', "관리자 '" . $d['login_id'] . "' 계정을 만들었습니다. 비밀번호는 직접 전달해 주세요.");
        redirect('/admin/admins');
    }

    /** POST /admin/admins/{id}/status (super, action=disable|enable) */
    public function status(string $id): void
    {
        $me = require_admin('super');
        $target = self::find($id);
        $action = (string) input('action', '');
        if ((int) $target['id'] === (int) $me['id']) {
            flash('error', '자기 계정은 사용 중지할 수 없습니다.');
            redirect('/admin/admins');
        }
        if (!in_array($action, ['disable', 'enable'], true)) {
            flash('error', '알 수 없는 요청입니다.');
            redirect('/admin/admins');
        }
        $to = $action === 'disable' ? 'disabled' : 'active';
        if ($to === 'disabled' && $target['role'] === 'super') {
            $supers = (int) db_value("SELECT COUNT(*) FROM admins WHERE role = 'super' AND status = 'active' AND id <> ?", [(int) $target['id']]);
            if ($supers === 0) {
                flash('error', '사용 중인 최고 관리자가 한 명은 있어야 합니다.');
                redirect('/admin/admins');
            }
        }
        if ($target['status'] !== $to) {
            db_update('admins', ['status' => $to], 'id = ?', [(int) $target['id']]);
            admin_audit('admin.' . $action, 'admin', (int) $target['id'], ['login_id' => $target['login_id']]);
        }
        flash('success', "'" . $target['login_id'] . "' 계정을 " . ($to === 'disabled' ? '사용 중지했습니다. 바로 로그아웃됩니다.' : '다시 사용하게 했습니다.'));
        redirect('/admin/admins');
    }

    /** POST /admin/admins/{id}/password (super): 임시 비밀번호를 만들어 한 번만 보여 준다. */
    public function resetPassword(string $id): void
    {
        $me = require_admin('super');
        $target = self::find($id);
        if ((int) $target['id'] === (int) $me['id']) {
            flash('info', '자기 비밀번호는 내 비밀번호 변경에서 바꿔 주세요.');
            redirect('/admin/account/password');
        }
        $temp = self::tempPassword();
        db_update('admins', ['password_hash' => password_hash($temp, PASSWORD_DEFAULT)], 'id = ?', [(int) $target['id']]);
        admin_audit('admin.password_reset', 'admin', (int) $target['id'], ['login_id' => $target['login_id']]);
        Session::set(self::TEMP_KEY, ['id' => (int) $target['id'], 'login_id' => $target['login_id'], 'name' => $target['name'], 'password' => $temp]);
        redirect('/admin/admins');
    }

    /** GET /admin/account/password */
    public function passwordForm(): string
    {
        $me = require_admin();

        return view('admin/admins/password', ['me' => $me]);
    }

    /** POST /admin/account/password */
    public function updatePassword(): void
    {
        $me = require_admin();
        $current = is_string(input('current_password')) ? (string) input('current_password') : '';
        $new = is_string(input('password')) ? (string) input('password') : '';
        $confirm = is_string(input('password_confirmation')) ? (string) input('password_confirmation') : '';
        $errors = [];
        if (!RateLimiter::hit('admin-password:' . (int) $me['id'], 10, 900)) {
            $errors['current_password'] = '시도가 너무 많습니다. 15분 뒤에 다시 시도해 주세요.';
        } elseif ($current === '' || !password_verify($current, (string) $me['password_hash'])) {
            $errors['current_password'] = '지금 비밀번호가 맞지 않습니다.';
        } else {
            $pwError = self::passwordError($new, $confirm);
            if ($pwError) {
                $errors[$pwError[0]] = $pwError[1];
            } elseif (hash_equals($current, $new)) {
                $errors['password'] = '지금 비밀번호와 다른 비밀번호로 정해 주세요.';
            }
        }
        if ($errors) {
            back_with_errors($errors, '/admin/account/password');
        }
        db_update('admins', ['password_hash' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [(int) $me['id']]);
        RateLimiter::clear('admin-password:' . (int) $me['id']);
        Session::regenerate();
        AdminAuth::syncPassword();
        admin_audit('admin.password_change', 'admin', (int) $me['id'], ['login_id' => $me['login_id']]);
        flash('success', '비밀번호를 바꿨습니다. 다음 로그인부터 새 비밀번호를 쓰세요.');
        redirect('/admin/account/password');
    }

    /**
     * 새 비밀번호 검사. 문제가 있으면 [항목, 메시지], 없으면 null
     * @return array|null
     */
    public static function passwordError(string $password, string $confirmation): ?array
    {
        $len = mb_strlen($password);
        if ($len === 0) {
            return ['password', '새 비밀번호를 입력해 주세요.'];
        }
        if ($len < self::PASSWORD_MIN) {
            return ['password', '비밀번호는 ' . self::PASSWORD_MIN . '자 이상으로 정해 주세요.'];
        }
        if ($len > self::PASSWORD_MAX) {
            return ['password', '비밀번호는 ' . self::PASSWORD_MAX . '자 이하로 정해 주세요.'];
        }
        if (!hash_equals($password, $confirmation)) {
            return ['password_confirmation', '비밀번호 확인이 일치하지 않습니다.'];
        }

        return null;
    }

    /** 헷갈리는 글자(0, O, l, 1 등)를 뺀 14자 임시 비밀번호 */
    public static function tempPassword(): string
    {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < 14; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }

    private static function find(string $id): array
    {
        $row = db_one('SELECT * FROM admins WHERE id = ?', [(int) $id]);
        if (!$row) {
            abort(404, '관리자 계정을 찾을 수 없습니다.');
        }

        return $row;
    }
}
