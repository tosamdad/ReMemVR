<?php
use App\Services\TtsEngine;
use App\Services\Typecast;

/**
 * TTS 엔진 선택(ElevenLabs / Typecast) 순수 로직 테스트. DB 도 네트워크도 쓰지 않는다.
 */
test('Typecast 목소리 id 형식: tc_, uc_ 접두어만 받는다', function () {
    assert_true(Typecast::validVoiceId('uc_64a1b2c3d4e5f6a7b8c9d0e1'));
    assert_true(Typecast::validVoiceId('tc_60e5426de8b95f1d3000d7b5'));
    assert_true(!Typecast::validVoiceId('EL_abcd1234'), 'ElevenLabs 목소리 id 는 받지 않는다');
    assert_true(!Typecast::validVoiceId('uc_짧음'));
    assert_true(!Typecast::validVoiceId(''));
});

test('Typecast 정렬 응답을 ElevenLabs 형식으로 바꾼다', function () {
    $a = Typecast::mapAlignment([
        ['text' => '안', 'start' => 0.08, 'end' => 0.18],
        ['text' => '녕', 'start' => 0.18, 'end' => 0.3],
    ]);
    assert_same(['chars' => ['안', '녕'], 'starts' => [0.08, 0.18], 'ends' => [0.18, 0.3]], $a);
    assert_same(null, Typecast::mapAlignment(null));
    assert_same(null, Typecast::mapAlignment([]));
    // 시작이나 끝 시각이 빠진 줄이 섞이면 정렬 전체를 못 쓴다(ElevenLabs 와 같은 규칙).
    assert_same(null, Typecast::mapAlignment([['text' => '안', 'start' => 0.08]]));
});

test('Typecast 샘플 고르기: WAV, MP3 가운데 가장 큰 파일 하나', function () {
    $dir = sys_get_temp_dir();
    $wav = $dir . '/typecast-test-' . bin2hex(random_bytes(4)) . '.wav';
    $mp3 = $dir . '/typecast-test-' . bin2hex(random_bytes(4)) . '.mp3';
    $txt = $dir . '/typecast-test-' . bin2hex(random_bytes(4)) . '.txt';
    file_put_contents($wav, str_repeat('w', 300));
    file_put_contents($mp3, str_repeat('m', 500));
    file_put_contents($txt, str_repeat('t', 9999));

    $pick = Typecast::pickSample([
        ['path' => $txt, 'name' => 'a.txt', 'mime' => 'text/plain'],
        ['path' => $wav, 'name' => 'b.wav', 'mime' => 'audio/wav'],
        ['path' => $mp3, 'name' => 'c.mp3', 'mime' => 'audio/mpeg'],
    ]);
    assert_same('c.mp3', $pick['name'], 'WAV, MP3 중 가장 큰 것');
    assert_same('audio/mpeg', $pick['mime']);

    // WAV 만 있으면 WAV
    $pick2 = Typecast::pickSample([['path' => $wav, 'name' => 'b.wav', 'mime' => 'audio/wav']]);
    assert_same('b.wav', $pick2['name']);

    assert_same(null, Typecast::pickSample([['path' => $txt, 'name' => 'a.txt', 'mime' => 'text/plain']]), 'WAV, MP3 가 없으면 거절');
    assert_same(null, Typecast::pickSample([['path' => $dir . '/없는-파일.wav', 'name' => 'x.wav', 'mime' => 'audio/wav']]));

    @unlink($wav);
    @unlink($mp3);
    @unlink($txt);
});

test('엔진 결정: Typecast 를 골라도 키가 없으면 ElevenLabs 로 되돌아간다', function () {
    if ((string) config('typecast.api_key', '') !== '' || (bool) config('providers_fake')) {
        skip_test('Typecast 키가 있거나 개발 모드라 되돌림 로직을 확인할 수 없다');
    }
    // settings 값 없이는 기본값(elevenlabs)이고, Typecast 키도 없으므로 반드시 ElevenLabs 다.
    assert_same('elevenlabs', TtsEngine::current());
    assert_same('ElevenLabs', TtsEngine::label());
    assert_true(TtsEngine::ready() === ElevenLabs::ready(), '엔진 준비 상태는 ElevenLabs 를 따른다');
});

test('엔진 기록: voice_profiles.tts_engine 값으로 그 목소리의 엔진을 안다', function () {
    assert_same('elevenlabs', TtsEngine::profileEngine(['tts_engine' => null]), 'NULL 은 이 기능 이전 목소리(ElevenLabs)');
    assert_same('elevenlabs', TtsEngine::profileEngine(['tts_engine' => 'elevenlabs']));
    assert_same('typecast', TtsEngine::profileEngine(['tts_engine' => 'typecast']));
});

test('목소리별 작업 엔진 결정: 그 목소리의 엔진을 따르고, 모르면 지금 엔진을 따른다', function () {
    $now = TtsEngine::current();
    // 목소리의 엔진 기록이 있으면 지금 설정과 관계없이 그 엔진으로 돌린다.
    assert_same('elevenlabs', TtsEngine::routeEngine('elevenlabs'));
    assert_same('typecast', TtsEngine::routeEngine('typecast'));
    // 기록이 없으면(예전 호출 형식) 지금 엔진이다.
    assert_same($now, TtsEngine::routeEngine(null));
    // 알 수 없는 값도 지금 엔진이다.
    assert_same($now, TtsEngine::routeEngine('unknown'));
    // 이전 버그: 엔진을 바꾼 뒤 예전 엔진 목소리를 지우면 지금 엔진으로 보내 "목소리 ID 형식이 올바르지 않습니다" 가 나왔다.
    // routeEngine 이 그 목소리의 엔진을 그대로 돌려주는지로 막는다.
    $madeElevenlabs = TtsEngine::profileEngine(['tts_engine' => null]);
    assert_same('elevenlabs', TtsEngine::routeEngine($madeElevenlabs), '예전 ElevenLabs 목소리는 ElevenLabs 로');
});

test('엔진별 준비 문구: 그 목소리의 엔진 키가 없을 때 그 엔진 이름으로 안내한다', function () {
    assert_contains('ElevenLabs', TtsEngine::notReadyMessage('elevenlabs'));
    assert_contains('Typecast', TtsEngine::notReadyMessage('typecast'));
    // 개발 모드(fake)에서는 두 엔진 모두 항상 준비되어 있다.
    if ((bool) config('providers_fake')) {
        assert_true(TtsEngine::ready('elevenlabs') && TtsEngine::ready('typecast'));
    }
});
