<?php
/**
 * 배포 상태 점검 창 본문(fetch 로 불러온다).
 * 변수: rows([story, fresh[], outdated[], working[], missing[]]), voices, totals
 */
$statusLabel = ['published' => '활성', 'hidden' => '숨김', 'draft' => '초안'];
$cell = static function (array $names, string $cls) {
    if (!$names) {
        return '<span class="font-label-sm text-label-sm text-outline">0</span>';
    }
    $title = implode(', ', array_slice($names, 0, 20)) . (count($names) > 20 ? ' 외 ' . (count($names) - 20) . '개' : '');

    return '<span class="inline-flex min-w-[2rem] justify-center rounded-full px-2 py-0.5 font-label-sm text-label-sm ' . $cls . '" title="' . e($title) . '">' . count($names) . '</span>';
};
?>
<?php if (!$voices): ?>
<div class="flex flex-col items-center gap-3 py-12 text-center">
  <span class="material-symbols-outlined text-[40px] text-outline">record_voice_over</span>
  <p class="font-label-md text-label-md text-on-surface">오디오를 만들 목소리가 아직 없습니다.</p>
  <p class="font-label-sm text-label-sm text-on-surface-variant">회원 목소리가 ElevenLabs 에서 만들어지면(처리 중, 완료) 동화 오디오 배포 상태가 여기에 표시됩니다.</p>
</div>
<?php else: ?>
<div class="flex flex-col gap-4">
  <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-surface-container-low p-4">
    <div class="flex flex-wrap items-center gap-2 font-label-sm text-label-sm">
      <span class="text-on-surface-variant">목소리 <?= count($voices) ?>개 기준</span>
      <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-emerald-800">최신 <?= (int) $totals['fresh'] ?></span>
      <span class="rounded-full bg-secondary-fixed px-2.5 py-0.5 text-on-secondary-fixed-variant">옛 본문 <?= (int) $totals['outdated'] ?></span>
      <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 text-on-primary-fixed-variant">생성 중 <?= (int) $totals['working'] ?></span>
      <span class="rounded-full bg-error-container px-2.5 py-0.5 text-on-error-container">없음·실패 <?= (int) $totals['missing'] ?></span>
    </div>
    <form method="post" action="<?= e(url('/admin/stories/regenerate-stale')) ?>" data-ajax data-deploy-action data-confirm="활성 동화 중 옛 본문이거나 없는 오디오만 다시 생성합니다. 대상 동화 글자 수만큼 ElevenLabs 크레딧이 사용됩니다. 계속할까요?">
      <?= csrf_field() ?>
      <button type="submit" class="a-btn-secondary"<?= $totals['outdated'] + $totals['missing'] === 0 ? ' disabled' : '' ?>><span class="material-symbols-outlined text-[18px]">autorenew</span>오래된 오디오 모두 다시 생성</button>
    </form>
  </div>
  <div class="relative overflow-x-auto">
    <table class="a-table w-full">
      <thead>
        <tr class="bg-surface-container-low">
          <th class="rounded-l-lg">동화</th>
          <th class="text-center">최신</th>
          <th class="text-center">옛 본문</th>
          <th class="text-center">생성 중</th>
          <th class="text-center">없음·실패</th>
          <th class="rounded-r-lg text-right">작업</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): $s = $r['story']; $published = $s['status'] === 'published'; $todo = count($r['outdated']) + count($r['missing']); ?>
        <tr>
          <td>
            <div class="flex flex-col">
              <span class="font-label-md text-label-md text-on-surface"><?= (int) $s['sort_order'] ?>. <?= e($s['title']) ?></span>
              <span class="font-label-sm text-label-sm text-on-surface-variant"><?= e($s['code'] ?: '코드 없음') ?> · <?= e(isset($statusLabel[$s['status']]) ? $statusLabel[$s['status']] : $s['status']) ?> · <?= number_format((int) $s['char_count']) ?>자</span>
            </div>
          </td>
          <td class="text-center"><?= $cell($r['fresh'], 'bg-emerald-100 text-emerald-800') ?></td>
          <td class="text-center"><?= $cell($r['outdated'], 'bg-secondary-fixed text-on-secondary-fixed-variant') ?></td>
          <td class="text-center"><?= $cell($r['working'], 'bg-primary-fixed text-on-primary-fixed-variant') ?></td>
          <td class="text-center"><?= $cell($r['missing'], 'bg-error-container text-on-error-container') ?></td>
          <td class="text-right">
            <?php if (!$published): ?>
            <span class="font-label-sm text-label-sm text-on-surface-variant">활성 동화만 생성</span>
            <?php else: ?>
            <div class="flex justify-end gap-2">
              <form method="post" action="<?= e(url('/admin/stories/' . (int) $s['id'] . '/regenerate')) ?>" data-ajax data-deploy-action data-confirm="「<?= e($s['title']) ?>」의 옛 본문, 누락 오디오 <?= $todo ?>건을 다시 생성할까요? 약 <?= number_format($todo * (int) $s['char_count']) ?>자 분량의 크레딧이 사용됩니다.">
                <?= csrf_field() ?><input type="hidden" name="mode" value="stale">
                <button type="submit" class="a-btn-tonal px-3 py-1.5"<?= $todo === 0 ? ' disabled' : '' ?>>다시 생성</button>
              </form>
              <form method="post" action="<?= e(url('/admin/stories/' . (int) $s['id'] . '/regenerate')) ?>" data-ajax data-deploy-action data-confirm="「<?= e($s['title']) ?>」을(를) 모든 목소리(<?= count($voices) ?>개)로 처음부터 다시 생성할까요? 최신 오디오도 다시 만들며 약 <?= number_format(count($voices) * (int) $s['char_count']) ?>자 분량의 크레딧이 사용됩니다.">
                <?= csrf_field() ?><input type="hidden" name="mode" value="all">
                <button type="submit" class="a-btn-tonal px-3 py-1.5 text-primary">전체 다시 생성</button>
              </form>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="font-label-sm text-label-sm text-on-surface-variant">숫자에 마우스를 올리면 해당 목소리 목록이 보입니다. 생성 작업은 관리자 화면이 열려 있는 동안 작업 처리기가 차례로 진행합니다.</p>
</div>
<?php endif; ?>
