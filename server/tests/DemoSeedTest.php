<?php
// 데모 데이터 생성기(server/bin/demo-seed.php)의 계산 부분 확인. DB 를 바꾸지 않는다.
if (!defined('DEMO_SEED_LIBRARY')) {
    define('DEMO_SEED_LIBRARY', true);
}
require_once APP_ROOT . '/bin/demo-seed.php';

use App\Core\Text;

test('데모: 문장, 단어 시각이 순서대로이고 단어 수가 Text::words 와 같다', function () {
    $sentences = [
        ['seq' => 1, 'content' => '언덕 위 작은 집에 밤하늘을 좋아하는 아이 다온이가 살았어요.'],
        ['seq' => 2, 'content' => '“달님, 오늘 밤도 반가워요.”'],
        ['seq' => 5, 'content' => '다온이는 눈이 동그래져서 고개를 끄덕였어요.'],
    ];
    $t = DemoSeed::timings($sentences, 14000);
    assert_same(1, $t['v']);
    assert_same(14000, $t['duration']);
    assert_same([1, 2, 5], array_column($t['sentences'], 'seq'));
    $prev = 0;
    foreach ($t['sentences'] as $i => $s) {
        assert_same(count(Text::words($sentences[$i]['content'])), count($s['words']), '단어 수');
        assert_true($s['start'] >= $prev && $s['end'] > $s['start'], '문장 시각 순서');
        $wp = $s['start'];
        foreach ($s['words'] as $w) {
            assert_true(is_int($w[0]) && is_int($w[1]), '정수 ms');
            assert_true($w[0] >= $wp && $w[1] >= $w[0] && $w[1] <= $s['end'], '단어 시각 순서');
            $wp = $w[1];
        }
        assert_same($s['end'], $s['words'][count($s['words']) - 1][1], '마지막 단어는 문장 끝에서 끝난다');
        $prev = $s['end'];
    }
    assert_true($prev <= 14000, '전체 길이 안');
});

test('데모: 글자가 많은 문장일수록 오래 읽는다', function () {
    $t = DemoSeed::timings([
        ['seq' => 1, 'content' => '짧아요.'],
        ['seq' => 2, 'content' => '이 문장은 앞 문장보다 훨씬 길어서 읽는 시간도 더 오래 걸려요.'],
    ], 10000);
    $a = $t['sentences'][0];
    $b = $t['sentences'][1];
    assert_true($b['end'] - $b['start'] > ($a['end'] - $a['start']) * 3);
});

test('데모: 아주 짧은 길이도 쉼 없이 나누고 범위를 넘지 않는다', function () {
    $t = DemoSeed::timings([['seq' => 1, 'content' => '하나 둘'], ['seq' => 2, 'content' => '셋 넷 다섯']], 500);
    assert_same(0, $t['sentences'][0]['start']);
    assert_true($t['sentences'][1]['end'] <= 500);
    assert_same([], DemoSeed::timings([], 1000)['sentences']);
});

test('데모: 재생 위치로 문장 번호를 찾는다', function () {
    $t = ['sentences' => [['seq' => 1, 'start' => 0, 'end' => 900], ['seq' => 2, 'start' => 1000, 'end' => 1900], ['seq' => 3, 'start' => 2000, 'end' => 2900]]];
    assert_same(1, DemoSeed::seqAt($t, 0));
    assert_same(2, DemoSeed::seqAt($t, 1500));
    assert_same(3, DemoSeed::seqAt($t, 99999));
});

test('데모: 8kHz 8비트 WAV 머리글과 길이가 맞다', function () {
    $w = DemoSeed::wav(1000, [440.0, 523.25]);
    assert_same('RIFF', substr($w, 0, 4));
    assert_same('WAVE', substr($w, 8, 4));
    assert_same(strlen($w) - 8, unpack('V', substr($w, 4, 4))[1]);
    $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr($w, 20, 16));
    assert_same(1, $fmt['format']);
    assert_same(1, $fmt['channels']);
    assert_same(8000, $fmt['rate']);
    assert_same(8, $fmt['bits']);
    assert_same('data', substr($w, 36, 4));
    assert_same(8000, unpack('V', substr($w, 40, 4))[1]);
    assert_same(44 + 8000, strlen($w));
    // 앞뒤를 줄여서 시작과 끝은 무음(128)에 가깝다
    assert_true(abs(ord($w[44]) - 128) <= 2 && abs(ord($w[strlen($w) - 1]) - 128) <= 2);
});

test('데모: 받침에 맞춰 조사를 붙이고 자리를 채운다', function () {
    assert_same('달님은', DemoSeed::josa('달님', '은', '는'));
    assert_same('부엉이는', DemoSeed::josa('부엉이', '은', '는'));
    assert_same('별을', DemoSeed::josa('별', '을', '를'));
    assert_same('사다리가', DemoSeed::josa('사다리', '이', '가'));
    assert_same('엄마, 오리가 뭐야?', DemoSeed::fill('{who}{kw:이} 뭐야?', '오리', '엄마', '엄마'));
    assert_same('구름이 뭐야?', DemoSeed::fill('{who}{kw:이} 뭐야?', '구름', '', '엄마'));
    assert_same('할머니가 옆에 있을게.', DemoSeed::fill('{self}가 옆에 있을게.', '', '', '할머니'));
});

test('데모: 준비한 질문과 답은 자리를 채워도 답변 글자 수 한도 안이다', function () {
    $max = (int) App\Core\Settings::DEFAULTS['qa.max_answer_chars'];
    $all = [];
    foreach (DemoSeed::crafted() as $code => $list) {
        assert_true(preg_match('/^Fairytale-\d{3}$/', $code) === 1, '동화 코드 ' . $code);
        foreach ($list as $c) {
            assert_true(!empty($c['t']) && in_array($c['e'], ['호기심', '공감', '걱정', '기쁨', '무서움'], true), '트리거와 감정');
            $all[] = $c;
        }
    }
    foreach (DemoSeed::generic() as $g) {
        $all[] = $g + ['t' => []];
    }
    foreach ($all as $c) {
        $a = DemoSeed::fill($c['a'], '반딧불이', '할아버지', '할아버지');
        assert_true(mb_strlen($a) <= $max, '답이 너무 길다: ' . $a);
        assert_true(strpos($a, '{') === false && strpos(DemoSeed::fill($c['q'], '반딧불이', '엄마', '엄마'), '{') === false, '채우지 못한 자리');
    }
});

test('데모: 안내 음성 해시는 질문 처리(VoiceService::textHash)와 같다', function () {
    if (!class_exists('App\Services\VoiceService')) {
        skip_test('VoiceService 없음');
    }
    $lines = DemoSeed::clipLines([['fallback_lines' => json_encode(['  이야기 끝나고 또 물어봐 줘!  '])]]);
    assert_true(count($lines) >= 2);
    foreach ($lines as $hash => $line) {
        assert_same(App\Services\VoiceService::textHash($line['text']), $hash);
        assert_same(trim($line['text']), $line['text']);
    }
});

test('데모: 명령행 옵션을 읽는다', function () {
    $o = DemoSeed::parseArgs(['demo-seed.php', '--reset', '--users=40', '--seed=7']);
    assert_true($o['reset'] && !$o['clean'] && !$o['force']);
    assert_same(40, $o['users']);
    assert_same(7, $o['seed']);
    assert_same('', $o['error']);
    assert_same(24, DemoSeed::parseArgs(['demo-seed.php'])['users']);
    assert_true(DemoSeed::parseArgs(['demo-seed.php', '--users=0'])['error'] !== '');
    assert_true(DemoSeed::parseArgs(['demo-seed.php', '--what'])['error'] !== '');
});
