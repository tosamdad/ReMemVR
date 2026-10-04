<?php
/**
 * 쪽 번호 이동(운영 메뉴 공통). 변수: page, pages, base(경로), query(유지할 검색 조건), anchor(선택)
 */
$anchor = isset($anchor) ? (string) $anchor : '';
$link = static function (int $p) use ($base, $query, $anchor) {
    return url($base, array_merge($query, ['page' => $p])) . $anchor;
};
$nums = [1, $pages];
for ($p = $page - 2; $p <= $page + 2; $p++) {
    if ($p >= 1 && $p <= $pages) {
        $nums[] = $p;
    }
}
$nums = array_values(array_unique($nums));
sort($nums);
$btn = 'flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-2 font-label-md text-label-md transition-colors';
?>
<nav class="flex items-center gap-2" aria-label="쪽 이동">
  <?php if ($page > 1): ?>
  <a href="<?= e($link($page - 1)) ?>" class="<?= $btn ?> bg-surface-container-low text-on-surface hover:bg-surface-container-high" aria-label="이전 쪽"><span class="material-symbols-outlined text-[18px]">chevron_left</span></a>
  <?php else: ?>
  <span class="<?= $btn ?> bg-surface-container-low text-outline" aria-hidden="true"><span class="material-symbols-outlined text-[18px]">chevron_left</span></span>
  <?php endif; ?>
  <?php $prev = 0; foreach ($nums as $n): ?>
  <?php if ($n - $prev > 1): ?><span class="px-1 text-on-surface-variant">…</span><?php endif; ?>
  <?php if ($n === $page): ?>
  <span class="<?= $btn ?> bg-primary text-on-primary shadow-sm" aria-current="page"><?= $n ?></span>
  <?php else: ?>
  <a href="<?= e($link($n)) ?>" class="<?= $btn ?> bg-surface-container-low text-on-surface hover:bg-surface-container-high"><?= $n ?></a>
  <?php endif; ?>
  <?php $prev = $n; endforeach; ?>
  <?php if ($page < $pages): ?>
  <a href="<?= e($link($page + 1)) ?>" class="<?= $btn ?> bg-surface-container-low text-on-surface hover:bg-surface-container-high" aria-label="다음 쪽"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a>
  <?php else: ?>
  <span class="<?= $btn ?> bg-surface-container-low text-outline" aria-hidden="true"><span class="material-symbols-outlined text-[18px]">chevron_right</span></span>
  <?php endif; ?>
</nav>
