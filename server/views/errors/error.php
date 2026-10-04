<?php
/** 오류 화면. 관리자 영역이면 관리자 스타일, 아니면 회원 스타일 */
if (!empty($isAdmin)) {
    layout('admin/layout_auth', ['title' => '오류']);
} else {
    layout('user/layout', ['title' => '오류', 'showNav' => false, 'header' => 'none', 'mainClass' => 'px-margin-mobile pt-20 pb-12']);
}
$icons = [403 => 'lock', 404 => 'explore_off', 419 => 'schedule', 429 => 'hourglass_top', 500 => 'cloud_off'];
$icon = isset($icons[$status]) ? $icons[$status] : 'error';
?>
<div class="mx-auto flex max-w-md flex-col items-center gap-4 text-center">
  <div class="flex h-20 w-20 items-center justify-center rounded-full <?= !empty($isAdmin) ? 'bg-primary-fixed text-primary' : 'bg-primary-container text-on-primary-container' ?>">
    <span class="material-symbols-outlined text-[40px]"><?= e($icon) ?></span>
  </div>
  <p class="font-label-sm text-label-sm text-on-surface-variant">오류 <?= (int) $status ?></p>
  <h1 class="font-headline-md text-headline-md text-on-surface"><?= e($message) ?></h1>
  <div class="mt-2 flex gap-2">
    <button type="button" onclick="history.back()" class="<?= !empty($isAdmin) ? 'a-btn-tonal' : 'btn-ghost' ?>">이전으로</button>
    <a href="<?= e(url(!empty($isAdmin) ? '/admin' : '/')) ?>" class="<?= !empty($isAdmin) ? 'a-btn-primary' : 'btn-primary' ?>">처음으로</a>
  </div>
</div>
