<?php
namespace App\Services;

/**
 * 백그라운드 작업 큐(jobs 테이블)와 작업 기록(job_logs).
 *
 * - cafe24 에는 cron 이 없으므로 Worker 가 웹 요청(자기 호출, 관리자 화면 주기 호출)으로 작업을 처리한다.
 * - MariaDB 10.3 에도 맞도록 SKIP LOCKED 대신 "status='pending' 일 때만 바꾸는" 낙관적 UPDATE 로 작업을 가져간다.
 * - 실패하면 30초 × 2^(시도 횟수-1) 뒤에 다시 시도하고, max_attempts 를 넘으면 failed 로 끝낸다.
 */
class Jobs
{
    /** running 상태로 이보다 오래 멈춘 작업은 처리기가 죽은 것으로 보고 되돌린다(초). */
    const STALE_SECONDS = 600;

    /** 재시도 기본 간격(초). 실제 간격은 BACKOFF_BASE × 2^(시도-1) */
    const BACKOFF_BASE = 30;

    /** 재시도 간격 상한(초) */
    const BACKOFF_MAX = 3600;

    const TYPES = ['voice_clone', 'story_tts', 'voice_clips', 'voice_delete', 'credit_sync', 'mail'];

    /**
     * 작업을 등록하고 id 를 돌려준다.
     * opts: priority(작을수록 먼저, 기본 5), ref_type, ref_id, delay_seconds, max_attempts(기본 3),
     *       unique(true 면 같은 종류, 같은 대상, 같은 내용의 대기 작업이 있을 때 새로 만들지 않고 그 id 를 돌려준다)
     */
    public static function enqueue(string $type, array $payload, array $opts = []): int
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $type)) {
            throw new \InvalidArgumentException('잘못된 작업 종류: ' . $type);
        }
        $json = json_encode_u((object) $payload);
        $refType = isset($opts['ref_type']) && $opts['ref_type'] !== '' ? (string) $opts['ref_type'] : null;
        $refId = isset($opts['ref_id']) && $opts['ref_id'] !== null ? (int) $opts['ref_id'] : null;

        if (!empty($opts['unique'])) {
            $existing = db_value(
                'SELECT id FROM jobs WHERE type = ? AND status = ? AND payload = ? AND ref_type <=> ? AND ref_id <=> ? ORDER BY id LIMIT 1',
                [$type, 'pending', $json, $refType, $refId]
            );
            if ($existing) {
                return (int) $existing;
            }
        }

        $priority = isset($opts['priority']) ? max(-100, min(100, (int) $opts['priority'])) : 5;
        $delay = isset($opts['delay_seconds']) ? max(0, (int) $opts['delay_seconds']) : 0;
        $maxAttempts = isset($opts['max_attempts']) ? max(1, min(20, (int) $opts['max_attempts'])) : 3;

        db_query(
            'INSERT INTO jobs (type, payload, status, priority, attempts, max_attempts, available_at, ref_type, ref_id)
             VALUES (?, ?, ?, ?, 0, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?, ?)',
            [$type, $json, 'pending', $priority, $maxAttempts, $delay, $refType, $refId]
        );

        return (int) db()->lastInsertId();
    }

    /**
     * 처리할 차례가 된 작업 하나를 가져가 running 으로 바꾼다. 없으면 null.
     * opts: exclude(건너뛸 종류 목록), only(이 종류만), worker(locked_by 에 남길 이름)
     * 돌려주는 행의 payload 는 배열로 풀어 둔다. attempts 는 이번 시도를 포함한 값이다.
     */
    public static function claim(array $opts = []): ?array
    {
        $where = 'status = ? AND available_at <= NOW()';
        $params = ['pending'];
        foreach (['exclude' => 'NOT IN', 'only' => 'IN'] as $key => $op) {
            if (!empty($opts[$key]) && is_array($opts[$key])) {
                $types = array_values(array_map('strval', $opts[$key]));
                $where .= ' AND type ' . $op . ' (' . implode(', ', array_fill(0, count($types), '?')) . ')';
                $params = array_merge($params, $types);
            }
        }
        $worker = isset($opts['worker']) ? substr((string) $opts['worker'], 0, 64) : self::workerName();

        // 다른 처리기와 경쟁할 수 있으므로 후보 몇 개를 차례로 시도한다.
        for ($round = 0; $round < 3; $round++) {
            $candidates = db_all(
                'SELECT id FROM jobs WHERE ' . $where . ' ORDER BY priority, available_at, id LIMIT 5',
                $params
            );
            if (!$candidates) {
                return null;
            }
            foreach ($candidates as $c) {
                $won = db_exec(
                    'UPDATE jobs SET status = ?, locked_at = NOW(), locked_by = ?, attempts = attempts + 1
                     WHERE id = ? AND status = ? AND available_at <= NOW()',
                    ['running', $worker, (int) $c['id'], 'pending']
                );
                if ($won === 1) {
                    $job = db_one('SELECT * FROM jobs WHERE id = ?', [(int) $c['id']]);
                    if ($job) {
                        $job['payload'] = json_decode_array($job['payload']);

                        return $job;
                    }
                }
            }
        }

        return null;
    }

    /** 오래 멈춘 running 작업을 되돌린다(시도 횟수가 남았으면 pending, 아니면 failed). 되돌린 개수 */
    public static function reclaimStale(int $staleSeconds = self::STALE_SECONDS): int
    {
        $rows = db_all(
            'SELECT id, type, attempts, max_attempts, ref_type, ref_id FROM jobs
             WHERE status = ? AND locked_at IS NOT NULL AND locked_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            ['running', $staleSeconds]
        );
        $count = 0;
        foreach ($rows as $r) {
            $refType = $r['ref_type'] !== null ? (string) $r['ref_type'] : null;
            $refId = $r['ref_id'] !== null ? (int) $r['ref_id'] : null;
            if ((int) $r['attempts'] >= (int) $r['max_attempts']) {
                $n = db_exec(
                    'UPDATE jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, finished_at = NOW() WHERE id = ? AND status = ?',
                    ['failed', '처리 시간 초과(처리기 중단)', (int) $r['id'], 'running']
                );
                if ($n) {
                    self::log((int) $r['id'], $refType, $refId, 'error', '작업이 응답 없이 멈춰 실패로 처리했습니다(' . $r['type'] . ').');
                }
            } else {
                $n = db_exec(
                    'UPDATE jobs SET status = ?, available_at = NOW(), locked_at = NULL, locked_by = NULL, last_error = ? WHERE id = ? AND status = ?',
                    ['pending', '처리 시간 초과(처리기 중단), 다시 시도', (int) $r['id'], 'running']
                );
                if ($n) {
                    self::log((int) $r['id'], $refType, $refId, 'warn', '멈춘 작업을 다시 대기열에 넣었습니다(' . $r['type'] . ').');
                }
            }
            $count += $n;
        }

        return $count;
    }

    public static function complete(int $jobId): void
    {
        db_exec(
            'UPDATE jobs SET status = ?, locked_at = NULL, locked_by = NULL, last_error = NULL, finished_at = NOW() WHERE id = ?',
            ['done', $jobId]
        );
    }

    /**
     * 실패를 기록한다. $retry 가 false 거나 시도 횟수를 다 쓰면 failed 로 끝낸다.
     * 반환: ['final' => bool, 'delay' => 다음 시도까지 초(final 이면 0), 'attempts' => n, 'max_attempts' => n]
     */
    public static function fail(int $jobId, string $error, bool $retry = true): array
    {
        $job = db_one('SELECT attempts, max_attempts FROM jobs WHERE id = ?', [$jobId]);
        $attempts = $job ? (int) $job['attempts'] : 0;
        $max = $job ? (int) $job['max_attempts'] : 0;
        $error = self::cut($error, 1000);

        if (!$retry || $attempts >= $max) {
            db_exec(
                'UPDATE jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, finished_at = NOW() WHERE id = ?',
                ['failed', $error, $jobId]
            );

            return ['final' => true, 'delay' => 0, 'attempts' => $attempts, 'max_attempts' => $max];
        }
        $delay = self::backoffSeconds($attempts);
        db_exec(
            'UPDATE jobs SET status = ?, last_error = ?, locked_at = NULL, locked_by = NULL, available_at = DATE_ADD(NOW(), INTERVAL ? SECOND) WHERE id = ?',
            ['pending', $error, $delay, $jobId]
        );

        return ['final' => false, 'delay' => $delay, 'attempts' => $attempts, 'max_attempts' => $max];
    }

    /**
     * 시도 횟수를 쓰지 않고 잠시 뒤로 미룬다(다른 작업이 끝나야 할 수 있는 일, 예: 목소리 자리 기다림).
     * 등록한 지 $maxAgeHours 가 지난 작업은 미루지 않고 false 를 돌려준다(그때는 보통의 실패로 처리한다).
     */
    public static function postpone(int $jobId, int $seconds, string $reason, int $maxAgeHours = 24): bool
    {
        return db_exec(
            'UPDATE jobs SET status = ?, attempts = GREATEST(attempts - 1, 0), last_error = ?, locked_at = NULL, locked_by = NULL,
                    available_at = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE id = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)',
            ['pending', self::cut($reason, 1000), max(1, $seconds), $jobId, max(1, $maxAgeHours)]
        ) === 1;
    }

    /** n 번째 시도가 실패한 뒤 기다릴 시간(초): 30, 60, 120, 240 ... (상한 1시간) */
    public static function backoffSeconds(int $attempts): int
    {
        $n = max(1, $attempts);

        return (int) min(self::BACKOFF_MAX, self::BACKOFF_BASE * pow(2, $n - 1));
    }

    /** 대기 중인 작업을 취소한다(목소리 삭제, 반려 때). 취소한 개수 */
    public static function cancelPending(string $refType, int $refId, array $types = []): int
    {
        $sql = 'UPDATE jobs SET status = ?, finished_at = NOW(), last_error = ? WHERE status = ? AND ref_type = ? AND ref_id = ?';
        $params = ['cancelled', '취소됨', 'pending', $refType, $refId];
        if ($types) {
            $sql .= ' AND type IN (' . implode(', ', array_fill(0, count($types), '?')) . ')';
            $params = array_merge($params, array_values($types));
        }

        return db_exec($sql, $params);
    }

    /** 실패한 작업을 다시 대기열에 넣는다(관리자 재시도). */
    public static function retry(int $jobId): bool
    {
        return db_exec(
            'UPDATE jobs SET status = ?, attempts = 0, available_at = NOW(), finished_at = NULL, locked_at = NULL, locked_by = NULL WHERE id = ? AND status IN (?, ?)',
            ['pending', $jobId, 'failed', 'cancelled']
        ) === 1;
    }

    /** 처리기 콘솔에 남길 기록. 기록 실패가 작업을 막지 않도록 예외를 삼킨다. */
    public static function log(?int $jobId, ?string $refType, ?int $refId, string $level, string $message): void
    {
        $level = in_array($level, ['info', 'warn', 'error'], true) ? $level : 'info';
        try {
            db_insert('job_logs', [
                'job_id' => $jobId,
                'ref_type' => $refType,
                'ref_id' => $refId,
                'level' => $level,
                'message' => self::cut($message, 1000),
            ]);
        } catch (\Throwable $e) {
            app_log('error', '작업 기록 실패: ' . $e->getMessage());
        }
    }

    /**
     * 대상(예: voice_profile 12)의 작업 기록을 오래된 순서로 돌려준다. $afterId 보다 큰 것만(실시간 콘솔 이어 받기).
     * 각 행: id, job_id, level, message, created_at, time(H:i:s), line("[H:i:s] 메시지")
     */
    public static function latestLogs(string $refType, int $refId, int $afterId = 0, int $limit = 200): array
    {
        $limit = max(1, min(500, $limit));
        if ($afterId > 0) {
            $rows = db_all(
                'SELECT id, job_id, level, message, created_at FROM job_logs WHERE ref_type = ? AND ref_id = ? AND id > ? ORDER BY id LIMIT ?',
                [$refType, $refId, $afterId, $limit]
            );
        } else {
            // 처음 열 때는 최근 기록만
            $rows = array_reverse(db_all(
                'SELECT id, job_id, level, message, created_at FROM job_logs WHERE ref_type = ? AND ref_id = ? ORDER BY id DESC LIMIT ?',
                [$refType, $refId, $limit]
            ));
        }
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['job_id'] = $r['job_id'] !== null ? (int) $r['job_id'] : null;
            $r['time'] = date('H:i:s', strtotime((string) $r['created_at']));
            $r['line'] = '[' . $r['time'] . '] ' . $r['message'];
        }
        unset($r);

        return $rows;
    }

    /** 대상과 연결된 작업 목록(최근 순) */
    public static function forRef(string $refType, int $refId, int $limit = 50): array
    {
        $rows = db_all(
            'SELECT id, type, status, priority, attempts, max_attempts, available_at, locked_at, last_error, created_at, finished_at, payload
             FROM jobs WHERE ref_type = ? AND ref_id = ? ORDER BY id DESC LIMIT ?',
            [$refType, $refId, max(1, $limit)]
        );
        foreach ($rows as &$r) {
            $r['payload'] = json_decode_array($r['payload']);
        }
        unset($r);

        return $rows;
    }

    /** 지금 처리할 수 있는 대기 작업 수 */
    public static function dueCount(): int
    {
        return (int) db_value('SELECT COUNT(*) FROM jobs WHERE status = ? AND available_at <= NOW()', ['pending']);
    }

    /** 대상의 대기, 실행 중 작업 수(종류 지정 가능) */
    public static function activeCount(string $refType, int $refId, ?string $type = null): int
    {
        $sql = 'SELECT COUNT(*) FROM jobs WHERE ref_type = ? AND ref_id = ? AND status IN (?, ?)';
        $params = [$refType, $refId, 'pending', 'running'];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        return (int) db_value($sql, $params);
    }

    /**
     * 큐 현황. 대시보드와 작업 처리기 응답에 쓴다.
     * ['pending', 'running', 'failed_24h', 'done_today', 'due', 'oldest_pending_at', 'last_finished_at', 'last_run_at',
     *  'by_type' => [type => ['pending', 'running', 'failed_24h', 'done_today']]]
     */
    public static function stats(): array
    {
        $out = [
            'pending' => 0,
            'running' => 0,
            'failed_24h' => 0,
            'done_today' => 0,
            'due' => 0,
            'oldest_pending_at' => null,
            'last_finished_at' => null,
            'last_run_at' => Worker::lastRunAt(),
            'by_type' => [],
        ];
        $empty = ['pending' => 0, 'running' => 0, 'failed_24h' => 0, 'done_today' => 0];
        foreach (self::TYPES as $t) {
            $out['by_type'][$t] = $empty;
        }

        $rows = db_all(
            'SELECT type,
                    SUM(status = ?) AS pending,
                    SUM(status = ?) AS running,
                    SUM(status = ? AND finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_24h,
                    SUM(status = ? AND finished_at >= CURDATE()) AS done_today
             FROM jobs
             WHERE status IN (?, ?) OR finished_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) OR finished_at >= CURDATE()
             GROUP BY type',
            ['pending', 'running', 'failed', 'done', 'pending', 'running']
        );
        foreach ($rows as $r) {
            $t = (string) $r['type'];
            $row = [
                'pending' => (int) $r['pending'],
                'running' => (int) $r['running'],
                'failed_24h' => (int) $r['failed_24h'],
                'done_today' => (int) $r['done_today'],
            ];
            $out['by_type'][$t] = $row;
            foreach ($row as $k => $v) {
                $out[$k] += $v;
            }
        }
        $out['due'] = self::dueCount();
        $oldest = db_value('SELECT MIN(created_at) FROM jobs WHERE status = ?', ['pending']);
        $out['oldest_pending_at'] = $oldest ? (string) $oldest : null;
        $last = db_value('SELECT MAX(finished_at) FROM jobs WHERE status IN (?, ?)', ['done', 'failed']);
        $out['last_finished_at'] = $last ? (string) $last : null;

        return $out;
    }

    /** 오래된 기록 정리: 끝난 작업 30일, 작업 기록 90일 */
    public static function prune(int $jobDays = 30, int $logDays = 90): array
    {
        $jobs = db_exec(
            'DELETE FROM jobs WHERE status IN (?, ?, ?) AND finished_at IS NOT NULL AND finished_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            ['done', 'cancelled', 'failed', $jobDays]
        );
        $logs = db_exec('DELETE FROM job_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$logDays]);

        return ['jobs' => $jobs, 'logs' => $logs];
    }

    /** locked_by 에 남길 처리기 이름(호스트:프로세스) */
    public static function workerName(): string
    {
        $host = function_exists('gethostname') ? (string) @gethostname() : 'host';

        return substr($host . ':' . getmypid(), 0, 64);
    }

    private static function cut(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
