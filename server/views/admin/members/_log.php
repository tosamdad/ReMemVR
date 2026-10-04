<?php
/**
 * 대화 로그 창 본문(GET /admin/api/members/{id}/log 가 HTML 로 돌려준다).
 * 변수: log(user, child, latest, sessions[[session_id, started_at, story_title, voice_label, question_count, max_questions, items[]]], ids, count), code
 */
use App\Services\MemberStats;

$user = $log['user'];
$child = $log['child'];
$latest = $log['latest'];
if ($child) {
    $age = child_age($child);
    $title = $child['name'] . ($age !== null ? ' (' . $age . '세)' : '') . ' 인터랙션 상세 로그';
} else {
    $title = $user['name'] . ' 회원 인터랙션 상세 로그';
}
$subtitle = $latest
    ? '최근 감상 동화: ' . $latest['story_title'] . ' • ' . ($latest['voice_label'] ? $latest['voice_label'] . ' 목소리' : '기기 음성') . ' • 보호자 ' . $user['name']
    : '보호자 ' . $user['name'] . ' • 재생 기록 없음';
$lastGroup = $log['sessions'] ? $log['sessions'][count($log['sessions']) - 1] : null;
?>
<div class="flex items-start justify-between gap-4">
  <div class="flex items-center gap-3">
    <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[28px]">graphic_eq</span></div>
    <div class="min-w-0">
      <div class="flex flex-wrap items-center gap-2">
        <h3 class="font-headline-md text-headline-md font-bold text-on-surface"><?= e($title) ?></h3>
        <span class="rounded-full bg-surface-container-high px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= e($code) ?></span>
      </div>
      <p class="mt-0.5 font-label-md text-label-md text-on-surface-variant"><?= e($subtitle) ?></p>
    </div>
  </div>
  <button type="button" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-surface-container-low text-on-surface-variant transition-colors hover:bg-surface-container-high" data-close-log aria-label="닫기"><span class="material-symbols-outlined text-[20px]">close</span></button>
</div>

<div class="flex flex-col gap-4">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <span class="font-label-md text-label-md font-bold text-on-surface">발생한 끼어들기 및 가족 목소리 응답 기록</span>
    <span class="font-label-sm text-label-sm font-semibold text-secondary">답변 후 멈춘 문장부터 자동으로 이어 들려줍니다</span>
  </div>
  <?php if (!$log['sessions']): ?>
  <div class="flex flex-col items-center gap-2 rounded-xl bg-surface-container-low px-6 py-10 text-center">
    <span class="material-symbols-outlined text-[36px] text-outline">forum</span>
    <p class="font-label-md text-label-md text-on-surface">아직 질문 기록이 없습니다.</p>
    <p class="font-label-sm text-label-sm text-on-surface-variant">아이가 동화를 듣다가 질문하면 문장 위치, 질문, 답변이 순서대로 남습니다.</p>
  </div>
  <?php endif; ?>
  <?php foreach ($log['sessions'] as $g): ?>
  <div class="flex flex-col gap-4 rounded-xl bg-surface-container-low p-4">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-surface-variant/60 pb-2 font-label-sm text-label-sm text-on-surface-variant">
      <span><span class="font-bold text-on-surface"><?= e($g['story_title']) ?></span> · <?= e(date('m.d H:i', strtotime($g['started_at']))) ?> · <?= e($g['voice_label']) ?><?= $g['voice_label'] !== '기기 음성' ? ' 목소리' : '' ?></span>
      <span>질문 쿼터 <?= (int) $g['question_count'] ?>/<?= (int) $g['max_questions'] ?>회</span>
    </div>
    <?php foreach ($g['items'] as $it):
        $pos = $it['position_ms'] !== null ? fmt_duration($it['position_ms']) : null;
        $isAnswer = $it['mode'] === 'answer';
    ?>
    <div class="flex items-start gap-3">
      <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary/20 text-primary"><span class="material-symbols-outlined text-[16px]">auto_stories</span></div>
      <div class="flex max-w-[85%] flex-col">
        <span class="mb-1 font-label-sm text-label-sm text-on-surface-variant">동화 낭독 중<?= $pos !== null ? ' (' . e($pos) . ')' : '' ?> • <?= e($g['voice_label']) ?><?= $g['voice_label'] !== '기기 음성' ? ' 목소리 톤' : '' ?><?= $it['seq'] > 0 ? ' • ST-' . sprintf('%02d', $it['seq']) : '' ?></span>
        <div class="rounded-2xl rounded-tl-sm bg-surface-container-lowest p-3 font-body-md text-body-md text-on-surface shadow-sm"><?= $it['sentence'] !== '' ? '"...' . e($it['sentence']) . '"' : '<span class="text-on-surface-variant">문장 위치 정보가 없습니다.</span>' ?></div>
      </div>
    </div>
    <div class="flex items-start justify-end gap-3">
      <div class="flex max-w-[85%] flex-col items-end">
        <div class="mb-1 flex items-center gap-1.5">
          <span class="rounded-full bg-secondary px-2 py-0.5 font-label-sm text-label-sm text-on-secondary">Barge-in 감지</span>
          <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e(date('m.d H:i:s', strtotime($it['created_at']))) ?></span>
        </div>
        <div class="rounded-2xl rounded-tr-sm bg-secondary p-3.5 font-body-md text-body-md text-on-secondary shadow-sm"><?= $it['question_text'] !== '' ? '"' . e($it['question_text']) . '"' : '(질문 내용을 알아듣지 못했습니다)' ?></div>
        <div class="mt-1 flex flex-wrap items-center justify-end gap-2 font-label-sm text-label-sm text-on-surface-variant">
          <span>감정 분류: <?= $it['emotion'] !== '' ? e($it['emotion']) : '미분류' ?></span>
          <?php if ($it['has_question_audio']): ?>
          <button type="button" class="flex items-center gap-1 text-primary hover:underline" data-play-audio="<?= e(url('/admin/media/question/' . $it['id'])) ?>"><span class="material-symbols-outlined text-[16px]">play_circle</span>질문 음성</button>
          <?php endif; ?>
          <?php if ($it['reviewed']): ?><span class="flex items-center gap-0.5 text-primary"><span class="material-symbols-outlined text-[14px]">verified</span>검토됨</span><?php endif; ?>
        </div>
      </div>
      <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-secondary-fixed text-on-secondary-fixed"><span class="material-symbols-outlined text-[16px]">child_care</span></div>
    </div>
    <div class="flex items-start gap-3">
      <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full <?= $isAnswer ? 'bg-primary text-on-primary' : 'bg-surface-container-highest text-on-surface-variant' ?>"><span class="material-symbols-outlined text-[16px]"><?= $isAnswer ? 'record_voice_over' : 'shield' ?></span></div>
      <div class="flex max-w-[85%] flex-col">
        <div class="mb-1 flex flex-wrap items-center gap-2">
          <span class="font-label-sm text-label-sm font-bold <?= $isAnswer ? 'text-primary' : 'text-on-surface-variant' ?>"><?= $isAnswer ? e($g['voice_label'] !== '기기 음성' ? $g['voice_label'] . ' 목소리 AI 답변' : 'AI 답변 (기기 음성)') : e(MemberStats::modeLabel($it['mode'])) ?></span>
          <?php if ($it['latency_ms'] !== null): ?>
          <span class="rounded bg-primary-fixed px-1.5 font-label-sm text-label-sm text-on-primary-fixed">응답 <?= e(number_format($it['latency_ms'] / 1000, 2)) ?>초</span>
          <?php endif; ?>
        </div>
        <div class="rounded-2xl rounded-tl-sm bg-surface-container-lowest p-3 font-body-md text-body-md text-on-surface shadow-sm"><?= $it['answer_text'] !== '' ? '"' . e($it['answer_text']) . '"' : '<span class="text-on-surface-variant">답변 없음</span>' ?></div>
        <?php if ($it['error_message'] !== '' && !$isAnswer): ?>
        <span class="mt-1 font-label-sm text-label-sm text-error">원인: <?= e($it['error_message']) ?></span>
        <?php endif; ?>
        <div class="mt-2 flex flex-wrap items-center gap-3">
          <?php if ($it['has_answer_audio']): ?>
          <button type="button" class="flex items-center gap-1.5 rounded-lg bg-surface-container-high px-3 py-1.5 font-label-sm text-label-sm text-primary transition-colors hover:bg-primary-fixed" data-play-audio="<?= e(url('/admin/media/answer/' . $it['id'])) ?>"><span class="material-symbols-outlined text-[16px]">play_circle</span><span>합성 음성 들어보기</span></button>
          <?php else: ?>
          <span class="font-label-sm text-label-sm text-on-surface-variant">음성 파일 없음 · 화면에서 기기 음성으로 읽음</span>
          <?php endif; ?>
          <span class="font-label-sm text-label-sm text-on-surface-variant">스토리 자동 복귀<?= $pos !== null ? ' (' . e($pos) . ')' : '' ?></span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($log['count'] >= 30): ?>
  <p class="text-center font-label-sm text-label-sm text-on-surface-variant">최근 질문 30건까지 보여 줍니다.</p>
  <?php endif; ?>
</div>

<div class="flex flex-wrap items-center justify-between gap-3 pt-2">
  <div class="flex items-center gap-2">
    <?php if ($lastGroup): $full = $lastGroup['question_count'] >= $lastGroup['max_questions']; ?>
    <span class="h-2.5 w-2.5 rounded-full <?= $full ? 'bg-secondary-container' : 'bg-emerald-500' ?>"></span>
    <span class="font-label-md text-label-md text-on-surface-variant">질문 쿼터 <?= (int) $lastGroup['question_count'] ?>/<?= (int) $lastGroup['max_questions'] ?>회 소진 (<?= $full ? '한도 도달, 대체 응답 전환' : '정상 범위' ?>)</span>
    <?php else: ?>
    <span class="font-label-md text-label-md text-on-surface-variant">미검토 기록 없음</span>
    <?php endif; ?>
  </div>
  <div class="flex items-center gap-3">
    <button type="button" class="rounded-xl bg-surface-container-high px-5 py-2.5 font-label-md text-label-md text-on-surface transition-colors hover:bg-surface-variant" data-close-log>닫기</button>
    <button type="button" class="flex items-center gap-1.5 rounded-xl bg-primary px-5 py-2.5 font-label-md text-label-md text-on-primary shadow-md transition-all hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-50" data-review='<?= e(json_encode($log['ids'])) ?>'<?= $log['ids'] ? '' : ' disabled' ?>>
      <span class="material-symbols-outlined text-[18px]">verified</span><span>검토 완료<?= $log['ids'] ? ' (' . count($log['ids']) . '건)' : '' ?></span>
    </button>
  </div>
</div>
