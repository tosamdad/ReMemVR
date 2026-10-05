-- 0004 동화 생성 요청과 플레이리스트
-- 목소리가 완성되면 동화를 한꺼번에 만들지 않는다. 회원이 동화와 목소리를 골라 생성 요청을 하고,
-- 관리자가 확인한 뒤 생성을 시작한다. 완성된 동화는 회원이 플레이리스트에 담아 반복, 랜덤으로 듣는다.
-- 이 파일은 서버에 적용된 뒤에는 수정하지 않는다. 이후 변경은 0005_ 부터 새 파일로 작성한다.

-- 동화 생성 요청(회원이 동화 한 편을 어떤 가족 목소리로 만들어 달라고 요청)
CREATE TABLE story_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    story_id BIGINT UNSIGNED NOT NULL,
    voice_profile_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'requested' COMMENT 'requested(관리자 확인 대기) | approved(생성 시작, 진행은 story_audios 상태) | rejected | canceled',
    reject_reason VARCHAR(255) NULL,
    processed_by INT UNSIGNED NULL,
    processed_at DATETIME NULL COMMENT '관리자가 생성 시작 또는 반려한 시각',
    completed_at DATETIME NULL COMMENT '오디오가 완성된 시각',
    notified_at DATETIME NULL COMMENT '회원에게 완성 메일을 보낸 시각',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_story_requests_user (user_id, created_at),
    KEY ix_story_requests_status (status, created_at),
    KEY ix_story_requests_pair (story_id, voice_profile_id),
    KEY ix_story_requests_voice (voice_profile_id),
    CONSTRAINT fk_story_requests_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_story_requests_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE,
    CONSTRAINT fk_story_requests_voice FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE CASCADE,
    CONSTRAINT fk_story_requests_admin FOREIGN KEY (processed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='동화 생성 요청';

-- 이미 만들어진(또는 만드는 중인) 동화 오디오는 생성을 시작한 요청으로 옮겨 회원의 내 동화에 보이게 한다.
INSERT INTO story_requests (user_id, story_id, voice_profile_id, status, processed_at, completed_at, notified_at, created_at)
SELECT vp.user_id, sa.story_id, sa.voice_profile_id, 'approved', sa.created_at,
       CASE WHEN sa.status = 'completed' THEN COALESCE(sa.generated_at, sa.updated_at) ELSE NULL END,
       CASE WHEN sa.status = 'completed' THEN COALESCE(sa.generated_at, sa.updated_at) ELSE NULL END,
       sa.created_at
  FROM story_audios sa
  JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
 WHERE vp.deleted_at IS NULL;

-- 목소리 상태는 목소리 자체의 준비 여부만 뜻한다(동화 생성 진행은 요청과 오디오 상태로 본다).
UPDATE voice_profiles
   SET status = 'completed'
 WHERE status = 'processing' AND provider_voice_id IS NOT NULL AND provider_voice_id <> '';

-- 목소리 완성 후 동화 자동 일괄 생성 설정은 더 쓰지 않는다.
DELETE FROM settings WHERE k = 'voice.auto_batch_after_clone';

-- 플레이리스트: 반복 방식과 랜덤 재생
ALTER TABLE playlists
    ADD COLUMN repeat_mode VARCHAR(10) NOT NULL DEFAULT 'all' COMMENT 'off(한 번) | all(전체 반복) | one(한 편 반복)' AFTER name,
    ADD COLUMN shuffle TINYINT(1) NOT NULL DEFAULT 0 COMMENT '랜덤 재생' AFTER repeat_mode;

-- 같은 플레이리스트에 같은 동화, 같은 목소리는 한 번만 담는다.
ALTER TABLE playlist_items
    ADD UNIQUE KEY uq_playlist_items (playlist_id, story_id, voice_profile_id);
