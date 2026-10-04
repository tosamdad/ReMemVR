<?php
/**
 * 회원 및 통계(디자인 시안 _1). 모든 숫자는 MemberStats 가 DB 에서 계산한다.
 * 변수: kpi, heatmaps, peaks, latency, list(rows, total, page, pages, per_page), filters(q, voice)
 */
use App\Services\MemberStats;

layout('admin/layout', ['title' => '회원 및 통계', 'active' => 'members']);

$pct = static function ($v) {
    return $v === null ? '–' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%';
};
$sec = static function ($ms, int $d = 2) {
    return $ms === null ? '–' : number_format($ms / 1000, $d);
};
$c = $kpi['completion'];
$b = $kpi['bargein'];
$l = $kpi['latency'];
$heatClass = static function (array $seg, bool $peak) {
    if ($peak) {
        return 'bg-secondary-container hover:bg-secondary';
    }
    $s = $seg['share'];
    if ($s <= 0) {
        return 'bg-primary/10 hover:bg-primary';
    }
    if ($s < 0.05) {
        return 'bg-primary/20 hover:bg-primary';
    }
    if ($s < 0.1) {
        return 'bg-primary/30 hover:bg-primary';
    }
    if ($s < 0.15) {
        return 'bg-primary/40 hover:bg-primary';
    }
    if ($s < 0.25) {
        return 'bg-primary/60 hover:bg-primary';
    }

    return 'bg-primary/80 hover:bg-primary';
};
$statusChip = [
    'active' => 'bg-emerald-100 text-emerald-800',
    'voice_needed' => 'bg-amber-100 text-amber-800',
    'voice_waiting' => 'bg-primary-fixed text-on-primary-fixed-variant',
    'blocked' => 'bg-error-container text-on-error-container',
    'withdrawn' => 'bg-surface-container-high text-on-surface-variant',
];
$query = array_filter($filters, static function ($v) {
    return $v !== '';
});
$stageTotal = max(1, (int) $latency['llm_avg'] + (int) $latency['mid_avg'] + (int) $latency['tts_avg']);
$rows = $list['rows'];
$from = $list['total'] ? ($list['page'] - 1) * $list['per_page'] + 1 : 0;
$to = min($list['total'], $list['page'] * $list['per_page']);
?>
<div class="flex w-full flex-col gap-8" data-members-page>
  <!-- 상단 지표 카드 -->
  <section class="grid grid-cols-1 gap-6 md:grid-cols-2 xl:grid-cols-4">
    <div class="relative flex flex-col justify-between overflow-hidden rounded-xl bg-surface-container-lowest p-card-padding shadow-md">
      <div class="pointer-events-none absolute -bottom-6 -right-6 h-32 w-32 rounded-full bg-primary-fixed/30 blur-2xl"></div>
      <div class="flex items-center justify-between">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Total Members</span>
        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-fixed text-on-primary-fixed-variant"><span class="material-symbols-outlined icon-fill text-[20px]">group</span></div>
      </div>
      <div class="mt-4">
        <div class="flex items-baseline gap-2">
          <span class="font-headline-lg text-headline-lg tracking-tight text-on-surface"><?= e(fmt_number($kpi['members'])) ?></span>
          <span class="font-body-md text-body-md text-on-surface-variant">명</span>
        </div>
        <div class="mt-3 flex items-center justify-between gap-2 font-label-sm text-label-sm text-on-surface-variant">
          <span>AI 목소리 등록률</span>
          <span class="font-bold text-primary"><?= $kpi['voice_rate'] === null ? '회원 없음' : e($pct($kpi['voice_rate'])) . ' (' . e(fmt_number($kpi['households'])) . '가구)' ?></span>
        </div>
        <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-primary/10">
          <div class="h-full rounded-full bg-primary transition-all duration-1000" style="width: <?= e(number_format((float) $kpi['voice_rate'], 1, '.', '')) ?>%;"></div>
        </div>
      </div>
    </div>

    <div class="relative flex flex-col justify-between overflow-hidden rounded-xl bg-surface-container-lowest p-card-padding shadow-md">
      <div class="pointer-events-none absolute -bottom-6 -right-6 h-32 w-32 rounded-full bg-secondary-fixed/40 blur-2xl"></div>
      <div class="flex items-center justify-between">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Book Completion</span>
        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-secondary-fixed text-on-secondary-fixed-variant"><span class="material-symbols-outlined icon-fill text-[20px]">menu_book</span></div>
      </div>
      <div class="mt-4">
        <div class="flex items-baseline gap-2">
          <span class="font-headline-lg text-headline-lg tracking-tight text-on-surface"><?= e($pct($c['rate'])) ?></span>
          <?php if ($c['delta'] !== null): $up = $c['delta'] >= 0; ?>
          <span class="inline-flex items-center font-label-sm text-label-sm <?= $up ? 'text-primary' : 'text-error' ?>" title="직전 30일 대비">
            <span class="material-symbols-outlined text-[16px]"><?= $up ? 'trending_up' : 'trending_down' ?></span><?= ($up ? '+' : '') . e(number_format($c['delta'], 1)) ?>%p
          </span>
          <?php endif; ?>
        </div>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant"><?= $c['started'] > 0 ? '동화 1회차 기준 평균 완독률 (최근 30일 ' . e(fmt_number($c['started'])) . '회)' : '최근 30일 재생 기록이 없습니다.' ?></p>
        <div class="mt-3 flex w-fit items-center gap-1.5 rounded-full bg-surface-container-low px-2.5 py-1 font-label-sm text-label-sm text-on-tertiary-container">
          <span class="material-symbols-outlined text-[14px]">bedtime</span>
          <span>취침 시간대(19~23시) 완독률 <?= e($pct($c['bedtime_rate'])) ?></span>
        </div>
      </div>
    </div>

    <div class="relative flex flex-col justify-between overflow-hidden rounded-xl bg-surface-container-lowest p-card-padding shadow-md">
      <div class="pointer-events-none absolute -bottom-6 -right-6 h-32 w-32 rounded-full bg-secondary-container/20 blur-2xl"></div>
      <div class="flex items-center justify-between">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Barge-in Interactivity</span>
        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-secondary-container/40 text-on-secondary-container"><span class="material-symbols-outlined icon-fill text-[20px]">record_voice_over</span></div>
      </div>
      <div class="mt-4">
        <div class="flex items-baseline gap-2">
          <span class="font-headline-lg text-headline-lg tracking-tight text-on-surface"><?= $b['per_session'] === null ? '–' : e(number_format($b['per_session'], 2)) ?></span>
          <span class="font-body-md text-body-md text-on-surface-variant">회 / 편당</span>
        </div>
        <div class="mt-2 flex items-center justify-between gap-2 font-label-sm text-label-sm">
          <span class="text-on-surface-variant">질문 한도 초과 시 대체 응답</span>
          <span class="font-bold text-secondary"><?= $b['quota_attempts'] > 0 ? e($pct($b['quota_rate'])) . ' (과몰입 방지)' : '초과 시도 없음' ?></span>
        </div>
        <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-secondary/10">
          <div class="h-full rounded-full bg-secondary transition-all duration-1000" style="width: <?= e(number_format((float) $b['quota_rate'], 1, '.', '')) ?>%;"></div>
        </div>
        <p class="mt-2 font-label-sm text-label-sm text-on-surface-variant">답변한 질문 평균 · 편당 <?= (int) $b['max_questions'] ?>회 한도 · 초과 <?= e(fmt_number($b['quota_attempts'])) ?>건</p>
      </div>
    </div>

    <div class="relative flex flex-col justify-between overflow-hidden rounded-xl bg-surface-container-lowest p-card-padding shadow-md">
      <div class="pointer-events-none absolute -bottom-6 -right-6 h-32 w-32 rounded-full bg-primary-container/20 blur-2xl"></div>
      <div class="flex items-center justify-between">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Perceived Latency (E2E)</span>
        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-container text-on-primary-container"><span class="material-symbols-outlined icon-fill text-[20px]">bolt</span></div>
      </div>
      <div class="mt-4">
        <div class="flex flex-wrap items-baseline gap-2">
          <span class="font-headline-lg text-headline-lg tracking-tight text-primary"><?= e($sec($l['avg_ms'])) ?><span class="ml-1 font-body-lg text-body-lg text-on-surface">초</span></span>
          <?php if ($l['avg_ms'] !== null): ?>
          <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $l['achieved'] ? 'bg-primary-fixed text-primary' : 'bg-error-container text-on-error-container' ?>">목표 &lt; <?= e(rtrim(rtrim(number_format($l['target_ms'] / 1000, 2), '0'), '.')) ?>s <?= $l['achieved'] ? '달성' : '미달' ?></span>
          <?php endif; ?>
        </div>
        <p class="mt-1 font-body-md text-body-md text-on-surface-variant"><?= $l['avg_ms'] === null ? '최근 30일 답변 기록이 없습니다.' : ($l['achieved'] ? '자연스러운 대화 템포 유지' : '응답이 목표보다 느립니다') ?></p>
        <div class="mt-3 flex items-center gap-2 font-label-sm text-label-sm text-on-surface-variant">
          <span class="h-2 w-2 rounded-full bg-primary"></span>
          <span><?= e($l['model_label']) ?> 적용 중</span>
        </div>
      </div>
    </div>
  </section>

  <!-- 히트맵 + 응답 지연 -->
  <section class="grid grid-cols-1 items-start gap-8 xl:grid-cols-12">
    <div class="flex flex-col gap-6 rounded-xl bg-surface-container-lowest p-card-padding shadow-md xl:col-span-7">
      <div class="flex flex-col justify-between gap-4 sm:flex-row sm:items-center">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm text-on-primary-fixed">Context Insights</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant">아이 질문 발생 지점 분석</span>
          </div>
          <h2 class="mt-1 font-headline-md text-headline-md text-on-surface">동화 구간별 끼어들기(Barge-in) 히트맵</h2>
        </div>
        <?php if ($heatmaps): ?>
        <select class="cursor-pointer rounded-lg border-0 bg-surface-container-low px-3 py-2 pr-8 font-label-md text-label-md text-on-surface focus:ring-2 focus:ring-primary/30" data-heatmap-select aria-label="동화 선택">
          <?php foreach ($heatmaps as $i => $h): ?>
          <option value="<?= $i ?>"><?= e($h['story']['title']) ?> (질문 <?= (int) $h['rank'] ?>위)</option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>

      <?php if (!$heatmaps): ?>
      <div class="flex flex-col items-center gap-2 rounded-xl bg-surface-container-low px-6 py-10 text-center">
        <span class="material-symbols-outlined text-[36px] text-outline">insights</span>
        <p class="font-label-md text-label-md text-on-surface">아직 질문 기록이 없습니다.</p>
        <p class="font-label-sm text-label-sm text-on-surface-variant">아이가 동화를 듣다가 질문하면 이야기 구간별 질문 분포가 여기에 표시됩니다.</p>
      </div>
      <?php endif; ?>
      <?php foreach ($heatmaps as $i => $h):
          $segs = $h['segments'];
          $peak = $h['peak'];
          $endLabel = $h['end_ms'] !== null ? '엔딩 (' . fmt_duration($h['end_ms']) . ')' : '엔딩 (ST-' . sprintf('%02d', $h['sentence_count']) . ')';
          $peakLabel = '인터랙션 피크 구간';
          if ($peak !== null) {
              $ps = $segs[$peak];
              $peakLabel .= ' (구간 ' . sprintf('%02d', $ps['index']) . ($ps['start_ms'] !== null ? ' · ' . fmt_duration($ps['start_ms']) . ($ps['end_ms'] !== null ? '~' . fmt_duration($ps['end_ms']) : '') : ' · ST-' . sprintf('%02d', (int) $ps['first_seq']) . '~' . sprintf('%02d', (int) $ps['last_seq'])) . ')';
          }
      ?>
      <div class="flex flex-col gap-4 rounded-xl bg-surface-container-low p-4<?= $i > 0 ? ' hidden' : '' ?>" data-heatmap="<?= $i ?>">
        <div class="flex items-center justify-between gap-2 font-label-sm text-label-sm text-on-surface-variant">
          <span>도입부 (<?= $h['has_times'] && $segs[0]['start_ms'] !== null ? e(fmt_duration($segs[0]['start_ms'])) : 'ST-01' ?>)</span>
          <span class="text-center font-bold text-secondary"><?= e($peakLabel) ?></span>
          <span><?= e($endLabel) ?></span>
        </div>
        <div class="grid h-14 grid-cols-10 items-end gap-1.5">
          <?php foreach ($segs as $k => $seg):
              $height = $h['max_count'] > 0 ? max(8, (int) round($seg['count'] * 100 / $h['max_count'])) : 8;
              $range = $seg['first_seq'] !== null ? 'ST-' . sprintf('%02d', $seg['first_seq']) . ($seg['last_seq'] !== $seg['first_seq'] ? '~' . sprintf('%02d', $seg['last_seq']) : '') : '문장 없음';
              $tip = '구간 ' . sprintf('%02d', $seg['index']) . ': 질문 ' . round($seg['share'] * 100) . '% (' . $seg['count'] . '회) · ' . $range;
          ?>
          <div class="group relative cursor-default rounded-md transition-all <?= $heatClass($seg, $k === $peak) ?>" style="height: <?= (int) $height ?>%;" title="<?= e($tip) ?>">
            <div class="absolute bottom-full left-1/2 z-20 mb-2 hidden -translate-x-1/2 whitespace-nowrap rounded bg-inverse-surface px-2 py-1 font-label-sm text-label-sm text-inverse-on-surface group-hover:block"><?= e($tip) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="flex justify-between gap-2 px-1 font-label-sm text-label-sm text-on-surface-variant">
          <span>01. <?= e($segs[0]['label'] ?: '시작') ?></span>
          <?php if ($peak !== null && $peak !== 0 && $peak !== 9): ?>
          <span class="font-semibold text-secondary"><?= sprintf('%02d', $peak + 1) ?>. <?= e($segs[$peak]['label']) ?> (최다)</span>
          <?php endif; ?>
          <span>10. <?= e($segs[9]['label'] ?: '끝') ?></span>
        </div>
        <p class="font-label-sm text-label-sm text-on-surface-variant">질문 <?= e(fmt_number($h['total'])) ?>회 · 문장 <?= (int) $h['sentence_count'] ?>개를 10구간으로 나눈 분포<?= $h['has_times'] ? '' : ' · 시각 정보 없음(문장 번호 표시)' ?></p>
      </div>
      <?php endforeach; ?>

      <div class="flex flex-col gap-3">
        <span class="font-label-md text-label-md text-on-surface">피크 장면 문맥 및 대표 아동 발화 패턴</span>
        <?php if (!$peaks): ?>
        <p class="rounded-xl bg-surface-container-low px-4 py-6 text-center font-label-sm text-label-sm text-on-surface-variant">질문이 쌓이면 가장 많이 물어본 장면과 대표 질문이 표시됩니다.</p>
        <?php else: ?>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <?php foreach ($peaks as $n => $p): ?>
          <div class="flex flex-col justify-between gap-2 rounded-xl bg-surface-container-high/60 p-4">
            <div>
              <div class="mb-1 flex items-center gap-2">
                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full font-label-sm text-label-sm font-bold <?= $n === 0 ? 'bg-secondary text-on-secondary' : 'bg-primary text-on-primary' ?>"><?= $n + 1 ?></span>
                <span class="font-label-md text-label-md font-bold text-on-surface"><?= e(($p['keyword'] !== '' ? $p['keyword'] : 'ST-' . sprintf('%02d', $p['seq'])) . ' 장면') ?><?= $p['time_ms'] !== null ? ' (' . e(fmt_duration($p['time_ms'])) . ')' : '' ?></span>
              </div>
              <p class="mb-2 font-label-sm text-label-sm text-on-surface-variant"><?= e($p['story_title']) ?> · ST-<?= sprintf('%02d', $p['seq']) ?> “<?= e(str_limit($p['sentence'], 34)) ?>”</p>
              <p class="font-body-md text-body-md italic text-on-surface-variant"><?= $p['question'] !== '' ? '"' . e($p['question']) . '"' : '인식된 질문 문장이 없습니다.' ?></p>
            </div>
            <div class="flex items-center justify-between gap-2 font-label-sm text-label-sm text-on-surface-variant">
              <span>감정 분류: <?= $p['emotion'] !== '' ? e($p['emotion']) . ' (' . (int) $p['emotion_pct'] . '%)' : '미분류' ?></span>
              <span class="font-semibold text-primary">발생: <?= e(fmt_number($p['count'])) ?>회</span>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="flex flex-col gap-6 rounded-xl bg-surface-container-lowest p-card-padding shadow-md xl:col-span-5">
      <div class="flex items-start justify-between gap-3">
        <div>
          <span class="rounded-full bg-secondary-fixed px-2.5 py-0.5 font-label-sm text-label-sm text-on-secondary-fixed">Real-time Stream Engine</span>
          <h2 class="mt-1 font-headline-md text-headline-md text-on-surface">E2E 응답 레이턴시</h2>
          <p class="font-label-sm text-label-sm text-on-surface-variant"><?= $latency['samples'] ? 'P50 ' . e($sec($latency['p50'])) . '초 · 최근 답변 ' . (int) $latency['samples'] . '건' : '측정할 답변 기록이 없습니다' ?></p>
        </div>
        <div class="text-right">
          <span class="font-headline-md text-headline-md font-bold text-secondary"><?= e($sec($latency['p95'])) ?>초</span>
          <p class="font-label-sm text-label-sm text-on-surface-variant">P95 측정치</p>
        </div>
      </div>
      <?php if (!$latency['samples']): ?>
      <div class="flex flex-col items-center gap-2 rounded-xl bg-surface-container-low px-6 py-10 text-center">
        <span class="material-symbols-outlined text-[36px] text-outline">speed</span>
        <p class="font-label-md text-label-md text-on-surface"><?= qa_available() ? '아직 AI 답변이 없습니다.' : '아이 질문 기능이 보류 중입니다.' ?></p>
        <p class="font-label-sm text-label-sm text-on-surface-variant"><?= qa_available() ? '아이 질문에 답한 기록이 생기면 단계별 처리 시간을 보여 줍니다.' : '아이 대상 사용을 허용하는 답변 AI 로 바꾸면 단계별 처리 시간을 보여 줍니다.' ?></p>
      </div>
      <?php else: ?>
      <div class="flex flex-col gap-4">
        <?php foreach ([
            ['graphic_eq', 'text-primary', 'bg-primary', '1단계: Gemini 음성 이해 + 답변', $latency['llm_avg'], '아이 질문 음성을 듣고 동화 맥락에 맞는 짧은 답을 만듭니다.'],
            ['verified_user', 'text-tertiary', 'bg-tertiary', '2단계: 질문 한도·안전 필터·저장', $latency['mid_avg'], '질문 횟수 확인, 금지어 필터, 질문 음성 저장과 기록에 걸린 시간입니다.'],
            ['record_voice_over', 'text-secondary', 'bg-secondary', '3단계: ElevenLabs 답변 음성 합성', $latency['tts_avg'], '등록된 가족 목소리(' . $kpi['latency']['model_label'] . ')로 답변 음성을 만듭니다.'],
        ] as $st): ?>
        <div class="flex flex-col gap-1.5">
          <div class="flex items-center justify-between gap-2 font-label-md text-label-md text-on-surface">
            <div class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px] <?= $st[1] ?>"><?= $st[0] ?></span><span><?= e($st[3]) ?></span></div>
            <span class="shrink-0 font-bold <?= $st[1] ?>"><?= $st[4] === null ? '–' : '~' . e(fmt_number($st[4])) . 'ms' ?></span>
          </div>
          <div class="flex h-3 w-full overflow-hidden rounded-full bg-surface-container-low">
            <div class="h-full rounded-full <?= $st[2] ?> transition-all duration-700" style="width: <?= e(number_format(min(100, (int) $st[4] * 100 / $stageTotal), 1, '.', '')) ?>%;"></div>
          </div>
          <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($st[5]) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-2 flex items-center justify-between gap-3 rounded-xl bg-surface-container-high/50 p-4">
        <div class="flex items-center gap-3">
          <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full <?= $latency['stable'] ? 'bg-primary/10 text-primary' : 'bg-error-container text-error' ?>"><span class="material-symbols-outlined text-[20px]"><?= $latency['stable'] ? 'thumb_up' : 'warning' ?></span></div>
          <div class="flex flex-col">
            <span class="font-label-md text-label-md font-bold text-on-surface"><?= $latency['stable'] ? '안정 권역 유지' : '주의 권역' ?></span>
            <span class="font-label-sm text-label-sm text-on-surface-variant">아동 집중 이탈 임계시간 (<?= e(number_format($latency['limit_ms'] / 1000, 1)) ?>초) <?= $latency['stable'] ? '대비 ' . (int) $latency['margin_pct'] . '% 여유' : '을 P95 가 ' . abs((int) $latency['margin_pct']) . '% 넘었습니다' ?></span>
          </div>
        </div>
        <span class="shrink-0 rounded-full bg-surface-container-lowest px-3 py-1 font-label-sm text-label-sm text-primary shadow-sm">P99: <?= e($sec($latency['p99'])) ?>s</span>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- 회원 인터랙션 관리 -->
  <section class="flex flex-col gap-6 rounded-xl bg-surface-container-lowest p-card-padding shadow-md" id="members">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
      <div>
        <div class="flex items-center gap-2">
          <h2 class="font-headline-md text-headline-md text-on-surface">회원 인터랙션 관리</h2>
          <span class="rounded-full bg-primary-fixed px-2 py-0.5 font-label-sm text-label-sm text-primary">총 <?= e(fmt_number($list['total'])) ?>명</span>
        </div>
        <p class="mt-0.5 font-body-md text-body-md text-on-surface-variant">자녀의 질문 반응 기록과 가족 목소리 등록 상태 모니터링</p>
      </div>
      <form method="get" action="<?= e(url('/admin/members')) ?>" class="flex flex-wrap items-center gap-3 xl:shrink-0 xl:flex-nowrap" data-member-filter>
        <label class="flex w-56 items-center rounded-xl bg-surface-container-low px-3 py-2">
          <span class="material-symbols-outlined mr-2 text-[20px] text-on-surface-variant">search</span>
          <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="부모/자녀 이름, 이메일, #RM-ID" class="w-full border-0 bg-transparent p-0 font-body-md text-body-md text-on-surface placeholder:text-outline focus:ring-0">
        </label>
        <select name="voice" class="cursor-pointer rounded-xl border-0 bg-surface-container-low px-3 py-2.5 pr-8 font-label-md text-label-md text-on-surface focus:ring-2 focus:ring-primary/30" data-auto-submit aria-label="목소리 유형">
          <option value="">전체 목소리 유형</option>
          <?php foreach (MemberStats::VOICE_FILTERS as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $filters['voice'] === $k ? ' selected' : '' ?>><?= e($k === 'none' ? '미등록(기본 음성)' : $label . ' 목소리 등록') ?></option>
          <?php endforeach; ?>
        </select>
        <a href="<?= e(url('/admin/members/export.csv', $query)) ?>" class="flex items-center gap-1.5 rounded-xl bg-surface-container-high px-4 py-2.5 font-label-md text-label-md text-on-surface shadow-sm transition-colors hover:bg-surface-variant">
          <span class="material-symbols-outlined text-[18px]">download</span><span class="whitespace-nowrap">로그 내보내기</span>
        </a>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-left">
        <thead>
          <tr class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
            <th class="whitespace-nowrap rounded-l-lg px-2.5 py-3">회원 (ID, 부모 이름)</th>
            <th class="whitespace-nowrap px-2.5 py-3">자녀 정보</th>
            <th class="whitespace-nowrap px-2.5 py-3">등록된 목소리</th>
            <th class="whitespace-nowrap px-2.5 py-3">주 이용 시간대</th>
            <th class="whitespace-nowrap px-2.5 py-3">총 청취 시간</th>
            <th class="whitespace-nowrap px-2.5 py-3">끼어들기 빈도</th>
            <th class="whitespace-nowrap px-2.5 py-3">상태</th>
            <th class="whitespace-nowrap rounded-r-lg px-2.5 py-3 text-center">상세 로그</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-container-high font-body-md text-body-md text-on-surface">
          <?php if (!$rows): ?>
          <tr><td colspan="8" class="px-4 py-12 text-center">
            <span class="material-symbols-outlined text-[36px] text-outline">person_search</span>
            <p class="mt-2 font-label-md text-label-md text-on-surface"><?= $query ? '조건에 맞는 회원이 없습니다.' : '아직 가입한 회원이 없습니다.' ?></p>
            <?php if ($query): ?><a href="<?= e(url('/admin/members')) ?>" class="font-label-sm text-label-sm text-primary hover:underline">검색 조건 지우기</a><?php endif; ?>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
              $u = $r['user'];
              $child = $r['children'] ? $r['children'][0] : null;
          ?>
          <tr class="group transition-colors hover:bg-surface-container-low/70">
            <td class="px-2.5 py-4">
              <a href="<?= e(url('/admin/members/' . (int) $u['id'])) ?>" class="flex items-baseline gap-2 whitespace-nowrap hover:text-primary"><span class="font-label-md text-label-md font-bold text-primary"><?= e($r['code']) ?></span><span class="font-semibold"><?= e($u['name']) ?></span></a>
              <span class="block max-w-[150px] truncate font-label-sm text-label-sm text-on-surface-variant" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></span>
            </td>
            <td class="px-2.5 py-4">
              <?php if ($child): ?>
              <div class="flex items-center gap-1.5">
                <span class="h-2 w-2 shrink-0 rounded-full bg-secondary"></span>
                <span><?= e(MemberStats::childText($child)) ?></span>
                <?php if (count($r['children']) > 1): ?><span class="rounded-full bg-surface-container-high px-1.5 font-label-sm text-label-sm text-on-surface-variant">+<?= count($r['children']) - 1 ?></span><?php endif; ?>
              </div>
              <?php else: ?>
              <span class="font-label-sm text-label-sm text-on-surface-variant">등록 자녀 없음</span>
              <?php endif; ?>
            </td>
            <td class="px-2.5 py-4">
              <div class="flex max-w-[124px] flex-wrap items-center gap-1">
                <?php $shown = 0; foreach ($r['voices'] as $v):
                    $ready = in_array($v['status'], MemberStats::VOICE_READY, true);
                    if (!$ready && !in_array($v['status'], MemberStats::VOICE_WAITING, true)) {
                        continue;
                    }
                    $shown++;
                    $elder = strpos($v['label'], '할머니') !== false || strpos($v['label'], '할아버지') !== false;
                ?>
                <?php if ($ready): ?>
                <span class="whitespace-nowrap rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $elder ? 'bg-secondary-fixed text-on-secondary-fixed' : 'bg-primary-fixed text-on-primary-fixed' ?>" title="<?= e(voice_status_label($v['status'])) ?>"><?= e($v['label']) ?></span>
                <?php else: ?>
                <span class="whitespace-nowrap rounded-full border border-outline-variant px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant" title="<?= e(voice_status_label($v['status'])) ?>"><?= e($v['label']) ?> · <?= e(voice_status_label($v['status'])) ?></span>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($shown === 0): ?>
                <span class="whitespace-nowrap rounded-full bg-surface-container-high px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant">미등록(기본 음성)</span>
                <?php endif; ?>
              </div>
            </td>
            <td class="px-2.5 py-4 text-on-surface-variant"><?php if ($r['hour'] === null): ?><span class="font-label-sm text-label-sm">재생 기록 없음</span><?php else: ?><span class="block whitespace-nowrap"><?= e(sprintf('%02d:00 ~ %02d:00', $r['hour'], ($r['hour'] + 1) % 24)) ?></span><span class="block">(<?= e(MemberStats::hourLabel($r['hour'])) ?>)</span><?php endif; ?></td>
            <td class="whitespace-nowrap px-2.5 py-4 font-semibold"><?= e(number_format($r['listened_hours'], 1)) ?> 시간</td>
            <td class="whitespace-nowrap px-2.5 py-4">
              <?php if ($r['per_story'] === null): ?>
              <span class="font-label-sm text-label-sm text-on-surface-variant">–</span>
              <?php else: ?>
              <div class="flex items-center gap-2">
                <span class="font-bold <?= $r['per_story'] >= 2 ? 'text-secondary' : 'text-on-surface' ?>"><?= e(number_format($r['per_story'], 1)) ?>회</span><span class="font-label-sm text-label-sm text-on-surface-variant">/편</span>
              </div>
              <?php endif; ?>
            </td>
            <td class="whitespace-nowrap px-2.5 py-4"><span class="rounded-full px-2.5 py-1 font-label-sm text-label-sm <?= $statusChip[$r['status']['key']] ?>"><?= e($r['status']['label']) ?></span></td>
            <td class="px-2.5 py-4 text-center">
              <button type="button" class="relative whitespace-nowrap rounded-lg bg-surface-container-high px-2.5 py-1.5 font-label-sm text-label-sm text-primary shadow-sm transition-all hover:bg-primary hover:text-on-primary disabled:cursor-not-allowed disabled:opacity-50" data-open-log="<?= (int) $u['id'] ?>"<?= $r['questions'] === 0 && $r['sessions'] === 0 ? ' disabled title="재생 기록이 없습니다"' : '' ?>>
                대화 로그<?php if ($r['unreviewed'] > 0): ?><span class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full bg-secondary-container ring-2 ring-surface-container-lowest" title="미검토 <?= (int) $r['unreviewed'] ?>건"></span><?php endif; ?>
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex flex-col items-center justify-between gap-4 pt-2 sm:flex-row">
      <span class="font-label-md text-label-md text-on-surface-variant"><?= $list['total'] ? e(fmt_number($list['total'])) . '명 중 ' . $from . ' - ' . $to . ' 표시 중' : '표시할 회원 없음' ?></span>
      <?php if ($list['pages'] > 1): ?>
      <?= partial('admin/members/_pager', ['page' => $list['page'], 'pages' => $list['pages'], 'base' => '/admin/members', 'query' => $query, 'anchor' => '#members']) ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<!-- 대화 로그 창 -->
<div class="fixed inset-0 z-50 hidden items-center justify-center bg-inverse-surface/40 p-4 backdrop-blur-sm" data-log-modal role="dialog" aria-modal="true" aria-label="인터랙션 상세 로그">
  <div class="flex max-h-[90vh] w-full max-w-2xl flex-col gap-6 overflow-y-auto rounded-2xl bg-surface-container-lowest p-6 shadow-2xl sm:p-8" data-log-body>
    <p class="py-12 text-center font-label-md text-label-md text-on-surface-variant">불러오는 중…</p>
  </div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/admin-members.js')) ?>"></script>
<?php endsection(); ?>
