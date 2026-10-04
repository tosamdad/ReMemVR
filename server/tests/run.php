<?php
/**
 * 간단한 테스트 실행기. server/tests/ 아래 *Test.php 파일을 모두 실행한다.
 *   php server/tests/run.php            전체
 *   php server/tests/run.php Alignment  파일 이름에 Alignment 가 들어간 것만
 * 테스트 파일 작성법:
 *   test('문장을 나눈다', function () { assert_same(['a.', 'b.'], App\Core\Text::splitSentences('a. b.')); });
 * DB 가 필요한 테스트는 test_db() 로 연결을 얻는다(설정이 없으면 건너뛴다).
 * 이 파일과 테스트는 서버에 올라가도 웹으로 열리지 않는다(server/.htaccess).
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require __DIR__ . '/../bootstrap.php';

$GLOBALS['__tests'] = [];
$GLOBALS['__skips'] = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = [$GLOBALS['__current_file'], $name, $fn];
}

final class TestSkipped extends Exception
{
}

function skip_test(string $why): void
{
    throw new TestSkipped($why);
}

function assert_true($cond, string $message = '조건이 거짓이다'): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function assert_same($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . '기대 ' . var_export($expected, true) . ', 실제 ' . var_export($actual, true));
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (strpos($haystack, $needle) === false) {
        throw new RuntimeException(($message !== '' ? $message . ': ' : '') . "'" . $needle . "' 이(가) 없다");
    }
}

function test_db(): PDO
{
    try {
        return db();
    } catch (Throwable $e) {
        skip_test('DB 연결 없음: ' . $e->getMessage());
    }
}

$filter = isset($argv[1]) ? $argv[1] : '';
$files = glob(__DIR__ . '/*Test.php');
sort($files);
foreach ($files as $file) {
    if ($filter !== '' && strpos(basename($file), $filter) === false) {
        continue;
    }
    $GLOBALS['__current_file'] = basename($file);
    require $file;
}

$pass = 0;
$fail = 0;
foreach ($GLOBALS['__tests'] as $t) {
    list($file, $name, $fn) = $t;
    try {
        $fn();
        $pass++;
        echo "  ok    $file › $name\n";
    } catch (TestSkipped $e) {
        $GLOBALS['__skips']++;
        echo "  skip  $file › $name (" . $e->getMessage() . ")\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL  $file › $name\n        " . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    }
}
echo "\n통과 $pass, 실패 $fail, 건너뜀 {$GLOBALS['__skips']}\n";
exit($fail > 0 ? 1 : 0);
