<?php
/** 로그인(회원가입 시안 _3 과 같은 모양). 변수: $next, $social */
layout('user/layout_auth', ['title' => '로그인']);
$brand = setting('app.brand', '르멤버');
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline-variant focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$err = function ($key) {
    $m = errors($key);

    return $m ? '<p class="field-error ml-2" role="alert">' . e($m) . '</p>' : '';
};
$cls = function ($key) use ($input) {
    return $input . ' ' . (errors($key) ? 'border-error' : 'border-surface-variant');
};
?>
<div class="relative isolate break-keep">
  <?= partial('user/auth/backdrop') ?>
  <div class="mx-auto w-full max-w-md space-y-8">
    <header class="space-y-3 text-center">
      <div class="mb-2 inline-flex h-16 w-16 items-center justify-center rounded-3xl bg-primary-container text-primary">
        <span class="material-symbols-outlined icon-fill text-[32px]">auto_stories</span>
      </div>
      <h1 class="font-headline-xl-mobile text-headline-xl-mobile tracking-tight text-on-background">다시 만나서 반가워요</h1>
      <p class="mx-auto max-w-[280px] font-body-md text-body-md text-on-surface-variant"><?= e($brand) ?>에서 오늘의 이야기를 이어 들어요</p>
    </header>

    <section class="space-y-6 rounded-[32px] border border-surface-container-lowest/50 bg-surface-container-lowest/85 p-md shadow-[0_20px_40px_-15px_rgba(128,80,98,0.1)] backdrop-blur-md">
      <form method="post" action="<?= e(url('/login')) ?>" class="space-y-4" novalidate>
        <?= csrf_field() ?>
        <?php if ($next !== ''): ?><input type="hidden" name="next" value="<?= e($next) ?>"><?php endif; ?>
        <div class="space-y-1.5">
          <label for="email" class="<?= $label ?>">이메일</label>
          <input id="email" name="email" type="email" autocomplete="username" inputmode="email" maxlength="191" required autofocus value="<?= e(old('email')) ?>" placeholder="example@email.com" class="<?= $cls('email') ?>">
          <?= $err('email') ?>
        </div>
        <div class="space-y-1.5">
          <label for="password" class="<?= $label ?>">비밀번호</label>
          <div class="relative">
            <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="비밀번호" class="<?= $cls('password') ?> pr-12">
            <button type="button" data-toggle-password="password" class="absolute right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full text-outline hover:bg-surface-container-low" aria-label="비밀번호 보기">
              <span class="material-symbols-outlined text-[22px]">visibility</span>
            </button>
          </div>
          <?= $err('password') ?>
        </div>
        <div class="flex justify-end px-2">
          <a href="<?= e(url('/password/forgot')) ?>" class="font-label-sm text-label-sm font-bold text-primary hover:underline">비밀번호를 잊으셨나요?</a>
        </div>
        <div class="space-y-3 pt-2">
          <button type="submit" class="min-h-12 w-full rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all hover:brightness-110 active:translate-y-0.5 active:scale-[0.98] active:shadow-[0_1px_0_0_rgba(0,0,0,0.1)]">
            로그인
          </button>
          <p class="flex items-center justify-center gap-1 font-label-sm text-label-sm text-on-surface-variant">
            <span class="material-symbols-outlined text-[16px] text-secondary">verified_user</span>이 기기에서 로그인 상태가 30일 동안 유지돼요
          </p>
        </div>
      </form>

      <?= partial('user/auth/social', ['social' => $social]) ?>
    </section>

    <footer class="pb-8 text-center">
      <p class="font-body-md text-body-md text-on-surface-variant">
        아직 계정이 없으신가요?
        <a href="<?= e(url('/signup')) ?>" class="ml-1 font-bold text-primary decoration-2 underline-offset-4 hover:underline">회원가입</a>
      </p>
    </footer>
  </div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
