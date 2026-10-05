<?php
/** 방문자 소개 화면. 변수: $storyCount, $sampleSeconds, $maxQuestions(0 이면 질문 기능 꺼짐), $maxVoices */
layout('user/layout', ['title' => '', 'showNav' => false, 'mainClass' => 'px-margin-mobile pt-6 pb-12 break-keep']);
$brand = setting('app.brand', '르멤버');
$minutes = max(1, (int) round($sampleSeconds / 60));
$values = [
    ['record_voice_over', 'bg-primary-container text-on-primary-container', '가족 목소리로 듣는 동화', '엄마, 아빠, 할머니의 목소리를 약 ' . $minutes . '분만 녹음하면 모든 동화를 그 목소리로 들려줘요.'],
    ['forum', 'bg-secondary-container text-on-secondary-container', '궁금한 건 바로 물어보는 대화형 동화', '이야기 중간에 아이가 말로 물어보면 같은 목소리로 다정하게 대답하고, 이야기를 이어서 들려줘요.'],
    ['monitoring', 'bg-tertiary-container text-on-tertiary-container', '우리 아이 성장 리포트', '어떤 동화를 좋아하고 무엇을 궁금해했는지, 아이의 호기심이 자라는 모습을 한눈에 볼 수 있어요.'],
];
$steps = [
    ['mic', '목소리 녹음', '안내 문장을 따라 편하게 읽어 주세요.'],
    ['verified', '목소리 준비', '녹음을 마치면 AI가 바로 목소리를 만들어요.'],
    ['auto_stories', '함께 듣기', '아이가 듣다가 궁금한 건 바로 물어봐요.'],
];
// 질문 기능이 꺼져 있으면(qa_available) 대화형 동화 소개 대신 읽기 기능을 소개한다.
if ((int) $maxQuestions <= 0) {
    $values[1] = ['menu_book', 'bg-secondary-container text-on-secondary-container', '한 글자씩 따라 읽는 동화', '읽어 주는 낱말이 화면에서 차례로 빛나서 아이가 글자와 소리를 함께 익혀요.'];
    $values[2][3] = '어떤 동화를 좋아하고 얼마나 들었는지, 새로 만난 낱말까지 한눈에 볼 수 있어요.';
    $steps[2] = ['auto_stories', '함께 듣기', '아이가 좋아하는 가족 목소리로 언제든 들어요.'];
}
?>
<section class="relative isolate text-center">
  <div class="pointer-events-none absolute -inset-x-margin-mobile -top-6 bottom-0 -z-10 overflow-hidden" aria-hidden="true">
    <div class="absolute -right-12 top-4 h-64 w-64 rounded-full bg-secondary-container opacity-40 blur-[80px]"></div>
    <div class="absolute -left-16 top-48 h-72 w-72 rounded-full bg-primary-container opacity-40 blur-[90px]"></div>
  </div>
  <div class="mx-auto flex aspect-[4/3] w-full max-w-sm items-center justify-center overflow-hidden rounded-[32px] bg-surface-container-lowest/70 shadow-[0_20px_40px_-15px_rgba(128,80,98,0.15)]">
    <img src="<?= e(asset('img/hero-family.svg')) ?>" alt="가족이 아이에게 동화를 읽어 주는 그림" class="h-full w-full object-contain" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'material-symbols-outlined icon-fill text-[96px] text-primary-container',textContent:'family_restroom'}))">
  </div>
  <p class="mt-8 inline-flex items-center gap-1 rounded-full bg-primary-fixed px-3 py-1 font-label-sm text-label-sm text-on-primary-fixed-variant">
    <span class="material-symbols-outlined icon-fill text-[16px]">favorite</span><?= e($brand) ?>
  </p>
  <h1 class="mt-3 font-headline-xl-mobile text-headline-xl-mobile tracking-tight text-on-background">아이에게 들려주는<br>세상에서 가장<br>따뜻한 목소리</h1>
  <p class="mx-auto mt-3 max-w-[320px] font-body-md text-body-md text-on-surface-variant">
    바쁜 날에도, 멀리 있는 날에도<br>가족의 목소리로 동화를 읽어 주세요.
  </p>
  <div class="mx-auto mt-8 grid max-w-sm gap-3">
    <a href="<?= e(url('/signup')) ?>" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all hover:brightness-110 active:translate-y-0.5 active:shadow-[0_1px_0_0_rgba(0,0,0,0.1)]">
      시작하기<span class="material-symbols-outlined text-[20px]">arrow_forward</span>
    </a>
    <a href="<?= e(url('/login')) ?>" class="flex min-h-12 w-full items-center justify-center rounded-full border-2 border-surface-variant bg-surface-container-lowest py-3.5 font-label-lg text-label-lg text-primary transition-all active:scale-[0.98]">
      로그인
    </a>
  </div>
</section>

<section class="mt-14 space-y-4" aria-labelledby="values-title">
  <h2 id="values-title" class="px-1 font-headline-md text-headline-md text-on-surface"><?= e($brand) ?>와 함께라면</h2>
  <?php foreach ($values as $v): ?>
  <article class="flex items-start gap-4 rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest p-md shadow-[0_4px_20px_0_rgba(0,0,0,0.05)]">
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl <?= $v[1] ?>"><span class="material-symbols-outlined icon-fill"><?= e($v[0]) ?></span></span>
    <div>
      <h3 class="font-headline-md text-[18px] font-bold leading-6 text-on-surface"><?= e($v[2]) ?></h3>
      <p class="mt-1 font-body-md text-[15px] leading-6 text-on-surface-variant"><?= e($v[3]) ?></p>
    </div>
  </article>
  <?php endforeach; ?>
</section>

<section class="mt-12 rounded-[32px] bg-surface-container-low p-md" aria-labelledby="steps-title">
  <h2 id="steps-title" class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">이렇게 시작해요</h2>
  <ol class="mt-4 space-y-4">
    <?php foreach ($steps as $i => $s): ?>
    <li class="flex items-center gap-4">
      <span class="relative flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface-container-lowest text-primary shadow-sm">
        <span class="material-symbols-outlined"><?= e($s[0]) ?></span>
        <span class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full bg-primary text-[11px] font-bold text-on-primary"><?= $i + 1 ?></span>
      </span>
      <span>
        <span class="block font-label-lg text-label-lg text-on-surface"><?= e($s[1]) ?></span>
        <span class="block font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant"><?= e($s[2]) ?></span>
      </span>
    </li>
    <?php endforeach; ?>
  </ol>
  <ul class="mt-6 flex flex-wrap gap-2">
    <?php if ($storyCount > 0): ?>
    <li class="rounded-full bg-surface-container-lowest px-3 py-1.5 font-label-sm text-label-sm text-on-surface-variant">무료 동화 <?= (int) $storyCount ?>편</li>
    <?php endif; ?>
    <?php if ($maxQuestions > 0): ?>
    <li class="rounded-full bg-surface-container-lowest px-3 py-1.5 font-label-sm text-label-sm text-on-surface-variant">동화마다 질문 <?= (int) $maxQuestions ?>번</li>
    <?php endif; ?>
    <?php if ($maxVoices > 1): ?>
    <li class="rounded-full bg-surface-container-lowest px-3 py-1.5 font-label-sm text-label-sm text-on-surface-variant">가족 목소리 최대 <?= (int) $maxVoices ?>개</li>
    <?php endif; ?>
  </ul>
</section>

<footer class="mt-12 space-y-2 text-center">
  <p class="font-label-sm text-label-sm text-on-surface-variant">
    <a href="<?= e(url('/settings/terms')) ?>" class="hover:text-primary hover:underline">이용약관</a>
    <span class="mx-2 text-outline-variant">|</span>
    <a href="<?= e(url('/settings/privacy')) ?>" class="font-bold hover:text-primary hover:underline">개인정보 처리방침</a>
  </p>
  <p class="font-label-sm text-label-sm font-normal text-outline">© <?= date('Y') ?> <?= e($brand) ?></p>
</footer>
