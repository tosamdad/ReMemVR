<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Services\SocialLogin;

/** 간편 로그인(카카오, 구글) 시작과 콜백 */
class SocialAuthController
{
    /** state 유효 시간(초) */
    const STATE_TTL = 600;

    public function redirect(string $provider): void
    {
        if (!SocialLogin::supports($provider)) {
            abort(404);
        }
        $next = safe_next((string) Request::query('next', ''), '');
        if (Auth::user()) {
            redirect(AuthController::afterLoginPath($next));
        }
        if (!SocialLogin::ready($provider)) {
            flash('info', '간편 로그인은 준비 중이에요. 이메일로 가입하거나 로그인해 주세요.');
            redirect('/login');
        }
        $state = SocialLogin::newState();
        Session::set('oauth_state', [
            'provider' => $provider,
            'state' => $state,
            'at' => time(),
            'next' => $next,
        ]);
        redirect(SocialLogin::authorizeUrl($provider, $state));
    }

    public function callback(string $provider): void
    {
        if (!SocialLogin::supports($provider)) {
            abort(404);
        }
        $saved = Session::get('oauth_state');
        Session::forget('oauth_state');
        $label = SocialLogin::label($provider);

        // 사용자가 동의 화면에서 취소한 경우
        $error = (string) Request::query('error', '');
        if ($error !== '') {
            flash('info', $label . ' 로그인을 취소했어요.');
            redirect('/login');
        }

        $state = (string) Request::query('state', '');
        $code = (string) Request::query('code', '');
        $valid = is_array($saved)
            && isset($saved['provider'], $saved['state'], $saved['at'])
            && $saved['provider'] === $provider
            && $state !== ''
            && hash_equals((string) $saved['state'], $state)
            && time() - (int) $saved['at'] <= self::STATE_TTL;
        if (!$valid || $code === '') {
            flash('error', '로그인 요청이 만료되었어요. 다시 시도해 주세요.');
            redirect('/login');
        }
        if (!RateLimiter::hit('social:' . client_ip(), 30, 600)) {
            flash('error', '로그인 시도가 너무 많아요. 잠시 후 다시 시도해 주세요.');
            redirect('/login');
        }

        try {
            $token = SocialLogin::exchangeCode($provider, $code);
            $profile = SocialLogin::mapProfile($provider, SocialLogin::fetchProfile($provider, $token));
            $result = SocialLogin::findOrCreateUser($profile);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/login');

            return;
        }

        $user = $result['user'];
        Auth::login($user);
        if ($result['created']) {
            flash('success', $label . ' 계정으로 가입했어요. 반가워요!');
        } elseif ($result['linked']) {
            flash('success', '같은 이메일의 계정에 ' . $label . ' 로그인을 연결했어요.');
        }
        $next = isset($saved['next']) ? (string) $saved['next'] : '';
        redirect(AuthController::afterLoginPath($next));
    }
}
