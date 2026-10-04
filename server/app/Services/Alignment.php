<?php
namespace App\Services;

use App\Core\Text;

/**
 * 동화 오디오의 문장, 낱말 타이밍(sentence_timings) 만들기.
 * 형식: {"v":1,"duration":ms,"sentences":[{"seq","start","end","words":[[start,end],...]}]}
 * words 는 Text::words(문장) 순서와 같다. 시각은 밀리초 정수.
 *
 * 문장 구간은 이어 붙인다: 첫 문장은 0 에서 시작하고, 각 문장의 end 는 다음 문장의 start, 마지막 문장의 end 는 전체 길이다.
 * 그래서 재생 위치가 문장 사이 쉼에 있어도 항상 한 문장에 속한다.
 */
class Alignment
{
    /** 합성할 전체 본문. 문장 순서(seq)대로 앞뒤 공백을 지우고 $joiner 로 잇는다. */
    public static function joinText(array $sentences, string $joiner = ' '): string
    {
        $parts = [];
        foreach (self::ordered($sentences) as $s) {
            $parts[] = $s['content'];
        }

        return implode($joiner, $parts);
    }

    /**
     * ElevenLabs 글자 정렬 정보로 타이밍을 만든다.
     * $alignment: ['chars' => string[], 'starts' => float[](초), 'ends' => float[](초)]
     * 우리 본문과 돌려받은 글자 배열의 공백, 정규화 차이에 흔들리지 않도록 공백이 아닌 글자끼리 차례로 맞춘다.
     * 맞지 않는 글자는 앞뒤 맞은 글자 사이 시각으로 채운다. 정렬 정보가 비어 있으면 estimate() 를 쓴다.
     */
    public static function build(array $sentences, array $alignment, int $durationMs, string $joiner = ' '): array
    {
        $aChars = isset($alignment['chars']) && is_array($alignment['chars']) ? array_values($alignment['chars']) : [];
        $aStarts = isset($alignment['starts']) && is_array($alignment['starts']) ? array_values($alignment['starts']) : [];
        $aEnds = isset($alignment['ends']) && is_array($alignment['ends']) ? array_values($alignment['ends']) : [];

        // 정렬 정보의 공백 아닌 글자(시각은 ms)
        $A = [];
        $AS = [];
        $AE = [];
        $count = min(count($aChars), count($aStarts), count($aEnds));
        $lastEnd = 0;
        for ($i = 0; $i < $count; $i++) {
            $st = (int) round((float) $aStarts[$i] * 1000);
            $en = (int) round((float) $aEnds[$i] * 1000);
            $lastEnd = max($lastEnd, $en);
            // 한 칸에 여러 글자가 들어 있을 수도 있어 글자 단위로 펼친다.
            foreach (preg_split('//u', (string) $aChars[$i], -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                if (self::isSpace($ch)) {
                    continue;
                }
                $A[] = self::norm($ch);
                $AS[] = $st;
                $AE[] = max($st, $en);
            }
        }
        $ordered = self::ordered($sentences);
        if (!$A || !$ordered) {
            return self::estimate($sentences, max($durationMs, $lastEnd), $joiner);
        }
        $duration = max($durationMs, $lastEnd);

        // 우리 본문의 공백 아닌 글자 목록. owner = [문장 번호, 낱말 번호] 또는 null(이음 문자)
        $S = [];
        $owner = [];
        $joinChars = [];
        foreach (preg_split('//u', $joiner, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            if (!self::isSpace($ch)) {
                $joinChars[] = self::norm($ch);
            }
        }
        foreach ($ordered as $si => $s) {
            if ($si > 0) {
                foreach ($joinChars as $ch) {
                    $S[] = $ch;
                    $owner[] = null;
                }
            }
            foreach (Text::words($s['content']) as $wi => $word) {
                foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                    $S[] = self::norm($ch);
                    $owner[] = [$si, $wi];
                }
            }
        }

        $map = self::matchChars($S, $A);

        // 글자별 시각. 맞지 않은 글자는 비워 두었다가 앞뒤 사이로 채운다.
        $n = count($S);
        $cs = array_fill(0, $n, null);
        $ce = array_fill(0, $n, null);
        foreach ($map as $i => $q) {
            if ($q !== null) {
                $cs[$i] = $AS[$q];
                $ce[$i] = $AE[$q];
            }
        }
        self::fillGaps($cs, $ce, $duration);

        // 시각이 거꾸로 가지 않게 정리
        $prev = 0;
        for ($i = 0; $i < $n; $i++) {
            $cs[$i] = min($duration, max($prev, (int) $cs[$i]));
            $ce[$i] = min($duration, max($cs[$i], (int) $ce[$i]));
            $prev = $cs[$i];
        }

        // 낱말, 문장으로 모은다.
        $words = [];
        for ($i = 0; $i < $n; $i++) {
            if ($owner[$i] === null) {
                continue;
            }
            list($si, $wi) = $owner[$i];
            if (!isset($words[$si][$wi])) {
                $words[$si][$wi] = [$cs[$i], $ce[$i]];
            } else {
                $words[$si][$wi][1] = max($words[$si][$wi][1], $ce[$i]);
            }
        }

        return self::assemble($ordered, $words, $duration);
    }

    /** 정렬 정보가 없을 때: 글자 수에 비례해 시각을 나눈다. */
    public static function estimate(array $sentences, int $durationMs, string $joiner = ' '): array
    {
        $ordered = self::ordered($sentences);
        $duration = max(0, $durationMs);
        $joinLen = mb_strlen($joiner);
        $total = 0;
        foreach ($ordered as $si => $s) {
            $total += ($si > 0 ? $joinLen : 0) + mb_strlen($s['content']);
        }
        $msPerChar = $total > 0 ? $duration / $total : 0;

        $words = [];
        $offset = 0;
        foreach ($ordered as $si => $s) {
            if ($si > 0) {
                $offset += $joinLen;
            }
            $content = $s['content'];
            // Text::words 와 같은 낱말 목록(공백 아닌 글자 덩어리)과 바이트 위치
            preg_match_all('/\S+/u', $content, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as $wi => $hit) {
                $pos = mb_strlen(substr($content, 0, $hit[1]));
                $len = mb_strlen($hit[0]);
                $words[$si][$wi] = [
                    (int) round(($offset + $pos) * $msPerChar),
                    (int) round(($offset + $pos + $len) * $msPerChar),
                ];
            }
            $offset += mb_strlen($content);
        }

        return self::assemble($ordered, $words, $duration);
    }

    /** 재생 위치(ms)가 속한 문장 seq. 타이밍이 없으면 null */
    public static function sentenceAt(array $timings, int $positionMs): ?int
    {
        $found = null;
        foreach (isset($timings['sentences']) ? $timings['sentences'] : [] as $s) {
            if ($found === null || $positionMs >= (int) $s['start']) {
                $found = (int) $s['seq'];
            }
            if ($positionMs < (int) $s['end']) {
                break;
            }
        }

        return $found;
    }

    // ───────────────────────── 내부 ─────────────────────────

    /** 빈 문장을 빼고 seq 순서로 정렬한 [['seq', 'content']] */
    private static function ordered(array $sentences): array
    {
        $list = [];
        foreach (array_values($sentences) as $i => $s) {
            $content = trim((string) (isset($s['content']) ? $s['content'] : ''));
            if ($content === '') {
                continue;
            }
            $list[] = ['seq' => isset($s['seq']) ? (int) $s['seq'] : $i + 1, 'content' => $content, '_i' => $i];
        }
        usort($list, static function ($a, $b) {
            return $a['seq'] === $b['seq'] ? $a['_i'] - $b['_i'] : $a['seq'] - $b['seq'];
        });

        return $list;
    }

    /**
     * 우리 글자 S 와 정렬 글자 A 를 앞에서부터 맞춘다. 결과: S 위치 → A 위치(없으면 null)
     * 같은 글자면 그대로 짝, 다르면 가까운 앞쪽에서 다시 맞는 곳을 찾는다(빠진 글자, 덧붙은 글자, 바뀐 글자 처리).
     */
    private static function matchChars(array $S, array $A): array
    {
        $n = count($S);
        $m = count($A);
        $map = array_fill(0, $n, null);
        $p = 0;
        $window = 8;
        for ($i = 0; $i < $n && $p < $m; $i++) {
            if ($S[$i] === $A[$p]) {
                $map[$i] = $p;
                $p++;
                continue;
            }
            // 1) 정렬 쪽에 글자가 더 있다: 가까운 앞에서 이 글자와 다음 글자가 함께 맞는 곳
            $jump = null;
            for ($q = $p + 1; $q < min($m, $p + 1 + $window); $q++) {
                if ($A[$q] === $S[$i] && ($i + 1 >= $n || $q + 1 >= $m || $A[$q + 1] === $S[$i + 1])) {
                    $jump = $q;
                    break;
                }
            }
            // 2) 우리 쪽에 글자가 더 있다: 지금 정렬 글자가 우리 다음 글자들 중에 있으면 이 글자는 건너뛴다.
            $skip = false;
            for ($k = 1; $k <= $window && $i + $k < $n; $k++) {
                if ($S[$i + $k] === $A[$p] && ($p + 1 >= $m || $i + $k + 1 >= $n || $S[$i + $k + 1] === $A[$p + 1])) {
                    $skip = true;
                    break;
                }
            }
            if ($jump !== null && (!$skip || $jump - $p <= 2)) {
                $map[$i] = $jump;
                $p = $jump + 1;
            } elseif ($skip) {
                continue;
            } else {
                // 3) 바뀐 글자(정규화 등): 서로 한 글자씩 짝지어 넘어간다.
                $map[$i] = $p;
                $p++;
            }
        }

        return $map;
    }

    /** 비어 있는 글자 시각을 앞뒤 맞은 글자 사이로 고르게 채운다. */
    private static function fillGaps(array &$cs, array &$ce, int $duration): void
    {
        $n = count($cs);
        $i = 0;
        while ($i < $n) {
            if ($cs[$i] !== null) {
                $i++;
                continue;
            }
            $j = $i;
            while ($j < $n && $cs[$j] === null) {
                $j++;
            }
            $from = $i > 0 ? (int) $ce[$i - 1] : 0;
            $to = $j < $n ? (int) $cs[$j] : $duration;
            if ($to < $from) {
                $to = $from;
            }
            $span = ($to - $from) / ($j - $i);
            for ($k = $i; $k < $j; $k++) {
                $cs[$k] = (int) round($from + ($k - $i) * $span);
                $ce[$k] = (int) round($from + ($k - $i + 1) * $span);
            }
            $i = $j;
        }
    }

    /** 낱말 시각으로 문장 구간을 만들고 결과 구조로 묶는다. */
    private static function assemble(array $ordered, array $words, int $duration): array
    {
        $out = [];
        $count = count($ordered);
        $starts = [];
        foreach ($ordered as $si => $s) {
            $first = isset($words[$si]) && $words[$si] ? reset($words[$si]) : null;
            $starts[$si] = $first ? (int) $first[0] : null;
        }
        // 낱말이 없는 문장은 앞 문장 시작을 따른다.
        $prev = 0;
        foreach ($ordered as $si => $s) {
            if ($starts[$si] === null || $starts[$si] < $prev) {
                $starts[$si] = $prev;
            }
            $prev = $starts[$si];
        }
        foreach ($ordered as $si => $s) {
            $start = $si === 0 ? 0 : $starts[$si];
            $end = $si + 1 < $count ? $starts[$si + 1] : $duration;
            $end = max($start, $end);
            $list = [];
            if (isset($words[$si])) {
                ksort($words[$si]);
                foreach ($words[$si] as $w) {
                    $list[] = [(int) $w[0], (int) $w[1]];
                }
            }
            $out[] = ['seq' => $s['seq'], 'start' => (int) $start, 'end' => (int) $end, 'words' => $list];
        }

        return ['v' => 1, 'duration' => $duration, 'sentences' => $out];
    }

    private static function isSpace(string $ch): bool
    {
        return (bool) preg_match('/^[\s\x{00A0}\x{200B}\x{3000}\x{FEFF}]$/u', $ch);
    }

    /** 비교용 글자 정리: 여러 모양의 따옴표, 말줄임표를 같은 글자로 본다. */
    private static function norm(string $ch): string
    {
        static $map = [
            '“' => '"', '”' => '"', '„' => '"', '‟' => '"', '«' => '"', '»' => '"',
            '‘' => "'", '’' => "'", '‚' => "'", '‛' => "'", '`' => "'", '´' => "'",
            '…' => '.', '。' => '.', '！' => '!', '？' => '?', '，' => ',', '～' => '~',
        ];
        if (isset($map[$ch])) {
            return $map[$ch];
        }

        return mb_strtolower($ch);
    }
}
