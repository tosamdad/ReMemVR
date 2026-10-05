<?php
namespace App\Controllers\Admin;

use App\Core\Request;

/**
 * 감사 로그. 관리자, 작업 종류(앞부분 일치), 날짜로 거르고 50개씩 본다. 상세(detail)는 JSON 을 보기 좋게 펼친다.
 */
class AuditController
{
    const PER_PAGE = 50;

    const ACTION_LABELS = [
        'admin.login' => '관리자 로그인',
        'admin.logout' => '관리자 로그아웃',
        'admin.setup' => '최초 관리자 설정',
        'admin.create' => '관리자 추가',
        'admin.disable' => '관리자 사용 중지',
        'admin.enable' => '관리자 다시 사용',
        'admin.password_reset' => '관리자 비밀번호 초기화',
        'admin.password_change' => '내 비밀번호 변경',
        'voice.approve' => '목소리 승인',
        'voice.approve_all' => '목소리 일괄 승인',
        'voice.reject' => '목소리 반려',
        'voice.test' => '목소리 테스트 합성',
        'voice.refresh' => '목소리 상태 확인',
        'voice.params' => '목소리 설정 변경',
        'voice.batch' => '동화 일괄 생성(예전 방식)',
        'request.approve' => '동화 생성 시작',
        'request.reject' => '동화 생성 요청 반려',
        'user.memo' => '회원 메모',
        'member.memo' => '회원 메모',
        'member.block' => '회원 이용 정지',
        'member.unblock' => '회원 이용 정지 해제',
        'member.export' => '회원 목록 내보내기',
        'interaction.review' => '대화 로그 검토',
        'story.create' => '동화 등록',
        'story.update' => '동화 수정',
        'story.delete' => '동화 삭제',
        'story.timecodes' => '타임코드 가져오기',
        'story.regenerate' => '동화 오디오 다시 생성',
        'story.regenerate_stale' => '오래된 오디오 모두 다시 생성',
        'settings.update' => '운영 설정 변경',
        'settings.voice_clips' => '대체 음성 다시 만들기',
        'notice.create' => '공지 작성',
        'notice.update' => '공지 수정',
        'notice.delete' => '공지 삭제',
        'faq.create' => 'FAQ 추가',
        'faq.update' => 'FAQ 수정',
        'faq.delete' => 'FAQ 삭제',
        'faq.toggle' => 'FAQ 노출 변경',
        'faq.move' => 'FAQ 순서 변경',
        'inquiry.answer' => '문의 답변',
        'inquiry.answer_edit' => '문의 답변 수정',
        'inquiry.close' => '문의 종료',
        'inquiry.reopen' => '문의 다시 열기',
    ];
    const GROUP_LABELS = [
        'admin' => '관리자 계정',
        'voice' => '목소리',
        'member' => '회원',
        'user' => '회원 메모',
        'interaction' => '대화 로그',
        'story' => '동화',
        'settings' => '운영 설정',
        'notice' => '공지',
        'faq' => 'FAQ',
        'inquiry' => '1:1 문의',
        'job' => '작업',
    ];
    const TARGET_LABELS = [
        'voice_profile' => '목소리',
        'story' => '동화',
        'user' => '회원',
        'inquiry' => '문의',
        'notice' => '공지',
        'faq' => 'FAQ',
        'admin' => '관리자',
        'interaction' => '대화',
        'settings' => '설정',
        'job' => '작업',
    ];

    /** GET /admin/audit (?admin, action, from, to, page) */
    public function index(): string
    {
        require_admin();
        $errors = [];
        $adminRaw = Request::str('admin');
        $admin = ctype_digit($adminRaw) ? (int) $adminRaw : null;
        $actionRaw = mb_substr(Request::str('action'), 0, 50);
        $action = strtolower($actionRaw);
        if ($action !== '' && !preg_match('/^[a-z_.]{1,50}$/', $action)) {
            $errors['action'] = '작업 이름은 영문 소문자, 점, 밑줄로 입력해 주세요. 예) story. 또는 voice.approve';
            $action = '';
        }
        $from = Request::str('from');
        $to = Request::str('to');
        if ($from !== '' && !self::validDate($from)) {
            $errors['from'] = '날짜 형식이 올바르지 않습니다.';
            $from = '';
        }
        if ($to !== '' && !self::validDate($to)) {
            $errors['to'] = '날짜 형식이 올바르지 않습니다.';
            $to = '';
        }
        if ($from !== '' && $to !== '' && $from > $to) {
            $errors['to'] = '끝 날짜가 시작 날짜보다 빠릅니다.';
            $to = '';
        }

        $where = ['1 = 1'];
        $params = [];
        if ($admin !== null) {
            if ($admin === 0) {
                $where[] = 'l.admin_id IS NULL';
            } else {
                $where[] = 'l.admin_id = ?';
                $params[] = $admin;
            }
        }
        if ($action !== '') {
            $where[] = 'l.action LIKE ?';
            $params[] = addcslashes($action, '%_\\') . '%';
        }
        if ($from !== '') {
            $where[] = 'l.created_at >= ?';
            $params[] = $from . ' 00:00:00';
        }
        if ($to !== '') {
            $where[] = 'l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $to;
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) db_value('SELECT COUNT(*) FROM admin_audit_logs l WHERE ' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, Request::int('page', 1)));
        $rows = db_all(
            'SELECT l.*, a.name AS admin_name, a.login_id AS admin_login
             FROM admin_audit_logs l LEFT JOIN admins a ON a.id = l.admin_id
             WHERE ' . $sqlWhere . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        $groups = db_all("SELECT SUBSTRING_INDEX(action, '.', 1) AS g, COUNT(*) AS n FROM admin_audit_logs GROUP BY g ORDER BY n DESC");
        $actions = array_column(db_all('SELECT DISTINCT action FROM admin_audit_logs ORDER BY action'), 'action');

        return view('admin/audit/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'filters' => ['admin' => $admin, 'action' => $action, 'from' => $from, 'to' => $to],
            'filterErrors' => $errors,
            'actionInput' => $actionRaw,
            'admins' => db_all('SELECT id, name, login_id, status FROM admins ORDER BY id'),
            'groups' => $groups,
            'actions' => $actions,
        ]);
    }

    public static function actionLabel(string $action): string
    {
        return isset(self::ACTION_LABELS[$action]) ? self::ACTION_LABELS[$action] : $action;
    }

    /** 대상 링크(관리 화면이 있는 대상만). 없으면 null */
    public static function targetUrl(?string $type, $id): ?string
    {
        if ($type === null || $id === null) {
            return null;
        }
        $id = (int) $id;
        $map = [
            'voice_profile' => '/admin/voices/' . $id,
            'story' => '/admin/stories/' . $id,
            'user' => '/admin/members/' . $id,
            'inquiry' => '/admin/inquiries/' . $id,
            'notice' => '/admin/notices/' . $id . '/edit',
            'faq' => '/admin/faqs/' . $id . '/edit',
        ];
        if ($type === 'admin') {
            return url('/admin/audit', ['admin' => $id]);
        }

        return isset($map[$type]) ? url($map[$type]) : null;
    }

    /**
     * 상세 JSON 을 [한 줄 요약, 펼친 글] 로 바꾼다. JSON 이 아니면 원문 그대로.
     */
    public static function detail(?string $raw): array
    {
        $raw = (string) $raw;
        if (trim($raw) === '') {
            return ['', ''];
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [str_limit($raw, 70), $raw];
        }
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $parts = [];
        foreach ($data as $k => $v) {
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            } elseif ($v === null) {
                $v = 'null';
            } elseif (!is_scalar($v)) {
                $v = json_encode($v, $flags);
            }
            $parts[] = $k . ': ' . $v;
        }

        return [str_limit(implode(', ', $parts), 70), (string) json_encode($data, $flags | JSON_PRETTY_PRINT)];
    }

    private static function validDate(string $d): bool
    {
        $dt = \DateTime::createFromFormat('!Y-m-d', $d);

        return $dt !== false && $dt->format('Y-m-d') === $d;
    }
}
