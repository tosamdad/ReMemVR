<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Mailer;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;

/** 비밀번호 찾기(재설정 메일)와 새 비밀번호 설정 */
class PasswordController
{
    /** 재설정 링크 유효 시간(초) */
    const TOKEN_TTL = 3600;

    public function showForgot(): string
    {
        return view('user/auth/forgot', [
            'sent' => (bool) Session::get('pw_forgot_sent', false) && Request::query('sent') === '1',
        ]);
    }

    public function sendForgot(): void
    {
        $email = AuthController::normalizeEmail(Request::str('email'));
        $emailError = AuthController::emailError($email);
        if ($emailError !== null) {
            back_with_errors(['email' => $emailError], '/password/forgot');
        }
        if (!RateLimiter::hit('pwreset:ip:' . client_ip(), 10, 3600) || !RateLimiter::hit('pwreset:email:' . $email, 3, 900)) {
            back_with_errors(['email' => '요청이 너무 많아요. 잠시 후 다시 시도해 주세요.'], '/password/forgot');
        }

        $user = db_one("SELECT id, name, email FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL", [$email]);
        if ($user) {
            $token = bin2hex(random_bytes(32));
            db_exec('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [(int) $user['id']]);
            // 만료 시각은 DB 시계 기준으로 둔다(확인할 때도 NOW() 로 비교한다).
            db_exec(
                'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
                [(int) $user['id'], hash('sha256', $token), self::TOKEN_TTL]
            );
            $link = absolute_url('/password/reset/' . $token);
            $brand = (string) setting('app.brand', '르멤버');
            $text = $user['name'] . "님, 안녕하세요.\n\n"
                . $brand . " 비밀번호 재설정을 요청하셨어요. 아래 링크를 눌러 새 비밀번호를 만들어 주세요.\n\n"
                . $link . "\n\n"
                . "이 링크는 1시간 동안 한 번만 쓸 수 있어요.\n"
                . "직접 요청하지 않으셨다면 이 메일은 무시하셔도 괜찮아요. 비밀번호는 바뀌지 않아요.\n";
            Mailer::send((string) $user['email'], '[' . $brand . '] 비밀번호 재설정 안내', $text);
            if (config('providers_fake')) {
                // 로컬 개발에서는 메일을 보내지 않으므로 링크를 로그로 남긴다(실서버 설정에는 이 값이 없다).
                app_log('info', '비밀번호 재설정 링크(개발 모드)', ['link' => $link]);
            }
        }
        // 가입 여부를 알 수 없도록 항상 같은 안내를 보여 준다.
        Session::set('pw_forgot_sent', true);
        redirect('/password/forgot?sent=1');
    }

    public function showReset(string $token): string
    {
        $row = $this->findToken($token);

        return view('user/auth/reset', [
            'token' => $token,
            'valid' => $row !== null,
        ]);
    }

    public function reset(string $token): void
    {
        $row = $this->findToken($token);
        if ($row === null) {
            flash('error', '링크가 만료되었거나 이미 사용되었어요. 다시 요청해 주세요.');
            redirect('/password/forgot');
        }
        $password = (string) input('password', '');
        $errors = [];
        $pwError = AuthController::passwordError($password);
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        } elseif (!hash_equals($password, (string) input('password_confirmation', ''))) {
            $errors['password_confirmation'] = '비밀번호 확인이 일치하지 않아요.';
        }
        if ($errors) {
            back_with_errors($errors, '/password/reset/' . $token);
        }

        $userId = (int) $row['user_id'];
        db_tx(static function () use ($userId, $password, $row) {
            db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $userId]);
            db_exec('UPDATE password_resets SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);
            db_exec('DELETE FROM password_resets WHERE user_id = ? AND used_at IS NULL', [$userId]);
        });
        RateLimiter::clear('pwreset:email:' . (string) $row['email']);
        Auth::login(['id' => $userId]);
        flash('success', '새 비밀번호로 바꿨어요. 이제 이 비밀번호로 로그인하면 돼요.');
        redirect(AuthController::afterLoginPath());
    }

    /** 유효한(만료 전, 미사용, 활성 회원) 토큰 행. 없으면 null */
    private function findToken(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        return db_one(
            "SELECT pr.id, pr.user_id, u.email FROM password_resets pr JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW() AND u.status = 'active' AND u.deleted_at IS NULL",
            [hash('sha256', $token)]
        );
    }
}
