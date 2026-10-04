<?php
/**
 * 학습 리포트(시안 _4). 칭찬 문구, 읽은 책, 새로운 단어, 완독률, 독서 성장도(하루별 들은 분), 성장 단어, 아이가 궁금해한 것.
 * @var array $child
 * @var int $range
 * @var string $periodLabel
 * @var string $prevLabel
 * @var bool $hasAny
 * @var array $totals
 * @var array $period
 * @var array $level
 * @var array $headline
 */
layout('user/layout', ['title' => '학습 리포트', 'nav' => 'report', 'mainClass' => 'px-margin-mobile pt-8 pb-6 space-y-8']);

$p = $period;
$wordCount = count($p['words']['heard']);
$masteredCount = count($p['words']['mastered']);
$delta = $p['books'] - $p['books_prev'];
if ($delta > 0) {
    $badge = $prevLabel . '보다 +' . $delta . '권';
} elseif ($delta === 0) {
    $badge = $p['books'] > 0 ? $prevLabel . '와 같아요' : '';
} else {
    $badge = $prevLabel . ' ' . $p['books_prev'] . '권';
}
$maxBooks = max(1, max(array_column($p['daily_books'], 'value')));
$maxMin = max(array_column($p['daily'], 'value'));
$totalMin = (int) round($p['listened_ms'] / 60000);
$activeDays = count(array_filter($p['daily'], static function ($d) {
    return $d['value'] > 0;
}));
$chipStyles = [
    'bg-secondary-fixed text-on-secondary-fixed-variant',
    'bg-tertiary-fixed text-on-tertiary-fixed-variant',
    'bg-primary-fixed text-on-primary-fixed-variant',
    'bg-surface-container-high text-on-surface-variant',
];
$few = $range === 7;
?>
<!-- 칭찬 문구 -->
<section class="bg-primary-container/20 p-md rounded-xl border-l-4 border-primary">
  <h1 class="text-headline-md font-headline-md text-on-primary-container mb-2"><?= e($headline['title']) ?></h1>
  <p class="font-body-md text-body-md text-on-surface-variant"><?= e($headline['body']) ?></p>
  <?php if ($hasAny): ?>
  <p class="mt-3 inline-flex items-center gap-1 rounded-full bg-surface-container-lowest/70 px-3 py-1 text-label-sm font-label-sm text-primary">
    <span class="material-symbols-outlined icon-fill text-[16px]">military_tech</span>레벨 <?= (int) $level['level'] ?> <?= e($level['title']) ?> · <?= e(fmt_number($level['xp'])) ?> XP
  </p>
  <?php endif; ?>
</section>

<?php if (!$hasAny): ?>
<section class="card flex flex-col items-center gap-3 p-8 text-center">
  <img src="<?= e(asset('img/empty-report.svg')) ?>" alt="" class="h-32 w-auto">
  <h2 class="text-headline-md font-headline-md text-on-surface">아직 학습 기록이 없어요</h2>
  <p class="text-body-md text-on-surface-variant">동화를 들으면 읽은 책, 새로 만난 단어,<br>궁금해한 것들이 여기에 차곡차곡 쌓여요.</p>
  <a href="<?= e(url('/stories')) ?>" class="btn-primary mt-2 w-full"><span class="material-symbols-outlined icon-fill">auto_stories</span>동화 들으러 가기</a>
</section>
<?php else: ?>

<!-- 숫자 카드 -->
<div class="grid grid-cols-2 gap-4">
  <div class="col-span-2 bg-surface-container-lowest p-md rounded-xl shadow-soft bento-card border border-surface-variant/50">
    <div class="flex items-start justify-between mb-4">
      <div class="bg-secondary-container p-3 rounded-xl">
        <span class="material-symbols-outlined icon-fill text-secondary">menu_book</span>
      </div>
      <?php if ($badge !== ''): ?>
      <span class="text-label-sm font-label-sm text-on-secondary-fixed-variant bg-secondary-fixed px-2 py-1 rounded-full"><?= e($badge) ?></span>
      <?php endif; ?>
    </div>
    <h2 class="text-label-lg font-label-lg text-on-surface-variant"><?= e($periodLabel) ?> 읽은 책</h2>
    <p class="text-headline-lg font-headline-lg text-on-surface"><?= (int) $p['books'] ?><span class="ml-1 text-body-md text-on-surface-variant">권</span></p>
    <div class="mt-4 flex items-end h-16 <?= $few ? 'gap-1' : 'gap-[2px]' ?>" aria-hidden="true">
      <?php foreach ($p['daily_books'] as $d): $h = $d['value'] > 0 ? max(12, (int) round($d['value'] * 100 / $maxBooks)) : 6; ?>
      <div class="w-full <?= $few ? 'rounded-t-lg' : 'rounded-t' ?> <?= $d['today'] ? 'bg-secondary' : ($d['value'] > 0 ? 'bg-secondary-container' : 'bg-surface-container-high') ?><?= $d['future'] ? ' opacity-40' : '' ?>" style="height: <?= $h ?>%"></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bg-surface-container-lowest p-md rounded-xl shadow-soft bento-card border border-surface-variant/50">
    <div class="bg-primary-container w-fit p-3 rounded-xl mb-4">
      <span class="material-symbols-outlined icon-fill text-primary">spellcheck</span>
    </div>
    <h2 class="text-label-lg font-label-lg text-on-surface-variant">새로운 단어</h2>
    <p class="text-headline-md font-headline-md text-on-surface"><?= $wordCount ?></p>
    <div class="mt-2 text-label-sm font-label-sm text-primary">익힌 단어: <?= $masteredCount ?>개</div>
  </div>

  <div class="bg-surface-container-lowest p-md rounded-xl shadow-soft bento-card border border-surface-variant/50">
    <div class="bg-tertiary-container/30 w-fit p-3 rounded-xl mb-4">
      <span class="material-symbols-outlined icon-fill text-tertiary">task_alt</span>
    </div>
    <h2 class="text-label-lg font-label-lg text-on-surface-variant">완독률</h2>
    <?php if ($p['completion_rate'] !== null): ?>
    <p class="text-headline-md font-headline-md text-on-surface"><?= (int) $p['completion_rate'] ?>%</p>
    <div class="w-full bg-surface-container-high h-2 rounded-full mt-3 overflow-hidden">
      <div class="bg-tertiary h-full rounded-full" style="width: <?= (int) $p['completion_rate'] ?>%"></div>
    </div>
    <p class="mt-2 text-label-sm font-label-sm text-on-surface-variant"><?= (int) $p['started'] ?>번 중 <?= (int) $p['completed_sessions'] ?>번 끝까지</p>
    <?php else: ?>
    <p class="text-headline-md font-headline-md text-on-surface-variant">-</p>
    <p class="mt-2 text-label-sm font-label-sm text-on-surface-variant">아직 들은 동화가 없어요</p>
    <?php endif; ?>
  </div>
</div>

<!-- 독서 성장도 -->
<section id="growth" class="bg-surface-container-lowest p-md rounded-xl shadow-soft border border-surface-variant/50 scroll-mt-24">
  <div class="flex justify-between items-center mb-2">
    <h2 class="text-headline-md font-headline-md text-on-surface">독서 성장도</h2>
    <details class="relative">
      <summary class="flex cursor-pointer list-none items-center gap-1 rounded-full px-2 py-1 text-label-lg font-label-lg text-primary hover:bg-surface-container-low [&::-webkit-details-marker]:hidden">
        <?= e($periodLabel) ?> <span class="material-symbols-outlined text-[18px]">expand_more</span>
      </summary>
      <div class="absolute right-0 z-10 mt-2 w-36 overflow-hidden rounded-xl border border-surface-variant/50 bg-surface-container-lowest shadow-lg">
        <?php foreach ([7 => '이번 주', 30 => '최근 30일'] as $r => $label): ?>
        <a href="<?= e(url('/report', ['range' => $r])) ?>#growth" class="flex items-center justify-between px-4 py-3 text-label-lg font-label-lg <?= $range === $r ? 'bg-primary-container/30 text-primary' : 'text-on-surface hover:bg-surface-container-low' ?>"><?= e($label) ?><?php if ($range === $r): ?><span class="material-symbols-outlined text-[18px]">check</span><?php endif; ?></a>
        <?php endforeach; ?>
      </div>
    </details>
  </div>
  <p class="text-label-sm font-label-sm text-on-surface-variant">
    <?php if ($totalMin > 0): ?>
    모두 <?= e(fmt_number($totalMin)) ?>분 들었어요 · 들은 날 <?= $activeDays ?>일
    <?php else: ?>
    <?= e($periodLabel) ?>에는 아직 들은 시간이 없어요
    <?php endif; ?>
  </p>
  <div class="relative mt-4 h-48 w-full px-1">
    <div class="pointer-events-none absolute inset-0 flex flex-col justify-between opacity-10" aria-hidden="true">
      <div class="border-b border-outline w-full"></div>
      <div class="border-b border-outline w-full"></div>
      <div class="border-b border-outline w-full"></div>
      <div class="border-b border-outline w-full"></div>
    </div>
    <div class="relative flex h-full items-end <?= $few ? 'gap-3' : 'gap-[3px]' ?>" role="img" aria-label="하루별 들은 시간">
      <?php foreach ($p['daily'] as $d): $h = $maxMin > 0 && $d['value'] > 0 ? max(6, (int) round($d['value'] * 82 / $maxMin)) : 0; ?>
      <div class="flex h-full flex-1 flex-col items-center justify-end" title="<?= e($d['date'] . ' ' . $d['value'] . '분') ?>">
        <?php if ($few && $d['value'] > 0): ?>
        <span class="mb-1 text-[10px] font-bold text-on-surface-variant"><?= (int) $d['value'] ?>분</span>
        <?php endif; ?>
        <?php if ($h > 0): ?>
        <div class="w-full <?= $few ? 'max-w-[32px] rounded-t-lg' : 'rounded-t' ?> <?= $d['today'] ? 'bg-primary' : 'bg-primary-container' ?>" style="height: <?= $h ?>%"></div>
        <?php else: ?>
        <div class="h-1 w-full <?= $few ? 'max-w-[32px]' : '' ?> rounded-full bg-surface-container-high<?= $d['future'] ? ' opacity-40' : '' ?>"></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="mt-4 flex px-1 text-label-sm font-label-sm text-outline <?= $few ? 'gap-3' : 'gap-[3px]' ?>">
    <?php foreach ($p['daily'] as $i => $d): $show = $few || $i % 5 === 0 || $i === count($p['daily']) - 1; ?>
    <span class="flex-1 text-center whitespace-nowrap <?= $d['today'] ? 'text-primary' : '' ?> <?= $few ? '' : 'text-[10px]' ?>"><?= $show ? e($d['label']) : '' ?></span>
    <?php endforeach; ?>
  </div>
</section>

<!-- 성장 단어 -->
<section class="space-y-4">
  <h2 class="text-headline-md font-headline-md text-on-surface">성장 단어</h2>
  <?php if ($p['words']['recent']): ?>
  <div class="flex flex-wrap gap-2">
    <?php foreach (array_slice($p['words']['recent'], 0, 16) as $i => $w): ?>
    <span class="whitespace-nowrap px-4 py-2 rounded-full text-label-lg font-label-lg shadow-sm <?= $chipStyles[$i % count($chipStyles)] ?>"><?= e($w) ?></span>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="rounded-xl bg-surface-container-low px-4 py-4 text-body-md text-on-surface-variant">동화를 들으면 새로 만난 단어가 여기에 모여요.</p>
  <?php endif; ?>
</section>

<!-- 아이가 궁금해한 것 -->
<section class="space-y-4 pb-6">
  <h2 class="text-headline-md font-headline-md text-on-surface">아이가 궁금해한 것</h2>
  <?php if ($p['questions']): ?>
  <div class="space-y-3">
    <?php foreach ($p['questions'] as $qa): ?>
    <article class="card space-y-3 p-4">
      <div class="flex items-center justify-between gap-2 text-label-sm font-label-sm text-on-surface-variant">
        <a href="<?= e(url('/player/' . (int) $qa['story_id'])) ?>" class="flex min-w-0 items-center gap-1 text-primary">
          <span class="material-symbols-outlined text-[16px]">auto_stories</span><span class="truncate"><?= e($qa['story_title']) ?></span>
        </a>
        <span class="shrink-0"><?= e(time_ago($qa['created_at'])) ?></span>
      </div>
      <div class="flex items-end justify-end gap-2">
        <p class="max-w-[85%] rounded-2xl rounded-br-md bg-primary-container px-4 py-2.5 text-body-md text-on-primary-container"><?= e($qa['question_text']) ?></p>
        <?= child_avatar($child, 'w-8 h-8 text-base') ?>
      </div>
      <div class="flex items-end gap-2">
        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container">
          <span class="material-symbols-outlined text-[18px]">record_voice_over</span>
        </span>
        <p class="max-w-[85%] rounded-2xl rounded-bl-md bg-secondary-container px-4 py-2.5 text-body-md text-on-secondary-container"><?= e($qa['answer_text']) ?></p>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p class="rounded-xl bg-surface-container-low px-4 py-4 text-body-md text-on-surface-variant">동화를 듣다가 마이크 버튼을 눌러 궁금한 것을 물어보면 여기에 남아요.</p>
  <?php endif; ?>
</section>
<?php endif; ?>
