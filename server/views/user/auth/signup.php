<?php
/** 회원가입(시안 _3). 변수: $social */
layout('user/layout_auth', ['title' => '회원가입']);
$brand = setting('app.brand', '르멤버');
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$box = 'h-5 w-5 shrink-0 rounded-md border-2 border-outline-variant bg-surface-container-lowest text-primary focus:ring-primary/30 focus:ring-offset-0';
$err = function ($key) {
    $m = errors($key);

    return $m ? '<p class="field-error ml-2" role="alert">' . e($m) . '</p>' : '';
};
$cls = function ($key) use ($input) {
    return $input . ' ' . (errors($key) ? 'border-error' : 'border-surface-variant');
};
$agreeOld = function ($key) {
    return old($key) ? ' checked' : '';
};
?>
<div class="relative isolate break-keep">
  <?= partial('user/auth/backdrop') ?>
  <div class="mx-auto w-full max-w-md space-y-8">
    <header class="space-y-3 text-center">
      <div class="mb-2 inline-flex h-16 w-16 items-center justify-center rounded-3xl bg-primary-container text-primary">
        <span class="material-symbols-outlined icon-fill text-[32px]">auto_stories</span>
      </div>
      <h1 class="font-headline-xl-mobile text-headline-xl-mobile tracking-tight text-on-background"><?= e($brand) ?> 시작하기</h1>
      <p class="mx-auto max-w-[280px] font-body-md text-body-md text-on-surface-variant">아이에게 들려주는 세상에서 가장 따뜻한 목소리</p>
    </header>

    <section class="space-y-6 rounded-[32px] border border-surface-container-lowest/50 bg-surface-container-lowest/85 p-md shadow-[0_20px_40px_-15px_rgba(128,80,98,0.1)] backdrop-blur-md">
      <form method="post" action="<?= e(url('/signup')) ?>" class="space-y-4" novalidate>
        <?= csrf_field() ?>
        <div class="space-y-1.5">
          <label for="name" class="<?= $label ?>">이름</label>
          <input id="name" name="name" type="text" autocomplete="name" maxlength="30" required value="<?= e(old('name')) ?>" placeholder="아이의 보호자 이름" class="<?= $cls('name') ?>">
          <?= $err('name') ?>
        </div>
        <div class="space-y-1.5">
          <label for="email" class="<?= $label ?>">이메일</label>
          <input id="email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="191" required value="<?= e(old('email')) ?>" placeholder="example@email.com" class="<?= $cls('email') ?>">
          <?= $err('email') ?>
        </div>
        <div class="space-y-1.5">
          <label for="password" class="<?= $label ?>">비밀번호</label>
          <div class="relative">
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="8자 이상의 비밀번호" class="<?= $cls('password') ?> pr-12" aria-describedby="pw-help">
            <button type="button" data-toggle-password="password" class="absolute right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full text-outline hover:bg-surface-container-low" aria-label="비밀번호 보기">
              <span class="material-symbols-outlined text-[22px]">visibility</span>
            </button>
          </div>
          <?php if (errors('password')): ?>
            <?= $err('password') ?>
          <?php else: ?>
            <p id="pw-help" class="ml-2 font-label-sm text-label-sm text-on-surface-variant">영문과 숫자를 함께 넣어 8자 이상으로 만들어 주세요.</p>
          <?php endif; ?>
        </div>

        <fieldset class="rounded-2xl border-2 <?= errors('agree') ? 'border-error' : 'border-surface-variant' ?> bg-surface-container-low/60 p-4" data-agree-group>
          <legend class="sr-only">약관 동의</legend>
          <label class="flex cursor-pointer items-center gap-3">
            <input type="checkbox" data-agree-all class="<?= $box ?>"<?= old('agree_terms') && old('agree_privacy') && old('agree_age') && old('agree_marketing') ? ' checked' : '' ?>>
            <span class="font-label-lg text-label-lg text-on-surface">전체 동의</span>
          </label>
          <div class="my-3 h-px bg-outline-variant/40"></div>
          <ul class="space-y-3">
            <li class="flex items-center gap-3">
              <label class="flex flex-1 cursor-pointer items-center gap-3">
                <input type="checkbox" name="agree_terms" value="1" data-agree-item required class="<?= $box ?>"<?= $agreeOld('agree_terms') ?>>
                <span class="font-body-md text-[15px] leading-6 text-on-surface">이용약관 <span class="text-primary">(필수)</span></span>
              </label>
              <a href="<?= e(url('/settings/terms')) ?>" target="_blank" rel="noopener" class="flex items-center font-label-sm text-label-sm text-outline hover:text-primary">보기<span class="material-symbols-outlined text-[18px]">chevron_right</span></a>
            </li>
            <li class="flex items-center gap-3">
              <label class="flex flex-1 cursor-pointer items-center gap-3">
                <input type="checkbox" name="agree_privacy" value="1" data-agree-item required class="<?= $box ?>"<?= $agreeOld('agree_privacy') ?>>
                <span class="font-body-md text-[15px] leading-6 text-on-surface">개인정보 수집·이용 <span class="text-primary">(필수)</span></span>
              </label>
              <a href="<?= e(url('/settings/privacy')) ?>" target="_blank" rel="noopener" class="flex items-center font-label-sm text-label-sm text-outline hover:text-primary">보기<span class="material-symbols-outlined text-[18px]">chevron_right</span></a>
            </li>
            <li>
              <label class="flex cursor-pointer items-center gap-3">
                <input type="checkbox" name="agree_age" value="1" data-agree-item required class="<?= $box ?>"<?= $agreeOld('agree_age') ?>>
                <span class="font-body-md text-[15px] leading-6 text-on-surface">만 14세 이상 보호자입니다 <span class="text-primary">(필수)</span></span>
              </label>
            </li>
            <li>
              <label class="flex cursor-pointer items-center gap-3">
                <input type="checkbox" name="agree_marketing" value="1" data-agree-item class="<?= $box ?>"<?= $agreeOld('agree_marketing') ?>>
                <span class="font-body-md text-[15px] leading-6 text-on-surface">마케팅 정보 수신 <span class="text-on-surface-variant">(선택)</span></span>
              </label>
            </li>
          </ul>
        </fieldset>
        <?= $err('agree') ?>

        <div class="pt-2">
          <button type="submit" class="min-h-12 w-full rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all hover:brightness-110 active:translate-y-0.5 active:scale-[0.98] active:shadow-[0_1px_0_0_rgba(0,0,0,0.1)]">
            회원가입 완료
          </button>
        </div>
      </form>

      <?= partial('user/auth/social', ['social' => $social]) ?>
    </section>

    <footer class="pb-8 text-center">
      <p class="font-body-md text-body-md text-on-surface-variant">
        이미 계정이 있으신가요?
        <a href="<?= e(url('/login')) ?>" class="ml-1 font-bold text-primary decoration-2 underline-offset-4 hover:underline">로그인하기</a>
      </p>
    </footer>
  </div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
