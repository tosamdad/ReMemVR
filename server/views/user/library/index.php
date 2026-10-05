<?php
/**
 * 내 동화: 생성 요청 목록(전체, 만드는 중, 완성, 반려)과 플레이리스트 바로 가기.
 * 완성된 동화는 바로 듣거나 플레이리스트에 담는다(아래 담기 시트).
 * @var string $tab
 * @var array $counts
 * @var array $rows StoryRequests::forUser()
 * @var array $playlists Playlists::forUser()
 * @var int $readyVoices
 * @var int $anyVoices
 * @var bool $autoApprove 요청하면 관리자 확인 없이 바로 만드는지
 */
use App\Controllers\User\LibraryController;
use App\Services\Playlists;

layout('user/layout', [
    'title' => '내 동화',
    'nav' => 'library',
    'mainClass' => 'px-margin-mobile pt-6 pb-10 space-y-6',
]);
$chip = static function (bool $on): string {
    return $on ? 'bg-primary text-on-primary shadow-sm' : 'bg-surface-container-high text-on-surface-variant hover:bg-surface-container-highest';
};
$stateLook = [
    'done' => ['완성', 'bg-secondary-container text-on-secondary-container', 'check_circle'],
    'requested' => ['요청됨', 'bg-tertiary-container text-on-tertiary-container', 'schedule'],
    'making' => ['만드는 중', 'bg-primary-fixed text-on-primary-fixed', 'graphic_eq'],
    'failed' => ['확인 중', 'bg-primary-fixed text-on-primary-fixed', 'support_agent'],
    'rejected' => ['반려', 'bg-error-container text-on-error-container', 'block'],
];
$storyOf = static function (array $r): array {
    return ['id' => (int) $r['story_id'], 'cover_image_path' => $r['cover_image_path'], 'updated_at' => $r['story_updated_at']];
};
$back = '/library' . ($tab !== 'all' ? '?tab=' . $tab : '');
$empty = [
    'all' => ['아직 요청한 동화가 없어요', '동화 책장에서 듣고 싶은 동화를 고르고, 읽어 줄 가족 목소리를 골라 요청해 주세요.'],
    'making' => ['만드는 중인 동화가 없어요', $autoApprove ? '요청한 동화는 바로 만들기 시작해요. 완성되면 메일로 알려 드려요.' : '요청한 동화는 운영팀이 확인한 뒤 만들어요. 완성되면 메일로 알려 드려요.'],
    'done' => ['아직 완성된 동화가 없어요', '요청한 동화가 완성되면 여기에서 바로 들을 수 있어요.'],
    'rejected' => ['반려된 요청이 없어요', ''],
];
?>
<!-- 머리말 -->
<section class="flex items-end justify-between gap-3">
  <div class="min-w-0 space-y-1">
    <h1 class="text-headline-md font-headline-md text-on-surface">내 동화</h1>
    <p class="text-label-lg font-label-lg text-on-surface-variant">완성 <?= (int) $counts['done'] ?>편<?= $counts['making'] ? ' · 만드는 중 ' . (int) $counts['making'] . '편' : '' ?></p>
  </div>
  <a href="<?= e(url('/stories')) ?>" class="inline-flex shrink-0 items-center gap-1 rounded-full bg-primary-container px-4 py-2 text-label-lg font-label-lg text-on-primary-container shadow-sm active:scale-95"><span class="material-symbols-outlined text-[20px]">add</span>동화 고르기</a>
</section>

<!-- 플레이리스트 -->
<section class="space-y-3">
  <div class="flex items-center justify-between">
    <h2 class="text-label-lg font-label-lg text-on-surface">플레이리스트</h2>
    <a href="<?= e(url('/playlists')) ?>" class="text-label-sm font-label-sm text-primary">모두 보기</a>
  </div>
  <?php if ($playlists): ?>
  <div class="-mx-margin-mobile flex gap-3 overflow-x-auto no-scrollbar px-margin-mobile pb-1">
    <?php foreach ($playlists as $p): $cover = $p['cover']; ?>
    <div class="relative w-36 shrink-0">
      <a href="<?= e(url('/playlists/' . (int) $p['id'])) ?>" class="block overflow-hidden rounded-2xl border border-surface-variant/30 bg-surface-container-lowest shadow-sm">
        <div class="flex aspect-square w-full items-center justify-center bg-secondary-container/40">
          <?php if ($cover): ?>
          <img src="<?= e(cover_url(['id' => (int) $cover['story_id'], 'cover_image_path' => $cover['cover_image_path'], 'updated_at' => $cover['updated_at']])) ?>" alt="" class="h-full w-full object-cover" loading="lazy">
          <?php else: ?>
          <span class="material-symbols-outlined text-[48px] text-secondary">queue_music</span>
          <?php endif; ?>
        </div>
        <div class="space-y-0.5 p-2.5">
          <p class="truncate text-label-lg font-label-lg text-on-surface"><?= e($p['name']) ?></p>
          <p class="flex items-center gap-1 text-[12px] leading-4 text-on-surface-variant"><?= (int) $p['count'] ?>편<?php if ((int) $p['shuffle'] === 1): ?><span class="material-symbols-outlined text-[14px]" title="랜덤 재생">shuffle</span><?php endif; ?><?php if ($p['repeat_mode'] !== 'off'): ?><span class="material-symbols-outlined text-[14px]" title="<?= e(Playlists::repeatLabel($p['repeat_mode'])) ?>"><?= $p['repeat_mode'] === 'one' ? 'repeat_one' : 'repeat' ?></span><?php endif; ?></p>
        </div>
      </a>
      <?php if ($p['playable'] > 0): ?>
      <a href="<?= e(url('/playlists/' . (int) $p['id'] . '/play')) ?>" class="absolute right-2 top-[5.75rem] flex h-11 w-11 items-center justify-center rounded-full bg-primary text-on-primary shadow-lg active:scale-95" aria-label="<?= e($p['name']) ?> 재생"><span class="material-symbols-outlined icon-fill text-[28px]">play_arrow</span></a>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <a href="<?= e(url('/playlists') . '#new') ?>" class="flex w-36 shrink-0 flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant bg-surface-container-low/60 p-3 text-center text-primary">
      <span class="material-symbols-outlined text-[32px]">playlist_add</span>
      <span class="text-label-lg font-label-lg">새로 만들기</span>
    </a>
  </div>
  <?php else: ?>
  <a href="<?= e(url('/playlists') . '#new') ?>" class="flex items-center gap-3 rounded-2xl bg-surface-container-low p-4">
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container"><span class="material-symbols-outlined">queue_music</span></span>
    <span class="min-w-0 flex-1">
      <span class="block text-label-lg font-label-lg text-on-surface">플레이리스트 만들기</span>
      <span class="block text-label-sm font-label-sm text-on-surface-variant">완성된 동화를 담아 반복하거나 랜덤으로 들어요</span>
    </span>
    <span class="material-symbols-outlined text-outline">chevron_right</span>
  </a>
  <?php endif; ?>
</section>

<!-- 요청 목록 -->
<section class="space-y-4">
  <nav class="-mx-margin-mobile flex gap-2 overflow-x-auto no-scrollbar px-margin-mobile pb-1" aria-label="요청 상태">
    <?php foreach (LibraryController::TABS as $key => $label): ?>
    <a href="<?= e(url('/library', $key !== 'all' ? ['tab' => $key] : [])) ?>" class="inline-flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-full px-4 py-2 text-label-lg font-label-lg transition-all active:scale-95 <?= $chip($tab === $key) ?>"<?= $tab === $key ? ' aria-current="true"' : '' ?>><?= e($label) ?><span class="text-[12px] opacity-80"><?= (int) $counts[$key] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <?php if (!$rows): ?>
  <div class="card flex flex-col items-center gap-3 p-6 text-center">
    <img src="<?= e(asset('img/empty-stories.svg')) ?>" alt="" class="h-24 w-auto">
    <p class="text-label-lg font-label-lg text-on-surface"><?= e($empty[$tab][0]) ?></p>
    <?php if ($empty[$tab][1] !== ''): ?><p class="text-body-md text-on-surface-variant break-keep"><?= e($empty[$tab][1]) ?></p><?php endif; ?>
    <?php if ($tab === 'all'): ?>
      <?php if ($readyVoices > 0): ?>
      <a href="<?= e(url('/stories')) ?>" class="btn-primary mt-1 w-full"><span class="material-symbols-outlined">auto_stories</span>동화 고르러 가기</a>
      <?php elseif ($anyVoices > 0): ?>
      <p class="text-label-sm font-label-sm text-on-surface-variant">가족 목소리가 준비되면 동화를 요청할 수 있어요.</p>
      <a href="<?= e(url('/voice-lab')) ?>" class="btn-secondary mt-1 w-full"><span class="material-symbols-outlined">mic</span>목소리 연구실 보기</a>
      <?php else: ?>
      <a href="<?= e(url('/voice-lab/new')) ?>" class="btn-primary mt-1 w-full"><span class="material-symbols-outlined">mic</span>가족 목소리 먼저 녹음하기</a>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <ul class="space-y-3">
    <?php foreach ($rows as $r):
        $state = $r['state'];
        $look = $stateLook[$state];
        $sid = (int) $r['story_id'];
        $vid = (int) $r['voice_profile_id'];
        $voice = ['icon' => $r['voice_icon'], 'label' => $r['voice_label']];
        $published = $r['story_status'] === 'published' && $r['story_deleted_at'] === null;
        $playable = $state === 'done' && $published;
        $when = $state === 'done' && $r['completed_at'] ? '완성 ' . time_ago($r['completed_at']) : '요청 ' . time_ago($r['created_at']);
    ?>
    <li class="card flex gap-3 p-3" data-req-id="<?= (int) $r['id'] ?>" data-req-state="<?= e($state) ?>">
      <a href="<?= e(url('/stories/' . $sid)) ?>" class="relative h-20 w-16 shrink-0 overflow-hidden rounded-xl bg-surface-container">
        <img src="<?= e(cover_url($storyOf($r))) ?>" alt="" class="h-full w-full object-cover" loading="lazy">
      </a>
      <div class="min-w-0 flex-1 space-y-1">
        <a href="<?= e(url('/stories/' . $sid)) ?>" class="block truncate text-label-lg font-label-lg text-on-surface"><?= e($r['story_title']) ?></a>
        <p class="flex items-center gap-1 text-label-sm font-label-sm text-on-surface-variant">
          <span class="material-symbols-outlined text-[16px]"><?= e(voice_icon($voice)) ?></span><?= e($r['voice_label']) ?> 목소리
          <?php if ($state === 'done' && $r['audio_duration_ms']): ?><span class="text-outline">·</span><?= e(fmt_duration((int) $r['audio_duration_ms'])) ?><?php endif; ?>
        </p>
        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold <?= $look[1] ?>"><span class="material-symbols-outlined text-[13px]"><?= $look[2] ?></span><?= e($look[0]) ?></span>
          <span class="text-[11px] text-outline"><?= e($when) ?></span>
        </div>
        <?php if ($state === 'requested'): ?>
        <p class="text-[12px] leading-4 text-on-surface-variant">운영팀이 확인한 뒤 만들어 드려요</p>
        <?php elseif ($state === 'making'): ?>
        <p class="text-[12px] leading-4 text-on-surface-variant">가족 목소리로 녹음하고 있어요. 다 되면 완성으로 바뀌고 메일로도 알려 드려요</p>
        <?php elseif ($state === 'failed'): ?>
        <p class="text-[12px] leading-4 text-on-surface-variant">만드는 중에 문제가 생겨 운영팀이 확인하고 있어요</p>
        <?php elseif ($state === 'rejected'): ?>
        <p class="text-[12px] leading-4 text-error break-keep"><?= e((string) $r['reject_reason'] !== '' ? $r['reject_reason'] : '운영팀이 이번 요청을 반려했어요') ?></p>
        <?php elseif (!$published): ?>
        <p class="text-[12px] leading-4 text-on-surface-variant">지금은 쉬고 있는 동화라 들을 수 없어요</p>
        <?php endif; ?>
      </div>
      <div class="flex shrink-0 flex-col items-end justify-center gap-2">
        <?php if ($playable): ?>
        <a href="<?= e(url('/player/' . $sid, ['voice' => $vid])) ?>" class="flex h-11 w-11 items-center justify-center rounded-full bg-primary text-on-primary shadow-sm active:scale-95" aria-label="<?= e($r['story_title']) ?> 듣기"><span class="material-symbols-outlined icon-fill text-[26px]">play_arrow</span></a>
        <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-primary hover:bg-surface-container-low" data-add-story="<?= $sid ?>" data-add-voice="<?= $vid ?>" data-add-title="<?= e($r['story_title'] . ' · ' . $r['voice_label']) ?>" aria-label="플레이리스트에 담기"><span class="material-symbols-outlined">playlist_add</span></button>
        <?php elseif ($state === 'requested'): ?>
        <form method="post" action="<?= e(url('/library/requests/' . (int) $r['id'] . '/cancel')) ?>" data-confirm="'<?= e($r['story_title']) ?>' 요청을 취소할까요?">
          <?= csrf_field() ?>
          <input type="hidden" name="back" value="<?= e($back) ?>">
          <button type="submit" class="rounded-full px-3 py-2 text-label-sm font-label-sm text-on-surface-variant hover:bg-surface-container-low">요청 취소</button>
        </form>
        <?php elseif ($state === 'rejected' && $published): ?>
        <a href="<?= e(url('/stories/' . $sid)) ?>" class="rounded-full bg-surface-container-high px-3 py-2 text-label-sm font-label-sm text-on-surface">다시 요청</a>
        <?php elseif ($state === 'making'): ?>
        <span class="material-symbols-outlined animate-pulse text-primary" aria-hidden="true">graphic_eq</span>
        <?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<!-- 플레이리스트에 담기 시트 -->
<div id="add-sheet" class="fixed inset-0 z-[60]" hidden>
  <div class="absolute inset-0 bg-inverse-surface/60 backdrop-blur-sm" data-sheet-close></div>
  <div class="relative mx-auto flex h-full w-full max-w-[520px] flex-col justify-end px-margin-mobile pb-8">
    <form method="post" action="<?= e(url('/playlists/add')) ?>" class="max-h-[80vh] space-y-4 overflow-y-auto rounded-3xl bg-surface-container-lowest p-md shadow-lg" role="dialog" aria-modal="true" aria-labelledby="add-sheet-title">
      <?= csrf_field() ?>
      <input type="hidden" name="story_id" value="">
      <input type="hidden" name="voice_id" value="">
      <div class="space-y-1">
        <p id="add-sheet-title" class="text-headline-md font-headline-md text-on-surface">플레이리스트에 담기</p>
        <p class="truncate text-label-lg font-label-lg text-on-surface-variant" data-sheet-story></p>
      </div>
      <div class="space-y-2">
        <?php foreach ($playlists as $i => $p): ?>
        <label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-surface-container-low px-4 py-3">
          <input type="radio" name="playlist_id" value="<?= (int) $p['id'] ?>" class="h-5 w-5 border-outline text-primary focus:ring-primary/30"<?= $i === 0 ? ' checked' : '' ?>>
          <span class="min-w-0 flex-1 truncate text-label-lg font-label-lg text-on-surface"><?= e($p['name']) ?></span>
          <span class="text-label-sm font-label-sm text-on-surface-variant"><?= (int) $p['count'] ?>편</span>
        </label>
        <?php endforeach; ?>
        <label class="flex cursor-pointer items-center gap-3 rounded-2xl bg-surface-container-low px-4 py-3">
          <input type="radio" name="playlist_id" value="0" class="h-5 w-5 border-outline text-primary focus:ring-primary/30"<?= $playlists ? '' : ' checked' ?> data-new-radio>
          <input type="text" name="new_name" maxlength="<?= Playlists::NAME_MAX ?>" placeholder="새 플레이리스트 이름(예: 잠자리 동화)" class="min-w-0 flex-1 border-0 bg-transparent p-0 text-label-lg font-label-lg text-on-surface placeholder:text-outline focus:ring-0" data-new-name>
        </label>
      </div>
      <div class="grid grid-cols-2 gap-3">
        <button type="button" class="btn-ghost bg-surface-container-low px-3" data-sheet-close>닫기</button>
        <button type="submit" class="btn-primary px-3"><span class="material-symbols-outlined">playlist_add</span>담기</button>
      </div>
    </form>
  </div>
</div>
<?php section('scripts'); ?>
<script>
(function () {
  var sheet = document.getElementById('add-sheet');
  if (!sheet) return;
  var form = sheet.querySelector('form');
  var newName = sheet.querySelector('[data-new-name]');
  var newRadio = sheet.querySelector('[data-new-radio]');
  document.querySelectorAll('[data-add-story]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      form.elements.story_id.value = btn.getAttribute('data-add-story');
      form.elements.voice_id.value = btn.getAttribute('data-add-voice');
      sheet.querySelector('[data-sheet-story]').textContent = btn.getAttribute('data-add-title');
      sheet.hidden = false;
    });
  });
  sheet.querySelectorAll('[data-sheet-close]').forEach(function (el) {
    el.addEventListener('click', function () { sheet.hidden = true; });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') sheet.hidden = true; });
  if (newName && newRadio) {
    newName.addEventListener('focus', function () { newRadio.checked = true; });
  }
  form.addEventListener('submit', function (e) {
    if (newRadio && newRadio.checked && newName && !newName.value.trim()) {
      e.preventDefault();
      newName.focus();
      RM.toast('새 플레이리스트 이름을 적어 주세요.', 'error');
    }
  });
})();
</script>
<?php endsection(); ?>
