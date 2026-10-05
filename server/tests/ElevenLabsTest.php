<?php
use App\Services\ElevenLabs;
use App\Services\FakeAudio;

/** MPEG1 Layer III 128kbps 44.1kHz 프레임 $n 개(+ 선택: ID3 태그, Xing 프레임) */
function el_mp3_fixture(int $n, bool $id3 = false, bool $xing = false): string
{
    $frame = "\xFF\xFB\x90\x00" . str_repeat("\x00", 413); // 417바이트
    $out = '';
    if ($id3) {
        $out .= 'ID3' . "\x04\x00\x00" . "\x00\x00\x00\x14" . str_repeat("\x00", 20);
    }
    if ($xing) {
        $out .= "\xFF\xFB\x90\x00" . str_repeat("\x00", 32) . 'Xing' . str_repeat("\x00", 377);
    }

    return $out . str_repeat($frame, $n);
}

function el_require_fake(): void
{
    if (!config('providers_fake')) {
        skip_test('providers_fake 가 꺼져 있어 실제 API 를 부르지 않는다');
    }
}

test('MP3 길이: 프레임 수로 계산하고 ID3 태그, Xing 프레임은 세지 않는다', function () {
    assert_same(2612, ElevenLabs::mp3DurationMs(el_mp3_fixture(100)));
    assert_same(2612, ElevenLabs::mp3DurationMs(el_mp3_fixture(100, true, true)));
    assert_same(null, ElevenLabs::mp3DurationMs('not an mp3'));
});

test('MP3 잇기: 두 번째 조각부터 ID3 태그와 Xing 프레임을 뗀다', function () {
    $a = el_mp3_fixture(100, true, true);
    $joined = ElevenLabs::concatMp3([$a, $a, $a]);
    assert_same(strlen($a) + 2 * 100 * 417, strlen($joined));
    assert_same(7837, ElevenLabs::mp3DurationMs($joined));
});

test('긴 본문은 문장 경계에서 나누고 이어 붙이면 원문과 같다', function () {
    $sentence = '옛날 옛적 깊은 숲속에 마음씨 착한 아기 곰이 살았어요. ';
    $text = trim(str_repeat($sentence, 40)); // 약 1,300자
    $chunks = ElevenLabs::splitText($text, 300);
    assert_true(count($chunks) >= 5, '여러 조각으로 나뉜다');
    foreach ($chunks as $c) {
        assert_true(mb_strlen($c) <= 300, '조각 길이 ' . mb_strlen($c));
        assert_same('.', mb_substr($c, -1), '조각이 문장 끝에서 끝난다');
    }
    assert_same($text, implode(' ', $chunks));
    // 문장 부호가 없으면 공백에서 자른다.
    $words = trim(str_repeat('낱말 ', 200));
    foreach (ElevenLabs::splitText($words, 100) as $c) {
        assert_true(mb_strlen($c) <= 100 && mb_substr($c, -1) === '말', '공백에서 자른다');
    }
    assert_same(['짧은 글.'], ElevenLabs::splitText(' 짧은 글. '));
});

test('목소리 파라미터: 빈 값은 설정 기본값, 범위는 0~1', function () {
    $v = ElevenLabs::voiceSettings(['stability' => null, 'similarity_boost' => '1.7', 'style' => -0.2, 'use_speaker_boost' => '0']);
    assert_same(round((float) setting('elevenlabs.default_stability'), 2), $v['stability']);
    assert_same(1.0, $v['similarity_boost']);
    assert_same(0.0, $v['style']);
    assert_same(false, $v['use_speaker_boost']);
    $d = ElevenLabs::voiceSettings([]);
    assert_same((bool) setting('elevenlabs.speaker_boost'), $d['use_speaker_boost']);
    assert_same(round((float) setting('elevenlabs.default_similarity'), 2), $d['similarity_boost']);
});

test('API 오류를 한글 메시지로 바꾼다', function () {
    $r = function (int $status, $body) {
        return ['status' => $status, 'headers' => [], 'body' => is_string($body) ? $body : json_encode($body), 'error' => null];
    };
    assert_contains('API 키가 올바르지 않습니다', ElevenLabs::errorMessage($r(401, ['detail' => ['status' => 'invalid_api_key', 'message' => 'Invalid API key']])));
    assert_contains('API 키가 올바르지 않습니다', ElevenLabs::errorMessage($r(401, '')));
    assert_contains('크레딧이 부족합니다', ElevenLabs::errorMessage($r(401, ['detail' => ['status' => 'quota_exceeded', 'message' => 'quota']])));
    assert_contains('요청 한도', ElevenLabs::errorMessage($r(429, ['detail' => ['status' => 'rate_limited']])));
    assert_contains('목소리를 찾을 수 없습니다', ElevenLabs::errorMessage($r(400, ['detail' => ['status' => 'voice_not_found']])));
    assert_contains('네트워크 오류', ElevenLabs::errorMessage(['status' => 0, 'headers' => [], 'body' => '', 'error' => 'timeout']));
    assert_contains('서버 오류(503)', ElevenLabs::errorMessage($r(503, 'busy')));
    // 키는 맞지만 요금제에 목소리 복제가 없는 경우는 키 오류로 보이면 안 된다.
    $plan = ElevenLabs::errorMessage($r(401, ['detail' => ['status' => 'can_not_use_instant_voice_cloning', 'message' => 'Your subscription has no access to use instant voice cloning, please upgrade.']]));
    assert_contains('Starter 이상', $plan);
    assert_true(strpos($plan, 'API 키가 올바르지') === false, $plan);
    $other = ElevenLabs::errorMessage($r(401, ['detail' => ['status' => 'some_new_reason', 'message' => 'Something else']]));
    assert_contains('some_new_reason', $other);
    assert_contains('Something else', $other);
    assert_contains('요금제나 결제', ElevenLabs::errorMessage($r(402, ['detail' => ['message' => 'Payment required']])));
});

test('가짜 합성: 16kHz WAV, 글자당 75ms, 글자별 정렬', function () {
    el_require_fake();
    $text = '달님, 안녕? 오늘도 고마워요.';
    $res = ElevenLabs::synthesize('fake_voice', $text, ['with_timestamps' => true]);
    assert_true($res['ok'], (string) $res['error']);
    assert_same('wav', $res['ext']);
    assert_same('audio/wav', $res['mime']);
    $n = mb_strlen($text);
    assert_same($n, $res['chars']);
    assert_same($n * FakeAudio::MS_PER_CHAR, $res['duration_ms']);
    $w = FakeAudio::parseWav($res['audio']);
    assert_same(16000, $w['rate']);
    assert_same(1, $w['channels']);
    assert_same(16, $w['bits']);
    assert_same($n, count($res['alignment']['chars']));
    assert_same($res['duration_ms'] / 1000, end($res['alignment']['ends']));
    // 음량이 작고(클리핑 없음) 쉼표 자리는 조용하다.
    $samples = unpack('v*', $w['data']);
    $peak = 0;
    foreach ($samples as $s) {
        $s = $s >= 32768 ? $s - 65536 : $s;
        $peak = max($peak, abs($s));
    }
    assert_true($peak > 1000 && $peak < 8000, '최대 음량 ' . $peak);

    $plain = ElevenLabs::synthesize('fake_voice', $text);
    assert_same(null, $plain['alignment'], '타임스탬프를 요청하지 않으면 정렬이 없다');
});

test('가짜 합성: 길게 나눠 합성해도 오디오와 정렬 시각이 이어진다', function () {
    el_require_fake();
    $text = trim(str_repeat('아기 토끼가 깡충깡충 뛰었어요. ', 12));
    $res = ElevenLabs::synthesize('fake_voice', $text, ['with_timestamps' => true, 'chunk_chars' => 60]);
    assert_true($res['ok'], (string) $res['error']);
    $chunks = ElevenLabs::splitText($text, 60);
    assert_true(count($chunks) > 1, '여러 조각');
    // 조각 사이 공백은 오디오가 없으므로 전체 길이는 (전체 글자 - 이음 공백) × 75ms
    $expected = (mb_strlen($text) - (count($chunks) - 1)) * FakeAudio::MS_PER_CHAR;
    assert_same($expected, $res['duration_ms']);
    assert_same($expected, FakeAudio::wavDurationMs($res['audio']));
    $prev = 0.0;
    foreach ($res['alignment']['starts'] as $i => $st) {
        assert_true($st + 1e-6 >= $prev, '정렬 시각이 거꾸로 간다(' . $i . ')');
        $prev = $st;
    }
    assert_same(mb_strlen($text), count($res['alignment']['chars']));
    $t = App\Services\Alignment::build([['seq' => 1, 'content' => $text]], $res['alignment'], $res['duration_ms']);
    assert_same(count(App\Core\Text::words($text)), count($t['sentences'][0]['words']));
});

test('가짜 합성은 사용량을 글자 수와 모델 비율로 기록한다', function () {
    el_require_fake();
    test_db();
    $ref = random_int(100000000, 900000000);
    try {
        ElevenLabs::synthesize('fake_voice', '안녕하세요', [
            'model_id' => 'eleven_flash_v2_5',
            'usage' => ['purpose' => 'test_tts', 'ref_type' => 'a1_test', 'ref_id' => $ref],
        ]);
        $row = db_one('SELECT * FROM api_usage_logs WHERE ref_type = ? AND ref_id = ?', ['a1_test', $ref]);
        assert_true($row !== null, '사용량 기록');
        assert_same('chars', $row['unit_type']);
        assert_same(5, (int) $row['units']);
        assert_same('eleven_flash_v2_5', $row['model']);
        assert_true(abs((float) $row['cost_usd'] - App\Services\Usage::elevenlabsCostUsd(5, 'eleven_flash_v2_5')) < 1e-6, '비용');
    } finally {
        db_exec('DELETE FROM api_usage_logs WHERE ref_type = ? AND ref_id = ?', ['a1_test', $ref]);
    }
});

test('가짜 모드: 목소리 생성, 삭제, 구독 정보', function () {
    el_require_fake();
    $tmp = tempnam(sys_get_temp_dir(), 'rmv');
    file_put_contents($tmp, FakeAudio::speech('샘플 음성입니다')['audio']);
    try {
        $add = ElevenLabs::addVoice('르멤버 엄마 #1', [['path' => $tmp, 'name' => 'a.wav', 'mime' => 'audio/wav']], '테스트');
        assert_true($add['ok']);
        assert_same(0, strpos((string) $add['voice_id'], 'fake_'));
        $none = ElevenLabs::addVoice('빈 목소리', [['path' => '/nonexistent/file.wav', 'name' => 'x.wav', 'mime' => 'audio/wav']]);
        assert_same(false, $none['ok']);
        assert_true($none['error'] !== null && $none['error'] !== '');
    } finally {
        @unlink($tmp);
    }
    assert_same(['ok' => true, 'error' => null], ElevenLabs::deleteVoice('fake_abc'));
    $sub = ElevenLabs::subscription();
    assert_true($sub['ok']);
    assert_same(12000, $sub['used']);
    assert_same(100000, $sub['limit']);
    assert_true((bool) preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', (string) $sub['reset_at']));
});

test('크레딧 동기화: 직전 기록 잔량에서 그 뒤 사용한 크레딧을 뺀 내부 추정치를 남긴다', function () {
    el_require_fake();
    test_db();
    $ids = [];
    $ref = random_int(100000000, 900000000);
    try {
        $ids[] = db_insert('provider_credit_snapshots', [
            'provider' => 'elevenlabs', 'remaining_units' => 90000, 'limit_units' => 100000, 'checked_at' => now(),
        ]);
        App\Services\Usage::log(['provider' => 'elevenlabs', 'purpose' => 'test_tts', 'model' => 'eleven_multilingual_v2', 'ref_type' => 'a1_test', 'ref_id' => $ref, 'unit_type' => 'chars', 'units' => 1000]);
        App\Services\Usage::log(['provider' => 'elevenlabs', 'purpose' => 'test_tts', 'model' => 'eleven_flash_v2_5', 'ref_type' => 'a1_test', 'ref_id' => $ref, 'unit_type' => 'chars', 'units' => 1000]);
        $r = ElevenLabs::syncCredits();
        assert_true($r['ok']);
        assert_same(88000, $r['remaining']);
        $row = db_one("SELECT * FROM provider_credit_snapshots WHERE provider = 'elevenlabs' ORDER BY id DESC LIMIT 1");
        $ids[] = (int) $row['id'];
        assert_same(88000, (int) $row['remaining_units']);
        assert_same(100000, (int) $row['limit_units']);
        $expected = 90000 - (1000 + (int) round(1000 * (float) setting('elevenlabs.flash_credit_ratio')));
        assert_same($expected, (int) $row['internal_estimate']);
    } finally {
        foreach ($ids as $id) {
            db_exec('DELETE FROM provider_credit_snapshots WHERE id = ?', [$id]);
        }
        db_exec('DELETE FROM api_usage_logs WHERE ref_type = ? AND ref_id = ?', ['a1_test', $ref]);
    }
});

test('상태 점검: 항목 구조, 5분 보관, 새로 점검', function () {
    el_require_fake();
    test_db();
    $hadCache = db_value('SELECT COUNT(*) FROM settings WHERE k = ?', ['health.cache']) > 0;
    $lastSnap = (int) db_value('SELECT COALESCE(MAX(id), 0) FROM provider_credit_snapshots');
    try {
        $s = App\Services\Health::status(true);
        foreach (['gemini', 'elevenlabs', 'storage', 'db', 'worker', 'checked_at'] as $k) {
            assert_true(array_key_exists($k, $s), $k . ' 항목');
        }
        assert_same(false, $s['cached']);
        assert_true($s['gemini']['ok'] && $s['elevenlabs']['ok'] && $s['db']['ok'], '가짜 모드는 모두 정상');
        assert_same(['used', 'limit', 'remaining', 'reset_at', 'tier'], array_keys($s['elevenlabs']['credits']));
        assert_same(88000, $s['elevenlabs']['credits']['remaining']);
        assert_true($s['storage']['writable'], '저장 폴더 쓰기');
        assert_same(['pending', 'running', 'failed_24h', 'last_done_at', 'stale'], array_keys($s['worker']));
        $c = App\Services\Health::status();
        assert_same(true, $c['cached']);
        assert_same($s['checked_at'], $c['checked_at']);
        assert_same(false, App\Services\Health::status(true)['cached']);
    } finally {
        if (!$hadCache) {
            App\Core\Settings::forget('health.cache');
        }
        db_exec('DELETE FROM provider_credit_snapshots WHERE id > ?', [$lastSnap]);
    }
});
