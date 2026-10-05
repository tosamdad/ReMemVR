<?php
/**
 * 새 목소리 만들기: 누구 목소리인지(칩, 직접 입력)와 아이콘을 고른다. 변수: $presets, $icons, $max, $used, $canCreate, $labelMax
 * 제출하면 초안(draft) 목소리가 만들어지고 녹음 화면으로 간다.
 */
layout('user/layout', ['title' => '새 목소리 만들기', 'nav' => 'voice', 'header' => 'sub', 'back' => '/voice-lab', 'headerTitle' => '새 목소리 만들기']);
$oldWho = (string) old('who', '엄마');
$oldCustom = (string) old('custom_label', '');
$oldIcon = (string) old('icon', '');
if (!in_array($oldIcon, $icons, true)) {
    $oldIcon = isset($presets[$oldWho]) ? $presets[$oldWho] : 'face_5';
}
$previewLabel = $oldWho === 'custom' ? ($oldCustom !== '' ? $oldCustom : '우리 가족') : $oldWho;
$chip = 'inline-flex cursor-pointer select-none items-center gap-1.5 rounded-full border-2 border-surface-variant bg-surface-container-lowest px-4 py-2.5 font-label-lg text-label-lg text-on-surface-variant transition-all active:scale-95 peer-checked:border-primary peer-checked:bg-primary-container peer-checked:text-on-primary-container peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40';
?>
<div class="space-y-6" data-vl-new>
  <!-- 단계 안내 -->
  <ol class="flex items-center gap-2 text-label-sm font-label-sm" aria-label="진행 단계">
    <li class="flex items-center gap-1.5 rounded-full bg-primary px-3 py-1.5 text-on-primary"><span class="material-symbols-outlined text-[16px]">person</span>누구 목소리</li>
    <li class="h-px flex-1 bg-outline-variant" aria-hidden="true"></li>
    <li class="flex items-center gap-1.5 rounded-full bg-surface-container px-3 py-1.5 text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">mic</span>녹음</li>
    <li class="h-px flex-1 bg-outline-variant" aria-hidden="true"></li>
    <li class="flex items-center gap-1.5 rounded-full bg-surface-container px-3 py-1.5 text-on-surface-variant"><span class="material-symbols-outlined text-[16px]">task_alt</span>완료</li>
  </ol>

  <!-- 미리 보기 카드 -->
  <section class="relative flex flex-col items-center overflow-hidden rounded-3xl bg-primary-container/20 px-6 py-8 text-center">
    <div class="absolute -left-10 -top-10 h-40 w-40 rounded-full bg-secondary-container/30 blur-3xl" aria-hidden="true"></div>
    <div class="relative mb-4 flex h-24 w-24 items-center justify-center rounded-[28px] bg-surface-container-lowest text-primary shadow-soft">
      <span class="material-symbols-outlined text-[52px]" data-preview-icon><?= e($oldIcon) ?></span>
    </div>
    <p class="relative font-headline-md text-headline-md text-primary"><span data-preview-label><?= e($previewLabel) ?></span> 목소리</p>
    <p class="relative mt-1 text-[14px] leading-5 text-on-surface-variant">아이가 동화를 고를 때 이 이름과 아이콘이 보여요.</p>
  </section>

  <?php if (!$canCreate): ?>
    <div class="flex items-start gap-3 rounded-2xl bg-error-container px-4 py-3 text-on-error-container">
      <span class="material-symbols-outlined">block</span>
      <p class="text-[14px] font-semibold leading-5">목소리는 최대 <?= (int) $max ?>개까지 만들 수 있어요. 지금 <?= (int) $used ?>개를 쓰고 있어요. 쓰지 않는 목소리를 삭제한 뒤 다시 만들어 주세요.</p>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= e(url('/voice-lab')) ?>" class="space-y-8" novalidate>
    <?= csrf_field() ?>
    <!-- 누구 목소리 -->
    <fieldset class="space-y-3">
      <legend class="mb-3 font-headline-md text-[20px] font-bold leading-7 text-on-surface">누구의 목소리인가요?</legend>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($presets as $name => $icon): ?>
          <label class="relative">
            <input type="radio" name="who" value="<?= e($name) ?>" class="peer sr-only" data-who data-icon="<?= e($icon) ?>"<?= $oldWho === $name ? ' checked' : '' ?>>
            <span class="<?= $chip ?>"><?= e($name) ?></span>
          </label>
        <?php endforeach; ?>
        <label class="relative">
          <input type="radio" name="who" value="custom" class="peer sr-only" data-who data-icon="face_5"<?= $oldWho === 'custom' ? ' checked' : '' ?>>
          <span class="<?= $chip ?>"><span class="material-symbols-outlined text-[18px]">edit</span>직접 입력</span>
        </label>
      </div>
      <?php if (errors('who')): ?><p class="field-error"><?= e(errors('who')) ?></p><?php endif; ?>
      <div class="pt-1<?= $oldWho === 'custom' ? '' : ' hidden' ?>" data-custom-wrap>
        <label for="custom_label" class="field-label">이름</label>
        <div class="relative">
          <input type="text" id="custom_label" name="custom_label" value="<?= e($oldCustom) ?>" maxlength="<?= (int) $labelMax ?>" placeholder="예) 큰엄마, 외할머니, 누나" class="field pr-16" autocomplete="off" data-custom-input>
          <span class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-label-sm text-outline"><span data-custom-count><?= mb_strlen($oldCustom) ?></span>/<?= (int) $labelMax ?></span>
        </div>
        <?php if (errors('custom_label')): ?><p class="field-error"><?= e(errors('custom_label')) ?></p><?php endif; ?>
      </div>
    </fieldset>

    <!-- 아이콘 -->
    <fieldset>
      <legend class="mb-3 font-headline-md text-[20px] font-bold leading-7 text-on-surface">아이콘을 골라 주세요</legend>
      <div class="grid grid-cols-4 gap-3">
        <?php foreach ($icons as $icon): ?>
          <label class="relative">
            <input type="radio" name="icon" value="<?= e($icon) ?>" class="peer sr-only" data-icon-pick<?= $oldIcon === $icon ? ' checked' : '' ?>>
            <span class="flex aspect-square cursor-pointer items-center justify-center rounded-2xl border-2 border-surface-variant bg-surface-container-lowest text-on-surface-variant transition-all active:scale-95 peer-checked:border-primary peer-checked:bg-primary-container/40 peer-checked:text-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40">
              <span class="material-symbols-outlined text-[32px]"><?= e($icon) ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="space-y-3 pt-2">
      <button type="submit" class="btn-primary w-full rounded-full py-4"<?= $canCreate ? '' : ' disabled' ?>>
        <span class="material-symbols-outlined">mic</span>다음: 녹음하러 가기
      </button>
      <p class="text-center text-label-sm text-on-surface-variant">대본 3개를 읽으면 돼요. 한 번에 다 하지 않아도 이어서 녹음할 수 있어요.</p>
    </div>
  </form>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/voice-lab.js')) ?>"></script>
<?php endsection(); ?>
