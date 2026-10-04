<?php
/**
 * 공지사항 목록. 변수: rows, total, page, pages, q, filter, counts(필터별), tabCounts
 * 컨트롤러가 주는 칩 색(Tailwind 가 이 파일에서 찾도록 적어 둠): bg-surface-container-high text-on-surface-variant bg-secondary-fixed text-on-secondary-fixed bg-emerald-100 text-emerald-800
 */
use App\Controllers\Admin\NoticeController;

layout('admin/layout', ['title' => '공지사항', 'active' => 'notices']);
$query = array_filter(['q' => $q, 'status' => $filter], 'strlen');
$from = $total ? ($page - 1) * NoticeController::PER_PAGE + 1 : 0;
$to = min($total, $page * NoticeController::PER_PAGE);
?>
<div class="flex w-full flex-col gap-6">
  <?= partial('admin/notices/_tabs', [
      'tab' => 'notices',
      'counts' => $tabCounts,
      'action' => '<a href="' . e(url('/admin/notices/new')) . '" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">add</span>새 공지 작성</a>',
  ]) ?>

  <section class="flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md">
    <div class="flex flex-col justify-between gap-4 md:flex-row md:items-center">
      <div class="flex flex-wrap gap-2">
        <?php foreach (NoticeController::FILTERS as $key => $label): $on = $key === $filter; ?>
        <a href="<?= e(url('/admin/notices', array_filter(['q' => $q, 'status' => $key], 'strlen'))) ?>" class="flex items-center gap-1.5 rounded-full px-3.5 py-1.5 font-label-md text-label-md transition-colors <?= $on ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' ?>"<?= $on ? ' aria-current="true"' : '' ?>>
          <?= e($label) ?><span class="font-label-sm text-label-sm <?= $on ? 'text-on-primary/80' : 'text-outline' ?>"><?= (int) $counts[$key] ?></span>
        </a>
        <?php endforeach; ?>
      </div>
      <form method="get" action="<?= e(url('/admin/notices')) ?>" class="flex items-center gap-2">
        <?php if ($filter !== ''): ?><input type="hidden" name="status" value="<?= e($filter) ?>"><?php endif; ?>
        <label class="flex w-72 items-center rounded-xl bg-surface-container-low px-3 py-2">
          <span class="material-symbols-outlined mr-2 text-[20px] text-on-surface-variant">search</span>
          <input type="search" name="q" value="<?= e($q) ?>" maxlength="100" placeholder="제목, 본문 검색" class="w-full border-0 bg-transparent p-0 font-body-md text-body-md text-on-surface placeholder:text-outline focus:ring-0">
        </label>
        <button type="submit" class="a-btn-tonal shrink-0 whitespace-nowrap">검색</button>
      </form>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full border-collapse text-left">
        <thead>
          <tr class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
            <th class="w-12 rounded-l-lg px-4 py-3 text-center"><span class="sr-only">상단 고정</span><span class="material-symbols-outlined text-[18px]" aria-hidden="true">push_pin</span></th>
            <th class="px-4 py-3">제목</th>
            <th class="whitespace-nowrap px-4 py-3">상태</th>
            <th class="whitespace-nowrap px-4 py-3">게시 시각</th>
            <th class="whitespace-nowrap px-4 py-3">작성자</th>
            <th class="whitespace-nowrap px-4 py-3">마지막 수정</th>
            <th class="whitespace-nowrap rounded-r-lg px-4 py-3 text-right">관리</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-surface-container-high font-body-md text-body-md text-on-surface">
          <?php if (!$rows): ?>
          <tr><td colspan="7" class="px-4 py-14 text-center">
            <span class="material-symbols-outlined text-[36px] text-outline">campaign</span>
            <?php if ($query): ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface">조건에 맞는 공지가 없습니다.</p>
            <a href="<?= e(url('/admin/notices')) ?>" class="font-label-sm text-label-sm text-primary hover:underline">검색 조건 지우기</a>
            <?php else: ?>
            <p class="mt-2 font-label-md text-label-md text-on-surface">아직 작성한 공지가 없습니다.</p>
            <p class="font-label-sm text-label-sm text-on-surface-variant">서비스 소식, 점검 안내를 올리면 회원 앱 설정 → 공지사항에 보입니다.</p>
            <?php endif; ?>
          </td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $n): $state = NoticeController::stateOf($n); ?>
          <tr class="transition-colors hover:bg-surface-container-low/70">
            <td class="px-4 py-4 text-center"><?php if ((int) $n['is_pinned']): ?><span class="material-symbols-outlined icon-fill text-[20px] text-secondary" title="상단 고정">push_pin</span><?php endif; ?></td>
            <td class="px-4 py-4">
              <a href="<?= e(url('/admin/notices/' . (int) $n['id'] . '/edit')) ?>" class="block font-semibold hover:text-primary"><?= e($n['title']) ?></a>
              <span class="block max-w-[340px] truncate font-label-sm text-label-sm text-on-surface-variant"><?= e(str_limit(preg_replace('/\s+/u', ' ', (string) $n['body']), 90)) ?></span>
            </td>
            <td class="whitespace-nowrap px-4 py-4"><span class="a-chip <?= $state[1] ?>"><?= e($state[0]) ?></span></td>
            <td class="whitespace-nowrap px-4 py-4 text-on-surface-variant"><?= $n['published_at'] ? e(date('Y.m.d H:i', strtotime((string) $n['published_at']))) : '–' ?></td>
            <td class="whitespace-nowrap px-4 py-4 text-on-surface-variant"><?= e($n['author'] !== null ? $n['author'] : '시스템') ?></td>
            <td class="whitespace-nowrap px-4 py-4 text-on-surface-variant" title="<?= e($n['updated_at']) ?>"><?= e(time_ago((string) $n['updated_at'])) ?></td>
            <td class="whitespace-nowrap px-4 py-4">
              <div class="flex items-center justify-end gap-1">
                <a href="<?= e(url('/admin/notices/' . (int) $n['id'] . '/edit')) ?>" class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary" title="수정" aria-label="수정"><span class="material-symbols-outlined text-[20px]">edit</span></a>
                <form method="post" action="<?= e(url('/admin/notices/' . (int) $n['id'] . '/delete')) ?>" data-confirm="'<?= e(str_limit($n['title'], 30)) ?>' 공지를 삭제할까요? 회원 화면에서도 바로 사라집니다.">
                  <?= csrf_field() ?>
                  <button type="submit" class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant transition-colors hover:bg-error-container hover:text-on-error-container" title="삭제" aria-label="삭제"><span class="material-symbols-outlined text-[20px]">delete</span></button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="flex flex-col items-center justify-between gap-4 sm:flex-row">
      <span class="font-label-md text-label-md text-on-surface-variant"><?= $total ? e(fmt_number($total)) . '개 중 ' . $from . ' - ' . $to . ' 표시 중' : '표시할 공지 없음' ?></span>
      <?php if ($pages > 1): ?>
      <?= partial('admin/members/_pager', ['page' => $page, 'pages' => $pages, 'base' => '/admin/notices', 'query' => $query]) ?>
      <?php endif; ?>
    </div>
  </section>
</div>
