<?php
namespace App\Services;

use App\Core\HttpClient;

/**
 * Gemini API: 아이 질문 음성을 듣고 동화 맥락에 맞는 짧은 답을 만든다.
 * 음성을 직접 넣으므로 별도 음성 인식 단계가 없다. 답은 JSON(responseSchema)으로 받는다.
 * config('providers_fake') 가 true 면 네트워크 없이 정해진 형식의 가짜 답을 돌려준다.
 */
class Gemini
{
    const BASE = 'https://generativelanguage.googleapis.com/v1beta';
    const EMOTIONS = ['호기심', '공감', '걱정', '기쁨', '무서움', '기타'];

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
     * 질문 음성에 답한다.
     * $ctx: story_title, persona, child_name, child_age(?int), context_before, current_sentence, context_after, max_chars
     * @return array ['ok', 'question', 'answer', 'emotion', 'on_topic', 'unsafe', 'input_tokens', 'output_tokens', 'audio_tokens', 'ms', 'error']
     */
    public static function answerQuestion(array $ctx, string $audioBytes, string $mime): array
    {
        $started = microtime(true);
        $out = [
            'ok' => false, 'question' => '', 'answer' => '', 'emotion' => null, 'on_topic' => true, 'unsafe' => false,
            'input_tokens' => 0, 'output_tokens' => 0, 'audio_tokens' => 0, 'ms' => 0, 'error' => null,
        ];
        $ctx = self::normalizeContext($ctx);
        if (!self::ready()) {
            $out['error'] = 'Gemini API 키가 등록되지 않았습니다.';

            return $out;
        }
        if ($audioBytes === '') {
            $out['error'] = '질문 음성이 비어 있습니다.';

            return $out;
        }

        if (config('providers_fake')) {
            $out = array_merge($out, self::fakeAnswer($ctx, $audioBytes));
            $out['ms'] = (int) round((microtime(true) - $started) * 1000);

            return $out;
        }

        $model = self::model();
        $payload = [
            'systemInstruction' => ['parts' => [['text' => self::systemPrompt($ctx)]]],
            'contents' => [[
                'role' => 'user',
                'parts' => [
                    ['inline_data' => ['mime_type' => self::audioMime($mime), 'data' => base64_encode($audioBytes)]],
                    ['text' => self::contextPrompt($ctx)],
                ],
            ]],
            'generationConfig' => [
                'temperature' => 0.6,
                'maxOutputTokens' => 300,
                'responseMimeType' => 'application/json',
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'question' => ['type' => 'STRING', 'description' => '아이가 한 말을 들리는 그대로 적은 한국어 문장. 분명한 말이 없으면 빈 문자열'],
                        'answer' => ['type' => 'STRING', 'description' => '아이에게 들려줄 답(1~2문장)'],
                        'emotion' => ['type' => 'STRING', 'format' => 'enum', 'enum' => self::EMOTIONS],
                        'on_topic' => ['type' => 'BOOLEAN'],
                        'unsafe' => ['type' => 'BOOLEAN'],
                    ],
                    'required' => ['question', 'answer', 'emotion', 'on_topic', 'unsafe'],
                    'propertyOrdering' => ['question', 'answer', 'emotion', 'on_topic', 'unsafe'],
                ],
            ],
            'safetySettings' => [
                ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
                ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_LOW_AND_ABOVE'],
            ],
        ];
        // 2.5 Flash 는 생각 단계를 끄면 응답이 빨라진다(실시간 대화용).
        if (strpos($model, '2.5-flash') !== false) {
            $payload['generationConfig']['thinkingConfig'] = ['thinkingBudget' => 0];
        }

        $res = HttpClient::request('POST', self::base() . '/models/' . rawurlencode($model) . ':generateContent', [
            'headers' => ['x-goog-api-key' => (string) config('gemini.api_key', '')],
            'json' => $payload,
            'timeout' => 30,
        ]);
        $out['ms'] = (int) round((microtime(true) - $started) * 1000);
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
            $out['answer'] = self::safeRedirect();

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
        $parsed = self::parseJson($text);
        if ($parsed === null) {
            $parsed = self::salvage($text);
            if ($parsed === null) {
                $out['error'] = 'Gemini 응답을 읽지 못했습니다' . ($finish !== '' ? '(' . $finish . ')' : '') . '.';

                return $out;
            }
        }
        $out['ok'] = true;
        $out['question'] = trim((string) (isset($parsed['question']) ? $parsed['question'] : ''));
        $out['answer'] = trim((string) (isset($parsed['answer']) ? $parsed['answer'] : ''));
        $emotion = isset($parsed['emotion']) ? trim((string) $parsed['emotion']) : '';
        $out['emotion'] = in_array($emotion, self::EMOTIONS, true) ? $emotion : ($emotion !== '' ? '기타' : null);
        $out['on_topic'] = isset($parsed['on_topic']) ? (bool) $parsed['on_topic'] : true;
        $out['unsafe'] = !empty($parsed['unsafe']);
        if ($out['answer'] === '') {
            $out['ok'] = false;
            $out['error'] = 'Gemini 가 답을 만들지 못했습니다.';
        }

        return $out;
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

    /** 위험한 주제, 금지어가 나왔을 때 들려줄 화제 전환 문장 */
    public static function safeRedirect(): string
    {
        return '그 이야기는 이야기가 끝나고 꼭 같이 이야기해 보자. 지금은 우리 동화를 계속 들어 볼까?';
    }

    /** 아이 이름 부르는 말. 받침이 있으면 '아', 없으면 '야'(민준아, 서연아, 지우야) */
    public static function vocative(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '우리 아가';
        }
        $last = self::ord(mb_substr($name, -1));
        if ($last >= 0xAC00 && $last <= 0xD7A3) {
            return $name . ((($last - 0xAC00) % 28) > 0 ? '아' : '야');
        }

        return $name;
    }

    /** 받침에 맞는 조사를 붙인다. josa('엄마', '이', '가') → '엄마가'. 끝의 문장 부호, 괄호는 건너뛰고 본다. */
    public static function josa(string $word, string $withBatchim, string $without): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $code = self::ord($chars[$i]);
            if ($code >= 0xAC00 && $code <= 0xD7A3) {
                return $word . ((($code - 0xAC00) % 28) > 0 ? $withBatchim : $without);
            }
            if (preg_match('/[A-Za-z0-9]/', $chars[$i])) {
                break;
            }
        }

        return $word . $without;
    }

    // ───────────────────────── 프롬프트 ─────────────────────────

    private static function normalizeContext(array $ctx): array
    {
        $clean = static function ($v, int $max) {
            $v = preg_replace('/\s+/u', ' ', (string) $v);

            return mb_substr(trim((string) $v), 0, $max);
        };
        $age = isset($ctx['child_age']) && $ctx['child_age'] !== null && $ctx['child_age'] !== '' ? (int) $ctx['child_age'] : null;

        return [
            'story_title' => $clean(isset($ctx['story_title']) ? $ctx['story_title'] : '', 100),
            'persona' => $clean(isset($ctx['persona']) && $ctx['persona'] !== '' ? $ctx['persona'] : '보호자', 20),
            'child_name' => $clean(isset($ctx['child_name']) ? $ctx['child_name'] : '', 20),
            'child_age' => $age !== null ? max(0, min(15, $age)) : null,
            'context_before' => $clean(isset($ctx['context_before']) ? $ctx['context_before'] : '', 2000),
            'current_sentence' => $clean(isset($ctx['current_sentence']) ? $ctx['current_sentence'] : '', 500),
            'context_after' => $clean(isset($ctx['context_after']) ? $ctx['context_after'] : '', 600),
            'max_chars' => max(20, min(400, isset($ctx['max_chars']) ? (int) $ctx['max_chars'] : 120)),
        ];
    }

    /** 페르소나 지시문(시스템 프롬프트) */
    public static function systemPrompt(array $ctx): string
    {
        $persona = $ctx['persona'];
        $call = $ctx['child_name'] !== '' ? self::vocative($ctx['child_name']) : '';
        $age = $ctx['child_age'] !== null ? '만 ' . $ctx['child_age'] . '세' : '어린';
        $title = $ctx['story_title'] !== '' ? '「' . $ctx['story_title'] . '」' : '동화';

        $lines = [
            '너는 아이에게 ' . self::josa($title, '을', '를') . ' 소리 내어 읽어 주고 있는 아이의 ' . self::josa($persona, '이야', '야') . '.',
            '아이가 이야기를 듣다가 말을 걸었어. 함께 보낸 음성이 아이가 방금 한 말이야.',
            '',
            '[대답하는 법]',
            '1. ' . self::josa($persona, '이', '가') . ' 아이에게 말하듯 따뜻하고 다정한 반말로 대답해.' . ($call !== '' ? ' 아이를 부를 때는 "' . $call . '"라고 불러.' : ''),
            '2. ' . $age . ' 아이가 바로 알아듣는 쉬운 낱말만 써.',
            '3. 답은 1~2문장, ' . $ctx['max_chars'] . '자 이내로 짧게 해.',
            '4. 무섭거나 잔인한 표현, 겁을 주는 말은 절대 하지 마.',
            '5. 아직 읽지 않은 뒷이야기는 미리 알려 주지 마.',
            '6. 답의 끝에는 이야기를 계속 듣자고 부드럽게 이끌어(예: "우리 계속 들어 볼까?").',
            '7. 음성에 분명한 질문이나 말이 없으면 question 은 빈 문자열로 두고, answer 에는 "다시 한 번 말해 줄래?"처럼 짧고 다정하게 되물어.',
            '8. 아이에게 맞지 않거나 위험한 주제(폭력, 성, 다침, 낯선 사람 등)가 나오면 unsafe 를 true 로 하고, 자세히 설명하지 말고 그 이야기는 이야기가 끝난 뒤 함께 이야기하자고 다정하게 말한 다음 동화로 돌아가.',
            '',
            '[출력 항목]',
            '- question: 아이가 한 말을 들리는 그대로 한국어로 적어.',
            '- answer: 아이에게 들려줄 답. 소리 내어 읽을 글이므로 이모지, 괄호, 특수 기호를 쓰지 마.',
            '- emotion: 아이 말에서 느껴지는 감정. 호기심, 공감, 걱정, 기쁨, 무서움, 기타 중 하나.',
            '- on_topic: 아이 말이 동화 내용과 관련 있으면 true.',
            '- unsafe: 8번에 해당하면 true.',
        ];

        return implode("\n", $lines);
    }

    /** 동화 맥락(사용자 메시지의 텍스트 부분) */
    public static function contextPrompt(array $ctx): string
    {
        $who = $ctx['child_name'] !== '' ? $ctx['child_name'] : '아이';
        if ($ctx['child_age'] !== null) {
            $who .= ' (만 ' . $ctx['child_age'] . '세)';
        }
        $lines = [
            '[동화 정보]',
            '제목: ' . ($ctx['story_title'] !== '' ? $ctx['story_title'] : '(제목 없음)'),
            '듣는 아이: ' . $who,
            '읽어 주는 사람: ' . $ctx['persona'],
            '',
            '[지금까지 읽은 부분]',
            $ctx['context_before'] !== '' ? $ctx['context_before'] : '(처음 부분)',
            '',
            '[아이가 말을 걸 때 읽고 있던 문장]',
            $ctx['current_sentence'] !== '' ? $ctx['current_sentence'] : '(알 수 없음)',
        ];
        if ($ctx['context_after'] !== '') {
            $lines[] = '';
            $lines[] = '[곧 읽을 부분: 답에서 미리 말하지 말 것]';
            $lines[] = $ctx['context_after'];
        }
        $lines[] = '';
        $lines[] = '음성을 듣고 규칙에 맞게 JSON 으로 답해.';

        return implode("\n", $lines);
    }

    // ───────────────────────── 내부 ─────────────────────────

    /** Gemini 가 받는 오디오 MIME 으로 맞춘다. */
    private static function audioMime(string $mime): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $map = [
            'audio/x-wav' => 'audio/wav', 'audio/wave' => 'audio/wav', 'audio/vnd.wave' => 'audio/wav', 'audio/wav' => 'audio/wav',
            'audio/mpeg' => 'audio/mp3', 'audio/mp3' => 'audio/mp3', 'audio/ogg' => 'audio/ogg', 'audio/flac' => 'audio/flac',
            'audio/x-flac' => 'audio/flac', 'audio/aac' => 'audio/aac', 'audio/aiff' => 'audio/aiff', 'audio/x-aiff' => 'audio/aiff',
            'audio/webm' => 'audio/webm', 'video/webm' => 'audio/webm', 'audio/mp4' => 'audio/mp4', 'audio/x-m4a' => 'audio/mp4', 'audio/m4a' => 'audio/mp4',
        ];

        return isset($map[$mime]) ? $map[$mime] : 'audio/wav';
    }

    private static function readUsage(array $data, array &$out): void
    {
        $u = isset($data['usageMetadata']) && is_array($data['usageMetadata']) ? $data['usageMetadata'] : [];
        $out['input_tokens'] = (int) (isset($u['promptTokenCount']) ? $u['promptTokenCount'] : 0);
        // 생각 토큰도 출력 단가로 과금된다.
        $out['output_tokens'] = (int) (isset($u['candidatesTokenCount']) ? $u['candidatesTokenCount'] : 0)
            + (int) (isset($u['thoughtsTokenCount']) ? $u['thoughtsTokenCount'] : 0);
        $audio = 0;
        if (isset($u['promptTokensDetails']) && is_array($u['promptTokensDetails'])) {
            foreach ($u['promptTokensDetails'] as $d) {
                if (isset($d['modality']) && strtoupper((string) $d['modality']) === 'AUDIO') {
                    $audio += (int) (isset($d['tokenCount']) ? $d['tokenCount'] : 0);
                }
            }
        }
        $out['audio_tokens'] = $audio;
    }

    /** 모델이 돌려준 JSON 글(가끔 ```json 으로 감싸짐)을 배열로 */
    private static function parseJson(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (strpos($text, '```') === 0) {
            $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text);
        }
        $data = json_decode((string) $text, true);
        if (!is_array($data)) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $data = json_decode(substr($text, $start, $end - $start + 1), true);
            }
        }

        return is_array($data) && isset($data['answer']) ? $data : null;
    }

    /**
     * 출력 한도에 걸려 JSON 이 중간에 끊긴 경우: 읽을 수 있는 question, answer 만 건진다.
     * JSON 모양이 아닌 글이면 글 전체를 답으로 쓴다. 건질 답이 없으면 null
     */
    public static function salvage(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (strpos($text, '{') === false && strpos($text, '"answer"') === false) {
            return ['question' => '', 'answer' => $text];
        }
        $field = static function (string $name) use ($text): string {
            if (!preg_match('/"' . $name . '"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/su', $text, $m)) {
                return '';
            }
            $decoded = json_decode('"' . $m[1] . '"');
            if (!is_string($decoded)) {
                // 끝이 이스케이프 중간에서 끊겼으면 마지막 역슬래시를 떼고 다시 읽는다.
                $decoded = json_decode('"' . rtrim($m[1], '\\') . '"');
            }

            return is_string($decoded) ? trim($decoded) : '';
        };
        $answer = $field('answer');
        if ($answer === '') {
            return null;
        }

        return ['question' => $field('question'), 'answer' => $answer];
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

    /** 개발 모드 가짜 답(같은 입력이면 같은 답) */
    private static function fakeAnswer(array $ctx, string $audioBytes): array
    {
        $title = $ctx['story_title'] !== '' ? $ctx['story_title'] : '이 이야기';
        $call = $ctx['child_name'] !== '' ? self::vocative($ctx['child_name']) : '우리 아가';
        $seconds = FakeAudio::wavDurationMs($audioBytes);
        $seconds = $seconds !== null ? $seconds / 1000 : strlen($audioBytes) / 32000;
        $audioTokens = (int) ceil($seconds * 32);
        $promptChars = mb_strlen(self::systemPrompt($ctx) . self::contextPrompt($ctx));

        if ($seconds < 0.3) {
            // 너무 짧으면 말이 없었던 것으로 보고 되묻는다.
            $q = '';
            $a = $call . ', 잘 못 들었어. 다시 한 번 말해 줄래?';
            $emotion = '기타';
        } else {
            $pairs = [
                [$title . '에서 다음엔 어떻게 돼요?', $call . ', 정말 궁금하구나! ' . $title . '의 뒷이야기는 같이 들어 보면 알 수 있어. 우리 계속 들어 볼까?'],
                ['왜 그렇게 됐어요?', '좋은 질문이야, ' . $call . '. ' . $title . ' 속 친구도 그게 궁금했을 거야. 조금만 더 들어 보자!'],
                [$title . '에 나오는 친구는 무서웠어요?', '아니야, ' . $call . '. 친구는 용기를 냈단다. 우리 같이 끝까지 들어 볼까?'],
            ];
            $pick = $pairs[(int) (sprintf('%u', crc32($audioBytes . $ctx['current_sentence'])) % count($pairs))];
            $q = $pick[0];
            $a = $pick[1];
            $emotion = '호기심';
        }
        if (mb_strlen($a) > $ctx['max_chars']) {
            $a = mb_substr($a, 0, $ctx['max_chars']);
        }
        $json = json_encode_u(['question' => $q, 'answer' => $a]);

        return [
            'ok' => true,
            'question' => $q,
            'answer' => $a,
            'emotion' => $emotion,
            'on_topic' => true,
            'unsafe' => false,
            'input_tokens' => $audioTokens + (int) ceil($promptChars / 1.5),
            'output_tokens' => (int) ceil(mb_strlen($json) / 1.5),
            'audio_tokens' => $audioTokens,
            'error' => null,
        ];
    }

    /** 한 글자(UTF-8)의 유니코드 코드값 */
    private static function ord(string $ch): int
    {
        if ($ch === '') {
            return 0;
        }
        $b = array_values(unpack('C*', substr($ch, 0, 4)));
        if ($b[0] < 0x80) {
            return $b[0];
        }
        if ($b[0] < 0xE0 && isset($b[1])) {
            return (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F);
        }
        if ($b[0] < 0xF0 && isset($b[2])) {
            return (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F);
        }
        if (isset($b[3])) {
            return (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F);
        }

        return 0;
    }
}
