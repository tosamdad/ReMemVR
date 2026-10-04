<?php
/**
 * 간편 로그인 버튼 묶음(로그인, 회원가입 화면 공통). 변수: $social (AuthController::socialButtons)
 * 키가 없는 제공자는 이동하지 않고 "준비 중" 안내를 띄운다(settings.js 의 data-social-off).
 */
$kakao = $social['kakao'];
$google = $social['google'];
$btn = 'flex min-h-12 items-center justify-center gap-2 rounded-2xl px-4 text-[14px] font-semibold leading-5 tracking-[0.02em] transition-all active:scale-95';
?>
<div class="flex items-center gap-4 py-2">
  <div class="h-px flex-1 bg-outline-variant opacity-50"></div>
  <span class="font-label-sm text-label-sm text-outline">또는 간편 로그인</span>
  <div class="h-px flex-1 bg-outline-variant opacity-50"></div>
</div>
<div class="grid grid-cols-2 gap-4">
  <?php /* 카카오 브랜드 색(#FEE500, #191919)은 카카오 로그인 버튼 가이드에 따른 예외 */ ?>
  <?php if ($kakao['ready']): ?>
  <a href="<?= e($kakao['url']) ?>" class="<?= $btn ?> bg-[#FEE500] text-[#191919]">
  <?php else: ?>
  <button type="button" data-social-off class="<?= $btn ?> bg-[#FEE500] text-[#191919]">
  <?php endif; ?>
    <span class="material-symbols-outlined icon-fill text-[20px]">chat_bubble</span>
    카카오
  <?= $kakao['ready'] ? '</a>' : '</button>' ?>

  <?php if ($google['ready']): ?>
  <a href="<?= e($google['url']) ?>" class="<?= $btn ?> border-2 border-surface-variant bg-surface-container-lowest text-on-surface">
  <?php else: ?>
  <button type="button" data-social-off class="<?= $btn ?> border-2 border-surface-variant bg-surface-container-lowest text-on-surface">
  <?php endif; ?>
    <?php /* 구글 로고 공식 색(브랜드 예외) */ ?>
    <?= partial('user/auth/google_logo') ?>
    구글
  <?= $google['ready'] ? '</a>' : '</button>' ?>
</div>
<p class="text-center font-label-sm text-label-sm leading-5 text-on-surface-variant">
  간편 로그인을 하면 <a href="<?= e(url('/settings/terms')) ?>" target="_blank" rel="noopener" class="font-bold text-primary underline-offset-2 hover:underline">이용약관</a>과
  <a href="<?= e(url('/settings/privacy')) ?>" target="_blank" rel="noopener" class="font-bold text-primary underline-offset-2 hover:underline">개인정보 처리방침</a>에 동의하는 것으로 봐요.
  만 14세 이상 보호자만 가입할 수 있어요.
</p>
