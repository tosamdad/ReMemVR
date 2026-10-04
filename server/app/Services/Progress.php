<?php
namespace App\Services;

use App\Core\Auth;
use App\Core\Text;

/**
 * 아이 학습 현황(경험치, 레벨, 기간별 통계). 홈과 학습 리포트가 함께 쓴다.
 * 통계 범위는 지금 선택된 자녀의 재생 기록이다. 자녀가 한 명뿐이면 자녀가 지정되지 않은 기록(child_id NULL)도 포함한다.
 * 계산식(순수 함수)은 DB 없이 테스트할 수 있게 따로 둔다(server/tests/ProgressTest.php).
 */
class Progress
{
    const XP_PER_COMPLETED = 20;
    const XP_PER_ANSWER = 5;
    const XP_PER_MINUTE = 1;

    /** 레벨별 누적 경험치 시작점. 10 레벨 이후는 레벨마다 800 씩 늘어난다. */
    const LEVELS = [1 => 0, 2 => 60, 3 => 150, 4 => 300, 5 => 500, 6 => 800, 7 => 1200, 8 => 1700, 9 => 2300, 10 => 3000];
    const LEVEL_STEP_AFTER = 800;

    /** 레벨 칭호. 6 레벨부터는 모두 우주 탐험가 */
    const TITLES = [1 => '새싹 탐험가', 2 => '꼬마 탐험가', 3 => '용감한 탐험가', 4 => '별빛 탐험가', 5 => '은하수 탐험가', 6 => '우주 탐험가'];

    const WEEKDAYS = ['월', '화', '수', '목', '금', '토', '일'];

    // ───────────────────────── 계산식(순수 함수) ─────────────────────────

    /** 경험치 = 완독 20 + 답을 들은 질문 5 + 들은 시간 1분당 1 */
    public static function xp(int $completed, int $answered, int $listenedMs): int
    {
        return max(0, $completed) * self::XP_PER_COMPLETED
            + max(0, $answered) * self::XP_PER_ANSWER
            + intdiv(max(0, $listenedMs), 60000) * self::XP_PER_MINUTE;
    }

    /** 레벨 시작 경험치 */
    public static function threshold(int $level): int
    {
        if ($level <= 1) {
            return 0;
        }
        $last = max(array_keys(self::LEVELS));
        if ($level <= $last) {
            return self::LEVELS[$level];
        }

        return self::LEVELS[$last] + ($level - $last) * self::LEVEL_STEP_AFTER;
    }

    public static function title(int $level): string
    {
        $level = max(1, $level);
        $max = max(array_keys(self::TITLES));

        return self::TITLES[min($level, $max)];
    }

    /**
     * 경험치로 레벨과 다음 레벨까지 진행률을 구한다.
     * ['level', 'title', 'xp', 'floor'(이번 레벨 시작), 'next'(다음 레벨 시작), 'into'(이번 레벨에서 쌓은 양), 'span'(이번 레벨 구간), 'percent']
     */
    public static function level(int $xp): array
    {
        $xp = max(0, $xp);
        $level = 1;
        while (self::threshold($level + 1) <= $xp) {
            $level++;
        }
        $floor = self::threshold($level);
        $next = self::threshold($level + 1);
        $span = max(1, $next - $floor);
        $into = $xp - $floor;

        return [
            'level' => $level,
            'title' => self::title($level),
            'xp' => $xp,
            'floor' => $floor,
            'next' => $next,
            'into' => $into,
            'span' => $span,
            'percent' => (int) min(100, floor($into * 100 / $span)),
        ];
    }

    /** 그 주 월요일 0시(Asia/Seoul) */
    public static function weekStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $day = (int) $now->format('N'); // 1(월) ~ 7(일)

        return $now->setTime(0, 0, 0)->modify('-' . ($day - 1) . ' days');
    }

    /**
     * 통계 범위 SQL 조각. 별칭 ps(play_sessions)를 기준으로 쓴다.
     * 자녀가 한 명뿐이면 child_id 가 비어 있는 기록도 그 아이 것으로 본다.
     * @return array [string $sql, array $params]
     */
    public static function scopeSql(int $userId, ?int $childId, int $childCount): array
    {
        if ($childId === null) {
            return ['ps.user_id = ?', [$userId]];
        }
        if ($childCount <= 1) {
            return ['ps.user_id = ? AND (ps.child_id = ? OR ps.child_id IS NULL)', [$userId, $childId]];
        }

        return ['ps.user_id = ? AND ps.child_id = ?', [$userId, $childId]];
    }

    /**
     * 오늘의 추천 순서: 아직 다 듣지 않은 동화 먼저, 저녁 7시 이후에는 '취침 전' 먼저, 그 안에서는 날짜마다 바뀌는 고정 순서.
     * $stories 는 id, category 를 가진 행 목록. $completedIds 는 다 들은 동화 id 목록.
     */
    public static function recommend(array $stories, array $completedIds, int $hour, string $date, int $limit = 8): array
    {
        $done = array_flip(array_map('intval', $completedIds));
        $bedtime = $hour >= 19 || $hour < 5;
        $keyed = [];
        foreach ($stories as $s) {
            $id = (int) $s['id'];
            $keyed[] = [
                isset($done[$id]) ? 1 : 0,
                ($bedtime && isset($s['category']) && $s['category'] === '취침 전') ? 0 : 1,
                crc32($date . ':' . $id),
                $id,
                $s,
            ];
        }
        usort($keyed, static function ($a, $b) {
            for ($i = 0; $i < 4; $i++) {
                if ($a[$i] !== $b[$i]) {
                    return $a[$i] < $b[$i] ? -1 : 1;
                }
            }

            return 0;
        });
        $out = [];
        foreach (array_slice($keyed, 0, $limit) as $k) {
            $out[] = $k[4];
        }

        return $out;
    }

    /**
     * 들은 문장의 키워드 행으로 단어 묶음을 만든다.
     * $rows: [['keywords' => '밤하늘, 달님', 'done' => 0|1], ...] 최근에 들은 순서
     * @return array ['heard' => 들은 단어, 'mastered' => 끝까지 들은 동화의 단어, 'recent' => 최근 단어 순서]
     */
    public static function collectWords(array $rows): array
    {
        $heard = [];
        $mastered = [];
        foreach ($rows as $r) {
            foreach (Text::keywords(isset($r['keywords']) ? (string) $r['keywords'] : '') as $w) {
                if (!isset($heard[$w])) {
                    $heard[$w] = true;
                }
                if (!empty($r['done'])) {
                    $mastered[$w] = true;
                }
            }
        }
        $list = array_map('strval', array_keys($heard));

        return ['heard' => $list, 'mastered' => array_map('strval', array_keys($mastered)), 'recent' => $list];
    }

    /**
     * 날짜별 값 맵(Y-m-d => 값)을 $from 부터 $days 일 배열로 채운다.
     * @return array [['date' => 'Y-m-d', 'value' => n, 'label' => '월' 또는 '10/4', 'today' => bool], ...]
     */
    public static function fillDays(array $byDate, \DateTimeImmutable $from, int $days, \DateTimeImmutable $today, bool $weekdayLabels): array
    {
        $out = [];
        $todayKey = $today->format('Y-m-d');
        for ($i = 0; $i < $days; $i++) {
            $d = $from->modify('+' . $i . ' days');
            $key = $d->format('Y-m-d');
            $out[] = [
                'date' => $key,
                'value' => isset($byDate[$key]) ? (int) $byDate[$key] : 0,
                'label' => $weekdayLabels ? self::WEEKDAYS[(int) $d->format('N') - 1] : $d->format('n/j'),
                'today' => $key === $todayKey,
                'future' => $key > $todayKey,
            ];
        }

        return $out;
    }

    /** 이전 기간 대비 증감 표시(+2, -1, 0) */
    public static function delta(int $now, int $before): string
    {
        $d = $now - $before;

        return ($d > 0 ? '+' : '') . $d;
    }

    // ───────────────────────── DB 조회 ─────────────────────────

    /** 지금 로그인한 회원과 선택된 자녀 기준 범위 */
    public static function currentScope(): array
    {
        $userId = (int) Auth::id();
        $child = Auth::child();

        return self::scopeSql($userId, $child ? (int) $child['id'] : null, count(Auth::children()));
    }

    /** 누적 합계: 완독 수, 답을 들은 질문 수, 들은 시간 */
    public static function totals(array $scope): array
    {
        list($where, $params) = $scope;
        $row = db_one(
            'SELECT COALESCE(SUM(ps.completed), 0) AS completed, COALESCE(SUM(ps.listened_ms), 0) AS listened_ms, COUNT(*) AS sessions
             FROM play_sessions ps WHERE ' . $where,
            $params
        );
        $answered = (int) db_value(
            "SELECT COUNT(*) FROM interactions i JOIN play_sessions ps ON ps.id = i.play_session_id
             WHERE $where AND i.mode = 'answer' AND i.question_text IS NOT NULL AND i.question_text <> ''",
            $params
        );

        return [
            'completed' => (int) $row['completed'],
            'listened_ms' => (int) $row['listened_ms'],
            'sessions' => (int) $row['sessions'],
            'answered' => $answered,
        ];
    }

    /** 누적 경험치와 레벨 */
    public static function levelFor(array $scope): array
    {
        $t = self::totals($scope);

        return self::level(self::xp($t['completed'], $t['answered'], $t['listened_ms'])) + ['totals' => $t];
    }

    /** 기간 안에 끝까지 들은 동화 수(같은 동화는 한 권) */
    public static function booksBetween(array $scope, string $from, string $to): int
    {
        list($where, $params) = $scope;

        return (int) db_value(
            "SELECT COUNT(DISTINCT ps.story_id) FROM play_sessions ps
             WHERE $where AND ps.completed = 1 AND ps.completed_at >= ? AND ps.completed_at < ?",
            array_merge($params, [$from, $to])
        );
    }

    /** 이번 주(월~일) 읽은 책 수 */
    public static function weekBooks(array $scope, ?\DateTimeImmutable $now = null): int
    {
        $start = self::weekStart($now ?: new \DateTimeImmutable('now'));

        return self::booksBetween($scope, $start->format('Y-m-d H:i:s'), $start->modify('+7 days')->format('Y-m-d H:i:s'));
    }

    /**
     * 기간 통계(학습 리포트).
     * @return array books, books_prev, started, completed_sessions, stories_started, completion_rate(null 가능),
     *               listened_ms, daily(분), daily_books, words, questions
     */
    public static function period(array $scope, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $prevFrom, bool $weekdayLabels, \DateTimeImmutable $today): array
    {
        list($where, $params) = $scope;
        $f = $from->format('Y-m-d H:i:s');
        $t = $to->format('Y-m-d H:i:s');
        $pf = $prevFrom->format('Y-m-d H:i:s');
        $days = (int) round(($to->getTimestamp() - $from->getTimestamp()) / 86400);

        $books = self::booksBetween($scope, $f, $t);
        $booksPrev = self::booksBetween($scope, $pf, $f);

        $agg = db_one(
            "SELECT COUNT(*) AS started, COALESCE(SUM(ps.completed), 0) AS completed, COUNT(DISTINCT ps.story_id) AS stories,
                    COALESCE(SUM(ps.listened_ms), 0) AS listened_ms
             FROM play_sessions ps WHERE $where AND ps.started_at >= ? AND ps.started_at < ?",
            array_merge($params, [$f, $t])
        );
        $started = (int) $agg['started'];
        $completedSessions = (int) $agg['completed'];

        // 하루별 들은 시간(분). 재생 기록은 시작한 날에 넣는다.
        $minutes = [];
        foreach (db_all(
            "SELECT DATE(ps.started_at) AS d, SUM(ps.listened_ms) AS ms FROM play_sessions ps
             WHERE $where AND ps.started_at >= ? AND ps.started_at < ? GROUP BY DATE(ps.started_at)",
            array_merge($params, [$f, $t])
        ) as $r) {
            $minutes[(string) $r['d']] = (int) round(((int) $r['ms']) / 60000);
        }
        // 하루별 다 들은 동화 수
        $booksByDay = [];
        foreach (db_all(
            "SELECT DATE(ps.completed_at) AS d, COUNT(DISTINCT ps.story_id) AS n FROM play_sessions ps
             WHERE $where AND ps.completed = 1 AND ps.completed_at >= ? AND ps.completed_at < ? GROUP BY DATE(ps.completed_at)",
            array_merge($params, [$f, $t])
        ) as $r) {
            $booksByDay[(string) $r['d']] = (int) $r['n'];
        }

        return [
            'books' => $books,
            'books_prev' => $booksPrev,
            'started' => $started,
            'completed_sessions' => $completedSessions,
            'stories_started' => (int) $agg['stories'],
            'completion_rate' => $started > 0 ? (int) round($completedSessions * 100 / $started) : null,
            'listened_ms' => (int) $agg['listened_ms'],
            'daily' => self::fillDays($minutes, $from, $days, $today, $weekdayLabels),
            'daily_books' => self::fillDays($booksByDay, $from, $days, $today, $weekdayLabels),
            'words' => self::words($scope, $f, $t),
            'questions' => self::questions($scope, $f, $t, 10),
        ];
    }

    /** 기간 안에 들은 문장의 키워드(다 들은 동화는 전체 문장, 아니면 마지막으로 들은 문장까지) */
    public static function words(array $scope, string $from, string $to): array
    {
        list($where, $params) = $scope;
        $rows = db_all(
            "SELECT MAX(ss.keywords) AS keywords, MAX(ps.completed) AS done, MAX(ps.started_at) AS heard_at, MAX(ss.seq) AS seq
             FROM play_sessions ps
             JOIN story_sentences ss ON ss.story_id = ps.story_id AND (ps.completed = 1 OR ss.seq <= COALESCE(ps.last_sentence_seq, 0))
             WHERE $where AND ps.started_at >= ? AND ps.started_at < ? AND ss.keywords IS NOT NULL AND ss.keywords <> ''
             GROUP BY ss.id
             ORDER BY heard_at DESC, seq DESC
             LIMIT 2000",
            array_merge($params, [$from, $to])
        );

        return self::collectWords($rows);
    }

    /** 아이가 물어본 질문과 답(답을 들은 것만) */
    public static function questions(array $scope, string $from, string $to, int $limit = 10): array
    {
        list($where, $params) = $scope;

        return db_all(
            "SELECT i.id, i.question_text, i.answer_text, i.created_at, s.id AS story_id, s.title AS story_title
             FROM interactions i
             JOIN play_sessions ps ON ps.id = i.play_session_id
             JOIN stories s ON s.id = ps.story_id
             WHERE $where AND i.mode = 'answer' AND i.question_text IS NOT NULL AND i.question_text <> ''
               AND i.created_at >= ? AND i.created_at < ?
             ORDER BY i.id DESC LIMIT ?",
            array_merge($params, [$from, $to, $limit])
        );
    }

    /** 다 들은 적이 있는 동화 id 목록 */
    public static function completedStoryIds(array $scope): array
    {
        list($where, $params) = $scope;

        return array_map('intval', array_column(db_all(
            "SELECT DISTINCT ps.story_id FROM play_sessions ps WHERE $where AND ps.completed = 1",
            $params
        ), 'story_id'));
    }

    /**
     * 동화별 이어 듣기 상태. 동화마다 가장 최근 재생 기록이 끝나지 않았을 때만 넣는다(최근 순서).
     * $withPositionOnly 가 true 면 조금이라도 들은 기록만 넣는다.
     * @return array story_id => ['session_id', 'position_ms', 'sentence_seq', 'voice'(id 또는 'device'), 'voice_profile_id']
     */
    public static function resumeStates(array $scope, bool $withPositionOnly = true): array
    {
        list($where, $params) = $scope;
        $rows = db_all(
            "SELECT ps.id, ps.story_id, ps.voice_profile_id, ps.audio_source, ps.last_position_ms, ps.last_sentence_seq, ps.completed
             FROM play_sessions ps
             WHERE $where
             ORDER BY ps.updated_at DESC, ps.id DESC LIMIT 300",
            $params
        );
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $sid = (int) $r['story_id'];
            if (isset($seen[$sid])) {
                continue;
            }
            $seen[$sid] = true;
            $started = (int) $r['last_position_ms'] > 0 || (int) $r['last_sentence_seq'] > 1;
            if ((int) $r['completed'] === 1 || ($withPositionOnly && !$started)) {
                continue;
            }
            $out[$sid] = [
                'session_id' => (int) $r['id'],
                'position_ms' => (int) $r['last_position_ms'],
                'sentence_seq' => (int) $r['last_sentence_seq'],
                'voice_profile_id' => $r['voice_profile_id'] !== null ? (int) $r['voice_profile_id'] : null,
                'voice' => ($r['audio_source'] === 'device' || $r['voice_profile_id'] === null) ? 'device' : (string) (int) $r['voice_profile_id'],
            ];
        }

        return $out;
    }

    /**
     * 동화 길이(초). 예상 낭독 시간 → 오디오 길이 → 글자 수 추정 순서. 모르면 0.
     */
    public static function durationSec(array $story, ?int $audioMs = null): int
    {
        if (!empty($story['est_duration_sec'])) {
            return (int) $story['est_duration_sec'];
        }
        if ($audioMs !== null && $audioMs > 0) {
            return (int) round($audioMs / 1000);
        }
        $chars = isset($story['char_count']) ? (int) $story['char_count'] : 0;

        // 동화 낭독은 대략 초당 5글자(공백 포함)
        return $chars > 0 ? (int) ceil($chars / 5) : 0;
    }

    /** "8분" 같은 짧은 길이 표시. 모르면 빈 문자열 */
    public static function durationLabel(int $sec): string
    {
        if ($sec <= 0) {
            return '';
        }

        return max(1, (int) round($sec / 60)) . '분';
    }

    /** 동화별 내 목소리 오디오 중 가장 짧은 길이(ms). story_id => ms */
    public static function audioDurations(int $userId, array $storyIds): array
    {
        $storyIds = array_values(array_unique(array_map('intval', $storyIds)));
        if (!$storyIds) {
            return [];
        }
        $in = implode(',', array_fill(0, count($storyIds), '?'));
        $out = [];
        foreach (db_all(
            "SELECT sa.story_id, MIN(sa.duration_ms) AS ms FROM story_audios sa
             JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
             WHERE vp.user_id = ? AND vp.deleted_at IS NULL AND sa.status = 'completed' AND sa.duration_ms > 0 AND sa.story_id IN ($in)
             GROUP BY sa.story_id",
            array_merge([$userId], $storyIds)
        ) as $r) {
            $out[(int) $r['story_id']] = (int) $r['ms'];
        }

        return $out;
    }
}
