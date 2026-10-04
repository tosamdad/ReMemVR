<?php
/** 새 비밀번호 설정(메일 링크). 변수: $token, $valid */
layout('user/layout_auth', ['title' => '새 비밀번호']);
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 pr-12 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline-variant focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
?>
<div class="relative isolate">
  <?= partial('user/auth/backdrop') ?>
  <div class="mx-auto w-full max-w-md space-y-8">
    <header class="space-y-3 text-center">
      <div class="mb-2 inline-flex h-16 w-16 items-center justify-center rounded-3xl <?= $valid ? 'bg-primary-container text-primary' : 'bg-error-container text-on-error-container' ?>">
        <span class="material-symbols-outlined icon-fill text-[32px]"><?= $valid ? 'key' : 'link_off' ?></span>
      </div>
      <h1 class="font-headline-xl-mobile text-headline-xl-mobile tracking-tight text-on-background"><?= $valid ? '새 비밀번호 만들기' : '링크를 쓸 수 없어요' ?></h1>
      <p class="mx-auto max-w-[300px] font-body-md text-body-md text-on-surface-variant">
        <?= $valid ? '앞으로 로그인할 때 쓸 새 비밀번호를 입력해 주세요.' : '링크가 만료되었거나 이미 사용되었어요. 비밀번호 찾기를 다시 요청해 주세요.' ?>
      </p>
    </header>

    <section class="space-y-6 rounded-[32px] border border-surface-container-lowest/50 bg-surface-container-lowest/85 p-md shadow-[0_20px_40px_-15px_rgba(128,80,98,0.1)] backdrop-blur-md">
      <?php if ($valid): ?>
      <form method="post" action="<?= e(url('/password/reset/' . $token)) ?>" class="space-y-4" novalidate>
        <?= csrf_field() ?>
        <div class="space-y-1.5">
          <label for="password" class="<?= $label ?>">새 비밀번호</label>
          <div class="relative">
            <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required placeholder="8자 이상, 영문과 숫자 함께" class="<?= $input ?> <?= errors('password') ? 'border-error' : 'border-surface-variant' ?>">
            <button type="button" data-toggle-password="password" class="absolute right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full text-outline hover:bg-surface-container-low" aria-label="비밀번호 보기">
              <span class="material-symbols-outlined text-[22px]">visibility</span>
            </button>
          </div>
          <?php if (errors('password')): ?><p class="field-error ml-2" role="alert"><?= e(errors('password')) ?></p><?php endif; ?>
        </div>
        <div class="space-y-1.5">
          <label for="password_confirmation" class="<?= $label ?>">새 비밀번호 확인</label>
          <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required placeholder="한 번 더 입력" class="<?= $input ?> <?= errors('password_confirmation') ? 'border-error' : 'border-surface-variant' ?>">
          <?php if (errors('password_confirmation')): ?><p class="field-error ml-2" role="alert"><?= e(errors('password_confirmation')) ?></p><?php endif; ?>
        </div>
        <div class="pt-2">
          <button type="submit" class="min-h-12 w-full rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all hover:brightness-110 active:translate-y-0.5 active:scale-[0.98] active:shadow-[0_1px_0_0_rgba(0,0,0,0.1)]">비밀번호 바꾸고 로그인</button>
        </div>
      </form>
      <?php else: ?>
        <a href="<?= e(url('/password/forgot')) ?>" class="flex min-h-12 w-full items-center justify-center rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all active:translate-y-0.5">비밀번호 찾기 다시 요청</a>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
