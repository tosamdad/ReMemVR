<?php
/** 관리자 로그인, 최초 설정 화면 레이아웃(왼쪽 메뉴 없음). 변수: title */
$title = isset($title) ? $title : '';
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title !== '' ? $title . ' · ' : '') . 'ReMemVR Admin') ?></title>
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=block" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css" rel="stylesheet">
<link href="<?= e(asset('css/admin.css')) ?>" rel="stylesheet">
<script>window.RM_CONFIG = <?= json_encode_u(['base' => base_path(), 'csrf' => csrf_token(), 'admin' => true]) ?>;</script>
</head>
<body class="min-h-screen bg-surface-container-low font-body-md text-on-surface antialiased">
<?= partial('admin/partials/flash') ?>
<main class="flex min-h-screen items-center justify-center px-4 py-12">
  <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= yield_section('scripts') ?>
</body>
</html>
