<?php
/** 대시보드: 끼어들기 응답 지연(오늘, 2시간 단위 평균)과 단계별 평균, 질문당 비용. 변수: d */
use App\Services\DashboardStats;

$lat = $d['latency'];
$target = max(1, (int) $lat['target_ms']);
$maxBucket = 0;
foreach ($lat['buckets'] as $b) {
    if ($b['avg_ms'] !== null && $b['avg_ms'] > $maxBucket) {
        $maxBucket = $b['avg_ms'];
    }
}
$scale = max($target * 1.3, $maxBucket * 1.15, 1);
$targetPct = round($target * 100 / $scale, 1);
$met = $lat['avg_ms'] !== null && $lat['avg_ms'] <= $target;
?>
<div class="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
  <div class="flex flex-col">
    <h2 class="font-headline-md text-headline-md text-on-surface">끼어들기(Barge-in) 응답 지연 &amp; 시간대별 비용 방어</h2>
    <p class="font-body-md text-body-md text-on-surface-variant">아이 질문 녹음을 받은 뒤 Gemini 답변 작성과 ElevenLabs 음성 합성까지 걸린 시간 (오늘, 2시간 단위 평균)</p>
  </div>
  <div class="flex shrink-0 items-center gap-2">
    <?php if ($lat['avg_ms'] === null): ?>
    <span class="flex items-center gap-1 font-label-sm text-label-sm font-bold text-on-surface-variant"><span class="h-2 w-2 rounded-full bg-outline-variant"></span> 목표 <?= e(DashboardStats::koLatency($target)) ?> 이내</span>
    <?php else: ?>
    <span class="flex items-center gap-1 font-label-sm text-label-sm font-bold <?= $met ? 'text-primary' : 'text-error' ?>"><span class="h-2 w-2 rounded-full <?= $met ? 'bg-primary' : 'bg-error' ?>"></span> 평균 <?= e(DashboardStats::koLatency($lat['avg_ms'])) ?> (목표 <?= e(DashboardStats::koLatency($target)) ?> <?= $met ? '이내 충족' : '초과' ?>)</span>
    <?php endif; ?>
  </div>
</div>
<?php if ($lat['count'] === 0): ?>
<div class="flex h-56 flex-col items-center justify-center gap-3 rounded-2xl bg-surface-container-low/70 px-6 text-center">
  <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-container-high text-primary"><span class="material-symbols-outlined text-[28px]">speed</span></div>
  <?php if (!qa_available()): ?>
  <p class="font-label-md text-label-md text-on-surface">아이 질문 기능이 보류 중입니다</p>
  <p class="max-w-md font-body-md text-sm text-on-surface-variant">Gemini API 약관이 18세 미만이 이용하는 서비스에서의 사용을 금지해 질문 기능을 꺼 두었습니다. 아이 대상 사용을 허용하는 답변 AI 로 바꾸면 이곳에 응답 시간이 표시됩니다.</p>
  <?php else: ?>
  <p class="font-label-md text-label-md text-on-surface">오늘은 아직 답변한 질문이 없습니다</p>
  <p class="max-w-md font-body-md text-sm text-on-surface-variant">아이가 동화를 듣다가 질문하면 시간대별 평균 응답 시간이 막대로 표시됩니다. 점선은 목표 응답 시간(<?= e(DashboardStats::koLatency($target)) ?>)입니다.</p>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="relative h-56 w-full pt-6" role="img" aria-label="시간대별 평균 응답 지연 막대 그래프">
  <div class="relative flex h-full items-end gap-1.5 border-b border-surface-variant sm:gap-2">
    <div class="pointer-events-none absolute inset-x-0 z-10 border-t-2 border-dashed border-outline-variant" style="bottom: <?= $targetPct ?>%">
      <span class="absolute -top-5 right-0 font-label-sm text-[11px] text-outline">목표 응답 시간 (<?= e(DashboardStats::koLatency($target)) ?>)</span>
    </div>
    <?php foreach ($lat['buckets'] as $b):
        $cur = $b['index'] === $lat['current'];
        if ($b['avg_ms'] === null) {
            $h = 0;
        } else {
            $h = max(4, round($b['avg_ms'] * 100 / $scale, 1));
        }
        $over = $b['avg_ms'] !== null && $b['avg_ms'] > $target;
    ?>
    <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="<?= e($b['label'] . ' ~ ' . sprintf('%02d시', $b['index'] * 2 + 2) . ($b['avg_ms'] !== null ? ' · 평균 ' . DashboardStats::koLatency($b['avg_ms']) . ' · ' . $b['count'] . '건' : ' · 기록 없음')) ?>">
      <?php if ($b['avg_ms'] !== null): ?>
      <span class="whitespace-nowrap font-label-sm text-[11px] <?= $over ? 'text-error' : 'text-on-surface-variant' ?>"><?= e(number_format($b['avg_ms'] / 1000, 2)) ?>s</span>
      <div class="w-full max-w-[36px] rounded-t-lg <?= $over ? 'bg-secondary-container' : 'bg-primary' ?> <?= $cur ? 'ring-4 ring-secondary/20' : '' ?>" style="height: <?= $h ?>%"></div>
      <?php else: ?>
      <div class="h-1 w-full max-w-[36px] rounded-full bg-surface-container-high"></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<div class="flex gap-1.5 pt-2 sm:gap-2">
  <?php foreach ($lat['buckets'] as $b): ?>
  <span class="min-w-0 flex-1 text-center font-label-sm text-[11px] <?= $b['index'] === $lat['current'] ? 'font-bold text-secondary' : 'text-on-surface-variant' ?>"><?= $b['index'] === $lat['current'] ? '현재' : e($b['label']) ?></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<div class="mt-6 grid grid-cols-1 gap-4 rounded-2xl bg-surface-container-low p-4 sm:grid-cols-3">
  <div class="flex flex-col">
    <span class="font-label-sm text-label-sm text-on-surface-variant">TTS 평균 (답변 음성 합성)</span>
    <span class="mt-0.5 font-headline-md text-[20px] font-bold text-on-surface"><?= e($lat['tts_ms'] !== null ? fmt_number($lat['tts_ms']) . 'ms' : '-') ?></span>
    <span class="font-label-sm text-[11px] text-primary">ElevenLabs <?= e((string) setting('elevenlabs.model_answer', 'eleven_flash_v2_5')) ?></span>
  </div>
  <div class="flex flex-col">
    <span class="font-label-sm text-label-sm text-on-surface-variant">LLM 응답 생성 시간 (Gemini)</span>
    <span class="mt-0.5 font-headline-md text-[20px] font-bold text-on-surface"><?= e($lat['llm_ms'] !== null ? fmt_number($lat['llm_ms']) . 'ms' : '-') ?></span>
    <span class="font-label-sm text-[11px] text-primary"><?= e((string) setting('gemini.model', 'gemini-2.5-flash')) ?> · 음성 질문 이해 포함</span>
  </div>
  <div class="flex flex-col">
    <span class="font-label-sm text-label-sm text-on-surface-variant">질문당 평균 비용</span>
    <span class="mt-0.5 font-headline-md text-[20px] font-bold text-secondary"><?= e($lat['cost_per_question'] !== null ? '₩' . fmt_number($lat['cost_per_question'], 1) : '-') ?></span>
    <span class="font-label-sm text-[11px] text-on-secondary-fixed"><?= $lat['count'] > 0 ? '오늘 답변 ' . fmt_number($lat['count']) . '건 기준' : '오늘 답변 기록 없음' ?></span>
  </div>
</div>
