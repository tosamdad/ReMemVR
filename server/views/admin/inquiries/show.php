<?php
/**
 * 1:1 문의 상세와 답변. 변수: inq, user, children, voices, plays, others, answerer, canMail
 */
use App\Controllers\Admin\InquiryController;
use App\Services\MemberStats;

layout('admin/layout', ['title' => '1:1 문의 #' . (int) $inq['id'], 'active' => 'inquiries']);
$st = InquiryController::STATUS[$inq['status']] ?? [$inq['status'], 'bg-surface-container-high text-on-surface-variant'];
$hasAnswer = trim((string) $inq['answer']) !== '';
$answer = (string) old('answer', (string) $inq['answer']);
$withdrawn = $user && ($user['status'] === 'withdrawn' || !empty($user['deleted_at']));
$wait = (int) floor(((($inq['answered_at'] ? strtotime((string) $inq['answered_at']) : time())) - strtotime((string) $inq['created_at'])) / 60);
?>
<div class="flex w-full flex-col gap-6">
  <div class="flex flex-wrap items-center justify-between gap-3">
    <a href="<?= e(url('/admin/inquiries', $inq['status'] === 'open' ? [] : ['status' => $inq['status']])) ?>" class="flex items-center gap-1 font-label-md text-label-md text-on-surface-variant hover:text-primary"><span class="material-symbols-outlined text-[20px]">arrow_back</span>문의 목록</a>
    <div class="flex items-center gap-2">
      <?php if ($inq['status'] !== 'closed'): ?>
      <form method="post" action="<?= e(url('/admin/inquiries/' . (int) $inq['id'] . '/status')) ?>" data-confirm="문의를 종료할까요? 회원 화면에 '종료'로 보입니다.">
        <?= csrf_field() ?><input type="hidden" name="action" value="close">
        <button type="submit" class="a-btn-tonal"><span class="material-symbols-outlined text-[18px]">archive</span>문의 종료</button>
      </form>
      <?php else: ?>
      <form method="post" action="<?= e(url('/admin/inquiries/' . (int) $inq['id'] . '/status')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="reopen">
        <button type="submit" class="a-btn-tonal"><span class="material-symbols-outlined text-[18px]">unarchive</span>다시 열기</button>
      </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid grid-cols-12 items-start gap-6">
    <div class="col-span-12 flex flex-col gap-6 xl:col-span-8">
      <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-md">
        <div class="flex flex-wrap items-center gap-2">
          <span class="font-label-md text-label-md text-on-surface-variant">#<?= (int) $inq['id'] ?></span>
          <span class="a-chip bg-secondary-fixed text-on-secondary-fixed"><?= e(InquiryController::categoryLabel($inq['category'])) ?></span>
          <span class="a-chip <?= $st[1] ?>"><?= e($st[0]) ?></span>
          <span class="ml-auto font-label-sm text-label-sm text-on-surface-variant"><?= e(date('Y.m.d H:i', strtotime((string) $inq['created_at']))) ?> 접수<?= $inq['status'] === 'open' ? ' · ' . e(InquiryController::waitText($wait)) . ' 대기' : '' ?></span>
        </div>
        <h1 class="font-headline-md text-headline-md text-on-surface"><?= e($inq['title']) ?></h1>
        <div class="whitespace-pre-line break-words rounded-xl bg-surface-container-low p-5 font-body-md text-body-md leading-7 text-on-surface"><?= e(trim((string) $inq['body'])) ?></div>
      </section>

      <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-md" id="answer">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <h2 class="flex items-center gap-2 font-label-md text-label-md font-bold text-on-surface"><span class="material-symbols-outlined text-[20px] text-primary">support_agent</span><?= $hasAnswer ? '등록된 답변' : '답변 작성' ?></h2>
          <?php if ($hasAnswer && $inq['answered_at']): ?>
          <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($answerer !== null ? (string) $answerer : '삭제된 관리자') ?> · <?= e(date('Y.m.d H:i', strtotime((string) $inq['answered_at']))) ?> (접수 후 <?= e(InquiryController::waitText($wait)) ?>)</span>
          <?php endif; ?>
        </div>
        <form method="post" action="<?= e(url('/admin/inquiries/' . (int) $inq['id'])) ?>" class="flex flex-col gap-4" novalidate>
          <?= csrf_field() ?>
          <div>
            <label class="sr-only" for="inq-answer">답변</label>
            <textarea id="inq-answer" name="answer" rows="10" maxlength="<?= InquiryController::ANSWER_MAX ?>" required class="a-input font-body-md text-body-md leading-7<?= errors('answer') ? ' ring-2 ring-error' : '' ?>" placeholder="<?= e($user ? $user['name'] : '회원') ?>님, 안녕하세요. 문의 주셔서 감사해요."><?= e($answer) ?></textarea>
            <?php if (errors('answer')): ?><p class="mt-1 font-label-sm text-label-sm text-error" role="alert"><?= e(errors('answer')) ?></p><?php endif; ?>
            <p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">회원 앱에는 줄바꿈이 그대로 보입니다. 회원 화면 말투(해요체)로 적어 주세요. 최대 <?= e(fmt_number(InquiryController::ANSWER_MAX)) ?>자</p>
          </div>
          <div class="flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
            <?php if ($canMail): ?>
            <label class="flex min-w-0 cursor-pointer flex-wrap items-center gap-x-2 gap-y-1 font-label-md text-label-md text-on-surface">
              <input type="checkbox" name="notify" value="1" class="h-4 w-4 rounded border-outline text-primary focus:ring-primary/30"<?= $hasAnswer ? '' : ' checked' ?>>
              <span class="whitespace-nowrap">회원에게 이메일로 알리기</span> <span class="min-w-0 break-all font-label-sm text-label-sm text-on-surface-variant">(<?= e(mask_email((string) $user['email'])) ?>)</span>
            </label>
            <?php else: ?>
            <span class="font-label-sm text-label-sm text-on-surface-variant">탈퇴했거나 메일 주소가 없는 회원이라 메일 알림을 보낼 수 없습니다. 답변은 앱에서만 보입니다.</span>
            <?php endif; ?>
            <button type="submit" class="a-btn-primary shrink-0"><span class="material-symbols-outlined text-[18px]">send</span><?= $hasAnswer ? '답변 수정' : '답변 등록' ?></button>
          </div>
        </form>
      </section>
    </div>

    <aside class="col-span-12 flex flex-col gap-6 xl:sticky xl:top-24 xl:col-span-4">
      <section class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-md">
        <h3 class="font-label-md text-label-md font-bold text-on-surface">문의한 회원</h3>
        <?php if ($user): ?>
        <div class="flex items-center gap-3">
          <span class="flex h-11 w-11 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined">person</span></span>
          <div class="min-w-0">
            <p class="font-label-md text-label-md text-on-surface"><?= e($user['name']) ?> <span class="font-label-sm text-label-sm text-primary"><?= e(MemberStats::memberCode((int) $user['id'])) ?></span></p>
            <p class="truncate font-label-sm text-label-sm text-on-surface-variant"><?= e($user['email']) ?></p>
          </div>
        </div>
        <dl class="grid grid-cols-2 gap-3">
          <div class="rounded-lg bg-surface-container-low px-3 py-2"><dt class="font-label-sm text-label-sm text-on-surface-variant">상태</dt><dd class="font-label-md text-label-md <?= $withdrawn || $user['status'] === 'blocked' ? 'text-error' : 'text-on-surface' ?>"><?= $withdrawn ? '탈퇴' : ($user['status'] === 'blocked' ? '이용 정지' : '정상') ?></dd></div>
          <div class="rounded-lg bg-surface-container-low px-3 py-2"><dt class="font-label-sm text-label-sm text-on-surface-variant">가입일</dt><dd class="font-label-md text-label-md text-on-surface"><?= e(date('Y.m.d', strtotime((string) $user['created_at']))) ?></dd></div>
          <div class="rounded-lg bg-surface-container-low px-3 py-2"><dt class="font-label-sm text-label-sm text-on-surface-variant">목소리</dt><dd class="font-label-md text-label-md text-on-surface"><?= (int) $voices ?>개</dd></div>
          <div class="rounded-lg bg-surface-container-low px-3 py-2"><dt class="font-label-sm text-label-sm text-on-surface-variant">동화 재생</dt><dd class="font-label-md text-label-md text-on-surface"><?= e(fmt_number($plays)) ?>회</dd></div>
        </dl>
        <?php if ($user['phone']): ?><p class="font-label-sm text-label-sm text-on-surface-variant">연락처 <?= e(mask_phone((string) $user['phone'])) ?></p><?php endif; ?>
        <?php if ($children): ?>
        <div class="flex flex-wrap gap-1.5">
          <?php foreach ($children as $c): ?><span class="a-chip bg-surface-container-high text-on-surface"><?= e(MemberStats::childText($c)) ?></span><?php endforeach; ?>
        </div>
        <?php endif; ?>
        <a href="<?= e(url('/admin/members/' . (int) $user['id'])) ?>" class="a-btn-tonal w-full"><span class="material-symbols-outlined text-[18px]">monitoring</span>회원 상세, 대화 로그 보기</a>
        <?php else: ?>
        <p class="font-label-sm text-label-sm text-on-surface-variant">회원 정보를 찾을 수 없습니다.</p>
        <?php endif; ?>
      </section>

      <section class="flex flex-col gap-3 rounded-xl bg-surface-container-lowest p-6 shadow-md">
        <h3 class="font-label-md text-label-md font-bold text-on-surface">이 회원의 다른 문의</h3>
        <?php if (!$others): ?>
        <p class="font-label-sm text-label-sm text-on-surface-variant">다른 문의가 없습니다.</p>
        <?php endif; ?>
        <?php foreach ($others as $o): $os = InquiryController::STATUS[$o['status']] ?? [$o['status'], 'bg-surface-container-high text-on-surface-variant']; ?>
        <a href="<?= e(url('/admin/inquiries/' . (int) $o['id'])) ?>" class="flex items-center justify-between gap-3 rounded-lg bg-surface-container-low px-3 py-2.5 transition-colors hover:bg-surface-container-high">
          <span class="min-w-0">
            <span class="block truncate font-label-md text-label-md text-on-surface"><?= e($o['title']) ?></span>
            <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e(InquiryController::categoryLabel($o['category'])) ?> · <?= e(date('Y.m.d', strtotime((string) $o['created_at']))) ?></span>
          </span>
          <span class="a-chip shrink-0 <?= $os[1] ?>"><?= e($os[0]) ?></span>
        </a>
        <?php endforeach; ?>
      </section>
    </aside>
  </div>
</div>
