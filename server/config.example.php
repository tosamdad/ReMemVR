<?php
/**
 * 로컬 개발용 설정 예시. config.php 로 복사해서 쓴다. config.php 는 저장소에 올리지 않는다.
 * 서버의 config.php 는 배포 워크플로가 GitHub Secrets 값으로 매번 새로 만든다.
 */
return [
    'env' => 'local',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'rememvr',
        'user' => 'rememvr',
        'pass' => 'change-me',
        'charset' => 'utf8mb4',
    ],
    // 32자 이상 임의 문자열
    'ops_token' => 'local-ops-token-change-me-0123456789abcdef',
];
