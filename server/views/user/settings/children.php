<?php
/** 아이 프로필 목록. 변수: $children, $currentId, $canAdd */
use App\Controllers\User\ChildrenController;

layout('user/layout', ['title' => '아이 프로필 설정', 'header' => 'sub', 'back' => '/settings']);
?>
<p class="mb-6 px-1 font-body-md text-body-md text-on-surface-variant">지금 듣는 아이를 고르면 재생 기록과 리포트가 그 아이 기준으로 쌓여요.</p>

<?php if (!$children): ?>
  <div class="card flex flex-col items-center gap-4 p-lg text-center">
    <span class="flex h-16 w-16 items-center justify-center rounded-full bg-tertiary-fixed text-on-tertiary-fixed-variant"><span class="material-symbols-outlined icon-fill text-[32px]">child_care</span></span>
    <p class="font-body-md text-body-md text-on-surface-variant">아직 등록된 아이가 없어요.<br>아이 프로필을 만들어 이야기를 시작해 보세요.</p>
    <a href="<?= e(url('/settings/children/new')) ?>" class="btn-primary rounded-full"><span class="material-symbols-outlined text-[20px]">add</span>아이 추가하기</a>
  </div>
<?php else: ?>
  <ul class="space-y-3">
    <?php foreach ($children as $c): $selected = (int) $c['id'] === (int) $currentId; $age = child_age($c); $gender = ChildrenController::genderLabel($c['gender']); ?>
    <li class="rounded-[24px] border bg-surface-container-lowest p-4 shadow-[0_4px_20px_0_rgba(0,0,0,0.05)] <?= $selected ? 'border-primary/60 ring-2 ring-primary/20' : 'border-surface-variant/30' ?>">
      <div class="flex items-center gap-4">
        <?= child_avatar($c, 'w-14 h-14 text-3xl') ?>
        <div class="min-w-0 flex-1">
          <p class="flex items-center gap-2">
            <span class="truncate font-headline-md text-[18px] font-bold leading-6 text-on-surface"><?= e($c['name']) ?></span>
            <?php if ($selected): ?><span class="shrink-0 rounded-full bg-primary-container px-2 py-0.5 text-[11px] font-bold text-on-primary-container">지금 듣는 중</span><?php endif; ?>
          </p>
          <p class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant">
            <?= $age !== null ? '만 ' . (int) $age . '세' : '나이 미입력' ?><?= $gender !== '' ? ' · ' . e($gender) : '' ?><?= !empty($c['birth_date']) ? ' · ' . e(date('Y.m.d', strtotime($c['birth_date']))) . '생' : '' ?>
          </p>
        </div>
        <a href="<?= e(url('/settings/children/' . (int) $c['id'] . '/edit')) ?>" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full text-outline hover:bg-surface-container-low" aria-label="<?= e($c['name']) ?> 수정">
          <span class="material-symbols-outlined">edit</span>
        </a>
      </div>
      <?php if (!$selected): ?>
      <form method="post" action="<?= e(url('/settings/children/' . (int) $c['id'] . '/select')) ?>" class="mt-3">
        <?= csrf_field() ?>
        <button type="submit" class="flex w-full items-center justify-center gap-1 rounded-xl bg-surface-container-low py-2.5 font-label-lg text-label-lg text-primary transition-all hover:bg-surface-container active:scale-[0.98]">
          <span class="material-symbols-outlined text-[18px]">swap_horiz</span><?= e($c['name']) ?>(으)로 바꾸기
        </button>
      </form>
      <?php endif; ?>
    </li>
    <?php endforeach; ?>
  </ul>

  <?php if ($canAdd): ?>
  <a href="<?= e(url('/settings/children/new')) ?>" class="mt-4 flex w-full items-center justify-center gap-2 rounded-[24px] border-2 border-dashed border-outline-variant py-5 font-label-lg text-label-lg text-primary transition-colors hover:bg-surface-container-low">
    <span class="material-symbols-outlined">add_circle</span>아이 추가하기
  </a>
  <?php else: ?>
  <p class="mt-4 text-center font-label-sm text-label-sm text-on-surface-variant">아이 프로필은 <?= (int) ChildrenController::MAX_CHILDREN ?>명까지 만들 수 있어요.</p>
  <?php endif; ?>
<?php endif; ?>
