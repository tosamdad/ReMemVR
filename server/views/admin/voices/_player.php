<?php
/**
 * 샘플 미니 플레이어. 재생은 admin-voices.js 가 맡는다(한 번에 하나만 재생).
 * 변수: src(재생 주소, 없으면 '샘플 없음'), ms(길이), variant(dash | table | list), seed(막대 모양용 숫자)
 */
$src = isset($src) ? (string) $src : '';
$ms = isset($ms) ? (int) $ms : 0;
$variant = isset($variant) ? $variant : 'dash';
$seed = isset($seed) ? (int) $seed : 1;
if ($src === '') {
    echo '<span class="inline-flex items-center gap-1 rounded-lg bg-surface-container px-3 py-2 font-label-sm text-label-sm text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">mic_off</span>샘플 없음</span>';

    return;
}
// 막대 높이는 장식이며 재생 진행률만 색으로 보여 준다.
$heights = $variant === 'dash' ? ['h-1', 'h-2', 'h-3', 'h-4', 'h-2', 'h-3'] : ['h-2', 'h-3', 'h-4', 'h-5', 'h-6', 'h-3'];
$count = $variant === 'dash' ? 10 : 14;
$bars = '';
for ($i = 0; $i < $count; $i++) {
    $h = $heights[($seed * 7 + $i * 5 + intdiv($i, 3)) % count($heights)];
    $bars .= '<span class="w-1 shrink-0 rounded-full bg-primary/40 ' . $h . '" data-bar></span>';
}
$total = fmt_duration($ms);
if ($variant === 'dash'): ?>
<div class="flex items-center gap-2 rounded-xl bg-surface-container-lowest px-3 py-2 shadow-sm" data-player>
  <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container transition-transform hover:scale-105 active:scale-95" data-play="<?= e($src) ?>" aria-label="샘플 듣기"><span class="material-symbols-outlined text-[18px]">play_arrow</span></button>
  <div class="flex flex-col">
    <div class="flex h-4 w-24 items-center gap-1"><?= $bars ?></div>
    <span class="font-label-sm text-[10px] text-on-surface-variant" data-play-time data-total-ms="<?= $ms ?>" data-format="pair">00:00 / <?= e($total) ?></span>
  </div>
</div>
<?php elseif ($variant === 'table'): ?>
<div class="flex items-center gap-2 rounded-lg bg-surface-container p-2" data-player>
  <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-secondary text-on-secondary shadow-sm transition-transform hover:scale-105" data-play="<?= e($src) ?>" aria-label="샘플 듣기"><span class="material-symbols-outlined text-[18px]">play_arrow</span></button>
  <div class="flex h-6 flex-1 items-center gap-0.5 overflow-hidden"><?= $bars ?></div>
  <span class="font-label-sm text-label-sm text-on-surface-variant" data-play-time data-total-ms="<?= $ms ?>" data-format="total"><?= e($total) ?></span>
</div>
<?php else: ?>
<div class="flex items-center gap-2" data-player>
  <button type="button" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-surface-container-highest text-on-surface transition-colors hover:bg-surface-container-high" data-play="<?= e($src) ?>" aria-label="듣기"><span class="material-symbols-outlined text-[18px]">play_arrow</span></button>
  <div class="flex h-5 flex-1 items-center gap-0.5 overflow-hidden"><?= $bars ?></div>
  <span class="font-label-sm text-label-sm text-on-surface-variant" data-play-time data-total-ms="<?= $ms ?>" data-format="total"><?= e($total) ?></span>
</div>
<?php endif;
