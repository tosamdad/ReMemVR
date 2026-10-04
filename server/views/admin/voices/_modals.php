<?php
/**
 * 목소리 관리 공용 창: 재녹음 요청(반려), 캐시 파일 목록, 테스트 재생. admin-voices.js 가 연다.
 * 여는 버튼: [data-reject-url][data-reject-name], [data-audios-url][data-audios-name], [data-test-url][data-test-name]
 * 변수: elReady
 */
$reasons = [
    '주변 소음이 커서 목소리가 잘 들리지 않아요.',
    '목소리가 너무 작거나 멀리서 녹음되었어요.',
    '녹음 길이가 부족해요. 조금 더 길게 읽어 주세요.',
    '여러 사람의 목소리가 섞여 있어요.',
    '소리가 찢어지는 구간(클리핑)이 있어요.',
];
?>
<dialog id="reject-dialog" class="w-[520px] max-w-[calc(100vw-2rem)] rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-card backdrop:bg-black/30" aria-labelledby="reject-title">
  <form method="post" action="" class="flex flex-col gap-5 p-6" data-reject-form>
    <?= csrf_field() ?>
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-error-container text-on-error-container"><span class="material-symbols-outlined text-[22px]">replay</span></div>
        <div class="flex flex-col">
          <h2 id="reject-title" class="font-headline-md text-[20px] font-bold text-on-surface">재녹음 요청</h2>
          <span class="font-label-sm text-label-sm text-on-surface-variant" data-reject-target></span>
        </div>
      </div>
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high" data-dialog-close aria-label="닫기"><span class="material-symbols-outlined">close</span></button>
    </div>
    <p class="font-body-md text-sm text-on-surface-variant">요청을 반려하고 회원에게 다시 녹음해 달라는 안내 메일을 보냅니다. 아래 사유가 메일과 회원 화면에 그대로 표시됩니다.</p>
    <div class="flex flex-wrap gap-1.5">
      <?php foreach ($reasons as $r): ?>
      <button type="button" class="rounded-full bg-surface-container-high px-3 py-1 font-label-sm text-label-sm text-on-surface transition-colors hover:bg-surface-variant" data-reason="<?= e($r) ?>"><?= e(str_limit($r, 18)) ?></button>
      <?php endforeach; ?>
    </div>
    <div>
      <label class="a-label" for="reject-reason">반려 사유</label>
      <textarea id="reject-reason" name="reason" rows="3" maxlength="255" required class="a-input resize-none" placeholder="예) 주변 소음이 커서 목소리가 잘 들리지 않아요."></textarea>
      <p class="mt-1.5 hidden font-label-sm text-label-sm text-error" data-reject-error>반려 사유를 입력하세요.</p>
    </div>
    <div class="flex justify-end gap-2">
      <button type="button" class="a-btn-tonal" data-dialog-close>취소</button>
      <button type="submit" class="a-btn-danger"><span class="material-symbols-outlined text-[18px]">send</span>반려하고 재녹음 요청</button>
    </div>
  </form>
</dialog>

<dialog id="audios-dialog" class="w-[720px] max-w-[calc(100vw-2rem)] rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-card backdrop:bg-black/30" aria-labelledby="audios-title">
  <div class="flex max-h-[80vh] flex-col gap-4 p-6">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-fixed text-on-primary-fixed-variant"><span class="material-symbols-outlined text-[22px]">folder_open</span></div>
        <div class="flex flex-col">
          <h2 id="audios-title" class="font-headline-md text-[20px] font-bold text-on-surface">사전 생성 오디오 파일</h2>
          <span class="font-label-sm text-label-sm text-on-surface-variant" data-audios-target></span>
        </div>
      </div>
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high" data-dialog-close aria-label="닫기"><span class="material-symbols-outlined">close</span></button>
    </div>
    <div class="min-h-[120px] overflow-y-auto" data-audios-body>
      <p class="py-8 text-center font-label-md text-label-md text-on-surface-variant">불러오는 중…</p>
    </div>
  </div>
</dialog>

<dialog id="test-dialog" class="w-[520px] max-w-[calc(100vw-2rem)] rounded-2xl bg-surface-container-lowest p-0 text-on-surface shadow-card backdrop:bg-black/30" aria-labelledby="test-title">
  <form class="flex flex-col gap-5 p-6" data-test-form>
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-3">
        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-secondary-fixed text-on-secondary-fixed"><span class="material-symbols-outlined text-[22px]">campaign</span></div>
        <div class="flex flex-col">
          <h2 id="test-title" class="font-headline-md text-[20px] font-bold text-on-surface">테스트 재생</h2>
          <span class="font-label-sm text-label-sm text-on-surface-variant" data-test-target></span>
        </div>
      </div>
      <button type="button" class="flex h-9 w-9 items-center justify-center rounded-full text-on-surface-variant hover:bg-surface-container-high" data-dialog-close aria-label="닫기"><span class="material-symbols-outlined">close</span></button>
    </div>
    <p class="font-body-md text-sm text-on-surface-variant">지금 저장된 합성 파라미터로 문장을 바로 합성해 들어 봅니다. 답변용 모델로 합성하며 ElevenLabs 크레딧이 소모됩니다.</p>
    <div>
      <label class="a-label" for="test-text">테스트 문장</label>
      <textarea id="test-text" name="text" rows="3" maxlength="300" class="a-input resize-none">안녕! 오늘도 재미있는 이야기를 들려줄게.</textarea>
    </div>
    <div class="hidden flex-col gap-2 rounded-xl bg-surface-container-low p-3" data-test-result>
      <audio controls class="w-full" data-test-audio></audio>
      <span class="font-label-sm text-label-sm text-on-surface-variant" data-test-meta></span>
    </div>
    <div class="flex justify-end gap-2">
      <button type="button" class="a-btn-tonal" data-dialog-close>닫기</button>
      <button type="submit" class="a-btn-primary"<?= !empty($elReady) ? '' : ' disabled title="ElevenLabs API 키가 등록되지 않았습니다"' ?>><span class="material-symbols-outlined text-[18px]">play_arrow</span>합성해서 듣기</button>
    </div>
  </form>
</dialog>
