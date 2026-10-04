<?php
use App\Core\Settings;
use App\Core\Storage;
use App\Core\Text;
use App\Services\Jobs;
use App\Services\VoiceService;
use App\Services\Worker;

/**
 * 목소리 처리 테스트. DB 변경은 트랜잭션으로 되돌리고, 만든 파일은 끝에서 지운다.
 * 다른 게시 동화와 대기 작업이 결과에 섞이지 않도록 트랜잭션 안에서 잠시 숨긴다(되돌릴 때 원래대로).
 */
function voice_test_tx(callable $fn): void
{
    $pdo = test_db();
    try {
        db_value('SELECT 1 FROM voice_clips LIMIT 1');
    } catch (Throwable $e) {
        skip_test('테이블 없음(마이그레이션 필요)');
    }
    $GLOBALS['__voice_test_profiles'] = [];
    $pdo->beginTransaction();
    try {
        db_exec("UPDATE stories SET status = 'hidden' WHERE status = 'published'");
        db_exec("UPDATE jobs SET available_at = DATE_ADD(NOW(), INTERVAL 1 DAY) WHERE status = 'pending'");
        $fn();
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // 테스트 중 바꾼 설정 캐시를 비운다(DB 는 되돌려졌다).
        $prop = new ReflectionProperty(Settings::class, 'cache');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
        foreach ($GLOBALS['__voice_test_profiles'] as $pid) {
            foreach (['story-audio/' . $pid, 'clips/' . $pid, 'voice-samples/' . $pid] as $dir) {
                $full = storage_path($dir);
                foreach ((array) glob($full . '/*') as $f) {
                    @unlink($f);
                }
                @rmdir($full);
            }
        }
    }
}

/** 테스트 회원과 목소리 */
function voice_test_profile(array $profile = [], array $user = []): array
{
    $uid = db_insert('users', array_merge([
        'email' => 'voice-test-' . bin2hex(random_bytes(4)) . '@example.com',
        'password_hash' => password_hash('test1234', PASSWORD_DEFAULT),
        'name' => '테스트',
        'status' => 'active',
    ], $user));
    $pid = db_insert('voice_profiles', array_merge([
        'user_id' => $uid,
        'label' => '엄마',
        'status' => 'draft',
    ], $profile));
    $GLOBALS['__voice_test_profiles'][] = $pid;

    return ['user_id' => $uid, 'profile_id' => $pid];
}

/** 게시된 테스트 동화(문장 3개, content_hash 는 비워 둔다) */
function voice_test_story(array $sentences = []): int
{
    $sentences = $sentences ?: ['옛날 옛적에 작은 토끼가 살았어요.', '토끼는 달님을 좋아했어요.', '"달님, 안녕!" 토끼가 인사했어요.'];
    $sid = db_insert('stories', [
        'title' => '테스트 달님 토끼',
        'body' => implode("\n", $sentences),
        'status' => 'published',
        'char_count' => mb_strlen(implode(' ', $sentences)),
    ]);
    foreach (array_values($sentences) as $i => $s) {
        db_insert('story_sentences', ['story_id' => $sid, 'seq' => $i + 1, 'content' => $s]);
    }

    return $sid;
}

/** 가짜 음성 샘플(WAV)을 저장하고 voice_samples 에 넣는다. */
function voice_test_sample(int $pid, int $ms = 70000, string $grade = 'good'): int
{
    $text = str_repeat('가', 8);
    $wav = class_exists('App\\Services\\FakeAudio') ? App\Services\FakeAudio::speech($text)['audio'] : 'RIFF0000WAVEfmt ';
    $rel = Storage::put('voice-samples/' . $pid . '/' . Storage::randomName('wav'), $wav);

    return db_insert('voice_samples', [
        'voice_profile_id' => $pid,
        'file_path' => $rel,
        'mime_type' => 'audio/wav',
        'file_size' => strlen($wav),
        'duration_ms' => $ms,
        'source' => 'record',
        'quality_grade' => $grade,
    ]);
}

test('합성 파라미터는 0~1 로 자르고 빈 값은 NULL 로 둔다', function () {
    voice_test_tx(function () {
        $t = voice_test_profile();
        VoiceService::saveParams($t['profile_id'], ['stability' => '1.7', 'similarity' => -0.2, 'style' => '', 'speaker_boost' => 'false']);
        $row = db_one('SELECT stability, similarity_boost, style, speaker_boost FROM voice_profiles WHERE id = ?', [$t['profile_id']]);
        assert_same('1.00', (string) $row['stability']);
        assert_same('0.00', (string) $row['similarity_boost']);
        assert_same(null, $row['style']);
        assert_same(0, (int) $row['speaker_boost']);

        $vs = VoiceService::voiceSettings(array_merge($row, ['style' => null]));
        assert_same(1.0, $vs['stability']);
        assert_same((float) setting('elevenlabs.default_style', 0.0), $vs['style'], '빈 값은 설정 기본값');
        assert_same(false, $vs['use_speaker_boost']);
    });
});

test('제출은 녹음 길이를 확인하고 검토 대기로 바꾼다', function () {
    voice_test_tx(function () {
        Settings::set('notify.admin_email', 'ops@example.com');
        Settings::set('voice.auto_clone_on_submit', false);
        $t = voice_test_profile();
        $pid = $t['profile_id'];

        $msg = '';
        try {
            VoiceService::submit($pid);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }
        assert_contains('녹음된 목소리가 없어요', $msg);

        voice_test_sample($pid, 20000, 'good');
        $msg = '';
        try {
            VoiceService::submit($pid);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }
        assert_contains('최소', $msg, '짧은 녹음 거부');

        voice_test_sample($pid, 60000, 'poor');
        VoiceService::submit($pid);
        $row = db_one('SELECT status, sample_total_ms, quality_grade, requested_at FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('pending', $row['status']);
        assert_same(80000, (int) $row['sample_total_ms']);
        // 20초 good(3) + 60초 poor(1) → 평균 1.5 → poor
        assert_same('poor', $row['quality_grade']);
        $mail = db_one("SELECT payload FROM jobs WHERE type = 'mail' AND ref_type = 'voice_profile' AND ref_id = ?", [$pid]);
        assert_true($mail !== null, '관리자 알림 메일 작업이 없다');
        assert_same('ops@example.com', json_decode($mail['payload'], true)['to']);

        // 검토 대기에서는 다시 제출할 수 없다
        $thrown = false;
        try {
            VoiceService::submit($pid);
        } catch (RuntimeException $e) {
            $thrown = true;
        }
        assert_true($thrown, '중복 제출을 막지 않았다');
    });
});

test('승인은 상태와 샘플을 확인하고 voice_clone 작업을 등록한다', function () {
    voice_test_tx(function () {
        $t = voice_test_profile(['status' => 'draft']);
        $pid = $t['profile_id'];
        $msg = '';
        try {
            VoiceService::approve($pid, null);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }
        assert_contains('승인할 수 없습니다', $msg, 'draft 승인 거부');

        db_exec("UPDATE voice_profiles SET status = 'pending' WHERE id = ?", [$pid]);
        $msg = '';
        try {
            VoiceService::approve($pid, null);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }
        assert_contains('샘플이 없어', $msg, '샘플 없는 승인 거부');

        voice_test_sample($pid);
        $admin = db_value('SELECT id FROM admins ORDER BY id LIMIT 1');
        VoiceService::approve($pid, $admin !== null ? (int) $admin : null, ['stability' => 0.5]);
        $row = db_one('SELECT status, stability, processed_at FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('cloning', $row['status']);
        assert_same('0.50', (string) $row['stability']);
        assert_true($row['processed_at'] !== null, 'processed_at 이 없다');
        $job = db_one("SELECT priority, status FROM jobs WHERE type = 'voice_clone' AND ref_type = 'voice_profile' AND ref_id = ?", [$pid]);
        assert_true($job !== null, 'voice_clone 작업이 없다');
        assert_same(1, (int) $job['priority']);

        // 반려: 사유와 사용자 메일
        VoiceService::reject($pid, null, '주변 소음이 커요');
        $row = db_one('SELECT status, reject_reason FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('rejected', $row['status']);
        assert_same('주변 소음이 커요', $row['reject_reason']);
        assert_same('cancelled', db_value("SELECT status FROM jobs WHERE type = 'voice_clone' AND ref_id = ?", [$pid]), '대기 중 생성 작업 취소');
        assert_true((int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_id = ?", [$pid]) === 1, '반려 메일 작업이 없다');
    });
});

test('동화 오디오 등록, 진행률, 상태 재계산', function () {
    voice_test_tx(function () {
        $t = voice_test_profile(['status' => 'processing', 'provider_voice_id' => 'fake_test_voice', 'cloned_at' => now()]);
        $pid = $t['profile_id'];
        $sid = voice_test_story();

        $p = VoiceService::progress($pid);
        assert_same(['total' => 1, 'completed' => 0, 'failed' => 0, 'pending' => 1, 'percent' => 0], array_intersect_key($p, array_flip(['total', 'completed', 'failed', 'pending', 'percent'])));

        assert_same(1, VoiceService::queueStories($pid));
        $hash = db_value('SELECT content_hash FROM stories WHERE id = ?', [$sid]);
        $expected = Text::hashSentences(array_column(db_all('SELECT content FROM story_sentences WHERE story_id = ? ORDER BY seq', [$sid]), 'content'));
        assert_same($expected, $hash, '비어 있던 content_hash 를 채워야 한다');
        assert_same('pending', db_value('SELECT status FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$sid, $pid]));
        assert_same('queued', db_value('SELECT batch_status FROM voice_profiles WHERE id = ?', [$pid]));
        $job = db_one("SELECT payload, priority FROM jobs WHERE type = 'story_tts' AND ref_type = 'voice_profile' AND ref_id = ? AND status = 'pending'", [$pid]);
        assert_same(5, (int) $job['priority']);
        assert_same(['profile_id' => $pid, 'story_id' => $sid, 'force' => false], json_decode($job['payload'], true));

        // 다시 불러도 대기 작업을 중복으로 만들지 않는다
        VoiceService::queueStories($pid);
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'story_tts' AND ref_id = ? AND status = 'pending'", [$pid]));
        $r = VoiceService::refresh($pid);
        assert_same('processing', $r['status']);
        assert_same('queued', $r['batch_status']);

        // 작업이 끝난 것처럼 만든다
        $rel = Storage::put('story-audio/' . $pid . '/' . $sid . '-test.wav', 'RIFF');
        db_exec("UPDATE story_audios SET status = 'completed', file_path = ?, content_hash = ?, duration_ms = 1000 WHERE story_id = ? AND voice_profile_id = ?", [$rel, $hash, $sid, $pid]);
        db_exec("UPDATE jobs SET status = 'done' WHERE type = 'story_tts' AND ref_id = ?", [$pid]);
        $r = VoiceService::refresh($pid);
        assert_same('completed', $r['status']);
        assert_same('done', $r['batch_status']);
        assert_same(100, $r['progress']['percent']);
        assert_true(db_value('SELECT batch_done_at FROM voice_profiles WHERE id = ?', [$pid]) !== null, 'batch_done_at 이 없다');
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_id = ?", [$pid]), '준비 완료 메일');

        // 최신 오디오는 건너뛰고, force 면 다시 만든다
        assert_same(0, VoiceService::queueStories($pid));
        assert_same(1, VoiceService::queueStories($pid, [$sid], true));
        assert_same('processing', db_value('SELECT status FROM voice_profiles WHERE id = ?', [$pid]));

        // 본문이 바뀌면 옛 오디오(stale)로 센다
        db_exec("UPDATE story_audios SET status = 'completed' WHERE story_id = ? AND voice_profile_id = ?", [$sid, $pid]);
        db_exec("UPDATE stories SET content_hash = ? WHERE id = ?", [str_repeat('a', 64), $sid]);
        assert_same(1, VoiceService::progress($pid)['stale']);

        // 두 번째 일괄 생성이 끝나도 준비 완료 메일은 다시 보내지 않는다
        db_exec("UPDATE jobs SET status = 'done' WHERE type = 'story_tts' AND ref_id = ?", [$pid]);
        db_exec("UPDATE story_audios SET content_hash = ? WHERE story_id = ? AND voice_profile_id = ?", [str_repeat('a', 64), $sid, $pid]);
        VoiceService::refresh($pid);
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_id = ?", [$pid]));
    });
});

test('알림을 끈 회원에게는 준비 완료 메일을 보내지 않는다', function () {
    voice_test_tx(function () {
        $t = voice_test_profile(
            ['status' => 'processing', 'provider_voice_id' => 'fake_test_voice', 'batch_status' => 'running'],
            ['prefs' => json_encode(['notify_voice_ready' => false])]
        );
        VoiceService::refresh($t['profile_id']);
        assert_same('completed', db_value('SELECT status FROM voice_profiles WHERE id = ?', [$t['profile_id']]));
        assert_same(0, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_id = ?", [$t['profile_id']]));
    });
});

test('삭제하면 대기 작업을 취소하고 voice_delete 작업을 등록한다', function () {
    voice_test_tx(function () {
        $t = voice_test_profile(['status' => 'processing', 'provider_voice_id' => 'fake_del_voice']);
        $pid = $t['profile_id'];
        voice_test_story();
        VoiceService::queueStories($pid);
        VoiceService::delete($pid);
        assert_true(db_value('SELECT deleted_at FROM voice_profiles WHERE id = ?', [$pid]) !== null, '소프트 삭제가 아니다');
        assert_same(0, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'story_tts' AND ref_id = ? AND status = 'pending'", [$pid]));
        $job = db_one("SELECT payload FROM jobs WHERE type = 'voice_delete' AND ref_id = ?", [$pid]);
        assert_same('fake_del_voice', json_decode($job['payload'], true)['provider_voice_id']);
    });
});

test('가짜 API 로 승인부터 동화 오디오 완성까지 처리한다', function () {
    if (!class_exists('App\\Services\\ElevenLabs') || !class_exists('App\\Services\\Alignment') || !class_exists('App\\Services\\FakeAudio')) {
        skip_test('ElevenLabs, Alignment 클래스가 아직 없다');
    }
    if (!config('providers_fake')) {
        skip_test('providers_fake 설정이 꺼져 있다');
    }
    voice_test_tx(function () {
        Settings::set('voice.auto_batch_after_clone', true);
        $t = voice_test_profile(['status' => 'pending']);
        $pid = $t['profile_id'];
        voice_test_sample($pid, 75000);
        voice_test_sample($pid, 60000);
        $sid = voice_test_story();

        VoiceService::approve($pid, null);
        $rounds = 0;
        while ($rounds < 40 && Jobs::activeCount('voice_profile', $pid) > 0) {
            $r = Worker::run(60);
            if (!empty($r['locked'])) {
                // 웹 서버의 처리기가 잠금을 잡고 있으면 잠시 기다린다(트랜잭션 밖이라 이 작업은 보지 못한다).
                usleep(300000);
            }
            $rounds++;
        }
        assert_same(0, Jobs::activeCount('voice_profile', $pid), '작업이 남아 있다');
        $failed = db_all("SELECT type, last_error FROM jobs WHERE ref_id = ? AND ref_type = 'voice_profile' AND status = 'failed'", [$pid]);
        assert_same([], $failed, '실패한 작업');

        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('completed', $profile['status']);
        assert_same('done', $profile['batch_status']);
        assert_true(strpos((string) $profile['provider_voice_id'], 'fake_') === 0, 'voice id: ' . $profile['provider_voice_id']);
        assert_true($profile['cloned_at'] !== null, 'cloned_at 이 없다');

        $audio = db_one('SELECT * FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$sid, $pid]);
        assert_same('completed', $audio['status']);
        assert_true(Storage::exists($audio['file_path']), '오디오 파일이 없다: ' . $audio['file_path']);
        $story = db_one('SELECT content_hash FROM stories WHERE id = ?', [$sid]);
        assert_same('story-audio/' . $pid . '/' . $sid . '-' . substr($story['content_hash'], 0, 8) . '.wav', $audio['file_path']);
        assert_same($story['content_hash'], $audio['content_hash']);
        assert_true((int) $audio['duration_ms'] > 0 && (int) $audio['file_size'] > 0, '길이, 크기');
        $timings = json_decode($audio['sentence_timings'], true);
        assert_same(1, $timings['v']);
        assert_same(3, count($timings['sentences']));
        assert_same(0, $timings['sentences'][0]['start']);
        assert_same((int) $audio['duration_ms'], (int) $timings['duration']);
        assert_true($timings['sentences'][1]['start'] > 0 && $timings['sentences'][1]['start'] < $timings['duration'], '둘째 문장 시작');
        assert_same(count(Text::words('토끼는 달님을 좋아했어요.')), count($timings['sentences'][1]['words']));

        $clips = db_all("SELECT kind, status, file_path FROM voice_clips WHERE voice_profile_id = ?", [$pid]);
        $kinds = array_count_values(array_column($clips, 'kind'));
        assert_true(isset($kinds['preview'], $kinds['fallback'], $kinds['error']), '안내 음성 종류: ' . json_encode($kinds));
        foreach ($clips as $c) {
            assert_same('completed', $c['status']);
            assert_true(Storage::exists($c['file_path']), '안내 음성 파일이 없다');
        }

        $log = implode("\n", array_column(Jobs::latestLogs('voice_profile', $pid), 'message'));
        assert_contains('샘플 2개(총 2분 15초) 업로드', $log);
        assert_contains('ElevenLabs 목소리 생성 완료: fake_', $log);
        assert_contains('동화 1편 생성 작업 등록', $log);
        assert_contains("'테스트 달님 토끼' 오디오 생성 완료", $log);

        $mail = db_one("SELECT status, payload FROM jobs WHERE type = 'mail' AND ref_id = ?", [$pid]);
        assert_true($mail !== null, '준비 완료 메일 작업이 없다');
        assert_same('done', $mail['status']);
        assert_true((int) db_value("SELECT COUNT(*) FROM api_usage_logs WHERE ref_type = 'story_audio' AND ref_id = ?", [(int) $audio['id']]) >= 1, '사용량 기록');

        // 목소리 삭제 작업은 로컬 파일을 모두 지운다
        VoiceService::delete($pid);
        Worker::run(20);
        assert_true(db_value('SELECT provider_deleted_at FROM voice_profiles WHERE id = ?', [$pid]) !== null, 'provider_deleted_at 이 없다');
        assert_same(false, Storage::exists($audio['file_path']), '동화 오디오가 남았다');
        assert_same(0, (int) db_value('SELECT COUNT(*) FROM voice_clips WHERE voice_profile_id = ?', [$pid]));
        foreach (db_all('SELECT file_path FROM voice_samples WHERE voice_profile_id = ?', [$pid]) as $s) {
            assert_same(false, Storage::exists($s['file_path']), '샘플 파일이 남았다');
        }
    });
});
