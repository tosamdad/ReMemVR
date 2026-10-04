<?php
use App\Services\Progress;

test('경험치는 완독 20, 질문 5, 들은 1분당 1 이다', function () {
    assert_same(0, Progress::xp(0, 0, 0));
    assert_same(26, Progress::xp(1, 1, 60000));
    assert_same(0, Progress::xp(0, 0, 59999), '1분이 안 되면 0');
    assert_same(20 * 3 + 5 * 2 + 12, Progress::xp(3, 2, 12 * 60000 + 30000));
    assert_same(0, Progress::xp(-1, -2, -3000), '음수는 0 으로 본다');
});

test('레벨 시작 경험치와 칭호', function () {
    assert_same(0, Progress::threshold(1));
    assert_same(60, Progress::threshold(2));
    assert_same(3000, Progress::threshold(10));
    assert_same(3800, Progress::threshold(11));
    assert_same(4600, Progress::threshold(12));
    assert_same('새싹 탐험가', Progress::title(1));
    assert_same('별빛 탐험가', Progress::title(4));
    assert_same('우주 탐험가', Progress::title(6));
    assert_same('우주 탐험가', Progress::title(15));
});

test('경험치로 레벨과 다음 레벨까지 진행률을 구한다', function () {
    $l = Progress::level(0);
    assert_same(1, $l['level']);
    assert_same('새싹 탐험가', $l['title']);
    assert_same(0, $l['into']);
    assert_same(60, $l['span']);
    assert_same(0, $l['percent']);

    $l = Progress::level(60);
    assert_same(2, $l['level'], '경계값은 다음 레벨');
    assert_same(0, $l['into']);
    assert_same(90, $l['span']);

    $l = Progress::level(299);
    assert_same(3, $l['level']);
    assert_same(149, $l['into']);
    assert_same(150, $l['span']);
    assert_same(99, $l['percent']);

    $l = Progress::level(3900);
    assert_same(11, $l['level']);
    assert_same('우주 탐험가', $l['title']);
    assert_same(100, $l['into']);
    assert_same(800, $l['span']);
    assert_same(12, $l['percent']);
});

test('한 주는 월요일 0시에 시작한다', function () {
    $tz = new DateTimeZone('Asia/Seoul');
    assert_same('2026-09-28 00:00:00', Progress::weekStart(new DateTimeImmutable('2026-10-04 23:30:00', $tz))->format('Y-m-d H:i:s'), '일요일');
    assert_same('2026-09-28 00:00:00', Progress::weekStart(new DateTimeImmutable('2026-09-28 00:00:00', $tz))->format('Y-m-d H:i:s'), '월요일 0시');
    assert_same('2026-10-05 00:00:00', Progress::weekStart(new DateTimeImmutable('2026-10-07 09:00:00', $tz))->format('Y-m-d H:i:s'), '수요일');
});

test('자녀 범위: 한 명이면 자녀 미지정 기록도 포함한다', function () {
    list($sql, $params) = Progress::scopeSql(7, 3, 1);
    assert_same('ps.user_id = ? AND (ps.child_id = ? OR ps.child_id IS NULL)', $sql);
    assert_same([7, 3], $params);
    list($sql, $params) = Progress::scopeSql(7, 3, 2);
    assert_same('ps.user_id = ? AND ps.child_id = ?', $sql);
    list($sql, $params) = Progress::scopeSql(7, null, 0);
    assert_same('ps.user_id = ?', $sql);
    assert_same([7], $params);
});

test('추천 순서: 안 들은 동화 먼저, 저녁에는 취침 전 먼저, 날마다 고정된 순서', function () {
    $stories = [
        ['id' => 1, 'category' => '모험'],
        ['id' => 2, 'category' => '취침 전'],
        ['id' => 3, 'category' => '우정'],
        ['id' => 4, 'category' => '취침 전'],
    ];
    $ids = function ($list) {
        return array_map(function ($s) {
            return $s['id'];
        }, $list);
    };
    $night = $ids(Progress::recommend($stories, [2], 20, '2026-10-04'));
    assert_same(4, $night[0], '저녁에는 안 들은 취침 전 동화가 맨 앞');
    assert_same(2, $night[3], '다 들은 동화는 맨 뒤');
    $day = $ids(Progress::recommend($stories, [2], 10, '2026-10-04'));
    assert_same(2, $day[3], '낮에도 다 들은 동화는 맨 뒤');
    assert_same($day, $ids(Progress::recommend($stories, [2], 10, '2026-10-04')), '같은 날에는 같은 순서');
    assert_same(2, count(Progress::recommend($stories, [], 10, '2026-10-04', 2)), '개수 제한');
});

test('들은 문장 키워드로 새 단어와 익힌 단어를 모은다', function () {
    $w = Progress::collectWords([
        ['keywords' => '달님, 밤하늘', 'done' => 0],
        ['keywords' => '#별빛 달님', 'done' => 1],
        ['keywords' => '', 'done' => 1],
    ]);
    assert_same(['달님', '밤하늘', '별빛'], $w['heard']);
    assert_same(['별빛', '달님'], $w['mastered']);
    assert_same(['달님', '밤하늘', '별빛'], $w['recent']);
});

test('날짜별 값을 기간 배열로 채운다', function () {
    $tz = new DateTimeZone('Asia/Seoul');
    $from = new DateTimeImmutable('2026-09-28 00:00:00', $tz);
    $today = new DateTimeImmutable('2026-09-30 10:00:00', $tz);
    $days = Progress::fillDays(['2026-09-29' => 12], $from, 7, $today, true);
    assert_same(7, count($days));
    assert_same('월', $days[0]['label']);
    assert_same('일', $days[6]['label']);
    assert_same(12, $days[1]['value']);
    assert_same(0, $days[0]['value']);
    assert_true($days[2]['today'], '오늘 표시');
    assert_true($days[3]['future'], '내일은 미래');
    $dates = Progress::fillDays([], $from, 3, $today, false);
    assert_same('9/28', $dates[0]['label']);
});

test('동화 길이: 예상 시간, 오디오 길이, 글자 수 순서로 고른다', function () {
    assert_same(480, Progress::durationSec(['est_duration_sec' => 480, 'char_count' => 100], 1000));
    assert_same(125, Progress::durationSec(['est_duration_sec' => null, 'char_count' => 100], 125000));
    assert_same(20, Progress::durationSec(['est_duration_sec' => null, 'char_count' => 100], null));
    assert_same(0, Progress::durationSec([], null));
    assert_same('8분', Progress::durationLabel(480));
    assert_same('1분', Progress::durationLabel(20));
    assert_same('', Progress::durationLabel(0));
    assert_same('+2', Progress::delta(5, 3));
    assert_same('-1', Progress::delta(2, 3));
    assert_same('0', Progress::delta(3, 3));
});
