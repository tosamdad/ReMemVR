<?php
namespace App\Core;

/** 동화 본문 처리(문장 나누기, 단어 나누기, 내용 해시) */
class Text
{
    /**
     * 본문을 문장 단위로 나눈다. 마침표, 물음표, 느낌표, 말줄임표 뒤(닫는 따옴표 포함)와 줄바꿈에서 자른다.
     * 따옴표 안의 대사는 닫는 따옴표까지 한 문장으로 둔다.
     */
    public static function splitSentences(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $out = [];
        foreach (preg_split('/\n+/u', $body) as $para) {
            $para = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $para));
            if ($para === '') {
                continue;
            }
            $parts = self::splitKeepingQuotes($para);
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $out[] = $p;
                }
            }
        }

        return $out;
    }

    private static function splitKeepingQuotes(string $para): array
    {
        $chars = preg_split('//u', $para, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($chars);
        $parts = [];
        $buf = '';
        $inQuote = false;
        $enders = ['.', '!', '?', '…', '。', '？', '！', '~'];
        $closers = ['"', '”', '’', '\'', '」', '』', ')'];
        for ($i = 0; $i < $n; $i++) {
            $c = $chars[$i];
            $buf .= $c;
            if ($c === '“' || $c === '「' || $c === '『') {
                $inQuote = true;
            } elseif ($c === '”' || $c === '」' || $c === '』') {
                $inQuote = false;
            } elseif ($c === '"') {
                $inQuote = !$inQuote;
            }
            if (in_array($c, $enders, true)) {
                // 이어지는 마침 기호와 닫는 따옴표를 붙인다.
                while ($i + 1 < $n && (in_array($chars[$i + 1], $enders, true) || in_array($chars[$i + 1], $closers, true))) {
                    $i++;
                    $buf .= $chars[$i];
                    if ($chars[$i] === '"' || $chars[$i] === '”' || $chars[$i] === '」' || $chars[$i] === '』') {
                        $inQuote = false;
                    }
                }
                if (!$inQuote && ($i + 1 >= $n || preg_match('/\s/u', $chars[$i + 1])) && !self::continuesQuote($chars, $i)) {
                    $parts[] = $buf;
                    $buf = '';
                }
            }
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }

    /** 닫는 따옴표 뒤에 "하고", "라고" 처럼 문장이 이어지면 자르지 않는다. */
    private static function continuesQuote(array $chars, int $i): bool
    {
        if (!in_array($chars[$i], ['"', '”', '’', '\'', '」', '』'], true)) {
            return false;
        }
        $next = implode('', array_slice($chars, $i + 1, 6));

        return (bool) preg_match('/^\s*(하고|라고|이라고|하며|하면서|하자|하니)/u', $next);
    }

    /** 공백 기준 단어 목록 */
    public static function words(string $sentence): array
    {
        return preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
    }

    /** 문장 목록의 내용 해시. 오디오가 현재 본문으로 만들어졌는지 비교할 때 쓴다. */
    public static function hashSentences(array $sentences): string
    {
        return hash('sha256', implode("\n", array_map('trim', $sentences)));
    }

    /** 키워드 문자열("밤하늘, 달님") → 배열 */
    public static function keywords(?string $keywords): array
    {
        $list = preg_split('/[,#\s]+/u', (string) $keywords, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map('trim', $list)));
    }

    /** 글자 수(공백 포함). TTS 비용 계산용 */
    public static function charCount(string $text): int
    {
        return mb_strlen($text);
    }
}
