<?php
/** 비밀번호 찾기(재설정 메일 요청). 변수: $sent */
layout('user/layout_auth', ['title' => '비밀번호 찾기']);
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline-variant focus:border-primary focus:ring-0';
?>
<div class="relative isolate break-keep">
  <?= partial('user/auth/backdrop') ?>
  <div class="mx-auto w-full max-w-md space-y-8">
    <header class="space-y-3 text-center">
      <div class="mb-2 inline-flex h-16 w-16 items-center justify-center rounded-3xl bg-secondary-container text-on-secondary-container">
        <span class="material-symbols-outlined icon-fill text-[32px]"><?= $sent ? 'mark_email_read' : 'lock_reset' ?></span>
      </div>
      <h1 class="font-headline-xl-mobile text-headline-xl-mobile tracking-tight text-on-background"><?= $sent ? '메일을 확인해 주세요' : '비밀번호 찾기' ?></h1>
      <p class="mx-auto max-w-[300px] font-body-md text-body-md text-on-surface-variant">
        <?= $sent ? '입력하신 이메일로 가입된 계정이 있다면 비밀번호 재설정 링크를 보내 드렸어요.' : '가입한 이메일을 입력하면 새 비밀번호를 만들 수 있는 링크를 보내 드려요.' ?>
      </p>
    </header>

    <section class="space-y-6 rounded-[32px] border border-surface-container-lowest/50 bg-surface-container-lowest/85 p-md shadow-[0_20px_40px_-15px_rgba(128,80,98,0.1)] backdrop-blur-md">
      <?php if ($sent): ?>
        <ul class="space-y-3 font-body-md text-[15px] text-on-surface-variant">
          <li class="flex gap-3"><span class="material-symbols-outlined text-[20px] text-secondary">schedule</span>링크는 1시간 동안 한 번만 쓸 수 있어요.</li>
          <li class="flex gap-3"><span class="material-symbols-outlined text-[20px] text-secondary">inbox</span>메일이 보이지 않으면 스팸함도 확인해 주세요.</li>
          <li class="flex gap-3"><span class="material-symbols-outlined text-[20px] text-secondary">chat</span>간편 로그인으로 가입하셨다면 카카오, 구글 버튼으로 로그인할 수 있어요.</li>
        </ul>
        <div class="grid gap-3">
          <a href="<?= e(url('/login')) ?>" class="flex min-h-12 w-full items-center justify-center rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all active:translate-y-0.5">로그인으로 돌아가기</a>
          <a href="<?= e(url('/password/forgot')) ?>" class="btn-ghost w-full">다른 이메일로 다시 보내기</a>
        </div>
      <?php else: ?>
        <form method="post" action="<?= e(url('/password/forgot')) ?>" class="space-y-4" novalidate>
          <?= csrf_field() ?>
          <div class="space-y-1.5">
            <label for="email" class="ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant">이메일</label>
            <input id="email" name="email" type="email" autocomplete="email" inputmode="email" maxlength="191" required autofocus value="<?= e(old('email')) ?>" placeholder="example@email.com" class="<?= $input ?> <?= errors('email') ? 'border-error' : 'border-surface-variant' ?>">
            <?php if (errors('email')): ?><p class="field-error ml-2" role="alert"><?= e(errors('email')) ?></p><?php endif; ?>
          </div>
          <div class="pt-2">
            <button type="submit" class="min-h-12 w-full rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all hover:brightness-110 active:translate-y-0.5 active:scale-[0.98] active:shadow-[0_1px_0_0_rgba(0,0,0,0.1)]">재설정 링크 받기</button>
          </div>
        </form>
      <?php endif; ?>
    </section>

    <footer class="pb-8 text-center">
      <p class="font-body-md text-body-md text-on-surface-variant">
        비밀번호가 기억나셨나요?
        <a href="<?= e(url('/login')) ?>" class="ml-1 font-bold text-primary decoration-2 underline-offset-4 hover:underline">로그인하기</a>
      </p>
    </footer>
  </div>
</div>
