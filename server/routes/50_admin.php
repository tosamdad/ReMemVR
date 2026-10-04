<?php
/**
 * 관리자 로그인, 최초 설정, 운영 대시보드, 목소리 생성 관리 경로.
 * 모든 화면은 컨트롤러에서 require_admin() 으로 관리자 로그인을 확인한다(로그인, 최초 설정 제외).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\VoiceController;

// 진입, 최초 설정, 로그인
$router->get('/admin', [AuthController::class, 'index']);
$router->get('/admin/setup', [AuthController::class, 'setupForm']);
$router->post('/admin/setup', [AuthController::class, 'setup']);
$router->get('/admin/login', [AuthController::class, 'loginForm']);
$router->post('/admin/login', [AuthController::class, 'login']);
$router->post('/admin/logout', [AuthController::class, 'logout']);

// 운영 대시보드
$router->get('/admin/dashboard', [DashboardController::class, 'index']);
$router->get('/admin/api/dashboard', [DashboardController::class, 'api']);

// 목소리 생성 관리
$router->post('/admin/voices/approve-all', [VoiceController::class, 'approveAll']);
$router->get('/admin/voices', [VoiceController::class, 'index']);
$router->get('/admin/voices/{id:\d+}', [VoiceController::class, 'show']);
$router->post('/admin/voices/{id:\d+}/clone', [VoiceController::class, 'clone']);
$router->post('/admin/voices/{id:\d+}/reject', [VoiceController::class, 'reject']);
$router->post('/admin/voices/{id:\d+}/batch', [VoiceController::class, 'batch']);
$router->post('/admin/voices/{id:\d+}/refresh', [VoiceController::class, 'refresh']);
$router->post('/admin/voices/{id:\d+}/params', [VoiceController::class, 'params']);
$router->post('/admin/voices/{id:\d+}/test', [VoiceController::class, 'test']);
$router->post('/admin/voices/{id:\d+}/memo', [VoiceController::class, 'memo']);
$router->get('/admin/api/voices/{id:\d+}/logs', [VoiceController::class, 'logs']);
$router->get('/admin/api/voices/{id:\d+}/audios', [VoiceController::class, 'audios']);
