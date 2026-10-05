<?php
/**
 * 동화 전체 목록. 분류 칩, 검색, 2열 표지 카드(다 들은 표시, 이어 듣기 진행률, 내 요청 현황).
 * 카드를 누르면 동화 상세에서 가족 목소리를 골라 생성 요청을 하거나 완성된 목소리로 듣는다.
 * @var array $stories
 * @var array $categories
 * @var string $category
 * @var string $q
 * @var int $total
 */
layout('user/layout', ['title' => '동화 목록', 'nav' => 'home', 'mainClass' => 'px-margin-mobile pt-6 pb-6 space-y-6']);
$chip = static function (bool $active): string {
    return $active
        ? 'bg-primary text-on-primary shadow-sm'
        : 'bg-surface-container-lowest text-on-surface-variant border border-surface-variant/60 hover:bg-surface-container-low';
};
?>
<section class="space-y-1">
  <h1 class="text-headline-xl-mobile font-headline-xl-mobile text-on-surface">동화 책장</h1>
  <p class="text-body-md text-on-surface-variant"><?= $total > 0 ? e($total . '편의 동화가 기다리고 있어요. 골라서 가족 목소리로 만들어 달라고 요청해 보세요.') : '곧 새로운 동화가 찾아올 거예요' ?></p>
</section>

<form method="get" action="<?= e(url('/stories')) ?>" class="relative" role="search">
  <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= e($category) ?>"><?php endif; ?>
  <span class="material-symbols-outlined pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-on-surface-variant">search</span>
  <input type="search" name="q" value="<?= e($q) ?>" maxlength="50" placeholder="동화 제목으로 찾아보기" class="field rounded-full pl-12 pr-12" aria-label="동화 검색">
  <?php if ($q !== ''): ?>
  <a href="<?= e(url('/stories', $category !== '' ? ['category' => $category] : [])) ?>" class="material-symbols-outlined absolute right-3 top-1/2 -translate-y-1/2 rounded-full p-1 text-on-surface-variant hover:bg-surface-container-low" aria-label="검색어 지우기">close</a>
  <?php endif; ?>
</form>

<?php if ($categories): ?>
<nav class="flex gap-2 overflow-x-auto no-scrollbar -mx-margin-mobile px-margin-mobile pb-1" aria-label="분류">
  <a href="<?= e(url('/stories', $q !== '' ? ['q' => $q] : [])) ?>" class="shrink-0 rounded-full px-4 py-2 text-label-lg font-label-lg transition-all active:scale-95 <?= $chip($category === '') ?>"<?= $category === '' ? ' aria-current="true"' : '' ?>>전체</a>
  <?php foreach ($categories as $c): $params = ['category' => $c] + ($q !== '' ? ['q' => $q] : []); ?>
  <a href="<?= e(url('/stories', $params)) ?>" class="shrink-0 whitespace-nowrap rounded-full px-4 py-2 text-label-lg font-label-lg transition-all active:scale-95 <?= $chip($category === $c) ?>"<?= $category === $c ? ' aria-current="true"' : '' ?>><?= e($c) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($stories): ?>
<div class="grid grid-cols-2 gap-4">
  <?php foreach ($stories as $s): ?>
  <a href="<?= e($s['url']) ?>" class="bento-card flex flex-col overflow-hidden rounded-xl border border-surface-variant/30 bg-surface-container-lowest shadow-sm">
    <div class="relative aspect-[4/5] w-full bg-surface-container">
      <img class="h-full w-full object-cover" src="<?= e(cover_url($s)) ?>" alt="" loading="lazy">
      <?php if (!empty($s['category'])): ?>
      <div class="absolute top-2.5 right-2.5 whitespace-nowrap rounded-full bg-surface-container-lowest/90 px-2 py-1 text-[10px] font-bold tracking-wider text-primary shadow-sm backdrop-blur-md"><?= e($s['category']) ?></div>
      <?php endif; ?>
      <?php if ($s['completed']): ?>
      <div class="absolute top-2.5 left-2.5 flex h-7 w-7 items-center justify-center rounded-full bg-secondary text-on-secondary shadow-sm" title="다 들은 동화">
        <span class="material-symbols-outlined text-[18px]">check</span>
      </div>
      <?php endif; ?>
      <?php if ($s['resume']): ?>
      <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-inverse-surface/70 to-transparent px-3 pb-2.5 pt-6">
        <div class="flex items-center gap-1 text-[11px] font-bold text-inverse-on-surface">
          <span class="material-symbols-outlined icon-fill text-[16px]">play_circle</span>이어 듣기
        </div>
        <?php if ($s['resume']['percent'] !== null): ?>
        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-inverse-on-surface/30">
          <div class="h-full rounded-full bg-primary-container" style="width: <?= (int) $s['resume']['percent'] ?>%"></div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="space-y-1 p-3">
      <h2 class="truncate text-label-lg font-label-lg text-on-surface"><?= e($s['title']) ?></h2>
      <p class="flex items-center gap-1 text-label-sm font-label-sm text-on-surface-variant">
        <?php if ($s['duration_label'] !== ''): ?>
        <span class="material-symbols-outlined text-[14px]">schedule</span><?= e($s['duration_label']) ?>
        <?php else: ?>
        <span class="material-symbols-outlined text-[14px]">auto_stories</span>동화
        <?php endif; ?>
        <?php if ($s['completed']): ?>
        <span class="ml-auto text-secondary">다 들었어요</span>
        <?php endif; ?>
      </p>
      <?php if ($s['mine']['done'] > 0): ?>
      <p class="inline-flex items-center gap-1 rounded-full bg-secondary-container px-2 py-0.5 text-[11px] font-bold text-on-secondary-container"><span class="material-symbols-outlined text-[13px]">graphic_eq</span>가족 목소리 <?= (int) $s['mine']['done'] ?>개 완성</p>
      <?php elseif ($s['mine']['making'] > 0): ?>
      <p class="inline-flex items-center gap-1 rounded-full bg-primary-fixed px-2 py-0.5 text-[11px] font-bold text-on-primary-fixed"><span class="material-symbols-outlined text-[13px]">schedule</span>요청 중</p>
      <?php endif; ?>
    </div>
  </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card flex flex-col items-center gap-3 p-8 text-center">
  <img src="<?= e(asset('img/empty-stories.svg')) ?>" alt="" class="h-28 w-auto">
  <?php if ($q !== '' || $category !== ''): ?>
  <p class="text-label-lg font-label-lg text-on-surface">찾는 동화가 없어요</p>
  <p class="text-body-md text-on-surface-variant">다른 낱말로 찾아보거나 전체 목록을 볼까요?</p>
  <a href="<?= e(url('/stories')) ?>" class="btn-secondary mt-1">전체 동화 보기</a>
  <?php else: ?>
  <p class="text-label-lg font-label-lg text-on-surface">아직 준비된 동화가 없어요</p>
  <p class="text-body-md text-on-surface-variant">새 동화가 올라오면 여기에서 바로 들을 수 있어요.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
