<?php
/**
 * 운영 설정. 변수: values(Settings::all), schema, health(점검 결과 또는 null), checked, providers, fake, clipVoices, clipJobs, isSuper
 * 입력 이름은 s[설정 키] 이다(점이 들어간 키를 PHP 가 바꾸지 않도록 배열 안에 둔다).
 */
use App\Controllers\Admin\SettingsController;

layout('admin/layout', ['title' => '운영 설정', 'active' => 'settings']);

$old = old('s', null);
$v = static function (string $key) use ($values, $old) {
    if (is_array($old) && array_key_exists($key, $old)) {
        return $old[$key];
    }

    return array_key_exists($key, $values) ? $values[$key] : null;
};
$err = static function (string $key) {
    $m = errors($key);

    return $m ? '<p class="mt-1 font-label-sm text-label-sm text-error">' . e($m) . '</p>' : '';
};
$name = static function (string $key) {
    return 's[' . $key . ']';
};
$id = static function (string $key) {
    return 'set-' . str_replace(['.', '_'], '-', $key);
};
/** 숫자, 글자 입력칸 */
$input = static function (string $key, string $label, string $help = '', array $o = []) use ($v, $err, $name, $id, $schema) {
    $rule = $schema[$key];
    $val = $v($key);
    if (is_array($val)) {
        $val = '';
    }
    $type = in_array($rule['type'], ['int', 'float'], true) ? 'number' : ($rule['type'] === 'email' ? 'email' : 'text');
    $attrs = '';
    if ($type === 'number') {
        $attrs .= ' min="' . e($rule['min']) . '" max="' . e($rule['max']) . '" step="' . e(isset($o['step']) ? $o['step'] : ($rule['type'] === 'int' ? '1' : '0.01')) . '" inputmode="decimal"';
    } elseif (isset($rule['len'])) {
        $attrs .= ' maxlength="' . (int) $rule['len'] . '"';
    }
    $suffix = isset($o['suffix']) ? '<span class="shrink-0 font-label-md text-label-md text-on-surface-variant">' . e($o['suffix']) . '</span>' : '';
    $range = $type === 'number' ? '<span class="font-label-sm text-label-sm text-outline">' . e($rule['min']) . '~' . e($rule['max']) . '</span>' : '';

    return '<div><label class="a-label" for="' . $id($key) . '">' . e($label) . '</label>'
        . '<div class="flex items-center gap-2"><input id="' . $id($key) . '" name="' . e($name($key)) . '" type="' . $type . '" value="' . e(is_bool($val) ? (int) $val : $val) . '" class="a-input' . (errors($key) ? ' ring-2 ring-error' : '') . '"' . $attrs . (isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '') . '>' . $suffix . '</div>'
        . ($help !== '' || $range !== '' ? '<p class="mt-1 flex justify-between gap-2 font-label-sm text-label-sm text-on-surface-variant"><span>' . e($help) . '</span>' . $range . '</p>' : '')
        . $err($key) . '</div>';
};
/** 켜고 끄기 */
$toggle = static function (string $key, string $label, string $help = '', string $extra = '') use ($v, $name, $id) {
    $on = (bool) $v($key);
    if (is_string($v($key))) {
        $on = in_array($v($key), ['1', 'on', 'true'], true);
    }

    return '<label class="flex items-center justify-between gap-4 rounded-xl bg-surface-container-low p-4" for="' . $id($key) . '">'
        . '<span><span class="block font-label-md text-label-md text-on-surface">' . e($label) . '</span>'
        . ($help !== '' ? '<span class="block font-label-sm text-label-sm text-on-surface-variant">' . e($help) . '</span>' : '') . '</span>'
        . '<span class="switch"><input type="hidden" name="' . e($name($key)) . '" value="0"><input id="' . $id($key) . '" type="checkbox" name="' . e($name($key)) . '" value="1"' . ($on ? ' checked' : '') . $extra . '><span></span></span></label>';
};
/** 목록에서 고르기(지금 값이 목록에 없어도 고를 수 있게 둔다) */
$select = static function (string $key, string $label, string $help = '') use ($v, $err, $name, $id, $schema) {
    $val = (string) $v($key);
    $opts = $schema[$key]['options'];
    $html = '<div><label class="a-label" for="' . $id($key) . '">' . e($label) . '</label><select id="' . $id($key) . '" name="' . e($name($key)) . '" class="a-input">';
    if ($val !== '' && !isset($opts[$val])) {
        $html .= '<option value="' . e($val) . '" selected>' . e($val) . ' (현재 값)</option>';
    }
    foreach ($opts as $k => $l) {
        $html .= '<option value="' . e($k) . '"' . ((string) $k === $val ? ' selected' : '') . '>' . e($l) . '</option>';
    }

    return $html . '</select>' . ($help !== '' ? '<p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">' . e($help) . '</p>' : '') . $err($key) . '</div>';
};
/** 여러 줄 */
$area = static function (string $key, string $label, string $help = '', int $rows = 4) use ($v, $err, $name, $id, $schema) {
    $val = $v($key);
    $type = $schema[$key]['type'];
    if (is_array($val)) {
        $val = implode($type === 'words' ? ', ' : "\n", $val);
    }

    return '<div><label class="a-label" for="' . $id($key) . '">' . e($label) . '</label>'
        . '<textarea id="' . $id($key) . '" name="' . e($name($key)) . '" rows="' . $rows . '" class="a-input font-body-md text-body-md' . (errors($key) ? ' ring-2 ring-error' : '') . '">' . e((string) $val) . '</textarea>'
        . ($help !== '' ? '<p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">' . e($help) . '</p>' : '') . $err($key) . '</div>';
};

$nav = [
    'api' => ['hub', 'API 연동 상태'],
    'models' => ['memory', '모델과 단가'],
    'defense' => ['shield', '질문과 비용 방어'],
    'voice' => ['record_voice_over', '목소리 정책'],
    'service' => ['storefront', '서비스 정보'],
    'legal' => ['gavel', '약관'],
];
$providerInfo = [
    'elevenlabs' => ['ElevenLabs', '목소리 복제, 동화 오디오, 답변 음성', 'ELEVENLABS_API_KEY', 'graphic_eq'],
    'gemini' => ['Gemini', $fake ? '아이 질문 음성 이해와 답변 생성' : '아이 질문 답변 생성 (기능 보류 중, 쓰지 않음)', 'GEMINI_API_KEY', 'neurology'],
    'kakao' => ['카카오 로그인', '카카오 간편 로그인', 'KAKAO_REST_API_KEY, KAKAO_CLIENT_SECRET', 'chat'],
    'google' => ['구글 로그인', '구글 간편 로그인', 'GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET', 'account_circle'],
];
// 동화 1편(평균 글자 수) 생성 예상 비용
$avgChars = (int) db_value("SELECT COALESCE(ROUND(AVG(char_count)), 0) FROM stories WHERE deleted_at IS NULL AND status = 'published'");
$ratio = static function (string $model) use ($values) {
    $m = strtolower($model);

    return (strpos($m, 'flash') !== false || strpos($m, 'turbo') !== false) ? (float) $values['elevenlabs.flash_credit_ratio'] : 1.0;
};
$storyCredits = (int) round($avgChars * $ratio((string) $values['elevenlabs.model_story']));
$storyKrw = $storyCredits / 1000 * (float) $values['elevenlabs.usd_per_1k_credits'] * (float) $values['cost.usd_krw'];
$publishedCount = (int) db_value("SELECT COUNT(*) FROM stories WHERE deleted_at IS NULL AND status = 'published'");
$todayCost = (float) db_value('SELECT COALESCE(SUM(cost_krw), 0) FROM api_usage_logs WHERE created_at >= CURDATE()');
$budget = (float) $values['cost.daily_budget_krw'];
$okChip = static function ($ok, string $yes = '정상', string $no = '확인 필요') {
    return $ok
        ? '<span class="a-chip bg-emerald-100 text-emerald-800">' . e($yes) . '</span>'
        : '<span class="a-chip bg-error-container text-on-error-container">' . e($no) . '</span>';
};
?>
<div class="flex w-full flex-col gap-8" data-settings-page>
  <div class="flex flex-col justify-between gap-4 rounded-xl bg-surface-container-lowest p-6 shadow-sm lg:flex-row lg:items-center">
    <div class="flex flex-col gap-1.5">
      <div class="flex items-center gap-3">
        <span class="rounded-full bg-primary-fixed px-2.5 py-0.5 font-label-sm text-label-sm uppercase tracking-wider text-primary">Operations</span>
        <span class="font-label-sm text-label-sm text-on-surface-variant">바꾼 값은 저장 즉시 회원 화면과 작업 처리기에 적용됩니다</span>
      </div>
      <h1 class="font-headline-md text-headline-md text-on-surface">운영 설정</h1>
    </div>
    <button type="submit" form="settings-form" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">save</span>변경사항 저장</button>
  </div>

  <div class="grid grid-cols-12 items-start gap-8">
    <nav class="col-span-12 flex gap-1 overflow-x-auto no-scrollbar lg:sticky lg:top-24 lg:col-span-3 lg:flex-col xl:col-span-2" aria-label="설정 구역">
      <?php foreach ($nav as $anchor => $n): ?>
      <a href="#<?= $anchor ?>" class="flex shrink-0 items-center gap-2 rounded-xl px-3 py-2.5 font-label-md text-label-md text-on-surface-variant transition-colors hover:bg-surface-container-high hover:text-on-surface" data-nav="<?= $anchor ?>">
        <span class="material-symbols-outlined text-[18px]"><?= $n[0] ?></span><?= e($n[1]) ?>
      </a>
      <?php endforeach; ?>
    </nav>

    <div class="col-span-12 flex flex-col gap-8 lg:col-span-9 xl:col-span-10">
      <!-- API 연동 상태(폼 밖) -->
      <section id="api" class="a-card flex scroll-mt-24 flex-col gap-5" data-section>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">API 연동 상태</h2>
            <p class="font-label-sm text-label-sm text-on-surface-variant">API 키 값은 이 화면에 나오지 않습니다. 등록 여부와 최근 점검 결과만 보여 줍니다.</p>
          </div>
          <a href="<?= e(url('/admin/settings', ['check' => 1])) ?>#api" class="a-btn-tonal"><span class="material-symbols-outlined text-[18px]">network_check</span>지금 점검</a>
        </div>
        <?php if ($fake): ?>
        <p class="rounded-lg bg-secondary-fixed px-4 py-2.5 font-label-md text-label-md text-on-secondary-fixed">개발 모드입니다. ElevenLabs, Gemini 대신 가짜 응답(삐 소리 음성, 고정 답변)을 쓰고 메일을 보내지 않습니다.</p>
        <?php endif; ?>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
          <?php foreach ($providerInfo as $key => $p):
              $ready = $providers[$key];
              $h = $health && isset($health[$key]) ? $health[$key] : null;
          ?>
          <div class="flex flex-col gap-2 rounded-xl bg-surface-container-low p-4">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2">
                <span class="flex h-9 w-9 items-center justify-center rounded-full <?= $ready ? 'bg-primary-fixed text-primary' : 'bg-surface-container-highest text-on-surface-variant' ?>"><span class="material-symbols-outlined text-[20px]"><?= $p[3] ?></span></span>
                <div>
                  <p class="font-label-md text-label-md text-on-surface"><?= e($p[0]) ?></p>
                  <p class="font-label-sm text-label-sm text-on-surface-variant"><?= e($p[1]) ?></p>
                </div>
              </div>
              <?php if ($key === 'gemini' && !$fake): ?>
              <span class="a-chip bg-surface-container-highest text-on-surface-variant">보류</span>
              <?php else: ?>
              <?= $okChip($ready, $fake ? '개발 모드' : '키 등록됨', '키 미등록') ?>
              <?php endif; ?>
            </div>
            <?php if ($h): ?>
            <p class="font-label-sm text-label-sm <?= !empty($h['ok']) ? 'text-on-surface-variant' : 'text-error' ?>">점검: <?= e((string) $h['message']) ?><?= !empty($h['ms']) ? ' · ' . (int) $h['ms'] . 'ms' : '' ?></p>
            <?php if ($key === 'elevenlabs' && !empty($h['credits'])): $cr = $h['credits']; ?>
            <p class="font-label-sm text-label-sm text-on-surface-variant">잔여 크레딧 <?= e(fmt_number($cr['remaining'])) ?> / <?= e(fmt_number($cr['limit'])) ?><?= !empty($cr['tier']) ? ' · ' . e($cr['tier']) : '' ?></p>
            <?php endif; ?>
            <?php endif; ?>
            <?php if (!$ready && !($key === 'gemini' && !$fake)): ?>
            <p class="font-label-sm text-label-sm text-on-surface-variant">GitHub Secrets: <span class="font-mono"><?= e($p[2]) ?></span></p>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
        <?php if ($health): ?>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
          <div class="flex items-center justify-between gap-2 rounded-xl bg-surface-container-low px-4 py-3">
            <span class="font-label-md text-label-md text-on-surface">DB</span>
            <span class="flex items-center gap-2 font-label-sm text-label-sm text-on-surface-variant"><?= isset($health['db']['ms']) && $health['db']['ms'] !== null ? (int) $health['db']['ms'] . 'ms' : '' ?> <?= $okChip(!empty($health['db']['ok'])) ?></span>
          </div>
          <div class="flex items-center justify-between gap-2 rounded-xl bg-surface-container-low px-4 py-3">
            <span class="font-label-md text-label-md text-on-surface">저장 공간</span>
            <span class="flex items-center gap-2 font-label-sm text-label-sm text-on-surface-variant"><?= isset($health['storage']['free_mb']) && $health['storage']['free_mb'] !== null ? '남은 ' . e(fmt_number($health['storage']['free_mb'])) . 'MB' : '' ?> <?= $okChip(!empty($health['storage']['ok'])) ?></span>
          </div>
          <div class="flex items-center justify-between gap-2 rounded-xl bg-surface-container-low px-4 py-3">
            <span class="font-label-md text-label-md text-on-surface">작업 처리기</span>
            <span class="font-label-sm text-label-sm text-on-surface-variant">대기 <?= (int) ($health['worker']['pending'] ?? 0) ?> · 실패(24시간) <?= (int) ($health['worker']['failed_24h'] ?? 0) ?></span>
          </div>
        </div>
        <p class="font-label-sm text-label-sm text-on-surface-variant"><?= $checked ? '방금 점검했습니다' : '최근 점검' ?>: <?= e(isset($health['checked_at']) ? (string) $health['checked_at'] : '') ?><?= !$checked ? ' · "지금 점검"을 누르면 외부 API 에 다시 확인합니다.' : '' ?></p>
        <?php else: ?>
        <p class="font-label-sm text-label-sm text-on-surface-variant">아직 점검 기록이 없습니다. "지금 점검"을 누르면 ElevenLabs, 저장 공간, DB 상태를 확인합니다.</p>
        <?php endif; ?>
        <details class="rounded-xl bg-surface-container-low p-4">
          <summary class="cursor-pointer font-label-md text-label-md text-on-surface">API 키 등록 방법 (GitHub Secrets)</summary>
          <ol class="mt-3 list-decimal space-y-1.5 pl-5 font-label-sm text-label-sm text-on-surface-variant">
            <li>GitHub 저장소 → Settings → Secrets and variables → Actions → New repository secret 을 엽니다.</li>
            <li>이름과 값을 등록합니다: <span class="font-mono text-on-surface">ELEVENLABS_API_KEY</span>, <span class="font-mono text-on-surface">GEMINI_API_KEY</span>, <span class="font-mono text-on-surface">KAKAO_REST_API_KEY</span>, <span class="font-mono text-on-surface">KAKAO_CLIENT_SECRET</span>, <span class="font-mono text-on-surface">GOOGLE_CLIENT_ID</span>, <span class="font-mono text-on-surface">GOOGLE_CLIENT_SECRET</span> (필요한 것만).</li>
            <li>main 브랜치에 배포가 한 번 돌면 서버 설정 파일(config.php)에 들어가고, 이 화면에서 "키 등록됨"으로 바뀝니다.</li>
            <li>키가 없으면 해당 기능은 안내 문구와 함께 꺼집니다(목소리 생성 승인 비활성, 질문 대신 기기 음성 안내, 간편 로그인 준비 중).</li>
          </ol>
        </details>
      </section>

      <form id="settings-form" method="post" action="<?= e(url('/admin/settings')) ?>" class="flex flex-col gap-8" novalidate data-settings-form>
        <?= csrf_field() ?>
        <input type="hidden" name="_section" value="" data-section-input>

        <section id="models" class="a-card flex scroll-mt-24 flex-col gap-5" data-section>
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">모델과 단가</h2>
            <p class="font-label-sm text-label-sm text-on-surface-variant">비용 계산(대시보드, 일일 예산)에 쓰는 단가입니다. 실제 청구 금액은 각 서비스 결제 화면에서 확인하세요.</p>
          </div>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
              <?= $select('elevenlabs.model_story', '동화 오디오 모델') ?>
              <p class="mt-1 font-label-sm text-label-sm text-on-surface-variant">Flash 로 바꾸면 동화 생성 크레딧이 절반으로 줄고 음질은 조금 낮아집니다. 새로 만드는 오디오에만 적용됩니다.</p>
              <?php if ($avgChars > 0): ?>
              <p class="mt-1 font-label-sm text-label-sm text-primary">현재 모델 기준 동화 1편(평균 <?= e(fmt_number($avgChars)) ?>자) 약 <?= e(fmt_number($storyCredits)) ?>크레딧, <?= e(fmt_krw($storyKrw)) ?> · 목소리 1개당 <?= (int) $publishedCount ?>편 약 <?= e(fmt_krw($storyKrw * $publishedCount)) ?></p>
              <?php endif; ?>
            </div>
            <?= $select('elevenlabs.model_answer', '질문 답변 음성 모델', '답변은 빨라야 하므로 Flash v2.5 를 권장합니다.') ?>
            <?= $select('elevenlabs.output_format', '오디오 출력 형식', '바꾸면 새로 만드는 오디오부터 적용됩니다.') ?>
            <div></div>
            <?= $input('elevenlabs.default_stability', '안정성 기본값 (stability)', '높을수록 차분하고 일정한 목소리', ['step' => '0.05']) ?>
            <?= $input('elevenlabs.default_similarity', '유사도 기본값 (similarity boost)', '높을수록 원래 목소리에 가깝게', ['step' => '0.05']) ?>
            <?= $input('elevenlabs.default_style', '스타일 과장 기본값 (style)', '0 권장. 높이면 감정 표현이 커지고 느려집니다', ['step' => '0.05']) ?>
            <div></div>
            <?= $toggle('elevenlabs.speaker_boost', '화자 강조 (speaker boost)', '목소리 유사도를 조금 높입니다. 지연이 약간 늘어납니다.') ?>
            <?= $toggle('elevenlabs.remove_background_noise', '샘플 배경 소음 제거', '목소리 복제 때 녹음 샘플의 잡음을 지웁니다.') ?>
            <?= $input('elevenlabs.usd_per_1k_credits', 'ElevenLabs 1천 크레딧 단가', '대시보드 비용 계산 기준. 정액 요금제 원가는 월 요금 ÷ 월 크레딧 × 1000, 초과 사용 단가는 보통 0.30', ['suffix' => 'USD', 'step' => '0.001']) ?>
            <?= $input('elevenlabs.flash_credit_ratio', 'Flash, Turbo 글자당 크레딧 비율', 'Multilingual 은 1자 1크레딧', ['step' => '0.05']) ?>
            <?= $input('elevenlabs.voice_slot_limit', 'ElevenLabs 목소리 자리 수', '요금제의 커스텀 목소리 한도(Starter 10). 다 차면 가장 오래 안 쓴 목소리의 자리를 비우고, 그 목소리로 동화를 만들 때 녹음으로 다시 만듭니다. 0 이면 관리하지 않습니다', ['suffix' => '개']) ?>
            <?= $input('gemini.model', 'Gemini 모델', '예) gemini-2.5-flash') ?>
            <?= $input('cost.usd_krw', '환율 (1달러)', '비용을 원화로 바꿀 때 씁니다', ['suffix' => '원']) ?>
            <?= $input('gemini.usd_per_1m_input', 'Gemini 텍스트 입력 100만 토큰 단가', '', ['suffix' => 'USD']) ?>
            <?= $input('gemini.usd_per_1m_audio_input', 'Gemini 음성 입력 100만 토큰 단가', '', ['suffix' => 'USD']) ?>
            <?= $input('gemini.usd_per_1m_output', 'Gemini 출력 100만 토큰 단가', '', ['suffix' => 'USD']) ?>
          </div>
        </section>

        <section id="defense" class="a-card flex scroll-mt-24 flex-col gap-5" data-section>
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">질문(끼어들기)과 비용 방어</h2>
            <p class="font-label-sm text-label-sm text-on-surface-variant">동화별 설정(동화 콘텐츠 관리 → 끼어들기 설정)이 비어 있으면 이 값을 씁니다.</p>
          </div>
          <?php if (!$fake): ?>
          <p class="rounded-lg bg-secondary-fixed px-4 py-2.5 font-label-md text-label-md text-on-secondary-fixed">아이 질문 기능은 보류 중입니다. Gemini API 약관이 18세 미만이 이용하는 서비스에서의 사용을 금지해, 아래 스위치를 켜도 실서버에서는 질문 버튼이 나타나지 않습니다. 아이 대상 사용을 허용하는 답변 AI 로 바꾼 뒤 다시 켤 수 있습니다.</p>
          <?php endif; ?>
          <div class="rounded-xl <?= $v('qa.enabled') ? 'bg-surface-container-low' : 'bg-error-container' ?>" data-killswitch>
            <?= $toggle('qa.enabled', '질문 기능 전체 켜기 (긴급 차단 스위치)', '끄면 모든 동화에서 Gemini, ElevenLabs 호출 없이 대체 문장만 들려줍니다.', ' data-kill') ?>
          </div>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
            <?= $input('qa.max_questions', '동화 1편당 질문 한도', '넘으면 대체 문장으로 응답', ['suffix' => '회']) ?>
            <?= $input('qa.max_answer_chars', '답변 최대 글자 수', 'TTS 비용과 길이를 제한', ['suffix' => '자']) ?>
            <?= $input('qa.max_record_seconds', '질문 최대 녹음 시간', '', ['suffix' => '초']) ?>
            <?= $input('qa.vad_min_speech_ms', '발화 감지 최소 길이 (VAD)', '숨소리, 추임새 오인 방지', ['suffix' => 'ms', 'step' => '50']) ?>
            <?= $input('qa.silence_stop_ms', '말 끝 감지 침묵 길이', '이만큼 조용하면 녹음 종료', ['suffix' => 'ms', 'step' => '50']) ?>
            <?= $select('qa.aec_level', '반향 제거(AEC) 감도', '최대는 잡음 억제까지 켭니다.') ?>
          </div>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <?= $area('qa.fallback_lines', '질문 한도 초과 대체 문장 (한 줄에 하나, 순서대로 돌아가며 사용)', '최대 10줄, 한 줄 200자 이하', 4) ?>
            <?= $area('qa.error_lines', '오류 안내 문장 (한 줄에 하나)', '알아듣지 못했거나 처리에 실패했을 때', 4) ?>
          </div>
          <?= $area('qa.blocked_words', '금지어 (쉼표나 줄바꿈으로 구분)', '질문이나 답변에 들어 있으면 안전한 안내 문장으로 바꿉니다.', 3) ?>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <div>
              <?= $input('cost.daily_budget_krw', '일일 API 예산', '넘으면 그날은 질문 대신 안내 문장으로 응답합니다. 0 이면 제한 없음', ['suffix' => '원', 'step' => '1000']) ?>
              <p class="mt-1 font-label-sm text-label-sm text-primary">오늘 사용: <?= e(fmt_krw($todayCost)) ?><?= $budget > 0 ? ' (' . e(number_format(min(999, $todayCost * 100 / $budget), 1)) . '%)' : '' ?></p>
            </div>
            <?= $input('cost.latency_target_ms', '응답 지연 목표', '회원 및 통계 화면의 달성 기준', ['suffix' => 'ms', 'step' => '50']) ?>
          </div>
          <div class="flex flex-col justify-between gap-3 rounded-xl bg-surface-container-low p-4 md:flex-row md:items-center">
            <div>
              <p class="font-label-md text-label-md text-on-surface">가족 목소리 대체 음성</p>
              <p class="font-label-sm text-label-sm text-on-surface-variant">대체 문장, 오류 안내를 목소리마다 미리 합성해 둡니다(동화별 전용 문장 포함). 이미 만든 문장은 건너뜁니다. 대상 목소리 <?= (int) $clipVoices ?>개<?= $clipJobs ? ' · 진행 중 작업 ' . (int) $clipJobs . '개' : '' ?></p>
            </div>
            <button type="submit" form="clips-form" class="a-btn-secondary shrink-0"<?= $clipVoices ? '' : ' disabled' ?>><span class="material-symbols-outlined text-[18px]">autorenew</span>모든 목소리 대체 음성 다시 만들기</button>
          </div>
        </section>

        <section id="voice" class="a-card flex scroll-mt-24 flex-col gap-5" data-section>
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">목소리 정책</h2>
            <p class="font-label-sm text-label-sm text-on-surface-variant">회원 목소리 연구실의 녹음 기준, 목소리 생성과 동화 생성 요청 흐름입니다.</p>
          </div>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-4">
            <?= $input('voice.max_per_user', '회원당 목소리 수', '', ['suffix' => '개']) ?>
            <?= $input('voice.min_sample_seconds', '최소 녹음 길이', '', ['suffix' => '초']) ?>
            <?= $input('voice.recommended_sample_seconds', '권장 녹음 길이', '', ['suffix' => '초']) ?>
            <?= $input('voice.max_sample_seconds', '최대 녹음 길이', '', ['suffix' => '초']) ?>
          </div>
          <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <?= $toggle('voice.auto_clone_on_submit', '목소리 제출 즉시 자동 생성', '회원이 녹음을 제출하면 관리자 검토 없이 바로 ElevenLabs 목소리를 만듭니다(기본 켬, 목소리 생성은 크레딧을 쓰지 않습니다). 끄면 관리자가 확인한 뒤 만듭니다.') ?>
            <?= $toggle('request.auto_approve', '동화 생성 요청 자동 승인', '회원이 요청하면 관리자 확인 없이 바로 동화를 만듭니다(비용 주의).') ?>
          </div>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-4">
            <?= $input('request.max_open', '회원당 진행 중 요청', '확인 대기와 만드는 중인 요청을 합친 최대 개수', ['suffix' => '건']) ?>
          </div>
        </section>

        <section id="service" class="a-card flex scroll-mt-24 flex-col gap-5" data-section>
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">서비스 정보</h2>
            <p class="font-label-sm text-label-sm text-on-surface-variant">회원 화면의 이름, 고객센터 안내, 관리자 알림 메일에 쓰입니다.</p>
          </div>
          <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
            <?= $input('app.brand', '서비스 이름 (회원 화면)') ?>
            <?= $input('app.version', '버전 표시') ?>
            <?= $input('support.email', '고객센터 이메일', '', ['placeholder' => 'help@example.com']) ?>
            <?= $input('support.phone', '고객센터 전화번호', '', ['placeholder' => '02-000-0000']) ?>
            <?= $input('support.hours', '고객센터 운영 시간') ?>
            <?= $input('notify.admin_email', '관리자 알림 받을 이메일', '목소리 제출, 1:1 문의 알림', ['placeholder' => 'ops@example.com']) ?>
          </div>
        </section>

        <section id="legal" class="a-card flex scroll-mt-24 flex-col gap-5" data-section>
          <div>
            <h2 class="font-headline-md text-headline-md text-on-surface">약관</h2>
            <p class="font-label-sm text-label-sm text-on-surface-variant">비워 두면 회원 화면에 기본 문구가 나옵니다. 개정할 때는 시행일을 본문에 적어 주세요.</p>
          </div>
          <?= $area('legal.terms', '이용약관', '', 10) ?>
          <?= $area('legal.privacy', '개인정보 처리방침', '음성 데이터 수집 항목, 보관 기간, ElevenLabs·Google 위탁 내용을 꼭 포함하세요.', 10) ?>
        </section>

        <div class="sticky bottom-4 z-20 flex items-center justify-between gap-3 rounded-2xl bg-inverse-surface px-5 py-3 text-inverse-on-surface shadow-card">
          <span class="font-label-md text-label-md" data-dirty-text>바꾼 값은 저장을 눌러야 적용됩니다.</span>
          <button type="submit" class="a-btn-primary"><span class="material-symbols-outlined text-[18px]">save</span>변경사항 저장</button>
        </div>
      </form>
      <form id="clips-form" method="post" action="<?= e(url('/admin/settings/voice-clips')) ?>" class="hidden" data-confirm="목소리 <?= (int) $clipVoices ?>개의 대체 음성 만들기 작업을 등록할까요? 새로 바뀐 문장만 합성합니다."><?= csrf_field() ?></form>
    </div>
  </div>
</div>
<?php section('scripts'); ?>
<script src="<?= e(asset('js/admin-settings.js')) ?>"></script>
<?php endsection(); ?>
