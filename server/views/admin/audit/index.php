<?php
/**
 * 감사 로그. 변수: rows, total, page, pages, filters(admin, action, from, to), filterErrors, actionInput(입력 그대로), admins, groups, actions
 */
use App\Controllers\Admin\AuditController;

layout('admin/layout', ['title' => '감사 로그', 'active' => 'audit']);
$query = array_filter([
    'admin' => $filters['admin'] !== null ? (string) $filters['admin'] : '',
    'action' => $filters['action'],
    'from' => $filters['from'],
    'to' => $filters['to'],
], 'strlen');
$filtered = (bool) $query;
$from = $total ? ($page - 1) * AuditController::PER_PAGE + 1 : 0;
$to = min($total, $page * AuditController::PER_PAGE);
$fe = static function (string $key) use ($filterErrors) {
    return isset($filterErrors[$key]) ? '<p class="mt-1 font-label-sm text-label-sm text-error" role="alert">' . e($filterErrors[$key]) . '</p>' : '';
};
$chipLink = static function (string $prefix) use ($query) {
    $q = $query;
    unset($q['action']);
    if ($prefix !== '') {
        $q['action'] = $prefix;
    }

    return url('/admin/audit', $q);
};
$groupIcons = ['admin' => 'manage_accounts', 'voice' => 'mic', 'member' => 'group', 'user' => 'sticky_note_2', 'interaction' => 'forum', 'story' => 'auto_stories', 'settings' => 'tune', 'notice' => 'campaign', 'faq' => 'quiz', 'inquiry' => 'support_agent', 'job' => 'sync'];
?>
<div class="flex w-full flex-col gap-6">
  <div class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
      <div class="flex flex-col gap-1.5">
        <div class="flex items-center gap-3">
          <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Audit Trail</span>
          <span class="font-label-sm text-label-sm text-on-surface-variant">관리자가 바꾼 모든 내용이 시간 순으로 남습니다. 지우거나 고칠 수 없습니다.</span>
        </div>
        <h1 class="font-headline-md text-headline-md text-on-surface">감사 로그</h1>
      </div>
    </div>

    <form method="get" action="<?= e(url('/admin/audit')) ?>" class="grid grid-cols-1 items-start gap-3 md:grid-cols-2 xl:grid-cols-[220px_260px_170px_170px_auto]" novalidate>
      <div>
        <label class="a-label" for="audit-admin">관리자</label>
        <select id="audit-admin" name="admin" class="a-input">
          <option value="">전체 관리자</option>
          <?php foreach ($admins as $a): ?>
          <option value="<?= (int) $a['id'] ?>"<?= $filters['admin'] === (int) $a['id'] ? ' selected' : '' ?>><?= e($a['name']) ?> (<?= e($a['login_id']) ?>)<?= $a['status'] !== 'active' ? ' · 사용 중지' : '' ?></option>
          <?php endforeach; ?>
          <option value="0"<?= $filters['admin'] === 0 ? ' selected' : '' ?>>시스템, 삭제된 관리자</option>
        </select>
      </div>
      <div>
        <label class="a-label" for="audit-action">작업 (앞부분 일치)</label>
        <input id="audit-action" name="action" type="text" list="audit-actions" value="<?= e($actionInput) ?>" maxlength="50" autocapitalize="off" spellcheck="false" placeholder="예) story. 또는 voice.approve" class="a-input font-mono<?= isset($filterErrors['action']) ? ' ring-2 ring-error' : '' ?>">
        <datalist id="audit-actions">
          <?php foreach ($actions as $act): ?><option value="<?= e($act) ?>"><?= e(AuditController::actionLabel($act)) ?></option><?php endforeach; ?>
        </datalist>
        <?= $fe('action') ?>
      </div>
      <div>
        <label class="a-label" for="audit-from">시작 날짜</label>
        <input id="audit-from" name="from" type="date" value="<?= e($filters['from']) ?>" class="a-input<?= isset($filterErrors['from']) ? ' ring-2 ring-error' : '' ?>">
        <?= $fe('from') ?>
      </div>
      <div>
        <label class="a-label" for="audit-to">끝 날짜</label>
        <input id="audit-to" name="to" type="date" value="<?= e($filters['to']) ?>" class="a-input<?= isset($filterErrors['to']) ? ' ring-2 ring-error' : '' ?>">
        <?= $fe('to') ?>
      </div>
      <div class="flex items-center gap-2 xl:pt-6">
        <button type="submit" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">filter_alt</span>적용</button>
        <?php if ($filtered): ?><a href="<?= e(url('/admin/audit')) ?>" class="a-btn-tonal">초기화</a><?php endif; ?>
      </div>
    </form>

    <?php if ($groups): ?>
    <div class="flex flex-wrap gap-2">
      <a href="<?= e($chipLink('')) ?>" class="rounded-full px-3.5 py-1.5 font-label-md text-label-md transition-colors <?= $filters['action'] === '' ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' ?>">전체 작업</a>
      <?php foreach ($groups as $g): $prefix = $g['g'] . '.'; $on = $filters['action'] === $prefix; ?>
      <a href="<?= e($chipLink($prefix)) ?>" class="flex items-center gap-1.5 rounded-full px-3.5 py-1.5 font-label-md text-label-md transition-colors <?= $on ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' ?>">
        <span class="material-symbols-outlined text-[16px]"><?= e($groupIcons[$g['g']] ?? 'label') ?></span><?= e(AuditController::GROUP_LABELS[$g['g']] ?? $g['g']) ?> <span class="font-label-sm text-label-sm opacity-80"><?= e(fmt_number((int) $g['n'])) ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <section class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md">
    <div class="relative overflow-x-auto">
      <table class="w-full border-collapse text-left">
        <thead>
          <tr class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
            <th class="whitespace-nowrap rounded-l-lg px-4 py-3">시각</th>
            <th class="whitespace-nowrap px-4 py-3">관리자</th>
            <th class="whitespace-nowrap px-4 py-3">작업</th>
            <th class="whitespace-nowrap px-4 py-3">대상</th>
            <th class="px-4 py-3">상세</th>
            <th class="whitespace-nowrap rounded-r-lg px-4 py-3">IP</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-container-high font-body-md text-body-md text-on-surface">
          <?php if (!$rows): ?>
          <tr><td colspan="6" class="px-4 py-14 text-center">
            <span class="material-symbols-outlined text-[36px] text-outline">policy</span>
            <p class="mt-2 font-label-md text-label-md text-on-surface"><?= $filtered ? '조건에 맞는 기록이 없습니다.' : '아직 기록이 없습니다.' ?></p>
            <?php if ($filtered): ?><a href="<?= e(url('/admin/audit')) ?>" class="font-label-sm text-label-sm text-primary hover:underline">조건 지우기</a><?php endif; ?>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
              list($summary, $pretty) = AuditController::detail($r['detail']);
              $target = AuditController::targetUrl($r['target_type'], $r['target_id']);
              $group = strstr((string) $r['action'], '.', true) ?: (string) $r['action'];
          ?>
          <tr class="align-top transition-colors hover:bg-surface-container-low/70">
            <td class="whitespace-nowrap px-4 py-3.5">
              <span class="block font-label-md text-label-md"><?= e(date('Y.m.d', strtotime((string) $r['created_at']))) ?></span>
              <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e(date('H:i:s', strtotime((string) $r['created_at']))) ?></span>
            </td>
            <td class="whitespace-nowrap px-4 py-3.5">
              <?php if ($r['admin_id'] !== null && $r['admin_name'] !== null): ?>
              <a href="<?= e(url('/admin/audit', array_merge($query, ['admin' => (int) $r['admin_id']]))) ?>" class="block hover:text-primary"><?= e($r['admin_name']) ?></a>
              <span class="block font-label-sm text-label-sm text-on-surface-variant"><?= e($r['admin_login']) ?></span>
              <?php else: ?>
              <span class="text-on-surface-variant">시스템</span>
              <?php endif; ?>
            </td>
            <td class="px-4 py-3.5">
              <span class="flex items-center gap-1.5 whitespace-nowrap"><span class="material-symbols-outlined text-[18px] text-primary"><?= e($groupIcons[$group] ?? 'label') ?></span><?= e(AuditController::actionLabel((string) $r['action'])) ?></span>
              <span class="block font-mono text-[11px] leading-4 text-on-surface-variant"><?= e($r['action']) ?></span>
            </td>
            <td class="whitespace-nowrap px-4 py-3.5">
              <?php if ($r['target_type'] !== null): $tl = (AuditController::TARGET_LABELS[$r['target_type']] ?? $r['target_type']) . ($r['target_id'] !== null ? ' #' . (int) $r['target_id'] : ''); ?>
              <?php if ($target): ?><a href="<?= e($target) ?>" class="font-label-md text-label-md text-primary hover:underline"><?= e($tl) ?></a><?php else: ?><span class="font-label-md text-label-md text-on-surface-variant"><?= e($tl) ?></span><?php endif; ?>
              <?php else: ?><span class="text-on-surface-variant">–</span><?php endif; ?>
            </td>
            <td class="max-w-[420px] px-4 py-3.5">
              <?php if ($pretty !== ''): ?>
              <details class="group">
                <summary class="cursor-pointer list-none truncate font-label-sm text-label-sm text-on-surface-variant hover:text-on-surface"><span class="material-symbols-outlined mr-0.5 align-middle text-[16px] transition-transform group-open:rotate-90">chevron_right</span><?= e($summary) ?></summary>
                <pre class="mt-2 max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-inverse-surface p-3 font-mono text-[12px] leading-5 text-inverse-on-surface"><?= e($pretty) ?></pre>
              </details>
              <?php else: ?><span class="font-label-sm text-label-sm text-outline">–</span><?php endif; ?>
            </td>
            <td class="whitespace-nowrap px-4 py-3.5 font-mono text-[12px] text-on-surface-variant"><?= e((string) $r['ip']) ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
      <span class="font-label-md text-label-md text-on-surface-variant"><?= $total ? e(fmt_number($total)) . '건 중 ' . e(fmt_number($from)) . ' - ' . e(fmt_number($to)) . ' 표시 중' : '표시할 기록 없음' ?></span>
      <?php if ($pages > 1): ?>
      <?= partial('admin/members/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/audit', 'query' => $query]) ?>
      <?php endif; ?>
    </div>
  </section>
</div>
