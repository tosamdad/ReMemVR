<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Core\Storage;
use App\Core\Text;
use App\Services\VoiceService;

/**
 * 동화 콘텐츠 관리(CMS). 왼쪽 도서 목록 + 오른쪽 편집기(기본 정보, 문장 타임스탬프, 끼어들기 설정).
 * 저장은 한 번의 POST 로 동화 행과 문장 전체를 트랜잭션 안에서 바꾼다.
 */
class StoryController
{
    const CATEGORIES = ['취침 전', '모험', '용기', '상상', '우정', '기타'];
    const STATUSES = ['published' => '활성', 'hidden' => '숨김', 'draft' => '초안'];
    const AEC_LEVELS = ['normal' => '보통', 'strong' => '강함', 'max' => '최대'];
    const COVER_MAX_BYTES = 2097152;
    const COVER_EXTS = ['png', 'jpg', 'jpeg', 'webp'];
    const MAX_SENTENCES = 400;
    const MAX_FALLBACK_LINES = 10;
    /** 예상 낭독 속도(초당 글자 수) */
    const CHARS_PER_SEC = 5.5;

    // ───────────────────────── 화면 ─────────────────────────

    /** GET /admin/stories: 목록 + 첫 동화 편집기(동화가 없으면 빈 상태) */
    public function index(): string
    {
        require_admin();
        $library = $this->library();
        $id = $library ? (int) $library[0]['id'] : (int) db_value('SELECT id FROM stories WHERE deleted_at IS NULL ORDER BY sort_order, id LIMIT 1');

        return $this->render($id ? $this->findStory($id) : null, $library, $id === 0);
    }

    /** GET /admin/stories/new */
    public function create(): string
    {
        require_admin();

        return $this->render(null, $this->library(), false);
    }

    /** GET /admin/stories/{id} */
    public function show(string $id): string
    {
        require_admin();

        return $this->render($this->findStory((int) $id), $this->library(), false);
    }

    private function render(?array $story, array $library, bool $empty): string
    {
        $storyId = $story ? (int) $story['id'] : 0;
        $sentences = $storyId ? db_all('SELECT * FROM story_sentences WHERE story_id = ? ORDER BY seq', [$storyId]) : [];
        $audios = [];
        $outdated = 0;
        if ($storyId) {
            $rows = db_all(
                "SELECT sa.id, sa.duration_ms, sa.sentence_timings, sa.content_hash, sa.generated_at, sa.updated_at, vp.label, u.name AS user_name
                   FROM story_audios sa
                   JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
                   JOIN users u ON u.id = vp.user_id
                  WHERE sa.story_id = ? AND sa.status = 'completed' AND sa.file_path IS NOT NULL AND vp.deleted_at IS NULL
                  ORDER BY (sa.content_hash <=> ?) DESC, sa.id DESC LIMIT 30",
                [$storyId, $story['content_hash']]
            );
            foreach ($rows as $r) {
                $fresh = $story['content_hash'] !== null && $r['content_hash'] === $story['content_hash'];
                $timings = json_decode_array($r['sentence_timings']);
                $audios[] = [
                    'id' => (int) $r['id'],
                    'label' => $r['label'] . ' · ' . $r['user_name'] . ($fresh ? '' : ' (옛 본문)'),
                    'url' => url('/admin/media/story-audio/' . (int) $r['id']),
                    'fresh' => $fresh,
                    'duration_ms' => (int) $r['duration_ms'],
                    'timings' => isset($timings['sentences']) && is_array($timings['sentences']) ? array_map(static function ($t) {
                        return ['seq' => (int) (isset($t['seq']) ? $t['seq'] : 0), 'start' => (int) (isset($t['start']) ? $t['start'] : 0), 'end' => (int) (isset($t['end']) ? $t['end'] : 0)];
                    }, $timings['sentences']) : [],
                ];
            }
        }
        if ($storyId) {
            $outdated = (int) db_value(
                "SELECT COUNT(*) FROM story_audios sa JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
                  WHERE sa.story_id = ? AND sa.status = 'completed' AND vp.deleted_at IS NULL AND (sa.content_hash IS NULL OR sa.content_hash <> ?)",
                [$storyId, (string) $story['content_hash']]
            );
        }
        $timecodeSource = $storyId && $story['content_hash'] !== null ? db_one(
            "SELECT sa.id, vp.label FROM story_audios sa JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
              WHERE sa.story_id = ? AND sa.status = 'completed' AND sa.content_hash = ? AND sa.sentence_timings IS NOT NULL
              ORDER BY sa.generated_at DESC, sa.id DESC LIMIT 1",
            [$storyId, $story['content_hash']]
        ) : null;

        return view('admin/stories/index', [
            'story' => $story,
            'empty' => $empty,
            'sentences' => $sentences,
            'library' => $library,
            'filters' => ['q' => Request::str('q'), 'status' => Request::str('status')],
            'publishedCount' => (int) db_value("SELECT COUNT(*) FROM stories WHERE deleted_at IS NULL AND status = 'published'"),
            'totalCount' => (int) db_value('SELECT COUNT(*) FROM stories WHERE deleted_at IS NULL'),
            'audios' => $audios,
            'outdated' => $outdated,
            'timecodeSource' => $timecodeSource,
            'categories' => self::CATEGORIES,
            'statuses' => self::STATUSES,
            'aecLevels' => self::AEC_LEVELS,
            'presets' => $this->presetCovers(),
            'global' => [
                'max_questions' => (int) setting('qa.max_questions', 3),
                'vad_ms' => (int) setting('qa.vad_min_speech_ms', 1000),
                'aec' => (string) setting('qa.aec_level', 'strong'),
                'fallback_lines' => array_values(array_filter((array) setting('qa.fallback_lines', []), 'is_string')),
                'enabled' => (bool) setting('qa.enabled', true),
            ],
            'ttsReady' => provider_ready('elevenlabs'),
        ]);
    }

    /** 왼쪽 도서 목록(검색, 상태 필터) */
    private function library(): array
    {
        $where = ['s.deleted_at IS NULL'];
        $params = [];
        $status = Request::str('status');
        if (isset(self::STATUSES[$status])) {
            $where[] = 's.status = ?';
            $params[] = $status;
        }
        $q = Request::str('q');
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(s.title LIKE ? OR s.code LIKE ? OR s.category LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        return db_all(
            "SELECT s.id, s.code, s.title, s.status, s.category, s.age_min, s.age_max, s.sort_order, s.barge_in_enabled,
                    s.cover_image_path, s.est_duration_sec, s.updated_at,
                    (SELECT COUNT(*) FROM story_sentences ss WHERE ss.story_id = s.id) AS sentence_count,
                    (SELECT AVG(sa.duration_ms) FROM story_audios sa WHERE sa.story_id = s.id AND sa.status = 'completed' AND sa.content_hash = s.content_hash) AS audio_ms
               FROM stories s WHERE " . implode(' AND ', $where) . ' ORDER BY s.sort_order, s.id LIMIT 300',
            $params
        );
    }

    /** public/assets/covers/01.svg ~ 12.svg */
    private function presetCovers(): array
    {
        $out = [];
        for ($i = 1; $i <= 12; $i++) {
            $name = sprintf('%02d', $i);
            $out[$name] = 'assets:covers/' . $name . '.svg';
        }

        return $out;
    }

    private function findStory(int $id): array
    {
        $story = db_one('SELECT * FROM stories WHERE id = ? AND deleted_at IS NULL', [$id]);
        if (!$story) {
            abort(404, '동화를 찾을 수 없습니다.');
        }

        return $story;
    }

    // ───────────────────────── 저장 ─────────────────────────

    /** POST /admin/stories */
    public function store(): void
    {
        $admin = require_admin();
        $this->persist(null, $admin);
    }

    /** POST /admin/stories/{id} */
    public function update(string $id): void
    {
        $admin = require_admin();
        $this->persist($this->findStory((int) $id), $admin);
    }

    private function persist(?array $story, array $admin): void
    {
        list($fields, $sentences, $errors, $coverUpload) = $this->validateInput($_POST, $story);
        if ($errors) {
            $first = reset($errors);
            flash('error', '입력값을 확인해 주세요. ' . $first);
            back_with_errors($errors, $story ? '/admin/stories/' . (int) $story['id'] : '/admin/stories/new');
        }

        // 표지 업로드는 검증을 마친 뒤 저장 폴더로 옮긴다.
        $newCoverFile = null;
        if ($coverUpload !== null) {
            $newCoverFile = 'covers/' . Storage::randomName($coverUpload['ext']);
            Storage::putUploaded($coverUpload['tmp'], $newCoverFile);
            $fields['cover_image_path'] = 'storage:' . $newCoverFile;
        }

        $contents = array_map(static function ($s) {
            return $s['content'];
        }, $sentences);
        $joined = implode(' ', $contents);
        $chars = Text::charCount($joined);
        $fields['body'] = implode("\n", $contents);
        $fields['char_count'] = $chars;
        $fields['est_duration_sec'] = $contents ? (int) ceil($chars / self::CHARS_PER_SEC) : null;
        $fields['content_hash'] = $contents ? Text::hashSentences($contents) : null;

        try {
            $id = db_tx(function () use ($story, $fields, $sentences, $admin) {
                if ($story) {
                    $id = (int) $story['id'];
                    db_update('stories', $fields, 'id = ?', [$id]);
                } else {
                    $fields['created_by'] = (int) $admin['id'];
                    $id = db_insert('stories', $fields);
                }
                db_exec('DELETE FROM story_sentences WHERE story_id = ?', [$id]);
                foreach ($sentences as $i => $s) {
                    db_insert('story_sentences', [
                        'story_id' => $id,
                        'seq' => $i + 1,
                        'content' => $s['content'],
                        'keywords' => $s['keywords'] !== '' ? $s['keywords'] : null,
                        'ref_start_ms' => $s['start'],
                        'ref_end_ms' => $s['end'],
                    ]);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            if ($newCoverFile !== null) {
                Storage::delete($newCoverFile);
            }
            app_log('error', '동화 저장 실패: ' . $e->getMessage());
            flash('error', '동화를 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.');
            back_with_errors([], '/admin/stories');

            return;
        }

        // 바뀐 업로드 표지의 이전 파일은 지운다.
        if ($story && $newCoverFile !== null && strpos((string) $story['cover_image_path'], 'storage:') === 0) {
            Storage::delete(substr((string) $story['cover_image_path'], 8));
        } elseif ($story && strpos((string) $story['cover_image_path'], 'storage:') === 0 && $fields['cover_image_path'] !== $story['cover_image_path']) {
            Storage::delete(substr((string) $story['cover_image_path'], 8));
        }

        $hashChanged = $story !== null && (string) $story['content_hash'] !== (string) $fields['content_hash'];
        $changed = [];
        if ($story) {
            foreach ($fields as $k => $v) {
                if (array_key_exists($k, $story) && (string) $story[$k] !== (string) $v && !in_array($k, ['body', 'char_count', 'est_duration_sec', 'content_hash'], true)) {
                    $changed[] = $k;
                }
            }
            if ($hashChanged) {
                $changed[] = 'sentences';
            }
        }
        admin_audit($story ? 'story.update' : 'story.create', 'story', $id, [
            'code' => $fields['code'],
            'title' => $fields['title'],
            'sentences' => count($sentences),
            'changed' => $changed,
            'content_changed' => $hashChanged,
        ]);

        if ($hashChanged) {
            $stale = (int) db_value(
                "SELECT COUNT(*) FROM story_audios sa JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
                  WHERE sa.story_id = ? AND sa.status = 'completed' AND vp.deleted_at IS NULL AND (sa.content_hash IS NULL OR sa.content_hash <> ?)",
                [$id, (string) $fields['content_hash']]
            );
            if ($stale > 0) {
                flash('info', '본문이 바뀌어 목소리 ' . $stale . '개의 동화 오디오가 옛 본문 기준이 되었습니다. 배포 상태 점검에서 다시 생성해 주세요.');
            }
        }
        flash('success', $story ? '변경사항을 저장했습니다.' : '새 동화를 등록했습니다.');
        $tab = (string) input('_tab', '');
        $hash = in_array($tab, ['basic', 'sentences', 'bargein'], true) ? '#' . $tab : '';
        header('Location: ' . url('/admin/stories/' . $id) . $hash, true, 302);
        exit;
    }

    /**
     * 입력 검증. 통과하면 stories 행 값과 문장 목록을 돌려준다.
     * @return array [$fields, $sentences, $errors, $coverUpload]
     */
    public function validateInput(array $in, ?array $story): array
    {
        $errors = [];
        $str = static function ($key) use ($in) {
            return isset($in[$key]) && !is_array($in[$key]) ? trim((string) $in[$key]) : '';
        };

        $title = $str('title');
        if ($title === '') {
            $errors['title'] = '동화 제목을 입력해 주세요.';
        } elseif (mb_strlen($title) > 200) {
            $errors['title'] = '제목은 200자 이하로 입력해 주세요.';
        }

        $code = $str('code');
        if ($code === '') {
            $code = $story && (string) $story['code'] !== '' ? (string) $story['code'] : $this->nextCode();
        } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,29}$/', $code)) {
            $errors['code'] = '관리 코드는 영문, 숫자, -, _ 로 30자 이하여야 합니다.';
        }
        if (!isset($errors['code'])) {
            $dup = db_value('SELECT id FROM stories WHERE code = ? AND id <> ?', [$code, $story ? (int) $story['id'] : 0]);
            if ($dup) {
                $errors['code'] = '이미 쓰고 있는 관리 코드입니다.';
            }
        }

        $author = $str('author');
        if (mb_strlen($author) > 100) {
            $errors['author'] = '지은이는 100자 이하로 입력해 주세요.';
        }
        $summary = $str('summary');
        if (mb_strlen($summary) > 500) {
            $errors['summary'] = '소개는 500자 이하로 입력해 주세요.';
        }
        $category = $str('category');
        if ($category !== '' && !in_array($category, self::CATEGORIES, true)) {
            $errors['category'] = '분류를 목록에서 골라 주세요.';
        }

        $ageMin = $this->optionalInt($str('age_min'), 0, 12);
        $ageMax = $this->optionalInt($str('age_max'), 0, 12);
        if ($ageMin === false) {
            $errors['age_min'] = '최소 나이는 0~12 사이 숫자여야 합니다.';
        }
        if ($ageMax === false) {
            $errors['age_max'] = '최대 나이는 0~12 사이 숫자여야 합니다.';
        }
        if (is_int($ageMin) && is_int($ageMax) && $ageMin > $ageMax) {
            $errors['age_max'] = '최대 나이는 최소 나이보다 크거나 같아야 합니다.';
        }

        $status = $str('status');
        if (!isset(self::STATUSES[$status])) {
            $errors['status'] = '상태를 골라 주세요.';
        }
        $sort = $this->optionalInt($str('sort_order'), -9999, 9999);
        if ($sort === false) {
            $errors['sort_order'] = '정렬 순서는 숫자여야 합니다.';
        } elseif ($sort === null) {
            $sort = $story ? (int) $story['sort_order'] : (int) db_value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM stories WHERE deleted_at IS NULL');
        }

        // 표지: 기본 표지 선택, 업로드, 또는 그대로
        $cover = $story ? $story['cover_image_path'] : 'assets:covers/01.svg';
        $preset = $str('cover_preset');
        $presets = $this->presetCovers();
        if ($preset !== '' && $preset !== 'keep' && isset($presets[$preset])) {
            $cover = $presets[$preset];
        }
        $coverUpload = null;
        $up = isset($_FILES['cover_file']) && is_array($_FILES['cover_file']) ? $_FILES['cover_file'] : null;
        if ($up && !is_array($up['name']) && (int) $up['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = Request::file('cover_file');
            if ($file === null) {
                $errors['cover_file'] = (string) Request::uploadError('cover_file');
            } else {
                $check = $this->checkCover($file);
                if (is_string($check)) {
                    $errors['cover_file'] = $check;
                } else {
                    $coverUpload = $check;
                }
            }
        }

        // 끼어들기 설정
        $bargeIn = !empty($in['barge_in_enabled']) ? 1 : 0;
        $maxQ = $this->optionalInt($str('max_questions'), 0, 10);
        if ($maxQ === false) {
            $errors['max_questions'] = '질문 한도는 0~10 사이 숫자여야 합니다(비우면 전체 설정).';
        }
        $vad = null;
        if ($str('vad_custom') === '1') {
            $sec = $str('vad_seconds');
            if (!is_numeric($sec) || (float) $sec < 0.5 || (float) $sec > 2.0) {
                $errors['vad_seconds'] = '발화 감지 임계값은 0.5~2.0초 사이여야 합니다.';
            } else {
                $vad = (int) round((float) $sec * 1000);
            }
        }
        $aec = $str('aec_level');
        if ($aec === '') {
            $aec = null;
        } elseif (!isset(self::AEC_LEVELS[$aec])) {
            $errors['aec_level'] = '반향 제거 감도를 골라 주세요.';
        }
        $fallback = null;
        if ($str('fallback_custom') === '1') {
            $lines = [];
            foreach (isset($in['fallback_lines']) && is_array($in['fallback_lines']) ? $in['fallback_lines'] : [] as $i => $line) {
                $line = trim(preg_replace('/\s+/u', ' ', (string) (is_array($line) ? '' : $line)));
                if ($line === '') {
                    continue;
                }
                if (mb_strlen($line) > 200) {
                    $errors['fallback_lines'] = '대체 문장은 한 줄에 200자 이하로 입력해 주세요.';
                }
                $lines[] = $line;
            }
            if (count($lines) > self::MAX_FALLBACK_LINES) {
                $errors['fallback_lines'] = '대체 문장은 ' . self::MAX_FALLBACK_LINES . '개까지 등록할 수 있습니다.';
            }
            $fallback = $lines ? json_encode_u(array_values($lines)) : null;
        }

        // 문장
        list($sentences, $sentenceErrors) = self::parseSentences(isset($in['sentences']) && is_array($in['sentences']) ? $in['sentences'] : []);
        $errors = array_merge($errors, $sentenceErrors);
        if (!$sentences && $status === 'published' && !isset($errors['sentences'])) {
            $errors['sentences'] = '활성(공개) 상태로 저장하려면 문장이 한 개 이상 필요합니다.';
        }
        if (count($sentences) > self::MAX_SENTENCES) {
            $errors['sentences'] = '문장은 ' . self::MAX_SENTENCES . '개까지 등록할 수 있습니다.';
        }

        $fields = [
            'code' => $code,
            'title' => $title,
            'author' => $author !== '' ? $author : null,
            'summary' => $summary !== '' ? $summary : null,
            'category' => $category !== '' ? $category : null,
            'age_min' => is_int($ageMin) ? $ageMin : null,
            'age_max' => is_int($ageMax) ? $ageMax : null,
            'status' => $status,
            'sort_order' => is_int($sort) ? $sort : 0,
            'cover_image_path' => $cover,
            'barge_in_enabled' => $bargeIn,
            'max_questions' => is_int($maxQ) ? $maxQ : null,
            'vad_min_ms' => $vad,
            'aec_level' => $aec,
            'fallback_lines' => $fallback,
        ];

        return [$fields, $sentences, $errors, $coverUpload];
    }

    /**
     * 편집기 문장 행을 정리한다. 완전히 빈 행은 건너뛰고, 내용 없이 다른 칸만 채운 행은 오류로 둔다.
     * 오류 키는 'sentences.{행 번호}.{항목}' 이다(화면이 같은 순서로 다시 그린다).
     * @return array [[['content', 'keywords', 'start', 'end'], ...], $errors]
     */
    public static function parseSentences(array $rows): array
    {
        $out = [];
        $errors = [];
        foreach (array_values($rows) as $i => $row) {
            if (!is_array($row)) {
                continue;
            }
            $content = isset($row['content']) && !is_array($row['content']) ? trim(preg_replace('/\s+/u', ' ', (string) $row['content'])) : '';
            $kwRaw = isset($row['keywords']) && !is_array($row['keywords']) ? (string) $row['keywords'] : '';
            $startRaw = isset($row['start']) && !is_array($row['start']) ? trim((string) $row['start']) : '';
            $endRaw = isset($row['end']) && !is_array($row['end']) ? trim((string) $row['end']) : '';
            if ($content === '' && trim($kwRaw) === '' && $startRaw === '' && $endRaw === '') {
                continue;
            }
            if ($content === '') {
                $errors['sentences.' . $i . '.content'] = '문장 내용을 입력하거나 행을 삭제해 주세요.';
                continue;
            }
            if (mb_strlen($content) > 1000) {
                $errors['sentences.' . $i . '.content'] = '한 문장은 1000자 이하로 입력해 주세요.';
            }
            $keywords = implode(', ', Text::keywords($kwRaw));
            if (mb_strlen($keywords) > 255) {
                $errors['sentences.' . $i . '.keywords'] = '키워드는 모두 합쳐 255자 이하로 입력해 주세요.';
            }
            $start = self::parseTime($startRaw);
            $end = self::parseTime($endRaw);
            if ($start === false) {
                $errors['sentences.' . $i . '.start'] = '시작 시각은 MM:SS 형식으로 입력해 주세요.';
            }
            if ($end === false) {
                $errors['sentences.' . $i . '.end'] = '종료 시각은 MM:SS 형식으로 입력해 주세요.';
            }
            if (is_int($start) && is_int($end) && $end < $start) {
                $errors['sentences.' . $i . '.end'] = '종료 시각이 시작 시각보다 빠릅니다.';
            }
            $out[] = [
                'content' => $content,
                'keywords' => $keywords,
                'start' => is_int($start) ? $start : null,
                'end' => is_int($end) ? $end : null,
            ];
        }

        return [$out, $errors];
    }

    /** "MM:SS", "M:SS.s", "H:MM:SS" → 밀리초. 빈 값은 null, 형식 오류는 false */
    public static function parseTime(string $s)
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{1,2}):(\d{2})(?:\.(\d{1,3}))?$/', $s, $m)) {
            if ((int) $m[2] > 59 || (int) $m[3] > 59) {
                return false;
            }
            $ms = isset($m[4]) ? (int) str_pad($m[4], 3, '0') : 0;

            return (((int) $m[1] * 3600) + ((int) $m[2] * 60) + (int) $m[3]) * 1000 + $ms;
        }
        if (preg_match('/^(\d{1,3}):(\d{2})(?:\.(\d{1,3}))?$/', $s, $m)) {
            if ((int) $m[2] > 59) {
                return false;
            }
            $ms = isset($m[3]) ? (int) str_pad($m[3], 3, '0') : 0;

            return ((int) $m[1] * 60 + (int) $m[2]) * 1000 + $ms;
        }

        return false;
    }

    /** 밀리초 → "MM:SS" (편집기 표시용) */
    public static function formatTime(?int $ms): string
    {
        if ($ms === null) {
            return '';
        }

        return fmt_duration($ms);
    }

    /** 빈 값은 null, 범위를 벗어나거나 숫자가 아니면 false */
    private function optionalInt(string $v, int $min, int $max)
    {
        if ($v === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
            return false;
        }

        return (int) $v;
    }

    /** 다음 관리 코드(Fairytale-013 형식) */
    private function nextCode(): string
    {
        $n = 0;
        foreach (db_all("SELECT code FROM stories WHERE code LIKE 'Fairytale-%'") as $r) {
            if (preg_match('/^Fairytale-(\d+)$/', (string) $r['code'], $m)) {
                $n = max($n, (int) $m[1]);
            }
        }
        do {
            $n++;
            $code = sprintf('Fairytale-%03d', $n);
        } while (db_value('SELECT id FROM stories WHERE code = ?', [$code]));

        return $code;
    }

    /** 표지 이미지 확인(png, jpg, webp, 2MB 이하, 실제 이미지인지). 통과하면 ['tmp', 'ext'] */
    private function checkCover(array $file)
    {
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::COVER_EXTS, true)) {
            return '표지는 PNG, JPG, WEBP 이미지만 올릴 수 있습니다.';
        }
        if ((int) $file['size'] <= 0 || (int) $file['size'] > self::COVER_MAX_BYTES) {
            return '표지 이미지는 2MB 이하여야 합니다.';
        }
        $info = @getimagesize((string) $file['tmp_name']);
        $types = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'];
        if (defined('IMAGETYPE_WEBP')) {
            $types[IMAGETYPE_WEBP] = 'webp';
        }
        if (!$info || !isset($types[$info[2]])) {
            // getimagesize 가 webp 를 모르는 환경을 위해 파일 머리로 한 번 더 확인한다.
            $head = (string) @file_get_contents((string) $file['tmp_name'], false, null, 0, 16);
            if ($ext === 'webp' && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
                return ['tmp' => (string) $file['tmp_name'], 'ext' => 'webp'];
            }

            return '이미지 파일을 읽을 수 없습니다. 다른 파일을 골라 주세요.';
        }

        return ['tmp' => (string) $file['tmp_name'], 'ext' => $types[$info[2]]];
    }

    // ───────────────────────── 보조 기능 ─────────────────────────

    /** POST /admin/stories/split: 붙여 넣은 본문을 문장 행으로 나눈다(JSON) */
    public function split(): array
    {
        require_admin();
        $body = (string) input('body', '');
        if (mb_strlen($body) > 100000) {
            return ['ok' => false, 'error' => '본문이 너무 깁니다(10만 자 이하).'];
        }
        $rows = [];
        foreach (Text::splitSentences($body) as $s) {
            $rows[] = ['content' => $s, 'keywords' => ''];
        }

        return ['ok' => true, 'rows' => $rows, 'count' => count($rows)];
    }

    /** POST /admin/stories/{id}/delete: 숨김 + 소프트 삭제(관리 코드는 비워 다시 쓸 수 있게 한다) */
    public function destroy(string $id): void
    {
        require_admin();
        $story = $this->findStory((int) $id);
        db_update('stories', ['deleted_at' => now(), 'status' => 'hidden', 'code' => null], 'id = ?', [(int) $story['id']]);
        admin_audit('story.delete', 'story', (int) $story['id'], ['code' => $story['code'], 'title' => $story['title']]);
        flash('success', '「' . $story['title'] . '」을(를) 삭제했습니다. 회원 화면에서 더 이상 보이지 않습니다.');
        redirect('/admin/stories');
    }

    /** POST /admin/stories/{id}/timecodes: 현재 본문으로 만든 최신 완료 오디오의 문장 시각을 기준 타임코드로 복사한다. */
    public function importTimecodes(string $id): void
    {
        require_admin();
        $story = $this->findStory((int) $id);
        $audio = $story['content_hash'] === null ? null : db_one(
            "SELECT sa.id, sa.sentence_timings, vp.label FROM story_audios sa JOIN voice_profiles vp ON vp.id = sa.voice_profile_id
              WHERE sa.story_id = ? AND sa.status = 'completed' AND sa.content_hash = ? AND sa.sentence_timings IS NOT NULL
              ORDER BY sa.generated_at DESC, sa.id DESC LIMIT 1",
            [(int) $story['id'], $story['content_hash']]
        );
        if (!$audio) {
            flash('error', '현재 본문으로 만든 완료 오디오가 없어 타임코드를 가져올 수 없습니다.');
            redirect('/admin/stories/' . (int) $story['id'] . '#sentences');
        }
        $timings = json_decode_array($audio['sentence_timings']);
        $n = 0;
        db_tx(function () use ($timings, $story, &$n) {
            foreach (isset($timings['sentences']) && is_array($timings['sentences']) ? $timings['sentences'] : [] as $t) {
                if (!isset($t['seq'], $t['start'])) {
                    continue;
                }
                $n += db_exec(
                    'UPDATE story_sentences SET ref_start_ms = ?, ref_end_ms = ? WHERE story_id = ? AND seq = ?',
                    [max(0, (int) $t['start']), isset($t['end']) ? max(0, (int) $t['end']) : null, (int) $story['id'], (int) $t['seq']]
                );
            }
        });
        admin_audit('story.timecodes', 'story', (int) $story['id'], ['story_audio_id' => (int) $audio['id'], 'sentences' => $n]);
        flash('success', $audio['label'] . ' 목소리 오디오에서 문장 ' . $n . '개의 타임코드를 가져왔습니다.');
        redirect('/admin/stories/' . (int) $story['id'] . '#sentences');
    }

    /** 오디오를 만들 수 있는 목소리(ElevenLabs 목소리가 있고 처리 중 또는 완료) */
    private function deployableVoices(): array
    {
        return db_all(
            "SELECT vp.id, vp.label, vp.status, u.name AS user_name FROM voice_profiles vp JOIN users u ON u.id = vp.user_id
              WHERE vp.deleted_at IS NULL AND vp.provider_voice_id IS NOT NULL AND vp.provider_voice_id <> ''
                AND vp.status IN ('processing', 'completed')
              ORDER BY vp.id"
        );
    }

    /** POST /admin/stories/{id}/regenerate  mode=stale(옛 본문, 없는 것만) | all(전체 강제) */
    public function regenerate(string $id)
    {
        require_admin();
        $story = $this->findStory((int) $id);
        $mode = (string) input('mode', 'stale') === 'all' ? 'all' : 'stale';
        if ($story['status'] !== 'published') {
            return $this->respond(false, '활성(공개) 상태인 동화만 오디오를 만들 수 있습니다.');
        }
        $queued = 0;
        $failed = 0;
        $voices = $this->deployableVoices();
        foreach ($voices as $v) {
            try {
                $queued += VoiceService::queueStories((int) $v['id'], [(int) $story['id']], $mode === 'all');
            } catch (\Throwable $e) {
                $failed++;
                app_log('error', '동화 오디오 재생성 등록 실패', ['voice' => (int) $v['id'], 'story' => (int) $story['id'], 'error' => $e->getMessage()]);
            }
        }
        admin_audit('story.regenerate', 'story', (int) $story['id'], ['mode' => $mode, 'voices' => count($voices), 'queued' => $queued, 'failed' => $failed]);
        $msg = $queued > 0
            ? '「' . $story['title'] . '」 오디오 ' . $queued . '건을 생성 대기열에 넣었습니다.'
            : ($voices ? '다시 만들 오디오가 없습니다. 모두 최신 본문 기준입니다.' : '오디오를 만들 목소리가 아직 없습니다.');

        return $this->respond(true, $msg . ($failed ? ' (등록 실패 ' . $failed . '건, 로그 확인)' : ''), ['queued' => $queued]);
    }

    /** POST /admin/stories/regenerate-stale: 모든 공개 동화에서 옛 본문, 누락 오디오만 다시 만든다. */
    public function regenerateStale()
    {
        require_admin();
        $queued = 0;
        $failed = 0;
        $voices = $this->deployableVoices();
        foreach ($voices as $v) {
            try {
                $queued += VoiceService::queueStories((int) $v['id'], null, false);
            } catch (\Throwable $e) {
                $failed++;
                app_log('error', '동화 오디오 일괄 재생성 등록 실패', ['voice' => (int) $v['id'], 'error' => $e->getMessage()]);
            }
        }
        admin_audit('story.regenerate_stale', null, null, ['voices' => count($voices), 'queued' => $queued, 'failed' => $failed]);
        $msg = $queued > 0 ? '오래되었거나 없는 동화 오디오 ' . $queued . '건을 생성 대기열에 넣었습니다.' : '다시 만들 오디오가 없습니다.';

        return $this->respond(true, $msg . ($failed ? ' (등록 실패 ' . $failed . '건, 로그 확인)' : ''), ['queued' => $queued]);
    }

    /** GET /admin/api/stories/deploy-status: 동화별 목소리 오디오 최신 여부(배포 상태 점검 창) */
    public function deployStatus(): array
    {
        require_admin();
        $voices = $this->deployableVoices();
        $stories = db_all('SELECT id, code, title, sort_order, status, content_hash, char_count FROM stories WHERE deleted_at IS NULL ORDER BY sort_order, id');
        $audio = [];
        if ($voices) {
            $ids = array_map(static function ($v) {
                return (int) $v['id'];
            }, $voices);
            foreach (db_all(
                'SELECT story_id, voice_profile_id, status, content_hash FROM story_audios WHERE voice_profile_id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                $ids
            ) as $a) {
                $audio[(int) $a['story_id']][(int) $a['voice_profile_id']] = $a;
            }
        }
        $rows = [];
        $totals = ['fresh' => 0, 'outdated' => 0, 'working' => 0, 'missing' => 0];
        foreach ($stories as $s) {
            $r = ['story' => $s, 'fresh' => [], 'outdated' => [], 'working' => [], 'missing' => []];
            foreach ($voices as $v) {
                $a = isset($audio[(int) $s['id']][(int) $v['id']]) ? $audio[(int) $s['id']][(int) $v['id']] : null;
                $state = self::audioState($a, $s['content_hash']);
                $r[$state][] = $v['label'] . ' · ' . $v['user_name'];
                $totals[$state]++;
            }
            $rows[] = $r;
        }

        return [
            'ok' => true,
            'html' => partial('admin/stories/_deploy', ['rows' => $rows, 'voices' => $voices, 'totals' => $totals]),
            'totals' => $totals,
        ];
    }

    /** 오디오 한 건의 배포 상태: fresh | outdated | working | missing */
    public static function audioState(?array $audio, ?string $storyHash): string
    {
        if (!$audio || $audio['status'] === 'failed') {
            return 'missing';
        }
        if ($audio['status'] === 'pending' || $audio['status'] === 'processing') {
            return 'working';
        }
        if ($audio['status'] === 'completed' && $storyHash !== null && $audio['content_hash'] === $storyHash) {
            return 'fresh';
        }

        return 'outdated';
    }

    /** fetch 요청이면 JSON, 아니면 알림과 함께 직전 화면으로 */
    private function respond(bool $ok, string $message, array $extra = [])
    {
        if (Request::wantsJson()) {
            if ($ok) {
                return array_merge(['ok' => true, 'message' => $message], $extra);
            }
            json_error($message, 422);

            return null;
        }
        flash($ok ? 'success' : 'error', $message);
        redirect_back('/admin/stories');

        return null;
    }
}
