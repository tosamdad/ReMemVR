<?php
/**
 * 로컬과 CI 에서 쓰는 백업 명령. gzip 으로 압축한 SQL 덤프를 만든다.
 *   php server/bin/backup.php backup.sql.gz
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

if (!isset($argv[1])) {
    fwrite(STDERR, "사용법: php server/bin/backup.php <출력파일.sql.gz>\n");
    exit(1);
}

try {
    $config = app_config();
    $gz = gzopen($argv[1], 'wb6');
    if ($gz === false) {
        throw new RuntimeException($argv[1] . ' 파일을 만들 수 없다.');
    }
    $backup = new Backup(Db::connect($config['db'], false), function ($chunk) use ($gz) {
        gzwrite($gz, $chunk);
    });
    $backup->dump($config['db']['name']);
    gzclose($gz);
    echo "완료: {$argv[1]}\n";
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(1);
}
