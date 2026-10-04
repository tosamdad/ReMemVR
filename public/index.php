<?php
/**
 * 웹 요청 진입점. 실제 파일이 아닌 모든 요청이 .htaccess 의 재작성 규칙으로 이곳에 온다.
 * 로컬 개발: php -S localhost:8000 -t public public/index.php
 */
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && substr($file, -4) !== '.php') {
        return false;
    }
    if (preg_match('#^/_ops/(migrate|backup|worker)\.php$#', (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH), $m)) {
        require __DIR__ . '/_ops/' . $m[1] . '.php';
        return true;
    }
}

define('PUBLIC_ROOT', __DIR__);
require __DIR__ . '/_ops/bootstrap.php';

App\Core\App::run();
