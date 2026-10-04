<?php
namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Storage;

/**
 * 저장 폴더의 음성, 이미지 파일을 내려준다(Range 지원).
 * 회원 경로(/media/...)는 본인 것만, 관리자 경로(/admin/media/...)는 소유 확인 없이 내려준다.
 * 남의 파일이거나 없으면 존재 여부를 숨기려고 모두 404 로 답한다.
 */
class MediaController
{
    // ───────────────────────── 회원 ─────────────────────────

    /** 내 목소리 샘플 */
    public function sample(string $id): void
    {
        $uid = $this->userId();
        $row = db_one(
            'SELECT s.file_path, s.mime_type FROM voice_samples s
             JOIN voice_profiles vp ON vp.id = s.voice_profile_id
             WHERE s.id = ? AND vp.user_id = ? AND vp.deleted_at IS NULL',
            [(int) $id, $uid]
        );
        $this->send($row ? $row['file_path'] : null, $row ? $this->audioMime($row['mime_type'], $row['file_path']) : null);
    }

    /** 내 목소리로 만든 동화 오디오(삭제한 목소리, 삭제된 동화는 404) */
    public function storyAudio(string $id): void
    {
        $uid = $this->userId();
        $row = db_one(
            'SELECT sa.file_path FROM story_audios sa
             JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
             JOIN stories st ON st.id = sa.story_id
             WHERE sa.id = ? AND vp.user_id = ? AND vp.deleted_at IS NULL AND st.deleted_at IS NULL
               AND sa.status = ? AND sa.file_path IS NOT NULL',
            [(int) $id, $uid, 'completed']
        );
        // 관리자가 같은 동화를 다시 만들면 주소는 같고 파일만 바뀌므로 캐시는 짧게 두고 ETag 로 다시 확인한다.
        $this->send($row ? $row['file_path'] : null, null, 3600);
    }

    /** 내 목소리의 짧은 음성(대체 문장, 오류 안내, 미리듣기) */
    public function clip(string $id): void
    {
        $uid = $this->userId();
        $row = db_one(
            'SELECT c.file_path FROM voice_clips c
             JOIN voice_profiles vp ON vp.id = c.voice_profile_id
             WHERE c.id = ? AND vp.user_id = ? AND vp.deleted_at IS NULL AND c.status = ? AND c.file_path IS NOT NULL',
            [(int) $id, $uid, 'completed']
        );
        $this->send($row ? $row['file_path'] : null, null, 3600);
    }

    /** 아이 질문 녹음(내 재생 기록의 것만) */
    public function question(string $interactionId): void
    {
        $row = $this->userInteraction((int) $interactionId);
        $this->send($row ? $row['question_audio_path'] : null);
    }

    /** 질문에 대한 답변 음성(내 재생 기록의 것만) */
    public function answer(string $interactionId): void
    {
        $row = $this->userInteraction((int) $interactionId);
        $this->send($row ? $row['answer_audio_path'] : null);
    }

    /** 업로드한 동화 표지(공개). 'storage:' 로 저장된 표지만 내려준다. */
    public function cover(string $storyId): void
    {
        $path = (string) db_value('SELECT cover_image_path FROM stories WHERE id = ? AND deleted_at IS NULL', [(int) $storyId]);
        if (strpos($path, 'storage:') !== 0) {
            $this->notFound();
        }
        $rel = substr($path, 8);
        $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
        if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'gif', 'svg'], true)) {
            $this->notFound();
        }
        $this->releaseSession();
        // SVG 안의 스크립트가 실행되지 않게 막는다.
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox");
        $this->send($rel, Storage::mimeFor($rel), 2592000);
    }

    // ───────────────────────── 관리자 ─────────────────────────

    public function adminSample(string $id): void
    {
        require_admin();
        $row = db_one('SELECT file_path, mime_type, original_name FROM voice_samples WHERE id = ?', [(int) $id]);
        $download = $row && input('download') ? $this->downloadName('sample-' . (int) $id, $row['file_path']) : null;
        $this->send($row ? $row['file_path'] : null, $row ? $this->audioMime($row['mime_type'], $row['file_path']) : null, 3600, $download);
    }

    public function adminStoryAudio(string $id): void
    {
        require_admin();
        $row = db_one('SELECT file_path FROM story_audios WHERE id = ? AND file_path IS NOT NULL', [(int) $id]);
        $download = $row && input('download') ? $this->downloadName('story-audio-' . (int) $id, $row['file_path']) : null;
        $this->send($row ? $row['file_path'] : null, null, 3600, $download);
    }

    public function adminClip(string $id): void
    {
        require_admin();
        $row = db_one('SELECT file_path FROM voice_clips WHERE id = ? AND file_path IS NOT NULL', [(int) $id]);
        // 테스트 재생은 같은 주소로 다시 만들 수 있으므로 캐시하지 않는다.
        $this->send($row ? $row['file_path'] : null, null, 0);
    }

    public function adminQuestion(string $interactionId): void
    {
        require_admin();
        $path = db_value('SELECT question_audio_path FROM interactions WHERE id = ?', [(int) $interactionId]);
        $this->send($path !== null ? (string) $path : null, null, 3600);
    }

    public function adminAnswer(string $interactionId): void
    {
        require_admin();
        $path = db_value('SELECT answer_audio_path FROM interactions WHERE id = ?', [(int) $interactionId]);
        $this->send($path !== null ? (string) $path : null, null, 3600);
    }

    // ───────────────────────── 내부 ─────────────────────────

    /** 로그인한 회원 id. 미디어 요청은 화면 이동 대신 404 로 답한다(오디오 태그가 로그인 화면을 받지 않도록). */
    private function userId(): int
    {
        $id = Auth::id();
        if (!$id || !Auth::user()) {
            $this->notFound();
        }

        return (int) $id;
    }

    private function userInteraction(int $id): ?array
    {
        $uid = $this->userId();

        return db_one(
            'SELECT i.question_audio_path, i.answer_audio_path FROM interactions i
             JOIN play_sessions ps ON ps.id = i.play_session_id
             WHERE i.id = ? AND ps.user_id = ?',
            [$id, $uid]
        );
    }

    /** 파일을 Range 지원으로 내려보내고 끝낸다. */
    private function send(?string $relative, ?string $mime = null, int $maxAge = 86400, ?string $downloadName = null): void
    {
        $relative = $relative === null ? '' : trim($relative);
        if ($relative === '' || strpos($relative, '..') !== false || !Storage::exists($relative)) {
            $this->notFound();
        }
        $this->releaseSession();
        Storage::stream($relative, $mime, $downloadName, $maxAge);
    }

    /** 오디오를 받는 동안 세션 파일 잠금을 풀어 같은 회원의 다른 요청이 기다리지 않게 한다. */
    private function releaseSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    /** 브라우저 녹음은 audio/webm;codecs=opus 처럼 들어오므로 오디오 MIME 만 쓰고, 아니면 확장자로 정한다. */
    private function audioMime(?string $mime, string $path): string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));
        if (preg_match('#^audio/[a-z0-9.+-]+$#', $mime)) {
            return $mime;
        }

        return Storage::mimeFor($path);
    }

    private function downloadName(string $base, string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $base . ($ext !== '' ? '.' . $ext : '');
    }

    private function notFound(): void
    {
        throw new HttpException(404, '파일을 찾을 수 없습니다.');
    }
}
