<?php
/**
 * 배포 직후 GitHub Actions 가 호출하는 마이그레이션 엔드포인트.
 * POST, 토큰 필수. action=status 면 상태만, 그 외에는 미적용 파일을 적용한다.
 * 응답의 env 에는 서버 환경 점검 결과(PHP 버전, 확장, 저장 폴더, 업로드 한도)를 담는다.
 */
require __DIR__ . '/bootstrap.php';

ops_require_token();

/** 서버 환경 점검(비밀 값은 담지 않는다) */
function ops_env_report(): array
{
    $extensions = [];
    foreach (['curl', 'mbstring', 'openssl', 'fileinfo', 'gd', 'zlib', 'pdo_mysql'] as $ext) {
        $extensions[$ext] = extension_loaded($ext);
    }
    $storage = null;
    $writable = false;
    try {
        $storage = storage_path();
        $writable = is_dir($storage) && is_writable($storage);
    } catch (Throwable $e) {
        $storage = null;
    }

    // 배포 워크플로(_deploy.yml)가 env.php, env.storage.writable, env.upload_max_filesize 를 읽는다.
    return [
        'php' => PHP_VERSION,
        'extensions' => $extensions,
        'storage' => ['path' => $storage, 'writable' => $writable],
        'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
        'post_max_size' => (string) ini_get('post_max_size'),
        'max_execution_time' => (int) ini_get('max_execution_time'),
        'memory_limit' => (string) ini_get('memory_limit'),
    ];
}

// 실행 시간 한도를 늘리기 전에 서버 본래 값을 읽는다.
$env = ops_env_report();
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
        'env' => $env,
    ]);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage(), 'env' => $env], 500);
}
