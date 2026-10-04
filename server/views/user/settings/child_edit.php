<?php
/** 아이 추가, 수정. 변수: $child(null 이면 새로 만들기), $values, $canDelete */
$isNew = $child === null;
layout('user/layout', ['title' => $isNew ? '아이 추가' : '아이 프로필 수정', 'header' => 'sub', 'back' => '/settings/children', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$action = $isNew ? '/settings/children' : '/settings/children/' . (int) $child['id'];
?>
<form method="post" action="<?= e(url($action)) ?>" class="card space-y-6 p-md" novalidate>
  <?= csrf_field() ?>
  <?= partial('user/settings/child_fields', ['values' => $values]) ?>
  <button type="submit" class="btn-primary w-full rounded-full"><?= $isNew ? '아이 추가하기' : '저장하기' ?></button>
</form>

<?php if (!$isNew): ?>
<section class="mt-8 rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest p-md">
  <h3 class="font-label-lg text-label-lg text-on-surface">프로필 삭제</h3>
  <?php if (!empty($canDelete)): ?>
    <p class="mt-1 font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant">아이 프로필을 지워도 지난 재생 기록은 남지만, 이 아이의 리포트에서는 보이지 않아요.</p>
    <form method="post" action="<?= e(url('/settings/children/' . (int) $child['id'] . '/delete')) ?>" data-confirm="<?= e($child['name']) ?> 프로필을 삭제할까요?" class="mt-4">
      <?= csrf_field() ?>
      <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-error-container py-3 font-label-lg text-label-lg text-on-error-container active:scale-[0.98]">
        <span class="material-symbols-outlined text-[20px]">delete</span>프로필 삭제
      </button>
    </form>
  <?php else: ?>
    <p class="mt-1 font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant">아이 프로필이 하나뿐이라 삭제할 수 없어요. 다른 아이를 먼저 추가하면 삭제할 수 있어요.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
