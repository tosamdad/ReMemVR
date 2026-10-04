<?php
namespace App\Services;

use App\Core\HttpClient;

/**
 * 간편 로그인(카카오, 구글) OAuth 2.0 처리.
 *   authorizeUrl → (사용자 동의) → callback 에서 exchangeCode → fetchProfile → mapProfile → findOrCreateUser
 * 로컬 개발(providers_fake)에서 키가 없으면 외부로 나가지 않고 가짜 프로필로 흐름을 끝까지 시험한다.
 */
class SocialLogin
{
    const PROVIDERS = ['kakao', 'google'];

    const KAKAO_AUTHORIZE = 'https://kauth.kakao.com/oauth/authorize';
    const KAKAO_TOKEN = 'https://kauth.kakao.com/oauth/token';
    const KAKAO_PROFILE = 'https://kapi.kakao.com/v2/user/me';
    const GOOGLE_AUTHORIZE = 'https://accounts.google.com/o/oauth2/v2/auth';
    const GOOGLE_TOKEN = 'https://oauth2.googleapis.com/token';
    const GOOGLE_PROFILE = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** 카카오에서 이메일을 받지 못했을 때 쓰는 임시 주소 도메인 */
    const PLACEHOLDER_DOMAIN = 'users.rememvr.local';

    public static function supports(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    public static function label(string $provider): string
    {
        return $provider === 'kakao' ? '카카오' : ($provider === 'google' ? '구글' : $provider);
    }

    /** 버튼을 눌러 진행할 수 있는지(키가 있거나 개발용 가짜 모드) */
    public static function ready(string $provider): bool
    {
        return self::supports($provider) && provider_ready($provider);
    }

    /** 실제 키가 설정되어 있는지 */
    public static function configured(string $provider): bool
    {
        if ($provider === 'kakao') {
            return (string) config('kakao.rest_api_key', '') !== '';
        }
        if ($provider === 'google') {
            return (string) config('google.client_id', '') !== '' && (string) config('google.client_secret', '') !== '';
        }

        return false;
    }

    /** 개발용 가짜 흐름을 쓸지(키가 없고 providers_fake 일 때만) */
    public static function isFake(string $provider): bool
    {
        return (bool) config('providers_fake') && !self::configured($provider);
    }

    public static function redirectUri(string $provider): string
    {
        return absolute_url('/auth/' . $provider . '/callback');
    }

    public static function newState(): string
    {
        return bin2hex(random_bytes(20));
    }

    /** 동의 화면 주소. 가짜 모드면 바로 콜백으로 돌아온다. */
    public static function authorizeUrl(string $provider, string $state): string
    {
        if (self::isFake($provider)) {
            return absolute_url('/auth/' . $provider . '/callback', ['code' => 'fake-' . bin2hex(random_bytes(6)), 'state' => $state]);
        }
        $clientId = $provider === 'kakao' ? (string) config('kakao.rest_api_key', '') : (string) config('google.client_id', '');

        return self::buildAuthorizeUrl($provider, $clientId, self::redirectUri($provider), $state);
    }

    /** 순수 함수: 제공자별 동의 화면 주소를 만든다(테스트 대상). */
    public static function buildAuthorizeUrl(string $provider, string $clientId, string $redirectUri, string $state): string
    {
        if ($provider === 'kakao') {
            return self::KAKAO_AUTHORIZE . '?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'state' => $state,
            ], '', '&', PHP_QUERY_RFC3986);
        }
        if ($provider === 'google') {
            return self::GOOGLE_AUTHORIZE . '?' . http_build_query([
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account',
                'access_type' => 'online',
            ], '', '&', PHP_QUERY_RFC3986);
        }
        throw new \InvalidArgumentException('지원하지 않는 간편 로그인: ' . $provider);
    }

    /** 순수 함수: 토큰 교환 요청 본문(테스트 대상) */
    public static function tokenRequestForm(string $provider, string $code, string $redirectUri, array $creds): array
    {
        if ($provider === 'kakao') {
            $form = [
                'grant_type' => 'authorization_code',
                'client_id' => (string) (isset($creds['client_id']) ? $creds['client_id'] : ''),
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ];
            if (!empty($creds['client_secret'])) {
                $form['client_secret'] = (string) $creds['client_secret'];
            }

            return $form;
        }

        return [
            'grant_type' => 'authorization_code',
            'client_id' => (string) (isset($creds['client_id']) ? $creds['client_id'] : ''),
            'client_secret' => (string) (isset($creds['client_secret']) ? $creds['client_secret'] : ''),
            'redirect_uri' => $redirectUri,
            'code' => $code,
        ];
    }

    /** 인가 코드를 접근 토큰으로 바꾼다. 실패하면 RuntimeException(사용자에게 보여 줄 문구) */
    public static function exchangeCode(string $provider, string $code): string
    {
        if (self::isFake($provider)) {
            return 'fake-access-token';
        }
        if ($provider === 'kakao') {
            $url = self::KAKAO_TOKEN;
            $creds = ['client_id' => config('kakao.rest_api_key', ''), 'client_secret' => config('kakao.client_secret', '')];
        } else {
            $url = self::GOOGLE_TOKEN;
            $creds = ['client_id' => config('google.client_id', ''), 'client_secret' => config('google.client_secret', '')];
        }
        $res = HttpClient::request('POST', $url, [
            'form' => self::tokenRequestForm($provider, $code, self::redirectUri($provider), $creds),
            'headers' => ['Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        $data = HttpClient::json($res);
        if ($res['status'] !== 200 || empty($data['access_token'])) {
            // 응답 본문에는 토큰이 없을 때만 남는다. 키 값은 기록하지 않는다.
            app_log('warn', '간편 로그인 토큰 교환 실패', [
                'provider' => $provider,
                'status' => $res['status'],
                'error' => isset($data['error']) ? $data['error'] : $res['error'],
                'description' => isset($data['error_description']) ? str_limit((string) $data['error_description'], 200) : null,
            ]);
            throw new \RuntimeException(self::label($provider) . ' 로그인 확인에 실패했어요. 잠시 후 다시 시도해 주세요.');
        }

        return (string) $data['access_token'];
    }

    /** 사용자 정보 원본 응답 */
    public static function fetchProfile(string $provider, string $accessToken): array
    {
        if (self::isFake($provider)) {
            return self::fakeProfile($provider);
        }
        $url = $provider === 'kakao' ? self::KAKAO_PROFILE : self::GOOGLE_PROFILE;
        $res = HttpClient::request('GET', $url, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken, 'Accept' => 'application/json'],
            'timeout' => 15,
        ]);
        $data = HttpClient::json($res);
        if ($res['status'] !== 200 || !$data) {
            app_log('warn', '간편 로그인 사용자 정보 조회 실패', ['provider' => $provider, 'status' => $res['status'], 'error' => $res['error']]);
            throw new \RuntimeException(self::label($provider) . ' 계정 정보를 가져오지 못했어요. 잠시 후 다시 시도해 주세요.');
        }

        return $data;
    }

    /**
     * 순수 함수: 제공자 응답을 공통 형식으로 바꾼다(테스트 대상).
     * 반환: ['provider', 'id', 'email'(소문자 또는 null), 'email_verified'(bool), 'name'(문자열, 없으면 '')]
     */
    public static function mapProfile(string $provider, array $raw): array
    {
        $id = '';
        $email = null;
        $verified = false;
        $name = '';
        if ($provider === 'kakao') {
            $id = isset($raw['id']) ? (string) $raw['id'] : '';
            $acct = isset($raw['kakao_account']) && is_array($raw['kakao_account']) ? $raw['kakao_account'] : [];
            if (!empty($acct['email']) && is_string($acct['email'])) {
                $email = $acct['email'];
            }
            // 이메일이 인증되었고(is_email_verified) 유효할 때(is_email_valid, 없으면 유효로 본다)만 믿는다.
            $verified = self::truthy(isset($acct['is_email_verified']) ? $acct['is_email_verified'] : false)
                && (!array_key_exists('is_email_valid', $acct) || self::truthy($acct['is_email_valid']));
            if (isset($acct['profile']['nickname']) && is_string($acct['profile']['nickname'])) {
                $name = $acct['profile']['nickname'];
            } elseif (isset($raw['properties']['nickname']) && is_string($raw['properties']['nickname'])) {
                $name = $raw['properties']['nickname'];
            }
        } elseif ($provider === 'google') {
            $id = isset($raw['sub']) ? (string) $raw['sub'] : '';
            if (!empty($raw['email']) && is_string($raw['email'])) {
                $email = $raw['email'];
            }
            $verified = self::truthy(isset($raw['email_verified']) ? $raw['email_verified'] : false);
            if (!empty($raw['name']) && is_string($raw['name'])) {
                $name = $raw['name'];
            } elseif (!empty($raw['given_name']) && is_string($raw['given_name'])) {
                $name = $raw['given_name'];
            }
        } else {
            throw new \InvalidArgumentException('지원하지 않는 간편 로그인: ' . $provider);
        }
        if ($id === '') {
            throw new \RuntimeException(self::label($provider) . ' 계정 정보가 올바르지 않아요. 다시 시도해 주세요.');
        }
        $email = $email !== null ? mb_strtolower(trim($email)) : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = null;
            $verified = false;
        }
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name));

        return [
            'provider' => $provider,
            'id' => $id,
            'email' => $email,
            'email_verified' => $email !== null && $verified,
            'name' => $name,
        ];
    }

    /** 순수 함수: 이메일을 받지 못한 계정의 임시 주소 */
    public static function placeholderEmail(string $provider, string $id): string
    {
        $clean = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $id));

        return $provider . '_' . ($clean !== '' ? $clean : bin2hex(random_bytes(4))) . '@' . self::PLACEHOLDER_DOMAIN;
    }

    public static function isPlaceholderEmail(?string $email): bool
    {
        $email = (string) $email;
        $suffix = '@' . self::PLACEHOLDER_DOMAIN;

        return $email !== '' && substr($email, -strlen($suffix)) === $suffix;
    }

    /** 순수 함수: 새 계정 이름(닉네임이 없으면 '카카오 회원') */
    public static function displayName(array $profile): string
    {
        $name = isset($profile['name']) ? trim((string) $profile['name']) : '';
        if ($name === '') {
            $name = self::label((string) $profile['provider']) . ' 회원';
        }

        return mb_substr($name, 0, 30);
    }

    /**
     * 간편 로그인 결과로 회원을 찾거나 만든다.
     *   1) 이미 연결된 계정 → 그 회원
     *   2) 같은 이메일의 회원이 이미 있으면 → 원래 방법으로 로그인하라고 안내(자동 연결하지 않는다)
     *   3) 새 회원(비밀번호 없음, 간편 로그인 안내에 따라 약관 동의 시각 기록)
     * 반환: ['user' => 행, 'created' => bool, 'linked' => bool]
     */
    public static function findOrCreateUser(array $profile): array
    {
        $provider = (string) $profile['provider'];
        $pid = (string) $profile['id'];

        return db_tx(static function () use ($profile, $provider, $pid) {
            $link = db_one('SELECT * FROM user_social_accounts WHERE provider = ? AND provider_user_id = ?', [$provider, $pid]);
            if ($link) {
                $user = db_one('SELECT * FROM users WHERE id = ?', [(int) $link['user_id']]);
                if (!$user || $user['deleted_at'] !== null || $user['status'] === 'withdrawn') {
                    // 탈퇴한 회원에 남은 연결이면 정리하고 새로 가입시킨다.
                    db_exec('DELETE FROM user_social_accounts WHERE id = ?', [(int) $link['id']]);
                } else {
                    self::assertActive($user);
                    db_exec('UPDATE user_social_accounts SET last_login_at = NOW(), email = ? WHERE id = ?', [$profile['email'], (int) $link['id']]);

                    return ['user' => $user, 'created' => false, 'linked' => false];
                }
            }

            // 이메일 가입은 주소 소유를 확인하지 않으므로, 같은 이메일 계정에 자동으로 연결하면
            // 남의 주소로 먼저 가입해 둔 사람이 진짜 주인의 간편 로그인 계정을 가로챌 수 있다.
            if ($profile['email'] !== null && $profile['email_verified']
                && db_value('SELECT id FROM users WHERE email = ? AND deleted_at IS NULL', [$profile['email']])) {
                throw new \RuntimeException('이미 같은 이메일로 가입된 계정이 있어요. 처음 가입한 방법(이메일 또는 다른 간편 로그인)으로 로그인해 주세요.');
            }

            // 인증되지 않은 이메일은 남의 주소일 수 있어 계정 이메일로 쓰지 않는다.
            $email = ($profile['email'] !== null && $profile['email_verified']) ? $profile['email'] : self::placeholderEmail($provider, $pid);
            if (db_value('SELECT id FROM users WHERE email = ?', [$email])) {
                $email = self::placeholderEmail($provider, $pid . bin2hex(random_bytes(3)));
            }
            $now = now();
            $userId = db_insert('users', [
                'email' => $email,
                'password_hash' => null,
                'name' => self::displayName($profile),
                'signup_provider' => $provider,
                'terms_agreed_at' => $now,
                'privacy_agreed_at' => $now,
            ]);
            self::link($userId, $profile);

            return ['user' => db_one('SELECT * FROM users WHERE id = ?', [$userId]), 'created' => true, 'linked' => true];
        });
    }

    /** 회원의 연결된 간편 로그인 목록(provider => 행) */
    public static function linkedAccounts(int $userId): array
    {
        $out = [];
        foreach (db_all('SELECT * FROM user_social_accounts WHERE user_id = ? ORDER BY id', [$userId]) as $row) {
            $out[$row['provider']] = $row;
        }

        return $out;
    }

    private static function link(int $userId, array $profile): void
    {
        db_insert('user_social_accounts', [
            'user_id' => $userId,
            'provider' => $profile['provider'],
            'provider_user_id' => (string) $profile['id'],
            'email' => $profile['email'],
            'last_login_at' => now(),
        ]);
    }

    private static function assertActive(array $user): void
    {
        if ($user['status'] !== 'active' || $user['deleted_at'] !== null) {
            throw new \RuntimeException('이용이 제한된 계정이에요. 고객 센터로 문의해 주세요.');
        }
    }

    private static function truthy($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || (is_string($v) && strtolower($v) === 'true');
    }

    /** 개발용 가짜 응답(providers_fake 이고 키가 없을 때만) */
    private static function fakeProfile(string $provider): array
    {
        if ($provider === 'kakao') {
            return [
                'id' => 3900000001,
                'kakao_account' => [
                    'email' => 'kakao-demo@example.com',
                    'is_email_valid' => true,
                    'is_email_verified' => true,
                    'profile' => ['nickname' => '카카오 보호자'],
                ],
            ];
        }

        return ['sub' => '109000000000000000001', 'email' => 'google-demo@example.com', 'email_verified' => true, 'name' => '구글 보호자'];
    }
}
