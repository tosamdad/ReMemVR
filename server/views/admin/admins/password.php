<?php
/**
 * 내 비밀번호 변경. 변수: me
 */
use App\Controllers\Admin\AdminAccountController;

layout('admin/layout', ['title' => '내 비밀번호 변경', 'active' => 'admins']);
$err = static function (string $key) {
    $m = errors($key);

    return $m ? '<p class="mt-1 font-label-sm text-label-sm text-error" role="alert">' . e($m) . '</p>' : '';
};
$ring = static function (string $key) {
    return errors($key) ? ' ring-2 ring-error' : '';
};
?>
<div class="flex w-full flex-col gap-6">
  <a href="<?= e(url('/admin/admins')) ?>" class="flex items-center gap-1 self-start font-label-md text-label-md text-on-surface-variant hover:text-primary"><span class="material-symbols-outlined text-[20px]">arrow_back</span>관리자 계정</a>
  <div class="grid grid-cols-12 items-start gap-6">
    <section class="col-span-12 flex flex-col gap-5 rounded-xl bg-surface-container-lowest p-6 shadow-md lg:col-span-7 xl:col-span-5">
      <div class="flex items-center gap-3">
        <span class="flex h-11 w-11 items-center justify-center rounded-full bg-primary-fixed text-primary"><span class="material-symbols-outlined">lock_reset</span></span>
        <div>
          <h1 class="font-headline-md text-headline-md text-on-surface">내 비밀번호 변경</h1>
          <p class="font-label-sm text-label-sm text-on-surface-variant"><?= e($me['name']) ?> · <?= e($me['login_id']) ?></p>
        </div>
      </div>
      <form method="post" action="<?= e(url('/admin/account/password')) ?>" class="flex flex-col gap-4" novalidate>
        <?= csrf_field() ?>
        <input type="text" name="username" value="<?= e($me['login_id']) ?>" autocomplete="username" class="hidden" tabindex="-1" aria-hidden="true" readonly>
        <div>
          <label class="a-label" for="pw-current">지금 비밀번호</label>
          <input id="pw-current" name="current_password" type="password" autocomplete="current-password" required class="a-input<?= $ring('current_password') ?>">
          <?= $err('current_password') ?>
        </div>
        <div>
          <label class="a-label" for="pw-new">새 비밀번호</label>
          <input id="pw-new" name="password" type="password" autocomplete="new-password" minlength="<?= AdminAccountController::PASSWORD_MIN ?>" required class="a-input<?= $ring('password') ?>">
          <?= $err('password') ?>
        </div>
        <div>
          <label class="a-label" for="pw-new2">새 비밀번호 확인</label>
          <input id="pw-new2" name="password_confirmation" type="password" autocomplete="new-password" required class="a-input<?= $ring('password_confirmation') ?>">
          <?= $err('password_confirmation') ?>
        </div>
        <p class="font-label-sm text-label-sm text-on-surface-variant"><?= AdminAccountController::PASSWORD_MIN ?>자 이상으로 정해 주세요. 다른 서비스와 같은 비밀번호는 피하세요.</p>
        <button type="submit" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">save</span>비밀번호 바꾸기</button>
      </form>
    </section>
    <aside class="col-span-12 flex flex-col gap-3 rounded-xl bg-surface-container-low p-6 lg:col-span-5 xl:col-span-4">
      <h2 class="font-label-md text-label-md font-bold text-on-surface">안내</h2>
      <ul class="list-disc space-y-1.5 pl-5 font-label-sm text-label-sm text-on-surface-variant">
        <li>비밀번호를 잊었다면 최고 관리자에게 초기화를 요청하세요.</li>
        <li>바꾼 기록은 감사 로그에 남습니다(비밀번호 자체는 남지 않습니다).</li>
        <li>15분 안에 10번 넘게 틀리면 잠시 바꿀 수 없습니다.</li>
      </ul>
    </aside>
  </div>
</div>
