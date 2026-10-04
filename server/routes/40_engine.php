<?php
/**
 * 미디어(음성, 표지)와 작업 처리기 경로.
 * 회원 미디어는 본인 것만, 관리자 미디어는 관리자 로그인만 확인한다. 모두 Range 요청을 지원한다.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\WorkerController;
use App\Controllers\MediaController;

// 회원(세션 RMSESS)
$router->get('/media/sample/{id:\d+}', [MediaController::class, 'sample']);
$router->get('/media/story-audio/{id:\d+}', [MediaController::class, 'storyAudio']);
$router->get('/media/clip/{id:\d+}', [MediaController::class, 'clip']);
$router->get('/media/question/{id:\d+}', [MediaController::class, 'question']);
$router->get('/media/answer/{id:\d+}', [MediaController::class, 'answer']);
$router->get('/media/cover/{id:\d+}', [MediaController::class, 'cover']);

// 관리자(세션 RMADMIN)
$router->get('/admin/media/sample/{id:\d+}', [MediaController::class, 'adminSample']);
$router->get('/admin/media/story-audio/{id:\d+}', [MediaController::class, 'adminStoryAudio']);
$router->get('/admin/media/clip/{id:\d+}', [MediaController::class, 'adminClip']);
$router->get('/admin/media/question/{id:\d+}', [MediaController::class, 'adminQuestion']);
$router->get('/admin/media/answer/{id:\d+}', [MediaController::class, 'adminAnswer']);

// 작업 처리기 한 단계 실행(관리자 화면이 45초마다 호출)
$router->post('/admin/api/worker/tick', [WorkerController::class, 'tick']);
