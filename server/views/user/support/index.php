<?php
/** 고객 센터: 연락처, 자주 묻는 질문, 1:1 문의 작성과 내 문의 내역. 변수: $user, $faqs, $inquiries, $categories, $contact */
use App\Controllers\User\SupportController;

layout('user/layout', ['title' => '고객 센터', 'header' => 'sub', 'back' => '/settings', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$heading = 'mb-3 px-2 text-[14px] font-bold leading-5 text-primary';
$card = 'overflow-hidden rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest shadow-[0_4px_20px_0_rgba(0,0,0,0.05)]';
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$hasContact = $contact['email'] !== '' || $contact['phone'] !== '';
?>
<div class="space-y-8">
  <section class="rounded-[24px] bg-secondary-container p-md text-on-secondary-container">
    <div class="flex items-center gap-3">
      <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-surface-container-lowest/60"><span class="material-symbols-outlined icon-fill">support_agent</span></span>
      <div>
        <h2 class="font-headline-md text-[20px] font-bold leading-7">무엇을 도와드릴까요?</h2>
        <p class="font-label-sm text-label-sm font-normal">자주 묻는 질문에서 먼저 찾아보고, 없으면 1:1 문의를 남겨 주세요.</p>
      </div>
    </div>
    <?php if ($hasContact || $contact['hours'] !== ''): ?>
    <dl class="mt-4 space-y-2 rounded-2xl bg-surface-container-lowest/60 p-4 font-body-md text-[15px]">
      <?php if ($contact['email'] !== ''): ?>
      <div class="flex items-center gap-3"><dt class="material-symbols-outlined text-[20px]" aria-label="이메일">mail</dt><dd><a href="mailto:<?= e($contact['email']) ?>" class="font-semibold underline-offset-2 hover:underline"><?= e($contact['email']) ?></a></dd></div>
      <?php endif; ?>
      <?php if ($contact['phone'] !== ''): ?>
      <div class="flex items-center gap-3"><dt class="material-symbols-outlined text-[20px]" aria-label="전화">call</dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $contact['phone'])) ?>" class="font-semibold underline-offset-2 hover:underline"><?= e($contact['phone']) ?></a></dd></div>
      <?php endif; ?>
      <?php if ($contact['hours'] !== ''): ?>
      <div class="flex items-center gap-3"><dt class="material-symbols-outlined text-[20px]" aria-label="운영 시간">schedule</dt><dd><?= e($contact['hours']) ?></dd></div>
      <?php endif; ?>
    </dl>
    <?php endif; ?>
  </section>

  <section>
    <h3 class="<?= $heading ?>">자주 묻는 질문</h3>
    <?php if (!$faqs): ?>
      <div class="<?= $card ?> p-md text-center font-body-md text-body-md text-on-surface-variant">아직 정리된 질문이 없어요. 궁금한 점은 아래 1:1 문의로 남겨 주세요.</div>
    <?php else: ?>
    <div class="<?= $card ?>">
      <?php foreach ($faqs as $i => $f): ?>
      <details class="group<?= $i < count($faqs) - 1 ? ' border-b border-surface-variant/30' : '' ?>">
        <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-md transition-colors hover:bg-surface-container [&::-webkit-details-marker]:hidden">
          <span class="flex min-w-0 items-start gap-3">
            <span class="font-headline-md text-[18px] font-bold leading-6 text-primary">Q</span>
            <span class="min-w-0">
              <?php if (!empty($f['category'])): ?><span class="mb-0.5 block font-label-sm text-label-sm text-on-surface-variant"><?= e($f['category']) ?></span><?php endif; ?>
              <span class="block font-body-md text-body-md text-on-surface"><?= e($f['question']) ?></span>
            </span>
          </span>
          <span class="material-symbols-outlined shrink-0 text-outline-variant transition-transform group-open:rotate-180">expand_more</span>
        </summary>
        <div class="mx-md mb-md whitespace-pre-line break-words rounded-2xl bg-surface-container-low p-4 font-body-md text-[15px] leading-6 text-on-surface-variant"><?= e(trim((string) $f['answer'])) ?></div>
      </details>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>

  <section id="inquiry-form" class="scroll-mt-20">
    <h3 class="<?= $heading ?>">1:1 문의하기</h3>
    <form method="post" action="<?= e(url('/settings/support/inquiries')) ?>" class="card space-y-5 p-md" novalidate>
      <?= csrf_field() ?>
      <div class="space-y-1.5">
        <label for="category" class="<?= $label ?>">문의 종류</label>
        <select id="category" name="category" required class="<?= $input ?> <?= errors('category') ? 'border-error' : 'border-surface-variant' ?>">
          <option value="">골라 주세요</option>
          <?php foreach ($categories as $k => $v): ?>
          <option value="<?= e($k) ?>"<?= old('category') === $k ? ' selected' : '' ?>><?= e($v) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (errors('category')): ?><p class="field-error ml-2" role="alert"><?= e(errors('category')) ?></p><?php endif; ?>
      </div>
      <div class="space-y-1.5">
        <label for="title" class="<?= $label ?>">제목</label>
        <input id="title" name="title" type="text" maxlength="100" required value="<?= e(old('title')) ?>" placeholder="예) 아빠 목소리가 아직 준비 중이에요" class="<?= $input ?> <?= errors('title') ? 'border-error' : 'border-surface-variant' ?>">
        <?php if (errors('title')): ?><p class="field-error ml-2" role="alert"><?= e(errors('title')) ?></p><?php endif; ?>
      </div>
      <div class="space-y-1.5">
        <label for="body" class="<?= $label ?>">내용</label>
        <textarea id="body" name="body" rows="6" maxlength="3000" required placeholder="어떤 화면에서 무엇이 불편했는지 알려 주시면 더 빨리 도와드릴 수 있어요." class="<?= $input ?> py-3 <?= errors('body') ? 'border-error' : 'border-surface-variant' ?>"><?= e(old('body')) ?></textarea>
        <?php if (errors('body')): ?><p class="field-error ml-2" role="alert"><?= e(errors('body')) ?></p><?php else: ?><p class="ml-2 font-label-sm text-label-sm text-on-surface-variant">답변은 이 화면의 ‘내 문의 내역’에서 확인할 수 있어요.</p><?php endif; ?>
      </div>
      <button type="submit" class="btn-primary w-full rounded-full"><span class="material-symbols-outlined text-[20px]">send</span>문의 보내기</button>
    </form>
  </section>

  <section id="my-inquiries">
    <h3 class="<?= $heading ?>">내 문의 내역</h3>
    <?php if (!$inquiries): ?>
      <div class="<?= $card ?> flex flex-col items-center gap-2 p-md text-center">
        <span class="material-symbols-outlined text-[32px] text-outline-variant">forum</span>
        <p class="font-body-md text-body-md text-on-surface-variant">아직 남긴 문의가 없어요.</p>
      </div>
    <?php else: ?>
    <div class="<?= $card ?>">
      <?php foreach ($inquiries as $i => $q): $chip = SupportController::statusChip((string) $q['status']); ?>
      <a href="<?= e(url('/settings/support/inquiries/' . (int) $q['id'])) ?>" class="flex items-center gap-3 p-md transition-colors hover:bg-surface-container<?= $i < count($inquiries) - 1 ? ' border-b border-surface-variant/30' : '' ?>">
        <span class="min-w-0 flex-1">
          <span class="block truncate font-body-md text-body-md text-on-surface"><?= e($q['title']) ?></span>
          <span class="mt-1 block font-label-sm text-label-sm font-normal text-on-surface-variant"><?= e(SupportController::categoryLabel((string) $q['category'])) ?> · <?= e(date('Y.m.d', strtotime($q['created_at']))) ?></span>
        </span>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-bold <?= $chip[1] ?>"><?= e($chip[0]) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </section>
</div>
