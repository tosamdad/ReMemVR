<?php
/** 1:1 문의 상세와 운영자 답변. 변수: $inquiry */
use App\Controllers\User\SupportController;

layout('user/layout', ['title' => '문의 내역', 'header' => 'sub', 'back' => '/settings/support#my-inquiries', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$chip = SupportController::statusChip((string) $inquiry['status']);
$answered = trim((string) $inquiry['answer']) !== '';
?>
<article class="card p-md">
  <header class="border-b border-surface-variant/30 pb-4">
    <div class="mb-2 flex items-center gap-2">
      <span class="rounded-full px-2.5 py-1 text-[11px] font-bold <?= $chip[1] ?>"><?= e($chip[0]) ?></span>
      <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e(SupportController::categoryLabel((string) $inquiry['category'])) ?></span>
    </div>
    <h2 class="break-words font-headline-md text-[20px] font-bold leading-7 text-on-surface"><?= e($inquiry['title']) ?></h2>
    <p class="mt-1 font-label-sm text-label-sm text-outline"><?= e(date('Y.m.d H:i', strtotime($inquiry['created_at']))) ?> 문의</p>
  </header>
  <div class="whitespace-pre-line break-words pt-4 font-body-md text-body-md leading-7 text-on-surface"><?= e(trim((string) $inquiry['body'])) ?></div>
</article>

<?php if ($answered): ?>
<section class="mt-6 rounded-[24px] border border-secondary/20 bg-secondary-container/50 p-md">
  <div class="mb-3 flex items-center gap-2">
    <span class="flex h-9 w-9 items-center justify-center rounded-full bg-secondary text-on-secondary"><span class="material-symbols-outlined icon-fill text-[20px]">support_agent</span></span>
    <div>
      <p class="font-label-lg text-label-lg text-on-secondary-container"><?= e(setting('app.brand', '르멤버')) ?> 고객 센터</p>
      <?php if (!empty($inquiry['answered_at'])): ?><p class="font-label-sm text-label-sm font-normal text-on-surface-variant"><?= e(date('Y.m.d H:i', strtotime($inquiry['answered_at']))) ?> 답변</p><?php endif; ?>
    </div>
  </div>
  <div class="whitespace-pre-line break-words font-body-md text-body-md leading-7 text-on-surface"><?= e(trim((string) $inquiry['answer'])) ?></div>
</section>
<?php else: ?>
<section class="mt-6 flex items-start gap-3 rounded-[24px] bg-tertiary-fixed p-md text-on-tertiary-fixed-variant">
  <span class="material-symbols-outlined">hourglass_top</span>
  <p class="font-body-md text-[15px] leading-6">문의를 잘 받았어요. 담당자가 확인한 뒤 이곳에 답변을 남겨 드릴게요.<?php $hours = trim((string) setting('support.hours', '')); if ($hours !== ''): ?><br><span class="font-label-sm text-label-sm">운영 시간: <?= e($hours) ?></span><?php endif; ?></p>
</section>
<?php endif; ?>

<a href="<?= e(url('/settings/support#my-inquiries')) ?>" class="btn-ghost mt-6 w-full"><span class="material-symbols-outlined text-[20px]">list</span>내 문의 내역</a>
