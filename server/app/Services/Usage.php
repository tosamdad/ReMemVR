<?php
namespace App\Services;

/**
 * 외부 API 사용량과 비용 기록(api_usage_logs).
 * 비용은 관리자 설정의 단가로 자체 계산한 추정치이다. 원화는 cost.usd_krw 환율로 바꾼다.
 */
class Usage
{
    /**
     * 사용 기록 한 건. 실패해도 예외를 던지지 않는다(본 기능을 막지 않기 위해).
     * $row: provider, purpose, model, user_id, ref_type, ref_id, unit_type(chars | tokens | requests), units, cost_usd, latency_ms, success
     */
    public static function log(array $row): void
    {
        try {
            $costUsd = isset($row['cost_usd']) ? max(0.0, (float) $row['cost_usd']) : 0.0;
            $unitType = isset($row['unit_type']) ? (string) $row['unit_type'] : 'requests';
            if (!in_array($unitType, ['chars', 'tokens', 'requests'], true)) {
                $unitType = 'requests';
            }
            db_insert('api_usage_logs', [
                'provider' => substr((string) (isset($row['provider']) ? $row['provider'] : 'unknown'), 0, 30),
                'purpose' => substr((string) (isset($row['purpose']) ? $row['purpose'] : 'unknown'), 0, 30),
                'model' => isset($row['model']) && $row['model'] !== '' ? substr((string) $row['model'], 0, 60) : null,
                'user_id' => !empty($row['user_id']) ? (int) $row['user_id'] : null,
                'ref_type' => isset($row['ref_type']) && $row['ref_type'] !== '' ? substr((string) $row['ref_type'], 0, 30) : null,
                'ref_id' => !empty($row['ref_id']) ? (int) $row['ref_id'] : null,
                'unit_type' => $unitType,
                'units' => isset($row['units']) ? max(0, (int) $row['units']) : 0,
                'cost_usd' => round($costUsd, 6),
                'cost_krw' => round(self::krw($costUsd), 2),
                'latency_ms' => isset($row['latency_ms']) && $row['latency_ms'] !== null ? max(0, (int) $row['latency_ms']) : null,
                'success' => array_key_exists('success', $row) ? ($row['success'] ? 1 : 0) : 1,
            ]);
        } catch (\Throwable $e) {
            app_log('error', 'API 사용량 기록 실패: ' . $e->getMessage(), [
                'provider' => isset($row['provider']) ? $row['provider'] : null,
                'purpose' => isset($row['purpose']) ? $row['purpose'] : null,
            ]);
        }
    }

    /** 달러 → 원(설정 환율) */
    public static function krw(float $usd): float
    {
        return $usd * (float) setting('cost.usd_krw', 1400);
    }

    /** 오늘(00:00 부터) 누적 비용(원). 일일 예산 확인에 쓴다. */
    public static function todayCostKrw(): float
    {
        try {
            return (float) db_value('SELECT COALESCE(SUM(cost_krw), 0) FROM api_usage_logs WHERE created_at >= ?', [date('Y-m-d 00:00:00')]);
        } catch (\Throwable $e) {
            app_log('error', '오늘 비용 집계 실패: ' . $e->getMessage());

            return 0.0;
        }
    }

    /** 모델의 글자당 크레딧. flash, turbo 계열은 설정 비율(기본 0.5), 나머지는 1 */
    public static function elevenlabsCreditRatio(string $modelId): float
    {
        $m = strtolower($modelId);
        if (strpos($m, 'flash') !== false || strpos($m, 'turbo') !== false) {
            return (float) setting('elevenlabs.flash_credit_ratio', 0.5);
        }

        return 1.0;
    }

    /** ElevenLabs 합성 비용(달러). 글자 수 × 모델 크레딧 비율 × 1천 크레딧당 단가 */
    public static function elevenlabsCostUsd(int $chars, string $modelId): float
    {
        $credits = max(0, $chars) * self::elevenlabsCreditRatio($modelId);

        return $credits / 1000 * (float) setting('elevenlabs.usd_per_1k_credits', 0.30);
    }

    /**
     * Gemini 비용(달러).
     * $inputTokens 는 Gemini 가 알려 주는 전체 입력 토큰(promptTokenCount, 음성 포함)이고,
     * 그중 $audioInputTokens 만큼은 음성 단가, 나머지는 텍스트 단가로 계산한다.
     */
    public static function geminiCostUsd(int $inputTokens, int $outputTokens, int $audioInputTokens = 0): float
    {
        $audio = max(0, $audioInputTokens);
        $text = max(0, $inputTokens - $audio);

        return $text / 1000000 * (float) setting('gemini.usd_per_1m_input', 0.30)
            + $audio / 1000000 * (float) setting('gemini.usd_per_1m_audio_input', 1.00)
            + max(0, $outputTokens) / 1000000 * (float) setting('gemini.usd_per_1m_output', 2.50);
    }

    /**
     * 기간 비용 요약(관리자 화면용). $from, $to 는 'Y-m-d H:i:s'
     * @return array ['total_krw', 'calls', 'failed', 'by_provider' => [provider => krw], 'by_purpose' => [purpose => ['krw', 'calls', 'units']]]
     */
    public static function summary(string $from, ?string $to = null): array
    {
        $to = $to ?: date('Y-m-d H:i:s', time() + 1);
        $out = ['total_krw' => 0.0, 'calls' => 0, 'failed' => 0, 'by_provider' => [], 'by_purpose' => []];
        try {
            $rows = db_all(
                'SELECT provider, purpose, COUNT(*) AS calls, SUM(success = 0) AS failed, SUM(units) AS units, SUM(cost_krw) AS krw
                   FROM api_usage_logs WHERE created_at >= ? AND created_at < ? GROUP BY provider, purpose',
                [$from, $to]
            );
        } catch (\Throwable $e) {
            app_log('error', '비용 요약 실패: ' . $e->getMessage());

            return $out;
        }
        foreach ($rows as $r) {
            $krw = (float) $r['krw'];
            $out['total_krw'] += $krw;
            $out['calls'] += (int) $r['calls'];
            $out['failed'] += (int) $r['failed'];
            $p = (string) $r['provider'];
            $out['by_provider'][$p] = (isset($out['by_provider'][$p]) ? $out['by_provider'][$p] : 0.0) + $krw;
            $u = (string) $r['purpose'];
            if (!isset($out['by_purpose'][$u])) {
                $out['by_purpose'][$u] = ['krw' => 0.0, 'calls' => 0, 'units' => 0];
            }
            $out['by_purpose'][$u]['krw'] += $krw;
            $out['by_purpose'][$u]['calls'] += (int) $r['calls'];
            $out['by_purpose'][$u]['units'] += (int) $r['units'];
        }

        return $out;
    }
}
