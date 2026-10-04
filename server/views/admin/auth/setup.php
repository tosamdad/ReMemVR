<?php
/** 최초 설정(관리자 계정이 하나도 없을 때만). 변수: tokenConfigured */
layout('admin/layout_auth', ['title' => '최초 설정']);
$field = static function (string $name, string $label, string $type, string $icon, string $auto, string $help = '', bool $keepOld = true) {
    $err = errors($name);
    $value = $keepOld ? old($name) : '';
    $html = '<div><label class="a-label" for="' . e($name) . '">' . e($label) . '</label>'
        . '<div class="relative"><span class="material-symbols-outlined pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-[20px] text-on-surface-variant">' . e($icon) . '</span>'
        . '<input id="' . e($name) . '" name="' . e($name) . '" type="' . e($type) . '" class="a-input py-3 pl-11' . ($err ? ' ring-2 ring-error/40' : '') . '"'
        . ' value="' . e($value) . '" autocomplete="' . e($auto) . '" required' . ($err ? ' aria-invalid="true" aria-describedby="' . e($name) . '-error"' : '') . '></div>';
    if ($err) {
        $html .= '<p id="' . e($name) . '-error" class="mt-1.5 font-label-sm text-label-sm text-error">' . e($err) . '</p>';
    } elseif ($help !== '') {
        $html .= '<p class="mt-1.5 font-label-sm text-label-sm font-semibold text-on-surface-variant">' . e($help) . '</p>';
    }

    return $html . '</div>';
};
?>
<div class="w-full max-w-[520px]">
  <div class="a-card flex flex-col gap-7 p-8 sm:p-10">
    <div class="flex items-center gap-4">
      <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-2xl bg-primary text-on-primary shadow-sm">
        <span class="material-symbols-outlined text-[30px]">graphic_eq</span>
      </div>
      <div class="flex flex-col gap-0.5">
        <span class="font-label-sm text-label-sm uppercase tracking-wider text-primary">ReMemVR Admin Backoffice</span>
        <h1 class="font-headline-md text-headline-md text-on-surface">최초 관리자 설정</h1>
        <p class="font-body-md text-[15px] text-on-surface-variant">처음 한 번만 열리는 화면입니다. 최고 관리자 계정을 만듭니다.</p>
      </div>
    </div>

    <div class="flex items-start gap-3 rounded-xl bg-secondary-fixed px-4 py-3.5 text-on-secondary-fixed">
      <span class="material-symbols-outlined text-[20px]">key</span>
      <div class="flex flex-col gap-1">
        <span class="font-label-md text-label-md">OPS_TOKEN 이 필요합니다</span>
        <p class="font-label-sm text-label-sm font-semibold leading-relaxed">GitHub 저장소의 Settings → Secrets and variables → Actions 에 등록한 <strong>OPS_TOKEN</strong> 값을 그대로 붙여 넣으세요. 배포할 때 서버 설정에 들어가는 값과 같습니다. 이 값을 아는 사람만 최초 관리자를 만들 수 있습니다.</p>
      </div>
    </div>
    <?php if (empty($tokenConfigured)): ?>
    <div class="flex items-start gap-3 rounded-xl bg-error-container px-4 py-3.5 text-on-error-container" role="alert">
      <span class="material-symbols-outlined text-[20px]">error</span>
      <p class="font-label-sm text-label-sm font-semibold leading-relaxed">서버 설정에 OPS_TOKEN(32자 이상)이 없어 지금은 설정할 수 없습니다. 배포 설정을 먼저 확인하세요.</p>
    </div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('/admin/setup')) ?>" class="flex flex-col gap-5" novalidate>
      <?= csrf_field() ?>
      <?= $field('ops_token', 'OPS_TOKEN', 'password', 'vpn_key', 'off', 'GitHub Secret 에 저장한 운영 토큰', false) ?>
      <div class="h-px w-full bg-surface-variant"></div>
      <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <?= $field('login_id', '아이디', 'text', 'badge', 'username', '영문, 숫자, . _ - 로 4자 이상') ?>
        <?= $field('name', '이름', 'text', 'person', 'name', '화면 상단과 감사 로그에 표시') ?>
      </div>
      <?= $field('email', '이메일', 'email', 'mail', 'email', '운영 알림과 계정 복구에 사용') ?>
      <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
        <?= $field('password', '비밀번호', 'password', 'lock', 'new-password', '10자 이상', false) ?>
        <?= $field('password_confirmation', '비밀번호 확인', 'password', 'lock_reset', 'new-password', '한 번 더 입력', false) ?>
      </div>
      <button type="submit" class="a-btn-primary mt-1 w-full py-3"<?= empty($tokenConfigured) ? ' disabled' : '' ?>>
        <span class="material-symbols-outlined text-[20px]">admin_panel_settings</span>
        <span>최고 관리자 만들고 시작하기</span>
      </button>
    </form>
  </div>
  <p class="mt-6 text-center font-label-sm text-label-sm text-on-surface-variant">10분 동안 5번까지 시도할 수 있습니다.</p>
</div>
