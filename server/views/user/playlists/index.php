<?php
/**
 * 플레이리스트 목록과 새로 만들기.
 * @var array $playlists Playlists::forUser()
 * @var int $doneCount 담을 수 있는 완성 동화 수
 */
use App\Services\Playlists;

layout('user/layout', [
    'title' => '플레이리스트',
    'nav' => 'library',
    'header' => 'sub',
    'back' => '/library',
    'headerTitle' => '플레이리스트',
    'mainClass' => 'px-margin-mobile pt-4 pb-10 space-y-6',
]);
?>
<?php if ($playlists): ?>
<ul class="space-y-3">
  <?php foreach ($playlists as $p): $cover = $p['cover']; ?>
  <li class="card flex items-center gap-3 p-3">
    <a href="<?= e(url('/playlists/' . (int) $p['id'])) ?>" class="flex min-w-0 flex-1 items-center gap-3">
      <span class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-secondary-container/40">
        <?php if ($cover): ?>
        <img src="<?= e(cover_url(['id' => (int) $cover['story_id'], 'cover_image_path' => $cover['cover_image_path'], 'updated_at' => $cover['updated_at']])) ?>" alt="" class="h-full w-full object-cover" loading="lazy">
        <?php else: ?>
        <span class="material-symbols-outlined text-[32px] text-secondary">queue_music</span>
        <?php endif; ?>
      </span>
      <span class="min-w-0 flex-1">
        <span class="block truncate text-label-lg font-label-lg text-on-surface"><?= e($p['name']) ?></span>
        <span class="flex flex-wrap items-center gap-x-1.5 text-label-sm font-label-sm text-on-surface-variant">
          <span><?= (int) $p['count'] ?>편</span>
          <?php if ($p['duration_ms'] > 0): ?><span class="text-outline">·</span><span><?= e(fmt_duration((int) $p['duration_ms'])) ?></span><?php endif; ?>
          <span class="text-outline">·</span>
          <span class="inline-flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]"><?= $p['repeat_mode'] === 'one' ? 'repeat_one' : 'repeat' ?></span><?= e(Playlists::repeatLabel($p['repeat_mode'])) ?></span>
          <?php if ((int) $p['shuffle'] === 1): ?><span class="inline-flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">shuffle</span>랜덤</span><?php endif; ?>
        </span>
      </span>
    </a>
    <?php if ($p['playable'] > 0): ?>
    <a href="<?= e(url('/playlists/' . (int) $p['id'] . '/play')) ?>" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary shadow-sm active:scale-95" aria-label="<?= e($p['name']) ?> 재생"><span class="material-symbols-outlined icon-fill text-[28px]">play_arrow</span></a>
    <?php endif; ?>
  </li>
  <?php endforeach; ?>
</ul>
<?php else: ?>
<div class="card flex flex-col items-center gap-3 p-6 text-center">
  <span class="flex h-20 w-20 items-center justify-center rounded-full bg-secondary-container/50 text-secondary"><span class="material-symbols-outlined text-[44px]">queue_music</span></span>
  <p class="text-label-lg font-label-lg text-on-surface">아직 플레이리스트가 없어요</p>
  <p class="text-body-md text-on-surface-variant break-keep">완성된 동화를 담아 두면 한 편이 끝날 때 다음 편을 이어서 들려줘요. 반복해서 듣거나 랜덤으로 들을 수도 있어요.</p>
</div>
<?php endif; ?>

<form id="new" method="post" action="<?= e(url('/playlists')) ?>" class="card space-y-3 p-4">
  <?= csrf_field() ?>
  <label for="pl-name" class="field-label">새 플레이리스트</label>
  <div class="flex gap-2">
    <input id="pl-name" type="text" name="name" required maxlength="<?= Playlists::NAME_MAX ?>" placeholder="예: 잠자리 동화, 할머니 목소리 모음" class="field min-w-0 flex-1">
    <button type="submit" class="btn-primary shrink-0 px-4"><span class="material-symbols-outlined">add</span>만들기</button>
  </div>
  <?php if ($doneCount === 0): ?>
  <p class="text-label-sm font-label-sm text-on-surface-variant">아직 완성된 동화가 없어요. <a href="<?= e(url('/stories')) ?>" class="font-bold text-primary">동화를 골라 요청</a>하면 완성되는 대로 담을 수 있어요.</p>
  <?php else: ?>
  <p class="text-label-sm font-label-sm text-on-surface-variant">완성된 동화 <?= $doneCount ?>편을 담을 수 있어요.</p>
  <?php endif; ?>
</form>
