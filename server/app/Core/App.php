<?php
namespace App\Core;

/**
 * 웹 요청 처리 순서: 세션 시작(경로가 /admin 이면 관리자 세션) → server/routes/*.php 경로 등록 → 실행.
 * 오류는 요청 종류에 따라 JSON 또는 오류 화면으로 보여 준다.
 */
class App
{
    public static function run(): void
    {
        $path = Request::path();
        $isAdmin = $path === '/admin' || strpos($path, '/admin/') === 0;

        try {
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            Session::start($isAdmin ? 'admin' : 'user');

            $router = new Router();
            $files = glob(APP_ROOT . '/routes/*.php');
            sort($files);
            foreach ($files as $file) {
                (static function ($router, $__file) {
                    require $__file;
                })($router, $file);
            }
            $router->dispatch(Request::method(), $path);
            self::afterResponse();
        } catch (HttpException $e) {
            self::renderError($e->getStatus(), $e->getMessage(), $isAdmin);
        } catch (\Throwable $e) {
            app_log('error', get_class($e) . ': ' . $e->getMessage(), [
                'file' => $e->getFile() . ':' . $e->getLine(),
                'path' => $path,
            ]);
            $message = is_debug() ? $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')' : '';
            self::renderError(500, $message, $isAdmin);
        }
    }

    /**
     * 응답을 보낸 뒤 할 일. 대기 중인 백그라운드 작업이 있으면 작업 처리기를 깨운다(cafe24 에는 cron 이 없으므로).
     * Worker::maybeKick 은 내부에서 호출 간격을 제한하므로 매 요청 불러도 된다.
     */
    private static function afterResponse(): void
    {
        try {
            if (class_exists('App\\Services\\Worker')) {
                // FastCGI/LiteSpeed 환경이면 응답을 먼저 끝내 방문자가 깨우기 호출을 기다리지 않게 한다.
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    litespeed_finish_request();
                }
                \App\Services\Worker::maybeKick();
            }
        } catch (\Throwable $e) {
            app_log('error', '작업 처리기 호출 실패: ' . $e->getMessage());
        }
    }

    public static function renderError(int $status, string $message, bool $isAdmin): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code($status);
        }
        $defaults = [
            403 => '접근 권한이 없습니다.',
            404 => '페이지를 찾을 수 없습니다.',
            405 => '허용되지 않은 요청입니다.',
            419 => '보안 토큰이 만료되었습니다. 새로고침 후 다시 시도해 주세요.',
            429 => '요청이 너무 많습니다. 잠시 후 다시 시도해 주세요.',
            500 => '일시적인 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.',
        ];
        if ($message === '') {
            $message = isset($defaults[$status]) ? $defaults[$status] : '오류가 발생했습니다.';
        }
        if (Request::wantsJson()) {
            json_error($message, $status);

            return;
        }
        try {
            echo View::render('errors/error', ['status' => $status, 'message' => $message, 'isAdmin' => $isAdmin]);
        } catch (\Throwable $e) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $status . ' ' . $message;
        }
    }
}
