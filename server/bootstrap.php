<?php
/**
 * 서버 공통 진입점. 설정을 읽고 라이브러리를 불러온다.
 * 이 폴더(server/)는 cafe24 에서 웹 루트(www) 밖에 올라가므로 브라우저로 직접 열리지 않는다.
 * PHP 7.4 이상에서 동작하도록 작성한다(실서버는 PHP 8.4).
 */
define('APP_ROOT', __DIR__);

require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/SqlSplitter.php';
require_once __DIR__ . '/lib/Migrator.php';
require_once __DIR__ . '/lib/Backup.php';

// App\ 네임스페이스 클래스는 server/app/ 아래 같은 경로에서 불러온다. 예) App\Core\Router → app/Core/Router.php
spl_autoload_register(static function ($class) {
    if (strncmp($class, 'App\\', 4) !== 0) {
        return;
    }
    $file = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('Asia/Seoul');

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        // 로컬 개발에서 여러 설정을 바꿔 쓸 때는 환경 변수 REMEMVR_CONFIG 로 설정 파일 경로를 지정한다.
        $env = getenv('REMEMVR_CONFIG');
        $file = is_string($env) && $env !== '' ? $env : APP_ROOT . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('server/config.php 가 없다. 배포 워크플로가 만들거나, 로컬에서는 config.example.php 를 복사한다.');
        }
        $config = require $file;
    }

    return $config;
}

/**
 * 운영용 엔드포인트(_ops) 접근을 토큰으로 막는다.
 * 토큰은 X-Ops-Token 헤더나 POST 의 token 필드로 받는다. 틀리면 존재 여부를 숨기기 위해 404 를 돌려준다.
 */
function ops_require_token(): void
{
    if (!ops_token_valid()) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }
}

function ops_token_valid(): bool
{
    $config = app_config();
    $expected = isset($config['ops_token']) ? (string) $config['ops_token'] : '';

    $given = '';
    if (isset($_SERVER['HTTP_X_OPS_TOKEN'])) {
        $given = (string) $_SERVER['HTTP_X_OPS_TOKEN'];
    } elseif (isset($_POST['token'])) {
        $given = (string) $_POST['token'];
    }

    $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '';

    return $method === 'POST' && strlen($expected) >= 32 && $given !== '' && hash_equals($expected, $given);
}

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

require_once __DIR__ . '/app/Core/helpers.php';
