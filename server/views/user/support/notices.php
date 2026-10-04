<?php
/** 공지사항 목록(고정 공지 먼저). 변수: $notices, $page, $hasMore */
layout('user/layout', ['title' => '공지사항', 'header' => 'sub', 'back' => '/settings', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
?>
<?php if (!$notices): ?>
  <div class="card flex flex-col items-center gap-4 p-lg text-center">
    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container"><span class="material-symbols-outlined icon-fill text-[32px]">campaign</span></span>
    <p class="font-body-md text-body-md text-on-surface-variant">아직 등록된 공지사항이 없어요.<br>새 소식이 생기면 이곳에 알려 드릴게요.</p>
  </div>
<?php else: ?>
  <div class="overflow-hidden rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest shadow-[0_4px_20px_0_rgba(0,0,0,0.05)]">
    <?php foreach ($notices as $i => $n): $recent = strtotime($n['posted_at']) >= time() - 7 * 86400; ?>
    <a href="<?= e(url('/settings/notices/' . (int) $n['id'])) ?>" class="flex items-center gap-3 p-md transition-colors hover:bg-surface-container<?= $i < count($notices) - 1 ? ' border-b border-surface-variant/30' : '' ?>">
      <span class="min-w-0 flex-1">
        <span class="flex items-center gap-2">
          <?php if ((int) $n['is_pinned'] === 1): ?><span class="shrink-0 rounded-full bg-primary-container px-2 py-0.5 text-[11px] font-bold text-on-primary-container">중요</span><?php endif; ?>
          <span class="truncate font-body-md text-body-md font-semibold text-on-surface"><?= e($n['title']) ?></span>
          <?php if ($recent): ?><span class="h-2 w-2 shrink-0 rounded-full bg-primary" aria-label="새 공지"></span><?php endif; ?>
        </span>
        <span class="mt-1 block truncate font-label-sm text-label-sm font-normal text-on-surface-variant"><?= e(str_limit(preg_replace('/\s+/u', ' ', (string) $n['body']), 60)) ?></span>
        <span class="mt-1 block font-label-sm text-label-sm text-outline"><?= e(date('Y.m.d', strtotime($n['posted_at']))) ?></span>
      </span>
      <span class="material-symbols-outlined text-outline-variant">chevron_right</span>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($page > 1 || $hasMore): ?>
  <nav class="mt-6 flex items-center justify-between" aria-label="페이지">
    <?php if ($page > 1): ?><a href="<?= e(url('/settings/notices', ['page' => $page - 1])) ?>" class="btn-ghost"><span class="material-symbols-outlined text-[20px]">chevron_left</span>이전</a><?php else: ?><span></span><?php endif; ?>
    <?php if ($hasMore): ?><a href="<?= e(url('/settings/notices', ['page' => $page + 1])) ?>" class="btn-ghost">다음<span class="material-symbols-outlined text-[20px]">chevron_right</span></a><?php endif; ?>
  </nav>
  <?php endif; ?>
<?php endif; ?>
