-- 0007 준비된 목소리의 녹음 고치기와 다시 생성
-- 목소리가 준비된 뒤에도 회원이 녹음을 들어 보고 더하거나 지울 수 있다(사용자 확정 2026-10-27).
-- 녹음을 바꾸면 바로 목소리를 바꾸지 않고, 회원이 "다시 생성"을 눌러야 지금 남은 녹음으로 ElevenLabs 목소리를 새로 만든다.
-- 이미 만든 동화 오디오는 그대로 둔다. 남은 녹음은 최소 길이(voice.min_sample_seconds) 이상이어야 한다.
-- 이 파일은 서버에 적용된 뒤에는 수정하지 않는다. 이후 변경은 0008_ 부터 새 파일로 작성한다.

ALTER TABLE voice_profiles
    ADD COLUMN samples_changed_at DATETIME NULL COMMENT '목소리를 만든 뒤 녹음을 더하거나 지운 시각(다시 생성하면 비운다)' AFTER cloned_at;
