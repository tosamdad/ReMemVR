<?php
/**
 * 동화 상세: 표지, 소개, 가족 목소리별 상태.
 * 완성된 목소리는 바로 듣고, 아직 없는 목소리는 골라서 생성 요청을 보낸다(관리자가 확인한 뒤 만든다).
 * @var array $story
 * @var string $durationLabel
 * @var array $voices StoryRequests::voiceStates()
 * @var bool $completed
 * @var string|null $resumeUrl 듣다 만 위치(이어 듣기)
 */
use App\Services\StoryRequests;

$sid = (int) $story['id'];
layout('user/layout', [
    'title' => $story['title'],
    'nav' => 'home',
    'header' => 'sub',
    'back' => '/stories',
    'headerTitle' => '동화 고르기',
    'mainClass' => 'px-margin-mobile pt-4 pb-10 space-y-6',
]);
$requestable = 0;
$doneCount = 0;
foreach ($voices as $v) {
    if (in_array($v['state'], ['none', 'rejected'], true)) {
        $requestable++;
    }
    if ($v['state'] === 'done') {
        $doneCount++;
    }
}
$stateLook = [
    'done' => ['완성', 'bg-secondary-container text-on-secondary-container', 'check_circle'],
    'requested' => ['요청됨', 'bg-tertiary-container text-on-tertiary-container', 'schedule'],
    'making' => ['만드는 중', 'bg-primary-fixed text-on-primary-fixed', 'graphic_eq'],
    'failed' => ['확인 중', 'bg-primary-fixed text-on-primary-fixed', 'support_agent'],
    'rejected' => ['반려', 'bg-error-container text-on-error-container', 'block'],
    'unready' => ['목소리 준비 중', 'bg-surface-container-high text-on-surface-variant', 'hourglass_top'],
];
$summary = trim((string) $story['summary']);
?>
<!-- 표지와 소개 -->
<section class="flex gap-4">
  <div class="relative h-40 w-32 shrink-0 overflow-hidden rounded-2xl border-4 border-surface-container-lowest bg-surface-container shadow-lg">
    <img src="<?= e(cover_url($story)) ?>" alt="" class="h-full w-full object-cover">
    <?php if ($completed): ?>
    <div class="absolute left-1.5 top-1.5 flex h-6 w-6 items-center justify-center rounded-full bg-secondary text-on-secondary shadow-sm" title="다 들은 동화"><span class="material-symbols-outlined text-[16px]">check</span></div>
    <?php endif; ?>
  </div>
  <div class="min-w-0 flex-1 space-y-2 pt-1">
    <?php if (!empty($story['category'])): ?>
    <span class="inline-flex items-center gap-1 rounded-full bg-tertiary-container px-3 py-1 text-[11px] font-bold text-on-tertiary-container"><span class="material-symbols-outlined icon-fill text-[14px]">auto_stories</span><?= e($story['category']) ?></span>
    <?php endif; ?>
    <h1 class="text-headline-md font-headline-md text-on-surface break-keep"><?= e($story['title']) ?></h1>
    <p class="flex flex-wrap items-center gap-x-2 text-label-sm font-label-sm text-on-surface-variant">
      <?php if ((string) $story['author'] !== ''): ?><span><?= e($story['author']) ?></span><?php endif; ?>
      <?php if ($durationLabel !== ''): ?><span class="inline-flex items-center gap-0.5"><span class="material-symbols-outlined text-[14px]">schedule</span><?= e($durationLabel) ?></span><?php endif; ?>
    </p>
  </div>
</section>
<?php if ($summary !== ''): ?>
<p class="text-body-md text-on-surface-variant break-keep"><?= e($summary) ?></p>
<?php endif; ?>
<?php if ($resumeUrl): ?>
<a href="<?= e($resumeUrl) ?>" class="btn-secondary w-full rounded-full"><span class="material-symbols-outlined icon-fill">play_circle</span>듣던 곳부터 이어 듣기</a>
<?php endif; ?>

<!-- 가족 목소리 고르기 -->
<section class="space-y-3">
  <div class="space-y-1">
    <h2 class="text-label-lg font-label-lg text-on-surface">누구 목소리로 들을까요?</h2>
    <p class="text-label-sm font-label-sm text-on-surface-variant">완성된 목소리는 바로 들을 수 있어요. 아직 없는 목소리는 골라서 만들어 달라고 요청해 주세요.</p>
  </div>

  <?php if (!$voices): ?>
  <div class="card flex flex-col items-center gap-3 p-6 text-center">
    <img src="<?= e(asset('img/empty-voices.svg')) ?>" alt="" class="h-24 w-auto">
    <p class="text-label-lg font-label-lg text-on-surface">아직 등록된 가족 목소리가 없어요</p>
    <p class="text-body-md text-on-surface-variant">엄마, 아빠, 할머니 목소리를 먼저 녹음해 주세요.<br>목소리가 준비되면 이 동화를 그 목소리로 만들 수 있어요.</p>
    <a href="<?= e(url('/voice-lab/new')) ?>" class="btn-primary mt-1 w-full"><span class="material-symbols-outlined">mic</span>목소리 녹음하기</a>
  </div>
  <?php else: ?>
  <form method="post" action="<?= e(url('/stories/' . $sid . '/request')) ?>" class="space-y-3" data-request-form>
    <?= csrf_field() ?>
    <ul class="space-y-3">
      <?php foreach ($voices as $vid => $v):
          $voice = $v['voice'];
          $state = $v['state'];
          $req = $v['request'];
          $pick = in_array($state, ['none', 'rejected'], true);
          $look = isset($stateLook[$state]) ? $stateLook[$state] : null;
      ?>
      <li class="card flex items-center gap-3 p-4">
        <?php if ($pick): ?>
        <label class="flex min-w-0 flex-1 cursor-pointer items-center gap-3">
          <input type="checkbox" name="voice_ids[]" value="<?= (int) $vid ?>" class="h-5 w-5 shrink-0 rounded border-outline text-primary focus:ring-primary/30" data-voice-pick>
        <?php else: ?>
        <div class="flex min-w-0 flex-1 items-center gap-3">
        <?php endif; ?>
          <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full <?= $state === 'done' ? 'bg-primary-container/30 text-primary' : 'bg-surface-container text-on-surface-variant' ?>">
            <span class="material-symbols-outlined text-[28px]"><?= e(voice_icon($voice)) ?></span>
          </span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-label-lg font-label-lg text-on-surface"><?= e($voice['label']) ?> 목소리</span>
            <?php if ($look): ?>
            <span class="mt-0.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold <?= $look[1] ?>"><span class="material-symbols-outlined text-[13px]"><?= $look[2] ?></span><?= e($look[0]) ?></span>
            <?php else: ?>
            <span class="block text-label-sm font-label-sm text-on-surface-variant">골라서 요청할 수 있어요</span>
            <?php endif; ?>
            <?php if ($state === 'rejected' && $req && (string) $req['reject_reason'] !== ''): ?>
            <span class="mt-1 block text-[12px] leading-4 text-error break-keep"><?= e($req['reject_reason']) ?> · 다시 요청할 수 있어요</span>
            <?php elseif ($state === 'requested'): ?>
            <span class="mt-1 block text-[12px] leading-4 text-on-surface-variant">운영팀이 확인하고 있어요</span>
            <?php elseif ($state === 'failed'): ?>
            <span class="mt-1 block text-[12px] leading-4 text-on-surface-variant">만드는 중에 문제가 생겨 운영팀이 확인하고 있어요</span>
            <?php elseif ($state === 'unready'): ?>
            <span class="mt-1 block text-[12px] leading-4 text-on-surface-variant">목소리가 준비되면 요청할 수 있어요</span>
            <?php endif; ?>
          </span>
        <?php if ($pick): ?>
        </label>
        <?php else: ?>
        </div>
        <?php endif; ?>
        <?php if ($state === 'done'): ?>
        <a href="<?= e(url('/player/' . $sid, ['voice' => (int) $vid])) ?>" class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-primary text-on-primary shadow-sm active:scale-95" aria-label="<?= e($voice['label']) ?> 목소리로 듣기"><span class="material-symbols-outlined icon-fill text-[28px]">play_arrow</span></a>
        <?php elseif ($state === 'requested' && $req): ?>
        <button type="submit" form="cancel-<?= (int) $req['id'] ?>" class="shrink-0 rounded-full px-3 py-2 text-label-sm font-label-sm text-on-surface-variant hover:bg-surface-container-low">요청 취소</button>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($requestable > 0): ?>
    <button type="submit" class="btn-primary w-full rounded-full" data-request-btn disabled>
      <span class="material-symbols-outlined">library_add</span><span data-request-label>목소리를 골라 주세요</span>
    </button>
    <p class="text-center text-label-sm font-label-sm text-on-surface-variant">요청하면 운영팀이 확인한 뒤 만들어 드려요. 완성되면 내 동화에 나타나고 메일로 알려 드려요.</p>
    <?php endif; ?>
  </form>
  <?php foreach ($voices as $v): if ($v['state'] === 'requested' && $v['request']): ?>
  <form method="post" action="<?= e(url('/library/requests/' . (int) $v['request']['id'] . '/cancel')) ?>" id="cancel-<?= (int) $v['request']['id'] ?>" data-confirm="<?= e($v['voice']['label']) ?> 목소리 요청을 취소할까요?" hidden>
    <?= csrf_field() ?>
    <input type="hidden" name="back" value="/stories/<?= $sid ?>">
  </form>
  <?php endif; endforeach; ?>
  <?php endif; ?>
</section>

<!-- 기기 음성으로 먼저 들어 보기 -->
<a href="<?= e(url('/player/' . $sid, ['voice' => 'device'])) ?>" class="flex items-center gap-3 rounded-2xl bg-surface-container-low p-4">
  <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-surface-container-lowest text-secondary"><span class="material-symbols-outlined">smartphone</span></span>
  <span class="min-w-0 flex-1">
    <span class="block text-label-lg font-label-lg text-on-surface">기기 음성으로 먼저 들어 보기</span>
    <span class="block text-label-sm font-label-sm text-on-surface-variant">휴대폰에 들어 있는 기본 목소리로 읽어 줘요</span>
  </span>
  <span class="material-symbols-outlined text-outline">chevron_right</span>
</a>
<?php if ($doneCount > 0): ?>
<a href="<?= e(url('/library')) ?>" class="flex items-center justify-center gap-1 text-label-lg font-label-lg text-primary"><span class="material-symbols-outlined text-[20px]">library_music</span>내 동화와 플레이리스트 보기</a>
<?php endif; ?>
<?php section('scripts'); ?>
<script>
(function () {
  var form = document.querySelector('[data-request-form]');
  if (!form) return;
  var picks = Array.prototype.slice.call(form.querySelectorAll('[data-voice-pick]'));
  var btn = form.querySelector('[data-request-btn]');
  var label = form.querySelector('[data-request-label]');
  function update() {
    var n = picks.filter(function (p) { return p.checked; }).length;
    if (btn) btn.disabled = n === 0;
    if (label) label.textContent = n ? '고른 목소리 ' + n + '개로 생성 요청' : '목소리를 골라 주세요';
  }
  picks.forEach(function (p) { p.addEventListener('change', update); });
  update();
})();
</script>
<?php endsection(); ?>
