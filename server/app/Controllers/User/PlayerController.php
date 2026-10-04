<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Storage;
use App\Core\Text;
use App\Services\Alignment;
use App\Services\Progress;
use App\Services\QuestionService;

/**
 * 동화 플레이어(시안 _6)와 재생 기록 API.
 * 화면에는 재생 정보(문장, 오디오 주소, 타이밍, 목소리, 질문 한도, 환경 설정)를 JSON 으로 넣고 player.js 가 재생한다.
 * 재생 기록은 첫 재생 때 만든다. 같은 아이가 같은 동화를 12시간 안에 다시 열면 끝나지 않은 기록을 이어 쓴다
 * (새로고침, 목소리 바꾸기로 질문 한도가 다시 채워지지 않게).
 */
class PlayerController
{
    const RESUME_HOURS = 12;
    const MAX_QUESTION_BYTES = 5242880;
    const QUESTION_EXTS = ['wav', 'webm', 'ogg', 'm4a', 'mp4', 'mp3', 'mpeg'];

    // ───────────────────────── 화면 ─────────────────────────

    /** /player: 끝나지 않은 가장 최근 동화를 이어 듣는다. 없으면 첫 동화 */
    public function resume(): string
    {
        $user = require_user();
        $this->requireChild();
        // 동화마다 가장 최근 기록이 끝나지 않은 것 중 가장 최근 것(게시 중인 동화만)
        $published = array_flip(array_map('intval', array_column(
            db_all("SELECT id FROM stories WHERE status = 'published' AND deleted_at IS NULL"),
            'id'
        )));
        foreach (Progress::resumeStates(Progress::currentScope(), false) as $storyId => $r) {
            if (isset($published[$storyId])) {
                return $this->render($user, (int) $storyId, $r['voice'], $r['position_ms'], $r['sentence_seq']);
            }
        }
        $first = db_value("SELECT id FROM stories WHERE status = 'published' AND deleted_at IS NULL ORDER BY sort_order, id LIMIT 1");
        if (!$first) {
            return view('user/player/empty');
        }

        return $this->render($user, (int) $first, null, 0, 0);
    }

    /** /player/{storyId}?voice={id|device}&t={ms}&s={문장} */
    public function show(string $id): string
    {
        $user = require_user();
        $this->requireChild();
        $voice = Request::query('voice');

        return $this->render(
            $user,
            (int) $id,
            is_string($voice) && $voice !== '' ? $voice : null,
            max(0, (int) Request::query('t', 0)),
            max(0, (int) Request::query('s', 0))
        );
    }

    private function render(array $user, int $storyId, ?string $requestedVoice, int $startMs, int $startSeq): string
    {
        $userId = (int) $user['id'];
        $child = Auth::child();
        $story = db_one("SELECT * FROM stories WHERE id = ? AND status = 'published' AND deleted_at IS NULL", [$storyId]);
        if (!$story) {
            abort(404, '동화를 찾을 수 없어요.');
        }

        // 문장(없으면 본문을 나눠 쓴다)
        $sentences = [];
        foreach (db_all('SELECT seq, content FROM story_sentences WHERE story_id = ? ORDER BY seq', [$storyId]) as $r) {
            $sentences[] = ['seq' => (int) $r['seq'], 'content' => (string) $r['content'], 'words' => Text::words((string) $r['content'])];
        }
        if (!$sentences) {
            foreach (Text::splitSentences((string) $story['body']) as $i => $content) {
                $sentences[] = ['seq' => $i + 1, 'content' => $content, 'words' => Text::words($content)];
            }
        }

        // 목소리 칩: 이 동화 오디오가 준비된 목소리는 고를 수 있고, 만드는 중인 목소리는 '준비 중'
        $rows = db_all(
            'SELECT vp.id, vp.label, vp.icon, vp.status, sa.id AS audio_id, sa.status AS audio_status, sa.file_path,
                    sa.duration_ms, sa.sentence_timings, sa.content_hash, sa.generated_at
             FROM voice_profiles vp
             LEFT JOIN story_audios sa ON sa.voice_profile_id = vp.id AND sa.story_id = ?
             WHERE vp.user_id = ? AND vp.deleted_at IS NULL
             ORDER BY vp.id',
            [$storyId, $userId]
        );
        $voices = [];
        $audioByVoice = [];
        foreach ($rows as $r) {
            $ready = $r['audio_status'] === 'completed' && !empty($r['file_path']);
            $making = !$ready && in_array($r['status'], ['cloning', 'processing', 'completed'], true) && $r['audio_status'] !== 'failed';
            if (!$ready && !$making) {
                continue;
            }
            $voices[] = ['id' => (string) (int) $r['id'], 'label' => (string) $r['label'], 'icon' => voice_icon($r), 'ready' => $ready];
            if ($ready) {
                $audioByVoice[(string) (int) $r['id']] = $r;
            }
        }

        // 고를 목소리: 주소의 voice → 이 동화를 마지막으로 들은 목소리 → 첫 준비된 목소리 → 기기 음성
        $voice = null;
        if ($requestedVoice === 'device' || ($requestedVoice !== null && isset($audioByVoice[$requestedVoice]))) {
            $voice = $requestedVoice;
        }
        if ($voice === null) {
            $last = db_one(
                'SELECT voice_profile_id, audio_source FROM play_sessions WHERE user_id = ? AND story_id = ? ORDER BY id DESC LIMIT 1',
                [$userId, $storyId]
            );
            if ($last) {
                $lastKey = ($last['audio_source'] === 'device' || $last['voice_profile_id'] === null) ? 'device' : (string) (int) $last['voice_profile_id'];
                if ($lastKey === 'device' || isset($audioByVoice[$lastKey])) {
                    $voice = $lastKey;
                }
            }
        }
        if ($voice === null) {
            $voice = $audioByVoice ? (string) array_keys($audioByVoice)[0] : 'device';
        }

        $audio = null;
        if ($voice !== 'device') {
            $a = $audioByVoice[$voice];
            $timings = json_decode_array($a['sentence_timings']);
            $audio = [
                'id' => (int) $a['audio_id'],
                // 다시 만든 오디오는 같은 주소를 쓰므로 버전 값을 붙여 브라우저 캐시를 피한다.
                'url' => url('/media/story-audio/' . (int) $a['audio_id']) . '?v=' . substr(md5($a['file_path'] . '|' . $a['generated_at']), 0, 8),
                'duration_ms' => (int) $a['duration_ms'],
                'timings' => !empty($timings['sentences']) ? $timings : null,
                'outdated' => !empty($story['content_hash']) && (string) $a['content_hash'] !== (string) $story['content_hash'],
            ];
        }

        // 처음 보여 줄 문장(문장 번호 → 재생 위치 순서)
        $startIndex = 0;
        $seqToIndex = [];
        foreach ($sentences as $i => $s) {
            $seqToIndex[$s['seq']] = $i;
        }
        if ($startSeq > 0 && isset($seqToIndex[$startSeq])) {
            $startIndex = $seqToIndex[$startSeq];
        } elseif ($startMs > 0 && $audio && $audio['timings']) {
            $seq = Alignment::sentenceAt($audio['timings'], $startMs);
            if ($seq !== null && isset($seqToIndex[$seq])) {
                $startIndex = $seqToIndex[$seq];
            }
        }

        // 질문 한도: 이어 쓸 재생 기록이 있으면 이미 쓴 횟수를 뺀다.
        $childId = $child ? (int) $child['id'] : null;
        $reuse = self::reusableSession($userId, $childId, $storyId);
        $max = self::maxQuestions($story);
        $qaEnabled = qa_available() && (int) $story['barge_in_enabled'] === 1;
        $qaReady = $qaEnabled;
        $aec = !empty($story['aec_level']) ? (string) $story['aec_level'] : (string) setting('qa.aec_level', 'strong');

        $next = self::nextStory($storyId, Progress::completedStoryIds(Progress::currentScope()));
        $prefs = Auth::prefs();
        $estSec = Progress::durationSec($story, $audio ? $audio['duration_ms'] : null);

        $manifest = [
            'story' => [
                'id' => $storyId,
                'title' => (string) $story['title'],
                'category' => (string) $story['category'],
                'cover' => cover_url($story),
                'author' => (string) $story['author'],
            ],
            'child' => $child ? (string) $child['name'] : '',
            'brand' => (string) setting('app.brand', '르멤버'),
            'sentences' => $sentences,
            'voice' => $voice,
            'voices' => $voices,
            'audio' => $audio,
            'est_ms' => $estSec * 1000,
            'start' => ['t' => $startMs, 's' => $startSeq, 'index' => $startIndex],
            'quota' => ['max' => $max, 'used' => $reuse ? min($max, (int) $reuse['question_count']) : 0],
            'qa' => [
                'enabled' => $qaReady,
                'message' => $qaReady ? '' : ($qaEnabled ? '질문하기 기능을 준비하고 있어요.' : '이 동화는 질문 없이 들어요.'),
                'max_ms' => max(3, (int) setting('qa.max_record_seconds', 15)) * 1000,
                'vad' => [
                    'minSpeechMs' => $story['vad_min_ms'] !== null && $story['vad_min_ms'] !== '' ? (int) $story['vad_min_ms'] : (int) setting('qa.vad_min_speech_ms', 1000),
                    'silenceStopMs' => (int) setting('qa.silence_stop_ms', 1200),
                    'noSpeechTimeoutMs' => 6000,
                ],
                'aec' => $aec,
                'noise_suppression' => $aec === 'max',
            ],
            'prefs' => $prefs,
            'next' => $next ? [
                'id' => (int) $next['id'],
                'title' => (string) $next['title'],
                'cover' => cover_url($next),
                'url' => url('/player/' . (int) $next['id'], $voice !== 'device' ? ['voice' => $voice] : []),
            ] : null,
            'player_url' => url('/player/' . $storyId),
        ];

        return view('user/player/show', [
            'story' => $story,
            'manifest' => $manifest,
            'sentences' => $sentences,
            'startIndex' => $startIndex,
            'voices' => $voices,
            'voice' => $voice,
            'audio' => $audio,
            'prefs' => $prefs,
            'remaining' => max(0, $max - $manifest['quota']['used']),
            'qa' => $manifest['qa'],
            'estMs' => $estSec * 1000,
        ]);
    }

    // ───────────────────────── 재생 기록 API ─────────────────────────

    /** POST /api/play-sessions {story_id, voice: id|'device'} */
    public function start(): array
    {
        $user = require_user();
        $userId = (int) $user['id'];
        $storyId = Request::int('story_id');
        $story = db_one("SELECT id, max_questions FROM stories WHERE id = ? AND status = 'published' AND deleted_at IS NULL", [$storyId]);
        if (!$story) {
            abort(404, '동화를 찾을 수 없어요.');
        }
        $voiceIn = Request::str('voice');
        $voiceId = null;
        if ($voiceIn !== '' && $voiceIn !== 'device') {
            $ok = db_value(
                "SELECT sa.id FROM story_audios sa
                 JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
                 WHERE sa.story_id = ? AND vp.id = ? AND vp.user_id = ? AND vp.deleted_at IS NULL
                   AND sa.status = 'completed' AND sa.file_path IS NOT NULL",
                [$storyId, (int) $voiceIn, $userId]
            );
            if (!$ok) {
                abort(422, '이 목소리로는 아직 들을 수 없어요.');
            }
            $voiceId = (int) $voiceIn;
        }
        $child = Auth::child();
        $childId = $child ? (int) $child['id'] : null;
        $source = $voiceId === null ? 'device' : 'voice';

        $reuse = self::reusableSession($userId, $childId, $storyId);
        if ($reuse) {
            $sessionId = (int) $reuse['id'];
            $used = (int) $reuse['question_count'];
            db_update('play_sessions', ['voice_profile_id' => $voiceId, 'audio_source' => $source], 'id = ? AND user_id = ?', [$sessionId, $userId]);
        } else {
            $sessionId = db_insert('play_sessions', [
                'user_id' => $userId,
                'child_id' => $childId,
                'story_id' => $storyId,
                'voice_profile_id' => $voiceId,
                'audio_source' => $source,
                'started_at' => now(),
            ]);
            $used = 0;
        }
        $max = self::maxQuestions($story);

        return ['ok' => true, 'session_id' => $sessionId, 'remaining' => max(0, $max - $used), 'max' => $max, 'resumed' => (bool) $reuse];
    }

    /** POST /api/play-sessions/{id}/progress {position_ms, sentence_seq, listened_delta_ms} (JSON 또는 FormData) */
    public function progress(string $id): array
    {
        $user = require_user();
        $session = $this->ownSession((int) $id, (int) $user['id']);
        $seq = max(0, Request::int('sentence_seq'));
        db_exec(
            'UPDATE play_sessions SET last_position_ms = ?, last_sentence_seq = ?, listened_ms = LEAST(listened_ms + ?, 2000000000)
             WHERE id = ? AND user_id = ?',
            [
                min(2000000000, max(0, Request::int('position_ms'))),
                $seq > 0 ? $seq : null,
                min(60000, max(0, Request::int('listened_delta_ms'))),
                (int) $session['id'],
                (int) $user['id'],
            ]
        );

        return ['ok' => true];
    }

    /** POST /api/play-sessions/{id}/complete 끝까지 들었다. 새로 얻은 경험치와 레벨을 돌려준다. */
    public function complete(string $id): array
    {
        $user = require_user();
        $session = $this->ownSession((int) $id, (int) $user['id']);
        $scope = Progress::currentScope();
        $before = Progress::levelFor($scope);
        $first = (int) $session['completed'] === 0;
        $seq = max(0, Request::int('sentence_seq'));
        $pos = Request::int('position_ms', -1);
        db_exec(
            'UPDATE play_sessions SET completed = 1, completed_at = COALESCE(completed_at, NOW()), ended_at = NOW(),
                    last_position_ms = COALESCE(?, last_position_ms), last_sentence_seq = COALESCE(?, last_sentence_seq),
                    listened_ms = LEAST(listened_ms + ?, 2000000000)
             WHERE id = ? AND user_id = ?',
            [
                $pos >= 0 ? min(2000000000, $pos) : null,
                $seq > 0 ? $seq : null,
                min(60000, max(0, Request::int('listened_delta_ms'))),
                (int) $session['id'],
                (int) $user['id'],
            ]
        );
        $after = Progress::levelFor($scope);

        return [
            'ok' => true,
            'first' => $first,
            'xp_gained' => max(0, $after['xp'] - $before['xp']),
            'level' => ['level' => $after['level'], 'title' => $after['title'], 'percent' => $after['percent']],
            'level_up' => $after['level'] > $before['level'],
        ];
    }

    /** POST /api/play-sessions/{id}/question multipart: audio, sentence_seq, position_ms */
    public function question(string $id): array
    {
        $user = require_user();
        $session = $this->ownSession((int) $id, (int) $user['id']);
        $file = Request::file('audio');
        if ($file === null) {
            $code = isset($_FILES['audio']['error']) ? (int) $_FILES['audio']['error'] : -1;
            if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
                abort(413, '질문 녹음이 너무 길어요. 조금 짧게 물어봐 줄래요?');
            }
            abort(422, '질문 녹음을 받지 못했어요. 다시 말해 줄래요?');
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::MAX_QUESTION_BYTES) {
            abort(413, '질문 녹음이 너무 길어요. 조금 짧게 물어봐 줄래요?');
        }
        $mime = self::detectAudioMime($file);
        if ($mime === null) {
            abort(415, '지원하지 않는 녹음 형식이에요.');
        }

        return QuestionService::ask(
            $user,
            $session,
            (string) $file['tmp_name'],
            $mime,
            max(0, Request::int('sentence_seq')),
            max(0, Request::int('position_ms'))
        );
    }

    // ───────────────────────── 내부 ─────────────────────────

    private function requireChild(): void
    {
        if (Auth::child() === null) {
            redirect('/onboarding');
        }
    }

    private function ownSession(int $id, int $userId): array
    {
        $row = db_one('SELECT * FROM play_sessions WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$row) {
            abort(404, '재생 기록을 찾을 수 없어요.');
        }

        return $row;
    }

    /** 이어 쓸 수 있는 재생 기록(같은 아이, 같은 동화, 끝나지 않음, 12시간 안) */
    public static function reusableSession(int $userId, ?int $childId, int $storyId): ?array
    {
        return db_one(
            'SELECT id, question_count FROM play_sessions
             WHERE user_id = ? AND story_id = ? AND child_id <=> ? AND completed = 0
               AND updated_at >= DATE_SUB(NOW(), INTERVAL ' . self::RESUME_HOURS . ' HOUR)
             ORDER BY id DESC LIMIT 1',
            [$userId, $storyId, $childId]
        );
    }

    /** 동화별 질문 한도(비어 있으면 전체 설정) */
    public static function maxQuestions(array $story): int
    {
        if (isset($story['max_questions']) && $story['max_questions'] !== null && $story['max_questions'] !== '') {
            return max(0, (int) $story['max_questions']);
        }

        return max(0, (int) setting('qa.max_questions', 3));
    }

    /** 다음 이야기: 목록 순서에서 뒤에 있는 동화 중 아직 다 듣지 않은 것, 모두 들었으면 바로 다음 동화 */
    private static function nextStory(int $storyId, array $completedIds): ?array
    {
        $list = db_all("SELECT id, title, cover_image_path, updated_at FROM stories WHERE status = 'published' AND deleted_at IS NULL ORDER BY sort_order, id");
        $n = count($list);
        if ($n < 2) {
            return null;
        }
        $pos = 0;
        foreach ($list as $i => $s) {
            if ((int) $s['id'] === $storyId) {
                $pos = $i;
                break;
            }
        }
        $done = array_flip($completedIds);
        for ($k = 1; $k < $n; $k++) {
            $cand = $list[($pos + $k) % $n];
            if (!isset($done[(int) $cand['id']])) {
                return $cand;
            }
        }

        return $list[($pos + 1) % $n];
    }

    /** 업로드된 질문 녹음의 형식 확인. 허용 형식이 아니면 null */
    private static function detectAudioMime(array $file): ?string
    {
        $clientExt = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mime = strtolower(trim(explode(';', (string) $file['type'])[0]));
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $detected = @finfo_file($fi, (string) $file['tmp_name']);
                finfo_close($fi);
                if (is_string($detected) && $detected !== '' && $detected !== 'application/octet-stream') {
                    $mime = strtolower($detected);
                }
            }
        }
        // 브라우저 녹음은 video/webm, video/mp4 로 표시되기도 한다.
        if ($mime === 'video/webm' || $mime === 'video/mp4') {
            $mime = 'audio/' . substr($mime, 6);
        }
        $ext = Storage::extForMime($mime, $clientExt);
        if (!in_array($ext, self::QUESTION_EXTS, true)) {
            return null;
        }
        if (strpos($mime, 'audio/') !== 0) {
            $mime = Storage::mimeFor('x.' . ($ext === 'mpeg' ? 'mp3' : $ext));
        }

        return $mime;
    }
}
