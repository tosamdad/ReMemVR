<?php
/**
 * 목소리 생성 관리(디자인 시안 _2): 상단 KPI, 상태 탭, 검색 필터, 요청 표, 오른쪽 상세 검수 패널.
 * 변수: rows, total, page, pages, filters, counts, kpi, estimate, detail(?array), elReady
 */
use App\Services\DashboardStats;

layout('admin/layout', ['title' => '목소리 생성 관리', 'active' => 'voices']);

$elTip = '이 기능은 ElevenLabs API 키가 등록되어야 사용할 수 있습니다';
$statusChip = [
    'pending' => ['대기 중', 'bg-secondary-container text-on-secondary-container'],
    'cloning' => ['모델 생성 중', 'bg-primary-fixed text-on-primary-fixed-variant'],
    'processing' => ['오디오 생성 중', 'bg-primary-fixed text-on-primary-fixed-variant'],
    'completed' => ['전체 완료', 'bg-surface-container-high text-on-surface-variant'],
    'rejected' => ['반려', 'bg-error-container text-on-error-container'],
    'failed' => ['실패', 'bg-error-container text-on-error-container'],
    'draft' => ['녹음 중', 'bg-surface-container-high text-on-surface-variant'],
];
$chip = static function ($status) use ($statusChip) {
    $c = isset($statusChip[$status]) ? $statusChip[$status] : [$status, 'bg-surface-container-high text-on-surface-variant'];

    return '<span class="whitespace-nowrap rounded-full px-2 py-0.5 font-label-sm text-label-sm ' . $c[1] . '">' . e($c[0]) . '</span>';
};
$gradeChip = [
    'primary' => 'bg-secondary-fixed text-on-secondary-fixed',
    'secondary' => 'bg-surface-container-high text-on-surface',
    'error' => 'bg-error-container text-on-error-container',
    'muted' => 'bg-surface-container-high text-on-surface-variant',
];
$query = array_filter($filters, static function ($v) {
    return $v !== '';
});
$pageUrl = static function ($path, array $extra = []) use ($query) {
    return url($path, array_filter(array_merge($query, $extra), static function ($v) {
        return $v !== '' && $v !== null;
    }));
};
$tabs = [
    '' => ['전체', 'all', 'bg-primary-container text-on-primary-container'],
    'pending' => ['대기 중', 'pending', 'bg-secondary-container text-on-secondary-container'],
    'cloning' => ['모델 생성 중', 'cloning', 'bg-primary-fixed text-on-primary-fixed-variant'],
    'processing' => ['사전 오디오 생성 중', 'processing', 'bg-surface-container-highest text-on-surface'],
    'completed' => ['완료', 'completed', 'bg-surface-container-low text-on-surface-variant'],
    'rejected' => ['반려', 'rejected', 'bg-error-container text-on-error-container'],
    'failed' => ['실패', 'failed', 'bg-error-container text-on-error-container'],
];
$storyCount = (int) $estimate['stories'];
$ready = $kpi['readiness'];
$selId = $detail ? (int) $detail['voice']['id'] : 0;
$from = ($page - 1) * 20 + 1;
$to = min($total, $page * 20);
?>
<div class="flex w-full flex-col gap-6">
  <!-- KPI -->
  <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
    <div class="flex items-center justify-between gap-3 rounded-xl bg-surface-container-lowest p-card-padding shadow-card">
      <div class="flex flex-col">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">신규 대기 건수</span>
        <span class="mt-1 font-headline-lg text-headline-lg font-bold text-secondary"><?= fmt_number($kpi['pending']) ?><span class="ml-1 font-body-md text-body-md font-normal text-on-surface-variant">건</span></span>
        <span class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= $kpi['avg_wait_sec'] !== null ? '평균 대기시간: ' . e(DashboardStats::koSpan($kpi['avg_wait_sec'])) : '기다리는 요청 없음' ?></span>
      </div>
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-secondary-fixed text-on-secondary-fixed"><span class="material-symbols-outlined text-[26px]">pending_actions</span></div>
    </div>
    <div class="flex items-center justify-between gap-3 rounded-xl bg-surface-container-lowest p-card-padding shadow-card">
      <div class="flex flex-col">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">ElevenLabs 생성 중</span>
        <span class="mt-1 font-headline-lg text-headline-lg font-bold text-primary"><?= fmt_number($kpi['cloning']) ?><span class="ml-1 font-body-md text-body-md font-normal text-on-surface-variant">건</span></span>
        <span class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant">Instant Voice Clone</span>
      </div>
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-fixed text-on-primary-fixed"><span class="material-symbols-outlined text-[26px]">neurology</span></div>
    </div>
    <div class="flex items-center justify-between gap-3 rounded-xl bg-surface-container-lowest p-card-padding shadow-card">
      <div class="flex flex-col">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">동화 오디오 준비율</span>
        <?php if ($ready['percent'] === null): ?>
        <span class="mt-1 font-headline-lg text-headline-lg font-bold text-on-surface-variant">-</span>
        <span class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= $ready['stories'] === 0 ? '게시된 동화가 없습니다' : '복제된 목소리가 없습니다' ?></span>
        <?php else: ?>
        <span class="mt-1 font-headline-lg text-headline-lg font-bold text-on-surface"><?= e(fmt_number($ready['percent'], 1)) ?><span class="ml-1 font-body-md text-body-md font-normal text-on-surface-variant">%</span></span>
        <span class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant">준비 <?= fmt_number($ready['ready']) ?> / <?= fmt_number($ready['expected']) ?> 파일 · 자체 서버 저장</span>
        <?php endif; ?>
      </div>
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-surface-container-high text-primary"><span class="material-symbols-outlined text-[26px]">cloud_sync</span></div>
    </div>
    <div class="relative flex flex-col justify-between overflow-hidden rounded-xl bg-surface-container p-card-padding shadow-card">
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-[20px] text-secondary">verified_user</span>
          <span class="font-label-md text-label-md font-bold text-on-surface">비용 방어 파이프라인</span>
        </div>
        <span class="shrink-0 rounded-full bg-surface-container-lowest px-2 py-0.5 font-label-sm text-label-sm text-secondary">활성됨</span>
      </div>
      <div class="mt-2 flex flex-col">
        <span class="font-label-sm text-label-sm text-on-surface-variant">사전 1회 일괄 생성 원칙 준수</span>
        <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-surface-container-highest"><div class="h-full w-full rounded-full bg-secondary"></div></div>
        <span class="mt-1 font-label-sm text-label-sm font-semibold text-secondary">재생 시 실시간 합성 호출 0건</span>
      </div>
    </div>
  </div>

  <div class="grid grid-cols-1 items-start gap-6 xl:grid-cols-12">
    <!-- 왼쪽: 탭, 필터, 표 -->
    <div class="flex min-w-0 flex-col gap-4 xl:col-span-8">
      <nav class="no-scrollbar flex items-center gap-1 overflow-x-auto rounded-xl bg-surface-container-lowest p-2 shadow-card" aria-label="상태">
        <?php foreach ($tabs as $key => $t):
            $on = $filters['status'] === $key;
            $href = url('/admin/voices', array_filter(array_merge($query, ['status' => $key]), static function ($v) { return $v !== ''; }));
        ?>
        <a href="<?= e($href) ?>" class="flex shrink-0 items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-2 font-label-md text-label-md transition-colors <?= $on ? 'bg-primary text-on-primary shadow-sm' : 'text-on-surface-variant hover:bg-surface-container-high' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
          <span><?= e($t[0]) ?></span>
          <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $t[2] ?>"><?= fmt_number($counts[$t[1]]) ?></span>
        </a>
        <?php endforeach; ?>
      </nav>

      <form method="get" action="<?= e(url('/admin/voices')) ?>" class="flex flex-wrap items-center gap-3 rounded-xl bg-surface-container-lowest p-4 shadow-card" role="search">
        <?php if ($filters['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($filters['status']) ?>"><?php endif; ?>
        <div class="relative min-w-[200px] flex-1">
          <span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant">search</span>
          <input type="search" name="q" value="<?= e($filters['q']) ?>" class="w-full rounded-lg border-0 bg-surface-container-low py-2.5 pl-10 pr-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-on-surface-variant/60 focus:bg-surface-container-lowest focus:ring-2 focus:ring-primary/30" placeholder="부모명, 자녀명, 이메일, 신청 ID(#REQ-0012) 또는 연락처 검색" aria-label="검색어">
        </div>
        <div class="flex flex-wrap items-center gap-2">
          <div class="flex items-center gap-1.5 rounded-lg bg-surface-container-low px-3 py-1">
            <span class="material-symbols-outlined text-[18px] text-on-surface-variant">calendar_today</span>
            <input type="date" name="from" value="<?= e($filters['from']) ?>" class="w-[128px] border-0 bg-transparent p-1 font-label-sm text-label-sm text-on-surface focus:ring-0" aria-label="신청일 시작">
            <span class="font-label-sm text-label-sm text-on-surface-variant">~</span>
            <input type="date" name="to" value="<?= e($filters['to']) ?>" class="w-[128px] border-0 bg-transparent p-1 font-label-sm text-label-sm text-on-surface focus:ring-0" aria-label="신청일 끝">
          </div>
          <select name="grade" class="rounded-lg border-0 bg-surface-container-low py-2 pl-3 pr-8 font-label-sm text-label-sm text-on-surface focus:ring-2 focus:ring-primary/30" aria-label="음질 등급" onchange="this.form.submit()">
            <option value="">음질 등급: 전체</option>
            <option value="good"<?= $filters['grade'] === 'good' ? ' selected' : '' ?>>우수 (SNR 25dB+)</option>
            <option value="fair"<?= $filters['grade'] === 'fair' ? ' selected' : '' ?>>보통 (재확인 권장)</option>
            <option value="poor"<?= $filters['grade'] === 'poor' ? ' selected' : '' ?>>불량 (노이즈 감지)</option>
          </select>
          <button type="submit" class="rounded-lg bg-primary px-3 py-2 font-label-sm text-label-sm text-on-primary hover:opacity-95">검색</button>
          <a href="<?= e(url('/admin/voices', $filters['status'] !== '' ? ['status' => $filters['status']] : [])) ?>" class="rounded-lg bg-surface-container-high p-2 text-on-surface-variant transition-colors hover:text-on-surface" title="필터 초기화, 새로 고침" aria-label="필터 초기화"><span class="material-symbols-outlined text-[20px]">refresh</span></a>
        </div>
      </form>

      <div class="overflow-hidden rounded-xl bg-surface-container-lowest shadow-card">
        <?php if (!$rows): ?>
        <div class="flex flex-col items-center gap-3 px-6 py-16 text-center">
          <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-container-high text-on-surface-variant"><span class="material-symbols-outlined text-[28px]"><?= $query ? 'search_off' : 'mic_none' ?></span></div>
          <p class="font-label-md text-label-md text-on-surface"><?= $query ? '조건에 맞는 목소리 요청이 없습니다' : '아직 목소리 생성 요청이 없습니다' ?></p>
          <p class="max-w-md font-body-md text-sm text-on-surface-variant"><?= $query ? '검색어나 기간, 음질 등급을 바꿔 보세요.' : '회원이 목소리 연구실에서 녹음을 마치고 제출하면 이곳에 표시됩니다.' ?></p>
          <?php if ($query): ?><a href="<?= e(url('/admin/voices')) ?>" class="a-btn-tonal mt-1">필터 초기화</a><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="relative overflow-x-auto">
          <table class="w-full border-collapse text-left">
            <thead>
              <tr class="bg-surface-container-low font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">
                <th class="px-2.5 py-3 font-label-sm">신청 ID / 대상</th>
                <th class="px-2.5 py-3 font-label-sm">음성 샘플 및 품질</th>
                <th class="px-2.5 py-3 font-label-sm">ElevenLabs 모델</th>
                <th class="px-2.5 py-3 font-label-sm"><?= $storyCount ?>편 사전 캐싱</th>
                <th class="px-2.5 py-3 text-right font-label-sm">수동 액션</th>
              </tr>
            </thead>
            <tbody class="font-body-md text-body-md text-on-surface">
            <?php foreach ($rows as $r):
                $id = (int) $r['id'];
                $sel = $id === $selId;
                $p = $r['progress'];
                $smp = $r['samples'];
                $grade = $r['grade'];
                $child = $r['child'];
                $age = $child ? child_age($child) : null;
                $hasVoice = (string) $r['provider_voice_id'] !== '';
                $name = $r['user_name'] . ' (' . $r['label'] . ')';
                $detailUrl = $pageUrl('/admin/voices/' . $id, ['page' => $page > 1 ? $page : null]);
                $contact = (string) $r['user_phone'] !== '' ? mask_phone($r['user_phone']) : mask_email($r['user_email']);
                $batchText = [
                    'none' => '미생성 상태',
                    'queued' => '대기열 등록됨',
                    'running' => '자체 서버에 저장 중',
                    'done' => '저장 완료',
                    'partial' => '일부 실패 ' . (int) $p['failed'] . '편',
                    'failed' => '생성 실패',
                ];
                $state = isset($batchText[$r['batch_status']]) ? $batchText[$r['batch_status']] : (string) $r['batch_status'];
                if ($p['stale'] > 0) {
                    $state = '옛 본문 ' . (int) $p['stale'] . '편 재생성 필요';
                }
            ?>
              <tr class="group cursor-pointer border-t border-surface-container-high transition-colors <?= $sel ? 'bg-primary/5 hover:bg-primary/10' : 'hover:bg-surface-container-low' ?>" data-href="<?= e($detailUrl) ?>">
                <td class="px-2.5 py-4 align-top">
                  <div class="flex flex-col">
                    <div class="flex flex-wrap items-center gap-2">
                      <a href="<?= e($detailUrl) ?>" class="font-label-sm text-label-sm font-bold <?= $sel ? 'text-primary' : 'text-on-surface-variant' ?> hover:underline"><?= e(DashboardStats::reqId($id)) ?></a>
                      <?= $chip($r['status']) ?>
                    </div>
                    <span class="mt-1 min-w-[112px] break-keep font-headline-md text-[16px] font-bold leading-6 text-on-surface"><?= e($name) ?></span>
                    <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($contact) ?></span>
                    <div class="mt-1 flex w-fit items-center gap-1.5 rounded bg-tertiary-fixed/60 px-2 py-0.5 text-on-tertiary-container">
                      <span class="material-symbols-outlined text-[14px]">child_care</span>
                      <span class="font-label-sm text-label-sm"><?= $child ? e(($age !== null ? '만 ' . $age . '세 ' : '') . $child['name']) : '자녀 미등록' ?></span>
                    </div>
                  </div>
                </td>
                <td class="px-2.5 py-4 align-top">
                  <div class="flex min-w-[148px] flex-col gap-2">
                    <?= partial('admin/voices/_player', ['src' => $smp['first_id'] ? url('/admin/media/sample/' . $smp['first_id']) : '', 'ms' => $smp['first_ms'], 'variant' => 'table', 'seed' => $id]) ?>
                    <div class="flex flex-wrap gap-1.5">
                      <span class="flex items-center gap-1 rounded-full bg-surface-container-high px-2 py-0.5 font-label-sm text-label-sm text-on-surface"><span class="material-symbols-outlined text-[13px] text-primary">timer</span><?= (int) $smp['count'] ?>개 · <?= e(DashboardStats::koDuration($smp['total_ms'])) ?></span>
                      <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $gradeChip[$grade['tone']] ?>"><?= e($grade['label']) ?></span>
                    </div>
                  </div>
                </td>
                <td class="px-2.5 py-4 align-top">
                  <div class="flex flex-col">
                    <?php if ($hasVoice): ?>
                    <span class="w-fit whitespace-nowrap rounded-md <?= $r['status'] === 'completed' ? 'bg-surface-container-high text-on-surface' : 'bg-primary-container text-on-primary-container' ?> px-2.5 py-1 font-label-sm text-label-sm font-bold" title="ElevenLabs voice_id(가림)"><?= e(DashboardStats::maskVoiceId($r['provider_voice_id'])) ?></span>
                    <span class="mt-1.5 font-label-sm text-label-sm text-on-surface-variant">복제 완료 (IVC)</span>
                    <?php if ($r['cloned_at']): ?><span class="font-label-sm text-label-sm text-on-surface-variant/80"><?= e(date('m.d H:i', strtotime($r['cloned_at']))) ?></span><?php endif; ?>
                    <?php elseif ($r['status'] === 'cloning'): ?>
                    <span class="flex w-fit items-center gap-1 rounded-md bg-primary-fixed px-2.5 py-1 font-label-sm text-label-sm text-on-primary-fixed-variant"><span class="material-symbols-outlined animate-spin text-[14px]">progress_activity</span>생성 중</span>
                    <span class="mt-1.5 font-label-sm text-label-sm text-on-surface-variant">ElevenLabs 응답 대기</span>
                    <?php else: ?>
                    <span class="flex w-fit items-center gap-1 rounded-md bg-surface-container-highest px-2.5 py-1 font-label-sm text-label-sm text-on-surface-variant"><span class="h-2 w-2 rounded-full bg-outline"></span> 미생성</span>
                    <?php if (in_array($r['status'], ['pending', 'failed', 'rejected'], true) && $storyCount > 0): ?>
                    <span class="mt-2 max-w-[104px] font-label-sm text-label-sm text-on-surface-variant" title="게시된 동화 <?= $storyCount ?>편 본문 글자 수 기준 예상치">예상 소모 약 <?= fmt_number($estimate['credits']) ?> 크레딧</span>
                    <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-2.5 py-4 align-top">
                  <div class="flex flex-col gap-1.5">
                    <?php if ($p['total'] > 0 && $p['completed'] >= $p['total'] && $p['stale'] === 0): ?>
                    <span class="flex items-center gap-1 font-label-sm text-label-sm font-bold text-primary"><span class="material-symbols-outlined text-[16px]">verified</span><?= (int) $p['total'] ?>편 전체 완료</span>
                    <div class="h-2 w-24 rounded-full bg-primary"></div>
                    <?php else: ?>
                    <div class="flex w-24 items-center justify-between font-label-sm text-label-sm">
                      <span class="<?= $p['completed'] > 0 ? 'font-bold text-primary' : 'text-on-surface-variant' ?>"><?= (int) $p['completed'] ?> / <?= (int) $p['total'] ?>편</span>
                      <?php if ($p['completed'] > 0): ?><span class="font-bold text-secondary"><?= (int) $p['percent'] ?>%</span><?php endif; ?>
                    </div>
                    <div class="h-2 w-24 overflow-hidden rounded-full bg-surface-container-high"><div class="h-full rounded-full bg-secondary <?= $r['batch_status'] === 'running' ? 'animate-pulse' : '' ?>" style="width: <?= (int) $p['percent'] ?>%"></div></div>
                    <?php endif; ?>
                    <span class="max-w-[112px] font-label-sm text-label-sm <?= in_array($r['batch_status'], ['partial', 'failed'], true) || $p['stale'] > 0 ? 'text-error' : 'text-on-surface-variant' ?>"><?= e($state) ?></span>
                  </div>
                </td>
                <td class="px-2.5 py-4 text-right align-top">
                  <div class="flex w-[118px] flex-col items-end gap-1.5">
                    <?php if (in_array($r['status'], ['pending', 'failed'], true) && !$hasVoice): ?>
                    <form method="post" action="<?= e(url('/admin/voices/' . $id . '/clone')) ?>" class="w-full" data-confirm="<?= e($name . ' 목소리로 ElevenLabs 목소리 생성을 시작할까요?') ?>">
                      <?= csrf_field() ?>
                      <button type="submit" class="flex w-full items-center justify-center gap-1 rounded-lg bg-primary px-2 py-1.5 text-center font-label-sm text-label-sm leading-tight text-on-primary shadow-sm transition-colors hover:bg-on-primary-fixed-variant disabled:cursor-not-allowed disabled:opacity-40"<?= $elReady ? '' : ' disabled title="' . e($elTip) . '"' ?>><span class="material-symbols-outlined shrink-0 text-[16px]">record_voice_over</span>ElevenLabs 모델 생성</button>
                    </form>
                    <?php elseif ($r['status'] === 'cloning'): ?>
                    <button type="button" class="flex w-full cursor-not-allowed items-center justify-center gap-1 rounded-lg bg-surface-container px-2 py-1.5 text-center font-label-sm text-label-sm leading-tight text-on-surface-variant/60" disabled><span class="material-symbols-outlined shrink-0 animate-spin text-[16px]">sync</span>모델 생성 중</button>
                    <?php endif; ?>

                    <?php if ($r['status'] === 'processing' || $r['batch_status'] === 'running' || $r['batch_status'] === 'queued'): ?>
                    <button type="button" class="flex w-full cursor-default items-center justify-center gap-1 rounded-lg bg-secondary px-2 py-1.5 text-center font-label-sm text-label-sm leading-tight text-on-secondary shadow-sm" disabled><span class="material-symbols-outlined shrink-0 animate-spin text-[16px]">sync</span>캐싱 진행중</button>
                    <?php elseif ($r['status'] !== 'rejected'): ?>
                    <form method="post" action="<?= e(url('/admin/voices/' . $id . '/batch')) ?>" class="w-full" data-confirm="<?= e('게시된 동화 ' . $storyCount . '편 중 아직 없는 오디오를 ' . $name . ' 목소리로 만들까요? ElevenLabs 크레딧이 소모됩니다.') ?>">
                      <?= csrf_field() ?>
                      <?php $canBatch = $hasVoice && $elReady && $storyCount > 0; ?>
                      <button type="submit" class="flex w-full items-center justify-center gap-1 rounded-lg px-2 py-1.5 text-center font-label-sm text-label-sm leading-tight transition-colors <?= $canBatch ? 'bg-surface-container-high text-on-surface hover:bg-surface-container-highest' : 'cursor-not-allowed bg-surface-container-high text-on-surface-variant/40' ?>"<?= $canBatch ? '' : ' disabled title="' . e(!$elReady ? $elTip : (!$hasVoice ? 'ElevenLabs 목소리를 먼저 생성하세요' : '게시된 동화가 없습니다')) . '"' ?>><span class="material-symbols-outlined shrink-0 text-[16px]">queue_music</span><?= $storyCount ?>편 일괄 생성</button>
                    </form>
                    <?php endif; ?>

                    <?php if ($hasVoice || $p['completed'] > 0): ?>
                    <button type="button" class="flex w-full items-center justify-center gap-1 rounded-lg bg-surface-container-high px-2 py-1.5 text-center font-label-sm text-label-sm leading-tight text-on-surface transition-colors hover:bg-surface-container-highest" data-audios-url="<?= e(url('/admin/api/voices/' . $id . '/audios')) ?>" data-audios-name="<?= e($name) ?>"><span class="material-symbols-outlined shrink-0 text-[16px]">visibility</span>캐시 파일 목록</button>
                    <?php endif; ?>
                    <?php if ($hasVoice && in_array($r['status'], ['processing', 'completed'], true)): ?>
                    <button type="button" class="rounded px-2.5 py-1 font-label-sm text-label-sm text-primary hover:bg-primary-fixed/30 disabled:cursor-not-allowed disabled:opacity-40" data-test-url="<?= e(url('/admin/voices/' . $id . '/test')) ?>" data-test-name="<?= e($name) ?>"<?= $elReady ? '' : ' disabled title="' . e($elTip) . '"' ?>>테스트 재생</button>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['cloning', 'processing', 'completed', 'failed'], true)): ?>
                    <form method="post" action="<?= e(url('/admin/voices/' . $id . '/refresh')) ?>">
                      <?= csrf_field() ?>
                      <button type="submit" class="rounded px-2.5 py-1 font-label-sm text-label-sm text-on-surface-variant hover:bg-surface-container">상태 강제 갱신</button>
                    </form>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['pending', 'failed', 'completed'], true)): ?>
                    <button type="button" class="rounded px-2.5 py-1 font-label-sm text-label-sm text-error transition-colors hover:bg-error-container hover:text-on-error-container" data-reject-url="<?= e(url('/admin/voices/' . $id . '/reject')) ?>" data-reject-name="<?= e($name) ?>">반려 / 재요청</button>
                    <?php elseif ($r['status'] === 'rejected'): ?>
                    <span class="max-w-[118px] text-right font-label-sm text-label-sm text-on-surface-variant" title="<?= e((string) $r['reject_reason']) ?>">재녹음 대기<?= $r['reject_reason'] ? ': ' . e(str_limit($r['reject_reason'], 24)) : '' ?></span>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
        <div class="flex flex-wrap items-center justify-between gap-3 bg-surface-container-low p-4">
          <span class="font-label-sm text-label-sm text-on-surface-variant"><?= $total > 0 ? '총 ' . fmt_number($total) . '개 신청건 중 ' . fmt_number($from) . ' - ' . fmt_number($to) . ' 표시' : '표시할 신청건이 없습니다' ?></span>
          <?php if ($pages > 1):
              $start = max(1, min($page - 2, $pages - 4));
              $end = min($pages, $start + 4);
          ?>
          <nav class="flex items-center gap-1" aria-label="페이지">
            <?php if ($page > 1): ?>
            <a href="<?= e($pageUrl('/admin/voices', ['page' => $page - 1])) ?>" class="flex h-8 w-8 items-center justify-center rounded bg-surface-container-lowest text-on-surface-variant hover:text-on-surface" aria-label="이전 페이지"><span class="material-symbols-outlined text-[18px]">chevron_left</span></a>
            <?php endif; ?>
            <?php for ($i = $start; $i <= $end; $i++): ?>
            <a href="<?= e($pageUrl('/admin/voices', ['page' => $i])) ?>" class="flex h-8 min-w-8 items-center justify-center rounded px-2 font-label-sm text-label-sm <?= $i === $page ? 'bg-primary text-on-primary' : 'bg-surface-container-lowest text-on-surface' ?>"<?= $i === $page ? ' aria-current="page"' : '' ?>><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $pages): ?>
            <a href="<?= e($pageUrl('/admin/voices', ['page' => $page + 1])) ?>" class="flex h-8 w-8 items-center justify-center rounded bg-surface-container-lowest text-on-surface-variant hover:text-on-surface" aria-label="다음 페이지"><span class="material-symbols-outlined text-[18px]">chevron_right</span></a>
            <?php endif; ?>
          </nav>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- 오른쪽: 상세 검수 패널 -->
    <div class="flex min-w-0 flex-col gap-4 xl:col-span-4">
      <?php if ($detail): ?>
      <?= partial('admin/voices/_detail', ['detail' => $detail, 'elReady' => $elReady, 'chip' => $chip, 'storyCount' => $storyCount, 'elTip' => $elTip]) ?>
      <?php else: ?>
      <div class="flex flex-col items-center gap-3 rounded-xl bg-surface-container-lowest p-card-padding py-12 text-center shadow-card">
        <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-surface-container-high text-on-surface-variant"><span class="material-symbols-outlined text-[28px]">fact_check</span></div>
        <p class="font-label-md text-label-md text-on-surface">상세 검수할 목소리가 없습니다</p>
        <p class="font-body-md text-sm text-on-surface-variant">표에서 신청건을 누르면 샘플 분석, 합성 파라미터, 처리 기록을 여기에서 볼 수 있습니다.</p>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?= partial('admin/voices/_modals', ['elReady' => $elReady]) ?>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/admin-voices.js')) ?>"></script>
<?php endsection(); ?>
