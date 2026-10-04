<?php
/**
 * 동화 플레이어(시안 _6). 큰 읽기 카드(낱말 강조), 목소리 칩, 진행 막대, 재생 버튼, 질문하기 버튼.
 * 재생과 질문 흐름은 public/assets/js/player.js 가 맡는다. 서버는 첫 화면(시작 문장)을 미리 그려 둔다.
 * @var array $story
 * @var array $manifest
 * @var array $sentences
 * @var int $startIndex
 * @var array $voices
 * @var string $voice
 * @var array|null $audio
 * @var array $prefs
 * @var int $remaining
 * @var array $qa
 * @var int $estMs
 */
layout('user/layout', ['title' => $story['title'], 'nav' => 'player', 'mainClass' => 'px-margin-mobile pt-4 pb-6']);

$current = isset($sentences[$startIndex]) ? $sentences[$startIndex] : ['seq' => 1, 'content' => '', 'words' => []];
$prev = $startIndex > 0 && isset($sentences[$startIndex - 1]) ? $sentences[$startIndex - 1] : null;
$sizes = [
    'sm' => 'text-[19px] leading-[32px]',
    'md' => 'text-[22px] leading-[36px]',
    'lg' => 'text-[26px] leading-[42px]',
];
$textSize = isset($sizes[$prefs['text_size']]) ? $sizes[$prefs['text_size']] : $sizes['md'];
$device = $voice === 'device';
$speed = (float) $prefs['playback_speed'];
$sleepMin = (int) $prefs['sleep_timer_min'];
// 기기 음성은 글자 수로 길이를 어림한다(player.js 의 deviceTotalMs 와 같은 식)
$chars = 0;
foreach ($sentences as $s) {
    $chars += mb_strlen($s['content']) + 1;
}
$totalMs = $audio ? (int) $audio['duration_ms'] : (int) round($chars * 160 / ($speed > 0 ? $speed : 1));
// 목소리 칩: 3개까지는 한 줄 칸으로, 더 많으면 옆으로 넘겨 본다.
$chipCount = max(2, count($voices) + 1); // 목소리가 없으면 '가족 목소리 만들기' 칸을 함께 보여 준다
$chipCols = [1 => 'grid-cols-1', 2 => 'grid-cols-2', 3 => 'grid-cols-3'];
$chipWrap = $chipCount <= 3 ? 'grid gap-3 pt-1 ' . $chipCols[$chipCount] : '-mx-margin-mobile flex gap-3 overflow-x-auto no-scrollbar px-margin-mobile pt-1 pb-1';
$chipSize = $chipCount <= 3 ? 'min-w-0' : 'w-[124px] shrink-0';
$askSub = !$qa['enabled'] ? $qa['message'] : ($remaining > 0 ? '질문 ' . $remaining . '번 남았어요' : '이야기 끝나고 또 물어보자');
?>
<div id="player" class="space-y-4" data-mode="<?= $device ? 'device' : 'audio' ?>">
  <!-- 표지, 분류, 제목 -->
  <section class="flex items-center gap-4">
    <div class="relative h-[104px] w-[84px] shrink-0 overflow-hidden rounded-[22px] border-4 border-surface-container-lowest bg-surface-container shadow-lg">
      <img src="<?= e(cover_url($story)) ?>" alt="" class="h-full w-full object-cover">
    </div>
    <div class="min-w-0 flex-1 space-y-1.5">
      <?php if (!empty($story['category'])): ?>
      <span class="inline-flex items-center gap-1.5 rounded-full bg-tertiary-container px-3 py-1 font-label-sm text-label-sm text-on-tertiary-container shadow-sm">
        <span class="material-symbols-outlined icon-fill text-[16px]">auto_stories</span><?= e($story['category']) ?>
      </span>
      <?php endif; ?>
      <h1 class="text-[22px] font-bold leading-7 text-on-surface font-headline-md"><?= e($story['title']) ?></h1>
      <p id="pl-subtitle" class="font-label-lg text-label-lg text-on-surface-variant">문장 <?= (int) $startIndex + 1 ?> / <?= count($sentences) ?></p>
      <?php if ($sleepMin > 0 || abs($speed - 1.0) > 0.01): ?>
      <div class="flex flex-wrap gap-1.5 pt-0.5">
        <?php if ($sleepMin > 0): ?>
        <span id="pl-sleep" class="inline-flex items-center gap-1 rounded-full bg-surface-container-high px-2.5 py-0.5 text-[11px] font-bold text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">bedtime</span><span id="pl-sleep-text">잠자기 <?= $sleepMin ?>분</span></span>
        <?php endif; ?>
        <?php if (abs($speed - 1.0) > 0.01): ?>
        <span class="inline-flex items-center gap-1 rounded-full bg-surface-container-high px-2.5 py-0.5 text-[11px] font-bold text-on-surface-variant"><span class="material-symbols-outlined text-[14px]">speed</span><?= e(rtrim(rtrim(number_format($speed, 2), '0'), '.')) ?>배속</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- 읽기 카드 -->
  <section class="glass relative flex min-h-[196px] flex-col justify-center gap-3 rounded-3xl border border-surface-variant/40 px-md py-6 text-center shadow-soft">
    <p id="pl-prev" class="font-story text-body-md text-on-surface-variant/60 line-clamp-2<?= $prev ? '' : ' invisible' ?>"><?= $prev ? e($prev['content']) : '&nbsp;' ?></p>
    <p id="pl-text" class="font-story <?= e($textSize) ?> text-on-surface break-keep" aria-live="polite"><?php
        $n = count($current['words']);
        foreach ($current['words'] as $k => $w) {
            echo '<span class="word">' . e($w) . '</span>' . ($k < $n - 1 ? ' ' : '');
        }
    ?></p>
    <?php if ($audio && $audio['outdated']): ?>
    <p class="text-[11px] font-semibold text-on-surface-variant/70">글이 고쳐진 동화라 소리와 글자가 조금 다를 수 있어요</p>
    <?php endif; ?>
  </section>

  <!-- 목소리 고르기 -->
  <section class="<?= e($chipWrap) ?>" aria-label="목소리 고르기">
    <?php foreach ($voices as $v): $active = $v['id'] === $voice; ?>
    <button type="button" data-voice="<?= e($v['id']) ?>" class="relative flex <?= e($chipSize) ?> flex-col items-center gap-1.5 rounded-3xl border-2 bg-surface-container-lowest px-2 py-3 shadow-sm transition-all duration-200 active:scale-95 <?= $active ? 'border-primary-container' : 'border-transparent' ?> <?= $v['ready'] ? '' : 'cursor-not-allowed opacity-60' ?>"<?= $v['ready'] ? '' : ' disabled' ?> aria-pressed="<?= $active ? 'true' : 'false' ?>">
      <div class="flex h-12 w-12 items-center justify-center rounded-full <?= $active ? 'bg-primary-container/30' : 'bg-secondary-container/30' ?>">
        <span class="material-symbols-outlined text-3xl <?= $active ? 'text-primary' : 'text-secondary' ?>"><?= e($v['icon']) ?></span>
      </div>
      <span class="font-label-lg text-label-lg <?= $active ? 'text-primary' : 'text-on-surface-variant' ?> max-w-full truncate"><?= e($v['label']) ?> 목소리</span>
      <?php if (!$v['ready']): ?>
      <span class="rounded-full bg-surface-container-high px-2 py-0.5 text-[11px] font-bold text-on-surface-variant">준비 중</span>
      <?php endif; ?>
      <?php if ($active): ?><div class="absolute -right-1 -top-1 h-4 w-4 rounded-full border-2 border-surface-container-lowest bg-primary"></div><?php endif; ?>
    </button>
    <?php endforeach; ?>
    <?php $active = $device; ?>
    <button type="button" data-voice="device" class="relative flex <?= e($chipSize) ?> flex-col items-center gap-1.5 rounded-3xl border-2 bg-surface-container-lowest px-2 py-3 shadow-sm transition-all duration-200 active:scale-95 <?= $active ? 'border-primary-container' : 'border-transparent' ?>" aria-pressed="<?= $active ? 'true' : 'false' ?>">
      <div class="flex h-12 w-12 items-center justify-center rounded-full <?= $active ? 'bg-primary-container/30' : 'bg-secondary-container/30' ?>">
        <span class="material-symbols-outlined text-3xl <?= $active ? 'text-primary' : 'text-secondary' ?>">smartphone</span>
      </div>
      <span class="font-label-lg text-label-lg <?= $active ? 'text-primary' : 'text-on-surface-variant' ?>">기기 음성</span>
      <?php if ($active): ?><div class="absolute -right-1 -top-1 h-4 w-4 rounded-full border-2 border-surface-container-lowest bg-primary"></div><?php endif; ?>
    </button>
    <?php if (!$voices): ?>
    <a href="<?= e(url('/voice-lab/new')) ?>" class="relative flex <?= e($chipSize) ?> flex-col items-center gap-1.5 rounded-3xl border-2 border-dashed border-outline-variant bg-surface-container-low/60 px-2 py-3 transition-all duration-200 active:scale-95">
      <div class="flex h-12 w-12 items-center justify-center rounded-full bg-surface-container-lowest">
        <span class="material-symbols-outlined text-3xl text-primary">add</span>
      </div>
      <span class="font-label-lg text-label-lg text-primary">가족 목소리 만들기</span>
    </a>
    <?php endif; ?>
  </section>

  <!-- 진행 막대 -->
  <section class="space-y-3">
    <div id="pl-bar" class="relative h-3 w-full cursor-pointer touch-none overflow-hidden rounded-full bg-outline-variant/30" role="slider" tabindex="0" aria-label="재생 위치" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
      <div id="pl-fill" class="absolute left-0 top-0 h-full rounded-full bg-secondary shadow-sm" style="width: 0%"></div>
    </div>
    <div class="flex justify-between px-1 font-label-sm text-label-sm text-on-surface-variant">
      <span id="pl-cur">00:00</span>
      <span id="pl-total"><?= e(fmt_duration($totalMs)) ?></span>
    </div>
  </section>

  <!-- 재생 버튼 -->
  <section class="flex items-center justify-around">
    <button type="button" id="pl-back" class="flex h-12 w-12 items-center justify-center text-primary transition-all active:scale-90" aria-label="<?= $device ? '앞 문장' : '10초 뒤로' ?>">
      <span class="material-symbols-outlined text-4xl"><?= $device ? 'skip_previous' : 'replay_10' ?></span>
    </button>
    <button type="button" id="pl-play" class="flex h-20 w-20 items-center justify-center rounded-full border-b-4 border-primary-fixed-dim/40 bg-primary text-on-primary shadow-lg transition-all active:scale-90" aria-label="재생">
      <span id="pl-play-icon" class="material-symbols-outlined icon-fill text-5xl">play_arrow</span>
    </button>
    <button type="button" id="pl-fwd" class="flex h-12 w-12 items-center justify-center text-primary transition-all active:scale-90" aria-label="<?= $device ? '다음 문장' : '10초 앞으로' ?>">
      <span class="material-symbols-outlined text-4xl"><?= $device ? 'skip_next' : 'forward_10' ?></span>
    </button>
  </section>

  <!-- 질문하기 -->
  <button type="button" id="pl-ask" class="flex w-full items-center gap-4 rounded-3xl bg-secondary-container px-4 py-3 text-left text-on-secondary-container shadow-sm transition-all active:scale-[0.98] disabled:cursor-not-allowed disabled:opacity-60"<?= $qa['enabled'] ? '' : ' disabled' ?>>
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-secondary text-on-secondary shadow-sm">
      <span class="material-symbols-outlined icon-fill text-3xl">mic</span>
    </span>
    <span class="min-w-0 flex-1">
      <span class="block font-headline-md text-[20px] font-bold leading-7">질문하기</span>
      <span id="pl-ask-sub" class="block text-label-lg font-label-lg opacity-80"><?= e($askSub) ?></span>
    </span>
    <span class="material-symbols-outlined text-on-secondary-container/60">record_voice_over</span>
  </button>
</div>

<!-- 질문 화면(듣는 중 → 생각 중 → 답) -->
<div id="pl-ask-overlay" class="fixed inset-0 z-[60]" hidden>
  <div class="absolute inset-0 bg-inverse-surface/60 backdrop-blur-sm"></div>
  <div class="relative mx-auto flex h-full w-full max-w-[520px] flex-col justify-end px-margin-mobile pb-8">
    <div class="space-y-5 rounded-3xl bg-surface-container-lowest p-md text-center shadow-lg" role="dialog" aria-modal="true" aria-labelledby="pl-ask-title">
      <div data-step="listening" class="space-y-4">
        <button type="button" id="pl-rec" class="relative mx-auto flex h-28 w-28 items-center justify-center" aria-label="다 말했어요">
          <span class="pulse-ring absolute inset-0 rounded-full bg-secondary-container"></span>
          <span class="relative flex h-24 w-24 items-center justify-center rounded-full bg-secondary text-on-secondary shadow-lg">
            <span class="material-symbols-outlined icon-fill text-5xl">mic</span>
          </span>
        </button>
        <div id="pl-levels" class="flex h-8 items-end justify-center gap-1" aria-hidden="true"></div>
        <div class="space-y-1">
          <p id="pl-ask-title" class="text-headline-md font-headline-md text-on-surface">듣고 있어요</p>
          <p class="text-body-md text-on-surface-variant">다 말했으면 마이크를 한 번 더 눌러요</p>
        </div>
      </div>
      <div data-step="thinking" class="space-y-4 py-4" hidden>
        <div class="mx-auto flex h-24 w-24 items-center justify-center rounded-full bg-tertiary-container text-on-tertiary-container">
          <span class="material-symbols-outlined icon-fill animate-pulse text-5xl">emoji_objects</span>
        </div>
        <p class="text-headline-md font-headline-md text-on-surface">생각하고 있어요</p>
        <div class="flex justify-center gap-1.5" aria-hidden="true">
          <span class="h-2.5 w-2.5 animate-bounce rounded-full bg-primary"></span>
          <span class="h-2.5 w-2.5 animate-bounce rounded-full bg-primary [animation-delay:150ms]"></span>
          <span class="h-2.5 w-2.5 animate-bounce rounded-full bg-primary [animation-delay:300ms]"></span>
        </div>
      </div>
      <div data-step="answer" class="space-y-3 text-left" hidden>
        <div id="pl-q-row" class="flex items-end justify-end gap-2">
          <p id="pl-q" class="max-w-[80%] rounded-3xl rounded-br-md bg-primary-container px-4 py-3 text-body-md text-on-primary-container"></p>
          <?= child_avatar(\App\Core\Auth::child(), 'w-9 h-9 text-lg') ?>
        </div>
        <div class="flex items-end gap-2">
          <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container">
            <span id="pl-a-icon" class="material-symbols-outlined text-[20px]">face_3</span>
          </span>
          <p id="pl-a" class="max-w-[80%] rounded-3xl rounded-bl-md bg-secondary-container px-4 py-3 font-story text-body-lg text-on-secondary-container"></p>
        </div>
        <p class="pt-1 text-center text-label-sm font-label-sm text-on-surface-variant">답을 듣고 나면 이야기로 돌아갈게요</p>
      </div>
      <button type="button" id="pl-ask-cancel" class="btn-ghost w-full">그만하고 이야기 듣기</button>
    </div>
  </div>
</div>

<!-- 다 들었을 때 -->
<div id="pl-end" class="fixed inset-0 z-[60]" hidden>
  <div class="absolute inset-0 bg-inverse-surface/60 backdrop-blur-sm"></div>
  <div class="relative mx-auto flex h-full w-full max-w-[520px] flex-col justify-end px-margin-mobile pb-8">
    <div class="space-y-5 rounded-3xl bg-surface-container-lowest p-md text-center shadow-lg" role="dialog" aria-modal="true" aria-labelledby="pl-end-title">
      <div class="space-y-1">
        <p class="text-[40px] leading-none" aria-hidden="true">🎉</p>
        <p id="pl-end-title" class="text-headline-md font-headline-md text-on-surface">끝까지 다 들었어요!</p>
        <p id="pl-end-xp" class="text-label-lg font-label-lg text-primary" hidden></p>
      </div>
      <?php if ($manifest['next']): ?>
      <a href="<?= e($manifest['next']['url']) ?>" id="pl-next" class="flex items-center gap-3 rounded-2xl border border-surface-variant/40 bg-surface-container-low p-3 text-left">
        <img src="<?= e($manifest['next']['cover']) ?>" alt="" class="h-16 w-14 shrink-0 rounded-xl bg-surface-container object-cover">
        <span class="min-w-0 flex-1">
          <span class="block text-label-sm font-label-sm text-on-surface-variant">다음 이야기</span>
          <span class="block truncate text-label-lg font-label-lg text-on-surface"><?= e($manifest['next']['title']) ?></span>
          <span id="pl-next-count" class="block text-label-sm font-label-sm text-primary" hidden></span>
        </span>
        <span class="material-symbols-outlined icon-fill text-3xl text-primary">play_circle</span>
      </a>
      <?php endif; ?>
      <div class="grid grid-cols-2 gap-3">
        <button type="button" id="pl-again" class="btn-secondary px-3"><span class="material-symbols-outlined">replay</span>처음부터</button>
        <a href="<?= e(url('/home')) ?>" id="pl-home" class="btn-ghost px-3 bg-surface-container-low"><span class="material-symbols-outlined">home</span>홈으로</a>
      </div>
      <button type="button" id="pl-next-cancel" class="btn-ghost w-full" hidden>다음 이야기는 그만 들을래요</button>
    </div>
  </div>
</div>

<script type="application/json" id="player-data"><?= json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/recorder.js')) ?>"></script>
<script src="<?= e(asset('js/player.js')) ?>"></script>
<?php endsection(); ?>
