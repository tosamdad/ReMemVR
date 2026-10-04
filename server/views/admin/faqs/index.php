<?php
/**
 * 자주 묻는 질문 목록과 작성/수정 패널. 변수: rows, editing(수정 중인 항목 또는 null), categories, filters, total, active, nextSort, tabCounts
 */
use App\Controllers\Admin\FaqController;

layout('admin/layout', ['title' => '자주 묻는 질문', 'active' => 'notices']);
$isEdit = $editing !== null;
$filtered = $filters['category'] !== '' || $filters['q'] !== '';
$hasOld = old('question', null) !== null;
$f = [
    'category' => (string) old('category', $isEdit ? (string) $editing['category'] : ''),
    'question' => (string) old('question', $isEdit ? $editing['question'] : ''),
    'answer' => (string) old('answer', $isEdit ? $editing['answer'] : ''),
    'sort_order' => (string) old('sort_order', $isEdit ? (string) $editing['sort_order'] : ''),
    'is_active' => $hasOld ? in_array((string) old('is_active', ''), ['1', 'on'], true) : ($isEdit ? (bool) $editing['is_active'] : true),
];
$err = static function (string $key) {
    $m = errors($key);

    return $m ? '<p class="mt-1 font-label-sm text-label-sm text-error" role="alert">' . e($m) . '</p>' : '';
};
$catLink = static function (string $c) use ($filters) {
    return url('/admin/faqs', array_filter(['category' => $c, 'q' => $filters['q']], 'strlen'));
};
$last = count($rows) - 1;
?>
<div class="flex w-full flex-col gap-6">
  <?= partial('admin/notices/_tabs', [
      'tab' => 'faqs',
      'counts' => $tabCounts,
      'action' => '<a href="' . e(url('/admin/faqs')) . '#faq-form" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">add</span>새 질문 추가</a>',
  ]) ?>

  <div class="grid grid-cols-12 items-start gap-6">
    <section class="col-span-12 flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md xl:col-span-8">
      <div class="flex flex-col justify-between gap-3 md:flex-row md:items-center">
        <div>
          <h2 class="font-headline-md text-headline-md text-on-surface">질문 목록</h2>
          <p class="font-label-sm text-label-sm text-on-surface-variant">전체 <?= (int) $total ?>개 중 <?= (int) $active ?>개가 회원 고객 센터에 보입니다. 위에서부터 차례로 보입니다.</p>
        </div>
        <form method="get" action="<?= e(url('/admin/faqs')) ?>" class="flex shrink-0 items-center gap-2">
          <?php if ($filters['category'] !== ''): ?><input type="hidden" name="category" value="<?= e($filters['category']) ?>"><?php endif; ?>
          <label class="flex w-52 items-center rounded-xl bg-surface-container-low px-3 py-2">
            <span class="material-symbols-outlined mr-2 text-[20px] text-on-surface-variant">search</span>
            <input type="search" name="q" value="<?= e($filters['q']) ?>" maxlength="100" placeholder="질문, 답변 검색" class="w-full border-0 bg-transparent p-0 font-body-md text-body-md text-on-surface placeholder:text-outline focus:ring-0">
          </label>
          <button type="submit" class="a-btn-tonal shrink-0 whitespace-nowrap">검색</button>
        </form>
      </div>

      <?php if ($categories): ?>
      <div class="flex flex-wrap gap-2">
        <a href="<?= e($catLink('')) ?>" class="rounded-full px-3.5 py-1.5 font-label-md text-label-md transition-colors <?= $filters['category'] === '' ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' ?>">전체 <span class="font-label-sm text-label-sm opacity-80"><?= (int) $total ?></span></a>
        <?php foreach ($categories as $c): $key = $c['category'] === '' ? '-' : $c['category']; $on = $filters['category'] === $key; ?>
        <a href="<?= e($catLink($key)) ?>" class="rounded-full px-3.5 py-1.5 font-label-md text-label-md transition-colors <?= $on ? 'bg-primary text-on-primary' : 'bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high' ?>"><?= e($c['category'] === '' ? '분류 없음' : $c['category']) ?> <span class="font-label-sm text-label-sm opacity-80"><?= (int) $c['n'] ?></span></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <?php if ($filtered && $rows): ?>
      <p class="rounded-lg bg-surface-container-low px-4 py-2.5 font-label-sm text-label-sm text-on-surface-variant">필터를 적용한 상태에서는 순서를 바꿀 수 없습니다. 전체 보기에서 위, 아래 버튼으로 바꾸세요.</p>
      <?php endif; ?>

      <ol class="flex flex-col gap-3">
        <?php if (!$rows): ?>
        <li class="flex flex-col items-center gap-2 rounded-xl bg-surface-container-low px-4 py-14 text-center">
          <span class="material-symbols-outlined text-[36px] text-outline">quiz</span>
          <?php if ($filtered): ?>
          <p class="font-label-md text-label-md text-on-surface">조건에 맞는 질문이 없습니다.</p>
          <a href="<?= e(url('/admin/faqs')) ?>" class="font-label-sm text-label-sm text-primary hover:underline">필터 지우기</a>
          <?php else: ?>
          <p class="font-label-md text-label-md text-on-surface">아직 등록한 질문이 없습니다.</p>
          <p class="font-label-sm text-label-sm text-on-surface-variant">오른쪽에서 자주 받는 문의와 답변을 추가하면 회원 고객 센터에 바로 보입니다.</p>
          <?php endif; ?>
        </li>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r):
            $on = (int) $r['is_active'] === 1;
            $current = $isEdit && (int) $editing['id'] === (int) $r['id'];
        ?>
        <li id="faq-<?= (int) $r['id'] ?>" class="flex scroll-mt-24 items-start gap-4 rounded-xl p-4 transition-colors <?= $current ? 'bg-primary-fixed/50 ring-2 ring-primary/60' : 'bg-surface-container-low hover:bg-surface-container' ?><?= $on ? '' : ' opacity-70' ?>">
          <div class="flex w-10 shrink-0 flex-col items-center gap-0.5">
            <?php if (!$filtered): ?>
            <form method="post" action="<?= e(url('/admin/faqs/' . (int) $r['id'] . '/move')) ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="up"><button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md text-on-surface-variant hover:bg-surface-container-highest disabled:opacity-30" aria-label="위로"<?= $i === 0 ? ' disabled' : '' ?>><span class="material-symbols-outlined text-[18px]">keyboard_arrow_up</span></button></form>
            <?php endif; ?>
            <span class="font-label-md text-label-md font-bold text-primary"><?= (int) $r['sort_order'] ?></span>
            <?php if (!$filtered): ?>
            <form method="post" action="<?= e(url('/admin/faqs/' . (int) $r['id'] . '/move')) ?>"><?= csrf_field() ?><input type="hidden" name="direction" value="down"><button type="submit" class="flex h-7 w-7 items-center justify-center rounded-md text-on-surface-variant hover:bg-surface-container-highest disabled:opacity-30" aria-label="아래로"<?= $i === $last ? ' disabled' : '' ?>><span class="material-symbols-outlined text-[18px]">keyboard_arrow_down</span></button></form>
            <?php endif; ?>
          </div>
          <div class="min-w-0 flex-1">
            <div class="mb-1 flex flex-wrap items-center gap-2">
              <?php if ($r['category'] !== null && $r['category'] !== ''): ?><span class="a-chip bg-secondary-fixed text-on-secondary-fixed"><?= e($r['category']) ?></span><?php endif; ?>
              <?php if (!$on): ?><span class="a-chip bg-surface-container-highest text-on-surface-variant">숨김</span><?php endif; ?>
            </div>
            <details class="group">
              <summary class="flex cursor-pointer list-none items-start gap-1 font-label-md text-label-md text-on-surface">
                <span class="font-bold text-primary">Q.</span><span class="flex-1"><?= e($r['question']) ?></span>
                <span class="material-symbols-outlined text-[18px] text-on-surface-variant transition-transform group-open:rotate-180">expand_more</span>
              </summary>
              <p class="mt-2 whitespace-pre-line rounded-lg bg-surface-container-lowest p-3 font-body-md text-body-md text-on-surface-variant"><?= e($r['answer']) ?></p>
            </details>
          </div>
          <div class="flex shrink-0 items-center gap-1">
            <form method="post" action="<?= e(url('/admin/faqs/' . (int) $r['id'] . '/toggle')) ?>">
              <?= csrf_field() ?>
              <button type="submit" class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 font-label-sm text-label-sm transition-colors <?= $on ? 'text-primary hover:bg-surface-container-high' : 'text-on-surface-variant hover:bg-surface-container-high' ?>" title="<?= $on ? '회원 화면에서 숨기기' : '회원 화면에 보이기' ?>">
                <span class="material-symbols-outlined text-[18px]"><?= $on ? 'visibility' : 'visibility_off' ?></span><?= $on ? '노출 중' : '숨김' ?>
              </button>
            </form>
            <a href="<?= e(url('/admin/faqs/' . (int) $r['id'] . '/edit')) ?>#faq-form" class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary" title="수정" aria-label="수정"><span class="material-symbols-outlined text-[20px]">edit</span></a>
            <form method="post" action="<?= e(url('/admin/faqs/' . (int) $r['id'] . '/delete')) ?>" data-confirm="이 질문을 삭제할까요? 되돌릴 수 없습니다.">
              <?= csrf_field() ?>
              <button type="submit" class="flex h-9 w-9 items-center justify-center rounded-lg text-on-surface-variant transition-colors hover:bg-error-container hover:text-on-error-container" title="삭제" aria-label="삭제"><span class="material-symbols-outlined text-[20px]">delete</span></button>
            </form>
          </div>
        </li>
        <?php endforeach; ?>
      </ol>
    </section>

    <section id="faq-form" class="col-span-12 flex scroll-mt-24 flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md xl:sticky xl:top-24 xl:col-span-4">
      <div class="flex items-center justify-between gap-2">
        <h3 class="font-headline-md text-headline-md text-on-surface"><?= $isEdit ? '질문 수정' : '새 질문 추가' ?></h3>
        <?php if ($isEdit): ?><a href="<?= e(url('/admin/faqs')) ?>" class="font-label-sm text-label-sm text-primary hover:underline">새로 쓰기</a><?php endif; ?>
      </div>
      <form method="post" action="<?= e(url($isEdit ? '/admin/faqs/' . (int) $editing['id'] : '/admin/faqs')) ?>" class="flex flex-col gap-4" novalidate>
        <?= csrf_field() ?>
        <div class="grid grid-cols-3 gap-3">
          <div class="col-span-2">
            <label class="a-label" for="faq-category">분류</label>
            <input id="faq-category" name="category" type="text" list="faq-categories" value="<?= e($f['category']) ?>" maxlength="<?= FaqController::CATEGORY_MAX ?>" placeholder="예) 목소리" class="a-input<?= errors('category') ? ' ring-2 ring-error' : '' ?>">
            <datalist id="faq-categories">
              <?php foreach ($categories as $c): if ($c['category'] === '') { continue; } ?><option value="<?= e($c['category']) ?>"><?php endforeach; ?>
            </datalist>
            <?= $err('category') ?>
          </div>
          <div>
            <label class="a-label" for="faq-sort">순서</label>
            <input id="faq-sort" name="sort_order" type="number" step="1" inputmode="numeric" value="<?= e($f['sort_order']) ?>" placeholder="<?= $isEdit ? '' : (int) $nextSort ?>" class="a-input<?= errors('sort_order') ? ' ring-2 ring-error' : '' ?>">
            <?= $err('sort_order') ?>
          </div>
        </div>
        <div>
          <label class="a-label" for="faq-question">질문</label>
          <input id="faq-question" name="question" type="text" value="<?= e($f['question']) ?>" maxlength="<?= FaqController::QUESTION_MAX ?>" required placeholder="회원이 묻는 말 그대로 적어 주세요" class="a-input<?= errors('question') ? ' ring-2 ring-error' : '' ?>">
          <?= $err('question') ?>
        </div>
        <div>
          <label class="a-label" for="faq-answer">답변</label>
          <textarea id="faq-answer" name="answer" rows="8" required class="a-input font-body-md text-body-md leading-relaxed<?= errors('answer') ? ' ring-2 ring-error' : '' ?>" placeholder="회원 앱 말투(해요체)로 적어 주세요."><?= e($f['answer']) ?></textarea>
          <?= $err('answer') ?>
        </div>
        <label class="flex items-center justify-between gap-4 rounded-xl bg-surface-container-low p-4" for="faq-active">
          <span>
            <span class="block font-label-md text-label-md text-on-surface">회원 화면에 보이기</span>
            <span class="block font-label-sm text-label-sm text-on-surface-variant">끄면 저장만 하고 숨깁니다.</span>
          </span>
          <span class="switch"><input id="faq-active" type="checkbox" name="is_active" value="1"<?= $f['is_active'] ? ' checked' : '' ?>><span></span></span>
        </label>
        <div class="flex gap-2">
          <button type="submit" class="a-btn-primary flex-1"><span class="material-symbols-outlined text-[18px]">save</span><?= $isEdit ? '변경사항 저장' : '질문 추가' ?></button>
          <?php if ($isEdit): ?><a href="<?= e(url('/admin/faqs')) ?>" class="a-btn-tonal">취소</a><?php endif; ?>
        </div>
        <p class="font-label-sm text-label-sm text-on-surface-variant">순서를 비우면 <?= $isEdit ? '지금 순서를 유지합니다' : '맨 뒤에 추가합니다' ?>. 같은 순서 번호는 먼저 만든 질문이 위에 옵니다.</p>
      </form>
    </section>
  </div>
</div>
