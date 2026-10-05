<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Services\Progress;

/** 홈: 인사, 학습 현황, 오늘의 추천 동화, 우리 가족 목소리 */
class HomeController
{
    public function index(): string
    {
        $user = require_user();
        $child = Auth::child();
        if ($child === null) {
            redirect('/onboarding');
        }
        $userId = (int) $user['id'];
        $scope = Progress::currentScope();

        $level = Progress::levelFor($scope);
        $weekBooks = Progress::weekBooks($scope);

        // 오늘의 추천 동화
        $stories = db_all(
            "SELECT id, title, category, cover_image_path, est_duration_sec, char_count, updated_at
             FROM stories WHERE status = 'published' AND deleted_at IS NULL ORDER BY sort_order, id"
        );
        $completedIds = Progress::completedStoryIds($scope);
        $recommended = Progress::recommend($stories, $completedIds, (int) date('G'), date('Y-m-d'), 8);
        $durations = Progress::audioDurations($userId, array_column($recommended, 'id'));
        foreach ($recommended as &$s) {
            $sid = (int) $s['id'];
            $s['duration_label'] = Progress::durationLabel(Progress::durationSec($s, isset($durations[$sid]) ? $durations[$sid] : null));
            $s['completed'] = in_array($sid, $completedIds, true);
        }
        unset($s);

        // 우리 가족 목소리
        $voices = db_all(
            'SELECT id, label, icon, status FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL ORDER BY id',
            [$userId]
        );
        foreach ($voices as &$v) {
            $v['status_line'] = self::voiceStatusLine($v);
        }
        unset($v);
        $maxVoices = (int) setting('voice.max_per_user', 5);

        return view('user/home', [
            'user' => $user,
            'child' => $child,
            'level' => $level,
            'weekBooks' => $weekBooks,
            'recommended' => $recommended,
            'voices' => $voices,
            'canAddVoice' => count($voices) < $maxVoices,
        ]);
    }

    /** 홈 목소리 칸의 상태 문구와 색 */
    private static function voiceStatusLine(array $v): array
    {
        $status = (string) $v['status'];
        if ($status === 'completed' || $status === 'processing') {
            return ['text' => '준비됨', 'class' => 'text-secondary', 'dot' => true];
        }
        $map = [
            'cloning' => ['목소리 만드는 중', 'text-primary'],
            'pending' => ['검토 대기', 'text-on-surface-variant'],
            'rejected' => ['재녹음 필요', 'text-error'],
            'draft' => ['녹음 이어하기', 'text-on-surface-variant'],
            'failed' => ['다시 시도 필요', 'text-error'],
        ];
        if (isset($map[$status])) {
            return ['text' => $map[$status][0], 'class' => $map[$status][1], 'dot' => false];
        }

        return ['text' => voice_status_label($status), 'class' => 'text-on-surface-variant', 'dot' => false];
    }
}
