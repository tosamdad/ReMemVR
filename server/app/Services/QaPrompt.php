<?php
namespace App\Services;

/**
 * 아이 질문 답변에 쓰는 공통 부분: 프롬프트, 출력 형식, 응답 읽기, 개발 모드 가짜 답.
 * 답변 모델(Claude, Gemini)은 같은 프롬프트와 출력 항목을 쓰고, 고르는 일은 QaModel 이 맡는다.
 *
 * 아이 음성은 받지 않는다. 앱이 폰 안에서 글로 바꾼 질문 문장만 들어온다.
 * 아이 실명은 모델과 음성 합성(ElevenLabs)에 보내지 않는다. 부를 때는 '우리 친구'라고 한다.
 */
class QaPrompt
{
    const EMOTIONS = ['호기심', '공감', '걱정', '기쁨', '무서움', '기타'];

    /** 질문 문장 최대 글자 수(앱 음성 인식 결과가 이보다 길면 자른다) */
    const MAX_QUESTION_CHARS = 200;

    /** 출력 항목(JSON 스키마). Claude structured outputs 와 Gemini responseSchema 가 같은 항목을 쓴다. */
    public static function outputFields(): array
    {
        return [
            'answer' => ['type' => 'string', 'description' => '아이에게 들려줄 답(1~2문장)'],
            'emotion' => ['type' => 'string', 'enum' => self::EMOTIONS],
            'on_topic' => ['type' => 'boolean'],
            'unsafe' => ['type' => 'boolean'],
            'unclear' => ['type' => 'boolean'],
        ];
    }

    /** 위험한 주제, 금지어가 나왔을 때 들려줄 화제 전환 문장 */
    public static function safeRedirect(): string
    {
        return '그 이야기는 이야기가 끝나고 꼭 같이 이야기해 보자. 지금은 우리 동화를 계속 들어 볼까?';
    }

    /**
     * 질문 문장 정리: 공백 정리, 제어 문자 제거, 길이 제한, 개인정보로 보이는 부분(전화번호, 이메일, 긴 숫자) 가리기.
     * @return array ['text' => string, 'masked' => bool]
     */
    public static function cleanQuestion(string $text): array
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = mb_substr($text, 0, self::MAX_QUESTION_CHARS);
        $before = $text;
        $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u', '○○', $text);
        $text = (string) preg_replace('/\d[\d\s\-]{6,}\d/u', '○○', $text);

        return ['text' => $text, 'masked' => $text !== $before];
    }

    // ───────────────────────── 프롬프트 ─────────────────────────

    /**
     * $ctx: story_title, persona, child_age(?int), context_before, current_sentence, context_after, max_chars
     */
    public static function normalizeContext(array $ctx): array
    {
        $clean = static function ($v, int $max) {
            $v = preg_replace('/\s+/u', ' ', (string) $v);

            return mb_substr(trim((string) $v), 0, $max);
        };
        $age = isset($ctx['child_age']) && $ctx['child_age'] !== null && $ctx['child_age'] !== '' ? (int) $ctx['child_age'] : null;

        return [
            'story_title' => $clean(isset($ctx['story_title']) ? $ctx['story_title'] : '', 100),
            'persona' => $clean(isset($ctx['persona']) && $ctx['persona'] !== '' ? $ctx['persona'] : '보호자', 20),
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
        $age = $ctx['child_age'] !== null ? '만 ' . $ctx['child_age'] . '세' : '어린';
        $title = $ctx['story_title'] !== '' ? '「' . $ctx['story_title'] . '」' : '동화';

        $lines = [
            '너는 아이에게 ' . self::josa($title, '을', '를') . ' 소리 내어 읽어 주고 있는 아이의 ' . self::josa($persona, '이야', '야') . '.',
            '아이가 이야기를 듣다가 말을 걸었어. 아이가 한 말은 음성 인식으로 받아 적은 글이라 틀린 글자가 있을 수 있어. 뜻을 너그럽게 짐작해.',
            '',
            '[대답하는 법]',
            '1. ' . self::josa($persona, '이', '가') . ' 아이에게 말하듯 따뜻하고 다정한 반말로 대답해. 아이 이름은 부르지 말고, 부를 때는 "우리 친구"라고 해.',
            '2. ' . $age . ' 아이가 바로 알아듣는 쉬운 낱말만 써.',
            '3. 답은 1~2문장, ' . $ctx['max_chars'] . '자 이내로 짧게 해.',
            '4. 무섭거나 잔인한 표현, 겁을 주는 말은 절대 하지 마.',
            '5. 아직 읽지 않은 뒷이야기는 미리 알려 주지 마.',
            '6. 답의 끝에는 이야기를 계속 듣자고 부드럽게 이끌어(예: "우리 계속 들어 볼까?").',
            '7. 무슨 말인지 알 수 없으면 unclear 를 true 로 하고, answer 에는 "다시 한 번 말해 줄래?"처럼 짧고 다정하게 되물어.',
            '8. 아이에게 맞지 않거나 위험한 주제(폭력, 성, 다침, 낯선 사람 등)가 나오면 unsafe 를 true 로 하고, 자세히 설명하지 말고 그 이야기는 이야기가 끝난 뒤 함께 이야기하자고 다정하게 말한 다음 동화로 돌아가.',
            '9. 전화번호, 주소, 학교 이름 같은 개인 정보는 묻지도 말하지도 마.',
            '10. 너는 진짜 가족이 아니라 가족 목소리로 답해 주는 컴퓨터라는 사실을 숨기지 마. 아이가 물으면 솔직하게 말해.',
            '',
            '[출력 항목]',
            '- answer: 아이에게 들려줄 답. 소리 내어 읽을 글이므로 이모지, 괄호, 특수 기호를 쓰지 마.',
            '- emotion: 아이 말에서 느껴지는 감정. ' . implode(', ', self::EMOTIONS) . ' 중 하나.',
            '- on_topic: 아이 말이 동화 내용과 관련 있으면 true.',
            '- unsafe: 8번에 해당하면 true.',
            '- unclear: 7번에 해당하면 true.',
        ];

        return implode("\n", $lines);
    }

    /** 동화 맥락과 아이 질문(사용자 메시지) */
    public static function contextPrompt(array $ctx, string $question): string
    {
        $who = '아이';
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
        $lines[] = '[아이가 한 말]';
        $lines[] = $question;
        $lines[] = '';
        $lines[] = '규칙에 맞게 JSON 으로 답해.';

        return implode("\n", $lines);
    }

    // ───────────────────────── 응답 읽기 ─────────────────────────

    /**
     * 모델이 돌려준 JSON 글을 결과 항목으로 바꾼다. 읽지 못하면 null
     * @return array|null ['answer', 'emotion', 'on_topic', 'unsafe', 'unclear']
     */
    public static function readOutput(string $text): ?array
    {
        $parsed = self::parseJson($text);
        if ($parsed === null) {
            $parsed = self::salvage($text);
        }
        if ($parsed === null) {
            return null;
        }
        $emotion = isset($parsed['emotion']) ? trim((string) $parsed['emotion']) : '';

        return [
            'answer' => trim((string) (isset($parsed['answer']) ? $parsed['answer'] : '')),
            'emotion' => in_array($emotion, self::EMOTIONS, true) ? $emotion : ($emotion !== '' ? '기타' : null),
            'on_topic' => isset($parsed['on_topic']) ? (bool) $parsed['on_topic'] : true,
            'unsafe' => !empty($parsed['unsafe']),
            'unclear' => !empty($parsed['unclear']),
        ];
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
     * 출력 한도에 걸려 JSON 이 중간에 끊긴 경우: 읽을 수 있는 answer 만 건진다.
     * JSON 모양이 아닌 글이면 글 전체를 답으로 쓴다. 건질 답이 없으면 null
     */
    public static function salvage(string $text): ?array
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        if (strpos($text, '{') === false && strpos($text, '"answer"') === false) {
            return ['answer' => $text];
        }
        if (!preg_match('/"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/su', $text, $m)) {
            return null;
        }
        $decoded = json_decode('"' . $m[1] . '"');
        if (!is_string($decoded)) {
            // 끝이 이스케이프 중간에서 끊겼으면 마지막 역슬래시를 떼고 다시 읽는다.
            $decoded = json_decode('"' . rtrim($m[1], '\\') . '"');
        }
        $answer = is_string($decoded) ? trim($decoded) : '';

        return $answer !== '' ? ['answer' => $answer] : null;
    }

    // ───────────────────────── 개발 모드 ─────────────────────────

    /**
     * 개발 모드 가짜 답(같은 입력이면 같은 답). 모델 결과와 같은 모양이다.
     * @return array ['ok', 'answer', 'emotion', 'on_topic', 'unsafe', 'unclear', 'input_tokens', 'output_tokens', 'error']
     */
    public static function fakeAnswer(array $ctx, string $question): array
    {
        $title = $ctx['story_title'] !== '' ? $ctx['story_title'] : '이 이야기';
        $promptChars = mb_strlen(self::systemPrompt($ctx) . self::contextPrompt($ctx, $question));

        if (mb_strlen(preg_replace('/[^\p{L}\p{N}]/u', '', $question)) < 2) {
            // 글자가 거의 없으면 말이 없었던 것으로 보고 되묻는다.
            $a = '우리 친구, 잘 못 들었어. 다시 한 번 말해 줄래?';
            $emotion = '기타';
            $unclear = true;
        } else {
            $answers = [
                '우리 친구, 정말 궁금하구나! ' . $title . '의 뒷이야기는 같이 들어 보면 알 수 있어. 우리 계속 들어 볼까?',
                '좋은 질문이야, 우리 친구. ' . $title . ' 속 친구도 그게 궁금했을 거야. 조금만 더 들어 보자!',
                '아니야, 우리 친구. ' . $title . ' 속 친구는 용기를 냈단다. 우리 같이 끝까지 들어 볼까?',
            ];
            $a = $answers[(int) (sprintf('%u', crc32($question . $ctx['current_sentence'])) % count($answers))];
            $emotion = '호기심';
            $unclear = false;
        }
        if (mb_strlen($a) > $ctx['max_chars']) {
            $a = mb_substr($a, 0, $ctx['max_chars']);
        }
        $json = json_encode_u(['answer' => $a]);

        return [
            'ok' => true,
            'answer' => $a,
            'emotion' => $emotion,
            'on_topic' => true,
            'unsafe' => false,
            'unclear' => $unclear,
            'input_tokens' => (int) ceil($promptChars / 1.5),
            'output_tokens' => (int) ceil(mb_strlen($json) / 1.5),
            'error' => null,
        ];
    }

    // ───────────────────────── 한국어 ─────────────────────────

    /** 받침에 맞는 조사를 붙인다. josa('엄마', '이', '가') → '엄마가'. 끝의 문장 부호, 괄호는 건너뛰고 본다. */
    public static function josa(string $word, string $withBatchim, string $without): string
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $code = mb_ord($chars[$i], 'UTF-8');
            if ($code >= 0xAC00 && $code <= 0xD7A3) {
                return $word . ((($code - 0xAC00) % 28) > 0 ? $withBatchim : $without);
            }
            if (preg_match('/[A-Za-z0-9]/', $chars[$i])) {
                break;
            }
        }

        return $word . $without;
    }
}
