<?php
/** 공지사항 본문. 변수: $notice */
layout('user/layout', ['title' => $notice['title'], 'header' => 'sub', 'headerTitle' => '공지사항', 'back' => '/settings/notices']);
?>
<article class="card p-md">
  <header class="border-b border-surface-variant/30 pb-4">
    <?php if ((int) $notice['is_pinned'] === 1): ?><span class="mb-2 inline-block rounded-full bg-primary-container px-2 py-0.5 text-[11px] font-bold text-on-primary-container">중요</span><?php endif; ?>
    <h2 class="font-headline-md text-[22px] font-bold leading-8 text-on-surface"><?= e($notice['title']) ?></h2>
    <p class="mt-1 font-label-sm text-label-sm text-outline"><?= e(date('Y년 n월 j일', strtotime($notice['posted_at']))) ?></p>
  </header>
  <div class="whitespace-pre-line break-words pt-4 font-body-md text-body-md leading-7 text-on-surface"><?= e(trim((string) $notice['body'])) ?></div>
</article>
<a href="<?= e(url('/settings/notices')) ?>" class="btn-ghost mt-6 w-full"><span class="material-symbols-outlined text-[20px]">list</span>목록으로</a>
