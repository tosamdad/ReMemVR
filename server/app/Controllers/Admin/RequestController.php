<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Services\ElevenLabs;
use App\Services\MemberStats;
use App\Services\StoryRequests;
use App\Services\Worker;

/**
 * 동화 생성 요청 관리. 회원이 동화와 가족 목소리를 골라 보낸 요청을 확인하고
 * 생성을 시작(ElevenLabs 음성 합성 작업 등록)하거나 반려한다.
 * 완성된 동화는 회원의 내 동화 화면에 나타나고, 회원에게 완성 메일이 간다.
 */
class RequestController
{
    const PER_PAGE = 30;
    const TABS = [
        'requested' => '확인 대기',
        'making' => '생성 중',
        'failed' => '생성 실패',
        'done' => '완성',
        'rejected' => '반려',
        'canceled' => '회원 취소',
        'all' => '전체',
    ];
    /** 상태 칩 색(Tailwind 가 이 파일에서 찾도록 적어 둠) */
    const CHIPS = [
        'requested' => 'bg-secondary-container text-on-secondary-container',
        'making' => 'bg-primary-fixed text-primary',
        'failed' => 'bg-error-container text-on-error-container',
        'done' => 'bg-emerald-100 text-emerald-800',
        'rejected' => 'bg-surface-container-high text-on-surface-variant',
        'canceled' => 'bg-surface-container-high text-outline',
    ];

    /** GET /admin/requests (?status, q, voice, page) */
    public function index(): string
    {
        require_admin();
        $tab = Request::str('status', 'requested');
        if (!isset(self::TABS[$tab])) {
            $tab = 'requested';
        }
        $q = mb_substr(Request::str('q'), 0, 100);
        $voiceId = Request::int('voice');

        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $code = MemberStats::parseMemberId($q);
            $where[] = '(s.title LIKE ? OR u.name LIKE ? OR u.email LIKE ? OR vp.label LIKE ?' . ($code ? ' OR r.user_id = ?' : '') . ')';
            array_push($params, $like, $like, $like, $like);
            if ($code) {
                $params[] = $code;
            }
        }
        if ($voiceId > 0) {
            $where[] = 'r.voice_profile_id = ?';
            $params[] = $voiceId;
        }
        $base = implode(' AND ', $where);
        $from = ' FROM story_requests r
              JOIN stories s ON s.id = r.story_id
              JOIN voice_profiles vp ON vp.id = r.voice_profile_id
              JOIN users u ON u.id = r.user_id
              LEFT JOIN story_audios sa ON sa.story_id = r.story_id AND sa.voice_profile_id = r.voice_profile_id';

        $counts = [];
        foreach (array_keys(self::TABS) as $key) {
            $cond = $key === 'all' ? '1 = 1' : StoryRequests::stateWhere($key);
            $counts[$key] = (int) db_value('SELECT COUNT(*)' . $from . ' WHERE ' . $base . ' AND ' . $cond, $params);
        }

        $listWhere = $base . ($tab === 'all' ? '' : ' AND ' . StoryRequests::stateWhere($tab));
        $total = $counts[$tab];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, Request::int('page', 1)));
        // 확인 대기와 생성 중은 오래된 요청부터, 나머지는 최근 순
        $order = in_array($tab, ['requested', 'making', 'failed'], true) ? 'r.created_at ASC, r.id ASC' : 'COALESCE(r.processed_at, r.created_at) DESC, r.id DESC';
        $rows = db_all(
            'SELECT r.*, s.title AS story_title, s.char_count AS story_chars, s.status AS story_status, s.deleted_at AS story_deleted_at,
                    vp.label AS voice_label, vp.icon AS voice_icon, vp.status AS voice_status, vp.deleted_at AS voice_deleted_at,
                    (vp.provider_voice_id IS NOT NULL AND vp.provider_voice_id <> \'\') AS voice_has_provider,
                    u.name AS user_name, u.email AS user_email, a.name AS admin_name,
                    sa.id AS audio_id, sa.status AS audio_status, sa.file_path AS audio_file, sa.duration_ms AS audio_duration_ms,
                    sa.error_message AS audio_error'
            . $from . ' LEFT JOIN admins a ON a.id = r.processed_by WHERE ' . $listWhere . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            array_merge($params, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        foreach ($rows as &$r) {
            $r['state'] = StoryRequests::state($r);
        }
        unset($r);

        $voice = $voiceId > 0 ? db_one('SELECT id, label FROM voice_profiles WHERE id = ?', [$voiceId]) : null;

        return view('admin/requests/index', [
            'rows' => $rows,
            'tab' => $tab,
            'counts' => $counts,
            'filters' => ['q' => $q, 'voice' => $voice ? (int) $voice['id'] : 0],
            'voice' => $voice,
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'elReady' => ElevenLabs::ready(),
        ]);
    }

    /** POST /admin/requests/approve (ids[]) : 생성 시작(실패, 반려 요청은 다시 만든다) */
    public function approve(): void
    {
        $admin = require_admin();
        $raw = input('ids', []);
        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : [$raw])));
        if (!$ids) {
            flash('error', '생성할 요청을 하나 이상 고르세요.');
            redirect_back('/admin/requests');
        }
        try {
            $res = StoryRequests::approve($ids, (int) $admin['id']);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect_back('/admin/requests');

            return;
        }
        admin_audit('request.approve', 'story_request', count($ids) === 1 ? $ids[0] : null, ['ids' => $ids, 'approved' => $res['approved']]);
        if ($res['approved'] > 0) {
            Worker::kick();
            flash('success', '요청 ' . $res['approved'] . '건의 동화 생성을 시작했습니다. 완성되면 회원에게 메일로 알립니다.'
                . ($res['errors'] ? ' 건너뜀: ' . implode(' / ', array_slice($res['errors'], 0, 3)) : ''));
        } else {
            flash('error', $res['errors'] ? implode(' / ', array_slice($res['errors'], 0, 3)) : '생성할 수 있는 요청이 없습니다.');
        }
        redirect_back('/admin/requests');
    }

    /** POST /admin/requests/{id}/reject (reason) */
    public function reject(string $id): void
    {
        $admin = require_admin();
        $reason = Request::str('reason');
        try {
            StoryRequests::reject((int) $id, (int) $admin['id'], $reason);
        } catch (\RuntimeException $e) {
            flash('error', $e->getMessage());
            redirect_back('/admin/requests');

            return;
        }
        admin_audit('request.reject', 'story_request', (int) $id, ['reason' => mb_substr($reason, 0, 255)]);
        flash('success', StoryRequests::reqId($id) . ' 요청을 반려했습니다. 회원의 내 동화 화면에 사유가 보입니다.');
        redirect_back('/admin/requests');
    }
}
