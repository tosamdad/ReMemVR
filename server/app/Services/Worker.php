<?php
namespace App\Services;

use App\Core\Mailer;
use App\Core\Storage;
use App\Core\Text;

/**
 * 백그라운드 작업 처리기.
 *
 * cafe24 에는 cron 이 없으므로 세 가지 방법으로 돈다.
 *  1) 화면 요청이 끝날 때 App::run 이 Worker::maybeKick() 을 부른다(대기 작업이 있으면 60초에 한 번 자기 호출).
 *  2) 관리자 화면이 45초마다 /admin/api/worker/tick 을 부른다.
 *  3) /_ops/worker.php 가 50초 동안 처리하고, 남은 작업이 있으면 자신을 다시 부른다.
 * GET_LOCK 으로 동시에 하나만 돌게 한다.
 */
class Worker
{
    const LOCK_NAME = 'rememvr_worker';

    /** 동화 오디오 생성은 오래 걸리므로 남은 시간이 이보다 적으면 새로 시작하지 않는다(초). */
    const STORY_TTS_MIN_SECONDS = 25;

    /** 자기 호출 간격(초) */
    const KICK_INTERVAL = 60;

    /** 미리듣기 문장(목소리별로 한 번 만들어 둔다) */
    const PREVIEW_LINE = '안녕! 오늘도 재미있는 이야기를 들려줄게.';

    /** @var bool 처리 중에는 자기 호출을 하지 않는다(어차피 잠금 때문에 바로 끝난다). */
    private static $running = false;

    /** @var float 이번 실행의 마감 시각(microtime) */
    private static $deadline = 0.0;

    /**
     * 처리할 작업을 시간 예산 안에서 처리한다.
     * 반환: ['processed' => 처리한 작업 수, 'remaining' => 지금 처리 가능한 대기 작업 수, 'locked' => 다른 처리기가 실행 중이었는지, 'elapsed_ms']
     */
    public static function run(int $maxSeconds = 20): array
    {
        $started = microtime(true);
        $maxSeconds = max(1, $maxSeconds);
        if (PHP_SAPI !== 'cli') {
            // 동화 한 편 합성이 예산을 넘겨도 끊기지 않도록 여유를 둔다.
            @set_time_limit($maxSeconds + 150);
        }

        $locked = (int) db_value('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]);
        if ($locked !== 1) {
            return [
                'processed' => 0,
                'remaining' => Jobs::dueCount(),
                'locked' => true,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        $processed = 0;
        self::$running = true;
        self::$deadline = $started + $maxSeconds;
        try {
            self::touchRun();
            Jobs::reclaimStale();
            self::recoverStuckAudio();
            self::housekeeping();

            while (true) {
                $left = self::$deadline - microtime(true);
                if ($left < 1) {
                    break;
                }
                // 동화 생성은 남은 시간이 25초 이상일 때만 시작한다.
                // 예산 자체가 그보다 짧으면(관리자 화면 tick 15초) 첫 작업으로만 허용한다.
                $allowTts = $maxSeconds < self::STORY_TTS_MIN_SECONDS
                    ? $processed === 0
                    : $left >= self::STORY_TTS_MIN_SECONDS;
                $exclude = $allowTts ? [] : ['story_tts'];
                $job = Jobs::claim(['exclude' => $exclude]);
                if ($job === null) {
                    break;
                }
                self::process($job);
                $processed++;
            }
        } finally {
            self::$running = false;
            try {
                db_value('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
            } catch (\Throwable $e) {
                // 연결이 끊기면 잠금은 저절로 풀린다.
            }
        }

        return [
            'processed' => $processed,
            'remaining' => Jobs::dueCount(),
            'locked' => false,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /** 작업 하나를 처리한다. 예외는 여기서 받아 재시도 또는 실패로 기록한다. */
    public static function process(array $job): void
    {
        $id = (int) $job['id'];
        $payload = is_array($job['payload']) ? $job['payload'] : json_decode_array($job['payload']);
        try {
            switch ($job['type']) {
                case 'voice_clone':
                    self::handleVoiceClone($job, $payload);
                    break;
                case 'story_tts':
                    self::handleStoryTts($job, $payload);
                    break;
                case 'voice_clips':
                    self::handleVoiceClips($job, $payload);
                    break;
                case 'voice_delete':
                    self::handleVoiceDelete($job, $payload);
                    break;
                case 'credit_sync':
                    self::handleCreditSync();
                    break;
                case 'mail':
                    self::handleMail($payload);
                    break;
                default:
                    throw new \DomainException('알 수 없는 작업 종류: ' . $job['type']);
            }
            Jobs::complete($id);
        } catch (\Throwable $e) {
            // DomainException 은 다시 해도 같은 결과인 오류(대상 없음 등)이므로 재시도하지 않는다.
            $retry = !($e instanceof \DomainException);
            $message = $e->getMessage() !== '' ? $e->getMessage() : get_class($e);
            if ($e instanceof \PDOException || (!($e instanceof \RuntimeException) && !($e instanceof \LogicException))) {
                app_log('error', '작업 처리 오류', ['job' => $id, 'type' => $job['type'], 'error' => $message, 'at' => basename($e->getFile()) . ':' . $e->getLine()]);
            }
            $result = Jobs::fail($id, $message, $retry);
            try {
                self::onFailure($job, $payload, $message, $result);
            } catch (\Throwable $e2) {
                app_log('error', '작업 실패 후처리 오류: ' . $e2->getMessage(), ['job' => $id]);
            }
        }
    }

    // ───────────────────────── 작업별 처리 ─────────────────────────

    /** voice_clone {profile_id}: 샘플을 ElevenLabs 에 올려 목소리를 만들고 동화 생성을 등록한다. */
    private static function handleVoiceClone(array $job, array $payload): void
    {
        $pid = isset($payload['profile_id']) ? (int) $payload['profile_id'] : 0;
        $profile = $pid ? db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]) : null;
        if (!$profile || $profile['deleted_at'] !== null) {
            self::plog($job, $pid, 'warn', '삭제된 목소리라서 생성을 건너뜁니다.');

            return;
        }
        $hasVoice = (string) $profile['provider_voice_id'] !== '';
        // 반려되었거나 다른 상태로 바뀐 요청은 처리하지 않는다(재시도 중 이미 목소리를 만든 경우는 이어서 진행).
        if ($profile['status'] !== 'cloning' && !($hasVoice && $profile['status'] === 'processing')) {
            self::plog($job, $pid, 'warn', '목소리 상태가 "' . voice_status_label((string) $profile['status']) . '"(으)로 바뀌어 생성을 건너뜁니다.');

            return;
        }
        self::plog($job, $pid, 'info', '목소리 생성 작업 시작 (시도 ' . (int) $job['attempts'] . '/' . (int) $job['max_attempts'] . ')');

        $created = false;
        if (!$hasVoice) {
            $samples = db_all('SELECT * FROM voice_samples WHERE voice_profile_id = ? ORDER BY id', [$pid]);
            $files = [];
            $totalMs = 0;
            foreach ($samples as $s) {
                if (!Storage::exists($s['file_path'])) {
                    self::plog($job, $pid, 'warn', '샘플 파일이 없어 제외합니다: #' . (int) $s['id']);
                    continue;
                }
                $mime = (string) $s['mime_type'];
                if ($mime === '' || strpos($mime, '/') === false) {
                    $mime = Storage::mimeFor((string) $s['file_path']);
                }
                $files[] = [
                    'path' => Storage::path((string) $s['file_path']),
                    'name' => 'sample-' . (int) $s['id'] . '.' . strtolower(pathinfo((string) $s['file_path'], PATHINFO_EXTENSION)),
                    'mime' => $mime,
                ];
                $totalMs += (int) $s['duration_ms'];
            }
            if (!$files) {
                throw new \DomainException('업로드할 샘플 파일이 없습니다.');
            }
            self::plog($job, $pid, 'info', '샘플 ' . count($files) . '개(총 ' . self::koDuration($totalMs) . ') 업로드');

            $name = '르멤버 ' . $profile['label'] . ' #' . $pid;
            $description = '르멤버 가족 목소리 프로필 #' . $pid . ' (' . $profile['label'] . ')';
            $res = ElevenLabs::addVoice($name, $files, $description, [
                'user_id' => (int) $profile['user_id'],
                'ref_type' => 'voice_profile',
                'ref_id' => $pid,
            ]);
            if (empty($res['ok']) || empty($res['voice_id'])) {
                throw new \RuntimeException('ElevenLabs 목소리 생성 실패: ' . self::errorText($res));
            }
            $voiceId = (string) $res['voice_id'];

            // 처리하는 동안 삭제나 반려가 되었으면 방금 만든 목소리를 바로 지운다.
            $now = db_one('SELECT status, deleted_at FROM voice_profiles WHERE id = ?', [$pid]);
            if (!$now || $now['deleted_at'] !== null || $now['status'] !== 'cloning') {
                ElevenLabs::deleteVoice($voiceId);
                self::plog($job, $pid, 'warn', '처리 중 목소리가 삭제되거나 반려되어 만든 목소리를 지웠습니다.');

                return;
            }
            db_exec(
                'UPDATE voice_profiles SET provider_voice_id = ?, cloned_at = NOW(), status = ? WHERE id = ?',
                [$voiceId, 'processing', $pid]
            );
            $created = true;
            self::plog($job, $pid, 'info', 'ElevenLabs 목소리 생성 완료: ' . self::shortId($voiceId) . self::tookText(isset($res['ms']) ? (int) $res['ms'] : 0));

            // 새 목소리이므로 이전 목소리로 만든 짧은 음성은 지운다.
            foreach (db_all('SELECT id, file_path FROM voice_clips WHERE voice_profile_id = ?', [$pid]) as $clip) {
                Storage::delete($clip['file_path']);
            }
            db_exec('DELETE FROM voice_clips WHERE voice_profile_id = ?', [$pid]);
        } else {
            db_exec('UPDATE voice_profiles SET status = ? WHERE id = ?', ['processing', $pid]);
            self::plog($job, $pid, 'info', '기존 ElevenLabs 목소리를 사용합니다: ' . self::shortId((string) $profile['provider_voice_id']));
        }

        if (setting('voice.auto_batch_after_clone', true)) {
            // 새로 만든 목소리면 예전 오디오가 남아 있어도 모두 다시 만든다.
            $count = VoiceService::queueStories($pid, null, $created && self::hasCompletedAudio($pid));
            self::plog($job, $pid, 'info', $count > 0 ? '동화 ' . $count . '편 생성 작업 등록' : '새로 만들 동화 오디오가 없습니다.');
        } else {
            self::plog($job, $pid, 'info', '자동 동화 생성이 꺼져 있어 목소리만 만들었습니다.');
        }
        Jobs::enqueue('voice_clips', ['profile_id' => $pid], [
            'priority' => 4, 'ref_type' => 'voice_profile', 'ref_id' => $pid, 'unique' => true,
        ]);
        self::plog($job, $pid, 'info', '안내 음성(대체 문장, 미리듣기) 생성 작업 등록');
        VoiceService::refresh($pid);
    }

    /** story_tts {profile_id, story_id, force}: 동화 한 편을 목소리로 합성해 저장한다. */
    private static function handleStoryTts(array $job, array $payload): void
    {
        $pid = isset($payload['profile_id']) ? (int) $payload['profile_id'] : 0;
        $sid = isset($payload['story_id']) ? (int) $payload['story_id'] : 0;
        $force = !empty($payload['force']);

        $profile = $pid ? db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]) : null;
        if (!$profile || $profile['deleted_at'] !== null) {
            return;
        }
        if ((string) $profile['provider_voice_id'] === '') {
            throw new \DomainException('ElevenLabs 목소리가 아직 없습니다.');
        }
        $story = db_one('SELECT * FROM stories WHERE id = ? AND status = ? AND deleted_at IS NULL', [$sid, 'published']);
        $audio = db_one('SELECT * FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$sid, $pid]);
        if (!$story) {
            // 그 사이 내려간 동화: 파일 없는 대기 행은 지운다.
            if ($audio && $audio['status'] !== 'completed' && (string) $audio['file_path'] === '') {
                db_exec('DELETE FROM story_audios WHERE id = ?', [(int) $audio['id']]);
            } elseif ($audio && $audio['status'] !== 'completed') {
                db_exec('UPDATE story_audios SET status = ?, error_message = ? WHERE id = ?', ['failed', '게시되지 않은 동화입니다.', (int) $audio['id']]);
            }
            self::plog($job, $pid, 'warn', '동화 #' . $sid . '이(가) 게시 상태가 아니어서 건너뜁니다.');
            VoiceService::refresh($pid);

            return;
        }
        $sentences = db_all('SELECT seq, content FROM story_sentences WHERE story_id = ? ORDER BY seq', [$sid]);
        if (!$sentences) {
            throw new \DomainException('동화 문장이 없습니다.');
        }
        $hash = VoiceService::storyContentHash($story, $sentences);

        // 이미 최신 본문으로 만든 오디오가 있으면 다시 만들지 않는다(중복 등록 대비).
        if (!$force && $audio && $audio['status'] === 'completed' && $audio['content_hash'] === $hash && Storage::exists($audio['file_path'])) {
            VoiceService::refresh($pid);

            return;
        }

        if ($audio) {
            db_exec(
                'UPDATE story_audios SET status = ?, attempts = attempts + 1, error_message = NULL WHERE id = ?',
                ['processing', (int) $audio['id']]
            );
            $audioId = (int) $audio['id'];
        } else {
            $audioId = db_insert('story_audios', [
                'story_id' => $sid,
                'voice_profile_id' => $pid,
                'status' => 'processing',
                'attempts' => 1,
            ]);
        }
        VoiceService::refresh($pid);

        $text = Alignment::joinText($sentences);
        $model = (string) setting('elevenlabs.model_story', 'eleven_multilingual_v2');
        $res = ElevenLabs::synthesize((string) $profile['provider_voice_id'], $text, [
            'model_id' => $model,
            'voice_settings' => VoiceService::voiceSettings($profile),
            'with_timestamps' => true,
            'output_format' => (string) setting('elevenlabs.output_format', 'mp3_44100_128'),
            'usage' => [
                'purpose' => 'story_tts',
                'user_id' => (int) $profile['user_id'],
                'ref_type' => 'story_audio',
                'ref_id' => $audioId,
            ],
        ]);
        if (empty($res['ok']) || !isset($res['audio']) || (string) $res['audio'] === '') {
            throw new \RuntimeException('음성 합성 실패: ' . self::errorText($res));
        }

        $ext = self::cleanExt(isset($res['ext']) ? (string) $res['ext'] : '', isset($res['mime']) ? (string) $res['mime'] : '');
        $rel = 'story-audio/' . $pid . '/' . $sid . '-' . substr($hash, 0, 8) . '.' . $ext;
        Storage::put($rel, (string) $res['audio']);
        $size = strlen((string) $res['audio']);

        $duration = isset($res['duration_ms']) ? (int) $res['duration_ms'] : 0;
        if ($duration <= 0) {
            $duration = self::guessDurationMs((string) $res['audio'], $ext);
        }
        $alignment = isset($res['alignment']) && is_array($res['alignment']) && !empty($res['alignment']['starts']) ? $res['alignment'] : null;
        $timings = $alignment
            ? Alignment::build($sentences, $alignment, $duration)
            : Alignment::estimate($sentences, $duration);
        if (isset($timings['duration']) && (int) $timings['duration'] > 0) {
            $duration = (int) $timings['duration'];
        }

        $old = (string) ($audio ? $audio['file_path'] : '');
        db_update('story_audios', [
            'status' => 'completed',
            'file_path' => $rel,
            'duration_ms' => $duration,
            'sentence_timings' => json_encode_u($timings),
            'content_hash' => $hash,
            'model_id' => substr($model, 0, 60),
            'file_size' => $size,
            'char_count' => isset($res['chars']) && (int) $res['chars'] > 0 ? (int) $res['chars'] : Text::charCount($text),
            'error_message' => null,
            'generated_at' => now(),
        ], 'id = ?', [$audioId]);
        if ($old !== '' && $old !== $rel) {
            Storage::delete($old);
        }
        self::plog($job, $pid, 'info', "'" . $story['title'] . "' 오디오 생성 완료 (길이 " . fmt_duration($duration) . ', ' . number_format(mb_strlen($text)) . '자)' . self::tookText(isset($res['ms']) ? (int) $res['ms'] : 0));
        VoiceService::refresh($pid);
    }

    /** voice_clips {profile_id}: 질문 한도 초과 대체 문장, 오류 안내, 미리듣기 문장을 목소리로 만들어 둔다. */
    private static function handleVoiceClips(array $job, array $payload): void
    {
        $pid = isset($payload['profile_id']) ? (int) $payload['profile_id'] : 0;
        $profile = $pid ? db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]) : null;
        if (!$profile || $profile['deleted_at'] !== null) {
            return;
        }
        if ((string) $profile['provider_voice_id'] === '') {
            throw new \DomainException('ElevenLabs 목소리가 아직 없습니다.');
        }

        $made = 0;
        $kept = 0;
        $failed = [];
        foreach (self::clipLines() as $hash => $line) {
            $row = db_one('SELECT * FROM voice_clips WHERE voice_profile_id = ? AND text_hash = ?', [$pid, $hash]);
            if ($row && $row['status'] === 'completed' && Storage::exists($row['file_path'])) {
                $kept++;
                continue;
            }
            if ($row) {
                $clipId = (int) $row['id'];
            } else {
                $clipId = db_insert('voice_clips', [
                    'voice_profile_id' => $pid,
                    'kind' => $line['kind'],
                    'text' => $line['text'],
                    'text_hash' => $hash,
                    'status' => 'pending',
                ]);
            }
            $res = ElevenLabs::synthesize((string) $profile['provider_voice_id'], $line['text'], [
                'model_id' => (string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5'),
                'voice_settings' => VoiceService::voiceSettings($profile),
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
                $err = self::errorText($res);
                db_exec('UPDATE voice_clips SET status = ?, error_message = ? WHERE id = ?', ['failed', mb_substr($err, 0, 500), $clipId]);
                $failed[] = $err;
                continue;
            }
            $ext = self::cleanExt(isset($res['ext']) ? (string) $res['ext'] : '', isset($res['mime']) ? (string) $res['mime'] : '');
            $rel = 'clips/' . $pid . '/' . Storage::randomName($ext);
            Storage::put($rel, (string) $res['audio']);
            if ($row && (string) $row['file_path'] !== '' && $row['file_path'] !== $rel) {
                Storage::delete($row['file_path']);
            }
            db_update('voice_clips', [
                'status' => 'completed',
                'file_path' => $rel,
                'duration_ms' => isset($res['duration_ms']) ? (int) $res['duration_ms'] : null,
                'error_message' => null,
            ], 'id = ?', [$clipId]);
            $made++;
        }
        if ($failed) {
            self::plog($job, $pid, 'warn', '안내 음성 ' . count($failed) . '개 생성 실패: ' . $failed[0]);
            throw new \RuntimeException('안내 음성 일부 생성 실패: ' . $failed[0]);
        }
        self::plog($job, $pid, 'info', '안내 음성 ' . ($made + $kept) . '개 준비 완료 (새로 만든 것 ' . $made . '개)');
    }

    /**
     * voice_delete {profile_id, provider_voice_id, provider_only}: ElevenLabs 목소리와 로컬 파일(동화 오디오, 짧은 음성, 샘플)을 지운다.
     * provider_only 면 ElevenLabs 목소리만 지운다(재녹음 후 다시 만들 때).
     */
    private static function handleVoiceDelete(array $job, array $payload): void
    {
        $pid = isset($payload['profile_id']) ? (int) $payload['profile_id'] : 0;
        $voiceId = isset($payload['provider_voice_id']) ? (string) $payload['provider_voice_id'] : '';
        $providerOnly = !empty($payload['provider_only']);
        $profile = $pid ? db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]) : null;
        if ($voiceId === '' && $profile && !$providerOnly) {
            $voiceId = (string) $profile['provider_voice_id'];
        }

        if (!$providerOnly && $profile) {
            // 로컬 파일을 먼저 지운다(외부 API 가 실패해도 개인 음성 파일은 남기지 않는다).
            $files = 0;
            foreach (db_all('SELECT file_path FROM story_audios WHERE voice_profile_id = ?', [$pid]) as $r) {
                if (Storage::exists($r['file_path'])) {
                    Storage::delete($r['file_path']);
                    $files++;
                }
            }
            db_exec('DELETE FROM story_audios WHERE voice_profile_id = ?', [$pid]);
            foreach (db_all('SELECT file_path FROM voice_clips WHERE voice_profile_id = ?', [$pid]) as $r) {
                if (Storage::exists($r['file_path'])) {
                    Storage::delete($r['file_path']);
                    $files++;
                }
            }
            db_exec('DELETE FROM voice_clips WHERE voice_profile_id = ?', [$pid]);
            foreach (db_all('SELECT file_path FROM voice_samples WHERE voice_profile_id = ?', [$pid]) as $r) {
                if (Storage::exists($r['file_path'])) {
                    Storage::delete($r['file_path']);
                    $files++;
                }
            }
            // 빈 폴더 정리(목소리 연구실은 voice-samples/{회원}/{목소리}/ 에 샘플을 둔다)
            foreach (['story-audio/' . $pid, 'clips/' . $pid, 'voice-samples/' . (int) $profile['user_id'] . '/' . $pid, 'voice-samples/' . $pid] as $dir) {
                $full = storage_path($dir);
                if (is_dir($full)) {
                    @rmdir($full);
                }
            }
            self::plog($job, $pid, 'info', '로컬 음성 파일 ' . $files . '개 삭제');
        }

        if ($voiceId !== '') {
            $res = ElevenLabs::deleteVoice($voiceId);
            if (empty($res['ok'])) {
                $err = self::errorText($res);
                // 이미 없는 목소리면 삭제된 것으로 본다.
                if (!preg_match('/not[_ ]?found|404|voice_does_not_exist|존재하지/i', $err)) {
                    throw new \RuntimeException('ElevenLabs 목소리 삭제 실패: ' . $err);
                }
            }
            self::plog($job, $pid, 'info', 'ElevenLabs 목소리 삭제 완료: ' . self::shortId($voiceId));
        }
        if ($profile && !$providerOnly) {
            db_exec('UPDATE voice_profiles SET provider_deleted_at = NOW() WHERE id = ?', [$pid]);
        }
    }

    private static function handleCreditSync(): void
    {
        $res = ElevenLabs::syncCredits();
        if (is_array($res) && array_key_exists('ok', $res) && !$res['ok']) {
            throw new \RuntimeException('크레딧 동기화 실패: ' . self::errorText($res));
        }
    }

    private static function handleMail(array $payload): void
    {
        $to = isset($payload['to']) ? (string) $payload['to'] : '';
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \DomainException('받는 사람 주소가 올바르지 않습니다.');
        }
        $ok = Mailer::send($to, isset($payload['subject']) ? (string) $payload['subject'] : '', isset($payload['text']) ? (string) $payload['text'] : '');
        if (!$ok) {
            throw new \RuntimeException('메일 발송 실패');
        }
    }

    /** 재시도할 수 없게 실패했을 때 대상 상태를 정리한다. */
    private static function onFailure(array $job, array $payload, string $message, array $result): void
    {
        $pid = isset($payload['profile_id']) ? (int) $payload['profile_id'] : 0;
        $final = !empty($result['final']);
        $tail = $final ? '' : ' — ' . (int) $result['delay'] . '초 뒤 다시 시도(' . (int) $result['attempts'] . '/' . (int) $result['max_attempts'] . ')';

        if ($job['type'] === 'voice_clone' && $pid) {
            self::plog($job, $pid, $final ? 'error' : 'warn', '목소리 생성 실패: ' . $message . $tail);
            if ($final) {
                db_exec(
                    'UPDATE voice_profiles SET status = ? WHERE id = ? AND status = ? AND deleted_at IS NULL',
                    ['failed', $pid, 'cloning']
                );
            }

            return;
        }
        if ($job['type'] === 'story_tts' && $pid) {
            $sid = isset($payload['story_id']) ? (int) $payload['story_id'] : 0;
            $title = (string) db_value('SELECT title FROM stories WHERE id = ?', [$sid]);
            self::plog($job, $pid, $final ? 'error' : 'warn', "'" . ($title !== '' ? $title : '#' . $sid) . "' 생성 실패: " . $message . $tail);
            db_exec(
                'UPDATE story_audios SET status = ?, error_message = ? WHERE story_id = ? AND voice_profile_id = ? AND status IN (?, ?)',
                [$final ? 'failed' : 'pending', mb_substr($message, 0, 500), $sid, $pid, 'processing', 'pending']
            );
            VoiceService::refresh($pid);

            return;
        }
        if ($pid && in_array($job['type'], ['voice_clips', 'voice_delete'], true)) {
            $label = $job['type'] === 'voice_clips' ? '안내 음성 생성' : '목소리 삭제';
            self::plog($job, $pid, $final ? 'error' : 'warn', $label . ' 실패: ' . $message . $tail);

            return;
        }
        if ($final) {
            Jobs::log((int) $job['id'], $job['ref_type'], $job['ref_id'] !== null ? (int) $job['ref_id'] : null, 'error', $job['type'] . ' 작업 실패: ' . $message);
        }
    }

    // ───────────────────────── 자기 호출 ─────────────────────────

    /**
     * 응답을 기다리지 않고 /_ops/worker.php 를 호출해 백그라운드 처리를 시작한다.
     * 요청만 보내고 바로 연결을 닫는다(작업 처리기 쪽은 ignore_user_abort 로 계속 돈다).
     * 명령행(테스트, bin/worker.php)과 처리 중에는 부르지 않는다.
     */
    public static function kick(): void
    {
        if (self::$running || (PHP_SAPI === 'cli' && !getenv('REMEMVR_KICK_FROM_CLI'))) {
            return;
        }
        $site = trim((string) config('site_url', ''));
        $token = (string) config('ops_token', '');
        if ($site === '' || strlen($token) < 32) {
            return;
        }
        $u = parse_url($site);
        if (!is_array($u) || empty($u['host'])) {
            return;
        }
        $https = isset($u['scheme']) && strtolower($u['scheme']) === 'https';
        $port = isset($u['port']) ? (int) $u['port'] : ($https ? 443 : 80);
        $path = (isset($u['path']) ? rtrim($u['path'], '/') : '') . '/_ops/worker.php';
        $hostHeader = $u['host'] . (isset($u['port']) ? ':' . (int) $u['port'] : '');

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen(($https ? 'ssl://' : '') . $u['host'], $port, $errno, $errstr, 1);
        if (!$fp) {
            return;
        }
        @stream_set_timeout($fp, 1);
        $request = 'POST ' . $path . " HTTP/1.1\r\n"
            . 'Host: ' . $hostHeader . "\r\n"
            . 'X-Ops-Token: ' . $token . "\r\n"
            . "User-Agent: rememvr-worker-kick\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\n"
            . "Content-Length: 0\r\n"
            . "Connection: close\r\n\r\n";
        @fwrite($fp, $request);
        @fclose($fp);
    }

    /** 처리할 작업이 있으면 60초에 한 번 kick() 한다. App::run 이 모든 응답 뒤에 부른다. */
    public static function maybeKick(): void
    {
        if (self::$running || trim((string) config('site_url', '')) === '') {
            return;
        }
        $file = storage_path('worker-kick.txt');
        $last = is_file($file) ? (int) @file_get_contents($file) : 0;
        if ($last > 0 && time() - $last < self::KICK_INTERVAL) {
            return;
        }
        if (Jobs::dueCount() === 0) {
            return;
        }
        // 동시에 여러 요청이 부르지 않도록 시각을 먼저 남긴다.
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return;
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);

            return;
        }
        $prev = (int) stream_get_contents($fp);
        if ($prev > 0 && time() - $prev < self::KICK_INTERVAL) {
            flock($fp, LOCK_UN);
            fclose($fp);

            return;
        }
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, (string) time());
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        self::kick();
    }

    /** 마지막으로 처리기가 돈 시각(Y-m-d H:i:s). 없으면 null */
    public static function lastRunAt(): ?string
    {
        $file = storage_path('worker-last-run.txt');
        $t = is_file($file) ? (int) @file_get_contents($file) : 0;

        return $t > 0 ? date('Y-m-d H:i:s', $t) : null;
    }

    /** 이번 실행에서 남은 시간(초). 실행 중이 아니면 0 */
    public static function secondsLeft(): float
    {
        return self::$running ? max(0.0, self::$deadline - microtime(true)) : 0.0;
    }

    // ───────────────────────── 내부 도우미 ─────────────────────────

    private static function touchRun(): void
    {
        @file_put_contents(storage_path('worker-last-run.txt'), (string) time(), LOCK_EX);
    }

    /**
     * 처리기가 죽어 processing 으로 남은 동화 오디오를 정리한다(잠금을 잡은 상태라 지금 도는 합성은 없다).
     * 처리할 작업이 남았으면 pending, 아니면 failed 로 돌린다.
     */
    private static function recoverStuckAudio(): void
    {
        $rows = db_all(
            'SELECT id, voice_profile_id FROM story_audios WHERE status = ? AND updated_at < DATE_SUB(NOW(), INTERVAL ? SECOND)',
            ['processing', Jobs::STALE_SECONDS]
        );
        $profiles = [];
        foreach ($rows as $r) {
            $pid = (int) $r['voice_profile_id'];
            $hasJob = Jobs::activeCount('voice_profile', $pid, 'story_tts') > 0;
            db_exec(
                'UPDATE story_audios SET status = ?, error_message = ? WHERE id = ? AND status = ?',
                [$hasJob ? 'pending' : 'failed', '처리가 중단되었습니다.', (int) $r['id'], 'processing']
            );
            $profiles[$pid] = true;
        }
        foreach (array_keys($profiles) as $pid) {
            VoiceService::refresh($pid);
        }
    }

    /** 정기 작업: 크레딧 동기화(12시간마다), 오래된 기록 정리(하루 한 번) */
    private static function housekeeping(): void
    {
        try {
            $file = storage_path('worker-housekeeping.txt');
            $last = is_file($file) ? (int) @file_get_contents($file) : 0;
            if ($last > 0 && time() - $last < 3600) {
                return;
            }
            @file_put_contents($file, (string) time(), LOCK_EX);

            if (ElevenLabs::ready()) {
                $recent = db_value(
                    'SELECT COUNT(*) FROM provider_credit_snapshots WHERE provider = ? AND checked_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR)',
                    ['elevenlabs']
                );
                if ((int) $recent === 0) {
                    Jobs::enqueue('credit_sync', [], ['priority' => 8, 'unique' => true, 'max_attempts' => 2]);
                }
            }
            $pruneFile = storage_path('worker-prune.txt');
            $pruned = is_file($pruneFile) ? (int) @file_get_contents($pruneFile) : 0;
            if (time() - $pruned >= 86400) {
                @file_put_contents($pruneFile, (string) time(), LOCK_EX);
                Jobs::prune();
            }
        } catch (\Throwable $e) {
            app_log('error', '작업 처리기 정기 작업 오류: ' . $e->getMessage());
        }
    }

    /** 대체 문장, 오류 안내, 미리듣기 문장 목록. [text_hash => ['kind', 'text']] (같은 문장은 한 번만) */
    public static function clipLines(): array
    {
        $lines = [];
        // 질문 처리(QuestionService::clipUrl)가 앞뒤 공백만 뗀 문장의 해시로 찾으므로 같은 방식으로 정리한다.
        $add = static function ($text, string $kind) use (&$lines) {
            $text = is_string($text) ? trim($text) : '';
            if ($text === '' || mb_strlen($text) > 500) {
                return;
            }
            $hash = VoiceService::textHash($text);
            if (!isset($lines[$hash])) {
                $lines[$hash] = ['kind' => $kind, 'text' => $text];
            }
        };
        // 설정을 비워 두면 질문 처리도 기본 문장을 쓰므로 기본 문장을 만든다.
        foreach (['qa.fallback_lines' => 'fallback', 'qa.error_lines' => 'error'] as $key => $kind) {
            $list = array_filter((array) setting($key, []), static function ($t) {
                return is_string($t) && trim($t) !== '';
            });
            if (!$list) {
                $list = (array) \App\Core\Settings::DEFAULTS[$key];
            }
            foreach ($list as $t) {
                $add($t, $kind);
            }
        }
        $rows = db_all(
            'SELECT fallback_lines FROM stories WHERE status = ? AND deleted_at IS NULL AND fallback_lines IS NOT NULL',
            ['published']
        );
        foreach ($rows as $r) {
            foreach (json_decode_array($r['fallback_lines']) as $t) {
                if (is_string($t)) {
                    $add($t, 'fallback');
                }
            }
        }
        $add(self::PREVIEW_LINE, 'preview');

        return $lines;
    }

    private static function hasCompletedAudio(int $pid): bool
    {
        return (int) db_value('SELECT COUNT(*) FROM story_audios WHERE voice_profile_id = ? AND status = ?', [$pid, 'completed']) > 0;
    }

    /** 목소리 프로필 기준 처리 콘솔 기록 */
    private static function plog(array $job, int $pid, string $level, string $message): void
    {
        Jobs::log((int) $job['id'], 'voice_profile', $pid ?: null, $level, $message);
    }

    private static function errorText($res): string
    {
        if (is_array($res) && !empty($res['error'])) {
            return is_string($res['error']) ? $res['error'] : json_encode_u($res['error']);
        }

        return '알 수 없는 오류';
    }

    /** 확장자 정리(합성 결과의 ext, 없으면 MIME 으로 추정) */
    private static function cleanExt(string $ext, string $mime): string
    {
        $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));
        if ($ext === '' && $mime !== '') {
            $ext = Storage::extForMime($mime, 'mp3');
        }

        return $ext !== '' ? $ext : 'mp3';
    }

    /** 합성 결과에 길이가 없을 때: WAV 는 머리글로 계산하고, 그 밖에는 128kbps MP3 로 보고 크기에서 추정한다. */
    public static function guessDurationMs(string $bytes, string $ext): int
    {
        $size = strlen($bytes);
        if ($ext === 'wav' && $size > 44 && substr($bytes, 0, 4) === 'RIFF') {
            $info = unpack('VbyteRate', substr($bytes, 28, 4));
            $byteRate = $info ? (int) $info['byteRate'] : 0;
            if ($byteRate > 0) {
                return (int) round(($size - 44) * 1000 / $byteRate);
            }
        }

        return (int) round($size * 8 / 128);
    }

    /** 걸린 시간 표시(" · 12.3초 소요"). 1초 미만이면 생략한다. */
    private static function tookText(int $ms): string
    {
        return $ms >= 1000 ? ' · ' . number_format($ms / 1000, 1) . '초 소요' : '';
    }

    /** ElevenLabs 목소리 id 를 콘솔에 짧게 보여 준다. */
    private static function shortId(string $id): string
    {
        return mb_strlen($id) > 8 ? mb_substr($id, 0, 8) . '…' : $id;
    }

    /** 135000 → "2분 15초" */
    public static function koDuration(int $ms): string
    {
        $s = (int) round($ms / 1000);
        $m = intdiv($s, 60);
        $sec = $s % 60;
        if ($m > 0) {
            return $m . '분' . ($sec > 0 ? ' ' . $sec . '초' : '');
        }

        return $sec . '초';
    }
}
