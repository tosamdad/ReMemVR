<?php
/** 첫 자녀 등록. 변수: $user, $values */
layout('user/layout_auth', ['title' => '아이 프로필 만들기']);
?>
<div class="relative isolate break-keep">
  <?= partial('user/auth/backdrop') ?>
  <div class="mx-auto w-full max-w-md space-y-8">
    <header class="space-y-3 text-center">
      <div class="mx-auto mb-2 inline-flex h-16 w-16 items-center justify-center rounded-3xl bg-tertiary-fixed text-on-tertiary-fixed-variant">
        <span class="material-symbols-outlined icon-fill text-[32px]">child_care</span>
      </div>
      <h1 class="font-headline-xl-mobile text-headline-xl-mobile tracking-tight text-on-background">우리 아이를<br>소개해 주세요</h1>
      <p class="mx-auto max-w-[300px] font-body-md text-body-md text-on-surface-variant"><?= e($user['name']) ?>님, 아이에게 꼭 맞는 이야기 시간을 준비할게요.</p>
    </header>

    <section class="rounded-[32px] border border-surface-container-lowest/50 bg-surface-container-lowest/85 p-md shadow-[0_20px_40px_-15px_rgba(128,80,98,0.1)] backdrop-blur-md">
      <form method="post" action="<?= e(url('/onboarding')) ?>" class="space-y-6" novalidate>
        <?= csrf_field() ?>
        <?= partial('user/settings/child_fields', ['values' => $values]) ?>
        <div class="pt-2">
          <button type="submit" class="flex min-h-12 w-full items-center justify-center gap-2 rounded-full bg-primary py-4 font-label-lg text-label-lg text-on-primary shadow-[0_4px_0_0_rgba(0,0,0,0.1)] transition-all hover:brightness-110 active:translate-y-0.5 active:scale-[0.98] active:shadow-[0_1px_0_0_rgba(0,0,0,0.1)]">
            이야기 시작하기<span class="material-symbols-outlined text-[20px]">arrow_forward</span>
          </button>
        </div>
      </form>
    </section>

    <p class="pb-8 text-center font-label-sm text-label-sm leading-5 text-on-surface-variant">
      아이 정보는 맞춤 대답과 성장 리포트에만 쓰여요.<br>설정 &gt; 아이 프로필 설정에서 언제든 바꾸거나 더 추가할 수 있어요.
    </p>
  </div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/settings.js')) ?>"></script>
<?php endsection(); ?>
