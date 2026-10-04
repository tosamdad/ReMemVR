<?php
/**
 * 목소리 연구실 목록의 목소리 카드 한 장. 변수: $v (VoiceLabController::decorate 결과)
 * 카드 전체가 상세(초안이면 녹음 화면)로 가고, 준비된 목소리는 오른쪽 재생 버튼으로 미리 들어 본다.
 */
$status = (string) $v['status'];
$id = (int) $v['id'];
// 녹음 중인 초안은 바로 녹음 화면으로, 나머지는 상세 화면으로
$href = $status === 'draft' ? url('/voice-lab/' . $id . '/record') : url('/voice-lab/' . $id);
$working = in_array($status, ['cloning', 'processing'], true);
$percent = $working && !empty($v['progress']) ? (int) $v['progress']['percent'] : 0;

// 상태별 모양: [아바타 배경, 아이콘 색, 점 색, 상태 글자 색, 상태 문구]
$looks = [
    'completed' => ['bg-primary-container/30', 'text-primary', 'bg-secondary', 'text-on-secondary-container', '준비됨'],
    'cloning' => ['bg-tertiary-container/30', 'text-tertiary', 'bg-primary animate-pulse', 'text-primary tracking-tighter', '생성 중... ' . $percent . '%'],
    'processing' => ['bg-tertiary-container/30', 'text-tertiary', 'bg-primary animate-pulse', 'text-primary tracking-tighter', '생성 중... ' . $percent . '%'],
    'pending' => ['bg-secondary-container/40', 'text-secondary', 'bg-tertiary', 'text-on-tertiary-container', '검토 대기 중'],
    'rejected' => ['bg-error-container/50', 'text-error', 'bg-error', 'text-error', '재녹음 필요'],
    'failed' => ['bg-error-container/50', 'text-error', 'bg-error', 'text-error', '생성 실패, 문의해 주세요'],
    'draft' => ['bg-surface-container', 'text-on-surface-variant', 'bg-outline', 'text-on-surface-variant', '녹음 이어하기'],
];
$look = isset($looks[$status]) ? $looks[$status] : $looks['draft'];
?>
<div class="relative flex items-center justify-between gap-3 rounded-[24px] border border-surface-container bg-surface-container-lowest p-6 shadow-[0_10px_15px_-3px_rgba(0,0,0,0.05),0_4px_6px_-2px_rgba(0,0,0,0.02)] transition-transform active:scale-[0.99]<?= $working ? ' opacity-80' : '' ?>"
     data-voice-card="<?= $id ?>" data-status="<?= e($status) ?>">
  <a href="<?= e($href) ?>" class="flex min-w-0 flex-1 items-center gap-4 after:absolute after:inset-0 after:rounded-[24px]" aria-label="<?= e($v['label'] . ' 목소리 ' . $look[4]) ?>">
    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl <?= $look[0] ?> <?= $look[1] ?>">
      <span class="material-symbols-outlined text-3xl"><?= e(voice_icon($v)) ?></span>
    </div>
    <div class="min-w-0">
      <p class="truncate font-headline-md text-body-lg font-bold<?= $working ? ' text-outline' : ' text-on-surface' ?>"><?= e($v['label']) ?> 목소리</p>
      <div class="mt-1 flex items-center gap-1.5">
        <span class="h-2 w-2 shrink-0 rounded-full <?= $look[2] ?>"></span>
        <span class="text-label-sm font-semibold <?= $look[3] ?>" data-voice-status><?= e($look[4]) ?></span>
      </div>
      <?php if ($status === 'rejected' && (string) $v['reject_reason'] !== ''): ?>
        <p class="mt-1.5 line-clamp-2 text-label-sm text-on-surface-variant"><?= e($v['reject_reason']) ?></p>
      <?php endif; ?>
    </div>
  </a>
  <?php if ($status === 'completed' && !empty($v['play_url'])): ?>
    <button type="button" class="relative z-10 flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-surface-container text-on-surface-variant transition-all hover:bg-surface-container-high active:scale-90"
            data-play-src="<?= e($v['play_url']) ?>" aria-label="<?= e($v['label']) ?> 목소리 들어 보기">
      <span class="material-symbols-outlined icon-fill" data-play-icon>play_arrow</span>
    </button>
  <?php elseif ($working): ?>
    <div class="flex h-12 w-12 shrink-0 items-center justify-center text-outline" aria-hidden="true">
      <span class="material-symbols-outlined animate-spin">progress_activity</span>
    </div>
  <?php elseif ($status === 'pending'): ?>
    <div class="flex h-12 w-12 shrink-0 items-center justify-center text-outline" aria-hidden="true">
      <span class="material-symbols-outlined">hourglass_top</span>
    </div>
  <?php elseif ($status === 'rejected'): ?>
    <a href="<?= e(url('/voice-lab/' . $id . '/record')) ?>" class="relative z-10 flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-error-container text-on-error-container transition-all active:scale-90" aria-label="다시 녹음하기">
      <span class="material-symbols-outlined">mic</span>
    </a>
  <?php elseif ($status === 'failed'): ?>
    <div class="flex h-12 w-12 shrink-0 items-center justify-center text-error" aria-hidden="true">
      <span class="material-symbols-outlined">error</span>
    </div>
  <?php else: ?>
    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary-container/40 text-primary" aria-hidden="true">
      <span class="material-symbols-outlined">mic</span>
    </div>
  <?php endif; ?>
</div>
