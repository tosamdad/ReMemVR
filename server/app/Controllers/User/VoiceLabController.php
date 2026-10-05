<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Storage;
use App\Services\VoiceService;

/**
 * 목소리 연구실: 가족 목소리를 만들고(초안), 대본을 녹음하거나 파일을 올리고, 제출한 뒤 생성 과정을 지켜본다.
 * 모든 조회는 voice_profiles.user_id = 로그인 회원, deleted_at IS NULL 조건으로 본인 것만 다룬다.
 */
class VoiceLabController
{
    /** 누구 목소리인지 고르는 칩과 기본 아이콘 */
    const PRESETS = [
        '엄마' => 'face_3',
        '아빠' => 'face_6',
        '할머니' => 'elderly_woman',
        '할아버지' => 'elderly',
        '이모' => 'face_2',
        '삼촌' => 'face_4',
    ];

    /** 고를 수 있는 아이콘(Material Symbols) */
    const ICONS = ['face_3', 'face_6', 'elderly_woman', 'elderly', 'face_2', 'face_4', 'face_5', 'sentiment_satisfied'];

    /** 업로드 허용 확장자 */
    const AUDIO_EXT = ['wav', 'mp3', 'm4a', 'mp4', 'aac', 'ogg', 'webm', 'flac'];

    const MAX_UPLOAD_BYTES = 20971520; // 20MB
    const MIN_SAMPLE_MS = 3000;        // 이보다 짧은 녹음은 목소리 학습에 쓸 수 없다
    const MAX_SAMPLES = 30;            // 목소리 하나에 올릴 수 있는 샘플 수
    const LABEL_MAX = 20;

    /** 녹음, 샘플 추가가 가능한 상태 */
    const EDITABLE = ['draft', 'rejected'];

    /** 생성이 진행 중인 상태(화면이 주기적으로 상태를 다시 읽는다) */
    const WORKING = ['cloning', 'processing'];

    // ───────────────────────── 화면 ─────────────────────────

    /** GET /voice-lab */
    public function index(): string
    {
        $user = require_user();
        $voices = $this->userVoices((int) $user['id']);
        $max = $this->maxVoices();
        foreach ($voices as $i => $v) {
            $voices[$i] = $this->decorate($v);
        }

        return view('user/voice_lab/index', [
            'voices' => $voices,
            'max' => $max,
            'used' => count($voices),
            'canCreate' => count($voices) < $max,
        ]);
    }

    /** GET /voice-lab/new */
    public function create(): string
    {
        $user = require_user();
        $used = count($this->userVoices((int) $user['id']));
        $max = $this->maxVoices();

        return view('user/voice_lab/new', [
            'presets' => self::PRESETS,
            'icons' => self::ICONS,
            'max' => $max,
            'used' => $used,
            'canCreate' => $used < $max,
            'labelMax' => self::LABEL_MAX,
        ]);
    }

    /** POST /voice-lab: 초안 목소리를 만들고 녹음 화면으로 보낸다. */
    public function store(): void
    {
        $user = require_user();
        $uid = (int) $user['id'];
        if (count($this->userVoices($uid)) >= $this->maxVoices()) {
            flash('error', '목소리는 최대 ' . $this->maxVoices() . '개까지 만들 수 있어요. 쓰지 않는 목소리를 삭제한 뒤 다시 시도해 주세요.');
            redirect('/voice-lab');
        }

        $who = Request::str('who');
        $custom = self::cleanLabel(Request::str('custom_label'));
        $icon = Request::str('icon');
        $errors = [];
        $label = '';
        if ($who === 'custom') {
            $label = $custom;
            if ($label === '') {
                $errors['custom_label'] = '누구의 목소리인지 입력해 주세요.';
            } elseif (mb_strlen($label) > self::LABEL_MAX) {
                $errors['custom_label'] = '이름은 ' . self::LABEL_MAX . '자 이하로 입력해 주세요.';
            }
        } elseif (isset(self::PRESETS[$who])) {
            $label = $who;
        } else {
            $errors['who'] = '누구의 목소리인지 골라 주세요.';
        }
        if ($label !== '' && !$errors && $this->labelTaken($uid, $label)) {
            $errors[$who === 'custom' ? 'custom_label' : 'who'] = '이미 "' . $label . ' 목소리"가 있어요. 다른 이름으로 만들어 주세요.';
        }
        if (!in_array($icon, self::ICONS, true)) {
            $icon = isset(self::PRESETS[$label]) ? self::PRESETS[$label] : 'face_5';
        }
        if ($errors) {
            back_with_errors($errors, '/voice-lab/new');
        }

        $id = db_insert('voice_profiles', [
            'user_id' => $uid,
            'label' => $label,
            'icon' => $icon,
            'status' => 'draft',
        ]);
        flash('success', $label . ' 목소리를 만들었어요. 이제 대본을 읽어 녹음해 볼까요?');
        redirect('/voice-lab/' . $id . '/record');
    }

    /** GET /voice-lab/{id}/record: 안내된 녹음 화면(초안, 반려 상태만) */
    public function record(string $id): string
    {
        $user = require_user();
        $voice = $this->profile((int) $id);
        if (!in_array($voice['status'], self::EDITABLE, true)) {
            flash('info', '이미 제출한 목소리예요. 진행 상황을 확인해 주세요.');
            redirect('/voice-lab/' . (int) $voice['id']);
        }
        $samples = $this->samples((int) $voice['id']);
        $scripts = self::scripts();

        return view('user/voice_lab/record', [
            'voice' => $voice,
            'scripts' => $scripts,
            'samples' => $samples,
            'minSec' => (int) setting('voice.min_sample_seconds', 60),
            'recSec' => (int) setting('voice.recommended_sample_seconds', 120),
            'maxSec' => (int) setting('voice.max_sample_seconds', 300),
            'maxUpload' => self::uploadLimit(),
            'user' => $user,
            'auto' => VoiceService::autoClone(),
        ]);
    }

    /** GET /voice-lab/{id}: 진행 상태, 준비된 동화, 샘플, 이름 바꾸기, 삭제 */
    public function show(string $id): string
    {
        require_user();
        $voice = $this->decorate($this->profile((int) $id));
        $pid = (int) $voice['id'];
        $stories = db_all(
            'SELECT s.id, s.title, s.category, s.cover_image_path, s.updated_at, s.est_duration_sec, sa.duration_ms
             FROM story_audios sa
             JOIN stories s ON s.id = sa.story_id
             WHERE sa.voice_profile_id = ? AND sa.status = ? AND sa.file_path IS NOT NULL
               AND s.status = ? AND s.deleted_at IS NULL
             ORDER BY s.sort_order, s.id',
            [$pid, 'completed', 'published']
        );
        $progress = null;

        return view('user/voice_lab/show', [
            'voice' => $voice,
            'samples' => $this->samples($pid),
            'stories' => $stories,
            'progress' => $progress,
            'timeline' => self::timeline($voice),
            'labelMax' => self::LABEL_MAX,
        ]);
    }

    // ───────────────────────── 샘플 업로드(JSON) ─────────────────────────

    /** POST /api/voice-lab/{id}/samples (multipart: audio, duration_ms, metrics, source, script_key) */
    public function uploadSample(string $id): array
    {
        $user = require_user();
        $uid = (int) $user['id'];
        $voice = $this->profile((int) $id);
        $pid = (int) $voice['id'];
        if (!in_array($voice['status'], self::EDITABLE, true)) {
            abort(409, '이미 제출한 목소리에는 녹음을 더할 수 없어요.');
        }
        if (!RateLimiter::hit('voice-sample:' . $uid, 60, 3600)) {
            abort(429, '잠시 너무 많이 올렸어요. 조금 뒤에 다시 시도해 주세요.');
        }

        $file = Request::file('audio');
        if ($file === null) {
            $err = Request::uploadError('audio');
            abort(422, $err !== null ? $err : '녹음 파일이 전송되지 않았어요. 다시 시도해 주세요.');
        }
        $size = (int) $file['size'];
        if ($size > self::MAX_UPLOAD_BYTES) {
            abort(422, '파일이 너무 커요. 20MB 이하 파일만 올릴 수 있어요.');
        }
        if ($size < 1024) {
            abort(422, '파일이 비어 있거나 너무 짧아요.');
        }
        $ext = self::audioExt((string) $file['name'], (string) $file['type']);
        if ($ext === null) {
            abort(422, '지원하지 않는 파일 형식이에요. (wav, mp3, m4a, aac, ogg, webm, flac)');
        }
        if (!self::looksLikeAudio((string) $file['tmp_name'])) {
            abort(422, '오디오 파일이 아니거나 손상된 파일이에요.');
        }

        $count = (int) db_value('SELECT COUNT(*) FROM voice_samples WHERE voice_profile_id = ?', [$pid]);
        if ($count >= self::MAX_SAMPLES) {
            abort(422, '녹음은 목소리 하나에 ' . self::MAX_SAMPLES . '개까지 저장할 수 있어요. 필요 없는 녹음을 지운 뒤 다시 시도해 주세요.');
        }

        // 길이: WAV, M4A 는 서버에서 직접 계산하고, 그 밖에는 브라우저가 잰 값을 쓴다(모르면 NULL).
        $durationMs = 0;
        if ($ext === 'wav') {
            $durationMs = self::wavDurationMs((string) $file['tmp_name']);
        } elseif ($ext === 'm4a' || $ext === 'mp4') {
            $durationMs = self::mp4DurationMs((string) $file['tmp_name']);
        }
        if ($durationMs <= 0) {
            $clientMs = Request::int('duration_ms');
            $durationMs = ($clientMs > 0 && $clientMs <= 3600000) ? $clientMs : 0;
        }
        if ($durationMs > 0 && $durationMs < self::MIN_SAMPLE_MS) {
            abort(422, '녹음이 너무 짧아요. 3초 이상 녹음해 주세요.');
        }
        $maxSec = (int) setting('voice.max_sample_seconds', 300);
        $currentMs = (int) db_value('SELECT COALESCE(SUM(duration_ms), 0) FROM voice_samples WHERE voice_profile_id = ?', [$pid]);
        if ($maxSec > 0 && $durationMs > 0 && $currentMs + $durationMs > $maxSec * 1000) {
            abort(422, '녹음은 모두 합쳐 최대 ' . self::secText($maxSec) . '까지 저장할 수 있어요. 필요 없는 녹음을 지운 뒤 다시 시도해 주세요.');
        }

        $source = Request::str('source') === 'record' ? 'record' : 'upload';
        $scriptKey = Request::str('script_key');
        if (!isset(self::scripts()[$scriptKey])) {
            $scriptKey = null;
        }
        $m = self::cleanMetrics(Request::str('metrics'));
        $original = self::cleanLabel(str_replace(['/', '\\'], '_', (string) $file['name']));
        if ($original === '' || $original === 'blob') {
            $original = $source === 'record' ? '녹음.' . $ext : '업로드.' . $ext;
        }

        $rel = 'voice-samples/' . $uid . '/' . $pid . '/' . Storage::randomName($ext);
        Storage::putUploaded((string) $file['tmp_name'], $rel);
        try {
            $sampleId = db_insert('voice_samples', [
                'voice_profile_id' => $pid,
                'file_path' => $rel,
                'original_name' => mb_substr($original, 0, 191),
                'mime_type' => Storage::mimeFor($rel),
                'file_size' => $size,
                'duration_ms' => $durationMs > 0 ? $durationMs : null,
                'source' => $source,
                'script_key' => $scriptKey,
                'snr_db' => $m['snr_db'],
                'peak_db' => $m['peak_db'],
                'noise_db' => $m['noise_db'],
                'clip_count' => $m['clip_count'],
                'quality_grade' => $m['quality_grade'],
            ]);
        } catch (\Throwable $e) {
            Storage::delete($rel);
            throw $e;
        }
        $totalMs = $this->updateTotal($pid);
        $row = db_one('SELECT * FROM voice_samples WHERE id = ?', [$sampleId]);

        return [
            'ok' => true,
            'message' => '녹음을 저장했어요.',
            'sample' => self::sampleJson($row),
            'total_ms' => $totalMs,
            'unknown_count' => $this->unknownCount($pid),
        ];
    }

    /** POST /api/voice-lab/{id}/samples/{sampleId}/delete */
    public function deleteSample(string $id, string $sampleId): array
    {
        require_user();
        $voice = $this->profile((int) $id);
        $pid = (int) $voice['id'];
        if (!in_array($voice['status'], self::EDITABLE, true)) {
            abort(409, '이미 제출한 목소리의 녹음은 지울 수 없어요.');
        }
        $sample = db_one('SELECT * FROM voice_samples WHERE id = ? AND voice_profile_id = ?', [(int) $sampleId, $pid]);
        if (!$sample) {
            abort(404, '녹음을 찾을 수 없어요.');
        }
        db_exec('DELETE FROM voice_samples WHERE id = ? AND voice_profile_id = ?', [(int) $sample['id'], $pid]);
        Storage::delete((string) $sample['file_path']);
        $totalMs = $this->updateTotal($pid);

        return [
            'ok' => true,
            'message' => '녹음을 지웠어요.',
            'id' => (int) $sample['id'],
            'total_ms' => $totalMs,
            'unknown_count' => $this->unknownCount($pid),
        ];
    }

    // ───────────────────────── 제출, 이름, 삭제(폼) ─────────────────────────

    /** POST /voice-lab/{id}/submit: 동의를 기록하고 제출한다(자동 생성이 켜져 있으면 바로 목소리 만들기 시작). */
    public function submit(string $id): void
    {
        require_user();
        $voice = $this->profile((int) $id);
        $pid = (int) $voice['id'];
        $back = '/voice-lab/' . $pid . '/record?step=3';
        if (!in_array($voice['status'], self::EDITABLE, true)) {
            flash('info', '이미 제출한 목소리예요.');
            redirect('/voice-lab/' . $pid);
        }
        if ((string) input('consent', '') !== '1') {
            back_with_errors(['consent' => '목소리 사용에 동의해 주셔야 제출할 수 있어요.'], $back);
        }
        $totalMs = $this->updateTotal($pid);
        $count = (int) db_value('SELECT COUNT(*) FROM voice_samples WHERE voice_profile_id = ?', [$pid]);
        $minSec = (int) setting('voice.min_sample_seconds', 60);
        if ($count === 0) {
            back_with_errors(['consent' => '녹음된 목소리가 없어요. 먼저 대본을 녹음해 주세요.'], $back);
        }
        if ($this->unknownCount($pid) === 0 && $totalMs < $minSec * 1000) {
            back_with_errors(['consent' => '최소 ' . self::secText($minSec) . ' 이상 녹음해 주세요. 지금 ' . self::secText((int) floor($totalMs / 1000)) . '이에요.'], $back);
        }

        db_update('voice_profiles', ['consent_at' => now(), 'consent_ip' => client_ip()], 'id = ? AND user_id = ?', [$pid, Auth::id()]);
        try {
            VoiceService::submit($pid);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect($back);
        }
        $now = (string) db_value('SELECT status FROM voice_profiles WHERE id = ?', [$pid]);
        flash('success', $now === 'cloning'
            ? '녹음 완료! 지금 AI가 목소리를 만들고 있어요. 잠시 뒤 준비되면 바로 동화를 고를 수 있어요.'
            : '목소리를 제출했어요! 확인이 끝나면 바로 목소리 만들기를 시작할게요.');
        redirect('/voice-lab/' . $pid);
    }

    /** POST /voice-lab/{id}/rename */
    public function rename(string $id): void
    {
        $user = require_user();
        $voice = $this->profile((int) $id);
        $pid = (int) $voice['id'];
        $label = self::cleanLabel(Request::str('label'));
        if ($label === '') {
            back_with_errors(['label' => '이름을 입력해 주세요.'], '/voice-lab/' . $pid);
        }
        if (mb_strlen($label) > self::LABEL_MAX) {
            back_with_errors(['label' => '이름은 ' . self::LABEL_MAX . '자 이하로 입력해 주세요.'], '/voice-lab/' . $pid);
        }
        if ($label !== $voice['label'] && $this->labelTaken((int) $user['id'], $label, $pid)) {
            back_with_errors(['label' => '이미 "' . $label . ' 목소리"가 있어요.'], '/voice-lab/' . $pid);
        }
        db_update('voice_profiles', ['label' => $label], 'id = ? AND user_id = ?', [$pid, (int) $user['id']]);
        flash('success', '이름을 "' . $label . ' 목소리"로 바꿨어요.');
        redirect('/voice-lab/' . $pid);
    }

    /** POST /voice-lab/{id}/delete */
    public function destroy(string $id): void
    {
        require_user();
        $voice = $this->profile((int) $id);
        VoiceService::delete((int) $voice['id']);
        flash('success', $voice['label'] . ' 목소리를 삭제했어요.');
        redirect('/voice-lab');
    }

    // ───────────────────────── 상태 확인(JSON) ─────────────────────────

    /** GET /api/voice-lab/status: 목록, 상세 화면이 10초마다 부른다. */
    public function status(): array
    {
        $user = require_user();
        $out = [];
        foreach ($this->userVoices((int) $user['id']) as $v) {
            $status = (string) $v['status'];
            $p = in_array($status, ['cloning', 'processing', 'completed', 'failed'], true) ? VoiceService::progress((int) $v['id']) : null;
            $out[] = [
                'id' => (int) $v['id'],
                'status' => $status,
                'status_label' => voice_status_label($status),
                'percent' => $p ? (int) $p['percent'] : 0,
                'completed' => $p ? (int) $p['completed'] : 0,
                'total' => $p ? (int) $p['total'] : 0,
            ];
        }

        return ['ok' => true, 'voices' => $out];
    }

    // ───────────────────────── 내부 도우미 ─────────────────────────

    /** 대본 목록(server/app/Data/recording_scripts.php) */
    public static function scripts(): array
    {
        static $scripts = null;
        if ($scripts === null) {
            $scripts = require APP_ROOT . '/app/Data/recording_scripts.php';
        }

        return $scripts;
    }

    /** 로그인 회원의 목소리 하나. 남의 것이거나 삭제되었으면 404 */
    private function profile(int $id): array
    {
        $row = db_one(
            'SELECT * FROM voice_profiles WHERE id = ? AND user_id = ? AND deleted_at IS NULL',
            [$id, (int) Auth::id()]
        );
        if (!$row) {
            abort(404, '목소리를 찾을 수 없어요.');
        }

        return $row;
    }

    private function userVoices(int $uid): array
    {
        return db_all('SELECT * FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL ORDER BY id', [$uid]);
    }

    private function maxVoices(): int
    {
        return max(1, (int) setting('voice.max_per_user', 5));
    }

    private function labelTaken(int $uid, string $label, int $exceptId = 0): bool
    {
        return (int) db_value(
            'SELECT COUNT(*) FROM voice_profiles WHERE user_id = ? AND label = ? AND id <> ? AND deleted_at IS NULL',
            [$uid, $label, $exceptId]
        ) > 0;
    }

    /** 목록 카드, 상세 화면에 필요한 값(진행률, 들어 볼 음성 주소)을 더한다. */
    private function decorate(array $v): array
    {
        $pid = (int) $v['id'];
        $v['progress'] = in_array($v['status'], self::WORKING, true) ? VoiceService::progress($pid) : null;
        $v['play_url'] = null;
        if ($v['status'] === 'completed' || $v['status'] === 'processing') {
            $clipId = db_value(
                'SELECT id FROM voice_clips WHERE voice_profile_id = ? AND kind = ? AND status = ? AND file_path IS NOT NULL ORDER BY id DESC LIMIT 1',
                [$pid, 'preview', 'completed']
            );
            if ($clipId) {
                $v['play_url'] = url('/media/clip/' . (int) $clipId);
            }
        }
        if ($v['play_url'] === null) {
            $sampleId = db_value('SELECT id FROM voice_samples WHERE voice_profile_id = ? ORDER BY id LIMIT 1', [$pid]);
            if ($sampleId) {
                $v['play_url'] = url('/media/sample/' . (int) $sampleId);
            }
        }

        return $v;
    }

    private function samples(int $pid): array
    {
        $out = [];
        foreach (db_all('SELECT * FROM voice_samples WHERE voice_profile_id = ? ORDER BY id', [$pid]) as $row) {
            $out[] = self::sampleJson($row);
        }

        return $out;
    }

    /** 샘플 길이 합계를 다시 계산해 저장한다. */
    private function updateTotal(int $pid): int
    {
        $total = (int) db_value('SELECT COALESCE(SUM(duration_ms), 0) FROM voice_samples WHERE voice_profile_id = ?', [$pid]);
        db_exec('UPDATE voice_profiles SET sample_total_ms = ? WHERE id = ?', [$total, $pid]);

        return $total;
    }

    /** 길이를 모르는 샘플 수(브라우저가 해석하지 못한 업로드 파일) */
    private function unknownCount(int $pid): int
    {
        return (int) db_value('SELECT COUNT(*) FROM voice_samples WHERE voice_profile_id = ? AND (duration_ms IS NULL OR duration_ms = 0)', [$pid]);
    }

    /** 화면과 JSON 에 쓰는 샘플 정보 */
    public static function sampleJson(array $row): array
    {
        $scripts = self::scripts();
        $key = (string) $row['script_key'];
        $title = isset($scripts[$key]) ? '대본 ' . $scripts[$key]['no'] . ' · ' . $scripts[$key]['title'] : (string) $row['original_name'];
        if ($title === '') {
            $title = $row['source'] === 'record' ? '녹음' : '업로드한 파일';
        }

        return [
            'id' => (int) $row['id'],
            'url' => url('/media/sample/' . (int) $row['id']),
            'title' => $title,
            'source' => (string) $row['source'],
            'script_key' => $key !== '' ? $key : null,
            'duration_ms' => $row['duration_ms'] !== null ? (int) $row['duration_ms'] : null,
            'grade' => in_array((string) $row['quality_grade'], ['good', 'fair', 'poor'], true) ? (string) $row['quality_grade'] : null,
            'snr_db' => $row['snr_db'] !== null ? (float) $row['snr_db'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * 상태 단계: 녹음 완료 → 검토 → 목소리 생성 → 동화 준비 → 완료.
     * 각 단계 state: done | current | error | todo
     */
    public static function timeline(array $voice, ?bool $review = null): array
    {
        $status = (string) $voice['status'];
        // 자동 생성이면 검토 단계가 없다. 다만 관리자 검토 중이거나 반려된 목소리는 검토 단계를 보여 준다.
        if ($review === null) {
            $review = !VoiceService::autoClone() || in_array($status, ['pending', 'rejected'], true);
        }
        $steps = [['key' => 'record', 'label' => '녹음 완료', 'icon' => 'mic']];
        if ($review) {
            $steps[] = ['key' => 'review', 'label' => '검토', 'icon' => 'fact_check'];
        }
        $steps[] = ['key' => 'clone', 'label' => '목소리 생성', 'icon' => 'graphic_eq'];
        $steps[] = ['key' => 'done', 'label' => '준비 완료', 'icon' => 'celebration'];
        $map = ['draft' => 0, 'pending' => 1, 'rejected' => 1, 'cloning' => 2, 'processing' => 3, 'completed' => 3, 'failed' => 2];
        $current = isset($map[$status]) ? $map[$status] : 0;
        if (!$review && $current > 0) {
            $current--;
        }
        foreach ($steps as $i => $s) {
            if ($i < $current || (in_array($status, ['completed', 'processing'], true) && $i === $current)) {
                $state = 'done';
            } elseif ($i === $current) {
                $state = in_array($status, ['rejected', 'failed'], true) ? 'error' : 'current';
            } else {
                $state = 'todo';
            }
            $steps[$i]['state'] = $state;
        }

        return $steps;
    }

    /** 라벨 정리: 제어 문자, 꺾쇠를 없애고 공백을 하나로 */
    public static function cleanLabel(string $s): string
    {
        $s = preg_replace('/[\x00-\x1F\x7F<>]/u', '', $s);
        $s = preg_replace('/\s+/u', ' ', (string) $s);

        return trim((string) $s);
    }

    /** 원래 파일 이름의 확장자(없거나 허용 밖이면 MIME 으로 추정). 허용 밖이면 null */
    public static function audioExt(string $name, string $mime): ?string
    {
        $ext = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, self::AUDIO_EXT, true)) {
            return $ext;
        }
        if ($ext === '' || $name === 'blob') {
            $guess = Storage::extForMime($mime, '');
            if (in_array($guess, self::AUDIO_EXT, true)) {
                return $guess;
            }
        }

        return null;
    }

    /** 파일 앞부분으로 오디오 형식인지 확인한다(확장자만 바꾼 다른 파일을 막는다). */
    public static function looksLikeAudio(string $path): bool
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return false;
        }
        $head = (string) fread($fp, 4096);
        fclose($fp);
        if (strlen($head) < 12) {
            return false;
        }
        $four = substr($head, 0, 4);
        if (($four === 'RIFF' && substr($head, 8, 4) === 'WAVE') || $four === 'OggS' || $four === 'fLaC'
            || $four === "\x1A\x45\xDF\xA3" || substr($head, 0, 3) === 'ID3' || substr($head, 4, 4) === 'ftyp') {
            return true;
        }
        // 흔한 다른 형식(JPEG, PNG, PDF, ZIP, GIF, 실행 파일, HTML/PHP)은 바로 거른다.
        foreach (["\xFF\xD8\xFF", "\x89PNG", '%PDF', "PK\x03\x04", 'GIF8', 'MZ', '<'] as $magic) {
            if (strncmp(ltrim(substr($head, 0, 64)), $magic, strlen($magic)) === 0) {
                return false;
            }
        }
        // 머리글 없는 MP3 프레임 또는 AAC(ADTS) 동기 신호
        $len = strlen($head) - 2;
        for ($i = 0; $i < $len; $i++) {
            if ($head[$i] !== "\xFF") {
                continue;
            }
            $b1 = ord($head[$i + 1]);
            if (($b1 & 0xE0) !== 0xE0) {
                continue;
            }
            if (($b1 & 0xF6) === 0xF0) {
                return true; // ADTS: 1111 x 00 x
            }
            $version = ($b1 >> 3) & 3;
            $layer = ($b1 >> 1) & 3;
            $b2 = ord($head[$i + 2]);
            if ($version !== 1 && $layer !== 0 && ($b2 >> 4) !== 15 && (($b2 >> 2) & 3) !== 3) {
                return true;
            }
        }

        return false;
    }

    /** WAV 머리글에서 길이(ms)를 계산한다. 해석할 수 없으면 0 */
    public static function wavDurationMs(string $path): int
    {
        $fp = @fopen($path, 'rb');
        if (!$fp) {
            return 0;
        }
        $head = (string) fread($fp, 12);
        if (strlen($head) < 12 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
            fclose($fp);

            return 0;
        }
        $byteRate = 0;
        $dataSize = 0;
        $fileSize = (int) @filesize($path);
        for ($guard = 0; $guard < 64; $guard++) {
            $chunk = (string) fread($fp, 8);
            if (strlen($chunk) < 8) {
                break;
            }
            $id = substr($chunk, 0, 4);
            $info = unpack('Vsize', substr($chunk, 4, 4));
            $size = $info ? (int) $info['size'] : 0;
            if ($id === 'fmt ') {
                $fmt = (string) fread($fp, $size);
                if (strlen($fmt) >= 12) {
                    $br = unpack('VbyteRate', substr($fmt, 8, 4));
                    $byteRate = $br ? (int) $br['byteRate'] : 0;
                }
                if ($size % 2 === 1) {
                    fseek($fp, 1, SEEK_CUR);
                }
                continue;
            }
            if ($id === 'data') {
                // 녹음 중 끊긴 파일은 머리글 크기가 실제보다 클 수 있어 남은 파일 크기로 자른다.
                $dataSize = min($size, max(0, $fileSize - (int) ftell($fp)));
                break;
            }
            fseek($fp, $size + ($size % 2), SEEK_CUR);
        }
        fclose($fp);

        return $byteRate > 0 && $dataSize > 0 ? (int) round($dataSize * 1000 / $byteRate) : 0;
    }

    /** M4A, MP4 의 mvhd 상자에서 길이(ms)를 읽는다. 찾지 못하면 0 */
    public static function mp4DurationMs(string $path): int
    {
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || substr($bytes, 4, 4) !== 'ftyp') {
            return 0;
        }
        $pos = strpos($bytes, 'mvhd');
        if ($pos === false || strlen($bytes) < $pos + 32) {
            return 0;
        }
        $version = ord($bytes[$pos + 4]);
        if ($version === 1) {
            $scale = unpack('N', substr($bytes, $pos + 24, 4));
            $hi = unpack('N', substr($bytes, $pos + 28, 4));
            $lo = unpack('N', substr($bytes, $pos + 32, 4));
            $duration = $hi && $lo ? $hi[1] * 4294967296 + $lo[1] : 0;
        } else {
            $scale = unpack('N', substr($bytes, $pos + 16, 4));
            $d = unpack('N', substr($bytes, $pos + 20, 4));
            $duration = $d ? $d[1] : 0;
        }
        $timescale = $scale ? (int) $scale[1] : 0;
        if ($timescale <= 0 || $duration <= 0) {
            return 0;
        }
        $ms = (int) round($duration * 1000 / $timescale);

        return $ms > 0 && $ms <= 3600000 ? $ms : 0;
    }

    /** 브라우저가 보낸 품질 지표(JSON)를 DB 열 범위에 맞게 정리한다. */
    public static function cleanMetrics(string $json): array
    {
        $m = json_decode_array($json);
        $num = static function ($key) use ($m) {
            if (!isset($m[$key]) || !is_numeric($m[$key])) {
                return null;
            }

            return round(max(-999.9, min(999.9, (float) $m[$key])), 1);
        };
        $grade = isset($m['grade']) && in_array($m['grade'], ['good', 'fair', 'poor'], true) ? $m['grade'] : null;

        return [
            'snr_db' => $num('snrDb'),
            'peak_db' => $num('peakDb'),
            'noise_db' => $num('noiseDb'),
            'clip_count' => isset($m['clipCount']) && is_numeric($m['clipCount']) ? max(0, min(4294967295, (int) $m['clipCount'])) : null,
            'quality_grade' => $grade,
        ];
    }

    /**
     * 한 번에 올릴 수 있는 파일 크기(바이트). 20MB 와 서버 PHP 한도(upload_max_filesize, post_max_size) 중 작은 값.
     * 녹음 화면은 이보다 큰 녹음을 여러 조각(WAV)으로 나눠 올린다.
     */
    public static function uploadLimit(): int
    {
        $limit = self::MAX_UPLOAD_BYTES;
        $upload = self::iniBytes((string) ini_get('upload_max_filesize'));
        if ($upload > 0) {
            $limit = min($limit, $upload);
        }
        $post = self::iniBytes((string) ini_get('post_max_size'));
        if ($post > 0) {
            // 폼 필드와 멀티파트 머리글 몫을 남긴다.
            $limit = min($limit, $post - 65536);
        }

        return max(262144, $limit);
    }

    /** php.ini 크기 표기("2M", "512K", "1G")를 바이트로 */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $n = (float) $value;
        $unit = strtolower(substr($value, -1));
        if ($unit === 'g') {
            $n *= 1073741824;
        } elseif ($unit === 'm') {
            $n *= 1048576;
        } elseif ($unit === 'k') {
            $n *= 1024;
        }

        return (int) $n;
    }

    /** 90 → "1분 30초", 60 → "1분", 45 → "45초" */
    public static function secText(int $sec): string
    {
        $m = intdiv(max(0, $sec), 60);
        $s = max(0, $sec) % 60;
        if ($m > 0) {
            return $m . '분' . ($s > 0 ? ' ' . $s . '초' : '');
        }

        return $s . '초';
    }
}
