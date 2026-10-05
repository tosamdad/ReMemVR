<?php
/** 들을 수 있는 동화가 하나도 없을 때의 플레이어 */
layout('user/layout', ['title' => '플레이어', 'nav' => 'library']);
?>
<div class="card mt-6 flex flex-col items-center gap-3 p-8 text-center">
  <img src="<?= e(asset('img/empty-stories.svg')) ?>" alt="" class="h-32 w-auto">
  <h1 class="text-headline-md font-headline-md text-on-surface">아직 들을 수 있는 동화가 없어요</h1>
  <p class="text-body-md text-on-surface-variant">새 동화가 준비되면 여기에서 바로 들려줄게요.<br>그동안 가족 목소리를 녹음해 둘까요?</p>
  <a href="<?= e(url('/voice-lab')) ?>" class="btn-primary mt-2 w-full"><span class="material-symbols-outlined">mic</span>목소리 연구실로 가기</a>
  <a href="<?= e(url('/home')) ?>" class="btn-ghost w-full">홈으로</a>
</div>
