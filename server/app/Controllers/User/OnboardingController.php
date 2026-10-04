<?php
namespace App\Controllers\User;

use App\Core\Auth;

/** 가입 직후 첫 자녀 프로필 등록. 이미 자녀가 있으면 홈으로 보낸다. */
class OnboardingController
{
    public function show(): string
    {
        $user = require_user();
        if (Auth::children()) {
            redirect('/home');
        }

        return view('user/onboarding/index', [
            'user' => $user,
            'values' => ChildrenController::formValues(null),
        ]);
    }

    public function store(): void
    {
        $user = require_user();
        if (Auth::children()) {
            redirect('/home');
        }
        list($data, $errors) = ChildrenController::validateInput();
        if ($errors) {
            back_with_errors($errors, '/onboarding');
        }
        $data['user_id'] = (int) $user['id'];
        $data['sort_order'] = 0;
        $id = db_insert('children', $data);
        Auth::selectChild($id);
        flash('success', $data['name'] . '의 이야기 공간이 준비됐어요!');
        redirect('/home');
    }
}
