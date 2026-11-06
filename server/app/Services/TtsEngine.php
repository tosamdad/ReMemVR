<?php
namespace App\Services;

/**
 * TTS 엔진 선택. 관리자 설정(tts.engine = elevenlabs | typecast)을 읽어
 * ElevenLabs 와 Typecast 어댑터 중 지금 쓸 엔진으로 목소리 만들기, 삭제, 합성을 넘긴다.
 * 호출하는 쪽(Worker, VoiceService, QuestionService)은 ElevenLabs 반환 형식 그대로 받는다.
 *
 * 안전한 기본값:
 *   - tts.engine 이 없거나 'elevenlabs' 면 지금까지와 똑같이 ElevenLabs 로 동작한다.
 *   - Typecast 를 골랐어도 API 키(TYPECAST_API)가 없으면 ElevenLabs 로 되돌아간다.
 * 목소리와 엔진: voice_profiles.tts_engine(0008)에 그 목소리를 만든 엔진을 남긴다.
 *   NULL 은 이 기능 이전에 만든 목소리라 ElevenLabs 이다. 엔진을 바꾸면 기존 목소리는
 *   새 엔진으로 읽을 수 없으므로(음성 모델이 다르다) 합성 전에 profileMismatch 로 막는다.
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

    /** 지금 엔진이 목소리 만들기와 합성을 할 수 있는지(키 등록 여부, 개발 모드면 항상 가능) */
    public static function ready(): bool
    {
        return self::current() === 'typecast' ? Typecast::ready() : ElevenLabs::ready();
    }

    /** 키가 없을 때 관리자에게 보여 줄 문구 */
    public static function notReadyMessage(): string
    {
        return self::current() === 'typecast'
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
     * 음성 샘플로 목소리를 만든다. ElevenLabs::addVoice 와 같은 인자와 반환.
     * Typecast 는 샘플 중 WAV/MP3 하나만 쓴다(어댑터 안에서 고른다).
     */
    public static function addVoice(string $name, array $files, string $description = '', array $usage = []): array
    {
        return self::current() === 'typecast'
            ? Typecast::addVoice($name, $files, $description, $usage)
            : ElevenLabs::addVoice($name, $files, $description, $usage);
    }

    /** 복제한 목소리를 지운다. ElevenLabs::deleteVoice 와 같은 반환. */
    public static function deleteVoice(string $voiceId): array
    {
        return self::current() === 'typecast'
            ? Typecast::deleteVoice($voiceId)
            : ElevenLabs::deleteVoice($voiceId);
    }

    /**
     * 문장을 목소리로 읽는다. ElevenLabs::synthesize 와 같은 반환.
     * model_id, voice_settings, output_format 은 ElevenLabs 전용 옵션이라 Typecast 에서는 무시되고
     * typecast.model, typecast.audio_format 설정을 따른다.
     */
    public static function synthesize(string $voiceId, string $text, array $opts = []): array
    {
        return self::current() === 'typecast'
            ? Typecast::synthesize($voiceId, $text, $opts)
            : ElevenLabs::synthesize($voiceId, $text, $opts);
    }

    // ───────────────────────── 목소리와 엔진 ─────────────────────────

    /** 이 목소리가 만들어진 엔진. 기록이 없으면(0008 이전 목소리) ElevenLabs 이다. */
    public static function profileEngine(array $profile): string
    {
        return isset($profile['tts_engine']) && $profile['tts_engine'] === 'typecast' ? 'typecast' : 'elevenlabs';
    }

    /**
     * 이 목소리를 지금 엔진으로 읽을 수 있는지. 읽을 수 없으면 관리자용 안내 문구를 돌려준다.
     * 엔진이 다르면(예: ElevenLabs 로 만든 목소리를 Typecast 로 읽기) 목소리를 다시 만들어야 한다.
     * @return string|null 문제가 없으면 null
     */
    public static function profileMismatch(array $profile): ?string
    {
        $made = self::profileEngine($profile);
        $now = self::current();
        if ($made === $now) {
            return null;
        }

        return '이 목소리는 ' . self::label($made) . '(으)로 만들어져 ' . self::label($now) . '(으)로 읽을 수 없습니다. '
            . '관리자 화면에서 이 목소리를 다시 생성하거나, 운영 설정에서 TTS 엔진을 ' . self::label($made) . '(으)로 되돌려 주세요.';
    }

    // ───────────────────────── 목소리 자리(슬롯) ─────────────────────────

    /**
     * 지금 엔진에서 만들어 둘 수 있는 목소리 수(0 이면 관리하지 않는다).
     * ElevenLabs 는 elevenlabs.voice_slot_limit(Starter 10), Typecast 는 플랜마다 클로닝 목소리 슬롯이 정해져 있다.
     */
    public static function slotLimit(): int
    {
        return self::current() === 'typecast'
            ? max(0, (int) setting('typecast.voice_slot_limit', 3))
            : max(0, (int) setting('elevenlabs.voice_slot_limit', 10));
    }
}
