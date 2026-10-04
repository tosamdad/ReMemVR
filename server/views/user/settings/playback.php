<?php
/** 재생 설정. 변수: $prefs, $speeds, $timers */
layout('user/layout', ['title' => '재생 설정', 'header' => 'sub', 'back' => '/settings']);
$seg = 'flex min-h-11 cursor-pointer items-center justify-center rounded-xl px-2 text-[14px] font-semibold text-on-surface-variant transition-all peer-checked:bg-surface-container-lowest peer-checked:text-primary peer-checked:shadow-sm peer-focus-visible:ring-2 peer-focus-visible:ring-primary/40';
$heading = 'mb-3 px-2 text-[14px] font-bold leading-5 text-primary';
$card = 'overflow-hidden rounded-[24px] border border-surface-variant/30 bg-surface-container-lowest shadow-[0_4px_20px_0_rgba(0,0,0,0.05)]';
$segGroup = function ($name, array $options, $current) use ($seg) {
    $cols = [2 => 'grid-cols-2', 3 => 'grid-cols-3', 4 => 'grid-cols-4'];
    $out = '<div class="grid gap-1 rounded-2xl bg-surface-container p-1 ' . (isset($cols[count($options)]) ? $cols[count($options)] : 'grid-cols-3') . '" role="radiogroup">';
    foreach ($options as $value => $text) {
        $checked = (string) $value === (string) $current ? ' checked' : '';
        $out .= '<label class="block"><input type="radio" name="' . e($name) . '" value="' . e($value) . '" class="peer sr-only"' . $checked . '><span class="' . $seg . '">' . e($text) . '</span></label>';
    }

    return $out . '</div>';
};
$speedOptions = [];
foreach ($speeds as $s) {
    $speedOptions[number_format($s, 2, '.', '')] = rtrim(rtrim(number_format($s, 2, '.', ''), '0'), '.') . '배';
}
$currentSpeed = number_format((float) $prefs['playback_speed'], 2, '.', '');
$timerOptions = [];
foreach ($timers as $t) {
    $timerOptions[$t] = $t === 0 ? '끔' : $t . '분';
}
$switchRow = function ($name, $icon, $title, $desc, $on, $badge = '') {
    return '<div class="flex items-start justify-between gap-4 p-md">'
        . '<span class="flex min-w-0 items-start gap-4"><span class="material-symbols-outlined mt-0.5 text-outline">' . e($icon) . '</span>'
        . '<span class="min-w-0"><span class="flex flex-wrap items-center gap-2 font-body-md text-body-md text-on-surface">' . e($title)
        . ($badge !== '' ? '<span class="rounded-full bg-tertiary-container px-2 py-0.5 text-[10px] font-bold text-on-tertiary-container">' . e($badge) . '</span>' : '')
        . '</span><span class="mt-0.5 block font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant">' . e($desc) . '</span></span></span>'
        . '<label class="switch mt-0.5" aria-label="' . e($title) . '"><input type="hidden" name="' . e($name) . '" value="0"><input type="checkbox" name="' . e($name) . '" value="1"' . ($on ? ' checked' : '') . '><span></span></label>'
        . '</div>';
};
?>
<p class="mb-6 px-1 font-body-md text-body-md text-on-surface-variant">동화 플레이어에서 쓰는 기본값이에요. 계정에 저장되어 어느 기기에서 들어도 똑같이 적용돼요.</p>

<form method="post" action="<?= e(url('/settings/playback')) ?>" class="space-y-8">
  <?= csrf_field() ?>
  <section>
    <h3 class="<?= $heading ?>">읽기 속도</h3>
    <div class="<?= $card ?> space-y-5 p-md">
      <?= $segGroup('playback_speed', $speedOptions, $currentSpeed) ?>
      <p class="px-1 font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant">말을 배우는 어린 아이에게는 0.75배가 듣기 편해요.</p>
    </div>
  </section>

  <section>
    <h3 class="<?= $heading ?>">화면</h3>
    <div class="<?= $card ?>">
      <div class="space-y-3 border-b border-surface-variant/30 p-md">
        <p class="flex items-center gap-4 font-body-md text-body-md text-on-surface"><span class="material-symbols-outlined text-outline">format_size</span>글자 크기</p>
        <?= $segGroup('text_size', ['sm' => '작게', 'md' => '보통', 'lg' => '크게'], $prefs['text_size']) ?>
      </div>
      <?= $switchRow('highlight', 'ink_highlighter', '읽는 문장 강조', '지금 읽고 있는 단어와 문장을 색으로 표시해요.', !empty($prefs['highlight'])) ?>
    </div>
  </section>

  <section>
    <h3 class="<?= $heading ?>">이어 듣기와 잠자리</h3>
    <div class="<?= $card ?>">
      <div class="border-b border-surface-variant/30">
        <?= $switchRow('autoplay_next', 'playlist_play', '다음 동화 이어 듣기', '동화가 끝나면 다음 동화를 자동으로 들려줘요.', !empty($prefs['autoplay_next'])) ?>
      </div>
      <div class="space-y-3 p-md">
        <p class="flex items-center gap-4 font-body-md text-body-md text-on-surface"><span class="material-symbols-outlined text-outline">bedtime</span>수면 타이머</p>
        <?= $segGroup('sleep_timer_min', $timerOptions, (int) $prefs['sleep_timer_min']) ?>
        <p class="px-1 font-label-sm text-label-sm font-normal leading-5 text-on-surface-variant">정한 시간이 지나면 재생을 조용히 멈춰요.</p>
      </div>
    </div>
  </section>

  <section>
    <h3 class="<?= $heading ?>">대화</h3>
    <div class="<?= $card ?>">
      <?= $switchRow('hands_free', 'record_voice_over', '말하면 바로 물어보기', '버튼을 누르지 않아도 아이가 말을 시작하면 동화를 멈추고 질문을 들어요. 주변이 시끄럽거나 스피커 소리가 크면 잘못 멈출 수 있어서 이어폰이나 조용한 곳을 추천해요.', !empty($prefs['hands_free']), '실험 기능') ?>
    </div>
  </section>

  <button type="submit" class="btn-primary w-full rounded-full">저장하기</button>
</form>
