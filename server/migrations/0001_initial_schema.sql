-- 0001 초기 스키마 (기획안 초기 런칭 버전 기준 초안)
-- 이 파일은 서버에 적용된 뒤에는 수정하지 않는다. 테이블 추가, 필드 추가와 변경은 0002_ 부터 새 파일로 작성한다.
-- cafe24 의 구버전 MariaDB 에서도 동작하도록 인덱스가 걸리는 VARCHAR 는 191자 이하로 둔다.

-- 관리자 계정
CREATE TABLE admins (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    login_id VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(50) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'admin' COMMENT 'super | admin',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_login_id (login_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='관리자';

-- 회원(보호자)
CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(191) NOT NULL,
    password_hash VARCHAR(255) NULL COMMENT '소셜 로그인만 쓰는 경우 NULL',
    name VARCHAR(50) NOT NULL,
    phone VARCHAR(20) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | blocked | withdrawn',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY ix_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원(보호자)';

-- 자녀
CREATE TABLE children (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    birth_year SMALLINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_children_user (user_id),
    CONSTRAINT fk_children_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='자녀';

-- 가족 목소리(엄마, 아빠, 할머니 등). 회원 한 명이 여러 목소리를 가진다.
CREATE TABLE voice_profiles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(30) NOT NULL COMMENT '엄마, 아빠, 할머니 등',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | processing | completed | rejected',
    provider VARCHAR(30) NOT NULL DEFAULT 'elevenlabs',
    provider_voice_id VARCHAR(100) NULL COMMENT 'ElevenLabs voice_id',
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at DATETIME NULL,
    processed_by INT UNSIGNED NULL,
    reject_reason VARCHAR(255) NULL,
    admin_memo TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_voice_profiles_user (user_id),
    KEY ix_voice_profiles_status (status, requested_at),
    CONSTRAINT fk_voice_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_voice_profiles_admin FOREIGN KEY (processed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='가족 목소리 및 생성 요청';

-- 목소리 생성용 음성 샘플(녹음 또는 업로드 파일)
CREATE TABLE voice_samples (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    voice_profile_id BIGINT UNSIGNED NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NULL,
    file_size INT UNSIGNED NULL,
    duration_ms INT UNSIGNED NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'record' COMMENT 'record | upload',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_voice_samples_profile (voice_profile_id),
    CONSTRAINT fk_voice_samples_profile FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='음성 샘플';

-- 무료 동화 콘텐츠
CREATE TABLE stories (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    author VARCHAR(100) NULL,
    summary VARCHAR(500) NULL,
    body MEDIUMTEXT NOT NULL,
    cover_image_path VARCHAR(500) NULL,
    age_min TINYINT UNSIGNED NULL,
    age_max TINYINT UNSIGNED NULL,
    char_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'TTS 비용 산정용 글자 수',
    status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | published | hidden',
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_stories_status (status, sort_order),
    CONSTRAINT fk_stories_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='동화';

-- 동화 문장. 끼어들기(Barge-in) 시 멈춘 문장 위치와 재생 복귀 지점 계산에 쓴다.
CREATE TABLE story_sentences (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    story_id BIGINT UNSIGNED NOT NULL,
    seq INT UNSIGNED NOT NULL,
    content TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_story_sentences_seq (story_id, seq),
    CONSTRAINT fk_story_sentences_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='동화 문장';

-- 목소리별로 사전 생성한 동화 오디오(MP3)
CREATE TABLE story_audios (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    story_id BIGINT UNSIGNED NOT NULL,
    voice_profile_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | processing | completed | failed',
    file_path VARCHAR(500) NULL,
    duration_ms INT UNSIGNED NULL,
    sentence_timings MEDIUMTEXT NULL COMMENT '문장별 시작 시각(ms) JSON 문자열',
    char_count INT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_story_audios (story_id, voice_profile_id),
    KEY ix_story_audios_voice (voice_profile_id),
    KEY ix_story_audios_status (status),
    CONSTRAINT fk_story_audios_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE,
    CONSTRAINT fk_story_audios_voice FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='동화 오디오';

-- 회원이 만든 플레이리스트
CREATE TABLE playlists (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_playlists_user (user_id),
    CONSTRAINT fk_playlists_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='플레이리스트';

-- 플레이리스트 항목. 동화마다 읽어 줄 목소리를 지정한다.
CREATE TABLE playlist_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    playlist_id BIGINT UNSIGNED NOT NULL,
    story_id BIGINT UNSIGNED NOT NULL,
    voice_profile_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_playlist_items_playlist (playlist_id, sort_order),
    KEY ix_playlist_items_story (story_id),
    KEY ix_playlist_items_voice (voice_profile_id),
    CONSTRAINT fk_playlist_items_playlist FOREIGN KEY (playlist_id) REFERENCES playlists (id) ON DELETE CASCADE,
    CONSTRAINT fk_playlist_items_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE,
    CONSTRAINT fk_playlist_items_voice FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='플레이리스트 항목';

-- 동화 1회 재생. 질문 횟수 제한(편당 3회)과 이어 듣기 위치를 관리한다.
CREATE TABLE play_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    child_id BIGINT UNSIGNED NULL,
    story_id BIGINT UNSIGNED NOT NULL,
    voice_profile_id BIGINT UNSIGNED NOT NULL,
    question_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_sentence_seq INT UNSIGNED NULL,
    last_position_ms INT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_play_sessions_user (user_id, started_at),
    KEY ix_play_sessions_story (story_id),
    KEY ix_play_sessions_child (child_id),
    KEY ix_play_sessions_voice (voice_profile_id),
    CONSTRAINT fk_play_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_play_sessions_child FOREIGN KEY (child_id) REFERENCES children (id) ON DELETE SET NULL,
    CONSTRAINT fk_play_sessions_story FOREIGN KEY (story_id) REFERENCES stories (id) ON DELETE CASCADE,
    CONSTRAINT fk_play_sessions_voice FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='재생 기록';

-- 아이 질문과 AI 답변(대화형 인터랙션)
CREATE TABLE interactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    play_session_id BIGINT UNSIGNED NOT NULL,
    sentence_seq INT UNSIGNED NULL,
    position_ms INT UNSIGNED NULL,
    question_audio_path VARCHAR(500) NULL,
    question_text TEXT NULL,
    answer_text TEXT NULL,
    llm_input_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    llm_output_tokens INT UNSIGNED NOT NULL DEFAULT 0,
    tts_chars INT UNSIGNED NOT NULL DEFAULT 0,
    cost_krw DECIMAL(10,2) NOT NULL DEFAULT 0,
    latency_ms INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_interactions_session (play_session_id),
    KEY ix_interactions_created (created_at),
    CONSTRAINT fk_interactions_session FOREIGN KEY (play_session_id) REFERENCES play_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='대화형 인터랙션';

-- 외부 API 사용량과 예상 비용(자체 계산). 관리자 비용 대시보드의 원천 데이터이다.
CREATE TABLE api_usage_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(30) NOT NULL COMMENT 'elevenlabs | gemini',
    purpose VARCHAR(30) NOT NULL COMMENT 'voice_clone | story_tts | answer_tts | answer_llm',
    user_id BIGINT UNSIGNED NULL,
    ref_type VARCHAR(30) NULL COMMENT 'voice_profile | story_audio | interaction',
    ref_id BIGINT UNSIGNED NULL,
    unit_type VARCHAR(20) NOT NULL COMMENT 'chars | tokens | requests',
    units INT UNSIGNED NOT NULL DEFAULT 0,
    cost_usd DECIMAL(12,6) NOT NULL DEFAULT 0,
    cost_krw DECIMAL(12,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_api_usage_created (created_at),
    KEY ix_api_usage_provider (provider, created_at),
    KEY ix_api_usage_user (user_id, created_at),
    KEY ix_api_usage_ref (ref_type, ref_id),
    CONSTRAINT fk_api_usage_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='API 사용량 및 비용';

-- ElevenLabs 잔여 크레딧 동기화 기록(1일 1~2회)
CREATE TABLE provider_credit_snapshots (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(30) NOT NULL,
    remaining_units BIGINT NOT NULL,
    limit_units BIGINT NULL,
    internal_estimate BIGINT NULL COMMENT '같은 시점 내부 DB 기준 추정 잔량',
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_credit_snapshots (provider, checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='외부 API 잔여 크레딧';
