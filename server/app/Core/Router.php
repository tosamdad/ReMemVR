<?php
namespace App\Core;

/**
 * 간단한 경로 라우터.
 * $router->get('/player/{id:\d+}', [PlayerController::class, 'show']);
 * 핸들러에는 경로 변수가 순서대로 문자열 인자로 전달된다.
 * POST 계열 요청은 기본으로 CSRF 토큰을 검사한다(['csrf' => false] 로 끌 수 있다).
 * 핸들러가 배열을 돌려주면 JSON 으로, 문자열을 돌려주면 HTML 로 출력한다.
 */
class Router
{
    /** @var array<int, array> */
    private $routes = [];

    public function get(string $pattern, $handler, array $opts = []): void
    {
        $this->add(['GET', 'HEAD'], $pattern, $handler, $opts);
    }

    public function post(string $pattern, $handler, array $opts = []): void
    {
        $this->add(['POST'], $pattern, $handler, $opts);
    }

    public function add(array $methods, string $pattern, $handler, array $opts = []): void
    {
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#', static function ($m) {
            $re = isset($m[2]) && $m[2] !== '' ? $m[2] : '[^/]+';

            return '(?P<' . $m[1] . '>' . $re . ')';
        }, rtrim($pattern, '/') === '' ? '/' : rtrim($pattern, '/'));
        $this->routes[] = [
            'methods' => $methods,
            'pattern' => $pattern,
            'regex' => '#^' . $regex . '$#u',
            'handler' => $handler,
            'opts' => $opts,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if (!in_array($method, $route['methods'], true)) {
                $allowed = array_merge($allowed, $route['methods']);
                continue;
            }
            $params = [];
            foreach ($m as $k => $v) {
                if (is_string($k)) {
                    $params[] = $v;
                }
            }
            if ($method !== 'GET' && $method !== 'HEAD' && (!isset($route['opts']['csrf']) || $route['opts']['csrf'] !== false)) {
                $token = isset($_POST['_token']) ? (string) $_POST['_token'] : (string) Request::header('X-CSRF-Token');
                if (!Csrf::verify($token)) {
                    throw new HttpException(419, '보안 토큰이 만료되었습니다. 페이지를 새로고침한 뒤 다시 시도해 주세요.');
                }
            }
            $this->invoke($route['handler'], $params);

            return;
        }
        if ($allowed) {
            throw new HttpException(405, '허용되지 않은 요청 방식입니다.');
        }
        throw new HttpException(404, '페이지를 찾을 수 없습니다.');
    }

    private function invoke($handler, array $params): void
    {
        if (is_array($handler) && is_string($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        $result = call_user_func_array($handler, $params);
        if (is_array($result)) {
            json_response($result);
        } elseif (is_string($result)) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
            }
            echo $result;
        }
    }
}
