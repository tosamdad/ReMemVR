<?php
/**
 * 관리자 화면 공통 레이아웃(Kindred Audio). 왼쪽 메뉴 + 상단 상태 바.
 * 템플릿에서 layout('admin/layout', ['title' => '대시보드', 'active' => 'dashboard']) 로 쓴다.
 * active: dashboard | voices | stories | members | notices | inquiries | settings | admins | audit
 * section('head'), section('scripts') 로 페이지별 요소를 넣는다.
 */
use App\Core\AdminAuth;

$admin = AdminAuth::admin();
$title = isset($title) ? $title : '';
$active = isset($active) ? $active : '';
$counts = ['voices' => 0, 'inquiries' => 0];
$todayCost = 0.0;
try {
    $counts['voices'] = (int) db_value("SELECT COUNT(*) FROM voice_profiles WHERE status = 'pending' AND deleted_at IS NULL");
    $counts['inquiries'] = (int) db_value("SELECT COUNT(*) FROM inquiries WHERE status = 'open'");
    $todayCost = (float) db_value('SELECT COALESCE(SUM(cost_krw), 0) FROM api_usage_logs WHERE created_at >= CURDATE()');
} catch (Throwable $e) {
    app_log('error', '관리자 레이아웃 집계 실패: ' . $e->getMessage());
}
// 아이 질문 기능이 보류된 동안(qa_available() 거짓) 실서버에 필요한 외부 API 는 ElevenLabs 하나다.
$elOk = provider_ready('elevenlabs');
if (config('providers_fake')) {
    $statusText = '개발 모드 (가짜 AI 응답)';
    $statusDot = 'bg-secondary-container';
} elseif ($elOk) {
    $statusText = '정상 가동 중 (ElevenLabs 연동)';
    $statusDot = 'bg-emerald-500';
} else {
    $statusText = 'ElevenLabs API 키 미등록';
    $statusDot = 'bg-error';
}
$menu = [
    ['dashboard', '/admin/dashboard', 'space_dashboard', '대시보드', 0],
    ['voices', '/admin/voices', 'mic', '목소리 생성 관리', $counts['voices']],
    ['stories', '/admin/stories', 'auto_stories', '동화 콘텐츠 관리', 0],
    ['members', '/admin/members', 'monitoring', '회원 및 통계', 0],
];
$ops = [
    ['notices', '/admin/notices', 'campaign', '공지사항, FAQ', 0],
    ['inquiries', '/admin/inquiries', 'support_agent', '1:1 문의', $counts['inquiries']],
    ['settings', '/admin/settings', 'tune', '운영 설정', 0],
    ['admins', '/admin/admins', 'manage_accounts', '관리자 계정', 0],
    ['audit', '/admin/audit', 'policy', '감사 로그', 0],
];
$roleLabel = $admin && $admin['role'] === 'super' ? 'Super Admin' : 'Admin';
$navLink = static function (array $item, string $active): string {
    $on = $item[0] === $active;
    $cls = $on ? 'bg-primary-container text-on-primary-container' : 'text-on-surface-variant hover:bg-surface-container-high hover:text-on-surface';
    $badge = $item[4] > 0 ? '<span class="rounded-full bg-secondary-container px-2 py-0.5 font-label-sm text-label-sm text-on-secondary-container">' . (int) $item[4] . '</span>' : '';

    return '<a class="flex items-center justify-between rounded-xl px-4 py-3 transition-all ' . $cls . '" href="' . e(url($item[1])) . '"' . ($on ? ' aria-current="page"' : '') . '>'
        . '<div class="flex items-center gap-3"><span class="material-symbols-outlined text-[20px]">' . e($item[2]) . '</span><span class="font-label-md text-label-md">' . e($item[3]) . '</span></div>' . $badge . '</a>';
};
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="robots" content="noindex, nofollow">
<title><?= e(($title !== '' ? $title . ' · ' : '') . 'ReMemVR Admin') ?></title>
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700&family=Plus+Jakarta+Sans:wght@500;600;700&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=block" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css" rel="stylesheet">
<link href="<?= e(asset('css/admin.css')) ?>" rel="stylesheet">
<script>window.RM_CONFIG = <?= json_encode_u(['base' => base_path(), 'csrf' => csrf_token(), 'admin' => true]) ?>;</script>
<?= yield_section('head') ?>
</head>
<body class="bg-surface font-body-md text-on-surface antialiased">
<div id="admin-backdrop" class="fixed inset-0 z-40 hidden bg-black/30 lg:hidden" data-admin-menu-close></div>
<aside id="admin-sidebar" class="fixed left-0 top-0 z-50 flex h-full w-72 -translate-x-full flex-col justify-between overflow-y-auto bg-surface-container-low px-4 py-6 shadow-bar transition-transform lg:translate-x-0">
  <div class="flex flex-col gap-6">
    <a href="<?= e(url('/admin/dashboard')) ?>" class="flex items-center gap-3 px-3 py-2">
      <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary text-on-primary shadow-sm"><span class="material-symbols-outlined text-[24px]">graphic_eq</span></div>
      <div class="flex flex-col"><span class="font-headline-md text-headline-md tracking-tight text-on-surface">ReMemVR</span><span class="font-label-sm text-label-sm uppercase text-primary">Admin Backoffice</span></div>
    </a>
    <div class="px-3"><div class="h-[1px] w-full bg-surface-variant"></div></div>
    <nav class="flex flex-col gap-1">
      <?php foreach ($menu as $item) { echo $navLink($item, $active); } ?>
    </nav>
    <div class="px-3"><span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant/70">운영</span></div>
    <nav class="-mt-4 flex flex-col gap-1">
      <?php foreach ($ops as $item) { echo $navLink($item, $active); } ?>
    </nav>
  </div>
  <div class="mt-6 flex flex-col gap-2 rounded-xl bg-surface-container-lowest p-3 shadow-bar">
    <div class="flex items-center gap-2"><span class="h-2 w-2 animate-pulse rounded-full bg-primary"></span><span class="font-label-sm text-label-sm text-on-surface-variant">v<?= e(setting('app.version', '1.0.0')) ?> Core Engine</span></div>
    <span class="font-label-sm text-label-sm text-on-surface-variant">© <?= date('Y') ?> ReMemVR</span>
  </div>
</aside>
<div class="lg:pl-72">
  <header class="fixed left-0 right-0 top-0 z-30 h-16 bg-surface/85 shadow-bar backdrop-blur-xl lg:left-72">
    <div class="flex h-16 w-full items-center justify-between gap-3 px-4 lg:px-8">
      <div class="flex min-w-0 items-center gap-3 lg:gap-6">
        <button type="button" class="flex h-10 w-10 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high lg:hidden" data-admin-menu-open aria-label="메뉴"><span class="material-symbols-outlined">menu</span></button>
        <div class="hidden items-center gap-2.5 rounded-full bg-surface-container-low px-3 py-1.5 sm:flex"><span class="h-2 w-2 rounded-full <?= $statusDot ?>"></span><span class="truncate font-label-sm text-label-sm text-on-surface"><?= e($statusText) ?></span></div>
        <div class="hidden items-center gap-2 rounded-full bg-surface-container-low px-3 py-1.5 md:flex"><span class="material-symbols-outlined text-[18px] text-secondary">monetization_on</span><span class="font-label-sm text-label-sm text-on-surface-variant">금일 예상 API 비용:</span><span class="font-label-md text-label-md font-bold text-secondary"><?= e(fmt_krw($todayCost)) ?></span></div>
      </div>
      <div class="flex items-center gap-3 lg:gap-4">
        <a href="<?= e(url('/admin/inquiries')) ?>" class="relative flex h-10 w-10 items-center justify-center rounded-full text-on-surface-variant transition-colors hover:bg-surface-container-high" aria-label="알림"><span class="material-symbols-outlined text-[20px]">notifications</span><?php if ($counts['voices'] + $counts['inquiries'] > 0): ?><span class="absolute right-2.5 top-2.5 h-2 w-2 rounded-full bg-error"></span><?php endif; ?></a>
        <div class="h-6 w-[1px] bg-surface-variant"></div>
        <div class="flex items-center gap-3">
          <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary"><span class="material-symbols-outlined text-[18px] text-on-primary">person</span></div>
          <div class="hidden flex-col text-left sm:flex"><span class="font-label-md text-label-md leading-tight text-on-surface"><?= e($admin ? $admin['name'] : '') ?></span><span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($roleLabel) ?></span></div>
        </div>
        <form method="post" action="<?= e(url('/admin/logout')) ?>">
          <?= csrf_field() ?>
          <button type="submit" class="ml-1 flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-on-surface-variant transition-colors hover:bg-error-container hover:text-on-error-container"><span class="material-symbols-outlined text-[18px]">logout</span><span class="hidden font-label-sm text-label-sm sm:inline">로그아웃</span></button>
        </form>
      </div>
    </div>
  </header>
  <?= partial('admin/partials/flash') ?>
  <main class="min-h-screen w-full bg-surface px-4 pb-10 pt-24 lg:px-8">
    <?= $content ?>
  </main>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<script src="<?= e(asset('js/admin.js')) ?>"></script>
<?= yield_section('scripts') ?>
</body>
</html>
