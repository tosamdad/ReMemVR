<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;
use App\Services\Playlists;
use App\Services\StoryRequests;
use App\Services\VoiceService;

/**
 * 내 동화: 회원이 보낸 동화 생성 요청 목록.
 * 확인 대기와 만드는 중인 요청은 진행 상태를, 완성된 동화는 바로 듣기와 플레이리스트 담기를,
 * 반려된 요청은 사유와 다시 요청하기를 보여 준다.
 */
class LibraryController
{
    const TABS = [
        'all' => '전체',
        'making' => '만드는 중',
        'done' => '완성',
        'rejected' => '반려',
    ];

    /** GET /library?tab= */
    public function index(): string
    {
        $user = require_user();
        if (Auth::child() === null) {
            redirect('/onboarding');
        }
        $userId = (int) $user['id'];
        $tab = Request::str('tab', 'all');
        if (!isset(self::TABS[$tab])) {
            $tab = 'all';
        }

        $readyVoices = (int) db_value(
            'SELECT COUNT(*) FROM voice_profiles vp WHERE vp.user_id = ? AND vp.deleted_at IS NULL AND ' . VoiceService::readySql('vp'),
            [$userId]
        );
        $anyVoices = (int) db_value('SELECT COUNT(*) FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL', [$userId]);

        return view('user/library/index', [
            'tab' => $tab,
            'counts' => StoryRequests::userCounts($userId),
            'rows' => StoryRequests::forUser($userId, $tab),
            'playlists' => Playlists::forUser($userId),
            'readyVoices' => $readyVoices,
            'anyVoices' => $anyVoices,
            'autoApprove' => StoryRequests::autoApprove(),
        ]);
    }

    /**
     * GET /api/requests/status?ids=1,2,3 : 내 요청들의 지금 진행 단계.
     * 동화 상세와 내 동화 화면이 확인 대기, 만드는 중인 요청을 몇 초마다 확인해 바뀌면 화면을 다시 그린다.
     */
    public function status(): array
    {
        $user = require_user();
        $ids = explode(',', (string) Request::query('ids', ''));

        return ['ok' => true, 'states' => (object) StoryRequests::statesFor((int) $user['id'], $ids)];
    }

    /** POST /library/requests/{id}/cancel (back) : 확인 대기 중인 요청 취소 */
    public function cancel(string $id): void
    {
        $user = require_user();
        $back = safe_next(Request::str('back'), '/library');
        try {
            StoryRequests::cancel((int) $user['id'], (int) $id);
            flash('success', '요청을 취소했어요.');
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
        }
        redirect($back);
    }
}
