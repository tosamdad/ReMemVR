<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Settings;
use App\Services\Health;
use App\Services\Jobs;

/**
 * 운영 설정. 화면의 모든 항목은 SCHEMA 에 형식과 범위를 적고, 저장할 때 형식에 맞게 바꾼 뒤 바뀐 키만 Settings::set 한다.
 * API 키는 서버 설정(GitHub Secrets)에만 있고 이 화면에서는 등록 여부만 보여 준다.
 */
class SettingsController
{
    const ELEVEN_MODELS = [
        'eleven_multilingual_v2' => 'Multilingual v2 (음질 우선, 1자당 1크레딧)',
        'eleven_flash_v2_5' => 'Flash v2.5 (비용 절반, 1자당 0.5크레딧)',
    ];
    const OUTPUT_FORMATS = [
        'mp3_44100_128' => 'MP3 44.1kHz 128kbps (기본)',
        'mp3_44100_64' => 'MP3 44.1kHz 64kbps (작은 파일)',
        'mp3_22050_32' => 'MP3 22.05kHz 32kbps (가장 작은 파일)',
    ];
    const AEC_LEVELS = ['normal' => '보통', 'strong' => '강함 (권장)', 'max' => '최대'];

    /**
     * 형식: int, float, bool, string, email, text(여러 줄), lines(한 줄에 하나 → 배열), words(쉼표나 줄바꿈 → 배열), select
     * min/max: 숫자 범위(넘으면 가장 가까운 값으로 맞춘다), len: 최대 글자 수, options: select 목록(지금 값은 항상 유지)
     */
    const SCHEMA = [
        // 모델과 단가
        'elevenlabs.model_story' => ['type' => 'select', 'options' => self::ELEVEN_MODELS],
        'elevenlabs.model_answer' => ['type' => 'select', 'options' => self::ELEVEN_MODELS],
        'elevenlabs.output_format' => ['type' => 'select', 'options' => self::OUTPUT_FORMATS],
        'elevenlabs.default_stability' => ['type' => 'float', 'min' => 0, 'max' => 1],
        'elevenlabs.default_similarity' => ['type' => 'float', 'min' => 0, 'max' => 1],
        'elevenlabs.default_style' => ['type' => 'float', 'min' => 0, 'max' => 1],
        'elevenlabs.speaker_boost' => ['type' => 'bool'],
        'elevenlabs.remove_background_noise' => ['type' => 'bool'],
        'elevenlabs.usd_per_1k_credits' => ['type' => 'float', 'min' => 0, 'max' => 10],
        'elevenlabs.flash_credit_ratio' => ['type' => 'float', 'min' => 0, 'max' => 1],
        'gemini.model' => ['type' => 'string', 'len' => 60, 'pattern' => '/^[a-z0-9][a-z0-9.\-]*$/'],
        'gemini.usd_per_1m_input' => ['type' => 'float', 'min' => 0, 'max' => 100],
        'gemini.usd_per_1m_audio_input' => ['type' => 'float', 'min' => 0, 'max' => 100],
        'gemini.usd_per_1m_output' => ['type' => 'float', 'min' => 0, 'max' => 100],
        'cost.usd_krw' => ['type' => 'int', 'min' => 500, 'max' => 5000],
        // 질문(끼어들기)과 비용 방어
        'qa.enabled' => ['type' => 'bool'],
        'qa.max_questions' => ['type' => 'int', 'min' => 0, 'max' => 10],
        'qa.max_answer_chars' => ['type' => 'int', 'min' => 40, 'max' => 400],
        'qa.max_record_seconds' => ['type' => 'int', 'min' => 3, 'max' => 60],
        'qa.vad_min_speech_ms' => ['type' => 'int', 'min' => 200, 'max' => 3000],
        'qa.silence_stop_ms' => ['type' => 'int', 'min' => 300, 'max' => 5000],
        'qa.aec_level' => ['type' => 'select', 'options' => self::AEC_LEVELS],
        'qa.fallback_lines' => ['type' => 'lines', 'len' => 200, 'max_items' => 10, 'required' => true],
        'qa.error_lines' => ['type' => 'lines', 'len' => 200, 'max_items' => 10, 'required' => true],
        'qa.blocked_words' => ['type' => 'words', 'len' => 50, 'max_items' => 300],
        'cost.daily_budget_krw' => ['type' => 'int', 'min' => 0, 'max' => 100000000],
        'cost.latency_target_ms' => ['type' => 'int', 'min' => 300, 'max' => 10000],
        // 목소리 정책
        'voice.max_per_user' => ['type' => 'int', 'min' => 1, 'max' => 20],
        'voice.min_sample_seconds' => ['type' => 'int', 'min' => 10, 'max' => 600],
        'voice.recommended_sample_seconds' => ['type' => 'int', 'min' => 10, 'max' => 900],
        'voice.max_sample_seconds' => ['type' => 'int', 'min' => 30, 'max' => 1800],
        'voice.auto_batch_after_clone' => ['type' => 'bool'],
        'voice.auto_clone_on_submit' => ['type' => 'bool'],
        // 서비스 정보
        'app.brand' => ['type' => 'string', 'len' => 30, 'required' => true],
        'app.version' => ['type' => 'string', 'len' => 20, 'required' => true],
        'support.email' => ['type' => 'email', 'len' => 191],
        'support.phone' => ['type' => 'string', 'len' => 30],
        'support.hours' => ['type' => 'string', 'len' => 100],
        'notify.admin_email' => ['type' => 'email', 'len' => 191],
        // 약관
        'legal.terms' => ['type' => 'text', 'len' => 60000],
        'legal.privacy' => ['type' => 'text', 'len' => 60000],
    ];

    /** GET /admin/settings (?check=1 이면 외부 API 상태를 새로 점검) */
    public function index(): string
    {
        require_admin();
        $health = null;
        $checked = (string) Request::query('check', '') === '1';
        if ($checked) {
            try {
                $health = Health::status(true);
            } catch (\Throwable $e) {
                app_log('error', '상태 점검 실패: ' . $e->getMessage());
                flash('error', '상태 점검 중 오류가 났습니다. 로그를 확인해 주세요.');
            }
        } else {
            // 저장된 최근 점검 결과가 있으면 보여 준다(외부 호출 없음).
            $cached = setting(Health::CACHE_KEY);
            if (is_array($cached) && isset($cached['data']) && is_array($cached['data'])) {
                $health = $cached['data'];
                $health['cached'] = true;
            }
        }
        $clipVoices = (int) db_value("SELECT COUNT(*) FROM voice_profiles WHERE deleted_at IS NULL AND provider_voice_id IS NOT NULL AND provider_voice_id <> ''");
        $clipJobs = (int) db_value("SELECT COUNT(*) FROM jobs WHERE type = 'voice_clips' AND status IN ('pending', 'running')");

        return view('admin/settings/index', [
            'values' => Settings::all(),
            'schema' => self::SCHEMA,
            'health' => $health,
            'checked' => $checked,
            'providers' => [
                'elevenlabs' => provider_ready('elevenlabs'),
                'gemini' => provider_ready('gemini'),
                'kakao' => provider_ready('kakao'),
                'google' => provider_ready('google'),
            ],
            'fake' => (bool) config('providers_fake'),
            'clipVoices' => $clipVoices,
            'clipJobs' => $clipJobs,
            'isSuper' => (current_admin()['role'] ?? '') === 'super',
        ]);
    }

    /** POST /admin/settings */
    public function save(): void
    {
        $admin = require_admin();
        $input = isset($_POST['s']) && is_array($_POST['s']) ? $_POST['s'] : [];
        list($clean, $errors) = self::normalize($input, Settings::all());
        if ($errors) {
            flash('error', '입력값을 확인해 주세요. ' . reset($errors));
            back_with_errors($errors, '/admin/settings');
        }
        $current = Settings::all();
        $changed = [];
        foreach ($clean as $key => $value) {
            if (!array_key_exists($key, $current) || json_encode_u($current[$key]) !== json_encode_u($value)) {
                Settings::set($key, $value, (int) $admin['id']);
                $changed[] = $key;
            }
        }
        if ($changed) {
            admin_audit('settings.update', 'settings', null, ['keys' => $changed]);
            flash('success', '설정 ' . count($changed) . '개를 저장했습니다.');
            if (array_intersect($changed, ['qa.fallback_lines', 'qa.error_lines'])) {
                flash('info', '대체 문장이 바뀌었습니다. 가족 목소리 음성은 "모든 목소리 대체 음성 다시 만들기"로 새로 만들 수 있습니다.');
            }
        } else {
            flash('info', '바뀐 설정이 없습니다.');
        }
        $section = preg_replace('/[^a-z]/', '', (string) input('_section', ''));
        header('Location: ' . url('/admin/settings') . ($section !== '' ? '#' . $section : ''), true, 302);
        exit;
    }

    /**
     * 입력값을 형식에 맞게 바꾼다. 화면에 없는 키는 건드리지 않는다.
     * 숫자는 범위 안으로 맞추고, 숫자가 아니면 오류. 오류 키는 화면 필드 이름(s.키)과 같은 'qa.max_questions' 형식이다.
     * @return array [$clean, $errors]
     */
    public static function normalize(array $input, array $current): array
    {
        $clean = [];
        $errors = [];
        foreach (self::SCHEMA as $key => $rule) {
            $type = $rule['type'];
            $present = array_key_exists($key, $input);
            if ($type === 'bool') {
                // 체크박스 앞의 숨은 값(0)으로 항목이 화면에 있었는지 안다.
                if ($present) {
                    $v = $input[$key];
                    $v = is_array($v) ? end($v) : $v;
                    $clean[$key] = in_array((string) $v, ['1', 'on', 'true'], true);
                }
                continue;
            }
            if (!$present) {
                continue;
            }
            $raw = $input[$key];
            if (is_array($raw)) {
                $errors[$key] = '값 형식이 올바르지 않습니다.';
                continue;
            }
            $raw = str_replace("\r\n", "\n", (string) $raw);
            switch ($type) {
                case 'int':
                case 'float':
                    $t = trim(str_replace(',', '', $raw));
                    if ($t === '' || !is_numeric($t)) {
                        $errors[$key] = '숫자를 입력해 주세요.';
                        break;
                    }
                    $n = $type === 'int' ? (int) round((float) $t) : round((float) $t, 4);
                    $n = max($rule['min'], min($rule['max'], $n));
                    $clean[$key] = $type === 'int' ? (int) $n : (float) $n;
                    break;
                case 'select':
                    $t = trim($raw);
                    $keep = isset($current[$key]) ? (string) $current[$key] : '';
                    if (!isset($rule['options'][$t]) && $t !== $keep) {
                        $errors[$key] = '목록에서 골라 주세요.';
                        break;
                    }
                    $clean[$key] = $t;
                    break;
                case 'email':
                    $t = trim($raw);
                    if ($t !== '' && (!filter_var($t, FILTER_VALIDATE_EMAIL) || mb_strlen($t) > $rule['len'])) {
                        $errors[$key] = '올바른 이메일 주소를 입력해 주세요.';
                        break;
                    }
                    $clean[$key] = $t;
                    break;
                case 'string':
                    $t = trim(preg_replace('/\s+/u', ' ', $raw));
                    if ($t === '' && !empty($rule['required'])) {
                        $errors[$key] = '값을 입력해 주세요.';
                        break;
                    }
                    if (mb_strlen($t) > $rule['len']) {
                        $errors[$key] = $rule['len'] . '자 이하로 입력해 주세요.';
                        break;
                    }
                    if ($t !== '' && isset($rule['pattern']) && !preg_match($rule['pattern'], $t)) {
                        $errors[$key] = '영문 소문자, 숫자, 점, 하이픈만 쓸 수 있습니다.';
                        break;
                    }
                    $clean[$key] = $t;
                    break;
                case 'text':
                    $t = trim($raw);
                    if (mb_strlen($t) > $rule['len']) {
                        $errors[$key] = number_format($rule['len']) . '자 이하로 입력해 주세요.';
                        break;
                    }
                    $clean[$key] = $t;
                    break;
                case 'lines':
                case 'words':
                    $parts = $type === 'lines' ? explode("\n", $raw) : preg_split('/[,\n]+/u', $raw);
                    $items = [];
                    foreach ($parts as $p) {
                        $p = trim(preg_replace('/\s+/u', ' ', $p));
                        if ($p === '' || in_array($p, $items, true)) {
                            continue;
                        }
                        if (mb_strlen($p) > $rule['len']) {
                            $errors[$key] = '한 항목은 ' . $rule['len'] . '자 이하로 입력해 주세요.';
                        }
                        $items[] = $p;
                    }
                    if (count($items) > $rule['max_items']) {
                        $errors[$key] = $rule['max_items'] . '개까지 입력할 수 있습니다.';
                    }
                    if (!$items && !empty($rule['required'])) {
                        $errors[$key] = '한 줄 이상 입력해 주세요.';
                    }
                    if (!isset($errors[$key])) {
                        $clean[$key] = $items;
                    }
                    break;
            }
        }
        // 녹음 길이: 최소 ≤ 권장 ≤ 최대
        $min = isset($clean['voice.min_sample_seconds']) ? $clean['voice.min_sample_seconds'] : (int) $current['voice.min_sample_seconds'];
        $rec = isset($clean['voice.recommended_sample_seconds']) ? $clean['voice.recommended_sample_seconds'] : (int) $current['voice.recommended_sample_seconds'];
        $max = isset($clean['voice.max_sample_seconds']) ? $clean['voice.max_sample_seconds'] : (int) $current['voice.max_sample_seconds'];
        if (!isset($errors['voice.recommended_sample_seconds']) && !($min <= $rec && $rec <= $max)) {
            $errors['voice.recommended_sample_seconds'] = '최소 ≤ 권장 ≤ 최대 순서가 되도록 입력해 주세요.';
        }

        return [$clean, $errors];
    }

    /** POST /admin/settings/voice-clips: 목소리마다 대체 문장, 오류 안내 음성을 다시 만든다(이미 있는 문장은 건너뜀). */
    public function rebuildClips(): void
    {
        require_admin();
        $voices = db_all("SELECT id FROM voice_profiles WHERE deleted_at IS NULL AND provider_voice_id IS NOT NULL AND provider_voice_id <> '' ORDER BY id");
        $n = 0;
        foreach ($voices as $v) {
            $id = (int) $v['id'];
            Jobs::enqueue('voice_clips', ['profile_id' => $id], ['ref_type' => 'voice_profile', 'ref_id' => $id, 'unique' => true]);
            $n++;
        }
        admin_audit('settings.voice_clips', 'settings', null, ['voices' => $n]);
        flash($n > 0 ? 'success' : 'info', $n > 0 ? '목소리 ' . $n . '개의 대체 음성 만들기 작업을 등록했습니다. 새 문장만 합성하므로 크레딧은 바뀐 문장 분량만 사용됩니다.' : '대체 음성을 만들 목소리(ElevenLabs 목소리)가 아직 없습니다.');
        header('Location: ' . url('/admin/settings') . '#defense', true, 302);
        exit;
    }
}
