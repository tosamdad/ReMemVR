<?php
namespace App\Core;

/**
 * 관리자 화면에서 바꾸는 운영 설정(settings 테이블). 값은 JSON 으로 저장한다.
 * 저장된 값이 없으면 DEFAULTS 를 쓴다. 새 설정을 추가할 때는 DEFAULTS 에 기본값을 함께 적는다.
 */
class Settings
{
    const DEFAULTS = [
        // 서비스 기본
        'app.version' => '1.0.0',
        'app.brand' => '르멤버',
        'support.email' => '',
        'support.phone' => '',
        'support.hours' => '평일 10:00 ~ 18:00 (주말, 공휴일 휴무)',
        'notify.admin_email' => '',
        'legal.terms' => '',
        'legal.privacy' => '',

        // 목소리
        'voice.max_per_user' => 5,
        'voice.min_sample_seconds' => 60,
        'voice.recommended_sample_seconds' => 120,
        'voice.max_sample_seconds' => 300,
        'voice.auto_clone_on_submit' => true,

        // 동화 생성 요청(회원이 동화와 목소리를 골라 요청하고 관리자가 생성을 시작한다)
        'request.max_open' => 30,
        'request.auto_approve' => false,

        // ElevenLabs
        'elevenlabs.model_story' => 'eleven_multilingual_v2',
        'elevenlabs.model_answer' => 'eleven_flash_v2_5',
        'elevenlabs.output_format' => 'mp3_44100_128',
        'elevenlabs.default_stability' => 0.65,
        'elevenlabs.default_similarity' => 0.80,
        'elevenlabs.default_style' => 0.0,
        'elevenlabs.speaker_boost' => true,
        'elevenlabs.remove_background_noise' => true,
        'elevenlabs.usd_per_1k_credits' => 0.30,
        'elevenlabs.flash_credit_ratio' => 0.5,
        // ElevenLabs 에 만들어 둘 수 있는 목소리 수(Starter 10). 다 차면 가장 오래 안 쓴 목소리의 자리를 비운다. 0 이면 관리하지 않는다.
        'elevenlabs.voice_slot_limit' => 10,

        // Claude(아이 질문 답변 기본 모델)
        'claude.model' => 'claude-haiku-5-5',

        // Gemini(관리자가 답변 모델로 고를 때만 쓴다)
        'gemini.model' => 'gemini-2.5-flash',
        'gemini.usd_per_1m_input' => 0.30,
        'gemini.usd_per_1m_audio_input' => 1.00,
        'gemini.usd_per_1m_output' => 2.50,

        // 질문(끼어들기): 앱이 폰 안에서 말을 글로 바꿔 보내면 답변 AI 가 답하고 가족 목소리로 읽는다.
        // 보호자 동의, 앱 준비 전까지 꺼 둔다.
        'qa.enabled' => false,
        'qa.llm_provider' => 'claude',
        // 마이크를 놓은 뒤 이 시간 안에 답 음성이 안 오면 준비된 음성(qa.timeout_lines)으로 답한다.
        'qa.timeout_ms' => 5000,
        'qa.max_questions' => 3,
        'qa.max_answer_chars' => 120,
        'qa.max_record_seconds' => 15,
        'qa.vad_min_speech_ms' => 1000,
        'qa.silence_stop_ms' => 1200,
        'qa.aec_level' => 'strong',
        'qa.fallback_lines' => [
            '나머지 이야기는 다 듣고 또 얘기하자, 조금만 더 들어볼까?',
            '질문은 이야기가 모두 끝나고 하면 훨씬 재미있을 것 같아!',
        ],
        'qa.error_lines' => [
            '음, 잘 못 들었어. 이야기를 계속 들어 볼까?',
        ],
        // 마이크를 놓자마자 들려주는 생각하는 소리
        'qa.thinking_lines' => [
            '음, 그건 말이지…',
            '오, 궁금하구나. 잠깐만…',
        ],
        // 답이 늦을 때(qa.timeout_ms 초과) 대신 들려주는 말
        'qa.timeout_lines' => [
            '우와, 좋은 질문이다! 이야기 다 듣고 같이 생각해 보자.',
        ],
        'qa.blocked_words' => [],

        // 비용
        'cost.usd_krw' => 1400,
        'cost.daily_budget_krw' => 27000,
        'cost.latency_target_ms' => 1500,
    ];

    /** @var array<string, mixed>|null */
    private static $cache = null;

    public static function get(string $key, $default = null)
    {
        $all = self::loaded();
        if (array_key_exists($key, $all)) {
            return $all[$key];
        }
        if (array_key_exists($key, self::DEFAULTS)) {
            return self::DEFAULTS[$key];
        }

        return $default;
    }

    /** 기본값과 저장값을 합친 전체 설정 */
    public static function all(): array
    {
        return array_merge(self::DEFAULTS, self::loaded());
    }

    public static function set(string $key, $value, ?int $adminId = null): void
    {
        db_query(
            'INSERT INTO settings (k, v, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_by = VALUES(updated_by)',
            [$key, json_encode_u($value), $adminId]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    public static function forget(string $key): void
    {
        db_exec('DELETE FROM settings WHERE k = ?', [$key]);
        if (self::$cache !== null) {
            unset(self::$cache[$key]);
        }
    }

    private static function loaded(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (db_all('SELECT k, v FROM settings') as $row) {
                    $decoded = json_decode((string) $row['v'], true);
                    self::$cache[$row['k']] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $row['v'];
                }
            } catch (\Throwable $e) {
                // settings 테이블이 아직 없을 때(마이그레이션 전)는 기본값만 쓴다.
                self::$cache = [];
            }
        }

        return self::$cache;
    }
}
