<?php
use App\Core\Settings;
use App\Core\Storage;
use App\Core\Text;
use App\Services\Jobs;
use App\Services\StoryRequests;
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

test('자동 생성이 켜져 있으면 제출하자마자 관리자 검토 없이 목소리 생성 작업을 등록한다', function () {
    voice_test_tx(function () {
        Settings::set('notify.admin_email', 'ops@example.com');
        Settings::forget('voice.auto_clone_on_submit');
        assert_true(VoiceService::autoClone(), '자동 생성 기본값은 켬');
        $t = voice_test_profile();
        $pid = $t['profile_id'];
        voice_test_sample($pid, 45000, 'good');
        voice_test_sample($pid, 30000, 'good');
        VoiceService::submit($pid);

        $row = db_one('SELECT status, processed_by, sample_total_ms FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('cloning', $row['status']);
        assert_same(null, $row['processed_by'], '관리자 없이 자동 승인');
        assert_same(75000, (int) $row['sample_total_ms']);
        $jobs = db_all("SELECT type, status FROM jobs WHERE ref_type = 'voice_profile' AND ref_id = ? ORDER BY id", [$pid]);
        assert_same([['type' => 'voice_clone', 'status' => 'pending']], $jobs, '검토 요청 메일 없이 목소리 생성 작업만 등록');
        $log = (string) db_value("SELECT GROUP_CONCAT(message SEPARATOR '|') FROM job_logs WHERE ref_type = 'voice_profile' AND ref_id = ?", [$pid]);
        assert_contains('자동 승인', $log);
    });
});

test('목소리 생성이 끝내 실패하면 실패로 바꾸고 운영 메일로 알린다', function () {
    voice_test_tx(function () {
        Settings::set('notify.admin_email', 'ops@example.com');
        $t = voice_test_profile(['status' => 'cloning']);
        $pid = $t['profile_id'];
        $jobId = Jobs::enqueue('voice_clone', ['profile_id' => $pid], ['ref_type' => 'voice_profile', 'ref_id' => $pid, 'max_attempts' => 1]);
        $job = db_one('SELECT * FROM jobs WHERE id = ?', [$jobId]);
        $m = new ReflectionMethod(Worker::class, 'onFailure');
        $m->setAccessible(true);
        $m->invoke(null, $job, ['profile_id' => $pid], 'ElevenLabs 목소리 생성 실패: 요금제에 목소리 복제가 없습니다.', ['final' => true, 'delay' => 0, 'attempts' => 1, 'max_attempts' => 1]);

        assert_same('failed', db_value('SELECT status FROM voice_profiles WHERE id = ?', [$pid]));
        $mail = db_one("SELECT payload FROM jobs WHERE type = 'mail' AND ref_type = 'voice_profile' AND ref_id = ?", [$pid]);
        assert_true($mail !== null, '실패 알림 메일 작업이 없다');
        $payload = json_decode($mail['payload'], true);
        assert_same('ops@example.com', $payload['to']);
        assert_contains('요금제에 목소리 복제가 없습니다', $payload['text']);
    });
});

test('실패한 목소리는 실패 사유를 보여 주고 회원이 다시 만들 수 있다', function () {
    voice_test_tx(function () {
        $t = voice_test_profile(['status' => 'completed']);
        $pid = $t['profile_id'];
        voice_test_sample($pid);
        $msg = '';
        try {
            VoiceService::retryByUser($pid);
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
        }
        assert_contains('다시 만들 수 없어요', $msg, '실패가 아니면 다시 만들기 거부');

        db_exec("UPDATE voice_profiles SET status = 'failed' WHERE id = ?", [$pid]);
        $jobId = Jobs::enqueue('voice_clone', ['profile_id' => $pid], ['ref_type' => 'voice_profile', 'ref_id' => $pid, 'max_attempts' => 1]);
        db_exec("UPDATE jobs SET status = 'failed', last_error = ? WHERE id = ?", ['ElevenLabs 목소리 생성 실패: 키 권한 없음', $jobId]);
        $reasons = VoiceService::failReasons([$pid, 999999]);
        assert_same(['ElevenLabs 목소리 생성 실패: 키 권한 없음'], array_values($reasons));
        assert_true(isset($reasons[$pid]), '목소리 번호로 찾는다');

        VoiceService::retryByUser($pid);
        assert_same('cloning', db_value('SELECT status FROM voice_profiles WHERE id = ?', [$pid]));
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'voice_clone' AND ref_id = ? AND status = 'pending'", [$pid]));
        assert_same([], VoiceService::failReasons([$pid]), '다시 만드는 중에는 실패 사유를 보여 주지 않는다');
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

test('동화 오디오 등록, 진행률, 상태 재계산(생성을 시작한 요청만)', function () {
    voice_test_tx(function () {
        $t = voice_test_profile(['status' => 'completed', 'provider_voice_id' => 'fake_test_voice', 'cloned_at' => now()]);
        $pid = $t['profile_id'];
        $sid = voice_test_story();

        // 요청이 없으면 만들 동화도 없다(목소리가 준비돼도 동화를 한꺼번에 만들지 않는다).
        assert_same(0, VoiceService::progress($pid)['total']);
        assert_same(0, VoiceService::queueStories($pid));
        assert_same(0, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'story_tts' AND ref_id = ?", [$pid]));

        // 확인 대기 요청도 아직 대상이 아니다
        $rid = db_insert('story_requests', ['user_id' => $t['user_id'], 'story_id' => $sid, 'voice_profile_id' => $pid, 'status' => 'requested']);
        assert_same(0, VoiceService::queueStories($pid));

        db_exec("UPDATE story_requests SET status = 'approved' WHERE id = ?", [$rid]);
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

        // 다시 불러도 대기 작업을 중복으로 만들지 않는다. 목소리 상태는 바꾸지 않는다.
        VoiceService::queueStories($pid);
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'story_tts' AND ref_id = ? AND status = 'pending'", [$pid]));
        $r = VoiceService::refresh($pid);
        assert_same('completed', $r['status']);
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
        // 완성 알림은 목소리가 아니라 요청 단위로 보낸다(StoryRequests::syncAudio).
        assert_same(0, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'mail' AND ref_type = 'voice_profile' AND ref_id = ?", [$pid]));

        // 최신 오디오는 건너뛰고, force 면 다시 만든다
        assert_same(0, VoiceService::queueStories($pid));
        assert_same(1, VoiceService::queueStories($pid, [$sid], true));
        assert_same('completed', db_value('SELECT status FROM voice_profiles WHERE id = ?', [$pid]));

        // 본문이 바뀌면 옛 오디오(stale)로 센다
        db_exec("UPDATE story_audios SET status = 'completed' WHERE story_id = ? AND voice_profile_id = ?", [$sid, $pid]);
        db_exec("UPDATE stories SET content_hash = ? WHERE id = ?", [str_repeat('a', 64), $sid]);
        assert_same(1, VoiceService::progress($pid)['stale']);
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

test('가짜 API 로 목소리 승인, 동화 생성 요청, 생성 시작, 완성 알림까지 처리한다', function () {
    if (!class_exists('App\\Services\\ElevenLabs') || !class_exists('App\\Services\\Alignment') || !class_exists('App\\Services\\FakeAudio')) {
        skip_test('ElevenLabs, Alignment 클래스가 아직 없다');
    }
    if (!config('providers_fake')) {
        skip_test('providers_fake 설정이 꺼져 있다');
    }
    voice_test_tx(function () {
        $t = voice_test_profile(['status' => 'pending']);
        $pid = $t['profile_id'];
        $uid = $t['user_id'];
        voice_test_sample($pid, 75000);
        voice_test_sample($pid, 60000);
        $sid = voice_test_story();
        $drain = function () use ($pid) {
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
        };

        // 1) 목소리 승인 → 복제. 동화는 만들지 않고 목소리 준비 완료 메일만 보낸다.
        VoiceService::approve($pid, null);
        $drain();
        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('completed', $profile['status']);
        assert_true(strpos((string) $profile['provider_voice_id'], 'fake_') === 0, 'voice id: ' . $profile['provider_voice_id']);
        assert_true($profile['cloned_at'] !== null, 'cloned_at 이 없다');
        assert_same(0, (int) db_value('SELECT COUNT(*) FROM story_audios WHERE voice_profile_id = ?', [$pid]), '요청 없이 동화를 만들었다');
        $mail = db_one("SELECT status, payload FROM jobs WHERE type = 'mail' AND ref_type = 'voice_profile' AND ref_id = ?", [$pid]);
        assert_true($mail !== null, '준비 완료 메일 작업이 없다');
        assert_same('done', $mail['status']);
        assert_contains('목소리가 준비되었어요', json_decode($mail['payload'], true)['subject']);

        // 2) 회원이 동화와 목소리를 골라 요청 → 관리자가 생성 시작
        $res = StoryRequests::create($uid, $sid, [$pid]);
        assert_same(1, count($res['created']));
        $rid = $res['created'][0];
        assert_same('requested', StoryRequests::state(StoryRequests::latestFor($uid, $sid, $pid)));
        assert_same(0, (int) db_value('SELECT COUNT(*) FROM story_audios WHERE voice_profile_id = ?', [$pid]), '확인 전에 동화를 만들었다');
        $ok = StoryRequests::approve([$rid], null);
        assert_same(1, $ok['approved']);
        assert_same('making', StoryRequests::state(StoryRequests::latestFor($uid, $sid, $pid)));
        $drain();

        $profile = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$pid]);
        assert_same('completed', $profile['status']);
        assert_same('done', $profile['batch_status']);
        $req = db_one('SELECT * FROM story_requests WHERE id = ?', [$rid]);
        assert_same('approved', $req['status']);
        assert_true($req['completed_at'] !== null, 'completed_at 이 없다');
        assert_true($req['notified_at'] !== null, 'notified_at 이 없다');
        assert_same('done', StoryRequests::state(StoryRequests::latestFor($uid, $sid, $pid)));
        $doneMail = db_one("SELECT payload FROM jobs WHERE type = 'mail' AND ref_type = 'user' AND ref_id = ?", [$uid]);
        assert_true($doneMail !== null, '동화 완성 메일 작업이 없다');
        assert_contains("'테스트 달님 토끼' 동화가 완성되었어요", json_decode($doneMail['payload'], true)['subject']);

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
        assert_contains("회원이 '테스트 달님 토끼' 생성을 요청했습니다 (1건)", $log);
        assert_contains(StoryRequests::reqId($rid) . " '테스트 달님 토끼' 생성 시작", $log);
        assert_contains("'테스트 달님 토끼' 오디오 생성 완료", $log);

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

/** 다른 목소리가 ElevenLabs 자리 계산에 섞이지 않도록 트랜잭션 안에서 숨긴다(되돌릴 때 원래대로). */
function voice_test_hide_slots(): void
{
    db_exec("UPDATE voice_profiles SET provider_deleted_at = NOW() WHERE provider_voice_id IS NOT NULL AND provider_voice_id <> '' AND provider_deleted_at IS NULL");
}

test('ElevenLabs 목소리 자리가 다 차면 가장 오래 쓰지 않은 목소리의 자리를 비운다(작업 중인 목소리는 건너뜀)', function () {
    voice_test_tx(function () {
        voice_test_hide_slots();
        Settings::set('elevenlabs.voice_slot_limit', 3);
        $make = function (string $label, string $usedAt) {
            $t = voice_test_profile(['status' => 'completed', 'label' => $label, 'provider_voice_id' => 'fake_' . bin2hex(random_bytes(6)), 'cloned_at' => $usedAt]);
            db_exec('UPDATE voice_profiles SET provider_last_used_at = ? WHERE id = ?', [$usedAt, $t['profile_id']]);

            return $t['profile_id'];
        };
        $oldest = $make('할머니', '2026-01-01 10:00:00');
        $older = $make('아빠', '2026-02-01 10:00:00');
        $recent = $make('엄마', '2026-09-01 10:00:00');
        $new = voice_test_profile(['status' => 'cloning', 'label' => '삼촌'])['profile_id'];
        assert_same(3, VoiceService::slotsUsed());

        // 가장 오래된 목소리는 동화를 만드는 중이라 건너뛰고, 그다음으로 오래된 목소리의 자리를 비운다.
        Jobs::enqueue('story_tts', ['profile_id' => $oldest, 'story_id' => 1], ['ref_type' => 'voice_profile', 'ref_id' => $oldest]);
        assert_same([$older], VoiceService::ensureSlot($new));
        $p = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$older]);
        assert_same(null, $p['provider_voice_id']);
        assert_same('completed', $p['status']);
        assert_true($p['provider_released_at'] !== null, 'provider_released_at 이 없다');
        assert_true(VoiceService::isReleased($p), '자리 비움으로 보이지 않는다');
        assert_same(2, VoiceService::slotsUsed());

        // 자리가 남으면 비우지 않는다.
        assert_same([], VoiceService::ensureSlot($new));
        assert_same($recent, (int) db_value("SELECT id FROM voice_profiles WHERE id = ? AND provider_voice_id <> ''", [$recent]));

        // 자리 비운 목소리도 동화를 요청할 수 있는 목소리로 센다.
        assert_same(1, (int) db_value('SELECT COUNT(*) FROM voice_profiles vp WHERE vp.id = ? AND ' . VoiceService::readySql('vp'), [$older]));

        // 모두 작업 중이면 기다린다(시도 횟수를 쓰지 않는 RetryLater).
        Settings::set('elevenlabs.voice_slot_limit', 1);
        Jobs::enqueue('voice_clips', ['profile_id' => $recent], ['ref_type' => 'voice_profile', 'ref_id' => $recent]);
        $thrown = null;
        try {
            VoiceService::ensureSlot($new);
        } catch (App\Services\RetryLater $e) {
            $thrown = $e;
        }
        assert_true($thrown !== null, 'RetryLater 가 나지 않았다');
    });
});

test('자리를 비운 목소리로 새 동화를 만들면 녹음으로 다시 만들고, 만든 동화와 안내 음성은 그대로 둔다', function () {
    if (!config('providers_fake') || !class_exists('App\\Services\\FakeAudio')) {
        skip_test('providers_fake 설정이 꺼져 있다');
    }
    voice_test_tx(function () {
        voice_test_hide_slots();
        Settings::set('elevenlabs.voice_slot_limit', 1);
        $drain = function () {
            for ($i = 0; $i < 40; $i++) {
                $due = (int) db_value("SELECT COUNT(*) FROM jobs WHERE status IN ('pending', 'running') AND available_at <= NOW() AND ref_type = 'voice_profile' AND ref_id IN (" . implode(', ', array_map('intval', $GLOBALS['__voice_test_profiles'])) . ')');
                if ($due === 0) {
                    break;
                }
                $r = Worker::run(60);
                if (!empty($r['locked'])) {
                    usleep(300000);
                }
            }
        };
        $a = voice_test_profile(['status' => 'pending', 'label' => '엄마']);
        voice_test_sample($a['profile_id'], 70000);
        $s1 = voice_test_story();
        $s2 = voice_test_story(['두 번째 동화 첫 문장이에요.', '두 번째 동화 끝이에요.']);
        VoiceService::approve($a['profile_id'], null);
        $drain();
        $res = StoryRequests::create($a['user_id'], $s1, [$a['profile_id']]);
        StoryRequests::approve($res['created'], null);
        $drain();
        $first = db_one('SELECT * FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$s1, $a['profile_id']]);
        assert_same('completed', $first['status']);
        $clipCount = (int) db_value("SELECT COUNT(*) FROM voice_clips WHERE voice_profile_id = ? AND status = 'completed'", [$a['profile_id']]);
        assert_true($clipCount > 0, '안내 음성이 없다');

        // 다른 회원의 목소리가 만들어지면 자리가 하나뿐이라 엄마 목소리의 자리를 비운다.
        $b = voice_test_profile(['status' => 'pending', 'label' => '아빠']);
        voice_test_sample($b['profile_id'], 70000);
        VoiceService::approve($b['profile_id'], null);
        $drain();
        $pa = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$a['profile_id']]);
        assert_true(VoiceService::isReleased($pa), '엄마 목소리 자리를 비우지 않았다');
        assert_true((string) db_value('SELECT provider_voice_id FROM voice_profiles WHERE id = ?', [$b['profile_id']]) !== '', '아빠 목소리가 없다');
        // 만든 동화는 그대로 재생할 수 있다.
        assert_true(Storage::exists($first['file_path']), '만든 동화 파일이 사라졌다');
        assert_same('done', StoryRequests::state(StoryRequests::latestFor($a['user_id'], $s1, $a['profile_id'])));

        // 엄마 목소리로 새 동화를 요청하고 생성을 시작하면 다시 만들고(아빠 자리를 비움) 동화를 만든다.
        $res = StoryRequests::create($a['user_id'], $s2, [$a['profile_id']]);
        assert_same(1, count($res['created']), json_encode($res['skipped'], JSON_UNESCAPED_UNICODE));
        $ok = StoryRequests::approve($res['created'], null);
        assert_same(1, $ok['approved'], json_encode($ok['errors'], JSON_UNESCAPED_UNICODE));
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'voice_clone' AND status = 'pending' AND ref_id = ?", [$a['profile_id']]));
        $drain();
        $pa = db_one('SELECT * FROM voice_profiles WHERE id = ?', [$a['profile_id']]);
        assert_true(strpos((string) $pa['provider_voice_id'], 'fake_') === 0, '다시 만들지 않았다');
        assert_same(null, $pa['provider_released_at']);
        assert_same('completed', $pa['status']);
        assert_true(VoiceService::isReleased(db_one('SELECT * FROM voice_profiles WHERE id = ?', [$b['profile_id']])), '아빠 목소리 자리를 비우지 않았다');
        assert_same('done', StoryRequests::state(StoryRequests::latestFor($a['user_id'], $s2, $a['profile_id'])));
        // 첫 동화는 다시 만들지 않았고 안내 음성도 그대로다.
        $again = db_one('SELECT * FROM story_audios WHERE story_id = ? AND voice_profile_id = ?', [$s1, $a['profile_id']]);
        assert_same($first['generated_at'], $again['generated_at']);
        assert_same($clipCount, (int) db_value("SELECT COUNT(*) FROM voice_clips WHERE voice_profile_id = ? AND status = 'completed'", [$a['profile_id']]));
        $failed = db_all("SELECT type, last_error FROM jobs WHERE ref_type = 'voice_profile' AND ref_id IN (?, ?) AND status = 'failed'", [$a['profile_id'], $b['profile_id']]);
        assert_same([], $failed, '실패한 작업');
        $log = implode("\n", array_column(Jobs::latestLogs('voice_profile', $a['profile_id']), 'message'));
        assert_contains('ElevenLabs 목소리 다시 만들기 완료', $log);
    });
});
