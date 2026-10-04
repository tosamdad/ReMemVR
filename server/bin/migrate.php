<?php
/**
 * 로컬과 CI 에서 쓰는 마이그레이션 명령.
 *   php server/bin/migrate.php          미적용 파일 적용
 *   php server/bin/migrate.php status   상태만 출력
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

$command = isset($argv[1]) ? $argv[1] : 'migrate';

try {
    $config = app_config();
    $migrator = new Migrator(Db::connect($config['db']), APP_ROOT . '/migrations');

    if ($command === 'migrate') {
        $result = $migrator->migrate();
        foreach ($result['applied'] as $file) {
            echo "applied  {$file}\n";
        }
        echo sprintf("완료: 새로 적용 %d개, 이미 적용 %d개\n", count($result['applied']), $result['skipped']);
    }

    $pending = 0;
    foreach ($migrator->status() as $row) {
        if ($command === 'status') {
            echo sprintf("%-9s %s\n", $row['state'], $row['file']);
        }
        if ($row['state'] !== 'applied') {
            $pending++;
        }
    }
    exit($pending === 0 ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(1);
}
