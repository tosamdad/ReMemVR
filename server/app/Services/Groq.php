<?php
namespace App\Services;

use App\Core\HttpClient;

/**
 * Groq API(OpenAI 호환): 아이 질문 문장에 동화 맥락에 맞는 짧은 답을 만든다(관리자 설정에서 답변 모델로 고를 때만 쓴다).
 * 무료 요금제(하루 요청, 토큰 한도 있음)로 쓸 수 있고 응답이 빠르다. 오픈 모델(gpt-oss, Qwen 등)을 돌린다.
 * 답은 JSON(response_format json_object)으로 받는다.
 * config('providers_fake') 가 true 면 네트워크 없이 정해진 형식의 가짜 답을 돌려준다.
 */
class Groq
{
    const BASE = 'https://api.groq.com/openai/v1';
    const DEFAULT_MODEL = 'openai/gpt-oss-120b';

    public static function ready(): bool
    {
        return provider_ready('groq');
    }

    /** API 주소. config 의 groq.base_url 로 바꿀 수 있다(로컬 모의 서버 점검용, 보통은 비워 둔다). */
    private static function base(): string
    {
        $url = (string) config('groq.base_url', '');

        return $url !== '' ? rtrim($url, '/') : self::BASE;
    }

    /** 설정의 모델 이름(경로에 넣지 않지만 안전한 글자만 남긴다) */
    public static function model(): string
    {
        $model = preg_replace('/[^A-Za-z0-9.\-_\/]/', '', trim((string) setting('groq.model', self::DEFAULT_MODEL)));

        return $model !== '' ? (string) $model : self::DEFAULT_MODEL;
    }

    private static function headers(): array
    {
        return ['Authorization' => 'Bearer ' . (string) config('groq.api_key', '')];
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
            $out['error'] = 'Groq API 키가 등록되지 않았습니다.';

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
        $fields = [];
        foreach (QaPrompt::outputFields() as $name => $f) {
            $fields[] = $name . (isset($f['enum']) ? '(' . implode('|', $f['enum']) . ')' : '(' . $f['type'] . ')');
        }
        $payload = [
            'model' => $model,
            'messages' => [
                // json_object 모드는 지시문에 JSON 이라는 말과 항목이 있어야 한다.
                ['role' => 'system', 'content' => QaPrompt::systemPrompt($ctx) . "\n\n답은 JSON 객체 하나로만 한다. 항목: " . implode(', ', $fields)],
                ['role' => 'user', 'content' => QaPrompt::contextPrompt($ctx, $question)],
            ],
            'temperature' => 0.6,
            'max_completion_tokens' => 600,
            'response_format' => ['type' => 'json_object'],
        ];
        // gpt-oss 는 생각 단계가 있는 모델이라 가장 짧게 생각하게 한다.
        if (strpos($model, 'gpt-oss') !== false) {
            $payload['reasoning_effort'] = 'low';
        }

        $res = HttpClient::request('POST', self::base() . '/chat/completions', [
            'headers' => self::headers(),
            'json' => $payload,
            'timeout_ms' => max(300, $timeoutMs),
        ]);
        $out['ms'] = (int) round((microtime(true) - $started) * 1000);
        if ($res['status'] === 0 && ($out['ms'] >= max(300, $timeoutMs) - 50 || stripos((string) $res['error'], 'timed out') !== false)) {
            $out['timed_out'] = true;
            $out['error'] = 'Groq 응답 시간 초과(' . $out['ms'] . 'ms)';

            return $out;
        }
        $data = HttpClient::json($res);
        if ($res['status'] !== 200) {
            $out['error'] = self::errorMessage($res, $model);

            return $out;
        }
        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $out['input_tokens'] = (int) (isset($usage['prompt_tokens']) ? $usage['prompt_tokens'] : 0);
        $out['output_tokens'] = (int) (isset($usage['completion_tokens']) ? $usage['completion_tokens'] : 0);

        $choice = isset($data['choices'][0]) ? $data['choices'][0] : null;
        $finish = $choice && isset($choice['finish_reason']) ? (string) $choice['finish_reason'] : '';
        $text = $choice && isset($choice['message']['content']) ? (string) $choice['message']['content'] : '';
        $parsed = QaPrompt::readOutput($text);
        if ($parsed === null || $parsed['answer'] === '') {
            $out['error'] = 'Groq 가 답을 만들지 못했습니다' . ($finish !== '' && $finish !== 'stop' ? '(' . $finish . ')' : '') . '.';

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
            return ['ok' => false, 'ms' => 0, 'error' => 'Groq API 키가 등록되지 않았습니다.'];
        }
        if (config('providers_fake')) {
            return ['ok' => true, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => null];
        }
        $model = self::model();
        $res = HttpClient::request('GET', self::base() . '/models/' . str_replace('%2F', '/', rawurlencode($model)), [
            'headers' => self::headers(),
            'timeout' => 15,
        ]);
        $ms = (int) round((microtime(true) - $started) * 1000);
        if ($res['status'] === 200) {
            return ['ok' => true, 'ms' => $ms, 'error' => null];
        }

        return ['ok' => false, 'ms' => $ms, 'error' => self::errorMessage($res, $model)];
    }

    private static function errorMessage(array $res, string $model): string
    {
        $status = (int) $res['status'];
        if ($status === 0) {
            return 'Groq 서버에 연결하지 못했습니다(네트워크 오류' . (!empty($res['error']) ? ': ' . str_limit((string) $res['error'], 80) : '') . ').';
        }
        $data = HttpClient::json($res);
        $msg = isset($data['error']['message']) ? (string) $data['error']['message'] : '';
        if ($status === 401) {
            return 'Groq API 키가 올바르지 않습니다.';
        }
        if ($status === 403) {
            return 'Groq API 권한이 없습니다.';
        }
        if ($status === 404) {
            return 'Groq 모델을 찾을 수 없습니다: ' . $model;
        }
        if ($status === 429) {
            return 'Groq 사용 한도(무료 요금제 하루 한도 등)를 넘었습니다. 잠시 후 다시 시도해 주세요.';
        }
        if ($status === 400) {
            return 'Groq 요청이 거절되었습니다' . ($msg !== '' ? ': ' . str_limit($msg, 120) : '.');
        }
        if ($status >= 500) {
            return 'Groq 서버가 일시적으로 응답하지 않습니다(' . $status . ').';
        }

        return 'Groq 오류(' . $status . ')' . ($msg !== '' ? ': ' . str_limit($msg, 120) : '');
    }
}
