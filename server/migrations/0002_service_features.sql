-- 0002 서비스 기능 확장(디자인 시안 기준 회원 화면, 관리자 화면, AI 연동에 필요한 항목)
-- 이 파일은 서버에 적용된 뒤에는 수정하지 않는다. 이후 변경은 0003_ 부터 새 파일로 작성한다.

-- 회원: 환경 설정, 약관 동의, 가입 경로, 관리자 메모
ALTER TABLE users
    ADD COLUMN prefs TEXT NULL COMMENT '환경 설정 JSON(다크 모드, 재생 속도, 알림 등)' AFTER phone,
    ADD COLUMN signup_provider VARCHAR(20) NOT NULL DEFAULT 'email' COMMENT 'email | kakao | google' AFTER prefs,
    ADD COLUMN terms_agreed_at DATETIME NULL AFTER signup_provider,
    ADD COLUMN privacy_agreed_at DATETIME NULL AFTER terms_agreed_at,
    ADD COLUMN marketing_agreed_at DATETIME NULL AFTER privacy_agreed_at,
    ADD COLUMN admin_memo TEXT NULL AFTER marketing_agreed_at;

-- 소셜 로그인 연결(카카오, 구글)
CREATE TABLE user_social_accounts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(20) NOT NULL COMMENT 'kakao | google',
    provider_user_id VARCHAR(191) NOT NULL,
    email VARCHAR(191) NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_social_provider_user (provider, provider_user_id),
    KEY ix_social_user (user_id),
    CONSTRAINT fk_social_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='소셜 로그인 연결';

-- 비밀번호 재설정 토큰(원문은 메일로만 보내고 DB 에는 해시만 둔다)
CREATE TABLE password_resets (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_resets_token (token_hash),
    KEY ix_password_resets_user (user_id),
    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='비밀번호 재설정';

-- 자녀: 생년월일, 성별, 아바타, 정렬
ALTER TABLE children
    ADD COLUMN birth_date DATE NULL AFTER birth_year,
    ADD COLUMN gender VARCHAR(10) NULL COMMENT 'boy | girl' AFTER birth_date,
    ADD COLUMN avatar VARCHAR(30) NULL COMMENT '아바타 프리셋 키' AFTER gender,
    ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER avatar;

-- 관리자: 이메일, 상태
ALTER TABLE admins
    ADD COLUMN email VARCHAR(191) NULL AFTER name,
    ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | disabled' AFTER role;

-- 목소리: 상태 확장(draft | pending | cloning | processing | completed | rejected | failed), 아이콘, 동의, ElevenLabs 파라미터, 사전 생성 상태
ALTER TABLE voice_profiles
    MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | pending | cloning | processing | completed | rejected | failed',
    ADD COLUMN icon VARCHAR(30) NULL COMMENT 'Material Symbols 아이콘 이름' AFTER label,
    ADD COLUMN consent_at DATETIME NULL COMMENT '본인 목소리 복제 동의 시각' AFTER icon,
    ADD COLUMN consent_ip VARCHAR(45) NULL AFTER consent_at,
    ADD COLUMN sample_total_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER consent_ip,
    ADD COLUMN quality_grade VARCHAR(10) NULL COMMENT 'good | fair | poor' AFTER sample_total_ms,
    ADD COLUMN stability DECIMAL(3,2) NULL AFTER provider_voice_id,
    ADD COLUMN similarity_boost DECIMAL(3,2) NULL AFTER stability,
    ADD COLUMN style DECIMAL(3,2) NULL AFTER similarity_boost,
    ADD COLUMN speaker_boost TINYINT(1) NULL AFTER style,
    ADD COLUMN cloned_at DATETIME NULL AFTER processed_by,
    ADD COLUMN batch_status VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none | queued | running | done | partial | failed' AFTER cloned_at,
    ADD COLUMN batch_done_at DATETIME NULL AFTER batch_status,
    ADD COLUMN provider_deleted_at DATETIME NULL AFTER batch_done_at,
    ADD COLUMN deleted_at DATETIME NULL AFTER updated_at;

-- 음성 샘플: 브라우저에서 측정한 품질 지표
ALTER TABLE voice_samples
    ADD COLUMN original_name VARCHAR(191) NULL AFTER file_path,
    ADD COLUMN script_key VARCHAR(30) NULL COMMENT '읽은 대본 번호' AFTER source,
    ADD COLUMN snr_db DECIMAL(5,1) NULL AFTER script_key,
    ADD COLUMN peak_db DECIMAL(5,1) NULL AFTER snr_db,
    ADD COLUMN noise_db DECIMAL(5,1) NULL AFTER peak_db,
    ADD COLUMN clip_count INT UNSIGNED NULL AFTER noise_db,
    ADD COLUMN quality_grade VARCHAR(10) NULL COMMENT 'good | fair | poor' AFTER clip_count;

-- 동화: 분류, 예상 길이, 끼어들기 설정, 내용 해시
ALTER TABLE stories
    ADD COLUMN code VARCHAR(30) NULL COMMENT '관리 코드 예) Fairytale-001' AFTER id,
    ADD COLUMN category VARCHAR(30) NULL COMMENT '취침 전, 모험, 용기, 상상, 우정 등' AFTER summary,
    ADD COLUMN est_duration_sec INT UNSIGNED NULL COMMENT '예상 낭독 시간(초)' AFTER char_count,
    ADD COLUMN barge_in_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER est_duration_sec,
    ADD COLUMN max_questions TINYINT UNSIGNED NULL COMMENT 'NULL 이면 전체 설정(qa.max_questions)' AFTER barge_in_enabled,
    ADD COLUMN vad_min_ms INT UNSIGNED NULL COMMENT 'NULL 이면 전체 설정' AFTER max_questions,
    ADD COLUMN aec_level VARCHAR(10) NULL COMMENT 'normal | strong | max, NULL 이면 전체 설정' AFTER vad_min_ms,
    ADD COLUMN fallback_lines TEXT NULL COMMENT '질문 한도 초과 시 대체 문장 JSON 배열, NULL 이면 전체 설정' AFTER aec_level,
    ADD COLUMN content_hash CHAR(64) NULL COMMENT '문장 목록 해시(오디오 최신 여부 비교)' AFTER fallback_lines,
    ADD UNIQUE KEY uq_stories_code (code),
    ADD KEY ix_stories_category (category);

-- 동화 문장: 맥락 키워드, 기준 타임코드(CMS 표시용)
ALTER TABLE story_sentences
    ADD COLUMN keywords VARCHAR(255) NULL COMMENT '쉼표로 구분한 맥락 키워드' AFTER content,
    ADD COLUMN ref_start_ms INT UNSIGNED NULL AFTER keywords,
    ADD COLUMN ref_end_ms INT UNSIGNED NULL AFTER ref_start_ms;

-- 동화 오디오: 생성 당시 내용 해시, 모델, 파일 크기
ALTER TABLE story_audios
    ADD COLUMN content_hash CHAR(64) NULL AFTER sentence_timings,
    ADD COLUMN model_id VARCHAR(60) NULL AFTER content_hash,
    ADD COLUMN file_size INT UNSIGNED NULL AFTER model_id,
    ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER error_message,
    ADD COLUMN generated_at DATETIME NULL AFTER attempts;

-- 목소리별 짧은 음성(질문 한도 초과 대체 문장, 오류 안내, 미리듣기)
CREATE TABLE voice_clips (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    voice_profile_id BIGINT UNSIGNED NOT NULL,
    kind VARCHAR(20) NOT NULL COMMENT 'fallback | error | preview | test',
    text VARCHAR(500) NOT NULL,
    text_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | completed | failed',
    file_path VARCHAR(500) NULL,
    duration_ms INT UNSIGNED NULL,
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_voice_clips (voice_profile_id, text_hash),
    KEY ix_voice_clips_kind (voice_profile_id, kind),
    CONSTRAINT fk_voice_clips_voice FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='목소리별 짧은 음성';

-- 재생 기록: 기기 음성 재생(목소리 없음) 허용, 들은 시간, 완독 여부
ALTER TABLE play_sessions DROP FOREIGN KEY fk_play_sessions_voice;
ALTER TABLE play_sessions
    MODIFY COLUMN voice_profile_id BIGINT UNSIGNED NULL,
    ADD COLUMN audio_source VARCHAR(20) NOT NULL DEFAULT 'voice' COMMENT 'voice | device' AFTER voice_profile_id,
    ADD COLUMN fallback_count TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '질문 한도 초과로 대체 음성을 낸 횟수' AFTER question_count,
    ADD COLUMN listened_ms INT UNSIGNED NOT NULL DEFAULT 0 AFTER last_position_ms,
    ADD COLUMN completed TINYINT(1) NOT NULL DEFAULT 0 AFTER listened_ms,
    ADD COLUMN completed_at DATETIME NULL AFTER completed,
    ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER ended_at,
    ADD KEY ix_play_sessions_started (started_at),
    ADD CONSTRAINT fk_play_sessions_voice FOREIGN KEY (voice_profile_id) REFERENCES voice_profiles (id) ON DELETE SET NULL;

-- 질문과 답변: 답변 음성, 처리 방식, 단계별 지연, 감정 분류
ALTER TABLE interactions
    ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'answer' COMMENT 'answer | fallback | quota | error | disabled | budget' AFTER position_ms,
    ADD COLUMN answer_audio_path VARCHAR(500) NULL AFTER answer_text,
    ADD COLUMN emotion VARCHAR(30) NULL COMMENT '아이 발화 감정 분류' AFTER answer_audio_path,
    ADD COLUMN llm_ms INT UNSIGNED NULL AFTER latency_ms,
    ADD COLUMN tts_ms INT UNSIGNED NULL AFTER llm_ms,
    ADD COLUMN error_message VARCHAR(500) NULL AFTER tts_ms,
    ADD COLUMN reviewed_at DATETIME NULL AFTER error_message;

-- API 사용량: 모델, 응답 시간, 성공 여부
ALTER TABLE api_usage_logs
    ADD COLUMN model VARCHAR(60) NULL AFTER purpose,
    ADD COLUMN latency_ms INT UNSIGNED NULL AFTER cost_krw,
    ADD COLUMN success TINYINT(1) NOT NULL DEFAULT 1 AFTER latency_ms;

-- 백그라운드 작업 큐(목소리 생성, 동화 오디오 일괄 생성, 목소리 삭제 등)
CREATE TABLE jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    type VARCHAR(40) NOT NULL COMMENT 'voice_clone | story_tts | voice_clips | voice_delete | credit_sync | mail',
    payload TEXT NOT NULL COMMENT 'JSON',
    status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending | running | done | failed | cancelled',
    priority TINYINT NOT NULL DEFAULT 5 COMMENT '작을수록 먼저',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME NULL,
    locked_by VARCHAR(64) NULL,
    ref_type VARCHAR(30) NULL,
    ref_id BIGINT UNSIGNED NULL,
    last_error VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY ix_jobs_pick (status, priority, available_at),
    KEY ix_jobs_ref (ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='백그라운드 작업';

-- 작업 진행 기록(관리자 화면의 처리 콘솔)
CREATE TABLE job_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    job_id BIGINT UNSIGNED NULL,
    ref_type VARCHAR(30) NULL,
    ref_id BIGINT UNSIGNED NULL,
    level VARCHAR(10) NOT NULL DEFAULT 'info' COMMENT 'info | warn | error',
    message VARCHAR(1000) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_job_logs_ref (ref_type, ref_id, id),
    KEY ix_job_logs_job (job_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='작업 진행 기록';

-- 운영 설정(관리자 화면에서 변경, 값은 JSON)
CREATE TABLE settings (
    k VARCHAR(100) NOT NULL,
    v MEDIUMTEXT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='운영 설정';

-- 공지사항
CREATE TABLE notices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'published' COMMENT 'published | draft',
    published_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_notices_list (status, is_pinned, published_at),
    CONSTRAINT fk_notices_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='공지사항';

-- 자주 묻는 질문
CREATE TABLE faqs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    category VARCHAR(30) NULL,
    question VARCHAR(300) NOT NULL,
    answer TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_faqs_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='자주 묻는 질문';

-- 1:1 문의
CREATE TABLE inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    category VARCHAR(30) NOT NULL DEFAULT 'general' COMMENT 'general | voice | playback | account | etc',
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open | answered | closed',
    answer TEXT NULL,
    answered_by INT UNSIGNED NULL,
    answered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_inquiries_user (user_id, created_at),
    KEY ix_inquiries_status (status, created_at),
    CONSTRAINT fk_inquiries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_inquiries_admin FOREIGN KEY (answered_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='1:1 문의';

-- 관리자 감사 로그
CREATE TABLE admin_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id INT UNSIGNED NULL,
    action VARCHAR(50) NOT NULL,
    target_type VARCHAR(30) NULL,
    target_id BIGINT UNSIGNED NULL,
    detail TEXT NULL,
    ip VARCHAR(45) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_audit_created (created_at),
    KEY ix_audit_admin (admin_id, created_at),
    KEY ix_audit_target (target_type, target_id),
    CONSTRAINT fk_audit_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='관리자 감사 로그';

-- 시도 횟수 제한(로그인, 질문 등)
CREATE TABLE rate_limits (
    k VARCHAR(191) NOT NULL,
    hits INT UNSIGNED NOT NULL DEFAULT 0,
    reset_at DATETIME NOT NULL,
    PRIMARY KEY (k),
    KEY ix_rate_limits_reset (reset_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='시도 횟수 제한';
