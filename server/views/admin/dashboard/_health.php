<?php
/** 대시보드: 코어 엔진 헬스체크(Health::status). 변수: d */
$h = $d['health'];
$policy = $d['policy'];
?>
<div class="flex items-center justify-between gap-2">
  <div class="flex items-center gap-2">
    <span class="material-symbols-outlined text-[22px] text-primary">health_and_safety</span>
    <h3 class="font-headline-md text-[18px] font-bold text-on-surface">코어 엔진 헬스체크</h3>
  </div>
  <div class="flex items-center gap-2">
    <?php if ($h['fake']): ?><span class="rounded-full bg-secondary-fixed px-2 py-0.5 font-label-sm text-label-sm text-on-secondary-fixed">개발 모드</span><?php endif; ?>
    <span class="relative flex h-2.5 w-2.5" title="<?= $h['all_ok'] ? '모두 정상' : '점검 필요' ?>">
      <?php if ($h['all_ok']): ?><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-500 opacity-60"></span><?php endif; ?>
      <span class="relative inline-flex h-2.5 w-2.5 rounded-full <?= $h['all_ok'] ? 'bg-emerald-500' : 'bg-error' ?>"></span>
    </span>
  </div>
</div>
<div class="flex flex-col gap-2.5">
  <?php foreach ($h['items'] as $it):
      $muted = !empty($it['muted']);
      $iconCls = $muted ? 'bg-surface-container-highest text-on-surface-variant' : ($it['ok'] ? 'bg-emerald-100 text-emerald-700' : 'bg-error-container text-on-error-container');
      $dotCls = $muted ? 'bg-outline' : ($it['ok'] ? 'bg-emerald-500' : 'bg-error');
  ?>
  <div class="flex items-center justify-between gap-3 rounded-xl bg-surface-container-low p-3.5" title="<?= e($it['text']) ?>">
    <div class="flex min-w-0 items-center gap-3">
      <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg <?= $iconCls ?>"><span class="material-symbols-outlined text-[18px]"><?= e($it['icon']) ?></span></div>
      <div class="flex min-w-0 flex-col">
        <span class="font-label-md text-label-md font-semibold text-on-surface"><?= e($it['name']) ?></span>
        <span class="font-label-sm text-label-sm leading-snug text-on-surface-variant"><?= e($it['detail']) ?></span>
      </div>
    </div>
    <div class="flex shrink-0 items-center gap-1.5 rounded-full bg-surface-container-lowest px-2.5 py-1 text-on-surface">
      <span class="h-2 w-2 shrink-0 rounded-full <?= $dotCls ?>"></span>
      <span class="whitespace-nowrap font-label-sm text-label-sm font-semibold"><?= e($it['badge']) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div class="mt-1 flex items-center justify-between gap-3 rounded-2xl bg-surface-container-high/50 p-4">
  <div class="flex items-center gap-2">
    <span class="material-symbols-outlined text-[20px] text-secondary">shield</span>
    <span class="font-label-md text-label-md text-on-surface">아이 질문 안전 장치</span>
  </div>
  <span class="text-right font-label-sm text-label-sm font-bold <?= $policy['qa_enabled'] ? 'text-primary' : 'text-on-surface-variant' ?>"><?= $policy['qa_enabled'] ? '답변 ' . (int) $policy['max_answer_chars'] . '자 제한 · 금지어 ' . (int) $policy['blocked_words'] . '개' : '질문 기능 보류 중' ?></span>
</div>
<?php if (!empty($h['checked_at'])): ?>
<span class="font-label-sm text-[11px] text-on-surface-variant/80">외부 API 점검 <?= e(time_ago($h['checked_at'])) ?> · 5분마다 다시 확인</span>
<?php endif; ?>
