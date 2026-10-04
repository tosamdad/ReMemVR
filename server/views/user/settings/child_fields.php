<?php
/**
 * 아이 프로필 입력 항목(첫 자녀 등록, 아이 추가, 수정 공통). 변수: $values (ChildrenController::formValues)
 * 생년월일과 만 나이 중 하나로 입력한다(settings.js 가 입력 방식을 바꾼다).
 */
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline-variant focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$seg = 'flex min-h-11 cursor-pointer items-center justify-center rounded-xl border-2 border-surface-variant bg-surface-container-lowest px-2 text-[14px] font-semibold text-on-surface-variant transition-all peer-checked:border-primary peer-checked:bg-primary-fixed peer-checked:text-on-primary-fixed-variant peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40';
$mode = $values['birth_mode'] === 'age' ? 'age' : 'date';
$genders = ['boy' => '남아', 'girl' => '여아', '' => '선택 안 함'];
?>
<div class="space-y-1.5">
  <label for="child-name" class="<?= $label ?>">아이 이름 또는 애칭</label>
  <input id="child-name" name="name" type="text" maxlength="20" required value="<?= e($values['name']) ?>" placeholder="예) 하늘이" class="<?= $input ?> <?= errors('name') ? 'border-error' : 'border-surface-variant' ?>">
  <?php if (errors('name')): ?><p class="field-error ml-2" role="alert"><?= e(errors('name')) ?></p><?php else: ?><p class="ml-2 font-label-sm text-label-sm text-on-surface-variant">동화 속 대답에서 이 이름으로 불러 줄 수 있어요.</p><?php endif; ?>
</div>

<fieldset class="space-y-2">
  <legend class="<?= $label ?> mb-1.5">나이</legend>
  <div class="grid grid-cols-2 gap-2" role="radiogroup">
    <label class="block">
      <input type="radio" name="birth_mode" value="date" data-birth-mode class="peer sr-only"<?= $mode === 'date' ? ' checked' : '' ?>>
      <span class="<?= $seg ?>">생년월일로 입력</span>
    </label>
    <label class="block">
      <input type="radio" name="birth_mode" value="age" data-birth-mode class="peer sr-only"<?= $mode === 'age' ? ' checked' : '' ?>>
      <span class="<?= $seg ?>">만 나이로 입력</span>
    </label>
  </div>
  <div data-birth-panel="date"<?= $mode === 'date' ? '' : ' hidden' ?>>
    <input name="birth_date" type="date" max="<?= e(date('Y-m-d')) ?>" min="<?= e(date('Y-m-d', strtotime('-14 years'))) ?>" value="<?= e($values['birth_date']) ?>" aria-label="생년월일" class="<?= $input ?> <?= errors('birth') ? 'border-error' : 'border-surface-variant' ?>">
  </div>
  <div data-birth-panel="age"<?= $mode === 'age' ? '' : ' hidden' ?>>
    <select name="age" aria-label="만 나이" class="<?= $input ?> <?= errors('birth') ? 'border-error' : 'border-surface-variant' ?>">
      <option value="">만 나이를 골라 주세요</option>
      <?php for ($a = 0; $a <= App\Controllers\User\ChildrenController::MAX_AGE; $a++): ?>
        <option value="<?= $a ?>"<?= $values['age'] !== '' && (int) $values['age'] === $a ? ' selected' : '' ?>>만 <?= $a ?>세</option>
      <?php endfor; ?>
    </select>
  </div>
  <?php if (errors('birth')): ?><p class="field-error ml-2" role="alert"><?= e(errors('birth')) ?></p><?php else: ?><p class="ml-2 font-label-sm text-label-sm text-on-surface-variant">나이에 맞는 말투로 질문에 대답해 줘요.</p><?php endif; ?>
</fieldset>

<fieldset class="space-y-2">
  <legend class="<?= $label ?> mb-1.5">성별</legend>
  <div class="grid grid-cols-3 gap-2">
    <?php foreach ($genders as $g => $gl): ?>
    <label class="block">
      <input type="radio" name="gender" value="<?= e($g) ?>" class="peer sr-only"<?= $values['gender'] === $g ? ' checked' : '' ?>>
      <span class="<?= $seg ?>"><?= e($gl) ?></span>
    </label>
    <?php endforeach; ?>
  </div>
  <?php if (errors('gender')): ?><p class="field-error ml-2" role="alert"><?= e(errors('gender')) ?></p><?php endif; ?>
</fieldset>

<fieldset class="space-y-2">
  <legend class="<?= $label ?> mb-1.5">아바타</legend>
  <div class="grid grid-cols-6 gap-2 rounded-2xl bg-surface-container-low p-3">
    <?php foreach (avatar_presets() as $key => $p): ?>
    <label class="flex cursor-pointer items-center justify-center" title="<?= e($key) ?>">
      <input type="radio" name="avatar" value="<?= e($key) ?>" class="peer sr-only"<?= $values['avatar'] === $key ? ' checked' : '' ?>>
      <?= child_avatar(['avatar' => $key], 'w-11 h-11 text-2xl', 'transition-all ring-offset-2 ring-offset-surface-container-low peer-checked:ring-[3px] peer-checked:ring-primary peer-checked:scale-110 peer-focus-visible:ring-2 peer-focus-visible:ring-primary/50') ?>
    </label>
    <?php endforeach; ?>
  </div>
</fieldset>
