<?php
/**
 * 설정 메인(시안 _1). 스위치 켜짐 색은 peer-checked 로 직접 준다(공통 .switch 규칙이 켜짐 색을 못 바꾸는 문제 대비). 변수: $user, $child, $childCount, $prefs, $hasPassword, $newNotice, $newAnswers, $version
 * 알림, 다크 모드 스위치와 캐시 삭제는 settings.js 가 처리한다.
 */
layout('user/layout', ['title' => '설정', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$card = 'overflow-hidden rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest shadow-[0_4px_20px_0_rgba(0,0,0,0.05)]';
$row = 'flex w-full items-center justify-between gap-3 p-md text-left transition-colors hover:bg-surface-container';
$line = ' border-b border-surface-variant/30';
$heading = 'mb-3 px-2 text-[14px] font-bold leading-5 text-primary';
$chev = '<span class="material-symbols-outlined text-outline-variant">chevron_right</span>';
$item = function ($icon, $text) {
    return '<span class="flex min-w-0 items-center gap-4"><span class="material-symbols-outlined text-outline">' . e($icon) . '</span><span class="truncate font-body-md text-body-md text-on-surface">' . e($text) . '</span></span>';
};
$notifyOn = !empty($prefs['notify_voice_ready']) || !empty($prefs['notify_notice']);
?>
<div class="mb-8">
  <h2 class="text-[32px] font-bold leading-10 text-on-surface">설정</h2>
  <p class="mt-1 font-body-md text-body-md text-on-surface-variant">앱 사용 환경을 자유롭게 관리하세요.</p>
</div>

<a href="<?= e(url('/settings/profile')) ?>" class="mb-8 flex items-center gap-4 rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest p-md shadow-[0_4px_20px_0_rgba(0,0,0,0.05)] transition-colors hover:bg-surface-container-low" aria-label="프로필 수정">
  <span class="relative shrink-0">
    <?php if ($child): ?>
      <?= child_avatar($child, 'w-16 h-16 text-4xl', '!rounded-2xl shadow-sm') ?>
    <?php else: ?>
      <span class="flex h-16 w-16 items-center justify-center rounded-2xl bg-secondary-fixed shadow-sm">
        <span class="material-symbols-outlined icon-fill text-4xl text-on-secondary-container">person</span>
      </span>
    <?php endif; ?>
    <span class="absolute -bottom-1 -right-1 flex h-6 w-6 items-center justify-center rounded-full border-2 border-surface-container-lowest bg-primary">
      <span class="material-symbols-outlined text-[14px] text-on-primary">edit</span>
    </span>
  </span>
  <span class="min-w-0 flex-1">
    <span class="block truncate font-headline-md text-[20px] leading-7 text-on-surface"><?= e($user['name']) ?> 보호자</span>
    <span class="block truncate font-body-md text-body-md text-on-surface-variant"><?= e($user['email']) ?></span>
    <?php if ($child): ?>
      <span class="mt-1 inline-flex items-center gap-1 rounded-full bg-primary-fixed px-2 py-0.5 font-label-sm text-label-sm text-on-primary-fixed-variant">
        <span class="material-symbols-outlined text-[14px]">favorite</span><?= e($child['name']) ?><?= $childCount > 1 ? ' 외 ' . ($childCount - 1) . '명' : '' ?>
      </span>
    <?php endif; ?>
  </span>
</a>

<div class="space-y-8">
  <section>
    <h3 class="<?= $heading ?>">계정 관리</h3>
    <div class="<?= $card ?>">
      <a href="<?= e(url('/settings/profile')) ?>" class="<?= $row . $line ?>"><?= $item('account_circle', '프로필 수정') ?><?= $chev ?></a>
      <a href="<?= e(url('/settings/password')) ?>" class="<?= $row ?>">
        <?= $item('lock', $hasPassword ? '비밀번호 변경' : '비밀번호 설정') ?>
        <?php if (!$hasPassword): ?>
          <span class="flex items-center gap-2"><span class="whitespace-nowrap font-label-sm text-label-sm text-on-surface-variant">간편 로그인 사용 중</span><?= $chev ?></span>
        <?php else: ?><?= $chev ?><?php endif; ?>
      </a>
    </div>
  </section>

  <section>
    <h3 class="<?= $heading ?>">학습 및 목소리</h3>
    <div class="<?= $card ?>">
      <a href="<?= e(url('/settings/children')) ?>" class="<?= $row . $line ?>">
        <?= $item('child_care', '아이 프로필 설정') ?>
        <span class="flex items-center gap-2"><?php if ($childCount > 0): ?><span class="font-label-sm text-label-sm text-on-surface-variant"><?= (int) $childCount ?>명</span><?php endif; ?><?= $chev ?></span>
      </a>
      <a href="<?= e(url('/voice-lab')) ?>" class="<?= $row . $line ?>">
        <?= $item('mic_external_on', 'AI 목소리 관리') ?>
        <span class="flex items-center gap-2"><span class="rounded-full bg-secondary-container px-2 py-0.5 text-[10px] font-bold text-on-secondary-container">NEW</span><?= $chev ?></span>
      </a>
      <a href="<?= e(url('/settings/playback')) ?>" class="<?= $row ?>"><?= $item('play_circle', '재생 설정') ?><?= $chev ?></a>
    </div>
  </section>

  <section>
    <h3 class="<?= $heading ?>">앱 설정</h3>
    <div class="<?= $card ?>">
      <div class="flex w-full items-center justify-between gap-3 p-md<?= $line ?>">
        <span class="flex min-w-0 items-center gap-4">
          <span class="material-symbols-outlined text-outline">notifications</span>
          <span class="min-w-0">
            <span class="block font-body-md text-body-md text-on-surface">알림 설정</span>
            <span class="block font-label-sm text-label-sm font-normal text-on-surface-variant">목소리 준비 완료, 새 공지 메일</span>
          </span>
        </span>
        <label class="switch">
          <input type="checkbox" class="peer" aria-label="알림 받기" data-pref-switch="notify"<?= $notifyOn ? ' checked' : '' ?>>
          <span class="peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40"></span>
        </label>
      </div>
      <div class="flex w-full items-center justify-between gap-3 p-md<?= $line ?>">
        <?= $item('dark_mode', '다크 모드') ?>
        <label class="switch">
          <input type="checkbox" class="peer" aria-label="다크 모드" data-pref-switch="dark_mode"<?= !empty($prefs['dark_mode']) ? ' checked' : '' ?>>
          <?php if (!\App\Core\Auth::darkModeChosen()): ?><script>document.currentScript.previousElementSibling.checked = document.documentElement.classList.contains('dark');</script><?php endif; ?>
          <span class="peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40"></span>
        </label>
      </div>
      <button type="button" data-cache-clear class="<?= $row ?>">
        <?= $item('delete_sweep', '캐시 삭제') ?>
        <span data-cache-size class="whitespace-nowrap text-[12px] font-semibold text-outline">확인 중…</span>
      </button>
    </div>
  </section>

  <section>
    <h3 class="<?= $heading ?>">지원 및 정보</h3>
    <div class="<?= $card ?>">
      <a href="<?= e(url('/settings/notices')) ?>" class="<?= $row . $line ?>">
        <?= $item('campaign', '공지사항') ?>
        <span class="flex items-center gap-2"><?php if ($newNotice): ?><span class="h-2 w-2 rounded-full bg-primary" aria-label="새 공지"></span><?php endif; ?><?= $chev ?></span>
      </a>
      <a href="<?= e(url('/settings/support')) ?>" class="<?= $row . $line ?>">
        <?= $item('support_agent', '고객 센터') ?>
        <span class="flex items-center gap-2"><?php if ($newAnswers > 0): ?><span class="whitespace-nowrap rounded-full bg-secondary-container px-2 py-0.5 text-[10px] font-bold text-on-secondary-container">새 답변</span><?php endif; ?><?= $chev ?></span>
      </a>
      <a href="<?= e(url('/settings/terms')) ?>" class="<?= $row . $line ?>"><?= $item('description', '이용약관') ?><?= $chev ?></a>
      <a href="<?= e(url('/settings/privacy')) ?>" class="<?= $row . $line ?>"><?= $item('shield_person', '개인정보 처리방침') ?><?= $chev ?></a>
      <div class="flex w-full items-center justify-between gap-3 p-md">
        <?= $item('info', '앱 버전') ?>
        <span class="font-body-md text-body-md text-on-surface-variant"><?= e($version) ?></span>
      </div>
    </div>
  </section>

  <form method="post" action="<?= e(url('/logout')) ?>">
    <?= csrf_field() ?>
    <button type="submit" class="mt-4 flex w-full items-center justify-center gap-2 rounded-2xl bg-surface-container-high py-4 font-body-lg text-body-lg text-error shadow-[0_2px_0_0_rgba(0,0,0,0.05)] transition-all active:translate-y-0.5 active:scale-95 active:shadow-none">
      <span class="material-symbols-outlined">logout</span>
      로그아웃
    </button>
  </form>
  <p class="pb-2 text-center">
    <a href="<?= e(url('/settings/withdraw')) ?>" class="font-label-sm text-label-sm text-on-surface-variant underline underline-offset-4 hover:text-error">회원 탈퇴</a>
  </p>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
