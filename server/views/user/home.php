<?php
/**
 * 홈(시안 _5). 인사, 학습 현황, 오늘의 추천 동화, 우리 가족 목소리, 이어 듣기 버튼.
 * @var array $child
 * @var array $level
 * @var int $weekBooks
 * @var array $recommended
 * @var array $voices
 * @var bool $canAddVoice
 */
layout('user/layout', ['title' => '홈', 'nav' => 'home', 'mainClass' => 'px-margin-mobile pt-6 pb-6 space-y-8']);
?>
<!-- 인사 -->
<section class="space-y-1">
  <h1 class="text-headline-xl-mobile font-headline-xl-mobile text-on-surface">안녕, <?= e($child['name']) ?>! 👋</h1>
  <p class="text-body-md font-body-md text-on-surface-variant">오늘은 어떤 모험을 떠나볼까?</p>
</section>

<!-- 학습 현황 -->
<a href="<?= e(url('/report')) ?>" class="bento-card block bg-surface-container-lowest rounded-xl p-6 shadow-soft border border-surface-variant/30" aria-label="학습 리포트 보기">
  <div class="flex justify-between items-start mb-4">
    <div class="space-y-1">
      <h2 class="text-headline-md font-headline-md text-on-surface">학습 현황</h2>
      <p class="text-label-lg font-label-lg text-on-surface-variant">이번 주 읽은 책 <?= (int) $weekBooks ?>권</p>
    </div>
    <div class="bg-secondary-container text-on-secondary-container p-3 rounded-xl">
      <span class="material-symbols-outlined icon-fill">auto_stories</span>
    </div>
  </div>
  <div class="relative w-full h-4 bg-primary-container/20 rounded-full overflow-hidden" role="progressbar" aria-valuemin="0" aria-valuemax="<?= (int) $level['span'] ?>" aria-valuenow="<?= (int) $level['into'] ?>" aria-label="다음 레벨까지">
    <div class="absolute left-0 top-0 h-full bg-primary rounded-full transition-all duration-1000 ease-out" style="width: <?= max(2, (int) $level['percent']) ?>%;"></div>
  </div>
  <div class="flex justify-between mt-3">
    <span class="text-label-sm font-label-sm text-primary">레벨 <?= (int) $level['level'] ?> <?= e($level['title']) ?></span>
    <span class="text-label-sm font-label-sm text-on-surface-variant"><?= e(fmt_number($level['into'])) ?> / <?= e(fmt_number($level['span'])) ?> XP</span>
  </div>
</a>

<!-- 오늘의 추천 도서 -->
<section class="space-y-4">
  <div class="flex justify-between items-center">
    <h2 class="text-headline-md font-headline-md text-on-surface">오늘의 추천 도서</h2>
    <a href="<?= e(url('/stories')) ?>" class="text-primary font-label-lg text-label-lg">전체보기</a>
  </div>
  <?php if ($recommended): ?>
  <div class="flex overflow-x-auto gap-4 pb-4 no-scrollbar -mx-margin-mobile px-margin-mobile">
    <?php foreach ($recommended as $s): ?>
    <a href="<?= e(url('/player/' . (int) $s['id'])) ?>" class="w-[200px] min-w-[200px] bento-card bg-surface-container-lowest rounded-xl overflow-hidden shadow-sm border border-surface-variant/30">
      <div class="h-48 w-full relative bg-surface-container">
        <img class="w-full h-full object-cover" src="<?= e(cover_url($s)) ?>" alt="" loading="lazy">
        <?php if (!empty($s['category'])): ?>
        <div class="absolute top-3 right-3 bg-surface-container-lowest/90 backdrop-blur-md px-2 py-1 rounded-full text-[10px] font-bold text-primary shadow-sm whitespace-nowrap tracking-wider"><?= e($s['category']) ?></div>
        <?php endif; ?>
        <?php if ($s['completed']): ?>
        <div class="absolute top-3 left-3 flex h-7 w-7 items-center justify-center rounded-full bg-secondary text-on-secondary shadow-sm" title="다 들은 동화">
          <span class="material-symbols-outlined text-[18px]">check</span>
        </div>
        <?php endif; ?>
      </div>
      <div class="p-4 space-y-1">
        <h3 class="text-label-lg font-label-lg text-on-surface truncate"><?= e($s['title']) ?></h3>
        <p class="text-label-sm font-label-sm text-on-surface-variant flex items-center gap-1">
          <?php if ($s['duration_label'] !== ''): ?>
          <span class="material-symbols-outlined text-[14px]">schedule</span> <?= e($s['duration_label']) ?>
          <?php else: ?>
          <span class="material-symbols-outlined text-[14px]">auto_stories</span> 동화
          <?php endif; ?>
        </p>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <div class="card flex flex-col items-center gap-3 p-6 text-center">
    <img src="<?= e(asset('img/empty-stories.svg')) ?>" alt="" class="h-28 w-auto">
    <p class="text-label-lg font-label-lg text-on-surface">곧 새로운 동화가 찾아올 거예요</p>
    <p class="text-body-md text-on-surface-variant">동화가 준비되면 여기에서 바로 들을 수 있어요.</p>
  </div>
  <?php endif; ?>
</section>

<!-- 우리 가족 목소리 -->
<section class="space-y-4">
  <h2 class="text-headline-md font-headline-md text-on-surface">우리 가족 목소리</h2>
  <?php if ($voices): ?>
  <div class="grid grid-cols-3 gap-3">
    <?php foreach ($voices as $v): $line = $v['status_line']; $ready = $v['status'] === 'completed'; ?>
    <a href="<?= e(url('/voice-lab/' . (int) $v['id'])) ?>" class="bento-card bg-surface-container-lowest p-4 rounded-xl flex flex-col items-center text-center space-y-2 border border-surface-variant/30 relative min-w-0">
      <div class="relative">
        <div class="w-16 h-16 rounded-full overflow-hidden border-2 <?= $ready ? 'border-primary-container bg-primary-container/30' : 'border-surface-variant bg-surface-container' ?> flex items-center justify-center">
          <span class="material-symbols-outlined text-3xl <?= $ready ? 'text-primary' : 'text-on-surface-variant' ?>"><?= e(voice_icon($v)) ?></span>
        </div>
        <?php if ($line['dot']): ?>
        <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-secondary border-2 border-surface-container-lowest rounded-full" aria-hidden="true"></div>
        <?php endif; ?>
      </div>
      <span class="text-label-lg font-label-lg text-on-surface max-w-full truncate"><?= e($v['label']) ?></span>
      <span class="text-label-sm font-label-sm break-keep <?= e($line['class']) ?>"><?= e($line['text']) ?></span>
    </a>
    <?php endforeach; ?>
    <?php if ($canAddVoice): ?>
    <a href="<?= e(url('/voice-lab/new')) ?>" class="bento-card p-4 rounded-xl flex flex-col items-center justify-center text-center space-y-2 border-2 border-dashed border-outline-variant bg-surface-container-low/60 min-w-0">
      <div class="w-16 h-16 rounded-full bg-surface-container-lowest flex items-center justify-center">
        <span class="material-symbols-outlined text-primary text-3xl">add</span>
      </div>
      <span class="text-label-lg font-label-lg text-primary break-keep leading-5">목소리 추가</span>
      <span class="text-label-sm font-label-sm text-on-surface-variant">녹음하기</span>
    </a>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <div class="card flex flex-col items-center gap-3 p-6 text-center">
    <img src="<?= e(asset('img/empty-voices.svg')) ?>" alt="" class="h-28 w-auto">
    <p class="text-label-lg font-label-lg text-on-surface">아직 등록된 가족 목소리가 없어요</p>
    <p class="text-body-md text-on-surface-variant">엄마, 아빠의 목소리를 녹음하면<br>그 목소리로 동화를 읽어 줘요.</p>
    <a href="<?= e(url('/voice-lab/new')) ?>" class="btn-primary mt-1 w-full"><span class="material-symbols-outlined">mic</span>목소리 녹음하기</a>
  </div>
  <?php endif; ?>
</section>

<!-- 이어 듣기 -->
<div class="pointer-events-none fixed inset-x-0 bottom-24 z-40 mx-auto flex w-full max-w-[520px] justify-end px-6">
  <a href="<?= e(url('/player')) ?>" class="pointer-events-auto bg-secondary shadow-lg hover:scale-105 active:scale-95 transition-all duration-300 w-16 h-16 rounded-xl flex items-center justify-center text-on-secondary border-b-4 border-on-secondary-fixed/20" aria-label="이어 듣기">
    <span class="material-symbols-outlined icon-fill text-3xl">play_arrow</span>
  </a>
</div>
