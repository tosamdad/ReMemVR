<?php
namespace App\Services;

use App\Core\Storage;
use App\Core\Text;

/**
 * 가족 목소리의 상태 흐름을 다룬다.
 *   draft → pending(제출) → cloning(승인, voice_clone 작업) → completed(목소리 준비됨)
 *   자동 생성(voice.auto_clone_on_submit, 기본 켬)이면 제출하자마자 승인되어 바로 cloning 으로 간다.
 *   rejected(반려), failed(생성 실패). 삭제는 소프트 삭제 + voice_delete 작업.
 * 목소리가 준비되어도 동화를 한꺼번에 만들지 않는다. 회원이 동화마다 생성 요청을 하고
 * 관리자가 생성을 시작하면(StoryRequests::approve) queueStories 로 그 동화만 만든다.
 * processing 은 예전 일괄 생성 방식의 상태로, 지금은 쓰지 않는다(0004 에서 completed 로 옮김).
 * 컨트롤러는 본인 소유 확인을 마친 profile id 로 부른다.
 */
class VoiceService
{
    /** 승인할 수 있는 상태 */
    const APPROVABLE = ['pending', 'rejected', 'failed'];

    // ───────────────────────── 사용자 제출 ─────────────────────────

    /**
     * 사용자 제출(draft|rejected → pending). 샘플 길이, 품질 등급을 다시 계산한다.
     * 자동 생성이 켜져 있으면(기본) 바로 승인해 cloning 으로 넘기고, 아니면 관리자에게 알리고 검토를 기다린다.
     */
    public static function submit(int $profileId): void
    {
        $profile = self::find($profileId);
        if (!in_array($profile['status'], ['draft', 'rejected'], true)) {
            throw new \RuntimeException('지금은 제출할 수 없어요. (현재 상태: ' . voice_status_label((string) $profile['status']) . ')');
        }
        $summary = self::sampleSummary($profileId);
        if ($summary['count'] === 0) {
            throw new \RuntimeException('녹음된 목소리가 없어요. 먼저 녹음해 주세요.');
        }
        $minSec = (int) setting('voice.min_sample_seconds', 60);
        // 길이를 모르는 샘플(업로드 파일 등)이 섞여 있으면 길이 검사는 관리자 검토에 맡긴다.
        if ($summary['all_measured'] && $minSec > 0 && $summary['total_ms'] < $minSec * 1000) {
            throw new \RuntimeException('목소리를 최소 ' . $minSec . '초 이상 녹음해 주세요. (지금 ' . (int) floor($summary['total_ms'] / 1000) . '초)');
        }

        db_exec(
            'UPDATE voice_profiles SET status = ?, requested_at = NOW(), sample_total_ms = ?, quality_grade = ?, reject_reason = NULL WHERE id = ?',
            ['pending', $summary['total_ms'], $summary['grade'], $profileId]
        );
        Jobs::log(null, 'voice_profile', $profileId, 'info', '사용자가 목소리 생성을 요청했습니다 (샘플 ' . $summary['count'] . '개, 총 ' . Worker::koDuration($summary['total_ms']) . ').');

        // 자동 생성(기본): 관리자 검토 없이 바로 ElevenLabs 목소리를 만든다.
        // 길이를 모르는 샘플이 섞여 있으면 최소 길이를 확인할 수 없으므로 관리자 검토로 넘긴다.
        if (self::autoClone() && $summary['all_measured']) {
            try {
                self::approve($profileId, null);

                return;
            } catch (\RuntimeException $e) {
                // 자동 생성을 시작하지 못하면 관리자 검토 대기로 남긴다.
                Jobs::log(null, 'voice_profile', $profileId, 'warn', '자동 생성 시작 실패: ' . $e->getMessage());
            }
        }

        $adminEmail = trim((string) setting('notify.admin_email', ''));
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $user = db_one('SELECT name, email FROM users WHERE id = ?', [(int) $profile['user_id']]);
            $text = "새 목소리 생성 요청이 들어왔습니다.\n\n"
                . '목소리: ' . $profile['label'] . ' (#' . $profileId . ")\n"
                . '회원: ' . ($user ? $user['name'] . ' / ' . mask_email((string) $user['email']) : '#' . $profile['user_id']) . "\n"
                . '샘플: ' . $summary['count'] . '개, 총 ' . Worker::koDuration($summary['total_ms'])
                . ($summary['grade'] ? ', 품질 ' . self::gradeLabel($summary['grade']) : '') . "\n\n"
                . '검토하기: ' . absolute_url('/admin/voices/' . $profileId) . "\n";
            Jobs::enqueue('mail', [
                'to' => $adminEmail,
                'subject' => '[ReMemVR] 새 목소리 생성 요청: ' . $profile['label'],
                'text' => $text,
            ], ['priority' => 6, 'ref_type' => 'voice_profile', 'ref_id' => $profileId]);
        }
        Worker::kick();
    }

    /** 제출하면 관리자 검토 없이 바로 목소리를 만드는지(운영 설정, 기본 켬. ElevenLabs 키가 있어야 한다) */
    public static function autoClone(): bool
    {
        return (bool) setting('voice.auto_clone_on_submit', true) && ElevenLabs::ready();
    }

    // ───────────────────────── 관리자 승인, 반려 ─────────────────────────

    /**
     * 승인: 파라미터를 저장하고 cloning 으로 바꾼 뒤 voice_clone 작업을 등록한다.
     * ElevenLabs 키가 없거나, 샘플이 없거나, 승인할 수 없는 상태면 RuntimeException(한국어 메시지).
     */
    public static function approve(int $profileId, ?int $adminId, array $params = []): void
    {
        if (!ElevenLabs::ready()) {
            throw new \RuntimeException('ElevenLabs API 키가 설정되지 않아 목소리를 생성할 수 없습니다.');
        }
        $profile = self::find($profileId);
        if (!in_array($profile['status'], self::APPROVABLE, true)) {
            throw new \RuntimeException('현재 상태(' . voice_status_label((string) $profile['status']) . ')에서는 승인할 수 없습니다.');
        }
        $summary = self::sampleSummary($profileId);
        if ($summary['count'] === 0) {
            throw new \RuntimeException('녹음된 샘플이 없어 목소리를 생성할 수 없습니다.');
        }
        if ($params) {
            self::saveParams($profileId, $params);
        }

        // 반려 후 다시 녹음했거나 샘플이 바뀌었으면 예전 ElevenLabs 목소리는 버리고 새로 만든다.
        $oldVoice = (string) $profile['provider_voice_id'];
        if ($oldVoice !== '') {
            $latestSample = (string) db_value('SELECT MAX(created_at) FROM voice_samples WHERE voice_profile_id = ?', [$profileId]);
            $changed = $profile['status'] === 'rejected'
                || $profile['cloned_at'] === null
                || ($latestSample !== '' && strtotime($latestSample) > strtotime((string) $profile['cloned_at']));
            if ($changed) {
                Jobs::enqueue('voice_delete', [
                    'profile_id' => $profileId,
                    'provider_voice_id' => $oldVoice,
                    'provider_only' => true,
                ], ['priority' => 3, 'ref_type' => 'voice_profile', 'ref_id' => $profileId]);
                db_exec('UPDATE voice_profiles SET provider_voice_id = NULL, cloned_at = NULL WHERE id = ?', [$profileId]);
            }
        }

        db_exec(
            'UPDATE voice_profiles SET status = ?, processed_by = ?, processed_at = NOW(), reject_reason = NULL,
                sample_total_ms = ?, quality_grade = COALESCE(?, quality_grade) WHERE id = ?',
            ['cloning', $adminId, $summary['total_ms'], $summary['grade'], $profileId]
        );
        // 목소리 생성이 끝나면 동화, 안내 음성 작업을 다시 등록하므로 남은 대기 작업은 지운다.
        Jobs::cancelPending('voice_profile', $profileId, ['voice_clone', 'story_tts', 'voice_clips']);
        Jobs::enqueue('voice_clone', ['profile_id' => $profileId], [
            'priority' => 1, 'ref_type' => 'voice_profile', 'ref_id' => $profileId,
        ]);
        $who = $adminId ? '관리자 승인' : '자동 승인';
        Jobs::log(null, 'voice_profile', $profileId, 'info', $who . ': 목소리 생성 작업을 등록했습니다.');
        Worker::kick();
    }

    /** 반려: 사유를 남기고 사용자에게 재녹음 안내 메일을 보낸다. */
    public static function reject(int $profileId, ?int $adminId, string $reason): void
    {
        $profile = self::find($profileId);
        if ($profile['status'] === 'draft') {
            throw new \RuntimeException('아직 제출되지 않은 목소리는 반려할 수 없습니다.');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('반려 사유를 입력하세요.');
        }
        $reason = mb_substr($reason, 0, 255);
        db_exec(
            'UPDATE voice_profiles SET status = ?, reject_reason = ?, processed_by = ?, processed_at = NOW() WHERE id = ?',
            ['rejected', $reason, $adminId, $profileId]
        );
        Jobs::cancelPending('voice_profile', $profileId, ['voice_clone', 'story_tts', 'voice_clips']);
        Jobs::log(null, 'voice_profile', $profileId, 'warn', '반려: ' . $reason);

        $user = db_one('SELECT name, email, status FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $profile['user_id']]);
        if ($user && $user['status'] === 'active' && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            $brand = (string) setting('app.brand', '르멤버');
            $text = $user['name'] . "님, 안녕하세요.\n\n"
                . "보내 주신 '" . $profile['label'] . "' 목소리를 검토했는데, 아쉽게도 이번에는 목소리를 만들기 어려웠어요.\n\n"
                . '사유: ' . $reason . "\n\n"
                . "조용한 곳에서 다시 녹음해 주시면 빠르게 다시 확인할게요.\n"
                . absolute_url('/voice-lab/' . $profileId) . "\n\n"
                . $brand . ' 드림';
            Jobs::enqueue('mail', [
                'to' => (string) $user['email'],
                'subject' => '[' . $brand . "] '" . $profile['label'] . "' 목소리를 다시 녹음해 주세요",
                'text' => $text,
            ], ['priority' => 6, 'ref_type' => 'voice_profile', 'ref_id' => $profileId]);
            Worker::kick();
        }
    }

    /** 합성 파라미터 저장. 0~1 로 자르고, 빈 값은 NULL(설정 기본값 사용) */
    public static function saveParams(int $profileId, array $params): void
    {
        $data = [];
        if (array_key_exists('similarity', $params) && !array_key_exists('similarity_boost', $params)) {
            $params['similarity_boost'] = $params['similarity'];
        }
        foreach (['stability', 'similarity_boost', 'style'] as $key) {
            if (!array_key_exists($key, $params)) {
                continue;
            }
            $v = $params[$key];
            $data[$key] = ($v === null || $v === '' || !is_numeric($v)) ? null : round(max(0.0, min(1.0, (float) $v)), 2);
        }
        if (array_key_exists('speaker_boost', $params)) {
            $v = $params['speaker_boost'];
            if ($v === null || $v === '') {
                $data['speaker_boost'] = null;
            } else {
                $data['speaker_boost'] = filter_var($v, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            }
        }
        if (!$data) {
            return;
        }
        // DECIMAL 열에는 문자열로 넘겨 소수점 반올림 차이를 없앤다.
        foreach (['stability', 'similarity_boost', 'style'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $data[$key] = number_format($data[$key], 2, '.', '');
            }
        }
        db_update('voice_profiles', $data, 'id = ?', [$profileId]);
    }

    // ───────────────────────── 동화 오디오 ─────────────────────────

    /**
     * 동화 오디오 생성 작업을 등록하고 등록 개수를 돌려준다.
     * 게시된 동화만 대상이며, 최신 본문으로 만든 오디오가 있으면 $force 가 아닌 한 건너뛴다.
     * $storyIds 가 null 이면 이 목소리로 생성을 시작한 요청(approved)이 있는 동화만 대상이다.
     */
    public static function queueStories(int $profileId, ?array $storyIds = null, bool $force = false): int
    {
        $profile = self::find($profileId);
        if ((string) $profile['provider_voice_id'] === '') {
            throw new \RuntimeException('아직 ElevenLabs 목소리가 만들어지지 않아 동화 오디오를 생성할 수 없습니다.');
        }
        $sql = 'SELECT * FROM stories WHERE status = ? AND deleted_at IS NULL';
        $params = ['published'];
        if ($storyIds === null) {
            $storyIds = array_map('intval', array_column(db_all(
                "SELECT DISTINCT story_id FROM story_requests WHERE voice_profile_id = ? AND status = 'approved'",
                [$profileId]
            ), 'story_id'));
        }
        if ($storyIds !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $storyIds))));
            if (!$ids) {
                return 0;
            }
            $sql .= ' AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
            $params = array_merge($params, $ids);
        }
        $stories = db_all($sql . ' ORDER BY sort_order, id', $params);

        // 이미 대기 중인 동화 작업(같은 목소리)
        $queued = [];
        foreach (db_all('SELECT payload FROM jobs WHERE type = ? AND status = ? AND ref_type = ? AND ref_id = ?', ['story_tts', 'pending', 'voice_profile', $profileId]) as $j) {
            $p = json_decode_array($j['payload']);
            if (isset($p['story_id'])) {
                $queued[(int) $p['story_id']] = true;
            }
        }

        $count = 0;
        foreach ($stories as $story) {
            $sid = (int) $story['id'];
            $sentences = db_all('SELECT seq, content FROM story_sentences WHERE story_id = ? ORDER BY seq', [$sid]);
            if (!$sentences) {
                continue;
            }
            $hash = self::storyContentHash($story, $sentences);
            $audio = db_one('SELECT * FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$sid, $profileId]);
            if (!$force && $audio && $audio['status'] === 'completed' && $audio['content_hash'] === $hash && Storage::exists($audio['file_path'])) {
                continue;
            }
            if ($audio) {
                db_exec(
                    'UPDATE story_audios SET status = ?, error_message = NULL, attempts = 0 WHERE id = ?',
                    ['pending', (int) $audio['id']]
                );
            } else {
                db_insert('story_audios', [
                    'story_id' => $sid,
                    'voice_profile_id' => $profileId,
                    'status' => 'pending',
                    'char_count' => (int) $story['char_count'],
                ]);
            }
            // 대기 작업이 이미 있으면 그 작업이 새 상태(pending)를 보고 다시 만든다.
            if (empty($queued[$sid])) {
                Jobs::enqueue('story_tts', ['profile_id' => $profileId, 'story_id' => $sid, 'force' => $force], [
                    'priority' => 5, 'ref_type' => 'voice_profile', 'ref_id' => $profileId,
                ]);
            }
            $count++;
        }

        if ($count > 0) {
            db_exec('UPDATE voice_profiles SET batch_status = ? WHERE id = ?', ['queued', $profileId]);
            Worker::kick();
        } else {
            self::refresh($profileId);
        }

        return $count;
    }

    /**
     * 생성을 시작한 요청(approved) 기준 진행률.
     * ['total', 'completed', 'failed', 'pending', 'percent', 'processing', 'stale'(옛 본문으로 만든 완료 오디오)]
     */
    public static function progress(int $profileId): array
    {
        $rows = db_all(
            "SELECT sa.status, COUNT(DISTINCT r.story_id) AS n,
                    COUNT(DISTINCT CASE WHEN sa.status = 'completed' AND s.content_hash IS NOT NULL AND (sa.content_hash IS NULL OR sa.content_hash <> s.content_hash) THEN r.story_id END) AS stale
               FROM story_requests r
               JOIN stories s ON s.id = r.story_id AND s.status = 'published' AND s.deleted_at IS NULL
               LEFT JOIN story_audios sa ON sa.story_id = r.story_id AND sa.voice_profile_id = r.voice_profile_id
              WHERE r.voice_profile_id = ? AND r.status = 'approved'
              GROUP BY sa.status",
            [$profileId]
        );
        $by = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        $stale = 0;
        $total = 0;
        foreach ($rows as $r) {
            $key = $r['status'] === null ? 'pending' : (string) $r['status'];
            if (isset($by[$key])) {
                $by[$key] += (int) $r['n'];
            }
            $total += (int) $r['n'];
            $stale += (int) $r['stale'];
        }

        return [
            'total' => $total,
            'completed' => $by['completed'],
            'failed' => $by['failed'],
            'pending' => max(0, $total - $by['completed'] - $by['failed']),
            'percent' => $total > 0 ? (int) floor($by['completed'] * 100 / $total) : 0,
            'processing' => $by['processing'],
            'stale' => $stale,
        ];
    }

    /**
     * 작업과 오디오 상태로 batch_status(이 목소리의 동화 생성 진행)를 다시 계산해 저장한다.
     * 목소리 상태(status)는 바꾸지 않는다. 예전 방식의 processing 만 completed 로 정리한다.
     * 반환: ['status', 'batch_status', 'progress' => progress()]
     */
    public static function refresh(int $profileId): array
    {
        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$profileId]);
        if (!$profile) {
            return [];
        }
        $status = (string) $profile['status'];
        $batch = (string) $profile['batch_status'];
        if ($profile['deleted_at'] !== null || (string) $profile['provider_voice_id'] === '') {
            return ['status' => $status, 'batch_status' => $batch, 'progress' => self::progress($profileId)];
        }

        $counts = ['pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach (db_all(
            'SELECT sa.status, COUNT(*) AS n FROM story_audios sa
             JOIN stories s ON s.id = sa.story_id AND s.status = ? AND s.deleted_at IS NULL
             WHERE sa.voice_profile_id = ? GROUP BY sa.status',
            ['published', $profileId]
        ) as $r) {
            $counts[(string) $r['status']] = (int) $r['n'];
        }
        $runningJobs = (int) db_value(
            'SELECT COUNT(*) FROM jobs WHERE ref_type = ? AND ref_id = ? AND type = ? AND status = ?',
            ['voice_profile', $profileId, 'story_tts', 'running']
        );
        $pendingJobs = (int) db_value(
            'SELECT COUNT(*) FROM jobs WHERE ref_type = ? AND ref_id = ? AND type = ? AND status = ?',
            ['voice_profile', $profileId, 'story_tts', 'pending']
        );
        // 합성 중인 오디오가 있거나, 대기 오디오를 처리할 작업이 남아 있으면 진행 중이다.
        $active = $counts['processing'] > 0 || ($counts['pending'] > 0 && $pendingJobs + $runningJobs > 0);
        $touched = $counts['pending'] + $counts['processing'] + $counts['completed'] + $counts['failed'];

        $newStatus = $status === 'processing' ? 'completed' : $status;
        $newBatch = $batch;
        $finishedNow = false;
        if ($active) {
            $newBatch = ($counts['processing'] > 0 || $runningJobs > 0 || $batch === 'running') ? 'running' : 'queued';
        } elseif ($touched > 0 && in_array($batch, ['queued', 'running'], true)) {
            $incomplete = $counts['failed'] + $counts['pending'];
            $newBatch = $incomplete === 0 ? 'done' : ($counts['completed'] === 0 ? 'failed' : 'partial');
            $finishedNow = true;
        } elseif ($touched === 0 && in_array($batch, ['queued', 'running'], true)) {
            $newBatch = 'done';
        }

        if ($newStatus !== $status || $newBatch !== $batch) {
            $sets = ['status' => $newStatus, 'batch_status' => $newBatch];
            if ($finishedNow) {
                $sets['batch_done_at'] = now();
            }
            db_update('voice_profiles', $sets, 'id = ?', [$profileId]);
            if ($finishedNow) {
                self::onBatchFinished($profile, $counts, $newBatch);
            }
        }

        return ['status' => $newStatus, 'batch_status' => $newBatch, 'progress' => self::progress($profileId)];
    }

    // ───────────────────────── 삭제, 테스트 ─────────────────────────

    /** 소프트 삭제 + 대기 작업 취소 + voice_delete 작업(ElevenLabs 목소리와 로컬 음성 파일 삭제) */
    public static function delete(int $profileId): void
    {
        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$profileId]);
        if (!$profile || $profile['deleted_at'] !== null) {
            return;
        }
        db_exec('UPDATE voice_profiles SET deleted_at = NOW() WHERE id = ?', [$profileId]);
        Jobs::cancelPending('voice_profile', $profileId, ['voice_clone', 'story_tts', 'voice_clips']);
        Jobs::enqueue('voice_delete', [
            'profile_id' => $profileId,
            'provider_voice_id' => (string) $profile['provider_voice_id'],
        ], ['priority' => 3, 'ref_type' => 'voice_profile', 'ref_id' => $profileId]);
        Jobs::log(null, 'voice_profile', $profileId, 'warn', '목소리를 삭제했습니다. 음성 파일과 ElevenLabs 목소리 삭제 작업을 등록했습니다.');
        Worker::kick();
    }

    /**
     * 관리자 테스트 재생(동기 합성). 지금 저장된 파라미터로 문장을 합성해 voice_clips(kind test)에 둔다.
     * 반환: ['ok' => true, 'audio_url', 'clip_id', 'duration_ms', 'ms'] 또는 ['ok' => false, 'error']
     */
    public static function testSpeak(int $profileId, string $text): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return ['ok' => false, 'error' => '테스트할 문장을 입력하세요.'];
        }
        if (mb_strlen($text) > 300) {
            return ['ok' => false, 'error' => '테스트 문장은 300자 이하로 입력하세요.'];
        }
        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ? AND deleted_at IS NULL', [$profileId]);
        if (!$profile) {
            return ['ok' => false, 'error' => '목소리를 찾을 수 없습니다.'];
        }
        if ((string) $profile['provider_voice_id'] === '') {
            return ['ok' => false, 'error' => '아직 ElevenLabs 목소리가 생성되지 않았습니다.'];
        }
        if (!ElevenLabs::ready()) {
            return ['ok' => false, 'error' => 'ElevenLabs API 키가 설정되지 않았습니다.'];
        }

        $hash = self::textHash($text);
        $row = db_one('SELECT * FROM voice_clips WHERE voice_profile_id = ? AND text_hash = ?', [$profileId, $hash]);
        $clipId = $row ? (int) $row['id'] : db_insert('voice_clips', [
            'voice_profile_id' => $profileId,
            'kind' => 'test',
            'text' => $text,
            'text_hash' => $hash,
            'status' => 'pending',
        ]);
        $res = ElevenLabs::synthesize((string) $profile['provider_voice_id'], $text, [
            'model_id' => (string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5'),
            'voice_settings' => self::voiceSettings($profile),
            'with_timestamps' => false,
            'output_format' => (string) setting('elevenlabs.output_format', 'mp3_44100_128'),
            'usage' => [
                'purpose' => 'clip_tts',
                'user_id' => (int) $profile['user_id'],
                'ref_type' => 'voice_clip',
                'ref_id' => $clipId,
            ],
        ]);
        if (empty($res['ok']) || !isset($res['audio']) || (string) $res['audio'] === '') {
            $err = !empty($res['error']) ? (string) $res['error'] : '음성 합성에 실패했습니다.';
            if (!$row || $row['status'] !== 'completed') {
                db_exec('UPDATE voice_clips SET status = ?, error_message = ? WHERE id = ?', ['failed', mb_substr($err, 0, 500), $clipId]);
            }

            return ['ok' => false, 'error' => $err];
        }
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', isset($res['ext']) ? (string) $res['ext'] : ''));
        if ($ext === '') {
            $ext = Storage::extForMime(isset($res['mime']) ? (string) $res['mime'] : '', 'mp3');
        }
        $rel = 'clips/' . $profileId . '/' . Storage::randomName($ext);
        Storage::put($rel, (string) $res['audio']);
        if ($row && (string) $row['file_path'] !== '') {
            Storage::delete($row['file_path']);
        }
        $duration = isset($res['duration_ms']) && (int) $res['duration_ms'] > 0 ? (int) $res['duration_ms'] : Worker::guessDurationMs((string) $res['audio'], $ext);
        db_update('voice_clips', [
            'status' => 'completed',
            'file_path' => $rel,
            'duration_ms' => $duration,
            'error_message' => null,
        ], 'id = ?', [$clipId]);
        self::pruneTestClips($profileId);

        return [
            'ok' => true,
            'audio_url' => url('/admin/media/clip/' . $clipId),
            'clip_id' => $clipId,
            'duration_ms' => $duration,
            'ms' => isset($res['ms']) ? (int) $res['ms'] : null,
        ];
    }

    // ───────────────────────── 공용 도우미 ─────────────────────────

    /**
     * ElevenLabs voice_settings. 프로필 값이 NULL 이면 설정 기본값을 쓴다.
     * 질문 답변 합성(QuestionService)에서도 같은 값을 쓴다.
     */
    public static function voiceSettings(array $profile): array
    {
        $pick = static function ($value, $default) {
            return ($value === null || $value === '') ? (float) $default : (float) $value;
        };

        return [
            'stability' => $pick(isset($profile['stability']) ? $profile['stability'] : null, setting('elevenlabs.default_stability', 0.65)),
            'similarity_boost' => $pick(isset($profile['similarity_boost']) ? $profile['similarity_boost'] : null, setting('elevenlabs.default_similarity', 0.80)),
            'style' => $pick(isset($profile['style']) ? $profile['style'] : null, setting('elevenlabs.default_style', 0.0)),
            'use_speaker_boost' => isset($profile['speaker_boost']) && $profile['speaker_boost'] !== null
                ? (bool) (int) $profile['speaker_boost']
                : (bool) setting('elevenlabs.speaker_boost', true),
        ];
    }

    /** 동화의 현재 내용 해시. stories.content_hash 가 비어 있으면 문장으로 계산해 저장한다. */
    public static function storyContentHash(array $story, ?array $sentences = null): string
    {
        $hash = isset($story['content_hash']) ? (string) $story['content_hash'] : '';
        if ($hash !== '') {
            return $hash;
        }
        if ($sentences === null) {
            $sentences = db_all('SELECT seq, content FROM story_sentences WHERE story_id = ? ORDER BY seq', [(int) $story['id']]);
        }
        $hash = Text::hashSentences(array_map(static function ($s) {
            return (string) $s['content'];
        }, $sentences));
        db_exec('UPDATE stories SET content_hash = ? WHERE id = ? AND content_hash IS NULL', [$hash, (int) $story['id']]);

        return $hash;
    }

    /** voice_clips.text_hash 계산 */
    public static function textHash(string $text): string
    {
        return hash('sha256', trim($text));
    }

    /**
     * 샘플 요약: ['count', 'total_ms', 'grade'(good|fair|poor|null), 'all_measured'(모든 샘플 길이를 아는지)]
     * 품질 등급은 샘플 길이로 가중 평균한다(길이를 모르면 같은 비중).
     */
    public static function sampleSummary(int $profileId): array
    {
        $rows = db_all('SELECT duration_ms, quality_grade FROM voice_samples WHERE voice_profile_id = ?', [$profileId]);
        $score = ['good' => 3, 'fair' => 2, 'poor' => 1];
        $total = 0;
        $allMeasured = true;
        $weighted = 0.0;
        $weights = 0.0;
        foreach ($rows as $r) {
            $ms = (int) $r['duration_ms'];
            if ($ms <= 0) {
                $allMeasured = false;
            }
            $total += max(0, $ms);
            $g = (string) $r['quality_grade'];
            if (isset($score[$g])) {
                $w = $ms > 0 ? $ms / 1000 : 1.0;
                $weighted += $score[$g] * $w;
                $weights += $w;
            }
        }
        $grade = null;
        if ($weights > 0) {
            $avg = $weighted / $weights;
            $grade = $avg >= 2.5 ? 'good' : ($avg >= 1.75 ? 'fair' : 'poor');
        }

        return ['count' => count($rows), 'total_ms' => $total, 'grade' => $grade, 'all_measured' => $allMeasured && count($rows) > 0];
    }

    public static function gradeLabel(?string $grade): string
    {
        $map = ['good' => '좋음', 'fair' => '보통', 'poor' => '나쁨'];

        return isset($map[(string) $grade]) ? $map[(string) $grade] : '-';
    }

    private static function find(int $profileId): array
    {
        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ? AND deleted_at IS NULL', [$profileId]);
        if (!$profile) {
            throw new \RuntimeException('목소리를 찾을 수 없습니다.');
        }

        return $profile;
    }

    /** 이 목소리의 동화 생성 작업이 모두 끝났을 때 처리 콘솔에 남긴다. */
    private static function onBatchFinished(array $profile, array $counts, string $batch): void
    {
        $pid = (int) $profile['id'];
        $done = $counts['completed'];
        $all = $counts['completed'] + $counts['failed'] + $counts['pending'];
        $level = $batch === 'done' ? 'info' : ($batch === 'partial' ? 'warn' : 'error');
        Jobs::log(null, 'voice_profile', $pid, $level, '동화 오디오 생성 작업 마침: ' . $all . '편 중 ' . $done . '편 완성'
            . ($all - $done > 0 ? ', ' . ($all - $done) . '편 실패' : ''));
    }

    /**
     * 목소리가 처음 준비되었을 때 회원에게 메일로 알린다(알림을 끄지 않은 경우).
     * 이제 동화 목록에서 동화를 골라 이 목소리로 생성을 요청할 수 있다고 안내한다.
     */
    public static function notifyReady(int $profileId): void
    {
        $profile = db_one('SELECT id, user_id, label FROM voice_profiles WHERE id = ? AND deleted_at IS NULL', [$profileId]);
        if (!$profile) {
            return;
        }
        $user = db_one('SELECT id, name, email, status, prefs FROM users WHERE id = ? AND deleted_at IS NULL', [(int) $profile['user_id']]);
        if (!$user || $user['status'] !== 'active' || !filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $prefs = json_decode_array($user['prefs']);
        if (array_key_exists('notify_voice_ready', $prefs) && !$prefs['notify_voice_ready']) {
            return;
        }
        $brand = (string) setting('app.brand', '르멤버');
        $text = $user['name'] . "님, 안녕하세요.\n\n"
            . "'" . $profile['label'] . "' 목소리가 준비되었어요.\n"
            . '이제 동화 책장에서 듣고 싶은 동화를 고르고, ' . $profile['label'] . " 목소리로 만들어 달라고 요청해 보세요.\n"
            . "동화가 완성되면 다시 알려 드릴게요.\n\n"
            . absolute_url('/stories') . "\n\n"
            . $brand . ' 드림';
        Jobs::enqueue('mail', [
            'to' => (string) $user['email'],
            'subject' => '[' . $brand . "] '" . $profile['label'] . "' 목소리가 준비되었어요",
            'text' => $text,
        ], ['priority' => 6, 'ref_type' => 'voice_profile', 'ref_id' => $profileId]);
    }

    /** 테스트 음성은 목소리마다 최근 20개만 남긴다. */
    private static function pruneTestClips(int $profileId): void
    {
        $old = db_all(
            'SELECT id, file_path FROM voice_clips WHERE voice_profile_id = ? AND kind = ? ORDER BY updated_at DESC, id DESC LIMIT 1000 OFFSET 20',
            [$profileId, 'test']
        );
        foreach ($old as $r) {
            Storage::delete($r['file_path']);
            db_exec('DELETE FROM voice_clips WHERE id = ?', [(int) $r['id']]);
        }
    }
}
