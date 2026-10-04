<?php
/**
 * 공지 작성, 수정. 변수: notice(id 가 null 이면 새 글), tabCounts
 */
use App\Controllers\Admin\NoticeController;

$isNew = $notice['id'] === null;
layout('admin/layout', ['title' => $isNew ? '새 공지 작성' : '공지 수정', 'active' => 'notices']);
$action = $isNew ? url('/admin/notices') : url('/admin/notices/' . (int) $notice['id']);
$title = (string) old('title', $notice['title']);
$body = (string) old('body', $notice['body']);
$status = (string) old('status', $notice['status']);
$hasOld = old('title', null) !== null;
$pinned = $hasOld ? in_array((string) old('is_pinned', ''), ['1', 'on'], true) : (bool) $notice['is_pinned'];
$at = old('published_at', null);
if ($at === null) {
    $at = $notice['published_at'] ? date('Y-m-d\TH:i', strtotime((string) $notice['published_at'])) : '';
}
$state = $isNew ? null : NoticeController::stateOf($notice);
$err = static function (string $key) {
    $m = errors($key);

    return $m ? '<p class="mt-1 font-label-sm text-label-sm text-error" role="alert">' . e($m) . '</p>' : '';
};
?>
<div class="flex w-full flex-col gap-6">
  <?= partial('admin/notices/_tabs', ['tab' => 'notices', 'counts' => $tabCounts]) ?>

  <form method="post" action="<?= e($action) ?>" class="grid grid-cols-12 items-start gap-6" novalidate>
    <?= csrf_field() ?>
    <section class="col-span-12 flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md xl:col-span-8">
      <div class="flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <a href="<?= e(url('/admin/notices')) ?>" class="flex h-9 w-9 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high" aria-label="목록으로"><span class="material-symbols-outlined">arrow_back</span></a>
          <h2 class="font-headline-md text-headline-md text-on-surface"><?= $isNew ? '새 공지 작성' : '공지 수정' ?></h2>
          <?php if ($state): ?><span class="a-chip <?= $state[1] ?>"><?= e($state[0]) ?></span><?php endif; ?>
        </div>
        <?php if (!$isNew): ?><span class="font-label-sm text-label-sm text-on-surface-variant">마지막 수정 <?= e(date('Y.m.d H:i', strtotime((string) $notice['updated_at']))) ?></span><?php endif; ?>
      </div>
      <div>
        <label class="a-label" for="notice-title">제목</label>
        <input id="notice-title" name="title" type="text" value="<?= e($title) ?>" maxlength="<?= NoticeController::TITLE_MAX ?>" required class="a-input<?= errors('title') ? ' ring-2 ring-error' : '' ?>" placeholder="예) 10월 12일 새벽 서버 점검 안내">
        <?= $err('title') ?>
      </div>
      <div>
        <label class="a-label" for="notice-body">본문</label>
        <textarea id="notice-body" name="body" rows="16" required class="a-input font-body-md text-body-md leading-relaxed<?= errors('body') ? ' ring-2 ring-error' : '' ?>" placeholder="회원 앱에는 줄바꿈이 그대로 보입니다."><?= e($body) ?></textarea>
        <p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">글자만 쓸 수 있습니다(서식, 링크 미지원). 최대 <?= e(fmt_number(NoticeController::BODY_MAX)) ?>자</p>
        <?= $err('body') ?>
      </div>
    </section>

    <aside class="col-span-12 flex flex-col gap-6 xl:sticky xl:top-24 xl:col-span-4">
      <section class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md">
        <h3 class="font-label-md text-label-md font-bold text-on-surface">게시 설정</h3>
        <fieldset>
          <legend class="a-label">상태</legend>
          <div class="grid grid-cols-2 gap-2">
            <?php foreach (['published' => ['게시', 'visibility'], 'draft' => ['임시 저장', 'edit_note']] as $k => $s): ?>
            <label class="flex cursor-pointer items-center gap-2 rounded-xl border-2 px-3 py-2.5 font-label-md text-label-md transition-colors has-[:checked]:border-primary has-[:checked]:bg-primary-fixed/60 has-[:checked]:text-primary has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-primary/40 border-surface-container-high text-on-surface-variant">
              <input type="radio" name="status" value="<?= $k ?>" class="sr-only"<?= $status === $k ? ' checked' : '' ?>>
              <span class="material-symbols-outlined text-[18px]"><?= $s[1] ?></span><?= e($s[0]) ?>
            </label>
            <?php endforeach; ?>
          </div>
        </fieldset>
        <div>
          <label class="a-label" for="notice-at">게시 시각</label>
          <input id="notice-at" name="published_at" type="datetime-local" value="<?= e((string) $at) ?>" class="a-input<?= errors('published_at') ? ' ring-2 ring-error' : '' ?>">
          <p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">비워 두면 저장하는 즉시 게시합니다. 미래 시각이면 그때부터 회원에게 보입니다(예약).</p>
          <?= $err('published_at') ?>
        </div>
        <label class="flex items-center justify-between gap-4 rounded-xl bg-surface-container-low p-4" for="notice-pin">
          <span>
            <span class="block font-label-md text-label-md text-on-surface">상단 고정</span>
            <span class="block font-label-sm text-label-sm text-on-surface-variant">목록 맨 위에 핀과 함께 보입니다.</span>
          </span>
          <span class="switch"><input id="notice-pin" type="checkbox" name="is_pinned" value="1"<?= $pinned ? ' checked' : '' ?>><span></span></span>
        </label>
        <div class="flex flex-col gap-2">
          <button type="submit" class="a-btn-primary w-full"><span class="material-symbols-outlined text-[18px]">save</span><?= $isNew ? '공지 저장' : '변경사항 저장' ?></button>
          <a href="<?= e(url('/admin/notices')) ?>" class="a-btn-tonal w-full">취소</a>
        </div>
      </section>
      <?php if (!$isNew): ?>
      <section class="flex items-center justify-between gap-3 rounded-xl bg-surface-container-lowest p-5 shadow-md">
        <div>
          <p class="font-label-md text-label-md text-on-surface">공지 삭제</p>
          <p class="font-label-sm text-label-sm text-on-surface-variant">삭제하면 되돌릴 수 없습니다. 잠시 내리려면 임시 저장으로 바꾸세요.</p>
        </div>
        <button type="submit" form="notice-delete-form" class="a-btn-danger shrink-0"><span class="material-symbols-outlined text-[18px]">delete</span>삭제</button>
      </section>
      <?php endif; ?>
    </aside>
  </form>
  <?php if (!$isNew): ?>
  <form id="notice-delete-form" method="post" action="<?= e(url('/admin/notices/' . (int) $notice['id'] . '/delete')) ?>" class="hidden" data-confirm="이 공지를 삭제할까요? 회원 화면에서도 바로 사라집니다."><?= csrf_field() ?></form>
  <?php endif; ?>
</div>
