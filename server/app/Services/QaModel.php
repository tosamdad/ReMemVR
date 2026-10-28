<?php
namespace App\Services;

/**
 * 아이 질문에 답할 AI 를 고른다(관리자 설정 qa.llm_provider).
 * claude(기본): Anthropic 은 안전 장치를 갖추면 미성년자 대상 서비스에서 쓰는 것을 허용한다.
 * groq: 무료 요금제로 오픈 모델(gpt-oss 등)을 빠르게 돌린다. 하루 요청, 토큰 한도가 있다.
 * gemini: Gemini API 추가 약관은 18세 미만이 쓰거나 쓸 가능성이 높은 서비스를 금지하므로 관리자 화면에 경고를 띄운다.
 */
class QaModel
{
    const PROVIDERS = [
        'claude' => 'Claude (Anthropic, 기본)',
        'groq' => 'Groq (무료 요금제, 오픈 모델)',
        'gemini' => 'Gemini (Google)',
    ];
    /** 고른 값 → [서비스 클래스, api_usage_logs.provider 이름, 화면 이름] */
    const MAP = [
        'claude' => [Claude::class, 'anthropic', 'Claude'],
        'groq' => [Groq::class, 'groq', 'Groq'],
        'gemini' => [Gemini::class, 'gemini', 'Gemini'],
    ];

    public static function provider(): string
    {
        $p = (string) setting('qa.llm_provider', 'claude');

        return isset(self::PROVIDERS[$p]) ? $p : 'claude';
    }

    private static function cls(): string
    {
        return self::MAP[self::provider()][0];
    }

    /** api_usage_logs.provider 와 provider_ready() 에 쓰는 이름 */
    public static function usageProvider(): string
    {
        return self::MAP[self::provider()][1];
    }

    public static function label(): string
    {
        return self::MAP[self::provider()][2];
    }

    public static function ready(): bool
    {
        $c = self::cls();

        return $c::ready();
    }

    public static function model(): string
    {
        $c = self::cls();

        return $c::model();
    }

    /** @return array Claude::answerQuestion 과 같은 형식 */
    public static function answer(array $ctx, string $question, int $timeoutMs): array
    {
        // 개발 모드 전용: 느린 응답을 흉내 내 시간 초과 흐름을 점검한다(qa.fake_delay_ms, 관리자 화면에는 없다).
        $delay = config('providers_fake') ? (int) setting('qa.fake_delay_ms', 0) : 0;
        if ($delay > 0) {
            usleep(min($delay, $timeoutMs) * 1000);
            if ($delay >= $timeoutMs) {
                return [
                    'ok' => false, 'answer' => '', 'emotion' => null, 'on_topic' => true, 'unsafe' => false, 'unclear' => false,
                    'input_tokens' => 0, 'output_tokens' => 0, 'ms' => $timeoutMs, 'timed_out' => true,
                    'error' => self::label() . ' 응답 시간 초과(' . $timeoutMs . 'ms)',
                ];
            }
        }
        $c = self::cls();

        return $c::answerQuestion($ctx, $question, $timeoutMs);
    }

    public static function costUsd(int $inputTokens, int $outputTokens): float
    {
        $p = self::provider();
        if ($p === 'gemini') {
            return Usage::geminiCostUsd($inputTokens, $outputTokens);
        }
        if ($p === 'groq') {
            return Usage::groqCostUsd($inputTokens, $outputTokens);
        }

        return Usage::claudeCostUsd($inputTokens, $outputTokens);
    }

    /** @return array ['ok', 'ms', 'error'] */
    public static function ping(): array
    {
        $c = self::cls();

        return $c::ping();
    }
}
