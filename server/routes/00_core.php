<?php
/**
 * 경로 등록 파일. server/routes/ 아래 파일을 이름 순서로 모두 읽는다.
 * 파일마다 담당 영역이 다르다: 10_auth(로그인, 설정), 20_user(홈, 동화, 플레이어, 리포트), 30_voice_lab(목소리 연구실),
 * 40_engine(미디어, 작업 처리기), 50_admin(관리자 로그인, 대시보드, 목소리 관리), 60_admin_content(동화, 회원, 운영 메뉴)
 *
 * @var App\Core\Router $router
 */

// 앱 설치(홈 화면에 추가)용 매니페스트
$router->get('/manifest.webmanifest', static function () {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    echo json_encode_u([
        'name' => setting('app.brand', '르멤버'),
        'short_name' => setting('app.brand', '르멤버'),
        'description' => '좋아하는 사람의 목소리로 듣는 동화',
        'lang' => 'ko',
        'start_url' => url('/home'),
        'scope' => url('/'),
        'display' => 'standalone',
        'background_color' => '#f3faff',
        'theme_color' => '#f3faff',
        'icons' => [
            ['src' => asset('img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png'],
            ['src' => asset('img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png'],
            ['src' => asset('img/icon.svg'), 'sizes' => 'any', 'type' => 'image/svg+xml'],
        ],
    ]);
});

// 서버 상태 확인(외부 모니터링용, 민감 정보 없음)
$router->get('/healthz', static function () {
    $db = true;
    try {
        db_value('SELECT 1');
    } catch (Throwable $e) {
        $db = false;
    }

    return ['ok' => $db, 'time' => date('c')];
});
