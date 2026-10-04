<?php
/**
 * 방문자 소개, 로그인, 회원가입, 간편 로그인, 비밀번호 재설정, 첫 자녀 등록, 설정과 고객 지원 경로.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\User\AuthController;
use App\Controllers\User\ChildrenController;
use App\Controllers\User\LandingController;
use App\Controllers\User\LegalController;
use App\Controllers\User\OnboardingController;
use App\Controllers\User\PasswordController;
use App\Controllers\User\SettingsController;
use App\Controllers\User\SocialAuthController;
use App\Controllers\User\SupportController;

// 방문자 소개(로그인 상태면 /home 또는 /onboarding)
$router->get('/', [LandingController::class, 'index']);

// 이메일 로그인, 회원가입
$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/signup', [AuthController::class, 'showSignup']);
$router->post('/signup', [AuthController::class, 'signup']);
$router->post('/logout', [AuthController::class, 'logout']);

// 간편 로그인(카카오, 구글)
$router->get('/auth/{provider:kakao|google}', [SocialAuthController::class, 'redirect']);
$router->get('/auth/{provider:kakao|google}/callback', [SocialAuthController::class, 'callback']);

// 비밀번호 재설정
$router->get('/password/forgot', [PasswordController::class, 'showForgot']);
$router->post('/password/forgot', [PasswordController::class, 'sendForgot']);
$router->get('/password/reset/{token:[A-Za-z0-9]+}', [PasswordController::class, 'showReset']);
$router->post('/password/reset/{token:[A-Za-z0-9]+}', [PasswordController::class, 'reset']);

// 첫 자녀 등록
$router->get('/onboarding', [OnboardingController::class, 'show']);
$router->post('/onboarding', [OnboardingController::class, 'store']);

// 설정
$router->get('/settings', [SettingsController::class, 'index']);
$router->get('/settings/profile', [SettingsController::class, 'profile']);
$router->post('/settings/profile', [SettingsController::class, 'updateProfile']);
$router->get('/settings/password', [SettingsController::class, 'password']);
$router->post('/settings/password', [SettingsController::class, 'updatePassword']);
$router->get('/settings/playback', [SettingsController::class, 'playback']);
$router->post('/settings/playback', [SettingsController::class, 'updatePlayback']);
$router->get('/settings/withdraw', [SettingsController::class, 'withdraw']);
$router->post('/settings/withdraw', [SettingsController::class, 'destroy']);
$router->post('/api/settings/prefs', [SettingsController::class, 'savePrefs']);

// 아이 프로필
$router->get('/settings/children', [ChildrenController::class, 'index']);
$router->get('/settings/children/new', [ChildrenController::class, 'create']);
$router->post('/settings/children', [ChildrenController::class, 'store']);
$router->get('/settings/children/{id:\d+}/edit', [ChildrenController::class, 'edit']);
$router->post('/settings/children/{id:\d+}', [ChildrenController::class, 'update']);
$router->post('/settings/children/{id:\d+}/delete', [ChildrenController::class, 'destroy']);
$router->post('/settings/children/{id:\d+}/select', [ChildrenController::class, 'select']);

// 공지사항, 고객 센터, 1:1 문의
$router->get('/settings/notices', [SupportController::class, 'notices']);
$router->get('/settings/notices/{id:\d+}', [SupportController::class, 'notice']);
$router->get('/settings/support', [SupportController::class, 'index']);
$router->post('/settings/support/inquiries', [SupportController::class, 'storeInquiry']);
$router->get('/settings/support/inquiries/{id:\d+}', [SupportController::class, 'inquiry']);

// 약관(로그인 없이도 볼 수 있다)
$router->get('/settings/terms', [LegalController::class, 'terms']);
$router->get('/settings/privacy', [LegalController::class, 'privacy']);
