<?php
/**
 * 관리자 계정 목록과 만들기. 변수: rows, me, isSuper, temp(방금 초기화한 임시 비밀번호, 한 번만)
 */
use App\Controllers\Admin\AdminAccountController;

layout('admin/layout', ['title' => '관리자 계정', 'active' => 'admins']);
$err = static function (string $key) {
    $m = errors($key);

    return $m ? '<p class="mt-1 font-label-sm text-label-sm text-error" role="alert">' . e($m) . '</p>' : '';
};
$ring = static function (string $key) {
    return errors($key) ? ' ring-2 ring-error' : '';
};
$active = 0;
foreach ($rows as $r) {
    $active += $r['status'] === 'active' ? 1 : 0;
}
$oldRole = (string) old('role', 'admin');
?>
<div class="flex w-full flex-col gap-6">
  <div class="flex flex-col justify-between gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm lg:flex-row lg:items-center">
    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-3">
        <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Access Control</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant">사용 중 <?= (int) $active ?>명 · 전체 <?= count($rows) ?>명</span>
      </div>
      <h1 class="font-headline-md text-headline-md text-on-surface">관리자 계정</h1>
    </div>
    <div class="flex flex-wrap gap-3">
      <a href="<?= e(url('/admin/audit')) ?>" class="a-btn-tonal"><span class="material-symbols-outlined text-[18px]">policy</span>감사 로그</a>
      <a href="<?= e(url('/admin/account/password')) ?>" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">lock_reset</span>내 비밀번호 변경</a>
    </div>
  </div>

  <?php if ($temp): ?>
  <div class="flex flex-col gap-3 rounded-xl border-2 border-secondary-container bg-secondary-fixed p-5 md:flex-row md:items-center md:justify-between" role="status">
    <div>
      <p class="font-label-md text-label-md font-bold text-on-secondary-fixed"><?= e($temp['name']) ?>(<?= e($temp['login_id']) ?>)의 비밀번호를 초기화했습니다.</p>
      <p class="font-label-sm text-label-sm text-on-secondary-fixed">이 임시 비밀번호는 지금 한 번만 보입니다. 본인에게 전달하고, 로그인 뒤 바로 바꾸도록 안내해 주세요.</p>
    </div>
    <code class="select-all rounded-lg bg-surface-container-lowest px-4 py-2 font-mono text-lg tracking-wider text-on-surface"><?= e($temp['password']) ?></code>
  </div>
  <?php endif; ?>

  <div class="grid grid-cols-12 items-start gap-6">
    <section class="col-span-12 flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-md <?= $isSuper ? 'xl:col-span-8' : '' ?>">
      <h2 class="font-headline-md text-headline-md text-on-surface">계정 목록</h2>
      <div class="overflow-x-auto">
        <table class="w-full border-collapse text-left">
          <thead>
            <tr class="bg-surface-container-low font-label-md text-label-md text-on-surface-variant">
              <th class="whitespace-nowrap rounded-l-lg px-4 py-3">관리자</th>
              <th class="whitespace-nowrap px-4 py-3">권한</th>
              <th class="whitespace-nowrap px-4 py-3">상태</th>
              <th class="whitespace-nowrap px-4 py-3">마지막 로그인</th>
              <th class="whitespace-nowrap px-4 py-3">최근 30일 작업</th>
              <th class="whitespace-nowrap rounded-r-lg px-4 py-3 text-right"><?= $isSuper ? '관리' : '' ?></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-surface-container-high font-body-md text-body-md text-on-surface">
            <?php foreach ($rows as $r):
                $self = (int) $r['id'] === (int) $me['id'];
                $on = $r['status'] === 'active';
            ?>
            <tr class="transition-colors hover:bg-surface-container-low/70<?= $on ? '' : ' opacity-60' ?>">
              <td class="px-4 py-4">
                <div class="flex items-center gap-3">
                  <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full <?= $r['role'] === 'super' ? 'bg-primary text-on-primary' : 'bg-primary-fixed text-primary' ?>"><span class="material-symbols-outlined text-[18px]"><?= $r['role'] === 'super' ? 'shield_person' : 'person' ?></span></span>
                  <div class="min-w-0">
                    <p class="flex items-center gap-1.5 font-semibold"><?= e($r['name']) ?><?php if ($self): ?><span class="a-chip bg-primary-fixed text-primary">나</span><?php endif; ?></p>
                    <p class="font-label-sm text-label-sm text-on-surface-variant"><?= e($r['login_id']) ?><?= $r['email'] ? ' · ' . e($r['email']) : '' ?></p>
                  </div>
                </div>
              </td>
              <td class="whitespace-nowrap px-4 py-4"><span class="a-chip <?= $r['role'] === 'super' ? 'bg-primary-container text-on-primary-container' : 'bg-surface-container-high text-on-surface' ?>"><?= e(AdminAccountController::ROLES[$r['role']] ?? $r['role']) ?></span></td>
              <td class="whitespace-nowrap px-4 py-4"><span class="a-chip <?= $on ? 'bg-emerald-100 text-emerald-800' : 'bg-error-container text-on-error-container' ?>"><?= $on ? '사용 중' : '사용 중지' ?></span></td>
              <td class="whitespace-nowrap px-4 py-4 text-on-surface-variant"><?= $r['last_login_at'] ? '<span title="' . e($r['last_login_at']) . '">' . e(time_ago((string) $r['last_login_at'])) . '</span>' : '로그인 기록 없음' ?></td>
              <td class="whitespace-nowrap px-4 py-4"><a href="<?= e(url('/admin/audit', ['admin' => (int) $r['id']])) ?>" class="font-label-md text-label-md text-primary hover:underline"><?= e(fmt_number((int) $r['actions_30d'])) ?>건</a></td>
              <td class="whitespace-nowrap px-4 py-4">
                <?php if ($isSuper && !$self): ?>
                <div class="flex items-center justify-end gap-1">
                  <form method="post" action="<?= e(url('/admin/admins/' . (int) $r['id'] . '/password')) ?>" data-confirm="<?= e($r['name']) ?>의 비밀번호를 임시 비밀번호로 바꿀까요? 지금 비밀번호는 더 쓸 수 없습니다.">
                    <?= csrf_field() ?>
                    <button type="submit" class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 font-label-sm text-label-sm text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-primary"><span class="material-symbols-outlined text-[18px]">key</span>비밀번호 초기화</button>
                  </form>
                  <form method="post" action="<?= e(url('/admin/admins/' . (int) $r['id'] . '/status')) ?>"<?= $on ? ' data-confirm="' . e($r['name']) . ' 계정을 사용 중지할까요? 바로 로그아웃되고 다시 로그인할 수 없습니다."' : '' ?>>
                    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $on ? 'disable' : 'enable' ?>">
                    <button type="submit" class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 font-label-sm text-label-sm transition-colors <?= $on ? 'text-error hover:bg-error-container' : 'text-primary hover:bg-primary-fixed' ?>"><span class="material-symbols-outlined text-[18px]"><?= $on ? 'block' : 'check_circle' ?></span><?= $on ? '사용 중지' : '다시 사용' ?></button>
                  </form>
                </div>
                <?php elseif ($self): ?>
                <div class="text-right"><a href="<?= e(url('/admin/account/password')) ?>" class="font-label-sm text-label-sm text-primary hover:underline">비밀번호 변경</a></div>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if (!$isSuper): ?>
      <p class="rounded-lg bg-surface-container-low px-4 py-2.5 font-label-sm text-label-sm text-on-surface-variant">계정 만들기, 사용 중지, 비밀번호 초기화는 최고 관리자만 할 수 있습니다.</p>
      <?php endif; ?>
    </section>

    <?php if ($isSuper): ?>
    <section class="col-span-12 flex flex-col gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-md xl:sticky xl:top-24 xl:col-span-4" id="new-admin">
      <div>
        <h2 class="font-headline-md text-headline-md text-on-surface">관리자 추가</h2>
        <p class="font-label-sm text-label-sm text-on-surface-variant">비밀번호는 직접 정해서 본인에게 따로 전달해 주세요.</p>
      </div>
      <form method="post" action="<?= e(url('/admin/admins')) ?>" class="flex flex-col gap-4" novalidate autocomplete="off">
        <?= csrf_field() ?>
        <div>
          <label class="a-label" for="adm-login">아이디</label>
          <input id="adm-login" name="login_id" type="text" value="<?= e((string) old('login_id')) ?>" maxlength="50" autocapitalize="off" spellcheck="false" placeholder="영문, 숫자 4자 이상" class="a-input<?= $ring('login_id') ?>">
          <?= $err('login_id') ?>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="a-label" for="adm-name">이름</label>
            <input id="adm-name" name="name" type="text" value="<?= e((string) old('name')) ?>" maxlength="50" placeholder="예) 콘텐츠팀" class="a-input<?= $ring('name') ?>">
            <?= $err('name') ?>
          </div>
          <div>
            <label class="a-label" for="adm-role">권한</label>
            <select id="adm-role" name="role" class="a-input<?= $ring('role') ?>">
              <?php foreach (AdminAccountController::ROLES as $k => $label): ?>
              <option value="<?= e($k) ?>"<?= $oldRole === $k ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $err('role') ?>
          </div>
        </div>
        <div>
          <label class="a-label" for="adm-email">이메일 (선택)</label>
          <input id="adm-email" name="email" type="email" value="<?= e((string) old('email')) ?>" maxlength="191" placeholder="ops@example.com" class="a-input<?= $ring('email') ?>">
          <?= $err('email') ?>
        </div>
        <div>
          <label class="a-label" for="adm-pw">비밀번호</label>
          <input id="adm-pw" name="password" type="password" autocomplete="new-password" minlength="<?= AdminAccountController::PASSWORD_MIN ?>" class="a-input<?= $ring('password') ?>">
          <?= $err('password') ?>
        </div>
        <div>
          <label class="a-label" for="adm-pw2">비밀번호 확인</label>
          <input id="adm-pw2" name="password_confirmation" type="password" autocomplete="new-password" class="a-input<?= $ring('password_confirmation') ?>">
          <?= $err('password_confirmation') ?>
          <p class="mt-1 font-label-sm text-label-sm text-on-surface-variant"><?= AdminAccountController::PASSWORD_MIN ?>자 이상</p>
        </div>
        <div class="rounded-lg bg-surface-container-low px-4 py-3 font-label-sm text-label-sm text-on-surface-variant">
          <p><span class="font-bold text-on-surface">운영자</span>: 운영 메뉴 전체를 쓰고 자기 비밀번호를 바꿀 수 있습니다.</p>
          <p><span class="font-bold text-on-surface">최고 관리자</span>: 운영자 권한에 더해 관리자 계정을 만들고 사용 중지, 비밀번호 초기화를 합니다.</p>
        </div>
        <button type="submit" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">person_add</span>계정 만들기</button>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
