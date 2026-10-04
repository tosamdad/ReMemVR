<?php
namespace App\Services;

/**
 * 관리자 운영 대시보드와 목소리 관리 화면의 집계.
 * 모든 숫자는 DB 와 운영 설정에서 계산한다(값이 없으면 null 을 돌려 화면이 빈 상태를 보여 준다).
 * 화면(DashboardController@index)과 30초 자동 갱신 API(DashboardController@api)가 같은 값을 쓴다.
 */
class DashboardStats
{
    /** SNR 등급 기준(dB). 녹음기(recorder.js)의 품질 등급 기준과 같다. */
    const SNR_GOOD = 25;
    const SNR_FAIR = 15;

    /** 대시보드 전체 */
    public static function collect(): array
    {
        $interactions = self::interactionKpis();
        $plays = self::playKpis();
        $interactions['per_story'] = $plays['total'] > 0 ? round($interactions['total'] / $plays['total'], 1) : null;

        return [
            'voices' => self::voiceKpis(),
            'plays' => $plays,
            'interactions' => $interactions,
            'cost' => self::costKpis($interactions['total']),
            'queue' => self::pendingQueue(3),
            'latency' => self::latency(),
            'batch' => self::batchQueue(3),
            'health' => self::health(),
            'policy' => self::policy(),
            'elevenlabs_ready' => provider_ready('elevenlabs'),
            'approvable' => self::approvableCount(),
            'generated_at' => now(),
        ];
    }

    // ───────────────────────── KPI 카드 ─────────────────────────

    /** 목소리 생성 대기열: 대기, 생성 중(cloning + processing), 오늘 승인 */
    public static function voiceKpis(): array
    {
        $row = db_one(
            "SELECT COALESCE(SUM(status = 'pending'), 0) AS pending,
                    COALESCE(SUM(status = 'cloning'), 0) AS cloning,
                    COALESCE(SUM(status = 'processing'), 0) AS processing,
                    COALESCE(SUM(processed_at >= ? AND status IN ('cloning', 'processing', 'completed', 'failed')), 0) AS approved_today
               FROM voice_profiles WHERE deleted_at IS NULL",
            [self::today()]
        );

        return [
            'pending' => (int) $row['pending'],
            'cloning' => (int) $row['cloning'],
            'processing' => (int) $row['processing'],
            'in_progress' => (int) $row['cloning'] + (int) $row['processing'],
            'approved_today' => (int) $row['approved_today'],
        ];
    }

    /** 오늘 시작한 동화 재생 수와 사전 생성 음성(가족 목소리) 재생 비율 */
    public static function playKpis(): array
    {
        $row = db_one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(audio_source = 'voice'), 0) AS voice, COALESCE(SUM(completed = 1), 0) AS completed
               FROM play_sessions WHERE started_at >= ?",
            [self::today()]
        );
        $total = (int) $row['total'];

        return [
            'total' => $total,
            'voice' => (int) $row['voice'],
            'device' => $total - (int) $row['voice'],
            'completed' => (int) $row['completed'],
            'voice_share' => $total > 0 ? (int) round((int) $row['voice'] * 100 / $total) : null,
        ];
    }

    /** 오늘 아이 질문 수와 처리 방식별 건수 */
    public static function interactionKpis(): array
    {
        $row = db_one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(mode = 'answer'), 0) AS answered,
                    COALESCE(SUM(mode IN ('quota', 'fallback')), 0) AS limited,
                    COALESCE(SUM(mode IN ('error', 'budget', 'disabled')), 0) AS blocked
               FROM interactions WHERE created_at >= ?",
            [self::today()]
        );

        return [
            'total' => (int) $row['total'],
            'answered' => (int) $row['answered'],
            'limited' => (int) $row['limited'],
            'blocked' => (int) $row['blocked'],
            'per_story' => null,
            'max_questions' => (int) setting('qa.max_questions', 3),
            'qa_enabled' => (bool) setting('qa.enabled', true),
        ];
    }

    /** 오늘 누적 API 비용, 일일 한도 대비 비율, 질문 1건당 평균 */
    public static function costKpis(int $interactionsToday): array
    {
        $today = Usage::todayCostKrw();
        $budget = (float) setting('cost.daily_budget_krw', 27000);

        return [
            'today' => $today,
            'budget' => $budget,
            'percent' => $budget > 0 ? (int) round($today * 100 / $budget) : null,
            'per_interaction' => $interactionsToday > 0 ? round($today / $interactionsToday, 1) : null,
        ];
    }

    /** 일괄 승인 대상(대기 중이며 품질 등급이 좋음, 보통) */
    public static function approvableCount(): int
    {
        return (int) db_value(
            "SELECT COUNT(*) FROM voice_profiles WHERE status = 'pending' AND deleted_at IS NULL AND quality_grade IN ('good', 'fair')"
        );
    }

    // ───────────────────────── 목소리 생성 요청 대기열 ─────────────────────────

    /** 검토 대기 목소리(오래된 순). ['items' => [...], 'total' => n] */
    public static function pendingQueue(int $limit = 3): array
    {
        $total = (int) db_value("SELECT COUNT(*) FROM voice_profiles WHERE status = 'pending' AND deleted_at IS NULL");
        $rows = $total > 0 ? db_all(
            "SELECT vp.*, u.name AS user_name, u.email AS user_email, u.phone AS user_phone
               FROM voice_profiles vp JOIN users u ON u.id = vp.user_id
              WHERE vp.status = 'pending' AND vp.deleted_at IS NULL
              ORDER BY vp.requested_at ASC, vp.id ASC LIMIT ?",
            [max(1, $limit)]
        ) : [];

        return ['items' => self::enrich($rows), 'total' => $total];
    }

    /**
     * 목소리 행에 샘플 요약(개수, 길이, 평균 SNR, 첫 샘플)과 첫째 자녀를 붙인다.
     * 각 행에 'samples' => [count, total_ms, snr, clips, first_id, first_ms], 'child' => ?array, 'grade' => gradeInfo()
     */
    public static function enrich(array $rows): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(static function ($r) {
            return (int) $r['id'];
        }, $rows);
        $userIds = array_values(array_unique(array_map(static function ($r) {
            return (int) $r['user_id'];
        }, $rows)));
        $samples = self::sampleStats($ids);
        $children = self::firstChildren($userIds);
        foreach ($rows as &$r) {
            $id = (int) $r['id'];
            $s = isset($samples[$id]) ? $samples[$id] : ['count' => 0, 'total_ms' => 0, 'snr' => null, 'clips' => 0, 'first_id' => null, 'first_ms' => 0];
            if ($s['total_ms'] <= 0 && (int) $r['sample_total_ms'] > 0) {
                $s['total_ms'] = (int) $r['sample_total_ms'];
            }
            $r['samples'] = $s;
            $r['child'] = isset($children[(int) $r['user_id']]) ? $children[(int) $r['user_id']] : null;
            $r['grade'] = self::gradeInfo($s['snr'], isset($r['quality_grade']) ? $r['quality_grade'] : null);
        }
        unset($r);

        return $rows;
    }

    /** 목소리별 샘플 요약. [profile_id => [count, total_ms, snr, clips, first_id, first_ms]] */
    public static function sampleStats(array $profileIds): array
    {
        $profileIds = array_values(array_filter(array_map('intval', $profileIds)));
        if (!$profileIds) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($profileIds), '?'));
        $out = [];
        foreach (db_all(
            'SELECT voice_profile_id, COUNT(*) AS n, COALESCE(SUM(duration_ms), 0) AS total_ms, AVG(snr_db) AS snr,
                    COALESCE(SUM(clip_count), 0) AS clips, MIN(id) AS first_id
               FROM voice_samples WHERE voice_profile_id IN (' . $in . ') GROUP BY voice_profile_id',
            $profileIds
        ) as $r) {
            $out[(int) $r['voice_profile_id']] = [
                'count' => (int) $r['n'],
                'total_ms' => (int) $r['total_ms'],
                'snr' => $r['snr'] !== null ? round((float) $r['snr'], 1) : null,
                'clips' => (int) $r['clips'],
                'first_id' => (int) $r['first_id'],
                'first_ms' => 0,
            ];
        }
        $firstIds = array_values(array_filter(array_map(static function ($s) {
            return $s['first_id'];
        }, $out)));
        if ($firstIds) {
            $in2 = implode(', ', array_fill(0, count($firstIds), '?'));
            foreach (db_all('SELECT id, voice_profile_id, duration_ms FROM voice_samples WHERE id IN (' . $in2 . ')', $firstIds) as $r) {
                $out[(int) $r['voice_profile_id']]['first_ms'] = (int) $r['duration_ms'];
            }
        }

        return $out;
    }

    /** 회원별 첫째 자녀(정렬 순서, 등록 순). [user_id => child row] */
    public static function firstChildren(array $userIds): array
    {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($userIds), '?'));
        $out = [];
        foreach (db_all('SELECT * FROM children WHERE user_id IN (' . $in . ') ORDER BY user_id, sort_order, id', $userIds) as $c) {
            $uid = (int) $c['user_id'];
            if (!isset($out[$uid])) {
                $out[$uid] = $c;
            }
        }

        return $out;
    }

    /**
     * 샘플 품질 등급. 녹음기가 매긴 등급(quality_grade)을 우선하고, 없으면 평균 SNR 로 정한다.
     * ['key' => good|fair|poor|null, 'short' => 최상|적합|재확인 권장, 'label' => 우수 (SNR 25dB+) ..., 'tone' => primary|secondary|error|muted]
     */
    public static function gradeInfo($snr, $grade = null): array
    {
        $key = in_array($grade, ['good', 'fair', 'poor'], true) ? $grade : null;
        if ($key === null && $snr !== null && $snr !== '') {
            $v = (float) $snr;
            $key = $v >= self::SNR_GOOD ? 'good' : ($v >= self::SNR_FAIR ? 'fair' : 'poor');
        }
        $map = [
            'good' => ['short' => '최상', 'label' => '우수 (SNR ' . self::SNR_GOOD . 'dB+)', 'tone' => 'primary'],
            'fair' => ['short' => '적합', 'label' => '보통 (재확인 권장)', 'tone' => 'secondary'],
            'poor' => ['short' => '재확인 권장', 'label' => '불량 (노이즈 감지)', 'tone' => 'error'],
        ];
        if ($key === null) {
            return ['key' => null, 'short' => '측정 없음', 'label' => '품질 측정 없음', 'tone' => 'muted'];
        }

        return array_merge(['key' => $key], $map[$key]);
    }

    // ───────────────────────── 끼어들기 응답 지연 ─────────────────────────

    /**
     * 오늘 답변(mode=answer)의 2시간 단위 평균 지연과 단계별 평균.
     * ['count', 'avg_ms', 'target_ms', 'tts_ms', 'llm_ms', 'cost_per_question', 'max_ms',
     *  'buckets' => [['index', 'label', 'from', 'count', 'avg_ms'] x 12], 'current' => 지금 시각 구간]
     */
    public static function latency(): array
    {
        $today = self::today();
        $target = (int) setting('cost.latency_target_ms', 1500);
        $sum = db_one(
            "SELECT COUNT(*) AS n, AVG(latency_ms) AS lat, AVG(tts_ms) AS tts, AVG(llm_ms) AS llm, AVG(cost_krw) AS cost, MAX(latency_ms) AS mx
               FROM interactions WHERE created_at >= ? AND mode = 'answer' AND latency_ms IS NOT NULL",
            [$today]
        );
        $byBucket = [];
        foreach (db_all(
            "SELECT FLOOR(HOUR(created_at) / 2) AS b, COUNT(*) AS n, AVG(latency_ms) AS lat
               FROM interactions WHERE created_at >= ? AND mode = 'answer' AND latency_ms IS NOT NULL
              GROUP BY FLOOR(HOUR(created_at) / 2)",
            [$today]
        ) as $r) {
            $byBucket[(int) $r['b']] = ['count' => (int) $r['n'], 'avg_ms' => (int) round((float) $r['lat'])];
        }
        $buckets = [];
        for ($b = 0; $b < 12; $b++) {
            $buckets[] = [
                'index' => $b,
                'label' => sprintf('%02d시', $b * 2),
                'count' => isset($byBucket[$b]) ? $byBucket[$b]['count'] : 0,
                'avg_ms' => isset($byBucket[$b]) ? $byBucket[$b]['avg_ms'] : null,
            ];
        }
        $n = (int) $sum['n'];

        return [
            'count' => $n,
            'avg_ms' => $n > 0 ? (int) round((float) $sum['lat']) : null,
            'max_ms' => $n > 0 ? (int) $sum['mx'] : null,
            'target_ms' => $target,
            'tts_ms' => $n > 0 && $sum['tts'] !== null ? (int) round((float) $sum['tts']) : null,
            'llm_ms' => $n > 0 && $sum['llm'] !== null ? (int) round((float) $sum['llm']) : null,
            'cost_per_question' => $n > 0 && $sum['cost'] !== null ? round((float) $sum['cost'], 1) : null,
            'buckets' => $buckets,
            'current' => intdiv((int) date('G'), 2),
        ];
    }

    // ───────────────────────── 동화 사전 생성 큐 ─────────────────────────

    /**
     * 동화 오디오를 만들고 있거나 기다리는 목소리. 생성 중인 것 먼저, 나머지는 대기열 순서.
     * ['items' => [[id, label, user_name, status, batch_status, progress, current_title, running, order, eta_sec]], 'total', 'running', 'stories'(게시 동화 수)]
     */
    public static function batchQueue(int $limit = 3): array
    {
        $rows = db_all(
            "SELECT vp.id, vp.label, vp.status, vp.batch_status, vp.user_id, vp.updated_at, u.name AS user_name
               FROM voice_profiles vp JOIN users u ON u.id = vp.user_id
              WHERE vp.deleted_at IS NULL AND (vp.batch_status IN ('queued', 'running') OR vp.status = 'processing')"
        );
        $stories = (int) db_value("SELECT COUNT(*) FROM stories WHERE status = 'published' AND deleted_at IS NULL");
        if (!$rows) {
            return ['items' => [], 'total' => 0, 'running' => 0, 'stories' => $stories];
        }
        $ids = array_map(static function ($r) {
            return (int) $r['id'];
        }, $rows);
        $in = implode(', ', array_fill(0, count($ids), '?'));

        // 지금 합성 중인 동화(작업 payload 의 story_id)
        $runningStory = [];
        foreach (db_all(
            "SELECT ref_id, payload FROM jobs WHERE type = 'story_tts' AND status = 'running' AND ref_type = 'voice_profile' AND ref_id IN (" . $in . ')',
            $ids
        ) as $j) {
            $p = json_decode_array($j['payload']);
            if (isset($p['story_id'])) {
                $runningStory[(int) $j['ref_id']] = (int) $p['story_id'];
            }
        }
        // 작업이 없더라도 오디오 행이 processing 이면 그 동화를 보여 준다.
        foreach (db_all(
            "SELECT voice_profile_id, MIN(story_id) AS story_id FROM story_audios WHERE status = 'processing' AND voice_profile_id IN (" . $in . ') GROUP BY voice_profile_id',
            $ids
        ) as $a) {
            if (!isset($runningStory[(int) $a['voice_profile_id']])) {
                $runningStory[(int) $a['voice_profile_id']] = (int) $a['story_id'];
            }
        }
        $titles = [];
        if ($runningStory) {
            $sids = array_values(array_unique($runningStory));
            foreach (db_all('SELECT id, title FROM stories WHERE id IN (' . implode(', ', array_fill(0, count($sids), '?')) . ')', $sids) as $s) {
                $titles[(int) $s['id']] = (string) $s['title'];
            }
        }
        // 대기열 순서: 각 목소리의 가장 먼저 등록된 대기 작업
        $firstPending = [];
        foreach (db_all(
            "SELECT ref_id, MIN(id) AS first_id FROM jobs WHERE type = 'story_tts' AND status = 'pending' AND ref_type = 'voice_profile' AND ref_id IN (" . $in . ') GROUP BY ref_id',
            $ids
        ) as $j) {
            $firstPending[(int) $j['ref_id']] = (int) $j['first_id'];
        }

        $perStorySec = self::avgStorySynthSeconds();
        $items = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $progress = VoiceService::progress($id);
            $running = isset($runningStory[$id]);
            $remaining = max(0, $progress['total'] - $progress['completed'] - $progress['failed']);
            $items[] = [
                'id' => $id,
                'label' => (string) $r['label'],
                'user_name' => (string) $r['user_name'],
                'status' => (string) $r['status'],
                'batch_status' => (string) $r['batch_status'],
                'progress' => $progress,
                'running' => $running,
                'current_title' => $running && isset($titles[$runningStory[$id]]) ? $titles[$runningStory[$id]] : null,
                'current_seq' => $running ? $progress['completed'] + 1 : null,
                'sort' => $running ? 0 : (isset($firstPending[$id]) ? $firstPending[$id] : PHP_INT_MAX),
                'eta_sec' => $perStorySec !== null && $remaining > 0 ? (int) round($perStorySec * $remaining) : null,
                'order' => 0,
            ];
        }
        usort($items, static function ($a, $b) {
            if ($a['sort'] === $b['sort']) {
                return $a['id'] - $b['id'];
            }

            return $a['sort'] < $b['sort'] ? -1 : 1;
        });
        $runningCount = 0;
        foreach ($items as $i => &$it) {
            $it['order'] = $i + 1;
            if ($it['running']) {
                $runningCount++;
            }
            unset($it['sort']);
        }
        unset($it);

        return ['items' => array_slice($items, 0, max(1, $limit)), 'total' => count($items), 'running' => $runningCount, 'stories' => $stories];
    }

    /** 최근 7일 동화 한 편 합성에 걸린 평균 시간(초). 기록이 없으면 null */
    public static function avgStorySynthSeconds(): ?float
    {
        $v = db_value(
            "SELECT AVG(t) FROM (
                SELECT SUM(latency_ms) AS t FROM api_usage_logs
                 WHERE purpose = 'story_tts' AND success = 1 AND latency_ms IS NOT NULL AND ref_id IS NOT NULL
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                 GROUP BY ref_id
             ) x"
        );

        return $v !== null ? round((float) $v / 1000, 1) : null;
    }

    // ───────────────────────── 헬스체크, 정책 ─────────────────────────

    /**
     * 코어 엔진 헬스체크(Health::status 결과를 화면용으로 정리).
     * ['all_ok', 'fake', 'checked_at', 'items' => [[key, icon, name, detail(세부 수치), ok, badge(짧은 상태), text(설명)]]]
     */
    public static function health(): array
    {
        try {
            $h = Health::status();
        } catch (\Throwable $e) {
            app_log('error', '헬스체크 실패: ' . $e->getMessage());
            $h = [];
        }
        $g = isset($h['gemini']) ? $h['gemini'] : ['ok' => false, 'ms' => null, 'message' => '점검 실패'];
        $el = isset($h['elevenlabs']) ? $h['elevenlabs'] : ['ok' => false, 'ms' => null, 'message' => '점검 실패', 'credits' => null];
        $st = isset($h['storage']) ? $h['storage'] : ['ok' => false, 'writable' => false, 'free_mb' => null, 'message' => '점검 실패'];
        $wk = isset($h['worker']) ? $h['worker'] : ['pending' => 0, 'running' => 0, 'failed_24h' => 0, 'stale' => 0, 'last_done_at' => null];

        $model = (string) setting('gemini.model', 'gemini-2.5-flash');
        $items = [];
        $msText = static function ($ms) {
            return $ms !== null ? ' (' . number_format((int) $ms) . 'ms)' : '';
        };
        $items[] = [
            'key' => 'gemini', 'icon' => 'neurology', 'name' => 'Gemini',
            'detail' => $model . ' · ' . (!empty($g['ok']) ? '아이 질문 이해, 답변 작성' : (string) $g['message']),
            'ok' => !empty($g['ok']),
            'badge' => !empty($g['ok']) ? '정상' . $msText($g['ms']) : '점검 필요',
            'text' => !empty($g['ok']) ? '정상' . $msText($g['ms']) : (string) $g['message'],
        ];
        $credits = isset($el['credits']) && is_array($el['credits']) ? $el['credits'] : null;
        $items[] = [
            'key' => 'elevenlabs', 'icon' => 'graphic_eq', 'name' => 'ElevenLabs',
            'detail' => !empty($el['ok'])
                ? ($credits ? '잔여 ' . number_format((int) $credits['remaining']) . ' / ' . number_format((int) $credits['limit']) . ' 크레딧' : '목소리 복제, 음성 합성')
                : (string) $el['message'],
            'ok' => !empty($el['ok']),
            'badge' => !empty($el['ok']) ? '정상' . $msText($el['ms']) : '점검 필요',
            'text' => !empty($el['ok']) ? '정상' : (string) $el['message'],
            'credit_percent' => $credits && (int) $credits['limit'] > 0 ? (int) round((int) $credits['remaining'] * 100 / (int) $credits['limit']) : null,
        ];
        $freeText = $st['free_mb'] !== null
            ? ($st['free_mb'] >= 1024 ? '여유 ' . number_format($st['free_mb'] / 1024, 1) . 'GB' : '여유 ' . number_format((int) $st['free_mb']) . 'MB')
            : '여유 공간 알 수 없음';
        $items[] = [
            'key' => 'storage', 'icon' => 'hard_drive', 'name' => '저장 공간',
            'detail' => !empty($st['writable']) ? '녹음 샘플, 동화 오디오 · ' . $freeText : (string) $st['message'],
            'ok' => !empty($st['ok']),
            'badge' => !empty($st['writable']) ? (!empty($st['ok']) ? '쓰기 가능' : '공간 부족') : '쓰기 불가',
            'text' => (string) $st['message'],
        ];
        $workerOk = (int) $wk['failed_24h'] === 0 && (int) $wk['stale'] === 0;
        $items[] = [
            'key' => 'worker', 'icon' => 'memory', 'name' => '작업 처리기',
            'detail' => '대기 ' . number_format((int) $wk['pending']) . ' · 실행 ' . number_format((int) $wk['running']) . ' · 24시간 실패 ' . number_format((int) $wk['failed_24h']),
            'ok' => $workerOk,
            'badge' => $workerOk ? '정상' : ((int) $wk['stale'] > 0 ? '멈춘 작업' : '실패 확인'),
            'text' => $workerOk ? '정상' : '실패한 작업이 있습니다',
        ];
        $allOk = true;
        foreach ($items as $it) {
            $allOk = $allOk && $it['ok'];
        }

        return [
            'all_ok' => $allOk,
            'fake' => !empty($h['fake']),
            'checked_at' => isset($h['checked_at']) ? (string) $h['checked_at'] : null,
            'items' => $items,
        ];
    }

    /** 비용 방어 정책 요약(운영 설정 값) */
    public static function policy(): array
    {
        $blocked = setting('qa.blocked_words', []);

        return [
            'max_questions' => (int) setting('qa.max_questions', 3),
            'daily_budget' => (float) setting('cost.daily_budget_krw', 27000),
            'qa_enabled' => (bool) setting('qa.enabled', true),
            'blocked_words' => is_array($blocked) ? count($blocked) : 0,
            'max_answer_chars' => (int) setting('qa.max_answer_chars', 120),
        ];
    }

    // ───────────────────────── 목소리 관리 화면 ─────────────────────────

    /** 목소리 관리 상단 KPI */
    public static function voiceListKpis(): array
    {
        $pending = db_one(
            "SELECT COUNT(*) AS n, AVG(TIMESTAMPDIFF(SECOND, requested_at, NOW())) AS wait
               FROM voice_profiles WHERE status = 'pending' AND deleted_at IS NULL"
        );
        $cloning = (int) db_value("SELECT COUNT(*) FROM voice_profiles WHERE status = 'cloning' AND deleted_at IS NULL");
        $ready = self::audioReadiness();

        return [
            'pending' => (int) $pending['n'],
            'avg_wait_sec' => (int) $pending['n'] > 0 ? (int) round((float) $pending['wait']) : null,
            'cloning' => $cloning,
            'readiness' => $ready,
        ];
    }

    /**
     * 동화 오디오 준비율 = 최신 본문으로 완성된 오디오 / (ElevenLabs 목소리가 있는 목소리 수 × 게시 동화 수)
     * ['voices', 'stories', 'expected', 'ready', 'percent'(null 이면 대상 없음)]
     */
    public static function audioReadiness(): array
    {
        $voices = (int) db_value(
            "SELECT COUNT(*) FROM voice_profiles WHERE deleted_at IS NULL AND provider_voice_id IS NOT NULL AND provider_voice_id <> ''"
        );
        $stories = (int) db_value("SELECT COUNT(*) FROM stories WHERE status = 'published' AND deleted_at IS NULL");
        $ready = (int) db_value(
            "SELECT COUNT(*) FROM story_audios sa
               JOIN stories s ON s.id = sa.story_id AND s.status = 'published' AND s.deleted_at IS NULL
               JOIN voice_profiles vp ON vp.id = sa.voice_profile_id AND vp.deleted_at IS NULL
                    AND vp.provider_voice_id IS NOT NULL AND vp.provider_voice_id <> ''
              WHERE sa.status = 'completed' AND (s.content_hash IS NULL OR sa.content_hash = s.content_hash)"
        );
        $expected = $voices * $stories;

        return [
            'voices' => $voices,
            'stories' => $stories,
            'expected' => $expected,
            'ready' => min($ready, $expected),
            'percent' => $expected > 0 ? round(min($ready, $expected) * 100 / $expected, 1) : null,
        ];
    }

    /** 상태별 개수(초안 제외). ['all' => n, 'pending' => n, ...] */
    public static function statusCounts(): array
    {
        $out = ['all' => 0, 'pending' => 0, 'cloning' => 0, 'processing' => 0, 'completed' => 0, 'rejected' => 0, 'failed' => 0];
        foreach (db_all("SELECT status, COUNT(*) AS n FROM voice_profiles WHERE deleted_at IS NULL AND status <> 'draft' GROUP BY status") as $r) {
            $s = (string) $r['status'];
            if (isset($out[$s])) {
                $out[$s] = (int) $r['n'];
            }
            $out['all'] += (int) $r['n'];
        }

        return $out;
    }

    /** 게시된 동화 전체를 한 목소리로 만들 때 예상 크레딧(글자 수 × 모델 크레딧 비율) */
    public static function estimatedStoryCredits(): array
    {
        $row = db_one("SELECT COUNT(*) AS n, COALESCE(SUM(char_count), 0) AS chars FROM stories WHERE status = 'published' AND deleted_at IS NULL");
        $model = (string) setting('elevenlabs.model_story', 'eleven_multilingual_v2');
        $credits = (int) round((int) $row['chars'] * Usage::elevenlabsCreditRatio($model));

        return [
            'stories' => (int) $row['n'],
            'chars' => (int) $row['chars'],
            'credits' => $credits,
            'krw' => Usage::krw($credits / 1000 * (float) setting('elevenlabs.usd_per_1k_credits', 0.30)),
        ];
    }

    // ───────────────────────── 서식 ─────────────────────────

    /** 신청 번호 #REQ-0012 */
    public static function reqId($id): string
    {
        return sprintf('#REQ-%04d', (int) $id);
    }

    /** ElevenLabs 목소리 id 가림: EL_ab12…7a */
    public static function maskVoiceId(?string $voiceId): string
    {
        $v = (string) $voiceId;
        if ($v === '') {
            return '';
        }
        if (strlen($v) <= 6) {
            return 'EL_' . $v;
        }

        return 'EL_' . substr($v, 0, 4) . '…' . substr($v, -2);
    }

    /** 초 → "14분", "2시간 5분", "3일 4시간" */
    public static function koSpan(?int $seconds): string
    {
        if ($seconds === null) {
            return '-';
        }
        $s = max(0, $seconds);
        if ($s < 60) {
            return '1분 미만';
        }
        if ($s < 3600) {
            return intdiv($s, 60) . '분';
        }
        if ($s < 86400) {
            $m = intdiv($s % 3600, 60);

            return intdiv($s, 3600) . '시간' . ($m > 0 ? ' ' . $m . '분' : '');
        }
        $h = intdiv($s % 86400, 3600);

        return intdiv($s, 86400) . '일' . ($h > 0 ? ' ' . $h . '시간' : '');
    }

    /** 밀리초 → "4분 12초" */
    public static function koDuration(int $ms): string
    {
        $s = (int) round($ms / 1000);
        $m = intdiv($s, 60);
        $sec = $s % 60;
        if ($m > 0) {
            return $m . '분' . ($sec > 0 ? ' ' . sprintf('%02d', $sec) . '초' : '');
        }

        return $sec . '초';
    }

    /** 밀리초 → "1.18초" 또는 "840ms" */
    public static function koLatency(?int $ms): string
    {
        if ($ms === null) {
            return '-';
        }

        return $ms >= 1000 ? number_format($ms / 1000, 2) . '초' : number_format($ms) . 'ms';
    }

    /** 오늘 0시 */
    public static function today(): string
    {
        return date('Y-m-d 00:00:00');
    }
}
