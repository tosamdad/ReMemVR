<?php
/**
 * 플레이리스트 상세: 재생, 반복 방식, 랜덤 재생, 담긴 동화(순서 바꾸기, 빼기), 완성 동화 담기, 이름 바꾸기, 지우기.
 * @var array $playlist
 * @var array $items Playlists::items()
 * @var int $playable
 * @var int $durationMs
 * @var array $addable Playlists::addable()
 */
use App\Services\Playlists;

$pid = (int) $playlist['id'];
$base = '/playlists/' . $pid;
layout('user/layout', [
    'title' => $playlist['name'],
    'nav' => 'library',
    'header' => 'sub',
    'back' => '/playlists',
    'headerTitle' => $playlist['name'],
    'mainClass' => 'px-margin-mobile pt-4 pb-10 space-y-6',
]);
$repeat = (string) $playlist['repeat_mode'];
$shuffle = (int) $playlist['shuffle'] === 1;
$covers = [];
foreach ($items as $it) {
    if (count($covers) >= 4) {
        break;
    }
    $covers[] = cover_url(['id' => $it['story_id'], 'cover_image_path' => $it['cover_image_path'], 'updated_at' => $it['updated_at']]);
}
if (count($covers) === 3) {
    $covers = array_slice($covers, 0, 2);
}
$repeatIcons = ['off' => 'trending_flat', 'all' => 'repeat', 'one' => 'repeat_one'];
$n = count($items);
?>
<!-- 표지와 재생 -->
<section class="flex items-center gap-4">
  <div class="grid h-28 w-28 shrink-0 <?= count($covers) > 1 ? 'grid-cols-2' : 'grid-cols-1' ?> gap-0.5 overflow-hidden rounded-3xl bg-secondary-container/40 shadow-sm">
    <?php if ($covers): ?>
      <?php foreach ($covers as $c): ?>
      <img src="<?= e($c) ?>" alt="" class="h-full w-full object-cover<?= count($covers) === 2 ? ' row-span-2' : '' ?>" loading="lazy">
      <?php endforeach; ?>
    <?php else: ?>
      <span class="flex items-center justify-center text-secondary"><span class="material-symbols-outlined text-[48px]">queue_music</span></span>
    <?php endif; ?>
  </div>
  <div class="min-w-0 flex-1 space-y-2">
    <p class="text-label-lg font-label-lg text-on-surface-variant"><?= $n ?>편<?= $durationMs > 0 ? ' · ' . e(fmt_duration($durationMs)) : '' ?></p>
    <?php if ($playable > 0): ?>
    <a href="<?= e(url($base . '/play')) ?>" class="btn-primary w-full rounded-full"><span class="material-symbols-outlined icon-fill"><?= $shuffle ? 'shuffle' : 'play_arrow' ?></span><?= $shuffle ? '랜덤 재생' : '처음부터 재생' ?></a>
    <?php else: ?>
    <p class="text-label-sm font-label-sm text-on-surface-variant">들을 수 있는 동화를 담으면 재생할 수 있어요.</p>
    <?php endif; ?>
  </div>
</section>

<!-- 반복, 랜덤 -->
<section class="card space-y-3 p-4">
  <form method="post" action="<?= e(url($base . '/mode')) ?>" class="space-y-2">
    <?= csrf_field() ?>
    <p class="text-label-lg font-label-lg text-on-surface">반복</p>
    <div class="grid grid-cols-3 gap-2" role="group" aria-label="반복 방식">
      <?php foreach (Playlists::REPEAT_MODES as $mode): $on = $repeat === $mode; ?>
      <button type="submit" name="repeat" value="<?= e($mode) ?>" class="flex flex-col items-center gap-0.5 rounded-2xl px-2 py-2.5 text-label-sm font-label-sm transition-all active:scale-95 <?= $on ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-low text-on-surface-variant' ?>" aria-pressed="<?= $on ? 'true' : 'false' ?>">
        <span class="material-symbols-outlined"><?= $repeatIcons[$mode] ?></span><?= e(Playlists::repeatLabel($mode)) ?>
      </button>
      <?php endforeach; ?>
    </div>
  </form>
  <form method="post" action="<?= e(url($base . '/mode')) ?>" class="flex items-center justify-between gap-3 border-t border-surface-variant/40 pt-3">
    <?= csrf_field() ?>
    <input type="hidden" name="shuffle" value="<?= $shuffle ? '0' : '1' ?>">
    <span class="min-w-0">
      <span class="flex items-center gap-1 text-label-lg font-label-lg text-on-surface"><span class="material-symbols-outlined text-[20px]">shuffle</span>랜덤 재생</span>
      <span class="block text-label-sm font-label-sm text-on-surface-variant">재생할 때마다 순서를 새로 섞어요</span>
    </span>
    <button type="submit" class="switch" role="switch" aria-checked="<?= $shuffle ? 'true' : 'false' ?>" aria-label="랜덤 재생 <?= $shuffle ? '끄기' : '켜기' ?>">
      <input type="checkbox" tabindex="-1"<?= $shuffle ? ' checked' : '' ?>><span></span>
    </button>
  </form>
</section>

<!-- 담긴 동화 -->
<section class="space-y-3">
  <h2 class="text-label-lg font-label-lg text-on-surface">담긴 동화</h2>
  <?php if (!$items): ?>
  <p class="rounded-2xl bg-surface-container-low p-4 text-center text-body-md text-on-surface-variant">아직 담긴 동화가 없어요. 아래에서 완성된 동화를 골라 담아 주세요.</p>
  <?php else: ?>
  <ol class="space-y-2">
    <?php foreach ($items as $i => $it):
        $voice = ['icon' => $it['voice_icon'], 'label' => $it['voice_label']];
    ?>
    <li id="item-<?= $it['id'] ?>" class="flex items-center gap-2 rounded-2xl bg-surface-container-lowest p-2 shadow-soft<?= $it['playable'] ? '' : ' opacity-60' ?>">
      <span class="w-5 shrink-0 text-center text-label-sm font-label-sm text-outline"><?= $i + 1 ?></span>
      <?php if ($it['playable']): ?>
      <a href="<?= e(url($base . '/play', ['item' => $it['id']])) ?>" class="flex min-w-0 flex-1 items-center gap-3" aria-label="<?= e($it['title']) ?>부터 재생">
      <?php else: ?>
      <div class="flex min-w-0 flex-1 items-center gap-3">
      <?php endif; ?>
        <img src="<?= e(cover_url(['id' => $it['story_id'], 'cover_image_path' => $it['cover_image_path'], 'updated_at' => $it['updated_at']])) ?>" alt="" class="h-12 w-12 shrink-0 rounded-xl bg-surface-container object-cover" loading="lazy">
        <span class="min-w-0 flex-1">
          <span class="block truncate text-label-lg font-label-lg text-on-surface"><?= e($it['title']) ?></span>
          <span class="flex items-center gap-1 text-label-sm font-label-sm text-on-surface-variant">
            <span class="material-symbols-outlined text-[14px]"><?= e(voice_icon($voice)) ?></span><?= e($it['voice_label']) ?>
            <?php if ($it['playable'] && $it['duration_ms']): ?><span class="text-outline">·</span><?= e(fmt_duration((int) $it['duration_ms'])) ?><?php endif; ?>
            <?php if (!$it['playable']): ?><span class="text-outline">·</span>지금은 들을 수 없어요<?php endif; ?>
          </span>
        </span>
      <?php if ($it['playable']): ?>
      </a>
      <?php else: ?>
      </div>
      <?php endif; ?>
      <div class="flex shrink-0 items-center">
        <form method="post" action="<?= e(url($base . '/items/' . $it['id'] . '/move')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="dir" value="up">
          <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-low disabled:opacity-30" aria-label="위로"<?= $i === 0 ? ' disabled' : '' ?>><span class="material-symbols-outlined text-[20px]">arrow_upward</span></button>
        </form>
        <form method="post" action="<?= e(url($base . '/items/' . $it['id'] . '/move')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="dir" value="down">
          <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-low disabled:opacity-30" aria-label="아래로"<?= $i === $n - 1 ? ' disabled' : '' ?>><span class="material-symbols-outlined text-[20px]">arrow_downward</span></button>
        </form>
        <form method="post" action="<?= e(url($base . '/items/' . $it['id'] . '/delete')) ?>" data-confirm="'<?= e($it['title']) ?>'을(를) 플레이리스트에서 뺄까요?">
          <?= csrf_field() ?>
          <button type="submit" class="flex h-8 w-8 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-low" aria-label="빼기"><span class="material-symbols-outlined text-[20px]">close</span></button>
        </form>
      </div>
    </li>
    <?php endforeach; ?>
  </ol>
  <?php endif; ?>
</section>

<!-- 완성 동화 담기 -->
<section class="space-y-3">
  <h2 class="text-label-lg font-label-lg text-on-surface">완성 동화 담기</h2>
  <?php if (!$addable): ?>
  <p class="rounded-2xl bg-surface-container-low p-4 text-center text-label-sm font-label-sm text-on-surface-variant break-keep"><?= $items ? '완성된 동화를 모두 담았어요.' : '아직 완성된 동화가 없어요.' ?> <a href="<?= e(url('/stories')) ?>" class="font-bold text-primary">동화를 골라 요청</a>하면 완성되는 대로 담을 수 있어요.</p>
  <?php else: ?>
  <form method="post" action="<?= e(url($base . '/items')) ?>" class="space-y-3" data-add-form>
    <?= csrf_field() ?>
    <ul class="space-y-2">
      <?php foreach ($addable as $a):
          $voice = ['icon' => $a['voice_icon'], 'label' => $a['voice_label']];
      ?>
      <li>
        <label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-surface-container-low p-2 pr-3">
          <img src="<?= e(cover_url(['id' => (int) $a['story_id'], 'cover_image_path' => $a['cover_image_path'], 'updated_at' => $a['updated_at']])) ?>" alt="" class="h-12 w-12 shrink-0 rounded-xl bg-surface-container object-cover" loading="lazy">
          <span class="min-w-0 flex-1">
            <span class="block truncate text-label-lg font-label-lg text-on-surface"><?= e($a['title']) ?></span>
            <span class="flex items-center gap-1 text-label-sm font-label-sm text-on-surface-variant"><span class="material-symbols-outlined text-[14px]"><?= e(voice_icon($voice)) ?></span><?= e($a['voice_label']) ?><?php if ($a['duration_ms']): ?><span class="text-outline">·</span><?= e(fmt_duration((int) $a['duration_ms'])) ?><?php endif; ?></span>
          </span>
          <input type="checkbox" name="keys[]" value="<?= (int) $a['story_id'] ?>:<?= (int) $a['voice_profile_id'] ?>" class="h-5 w-5 shrink-0 rounded border-outline text-primary focus:ring-primary/30" data-add-pick>
        </label>
      </li>
      <?php endforeach; ?>
    </ul>
    <button type="submit" class="btn-secondary w-full rounded-full" data-add-btn disabled><span class="material-symbols-outlined">playlist_add</span><span data-add-label>담을 동화를 골라 주세요</span></button>
  </form>
  <?php endif; ?>
</section>

<!-- 이름 바꾸기, 지우기 -->
<section class="space-y-3">
  <details class="card p-4">
    <summary class="flex cursor-pointer list-none items-center justify-between text-label-lg font-label-lg text-on-surface">이름 바꾸기<span class="material-symbols-outlined text-outline">expand_more</span></summary>
    <form method="post" action="<?= e(url($base . '/rename')) ?>" class="mt-3 flex gap-2">
      <?= csrf_field() ?>
      <input type="text" name="name" required maxlength="<?= Playlists::NAME_MAX ?>" value="<?= e($playlist['name']) ?>" class="field min-w-0 flex-1" aria-label="플레이리스트 이름">
      <button type="submit" class="btn-secondary shrink-0 px-4">저장</button>
    </form>
  </details>
  <form method="post" action="<?= e(url($base . '/delete')) ?>" data-confirm="'<?= e($playlist['name']) ?>' 플레이리스트를 지울까요? 담긴 동화는 내 동화에 그대로 남아요.">
    <?= csrf_field() ?>
    <button type="submit" class="btn-ghost w-full text-error"><span class="material-symbols-outlined">delete</span>플레이리스트 지우기</button>
  </form>
</section>
<?php section('scripts'); ?>
<script>
(function () {
  var form = document.querySelector('[data-add-form]');
  if (!form) return;
  var picks = Array.prototype.slice.call(form.querySelectorAll('[data-add-pick]'));
  var btn = form.querySelector('[data-add-btn]');
  var label = form.querySelector('[data-add-label]');
  function update() {
    var n = picks.filter(function (p) { return p.checked; }).length;
    btn.disabled = n === 0;
    label.textContent = n ? n + '편 담기' : '담을 동화를 골라 주세요';
  }
  picks.forEach(function (p) { p.addEventListener('change', update); });
  update();
})();
</script>
<?php endsection(); ?>
