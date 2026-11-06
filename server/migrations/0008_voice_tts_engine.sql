-- 0008 TTS 엔진 선택 1단계(ElevenLabs / Typecast)
-- 관리자가 운영 설정에서 TTS 엔진을 고를 수 있게 하기 위한 첫 단계로,
-- 각 목소리가 어느 엔진으로 만들어졌는지 기록한다. 엔진이 다르면 같은 목소리 id 로 음성 합성을 할 수 없다.
-- 이 파일은 서버에 적용된 뒤에는 수정하지 않는다. 이후 변경은 0009_ 부터 새 파일로 작성한다.

ALTER TABLE voice_profiles
    ADD COLUMN tts_engine VARCHAR(20) NULL COMMENT '이 provider_voice_id 를 만든 TTS 엔진(elevenlabs|typecast). NULL 은 이 기능 이전의 ElevenLabs 목소리' AFTER provider_voice_id;

-- 지금까지의 목소리는 모두 ElevenLabs 로 만들었다.
UPDATE voice_profiles SET tts_engine = 'elevenlabs' WHERE provider_voice_id IS NOT NULL AND provider_voice_id <> '';
