<?php
/**
 * 배포 직후 GitHub Actions 가 호출하는 마이그레이션 엔드포인트.
 * POST, 토큰 필수. action=status 면 상태만, 그 외에는 미적용 파일을 적용한다.
 */
require __DIR__ . '/bootstrap.php';

ops_require_token();
@set_time_limit(300);

try {
    $config = app_config();
    $migrator = new Migrator(Db::connect($config['db']), APP_ROOT . '/migrations');
    $action = isset($_POST['action']) ? $_POST['action'] : 'migrate';

    $result = $action === 'status' ? null : $migrator->migrate();

    json_response([
        'ok' => true,
        'action' => $action === 'status' ? 'status' : 'migrate',
        'applied' => $result ? $result['applied'] : [],
        'status' => $migrator->status(),
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
