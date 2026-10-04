<?php
/**
 * 목소리 상세: 진행 단계, 생성 진행률(10초마다 갱신), 반려 사유, 이 목소리로 준비된 동화, 샘플, 이름 바꾸기, 삭제.
 * 변수: $voice(decorate 결과), $samples, $stories, $progress, $timeline, $labelMax
 */
$pid = (int) $voice['id'];
$status = (string) $voice['status'];
layout('user/layout', [
    'title' => $voice['label'] . ' 목소리',
    'nav' => 'voice',
    'header' => 'sub',
    'back' => '/voice-lab',
    'headerTitle' => $voice['label'] . ' 목소리',
]);
$working = in_array($status, ['cloning', 'processing'], true);
$chips = [
    'draft' => 'bg-surface-container text-on-surface-variant',
    'pending' => 'bg-tertiary-container text-on-tertiary-container',
    'cloning' => 'bg-primary-container text-on-primary-container',
    'processing' => 'bg-primary-container text-on-primary-container',
    'completed' => 'bg-secondary-container text-on-secondary-container',
    'rejected' => 'bg-error-container text-on-error-container',
    'failed' => 'bg-error-container text-on-error-container',
];
$grades = [
    'good' => ['좋아요', 'bg-secondary-container text-on-secondary-container'],
    'fair' => ['괜찮아요', 'bg-tertiary-container text-on-tertiary-container'],
    'poor' => ['다시 녹음을 권해요', 'bg-error-container text-on-error-container'],
];
$p = $progress ?: ['total' => 0, 'completed' => 0, 'percent' => 0, 'failed' => 0];
// 녹음 합계는 지금 남아 있는 샘플 기준으로 보여 준다.
$sampleMs = 0;
foreach ($samples as $s) {
    $sampleMs += (int) $s['duration_ms'];
}
$stepDesc = [
    'record' => $samples ? '녹음 ' . count($samples) . '개 · 총 ' . fmt_duration($sampleMs) : '대본을 읽어 녹음해 주세요',
    'review' => $status === 'rejected' ? '다시 녹음이 필요해요' : ($status === 'pending' ? '운영팀이 녹음 상태를 확인하고 있어요' : ($status === 'draft' ? '제출하면 운영팀이 확인해요' : '확인을 마쳤어요')),
    'clone' => $status === 'cloning' ? 'AI가 목소리를 배우고 있어요' : (!empty($voice['cloned_at']) ? time_ago($voice['cloned_at']) . ' 완료' : ($status === 'failed' ? '목소리를 만들지 못했어요' : 'AI가 목소리의 특징을 배워요')),
    'stories' => in_array($status, ['processing', 'completed', 'failed'], true) && $p['total'] > 0 ? '동화 ' . (int) $p['total'] . '편 중 ' . (int) $p['completed'] . '편 준비' : '무료 동화를 이 목소리로 미리 읽어 둬요',
    'done' => $status === 'completed' ? '이제 이 목소리로 동화를 들을 수 있어요' : '준비가 끝나면 바로 들을 수 있어요',
];
$dot = [
    'done' => 'bg-secondary text-on-secondary',
    'current' => 'bg-primary text-on-primary ring-4 ring-primary-container',
    'error' => 'bg-error text-on-error ring-4 ring-error-container',
    'todo' => 'bg-surface-container-high text-outline',
];
?>
<div class="space-y-8" data-vl-show data-voice-card="<?= $pid ?>" data-status="<?= e($status) ?>">
  <!-- 목소리 머리 -->
  <section class="relative flex flex-col items-center overflow-hidden rounded-3xl bg-primary-container/20 px-6 py-8 text-center">
    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-secondary-container/30 blur-3xl" aria-hidden="true"></div>
    <div class="relative mb-4 flex h-24 w-24 items-center justify-center rounded-[28px] bg-surface-container-lowest text-primary shadow-soft">
      <span class="material-symbols-outlined text-[52px]"><?= e(voice_icon($voice)) ?></span>
    </div>
    <h2 class="relative font-headline-md text-headline-md text-on-surface"><?= e($voice['label']) ?> 목소리</h2>
    <span class="relative mt-2 inline-flex items-center gap-1.5 rounded-full px-3 py-1 font-label-sm text-label-sm <?= isset($chips[$status]) ? $chips[$status] : $chips['draft'] ?>">
      <?php if ($working): ?><span class="material-symbols-outlined animate-spin text-[14px]">progress_activity</span><?php endif; ?>
      <span data-voice-status><?= e($working ? '생성 중... ' . (int) $p['percent'] . '%' : voice_status_label($status)) ?></span>
    </span>
    <?php if (!empty($voice['play_url']) && in_array($status, ['completed', 'processing'], true)): ?>
      <button type="button" class="btn-secondary relative mt-5 rounded-full px-6 py-3" data-play-src="<?= e($voice['play_url']) ?>">
        <span class="material-symbols-outlined icon-fill" data-play-icon>play_arrow</span>목소리 들어 보기
      </button>
    <?php endif; ?>
  </section>

  <?php if ($status === 'rejected'): ?>
    <section class="space-y-4 rounded-3xl bg-error-container p-6 text-on-error-container">
      <div class="flex items-start gap-3">
        <span class="material-symbols-outlined">replay</span>
        <div class="space-y-1">
          <p class="font-label-lg text-label-lg">다시 녹음이 필요해요</p>
          <p class="text-[14px] leading-5"><?= e((string) $voice['reject_reason'] !== '' ? $voice['reject_reason'] : '녹음 상태가 목소리를 만들기에 충분하지 않았어요.') ?></p>
        </div>
      </div>
      <a href="<?= e(url('/voice-lab/' . $pid . '/record')) ?>" class="btn-primary w-full rounded-full"><span class="material-symbols-outlined">mic</span>다시 녹음하기</a>
    </section>
  <?php elseif ($status === 'draft'): ?>
    <section class="space-y-4 rounded-3xl bg-surface-container-low p-6">
      <p class="text-[14px] leading-5 text-on-surface-variant">아직 녹음 중이에요. 대본을 모두 읽고 제출하면 목소리 만들기가 시작돼요.</p>
      <a href="<?= e(url('/voice-lab/' . $pid . '/record')) ?>" class="btn-primary w-full rounded-full"><span class="material-symbols-outlined">mic</span>녹음 이어하기</a>
    </section>
  <?php elseif ($status === 'failed'): ?>
    <section class="space-y-4 rounded-3xl bg-error-container p-6 text-on-error-container">
      <div class="flex items-start gap-3">
        <span class="material-symbols-outlined">error</span>
        <div class="space-y-1">
          <p class="font-label-lg text-label-lg">생성 실패, 문의해 주세요</p>
          <p class="text-[14px] leading-5">목소리를 만드는 중에 문제가 생겼어요. 1:1 문의로 알려 주시면 빠르게 확인해 드릴게요.</p>
        </div>
      </div>
      <a href="<?= e(url('/settings/support')) ?>" class="btn-ghost w-full rounded-full border-2 border-on-error-container/30 text-on-error-container"><span class="material-symbols-outlined">support_agent</span>문의하기</a>
    </section>
  <?php elseif ($status === 'pending'): ?>
    <section class="flex items-start gap-3 rounded-3xl bg-tertiary-container/40 p-6 text-on-tertiary-container">
      <span class="material-symbols-outlined">hourglass_top</span>
      <p class="text-[14px] leading-5">제출해 주셔서 고마워요! 운영팀이 녹음을 확인한 뒤 목소리 만들기를 시작해요. 준비가 끝나면 이 화면에서 바로 확인할 수 있어요.</p>
    </section>
  <?php endif; ?>

  <?php if ($working || ($status === 'completed' && $p['total'] > 0)): ?>
    <!-- 생성 진행률 -->
    <section class="card space-y-3 p-6" data-progress-card>
      <div class="flex items-baseline justify-between">
        <p class="font-label-lg text-label-lg text-on-surface"><?= $status === 'cloning' ? '목소리 만드는 중' : '동화 준비' ?></p>
        <p class="font-headline-md text-[24px] font-bold text-primary"><span data-progress-percent><?= (int) $p['percent'] ?></span>%</p>
      </div>
      <div class="h-3 w-full overflow-hidden rounded-full bg-surface-container">
        <div class="h-full rounded-full bg-secondary transition-all duration-700" style="width:<?= (int) $p['percent'] ?>%" data-progress-bar></div>
      </div>
      <p class="text-label-sm text-on-surface-variant">동화 <span data-progress-total><?= (int) $p['total'] ?></span>편 중 <span data-progress-done><?= (int) $p['completed'] ?></span>편 준비됨<?= $working ? ' · 화면을 닫아도 계속 만들어요' : '' ?></p>
    </section>
  <?php endif; ?>

  <!-- 진행 단계 -->
  <section class="space-y-4">
    <h3 class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">진행 상황</h3>
    <ol class="card p-6">
      <?php foreach ($timeline as $i => $t): $last = $i === count($timeline) - 1; ?>
        <li class="relative flex gap-4<?= $last ? '' : ' pb-6' ?>"<?= $t['state'] === 'current' || $t['state'] === 'error' ? ' aria-current="step"' : '' ?>>
          <?php if (!$last): ?>
            <span class="absolute left-[19px] top-10 h-[calc(100%-2.5rem)] w-0.5 <?= $t['state'] === 'done' ? 'bg-secondary' : 'bg-surface-container-high' ?>" aria-hidden="true"></span>
          <?php endif; ?>
          <span class="relative flex h-10 w-10 shrink-0 items-center justify-center rounded-full <?= $dot[$t['state']] ?>">
            <span class="material-symbols-outlined text-[20px]<?= $t['state'] === 'current' && $working ? ' animate-pulse' : '' ?>"><?= e($t['state'] === 'done' ? 'check' : ($t['state'] === 'error' ? 'priority_high' : $t['icon'])) ?></span>
          </span>
          <div class="min-w-0 pt-1">
            <p class="font-label-lg text-label-lg <?= $t['state'] === 'todo' ? 'text-outline' : ($t['state'] === 'error' ? 'text-error' : 'text-on-surface') ?>"><?= e($t['label']) ?></p>
            <p class="text-label-sm text-on-surface-variant"><?= e($stepDesc[$t['key']]) ?></p>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
  </section>

  <?php if (in_array($status, ['processing', 'completed', 'failed'], true)): ?>
    <!-- 이 목소리로 준비된 동화 -->
    <section class="space-y-4">
      <div class="flex items-end justify-between">
        <h3 class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">이 목소리로 듣는 동화</h3>
        <span class="font-label-sm text-label-sm text-outline"><?= count($stories) ?>편</span>
      </div>
      <?php if (!$stories): ?>
        <div class="flex flex-col items-center rounded-[24px] bg-surface-container-low px-6 py-8 text-center">
          <img src="<?= e(asset('img/empty-stories.svg')) ?>" alt="" class="mb-3 h-24 w-24 object-contain" width="96" height="96">
          <p class="text-[14px] leading-5 text-on-surface-variant">아직 준비된 동화가 없어요.<br>한 편씩 준비되는 대로 여기에 나타나요.</p>
        </div>
      <?php else: ?>
        <ul class="space-y-3">
          <?php foreach ($stories as $st): ?>
            <li>
              <a href="<?= e(url('/player/' . (int) $st['id'], ['voice' => $pid])) ?>" class="bento-card flex items-center gap-4 rounded-[24px] border border-surface-container bg-surface-container-lowest p-3 pr-4 shadow-soft">
                <img src="<?= e(cover_url($st)) ?>" alt="" class="h-16 w-16 shrink-0 rounded-2xl bg-surface-container object-cover" width="64" height="64" loading="lazy">
                <div class="min-w-0 flex-1">
                  <p class="truncate font-label-lg text-[16px] leading-6 text-on-surface"><?= e($st['title']) ?></p>
                  <p class="text-label-sm text-on-surface-variant"><?= e(trim(((string) $st['category'] !== '' ? $st['category'] . ' · ' : '') . ($st['duration_ms'] ? fmt_duration((int) $st['duration_ms']) : ($st['est_duration_sec'] ? fmt_duration((int) $st['est_duration_sec'] * 1000) : '')), ' ·')) ?></p>
                </div>
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary"><span class="material-symbols-outlined icon-fill">play_arrow</span></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <!-- 녹음한 목소리 -->
  <section class="space-y-4">
    <div class="flex items-end justify-between">
      <h3 class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">녹음한 목소리</h3>
      <span class="font-label-sm text-label-sm text-outline"><?= count($samples) ?>개 · <?= e(fmt_duration($sampleMs)) ?></span>
    </div>
    <?php if (!$samples): ?>
      <p class="rounded-2xl bg-surface-container-low px-4 py-5 text-center text-[14px] text-on-surface-variant">아직 녹음이 없어요.</p>
    <?php else: ?>
      <ul class="space-y-3">
        <?php foreach ($samples as $s): $g = $s['grade'] && isset($grades[$s['grade']]) ? $grades[$s['grade']] : null; ?>
          <li class="flex items-center gap-3 rounded-2xl border border-surface-container bg-surface-container-lowest p-3">
            <button type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container active:scale-90" data-play-src="<?= e($s['url']) ?>" aria-label="<?= e($s['title']) ?> 듣기">
              <span class="material-symbols-outlined icon-fill" data-play-icon>play_arrow</span>
            </button>
            <div class="min-w-0 flex-1">
              <p class="truncate font-label-lg text-label-lg text-on-surface"><?= e($s['title']) ?></p>
              <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 gap-y-1 text-label-sm text-on-surface-variant">
                <span><?= e(($s['duration_ms'] ? fmt_duration($s['duration_ms']) : '길이 알 수 없음') . ' · ' . ($s['source'] === 'record' ? '녹음' : '파일')) ?></span>
                <?php if ($g): ?><span class="rounded-full px-2 py-0.5 text-[11px] leading-4 <?= $g[1] ?>"><?= e($g[0]) ?></span><?php endif; ?>
              </p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>

  <!-- 관리 -->
  <section class="space-y-3">
    <h3 class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">관리</h3>
    <details class="card group overflow-hidden"<?= errors('label') ? ' open' : '' ?>>
      <summary class="flex cursor-pointer list-none items-center gap-3 p-5 [&::-webkit-details-marker]:hidden">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-surface-container text-on-surface-variant"><span class="material-symbols-outlined">edit</span></span>
        <span class="flex-1 font-label-lg text-label-lg text-on-surface">이름 바꾸기</span>
        <span class="material-symbols-outlined text-outline transition-transform group-open:rotate-180">expand_more</span>
      </summary>
      <form method="post" action="<?= e(url('/voice-lab/' . $pid . '/rename')) ?>" class="space-y-3 border-t border-surface-container p-5">
        <?= csrf_field() ?>
        <label for="label" class="field-label">목소리 이름</label>
        <div class="flex gap-2">
          <input type="text" id="label" name="label" value="<?= e((string) old('label', $voice['label'])) ?>" maxlength="<?= (int) $labelMax ?>" class="field" required>
          <button type="submit" class="btn-primary shrink-0 rounded-xl px-5 py-3">저장</button>
        </div>
        <?php if (errors('label')): ?><p class="field-error"><?= e(errors('label')) ?></p><?php endif; ?>
        <p class="text-label-sm text-on-surface-variant">화면에는 "이름 + 목소리"로 보여요. 예) 외할머니 목소리</p>
      </form>
    </details>
    <form method="post" action="<?= e(url('/voice-lab/' . $pid . '/delete')) ?>" data-confirm="<?= e($voice['label']) ?> 목소리를 삭제할까요? 녹음과 이 목소리로 만든 동화 오디오도 모두 지워지고 되돌릴 수 없어요.">
      <?= csrf_field() ?>
      <button type="submit" class="flex w-full items-center gap-3 rounded-xl border border-error/30 bg-surface-container-lowest p-5 text-left text-error transition-all active:scale-[0.99]">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-error-container text-on-error-container"><span class="material-symbols-outlined">delete</span></span>
        <span class="flex-1">
          <span class="block font-label-lg text-label-lg">목소리 삭제</span>
          <span class="block text-label-sm text-on-surface-variant">녹음 파일과 AI 목소리를 모두 지워요.</span>
        </span>
      </button>
    </form>
  </section>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/voice-lab.js')) ?>"></script>
<?php endsection(); ?>
