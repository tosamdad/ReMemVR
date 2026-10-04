<?php
namespace App\Services;

/**
 * 관리자 "회원 및 통계" 화면의 집계.
 * 모든 숫자는 DB 의 실제 기록(users, voice_profiles, play_sessions, interactions)에서 계산하며,
 * 기록이 없으면 null 을 돌려 화면이 빈 상태 안내를 보여 주게 한다.
 */
class MemberStats
{
    /** AI 목소리가 만들어진 상태(동화 오디오를 만드는 중이어도 이미 들을 수 있다) */
    const VOICE_READY = ['completed', 'processing'];
    /** 아직 준비 중인 목소리 */
    const VOICE_WAITING = ['pending', 'cloning'];
    /** 취침 시간대(시작 시각 기준, 19:00 ~ 22:59) */
    const BEDTIME_FROM = 19;
    const BEDTIME_TO = 23;
    /** 아이 집중이 흐트러지기 시작하는 응답 대기 시간(안정 권역 판단 기준) */
    const ATTENTION_LIMIT_MS = 1800;
    /** 히트맵 구간 수 */
    const SEGMENTS = 10;

    const VOICE_FILTERS = ['mom' => '엄마', 'dad' => '아빠', 'grand' => '할머니·할아버지', 'none' => '미등록'];

    // ───────────────────────── 상단 지표 ─────────────────────────

    /**
     * 상단 4개 카드.
     * @return array ['members', 'voice', 'completion', 'bargein', 'latency']
     */
    public static function kpis(int $days = 30): array
    {
        $readyIn = self::inList(self::VOICE_READY);
        $total = (int) db_value("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL AND status <> 'withdrawn'");
        $households = (int) db_value(
            "SELECT COUNT(DISTINCT vp.user_id) FROM voice_profiles vp JOIN users u ON u.id = vp.user_id
              WHERE vp.deleted_at IS NULL AND vp.status IN ($readyIn) AND u.deleted_at IS NULL AND u.status <> 'withdrawn'",
            self::VOICE_READY
        );

        $cur = self::completionWindow($days, 0);
        $prev = self::completionWindow($days * 2, $days);
        $rate = self::ratio($cur['done'], $cur['started']);
        $prevRate = self::ratio($prev['done'], $prev['started']);

        $answered = (int) db_value(
            "SELECT COUNT(*) FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
              WHERE i.mode = 'answer' AND ps.started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        $quota = db_one(
            "SELECT COUNT(*) AS attempts, COALESCE(SUM(CASE WHEN answer_text IS NOT NULL AND answer_text <> '' THEN 1 ELSE 0 END), 0) AS handled
               FROM interactions WHERE mode = 'quota' AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        $avgLatency = db_value(
            "SELECT AVG(latency_ms) FROM interactions WHERE mode = 'answer' AND latency_ms IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );
        $target = max(1, (int) setting('cost.latency_target_ms', 1500));

        return [
            'days' => $days,
            'members' => $total,
            'households' => $households,
            'voice_rate' => self::ratio($households, $total),
            'completion' => [
                'started' => $cur['started'],
                'rate' => $rate,
                'delta' => ($rate !== null && $prevRate !== null) ? round($rate - $prevRate, 1) : null,
                'bedtime_started' => $cur['bed_started'],
                'bedtime_rate' => self::ratio($cur['bed_done'], $cur['bed_started']),
            ],
            'bargein' => [
                'sessions' => $cur['started'],
                'answered' => $answered,
                'per_session' => $cur['started'] > 0 ? round($answered / $cur['started'], 2) : null,
                'max_questions' => (int) setting('qa.max_questions', 3),
                'quota_attempts' => (int) $quota['attempts'],
                'quota_handled' => (int) $quota['handled'],
                'quota_rate' => self::ratio((int) $quota['handled'], (int) $quota['attempts']),
            ],
            'latency' => [
                'avg_ms' => $avgLatency === null ? null : (int) round((float) $avgLatency),
                'target_ms' => $target,
                'achieved' => $avgLatency !== null && (float) $avgLatency < $target,
                'model' => (string) setting('elevenlabs.model_answer', ''),
                'model_label' => self::modelLabel((string) setting('elevenlabs.model_answer', '')),
            ],
        ];
    }

    /** 기간 [NOW - $fromDays, NOW - $toDays) 안에 시작한 재생의 완독 집계 */
    private static function completionWindow(int $fromDays, int $toDays): array
    {
        $row = db_one(
            'SELECT COUNT(*) AS started, COALESCE(SUM(completed), 0) AS done,
                    COALESCE(SUM(CASE WHEN HOUR(started_at) >= ? AND HOUR(started_at) < ? THEN 1 ELSE 0 END), 0) AS bed_started,
                    COALESCE(SUM(CASE WHEN HOUR(started_at) >= ? AND HOUR(started_at) < ? AND completed = 1 THEN 1 ELSE 0 END), 0) AS bed_done
               FROM play_sessions
              WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY) AND started_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [self::BEDTIME_FROM, self::BEDTIME_TO, self::BEDTIME_FROM, self::BEDTIME_TO, $fromDays, $toDays]
        );

        return [
            'started' => (int) $row['started'],
            'done' => (int) $row['done'],
            'bed_started' => (int) $row['bed_started'],
            'bed_done' => (int) $row['bed_done'],
        ];
    }

    /** ElevenLabs 모델 id → 화면 표시 이름 */
    public static function modelLabel(string $model): string
    {
        $map = [
            'eleven_flash_v2_5' => 'ElevenLabs Flash v2.5',
            'eleven_turbo_v2_5' => 'ElevenLabs Turbo v2.5',
            'eleven_multilingual_v2' => 'ElevenLabs Multilingual v2',
            'eleven_v3' => 'ElevenLabs v3',
        ];

        return isset($map[$model]) ? $map[$model] : ($model !== '' ? $model : '미설정');
    }

    // ───────────────────────── 구간별 히트맵 ─────────────────────────

    /**
     * 질문이 많은 동화 상위 $limit 편의 10구간 히트맵.
     * 구간은 문장 순번(seq)을 10등분한다. 각 구간에 질문 비율(share, 0~1)과 시각(있을 때)을 담는다.
     */
    public static function heatmaps(int $limit = 3): array
    {
        $top = db_all(
            'SELECT ps.story_id, COUNT(*) AS n FROM interactions i
               JOIN play_sessions ps ON ps.id = i.play_session_id
               JOIN stories s ON s.id = ps.story_id
              WHERE s.deleted_at IS NULL
              GROUP BY ps.story_id ORDER BY n DESC, ps.story_id LIMIT ?',
            [$limit]
        );
        $out = [];
        foreach ($top as $rank => $t) {
            $story = db_one('SELECT id, title, code, sort_order, est_duration_sec, content_hash FROM stories WHERE id = ?', [(int) $t['story_id']]);
            if (!$story) {
                continue;
            }
            $sentences = db_all('SELECT seq, content, keywords, ref_start_ms, ref_end_ms FROM story_sentences WHERE story_id = ? ORDER BY seq', [(int) $story['id']]);
            $counts = [];
            foreach (db_all(
                'SELECT i.sentence_seq AS seq, COUNT(*) AS n FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
                  WHERE ps.story_id = ? AND i.sentence_seq IS NOT NULL GROUP BY i.sentence_seq',
                [(int) $story['id']]
            ) as $r) {
                $counts[(int) $r['seq']] = (int) $r['n'];
            }
            $timings = self::sentenceTimes($story, $sentences);
            $out[] = self::buildHeatmap($story, $sentences, $counts, $timings) + ['rank' => $rank + 1, 'total' => (int) $t['n']];
        }

        return $out;
    }

    /**
     * 히트맵 계산(순수 함수, 테스트 대상).
     * $counts: [seq => 질문 수], $timings: [seq => [start_ms, end_ms]]
     */
    public static function buildHeatmap(array $story, array $sentences, array $counts, array $timings): array
    {
        $n = 0;
        foreach ($sentences as $s) {
            $n = max($n, (int) $s['seq']);
        }
        if ($n === 0 && $counts) {
            $n = max(array_keys($counts));
        }
        $segments = [];
        for ($k = 0; $k < self::SEGMENTS; $k++) {
            $segments[$k] = ['index' => $k + 1, 'count' => 0, 'share' => 0.0, 'first_seq' => null, 'last_seq' => null, 'start_ms' => null, 'end_ms' => null, 'label' => ''];
        }
        $bySeq = [];
        foreach ($sentences as $s) {
            $bySeq[(int) $s['seq']] = $s;
        }
        for ($seq = 1; $seq <= $n; $seq++) {
            $k = self::segmentOf($seq, $n);
            if ($segments[$k]['first_seq'] === null) {
                $segments[$k]['first_seq'] = $seq;
                if (isset($bySeq[$seq])) {
                    $kw = \App\Core\Text::keywords((string) $bySeq[$seq]['keywords']);
                    $segments[$k]['label'] = $kw ? $kw[0] : str_limit((string) $bySeq[$seq]['content'], 8);
                }
                if (isset($timings[$seq])) {
                    $segments[$k]['start_ms'] = $timings[$seq][0];
                }
            }
            $segments[$k]['last_seq'] = $seq;
            if (isset($timings[$seq]) && $timings[$seq][1] !== null) {
                $segments[$k]['end_ms'] = $timings[$seq][1];
            }
        }
        $total = 0;
        foreach ($counts as $seq => $c) {
            if ($seq < 1 || $n < 1) {
                continue;
            }
            $k = self::segmentOf(min((int) $seq, $n), $n);
            $segments[$k]['count'] += (int) $c;
            $total += (int) $c;
        }
        $peak = null;
        $max = 0;
        foreach ($segments as $k => $seg) {
            $segments[$k]['share'] = $total > 0 ? $seg['count'] / $total : 0.0;
            if ($seg['count'] > $max) {
                $max = $seg['count'];
                $peak = $k;
            }
        }
        $endMs = null;
        for ($k = self::SEGMENTS - 1; $k >= 0; $k--) {
            if ($segments[$k]['end_ms'] !== null) {
                $endMs = $segments[$k]['end_ms'];
                break;
            }
        }

        return [
            'story' => $story,
            'sentence_count' => $n,
            'segments' => array_values($segments),
            'counted' => $total,
            'max_count' => $max,
            'peak' => $peak,
            'has_times' => (bool) $timings,
            'end_ms' => $endMs,
        ];
    }

    /** 문장 순번 → 0부터 시작하는 구간 번호 */
    public static function segmentOf(int $seq, int $total): int
    {
        if ($total <= 0) {
            return 0;
        }

        return (int) min(self::SEGMENTS - 1, max(0, intdiv(($seq - 1) * self::SEGMENTS, $total)));
    }

    /**
     * 문장별 [시작, 끝] 밀리초. CMS 기준 타임코드(ref_start_ms)가 있으면 그것을,
     * 없으면 가장 최근 완료된 동화 오디오의 sentence_timings 를 쓴다. 둘 다 없으면 빈 배열.
     */
    public static function sentenceTimes(array $story, array $sentences): array
    {
        $out = [];
        foreach ($sentences as $s) {
            if ($s['ref_start_ms'] !== null) {
                $out[(int) $s['seq']] = [(int) $s['ref_start_ms'], $s['ref_end_ms'] !== null ? (int) $s['ref_end_ms'] : null];
            }
        }
        if ($out) {
            return $out;
        }
        $audio = db_one(
            "SELECT sentence_timings FROM story_audios WHERE story_id = ? AND status = 'completed' AND sentence_timings IS NOT NULL
              ORDER BY (content_hash <=> ?) DESC, id DESC LIMIT 1",
            [(int) $story['id'], isset($story['content_hash']) ? $story['content_hash'] : null]
        );
        if ($audio) {
            $t = json_decode_array($audio['sentence_timings']);
            foreach (isset($t['sentences']) && is_array($t['sentences']) ? $t['sentences'] : [] as $row) {
                if (isset($row['seq'], $row['start'])) {
                    $out[(int) $row['seq']] = [(int) $row['start'], isset($row['end']) ? (int) $row['end'] : null];
                }
            }
        }

        return $out;
    }

    // ───────────────────────── 피크 장면 ─────────────────────────

    /** 질문이 가장 많이 나온 문장 상위 $limit 개(대표 질문, 주요 감정 포함) */
    public static function peakSentences(int $limit = 2): array
    {
        $rows = db_all(
            'SELECT ps.story_id, i.sentence_seq, COUNT(*) AS n, MAX(i.id) AS last_id FROM interactions i
               JOIN play_sessions ps ON ps.id = i.play_session_id
               JOIN stories s ON s.id = ps.story_id
              WHERE i.sentence_seq IS NOT NULL AND s.deleted_at IS NULL
              GROUP BY ps.story_id, i.sentence_seq ORDER BY n DESC, last_id DESC LIMIT ?',
            [$limit]
        );
        $out = [];
        foreach ($rows as $r) {
            $storyId = (int) $r['story_id'];
            $seq = (int) $r['sentence_seq'];
            $story = db_one('SELECT id, title, sort_order, content_hash FROM stories WHERE id = ?', [$storyId]);
            $sentence = db_one('SELECT seq, content, keywords, ref_start_ms, ref_end_ms FROM story_sentences WHERE story_id = ? AND seq = ?', [$storyId, $seq]);
            $question = db_value(
                "SELECT i.question_text FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
                  WHERE ps.story_id = ? AND i.sentence_seq = ? AND i.question_text IS NOT NULL AND i.question_text <> ''
                  GROUP BY i.question_text ORDER BY COUNT(*) DESC, MAX(i.id) DESC LIMIT 1",
                [$storyId, $seq]
            );
            $emotions = db_all(
                "SELECT i.emotion, COUNT(*) AS n FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
                  WHERE ps.story_id = ? AND i.sentence_seq = ? AND i.emotion IS NOT NULL AND i.emotion <> ''
                  GROUP BY i.emotion ORDER BY n DESC",
                [$storyId, $seq]
            );
            $emoTotal = 0;
            foreach ($emotions as $e) {
                $emoTotal += (int) $e['n'];
            }
            $time = null;
            if ($sentence && $sentence['ref_start_ms'] !== null) {
                $time = (int) $sentence['ref_start_ms'];
            } elseif ($story) {
                $times = self::sentenceTimes($story, []);
                $time = isset($times[$seq]) ? $times[$seq][0] : null;
            }
            if ($time === null) {
                $avg = db_value(
                    'SELECT AVG(i.position_ms) FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
                      WHERE ps.story_id = ? AND i.sentence_seq = ? AND i.position_ms > 0',
                    [$storyId, $seq]
                );
                $time = $avg === null ? null : (int) round((float) $avg);
            }
            $keywords = $sentence ? \App\Core\Text::keywords((string) $sentence['keywords']) : [];
            $out[] = [
                'story_id' => $storyId,
                'story_title' => $story ? (string) $story['title'] : '',
                'seq' => $seq,
                'count' => (int) $r['n'],
                'sentence' => $sentence ? (string) $sentence['content'] : '',
                'keyword' => $keywords ? $keywords[0] : '',
                'time_ms' => $time,
                'question' => $question === null ? '' : (string) $question,
                'emotion' => $emotions ? (string) $emotions[0]['emotion'] : '',
                'emotion_pct' => $emotions && $emoTotal > 0 ? (int) round((int) $emotions[0]['n'] * 100 / $emoTotal) : null,
            ];
        }

        return $out;
    }

    // ───────────────────────── 응답 지연 ─────────────────────────

    /** 최근 답변 $limit 건의 종단 지연 분포와 단계별 평균 */
    public static function latency(int $limit = 200): array
    {
        $rows = db_all(
            "SELECT latency_ms, llm_ms, tts_ms FROM interactions WHERE mode = 'answer' AND latency_ms IS NOT NULL ORDER BY id DESC LIMIT ?",
            [$limit]
        );

        return self::latencyFromRows($rows);
    }

    /** 지연 기록 배열로 분포를 계산한다(순수 함수, 테스트 대상). */
    public static function latencyFromRows(array $rows): array
    {
        $lat = [];
        $llm = [];
        $tts = [];
        $mid = [];
        foreach ($rows as $r) {
            $l = (int) $r['latency_ms'];
            $lat[] = $l;
            if ($r['llm_ms'] !== null) {
                $llm[] = (int) $r['llm_ms'];
            }
            if ($r['tts_ms'] !== null) {
                $tts[] = (int) $r['tts_ms'];
            }
            // 2단계(질문 한도, 안전 필터, 저장)는 전체에서 두 외부 API 시간을 뺀 나머지다.
            $mid[] = max(0, $l - (int) $r['llm_ms'] - (int) $r['tts_ms']);
        }
        $avg = static function (array $v) {
            return $v ? (int) round(array_sum($v) / count($v)) : null;
        };
        $p95 = self::percentile($lat, 95);
        $limit = self::ATTENTION_LIMIT_MS;

        return [
            'samples' => count($lat),
            'p50' => self::percentile($lat, 50),
            'p95' => $p95,
            'p99' => self::percentile($lat, 99),
            'avg' => $avg($lat),
            'llm_avg' => $avg($llm),
            'mid_avg' => $avg($mid),
            'tts_avg' => $avg($tts),
            'limit_ms' => $limit,
            'stable' => $p95 !== null && $p95 < $limit,
            'margin_pct' => $p95 !== null ? (int) round(($limit - $p95) * 100 / $limit) : null,
        ];
    }

    /** 백분위수(가장 가까운 순위 방식). 값이 없으면 null */
    public static function percentile(array $values, float $p): ?int
    {
        if (!$values) {
            return null;
        }
        $values = array_map('intval', array_values($values));
        sort($values);
        $rank = (int) ceil(($p / 100) * count($values));
        $rank = max(1, min(count($values), $rank));

        return $values[$rank - 1];
    }

    // ───────────────────────── 회원 목록 ─────────────────────────

    /**
     * 회원 목록 한 페이지.
     * $filters: q(이름, 이메일, 자녀 이름, #RM-번호), voice(mom|dad|grand|none)
     * @return array ['rows' => [...], 'total' => n, 'page' => n, 'pages' => n, 'per_page' => n]
     */
    public static function members(array $filters, int $page = 1, int $perPage = 20): array
    {
        list($where, $params) = self::memberWhere($filters);
        $total = (int) db_value('SELECT COUNT(*) FROM users u WHERE ' . $where, $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $users = db_all(
            'SELECT u.* FROM users u WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );

        return [
            'rows' => self::decorate($users),
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
        ];
    }

    /** CSV 내보내기용: 조건에 맞는 모든 회원을 $chunk 명씩 넘겨준다. */
    public static function eachMember(array $filters, callable $fn, int $chunk = 300): void
    {
        list($where, $params) = self::memberWhere($filters);
        $offset = 0;
        do {
            $users = db_all(
                'SELECT u.* FROM users u WHERE ' . $where . ' ORDER BY u.id DESC LIMIT ? OFFSET ?',
                array_merge($params, [$chunk, $offset])
            );
            foreach (self::decorate($users) as $row) {
                $fn($row);
            }
            $offset += $chunk;
        } while (count($users) === $chunk);
    }

    /** 검색 조건 SQL(자리표시자만 쓴다) */
    public static function memberWhere(array $filters): array
    {
        $where = ['1 = 1'];
        $params = [];
        $q = isset($filters['q']) ? trim((string) $filters['q']) : '';
        if ($q !== '') {
            $id = self::parseMemberId($q);
            if ($id !== null) {
                $where[] = 'u.id = ?';
                $params[] = $id;
            } else {
                $like = '%' . addcslashes($q, '%_\\') . '%';
                $where[] = '(u.name LIKE ? OR u.email LIKE ? OR EXISTS (SELECT 1 FROM children c WHERE c.user_id = u.id AND c.name LIKE ?))';
                array_push($params, $like, $like, $like);
            }
        }
        $voice = isset($filters['voice']) ? (string) $filters['voice'] : '';
        $ready = self::inList(self::VOICE_READY);
        if ($voice === 'none') {
            $where[] = "NOT EXISTS (SELECT 1 FROM voice_profiles vp WHERE vp.user_id = u.id AND vp.deleted_at IS NULL AND vp.status IN ($ready))";
            $params = array_merge($params, self::VOICE_READY);
        } elseif ($voice === 'mom' || $voice === 'dad' || $voice === 'grand') {
            $labels = $voice === 'mom' ? ['%엄마%'] : ($voice === 'dad' ? ['%아빠%'] : ['%할머니%', '%할아버지%']);
            $like = implode(' OR ', array_fill(0, count($labels), 'vp.label LIKE ?'));
            $where[] = "EXISTS (SELECT 1 FROM voice_profiles vp WHERE vp.user_id = u.id AND vp.deleted_at IS NULL AND vp.status IN ($ready) AND ($like))";
            $params = array_merge($params, self::VOICE_READY, $labels);
        }

        return [implode(' AND ', $where), $params];
    }

    /** "#RM-0012", "RM12", "12" → 12. 회원 번호 형식이 아니면 null */
    public static function parseMemberId(string $q): ?int
    {
        if (preg_match('/^#?\s*(?:RM\s*-?\s*)?0*(\d{1,10})$/i', trim($q), $m) && (stripos($q, 'rm') !== false || strpos($q, '#') === 0 || ctype_digit(trim($q)))) {
            return (int) $m[1];
        }

        return null;
    }

    public static function memberCode(int $id): string
    {
        return '#RM-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    }

    /** 회원 행에 자녀, 목소리, 재생 집계를 붙인다(쿼리 수는 페이지 크기와 무관). */
    public static function decorate(array $users): array
    {
        if (!$users) {
            return [];
        }
        $ids = array_map(static function ($u) {
            return (int) $u['id'];
        }, $users);
        $in = self::inList($ids);

        $children = [];
        foreach (db_all("SELECT * FROM children WHERE user_id IN ($in) ORDER BY sort_order, id", $ids) as $c) {
            $children[(int) $c['user_id']][] = $c;
        }
        $voices = [];
        foreach (db_all("SELECT id, user_id, label, icon, status FROM voice_profiles WHERE user_id IN ($in) AND deleted_at IS NULL AND status <> 'draft' ORDER BY id", $ids) as $v) {
            $voices[(int) $v['user_id']][] = $v;
        }
        $plays = [];
        foreach (db_all(
            "SELECT user_id, COUNT(*) AS sessions, COALESCE(SUM(listened_ms), 0) AS listened, COALESCE(SUM(completed), 0) AS done, MAX(started_at) AS last_at
               FROM play_sessions WHERE user_id IN ($in) GROUP BY user_id",
            $ids
        ) as $p) {
            $plays[(int) $p['user_id']] = $p;
        }
        $asks = [];
        foreach (db_all(
            "SELECT ps.user_id, COUNT(*) AS n, COALESCE(SUM(CASE WHEN i.reviewed_at IS NULL THEN 1 ELSE 0 END), 0) AS unreviewed
               FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
              WHERE ps.user_id IN ($in) GROUP BY ps.user_id",
            $ids
        ) as $a) {
            $asks[(int) $a['user_id']] = $a;
        }
        $hours = [];
        foreach (db_all("SELECT user_id, HOUR(started_at) AS h, COUNT(*) AS n FROM play_sessions WHERE user_id IN ($in) GROUP BY user_id, HOUR(started_at)", $ids) as $h) {
            $hours[(int) $h['user_id']][(int) $h['h']] = (int) $h['n'];
        }

        $out = [];
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $p = isset($plays[$uid]) ? $plays[$uid] : null;
            $sessions = $p ? (int) $p['sessions'] : 0;
            $questions = isset($asks[$uid]) ? (int) $asks[$uid]['n'] : 0;
            $userVoices = isset($voices[$uid]) ? $voices[$uid] : [];
            $hour = self::primaryHour(isset($hours[$uid]) ? $hours[$uid] : []);
            $out[] = [
                'user' => $u,
                'code' => self::memberCode($uid),
                'children' => isset($children[$uid]) ? $children[$uid] : [],
                'voices' => $userVoices,
                'sessions' => $sessions,
                'completed' => $p ? (int) $p['done'] : 0,
                'last_play_at' => $p ? $p['last_at'] : null,
                'listened_ms' => $p ? (int) $p['listened'] : 0,
                'listened_hours' => $p ? round((int) $p['listened'] / 3600000, 1) : 0.0,
                'questions' => $questions,
                'unreviewed' => isset($asks[$uid]) ? (int) $asks[$uid]['unreviewed'] : 0,
                'per_story' => $sessions > 0 ? round($questions / $sessions, 1) : null,
                'hour' => $hour,
                'hour_text' => $hour === null ? '' : self::hourRange($hour),
                'status' => self::memberStatus($u, $userVoices),
            ];
        }

        return $out;
    }

    /** 가장 많이 재생을 시작한 시(0~23). 같으면 늦은 시각(취침 전 이용이 많은 서비스 특성)을 고른다. */
    public static function primaryHour(array $hourCounts): ?int
    {
        $best = null;
        $bestN = 0;
        foreach ($hourCounts as $h => $n) {
            $n = (int) $n;
            if ($n > $bestN || ($n === $bestN && $n > 0 && (int) $h > (int) $best)) {
                $best = (int) $h;
                $bestN = $n;
            }
        }

        return $best;
    }

    /** 시각대 이름 */
    public static function hourLabel(int $h): string
    {
        $h = (($h % 24) + 24) % 24;
        if ($h >= 5 && $h <= 10) {
            return '아침';
        }
        if ($h >= 13 && $h <= 15) {
            return '낮잠';
        }
        if ($h >= 11 && $h <= 17) {
            return '낮';
        }
        if ($h >= 18 && $h <= 19) {
            return '저녁';
        }
        if ($h >= 20 && $h <= 21) {
            return '취침 전';
        }

        return '심야';
    }

    /** "21:00 ~ 22:00 (취침 전)" */
    public static function hourRange(int $h): string
    {
        return sprintf('%02d:00 ~ %02d:00 (%s)', $h, ($h + 1) % 24, self::hourLabel($h));
    }

    /**
     * 회원 상태. key: active | voice_waiting | voice_needed | blocked | withdrawn
     * @return array ['key', 'label']
     */
    public static function memberStatus(array $user, array $voices): array
    {
        if ($user['status'] === 'withdrawn' || !empty($user['deleted_at'])) {
            return ['key' => 'withdrawn', 'label' => '탈퇴'];
        }
        if ($user['status'] === 'blocked') {
            return ['key' => 'blocked', 'label' => '이용 정지'];
        }
        $waiting = false;
        foreach ($voices as $v) {
            if (in_array($v['status'], self::VOICE_READY, true)) {
                return ['key' => 'active', 'label' => '정상 이용중'];
            }
            if (in_array($v['status'], self::VOICE_WAITING, true)) {
                $waiting = true;
            }
        }

        return $waiting ? ['key' => 'voice_waiting', 'label' => '목소리 준비 중'] : ['key' => 'voice_needed', 'label' => '목소리 녹음 권장'];
    }

    /** 자녀 표시: "박시우 (5세, 남아)" */
    public static function childText(array $child): string
    {
        $parts = [];
        $age = child_age($child);
        if ($age !== null) {
            $parts[] = $age . '세';
        }
        if (!empty($child['gender'])) {
            $parts[] = $child['gender'] === 'girl' ? '여아' : ($child['gender'] === 'boy' ? '남아' : '');
        }
        $parts = array_filter($parts);

        return (string) $child['name'] . ($parts ? ' (' . implode(', ', $parts) . ')' : '');
    }

    // ───────────────────────── 대화 로그 ─────────────────────────

    /**
     * 회원의 최근 질문 기록(대화 로그 창).
     * @return array ['user', 'child', 'sessions' => [[session, story, voice_label, items => [...]]], 'ids' => 미검토 id, 'latest' => 최근 재생]
     */
    public static function memberLog(int $userId, int $limit = 30): ?array
    {
        $user = db_one('SELECT * FROM users WHERE id = ?', [$userId]);
        if (!$user) {
            return null;
        }
        $rows = db_all(
            'SELECT i.*, ps.story_id, ps.voice_profile_id, ps.audio_source, ps.child_id, ps.started_at AS session_started, ps.question_count, ps.fallback_count
               FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
              WHERE ps.user_id = ? ORDER BY i.id DESC LIMIT ?',
            [$userId, $limit]
        );
        $rows = array_reverse($rows);
        $latest = db_one(
            'SELECT ps.*, s.title AS story_title, vp.label AS voice_label FROM play_sessions ps
               JOIN stories s ON s.id = ps.story_id
               LEFT JOIN voice_profiles vp ON vp.id = ps.voice_profile_id
              WHERE ps.user_id = ? ORDER BY ps.started_at DESC, ps.id DESC LIMIT 1',
            [$userId]
        );

        $childId = null;
        if ($rows) {
            $last = end($rows);
            $childId = $last['child_id'] !== null ? (int) $last['child_id'] : null;
        } elseif ($latest && $latest['child_id'] !== null) {
            $childId = (int) $latest['child_id'];
        }
        $child = $childId ? db_one('SELECT * FROM children WHERE id = ? AND user_id = ?', [$childId, $userId]) : null;
        if (!$child) {
            $child = db_one('SELECT * FROM children WHERE user_id = ? ORDER BY sort_order, id LIMIT 1', [$userId]);
        }

        $stories = [];
        $voices = [];
        $sentences = [];
        $groups = [];
        $ids = [];
        $globalMax = (int) setting('qa.max_questions', 3);
        foreach ($rows as $r) {
            $sid = (int) $r['story_id'];
            if (!isset($stories[$sid])) {
                $stories[$sid] = db_one('SELECT id, title, max_questions FROM stories WHERE id = ?', [$sid]);
            }
            $vid = $r['voice_profile_id'] !== null ? (int) $r['voice_profile_id'] : 0;
            if ($vid && !isset($voices[$vid])) {
                $voices[$vid] = db_one('SELECT id, label FROM voice_profiles WHERE id = ?', [$vid]);
            }
            $seq = $r['sentence_seq'] !== null ? (int) $r['sentence_seq'] : 0;
            $key = $sid . ':' . $seq;
            if ($seq > 0 && !array_key_exists($key, $sentences)) {
                $sentences[$key] = db_value('SELECT content FROM story_sentences WHERE story_id = ? AND seq = ?', [$sid, $seq]);
            }
            $psid = (int) $r['play_session_id'];
            if (!isset($groups[$psid])) {
                $story = $stories[$sid];
                $max = $story && $story['max_questions'] !== null ? (int) $story['max_questions'] : $globalMax;
                $groups[$psid] = [
                    'session_id' => $psid,
                    'started_at' => $r['session_started'],
                    'story_title' => $story ? (string) $story['title'] : '(삭제된 동화)',
                    'voice_label' => $vid && $voices[$vid] ? (string) $voices[$vid]['label'] : '기기 음성',
                    'question_count' => (int) $r['question_count'],
                    'max_questions' => $max,
                    'items' => [],
                ];
            }
            $groups[$psid]['items'][] = [
                'id' => (int) $r['id'],
                'mode' => (string) $r['mode'],
                'created_at' => $r['created_at'],
                'position_ms' => $r['position_ms'] !== null ? (int) $r['position_ms'] : null,
                'sentence' => $seq > 0 && $sentences[$key] !== null ? (string) $sentences[$key] : '',
                'seq' => $seq,
                'question_text' => (string) $r['question_text'],
                'has_question_audio' => (string) $r['question_audio_path'] !== '',
                'answer_text' => (string) $r['answer_text'],
                'has_answer_audio' => (string) $r['answer_audio_path'] !== '',
                'emotion' => (string) $r['emotion'],
                'latency_ms' => $r['latency_ms'] !== null ? (int) $r['latency_ms'] : null,
                'error_message' => (string) $r['error_message'],
                'reviewed' => $r['reviewed_at'] !== null,
            ];
            if ($r['reviewed_at'] === null) {
                $ids[] = (int) $r['id'];
            }
        }

        return [
            'user' => $user,
            'child' => $child,
            'latest' => $latest,
            'sessions' => array_values($groups),
            'ids' => $ids,
            'count' => count($rows),
        ];
    }

    /** 처리 방식 이름 */
    public static function modeLabel(string $mode): string
    {
        $map = [
            'answer' => 'AI 답변',
            'quota' => '질문 한도 초과 대체 응답',
            'fallback' => '대체 응답',
            'error' => '오류 안내',
            'disabled' => '질문 꺼짐 안내',
            'budget' => '일일 예산 도달 안내',
        ];

        return isset($map[$mode]) ? $map[$mode] : $mode;
    }

    // ───────────────────────── 내부 ─────────────────────────

    private static function ratio(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    /** IN (...) 자리표시자 목록 */
    private static function inList(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
