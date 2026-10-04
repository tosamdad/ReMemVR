<?php
/**
 * 서비스 오퍼레이션 현황(디자인 시안 _3). 변수: d(DashboardStats::collect), s(글자), w(막대 너비)
 * data-stat 요소는 admin-dashboard.js 가 30초마다 /admin/api/dashboard 값으로 바꾼다.
 */
layout('admin/layout', ['title' => '대시보드', 'active' => 'dashboard']);
$elReady = $d['elevenlabs_ready'];
$c = $d['cost'];
?>
<div class="flex w-full flex-col" data-dashboard data-api="<?= e(url('/admin/api/dashboard')) ?>">
  <!-- 제목과 주요 버튼 -->
  <div class="mb-8 flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
    <div class="flex flex-col gap-1">
      <div class="flex flex-wrap items-center gap-2">
        <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm text-on-primary-fixed">실시간 데이터 연동됨</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant">• 30초마다 자동 갱신</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant/70">(마지막 갱신 <span data-stat="generated_at"><?= e($s['generated_at']) ?></span>)</span>
      </div>
      <h1 class="font-headline-lg text-headline-lg tracking-tight text-on-surface">서비스 오퍼레이션 현황</h1>
      <p class="font-body-md text-body-md text-on-surface-variant">가족 음성 복제(ElevenLabs) 및 아이 질문(Barge-in) 파이프라인 모니터링</p>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <a href="<?= e(url('/admin/settings')) ?>" class="flex items-center gap-2 rounded-xl bg-surface-container-high px-4 py-2.5 font-label-md text-label-md text-on-surface transition-colors hover:bg-surface-variant">
        <span class="material-symbols-outlined text-[18px]">tune</span><span>파라미터 조정</span>
      </a>
      <form method="post" action="<?= e(url('/admin/voices/approve-all')) ?>" data-confirm="품질 등급이 '좋음' 또는 '보통'인 검토 대기 목소리를 모두 승인하고 ElevenLabs 목소리 생성을 시작할까요?">
        <?= csrf_field() ?>
        <button type="submit" class="flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 font-label-md text-label-md text-on-primary shadow-sm transition-all hover:opacity-95 disabled:cursor-not-allowed disabled:opacity-50"<?= $elReady ? ' title="품질 등급이 좋음, 보통인 대기 목소리를 한 번에 승인합니다"' : ' disabled title="ElevenLabs API 키가 등록되지 않아 사용할 수 없습니다"' ?>>
          <span class="material-symbols-outlined text-[18px]">verified</span>
          <span>일괄 승인 모드</span>
          <span class="rounded-full bg-on-primary/20 px-2 py-0.5 font-label-sm text-label-sm" data-stat="approvable"><?= e($s['approvable']) ?></span>
        </button>
      </form>
    </div>
  </div>

  <!-- KPI 카드 4개 -->
  <div class="mb-8 grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-4">
    <div class="relative flex flex-col justify-between overflow-hidden rounded-2xl bg-surface-container-lowest p-6 shadow-card">
      <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Voice Pipeline Queue</span>
          <span class="font-headline-md text-headline-md font-bold text-on-surface">목소리 생성 대기열</span>
        </div>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-fixed text-on-primary-fixed-variant"><span class="material-symbols-outlined text-[22px]">record_voice_over</span></div>
      </div>
      <div class="mt-4 flex items-baseline gap-2">
        <span class="font-headline-lg text-headline-lg font-bold text-primary" data-stat="voices.pending"><?= e($s['voices.pending']) ?></span>
        <span class="font-label-md text-label-md text-on-surface-variant">건 대기 중</span>
      </div>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-2 pt-3 text-on-surface-variant">
        <div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-secondary-container"></span><span class="font-label-sm text-label-sm">생성 중 <strong class="text-on-surface" data-stat="voices.in_progress"><?= e($s['voices.in_progress']) ?></strong></span></div>
        <div class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary"></span><span class="font-label-sm text-label-sm">금일 승인 완료 <strong class="text-on-surface" data-stat="voices.approved_today"><?= e($s['voices.approved_today']) ?></strong></span></div>
      </div>
    </div>

    <div class="relative flex flex-col justify-between overflow-hidden rounded-2xl bg-surface-container-lowest p-6 shadow-card">
      <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Zero-Cost Playback</span>
          <span class="font-headline-md text-headline-md font-bold text-on-surface">당일 동화 재생 수</span>
        </div>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-container-high text-primary"><span class="material-symbols-outlined text-[22px]">play_circle</span></div>
      </div>
      <div class="mt-4 flex items-baseline gap-2">
        <span class="font-headline-lg text-headline-lg font-bold text-on-surface" data-stat="plays.total"><?= e($s['plays.total']) ?></span>
        <span class="font-label-md text-label-md text-on-surface-variant">회 청취</span>
      </div>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-2 pt-3">
        <span class="rounded-full bg-primary-fixed px-2 py-0.5 font-label-sm text-label-sm text-on-primary-fixed-variant">재생 비용 ₩0</span>
        <span class="font-label-sm text-label-sm font-semibold text-on-surface" data-stat="plays.voice_share"><?= e($s['plays.voice_share']) ?></span>
      </div>
    </div>

    <div class="relative flex flex-col justify-between overflow-hidden rounded-2xl bg-surface-container-lowest p-6 shadow-card">
      <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Real-time Barge-In</span>
          <span class="font-headline-md text-headline-md font-bold text-on-surface">실시간 질문 인터랙션</span>
        </div>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-secondary-fixed text-on-secondary-fixed"><span class="material-symbols-outlined text-[22px]">forum</span></div>
      </div>
      <div class="mt-4 flex items-baseline gap-2">
        <span class="font-headline-lg text-headline-lg font-bold text-secondary" data-stat="interactions.total"><?= e($s['interactions.total']) ?></span>
        <span class="font-label-md text-label-md text-on-surface-variant">회 발생</span>
      </div>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-2 pt-3 text-on-surface-variant">
        <span class="font-label-sm text-label-sm">동화당 평균 <strong class="text-on-surface" data-stat="interactions.per_story"><?= e($s['interactions.per_story']) ?></strong></span>
        <span class="rounded bg-surface-container-high px-2 py-0.5 font-label-sm text-label-sm text-on-surface" data-stat="interactions.limit"><?= e($s['interactions.limit']) ?></span>
      </div>
    </div>

    <div class="relative flex flex-col justify-between overflow-hidden rounded-2xl bg-surface-container-lowest p-6 shadow-card">
      <div class="flex items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <span class="font-label-sm text-label-sm uppercase tracking-wider text-on-surface-variant">Burn-rate Defense</span>
          <span class="font-headline-md text-headline-md font-bold text-on-surface">금일 누적 API 비용</span>
        </div>
        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-container-high text-secondary"><span class="material-symbols-outlined text-[22px]">payments</span></div>
      </div>
      <div class="mt-4 flex flex-wrap items-baseline gap-2">
        <span class="font-headline-lg text-headline-lg font-bold text-on-surface" data-stat="cost.today"><?= e($s['cost.today']) ?></span>
        <span class="font-label-md text-label-md font-semibold text-secondary" data-stat="cost.percent"><?= e($s['cost.percent']) ?></span>
      </div>
      <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-surface-container-high" role="progressbar" aria-label="일일 한도 대비 사용률" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= (int) $w['cost.percent'] ?>">
        <div class="h-full rounded-full <?= $w['cost.percent'] >= 90 ? 'bg-error' : 'bg-secondary-container' ?> transition-all duration-500" style="width: <?= (int) $w['cost.percent'] ?>%" data-stat-width="cost.percent"></div>
      </div>
      <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-on-surface-variant">
        <span class="font-label-sm text-label-sm">건당 평균 <strong class="text-on-surface" data-stat="cost.per_interaction"><?= e($s['cost.per_interaction']) ?></strong></span>
        <span class="font-label-sm text-label-sm text-on-surface-variant">안전 한도 <span data-stat="cost.budget"><?= e($s['cost.budget']) ?></span></span>
      </div>
    </div>
  </div>

  <div class="mb-8 grid grid-cols-1 gap-8 xl:grid-cols-12">
    <div class="flex min-w-0 flex-col gap-6 xl:col-span-8">
      <!-- 목소리 생성 요청 대기열 -->
      <div class="rounded-3xl bg-surface-container-lowest p-7 shadow-card">
        <div class="mb-6 flex flex-col justify-between gap-3 sm:flex-row sm:items-center">
          <div class="flex flex-col">
            <div class="flex flex-wrap items-center gap-2">
              <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-secondary-container"></span>
              <h2 class="font-headline-md text-headline-md text-on-surface">목소리 생성 요청 대기열</h2>
              <span class="rounded-full bg-secondary-container/20 px-2.5 py-0.5 font-label-sm text-label-sm font-bold text-secondary" data-stat="queue.total"><?= e($s['queue.total']) ?></span>
            </div>
            <p class="mt-0.5 font-body-md text-body-md text-on-surface-variant">부모가 녹음한 샘플을 바로 들어 보고 목소리 복제를 승인하거나 재녹음을 요청합니다.</p>
          </div>
          <a href="<?= e(url('/admin/voices', ['status' => 'pending'])) ?>" class="flex shrink-0 items-center gap-1 rounded-lg bg-surface-container-low px-3 py-1.5 font-label-sm text-label-sm text-on-surface-variant transition-colors hover:bg-surface-container-high">
            <span class="material-symbols-outlined text-[16px]">filter_list</span><span>정렬: 오래된 신청순</span>
          </a>
        </div>
        <div data-region="queue"><?= partial('admin/dashboard/_queue', ['d' => $d]) ?></div>
      </div>

      <!-- 끼어들기 응답 지연 -->
      <div class="rounded-3xl bg-surface-container-lowest p-7 shadow-card" data-region="latency"><?= partial('admin/dashboard/_latency', ['d' => $d]) ?></div>
    </div>

    <div class="flex min-w-0 flex-col gap-6 xl:col-span-4">
      <div class="flex flex-col gap-4 rounded-3xl bg-surface-container-lowest p-6 shadow-card" data-region="batch"><?= partial('admin/dashboard/_batch', ['d' => $d]) ?></div>
      <div class="flex flex-col gap-4 rounded-3xl bg-surface-container-lowest p-6 shadow-card" data-region="health"><?= partial('admin/dashboard/_health', ['d' => $d]) ?></div>
    </div>
  </div>

  <!-- 비용 방어 정책 -->
  <div class="flex flex-col justify-between gap-4 rounded-3xl bg-surface-container-lowest p-6 shadow-card sm:flex-row sm:items-center">
    <div class="flex items-center gap-3.5">
      <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl bg-primary text-on-primary"><span class="material-symbols-outlined text-[24px]">policy</span></div>
      <div class="flex flex-col">
        <span class="font-headline-md text-[18px] font-bold text-on-surface">비용 방어 규칙 적용 중</span>
        <span class="font-body-md text-body-md text-on-surface-variant">기본 동화는 미리 만든 음성으로 무료 재생 • 질문은 동화당 최대 <?= (int) $d['policy']['max_questions'] ?>회 • 일일 API 비용 한도 <?= e($d['policy']['daily_budget'] > 0 ? fmt_krw($d['policy']['daily_budget']) : '미설정') ?></span>
      </div>
    </div>
    <div class="flex shrink-0 items-center gap-3">
      <a href="<?= e(url('/admin/audit')) ?>" class="rounded-xl bg-surface-container-high px-4 py-2.5 font-label-md text-label-md text-on-surface transition-colors hover:bg-surface-variant">감사 로그 확인</a>
      <a href="<?= e(url('/admin/settings')) ?>#defense" class="rounded-xl bg-secondary px-4 py-2.5 font-label-md text-label-md text-on-secondary transition-opacity hover:opacity-95">비상 방어 정책 점검</a>
    </div>
  </div>
</div>
<?= partial('admin/voices/_modals', ['elReady' => $elReady]) ?>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/admin-voices.js')) ?>"></script>
<script src="<?= e(asset('js/admin-dashboard.js')) ?>"></script>
<?php endsection(); ?>
