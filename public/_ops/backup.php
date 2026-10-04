<?php
/**
 * 매일 밤 GitHub Actions 가 호출하는 DB 백업 엔드포인트.
 * POST, 토큰 필수. gzip 으로 압축한 SQL 덤프를 응답으로 흘려보내며 서버에는 파일을 남기지 않는다.
 */
require __DIR__ . '/bootstrap.php';

ops_require_token();
@set_time_limit(0);
@ini_set('zlib.output_compression', '0');

try {
    $config = app_config();
    $pdo = Db::connect($config['db'], false);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/gzip');
header('Content-Disposition: attachment; filename="rememvr-' . date('Ymd-His') . '.sql.gz"');
header('Cache-Control: no-store');

$deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
$write = function ($chunk) use ($deflate) {
    $out = deflate_add($deflate, $chunk, ZLIB_NO_FLUSH);
    if ($out !== '') {
        echo $out;
        flush();
    }
};

try {
    (new Backup($pdo, $write))->dump($config['db']['name']);
} catch (Throwable $e) {
    // 헤더는 이미 나갔으므로 덤프 안에 오류를 남긴다. 완료 표시가 없으므로 워크플로가 실패로 판단한다.
    $write("\n-- BACKUP FAILED: " . str_replace("\n", ' ', $e->getMessage()) . "\n");
}

echo deflate_add($deflate, '', ZLIB_FINISH);
