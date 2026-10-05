<?php
/**
 * 회원 화면: 홈, 동화 목록과 상세(생성 요청), 내 동화(요청 목록), 플레이리스트, 플레이어(끼어들기 질문 포함), 학습 리포트.
 * 모두 로그인이 필요하고, 자녀가 없으면 /onboarding 으로 보낸다(API 는 제외).
 *
 * @var App\Core\Router $router
 */

use App\Controllers\User\HomeController;
use App\Controllers\User\LibraryController;
use App\Controllers\User\PlayerController;
use App\Controllers\User\PlaylistController;
use App\Controllers\User\ReportController;
use App\Controllers\User\StoryController;

$router->get('/home', [HomeController::class, 'index']);
$router->get('/stories', [StoryController::class, 'index']);
$router->get('/stories/{id:\d+}', [StoryController::class, 'show']);
$router->post('/stories/{id:\d+}/request', [StoryController::class, 'request']);

// 내 동화: 생성 요청 목록(만드는 중, 완성, 반려)과 완성 동화 듣기
$router->get('/library', [LibraryController::class, 'index']);
$router->post('/library/requests/{id:\d+}/cancel', [LibraryController::class, 'cancel']);

// 플레이리스트: 완성 동화를 담아 차례로, 반복으로, 랜덤으로 듣기
$router->get('/playlists', [PlaylistController::class, 'index']);
$router->post('/playlists', [PlaylistController::class, 'create']);
$router->post('/playlists/add', [PlaylistController::class, 'addFromLibrary']);
$router->get('/playlists/{id:\d+}', [PlaylistController::class, 'show']);
$router->get('/playlists/{id:\d+}/play', [PlaylistController::class, 'play']);
$router->post('/playlists/{id:\d+}/rename', [PlaylistController::class, 'rename']);
$router->post('/playlists/{id:\d+}/delete', [PlaylistController::class, 'delete']);
$router->post('/playlists/{id:\d+}/mode', [PlaylistController::class, 'mode']);
$router->post('/playlists/{id:\d+}/items', [PlaylistController::class, 'addItems']);
$router->post('/playlists/{id:\d+}/items/{item:\d+}/delete', [PlaylistController::class, 'removeItem']);
$router->post('/playlists/{id:\d+}/items/{item:\d+}/move', [PlaylistController::class, 'moveItem']);

$router->get('/player', [PlayerController::class, 'resume']);
$router->get('/player/{id:\d+}', [PlayerController::class, 'show']);
$router->get('/report', [ReportController::class, 'index']);

// 재생 기록 API(JSON). CSRF 는 RM.api 헤더 또는 FormData 의 _token(sendBeacon)으로 확인한다.
$router->post('/api/play-sessions', [PlayerController::class, 'start']);
$router->post('/api/play-sessions/{id:\d+}/progress', [PlayerController::class, 'progress']);
$router->post('/api/play-sessions/{id:\d+}/complete', [PlayerController::class, 'complete']);
$router->post('/api/play-sessions/{id:\d+}/question', [PlayerController::class, 'question']);
