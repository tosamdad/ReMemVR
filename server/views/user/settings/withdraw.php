<?php
/** 회원 탈퇴. 변수: $user, $hasPassword, $phrase, $counts */
layout('user/layout', ['title' => '회원 탈퇴', 'header' => 'sub', 'back' => '/settings', 'mainClass' => 'px-margin-mobile pt-6 pb-6 break-keep']);
$input = 'w-full min-h-12 rounded-2xl border-2 bg-surface-container-lowest px-4 font-body-md text-body-md text-on-surface transition-all placeholder:text-outline focus:border-error focus:ring-0';
$label = 'ml-2 text-[14px] font-semibold leading-5 tracking-[0.02em] text-on-surface-variant';
$items = [
    ['record_voice_over', '가족 목소리 ' . (int) $counts['voices'] . '개', '녹음 원본, 복제된 AI 목소리(ElevenLabs), 미리 만든 동화 오디오를 모두 지워요.'],
    ['child_care', '아이 프로필 ' . (int) $counts['children'] . '명', '이름, 생년월일, 성별, 아바타를 지워요.'],
    ['mic', '질문 녹음과 대답 음성', '아이가 물어본 녹음 파일과 AI 대답 음성 파일을 바로 지워요.'],
    ['person_off', '계정 정보', '이름, 이메일, 휴대전화, 간편 로그인 연결을 지우고 다시 쓸 수 없게 해요.'],
];
?>
<div class="mb-6 flex flex-col items-center gap-3 text-center">
  <span class="flex h-16 w-16 items-center justify-center rounded-3xl bg-error-container text-on-error-container"><span class="material-symbols-outlined icon-fill text-[32px]">heart_broken</span></span>
  <h2 class="font-headline-md text-headline-md text-on-surface">정말 떠나시나요?</h2>
  <p class="max-w-[320px] font-body-md text-body-md text-on-surface-variant"><?= e($user['name']) ?>님, 탈퇴하면 아래 정보가 지워지고 되돌릴 수 없어요.</p>
</div>

<ul class="card mb-6 divide-y divide-surface-variant/30">
  <?php foreach ($items as $it): ?>
  <li class="flex gap-4 p-4">
    <span class="material-symbols-outlined mt-0.5 text-error"><?= e($it[0]) ?></span>
    <span>
      <span class="block font-label-lg text-label-lg text-on-surface"><?= e($it[1]) ?></span>
      <span class="mt-0.5 block font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant"><?= e($it[2]) ?></span>
    </span>
  </li>
  <?php endforeach; ?>
</ul>

<div class="mb-6 rounded-2xl bg-surface-container-low p-4 font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant">
  <?php if ($counts['plays'] > 0): ?>동화 재생 기록 <?= fmt_number($counts['plays']) ?>건은 누구의 기록인지 알 수 없는 통계로만 남아요. <?php endif; ?>
  1:1 문의 내역은 전자상거래 등에서의 소비자보호에 관한 법률에 따라 3년 동안 보관한 뒤 지워요.
  같은 이메일이나 간편 로그인 계정으로 언제든 다시 가입할 수 있지만, 지운 목소리와 기록은 돌아오지 않아요.
</div>

<form method="post" action="<?= e(url('/settings/withdraw')) ?>" class="card space-y-5 p-md" novalidate data-confirm="정말 탈퇴할까요? 지운 정보는 되돌릴 수 없어요.">
  <?= csrf_field() ?>
  <?php if ($hasPassword): ?>
  <div class="space-y-1.5">
    <label for="password" class="<?= $label ?>">비밀번호 확인</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="지금 쓰는 비밀번호" class="<?= $input ?> <?= errors('password') ? 'border-error' : 'border-surface-variant' ?>">
    <?php if (errors('password')): ?><p class="field-error ml-2" role="alert"><?= e(errors('password')) ?></p><?php endif; ?>
  </div>
  <?php else: ?>
  <div class="space-y-1.5">
    <label for="confirm_phrase" class="<?= $label ?>">확인 문구 입력</label>
    <input id="confirm_phrase" name="confirm_phrase" type="text" autocomplete="off" required data-confirm-phrase="<?= e($phrase) ?>" placeholder="<?= e($phrase) ?>" value="<?= e(old('confirm_phrase')) ?>" class="<?= $input ?> <?= errors('confirm_phrase') ? 'border-error' : 'border-surface-variant' ?>">
    <?php if (errors('confirm_phrase')): ?><p class="field-error ml-2" role="alert"><?= e(errors('confirm_phrase')) ?></p><?php else: ?><p class="ml-2 font-label-sm text-label-sm text-on-surface-variant">간편 로그인 계정이라 “<?= e($phrase) ?>”를 그대로 입력해 주세요.</p><?php endif; ?>
  </div>
  <?php endif; ?>
  <label class="flex cursor-pointer items-start gap-3 px-1">
    <input type="checkbox" name="agree" value="1" class="mt-0.5 h-5 w-5 shrink-0 rounded-md border-2 border-outline-variant bg-surface-container-lowest text-error focus:ring-error/30 focus:ring-offset-0"<?= old('agree') ? ' checked' : '' ?>>
    <span class="font-body-md text-[15px] leading-6 text-on-surface">위 안내를 모두 확인했고, 정보가 지워지는 것에 동의해요.</span>
  </label>
  <?php if (errors('agree')): ?><p class="field-error ml-2" role="alert"><?= e(errors('agree')) ?></p><?php endif; ?>
  <div class="grid grid-cols-2 gap-3">
    <a href="<?= e(url('/settings')) ?>" class="btn-secondary rounded-full">더 써 볼게요</a>
    <button type="submit" data-withdraw-submit class="inline-flex items-center justify-center gap-2 rounded-full bg-error px-6 py-4 font-label-lg text-label-lg text-on-error transition-all active:scale-[0.98] disabled:opacity-40">탈퇴하기</button>
  </div>
</form>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
