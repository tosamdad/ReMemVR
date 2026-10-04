<?php
namespace App\Core;

/** 회원(보호자) 로그인 상태. 회원 영역 세션(RMSESS)에 user_id 를 둔다. */
class Auth
{
    const PREF_DEFAULTS = [
        'dark_mode' => false,
        'playback_speed' => 1.0,      // 0.75 | 1.0 | 1.25
        'autoplay_next' => false,     // 동화가 끝나면 다음 동화 이어 듣기
        'highlight' => true,          // 읽는 문장 강조
        'text_size' => 'md',          // sm | md | lg
        'sleep_timer_min' => 0,       // 0 이면 끔
        'hands_free' => false,        // 말하면 자동으로 끼어들기(실험 기능)
        'notify_voice_ready' => true, // 목소리 준비 완료 메일
        'notify_notice' => true,      // 공지 알림
    ];

    /** @var array|null|false */
    private static $user = false;

    public static function id(): ?int
    {
        $id = Session::get('uid');

        return $id ? (int) $id : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function user(): ?array
    {
        if (self::$user === false) {
            self::$user = null;
            $id = self::id();
            if ($id) {
                $row = db_one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
                // 비밀번호가 바뀌면(재설정, 변경) 다른 기기에 남은 로그인은 끊는다.
                if ($row && $row['status'] === 'active'
                    && hash_equals((string) Session::get('pwv', ''), self::passwordVersion($row['password_hash']))) {
                    self::$user = $row;
                } else {
                    Session::forget('uid');
                }
            }
        }

        return self::$user;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('uid', (int) $user['id']);
        Session::set('pwv', self::passwordVersion(db_value('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']])));
        Session::forget('child_id');
        db_exec('UPDATE users SET last_login_at = NOW() WHERE id = ?', [(int) $user['id']]);
        self::$user = false;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = false;
    }

    /** 이 기기에서 비밀번호를 바꾼 뒤 현재 로그인은 유지한다(다른 기기의 로그인은 끊긴다). */
    public static function syncPassword(): void
    {
        $id = self::id();
        if ($id) {
            Session::set('pwv', self::passwordVersion(db_value('SELECT password_hash FROM users WHERE id = ?', [$id])));
        }
        self::$user = false;
    }

    /** 세션에 두는 비밀번호 지문(해시 값 자체는 세션에 넣지 않는다) */
    public static function passwordVersion($hash): string
    {
        return substr(hash('sha256', 'pwv|' . (string) $hash), 0, 16);
    }

    /** 다시 읽어야 할 때(정보 수정 직후) */
    public static function refresh(): void
    {
        self::$user = false;
    }

    /** 회원의 자녀 목록 */
    public static function children(): array
    {
        $id = self::id();

        return $id ? db_all('SELECT * FROM children WHERE user_id = ? ORDER BY sort_order, id', [$id]) : [];
    }

    /** 지금 선택된 자녀(없으면 첫째). 자녀가 없으면 null */
    public static function child(): ?array
    {
        $children = self::children();
        if (!$children) {
            return null;
        }
        $selected = (int) Session::get('child_id', 0);
        foreach ($children as $c) {
            if ((int) $c['id'] === $selected) {
                return $c;
            }
        }

        return $children[0];
    }

    public static function selectChild(int $childId): void
    {
        Session::set('child_id', $childId);
    }

    /** 회원 환경 설정(users.prefs JSON + 기본값) */
    public static function prefs(): array
    {
        $user = self::user();
        $saved = $user ? json_decode_array($user['prefs']) : [];

        return array_merge(self::PREF_DEFAULTS, array_intersect_key($saved, self::PREF_DEFAULTS));
    }

    public static function savePrefs(array $changes): array
    {
        $user = self::user();
        if (!$user) {
            return self::PREF_DEFAULTS;
        }
        $prefs = array_merge(self::prefs(), array_intersect_key($changes, self::PREF_DEFAULTS));
        db_exec('UPDATE users SET prefs = ? WHERE id = ?', [json_encode_u($prefs), (int) $user['id']]);
        self::$user = false;

        return $prefs;
    }
}
