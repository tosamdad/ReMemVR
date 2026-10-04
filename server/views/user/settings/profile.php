<?php
/** 프로필 수정. 변수: $user, $hasPassword, $placeholderEmail, $linked */
layout('user/layout', ['title' => '프로필 수정', 'header' => 'sub', 'back' => '/settings', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline-variant focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$cls = function ($key) use ($input) {
    return $input . ' ' . (errors($key) ? 'border-error' : 'border-surface-variant');
};
$err = function ($key) {
    $m = errors($key);

    return $m ? '<p class="field-error ml-2" role="alert">' . e($m) . '</p>' : '';
};
$emailValue = old('email', $placeholderEmail ? '' : $user['email']);
$providers = ['kakao' => '카카오', 'google' => '구글'];
?>
<p class="mb-6 px-1 font-body-md text-body-md text-on-surface-variant">보호자 정보를 바꿀 수 있어요. 이메일은 로그인과 안내 메일에 쓰여요.</p>

<?php if ($placeholderEmail): ?>
<div class="mb-6 flex gap-3 rounded-2xl bg-tertiary-fixed p-4 text-on-tertiary-fixed-variant">
  <span class="material-symbols-outlined">mail</span>
  <p class="font-body-md text-[15px] leading-6">간편 로그인에서 이메일을 받지 못해 임시 주소를 쓰고 있어요. 목소리 준비 완료 안내를 받으려면 실제 이메일을 입력해 주세요.</p>
</div>
<?php endif; ?>

<form method="post" action="<?= e(url('/settings/profile')) ?>" class="card space-y-5 p-md" novalidate>
  <?= csrf_field() ?>
  <div class="space-y-1.5">
    <label for="name" class="<?= $label ?>">이름</label>
    <input id="name" name="name" type="text" maxlength="30" autocomplete="name" required value="<?= e(old('name', $user['name'])) ?>" class="<?= $cls('name') ?>">
    <?= $err('name') ?>
  </div>
  <div class="space-y-1.5">
    <label for="email" class="<?= $label ?>">이메일</label>
    <input id="email" name="email" type="email" maxlength="191" autocomplete="email" inputmode="email" required value="<?= e($emailValue) ?>" placeholder="example@email.com" class="<?= $cls('email') ?>">
    <?= $err('email') ?>
  </div>
  <?php if ($hasPassword): ?>
  <div class="space-y-1.5">
    <label for="current_password" class="<?= $label ?>">현재 비밀번호 <span class="font-normal text-on-surface-variant">(이메일을 바꿀 때만)</span></label>
    <input id="current_password" name="current_password" type="password" autocomplete="current-password" placeholder="이메일을 바꿀 때 입력해 주세요" class="<?= $cls('current_password') ?>">
    <?= $err('current_password') ?>
  </div>
  <?php endif; ?>
  <div class="space-y-1.5">
    <label for="phone" class="<?= $label ?>">휴대전화 <span class="font-normal text-on-surface-variant">(선택)</span></label>
    <input id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" inputmode="tel" value="<?= e(old('phone', (string) $user['phone'])) ?>" placeholder="010-0000-0000" class="<?= $cls('phone') ?>">
    <?php if (errors('phone')): ?><?= $err('phone') ?><?php else: ?><p class="ml-2 font-label-sm text-label-sm text-on-surface-variant">문의 답변이 늦어질 때만 연락드려요.</p><?php endif; ?>
  </div>
  <button type="submit" class="btn-primary w-full rounded-full">저장하기</button>
</form>

<section class="mt-8">
  <h3 class="mb-3 px-2 text-[14px] font-bold leading-5 text-primary">로그인 방법</h3>
  <div class="overflow-hidden rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest shadow-[0_4px_20px_0_rgba(0,0,0,0.05)]">
    <div class="flex items-center justify-between gap-3 border-b border-surface-variant/30 p-md">
      <span class="flex items-center gap-4"><span class="material-symbols-outlined text-outline">alternate_email</span><span class="font-body-md text-body-md text-on-surface">이메일, 비밀번호</span></span>
      <span class="rounded-full px-2.5 py-0.5 font-label-sm text-label-sm <?= $hasPassword ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant' ?>"><?= $hasPassword ? '사용 중' : '설정 안 함' ?></span>
    </div>
    <?php $i = 0; foreach ($providers as $key => $p): $i++; $on = isset($linked[$key]); ?>
    <div class="flex items-center justify-between gap-3 p-md<?= $i < count($providers) ? ' border-b border-surface-variant/30' : '' ?>">
      <span class="flex items-center gap-4">
        <?php if ($key === 'kakao'): ?>
          <span class="flex h-6 w-6 items-center justify-center rounded-md bg-[#FEE500] text-[#191919]"><span class="material-symbols-outlined icon-fill text-[16px]">chat_bubble</span></span>
        <?php else: ?>
          <span class="flex h-6 w-6 items-center justify-center"><?= partial('user/auth/google_logo', ['class' => 'h-5 w-5']) ?></span>
        <?php endif; ?>
        <span class="font-body-md text-body-md text-on-surface"><?= e($p) ?></span>
      </span>
      <span class="rounded-full px-2.5 py-0.5 font-label-sm text-label-sm <?= $on ? 'bg-secondary-container text-on-secondary-container' : 'bg-surface-container-high text-on-surface-variant' ?>"><?= $on ? '연결됨' : '연결 안 됨' ?></span>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if (!$hasPassword): ?>
  <a href="<?= e(url('/settings/password')) ?>" class="btn-ghost mt-3 w-full"><span class="material-symbols-outlined text-[20px]">key</span>비밀번호 만들고 이메일로도 로그인하기</a>
  <?php endif; ?>
</section>
