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

test('엔진이 다른 목소리는 합성 전에 막는다(문구에 두 엔진 이름이 모두 나온다)', function () {
    $profile = ['tts_engine' => 'elevenlabs'];
    $made = TtsEngine::profileEngine($profile);
    $now = TtsEngine::current();
    $mismatch = $made !== $now ? TtsEngine::profileMismatch($profile) : null;
    if ($mismatch !== null) {
        assert_true(strpos($mismatch, 'ElevenLabs') !== false && strpos($mismatch, 'Typecast') !== false, '두 엔진 이름이 모두 나온다');
    }
    // 같은 엔진이면 문제 없다.
    assert_same(null, TtsEngine::profileMismatch(['tts_engine' => $now]));
});
