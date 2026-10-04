<?php
use App\Core\Text;

test('마침표와 따옴표 기준으로 문장을 나눈다', function () {
    $s = Text::splitSentences("달님이 말했어요. “정말?” 하고 물었지요... 그래서 \"좋아!\"라고 했어요.\n끝!");
    assert_same(['달님이 말했어요.', '“정말?” 하고 물었지요...', '그래서 "좋아!"라고 했어요.', '끝!'], $s);
});

test('대사만 있는 문장은 닫는 따옴표까지 한 문장이다', function () {
    $s = Text::splitSentences('"오늘 밤에는 누가 소원을 빌까?" 달님이 웃었어요.');
    assert_same(['"오늘 밤에는 누가 소원을 빌까?"', '달님이 웃었어요.'], $s);
});

test('내용 해시는 앞뒤 공백을 무시한다', function () {
    assert_same(Text::hashSentences(['가.', '나.']), Text::hashSentences([' 가.', '나. ']));
});

test('키워드 문자열을 배열로 바꾼다', function () {
    assert_same(['밤하늘', '달님'], Text::keywords('밤하늘, 달님, #밤하늘'));
});
