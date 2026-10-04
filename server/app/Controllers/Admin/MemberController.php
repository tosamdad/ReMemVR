<?php
namespace App\Controllers\Admin;

use App\Core\Request;
use App\Services\MemberStats;

/**
 * 회원 및 통계. 상단 지표, 구간별 끼어들기 히트맵, 응답 지연, 회원 인터랙션 표, 대화 로그 창, 회원 상세(이용 정지, 메모).
 */
class MemberController
{
    const PER_PAGE = 20;

    /** GET /admin/members */
    public function index(): string
    {
        require_admin();
        $filters = $this->filters();
        $page = max(1, Request::int('page', 1));

        return view('admin/members/index', [
            'kpi' => MemberStats::kpis(30),
            'heatmaps' => MemberStats::heatmaps(3),
            'peaks' => MemberStats::peakSentences(2),
            'latency' => MemberStats::latency(200),
            'list' => MemberStats::members($filters, $page, self::PER_PAGE),
            'filters' => $filters,
        ]);
    }

    /** GET /admin/members/export.csv (목록과 같은 조건, 엑셀에서 한글이 깨지지 않게 UTF-8 BOM) */
    public function export(): void
    {
        require_admin();
        $filters = $this->filters();
        admin_audit('member.export', null, null, $filters);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $name = 'rememvr-members-' . date('Ymd-His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"" . $name . "\"; filename*=UTF-8''" . rawurlencode($name));
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['회원 ID', '이름', '이메일', '가입 경로', '가입일', '최근 로그인', '자녀', '등록된 목소리', '주 이용 시간대', '재생 수', '완독 수', '총 청취 시간(시간)', '질문 수', '끼어들기 빈도(회/편)', '상태'], ',', '"', '');
        MemberStats::eachMember($filters, function (array $row) use ($out) {
            $u = $row['user'];
            $children = array_map([MemberStats::class, 'childText'], $row['children']);
            $voices = array_map(static function ($v) {
                return $v['label'] . '(' . voice_status_label((string) $v['status']) . ')';
            }, $row['voices']);
            fputcsv($out, [
                $row['code'],
                self::csvCell((string) $u['name']),
                self::csvCell((string) $u['email']),
                (string) $u['signup_provider'],
                substr((string) $u['created_at'], 0, 10),
                (string) $u['last_login_at'],
                self::csvCell(implode(' / ', $children)),
                self::csvCell($voices ? implode(' / ', $voices) : '미등록'),
                $row['hour_text'],
                $row['sessions'],
                $row['completed'],
                number_format($row['listened_hours'], 1, '.', ''),
                $row['questions'],
                $row['per_story'] === null ? '' : number_format($row['per_story'], 1, '.', ''),
                $row['status']['label'],
            ], ',', '"', '');
        });
        fclose($out);
        exit;
    }

    /** 엑셀 수식 주입 방지: =, +, -, @ 로 시작하는 값 앞에 작은따옴표를 붙인다. */
    public static function csvCell(string $v): string
    {
        return $v !== '' && strpos('=+-@', $v[0]) !== false ? "'" . $v : $v;
    }

    /** GET /admin/members/{id}: 회원 상세(자녀, 목소리, 최근 재생, 메모, 이용 정지) */
    public function show(string $id): string
    {
        require_admin();
        $user = $this->findUser((int) $id);
        $row = MemberStats::decorate([$user]);
        $sessions = db_all(
            'SELECT ps.*, s.title AS story_title, vp.label AS voice_label, c.name AS child_name,
                    (SELECT COUNT(*) FROM interactions i WHERE i.play_session_id = ps.id) AS asks
               FROM play_sessions ps
               JOIN stories s ON s.id = ps.story_id
               LEFT JOIN voice_profiles vp ON vp.id = ps.voice_profile_id
               LEFT JOIN children c ON c.id = ps.child_id
              WHERE ps.user_id = ? ORDER BY ps.started_at DESC, ps.id DESC LIMIT 15',
            [(int) $user['id']]
        );
        $voices = db_all('SELECT * FROM voice_profiles WHERE user_id = ? ORDER BY deleted_at IS NOT NULL, id', [(int) $user['id']]);
        $inquiries = db_all('SELECT id, title, status, created_at FROM inquiries WHERE user_id = ? ORDER BY id DESC LIMIT 5', [(int) $user['id']]);
        $social = db_all('SELECT provider, email, last_login_at FROM user_social_accounts WHERE user_id = ?', [(int) $user['id']]);

        return view('admin/members/show', [
            'member' => $row[0],
            'user' => $user,
            'sessions' => $sessions,
            'voices' => $voices,
            'inquiries' => $inquiries,
            'social' => $social,
        ]);
    }

    /** POST /admin/members/{id}/status  status=active|blocked */
    public function status(string $id): void
    {
        require_admin();
        $user = $this->findUser((int) $id);
        $status = (string) input('status', '');
        if (!in_array($status, ['active', 'blocked'], true)) {
            flash('error', '바꿀 상태가 올바르지 않습니다.');
            redirect('/admin/members/' . (int) $user['id']);
        }
        if ($user['status'] === 'withdrawn' || $user['deleted_at'] !== null) {
            flash('error', '탈퇴한 회원의 상태는 바꿀 수 없습니다.');
            redirect('/admin/members/' . (int) $user['id']);
        }
        if ($user['status'] !== $status) {
            db_update('users', ['status' => $status], 'id = ?', [(int) $user['id']]);
            admin_audit($status === 'blocked' ? 'member.block' : 'member.unblock', 'user', (int) $user['id'], [
                'from' => $user['status'],
                'to' => $status,
                'reason' => str_limit(trim((string) input('reason', '')), 200),
            ]);
        }
        flash('success', $status === 'blocked' ? '이용을 정지했습니다. 이 회원은 다음 요청부터 로그인 상태가 풀립니다.' : '이용 정지를 해제했습니다.');
        redirect('/admin/members/' . (int) $user['id']);
    }

    /** POST /admin/members/{id}/memo */
    public function memo(string $id): void
    {
        require_admin();
        $user = $this->findUser((int) $id);
        $memo = trim(str_replace("\r\n", "\n", (string) input('admin_memo', '')));
        if (mb_strlen($memo) > 2000) {
            back_with_errors(['admin_memo' => '메모는 2000자 이하로 입력해 주세요.'], '/admin/members/' . (int) $user['id']);
        }
        db_update('users', ['admin_memo' => $memo !== '' ? $memo : null], 'id = ?', [(int) $user['id']]);
        admin_audit('member.memo', 'user', (int) $user['id'], ['length' => mb_strlen($memo)]);
        flash('success', '관리자 메모를 저장했습니다.');
        redirect('/admin/members/' . (int) $user['id']);
    }

    /** GET /admin/api/members/{id}/log → {ok, html, ids} 대화 로그 창 본문 */
    public function log(string $id): array
    {
        require_admin();
        $log = MemberStats::memberLog((int) $id, 30);
        if ($log === null) {
            json_error('회원을 찾을 수 없습니다.', 404);
            exit;
        }

        return [
            'ok' => true,
            'html' => partial('admin/members/_log', ['log' => $log, 'code' => MemberStats::memberCode((int) $id)]),
            'ids' => $log['ids'],
        ];
    }

    /** POST /admin/interactions/review  ids[] → reviewed_at */
    public function review(): array
    {
        require_admin();
        $ids = input('ids', []);
        if (!is_array($ids)) {
            $ids = explode(',', (string) $ids);
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($v) {
            return $v > 0;
        })));
        $ids = array_slice($ids, 0, 500);
        if (!$ids) {
            return ['ok' => true, 'message' => '검토할 새 기록이 없습니다.', 'count' => 0];
        }
        $n = db_exec(
            'UPDATE interactions SET reviewed_at = NOW() WHERE reviewed_at IS NULL AND id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        admin_audit('interaction.review', 'interaction', $ids[0], ['count' => $n, 'ids' => array_slice($ids, 0, 50)]);

        return ['ok' => true, 'message' => '질문 기록 ' . $n . '건을 검토 완료로 표시했습니다.', 'count' => $n];
    }

    private function filters(): array
    {
        $voice = Request::str('voice');

        return [
            'q' => mb_substr(Request::str('q'), 0, 100),
            'voice' => isset(MemberStats::VOICE_FILTERS[$voice]) ? $voice : '',
        ];
    }

    private function findUser(int $id): array
    {
        $user = db_one('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            abort(404, '회원을 찾을 수 없습니다.');
        }

        return $user;
    }
}
