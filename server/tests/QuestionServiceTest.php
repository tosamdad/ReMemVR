<?php
use App\Core\RateLimiter;
use App\Core\Settings;
use App\Core\Storage;
use App\Services\QaModel;
use App\Services\QaPrompt;
use App\Services\QuestionService;

/** 앱이 폰 안에서 글로 바꿔 보낸 질문 문장(테스트용) */
const QS_Q = '토끼는 왜 달님을 좋아해요?';

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
        $r = QuestionService::ask($f->user, $s, QS_Q, 3, 9500);
        assert_same(true, $r['ok']);
        assert_same('answer', $r['mode']);
        assert_same(QS_Q, $r['question_text']);
        assert_contains('달님과 아기 토끼', $r['question_text'] . $r['answer_text'], '가짜 답은 동화 제목을 말한다');
        assert_true(mb_strlen($r['answer_text']) <= (int) setting('qa.max_answer_chars'), '답 길이 제한');
        assert_same(url('/media/answer/' . $r['interaction_id']), $r['audio_url']);
        assert_same((int) setting('qa.max_questions') - 1, $r['remaining']);
        assert_true(is_int($r['latency_ms']));

        $i = db_one('SELECT * FROM interactions WHERE id = ?', [$r['interaction_id']]);
        assert_same('answer', $i['mode']);
        assert_same(3, (int) $i['sentence_seq']);
        assert_same(9500, (int) $i['position_ms']);
        assert_same(null, $i['question_audio_path'], '아이 음성은 받지도 저장하지도 않는다');
        assert_same(QS_Q, $i['question_text']);
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
        assert_same(['anthropic', 'answer_llm', 'interaction', (string) $r['interaction_id']], [$logs[0]['provider'], $logs[0]['purpose'], $logs[0]['ref_type'], (string) $logs[0]['ref_id']]);
        assert_same(['elevenlabs', 'answer_tts'], [$logs[1]['provider'], $logs[1]['purpose']]);
    });
});

test('기기 음성 재생(목소리 없음): 답 음성 없이 글만 돌려준다', function () {
    qs_run(function (QsFixture $f) {
        $s = $f->session(null);
        $r = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
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
        $r1 = QuestionService::ask($f->user, $s, QS_Q, 4, 20000);
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
        $r2 = QuestionService::ask($f->user, $s, QS_Q, 4, 21000);
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
        $r1 = QuestionService::ask($f->user, $s, QS_Q, 2, 1000);
        assert_same('answer', $r1['mode']);
        assert_same(0, $r1['remaining']);
        $r2 = QuestionService::ask($f->user, $s, QS_Q, 2, 2000);
        assert_same('quota', $r2['mode']);
        assert_same('이 동화는 끝까지 듣고 이야기하자!', $r2['answer_text'], '공백을 정리한 동화별 문장');
    });
});

test('끼어들기 꺼짐: 동화별 설정, 전체 설정 모두 disabled', function () {
    qs_run(function (QsFixture $f) {
        db_update('stories', ['barge_in_enabled' => 0], 'id = ?', [$f->storyId]);
        $s = $f->session($f->profileId);
        $r = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
        assert_same('disabled', $r['mode']);
        assert_same(true, $r['ok']);
        assert_true($r['answer_text'] !== '');
        assert_same((int) setting('qa.max_questions'), $r['remaining']);

        db_update('stories', ['barge_in_enabled' => 1], 'id = ?', [$f->storyId]);
        $f->setSetting('qa.enabled', false);
        $r2 = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
        assert_same('disabled', $r2['mode']);
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
        $i = db_one('SELECT * FROM interactions WHERE id = ?', [$r2['interaction_id']]);
        assert_same('disabled', $i['mode']);
        assert_same(null, $i['question_text'], '꺼져 있으면 질문 문장도 남기지 않는다');
    });
});

test('일일 예산 도달: budget 모드와 오류 안내 문장, 질문 횟수는 그대로', function () {
    qs_run(function (QsFixture $f) {
        // 오늘 비용이 예산 이상이 되게 만든다(이 회원 기록이라 정리 때 지워진다).
        App\Services\Usage::log(['provider' => 'gemini', 'purpose' => 'test_budget', 'user_id' => $f->userId, 'unit_type' => 'tokens', 'units' => 1, 'cost_usd' => 0.01]);
        $f->setSetting('cost.daily_budget_krw', 1);
        $s = $f->session($f->profileId);
        $r = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
        assert_same('budget', $r['mode']);
        assert_true(in_array($r['answer_text'], QuestionService::errorLines(), true), '오류 안내 문장');
        assert_same((int) setting('qa.max_questions'), $r['remaining']);
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
        assert_same(1, (int) db_value('SELECT COUNT(*) FROM api_usage_logs WHERE user_id = ?', [$f->userId]), '답변 AI 를 부르지 않는다');
    });
});

test('알아듣기 어려운 질문은 되묻고 횟수를 빼지 않는다, 요청 과다는 error 모드', function () {
    qs_run(function (QsFixture $f) {
        $s = $f->session($f->profileId);
        $r = QuestionService::ask($f->user, $s, ' 어 ', 1, 0);
        assert_same(true, $r['ok']);
        assert_same('answer', $r['mode']);
        assert_contains('다시 한 번', $r['answer_text']);
        assert_same((int) setting('qa.max_questions'), $r['remaining'], '되물음은 질문 횟수에서 뺀다');
        assert_true($r['interaction_id'] > 0, '기록한다');

        for ($k = 0; $k < 20; $k++) {
            RateLimiter::hit('qa:' . $f->userId, 20, 600);
        }
        $r3 = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
        assert_same('error', $r3['mode']);
        assert_contains('한도', (string) db_value('SELECT error_message FROM interactions WHERE id = ?', [$r3['interaction_id']]));
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
    });
});

test('금지어가 나오면 답을 화제 전환 문장으로 바꾼다', function () {
    qs_run(function (QsFixture $f) {
        $f->setSetting('qa.blocked_words', ['토끼']);
        $s = $f->session(null);
        $r = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
        assert_same('answer', $r['mode']);
        assert_same(QaPrompt::safeRedirect(), $r['answer_text']);
        assert_same(0, (int) db_value("SELECT COUNT(*) FROM api_usage_logs WHERE user_id = ? AND purpose = 'answer_llm'", [$f->userId]), '질문에 금지어가 있으면 답변 AI 에 보내지 않는다');
    });
});

test('답 줄이기: 문장 끝, 낱말 경계에서 자른다', function () {
    assert_same('짧은 답이야.', QuestionService::trimAnswer('  짧은   답이야. ', 50));
    assert_same('첫 문장이야. 둘째 문장도 있어!', QuestionService::trimAnswer('첫 문장이야. 둘째 문장도 있어! 셋째 문장은 아주 길어서 잘려야 해.', 30));
    $cut = QuestionService::trimAnswer('마침표 없이 계속 이어지는 아주 긴 답변 문장 하나', 20);
    assert_true(mb_strlen($cut) <= 21 && mb_substr($cut, -1) === '…', $cut);
});

test('시간 초과: 준비된 말로 답하고 질문 횟수는 빼지 않는다', function () {
    qs_run(function (QsFixture $f) {
        $f->setSetting('qa.timeout_ms', 2000);
        $f->setSetting('qa.fake_delay_ms', 5000);
        $s = $f->session($f->profileId);
        $line = QuestionService::timeoutLines()[0];
        $clipId = db_insert('voice_clips', [
            'voice_profile_id' => $f->profileId, 'kind' => 'timeout', 'text' => $line,
            'text_hash' => hash('sha256', $line), 'status' => 'completed', 'file_path' => 'clips/t.mp3',
        ]);
        $started = microtime(true);
        $r = QuestionService::ask($f->user, $s, QS_Q, 2, 3000);
        $ms = (microtime(true) - $started) * 1000;
        assert_same('timeout', $r['mode']);
        assert_same($line, $r['answer_text']);
        assert_same(url('/media/clip/' . $clipId), $r['audio_url']);
        assert_same((int) setting('qa.max_questions'), $r['remaining']);
        assert_true($ms < 2000 - QuestionService::NETWORK_MARGIN_MS + 300, '앱 제한 시간보다 먼저 답한다(' . round($ms) . 'ms)');
        $i = db_one('SELECT * FROM interactions WHERE id = ?', [$r['interaction_id']]);
        assert_same('timeout', $i['mode']);
        assert_same(QS_Q, $i['question_text']);
        assert_contains('시간 초과', (string) $i['error_message']);
        assert_same(0, (int) db_value('SELECT question_count FROM play_sessions WHERE id = ?', [$s['id']]));
    });
});

test('답변 AI 고르기: Groq, Gemini 로 바꾸면 그 이름으로 기록한다', function () {
    qs_run(function (QsFixture $f) {
        assert_same('claude', QaModel::provider(), '기본은 Claude');
        assert_same('claude-haiku-5-5', QaModel::model());
        $f->setSetting('qa.llm_provider', 'gemini');
        assert_same('gemini', QaModel::provider());
        $s = $f->session(null);
        $r = QuestionService::ask($f->user, $s, QS_Q, 1, 0);
        assert_same('answer', $r['mode']);
        assert_same('gemini', (string) db_value("SELECT provider FROM api_usage_logs WHERE user_id = ? AND purpose = 'answer_llm'", [$f->userId]));
        $f->setSetting('qa.llm_provider', 'groq');
        assert_same('openai/gpt-oss-120b', QaModel::model());
        assert_same('Groq', QaModel::label());
        $r2 = QuestionService::ask($f->user, $f->session(null), QS_Q, 1, 0);
        assert_same('answer', $r2['mode']);
        assert_same(1, (int) db_value("SELECT COUNT(*) FROM api_usage_logs WHERE user_id = ? AND purpose = 'answer_llm' AND provider = 'groq'", [$f->userId]));
        $f->setSetting('qa.llm_provider', 'nope');
        assert_same('claude', QaModel::provider(), '모르는 값이면 Claude');
    });
});

test('질문 문장 정리: 공백, 길이, 개인정보로 보이는 부분', function () {
    $c = QaPrompt::cleanQuestion("  엄마  전화번호는 010-1234-5678 이야\n");
    assert_same('엄마 전화번호는 ○○ 이야', $c['text']);
    assert_same(true, $c['masked']);
    $c2 = QaPrompt::cleanQuestion('a@b.co 로 보내 줘');
    assert_same('○○ 로 보내 줘', $c2['text']);
    $c3 = QaPrompt::cleanQuestion(str_repeat('가', 500));
    assert_same(QaPrompt::MAX_QUESTION_CHARS, mb_strlen($c3['text']));
    assert_same(false, $c3['masked']);
});

test('모델 답 읽기: 끊긴 JSON 에서도 답을 건지고 조사를 맞춘다', function () {
    $o = QaPrompt::readOutput('{"answer": "우리 친구, 토끼는 달님이 좋아서 밤마다", "emotion": "호기심"');
    assert_same('우리 친구, 토끼는 달님이 좋아서 밤마다', $o['answer']);
    $o2 = QaPrompt::readOutput("```json\n{\"answer\":\"좋은 질문이야!\",\"emotion\":\"놀람\",\"on_topic\":true,\"unsafe\":false,\"unclear\":false}\n```");
    assert_same('좋은 질문이야!', $o2['answer']);
    assert_same('기타', $o2['emotion'], '목록에 없는 감정은 기타');
    assert_same(null, QaPrompt::salvage('{"answer": "'));
    assert_same('그냥 글 답이야.', QaPrompt::salvage(' 그냥 글 답이야. ')['answer']);
    assert_same('엄마가', QaPrompt::josa('엄마', '이', '가'));
    assert_same('할아버지야', QaPrompt::josa('할아버지', '이야', '야'));
    assert_same('「달님과 아기 토끼」를', QaPrompt::josa('「달님과 아기 토끼」', '을', '를'));
    assert_same('「곰」을', QaPrompt::josa('「곰」', '을', '를'));
});

test('프롬프트에 아이 실명을 넣지 않는다', function () {
    $ctx = QaPrompt::normalizeContext(['story_title' => '달님과 아기 토끼', 'persona' => '엄마', 'child_name' => '서연', 'child_age' => 5, 'current_sentence' => '토끼가 인사했어요.']);
    $all = QaPrompt::systemPrompt($ctx) . QaPrompt::contextPrompt($ctx, QS_Q);
    assert_true(strpos($all, '서연') === false, '실명 없음');
    assert_contains('우리 친구', $all);
});
