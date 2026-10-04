<?php
/**
 * 백그라운드 작업 처리기 엔드포인트(cafe24 에는 cron 이 없다).
 * POST, 토큰 필수. Worker::kick() 이 자기 자신을 부르거나, 배포 워크플로, 외부 모니터링이 부른다.
 * 50초 동안 작업을 처리하고, 남은 작업이 있으면 다음 호출을 걸어 둔다.
 */
require __DIR__ . '/bootstrap.php';

ops_require_token();
// 부른 쪽이 바로 연결을 끊어도 끝까지 처리한다.
ignore_user_abort(true);
@set_time_limit(120);

try {
    $result = App\Services\Worker::run(50);
    if ($result['remaining'] > 0 && empty($result['locked'])) {
        App\Services\Worker::kick();
    }
    json_response(array_merge(['ok' => true], $result));
} catch (Throwable $e) {
    app_log('error', '작업 처리기 오류: ' . $e->getMessage(), ['file' => basename($e->getFile()) . ':' . $e->getLine()]);
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
