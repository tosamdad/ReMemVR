<?php
/** 대시보드: 동화 사전 생성 큐. 변수: d */
use App\Services\DashboardStats;

$batch = $d['batch'];
$stories = (int) $batch['stories'];
if ($batch['running'] > 0) {
    $badge = ['배치 가동', 'bg-secondary-fixed text-on-secondary-fixed'];
} elseif ($batch['total'] > 0) {
    $badge = ['대기 중', 'bg-surface-container-high text-on-surface-variant'];
} else {
    $badge = ['유휴', 'bg-surface-container text-on-surface-variant'];
}
?>
<div class="flex items-center justify-between gap-2">
  <div class="flex items-center gap-2">
    <span class="material-symbols-outlined text-[22px] text-secondary">cloud_sync</span>
    <h3 class="font-headline-md text-[18px] font-bold text-on-surface">동화 사전 생성 큐</h3>
  </div>
  <span class="shrink-0 rounded-full px-2 py-0.5 font-label-sm text-label-sm font-bold <?= $badge[1] ?>"><?= e($badge[0]) ?></span>
</div>
<p class="font-body-md text-sm text-on-surface-variant">승인된 목소리로 게시된 동화 <?= (int) $stories ?>편을 한 번만 미리 만들어 우리 서버에 저장합니다. 아이가 들을 때는 저장된 파일을 그대로 재생하므로 추가 비용이 없습니다.</p>
<?php if (!$batch['items']): ?>
<div class="flex flex-col items-center gap-2 rounded-2xl bg-surface-container-low px-4 py-7 text-center">
  <span class="material-symbols-outlined text-[28px] text-on-surface-variant">library_music</span>
  <p class="font-label-md text-label-md text-on-surface">지금 만들고 있는 동화 오디오가 없습니다</p>
  <p class="font-label-sm text-label-sm font-semibold text-on-surface-variant">목소리 복제가 끝나면 자동으로 이 큐에 들어옵니다.</p>
</div>
<?php else: ?>
<?php foreach ($batch['items'] as $it): $p = $it['progress']; ?>
<a href="<?= e(url('/admin/voices/' . $it['id'])) ?>" class="flex flex-col gap-3 rounded-2xl bg-surface-container-low p-4 transition-colors hover:bg-surface-container">
  <div class="flex items-center justify-between gap-3">
    <div class="flex min-w-0 flex-col">
      <span class="truncate font-label-md text-label-md font-bold text-on-surface"><?= e($it['user_name']) ?> 가족 · <?= e($it['label']) ?> 목소리 (<?= (int) $p['total'] ?>편)</span>
      <span class="font-label-sm text-label-sm text-on-surface-variant">
        <?php if ($it['running']): ?>
          <?= (int) $it['current_seq'] ?>번째 동화<?= $it['current_title'] !== null ? " '" . e($it['current_title']) . "'" : '' ?> 생성 중
        <?php elseif ($it['status'] === 'cloning'): ?>
          목소리 복제 중 • 대기열 순번 #<?= (int) $it['order'] ?>
        <?php else: ?>
          큐 대기 중 • 대기열 순번 #<?= (int) $it['order'] ?>
        <?php endif; ?>
      </span>
    </div>
    <?php if ($it['running'] || $p['completed'] > 0): ?>
    <span class="shrink-0 font-headline-md text-[20px] font-bold text-primary"><?= (int) $p['percent'] ?>%</span>
    <?php else: ?>
    <span class="shrink-0 font-headline-md text-[16px] font-semibold text-on-surface-variant">대기중</span>
    <?php endif; ?>
  </div>
  <div class="h-3 w-full overflow-hidden rounded-full bg-surface-container-high">
    <div class="h-full rounded-full <?= $it['running'] ? 'bg-primary' : 'bg-secondary-container' ?> transition-all duration-500" style="width: <?= (int) $p['percent'] ?>%"></div>
  </div>
  <div class="flex items-center justify-between gap-2 font-label-sm text-[11px] text-on-surface-variant">
    <span><?= $it['eta_sec'] !== null ? '남은 시간 약 ' . e(DashboardStats::koSpan($it['eta_sec'])) : ($p['failed'] > 0 ? '실패 ' . (int) $p['failed'] . '편' : '예상 시간 계산 중') ?></span>
    <span><?= (int) $p['completed'] ?> / <?= (int) $p['total'] ?> 파일 완료</span>
  </div>
</a>
<?php endforeach; ?>
<?php endif; ?>
<a href="<?= e(url('/admin/voices', ['status' => 'processing'])) ?>" class="w-full rounded-xl bg-surface-container-high py-2.5 text-center font-label-md text-label-md text-on-surface transition-colors hover:bg-surface-variant">배치 작업 큐 상세 보기 (전체 <?= (int) $batch['total'] ?>건)</a>
