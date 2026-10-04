<?php
/** 1회성 알림(flash). 레이아웃이 상단에 띄우고 app.js 가 몇 초 뒤 닫는다. */
$messages = flash_messages();
if (!$messages) {
    return;
}
$styles = [
    'success' => ['bg-secondary-container text-on-secondary-container', 'check_circle'],
    'error' => ['bg-error-container text-on-error-container', 'error'],
    'info' => ['bg-primary-fixed text-on-primary-fixed', 'info'],
];
?>
<div class="pointer-events-none fixed inset-x-0 top-3 z-[60] mx-auto flex max-w-[520px] flex-col gap-2 px-margin-mobile" data-flash>
  <?php foreach ($messages as $m): $s = isset($styles[$m['type']]) ? $styles[$m['type']] : $styles['info']; ?>
  <div class="pointer-events-auto flex items-start gap-2 rounded-xl px-4 py-3 shadow-lg <?= $s[0] ?>" role="status">
    <span class="material-symbols-outlined text-[20px]"><?= $s[1] ?></span>
    <p class="flex-1 text-label-lg font-label-lg"><?= e($m['message']) ?></p>
  </div>
  <?php endforeach; ?>
</div>
