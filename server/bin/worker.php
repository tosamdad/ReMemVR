<?php
/**
 * 백그라운드 작업 처리기를 명령행에서 돌린다(로컬 개발, 점검용).
 *   php server/bin/worker.php            20초 동안 처리
 *   php server/bin/worker.php 60         60초 동안 처리
 *   php server/bin/worker.php --drain    대기 작업이 없을 때까지 반복(재시도 대기 중인 작업은 기다리지 않는다)
 *   php server/bin/worker.php --stats    큐 현황만 출력
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\Jobs;
use App\Services\Worker;

$args = array_slice($argv, 1);
$drain = in_array('--drain', $args, true);
$statsOnly = in_array('--stats', $args, true);
$seconds = 20;
foreach ($args as $a) {
    if (ctype_digit($a)) {
        $seconds = max(1, (int) $a);
    }
}

try {
    if (!$statsOnly) {
        $total = 0;
        $rounds = 0;
        do {
            $r = Worker::run($drain ? max($seconds, 50) : $seconds);
            if (!empty($r['locked'])) {
                echo "다른 작업 처리기가 실행 중이라 건너뛰었다.\n";
                break;
            }
            $total += $r['processed'];
            $rounds++;
            echo sprintf("처리 %d개, 남은 대기 작업 %d개 (%.1f초)\n", $r['processed'], $r['remaining'], $r['elapsed_ms'] / 1000);
        } while ($drain && $r['remaining'] > 0 && $r['processed'] > 0 && $rounds < 1000);
        if ($drain) {
            echo "합계 {$total}개 처리\n";
        }
    }
    $s = Jobs::stats();
    echo sprintf("대기 %d, 실행 중 %d, 오늘 완료 %d, 24시간 실패 %d\n", $s['pending'], $s['running'], $s['done_today'], $s['failed_24h']);
    foreach ($s['by_type'] as $type => $row) {
        if (array_sum($row) > 0) {
            echo sprintf("  %-13s 대기 %d, 실행 중 %d, 오늘 완료 %d, 24시간 실패 %d\n", $type, $row['pending'], $row['running'], $row['done_today'], $row['failed_24h']);
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(1);
}
