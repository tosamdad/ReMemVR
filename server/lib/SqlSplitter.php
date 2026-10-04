<?php
/**
 * SQL 파일 내용을 개별 문장으로 나눈다.
 * 따옴표('), 큰따옴표("), 백틱(`) 안의 세미콜론과 주석(--, #, 블록 주석)은 구분자로 보지 않는다.
 * DELIMITER 구문은 지원하지 않으므로 마이그레이션에서 프로시저나 트리거는 쓰지 않는다.
 */
final class SqlSplitter
{
    /**
     * @return string[]
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // 한 줄 주석: "-- " 또는 "#"
            if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2]))) || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end + 1;
                $buffer .= "\n";
                continue;
            }

            // 블록 주석
            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $length : $end + 2;
                $buffer .= ' ';
                continue;
            }

            // 따옴표로 감싼 문자열이나 식별자
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                $i++;
                while ($i < $length) {
                    $c = $sql[$i];
                    $buffer .= $c;
                    if ($c === '\\' && $quote !== '`' && $i + 1 < $length) {
                        $buffer .= $sql[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === $quote) {
                        // 같은 따옴표 두 번은 이스케이프된 따옴표이다.
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $buffer .= $quote;
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            if ($char === ';') {
                self::push($statements, $buffer);
                $buffer = '';
                $i++;
                continue;
            }

            $buffer .= $char;
            $i++;
        }

        self::push($statements, $buffer);

        return $statements;
    }

    private static function push(array &$statements, string $buffer): void
    {
        $trimmed = trim($buffer);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }
    }
}
