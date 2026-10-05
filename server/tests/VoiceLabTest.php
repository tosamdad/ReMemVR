<?php
use App\Controllers\User\VoiceLabController as VL;

/** 테스트용 WAV(PCM 16bit 모노) 파일을 임시 폴더에 만든다. */
function vl_test_wav(float $sec, int $rate = 16000, string $extraChunk = ''): string
{
    $n = (int) ($sec * $rate);
    $data = str_repeat(pack('v', 1000) . pack('v', 64536), (int) ($n / 2));
    $fmt = 'fmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1) . pack('V', $rate) . pack('V', $rate * 2) . pack('v', 2) . pack('v', 16);
    $body = 'WAVE' . $fmt . $extraChunk . 'data' . pack('V', strlen($data)) . $data;
    $file = tempnam(sys_get_temp_dir(), 'vlt');
    file_put_contents($file, 'RIFF' . pack('V', strlen($body)) . $body);

    return $file;
}

test('WAV 머리글로 길이를 계산한다', function () {
    $f = vl_test_wav(4.5);
    assert_same(4500, VL::wavDurationMs($f));
    @unlink($f);
});

test('WAV 사이에 다른 덩어리(LIST)가 있어도 data 를 찾는다', function () {
    $list = 'LIST' . pack('V', 5) . 'INFOx' . "\0"; // 홀수 크기는 1바이트 채움
    $f = vl_test_wav(2.0, 24000, $list);
    assert_same(2000, VL::wavDurationMs($f));
    @unlink($f);
});

test('WAV 가 아니면 길이는 0', function () {
    $f = tempnam(sys_get_temp_dir(), 'vlt');
    file_put_contents($f, str_repeat('a', 4000));
    assert_same(0, VL::wavDurationMs($f));
    assert_same(false, VL::looksLikeAudio($f));
    @unlink($f);
});

test('오디오 형식 머리글을 알아본다', function () {
    $cases = [
        'wav' => "RIFF\x24\0\0\0WAVEfmt ",
        'ogg' => 'OggS' . str_repeat("\0", 20),
        'flac' => 'fLaC' . str_repeat("\0", 20),
        'webm' => "\x1A\x45\xDF\xA3" . str_repeat("\0", 20),
        'mp3-id3' => 'ID3' . str_repeat("\0", 20),
        'mp3-frame' => "\xFF\xFB\x90\x64" . str_repeat("\0", 20),
        'm4a' => "\0\0\0\x20ftypM4A " . str_repeat("\0", 20),
    ];
    foreach ($cases as $name => $head) {
        $f = tempnam(sys_get_temp_dir(), 'vlt');
        file_put_contents($f, $head);
        assert_true(VL::looksLikeAudio($f), $name . ' 을(를) 오디오로 보지 못했다');
        @unlink($f);
    }
    $others = [
        'php' => '<?php echo "hello"; ?>' . str_repeat(' ', 40),
        'jpeg' => "\xFF\xD8\xFF\xE0\0\x10JFIF\0" . str_repeat("\xFF\xE1", 20),
        'png' => "\x89PNG\r\n\x1A\n" . str_repeat("\0", 20),
        'zip' => "PK\x03\x04" . str_repeat("\0", 20),
        'text' => str_repeat('hello world ', 10),
    ];
    foreach ($others as $name => $head) {
        $f = tempnam(sys_get_temp_dir(), 'vlt');
        file_put_contents($f, $head);
        assert_same(false, VL::looksLikeAudio($f), $name . ' 을(를) 오디오로 봤다');
        @unlink($f);
    }
});

test('업로드 확장자는 허용 목록만 받는다', function () {
    assert_same('wav', VL::audioExt('story-1.WAV', 'audio/wav'));
    assert_same('m4a', VL::audioExt('녹음.m4a', ''));
    assert_same('webm', VL::audioExt('blob', 'audio/webm;codecs=opus'));
    assert_same(null, VL::audioExt('a.exe', 'audio/wav'));
    assert_same(null, VL::audioExt('a.php', ''));
    assert_same(null, VL::audioExt('blob', 'text/html'));
});

test('품질 지표를 DB 범위에 맞게 정리한다', function () {
    $m = VL::cleanMetrics('{"snrDb":31.46,"peakDb":-6.21,"noiseDb":-99999,"clipCount":-3,"grade":"good"}');
    assert_same(31.5, $m['snr_db']);
    assert_same(-6.2, $m['peak_db']);
    assert_same(-999.9, $m['noise_db']);
    assert_same(0, $m['clip_count']);
    assert_same('good', $m['quality_grade']);
    $bad = VL::cleanMetrics('{"snrDb":"abc","grade":"<b>"}');
    assert_same(null, $bad['snr_db']);
    assert_same(null, $bad['quality_grade']);
    $none = VL::cleanMetrics('');
    assert_same(null, $none['clip_count']);
});

test('진행 단계는 상태에 맞춰 강조된다', function () {
    $states = function (array $voice) {
        return array_map(function ($s) {
            return $s['state'];
        }, VL::timeline($voice, true));
    };
    // 관리자 검토를 거칠 때: 녹음 → 검토 → 목소리 생성 → 준비 완료. 동화는 목소리가 준비된 뒤 회원이 골라 요청한다.
    assert_same(['current', 'todo', 'todo', 'todo'], $states(['status' => 'draft']));
    assert_same(['done', 'current', 'todo', 'todo'], $states(['status' => 'pending']));
    assert_same(['done', 'error', 'todo', 'todo'], $states(['status' => 'rejected']));
    assert_same(['done', 'done', 'current', 'todo'], $states(['status' => 'cloning']));
    assert_same(['done', 'done', 'done', 'done'], $states(['status' => 'processing']));
    assert_same(['done', 'done', 'done', 'done'], $states(['status' => 'completed']));
    assert_same(['done', 'done', 'error', 'todo'], $states(['status' => 'failed', 'provider_voice_id' => '']));

    // 자동 생성(기본)일 때: 녹음 → 목소리 생성 → 준비 완료.
    $auto = function (array $voice) {
        return array_map(function ($s) {
            return $s['key'] . ':' . $s['state'];
        }, VL::timeline($voice, false));
    };
    assert_same(['record:current', 'clone:todo', 'done:todo'], $auto(['status' => 'draft']));
    assert_same(['record:done', 'clone:current', 'done:todo'], $auto(['status' => 'cloning']));
    assert_same(['record:done', 'clone:done', 'done:done'], $auto(['status' => 'completed']));
    assert_same(['record:done', 'clone:error', 'done:todo'], $auto(['status' => 'failed']));
});

test('php.ini 크기 표기와 업로드 한도', function () {
    assert_same(2097152, VL::iniBytes('2M'));
    assert_same(524288, VL::iniBytes('512K'));
    assert_same(1073741824, VL::iniBytes('1G'));
    assert_same(0, VL::iniBytes(''));
    $limit = VL::uploadLimit();
    assert_true($limit >= 262144 && $limit <= 20971520, '업로드 한도 범위 밖: ' . $limit);
});

test('라벨 정리와 시간 문구', function () {
    assert_same('외할머니', VL::cleanLabel("  외할머니\n "));
    assert_same('bmine', VL::cleanLabel('<b>mine'));
    assert_same('큰 이모', VL::cleanLabel("큰   이모"));
    assert_same('1분 30초', VL::secText(90));
    assert_same('1분', VL::secText(60));
    assert_same('45초', VL::secText(45));
});

test('녹음 대본 3개는 낭독 40~60초 분량이다', function () {
    $scripts = VL::scripts();
    assert_same(3, count($scripts));
    foreach ($scripts as $key => $s) {
        assert_same($key, $s['key']);
        foreach (['no', 'title', 'kind', 'icon', 'guide', 'text'] as $field) {
            assert_true(isset($s[$field]) && $s[$field] !== '', $key . ' 에 ' . $field . ' 가 없다');
        }
        assert_true(strlen($key) <= 30, 'script_key 는 30자 이하(voice_samples.script_key)');
        // 한국어 동화 낭독은 초당 약 4음절: 160~260음절이면 40~65초
        $syllables = preg_match_all('/[\x{AC00}-\x{D7A3}]/u', $s['text']);
        assert_true($syllables >= 160 && $syllables <= 260, $key . ' 음절 수 ' . $syllables);
    }
});

test('M4A 의 mvhd 상자에서 길이를 읽는다', function () {
    // ftyp 상자 + moov(mvhd 버전 0: timescale 1000, duration 20500)
    $ftyp = pack('N', 16) . 'ftypM4A ' . pack('N', 0);
    $mvhd = 'mvhd' . "\0\0\0\0" . pack('N', 0) . pack('N', 0) . pack('N', 1000) . pack('N', 20500) . str_repeat("\0", 80);
    $moov = pack('N', 8 + 4 + strlen($mvhd)) . 'moov' . pack('N', 4 + strlen($mvhd)) . $mvhd;
    $f = tempnam(sys_get_temp_dir(), 'vlt');
    file_put_contents($f, $ftyp . $moov);
    assert_same(20500, VL::mp4DurationMs($f));
    file_put_contents($f, 'not an mp4 file at all');
    assert_same(0, VL::mp4DurationMs($f));
    @unlink($f);
});
