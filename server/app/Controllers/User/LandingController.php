<?php
namespace App\Controllers\User;

use App\Core\Auth;

/** 방문자 소개 화면. 로그인한 회원은 홈(자녀가 없으면 첫 자녀 등록)으로 보낸다. */
class LandingController
{
    public function index(): string
    {
        if (Auth::user()) {
            redirect(AuthController::afterLoginPath());
        }
        // 실제 공개 동화 수(없으면 화면에서 숫자를 감춘다)
        try {
            $storyCount = (int) db_value("SELECT COUNT(*) FROM stories WHERE status = 'published' AND deleted_at IS NULL");
        } catch (\Throwable $e) {
            $storyCount = 0;
        }

        return view('user/landing', [
            'storyCount' => $storyCount,
            'sampleSeconds' => (int) setting('voice.recommended_sample_seconds', 120),
            'maxQuestions' => setting('qa.enabled', true) ? (int) setting('qa.max_questions', 3) : 0,
            'maxVoices' => (int) setting('voice.max_per_user', 5),
        ]);
    }
}
