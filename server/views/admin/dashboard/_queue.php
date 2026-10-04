<?php
/** 대시보드: 목소리 생성 요청 대기열(오래된 순 3건). 변수: d */
use App\Services\DashboardStats;

$queue = $d['queue'];
$elReady = $d['elevenlabs_ready'];
$tones = ['primary' => 'text-primary', 'secondary' => 'text-secondary', 'error' => 'text-error', 'muted' => 'text-on-surface-variant'];
if (!$queue['items']): ?>
<div class="flex flex-col items-center gap-3 rounded-2xl bg-surface-container-low/70 px-6 py-10 text-center">
  <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-fixed text-on-primary-fixed-variant"><span class="material-symbols-outlined text-[28px]">task_alt</span></div>
  <p class="font-label-md text-label-md text-on-surface">검토할 목소리 요청이 없습니다</p>
  <p class="max-w-md font-body-md text-sm text-on-surface-variant">부모님이 녹음을 마치고 제출하면 여기에 바로 표시됩니다. 이 화면은 30초마다 새 요청을 확인합니다.</p>
</div>
<?php return; endif; ?>
<div class="flex flex-col gap-3.5">
  <?php foreach ($queue['items'] as $v):
      $icon = voice_icon($v);
      $chip = strpos($icon, 'elderly') === 0 ? 'bg-tertiary-fixed text-on-tertiary-fixed-variant' : ($icon === 'face_6' ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-primary-fixed text-on-primary-fixed-variant');
      $child = $v['child'];
      $age = $child ? child_age($child) : null;
      $childText = $child ? '자녀: ' . $child['name'] . ($age !== null ? '(만 ' . $age . '세)' : '') : '자녀 미등록';
      $smp = $v['samples'];
      $grade = $v['grade'];
      $name = $v['user_name'] . ' (' . $v['label'] . ')';
  ?>
  <div class="flex flex-wrap items-center justify-between gap-4 rounded-2xl bg-surface-container-low/70 p-5 transition-all hover:bg-surface-container-low">
    <div class="flex min-w-0 flex-1 basis-[320px] items-center gap-4">
      <a href="<?= e(url('/admin/voices/' . (int) $v['id'])) ?>" class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl <?= $chip ?>" aria-label="상세 검수"><span class="material-symbols-outlined text-[28px]"><?= e($icon) ?></span></a>
      <div class="flex min-w-0 flex-col">
        <div class="flex flex-wrap items-center gap-2">
          <a href="<?= e(url('/admin/voices/' . (int) $v['id'])) ?>" class="font-headline-md text-[18px] font-bold text-on-surface hover:text-primary"><?= e($v['user_name']) ?></a>
          <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $chip ?>"><?= e($v['label']) ?> 목소리</span>
          <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($childText) ?></span>
        </div>
        <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 font-body-md text-sm text-on-surface-variant">
          <span>신청: <?= e(time_ago($v['requested_at'])) ?></span>
          <span aria-hidden="true">•</span>
          <span>샘플 <?= (int) $smp['count'] ?>개 (총 <?= e(DashboardStats::koDuration($smp['total_ms'])) ?>)</span>
          <span aria-hidden="true">•</span>
          <span class="font-semibold <?= $tones[$grade['tone']] ?>"><?= $smp['snr'] !== null ? '평균 SNR ' . e(fmt_number($smp['snr'], 1)) . 'dB (' . e($grade['short']) . ')' : e($grade['label']) ?></span>
        </div>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <?= partial('admin/voices/_player', ['src' => $smp['first_id'] ? url('/admin/media/sample/' . $smp['first_id']) : '', 'ms' => $smp['first_ms'], 'variant' => 'dash', 'seed' => (int) $v['id']]) ?>
      <div class="flex items-center gap-2">
        <button type="button" class="flex items-center gap-1 rounded-xl bg-error-container px-3.5 py-2 font-label-md text-label-md text-on-error-container transition-colors hover:opacity-90" data-reject-url="<?= e(url('/admin/voices/' . (int) $v['id'] . '/reject')) ?>" data-reject-name="<?= e($name) ?>">
          <span class="material-symbols-outlined text-[16px]">close</span><span>재녹음 요청</span>
        </button>
        <form method="post" action="<?= e(url('/admin/voices/' . (int) $v['id'] . '/clone')) ?>" data-confirm="<?= e($name . ' 목소리로 ElevenLabs 목소리 복제를 시작할까요?') ?>">
          <?= csrf_field() ?>
          <button type="submit" class="flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 font-label-md text-label-md text-on-primary shadow-sm transition-all hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-50"<?= $elReady ? '' : ' disabled title="ElevenLabs API 키가 등록되지 않아 승인할 수 없습니다"' ?>>
            <span class="material-symbols-outlined text-[16px]">check_circle</span><span>클로닝 승인</span>
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<div class="mt-4 flex flex-wrap items-center justify-between gap-2 pt-3 text-on-surface-variant">
  <span class="font-body-md text-body-md"><?= $queue['total'] > count($queue['items']) ? '추가 ' . fmt_number($queue['total'] - count($queue['items'])) . '건의 대기 요청이 있습니다.' : '대기 중인 요청을 모두 표시했습니다.' ?></span>
  <a class="flex items-center gap-1 font-label-md text-label-md font-bold text-primary hover:underline" href="<?= e(url('/admin/voices', ['status' => 'pending'])) ?>">
    <span>목소리 관리 전체보기 (<?= fmt_number($queue['total']) ?>)</span><span class="material-symbols-outlined text-[16px]">arrow_forward</span>
  </a>
</div>
