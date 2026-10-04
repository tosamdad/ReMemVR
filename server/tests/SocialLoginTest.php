<?php
use App\Controllers\User\AuthController;
use App\Controllers\User\LegalController;
use App\Controllers\User\SettingsController;
use App\Services\SocialLogin;

// 간편 로그인 주소 만들기, 응답 변환(네트워크 없음)

function social_query(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);

    return $q;
}

test('카카오 동의 화면 주소에 client_id, redirect_uri, state 가 들어간다', function () {
    $url = SocialLogin::buildAuthorizeUrl('kakao', 'REST_KEY', 'https://rememvr.kr/auth/kakao/callback', 'st4te');
    assert_same(0, strpos($url, 'https://kauth.kakao.com/oauth/authorize?'));
    $q = social_query($url);
    assert_same('REST_KEY', $q['client_id']);
    assert_same('https://rememvr.kr/auth/kakao/callback', $q['redirect_uri']);
    assert_same('code', $q['response_type']);
    assert_same('st4te', $q['state']);
    assert_true(!isset($q['scope']), '카카오는 scope 를 보내지 않는다(콘솔 동의 항목 사용)');
});

test('구글 동의 화면 주소는 openid email profile 범위와 계정 선택을 요청한다', function () {
    $url = SocialLogin::buildAuthorizeUrl('google', 'cid.apps.googleusercontent.com', 'https://rememvr.kr/auth/google/callback', 'abc');
    assert_same(0, strpos($url, 'https://accounts.google.com/o/oauth2/v2/auth?'));
    assert_contains('scope=openid%20email%20profile', $url, '공백은 %20 으로 인코딩');
    $q = social_query($url);
    assert_same('openid email profile', $q['scope']);
    assert_same('select_account', $q['prompt']);
    assert_same('code', $q['response_type']);
    assert_same('abc', $q['state']);
    assert_same('cid.apps.googleusercontent.com', $q['client_id']);
});

test('지원하지 않는 제공자는 예외', function () {
    $thrown = false;
    try {
        SocialLogin::buildAuthorizeUrl('naver', 'x', 'y', 'z');
    } catch (InvalidArgumentException $e) {
        $thrown = true;
    }
    assert_true($thrown);
});

test('토큰 요청 본문: 카카오는 client_secret 이 있을 때만 넣는다', function () {
    $f = SocialLogin::tokenRequestForm('kakao', 'CODE', 'https://x/cb', ['client_id' => 'K', 'client_secret' => '']);
    assert_same(['grant_type' => 'authorization_code', 'client_id' => 'K', 'redirect_uri' => 'https://x/cb', 'code' => 'CODE'], $f);
    $f2 = SocialLogin::tokenRequestForm('kakao', 'CODE', 'https://x/cb', ['client_id' => 'K', 'client_secret' => 'S']);
    assert_same('S', $f2['client_secret']);
    $g = SocialLogin::tokenRequestForm('google', 'C2', 'https://x/g', ['client_id' => 'G', 'client_secret' => 'GS']);
    assert_same('authorization_code', $g['grant_type']);
    assert_same('GS', $g['client_secret']);
    assert_same('https://x/g', $g['redirect_uri']);
});

test('카카오 프로필: 인증된 이메일과 닉네임을 꺼낸다', function () {
    $p = SocialLogin::mapProfile('kakao', [
        'id' => 1234567890,
        'kakao_account' => [
            'email' => 'Mom@Kakao.com',
            'is_email_valid' => true,
            'is_email_verified' => true,
            'profile' => ['nickname' => ' 하늘 엄마 '],
        ],
    ]);
    assert_same(['provider' => 'kakao', 'id' => '1234567890', 'email' => 'mom@kakao.com', 'email_verified' => true, 'name' => '하늘 엄마'], $p);
});

test('카카오 프로필: 이메일이 없거나 인증되지 않으면 email_verified false', function () {
    $noEmail = SocialLogin::mapProfile('kakao', ['id' => 77, 'properties' => ['nickname' => '아빠']]);
    assert_same(null, $noEmail['email']);
    assert_same(false, $noEmail['email_verified']);
    assert_same('아빠', $noEmail['name']);
    $unverified = SocialLogin::mapProfile('kakao', ['id' => 78, 'kakao_account' => ['email' => 'a@b.com', 'is_email_verified' => false]]);
    assert_same('a@b.com', $unverified['email']);
    assert_same(false, $unverified['email_verified']);
    $invalid = SocialLogin::mapProfile('kakao', ['id' => 79, 'kakao_account' => ['email' => 'a@b.com', 'is_email_verified' => true, 'is_email_valid' => false]]);
    assert_same(false, $invalid['email_verified']);
});

test('구글 프로필: sub, email_verified(문자열 true 포함), 이름', function () {
    $p = SocialLogin::mapProfile('google', ['sub' => '1098', 'email' => 'Dad@Gmail.com', 'email_verified' => 'true', 'name' => '김아빠']);
    assert_same(['provider' => 'google', 'id' => '1098', 'email' => 'dad@gmail.com', 'email_verified' => true, 'name' => '김아빠'], $p);
    $q = SocialLogin::mapProfile('google', ['sub' => '1099', 'email' => 'x@y.com', 'given_name' => '지은']);
    assert_same(false, $q['email_verified']);
    assert_same('지은', $q['name']);
});

test('식별자가 없는 응답이나 잘못된 이메일은 거른다', function () {
    $thrown = false;
    try {
        SocialLogin::mapProfile('google', ['email' => 'x@y.com']);
    } catch (RuntimeException $e) {
        $thrown = true;
    }
    assert_true($thrown, 'sub 가 없으면 예외');
    $bad = SocialLogin::mapProfile('google', ['sub' => '1', 'email' => 'not-an-email', 'email_verified' => true]);
    assert_same(null, $bad['email']);
    assert_same(false, $bad['email_verified']);
});

test('임시 이메일과 기본 이름', function () {
    assert_same('kakao_1234@users.rememvr.local', SocialLogin::placeholderEmail('kakao', '1234'));
    assert_true(SocialLogin::isPlaceholderEmail('kakao_1234@users.rememvr.local'));
    assert_true(!SocialLogin::isPlaceholderEmail('mom@example.com'));
    assert_same('카카오 회원', SocialLogin::displayName(['provider' => 'kakao', 'name' => '']));
    assert_same(30, mb_strlen(SocialLogin::displayName(['provider' => 'google', 'name' => str_repeat('가', 40)])));
});

test('비밀번호 규칙: 8자 이상, 영문과 숫자', function () {
    assert_same(null, AuthController::passwordError('abcd1234'));
    assert_true(AuthController::passwordError('abc123') !== null, '짧음');
    assert_true(AuthController::passwordError('abcdefgh') !== null, '숫자 없음');
    assert_true(AuthController::passwordError('12345678') !== null, '영문 없음');
    assert_true(AuthController::passwordError('') !== null, '빈 값');
});

test('환경 설정 값 정리: 허용한 키와 값만 남긴다', function () {
    $out = SettingsController::sanitizePrefs([
        'dark_mode' => true, 'notify_notice' => '0', 'hands_free' => 'on',
        'playback_speed' => '1.25', 'text_size' => 'xl', 'sleep_timer_min' => '20', 'is_admin' => true,
    ]);
    assert_same(['dark_mode' => true, 'hands_free' => true, 'notify_notice' => false, 'playback_speed' => 1.25, 'sleep_timer_min' => 20], $out);
    assert_same([], SettingsController::sanitizePrefs(['playback_speed' => 2, 'sleep_timer_min' => 15]));
});

test('약관 텍스트를 소제목과 문단으로 나눈다', function () {
    $blocks = LegalController::parse("머리말입니다.\n\n제1조 (목적)\n첫 줄\n둘째 줄\n\n1. 수집 항목\n이메일");
    assert_same(3, count($blocks));
    assert_same(null, $blocks[0]['heading']);
    assert_same('제1조 (목적)', $blocks[1]['heading']);
    assert_same(['첫 줄', '둘째 줄'], $blocks[1]['lines']);
    assert_same('1. 수집 항목', $blocks[2]['heading']);
});
