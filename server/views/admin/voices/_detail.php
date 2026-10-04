<?php
/**
 * 목소리 관리: 오른쪽 상세 검수 패널.
 * 변수: detail(VoiceController::detail), elReady, chip(상태 칩 함수), storyCount, elTip
 */
use App\Services\DashboardStats;

$v = $detail['voice'];
$id = (int) $v['id'];
$smp = $v['samples'];
$grade = $v['grade'];
$child = $v['child'];
$age = $child ? child_age($child) : null;
$params = $detail['params'];
$progress = $detail['progress'];
$hasVoice = (string) $v['provider_voice_id'] !== '';
$canClone = in_array($v['status'], ['pending', 'rejected', 'failed'], true);
$name = $v['user_name'] . ' (' . $v['label'] . ' 목소리)';
$stories = $detail['stories'];
$checkedSec = 0;
$checkedN = 0;
foreach ($stories as $s) {
    if ($s['needs']) {
        $checkedSec += (int) $s['est_duration_sec'];
        $checkedN++;
    }
}
$audioState = [
    'none' => ['미생성', 'bg-surface-container-high text-on-surface-variant'],
    'pending' => ['대기', 'bg-secondary-fixed text-on-secondary-fixed'],
    'processing' => ['생성 중', 'bg-primary-fixed text-on-primary-fixed-variant'],
    'completed' => ['완료', 'bg-primary-fixed text-on-primary-fixed-variant'],
    'failed' => ['실패', 'bg-error-container text-on-error-container'],
    'outdated' => ['옛 본문', 'bg-error-container text-on-error-container'],
];
$levelClass = ['info' => 'text-inverse-on-surface/85', 'warn' => 'text-secondary-fixed', 'error' => 'text-error-container'];
$fmtSec = static function ($sec) {
    $sec = (int) $sec;
    if ($sec <= 0) {
        return '-';
    }

    return intdiv($sec, 60) . 'm ' . sprintf('%02d', $sec % 60) . 's';
};
$slider = static function ($key, $label, $value, $help, $accent) {
    $v = number_format((float) $value, 2, '.', '');

    return '<div class="flex flex-col gap-1">'
        . '<div class="flex justify-between font-label-sm text-label-sm"><label for="p-' . e($key) . '" class="text-on-surface-variant">' . e($label) . '</label>'
        . '<span class="font-bold text-on-surface" data-range-value="' . e($key) . '">' . e($v) . '</span></div>'
        . '<input id="p-' . e($key) . '" name="' . e($key) . '" class="h-1.5 w-full cursor-pointer rounded-lg bg-surface-container-high ' . $accent . '" type="range" min="0" max="1" step="0.05" value="' . e($v) . '" data-range="' . e($key) . '">'
        . '<span class="text-[11px] text-on-surface-variant/80">' . e($help) . '</span></div>';
};
?>
<!-- 헤더, 샘플 분석, 파라미터 -->
<div class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-card-padding shadow-card" data-voice-detail data-voice-id="<?= $id ?>">
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-secondary-container px-2 py-0.5 font-label-sm text-label-sm text-on-secondary-container">상세 검수 대상</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e(DashboardStats::reqId($id)) ?></span>
        <span data-live-status><?= $chip($v['status']) ?></span>
      </div>
      <h3 class="mt-1 font-headline-md text-headline-md text-on-surface"><?= e($name) ?></h3>
      <span class="font-label-sm text-label-sm text-on-surface-variant">아동: <?= $child ? e(($age !== null ? '만 ' . $age . '세 ' : '') . $child['name']) : '미등록' ?> | 신청: <?= e(date('Y-m-d H:i', strtotime($v['requested_at']))) ?></span>
    </div>
    <a href="<?= e(url('/admin/members', ['q' => $v['user_email']])) ?>" class="shrink-0 p-1 text-on-surface-variant hover:text-on-surface" title="회원 정보 보기" aria-label="회원 정보 보기"><span class="material-symbols-outlined text-[20px]">open_in_full</span></a>
  </div>

  <?php if ($v['status'] === 'rejected' && $v['reject_reason']): ?>
  <div class="flex items-start gap-2 rounded-xl bg-error-container/60 px-3 py-2.5 text-on-error-container">
    <span class="material-symbols-outlined text-[18px]">replay</span>
    <span class="font-label-sm text-label-sm font-semibold">반려 사유: <?= e($v['reject_reason']) ?></span>
  </div>
  <?php endif; ?>

  <!-- 업로드 샘플 정밀 분석 -->
  <div class="flex flex-col gap-3 rounded-xl bg-surface-container-low p-4">
    <div class="flex items-center justify-between gap-2">
      <span class="flex items-center gap-1.5 font-label-md text-label-md font-bold text-on-surface"><span class="material-symbols-outlined text-[18px] text-primary">graphic_eq</span>업로드 샘플 정밀 분석</span>
      <span class="font-label-sm text-label-sm font-bold <?= $grade['tone'] === 'error' ? 'text-error' : 'text-secondary' ?>"><?= $smp['snr'] !== null ? 'SNR ' . e(fmt_number($smp['snr'], 1)) . ' dB' : 'SNR 측정 없음' ?></span>
    </div>
    <div class="relative flex h-16 items-end gap-1 overflow-hidden rounded-lg bg-surface-container-lowest p-2" data-waveform<?= $smp['first_id'] ? ' data-src="' . e(url('/admin/media/sample/' . $smp['first_id'])) . '"' : '' ?>>
      <div class="absolute inset-x-0 top-1/2 h-px bg-surface-variant"></div>
      <span class="relative m-auto font-label-sm text-label-sm text-on-surface-variant/70" data-waveform-note><?= $smp['first_id'] ? '파형 분석 중…' : '샘플이 없습니다' ?></span>
    </div>
    <div class="grid grid-cols-2 gap-2 text-center">
      <div class="rounded bg-surface-container-lowest p-2">
        <span class="block font-label-sm text-label-sm text-on-surface-variant">샘플 길이</span>
        <span class="font-label-md text-label-md font-bold <?= $detail['length']['class'] ?>"><?= fmt_number(round($smp['total_ms'] / 1000)) ?>초 (<?= e($detail['length']['judge']) ?>)</span>
      </div>
      <div class="rounded bg-surface-container-lowest p-2">
        <span class="block font-label-sm text-label-sm text-on-surface-variant">클리핑</span>
        <span class="font-label-md text-label-md font-bold <?= $smp['clips'] > 0 ? 'text-error' : 'text-primary' ?>"><?= fmt_number($smp['clips']) ?>건 (<?= $smp['clips'] > 0 ? '주의' : '안전' ?>)</span>
      </div>
    </div>
    <span class="font-label-sm text-[11px] text-on-surface-variant">권장 <?= (int) $detail['length']['rec_sec'] ?>초 이상, 최소 <?= (int) $detail['length']['min_sec'] ?>초 · 품질 등급: <?= e($grade['label']) ?></span>
    <?php if ($detail['samples']): ?>
    <div class="flex flex-col gap-2 border-t border-surface-container-high pt-3">
      <?php $scripts = \App\Controllers\User\VoiceLabController::scripts();
      foreach ($detail['samples'] as $i => $s):
          $sg = DashboardStats::gradeInfo($s['snr_db'], $s['quality_grade']);
          $sk = (string) $s['script_key'];
          $scriptName = $sk === '' ? '' : (isset($scripts[$sk]) ? $scripts[$sk]['no'] . '. ' . $scripts[$sk]['title'] : $sk);
      ?>
      <div class="flex flex-col gap-1 rounded-lg bg-surface-container-lowest p-2">
        <div class="flex items-center justify-between gap-2 font-label-sm text-label-sm">
          <span class="min-w-0 text-on-surface">샘플 <?= $i + 1 ?><?= $scriptName !== '' ? ' · 대본 ' . e($scriptName) : '' ?><?= $s['source'] === 'upload' ? ' · 업로드' : '' ?></span>
          <span class="text-on-surface-variant"><?= $s['snr_db'] !== null ? 'SNR ' . e(fmt_number($s['snr_db'], 1)) . 'dB · ' : '' ?><?= e($sg['short']) ?><?= (int) $s['clip_count'] > 0 ? ' · 클리핑 ' . (int) $s['clip_count'] : '' ?></span>
        </div>
        <?= partial('admin/voices/_player', ['src' => url('/admin/media/sample/' . (int) $s['id']), 'ms' => (int) $s['duration_ms'], 'variant' => 'list', 'seed' => (int) $s['id']]) ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ElevenLabs 파라미터 조율 -->
  <form method="post" action="<?= e(url('/admin/voices/' . $id . '/params')) ?>" class="flex flex-col gap-3" data-params-form>
    <?= csrf_field() ?>
    <div class="flex items-center justify-between gap-2">
      <span class="font-label-md text-label-md font-bold text-on-surface">ElevenLabs 파라미터 조율</span>
      <span class="font-label-sm text-label-sm text-primary"><?= $params['custom'] ? '개별 값 적용 중' : '기본 권장값 적용' ?></span>
    </div>
    <?= $slider('stability', 'Stability (안정성)', $params['stability'], '높을수록 낭독이 차분해집니다. 동화 낭독 시 지나친 감정 왜곡 방지', 'accent-primary') ?>
    <?= $slider('similarity_boost', 'Similarity Boost (유사도)', $params['similarity_boost'], '부모 고유의 목소리 톤 일치 강조. 너무 높으면 녹음 잡음까지 따라 할 수 있습니다', 'accent-secondary') ?>
    <?= $slider('style', 'Style (표현 과장)', $params['style'], '0 에 가까울수록 안정적입니다. 높이면 감정 표현이 커지지만 발음이 흔들릴 수 있습니다', 'accent-primary') ?>
    <div class="flex items-center justify-between gap-3 rounded-lg bg-surface-container-low px-3 py-2.5">
      <div class="flex flex-col">
        <span class="font-label-sm text-label-sm text-on-surface">Speaker Boost</span>
        <span class="text-[11px] text-on-surface-variant/80">원래 목소리와 더 닮게 합니다(합성 시간이 조금 늘어남)</span>
      </div>
      <input type="hidden" name="speaker_boost" value="0">
      <label class="switch"><input type="checkbox" name="speaker_boost" value="1"<?= $params['speaker_boost'] ? ' checked' : '' ?> aria-label="Speaker Boost"><span></span></label>
    </div>
    <div class="flex gap-2">
      <button type="submit" class="a-btn-tonal flex-1 py-2"><span class="material-symbols-outlined text-[18px]">save</span>파라미터 저장</button>
      <?php if ($params['custom']): ?>
      <button type="submit" name="reset" value="1" class="a-btn-tonal py-2" title="설정의 기본값(안정성 <?= e(number_format($params['defaults']['stability'], 2)) ?>, 유사도 <?= e(number_format($params['defaults']['similarity_boost'], 2)) ?>)으로 되돌립니다">기본값</button>
      <?php endif; ?>
    </div>
    <?php if ($canClone): ?>
    <button type="submit" formaction="<?= e(url('/admin/voices/' . $id . '/clone')) ?>" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 font-label-md text-label-md text-on-primary shadow-md transition-all hover:bg-on-primary-fixed-variant disabled:cursor-not-allowed disabled:opacity-50" data-confirm="<?= e($name . '의 ElevenLabs Voice ID 를 생성할까요? 위 파라미터도 함께 저장됩니다.') ?>"<?= $elReady ? '' : ' disabled title="' . e($elTip) . '"' ?>>
      <span class="material-symbols-outlined text-[20px]">smart_toy</span>[수동 호출] ElevenLabs Voice ID 생성
    </button>
    <?php elseif ($hasVoice): ?>
    <div class="flex items-center justify-center gap-2 rounded-xl bg-surface-container-high py-3 font-label-md text-label-md text-on-surface-variant">
      <span class="material-symbols-outlined text-[20px] text-primary">verified</span>Voice ID 발급 완료: <?= e(DashboardStats::maskVoiceId($v['provider_voice_id'])) ?>
    </div>
    <?php else: ?>
    <div class="flex items-center justify-center gap-2 rounded-xl bg-primary-fixed py-3 font-label-md text-label-md text-on-primary-fixed-variant">
      <span class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>ElevenLabs Voice ID 생성 중
    </div>
    <?php endif; ?>
  </form>
</div>

<!-- 동화 일괄 사전 생성 체크리스트 -->
<form method="post" action="<?= e(url('/admin/voices/' . $id . '/batch')) ?>" class="flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-card-padding shadow-card" data-batch-form data-confirm="선택한 동화의 오디오를 이 목소리로 만들까요? ElevenLabs 크레딧이 소모됩니다.">
  <?= csrf_field() ?>
  <input type="hidden" name="selected" value="1">
  <div class="flex items-center justify-between gap-2">
    <div class="flex items-center gap-2">
      <span class="material-symbols-outlined text-[20px] text-primary">auto_stories</span>
      <span class="font-headline-md text-[17px] text-on-surface">무료 동화 <?= (int) $storyCount ?>편 일괄 캐싱</span>
    </div>
    <span class="shrink-0 rounded-full bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">대상 <?= (int) $storyCount ?>편</span>
  </div>
  <p class="font-label-sm text-label-sm leading-relaxed text-on-surface-variant">아이가 들을 때 실시간 API 를 부르지 않도록, Voice ID 가 생기면 게시된 동화 오디오를 한 번에 만들어 자체 서버에 저장합니다. 이미 최신 본문으로 만든 동화는 다시 만들지 않습니다.</p>
  <div class="flex items-center justify-between gap-2 font-label-sm text-label-sm">
    <span class="text-on-surface-variant">진행: <strong class="text-on-surface" data-live-progress><?= (int) $progress['completed'] ?> / <?= (int) $progress['total'] ?>편 완료</strong><?= $progress['failed'] > 0 ? ' · <span class="text-error">실패 ' . (int) $progress['failed'] . '편</span>' : '' ?></span>
    <span class="font-bold text-primary" data-live-percent><?= (int) $progress['percent'] ?>%</span>
  </div>
  <div class="h-2 w-full overflow-hidden rounded-full bg-surface-container-high"><div class="h-full rounded-full bg-primary transition-all duration-500" style="width: <?= (int) $progress['percent'] ?>%" data-live-bar></div></div>
  <?php if (!$stories): ?>
  <div class="flex flex-col items-center gap-2 rounded-lg bg-surface-container-low px-4 py-6 text-center">
    <span class="material-symbols-outlined text-[24px] text-on-surface-variant">menu_book</span>
    <p class="font-label-sm text-label-sm text-on-surface-variant">게시된 동화가 없습니다. 동화 콘텐츠 관리에서 먼저 게시하세요.</p>
    <a href="<?= e(url('/admin/stories')) ?>" class="font-label-sm text-label-sm font-bold text-primary hover:underline">동화 콘텐츠 관리로 이동</a>
  </div>
  <?php else: ?>
  <div class="flex items-center justify-between gap-2">
    <label class="flex cursor-pointer items-center gap-2 font-label-sm text-label-sm text-on-surface-variant"><input type="checkbox" class="rounded border-outline-variant text-primary focus:ring-primary/30" data-check-all>전체 선택</label>
    <span class="font-label-sm text-label-sm text-on-surface-variant" data-check-summary>선택 <?= $checkedN ?>편 · 약 <?= (int) round($checkedSec / 60) ?>분 분량</span>
  </div>
  <div class="flex max-h-64 flex-col gap-2 overflow-y-auto pr-1">
    <?php foreach ($stories as $i => $s): $st = $audioState[$s['audio_state']]; ?>
    <label class="flex cursor-pointer items-center justify-between gap-2 rounded-lg bg-surface-container-low p-2 font-label-sm text-label-sm transition-colors hover:bg-surface-container">
      <span class="flex min-w-0 items-center gap-2">
        <input type="checkbox" name="story_ids[]" value="<?= (int) $s['id'] ?>" class="rounded border-outline-variant text-primary focus:ring-primary/30" data-story-check data-sec="<?= (int) $s['est_duration_sec'] ?>"<?= $s['needs'] ? ' checked' : '' ?>>
        <span class="truncate text-on-surface"><?= sprintf('%02d', $i + 1) ?>. <?= e($s['title']) ?></span>
      </span>
      <span class="flex shrink-0 items-center gap-1.5">
        <span class="rounded-full px-1.5 py-0.5 text-[10px] <?= $st[1] ?>"><?= e($st[0]) ?></span>
        <span class="font-mono text-[11px] text-on-surface-variant"><?= e($fmtSec($s['est_duration_sec'])) ?></span>
      </span>
    </label>
    <?php endforeach; ?>
  </div>
  <label class="flex cursor-pointer items-center gap-2 font-label-sm text-label-sm text-on-surface-variant"><input type="checkbox" name="force" value="1" class="rounded border-outline-variant text-secondary focus:ring-secondary/30">이미 만든 오디오도 다시 생성 (크레딧 다시 소모)</label>
  <?php endif; ?>
  <?php $canBatch = $hasVoice && $elReady && $stories; ?>
  <button type="submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-secondary py-2.5 font-label-md text-label-md text-on-secondary shadow-sm transition-colors hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"<?= $canBatch ? '' : ' disabled title="' . e(!$elReady ? $elTip : (!$hasVoice ? 'ElevenLabs Voice ID 를 먼저 생성하세요' : '게시된 동화가 없습니다')) . '"' ?>>
    <span class="material-symbols-outlined text-[18px]">bolt</span>선택 동화 오디오 일괄 생성
  </button>
  <?php if ($hasVoice || $progress['completed'] > 0): ?>
  <div class="flex flex-wrap justify-center gap-2">
    <button type="button" class="a-btn-tonal py-1.5 text-[13px]" data-audios-url="<?= e(url('/admin/api/voices/' . $id . '/audios')) ?>" data-audios-name="<?= e($name) ?>"><span class="material-symbols-outlined text-[16px]">folder_open</span>캐시 파일 목록</button>
    <?php if ($hasVoice): ?>
    <button type="button" class="a-btn-tonal py-1.5 text-[13px]" data-test-url="<?= e(url('/admin/voices/' . $id . '/test')) ?>" data-test-name="<?= e($name) ?>"<?= $elReady ? '' : ' disabled title="' . e($elTip) . '"' ?>><span class="material-symbols-outlined text-[16px]">campaign</span>테스트 재생</button>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</form>

<!-- 처리 콘솔 -->
<div class="flex flex-col gap-3 rounded-xl bg-surface-container-lowest p-card-padding shadow-card">
  <div class="flex items-center justify-between gap-2">
    <span class="flex items-center gap-1.5 font-label-sm text-label-sm font-bold text-on-surface">
      <span class="h-2 w-2 rounded-full <?= $detail['active'] ? 'animate-pulse bg-primary' : 'bg-outline-variant' ?>" data-console-dot></span>처리 콘솔
    </span>
    <div class="flex items-center gap-2">
      <?php if ($detail['failed_jobs'] > 0): ?><span class="font-label-sm text-label-sm text-error">실패 작업 <?= (int) $detail['failed_jobs'] ?>건</span><?php endif; ?>
      <form method="post" action="<?= e(url('/admin/voices/' . $id . '/refresh')) ?>">
        <?= csrf_field() ?>
        <button type="submit" class="flex items-center gap-1 rounded px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant hover:bg-surface-container" title="작업과 오디오 상태로 다시 계산합니다"><span class="material-symbols-outlined text-[16px]">sync</span>상태 강제 갱신</button>
      </form>
    </div>
  </div>
  <div class="console-log flex h-48 flex-col gap-1 overflow-y-auto rounded-lg bg-inverse-surface p-3 text-[12px] leading-relaxed text-inverse-on-surface"
       data-console data-logs-url="<?= e(url('/admin/api/voices/' . $id . '/logs')) ?>" data-active="<?= $detail['active'] ? '1' : '0' ?>"
       data-after="<?= $detail['logs'] ? (int) end($detail['logs'])['id'] : 0 ?>" aria-live="polite">
    <?php if (!$detail['logs']): ?>
    <span class="text-inverse-on-surface/60" data-console-empty>[대기] 아직 처리 기록이 없습니다. 승인하면 작업 진행 상황이 여기에 표시됩니다.</span>
    <?php endif; ?>
    <?php foreach ($detail['logs'] as $l): ?>
    <span class="<?= isset($levelClass[$l['level']]) ? $levelClass[$l['level']] : $levelClass['info'] ?>">[<?= e($l['time']) ?>] <?= e($l['message']) ?></span>
    <?php endforeach; ?>
  </div>
  <span class="font-label-sm text-[11px] text-on-surface-variant" data-console-note><?= $detail['active'] ? '진행 중: 3초마다 새 기록을 불러오고 작업 처리기를 함께 돌립니다.' : '진행 중인 작업이 없습니다.' ?></span>
</div>

<!-- 회원 메모 -->
<form method="post" action="<?= e(url('/admin/voices/' . $id . '/memo')) ?>" class="flex flex-col gap-3 rounded-xl bg-surface-container-lowest p-card-padding shadow-card">
  <?= csrf_field() ?>
  <div class="flex items-center justify-between gap-2">
    <span class="flex items-center gap-1.5 font-label-md text-label-md font-bold text-on-surface"><span class="material-symbols-outlined text-[18px] text-secondary">sticky_note_2</span>회원 메모</span>
    <span class="font-label-sm text-label-sm text-on-surface-variant">관리자만 볼 수 있습니다</span>
  </div>
  <div class="grid grid-cols-2 gap-2 font-label-sm text-label-sm">
    <div class="rounded-lg bg-surface-container-low p-2"><span class="block text-on-surface-variant">이메일</span><span class="break-all text-on-surface"><?= e(mask_email($v['user_email'])) ?></span></div>
    <div class="rounded-lg bg-surface-container-low p-2"><span class="block text-on-surface-variant">연락처</span><span class="text-on-surface"><?= e((string) $v['user_phone'] !== '' ? mask_phone($v['user_phone']) : '-') ?></span></div>
  </div>
  <label for="voice-memo" class="sr-only">회원 메모</label>
  <textarea id="voice-memo" name="memo" rows="3" maxlength="2000" class="a-input resize-y" placeholder="예) 아이가 무서운 장면에 민감함. 재녹음 안내 전화 완료(10/2)"><?= e((string) $v['user_memo']) ?></textarea>
  <button type="submit" class="a-btn-tonal self-end py-2"><span class="material-symbols-outlined text-[18px]">save</span>메모 저장</button>
</form>
