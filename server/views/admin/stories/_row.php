<?php
/**
 * 문장 표 한 행. 변수: i(행 번호, 템플릿에서는 '__i__'), r(content, keywords, start, end), errs(항목별 오류)
 * 이름 규칙 sentences[i][content|keywords|start|end] 은 StoryController::parseSentences 와 맞춘다.
 */
$name = static function ($field) use ($i) {
    return 'sentences[' . $i . '][' . $field . ']';
};
$rowNo = is_int($i) ? sprintf('ST-%02d', $i + 1) : 'ST-00';
$hasErr = !empty($errs['content']) || !empty($errs['keywords']) || !empty($errs['start']) || !empty($errs['end']);
?>
<tr class="<?= $hasErr ? 'bg-error-container/30' : 'bg-surface-container-lowest' ?> transition-colors hover:bg-surface-container-high/30" data-row>
  <td class="px-4 py-3.5 align-top font-label-sm text-label-sm font-bold text-primary"><span class="inline-block pt-1.5" data-row-id><?= e($rowNo) ?></span></td>
  <td class="min-w-[280px] px-3 py-3 align-top">
    <textarea name="<?= e($name('content')) ?>" rows="2" maxlength="1000" class="block w-full resize-none rounded-lg border-0 bg-transparent px-2 py-1 font-body-md text-body-md text-on-surface placeholder:text-outline focus:bg-surface-container-low focus:ring-2 focus:ring-primary/30" placeholder="문장을 입력하세요" aria-label="문장 내용" data-content><?= e($r['content']) ?></textarea>
    <?php if (!empty($errs['content'])): ?><p class="mt-1 px-2 font-label-sm text-label-sm text-error"><?= e($errs['content']) ?></p><?php endif; ?>
  </td>
  <td class="px-3 py-3 align-top">
    <input name="<?= e($name('start')) ?>" value="<?= e($r['start']) ?>" class="mt-1 w-[76px] rounded border-0 bg-surface-container px-2 py-1 text-center font-mono text-label-sm text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary/30" placeholder="--:--" aria-label="시작 시각" autocomplete="off" data-time>
    <?php if (!empty($errs['start'])): ?><p class="mt-1 font-label-sm text-label-sm text-error"><?= e($errs['start']) ?></p><?php endif; ?>
  </td>
  <td class="px-3 py-3 align-top">
    <input name="<?= e($name('end')) ?>" value="<?= e($r['end']) ?>" class="mt-1 w-[76px] rounded border-0 bg-surface-container px-2 py-1 text-center font-mono text-label-sm text-on-surface placeholder:text-outline focus:ring-2 focus:ring-primary/30" placeholder="--:--" aria-label="종료 시각" autocomplete="off" data-time>
    <?php if (!empty($errs['end'])): ?><p class="mt-1 font-label-sm text-label-sm text-error"><?= e($errs['end']) ?></p><?php endif; ?>
  </td>
  <td class="min-w-[170px] px-3 py-3 align-top">
    <div class="flex flex-wrap gap-1.5" data-chips><?php foreach (App\Core\Text::keywords($r['keywords']) as $kw): ?><span class="rounded-full bg-primary-fixed px-2 py-0.5 font-label-sm text-label-sm text-on-primary-fixed-variant"><?= e($kw) ?></span><?php endforeach; ?></div>
    <input name="<?= e($name('keywords')) ?>" value="<?= e($r['keywords']) ?>" maxlength="255" class="mt-1.5 w-full rounded border-0 bg-transparent px-1 py-0.5 font-label-sm text-label-sm text-on-surface-variant placeholder:text-outline focus:bg-surface-container-low focus:ring-1 focus:ring-primary/30" placeholder="키워드, 쉼표로 구분" aria-label="맥락 키워드" autocomplete="off" data-keywords>
    <?php if (!empty($errs['keywords'])): ?><p class="mt-1 font-label-sm text-label-sm text-error"><?= e($errs['keywords']) ?></p><?php endif; ?>
  </td>
  <td class="px-3 py-3 text-right align-top">
    <div class="flex items-center justify-end gap-1">
      <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-primary" title="이 문장 기기 음성으로 듣기" aria-label="이 문장 듣기" data-row-play><span class="material-symbols-outlined text-[18px]">play_arrow</span></button>
      <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-primary" title="아래에 행 추가" aria-label="아래에 행 추가" data-row-insert><span class="material-symbols-outlined text-[18px]">add</span></button>
      <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg text-on-surface-variant hover:bg-surface-container hover:text-error" title="행 삭제" aria-label="행 삭제" data-row-delete><span class="material-symbols-outlined text-[18px]">delete</span></button>
    </div>
  </td>
</tr>
