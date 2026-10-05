-- 0006 ElevenLabs 목소리 자리(슬롯) 관리
-- Starter 요금제는 ElevenLabs 에 만들어 둘 수 있는 목소리가 10개까지다(사용자 확정 2026-10-05).
-- 새 목소리를 만들 자리가 없으면, 가장 오래 쓰지 않은 목소리를 ElevenLabs 에서만 지우고 자리를 비운다.
-- 녹음 샘플과 이미 만든 동화 오디오는 그대로 두므로 재생에는 영향이 없고,
-- 그 목소리로 새 동화를 만들 때 남겨 둔 녹음으로 자동으로 다시 만든다.
-- 이 파일은 서버에 적용된 뒤에는 수정하지 않는다. 이후 변경은 0007_ 부터 새 파일로 작성한다.

ALTER TABLE voice_profiles
    ADD COLUMN provider_last_used_at DATETIME NULL COMMENT 'ElevenLabs 목소리로 마지막 합성(또는 생성)한 시각' AFTER provider_deleted_at,
    ADD COLUMN provider_released_at DATETIME NULL COMMENT '자리를 비우려고 ElevenLabs 목소리만 지운 시각(녹음은 남아 있어 다시 만들 수 있다)' AFTER provider_last_used_at;

-- 지금 있는 목소리의 마지막 사용 시각: 마지막으로 만든 동화 오디오, 없으면 목소리를 만든 시각
UPDATE voice_profiles vp
   SET vp.provider_last_used_at = COALESCE(
           (SELECT MAX(sa.generated_at) FROM story_audios sa WHERE sa.voice_profile_id = vp.id),
           vp.cloned_at, vp.updated_at)
 WHERE vp.provider_voice_id IS NOT NULL AND vp.provider_voice_id <> '';
