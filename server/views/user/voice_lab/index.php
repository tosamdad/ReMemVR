<?php
/**
 * 목소리 연구실(시안 _7). 변수: $voices, $max, $used, $canCreate
 * 생성 중인 목소리가 있으면 voice-lab.js 가 10초마다 /api/voice-lab/status 로 카드를 갱신한다.
 */
layout('user/layout', ['title' => '목소리 연구실', 'nav' => 'voice']);
$tactile = 'shadow-[0_4px_0_0_rgb(var(--c-on-secondary-fixed-variant))] active:translate-y-0.5 active:shadow-[0_2px_0_0_rgb(var(--c-on-secondary-fixed-variant))]';
?>
<div class="space-y-8" data-vl-index>
  <!-- 목소리 만들기 안내 -->
  <section class="relative flex flex-col items-center space-y-6 overflow-hidden rounded-3xl bg-primary-container/20 p-6 text-center">
    <div class="absolute -right-12 -top-12 h-48 w-48 rounded-full bg-secondary-container/30 blur-3xl" aria-hidden="true"></div>
    <div class="relative">
      <div class="flex h-24 w-24 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container">
        <span class="material-symbols-outlined icon-fill text-[48px]">mic_external_on</span>
      </div>
    </div>
    <div class="relative space-y-2">
      <h2 class="font-headline-md text-headline-md text-primary">나만의 AI 목소리 만들기</h2>
      <p class="mx-auto max-w-xs font-body-md text-on-surface-variant">딱 2분만 녹음하면, 아이에게 언제든 당신의 목소리로 이야기를 들려줄 수 있어요.</p>
    </div>
    <?php if ($canCreate): ?>
      <a href="<?= e(url('/voice-lab/new')) ?>" class="relative flex items-center gap-2 rounded-full bg-secondary px-8 py-4 font-label-lg text-label-lg uppercase tracking-wider text-on-secondary transition-all <?= $tactile ?>">
        녹음 시작하기
      </a>
    <?php else: ?>
      <div class="relative space-y-3">
        <button type="button" disabled class="mx-auto flex cursor-not-allowed items-center gap-2 rounded-full bg-secondary px-8 py-4 font-label-lg text-label-lg uppercase tracking-wider text-on-secondary opacity-50">
          녹음 시작하기
        </button>
        <p class="flex items-start justify-center gap-1.5 text-label-sm text-on-surface-variant">
          <span class="material-symbols-outlined text-[16px]">info</span>
          <span>목소리는 최대 <?= (int) $max ?>개까지 만들 수 있어요. 새로 만들려면 쓰지 않는 목소리를 삭제해 주세요.</span>
        </p>
      </div>
    <?php endif; ?>
  </section>

  <!-- 연구실 현황 -->
  <section class="space-y-4">
    <div class="flex items-end justify-between">
      <h3 class="font-headline-md text-headline-md text-on-surface">연구실 현황</h3>
      <span class="font-label-sm text-label-sm uppercase tracking-widest text-outline"><?= (int) $max ?>개 중 <?= (int) $used ?>개 사용 중</span>
    </div>
    <div class="grid gap-4">
      <?php if (!$voices): ?>
        <div class="flex flex-col items-center rounded-[24px] border border-surface-container bg-surface-container-lowest px-6 py-8 text-center shadow-[0_10px_15px_-3px_rgba(0,0,0,0.05),0_4px_6px_-2px_rgba(0,0,0,0.02)]">
          <img src="<?= e(asset('img/empty-voices.svg')) ?>" alt="" class="mb-4 h-32 w-32 object-contain" width="128" height="128">
          <p class="font-label-lg text-label-lg text-on-surface">아직 만든 목소리가 없어요</p>
          <p class="mt-1 text-[14px] leading-5 text-on-surface-variant">엄마, 아빠, 할머니... 가족의 목소리를 녹음해<br>첫 번째 AI 목소리를 만들어 보세요.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($voices as $v): ?>
        <?= partial('user/voice_lab/_card', ['v' => $v]) ?>
      <?php endforeach; ?>
      <?php if ($canCreate): ?>
        <a href="<?= e(url('/voice-lab/new')) ?>" class="group flex flex-col items-center justify-center rounded-[24px] border-2 border-dashed border-outline-variant p-6 py-8 transition-colors hover:border-primary/50">
          <span class="material-symbols-outlined mb-2 text-outline-variant group-hover:text-primary">add_circle</span>
          <p class="font-label-lg text-outline">새 목소리 추가</p>
        </a>
      <?php endif; ?>
    </div>
  </section>

  <!-- 녹음 가이드 -->
  <section class="mb-8 space-y-6 rounded-[32px] bg-surface-container-low p-6">
    <h3 class="font-headline-md text-headline-md text-primary">최상의 품질을 위한 가이드</h3>
    <div class="space-y-4">
      <div class="flex items-start gap-4">
        <div class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm">
          <span class="material-symbols-outlined">volume_off</span>
        </div>
        <div>
          <h4 class="font-label-lg text-label-lg text-on-surface">조용한 장소를 찾으세요</h4>
          <p class="font-body-md text-sm text-on-surface-variant">팬 소리나 자동차 소음은 AI의 선명도를 떨어뜨릴 수 있습니다.</p>
        </div>
      </div>
      <div class="flex items-start gap-4">
        <div class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm">
          <span class="material-symbols-outlined">auto_stories</span>
        </div>
        <div>
          <h4 class="font-label-lg text-label-lg text-on-surface">자연스럽게 읽어주세요</h4>
          <p class="font-body-md text-sm text-on-surface-variant">지금 바로 아이에게 동화책을 읽어주는 것처럼 자연스럽게 말씀하세요.</p>
        </div>
      </div>
      <div class="flex items-start gap-4">
        <div class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm">
          <span class="material-symbols-outlined">distance</span>
        </div>
        <div>
          <h4 class="font-label-lg text-label-lg text-on-surface">일정한 거리 유지</h4>
          <p class="font-body-md text-sm text-on-surface-variant">스마트폰을 입에서 약 15cm 정도 띄운 상태를 유지하세요.</p>
        </div>
      </div>
    </div>
  </section>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/voice-lab.js')) ?>"></script>
<?php endsection(); ?>
