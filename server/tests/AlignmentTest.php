<?php
use App\Core\Text;
use App\Services\Alignment;

/** 글자마다 $ms 밀리초씩 차지하는 가짜 정렬 정보 */
function align_fixture(string $text, int $ms = 100): array
{
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $starts = [];
    $ends = [];
    foreach ($chars as $i => $c) {
        $starts[] = $i * $ms / 1000;
        $ends[] = ($i + 1) * $ms / 1000;
    }

    return ['chars' => $chars, 'starts' => $starts, 'ends' => $ends];
}

/** 타이밍 구조가 이어지고(빈틈 없음) 낱말 수가 Text::words 와 같은지 확인 */
function assert_timings_shape(array $sentences, array $t, int $duration): void
{
    assert_same(1, $t['v']);
    assert_same($duration, $t['duration']);
    assert_same(count($sentences), count($t['sentences']), '문장 수');
    $prevEnd = 0;
    foreach ($t['sentences'] as $i => $s) {
        assert_same($prevEnd, $s['start'], ($i + 1) . '번째 문장 시작');
        assert_true($s['end'] >= $s['start'], '문장 끝이 시작보다 앞선다');
        $words = Text::words($sentences[$i]['content']);
        assert_same(count($words), count($s['words']), ($i + 1) . '번째 문장 낱말 수');
        $prevWord = 0;
        foreach ($s['words'] as $w) {
            assert_true(is_int($w[0]) && is_int($w[1]), '낱말 시각은 정수 ms');
            assert_true($w[0] >= $prevWord && $w[1] >= $w[0], '낱말 시각이 거꾸로 간다');
            $prevWord = $w[0];
        }
        $prevEnd = $s['end'];
    }
    assert_same($duration, $prevEnd, '마지막 문장 끝은 전체 길이');
}

test('joinText 는 seq 순서로 앞뒤 공백을 지우고 잇는다', function () {
    $s = [['seq' => 2, 'content' => ' 둘째 문장. '], ['seq' => 1, 'content' => '첫째 문장.'], ['seq' => 3, 'content' => '  ']];
    assert_same('첫째 문장. 둘째 문장.', Alignment::joinText($s));
    assert_same("첫째 문장.\n둘째 문장.", Alignment::joinText($s, "\n"));
});

test('build: 정렬 정보가 본문과 같으면 글자 시각이 그대로 낱말 시각이 된다', function () {
    $sentences = [
        ['seq' => 1, 'content' => '옛날 옛적에 토끼가 살았어요.'],
        ['seq' => 2, 'content' => '토끼는 달을 좋아했어요.'],
    ];
    $text = Alignment::joinText($sentences);
    $t = Alignment::build($sentences, align_fixture($text), mb_strlen($text) * 100);
    assert_timings_shape($sentences, $t, mb_strlen($text) * 100);
    // "옛날"(0~2글자), "옛적에"(3~6), 둘째 문장 "토끼는" 은 17번째 글자부터
    assert_same([0, 200], $t['sentences'][0]['words'][0]);
    assert_same([300, 600], $t['sentences'][0]['words'][1]);
    assert_same(1700, $t['sentences'][1]['start']);
    assert_same([1700, 2000], $t['sentences'][1]['words'][0]);
    assert_same(1, $t['sentences'][0]['seq']);
    assert_same(2, $t['sentences'][1]['seq']);
});

test('build: 둥근 따옴표와 여러 칸 공백이 정렬 쪽과 달라도 낱말이 맞는다', function () {
    $sentences = [
        ['seq' => 1, 'content' => '“누구니?”   토끼가 물었어요.'],
        ['seq' => 2, 'content' => '「나는　거북이야」 하고 대답했지요…'],
    ];
    // ElevenLabs 는 공백을 한 칸으로, 따옴표를 곧은 따옴표로, 말줄임표를 ... 로 돌려줄 수 있다.
    $returned = '"누구니?" 토끼가 물었어요. 「나는 거북이야」 하고 대답했지요...';
    $duration = mb_strlen($returned) * 100;
    $t = Alignment::build($sentences, align_fixture($returned), $duration);
    assert_timings_shape($sentences, $t, $duration);
    // "토끼가" 는 돌려받은 글자의 7번째(0부터)에서 시작
    assert_same([700, 1000], $t['sentences'][0]['words'][1]);
    // 둘째 문장은 「 가 있는 17번째 글자에서 시작하고, 전각 공백(　)으로 나뉜 "「나는", "거북이야」" 가 따로 잡힌다.
    assert_same(1700, $t['sentences'][1]['start']);
    assert_same([1700, 2000], $t['sentences'][1]['words'][0]);
    assert_same([2100, 2600], $t['sentences'][1]['words'][1]);
    // 말줄임표(…)는 돌려받은 ... 의 첫 점과 맞는다.
    assert_same([3000, 3600], $t['sentences'][1]['words'][3]);
});

test('build: 정렬 쪽에 빠진 글자, 덧붙은 글자가 있어도 시각이 이어진다', function () {
    $sentences = [
        ['seq' => 1, 'content' => '아기 곰이 3마리 있었어요.'],
        ['seq' => 2, 'content' => '모두 꿀을 좋아했지요!'],
    ];
    // 숫자를 읽는 말로 바꾸고(3 → 세), 느낌표를 빼먹은 응답
    $returned = '아기 곰이 세마리 있었어요. 모두 꿀을 좋아했지요';
    $duration = mb_strlen($returned) * 100 + 400;
    $t = Alignment::build($sentences, align_fixture($returned), $duration);
    assert_timings_shape($sentences, $t, $duration);
    assert_same([0, 200], $t['sentences'][0]['words'][0]);
    assert_same(1600, $t['sentences'][1]['start']);
    // 마지막 낱말("좋아했지요!")의 빠진 느낌표는 뒤쪽 남은 시간으로 채운다.
    $last = end($t['sentences'][1]['words']);
    assert_true($last[1] <= $duration && $last[1] >= 2500, '마지막 낱말 끝 ' . $last[1]);
});

test('build: 정렬 정보가 없으면 글자 수 비례 추정을 쓴다', function () {
    $sentences = [['seq' => 1, 'content' => '하나 둘'], ['seq' => 2, 'content' => '셋']];
    $a = Alignment::build($sentences, ['chars' => [], 'starts' => [], 'ends' => []], 1000);
    $b = Alignment::estimate($sentences, 1000);
    assert_same($b, $a);
});

test('build: 조각마다 나눠 합성해 이어 붙인 정렬도 처리한다(공백 자리 시각 0 길이)', function () {
    $sentences = [['seq' => 1, 'content' => '가나 다라.'], ['seq' => 2, 'content' => '마바 사아.']];
    $a1 = align_fixture('가나 다라.');
    $a2 = align_fixture('마바 사아.');
    $joined = ['chars' => $a1['chars'], 'starts' => $a1['starts'], 'ends' => $a1['ends']];
    $joined['chars'][] = ' ';
    $joined['starts'][] = 0.6;
    $joined['ends'][] = 0.6;
    foreach ($a2['chars'] as $i => $c) {
        $joined['chars'][] = $c;
        $joined['starts'][] = 0.6 + $a2['starts'][$i];
        $joined['ends'][] = 0.6 + $a2['ends'][$i];
    }
    $t = Alignment::build($sentences, $joined, 1200);
    assert_timings_shape($sentences, $t, 1200);
    assert_same(600, $t['sentences'][1]['start']);
    assert_same([900, 1200], $t['sentences'][1]['words'][1]);
});

test('estimate: 글자 수에 비례하고 문장이 빈틈없이 이어진다', function () {
    $sentences = [
        ['seq' => 1, 'content' => '가나다 라마'],      // 6글자
        ['seq' => 2, 'content' => '바사  아자차.'],    // 8글자(공백 2칸 포함)
    ];
    // 전체 6 + 1(이음 공백) + 8 = 15글자, 1500ms → 글자당 100ms
    $t = Alignment::estimate($sentences, 1500);
    assert_timings_shape($sentences, $t, 1500);
    assert_same([0, 300], $t['sentences'][0]['words'][0]);
    assert_same([400, 600], $t['sentences'][0]['words'][1]);
    assert_same(700, $t['sentences'][1]['start']);
    assert_same([700, 900], $t['sentences'][1]['words'][0]);
    assert_same([1100, 1500], $t['sentences'][1]['words'][1]);
});

test('estimate: 빈 문장은 빼고 seq 순서로 정렬한다', function () {
    $t = Alignment::estimate([['seq' => 5, 'content' => '나중'], ['seq' => 2, 'content' => ''], ['seq' => 1, 'content' => '먼저']], 500);
    assert_same(2, count($t['sentences']));
    assert_same(1, $t['sentences'][0]['seq']);
    assert_same(5, $t['sentences'][1]['seq']);
});

test('sentenceAt: 재생 위치가 속한 문장', function () {
    $t = Alignment::estimate([['seq' => 1, 'content' => '가나'], ['seq' => 2, 'content' => '다라'], ['seq' => 3, 'content' => '마바']], 800);
    assert_same(1, Alignment::sentenceAt($t, 0));
    assert_same(2, Alignment::sentenceAt($t, 400));
    assert_same(3, Alignment::sentenceAt($t, 799));
    assert_same(3, Alignment::sentenceAt($t, 5000));
    assert_same(null, Alignment::sentenceAt(['sentences' => []], 10));
});

test('가짜 합성 음성의 정렬과 길이로 만든 타이밍이 정확하다', function () {
    $sentences = [['seq' => 1, 'content' => '달님이 웃었어요.'], ['seq' => 2, 'content' => '“안녕!” 하고 인사했지요.']];
    $text = Alignment::joinText($sentences);
    $s = App\Services\FakeAudio::speech($text);
    $t = Alignment::build($sentences, $s['alignment'], $s['duration_ms']);
    assert_timings_shape($sentences, $t, $s['duration_ms']);
    assert_same(10 * 75, $t['sentences'][1]['start']); // 9글자 + 이음 공백 1칸
});
