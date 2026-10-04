<?php
/**
 * 웹 루트(public/, 서버에서는 www/)에서 앱 폴더(server/)의 bootstrap.php 를 찾는다.
 * 배포 워크플로가 웹 루트에 _app_path.php 를 만들어 앱 폴더의 상대 경로를 알려 준다.
 * 파일이 없으면 저장소 구조(public/ 과 server/ 가 나란히 있음)를 기준으로 한다.
 */
$webRoot = dirname(__DIR__);
$pathFile = $webRoot . '/_app_path.php';
$relative = is_file($pathFile) ? (require $pathFile) : '../server';
$appDir = realpath($webRoot . '/' . $relative);

if ($appDir === false || !is_file($appDir . '/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'App directory not found';
    exit;
}

require $appDir . '/bootstrap.php';
