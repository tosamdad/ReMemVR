<?php
namespace App\Services;

/**
 * 아이 질문에 답할 AI 를 고른다(관리자 설정 qa.llm_provider).
 * claude(기본): Anthropic 은 안전 장치를 갖추면 미성년자 대상 서비스에서 쓰는 것을 허용한다.
 * gemini: Gemini API 추가 약관은 18세 미만이 쓰거나 쓸 가능성이 높은 서비스를 금지하므로 관리자 화면에 경고를 띄운다.
 */
class QaModel
{
    const PROVIDERS = [
        'claude' => 'Claude (Anthropic, 기본)',
        'gemini' => 'Gemini (Google)',
    ];

    public static function provider(): string
    {
        $p = (string) setting('qa.llm_provider', 'claude');

        return isset(self::PROVIDERS[$p]) ? $p : 'claude';
    }

    /** api_usage_logs.provider 와 provider_ready() 에 쓰는 이름 */
    public static function usageProvider(): string
    {
        return self::provider() === 'gemini' ? 'gemini' : 'anthropic';
    }

    public static function label(): string
    {
        return self::provider() === 'gemini' ? 'Gemini' : 'Claude';
    }

    public static function ready(): bool
    {
        return self::provider() === 'gemini' ? Gemini::ready() : Claude::ready();
    }

    public static function model(): string
    {
        return self::provider() === 'gemini' ? Gemini::model() : Claude::model();
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

        return self::provider() === 'gemini'
            ? Gemini::answerQuestion($ctx, $question, $timeoutMs)
            : Claude::answerQuestion($ctx, $question, $timeoutMs);
    }

    public static function costUsd(int $inputTokens, int $outputTokens): float
    {
        return self::provider() === 'gemini'
            ? Usage::geminiCostUsd($inputTokens, $outputTokens)
            : Usage::claudeCostUsd($inputTokens, $outputTokens);
    }

    /** @return array ['ok', 'ms', 'error'] */
    public static function ping(): array
    {
        return self::provider() === 'gemini' ? Gemini::ping() : Claude::ping();
    }
}
