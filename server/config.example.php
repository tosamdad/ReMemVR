<?php
/**
 * 로컬 개발용 설정 예시. config.php 로 복사해서 쓴다. config.php 는 저장소에 올리지 않는다.
 * 서버의 config.php 는 배포 워크플로가 GitHub Secrets 값으로 매번 새로 만든다.
 */
return [
    'env' => 'local',
    // true 면 오류 화면에 원인을 보여 준다. 실서버에서는 false.
    'debug' => true,
    // 외부에서 접속하는 주소(OAuth 콜백, 메일 링크, 백그라운드 작업 호출에 쓴다)
    'site_url' => 'http://127.0.0.1:8000',
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
    // 업로드, 생성 오디오, 세션, 로그 저장 폴더. 비우면 앱 폴더 상위의 rememvr_data 를 쓴다.
    'storage_dir' => '',
    // 보내는 메일 주소. 비우면 no-reply@사이트도메인
    'mail_from' => '',

    // 외부 API 키. 실서버는 GitHub Secrets(ELEVENLABS_API_KEY, GEMINI_API_KEY 등)로 채워진다.
    'elevenlabs' => ['api_key' => ''],
    'gemini' => ['api_key' => ''],
    'kakao' => ['rest_api_key' => '', 'client_secret' => ''],
    'google' => ['client_id' => '', 'client_secret' => ''],

    // 로컬 개발 전용. true 면 ElevenLabs, Gemini 대신 가짜 응답(삐 소리 음성, 고정 답변)을 쓰고 메일을 보내지 않는다.
    'providers_fake' => false,
];
