<?php
namespace App\Services;

use App\Core\Storage;

/**
 * 동화 생성 요청.
 *   회원이 동화와 가족 목소리를 골라 요청(requested)
 *   → 관리자가 확인하고 생성 시작(approved, story_tts 작업) 또는 반려(rejected)
 *   → 오디오가 완성되면 completed_at 을 남기고 회원에게 완성 메일
 * 생성 시작 뒤의 진행 상태는 같은 동화, 같은 목소리의 story_audios 상태로 본다(state()).
 * 회원이 요청을 거두면 canceled.
 */
class StoryRequests
{
    /** 화면에 보이는 진행 단계 */
    const STATES = ['requested', 'making', 'done', 'failed', 'rejected'];

    /** 회원 한 명이 동시에 걸어 둘 수 있는 요청(확인 대기 + 만드는 중) 기본값 */
    const DEFAULT_MAX_OPEN = 30;

    /** 요청 목록을 읽을 때 쓰는 공통 SELECT(오디오 상태 포함) */
    const SELECT = 'SELECT r.*, s.title AS story_title, s.category AS story_category, s.cover_image_path, s.updated_at AS story_updated_at,
            s.est_duration_sec, s.char_count AS story_chars, s.status AS story_status, s.deleted_at AS story_deleted_at, s.content_hash AS story_hash,
            vp.label AS voice_label, vp.icon AS voice_icon, vp.status AS voice_status, vp.deleted_at AS voice_deleted_at,
            (vp.provider_voice_id IS NOT NULL AND vp.provider_voice_id <> \'\') AS voice_has_provider,
            sa.id AS audio_id, sa.status AS audio_status, sa.file_path AS audio_file, sa.duration_ms AS audio_duration_ms,
            sa.error_message AS audio_error, sa.generated_at AS audio_generated_at, sa.content_hash AS audio_hash
          FROM story_requests r
          JOIN stories s ON s.id = r.story_id
          JOIN voice_profiles vp ON vp.id = r.voice_profile_id
          LEFT JOIN story_audios sa ON sa.story_id = r.story_id AND sa.voice_profile_id = r.voice_profile_id';

    /** 요청 행(SELECT 결과)의 진행 단계: requested | making | done | failed | rejected | canceled */
    public static function state(array $r): string
    {
        $status = (string) $r['status'];
        if ($status !== 'approved') {
            return $status;
        }
        $audio = isset($r['audio_status']) ? (string) $r['audio_status'] : '';
        if ($audio === 'completed' && !empty($r['audio_file'])) {
            return 'done';
        }
        if ($audio === 'failed') {
            return 'failed';
        }

        return 'making';
    }

    /** 진행 단계 이름(회원 화면) */
    public static function stateLabel(string $state): string
    {
        $map = [
            'requested' => '요청됨',
            'making' => '만드는 중',
            'done' => '완성',
            'failed' => '확인 중',
            'rejected' => '반려',
            'canceled' => '취소',
        ];

        return isset($map[$state]) ? $map[$state] : $state;
    }

    /** 진행 단계 이름(관리자 화면) */
    public static function adminStateLabel(string $state): string
    {
        $map = [
            'requested' => '확인 대기',
            'making' => '생성 중',
            'done' => '완성',
            'failed' => '생성 실패',
            'rejected' => '반려',
            'canceled' => '회원 취소',
        ];

        return isset($map[$state]) ? $map[$state] : $state;
    }

    /** 상태별 SQL 조건(SELECT 별칭 기준). 알 수 없는 값이면 null */
    public static function stateWhere(string $state): ?string
    {
        $done = "(sa.status = 'completed' AND sa.file_path IS NOT NULL AND sa.file_path <> '')";
        switch ($state) {
            case 'requested':
            case 'rejected':
            case 'canceled':
                return "r.status = '" . $state . "'";
            case 'done':
                return "r.status = 'approved' AND " . $done;
            case 'failed':
                return "r.status = 'approved' AND sa.status = 'failed'";
            case 'making':
                return "r.status = 'approved' AND NOT " . $done . " AND (sa.status IS NULL OR sa.status <> 'failed')";
        }

        return null;
    }

    // ───────────────────────── 회원 ─────────────────────────

    /**
     * 생성 요청. 목소리마다 요청 한 건을 만든다.
     * 이미 요청했거나 만든 동화, 아직 준비되지 않은 목소리는 건너뛴다.
     * 반환: ['created' => [요청 id], 'skipped' => [['voice' => 이름, 'reason' => 문구]]]
     * 요청할 수 없는 경우(동화 없음, 한도 초과 등)는 RuntimeException(회원에게 보여 줄 문구).
     */
    public static function create(int $userId, int $storyId, array $voiceIds): array
    {
        $story = db_one("SELECT id, title FROM stories WHERE id = ? AND status = 'published' AND deleted_at IS NULL", [$storyId]);
        if (!$story) {
            throw new \RuntimeException('지금은 요청할 수 없는 동화예요.');
        }
        $voiceIds = array_values(array_unique(array_filter(array_map('intval', $voiceIds))));
        if (!$voiceIds) {
            throw new \RuntimeException('읽어 줄 목소리를 하나 이상 골라 주세요.');
        }
        $voices = db_all(
            'SELECT id, label, status, provider_voice_id FROM voice_profiles
              WHERE user_id = ? AND deleted_at IS NULL AND id IN (' . implode(', ', array_fill(0, count($voiceIds), '?')) . ')
              ORDER BY id',
            array_merge([$userId], $voiceIds)
        );
        if (!$voices) {
            throw new \RuntimeException('고른 목소리를 찾을 수 없어요.');
        }

        $max = max(1, (int) setting('request.max_open', self::DEFAULT_MAX_OPEN));
        $open = self::openCount($userId);

        $created = [];
        $skipped = [];
        foreach ($voices as $v) {
            $vid = (int) $v['id'];
            if ($v['status'] !== 'completed' || (string) $v['provider_voice_id'] === '') {
                $skipped[] = ['voice' => (string) $v['label'], 'reason' => '목소리가 아직 준비되지 않았어요'];
                continue;
            }
            $existing = self::latestFor($userId, $storyId, $vid);
            if ($existing) {
                $state = self::state($existing);
                if ($state === 'requested' || $state === 'making' || $state === 'failed') {
                    $skipped[] = ['voice' => (string) $v['label'], 'reason' => '이미 요청했어요'];
                    continue;
                }
                if ($state === 'done') {
                    $skipped[] = ['voice' => (string) $v['label'], 'reason' => '이미 만들어져 있어요'];
                    continue;
                }
            }
            if ($open >= $max) {
                throw new \RuntimeException('확인을 기다리거나 만드는 중인 요청이 ' . $max . '건이에요. 완성된 뒤에 더 요청해 주세요.');
            }
            $created[] = db_insert('story_requests', [
                'user_id' => $userId,
                'story_id' => $storyId,
                'voice_profile_id' => $vid,
                'status' => 'requested',
            ]);
            $open++;
        }

        if ($created) {
            Jobs::log(null, 'voice_profile', (int) $voices[0]['id'], 'info', "회원이 '" . $story['title'] . "' 생성을 요청했습니다 (" . count($created) . '건).');
            self::notifyAdmin($userId, $story, $created);
            if (setting('request.auto_approve', false) && ElevenLabs::ready()) {
                self::approve($created, null);
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** 회원이 확인 대기 중인 요청을 거둔다. */
    public static function cancel(int $userId, int $requestId): void
    {
        $n = db_exec(
            "UPDATE story_requests SET status = 'canceled' WHERE id = ? AND user_id = ? AND status = 'requested'",
            [$requestId, $userId]
        );
        if (!$n) {
            throw new \RuntimeException('관리자가 이미 확인한 요청은 취소할 수 없어요.');
        }
    }

    /** 확인 대기 + 만드는 중 요청 수 */
    public static function openCount(int $userId): int
    {
        return (int) db_value(
            "SELECT COUNT(*) FROM story_requests r
               LEFT JOIN story_audios sa ON sa.story_id = r.story_id AND sa.voice_profile_id = r.voice_profile_id
              WHERE r.user_id = ? AND (r.status = 'requested' OR (r.status = 'approved' AND (sa.status IS NULL OR sa.status <> 'completed')))",
            [$userId]
        );
    }

    /** 회원의 같은 동화, 같은 목소리 요청 중 가장 최근 것(취소 제외) */
    public static function latestFor(int $userId, int $storyId, int $voiceId): ?array
    {
        return db_one(
            self::SELECT . " WHERE r.user_id = ? AND r.story_id = ? AND r.voice_profile_id = ? AND r.status <> 'canceled' ORDER BY r.id DESC LIMIT 1",
            [$userId, $storyId, $voiceId]
        );
    }

    /**
     * 회원의 요청 목록(삭제된 목소리, 취소 제외). $filter: all | making(확인 대기 + 만드는 중 + 다시 만드는 중) | done | rejected
     * 각 행에 state, state_label 을 더한다.
     */
    public static function forUser(int $userId, string $filter = 'all'): array
    {
        $where = "r.user_id = ? AND r.status <> 'canceled' AND vp.deleted_at IS NULL AND s.deleted_at IS NULL";
        if ($filter === 'done') {
            $where .= ' AND ' . self::stateWhere('done');
        } elseif ($filter === 'making') {
            $where .= " AND (r.status = 'requested' OR (" . self::stateWhere('making') . ') OR (' . self::stateWhere('failed') . '))';
        } elseif ($filter === 'rejected') {
            $where .= " AND r.status = 'rejected'";
        }
        $rows = db_all(self::SELECT . ' WHERE ' . $where . ' ORDER BY COALESCE(r.completed_at, r.created_at) DESC, r.id DESC LIMIT 300', [$userId]);
        foreach ($rows as &$r) {
            $r['state'] = self::state($r);
            $r['state_label'] = self::stateLabel($r['state']);
        }
        unset($r);

        return $rows;
    }

    /** 회원 화면의 요청 개수 ['all', 'making', 'done', 'rejected'] */
    public static function userCounts(int $userId): array
    {
        $counts = ['all' => 0, 'making' => 0, 'done' => 0, 'rejected' => 0];
        foreach (self::forUser($userId) as $r) {
            $counts['all']++;
            if ($r['state'] === 'done') {
                $counts['done']++;
            } elseif ($r['state'] === 'rejected') {
                $counts['rejected']++;
            } else {
                $counts['making']++;
            }
        }

        return $counts;
    }

    /**
     * 동화 한 편에 대한 회원 목소리별 상태(동화 상세 화면).
     * 반환: [voice_id => ['voice' => 목소리 행, 'request' => 요청 행|null, 'state' => none|requested|making|done|failed|rejected|unready]]
     */
    public static function voiceStates(int $userId, int $storyId): array
    {
        $voices = db_all(
            'SELECT id, label, icon, status, provider_voice_id FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL ORDER BY id',
            [$userId]
        );
        $out = [];
        foreach ($voices as $v) {
            $ready = $v['status'] === 'completed' && (string) $v['provider_voice_id'] !== '';
            $req = self::latestFor($userId, $storyId, (int) $v['id']);
            $state = $req ? self::state($req) : 'none';
            if (!$ready && ($state === 'none' || $state === 'rejected')) {
                $state = 'unready';
            }
            $out[(int) $v['id']] = ['voice' => $v, 'request' => $req, 'state' => $state];
        }

        return $out;
    }

    // ───────────────────────── 관리자 ─────────────────────────

    /**
     * 생성 시작. 확인 대기, 반려, 생성 실패 요청을 받아 story_tts 작업을 등록한다.
     * 반환: ['approved' => n, 'errors' => [문구]]
     */
    public static function approve(array $ids, ?int $adminId): array
    {
        if (!ElevenLabs::ready()) {
            throw new \RuntimeException('ElevenLabs API 키가 설정되지 않아 동화를 만들 수 없습니다.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $approved = 0;
        $errors = [];
        foreach ($ids as $id) {
            $r = db_one(self::SELECT . ' WHERE r.id = ?', [$id]);
            if (!$r) {
                continue;
            }
            $label = self::reqId($id) . " '" . $r['story_title'] . "'";
            $state = self::state($r);
            if (!in_array($state, ['requested', 'rejected', 'failed'], true)) {
                $errors[] = $label . ': ' . self::adminStateLabel($state) . ' 상태라 건너뜁니다.';
                continue;
            }
            if ($r['voice_deleted_at'] !== null || !(int) $r['voice_has_provider'] || $r['voice_status'] !== 'completed') {
                $errors[] = $label . ': 목소리(' . $r['voice_label'] . ')가 준비되지 않았습니다.';
                continue;
            }
            if ($r['story_status'] !== 'published' || $r['story_deleted_at'] !== null) {
                $errors[] = $label . ': 공개 중인 동화가 아닙니다.';
                continue;
            }
            db_update('story_requests', [
                'status' => 'approved',
                'processed_by' => $adminId,
                'processed_at' => now(),
                'reject_reason' => null,
                'completed_at' => null,
                'notified_at' => null,
            ], 'id = ?', [$id]);
            try {
                $queued = VoiceService::queueStories((int) $r['voice_profile_id'], [(int) $r['story_id']], false);
            } catch (\RuntimeException $e) {
                db_update('story_requests', ['status' => $r['status'], 'processed_at' => $r['processed_at']], 'id = ?', [$id]);
                $errors[] = $label . ': ' . $e->getMessage();
                continue;
            }
            Jobs::log(null, 'voice_profile', (int) $r['voice_profile_id'], 'info', $label . ' 생성 시작' . ($adminId ? '(관리자)' : '(자동)'));
            if ($queued === 0) {
                // 이미 최신 본문으로 만든 오디오가 있으면 바로 완성으로 본다.
                self::syncAudio((int) $r['story_id'], (int) $r['voice_profile_id']);
            }
            $approved++;
        }

        return ['approved' => $approved, 'errors' => $errors];
    }

    /** 반려: 사유를 남긴다(회원 내 동화 화면에 보인다). */
    public static function reject(int $id, ?int $adminId, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('반려 사유를 입력하세요.');
        }
        $r = db_one(self::SELECT . ' WHERE r.id = ?', [$id]);
        if (!$r) {
            throw new \RuntimeException('요청을 찾을 수 없습니다.');
        }
        $state = self::state($r);
        if (!in_array($state, ['requested', 'failed'], true)) {
            throw new \RuntimeException(self::adminStateLabel($state) . ' 상태의 요청은 반려할 수 없습니다.');
        }
        db_update('story_requests', [
            'status' => 'rejected',
            'reject_reason' => mb_substr($reason, 0, 255),
            'processed_by' => $adminId,
            'processed_at' => now(),
        ], 'id = ?', [$id]);
        Jobs::log(null, 'voice_profile', (int) $r['voice_profile_id'], 'warn', self::reqId($id) . " '" . $r['story_title'] . "' 반려: " . $reason);
    }

    /** 관리자 목록 상태별 개수 */
    public static function adminCounts(): array
    {
        $counts = ['requested' => 0, 'making' => 0, 'done' => 0, 'failed' => 0, 'rejected' => 0, 'canceled' => 0];
        $rows = db_all(
            "SELECT r.status, sa.status AS audio_status, (sa.file_path IS NOT NULL AND sa.file_path <> '') AS has_file, COUNT(*) AS n
               FROM story_requests r
               LEFT JOIN story_audios sa ON sa.story_id = r.story_id AND sa.voice_profile_id = r.voice_profile_id
              GROUP BY r.status, sa.status, (sa.file_path IS NOT NULL AND sa.file_path <> '')"
        );
        foreach ($rows as $r) {
            $r['audio_file'] = (int) $r['has_file'] ? '1' : '';
            $state = self::state($r);
            if (isset($counts[$state])) {
                $counts[$state] += (int) $r['n'];
            }
        }

        return $counts;
    }

    /** 확인 대기 요청 수(관리자 메뉴 배지) */
    public static function pendingCount(): int
    {
        return (int) db_value("SELECT COUNT(*) FROM story_requests WHERE status = 'requested'");
    }

    /** 화면에 쓰는 요청 번호 */
    public static function reqId($id): string
    {
        return 'SR-' . str_pad((string) (int) $id, 4, '0', STR_PAD_LEFT);
    }

    // ───────────────────────── 오디오 완성 ─────────────────────────

    /**
     * 동화 오디오 상태가 바뀐 뒤 부른다(story_tts 성공, 실패).
     * 완성된 요청에 completed_at 을 남기고, 회원의 만드는 중 요청이 모두 끝나면 완성 메일을 한 번 보낸다.
     */
    public static function syncAudio(int $storyId, int $voiceId): void
    {
        $audio = db_one('SELECT status, file_path FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$storyId, $voiceId]);
        $done = $audio && $audio['status'] === 'completed' && (string) $audio['file_path'] !== '' && Storage::exists((string) $audio['file_path']);
        if (!$done) {
            return;
        }
        $rows = db_all(
            "SELECT id, user_id FROM story_requests WHERE story_id = ? AND voice_profile_id = ? AND status = 'approved' AND completed_at IS NULL",
            [$storyId, $voiceId]
        );
        $users = [];
        foreach ($rows as $r) {
            db_exec('UPDATE story_requests SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL', [(int) $r['id']]);
            $users[(int) $r['user_id']] = true;
        }
        foreach (array_keys($users) as $userId) {
            self::notifyUser($userId);
        }
    }

    /** 만드는 중 요청이 남아 있지 않으면, 아직 알리지 않은 완성 요청을 묶어 메일 한 통을 보낸다. */
    public static function notifyUser(int $userId): void
    {
        $making = (int) db_value(
            "SELECT COUNT(*) FROM story_requests r
               LEFT JOIN story_audios sa ON sa.story_id = r.story_id AND sa.voice_profile_id = r.voice_profile_id
              WHERE r.user_id = ? AND r.status = 'approved' AND r.completed_at IS NULL AND (sa.status IS NULL OR sa.status IN ('pending', 'processing'))",
            [$userId]
        );
        if ($making > 0) {
            return;
        }
        $ready = db_all(
            "SELECT r.id, s.title, vp.label FROM story_requests r
               JOIN stories s ON s.id = r.story_id
               JOIN voice_profiles vp ON vp.id = r.voice_profile_id AND vp.deleted_at IS NULL
              WHERE r.user_id = ? AND r.status = 'approved' AND r.completed_at IS NOT NULL AND r.notified_at IS NULL
              ORDER BY r.completed_at, r.id",
            [$userId]
        );
        if (!$ready) {
            return;
        }
        db_exec(
            'UPDATE story_requests SET notified_at = NOW() WHERE id IN (' . implode(', ', array_fill(0, count($ready), '?')) . ')',
            array_map(static function ($r) {
                return (int) $r['id'];
            }, $ready)
        );

        $user = db_one('SELECT id, name, email, status, prefs FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        if (!$user || $user['status'] !== 'active' || !filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $prefs = json_decode_array($user['prefs']);
        if (array_key_exists('notify_voice_ready', $prefs) && !$prefs['notify_voice_ready']) {
            return;
        }
        $brand = (string) setting('app.brand', '르멤버');
        $lines = '';
        foreach (array_slice($ready, 0, 10) as $r) {
            $lines .= "- '" . $r['title'] . "' (" . $r['label'] . " 목소리)\n";
        }
        if (count($ready) > 10) {
            $lines .= '- 그 밖에 ' . (count($ready) - 10) . "편\n";
        }
        $first = $ready[0];
        $subject = count($ready) === 1
            ? "'" . $first['title'] . "' 동화가 완성되었어요"
            : '요청하신 동화 ' . count($ready) . '편이 완성되었어요';
        $text = $user['name'] . "님, 안녕하세요.\n\n"
            . "요청하신 동화가 완성되었어요.\n\n"
            . $lines . "\n"
            . "내 동화에서 바로 들을 수 있고, 플레이리스트에 담아 반복해서 들려줄 수도 있어요.\n"
            . absolute_url('/library') . "\n\n"
            . $brand . ' 드림';
        Jobs::enqueue('mail', [
            'to' => (string) $user['email'],
            'subject' => '[' . $brand . '] ' . $subject,
            'text' => $text,
        ], ['priority' => 6, 'ref_type' => 'user', 'ref_id' => $userId]);
    }

    /** 새 요청을 운영 메일로 알린다(설정에 관리자 메일이 있을 때). */
    private static function notifyAdmin(int $userId, array $story, array $ids): void
    {
        $adminEmail = trim((string) setting('notify.admin_email', ''));
        if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $user = db_one('SELECT name, email FROM users WHERE id = ?', [$userId]);
        $voices = db_all(
            'SELECT vp.label FROM story_requests r JOIN voice_profiles vp ON vp.id = r.voice_profile_id
              WHERE r.id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ') ORDER BY r.id',
            array_map('intval', $ids)
        );
        $text = "새 동화 생성 요청이 들어왔습니다.\n\n"
            . "동화: '" . $story['title'] . "'\n"
            . '목소리: ' . implode(', ', array_column($voices, 'label')) . "\n"
            . '회원: ' . ($user ? $user['name'] . ' / ' . mask_email((string) $user['email']) : '#' . $userId) . "\n\n"
            . '확인하기: ' . absolute_url('/admin/requests') . "\n";
        Jobs::enqueue('mail', [
            'to' => $adminEmail,
            'subject' => "[ReMemVR] 새 동화 생성 요청: '" . $story['title'] . "'",
            'text' => $text,
        ], ['priority' => 6, 'ref_type' => 'user', 'ref_id' => $userId]);
        Worker::kick();
    }
}
