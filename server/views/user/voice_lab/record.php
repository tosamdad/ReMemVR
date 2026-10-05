<?php
/**
 * 안내된 녹음 화면: 1 준비(안내, 마이크 확인) → 2 녹음(대본 3개, 파일로 올리기) → 3 확인 & 제출(동의).
 * 변수: $voice, $scripts, $samples, $minSec, $recSec, $maxSec, $maxUpload(한 번에 올릴 수 있는 바이트)
 * 녹음, 저장, 삭제는 voice-lab.js 가 RMRecorder 와 /api/voice-lab/{id}/samples 로 처리한다.
 */
$pid = (int) $voice['id'];
layout('user/layout', [
    'title' => $voice['label'] . ' 목소리 녹음',
    'nav' => 'voice',
    'header' => 'sub',
    'back' => '/voice-lab',
    'headerTitle' => $voice['label'] . ' 목소리 녹음',
    'showNav' => false,
    'mainClass' => 'px-margin-mobile pt-5 pb-16',
]);
$q = isset($_GET['step']) ? (int) $_GET['step'] : 0;
if (errors('consent')) {
    $step = 3;
} elseif ($q >= 1 && $q <= 3) {
    $step = $q;
} else {
    $step = $samples ? 2 : 1;
}
$recSec = max($recSec, $minSec, 1);
$minPct = min(100, round($minSec * 100 / $recSec, 1));
$data = [
    'voiceId' => $pid,
    'step' => $step,
    'minMs' => $minSec * 1000,
    'recMs' => $recSec * 1000,
    'maxMs' => $maxSec * 1000,
    'maxTakeMs' => 90000,
    'maxUpload' => $maxUpload,
    'uploadUrl' => '/api/voice-lab/' . $pid . '/samples',
    'scripts' => array_values(array_map(static function ($s) {
        return ['key' => $s['key'], 'no' => $s['no'], 'title' => $s['title']];
    }, $scripts)),
    'samples' => $samples,
];
$uploadMb = rtrim(rtrim(number_format($maxUpload / 1048576, 1), '0'), '.');
$stepTabs = [1 => ['준비', 'tune'], 2 => ['녹음', 'mic'], 3 => ['확인 & 제출', 'send']];
?>
<script type="application/json" id="vl-record-data"><?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<div class="space-y-6" data-vl-record>

  <!-- 누구 목소리 -->
  <div class="flex items-center gap-4">
    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary-container/30 text-primary">
      <span class="material-symbols-outlined text-3xl"><?= e(voice_icon($voice)) ?></span>
    </div>
    <div class="min-w-0">
      <p class="truncate font-headline-md text-body-lg font-bold text-on-surface"><?= e($voice['label']) ?> 목소리</p>
      <p class="text-label-sm text-on-surface-variant">대본 3개를 차례로 읽어 녹음해 주세요.</p>
    </div>
  </div>

  <?php if ($voice['status'] === 'rejected'): ?>
    <div class="flex items-start gap-3 rounded-2xl bg-error-container px-4 py-4 text-on-error-container">
      <span class="material-symbols-outlined">replay</span>
      <div class="min-w-0 space-y-1">
        <p class="font-label-lg text-label-lg">다시 녹음이 필요해요</p>
        <?php if ((string) $voice['reject_reason'] !== ''): ?>
          <p class="text-[14px] leading-5"><?= e($voice['reject_reason']) ?></p>
        <?php endif; ?>
        <p class="text-label-sm opacity-80">문제가 된 녹음은 지우고 새로 녹음한 뒤 다시 제출해 주세요.</p>
      </div>
    </div>
  <?php endif; ?>

  <!-- 단계 -->
  <nav class="grid grid-cols-3 gap-2 rounded-2xl bg-surface-container-low p-1.5" aria-label="녹음 단계">
    <?php foreach ($stepTabs as $n => $t): ?>
      <button type="button" data-step-tab="<?= $n ?>" class="flex items-center justify-center gap-1 rounded-xl px-2 py-2.5 font-label-lg text-[13px] leading-5 transition-all <?= $n === $step ? 'bg-surface-container-lowest text-primary shadow-sm' : 'text-on-surface-variant' ?>">
        <span class="flex h-5 w-5 items-center justify-center rounded-full text-[11px] <?= $n === $step ? 'bg-primary text-on-primary' : 'bg-surface-container-high text-on-surface-variant' ?>" data-step-num><?= $n ?></span>
        <?= e($t[0]) ?>
      </button>
    <?php endforeach; ?>
  </nav>

  <!-- 녹음한 시간(녹음 단계에서는 대본이 위에 오도록 녹음 영역 아래로 옮긴다) -->
  <section class="card space-y-3 p-5" aria-live="polite" data-total-card>
    <div class="flex items-baseline justify-between">
      <p class="font-label-lg text-label-lg text-on-surface">녹음한 시간</p>
      <p class="font-label-lg text-label-lg text-on-surface-variant"><span class="text-[20px] font-bold text-primary" data-total>0:00</span> / 권장 <?= e(fmt_duration($recSec * 1000)) ?></p>
    </div>
    <div class="relative pt-1">
      <div class="h-3 w-full overflow-hidden rounded-full bg-surface-container">
        <div class="h-full rounded-full bg-primary transition-all duration-500" style="width:0%" data-total-bar></div>
      </div>
      <div class="absolute -bottom-1 top-0 w-0.5 rounded-full bg-on-surface/60" style="left:<?= e((string) $minPct) ?>%" aria-hidden="true"></div>
    </div>
    <div class="relative h-4 text-label-sm text-on-surface-variant">
      <span class="absolute -translate-x-1/2 whitespace-nowrap" style="left:<?= e((string) min(88, max(12, $minPct))) ?>%">최소 <?= e(fmt_duration($minSec * 1000)) ?></span>
    </div>
    <p class="flex items-start gap-1.5 text-[14px] leading-5 text-on-surface-variant" data-total-hint></p>
  </section>

  <!-- 1단계: 준비 -->
  <section class="space-y-5" data-step="1"<?= $step === 1 ? '' : ' hidden' ?>>
    <div class="space-y-5 rounded-[32px] bg-surface-container-low p-6">
      <h2 class="font-headline-md text-headline-md text-primary">녹음 전에 확인해요</h2>
      <ul class="space-y-4">
        <li class="flex items-start gap-4">
          <span class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm"><span class="material-symbols-outlined">volume_off</span></span>
          <div><p class="font-label-lg text-label-lg text-on-surface">조용한 곳에서 녹음해요</p><p class="text-sm text-on-surface-variant">TV, 선풍기, 세탁기 소리가 없는 방이 좋아요. 창문도 닫아 주세요.</p></div>
        </li>
        <li class="flex items-start gap-4">
          <span class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm"><span class="material-symbols-outlined">distance</span></span>
          <div><p class="font-label-lg text-label-lg text-on-surface">휴대폰은 입에서 15cm</p><p class="text-sm text-on-surface-variant">너무 가까우면 소리가 찌그러지고, 멀면 작게 녹음돼요.</p></div>
        </li>
        <li class="flex items-start gap-4">
          <span class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm"><span class="material-symbols-outlined">auto_stories</span></span>
          <div><p class="font-label-lg text-label-lg text-on-surface">아이에게 읽어 주듯 자연스럽게</p><p class="text-sm text-on-surface-variant">평소 말투 그대로가 가장 닮은 목소리를 만들어요.</p></div>
        </li>
        <li class="flex items-start gap-4">
          <span class="rounded-xl bg-surface-container-lowest p-2 text-secondary shadow-sm"><span class="material-symbols-outlined">sentiment_satisfied</span></span>
          <div><p class="font-label-lg text-label-lg text-on-surface">틀려도 괜찮아요</p><p class="text-sm text-on-surface-variant">잠깐 쉬었다가 그 문장부터 다시 읽으면 돼요. 마음에 들지 않으면 다시 녹음할 수 있어요.</p></div>
        </li>
      </ul>
    </div>

    <div class="card space-y-4 p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <p class="font-label-lg text-label-lg text-on-surface">마이크 확인</p>
          <p class="text-label-sm text-on-surface-variant">버튼을 누르고 "안녕, 우리 아가" 하고 말해 보세요.</p>
        </div>
        <button type="button" class="btn-secondary shrink-0 rounded-full px-4 py-2.5" data-mic-check>
          <span class="material-symbols-outlined text-[20px]">mic</span><span data-mic-check-label>확인하기</span>
        </button>
      </div>
      <div class="flex h-12 items-center justify-center gap-[3px] rounded-xl bg-surface-container-low px-3" data-mic-meter aria-hidden="true"></div>
      <p class="flex items-center gap-1.5 text-[14px] font-semibold leading-5 text-on-surface-variant" data-mic-status>
        <span class="material-symbols-outlined text-[18px]">info</span>마이크 권한을 물어보면 '허용'을 눌러 주세요.
      </p>
    </div>

    <div class="card divide-y divide-surface-container p-2">
      <?php foreach ($scripts as $s): ?>
        <div class="flex items-center gap-3 px-3 py-3">
          <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-tertiary-container/40 text-tertiary"><span class="material-symbols-outlined"><?= e($s['icon']) ?></span></span>
          <div class="min-w-0 flex-1">
            <p class="truncate font-label-lg text-label-lg text-on-surface">대본 <?= (int) $s['no'] ?>. <?= e($s['title']) ?></p>
            <p class="text-label-sm text-on-surface-variant"><?= e($s['kind']) ?> · 약 40~60초</p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <button type="button" class="btn-primary w-full rounded-full" data-goto="2">
      <span class="material-symbols-outlined">mic</span>녹음 시작하기
    </button>
  </section>

  <!-- 2단계: 녹음 -->
  <section class="space-y-5" data-step="2"<?= $step === 2 ? '' : ' hidden' ?>>
    <!-- 대본 고르기와 녹음기: 대본을 읽는 동안 언제든 멈출 수 있게 머리글 아래에 붙어 있고, 대본만 아래에서 스크롤된다. -->
    <div class="sticky top-14 z-40 -mx-margin-mobile space-y-3 bg-background px-margin-mobile py-2" data-recorder>
      <div class="no-scrollbar -mx-margin-mobile flex gap-2 overflow-x-auto px-margin-mobile pb-1" role="tablist" aria-label="대본 고르기">
        <?php foreach ($scripts as $s): ?>
          <button type="button" role="tab" data-script-tab="<?= e($s['key']) ?>" class="flex shrink-0 items-center gap-1.5 rounded-full border-2 border-surface-variant bg-surface-container-lowest px-4 py-2 font-label-lg text-label-lg text-on-surface-variant transition-all">
            <span class="material-symbols-outlined hidden text-[18px] text-secondary icon-fill" data-script-done>check_circle</span>
            대본 <?= (int) $s['no'] ?>
          </button>
        <?php endforeach; ?>
      </div>
      <div class="card p-4">
        <div class="flex items-center gap-4">
          <div class="relative h-16 w-16 shrink-0">
            <span class="pulse-ring absolute inset-0 hidden rounded-full bg-error/40" data-rec-ring aria-hidden="true"></span>
            <button type="button" class="relative flex h-16 w-16 items-center justify-center rounded-full bg-primary text-on-primary shadow-lg transition-all active:scale-90" data-rec-btn aria-label="녹음 시작">
              <span class="material-symbols-outlined icon-fill text-[34px]" data-rec-icon>mic</span>
            </button>
          </div>
          <div class="min-w-0 flex-1 space-y-1">
            <p class="font-headline-md text-[22px] font-bold tabular-nums leading-7 text-on-surface">
              <span data-timer>00:00</span><span class="text-[14px] font-semibold text-outline"> / 01:30</span>
            </p>
            <div class="flex h-8 items-center gap-[2px] overflow-hidden" data-meter aria-hidden="true"></div>
          </div>
        </div>
        <p class="mt-3 text-[14px] font-semibold leading-5 text-on-surface-variant" data-rec-state>버튼을 누르고 아래 대본을 소리 내어 읽어 주세요.</p>
      </div>
    </div>

    <!-- 방금 녹음한 것 확인 -->
    <div class="card space-y-4 p-6" data-review hidden>
      <div class="flex items-center justify-between gap-3">
        <p class="font-label-lg text-label-lg text-on-surface">방금 녹음한 목소리 <span class="font-semibold text-on-surface-variant" data-review-duration></span></p>
        <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 font-label-sm text-label-sm" data-review-badge></span>
      </div>
      <audio controls preload="auto" class="w-full" data-review-audio></audio>
      <p class="flex items-start gap-1.5 text-[14px] leading-5 text-on-surface-variant" data-review-hint></p>
      <div class="grid grid-cols-2 gap-3">
        <button type="button" class="btn-ghost rounded-full border-2 border-surface-variant" data-retake>
          <span class="material-symbols-outlined">replay</span>다시 녹음
        </button>
        <button type="button" class="btn-primary rounded-full py-3" data-save>
          <span class="material-symbols-outlined">save</span>저장
        </button>
      </div>
    </div>

    <?php foreach ($scripts as $s): ?>
      <article class="card max-h-[60vh] space-y-4 overflow-y-auto overscroll-contain p-6" data-script-panel="<?= e($s['key']) ?>" tabindex="0" hidden>
        <div class="flex items-center justify-between gap-2">
          <span class="inline-flex items-center gap-1.5 rounded-full bg-tertiary-container px-3 py-1.5 font-label-sm text-label-sm text-on-tertiary-container">
            <span class="material-symbols-outlined icon-fill text-[16px]"><?= e($s['icon']) ?></span><?= e($s['kind']) ?>
          </span>
          <span class="text-label-sm text-outline">대본 <?= (int) $s['no'] ?> / <?= count($scripts) ?></span>
        </div>
        <h2 class="font-headline-md text-[20px] font-bold leading-7 text-primary"><?= e($s['title']) ?></h2>
        <p class="flex items-start gap-1.5 rounded-xl bg-surface-container-low px-3 py-2.5 text-label-sm text-on-surface-variant">
          <span class="material-symbols-outlined text-[16px]">lightbulb</span><?= e($s['guide']) ?>
        </p>
        <p class="whitespace-pre-line break-keep font-story text-[21px] leading-[1.9] text-on-surface"><?= e($s['text']) ?></p>
      </article>
    <?php endforeach; ?>


    <!-- 파일로 올리기 -->
    <details class="card group overflow-hidden" data-upload-box>
      <summary class="flex cursor-pointer list-none items-center gap-3 p-5 [&::-webkit-details-marker]:hidden">
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-secondary-container text-on-secondary-container"><span class="material-symbols-outlined">upload_file</span></span>
        <span class="flex-1">
          <span class="block font-label-lg text-label-lg text-on-surface">파일로 올리기</span>
          <span class="block text-label-sm text-on-surface-variant">미리 녹음해 둔 음성 파일이 있다면 올려 주세요.</span>
        </span>
        <span class="material-symbols-outlined text-outline transition-transform group-open:rotate-180">expand_more</span>
      </summary>
      <div class="space-y-4 border-t border-surface-container p-5">
        <label class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-outline-variant px-4 py-6 text-center transition-colors hover:border-primary/50">
          <span class="material-symbols-outlined text-[32px] text-outline">audio_file</span>
          <span class="font-label-lg text-label-lg text-on-surface" data-file-name>음성 파일 고르기</span>
          <span class="text-label-sm text-on-surface-variant">wav, mp3, m4a, aac, ogg, webm, flac · <?= e($uploadMb) ?>MB 이하</span>
          <input type="file" accept="audio/*" class="sr-only" data-file-input>
        </label>
        <div class="space-y-3" data-file-result hidden>
          <div class="flex items-center justify-between gap-3">
            <p class="text-[14px] font-semibold leading-5 text-on-surface" data-file-info></p>
            <span class="inline-flex items-center gap-1 rounded-full px-3 py-1 font-label-sm text-label-sm" data-file-badge></span>
          </div>
          <p class="flex items-start gap-1.5 text-[14px] leading-5 text-on-surface-variant" data-file-hint></p>
          <button type="button" class="btn-secondary w-full rounded-full py-3" data-file-upload>
            <span class="material-symbols-outlined">cloud_upload</span>이 파일 올리기
          </button>
        </div>
      </div>
    </details>
  </section>

  <!-- 저장한 녹음(2, 3단계) -->
  <section class="space-y-3" data-samples-wrap<?= $step === 1 ? ' hidden' : '' ?>>
    <div class="flex items-end justify-between">
      <h3 class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">저장한 녹음</h3>
      <span class="font-label-sm text-label-sm text-outline" data-samples-count></span>
    </div>
    <ul class="space-y-3" data-samples></ul>
    <div class="flex flex-col items-center rounded-2xl border-2 border-dashed border-outline-variant px-4 py-6 text-center" data-samples-empty hidden>
      <span class="material-symbols-outlined mb-1 text-outline-variant">graphic_eq</span>
      <p class="text-[14px] leading-5 text-on-surface-variant">아직 저장한 녹음이 없어요.<br>대본을 읽고 저장하면 여기에 모여요.</p>
    </div>
    <button type="button" class="btn-secondary w-full rounded-full" data-goto="3" data-next-step hidden>
      다음: 확인 & 제출<span class="material-symbols-outlined">arrow_forward</span>
    </button>
  </section>

  <!-- 3단계: 확인 & 제출 -->
  <section class="space-y-5" data-step="3"<?= $step === 3 ? '' : ' hidden' ?>>
    <form method="post" action="<?= e(url('/voice-lab/' . $pid . '/submit')) ?>" class="card space-y-5 p-6" data-submit-form>
      <?= csrf_field() ?>
      <div class="flex items-start gap-3">
        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-container/40 text-primary"><span class="material-symbols-outlined">verified_user</span></span>
        <div>
          <h2 class="font-headline-md text-[20px] font-bold leading-7 text-on-surface">제출하기 전에</h2>
          <p class="text-[14px] leading-5 text-on-surface-variant">운영팀이 녹음 상태를 확인한 뒤 AI 목소리를 만들고, 무료 동화를 이 목소리로 미리 준비해 둘게요.</p>
        </div>
      </div>
      <label class="flex cursor-pointer items-start gap-3 rounded-2xl bg-surface-container-low p-4">
        <input type="checkbox" name="consent" value="1" class="mt-0.5 h-5 w-5 shrink-0 rounded border-outline text-primary focus:ring-primary/30" data-consent<?= old('consent') === '1' ? ' checked' : '' ?>>
        <span class="text-[14px] font-semibold leading-5 text-on-surface">본인 목소리이거나 목소리 주인에게 동의를 받았으며, AI 음성 생성과 동화 낭독에 사용하는 데 동의합니다</span>
      </label>
      <a href="<?= e(url('/settings/privacy')) ?>" class="inline-flex items-center gap-1 text-label-sm font-label-sm text-primary underline-offset-4 hover:underline">
        <span class="material-symbols-outlined text-[16px]">policy</span>개인정보 처리방침 보기
      </a>
      <?php if (errors('consent')): ?><p class="field-error"><?= e(errors('consent')) ?></p><?php endif; ?>
      <button type="submit" class="btn-primary w-full rounded-full" data-submit-btn disabled>
        <span class="material-symbols-outlined">send</span>목소리 제출하기
      </button>
      <p class="text-center text-label-sm text-on-surface-variant" data-submit-hint></p>
    </form>
  </section>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/recorder.js')) ?>"></script>
<script src="<?= e(asset('js/voice-lab.js')) ?>"></script>
<?php endsection(); ?>
