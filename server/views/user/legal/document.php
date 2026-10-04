<?php
/** 이용약관, 개인정보 처리방침 화면. 변수: $kind(terms|privacy), $docTitle, $blocks, $loggedIn */
layout('user/layout', ['title' => $docTitle, 'header' => 'sub', 'back' => $loggedIn ? '/settings' : null]);
$other = $kind === 'terms' ? ['/settings/privacy', '개인정보 처리방침'] : ['/settings/terms', '이용약관'];
?>
<article class="card p-md">
  <header class="mb-2 flex items-center gap-3 border-b border-surface-variant/30 pb-4">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl <?= $kind === 'terms' ? 'bg-primary-fixed text-on-primary-fixed-variant' : 'bg-secondary-fixed text-on-secondary-fixed-variant' ?>">
      <span class="material-symbols-outlined icon-fill"><?= $kind === 'terms' ? 'description' : 'shield_person' ?></span>
    </span>
    <h2 class="font-headline-md text-[22px] font-bold leading-8 text-on-surface"><?= e($docTitle) ?></h2>
  </header>
  <div class="space-y-5 pt-2">
    <?php foreach ($blocks as $b): ?>
    <section>
      <?php if ($b['heading'] !== null): ?>
        <h3 class="mb-1.5 font-label-lg text-[15px] leading-6 text-on-surface"><?= e($b['heading']) ?></h3>
      <?php endif; ?>
      <?php foreach ($b['lines'] as $line): ?>
        <p class="break-words font-body-md text-[15px] leading-7 text-on-surface-variant"><?= e($line) ?></p>
      <?php endforeach; ?>
    </section>
    <?php endforeach; ?>
  </div>
</article>
<a href="<?= e(url($other[0])) ?>" class="btn-ghost mt-6 w-full"><?= e($other[1]) ?> 보기<span class="material-symbols-outlined text-[20px]">chevron_right</span></a>
