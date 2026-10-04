<?php
/** 비밀번호 변경(간편 로그인만 쓰던 회원은 새로 설정). 변수: $user, $hasPassword */
$title = $hasPassword ? '비밀번호 변경' : '비밀번호 설정';
layout('user/layout', ['title' => $title, 'header' => 'sub', 'back' => '/settings', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 pr-12 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline-variant focus:border-primary focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$field = function ($name, $text, $autocomplete, $placeholder, $help = '') use ($input, $label) {
    $error = errors($name);
    $out = '<div class="space-y-1.5"><label for="' . e($name) . '" class="' . $label . '">' . e($text) . '</label>'
        . '<div class="relative"><input id="' . e($name) . '" name="' . e($name) . '" type="password" autocomplete="' . e($autocomplete) . '" required placeholder="' . e($placeholder) . '" class="' . $input . ' ' . ($error ? 'border-error' : 'border-surface-variant') . '">'
        . '<button type="button" data-toggle-password="' . e($name) . '" class="absolute right-2 top-1/2 flex h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full text-outline hover:bg-surface-container-low" aria-label="비밀번호 보기"><span class="material-symbols-outlined text-[22px]">visibility</span></button></div>';
    if ($error) {
        $out .= '<p class="field-error ml-2" role="alert">' . e($error) . '</p>';
    } elseif ($help !== '') {
        $out .= '<p class="ml-2 font-label-sm text-label-sm text-on-surface-variant">' . e($help) . '</p>';
    }

    return $out . '</div>';
};
?>
<p class="mb-6 px-1 font-body-md text-body-md text-on-surface-variant">
  <?= $hasPassword ? '안전을 위해 현재 비밀번호를 확인한 뒤 바꿔 드려요.' : '간편 로그인으로 가입하셨어요. 비밀번호를 만들면 ' . e($user['email']) . ' 주소로도 로그인할 수 있어요.' ?>
</p>
<form method="post" action="<?= e(url('/settings/password')) ?>" class="card space-y-5 p-md" novalidate>
  <?= csrf_field() ?>
  <?php if ($hasPassword): ?>
    <?= $field('current_password', '현재 비밀번호', 'current-password', '지금 쓰는 비밀번호') ?>
  <?php endif; ?>
  <?= $field('password', '새 비밀번호', 'new-password', '8자 이상, 영문과 숫자 함께', '영문과 숫자를 함께 넣어 8자 이상으로 만들어 주세요.') ?>
  <?= $field('password_confirmation', '새 비밀번호 확인', 'new-password', '한 번 더 입력') ?>
  <button type="submit" class="btn-primary w-full rounded-full"><?= $hasPassword ? '비밀번호 바꾸기' : '비밀번호 만들기' ?></button>
</form>
<?php if ($hasPassword): ?>
<p class="mt-6 text-center font-label-sm text-label-sm text-on-surface-variant">
  현재 비밀번호가 기억나지 않나요? 로그아웃한 뒤 <a href="<?= e(url('/password/forgot')) ?>" class="font-bold text-primary hover:underline">비밀번호 찾기</a>를 이용해 주세요.
</p>
<?php endif; ?>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
