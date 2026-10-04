<?php
namespace App\Controllers\Admin;

use App\Core\Request;

/**
 * 자주 묻는 질문 관리. 회원 고객 센터는 is_active = 1 인 항목을 sort_order, id 순서로 보여 준다.
 */
class FaqController
{
    const CATEGORY_MAX = 30;
    const QUESTION_MAX = 300;
    const ANSWER_MAX = 5000;

    /** GET /admin/faqs */
    public function index(): string
    {
        require_admin();

        return $this->render(null);
    }

    /** GET /admin/faqs/{id}/edit */
    public function edit(string $id): string
    {
        require_admin();

        return $this->render(self::find($id));
    }

    /** POST /admin/faqs */
    public function store(): void
    {
        require_admin();
        $data = self::validated(null);
        if ($data['sort_order'] === null) {
            $data['sort_order'] = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM faqs') + 1;
        }
        $id = db_insert('faqs', $data);
        admin_audit('faq.create', 'faq', $id, ['question' => str_limit($data['question'], 80), 'category' => $data['category'], 'active' => (bool) $data['is_active']]);
        flash('success', '질문을 추가했습니다.');
        redirect('/admin/faqs#faq-' . $id);
    }

    /** POST /admin/faqs/{id} */
    public function update(string $id): void
    {
        require_admin();
        $faq = self::find($id);
        $data = self::validated($faq);
        if ($data['sort_order'] === null) {
            $data['sort_order'] = (int) $faq['sort_order'];
        }
        $changed = [];
        foreach ($data as $k => $v) {
            if ((string) $faq[$k] !== (string) $v) {
                $changed[] = $k;
            }
        }
        if ($changed) {
            db_update('faqs', $data, 'id = ?', [(int) $faq['id']]);
            admin_audit('faq.update', 'faq', (int) $faq['id'], ['question' => str_limit($data['question'], 80), 'fields' => $changed]);
            flash('success', '질문을 수정했습니다.');
        } else {
            flash('info', '바뀐 내용이 없습니다.');
        }
        redirect('/admin/faqs#faq-' . (int) $faq['id']);
    }

    /** POST /admin/faqs/{id}/delete */
    public function destroy(string $id): void
    {
        require_admin();
        $faq = self::find($id);
        db_exec('DELETE FROM faqs WHERE id = ?', [(int) $faq['id']]);
        admin_audit('faq.delete', 'faq', (int) $faq['id'], ['question' => str_limit((string) $faq['question'], 80)]);
        flash('success', '질문을 삭제했습니다.');
        redirect('/admin/faqs');
    }

    /** POST /admin/faqs/{id}/toggle: 회원 화면 노출 켜고 끄기 */
    public function toggle(string $id): void
    {
        require_admin();
        $faq = self::find($id);
        $active = (int) $faq['is_active'] ? 0 : 1;
        db_exec('UPDATE faqs SET is_active = ? WHERE id = ?', [$active, (int) $faq['id']]);
        admin_audit('faq.toggle', 'faq', (int) $faq['id'], ['active' => (bool) $active]);
        flash('success', $active ? '회원 화면에 보이게 했습니다.' : '회원 화면에서 숨겼습니다.');
        redirect('/admin/faqs#faq-' . (int) $faq['id']);
    }

    /** POST /admin/faqs/{id}/move (direction=up|down): 바로 옆 항목과 순서를 바꾼다. */
    public function move(string $id): void
    {
        require_admin();
        $faq = self::find($id);
        $dir = input('direction') === 'down' ? 1 : -1;
        $moved = db_tx(static function () use ($faq, $dir) {
            // 순서 번호를 1, 2, 3… 으로 다시 매긴 뒤 이웃과 맞바꾼다(같은 번호가 섞여 있어도 안전).
            $ids = array_map('intval', array_column(db_all('SELECT id FROM faqs ORDER BY sort_order, id FOR UPDATE'), 'id'));
            $pos = array_search((int) $faq['id'], $ids, true);
            $to = $pos === false ? -1 : $pos + $dir;
            if ($to < 0 || $to >= count($ids)) {
                return false;
            }
            $tmp = $ids[$pos];
            $ids[$pos] = $ids[$to];
            $ids[$to] = $tmp;
            foreach ($ids as $i => $fid) {
                db_exec('UPDATE faqs SET sort_order = ? WHERE id = ?', [$i + 1, $fid]);
            }

            return true;
        });
        if ($moved) {
            admin_audit('faq.move', 'faq', (int) $faq['id'], ['direction' => $dir < 0 ? 'up' : 'down']);
        }
        redirect('/admin/faqs#faq-' . (int) $faq['id']);
    }

    private function render(?array $editing): string
    {
        $category = Request::str('category');
        $q = mb_substr(Request::str('q'), 0, 100);
        $where = ['1 = 1'];
        $params = [];
        if ($category !== '') {
            $where[] = 'COALESCE(category, \'\') = ?';
            $params[] = $category === '-' ? '' : $category;
        }
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where[] = '(question LIKE ? OR answer LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }
        $rows = db_all('SELECT * FROM faqs WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order, id', $params);
        $categories = db_all("SELECT COALESCE(category, '') AS category, COUNT(*) AS n, SUM(is_active) AS active FROM faqs GROUP BY COALESCE(category, '') ORDER BY MIN(sort_order)");
        $total = (int) db_value('SELECT COUNT(*) FROM faqs');
        $active = (int) db_value('SELECT COUNT(*) FROM faqs WHERE is_active = 1');
        $nextSort = (int) db_value('SELECT COALESCE(MAX(sort_order), 0) FROM faqs') + 1;

        return view('admin/faqs/index', [
            'rows' => $rows,
            'editing' => $editing,
            'categories' => $categories,
            'filters' => ['category' => $category, 'q' => $q],
            'total' => $total,
            'active' => $active,
            'nextSort' => $nextSort,
            'tabCounts' => NoticeController::tabCounts(),
        ]);
    }

    private static function find(string $id): array
    {
        $row = db_one('SELECT * FROM faqs WHERE id = ?', [(int) $id]);
        if (!$row) {
            abort(404, '질문을 찾을 수 없습니다.');
        }

        return $row;
    }

    /** 입력 검증. sort_order 를 비우면 null(새 글은 맨 뒤, 수정은 그대로). */
    private static function validated(?array $faq): array
    {
        $str = static function (string $k): string {
            $v = input($k, '');

            return is_string($v) ? $v : '';
        };
        $category = trim(preg_replace('/\s+/u', ' ', $str('category')));
        $question = trim(preg_replace('/\s+/u', ' ', $str('question')));
        $answer = trim(str_replace("\r\n", "\n", $str('answer')));
        $sortRaw = trim($str('sort_order'));
        $errors = [];
        if (mb_strlen($category) > self::CATEGORY_MAX) {
            $errors['category'] = '분류는 ' . self::CATEGORY_MAX . '자 이하로 입력해 주세요.';
        }
        if ($question === '') {
            $errors['question'] = '질문을 입력해 주세요.';
        } elseif (mb_strlen($question) > self::QUESTION_MAX) {
            $errors['question'] = '질문은 ' . self::QUESTION_MAX . '자 이하로 입력해 주세요.';
        }
        if ($answer === '') {
            $errors['answer'] = '답변을 입력해 주세요.';
        } elseif (mb_strlen($answer) > self::ANSWER_MAX) {
            $errors['answer'] = '답변은 ' . number_format(self::ANSWER_MAX) . '자 이하로 입력해 주세요.';
        }
        if ($sortRaw !== '' && (!preg_match('/^-?\d{1,6}$/', $sortRaw))) {
            $errors['sort_order'] = '순서는 숫자로 입력해 주세요.';
        }
        if ($errors) {
            back_with_errors($errors, $faq ? '/admin/faqs/' . (int) $faq['id'] . '/edit' : '/admin/faqs');
        }

        return [
            'category' => $category !== '' ? $category : null,
            'question' => $question,
            'answer' => $answer,
            'sort_order' => $sortRaw !== '' ? (int) $sortRaw : null,
            'is_active' => in_array((string) input('is_active', ''), ['1', 'on'], true) ? 1 : 0,
        ];
    }
}
