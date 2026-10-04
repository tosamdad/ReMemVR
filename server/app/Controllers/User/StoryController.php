<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Services\Progress;

/** 동화 전체 목록: 분류 칩, 제목 검색, 다 들은 표시, 이어 듣기 진행률 */
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
            $s['url'] = $s['resume'] ? $s['resume']['url'] : url('/player/' . $sid);
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
