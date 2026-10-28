<?php
namespace App\Services;

use App\Core\HttpClient;

/**
 * Claude API(Anthropic): 아이 질문 문장에 동화 맥락에 맞는 짧은 답을 만든다.
 * Anthropic 은 안전 장치를 갖추면 미성년자 대상 서비스에서 쓰는 것을 허용한다(아이 질문 기능의 기본 답변 모델).
 * 다른 외부 API 와 같이 HttpClient 로 직접 부른다(서버에 composer 의존성을 두지 않는다).
 * 답은 structured outputs(output_config.format)로 받는다.
 * config('providers_fake') 가 true 면 네트워크 없이 정해진 형식의 가짜 답을 돌려준다.
 */
class Claude
{
    const BASE = 'https://api.anthropic.com/v1';
    const API_VERSION = '2026-10-21';
    const DEFAULT_MODEL = 'claude-haiku-5-5';
    /** 관리자 설정에서 고를 수 있는 모델 */
    const MODELS = [
        'claude-haiku-5-5' => 'Claude Haiku 5.5 (빠름, 권장)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 (답 품질 우선, 느리고 비쌈)',
    ];
    /** 1백만 토큰 단가(달러): [입력, 출력]. 2026-10 공개 가격 기준 */
    const PRICES = [
        'claude-haiku-5-5' => [0.10, 0.50],
        'claude-sonnet-5-5' => [2.00, 10.00],
    ];

    public static function ready(): bool
    {
        return provider_ready('anthropic');
    }

    /** API 주소. config 의 anthropic.base_url 로 바꿀 수 있다(로컬 모의 서버 점검용, 보통은 비워 둔다). */
    private static function base(): string
    {
        $url = (string) config('anthropic.base_url', '');

        return $url !== '' ? rtrim($url, '/') : self::BASE;
    }

    /** 설정의 모델 이름(목록에 없는 값이면 기본 모델) */
    public static function model(): string
    {
        $model = trim((string) setting('claude.model', self::DEFAULT_MODEL));

        return isset(self::MODELS[$model]) ? $model : self::DEFAULT_MODEL;
    }

    private static function headers(): array
    {
        return [
            'x-api-key' => (string) config('anthropic.api_key', ''),
            'anthropic-version' => self::API_VERSION,
        ];
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
            $out['error'] = 'Claude API 키가 등록되지 않았습니다.';

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
        $payload = [
            'model' => $model,
            // 생각을 끄므로 답 JSON 만 들어가면 된다(120자 답은 200토큰 안팎).
            'max_tokens' => 600,
            'system' => QaPrompt::systemPrompt($ctx),
            'messages' => [['role' => 'user', 'content' => QaPrompt::contextPrompt($ctx, $question)]],
            'output_config' => [
                'effort' => 'low',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => QaPrompt::outputFields(),
                        'required' => array_keys(QaPrompt::outputFields()),
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
        // 짧은 답이라 생각 단계를 끄면 가장 빠르다. Sonnet 5.5 는 disabled 대신 between_tools 로 끈다.
        if (strpos($model, 'haiku') !== false) {
            $payload['thinking'] = ['type' => 'disabled'];
        } elseif ($model === 'claude-sonnet-5-5') {
            $payload['thinking'] = ['type' => 'between_tools'];
        }

        $res = HttpClient::request('POST', self::base() . '/messages', [
            'headers' => self::headers(),
            'json' => $payload,
            'timeout_ms' => max(300, $timeoutMs),
        ]);
        $out['ms'] = (int) round((microtime(true) - $started) * 1000);
        if ($res['status'] === 0 && ($out['ms'] >= max(300, $timeoutMs) - 50 || stripos((string) $res['error'], 'timed out') !== false)) {
            $out['timed_out'] = true;
            $out['error'] = 'Claude 응답 시간 초과(' . $out['ms'] . 'ms)';

            return $out;
        }
        $data = HttpClient::json($res);
        if ($res['status'] !== 200) {
            $out['error'] = self::errorMessage($res, $model);

            return $out;
        }
        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $out['input_tokens'] = (int) (isset($usage['input_tokens']) ? $usage['input_tokens'] : 0)
            + (int) (isset($usage['cache_read_input_tokens']) ? $usage['cache_read_input_tokens'] : 0)
            + (int) (isset($usage['cache_creation_input_tokens']) ? $usage['cache_creation_input_tokens'] : 0);
        $out['output_tokens'] = (int) (isset($usage['output_tokens']) ? $usage['output_tokens'] : 0);

        // 안전 분류기가 거절한 경우: 실패가 아니라 다정한 화제 전환으로 답한다.
        $stop = isset($data['stop_reason']) ? (string) $data['stop_reason'] : '';
        if ($stop === 'refusal') {
            $out['ok'] = true;
            $out['unsafe'] = true;
            $out['on_topic'] = false;
            $out['emotion'] = '기타';
            $out['answer'] = QaPrompt::safeRedirect();

            return $out;
        }

        $text = '';
        if (isset($data['content']) && is_array($data['content'])) {
            foreach ($data['content'] as $block) {
                if (isset($block['type']) && $block['type'] === 'text' && isset($block['text'])) {
                    $text .= (string) $block['text'];
                }
            }
        }
        $parsed = QaPrompt::readOutput($text);
        if ($parsed === null || $parsed['answer'] === '') {
            $out['error'] = 'Claude 가 답을 만들지 못했습니다' . ($stop !== '' && $stop !== 'end_turn' ? '(' . $stop . ')' : '') . '.';

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
            return ['ok' => false, 'ms' => 0, 'error' => 'Claude API 키가 등록되지 않았습니다.'];
        }
        if (config('providers_fake')) {
            return ['ok' => true, 'ms' => (int) round((microtime(true) - $started) * 1000), 'error' => null];
        }
        $model = self::model();
        $res = HttpClient::request('GET', self::base() . '/models/' . rawurlencode($model), [
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
            return 'Claude 서버에 연결하지 못했습니다(네트워크 오류' . (!empty($res['error']) ? ': ' . str_limit((string) $res['error'], 80) : '') . ').';
        }
        $data = HttpClient::json($res);
        $msg = isset($data['error']['message']) ? (string) $data['error']['message'] : '';
        if ($status === 401) {
            return 'Claude API 키가 올바르지 않습니다.';
        }
        if ($status === 403) {
            return 'Claude API 권한이 없습니다(키 권한 또는 결제 설정을 확인해 주세요).';
        }
        if ($status === 404) {
            return 'Claude 모델을 찾을 수 없습니다: ' . $model;
        }
        if ($status === 429) {
            return 'Claude 사용 한도를 넘었습니다. 잠시 후 다시 시도해 주세요.';
        }
        if ($status === 400) {
            return 'Claude 요청이 거절되었습니다' . ($msg !== '' ? ': ' . str_limit($msg, 120) : '.');
        }
        if ($status === 529 || $status >= 500) {
            return 'Claude 서버가 일시적으로 응답하지 않습니다(' . $status . ').';
        }

        return 'Claude 오류(' . $status . ')' . ($msg !== '' ? ': ' . str_limit($msg, 120) : '');
    }
}
