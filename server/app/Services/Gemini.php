<?php
namespace App\Services;

use App\Core\HttpClient;

/**
 * Gemini API: 아이 질문 문장에 동화 맥락에 맞는 짧은 답을 만든다(관리자 설정에서 답변 모델로 고를 때만 쓴다).
 * 아이 음성은 보내지 않는다. 답은 JSON(responseSchema)으로 받는다.
 * 주의: Gemini API 추가 약관은 18세 미만이 쓰거나 쓸 가능성이 높은 서비스에서의 사용을 금지한다. 기본 답변 모델은 Claude 이다.
 * config('providers_fake') 가 true 면 네트워크 없이 정해진 형식의 가짜 답을 돌려준다.
 */
class Gemini
{
    const BASE = 'https://generativelanguage.googleapis.com/v1beta';

    public static function ready(): bool
    {
        return provider_ready('gemini');
    }

    /** API 주소. config 의 gemini.base_url 로 바꿀 수 있다(로컬 모의 서버 점검용, 보통은 비워 둔다). */
    private static function base(): string
    {
        $url = (string) config('gemini.base_url', '');

        return $url !== '' ? rtrim($url, '/') : self::BASE;
    }

    /** 설정의 모델 이름(경로에 넣으므로 안전한 글자만 남긴다) */
    public static function model(): string
    {
        $model = preg_replace('#^models/#', '', trim((string) setting('gemini.model', 'gemini-2.5-flash')));
        $model = preg_replace('/[^A-Za-z0-9.\-_]/', '', (string) $model);

        return $model !== '' ? $model : 'gemini-2.5-flash';
    }

    /**
     * 질문 문장에 답한다.
     * $ctx: QaPrompt::normalizeContext 항목, $timeoutMs: 이 호출에 쓸 수 있는 시간
     * @return array ['ok', 'answer', 'emotion', 'on_topic', 'unsafe', 'unclear', 'input_tokens', 'output_tokens', 'ms', 'timed_out', 'error']
     */
    public static function answerQuestion(array $ctx, string $question, int $timeoutMs = 3000): array
    {
        $started = microtime(true);
        $out = [
            'ok' => false, 'answer' => '', 'emotion' => null, 'on_topic' => true, 'unsafe' => false, 'unclear' => false,
            'input_tokens' => 0, 'output_tokens' => 0, 'ms' => 0, 'timed_out' => false, 'error' => null,
        ];
        $ctx = QaPrompt::normalizeContext($ctx);
        if (!self::ready()) {
            $out['error'] = 'Gemini API 키가 등록되지 않았습니다.';

            return $out;
        }
        if (trim($question) === '') {
            $out['error'] = '질문 문장이 비어 있습니다.';

            return $out;
        }

        if (config('providers_fake')) {
            $out = array_merge($out, QaPrompt::fakeAnswer($ctx, $question));
            $out['ms'] = (int) round((microtime(true) - $started) * 1000);

            return $out;
        }

        $model = self::model();
        $properties = [];
        foreach (QaPrompt::outputFields() as $name => $f) {
            $p = ['type' => strtoupper($f['type'])];
            if (isset($f['enum'])) {
                $p['format'] = 'enum';
                $p['enum'] = $f['enum'];
            }
            if (isset($f['description'])) {
                $p['description'] = $f['description'];
            }
            $properties[$name] = $p;
        }
        $payload = [
            'systemInstruction' => ['parts' => [['text' => QaPrompt::systemPrompt($ctx)]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [['text' => QaPrompt::contextPrompt($ctx, $question)]],
            ]],
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 300,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => $properties,
                    'required' => array_keys($properties),
                    'propertyOrdering' => array_keys($properties),
                ],
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
            ],
        ];
        // Flash 계열은 생각 단계를 끄면 응답이 빨라진다(실시간 대화용).
        if (strpos($model, 'flash') !== false) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $res = HttpClient::request('POST', self::base() . '/models/' . rawurlencode($model) . ':generateContent', [
            'headers' => ['x-goog-api-key' => (string) config('gemini.api_key', '')],
            'json' => $payload,
            'timeout_ms' => max(300, $timeoutMs),
        ]);
        $out['ms'] = (int) round((microtime(true) - $started) * 1000);
        if ($res['status'] === 0 && ($out['ms'] >= max(300, $timeoutMs) - 50 || stripos((string) $res['error'], 'timed out') !== false)) {
            $out['timed_out'] = true;
            $out['error'] = 'Gemini 응답 시간 초과(' . $out['ms'] . 'ms)';

            return $out;
        }
        $data = HttpClient::json($res);
        if ($res['status'] !== 200) {
            $out['error'] = self::errorMessage($res, $model);

            return $out;
        }
        self::readUsage($data, $out);

        // 안전 필터에 막힌 경우: 실패가 아니라 다정한 화제 전환으로 답한다.
        $blocked = !empty($data['promptFeedback']['blockReason']);
        $candidate = isset($data['candidates'][0]) ? $data['candidates'][0] : null;
        $finish = $candidate && isset($candidate['finishReason']) ? (string) $candidate['finishReason'] : '';
        if ($blocked || in_array($finish, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'SPII'], true)) {
            $out['ok'] = true;
            $out['unsafe'] = true;
            $out['on_topic'] = false;
            $out['emotion'] = '기타';
            $out['answer'] = QaPrompt::safeRedirect();

            return $out;
        }

        $text = '';
        if ($candidate && isset($candidate['content']['parts']) && is_array($candidate['content']['parts'])) {
            foreach ($candidate['content']['parts'] as $part) {
                if (!empty($part['thought'])) {
                    continue;
                }
                if (isset($part['text'])) {
                    $text .= (string) $part['text'];
                }
            }
        }
        $parsed = QaPrompt::readOutput($text);
        if ($parsed === null || $parsed['answer'] === '') {
            $out['error'] = 'Gemini 가 답을 만들지 못했습니다' . ($finish !== '' ? '(' . $finish . ')' : '') . '.';

            return $out;
        }

        return array_merge($out, $parsed, ['ok' => true]);
    }

    /**
     * 연결 확인(모델 정보 조회). 토큰을 쓰지 않는다.
     * @return array ['ok', 'ms', 'error']
     */
    public static function ping(): array
    {
        $started = microtime(true);
        if (!self::ready()) {
            return ['ok' => false, 'ms' => 0, 'error' => 'Gemini API 키가 등록되지 않았습니다.'];
        }
        if (config('providers_fake')) {
            return ['ok' => true, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => null];
        }
        $model = self::model();
        $res = HttpClient::request('GET', self::base() . '/models/' . rawurlencode($model), [
            'headers' => ['x-goog-api-key' => (string) config('gemini.api_key', '')],
            'timeout' => 15,
        ]);
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($res['status'] === 200) {
            return ['ok' => true, 'ms' => $ms, 'error' => null];
        }

        return ['ok' => false, 'ms' => $ms, 'error' => self::errorMessage($res, $model)];
    }

    // ───────────────────────── 내부 ─────────────────────────

    private static function readUsage(array $data, array &$out): void
    {
        $u = isset($data['usageMetadata']) && is_array($data['usageMetadata']) ? $data['usageMetadata'] : [];
        $out['input_tokens'] = (int) (isset($u['promptTokenCount']) ? $u['promptTokenCount'] : 0);
        // 생각 토큰도 출력 단가로 과금된다.
        $out['output_tokens'] = (int) (isset($u['candidatesTokenCount']) ? $u['candidatesTokenCount'] : 0)
            + (int) (isset($u['thoughtsTokenCount']) ? $u['thoughtsTokenCount'] : 0);
    }

    private static function errorMessage(array $res, string $model): string
    {
        $status = (int) $res['status'];
        if ($status === 0) {
            return 'Gemini 서버에 연결하지 못했습니다(네트워크 오류' . (!empty($res['error']) ? ': ' . str_limit((string) $res['error'], 80) : '') . ').';
        }
        $data = HttpClient::json($res);
        $msg = isset($data['error']['message']) ? (string) $data['error']['message'] : '';
        $reason = isset($data['error']['status']) ? (string) $data['error']['status'] : '';
        if (stripos($msg, 'API key') !== false && ($status === 400 || $status === 401 || $status === 403)) {
            return 'Gemini API 키가 올바르지 않습니다.';
        }
        if ($status === 429 || $reason === 'RESOURCE_EXHAUSTED') {
            return 'Gemini 사용 한도를 넘었습니다. 잠시 후 다시 시도해 주세요.';
        }
        if ($status === 403) {
            return 'Gemini API 권한이 없습니다(키 제한 또는 결제 설정을 확인해 주세요).';
        }
        if ($status === 404) {
            return 'Gemini 모델을 찾을 수 없습니다: ' . $model;
        }
        if ($status === 400) {
            return 'Gemini 요청이 거절되었습니다' . ($msg !== '' ? ': ' . str_limit($msg, 120) : '.');
        }
        if ($status >= 500) {
            return 'Gemini 서버가 일시적으로 응답하지 않습니다(' . $status . ').';
        }

        return 'Gemini 오류(' . $status . ')' . ($msg !== '' ? ': ' . str_limit($msg, 120) : '');
    }
}
