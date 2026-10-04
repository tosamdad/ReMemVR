<?php
/** 관리자 로그인. 변수: next, loggedOut */
layout('admin/layout_auth', ['title' => '관리자 로그인']);
$loginErr = errors('login_id');
$pwErr = errors('password');
?>
<div class="w-full max-w-[420px]">
  <div class="a-card flex flex-col gap-7 p-8 sm:p-10">
    <div class="flex flex-col items-center gap-4 text-center">
      <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary text-on-primary shadow-sm">
        <span class="material-symbols-outlined text-[30px]">graphic_eq</span>
      </div>
      <div class="flex flex-col gap-1">
        <span class="font-headline-md text-headline-md tracking-tight text-on-surface">ReMemVR</span>
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary">Admin Backoffice</span>
      </div>
      <p class="font-body-md text-[15px] text-on-surface-variant">운영 관리자 계정으로 로그인하세요.</p>
    </div>

    <?php if (!empty($loggedOut)): ?>
    <div class="flex items-center gap-2 rounded-xl bg-primary-fixed px-4 py-3 text-on-primary-fixed" role="status">
      <span class="material-symbols-outlined text-[20px]">check_circle</span>
      <span class="font-label-md text-label-md">로그아웃했습니다.</span>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/login')) ?>" class="flex flex-col gap-5" novalidate>
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div>
        <label class="a-label" for="login_id">아이디</label>
        <div class="relative">
          <span class="material-symbols-outlined pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant">badge</span>
          <input id="login_id" name="login_id" type="text" class="a-input py-3 pl-11 <?= $loginErr ? 'ring-2 ring-error/40' : '' ?>" value="<?= e(old('login_id')) ?>" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus<?= $loginErr ? ' aria-invalid="true" aria-describedby="login_id-error"' : '' ?>>
        </div>
        <?php if ($loginErr): ?><p id="login_id-error" class="mt-1.5 font-label-sm text-label-sm text-error"><?= e($loginErr) ?></p><?php endif; ?>
      </div>
      <div>
        <label class="a-label" for="password">비밀번호</label>
        <div class="relative">
          <span class="material-symbols-outlined pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant">lock</span>
          <input id="password" name="password" type="password" class="a-input py-3 pl-11 <?= $pwErr ? 'ring-2 ring-error/40' : '' ?>" autocomplete="current-password" required<?= $pwErr ? ' aria-invalid="true" aria-describedby="password-error"' : '' ?>>
        </div>
        <?php if ($pwErr): ?><p id="password-error" class="mt-1.5 font-label-sm text-label-sm text-error"><?= e($pwErr) ?></p><?php endif; ?>
      </div>
      <button type="submit" class="a-btn-primary mt-1 w-full py-3">
        <span class="material-symbols-outlined text-[20px]">login</span>
        <span>로그인</span>
      </button>
    </form>

    <div class="flex items-start gap-2 rounded-xl bg-surface-container-low px-4 py-3">
      <span class="material-symbols-outlined text-[18px] text-on-surface-variant">shield_lock</span>
      <p class="font-label-sm text-label-sm font-semibold leading-relaxed text-on-surface-variant">10분 동안 5번 이상 틀리면 잠시 로그인이 막힙니다. 모든 로그인과 작업은 감사 로그에 기록됩니다.</p>
    </div>
  </div>
  <p class="mt-6 text-center font-label-sm text-label-sm text-on-surface-variant">© <?= date('Y') ?> ReMemVR · 관리자 전용</p>
</div>
