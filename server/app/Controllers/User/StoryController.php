<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Services\Progress;
use App\Services\StoryRequests;

/**
 * 동화 책장: 분류 칩, 제목 검색, 다 들은 표시, 이어 듣기 진행률.
 * 동화를 누르면 상세 화면에서 가족 목소리를 골라 생성 요청을 하고, 완성된 목소리로 바로 듣는다.
 */
class StoryController
{
    /** 분류 칩 기본 순서(DB 에 있는 분류만 보인다) */
    const CATEGORY_ORDER = ['취침 전', '모험', '용기', '상상', '우정'];

    public function index(): string
    {
        $user = require_user();
        if (Auth::child() === null) {
            redirect('/onboarding');
        }
        $userId = (int) $user['id'];
        $scope = Progress::currentScope();

        $category = Request::str('category');
        $q = mb_substr(Request::str('q'), 0, 50);

        $categories = self::categories();
        if ($category !== '' && !in_array($category, $categories, true)) {
            $category = '';
        }

        $where = "status = 'published' AND deleted_at IS NULL";
        $params = [];
        if ($category !== '') {
            $where .= ' AND category = ?';
            $params[] = $category;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (title LIKE ? OR summary LIKE ? OR author LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $stories = db_all(
            "SELECT id, title, summary, category, cover_image_path, est_duration_sec, char_count, updated_at
             FROM stories WHERE $where ORDER BY sort_order, id",
            $params
        );

        $completed = array_flip(Progress::completedStoryIds($scope));
        $resume = Progress::resumeStates($scope);
        $durations = Progress::audioDurations($userId, array_column($stories, 'id'));
        // 동화별 내 요청 현황(완성, 만드는 중)
        $mine = [];
        foreach (StoryRequests::forUser($userId) as $r) {
            $sid = (int) $r['story_id'];
            if (!isset($mine[$sid])) {
                $mine[$sid] = ['done' => 0, 'making' => 0];
            }
            if ($r['state'] === 'done') {
                $mine[$sid]['done']++;
            } elseif (in_array($r['state'], ['requested', 'making', 'failed'], true)) {
                $mine[$sid]['making']++;
            }
        }
        foreach ($stories as &$s) {
            $sid = (int) $s['id'];
            $sec = Progress::durationSec($s, isset($durations[$sid]) ? $durations[$sid] : null);
            $s['duration_label'] = Progress::durationLabel($sec);
            $s['completed'] = isset($completed[$sid]);
            $s['resume'] = null;
            if (isset($resume[$sid])) {
                $r = $resume[$sid];
                $s['resume'] = [
                    'percent' => $sec > 0 ? max(3, min(97, (int) round($r['position_ms'] / ($sec * 10)))) : null,
                    'url' => url('/player/' . $sid, ['voice' => $r['voice'], 't' => $r['position_ms'], 's' => $r['sentence_seq']]),
                ];
            }
            $s['url'] = url('/stories/' . $sid);
            $s['mine'] = isset($mine[$sid]) ? $mine[$sid] : ['done' => 0, 'making' => 0];
        }
        unset($s);

        return view('user/stories/index', [
            'stories' => $stories,
            'categories' => $categories,
            'category' => $category,
            'q' => $q,
            'total' => (int) db_value("SELECT COUNT(*) FROM stories WHERE status = 'published' AND deleted_at IS NULL"),
        ]);
    }

    /** GET /stories/{id}: 동화 소개, 가족 목소리별 상태(완성이면 듣기, 아니면 생성 요청) */
    public function show(string $id): string
    {
        $user = require_user();
        if (Auth::child() === null) {
            redirect('/onboarding');
        }
        $userId = (int) $user['id'];
        $story = db_one("SELECT * FROM stories WHERE id = ? AND status = 'published' AND deleted_at IS NULL", [(int) $id]);
        if (!$story) {
            abort(404, '동화를 찾을 수 없어요.');
        }
        $sid = (int) $story['id'];
        $scope = Progress::currentScope();
        $durations = Progress::audioDurations($userId, [$sid]);
        $sec = Progress::durationSec($story, isset($durations[$sid]) ? $durations[$sid] : null);
        $resume = Progress::resumeStates($scope);
        $resumeUrl = isset($resume[$sid])
            ? url('/player/' . $sid, ['voice' => $resume[$sid]['voice'], 't' => $resume[$sid]['position_ms'], 's' => $resume[$sid]['sentence_seq']])
            : null;

        return view('user/stories/show', [
            'story' => $story,
            'durationLabel' => Progress::durationLabel($sec),
            'voices' => StoryRequests::voiceStates($userId, $sid),
            'autoApprove' => StoryRequests::autoApprove(),
            'completed' => in_array($sid, Progress::completedStoryIds($scope), true),
            'resumeUrl' => $resumeUrl,
        ]);
    }

    /** POST /stories/{id}/request (voice_ids[]) : 고른 가족 목소리로 생성 요청 */
    public function request(string $id): void
    {
        $user = require_user();
        $raw = input('voice_ids', []);
        $ids = is_array($raw) ? $raw : [$raw];
        try {
            $res = StoryRequests::create((int) $user['id'], (int) $id, $ids);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect('/stories/' . (int) $id);

            return;
        }
        $n = count($res['created']);
        if ($n > 0) {
            // 바로 만드는 설정이면 생성을 시작했다고, 아니면 운영팀 확인을 기다린다고 알린다.
            $msg = !empty($res['approved'])
                ? '동화 만들기를 시작했어요. 다 만들어지면 이 화면에서 완성으로 바뀌고 메일로도 알려 드려요.'
                : '생성 요청을 보냈어요. 운영팀이 확인한 뒤 만들어 드릴게요.';
            if ($res['skipped']) {
                $msg .= ' (' . implode(', ', array_map(static function ($s) {
                    return $s['voice'] . ': ' . $s['reason'];
                }, $res['skipped'])) . ')';
            }
            flash('success', $msg);
        } else {
            flash('info', $res['skipped'] ? implode(', ', array_map(static function ($s) {
                return $s['voice'] . ' 목소리는 ' . $s['reason'];
            }, $res['skipped'])) . '.' : '요청할 목소리를 골라 주세요.');
        }
        redirect('/stories/' . (int) $id);
    }

    /** 게시된 동화의 분류 목록(기본 순서 먼저, 나머지는 가나다순) */
    public static function categories(): array
    {
        $list = array_map('strval', array_column(db_all(
            "SELECT DISTINCT category FROM stories WHERE status = 'published' AND deleted_at IS NULL AND category IS NOT NULL AND category <> ''"
        ), 'category'));
        usort($list, static function ($a, $b) {
            $ia = array_search($a, self::CATEGORY_ORDER, true);
            $ib = array_search($b, self::CATEGORY_ORDER, true);
            $ia = $ia === false ? 99 : $ia;
            $ib = $ib === false ? 99 : $ib;

            return $ia === $ib ? strcmp($a, $b) : $ia - $ib;
        });

        return $list;
    }
}
