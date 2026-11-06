<?php
namespace App\Services;

/**
 * TTS 엔진 선택. 관리자 설정(tts.engine = elevenlabs | typecast)을 읽어 ElevenLabs 와 Typecast
 * 어댑터 가운데 알맞은 엔진으로 목소리 만들기, 삭제, 합성을 넘긴다. 호출하는 쪽(Worker,
 * VoiceService, QuestionService)은 ElevenLabs 반환 형식 그대로 받는다.
 *
 * 안전한 기본값:
 *   - tts.engine 이 없거나 'elevenlabs' 면 지금까지와 똑같이 ElevenLabs 로 동작한다.
 *   - Typecast 를 골랐어도 API 키(TYPECAST_API)가 없으면 ElevenLabs 로 되돌아간다.
 *
 * 목소리와 엔진: voice_profiles.tts_engine(0008)에 그 목소리를 만든 엔진을 남긴다.
 *   NULL 은 이 기능 이전에 만든 목소리라 ElevenLabs 이다. 목소리 만들기는 지금 설정 엔진으로
 *   하지만, 삭제와 합성 같은 목소리별 작업은 그 목소리가 만들어진 엔진으로 돌려야 한다.
 *   운영 설정에서 엔진을 바꿔도 기존 목소리는 원래 엔진에 그대로 남아 있으므로,
 *   지금 엔진으로 지우거나 읽으면("목소리 ID 형식이 올바르지 않습니다" 오류) 실패하기 때문이다.
 *   새로 만드는 목소리는 지금 설정 엔진을 따른다.
 */
class TtsEngine
{
    const ENGINES = ['elevenlabs', 'typecast'];

    /** 지금 쓸 엔진. Typecast 를 골랐는데 키가 없으면 ElevenLabs 로 되돌아간다(설정값 자체는 그대로 둔다). */
    public static function current(): string
    {
        $engine = (string) setting('tts.engine', 'elevenlabs');
        if ($engine === 'typecast' && \App\Services\Typecast::ready()) {
            return 'typecast';
        }

        return 'elevenlabs';
    }

    /**
     * 이 작업을 처리할 엔진. 엔진을 따로 알려 주면(목소리별 작업에서 그 목소리의 원래 엔진)
     * 그 엔진으로 돌리고, 아니면 지금 설정 엔진을 따른다.
     */
    public static function routeEngine(?string $engine = null): string
    {
        return $engine !== null && in_array($engine, self::ENGINES, true) ? $engine : self::current();
    }

    /** 지금 엔진이 목소리 만들기와 합성을 할 수 있는지(키 등록 여부, 개발 모드면 항상 가능). 목소리별 작업은 그 목소리의 엔진을 넘긴다. */
    public static function ready(?string $engine = null): bool
    {
        return self::routeEngine($engine) === 'typecast' ? Typecast::ready() : ElevenLabs::ready();
    }

    /** 키가 없을 때 관리자에게 보여 줄 문구. 목소리별 작업은 그 목소리의 엔진을 넘긴다. */
    public static function notReadyMessage(?string $engine = null): string
    {
        return self::routeEngine($engine) === 'typecast'
            ? 'Typecast API 키(TYPECAST_API)가 설정되지 않았습니다.'
            : 'ElevenLabs API 키가 설정되지 않았습니다.';
    }

    /** 화면에 보여 줄 엔진 이름 */
    public static function label(?string $engine = null): string
    {
        $engine = $engine !== null ? $engine : self::current();

        return $engine === 'typecast' ? 'Typecast' : 'ElevenLabs';
    }

    // ───────────────────────── 어댑터 위임 ─────────────────────────

    /**
     * 음성 샘플로 목소리를 만든다(지금 설정 엔진으로). ElevenLabs::addVoice 와 같은 인자와 반환.
     * Typecast 는 샘플 중 WAV/MP3 하나만 쓴다(어댑터 안에서 고른다).
     */
    public static function addVoice(string $name, array $files, string $description = '', array $usage = []): array
    {
        return self::current() === 'typecast'
            ? Typecast::addVoice($name, $files, $description, $usage)
            : ElevenLabs::addVoice($name, $files, $description, $usage);
    }

    /**
     * 복제한 목소리를 지운다. ElevenLabs::deleteVoice 와 같은 반환.
     * $engine 을 넘기면 지금 설정과 관계없이 그 엔진에서 지운다. 목소리를 지울 때는 반드시
     * 그 목소리가 만들어진 엔진(voice_profiles.tts_engine, profileEngine)을 넘겨야 한다.
     * 다른 엔진의 목소리 id 는 형식부터 달라서("목소리 ID 형식이 올바르지 않습니다") 지울 수 없다.
     */
    public static function deleteVoice(string $voiceId, ?string $engine = null): array
    {
        return self::routeEngine($engine) === 'typecast'
            ? Typecast::deleteVoice($voiceId)
            : ElevenLabs::deleteVoice($voiceId);
    }

    /**
     * 문장을 목소리로 읽는다. ElevenLabs::synthesize 와 같은 반환.
     * $engine 을 넘기면 지금 설정과 관계없이 그 엔진으로 읽는다. 합성할 때도 반드시 그 목소리가
     * 만들어진 엔진을 넘겨야 한다(음성 모델은 엔진마다 다르다).
     * model_id, voice_settings, output_format 은 ElevenLabs 전용 옵션이라 Typecast 에서는 무시되고
     * typecast.model, typecast.audio_format 설정을 따른다.
     */
    public static function synthesize(string $voiceId, string $text, array $opts = [], ?string $engine = null): array
    {
        return self::routeEngine($engine) === 'typecast'
            ? Typecast::synthesize($voiceId, $text, $opts)
            : ElevenLabs::synthesize($voiceId, $text, $opts);
    }

    // ───────────────────────── 목소리와 엔진 ─────────────────────────

    /** 이 목소리가 만들어진 엔진. 기록이 없으면(0008 이전 목소리) ElevenLabs 이다. */
    public static function profileEngine(array $profile): string
    {
        return isset($profile['tts_engine']) && $profile['tts_engine'] === 'typecast' ? 'typecast' : 'elevenlabs';
    }

    // ───────────────────────── 목소리 자리(슬롯) ─────────────────────────

    /**
     * 지금 엔진에서 만들어 둘 수 있는 목소리 수(0 이면 관리하지 않는다).
     * ElevenLabs 는 elevenlabs.voice_slot_limit(Starter 10), Typecast 는 플랜마다 클로닝 목소리 슬롯이 정해져 있다.
     * 자리는 엔진별로 센다(엔진을 바꿔도 예전 엔진 목소리는 새 엔진 자리를 쓰지 않는다).
     */
    public static function slotLimit(): int
    {
        return self::current() === 'typecast'
            ? max(0, (int) setting('typecast.voice_slot_limit', 3))
            : max(0, (int) setting('elevenlabs.voice_slot_limit', 10));
    }
}
