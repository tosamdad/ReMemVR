<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Session;
use App\Core\Storage;
use App\Services\SocialLogin;
use App\Services\VoiceService;

/** 설정 메인, 프로필, 비밀번호, 재생 설정, 환경 설정 API, 회원 탈퇴 */
class SettingsController
{
    /** 탈퇴 확인 문구(비밀번호가 없는 간편 로그인 회원) */
    const WITHDRAW_PHRASE = '탈퇴합니다';

    const SPEEDS = [0.75, 1.0, 1.25];
    const TEXT_SIZES = ['sm', 'md', 'lg'];
    const SLEEP_TIMERS = [0, 10, 20, 30];

    public function index(): string
    {
        $user = require_user();
        $latestNotice = db_value("SELECT MAX(COALESCE(published_at, created_at)) FROM notices WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW())");
        $openInquiries = (int) db_value("SELECT COUNT(*) FROM inquiries WHERE user_id = ? AND status = 'answered' AND answered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)", [(int) $user['id']]);

        return view('user/settings/index', [
            'user' => $user,
            'child' => Auth::child(),
            'childCount' => count(Auth::children()),
            'prefs' => Auth::prefs(),
            'hasPassword' => $this->hasPassword($user),
            'newNotice' => $latestNotice !== null && strtotime((string) $latestNotice) >= time() - 7 * 86400,
            'newAnswers' => $openInquiries,
            'version' => (string) setting('app.version', '1.0.0'),
        ]);
    }

    // ───────────────────────── 프로필 ─────────────────────────

    public function profile(): string
    {
        $user = require_user();

        return view('user/settings/profile', [
            'user' => $user,
            'hasPassword' => $this->hasPassword($user),
            'placeholderEmail' => SocialLogin::isPlaceholderEmail($user['email']),
            'linked' => SocialLogin::linkedAccounts((int) $user['id']),
        ]);
    }

    public function updateProfile(): void
    {
        $user = require_user();
        $name = Request::str('name');
        $email = AuthController::normalizeEmail(Request::str('email'));
        $phone = Request::str('phone');
        $errors = [];

        if ($name === '') {
            $errors['name'] = '이름을 입력해 주세요.';
        } elseif (mb_strlen($name) > 30) {
            $errors['name'] = '이름은 30자 이하로 입력해 주세요.';
        }
        if ($email === '') {
            $errors['email'] = '이메일을 입력해 주세요.';
        } elseif (strlen($email) > 191 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = '올바른 이메일 주소를 입력해 주세요.';
        } elseif ($email !== $user['email'] && SocialLogin::isPlaceholderEmail($email)) {
            $errors['email'] = '사용할 수 없는 이메일 주소예요.';
        } elseif ($email !== $user['email'] && db_value('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, (int) $user['id']])) {
            $errors['email'] = '이미 다른 계정에서 쓰고 있는 이메일이에요.';
        }
        $digits = preg_replace('/\D/', '', $phone);
        if ($phone !== '' && (strlen($digits) < 9 || strlen($digits) > 11 || !preg_match('/^[0-9\-\s+()]+$/', $phone))) {
            $errors['phone'] = '휴대전화 번호를 숫자로 정확히 입력해 주세요.';
        }
        // 이메일을 바꿀 때는 계정 보호를 위해 현재 비밀번호를 한 번 더 확인한다.
        if (!isset($errors['email']) && $email !== $user['email'] && $this->hasPassword($user)) {
            if (!password_verify((string) input('current_password', ''), (string) $user['password_hash'])) {
                $errors['current_password'] = '이메일을 바꾸려면 현재 비밀번호를 정확히 입력해 주세요.';
            }
        }
        if ($errors) {
            back_with_errors($errors, '/settings/profile');
        }

        try {
            db_update('users', [
                'name' => $name,
                'email' => $email,
                'phone' => $phone === '' ? null : $this->formatPhone($digits),
            ], 'id = ?', [(int) $user['id']]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '23000') {
                back_with_errors(['email' => '이미 다른 계정에서 쓰고 있는 이메일이에요.'], '/settings/profile');
            }
            throw $e;
        }
        Auth::refresh();
        flash('success', '프로필을 저장했어요.');
        redirect('/settings');
    }

    // ───────────────────────── 비밀번호 ─────────────────────────

    public function password(): string
    {
        $user = require_user();

        return view('user/settings/password', [
            'user' => $user,
            'hasPassword' => $this->hasPassword($user),
        ]);
    }

    public function updatePassword(): void
    {
        $user = require_user();
        $hasPassword = $this->hasPassword($user);
        $password = (string) input('password', '');
        $errors = [];
        if ($hasPassword && !password_verify((string) input('current_password', ''), (string) $user['password_hash'])) {
            $errors['current_password'] = '현재 비밀번호가 맞지 않아요.';
        }
        $pwError = AuthController::passwordError($password);
        if ($pwError !== null) {
            $errors['password'] = $pwError;
        } elseif (!hash_equals($password, (string) input('password_confirmation', ''))) {
            $errors['password_confirmation'] = '새 비밀번호 확인이 일치하지 않아요.';
        } elseif ($hasPassword && password_verify($password, (string) $user['password_hash'])) {
            $errors['password'] = '지금 쓰는 비밀번호와 다른 비밀번호로 만들어 주세요.';
        }
        if ($errors) {
            back_with_errors($errors, '/settings/password');
        }
        db_exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
        db_exec('DELETE FROM password_resets WHERE user_id = ?', [(int) $user['id']]);
        Session::regenerate();
        Auth::refresh();
        flash('success', $hasPassword ? '비밀번호를 바꿨어요.' : '비밀번호를 만들었어요. 이제 이메일로도 로그인할 수 있어요.');
        redirect('/settings');
    }

    // ───────────────────────── 재생 설정 ─────────────────────────

    public function playback(): string
    {
        require_user();

        return view('user/settings/playback', [
            'prefs' => Auth::prefs(),
            'speeds' => self::SPEEDS,
            'timers' => self::SLEEP_TIMERS,
        ]);
    }

    public function updatePlayback(): void
    {
        require_user();
        $changes = self::sanitizePrefs([
            'playback_speed' => input('playback_speed'),
            'text_size' => input('text_size'),
            'sleep_timer_min' => input('sleep_timer_min'),
            // 체크박스는 꺼져 있으면 값이 오지 않으므로 숨은 필드 0 을 함께 보낸다.
            'autoplay_next' => input('autoplay_next', '0'),
            'highlight' => input('highlight', '0'),
            'hands_free' => input('hands_free', '0'),
        ]);
        Auth::savePrefs($changes);
        flash('success', '재생 설정을 저장했어요.');
        redirect('/settings/playback');
    }

    // ───────────────────────── 환경 설정 API ─────────────────────────

    /** POST /api/settings/prefs {dark_mode: true, notify_voice_ready: false, ...} → {ok, prefs} */
    public function savePrefs(): array
    {
        require_user();
        $input = Request::json();
        if (!$input) {
            $input = $_POST;
        }
        unset($input['_token']);
        $changes = self::sanitizePrefs($input);
        if (!$changes) {
            http_response_code(422);

            return ['ok' => false, 'error' => '바꿀 설정이 없어요.'];
        }
        $prefs = Auth::savePrefs($changes);

        return ['ok' => true, 'prefs' => $prefs];
    }

    /** 허용한 항목만 올바른 형식으로 바꾼다(알 수 없는 키, 잘못된 값은 버린다). */
    public static function sanitizePrefs(array $input): array
    {
        $out = [];
        foreach (['dark_mode', 'autoplay_next', 'highlight', 'hands_free', 'notify_voice_ready', 'notify_notice'] as $k) {
            if (array_key_exists($k, $input) && $input[$k] !== null) {
                $v = $input[$k];
                $out[$k] = $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
            }
        }
        if (isset($input['playback_speed']) && is_numeric($input['playback_speed'])) {
            foreach (self::SPEEDS as $s) {
                if (abs((float) $input['playback_speed'] - $s) < 0.001) {
                    $out['playback_speed'] = $s;
                }
            }
        }
        if (isset($input['text_size']) && in_array($input['text_size'], self::TEXT_SIZES, true)) {
            $out['text_size'] = $input['text_size'];
        }
        if (isset($input['sleep_timer_min']) && is_numeric($input['sleep_timer_min']) && in_array((int) $input['sleep_timer_min'], self::SLEEP_TIMERS, true)) {
            $out['sleep_timer_min'] = (int) $input['sleep_timer_min'];
        }

        return $out;
    }

    // ───────────────────────── 회원 탈퇴 ─────────────────────────

    public function withdraw(): string
    {
        $user = require_user();
        $uid = (int) $user['id'];

        return view('user/settings/withdraw', [
            'user' => $user,
            'hasPassword' => $this->hasPassword($user),
            'phrase' => self::WITHDRAW_PHRASE,
            'counts' => [
                'children' => count(Auth::children()),
                'voices' => (int) db_value('SELECT COUNT(*) FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL', [$uid]),
                'plays' => (int) db_value('SELECT COUNT(*) FROM play_sessions WHERE user_id = ?', [$uid]),
            ],
        ]);
    }

    public function destroy(): void
    {
        $user = require_user();
        $uid = (int) $user['id'];
        $errors = [];
        if ($this->hasPassword($user)) {
            if (!password_verify((string) input('password', ''), (string) $user['password_hash'])) {
                $errors['password'] = '비밀번호가 맞지 않아요.';
            }
        } elseif (Request::str('confirm_phrase') !== self::WITHDRAW_PHRASE) {
            $errors['confirm_phrase'] = '“' . self::WITHDRAW_PHRASE . '”를 정확히 입력해 주세요.';
        }
        if (!input('agree')) {
            $errors['agree'] = '안내 사항을 확인하고 체크해 주세요.';
        }
        if ($errors) {
            back_with_errors($errors, '/settings/withdraw');
        }

        // 1) 질문, 답변 음성 파일(아이 목소리 포함)은 바로 지운다.
        $audio = db_all(
            'SELECT i.id, i.question_audio_path, i.answer_audio_path FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id WHERE ps.user_id = ?',
            [$uid]
        );
        foreach ($audio as $row) {
            foreach (['question_audio_path', 'answer_audio_path'] as $col) {
                if (!empty($row[$col])) {
                    try {
                        Storage::delete((string) $row[$col]);
                    } catch (\Throwable $e) {
                        app_log('error', '탈퇴 음성 파일 삭제 실패', ['interaction' => (int) $row['id'], 'error' => $e->getMessage()]);
                    }
                }
            }
        }

        // 2) 회원 정보는 알아볼 수 없게 바꾸고, 자녀, 간편 로그인 연결, 재설정 토큰은 지운다.
        db_tx(static function () use ($uid) {
            db_exec(
                'UPDATE interactions i JOIN play_sessions ps ON ps.id = i.play_session_id SET i.question_audio_path = NULL, i.answer_audio_path = NULL WHERE ps.user_id = ?',
                [$uid]
            );
            db_update('users', [
                'status' => 'withdrawn',
                'deleted_at' => now(),
                'email' => 'withdrawn_' . $uid . '_' . time() . '@deleted.local',
                'name' => '탈퇴 회원',
                'phone' => null,
                'password_hash' => null,
                'prefs' => null,
                'marketing_agreed_at' => null,
            ], 'id = ?', [$uid]);
            db_exec('DELETE FROM user_social_accounts WHERE user_id = ?', [$uid]);
            db_exec('DELETE FROM password_resets WHERE user_id = ?', [$uid]);
            db_exec('DELETE FROM children WHERE user_id = ?', [$uid]);
        });

        // 3) 가족 목소리: 소프트 삭제 + 녹음 파일, 생성 오디오, ElevenLabs 목소리 삭제 작업
        foreach (db_all('SELECT id FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL', [$uid]) as $vp) {
            try {
                VoiceService::delete((int) $vp['id']);
            } catch (\Throwable $e) {
                app_log('error', '탈퇴 목소리 삭제 실패', ['voice_profile' => (int) $vp['id'], 'error' => $e->getMessage()]);
            }
        }

        Auth::logout();
        AuthController::restartSessionWithFlash('info', '탈퇴가 완료됐어요. 그동안 함께해 주셔서 고마웠어요.');
        redirect('/');
    }

    // ───────────────────────── 내부 ─────────────────────────

    private function hasPassword(array $user): bool
    {
        return isset($user['password_hash']) && $user['password_hash'] !== null && $user['password_hash'] !== '';
    }

    private function formatPhone(string $digits): string
    {
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3) . '-' . substr($digits, 3, 4) . '-' . substr($digits, 7);
        }
        if (strlen($digits) === 10) {
            $head = strpos($digits, '02') === 0 ? 2 : 3;

            return substr($digits, 0, $head) . '-' . substr($digits, $head, 10 - $head - 4) . '-' . substr($digits, -4);
        }

        return $digits;
    }
}
