<?php
namespace App\Controllers\Admin;

use App\Core\Mailer;
use App\Core\Request;
use App\Services\MemberStats;

/**
 * 1:1 문의. 회원은 /settings/support/inquiries/{id} 에서 답변을 본다.
 * 답변을 저장하면 상태가 '답변 완료'가 되고, 고르면 회원에게 메일로 알린다(App\Core\Mailer).
 */
class InquiryController
{
    const PER_PAGE = 20;
    const ANSWER_MAX = 5000;
    // 회원 문의 폼(설정 → 고객 센터)과 같은 분류
    const CATEGORIES = [
        'general' => '서비스 이용',
        'voice' => '목소리 등록',
        'playback' => '동화 재생, 질문',
        'account' => '계정, 로그인',
        'etc' => '기타',
    ];
    const TABS = ['open' => '대기', 'answered' => '답변 완료', 'closed' => '종료', 'all' => '전체'];
    const STATUS = [
        'open' => ['답변 대기', 'bg-tertiary-container text-on-tertiary-container'],
        'answered' => ['답변 완료', 'bg-emerald-100 text-emerald-800'],
        'closed' => ['종료', 'bg-surface-container-high text-on-surface-variant'],
    ];

    /** GET /admin/inquiries (?status=open|answered|closed|all, q, category, page) */
    public function index(): string
    {
        require_admin();
        $tab = Request::str('status', 'open');
        if (!isset(self::TABS[$tab])) {
            $tab = 'open';
        }
        $q = mb_substr(Request::str('q'), 0, 100);
        $category = Request::str('category');
        if (!isset(self::CATEGORIES[$category])) {
            $category = '';
        }
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $code = MemberStats::parseMemberId($q);
            $where[] = '(i.title LIKE ? OR i.body LIKE ? OR u.name LIKE ? OR u.email LIKE ?' . ($code ? ' OR i.user_id = ?' : '') . ')';
            array_push($params, $like, $like, $like, $like);
            if ($code) {
                $params[] = $code;
            }
        }
        if ($category !== '') {
            $where[] = 'i.category = ?';
            $params[] = $category;
        }
        $base = implode(' AND ', $where);
        $c = db_one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(i.status = 'open'), 0) AS open, COALESCE(SUM(i.status = 'answered'), 0) AS answered, COALESCE(SUM(i.status = 'closed'), 0) AS closed
             FROM inquiries i JOIN users u ON u.id = i.user_id WHERE " . $base,
            $params
        );
        $counts = ['open' => (int) $c['open'], 'answered' => (int) $c['answered'], 'closed' => (int) $c['closed'], 'all' => (int) $c['total']];
        $listWhere = $base;
        $listParams = $params;
        if ($tab !== 'all') {
            $listWhere .= ' AND i.status = ?';
            $listParams[] = $tab;
        }
        $total = $counts[$tab];
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, Request::int('page', 1)));
        // 대기 탭은 오래 기다린 문의부터, 나머지는 최근 순
        $order = $tab === 'open' ? 'i.created_at ASC, i.id ASC' : 'i.created_at DESC, i.id DESC';
        $rows = db_all(
            'SELECT i.id, i.user_id, i.category, i.title, i.body, i.status, i.answered_at, i.created_at,
                    u.name AS user_name, u.email AS user_email, u.status AS user_status, u.deleted_at AS user_deleted, a.name AS answerer
             FROM inquiries i JOIN users u ON u.id = i.user_id LEFT JOIN admins a ON a.id = i.answered_by
             WHERE ' . $listWhere . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?',
            array_merge($listParams, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        $avg = db_value(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, answered_at)) FROM inquiries
             WHERE answered_at IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        $oldest = db_value("SELECT MIN(created_at) FROM inquiries WHERE status = 'open'");

        return view('admin/inquiries/index', [
            'rows' => $rows,
            'tab' => $tab,
            'counts' => $counts,
            'filters' => ['q' => $q, 'category' => $category],
            'page' => $page,
            'pages' => $pages,
            'total' => $total,
            'avgMinutes' => $avg !== null ? (int) round((float) $avg) : null,
            'oldestOpen' => $oldest,
        ]);
    }

    /** GET /admin/inquiries/{id} */
    public function show(string $id): string
    {
        require_admin();
        $inq = self::find($id);
        $user = db_one('SELECT id, email, name, phone, status, created_at, last_login_at, deleted_at FROM users WHERE id = ?', [(int) $inq['user_id']]);
        $children = db_all('SELECT * FROM children WHERE user_id = ? ORDER BY sort_order, id', [(int) $inq['user_id']]);
        $voices = (int) db_value("SELECT COUNT(*) FROM voice_profiles WHERE user_id = ? AND deleted_at IS NULL AND status IN ('processing', 'completed')", [(int) $inq['user_id']]);
        $plays = (int) db_value('SELECT COUNT(*) FROM play_sessions WHERE user_id = ?', [(int) $inq['user_id']]);
        $others = db_all(
            'SELECT id, category, title, status, created_at FROM inquiries WHERE user_id = ? AND id <> ? ORDER BY created_at DESC, id DESC LIMIT 5',
            [(int) $inq['user_id'], (int) $inq['id']]
        );
        $answerer = $inq['answered_by'] ? db_value('SELECT name FROM admins WHERE id = ?', [(int) $inq['answered_by']]) : null;

        return view('admin/inquiries/show', [
            'inq' => $inq,
            'user' => $user,
            'children' => $children,
            'voices' => $voices,
            'plays' => $plays,
            'others' => $others,
            'answerer' => $answerer,
            'canMail' => self::mailable($user),
        ]);
    }

    /** POST /admin/inquiries/{id}: 답변 저장(+ 선택 시 메일 알림) */
    public function answer(string $id): void
    {
        $admin = require_admin();
        $inq = self::find($id);
        $raw = input('answer', '');
        $answer = trim(str_replace("\r\n", "\n", is_string($raw) ? $raw : ''));
        if ($answer === '') {
            back_with_errors(['answer' => '답변을 입력해 주세요.'], '/admin/inquiries/' . (int) $inq['id']);
        }
        if (mb_strlen($answer) > self::ANSWER_MAX) {
            back_with_errors(['answer' => '답변은 ' . number_format(self::ANSWER_MAX) . '자 이하로 입력해 주세요.'], '/admin/inquiries/' . (int) $inq['id']);
        }
        $edited = trim((string) $inq['answer']) !== '';
        db_update('inquiries', [
            'answer' => $answer,
            'status' => 'answered',
            'answered_by' => (int) $admin['id'],
            'answered_at' => now(),
        ], 'id = ?', [(int) $inq['id']]);

        $notify = in_array((string) input('notify', ''), ['1', 'on'], true);
        $mailed = false;
        if ($notify) {
            $user = db_one('SELECT id, email, name, status, deleted_at FROM users WHERE id = ?', [(int) $inq['user_id']]);
            if (self::mailable($user)) {
                $brand = (string) setting('app.brand', '르멤버');
                $mailed = Mailer::send(
                    (string) $user['email'],
                    '[' . $brand . '] 1:1 문의에 답변이 등록되었어요',
                    $user['name'] . "님, 안녕하세요.\n보내 주신 문의에 답변을 드려요.\n\n"
                    . '문의: ' . $inq['title'] . "\n\n"
                    . "답변:\n" . $answer . "\n\n"
                    . '앱에서 보기: ' . absolute_url('/settings/support/inquiries/' . (int) $inq['id']) . "\n"
                );
            }
        }
        admin_audit($edited ? 'inquiry.answer_edit' : 'inquiry.answer', 'inquiry', (int) $inq['id'], [
            'title' => str_limit((string) $inq['title'], 60),
            'notify' => $notify,
            'mailed' => $mailed,
        ]);
        $msg = $edited ? '답변을 수정했습니다.' : '답변을 등록했습니다.';
        if ($notify) {
            $msg .= $mailed ? ' 회원에게 메일로 알렸습니다.' : ' 메일은 보내지 못했습니다(회원 메일 주소 또는 메일 설정을 확인해 주세요).';
        }
        flash($notify && !$mailed ? 'info' : 'success', $msg);
        redirect('/admin/inquiries/' . (int) $inq['id']);
    }

    /** POST /admin/inquiries/{id}/status (action=close|reopen) */
    public function status(string $id): void
    {
        require_admin();
        $inq = self::find($id);
        $action = (string) input('action', '');
        if ($action === 'close') {
            $to = 'closed';
        } elseif ($action === 'reopen') {
            $to = trim((string) $inq['answer']) !== '' ? 'answered' : 'open';
        } else {
            flash('error', '알 수 없는 요청입니다.');
            redirect('/admin/inquiries/' . (int) $inq['id']);
        }
        if ($to !== $inq['status']) {
            db_update('inquiries', ['status' => $to], 'id = ?', [(int) $inq['id']]);
            admin_audit('inquiry.' . $action, 'inquiry', (int) $inq['id'], ['from' => $inq['status'], 'to' => $to]);
        }
        flash('success', $action === 'close' ? '문의를 종료했습니다.' : '문의를 다시 열었습니다.');
        redirect('/admin/inquiries/' . (int) $inq['id']);
    }

    public static function categoryLabel(?string $key): string
    {
        return $key !== null && isset(self::CATEGORIES[$key]) ? self::CATEGORIES[$key] : '기타';
    }

    /** 기다린 시간 문구(분 단위) */
    public static function waitText(int $minutes): string
    {
        if ($minutes < 60) {
            return max(1, $minutes) . '분';
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60) . '시간';
        }

        return intdiv($minutes, 1440) . '일 ' . intdiv($minutes % 1440, 60) . '시간';
    }

    private static function find(string $id): array
    {
        $row = db_one('SELECT * FROM inquiries WHERE id = ?', [(int) $id]);
        if (!$row) {
            abort(404, '문의를 찾을 수 없습니다.');
        }

        return $row;
    }

    /** 메일을 보낼 수 있는 회원인지(탈퇴, 삭제 제외) */
    private static function mailable(?array $user): bool
    {
        return $user !== null && empty($user['deleted_at']) && $user['status'] !== 'withdrawn'
            && filter_var((string) $user['email'], FILTER_VALIDATE_EMAIL) !== false;
    }
}
