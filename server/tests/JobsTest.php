<?php
use App\Services\Jobs;

/**
 * 작업 큐 테스트. 모든 변경은 트랜잭션 안에서 하고 끝나면 되돌린다(공유 DB 를 더럽히지 않는다).
 * 다른 작업과 섞이지 않도록 테스트 전용 종류(zz_test_*)만 가져간다.
 */
function jobs_test_tx(callable $fn): void
{
    $pdo = test_db();
    try {
        db_value('SELECT 1 FROM jobs LIMIT 1');
    } catch (Throwable $e) {
        skip_test('jobs 테이블 없음(마이그레이션 필요)');
    }
    $pdo->beginTransaction();
    try {
        $fn();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}

test('작업을 등록하면 대기 상태와 옵션이 저장된다', function () {
    jobs_test_tx(function () {
        $id = Jobs::enqueue('zz_test_a', ['profile_id' => 7, 'name' => '엄마'], ['priority' => 2, 'ref_type' => 'voice_profile', 'ref_id' => 7, 'max_attempts' => 4]);
        assert_true($id > 0, 'id 가 없다');
        $row = db_one('SELECT * FROM jobs WHERE id = ?', [$id]);
        assert_same('pending', $row['status']);
        assert_same(2, (int) $row['priority']);
        assert_same(4, (int) $row['max_attempts']);
        assert_same('voice_profile', $row['ref_type']);
        assert_same(7, (int) $row['ref_id']);
        assert_same(['profile_id' => 7, 'name' => '엄마'], json_decode($row['payload'], true));

        // unique 옵션: 같은 대기 작업이 있으면 그 id
        $u1 = Jobs::enqueue('zz_test_a', ['x' => 1], ['unique' => true]);
        $u2 = Jobs::enqueue('zz_test_a', ['x' => 1], ['unique' => true]);
        assert_same($u1, $u2, 'unique 작업이 중복 등록되었다');
    });
});

test('잘못된 작업 종류는 거부한다', function () {
    $thrown = false;
    try {
        Jobs::enqueue('DROP TABLE', []);
    } catch (InvalidArgumentException $e) {
        $thrown = true;
    }
    assert_true($thrown, '예외가 없다');
});

test('우선순위 순서로 가져가고 같은 작업은 두 번 가져가지 않는다', function () {
    jobs_test_tx(function () {
        $low = Jobs::enqueue('zz_test_b', ['n' => 'low'], ['priority' => 9]);
        $high = Jobs::enqueue('zz_test_b', ['n' => 'high'], ['priority' => 1]);
        $later = Jobs::enqueue('zz_test_b', ['n' => 'later'], ['priority' => 0, 'delay_seconds' => 300]);

        $first = Jobs::claim(['only' => ['zz_test_b']]);
        assert_same($high, (int) $first['id'], '우선순위가 높은 작업이 먼저가 아니다');
        assert_same('running', $first['status']);
        assert_same(1, (int) $first['attempts']);
        assert_same(['n' => 'high'], $first['payload']);
        assert_true($first['locked_at'] !== null, 'locked_at 이 없다');

        $second = Jobs::claim(['only' => ['zz_test_b']]);
        assert_same($low, (int) $second['id']);
        // 남은 것은 아직 때가 안 된 작업뿐
        assert_same(null, Jobs::claim(['only' => ['zz_test_b']]));
        assert_same('pending', db_value('SELECT status FROM jobs WHERE id = ?', [$later]));

        // 이미 running 인 작업은 낙관적 UPDATE 가 실패해야 한다
        $won = db_exec("UPDATE jobs SET status = 'running' WHERE id = ? AND status = 'pending'", [$high]);
        assert_same(0, $won);

        Jobs::complete($high);
        $row = db_one('SELECT status, finished_at, locked_at FROM jobs WHERE id = ?', [$high]);
        assert_same('done', $row['status']);
        assert_true($row['finished_at'] !== null && $row['locked_at'] === null, '완료 정보가 이상하다');
    });
});

test('실패하면 30초 × 2^(시도-1) 뒤 다시 시도하고 마지막에는 failed 가 된다', function () {
    assert_same(30, Jobs::backoffSeconds(1));
    assert_same(60, Jobs::backoffSeconds(2));
    assert_same(120, Jobs::backoffSeconds(3));
    assert_same(3600, Jobs::backoffSeconds(20));

    jobs_test_tx(function () {
        $id = Jobs::enqueue('zz_test_c', [], ['max_attempts' => 2]);
        $job = Jobs::claim(['only' => ['zz_test_c']]);
        assert_same($id, (int) $job['id']);
        $r = Jobs::fail($id, '일시 오류');
        assert_same(false, $r['final']);
        assert_same(30, $r['delay']);
        $row = db_one('SELECT status, last_error, TIMESTAMPDIFF(SECOND, NOW(), available_at) AS wait FROM jobs WHERE id = ?', [$id]);
        assert_same('pending', $row['status']);
        assert_same('일시 오류', $row['last_error']);
        assert_true((int) $row['wait'] >= 28 && (int) $row['wait'] <= 30, '대기 시간이 30초가 아니다: ' . $row['wait']);
        assert_same(null, Jobs::claim(['only' => ['zz_test_c']]), '대기 시간이 지나기 전에 가져갔다');

        // 시간을 당겨 두 번째 시도
        db_exec('UPDATE jobs SET available_at = NOW() WHERE id = ?', [$id]);
        $job = Jobs::claim(['only' => ['zz_test_c']]);
        assert_same(2, (int) $job['attempts']);
        $r = Jobs::fail($id, '또 실패');
        assert_same(true, $r['final']);
        assert_same('failed', db_value('SELECT status FROM jobs WHERE id = ?', [$id]));

        // 재시도할 수 없는 오류는 바로 failed
        $id2 = Jobs::enqueue('zz_test_c', ['b' => 1], ['max_attempts' => 5]);
        Jobs::claim(['only' => ['zz_test_c']]);
        $r2 = Jobs::fail($id2, '대상 없음', false);
        assert_same(true, $r2['final']);

        // 관리자 재시도
        assert_true(Jobs::retry($id2), '재시도 등록 실패');
        $row = db_one('SELECT status, attempts FROM jobs WHERE id = ?', [$id2]);
        assert_same('pending', $row['status']);
        assert_same(0, (int) $row['attempts']);
    });
});

test('10분 넘게 멈춘 running 작업을 되돌린다', function () {
    jobs_test_tx(function () {
        $a = Jobs::enqueue('zz_test_d', ['n' => 1], ['max_attempts' => 3, 'ref_type' => 'voice_profile', 'ref_id' => 999999]);
        $b = Jobs::enqueue('zz_test_d', ['n' => 2], ['max_attempts' => 1]);
        $c = Jobs::enqueue('zz_test_d', ['n' => 3], ['max_attempts' => 3]);
        foreach ([$a, $b, $c] as $id) {
            Jobs::claim(['only' => ['zz_test_d']]);
        }
        db_exec('UPDATE jobs SET locked_at = DATE_SUB(NOW(), INTERVAL 11 MINUTE) WHERE id IN (?, ?)', [$a, $b]);

        $n = Jobs::reclaimStale();
        assert_true($n >= 2, '되돌린 개수: ' . $n);
        assert_same('pending', db_value('SELECT status FROM jobs WHERE id = ?', [$a]), '시도가 남은 작업은 pending');
        assert_same('failed', db_value('SELECT status FROM jobs WHERE id = ?', [$b]), '시도를 다 쓴 작업은 failed');
        assert_same('running', db_value('SELECT status FROM jobs WHERE id = ?', [$c]), '최근 작업은 그대로');

        $logs = Jobs::latestLogs('voice_profile', 999999);
        assert_true(count($logs) >= 1, '되돌림 기록이 없다');
        assert_same('warn', $logs[0]['level']);
    });
});

test('작업 기록을 이어 받고 현황 형식을 지킨다', function () {
    jobs_test_tx(function () {
        Jobs::log(null, 'voice_profile', 999998, 'info', '샘플 3개(총 2분 15초) 업로드');
        $first = Jobs::latestLogs('voice_profile', 999998);
        assert_same(1, count($first));
        assert_same(1, preg_match('/^\[\d{2}:\d{2}:\d{2}\] 샘플 3개/u', $first[0]['line']), '줄 형식: ' . $first[0]['line']);
        Jobs::log(null, 'voice_profile', 999998, 'bogus', '두 번째');
        $next = Jobs::latestLogs('voice_profile', 999998, $first[0]['id']);
        assert_same(1, count($next));
        assert_same('info', $next[0]['level'], '모르는 level 은 info');
        assert_same('두 번째', $next[0]['message']);

        Jobs::enqueue('zz_test_e', []);
        $s = Jobs::stats();
        foreach (['pending', 'running', 'failed_24h', 'done_today', 'by_type'] as $k) {
            assert_true(array_key_exists($k, $s), '키 없음: ' . $k);
        }
        assert_true($s['pending'] >= 1, 'pending 이 0 이다');
        assert_same(1, $s['by_type']['zz_test_e']['pending']);
        assert_true(isset($s['by_type']['story_tts']), '기본 종류가 없다');
    });
});
