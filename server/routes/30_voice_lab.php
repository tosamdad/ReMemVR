<?php
/**
 * 목소리 연구실 경로(회원). 가족 목소리 만들기, 녹음과 업로드, 제출, 진행 상황.
 * 모든 경로는 컨트롤러에서 require_user() 로 로그인을 확인하고 본인 목소리만 다룬다.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\User\VoiceLabController;

$router->get('/voice-lab', [VoiceLabController::class, 'index']);
$router->get('/voice-lab/new', [VoiceLabController::class, 'create']);
$router->post('/voice-lab', [VoiceLabController::class, 'store']);
$router->get('/voice-lab/{id:\d+}', [VoiceLabController::class, 'show']);
$router->get('/voice-lab/{id:\d+}/record', [VoiceLabController::class, 'record']);
$router->post('/voice-lab/{id:\d+}/submit', [VoiceLabController::class, 'submit']);
$router->post('/voice-lab/{id:\d+}/rename', [VoiceLabController::class, 'rename']);
$router->post('/voice-lab/{id:\d+}/delete', [VoiceLabController::class, 'destroy']);

// 화면에서 fetch 로 부르는 JSON
$router->get('/api/voice-lab/status', [VoiceLabController::class, 'status']);
$router->post('/api/voice-lab/{id:\d+}/samples', [VoiceLabController::class, 'uploadSample']);
$router->post('/api/voice-lab/{id:\d+}/samples/{sampleId:\d+}/delete', [VoiceLabController::class, 'deleteSample']);
