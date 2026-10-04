<?php
namespace App\Controllers\User;

use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Session;
use App\Services\Jobs;

/** 공지사항, 고객 센터(연락처, 자주 묻는 질문, 1:1 문의) */
class SupportController
{
    const NOTICES_PER_PAGE = 20;

    const CATEGORIES = [
        'general' => '서비스 이용',
        'voice' => '목소리 등록',
        'playback' => '동화 재생, 질문',
        'account' => '계정, 로그인',
        'etc' => '기타',
    ];

    /** 문의 상태 이름과 칩 색 */
    public static function statusChip(string $status): array
    {
        $map = [
            'open' => ['답변 대기', 'bg-tertiary-container text-on-tertiary-container'],
            'answered' => ['답변 완료', 'bg-secondary-container text-on-secondary-container'],
            'closed' => ['종료', 'bg-surface-container-high text-on-surface-variant'],
        ];

        return isset($map[$status]) ? $map[$status] : [$status, 'bg-surface-container-high text-on-surface-variant'];
    }

    public static function categoryLabel(string $key): string
    {
        return isset(self::CATEGORIES[$key]) ? self::CATEGORIES[$key] : '기타';
    }

    // ───────────────────────── 공지사항 ─────────────────────────

    public function notices(): string
    {
        require_user();
        $page = max(1, Request::int('page', 1));
        $total = (int) db_value("SELECT COUNT(*) FROM notices WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW())");
        $rows = db_all(
            "SELECT id, title, body, is_pinned, COALESCE(published_at, created_at) AS posted_at FROM notices
             WHERE status = 'published' AND (published_at IS NULL OR published_at <= NOW())
             ORDER BY is_pinned DESC, posted_at DESC, id DESC LIMIT ? OFFSET ?",
            [self::NOTICES_PER_PAGE, ($page - 1) * self::NOTICES_PER_PAGE]
        );

        if (!$rows && $page > 1) {
            redirect('/settings/notices');
        }

        return view('user/support/notices', [
            'notices' => $rows,
            'page' => $page,
            'hasMore' => $page * self::NOTICES_PER_PAGE < $total,
        ]);
    }

    public function notice(string $id): string
    {
        require_user();
        $notice = db_one(
            "SELECT id, title, body, is_pinned, COALESCE(published_at, created_at) AS posted_at FROM notices
             WHERE id = ? AND status = 'published' AND (published_at IS NULL OR published_at <= NOW())",
            [(int) $id]
        );
        if (!$notice) {
            abort(404, '공지사항을 찾을 수 없어요.');
        }

        return view('user/support/notice', ['notice' => $notice]);
    }

    // ───────────────────────── 고객 센터 ─────────────────────────

    public function index(): string
    {
        $user = require_user();
        $faqs = db_all('SELECT id, category, question, answer FROM faqs WHERE is_active = 1 ORDER BY sort_order, id');
        $inquiries = db_all(
            'SELECT id, category, title, status, created_at, answered_at FROM inquiries WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 30',
            [(int) $user['id']]
        );

        return view('user/support/index', [
            'user' => $user,
            'faqs' => $faqs,
            'inquiries' => $inquiries,
            'categories' => self::CATEGORIES,
            'contact' => [
                'email' => trim((string) setting('support.email', '')),
                'phone' => trim((string) setting('support.phone', '')),
                'hours' => trim((string) setting('support.hours', '')),
            ],
        ]);
    }

    public function storeInquiry(): void
    {
        $user = require_user();
        $category = Request::str('category');
        $title = Request::str('title');
        $body = trim((string) input('body', ''));
        $errors = [];
        if (!isset(self::CATEGORIES[$category])) {
            $errors['category'] = '문의 종류를 골라 주세요.';
        }
        if ($title === '') {
            $errors['title'] = '제목을 입력해 주세요.';
        } elseif (mb_strlen($title) > 100) {
            $errors['title'] = '제목은 100자 이하로 입력해 주세요.';
        }
        if ($body === '' || is_array(input('body'))) {
            $errors['body'] = '문의 내용을 입력해 주세요.';
        } elseif (mb_strlen($body) < 5) {
            $errors['body'] = '문의 내용을 조금 더 자세히 적어 주세요.';
        } elseif (mb_strlen($body) > 3000) {
            $errors['body'] = '문의 내용은 3,000자 이하로 입력해 주세요.';
        }
        if (!$errors && !RateLimiter::hit('inquiry:' . (int) $user['id'], 5, 3600)) {
            $errors['body'] = '문의를 짧은 시간에 너무 많이 보냈어요. 잠시 후 다시 시도해 주세요.';
        }
        if ($errors) {
            // 폼이 화면 아래쪽에 있으므로 오류와 함께 폼 위치로 바로 돌아간다.
            Session::setOldInput(['category' => $category, 'title' => $title, 'body' => $body], $errors);
            redirect('/settings/support#inquiry-form');
        }

        $id = db_insert('inquiries', [
            'user_id' => (int) $user['id'],
            'category' => $category,
            'title' => $title,
            'body' => $body,
            'status' => 'open',
        ]);

        // 운영자 알림 메일(설정된 경우만)
        $adminEmail = trim((string) setting('notify.admin_email', ''));
        if ($adminEmail !== '' && filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            try {
                Jobs::enqueue('mail', [
                    'to' => $adminEmail,
                    'subject' => '[ReMemVR] 새 1:1 문의: ' . str_limit($title, 40),
                    'text' => "새 1:1 문의가 등록되었습니다.\n\n"
                        . '분류: ' . self::categoryLabel($category) . "\n"
                        . '회원: ' . $user['name'] . ' / ' . mask_email((string) $user['email']) . "\n"
                        . '제목: ' . $title . "\n\n"
                        . '답변하기: ' . absolute_url('/admin/inquiries') . "\n",
                ], ['priority' => 6, 'ref_type' => 'inquiry', 'ref_id' => $id]);
            } catch (\Throwable $e) {
                app_log('error', '문의 알림 메일 등록 실패: ' . $e->getMessage());
            }
        }

        flash('success', '문의를 보냈어요. 답변이 등록되면 이곳에서 확인할 수 있어요.');
        redirect('/settings/support/inquiries/' . $id);
    }

    public function inquiry(string $id): string
    {
        $user = require_user();
        $row = db_one('SELECT * FROM inquiries WHERE id = ? AND user_id = ?', [(int) $id, (int) $user['id']]);
        if (!$row) {
            abort(404, '문의 내역을 찾을 수 없어요.');
        }

        return view('user/support/inquiry', ['inquiry' => $row]);
    }
}
