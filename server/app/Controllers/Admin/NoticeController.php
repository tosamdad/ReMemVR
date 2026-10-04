<?php
namespace App\Controllers\Admin;

use App\Core\Request;

/**
 * 공지사항 관리. 회원 화면(/settings/notices)은 status = published 이고 게시 시각이 지난 글만 보여 준다.
 * 게시 시각을 비워 두고 게시하면 저장 시각을 넣고, 미래 시각이면 그때부터 보이는 예약 게시가 된다.
 */
class NoticeController
{
    const PER_PAGE = 20;
    const TITLE_MAX = 200;
    const BODY_MAX = 20000;
    const FILTERS = ['' => '전체', 'live' => '게시 중', 'scheduled' => '예약', 'draft' => '임시 저장'];

    /** GET /admin/notices */
    public function index(): string
    {
        require_admin();
        $q = mb_substr(Request::str('q'), 0, 100);
        $filter = Request::str('status');
        if (!isset(self::FILTERS[$filter])) {
            $filter = '';
        }
        $where = ['1 = 1'];
        $params = [];
        if ($q !== '') {
            $where[] = '(n.title LIKE ? OR n.body LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $base = $where;
        $baseParams = $params;
        if ($filter === 'live') {
            $where[] = "n.status = 'published' AND (n.published_at IS NULL OR n.published_at <= NOW())";
        } elseif ($filter === 'scheduled') {
            $where[] = "n.status = 'published' AND n.published_at > NOW()";
        } elseif ($filter === 'draft') {
            $where[] = "n.status = 'draft'";
        }
        $sqlWhere = implode(' AND ', $where);
        $total = (int) db_value('SELECT COUNT(*) FROM notices n WHERE ' . $sqlWhere, $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, Request::int('page', 1)));
        $rows = db_all(
            'SELECT n.*, a.name AS author FROM notices n LEFT JOIN admins a ON a.id = n.created_by
             WHERE ' . $sqlWhere . '
             ORDER BY n.is_pinned DESC, COALESCE(n.published_at, n.created_at) DESC, n.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [self::PER_PAGE, ($page - 1) * self::PER_PAGE])
        );
        // 탭 숫자(검색어만 반영)
        $c = db_one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(n.status = 'published' AND (n.published_at IS NULL OR n.published_at <= NOW())), 0) AS live,
                    COALESCE(SUM(n.status = 'published' AND n.published_at > NOW()), 0) AS scheduled,
                    COALESCE(SUM(n.status = 'draft'), 0) AS draft
             FROM notices n WHERE " . implode(' AND ', $base),
            $baseParams
        );

        return view('admin/notices/index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'q' => $q,
            'filter' => $filter,
            'counts' => ['' => (int) $c['total'], 'live' => (int) $c['live'], 'scheduled' => (int) $c['scheduled'], 'draft' => (int) $c['draft']],
            'tabCounts' => self::tabCounts(),
        ]);
    }

    /** GET /admin/notices/new */
    public function create(): string
    {
        require_admin();

        return view('admin/notices/form', [
            'notice' => ['id' => null, 'title' => '', 'body' => '', 'is_pinned' => 0, 'status' => 'published', 'published_at' => null],
            'tabCounts' => self::tabCounts(),
        ]);
    }

    /** POST /admin/notices */
    public function store(): void
    {
        $admin = require_admin();
        $data = self::validated(null);
        $data['created_by'] = (int) $admin['id'];
        $id = db_insert('notices', $data);
        admin_audit('notice.create', 'notice', $id, ['title' => $data['title'], 'status' => $data['status'], 'pinned' => (bool) $data['is_pinned']]);
        flash('success', $data['status'] === 'draft' ? '공지를 임시 저장했습니다.' : '공지를 등록했습니다.');
        redirect('/admin/notices');
    }

    /** GET /admin/notices/{id}/edit */
    public function edit(string $id): string
    {
        require_admin();
        $notice = self::find($id);

        return view('admin/notices/form', ['notice' => $notice, 'tabCounts' => self::tabCounts()]);
    }

    /** POST /admin/notices/{id} */
    public function update(string $id): void
    {
        require_admin();
        $notice = self::find($id);
        $data = self::validated($notice);
        $changed = [];
        foreach ($data as $k => $v) {
            if ((string) $notice[$k] !== (string) $v) {
                $changed[] = $k;
            }
        }
        if ($changed) {
            db_update('notices', $data, 'id = ?', [(int) $notice['id']]);
            admin_audit('notice.update', 'notice', (int) $notice['id'], ['title' => $data['title'], 'fields' => $changed]);
            flash('success', '공지를 수정했습니다.');
        } else {
            flash('info', '바뀐 내용이 없습니다.');
        }
        redirect('/admin/notices');
    }

    /** POST /admin/notices/{id}/delete */
    public function destroy(string $id): void
    {
        require_admin();
        $notice = self::find($id);
        db_exec('DELETE FROM notices WHERE id = ?', [(int) $notice['id']]);
        admin_audit('notice.delete', 'notice', (int) $notice['id'], ['title' => $notice['title']]);
        flash('success', '공지를 삭제했습니다.');
        redirect('/admin/notices');
    }

    /** 상태 칩: [이름, 색] */
    public static function stateOf(array $n): array
    {
        if ($n['status'] === 'draft') {
            return ['임시 저장', 'bg-surface-container-high text-on-surface-variant'];
        }
        if (!empty($n['published_at']) && strtotime((string) $n['published_at']) > time()) {
            return ['예약', 'bg-secondary-fixed text-on-secondary-fixed'];
        }

        return ['게시 중', 'bg-emerald-100 text-emerald-800'];
    }

    /**
     * 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s' 를 DB 시각으로 바꾼다. 빈 값은 null, 형식이 틀리면 false.
     * @return string|null|false
     */
    public static function parseDateTime(string $raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i', 'Y-m-d\TH:i:s', 'Y-m-d H:i', 'Y-m-d H:i:s'] as $f) {
            $d = \DateTime::createFromFormat('!' . $f, $raw);
            if ($d && $d->format($f) === $raw) {
                $y = (int) $d->format('Y');
                return $y >= 2000 && $y <= 2100 ? $d->format('Y-m-d H:i:s') : false;
            }
        }

        return false;
    }

    /** 공지사항, FAQ 탭 숫자 */
    public static function tabCounts(): array
    {
        return [
            'notices' => (int) db_value('SELECT COUNT(*) FROM notices'),
            'faqs' => (int) db_value('SELECT COUNT(*) FROM faqs'),
        ];
    }

    private static function find(string $id): array
    {
        $row = db_one('SELECT * FROM notices WHERE id = ?', [(int) $id]);
        if (!$row) {
            abort(404, '공지를 찾을 수 없습니다.');
        }

        return $row;
    }

    /** 입력 검증. 오류가 있으면 입력값과 함께 폼으로 돌아간다. */
    private static function validated(?array $notice): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string) (is_string(input('title')) ? input('title') : '')));
        $body = trim(str_replace("\r\n", "\n", (string) (is_string(input('body')) ? input('body') : '')));
        $status = input('status') === 'draft' ? 'draft' : 'published';
        $pinned = in_array((string) input('is_pinned', ''), ['1', 'on'], true) ? 1 : 0;
        $at = self::parseDateTime(is_string(input('published_at')) ? (string) input('published_at') : '');
        $errors = [];
        if ($title === '') {
            $errors['title'] = '제목을 입력해 주세요.';
        } elseif (mb_strlen($title) > self::TITLE_MAX) {
            $errors['title'] = '제목은 ' . self::TITLE_MAX . '자 이하로 입력해 주세요.';
        }
        if ($body === '') {
            $errors['body'] = '본문을 입력해 주세요.';
        } elseif (mb_strlen($body) > self::BODY_MAX) {
            $errors['body'] = '본문은 ' . number_format(self::BODY_MAX) . '자 이하로 입력해 주세요.';
        }
        if ($at === false) {
            $errors['published_at'] = '게시 시각 형식이 올바르지 않습니다.';
        }
        if ($errors) {
            back_with_errors($errors, $notice ? '/admin/notices/' . (int) $notice['id'] . '/edit' : '/admin/notices/new');
        }
        // 게시하면서 시각을 비우면 지금(이미 게시된 글은 원래 시각 유지)
        if ($status === 'published' && $at === null) {
            $at = $notice && !empty($notice['published_at']) && $notice['status'] === 'published' ? (string) $notice['published_at'] : now();
        }

        return ['title' => $title, 'body' => $body, 'is_pinned' => $pinned, 'status' => $status, 'published_at' => $at];
    }
}
