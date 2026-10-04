<?php
namespace App\Controllers\User;

use App\Core\Auth;
use App\Core\Request;

/** 아이 프로필 목록, 추가, 수정, 삭제, 지금 듣는 아이 선택 */
class ChildrenController
{
    /** 한 보호자가 등록할 수 있는 아이 수 */
    const MAX_CHILDREN = 6;
    /** 만 나이로 고를 수 있는 범위 */
    const MAX_AGE = 13;

    // ───────────────────────── 공통(첫 자녀 등록과 함께 쓴다) ─────────────────────────

    /**
     * 아이 입력값 검사. 반환 [저장할 열, 오류]
     * 입력: name, birth_mode(date|age), birth_date, age, gender(boy|girl|빈 값), avatar
     */
    public static function validateInput(): array
    {
        $errors = [];
        $name = Request::str('name');
        if ($name === '') {
            $errors['name'] = '아이 이름(또는 애칭)을 입력해 주세요.';
        } elseif (mb_strlen($name) > 20) {
            $errors['name'] = '이름은 20자 이하로 입력해 주세요.';
        }

        $mode = Request::str('birth_mode') === 'age' ? 'age' : 'date';
        $birthDate = null;
        $birthYear = null;
        if ($mode === 'date') {
            $raw = Request::str('birth_date');
            $d = \DateTime::createFromFormat('!Y-m-d', $raw);
            if ($raw === '') {
                $errors['birth'] = '생년월일을 입력해 주세요.';
            } elseif (!$d || $d->format('Y-m-d') !== $raw) {
                $errors['birth'] = '생년월일을 올바르게 입력해 주세요.';
            } elseif ($d > new \DateTime('today')) {
                $errors['birth'] = '생년월일이 오늘보다 뒤일 수 없어요.';
            } elseif ((int) $d->diff(new \DateTime('today'))->y > self::MAX_AGE) {
                $errors['birth'] = '만 ' . self::MAX_AGE . '세 이하 아이만 등록할 수 있어요.';
            } else {
                $birthDate = $raw;
                $birthYear = (int) $d->format('Y');
            }
        } else {
            $age = Request::str('age');
            if ($age === '' || !preg_match('/^\d{1,2}$/', $age) || (int) $age > self::MAX_AGE) {
                $errors['birth'] = '아이 나이를 골라 주세요.';
            } else {
                // child_age() 는 birth_year 만 있을 때 (올해 - 출생연도 - 1) 로 계산하므로 같은 값이 나오도록 맞춘다.
                $birthYear = (int) date('Y') - (int) $age - 1;
            }
        }

        $gender = Request::str('gender');
        if (!in_array($gender, ['boy', 'girl', ''], true)) {
            $errors['gender'] = '성별을 다시 골라 주세요.';
        }
        $avatar = Request::str('avatar');
        $presets = avatar_presets();
        if (!isset($presets[$avatar])) {
            $avatar = 'bear';
        }

        return [[
            'name' => $name,
            'birth_date' => $birthDate,
            'birth_year' => $birthYear,
            'gender' => $gender === '' ? null : $gender,
            'avatar' => $avatar,
        ], $errors];
    }

    /** 템플릿에 넘길 폼 기본값(이전 입력 → 기존 행 → 빈 값 순서) */
    public static function formValues(?array $child): array
    {
        $mode = 'date';
        $age = '';
        if ($child && empty($child['birth_date']) && !empty($child['birth_year'])) {
            $mode = 'age';
            $a = child_age($child);
            $age = $a === null ? '' : (string) $a;
        }

        return [
            'name' => (string) old('name', $child ? $child['name'] : ''),
            'birth_mode' => (string) old('birth_mode', $mode),
            'birth_date' => (string) old('birth_date', $child && $child['birth_date'] ? $child['birth_date'] : ''),
            'age' => (string) old('age', $age),
            'gender' => (string) old('gender', $child && $child['gender'] ? $child['gender'] : ''),
            'avatar' => (string) old('avatar', $child && $child['avatar'] ? $child['avatar'] : 'bear'),
        ];
    }

    public static function genderLabel(?string $gender): string
    {
        return $gender === 'boy' ? '남아' : ($gender === 'girl' ? '여아' : '');
    }

    // ───────────────────────── 화면 ─────────────────────────

    public function index(): string
    {
        require_user();
        $children = Auth::children();
        $current = Auth::child();

        return view('user/settings/children', [
            'children' => $children,
            'currentId' => $current ? (int) $current['id'] : 0,
            'canAdd' => count($children) < self::MAX_CHILDREN,
        ]);
    }

    public function create(): string
    {
        require_user();
        if (count(Auth::children()) >= self::MAX_CHILDREN) {
            flash('info', '아이 프로필은 ' . self::MAX_CHILDREN . '명까지 만들 수 있어요.');
            redirect('/settings/children');
        }

        return view('user/settings/child_edit', [
            'child' => null,
            'values' => self::formValues(null),
        ]);
    }

    public function store(): void
    {
        $user = require_user();
        $count = count(Auth::children());
        if ($count >= self::MAX_CHILDREN) {
            flash('info', '아이 프로필은 ' . self::MAX_CHILDREN . '명까지 만들 수 있어요.');
            redirect('/settings/children');
        }
        list($data, $errors) = self::validateInput();
        if ($errors) {
            back_with_errors($errors, '/settings/children/new');
        }
        $data['user_id'] = (int) $user['id'];
        $data['sort_order'] = $count;
        $id = db_insert('children', $data);
        Auth::selectChild($id);
        flash('success', $data['name'] . ' 프로필을 만들었어요.');
        redirect('/settings/children');
    }

    public function edit(string $id): string
    {
        $user = require_user();
        $child = $this->find((int) $user['id'], (int) $id);

        return view('user/settings/child_edit', [
            'child' => $child,
            'values' => self::formValues($child),
            'canDelete' => count(Auth::children()) > 1,
        ]);
    }

    public function update(string $id): void
    {
        $user = require_user();
        $child = $this->find((int) $user['id'], (int) $id);
        list($data, $errors) = self::validateInput();
        if ($errors) {
            back_with_errors($errors, '/settings/children/' . (int) $child['id'] . '/edit');
        }
        db_update('children', $data, 'id = ? AND user_id = ?', [(int) $child['id'], (int) $user['id']]);
        flash('success', '아이 프로필을 저장했어요.');
        redirect('/settings/children');
    }

    public function destroy(string $id): void
    {
        $user = require_user();
        $child = $this->find((int) $user['id'], (int) $id);
        if (count(Auth::children()) <= 1) {
            flash('error', '아이 프로필이 하나뿐이라 삭제할 수 없어요. 다른 아이를 먼저 추가해 주세요.');
            redirect('/settings/children/' . (int) $child['id'] . '/edit');
        }
        db_exec('DELETE FROM children WHERE id = ? AND user_id = ?', [(int) $child['id'], (int) $user['id']]);
        $current = Auth::child();
        if ($current) {
            Auth::selectChild((int) $current['id']);
        }
        flash('success', $child['name'] . ' 프로필을 삭제했어요.');
        redirect('/settings/children');
    }

    /** 지금 듣는 아이를 바꾼다. fetch 요청이면 JSON 으로 답한다. */
    public function select(string $id)
    {
        $user = require_user();
        $child = $this->find((int) $user['id'], (int) $id);
        Auth::selectChild((int) $child['id']);
        if (Request::wantsJson()) {
            return ['ok' => true, 'child_id' => (int) $child['id'], 'message' => $child['name'] . '(으)로 바꿨어요.'];
        }
        flash('success', $child['name'] . '의 이야기 시간으로 바꿨어요.');
        redirect_back('/settings/children');

        return null;
    }

    private function find(int $userId, int $id): array
    {
        $child = db_one('SELECT * FROM children WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$child) {
            abort(404, '아이 프로필을 찾을 수 없어요.');
        }

        return $child;
    }
}
