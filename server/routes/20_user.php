<?php
/**
 * 회원 화면: 홈, 동화 목록, 플레이어(끼어들기 질문 포함), 학습 리포트.
 * 모두 로그인이 필요하고, 자녀가 없으면 /onboarding 으로 보낸다(API 는 제외).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\User\HomeController;
use App\Controllers\User\PlayerController;
use App\Controllers\User\ReportController;
use App\Controllers\User\StoryController;

$router->get('/home', [HomeController::class, 'index']);
$router->get('/stories', [StoryController::class, 'index']);
$router->get('/player', [PlayerController::class, 'resume']);
$router->get('/player/{id:\d+}', [PlayerController::class, 'show']);
$router->get('/report', [ReportController::class, 'index']);

// 재생 기록 API(JSON). CSRF 는 RM.api 헤더 또는 FormData 의 _token(sendBeacon)으로 확인한다.
$router->post('/api/play-sessions', [PlayerController::class, 'start']);
$router->post('/api/play-sessions/{id:\d+}/progress', [PlayerController::class, 'progress']);
$router->post('/api/play-sessions/{id:\d+}/complete', [PlayerController::class, 'complete']);
$router->post('/api/play-sessions/{id:\d+}/question', [PlayerController::class, 'question']);
