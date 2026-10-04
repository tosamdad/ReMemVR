<?php
/** 질문 한도 초과 대체 문장 카드. 변수: n(0부터, 템플릿에서는 '__n__'), line */
$label = is_int($n) ? '대체 샘플 ' . ($n + 1) . ' (순차 로테이션)' : '대체 샘플 (순차 로테이션)';
$secs = (int) ceil(mb_strlen($line) / 5.5);
?>
<div class="flex flex-col justify-between gap-4 rounded-xl bg-surface-container-lowest p-4 shadow-sm" data-fb-item>
  <div class="flex flex-col gap-2">
    <div class="flex items-center justify-between">
      <span class="font-label-sm text-label-sm font-bold text-primary" data-fb-title><?= e($label) ?></span>
      <span class="font-mono font-label-sm text-label-sm text-on-surface-variant" data-fb-dur><?= e(sprintf('00:%02d', min(59, $secs))) ?></span>
    </div>
    <textarea name="fallback_lines[]" rows="2" maxlength="200" class="block w-full resize-none rounded-lg border-0 bg-transparent px-0 py-0 font-body-md text-body-md font-medium text-on-surface placeholder:text-outline focus:bg-surface-container-low focus:ring-2 focus:ring-primary/30" placeholder="예) 나머지 이야기는 다 듣고 또 얘기하자!" aria-label="대체 문장" data-fb-line><?= e($line) ?></textarea>
  </div>
  <div class="flex items-center justify-between border-t border-surface-variant/40 pt-3">
    <div class="flex items-center gap-2">
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full bg-secondary-container text-on-secondary-container shadow-sm transition-transform hover:scale-105" aria-label="기기 음성으로 미리듣기" data-fb-play><span class="material-symbols-outlined text-[20px]">play_arrow</span></button>
      <span class="font-label-sm text-label-sm text-on-surface-variant">기기 음성 미리듣기</span>
    </div>
    <button type="button" class="font-label-sm text-label-sm text-error hover:underline" data-fb-remove>삭제</button>
  </div>
</div>
