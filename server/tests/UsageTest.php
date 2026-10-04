<?php
use App\Services\Usage;

/** 부동소수 비교 */
function usage_assert_close(float $expected, float $actual, string $message = '', float $eps = 1e-9): void
{
    if (abs($expected - $actual) > $eps) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . '기대 ' . $expected . ', 실제 ' . $actual);
    }
}

test('ElevenLabs 비용: flash, turbo 는 크레딧 비율, 나머지는 글자당 1크레딧', function () {
    $per1k = (float) setting('elevenlabs.usd_per_1k_credits');
    $ratio = (float) setting('elevenlabs.flash_credit_ratio');
    usage_assert_close(1000 / 1000 * $per1k, Usage::elevenlabsCostUsd(1000, 'eleven_multilingual_v2'), 'multilingual');
    usage_assert_close(1000 * $ratio / 1000 * $per1k, Usage::elevenlabsCostUsd(1000, 'eleven_flash_v2_5'), 'flash');
    usage_assert_close(2500 * $ratio / 1000 * $per1k, Usage::elevenlabsCostUsd(2500, 'eleven_turbo_v2_5'), 'turbo');
    usage_assert_close(0.0, Usage::elevenlabsCostUsd(0, 'eleven_v3'), '0글자');
    usage_assert_close(0.0, Usage::elevenlabsCostUsd(-5, 'eleven_v3'), '음수 글자');
    assert_same(1.0, Usage::elevenlabsCreditRatio('eleven_v3'));
    assert_same($ratio, Usage::elevenlabsCreditRatio('ELEVEN_FLASH_V2'));
});

test('기본 단가로 계산한 ElevenLabs 비용 예시(4,000자 동화 = 1.2달러)', function () {
    if ((float) setting('elevenlabs.usd_per_1k_credits') !== 0.30) {
        skip_test('단가 설정이 기본값이 아님');
    }
    usage_assert_close(1.2, Usage::elevenlabsCostUsd(4000, 'eleven_multilingual_v2'), '', 1e-6);
});

test('Gemini 비용: 음성 토큰은 음성 단가, 나머지 입력은 텍스트 단가', function () {
    $in = (float) setting('gemini.usd_per_1m_input');
    $audio = (float) setting('gemini.usd_per_1m_audio_input');
    $out = (float) setting('gemini.usd_per_1m_output');
    // 입력 1,500(그중 음성 480), 출력 60
    $expected = 1020 / 1e6 * $in + 480 / 1e6 * $audio + 60 / 1e6 * $out;
    usage_assert_close($expected, Usage::geminiCostUsd(1500, 60, 480));
    usage_assert_close(1e6 / 1e6 * $in, Usage::geminiCostUsd(1000000, 0), '텍스트만');
    // 음성 토큰이 전체보다 많게 들어와도 텍스트 토큰이 음수가 되지 않는다.
    usage_assert_close(500 / 1e6 * $audio, Usage::geminiCostUsd(100, 0, 500), '음성 토큰 초과');
});

test('원화 환산은 cost.usd_krw 환율을 쓴다', function () {
    usage_assert_close(2.5 * (float) setting('cost.usd_krw'), Usage::krw(2.5));
});

test('log 는 원화 비용을 계산해 남기고 todayCostKrw 에 더해진다', function () {
    test_db();
    $purpose = 'test_' . bin2hex(random_bytes(4));
    $before = Usage::todayCostKrw();
    try {
        Usage::log([
            'provider' => 'gemini', 'purpose' => $purpose, 'model' => 'gemini-2.5-flash',
            'unit_type' => 'tokens', 'units' => 1234, 'cost_usd' => 0.01, 'latency_ms' => 812, 'success' => true,
        ]);
        Usage::log([
            'provider' => 'elevenlabs', 'purpose' => $purpose, 'model' => 'eleven_flash_v2_5',
            'unit_type' => 'weird', 'units' => -3, 'cost_usd' => -1, 'success' => false,
        ]);
        $rows = db_all('SELECT * FROM api_usage_logs WHERE purpose = ? ORDER BY id', [$purpose]);
        assert_same(2, count($rows));
        usage_assert_close(round(0.01 * (float) setting('cost.usd_krw'), 2), (float) $rows[0]['cost_krw'], 'cost_krw', 0.001);
        assert_same('tokens', $rows[0]['unit_type']);
        assert_same(1234, (int) $rows[0]['units']);
        assert_same(812, (int) $rows[0]['latency_ms']);
        assert_same(1, (int) $rows[0]['success']);
        // 잘못된 단위, 음수 값은 정리된다.
        assert_same('requests', $rows[1]['unit_type']);
        assert_same(0, (int) $rows[1]['units']);
        usage_assert_close(0.0, (float) $rows[1]['cost_usd'], '음수 비용');
        assert_same(0, (int) $rows[1]['success']);
        assert_same(null, $rows[1]['latency_ms']);
        usage_assert_close($before + (float) $rows[0]['cost_krw'], Usage::todayCostKrw(), '오늘 비용', 0.011);

        $sum = Usage::summary(date('Y-m-d 00:00:00'));
        assert_true(isset($sum['by_purpose'][$purpose]), '요약에 용도별 합계가 있다');
        assert_same(2, $sum['by_purpose'][$purpose]['calls']);
    } finally {
        db_exec('DELETE FROM api_usage_logs WHERE purpose = ?', [$purpose]);
    }
});

test('log 는 DB 오류가 나도 예외를 던지지 않는다', function () {
    test_db();
    $purpose = 'test_' . bin2hex(random_bytes(4));
    // 없는 회원 번호(외래 키 위반)
    Usage::log(['provider' => 'gemini', 'purpose' => $purpose, 'user_id' => 4000000000, 'unit_type' => 'tokens', 'units' => 1]);
    assert_same(0, (int) db_value('SELECT COUNT(*) FROM api_usage_logs WHERE purpose = ?', [$purpose]));
});
