<?php
/** 관리자 화면 1회성 알림 */
$messages = flash_messages();
if (!$messages) {
    return;
}
$styles = [
    'success' => ['bg-primary-fixed text-on-primary-fixed', 'check_circle'],
    'error' => ['bg-error-container text-on-error-container', 'error'],
    'info' => ['bg-secondary-fixed text-on-secondary-fixed', 'info'],
];
?>
<div class="pointer-events-none fixed right-4 top-20 z-[60] flex w-[360px] max-w-[calc(100vw-2rem)] flex-col gap-2" data-flash>
  <?php foreach ($messages as $m): $s = isset($styles[$m['type']]) ? $styles[$m['type']] : $styles['info']; ?>
  <div class="pointer-events-auto flex items-start gap-2 rounded-xl px-4 py-3 shadow-card <?= $s[0] ?>" role="status">
    <span class="material-symbols-outlined text-[20px]"><?= $s[1] ?></span>
    <p class="flex-1 font-label-md text-label-md"><?= e($m['message']) ?></p>
  </div>
  <?php endforeach; ?>
</div>
