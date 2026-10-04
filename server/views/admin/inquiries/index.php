<?php
/**
 * 1:1 문의 목록. 변수: rows, tab, counts, filters(q, category), page, pages, total, avgMinutes, oldestOpen
 * 컨트롤러가 주는 칩 색(Tailwind 가 이 파일에서 찾도록 적어 둠): bg-secondary-container text-on-secondary-container bg-emerald-100 text-emerald-800 bg-surface-container-high text-on-surface-variant
 */
use App\Controllers\Admin\InquiryController;
use App\Services\MemberStats;

layout('admin/layout', ['title' => '1:1 문의', 'active' => 'inquiries']);
$query = array_filter(['status' => $tab === 'open' ? '' : $tab, 'q' => $filters['q'], 'category' => $filters['category']], 'strlen');
$filtered = $filters['q'] !== '' || $filters['category'] !== '';
$from = $total ? ($page - 1) * InquiryController::PER_PAGE + 1 : 0;
$to = min($total, $page * InquiryController::PER_PAGE);
$oldestMin = $oldestOpen ? (int) floor((time() - strtotime((string) $oldestOpen)) / 60) : null;
$tabIcons = ['open' => 'mark_email_unread', 'answered' => 'mark_email_read', 'closed' => 'archive', 'all' => 'inbox'];
?>
<div class="flex w-full flex-col gap-6">
  <div class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-sm">
    <div class="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
      <div class="flex flex-col gap-1.5">
        <div class="flex items-center gap-3">
          <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Customer Care</span>
          <span class="font-label-sm text-label-sm text-on-surface-variant">회원 앱 설정 → 고객 센터에서 보낸 문의입니다</span>
        </div>
        <h1 class="font-headline-md text-headline-md text-on-surface">1:1 문의</h1>
      </div>
      <div class="grid grid-cols-3 gap-3">
        <div class="rounded-xl bg-surface-container-low px-4 py-3">
          <p class="font-label-sm text-label-sm text-on-surface-variant">답변 대기</p>
          <p class="font-headline-md text-headline-md <?= $counts['open'] > 0 ? 'text-secondary' : 'text-on-surface' ?>"><?= (int) $counts['open'] ?><span class="ml-0.5 font-label-md text-label-md text-on-surface-variant">건</span></p>
        </div>
        <div class="rounded-xl bg-surface-container-low px-4 py-3">
          <p class="font-label-sm text-label-sm text-on-surface-variant">가장 오래 기다린 문의</p>
          <p class="font-headline-md text-headline-md <?= $oldestMin !== null && $oldestMin >= 1440 ? 'text-error' : 'text-on-surface' ?>"><?= $oldestMin !== null ? e(InquiryController::waitText($oldestMin)) : '–' ?></p>
        </div>
        <div class="rounded-xl bg-surface-container-low px-4 py-3">
          <p class="font-label-sm text-label-sm text-on-surface-variant">평균 첫 답변 (30일)</p>
          <p class="font-headline-md text-headline-md text-on-surface"><?= $avgMinutes !== null ? e(InquiryController::waitText($avgMinutes)) : '–' ?></p>
        </div>
      </div>
    </div>
    <nav class="flex gap-6 overflow-x-auto border-b border-surface-container-high no-scrollbar" aria-label="문의 상태">
      <?php foreach (InquiryController::TABS as $key => $label): $on = $key === $tab; ?>
      <a href="<?= e(url('/admin/inquiries', array_filter(['status' => $key === 'open' ? '' : $key, 'q' => $filters['q'], 'category' => $filters['category']], 'strlen'))) ?>" class="-mb-px flex shrink-0 items-center gap-2 border-b-2 px-1 pb-3 font-label-md text-label-md transition-colors <?= $on ? 'border-primary text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>"<?= $on ? ' aria-current="page"' : '' ?>>
        <span class="material-symbols-outlined text-[18px]"><?= $tabIcons[$key] ?></span><?= e($label) ?>
        <span class="rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $on ? 'bg-primary-fixed text-primary' : ($key === 'open' && $counts['open'] > 0 ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant') ?>"><?= (int) $counts[$key] ?></span>
      </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <section class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md">
    <form method="get" action="<?= e(url('/admin/inquiries')) ?>" class="flex flex-wrap items-center gap-3">
      <?php if ($tab !== 'open'): ?><input type="hidden" name="status" value="<?= e($tab) ?>"><?php endif; ?>
      <label class="flex w-80 items-center rounded-xl bg-surface-container-low px-3 py-2">
        <span class="material-symbols-outlined mr-2 text-[20px] text-on-surface-variant">search</span>
        <input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="제목, 내용, 회원 이름, 이메일, #RM-ID" class="w-full border-0 bg-transparent p-0 font-body-md text-body-md text-on-surface placeholder:text-outline focus:ring-0">
      </label>
      <select name="category" class="cursor-pointer rounded-xl border-0 bg-surface-container-low px-3 py-2.5 pr-8 font-label-md text-label-md text-on-surface focus:ring-2 focus:ring-primary/30" aria-label="문의 분류">
        <option value="">전체 분류</option>
        <?php foreach (InquiryController::CATEGORIES as $k => $label): ?>
        <option value="<?= e($k) ?>"<?= $filters['category'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="a-btn-tonal shrink-0 whitespace-nowrap">검색</button>
      <?php if ($filtered): ?><a href="<?= e(url('/admin/inquiries', $tab === 'open' ? [] : ['status' => $tab])) ?>" class="font-label-sm text-label-sm text-primary hover:underline">조건 지우기</a><?php endif; ?>
    </form>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-left">
        <thead>
          <tr class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
            <th class="whitespace-nowrap rounded-l-lg px-3 py-3">번호</th>
            <th class="whitespace-nowrap px-3 py-3">분류</th>
            <th class="px-3 py-3">제목</th>
            <th class="whitespace-nowrap px-3 py-3">회원</th>
            <th class="whitespace-nowrap px-3 py-3">접수</th>
            <th class="whitespace-nowrap px-3 py-3">상태, 답변</th>
            <th class="whitespace-nowrap rounded-r-lg px-3 py-3 text-right"><span class="sr-only">열기</span></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-container-high font-body-md text-body-md text-on-surface">
          <?php if (!$rows): ?>
          <tr><td colspan="7" class="px-3 py-14 text-center">
            <span class="material-symbols-outlined text-[36px] text-outline"><?= $tab === 'open' && !$filtered ? 'task_alt' : 'inbox' ?></span>
            <?php if ($filtered): ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface">조건에 맞는 문의가 없습니다.</p>
            <?php elseif ($tab === 'open'): ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface">기다리는 문의가 없습니다.</p>
            <p class="font-label-sm text-label-sm text-on-surface-variant">새 문의가 오면 이곳과 상단 알림 종에 표시됩니다.</p>
            <?php else: ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface"><?= e(InquiryController::TABS[$tab]) ?> 문의가 없습니다.</p>
            <?php endif; ?>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r):
              $st = InquiryController::STATUS[$r['status']] ?? [$r['status'], 'bg-surface-container-high text-on-surface-variant'];
              $wait = (int) floor((time() - strtotime((string) $r['created_at'])) / 60);
              $link = url('/admin/inquiries/' . (int) $r['id']);
          ?>
          <tr class="transition-colors hover:bg-surface-container-low/70">
            <td class="whitespace-nowrap px-3 py-4 font-label-md text-label-md text-on-surface-variant">#<?= (int) $r['id'] ?></td>
            <td class="whitespace-nowrap px-3 py-4"><span class="a-chip bg-secondary-fixed text-on-secondary-fixed"><?= e(InquiryController::categoryLabel($r['category'])) ?></span></td>
            <td class="px-3 py-4">
              <a href="<?= e($link) ?>" class="block font-semibold hover:text-primary"><?= e($r['title']) ?></a>
              <span class="block max-w-[260px] truncate font-label-sm text-label-sm text-on-surface-variant"><?= e(str_limit(preg_replace('/\s+/u', ' ', (string) $r['body']), 80)) ?></span>
            </td>
            <td class="px-3 py-4">
              <a href="<?= e(url('/admin/members/' . (int) $r['user_id'])) ?>" class="block whitespace-nowrap hover:text-primary"><?= e($r['user_name']) ?></a>
              <span class="block whitespace-nowrap font-label-sm text-label-sm text-on-surface-variant"><?= e(MemberStats::memberCode((int) $r['user_id'])) ?><?= $r['user_status'] === 'withdrawn' || $r['user_deleted'] ? ' · 탈퇴' : '' ?></span>
            </td>
            <td class="whitespace-nowrap px-3 py-4">
              <span class="block"><?= e(date('Y.m.d H:i', strtotime((string) $r['created_at']))) ?></span>
              <?php if ($r['status'] === 'open'): ?>
              <span class="block font-label-sm text-label-sm <?= $wait >= 1440 ? 'text-error' : 'text-on-surface-variant' ?>"><?= e(InquiryController::waitText($wait)) ?> 대기</span>
              <?php endif; ?>
            </td>
            <td class="whitespace-nowrap px-3 py-4">
              <span class="a-chip <?= $st[1] ?>"><?= e($st[0]) ?></span>
              <?php if ($r['answered_at']): ?>
              <span class="mt-1 block font-label-sm text-label-sm text-on-surface-variant"><?= e($r['answerer'] !== null ? $r['answerer'] : '삭제된 관리자') ?> · <?= e(date('m.d H:i', strtotime((string) $r['answered_at']))) ?></span>
              <?php endif; ?>
            </td>
            <td class="whitespace-nowrap px-3 py-4 text-right">
              <a href="<?= e($link) ?>" class="<?= $r['status'] === 'open' ? 'a-btn-primary' : 'a-btn-tonal' ?> !px-3 !py-1.5"><?= $r['status'] === 'open' ? '답변하기' : '보기' ?></a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
      <span class="font-label-md text-label-md text-on-surface-variant"><?= $total ? e(fmt_number($total)) . '건 중 ' . $from . ' - ' . $to . ' 표시 중' : '표시할 문의 없음' ?></span>
      <?php if ($pages > 1): ?>
      <?= partial('admin/members/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/inquiries', 'query' => $query]) ?>
      <?php endif; ?>
    </div>
  </section>
</div>
