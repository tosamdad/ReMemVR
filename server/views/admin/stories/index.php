<?php
/**
 * 동화 콘텐츠 관리(CMS). 디자인 시안 cms 기준.
 * 변수: story(선택한 동화 또는 null), empty(동화가 하나도 없음), sentences, library, filters, publishedCount, totalCount,
 *       audios(시뮬레이션용 완료 오디오), outdated(옛 본문 오디오 수), timecodeSource, categories, statuses, aecLevels, presets, global, ttsReady
 */
use App\Controllers\Admin\StoryController;

layout('admin/layout', ['title' => '동화 콘텐츠 관리', 'active' => 'stories']);

$isNew = $story === null;
$hasOld = old('_form') === 'story';
$val = static function (string $key, $default) use ($hasOld) {
    if (!$hasOld) {
        return $default;
    }
    $v = old($key, '');

    return is_array($v) ? $default : $v;
};
$err = static function (string $key) {
    $m = errors($key);

    return $m ? '<p class="mt-1 font-label-sm text-label-sm text-error">' . e($m) . '</p>' : '';
};

$statusChip = [
    'published' => 'bg-emerald-100 text-emerald-800',
    'hidden' => 'bg-surface-container-high text-on-surface-variant',
    'draft' => 'bg-secondary-fixed text-on-secondary-fixed-variant',
];
$filterQuery = array_filter($filters, static function ($v) {
    return $v !== '';
});

// 문장 행: 검증 실패로 돌아왔으면 직전 입력, 아니면 DB
$rows = [];
if ($hasOld && is_array(old('sentences', null))) {
    foreach (old('sentences', []) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rows[] = [
            'content' => isset($r['content']) ? (string) $r['content'] : '',
            'keywords' => isset($r['keywords']) ? (string) $r['keywords'] : '',
            'start' => isset($r['start']) ? (string) $r['start'] : '',
            'end' => isset($r['end']) ? (string) $r['end'] : '',
        ];
    }
} else {
    foreach ($sentences as $s) {
        $rows[] = [
            'content' => (string) $s['content'],
            'keywords' => (string) $s['keywords'],
            'start' => StoryController::formatTime($s['ref_start_ms'] !== null ? (int) $s['ref_start_ms'] : null),
            'end' => StoryController::formatTime($s['ref_end_ms'] !== null ? (int) $s['ref_end_ms'] : null),
        ];
    }
}
if (!$rows && ($isNew || $hasOld)) {
    $rows[] = ['content' => '', 'keywords' => '', 'start' => '', 'end' => ''];
}

// 끼어들기 설정 값
$maxQ = $val('max_questions', $story && $story['max_questions'] !== null ? (string) $story['max_questions'] : '');
$effectiveMax = $maxQ !== '' && is_numeric($maxQ) ? (int) $maxQ : (int) $global['max_questions'];
$bargeOn = $hasOld ? (bool) old('barge_in_enabled', '') : ($story ? (int) $story['barge_in_enabled'] === 1 : true);
$vadCustom = $hasOld ? old('vad_custom', '0') === '1' : ($story && $story['vad_min_ms'] !== null);
$vadMs = $story && $story['vad_min_ms'] !== null ? (int) $story['vad_min_ms'] : (int) $global['vad_ms'];
$vadSec = $hasOld && is_numeric(old('vad_seconds', '')) ? (float) old('vad_seconds') : $vadMs / 1000;
$vadSec = max(0.5, min(2.0, round($vadSec, 1)));
$aecValue = $val('aec_level', $story && $story['aec_level'] !== null ? (string) $story['aec_level'] : '');
$aecShown = $aecValue !== '' ? $aecValue : (string) $global['aec'];
$fbCustom = $hasOld ? old('fallback_custom', '0') === '1' : ($story && $story['fallback_lines'] !== null);
if ($hasOld && is_array(old('fallback_lines', null))) {
    $fbLines = array_values(array_filter(array_map('strval', old('fallback_lines', [])), static function ($l) {
        return trim($l) !== '';
    }));
} elseif ($story && $story['fallback_lines'] !== null) {
    $fbLines = json_decode_array($story['fallback_lines']);
} else {
    $fbLines = $global['fallback_lines'];
}

$coverPath = $story ? (string) $story['cover_image_path'] : '';
$currentPreset = preg_match('#^assets:covers/(\d{2})\.svg$#', $coverPath, $cm) ? $cm[1] : '';
$checkedPreset = $val('cover_preset', $currentPreset !== '' ? $currentPreset : ($isNew ? '01' : 'keep'));
$aecHelp = [
    'normal' => '브라우저 기본 반향 제거만 사용합니다. 조용한 방에서 이어폰 없이 들을 때 적합합니다.',
    'strong' => '동화 오디오가 스피커에서 흘러나오는 도중 마이크에 역류되는 간섭음을 실시간 소거합니다.',
    'max' => '반향 제거에 잡음 억제까지 더합니다. 시끄러운 곳에 적합하지만 작은 목소리가 덜 들릴 수 있습니다.',
];
$aecTitle = ['normal' => '보통 (기본 반향 제거)', 'strong' => '강함 (스피커 반사음 차단)', 'max' => '최대 (잡음 억제 포함)'];
$activeTab = $isNew ? 'basic' : 'sentences';
$savedSentences = array_map(static function ($s) {
    return ['seq' => (int) $s['seq'], 'content' => (string) $s['content']];
}, $sentences);
?>
<div class="flex w-full flex-col gap-8" data-story-page data-story-id="<?= $story ? (int) $story['id'] : 0 ?>">
  <!-- 상단 머리 카드 -->
  <div class="flex flex-col justify-between gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm lg:flex-row lg:items-center">
    <div class="flex flex-col gap-1.5">
      <div class="flex flex-wrap items-center gap-3">
        <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Fairytale Engine CMS</span>
        <?php if ($ttsReady): ?>
        <span class="flex items-center gap-1.5 font-label-sm text-label-sm text-on-surface-variant"><span class="h-2 w-2 rounded-full bg-emerald-500"></span>타임스탬프 동기화 엔진 활성화됨</span>
        <?php else: ?>
        <span class="flex items-center gap-1.5 font-label-sm text-label-sm text-on-surface-variant"><span class="h-2 w-2 rounded-full bg-outline"></span>타임스탬프 동기화 대기 (ElevenLabs 키 미등록)</span>
        <?php endif; ?>
      </div>
      <div class="flex flex-wrap items-baseline gap-3">
        <h1 class="font-headline-md text-headline-md text-on-surface">무료 인터랙티브 동화 관리</h1>
        <span class="font-body-md text-body-md text-on-surface-variant">총 <span class="font-bold text-primary"><?= (int) $publishedCount ?>편</span> 서비스 운용 중<?php if ($totalCount > $publishedCount): ?> <span class="font-label-sm text-label-sm">(전체 <?= (int) $totalCount ?>편)</span><?php endif; ?></span>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <button type="button" class="flex items-center gap-2 rounded-xl bg-surface-container px-4 py-2.5 font-label-md text-label-md text-on-surface transition-all hover:bg-surface-container-high" data-open-deploy>
        <span class="material-symbols-outlined text-[18px]">cloud_sync</span><span>배포 상태 점검</span>
      </button>
      <a href="<?= e(url('/admin/stories/new', $filterQuery)) ?>" class="flex items-center gap-2 rounded-xl bg-primary px-5 py-2.5 font-label-md text-label-md text-on-primary shadow-sm transition-all hover:shadow-md">
        <span class="material-symbols-outlined text-[20px]">add_circle</span><span>+ 새 무료 동화 등록</span>
      </a>
    </div>
  </div>

  <div class="grid grid-cols-12 items-start gap-8">
    <!-- 왼쪽: 도서 라이브러리 -->
    <div class="col-span-12 flex flex-col gap-4 xl:col-span-4">
      <div class="flex items-center justify-between px-1">
        <div class="flex items-center gap-2">
          <span class="material-symbols-outlined text-[20px] text-primary">library_books</span>
          <span class="font-headline-md text-headline-md text-on-surface">도서 라이브러리</span>
        </div>
        <span class="rounded-md bg-surface-container px-2 py-0.5 font-label-sm text-label-sm text-on-surface-variant"><?= count($library) ?>편 표시 중</span>
      </div>
      <form method="get" action="<?= e(url($story ? '/admin/stories/' . (int) $story['id'] : '/admin/stories')) ?>" class="flex gap-2" data-library-filter>
        <label class="flex flex-1 items-center rounded-xl bg-surface-container-lowest px-3 py-2 shadow-sm">
          <span class="material-symbols-outlined mr-2 text-[20px] text-on-surface-variant">search</span>
          <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="제목, 코드, 분류 검색" class="w-full border-0 bg-transparent p-0 font-label-md text-label-md text-on-surface placeholder:text-outline focus:ring-0">
        </label>
        <select name="status" class="rounded-xl border-0 bg-surface-container-lowest py-2 pl-3 pr-8 font-label-md text-label-md text-on-surface shadow-sm focus:ring-2 focus:ring-primary/30" data-auto-submit aria-label="상태 필터">
          <option value="">전체 상태</option>
          <?php foreach ($statuses as $k => $label): ?>
          <option value="<?= e($k) ?>"<?= $filters['status'] === $k ? ' selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <div class="flex flex-col gap-3">
        <?php if (!$library): ?>
        <div class="flex flex-col items-center gap-3 rounded-xl bg-surface-container-lowest p-8 text-center shadow-sm">
          <img src="<?= e(asset('img/empty-stories.svg')) ?>" alt="" class="h-24 w-24 opacity-90" onerror="this.remove()">
          <p class="font-label-md text-label-md text-on-surface"><?= $filters['q'] !== '' || $filters['status'] !== '' ? '조건에 맞는 동화가 없습니다.' : '아직 등록된 동화가 없습니다.' ?></p>
          <p class="font-label-sm text-label-sm text-on-surface-variant">오른쪽 위 "+ 새 무료 동화 등록"으로 첫 동화를 만들어 보세요.</p>
        </div>
        <?php endif; ?>
        <?php foreach ($library as $item):
            $selected = $story && (int) $item['id'] === (int) $story['id'];
            $durMs = $item['audio_ms'] !== null ? (int) round((float) $item['audio_ms']) : (int) $item['est_duration_sec'] * 1000;
            $age = $item['age_min'] !== null || $item['age_max'] !== null
                ? '만 ' . ($item['age_min'] !== null ? (int) $item['age_min'] : 0) . '~' . ($item['age_max'] !== null ? (int) $item['age_max'] : '') . '세' : '연령 미정';
        ?>
        <a href="<?= e(url('/admin/stories/' . (int) $item['id'], $filterQuery)) ?>" class="group relative flex flex-col rounded-xl bg-surface-container-lowest p-5 shadow-sm transition-all <?= $selected ? 'ring-2 ring-primary/80' : 'hover:shadow-md' ?>"<?= $selected ? ' aria-current="true"' : '' ?>>
          <div class="flex gap-4">
            <div class="relative h-20 w-20 flex-shrink-0 overflow-hidden rounded-xl bg-surface-container">
              <img class="h-full w-full object-cover" src="<?= e(cover_url($item)) ?>" alt="" loading="lazy">
              <span class="absolute bottom-1 right-1 rounded bg-on-background/70 px-1.5 py-0.5 font-label-sm text-label-sm text-surface backdrop-blur"><?= $durMs > 0 ? e(fmt_duration($durMs)) : '--:--' ?></span>
            </div>
            <div class="flex min-w-0 flex-1 flex-col justify-between gap-2">
              <div class="flex items-start justify-between gap-2">
                <span class="truncate font-headline-md text-headline-md text-on-surface" title="<?= e($item['title']) ?>"><?= (int) $item['sort_order'] ?>. <?= e($item['title']) ?></span>
                <span class="flex-shrink-0 rounded-full px-2 py-0.5 font-label-sm text-label-sm <?= $statusChip[$item['status']] ?? $statusChip['draft'] ?>"><?= e($statuses[$item['status']] ?? $item['status']) ?></span>
              </div>
              <div class="flex flex-wrap items-center gap-2 font-label-sm text-label-sm text-on-surface-variant">
                <span class="rounded bg-surface-container px-2 py-0.5"><?= e($age) ?></span>
                <span>문장 <?= (int) $item['sentence_count'] ?>개</span>
                <?php if ((int) $item['barge_in_enabled'] === 1): ?>
                <span>Barge-in 적용</span>
                <?php else: ?>
                <span class="text-outline">Barge-in 꺼짐</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php if ($selected): ?>
          <div class="mt-4 flex items-center justify-between border-t border-surface-variant/40 pt-3">
            <span class="flex items-center gap-1 font-label-sm text-label-sm font-semibold text-primary"><span class="material-symbols-outlined text-[16px]">edit_note</span> 편집 중인 스토리</span>
            <span class="material-symbols-outlined text-[18px] text-primary">chevron_right</span>
          </div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 오른쪽: 편집기 -->
    <div class="col-span-12 flex flex-col gap-6 xl:col-span-8">
      <?php if ($empty): ?>
      <div class="flex flex-col items-center gap-4 rounded-xl bg-surface-container-lowest p-12 text-center shadow-sm">
        <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[32px]">auto_stories</span></span>
        <h2 class="font-headline-md text-headline-md text-on-surface">편집할 동화가 없습니다</h2>
        <p class="max-w-md font-body-md text-body-md text-on-surface-variant">동화를 등록하면 문장별 맥락 키워드와 타임코드, 끼어들기 설정을 이곳에서 관리할 수 있습니다. 등록한 동화는 목소리마다 한 번씩 미리 생성됩니다.</p>
        <a href="<?= e(url('/admin/stories/new')) ?>" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">add_circle</span>새 무료 동화 등록</a>
      </div>
      <?php else: ?>
      <?php if ($story && $outdated > 0): ?>
      <div class="flex flex-col gap-3 rounded-xl bg-secondary-fixed px-5 py-4 text-on-secondary-fixed sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-start gap-3">
          <span class="material-symbols-outlined text-[22px] text-secondary">sync_problem</span>
          <div>
            <p class="font-label-md text-label-md">옛 본문으로 만든 목소리 오디오 <?= (int) $outdated ?>개가 있습니다.</p>
            <p class="font-label-sm text-label-sm text-on-secondary-fixed-variant">회원은 다시 생성될 때까지 옛 본문 오디오를 듣습니다. 다시 생성하면 ElevenLabs 크레딧이 사용됩니다.</p>
          </div>
        </div>
        <div class="flex shrink-0 gap-2">
          <button type="button" class="a-btn-tonal" data-open-deploy>배포 상태 점검</button>
          <?php if ($story['status'] === 'published'): ?>
          <form method="post" action="<?= e(url('/admin/stories/' . (int) $story['id'] . '/regenerate')) ?>" data-confirm="옛 본문 오디오를 현재 본문으로 다시 생성할까요? 목소리마다 약 <?= (int) $story['char_count'] ?>자 분량의 크레딧이 사용됩니다.">
            <?= csrf_field() ?><input type="hidden" name="mode" value="stale">
            <button type="submit" class="a-btn-secondary"><span class="material-symbols-outlined text-[18px]">autorenew</span>이 동화 다시 생성</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>

      <div class="overflow-hidden rounded-xl bg-surface-container-lowest shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4 bg-surface-container-low p-6">
          <div class="flex min-w-0 items-center gap-3">
            <span class="material-symbols-outlined text-[28px] text-secondary">auto_stories</span>
            <div class="min-w-0">
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-headline-md text-headline-md font-bold text-on-surface">
                  <?php if ($isNew): ?>새 무료 동화 등록<?php else: ?><?= (int) $story['sort_order'] ?>. <?= e($story['title']) ?><?= $story['code'] ? ' (' . e($story['code']) . ')' : '' ?><?php endif; ?>
                </h2>
                <span class="rounded bg-primary-fixed px-2 py-0.5 font-label-sm text-label-sm font-semibold text-on-primary-fixed-variant"><?= $isNew ? '작성 중' : '선택됨' ?></span>
              </div>
              <p class="font-body-md text-body-md text-on-surface-variant">
                <?php if ($isNew): ?>제목과 본문 문장을 입력하고 저장하세요. 활성 상태로 저장하면 회원 화면에 바로 보입니다.
                <?php else: ?><?= e($story['summary'] ? str_limit($story['summary'], 70) : (($story['category'] ?: '분류 없음') . ' · 글자 ' . number_format((int) $story['char_count']) . '자')) ?><?php endif; ?>
              </p>
            </div>
          </div>
          <div class="flex items-center gap-2">
            <button type="button" class="flex items-center gap-1.5 rounded-xl bg-surface-container px-3.5 py-2 font-label-md text-label-md text-on-surface transition-colors hover:bg-surface-container-high" data-open-simulate>
              <span class="material-symbols-outlined text-[18px]">play_circle</span>전체 청취 시뮬레이션
            </button>
            <button type="submit" form="story-form" class="flex items-center gap-1.5 rounded-xl bg-primary px-4 py-2 font-label-md text-label-md text-on-primary shadow-sm transition-all hover:shadow">
              <span class="material-symbols-outlined text-[18px]">save</span>변경사항 저장
            </button>
          </div>
        </div>

        <div class="flex items-center overflow-x-auto bg-surface-container-lowest px-6 no-scrollbar" role="tablist">
          <?php foreach (['basic' => ['info', '기본 정보'], 'sentences' => ['timer', '본문 문장 타임스탬프'], 'bargein' => ['record_voice_over', '끼어들기(Barge-in) 설정']] as $tab => $t): ?>
          <button type="button" role="tab" data-tab="<?= $tab ?>" class="flex shrink-0 items-center gap-2 border-b-2 px-5 py-4 font-label-md text-label-md transition-colors <?= $activeTab === $tab ? 'border-primary font-bold text-primary' : 'border-transparent text-on-surface-variant hover:text-on-surface' ?>">
            <span class="material-symbols-outlined text-[18px]"><?= $t[0] ?></span><?= e($t[1]) ?>
          </button>
          <?php endforeach; ?>
        </div>

        <form id="story-form" method="post" enctype="multipart/form-data" action="<?= e(url($isNew ? '/admin/stories' : '/admin/stories/' . (int) $story['id'])) ?>" class="flex flex-col gap-6 p-6" novalidate data-story-form>
          <?= csrf_field() ?>
          <input type="hidden" name="_form" value="story">
          <input type="hidden" name="_tab" value="<?= e($activeTab) ?>" data-tab-input>

          <!-- 기본 정보 -->
          <section class="flex flex-col gap-6" data-panel="basic"<?= $activeTab === 'basic' ? '' : ' hidden' ?>>
            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
              <div class="md:col-span-2">
                <label class="a-label" for="f-title">동화 제목</label>
                <input id="f-title" name="title" class="a-input" maxlength="200" required value="<?= e($val('title', $story ? $story['title'] : '')) ?>" placeholder="예) 달님의 비밀">
                <?= $err('title') ?>
              </div>
              <div>
                <label class="a-label" for="f-code">관리 코드</label>
                <input id="f-code" name="code" class="a-input font-mono" maxlength="30" value="<?= e($val('code', $story ? (string) $story['code'] : '')) ?>" placeholder="비우면 자동 (Fairytale-013)">
                <?= $err('code') ?>
              </div>
              <div>
                <label class="a-label" for="f-author">지은이</label>
                <input id="f-author" name="author" class="a-input" maxlength="100" value="<?= e($val('author', $story ? (string) $story['author'] : '르멤버 창작')) ?>">
                <?= $err('author') ?>
              </div>
              <div class="md:col-span-2">
                <label class="a-label" for="f-summary">소개</label>
                <textarea id="f-summary" name="summary" rows="2" maxlength="500" class="a-input" placeholder="회원 화면 동화 목록에 보이는 한두 문장 소개"><?= e($val('summary', $story ? (string) $story['summary'] : '')) ?></textarea>
                <?= $err('summary') ?>
              </div>
              <div>
                <label class="a-label" for="f-category">분류</label>
                <select id="f-category" name="category" class="a-input">
                  <option value="">분류 없음</option>
                  <?php $cat = $val('category', $story ? (string) $story['category'] : ''); foreach ($categories as $c): ?>
                  <option value="<?= e($c) ?>"<?= $cat === $c ? ' selected' : '' ?>><?= e($c) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= $err('category') ?>
              </div>
              <div>
                <span class="a-label">권장 연령 (만 나이)</span>
                <div class="flex items-center gap-2">
                  <input name="age_min" type="number" min="0" max="12" class="a-input" aria-label="최소 나이" value="<?= e($val('age_min', $story && $story['age_min'] !== null ? (string) $story['age_min'] : '')) ?>" placeholder="3">
                  <span class="text-on-surface-variant">~</span>
                  <input name="age_max" type="number" min="0" max="12" class="a-input" aria-label="최대 나이" value="<?= e($val('age_max', $story && $story['age_max'] !== null ? (string) $story['age_max'] : '')) ?>" placeholder="7">
                  <span class="shrink-0 font-label-md text-label-md text-on-surface-variant">세</span>
                </div>
                <?= $err('age_min') ?><?= $err('age_max') ?>
              </div>
              <div>
                <span class="a-label">공개 상태</span>
                <div class="flex gap-2">
                  <?php $st = $val('status', $story ? $story['status'] : 'draft'); foreach ($statuses as $k => $label): ?>
                  <label class="flex-1 cursor-pointer">
                    <input type="radio" name="status" value="<?= e($k) ?>" class="peer sr-only"<?= $st === $k ? ' checked' : '' ?>>
                    <span class="flex items-center justify-center rounded-lg bg-surface-container py-2 font-label-sm text-label-sm text-on-surface-variant transition-colors hover:bg-surface-container-high peer-checked:bg-primary peer-checked:text-on-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40"><?= e($label) ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
                <p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">활성: 회원에게 공개, 숨김: 목록에서 감춤, 초안: 작성 중</p>
                <?= $err('status') ?>
              </div>
              <div>
                <label class="a-label" for="f-sort">정렬 순서 (목록 번호)</label>
                <input id="f-sort" name="sort_order" type="number" class="a-input" value="<?= e($val('sort_order', $story ? (string) $story['sort_order'] : '')) ?>" placeholder="비우면 맨 뒤">
                <?= $err('sort_order') ?>
              </div>
            </div>

            <div class="flex flex-col gap-3 rounded-xl bg-surface-container-low p-5">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-label-md text-label-md font-bold text-on-surface">표지 이미지</span>
                <span class="font-label-sm text-label-sm text-on-surface-variant">기본 표지 12종 중 고르거나 PNG, JPG, WEBP(2MB 이하)를 올리세요.</span>
              </div>
              <div class="grid grid-cols-4 gap-3 sm:grid-cols-6 lg:grid-cols-7">
                <?php if ($coverPath !== '' && strpos($coverPath, 'storage:') === 0): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="cover_preset" value="keep" class="peer sr-only"<?= $checkedPreset === 'keep' ? ' checked' : '' ?>>
                  <span class="relative block aspect-square overflow-hidden rounded-xl bg-surface-container ring-offset-2 peer-checked:ring-2 peer-checked:ring-primary">
                    <img src="<?= e(cover_url($story)) ?>" alt="현재 업로드 표지" class="h-full w-full object-cover">
                    <span class="absolute inset-x-0 bottom-0 bg-on-background/60 py-0.5 text-center font-label-sm text-[10px] text-surface">업로드</span>
                  </span>
                </label>
                <?php endif; ?>
                <?php foreach ($presets as $num => $path): ?>
                <label class="cursor-pointer">
                  <input type="radio" name="cover_preset" value="<?= e($num) ?>" class="peer sr-only"<?= $checkedPreset === $num ? ' checked' : '' ?>>
                  <span class="block aspect-square overflow-hidden rounded-xl bg-surface-container ring-offset-2 peer-checked:ring-2 peer-checked:ring-primary">
                    <img src="<?= e(asset('covers/' . $num . '.svg')) ?>" alt="기본 표지 <?= e($num) ?>" class="h-full w-full object-cover" loading="lazy">
                  </span>
                </label>
                <?php endforeach; ?>
              </div>
              <div class="flex flex-wrap items-center gap-3">
                <label class="a-btn-tonal cursor-pointer">
                  <span class="material-symbols-outlined text-[18px]">upload</span>직접 업로드
                  <input type="file" name="cover_file" accept="image/png,image/jpeg,image/webp" class="sr-only" data-cover-input>
                </label>
                <span class="font-label-sm text-label-sm text-on-surface-variant" data-cover-name>선택한 파일 없음</span>
              </div>
              <?= $err('cover_file') ?>
            </div>

            <?php if ($story): ?>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
              <div class="rounded-xl bg-surface-container-low p-4"><p class="font-label-sm text-label-sm text-on-surface-variant">글자 수</p><p class="font-label-md text-label-md text-on-surface"><?= number_format((int) $story['char_count']) ?>자</p></div>
              <div class="rounded-xl bg-surface-container-low p-4"><p class="font-label-sm text-label-sm text-on-surface-variant">예상 낭독 시간</p><p class="font-label-md text-label-md text-on-surface"><?= $story['est_duration_sec'] !== null ? e(fmt_duration((int) $story['est_duration_sec'] * 1000)) : '-' ?></p></div>
              <div class="rounded-xl bg-surface-container-low p-4"><p class="font-label-sm text-label-sm text-on-surface-variant">내용 해시</p><p class="font-mono text-label-sm text-on-surface"><?= $story['content_hash'] ? e(substr($story['content_hash'], 0, 12)) . '…' : '-' ?></p></div>
              <div class="rounded-xl bg-surface-container-low p-4"><p class="font-label-sm text-label-sm text-on-surface-variant">마지막 수정</p><p class="font-label-md text-label-md text-on-surface"><?= e(date('Y.m.d H:i', strtotime($story['updated_at']))) ?></p></div>
            </div>
            <div class="flex items-center justify-between gap-3 rounded-xl border border-error-container p-4">
              <div>
                <p class="font-label-md text-label-md text-on-surface">동화 삭제</p>
                <p class="font-label-sm text-label-sm text-on-surface-variant">회원 화면에서 사라지고 이어 듣기 기록만 남습니다. 생성된 오디오 파일은 그대로 보관됩니다.</p>
              </div>
              <button type="submit" form="story-delete-form" class="a-btn-danger shrink-0"><span class="material-symbols-outlined text-[18px]">delete</span>삭제</button>
            </div>
            <?php endif; ?>
          </section>

          <!-- 본문 문장 타임스탬프 -->
          <section class="flex flex-col gap-3" data-panel="sentences"<?= $activeTab === 'sentences' ? '' : ' hidden' ?>>
            <div class="flex flex-wrap items-center justify-between gap-2">
              <div class="flex flex-wrap items-center gap-2">
                <span class="font-label-md text-label-md font-bold text-on-surface">오디오 타임코드 및 컨텍스트 키워드 매핑</span>
                <span class="font-label-sm text-label-sm text-on-surface-variant">(질문 답변 맥락과 재생 위치 동기화)</span>
              </div>
              <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="flex items-center gap-1 font-label-sm text-label-sm font-semibold text-primary hover:underline" data-toggle-split><span class="material-symbols-outlined text-[16px]">content_paste</span>본문 붙여넣기로 문장 나누기</button>
                <?php if ($story): ?>
                <button type="submit" form="timecode-form" class="flex items-center gap-1 font-label-sm text-label-sm font-semibold text-primary hover:underline disabled:cursor-not-allowed disabled:text-outline disabled:no-underline" data-timecode-btn<?= $timecodeSource ? '' : ' disabled title="현재 본문으로 만든 완료 오디오가 아직 없습니다"' ?>><span class="material-symbols-outlined text-[16px]">schedule</span>기준 오디오에서 타임코드 가져오기</button>
                <?php endif; ?>
                <button type="button" class="flex items-center gap-1 font-label-sm text-label-sm font-semibold text-primary hover:underline" data-add-row><span class="material-symbols-outlined text-[16px]">add</span>문장 행 추가</button>
              </div>
            </div>
            <?php if ($story && $timecodeSource): ?>
            <p class="font-label-sm text-label-sm text-on-surface-variant">타임코드 기준 오디오: <?= e($timecodeSource['label']) ?> 목소리 (현재 본문으로 생성됨)</p>
            <?php endif; ?>

            <div class="hidden flex-col gap-3 rounded-xl bg-surface-container-low p-4" data-split-panel>
              <label class="font-label-md text-label-md text-on-surface" for="split-body">동화 본문 붙여넣기</label>
              <textarea id="split-body" rows="6" class="a-input bg-surface-container-lowest" placeholder="본문 전체를 붙여 넣으면 마침표, 물음표, 느낌표, 줄바꿈 기준으로 문장을 나눕니다. 따옴표 안의 대사는 한 문장으로 둡니다." data-split-body></textarea>
              <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="a-btn-primary" data-split-run="replace"><span class="material-symbols-outlined text-[18px]">splitscreen</span>문장 나누기 (표 바꾸기)</button>
                <button type="button" class="a-btn-tonal" data-split-run="append">표 뒤에 추가</button>
                <span class="font-label-sm text-label-sm text-on-surface-variant">나눈 결과는 저장 전까지 이 화면에만 반영됩니다.</span>
              </div>
            </div>

            <?php if (errors('sentences')): ?><p class="rounded-lg bg-error-container px-3 py-2 font-label-sm text-label-sm text-on-error-container"><?= e(errors('sentences')) ?></p><?php endif; ?>
            <div class="relative overflow-x-auto rounded-xl bg-surface-container-low p-1">
              <table class="w-full text-left font-body-md text-body-md">
                <thead>
                  <tr class="bg-surface-container-high/60 font-label-sm text-label-sm text-on-surface-variant">
                    <th class="rounded-l-lg py-3 pl-4 pr-2">ID</th>
                    <th class="px-4 py-3">본문 텍스트 (아이용 정제 스크립트)</th>
                    <th class="whitespace-nowrap px-2 py-3">시작 (MM:SS)</th>
                    <th class="whitespace-nowrap px-2 py-3">종료 (MM:SS)</th>
                    <th class="px-2 py-3">맥락 키워드</th>
                    <th class="rounded-r-lg py-3 pl-1 pr-4 text-right">작업</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-surface-variant/40" data-rows>
                  <?php foreach ($rows as $i => $r): ?>
                  <?= partial('admin/stories/_row', ['i' => $i, 'r' => $r, 'errs' => [
                      'content' => errors('sentences.' . $i . '.content'),
                      'keywords' => errors('sentences.' . $i . '.keywords'),
                      'start' => errors('sentences.' . $i . '.start'),
                      'end' => errors('sentences.' . $i . '.end'),
                  ]]) ?>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <p class="px-4 py-6 text-center font-label-md text-label-md text-on-surface-variant<?= $rows ? ' hidden' : '' ?>" data-rows-empty>아직 문장이 없습니다. "본문 붙여넣기로 문장 나누기"나 "문장 행 추가"로 시작하세요.</p>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-2 px-1 font-label-sm text-label-sm text-on-surface-variant">
              <span data-summary>문장 0개</span>
              <span>저장하면 문장 순서대로 ST-01부터 번호가 다시 매겨집니다.</span>
            </div>
          </section>

          <!-- 끼어들기(Barge-in) 설정 -->
          <section class="flex flex-col gap-6 rounded-xl bg-surface-container-low p-5" data-panel="sentences bargein"<?= $activeTab === 'basic' ? ' hidden' : '' ?>>
            <div class="flex flex-col justify-between gap-2 sm:flex-row sm:items-center">
              <div>
                <div class="flex items-center gap-2">
                  <span class="material-symbols-outlined text-[22px] text-secondary">hearing</span>
                  <span class="font-label-md text-label-md font-bold text-on-surface">끼어들기(Barge-in) 및 반복 발화 방어 제어</span>
                </div>
                <p class="mt-0.5 font-label-sm text-label-sm text-on-surface-variant">아이가 동화 청취 중 <span data-max-text><?= (int) $effectiveMax ?></span>회를 넘겨 질문하면, 이야기 흐름 복귀를 유도하는 대체 음성을 들려줍니다.</p>
              </div>
              <div class="flex items-center gap-2">
                <span class="font-label-sm text-label-sm text-on-surface-variant">초과 방어 로직:</span>
                <span class="rounded-full bg-secondary-container px-2.5 py-1 font-label-sm text-label-sm font-semibold text-on-secondary-container"><span data-max-text><?= (int) $effectiveMax ?></span>회 초과 시 적용</span>
              </div>
            </div>

            <?php if (!$global['enabled']): ?>
            <p class="rounded-lg bg-error-container px-3 py-2 font-label-sm text-label-sm text-on-error-container">운영 설정에서 질문 기능이 꺼져 있어 모든 동화에 대체 문장만 나갑니다. <a class="underline" href="<?= e(url('/admin/settings#defense')) ?>">설정 보기</a></p>
            <?php endif; ?>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <label class="flex items-center justify-between gap-4 rounded-xl bg-surface-container-lowest p-4 shadow-sm">
                <span>
                  <span class="block font-label-md text-label-md text-on-surface">이 동화에서 질문 받기</span>
                  <span class="block font-label-sm text-label-sm text-on-surface-variant">끄면 질문 버튼을 눌러도 대체 문장으로 안내합니다.</span>
                </span>
                <span class="switch"><input type="checkbox" name="barge_in_enabled" value="1"<?= $bargeOn ? ' checked' : '' ?>><span></span></span>
              </label>
              <div class="rounded-xl bg-surface-container-lowest p-4 shadow-sm">
                <label class="block font-label-md text-label-md text-on-surface" for="f-maxq">편당 질문 한도</label>
                <div class="mt-1 flex items-center gap-2">
                  <input id="f-maxq" name="max_questions" type="number" min="0" max="10" class="a-input w-28" value="<?= e($maxQ) ?>" placeholder="<?= (int) $global['max_questions'] ?>" data-max-input data-global="<?= (int) $global['max_questions'] ?>">
                  <span class="font-label-sm text-label-sm text-on-surface-variant">회 · 비우면 전체 설정(<?= (int) $global['max_questions'] ?>회) 적용</span>
                </div>
                <?= $err('max_questions') ?>
              </div>
            </div>

            <div class="flex flex-col gap-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="font-label-md text-label-md text-on-surface">질문 한도 초과 대체 문장</span>
                <span class="rounded-full px-2.5 py-0.5 font-label-sm text-label-sm <?= $fbCustom ? 'bg-primary-fixed text-on-primary-fixed-variant' : 'bg-surface-container-high text-on-surface-variant' ?>" data-fb-badge><?= $fbCustom ? '이 동화 전용 문장' : '전체 설정 문장 사용 중' ?></span>
              </div>
              <input type="hidden" name="fallback_custom" value="<?= $fbCustom ? '1' : '0' ?>" data-fb-custom>
              <div class="grid grid-cols-1 gap-4 md:grid-cols-2" data-fb-list>
                <?php foreach ($fbLines as $n => $line): ?>
                <?= partial('admin/stories/_fallback', ['n' => $n, 'line' => (string) $line]) ?>
                <?php endforeach; ?>
              </div>
              <?= $err('fallback_lines') ?>
              <div class="flex flex-wrap items-center justify-between gap-2">
                <button type="button" class="flex items-center gap-1 font-label-sm text-label-sm font-semibold text-primary hover:underline" data-fb-add><span class="material-symbols-outlined text-[16px]">add</span>대체 문장 추가</button>
                <button type="button" class="font-label-sm text-label-sm text-on-surface-variant hover:underline<?= $fbCustom ? '' : ' hidden' ?>" data-fb-reset>전체 설정 문장으로 되돌리기</button>
              </div>
              <p class="flex items-start gap-1.5 font-label-sm text-label-sm text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">info</span>미리듣기는 기기 음성입니다. 가족 목소리 음성은 목소리마다 voice_clips 작업이 미리 만들어 두며, 문장을 바꾼 뒤에는 운영 설정의 "모든 목소리 대체 음성 다시 만들기"로 새 문장을 만들 수 있습니다.</p>
            </div>

            <div class="grid grid-cols-1 gap-6 pt-2 md:grid-cols-2">
              <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                  <label class="font-label-md text-label-md font-medium text-on-surface" for="f-vad">VAD 지속 발화 감지 임계값</label>
                  <span class="font-label-sm text-label-sm font-bold text-primary" data-vad-label></span>
                </div>
                <input type="hidden" name="vad_custom" value="<?= $vadCustom ? '1' : '0' ?>" data-vad-custom data-global="<?= e(number_format(max(0.5, min(2.0, $global['vad_ms'] / 1000)), 1)) ?>">
                <input id="f-vad" type="range" name="vad_seconds" min="0.5" max="2.0" step="0.1" value="<?= e(number_format($vadSec, 1)) ?>" class="h-2 w-full cursor-pointer rounded-lg bg-surface-container-highest accent-primary" data-vad>
                <span class="font-label-sm text-label-sm text-on-surface-variant">아동의 숨소리, 추임새 오인 방지를 위해 <span data-vad-sec></span>초 이상 이어지는 질문 음성만 Barge-in으로 수용합니다.</span>
                <button type="button" class="self-start font-label-sm text-label-sm text-on-surface-variant hover:underline<?= $vadCustom ? '' : ' hidden' ?>" data-vad-reset>전체 설정으로 되돌리기</button>
                <?= $err('vad_seconds') ?>
              </div>
              <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between gap-2">
                  <span class="font-label-md text-label-md font-medium text-on-surface">AEC (음향 반향 제거) 감도</span>
                  <span class="text-right font-label-sm text-label-sm font-bold text-primary" data-aec-label><?= e($aecTitle[$aecShown] ?? $aecShown) ?><?= $aecValue === '' ? ' · 전체 설정' : '' ?></span>
                </div>
                <input type="hidden" name="aec_level" value="<?= e($aecValue) ?>" data-aec-input data-global="<?= e($global['aec']) ?>">
                <div class="flex gap-2">
                  <?php foreach ($aecLevels as $k => $label): ?>
                  <button type="button" data-aec="<?= e($k) ?>" data-title="<?= e($aecTitle[$k]) ?>" data-help="<?= e($aecHelp[$k]) ?>" class="flex-1 rounded-lg py-2 font-label-sm text-label-sm transition-colors <?= $aecShown === $k ? 'bg-primary font-semibold text-on-primary shadow-sm' : 'bg-surface-container text-on-surface-variant hover:bg-surface-container-high' ?>"><?= e($label) ?><?= $k === 'strong' ? ' (권장)' : '' ?></button>
                  <?php endforeach; ?>
                </div>
                <span class="font-label-sm text-label-sm text-on-surface-variant" data-aec-help><?= e($aecHelp[$aecShown] ?? '') ?></span>
                <?= $err('aec_level') ?>
              </div>
            </div>
          </section>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($story): ?>
<form id="story-delete-form" method="post" action="<?= e(url('/admin/stories/' . (int) $story['id'] . '/delete')) ?>" data-confirm="「<?= e($story['title']) ?>」을(를) 삭제할까요? 회원 화면에서 바로 사라집니다." class="hidden"><?= csrf_field() ?></form>
<form id="timecode-form" method="post" action="<?= e(url('/admin/stories/' . (int) $story['id'] . '/timecodes')) ?>" class="hidden" data-confirm="저장된 문장의 시작, 종료 시각을 기준 오디오 값으로 덮어씁니다. 계속할까요?"><?= csrf_field() ?></form>
<?php endif; ?>

<template id="sentence-row-template"><?= partial('admin/stories/_row', ['i' => '__i__', 'r' => ['content' => '', 'keywords' => '', 'start' => '', 'end' => ''], 'errs' => []]) ?></template>
<template id="fallback-template"><?= partial('admin/stories/_fallback', ['n' => '__n__', 'line' => '']) ?></template>

<!-- 배포 상태 점검 창 -->
<div class="fixed inset-0 z-50 hidden items-center justify-center bg-inverse-surface/40 p-4 backdrop-blur-sm" data-modal="deploy" role="dialog" aria-modal="true" aria-labelledby="deploy-title">
  <div class="flex max-h-[90vh] w-full max-w-4xl flex-col gap-5 overflow-hidden rounded-2xl bg-surface-container-lowest p-6 shadow-2xl sm:p-8">
    <div class="flex items-start justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-fixed text-primary"><span class="material-symbols-outlined text-[28px]">cloud_sync</span></div>
        <div>
          <h3 id="deploy-title" class="font-headline-md text-headline-md font-bold text-on-surface">배포 상태 점검</h3>
          <p class="font-label-md text-label-md text-on-surface-variant">목소리별 동화 오디오가 현재 본문(내용 해시)으로 만들어졌는지 확인합니다.</p>
        </div>
      </div>
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high" data-close-modal aria-label="닫기"><span class="material-symbols-outlined text-[20px]">close</span></button>
    </div>
    <div class="min-h-[200px] overflow-y-auto" data-deploy-body>
      <p class="py-12 text-center font-label-md text-label-md text-on-surface-variant">불러오는 중…</p>
    </div>
  </div>
</div>

<!-- 전체 청취 시뮬레이션 창 -->
<div class="fixed inset-0 z-50 hidden items-center justify-center bg-inverse-surface/40 p-4 backdrop-blur-sm" data-modal="simulate" role="dialog" aria-modal="true" aria-labelledby="sim-title">
  <div class="flex max-h-[90vh] w-full max-w-2xl flex-col gap-5 overflow-hidden rounded-2xl bg-surface-container-lowest p-6 shadow-2xl sm:p-8">
    <div class="flex items-start justify-between gap-4">
      <div class="flex items-center gap-3">
        <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-secondary-fixed text-secondary"><span class="material-symbols-outlined text-[28px]">headphones</span></div>
        <div>
          <h3 id="sim-title" class="font-headline-md text-headline-md font-bold text-on-surface">전체 청취 시뮬레이션</h3>
          <p class="font-label-md text-label-md text-on-surface-variant"><?= $story ? e($story['title']) : '작성 중인 동화' ?> · 문장 강조와 함께 처음부터 들어 봅니다.</p>
        </div>
      </div>
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-surface-container-low text-on-surface-variant hover:bg-surface-container-high" data-close-modal aria-label="닫기"><span class="material-symbols-outlined text-[20px]">close</span></button>
    </div>
    <div class="flex flex-wrap items-center gap-3">
      <select class="a-input w-auto min-w-[260px] flex-1" data-sim-source aria-label="재생 음성">
        <option value="tts">기기 음성 (편집 중인 문장, 한국어 TTS)</option>
        <?php foreach ($audios as $a): ?>
        <option value="<?= (int) $a['id'] ?>"><?= e($a['label']) ?> 목소리 오디오 (<?= e(fmt_duration($a['duration_ms'])) ?>)</option>
        <?php endforeach; ?>
      </select>
      <button type="button" class="a-btn-primary" data-sim-play><span class="material-symbols-outlined text-[18px]" data-sim-icon>play_arrow</span><span data-sim-play-text>재생</span></button>
      <button type="button" class="a-btn-tonal" data-sim-stop><span class="material-symbols-outlined text-[18px]">stop</span>정지</button>
    </div>
    <div class="flex items-center justify-between font-label-sm text-label-sm text-on-surface-variant">
      <span data-sim-status>준비됨</span>
      <span class="font-mono" data-sim-time>00:00</span>
    </div>
    <audio preload="none" data-sim-audio></audio>
    <div class="flex flex-col gap-1 overflow-y-auto rounded-xl bg-surface-container-low p-3" data-sim-list></div>
  </div>
</div>

<script type="application/json" id="story-data"><?= json_encode([
    'audios' => $audios,
    'saved' => $savedSentences,
    'globalFallback' => $global['fallback_lines'],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/admin-stories.js')) ?>"></script>
<?php endsection(); ?>
