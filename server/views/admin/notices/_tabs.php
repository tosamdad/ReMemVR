<?php
/**
 * 공지사항, FAQ 공통 머리글과 탭. 변수: tab(notices|faqs), counts(notices, faqs), action(오른쪽 버튼 HTML, 선택)
 */
$tabs = [
    'notices' => ['campaign', '공지사항', '/admin/notices'],
    'faqs' => ['quiz', '자주 묻는 질문', '/admin/faqs'],
];
?>
<div class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
  <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-3">
        <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Operations</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant">회원 앱의 설정 → 공지사항, 고객 센터에 그대로 보입니다</span>
      </div>
      <h1 class="font-headline-md text-headline-md text-on-surface">공지사항, 자주 묻는 질문</h1>
    </div>
    <?php if (!empty($action)): ?><div class="flex flex-wrap items-center gap-3"><?= $action ?></div><?php endif; ?>
  </div>
  <nav class="flex gap-6 border-b border-surface-container-high" aria-label="운영 콘텐츠">
    <?php foreach ($tabs as $key => $t): $on = $key === $tab; ?>
    <a href="<?= e(url($t[2])) ?>" class="-mb-px flex items-center gap-2 border-b-2 px-1 pb-3 font-label-md text-label-md transition-colors <?= $on ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
      <span class="material-symbols-outlined text-[18px]"><?= $t[0] ?></span><?= e($t[1]) ?>
      <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $on ? 'bg-primary-fixed text-primary' : 'bg-surface-container-high text-on-surface-variant' ?>"><?= (int) $counts[$key] ?></span>
    </a>
    <?php endforeach; ?>
  </nav>
</div>
