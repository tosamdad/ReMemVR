<?php
/**
 * 회원 화면 공통 레이아웃(Luminous Storyteller).
 * 템플릿에서 layout('user/layout', [...]) 로 쓴다. 변수:
 *   title       문서 제목
 *   nav         하단 탭 강조: home | voice | player | report (없으면 강조 없음)
 *   showNav     하단 탭 표시 여부(기본 true)
 *   header      main(기본, 아바타+로고+설정) | sub(뒤로 가기+제목) | none
 *   back        header=sub 일 때 뒤로 갈 주소(기본: 브라우저 뒤로 가기)
 *   headerTitle header=sub 일 때 제목
 *   headerAction header=sub 오른쪽에 넣을 HTML(선택)
 *   mainClass   main 요소에 더할 클래스
 * section('head'), section('scripts') 로 페이지별 head 요소와 스크립트를 넣는다.
 */
use App\Core\Auth;

$user = Auth::user();
$prefs = Auth::prefs();
$child = $user ? Auth::child() : null;
$title = isset($title) ? $title : '';
$nav = isset($nav) ? $nav : null;
$showNav = isset($showNav) ? (bool) $showNav : true;
$header = isset($header) ? $header : 'main';
$brand = setting('app.brand', '르멤버');
$dark = !empty($prefs['dark_mode']);
$tabs = [
    'home' => ['/home', 'home', '홈'],
    'voice' => ['/voice-lab', 'mic', '목소리 연구실'],
    'player' => ['/player', 'auto_stories', '플레이어'],
    'report' => ['/report', 'bar_chart', '리포트'],
];
?><!DOCTYPE html>
<html lang="ko" class="<?= $dark ? 'dark' : 'light' ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="<?= $dark ? '#0b1d25' : '#f3faff' ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title !== '' ? $title . ' · ' . $brand : $brand) ?></title>
<link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/icon-192.png')) ?>">
<link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&family=Nunito+Sans:wght@400;600;700&family=Gowun+Dodum&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=block" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/gh/orioncactus/pretendard@v1.3.9/dist/web/variable/pretendardvariable-dynamic-subset.min.css" rel="stylesheet">
<link href="<?= e(asset('css/user.css')) ?>" rel="stylesheet">
<script>
  window.RM_CONFIG = <?= json_encode_u(['base' => base_path(), 'csrf' => csrf_token(), 'loggedIn' => (bool) $user, 'prefs' => $prefs]) ?>;
  <?php if (!$user): ?>try { if (localStorage.getItem('rm-dark') === '1') { document.documentElement.classList.replace('light', 'dark'); } } catch (e) {}<?php endif; ?>
</script>
<?= yield_section('head') ?>
</head>
<body class="bg-background font-body-md text-on-background min-h-screen antialiased<?= $showNav ? ' pb-32' : '' ?>">
<div class="mx-auto min-h-screen w-full max-w-[520px] bg-background sm:shadow-[0_0_40px_rgba(0,0,0,0.04)]">
<?php if ($header === 'main'): ?>
<header class="sticky top-0 z-50 flex items-center justify-between bg-surface/95 px-margin-mobile py-4 shadow-sm backdrop-blur-md transition-all duration-200">
  <a href="<?= e(url($user ? '/home' : '/')) ?>" class="flex items-center gap-3">
    <?php if ($child): ?>
      <span class="overflow-hidden rounded-full border-2 border-primary-container"><?= child_avatar($child, 'w-10 h-10 text-xl') ?></span>
    <?php else: ?>
      <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-container text-on-primary-container"><span class="material-symbols-outlined icon-fill">auto_stories</span></span>
    <?php endif; ?>
    <span class="font-headline-md text-headline-md font-bold text-primary"><?= e($brand) ?></span>
  </a>
  <?php if ($user): ?>
  <a href="<?= e(url('/settings')) ?>" class="material-symbols-outlined rounded-full p-2 text-primary transition-all duration-200 hover:bg-surface-container-low active:scale-95" aria-label="설정">settings</a>
  <?php endif; ?>
</header>
<?php elseif ($header === 'sub'): ?>
<header class="sticky top-0 z-50 flex items-center gap-2 bg-surface/95 px-2 py-3 shadow-sm backdrop-blur-md">
  <?php if (!empty($back)): ?>
    <a href="<?= e(url($back)) ?>" class="material-symbols-outlined rounded-full p-2 text-on-surface hover:bg-surface-container-low active:scale-95" aria-label="뒤로">arrow_back</a>
  <?php else: ?>
    <button type="button" onclick="history.length > 1 ? history.back() : location.href='<?= e(url('/home')) ?>'" class="material-symbols-outlined rounded-full p-2 text-on-surface hover:bg-surface-container-low active:scale-95" aria-label="뒤로">arrow_back</button>
  <?php endif; ?>
  <h1 class="flex-1 truncate font-headline-md text-[20px] font-bold leading-7 text-on-surface"><?= e(isset($headerTitle) ? $headerTitle : $title) ?></h1>
  <?= isset($headerAction) ? $headerAction : '' ?>
</header>
<?php endif; ?>

<?= partial('user/partials/flash') ?>

<main class="<?= e(isset($mainClass) ? $mainClass : 'px-margin-mobile pt-6 pb-6') ?>">
<?= $content ?>
</main>

<?php if ($showNav && $user): ?>
<nav class="pb-safe fixed bottom-0 left-1/2 z-50 flex w-full max-w-[520px] -translate-x-1/2 items-center justify-around rounded-t-xl bg-surface px-gutter-mobile pt-base shadow-nav" aria-label="주요 메뉴">
  <?php foreach ($tabs as $key => $tab): $active = $nav === $key; ?>
  <a href="<?= e(url($tab[0])) ?>" class="flex flex-col items-center justify-center rounded-xl px-4 py-1 transition-transform duration-300 active:scale-90 <?= $active ? 'bg-primary-container text-on-primary-container' : 'text-on-surface-variant hover:bg-surface-container-high' ?>"<?= $active ? ' aria-current="page"' : '' ?>>
    <span class="material-symbols-outlined<?= $active ? ' icon-fill' : '' ?>"><?= e($tab[1]) ?></span>
    <span class="font-label-sm text-label-sm"><?= e($tab[2]) ?></span>
  </a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
</div>
<script src="<?= e(asset('js/app.js')) ?>"></script>
<?= yield_section('scripts') ?>
</body>
</html>
