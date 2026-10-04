<?php
use App\Core\RateLimiter;
use App\Core\Settings;
use App\Core\Storage;
use App\Services\FakeAudio;
use App\Services\QuestionService;

/**
 * 질문 처리 전체 흐름(가짜 API 모드, 테스트 DB).
 * 만든 회원, 동화, 기록, 파일, 바꾼 설정은 테스트가 끝나면 모두 되돌린다.
 */
final class QsFixture
{
    public $userId;
    public $user;
    public $childId;
    public $storyId;
    public $profileId;
    /** @var array<string, mixed> 바꾼 설정의 원래 저장값(null 이면 저장값 없음) */
    private $savedSettings = [];

    public static function create(): self
    {
        $f = new self();
        $tag = bin2hex(random_bytes(4));
        $f->userId = db_insert('users', ['email' => 'a1qs-' . $tag . '@example.com', 'password_hash' => 'x', 'name' => '테스트 보호자']);
        $f->user = db_one('SELECT * FROM users WHERE id = ?', [$f->userId]);
        $f->childId = db_insert('children', ['user_id' => $f->userId, 'name' => '서연', 'birth_date' => date('Y-m-d', strtotime('-5 years -2 months'))]);
        $f->storyId = db_insert('stories', [
            'title' => '달님과 아기 토끼', 'body' => '본문', 'status' => 'published', 'barge_in_enabled' => 1,
        ]);
        $lines = ['옛날 옛적에 아기 토끼가 살았어요.', '토끼는 밤마다 달님을 바라보았어요.', '“달님, 안녕?” 토끼가 인사했어요.', '달님은 환하게 웃었어요.', '그리고 둘은 친구가 되었답니다.'];
        foreach ($lines as $i => $l) {
            db_insert('story_sentences', ['story_id' => $f->storyId, 'seq' => $i + 1, 'content' => $l]);
        }
        $f->profileId = db_insert('voice_profiles', [
            'user_id' => $f->userId, 'label' => '엄마', 'status' => 'completed', 'provider_voice_id' => 'fake_' . $tag, 'stability' => 0.5,
        ]);
        // 질문 기능은 기본값이 꺼짐이므로 테스트에서는 켜 둔다(가짜 API 모드에서만 실제로 켜진다).
        $f->setSetting('qa.enabled', true);

        return $f;
    }

    public function session(?int $profileId, array $extra = []): array
    {
        $id = db_insert('play_sessions', array_merge([
            'user_id' => $this->userId, 'child_id' => $this->childId, 'story_id' => $this->storyId,
            'voice_profile_id' => $profileId, 'audio_source' => $profileId ? 'voice' : 'device',
        ], $extra));

        return db_one('SELECT * FROM play_sessions WHERE id = ?', [$id]);
    }

    /** 질문 음성 임시 파일(가짜 합성 WAV, 약 1.5초) */
    public function audio(string $text = '토끼는 왜 달님을 좋아해요?'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'rmq');
        file_put_contents($tmp, FakeAudio::speech($text)['audio']);

        return $tmp;
    }

    public function setSetting(string $key, $value): void
    {
        if (!array_key_exists($key, $this->savedSettings)) {
            $raw = db_value('SELECT v FROM settings WHERE k = ?', [$key]);
            $this->savedSettings[$key] = $raw === null ? null : json_decode((string) $raw, true);
        }
        Settings::set($key, $value);
    }

    public function cleanup(): void
    {
        foreach ($this->savedSettings as $k => $v) {
            if ($v === null) {
                Settings::forget($k);
            } else {
                Settings::set($k, $v);
            }
        }
        $files = db_all(
            'SELECT i.question_audio_path, i.answer_audio_path FROM interactions i JOIN play_sessions p ON p.id = i.play_session_id WHERE p.user_id = ?',
            [$this->userId]
        );
        foreach ($files as $r) {
            Storage::delete($r['question_audio_path']);
            Storage::delete($r['answer_audio_path']);
        }
        RateLimiter::clear('qa:' . $this->userId);
        db_exec('DELETE FROM api_usage_logs WHERE user_id = ?', [$this->userId]);
        db_exec('DELETE FROM users WHERE id = ?', [$this->userId]);
        db_exec('DELETE FROM stories WHERE id = ?', [$this->storyId]);
    }
}

function qs_run(callable $fn): void
{
    if (!config('providers_fake')) {
        skip_test('providers_fake 가 꺼져 있어 실제 API 를 부르지 않는다');
    }
    test_db();
    $f = QsFixture::create();
    try {
        $fn($f);
    } finally {
        $f->cleanup();
    }
}

test('답변: 가족 목소리로 합성하고 질문 횟수, 기록, 사용량을 남긴다', function () {
    qs_run(function (QsFixture $f) {
        $s = $f->session($f->profileId);
        $r = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 3, 9500);
        assert_same(true, $r['ok']);
        assert_same('answer', $r['mode']);
        assert_true($r['question_text'] !== null && $r['question_text'] !== '', '질문 글');
        assert_contains('달님과 아기 토끼', $r['question_text'] . $r['answer_text'], '가짜 답은 동화 제목을 말한다');
        assert_true(mb_strlen($r['answer_text']) <= (int) setting('qa.max_answer_chars'), '답 길이 제한');
        assert_same(url('/media/answer/' . $r['interaction_id']), $r['audio_url']);
        assert_same((int) setting('qa.max_questions') - 1, $r['remaining']);
        assert_true(is_int($r['latency_ms']));

        $i = db_one('SELECT * FROM interactions WHERE id = ?', [$r['interaction_id']]);
        assert_same('answer', $i['mode']);
        assert_same(3, (int) $i['sentence_seq']);
        assert_same(9500, (int) $i['position_ms']);
        assert_true(Storage::exists($i['question_audio_path']), '질문 음성 저장');
        assert_same(0, strpos($i['question_audio_path'], 'questions/' . date('Ym') . '/'));
        assert_true(Storage::exists($i['answer_audio_path']), '답 음성 저장');
        assert_same(0, strpos($i['answer_audio_path'], 'answers/' . date('Ym') . '/'));
        assert_same('호기심', $i['emotion']);
        assert_true((int) $i['llm_input_tokens'] > 0 && (int) $i['llm_output_tokens'] > 0, '토큰');
        assert_same(mb_strlen($r['answer_text']), (int) $i['tts_chars']);
        assert_true((float) $i['cost_krw'] > 0, '비용');
        assert_true($i['llm_ms'] !== null && $i['tts_ms'] !== null, 'LLM, TTS 시간');
        assert_same(1, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));

        $logs = db_all('SELECT provider, purpose, ref_type, ref_id FROM api_usage_logs WHERE user_id = ? ORDER BY id', [$f->userId]);
        assert_same(2, count($logs));
        assert_same(['gemini', 'answer_llm', 'interaction', (string) $r['interaction_id']], [$logs[0]['provider'], $logs[0]['purpose'], $logs[0]['ref_type'], (string) $logs[0]['ref_id']]);
        assert_same(['elevenlabs', 'answer_tts'], [$logs[1]['provider'], $logs[1]['purpose']]);
    });
});

test('기기 음성 재생(목소리 없음): 답 음성 없이 글만 돌려준다', function () {
    qs_run(function (QsFixture $f) {
        $s = $f->session(null);
        $r = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 1, 0);
        assert_same('answer', $r['mode']);
        assert_same(null, $r['audio_url']);
        assert_true($r['answer_text'] !== '');
        $i = db_one('SELECT * FROM interactions WHERE id = ?', [$r['interaction_id']]);
        assert_same(null, $i['answer_audio_path']);
        assert_same(0, (int) $i['tts_chars']);
    });
});

test('질문 한도: 대체 문장을 돌아가며 내고 fallback_count 를 늘린다', function () {
    qs_run(function (QsFixture $f) {
        $max = (int) setting('qa.max_questions');
        $s = $f->session($f->profileId, ['question_count' => $max]);
        $lines = QuestionService::fallbackLines(db_one('SELECT * FROM stories WHERE id = ?', [$f->storyId]));
        $r1 = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 4, 20000);
        assert_same('quota', $r1['mode']);
        assert_same($lines[0], $r1['answer_text']);
        assert_same(0, $r1['remaining']);
        assert_same(null, $r1['audio_url'], '미리 만든 음성이 없으면 null');
        // 미리 만든 목소리 음성이 있으면 그 주소를 준다.
        $line2 = $lines[1 % count($lines)];
        $clipId = db_insert('voice_clips', [
            'voice_profile_id' => $f->profileId, 'kind' => 'fallback', 'text' => $line2,
            'text_hash' => hash('sha256', $line2), 'status' => 'completed', 'file_path' => 'clips/x.mp3',
        ]);
        $r2 = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 4, 21000);
        assert_same('quota', $r2['mode']);
        assert_same($line2, $r2['answer_text']);
        assert_same(url('/media/clip/' . $clipId), $r2['audio_url']);
        $row = db_one('SELECT question_count, fallback_count FROM play_sessions WHERE id = ?', [$s['id']]);
        assert_same($max, (int) $row['question_count']);
        assert_same(2, (int) $row['fallback_count']);
        assert_same(2, (int) db_value("SELECT COUNT(*) FROM interactions WHERE play_session_id = ? AND mode = 'quota'", [$s['id']]));
        assert_same(0, (int) db_value('SELECT COUNT(*) FROM api_usage_logs WHERE user_id = ?', [$f->userId]), '한도 초과는 API 를 부르지 않는다');
    });
});

test('질문 한도: 동화별 최대 횟수와 대체 문장이 전체 설정보다 우선한다', function () {
    qs_run(function (QsFixture $f) {
        db_update('stories', ['max_questions' => 1, 'fallback_lines' => json_encode_u(['이 동화는 끝까지 듣고  이야기하자!'])], 'id = ?', [$f->storyId]);
        $s = $f->session($f->profileId);
        $r1 = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 2, 1000);
        assert_same('answer', $r1['mode']);
        assert_same(0, $r1['remaining']);
        $r2 = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 2, 2000);
        assert_same('quota', $r2['mode']);
        assert_same('이 동화는 끝까지 듣고 이야기하자!', $r2['answer_text'], '공백을 정리한 동화별 문장');
    });
});

test('끼어들기 꺼짐: 동화별 설정, 전체 설정 모두 disabled', function () {
    qs_run(function (QsFixture $f) {
        db_update('stories', ['barge_in_enabled' => 0], 'id = ?', [$f->storyId]);
        $s = $f->session($f->profileId);
        $tmp = $f->audio();
        $r = QuestionService::ask($f->user, $s, $tmp, 'audio/wav', 1, 0);
        assert_same('disabled', $r['mode']);
        assert_true(!is_file($tmp), '받은 임시 녹음 파일은 지운다');
        assert_same(true, $r['ok']);
        assert_true($r['answer_text'] !== '');
        assert_same((int) setting('qa.max_questions'), $r['remaining']);

        db_update('stories', ['barge_in_enabled' => 1], 'id = ?', [$f->storyId]);
        $f->setSetting('qa.enabled', false);
        $r2 = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 1, 0);
        assert_same('disabled', $r2['mode']);
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
        $i = db_one('SELECT * FROM interactions WHERE id = ?', [$r2['interaction_id']]);
        assert_same('disabled', $i['mode']);
        assert_same(null, $i['question_audio_path'], '꺼져 있으면 아이 음성을 저장하지 않는다');
    });
});

test('일일 예산 도달: budget 모드와 오류 안내 문장, 질문 횟수는 그대로', function () {
    qs_run(function (QsFixture $f) {
        // 오늘 비용이 예산 이상이 되게 만든다(이 회원 기록이라 정리 때 지워진다).
        App\Services\Usage::log(['provider' => 'gemini', 'purpose' => 'test_budget', 'user_id' => $f->userId, 'unit_type' => 'tokens', 'units' => 1, 'cost_usd' => 0.01]);
        $f->setSetting('cost.daily_budget_krw', 1);
        $s = $f->session($f->profileId);
        $r = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 1, 0);
        assert_same('budget', $r['mode']);
        assert_true(in_array($r['answer_text'], QuestionService::errorLines(), true), '오류 안내 문장');
        assert_same((int) setting('qa.max_questions'), $r['remaining']);
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
        assert_same(1, (int) db_value('SELECT COUNT(*) FROM api_usage_logs WHERE user_id = ?', [$f->userId]), 'Gemini 를 부르지 않는다');
    });
});

test('잘못된 음성, 요청 과다는 error 모드로 답하고 예외를 던지지 않는다', function () {
    qs_run(function (QsFixture $f) {
        $s = $f->session($f->profileId);
        $tmp = tempnam(sys_get_temp_dir(), 'rmq');
        file_put_contents($tmp, 'x');
        $r = QuestionService::ask($f->user, $s, $tmp, 'audio/wav', 1, 0);
        assert_same(true, $r['ok']);
        assert_same('error', $r['mode']);
        assert_true(in_array($r['answer_text'], QuestionService::errorLines(), true));
        assert_true($r['interaction_id'] > 0, '오류도 기록한다');

        $r2 = QuestionService::ask($f->user, $s, '/nonexistent/audio.wav', 'audio/wav', 1, 0);
        assert_same('error', $r2['mode']);

        for ($k = 0; $k < 20; $k++) {
            RateLimiter::hit('qa:' . $f->userId, 20, 600);
        }
        $r3 = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 1, 0);
        assert_same('error', $r3['mode']);
        assert_contains('한도', (string) db_value('SELECT error_message FROM interactions WHERE id = ?', [$r3['interaction_id']]));
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
    });
});

test('금지어가 나오면 답을 화제 전환 문장으로 바꾼다', function () {
    qs_run(function (QsFixture $f) {
        $f->setSetting('qa.blocked_words', ['토끼']);
        $s = $f->session(null);
        $r = QuestionService::ask($f->user, $s, $f->audio(), 'audio/wav', 1, 0);
        assert_same('answer', $r['mode']);
        assert_same(App\Services\Gemini::safeRedirect(), $r['answer_text']);
    });
});

test('답 줄이기: 문장 끝, 낱말 경계에서 자른다', function () {
    assert_same('짧은 답이야.', QuestionService::trimAnswer('  짧은   답이야. ', 50));
    assert_same('첫 문장이야. 둘째 문장도 있어!', QuestionService::trimAnswer('첫 문장이야. 둘째 문장도 있어! 셋째 문장은 아주 길어서 잘려야 해.', 30));
    $cut = QuestionService::trimAnswer('마침표 없이 계속 이어지는 아주 긴 답변 문장 하나', 20);
    assert_true(mb_strlen($cut) <= 21 && mb_substr($cut, -1) === '…', $cut);
});

test('음성 형식은 파일 머리로 알아낸다', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'rms');
    $cases = [
        'wav' => FakeAudio::speech('가')['audio'],
        'webm' => "\x1A\x45\xDF\xA3" . str_repeat("\x00", 20),
        'ogg' => 'OggS' . str_repeat("\x00", 20),
        'm4a' => "\x00\x00\x00\x20ftypM4A " . str_repeat("\x00", 20),
        'mp3' => 'ID3' . str_repeat("\x00", 20),
    ];
    try {
        foreach ($cases as $ext => $bytes) {
            file_put_contents($tmp, $bytes);
            assert_same($ext, QuestionService::sniffAudioExt($tmp));
        }
        file_put_contents($tmp, str_repeat('a', 30));
        assert_same(null, QuestionService::sniffAudioExt($tmp));
    } finally {
        @unlink($tmp);
    }
});

test('Gemini 응답이 끊긴 JSON 이어도 답을 건지고, 이름 부르기와 조사를 맞춘다', function () {
    $g = App\Services\Gemini::salvage('{"question": "토끼는 왜 달을 좋아해?", "answer": "서연아, 토끼는 달님이 좋아서 밤마다');
    assert_same('토끼는 왜 달을 좋아해?', $g['question']);
    assert_same('서연아, 토끼는 달님이 좋아서 밤마다', $g['answer']);
    assert_same(null, App\Services\Gemini::salvage('{"question": "뭐'));
    assert_same('그냥 글 답이야.', App\Services\Gemini::salvage(' 그냥 글 답이야. ')['answer']);
    assert_same('서연아', App\Services\Gemini::vocative('서연'));
    assert_same('지우야', App\Services\Gemini::vocative('지우'));
    assert_same('엄마가', App\Services\Gemini::josa('엄마', '이', '가'));
    assert_same('할아버지야', App\Services\Gemini::josa('할아버지', '이야', '야'));
    assert_same('「달님과 아기 토끼」를', App\Services\Gemini::josa('「달님과 아기 토끼」', '을', '를'));
    assert_same('「곰」을', App\Services\Gemini::josa('「곰」', '을', '를'));
});
