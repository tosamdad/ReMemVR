<?php
/**
 * 관리자 운영 메뉴 경로: 동화 콘텐츠(CMS), 회원과 통계, 운영 설정, 공지사항, FAQ, 1:1 문의, 관리자 계정, 감사 로그.
 * 모든 컨트롤러 메서드는 첫 줄에서 require_admin() 으로 로그인을 확인한다.
 *
 * @var App\Core\Router $router
 */

use App\Controllers\Admin\AdminAccountController;
use App\Controllers\Admin\AuditController;
use App\Controllers\Admin\FaqController;
use App\Controllers\Admin\InquiryController;
use App\Controllers\Admin\MemberController;
use App\Controllers\Admin\NoticeController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\StoryController;

// 동화 콘텐츠 관리(CMS)
$router->get('/admin/stories', [StoryController::class, 'index']);
$router->get('/admin/stories/new', [StoryController::class, 'create']);
$router->post('/admin/stories', [StoryController::class, 'store']);
$router->post('/admin/stories/split', [StoryController::class, 'split']);
$router->post('/admin/stories/regenerate-stale', [StoryController::class, 'regenerateStale']);
$router->get('/admin/stories/{id:\d+}', [StoryController::class, 'show']);
$router->post('/admin/stories/{id:\d+}', [StoryController::class, 'update']);
$router->post('/admin/stories/{id:\d+}/delete', [StoryController::class, 'destroy']);
$router->post('/admin/stories/{id:\d+}/timecodes', [StoryController::class, 'importTimecodes']);
$router->post('/admin/stories/{id:\d+}/regenerate', [StoryController::class, 'regenerate']);
$router->get('/admin/api/stories/deploy-status', [StoryController::class, 'deployStatus']);

// 회원 및 통계
$router->get('/admin/members', [MemberController::class, 'index']);
$router->get('/admin/members/export.csv', [MemberController::class, 'export']);
$router->get('/admin/members/{id:\d+}', [MemberController::class, 'show']);
$router->post('/admin/members/{id:\d+}/status', [MemberController::class, 'status']);
$router->post('/admin/members/{id:\d+}/memo', [MemberController::class, 'memo']);
$router->get('/admin/api/members/{id:\d+}/log', [MemberController::class, 'log']);
$router->post('/admin/interactions/review', [MemberController::class, 'review']);

// 운영 설정
$router->get('/admin/settings', [SettingsController::class, 'index']);
$router->post('/admin/settings', [SettingsController::class, 'save']);
$router->post('/admin/settings/voice-clips', [SettingsController::class, 'rebuildClips']);

// 공지사항
$router->get('/admin/notices', [NoticeController::class, 'index']);
$router->get('/admin/notices/new', [NoticeController::class, 'create']);
$router->post('/admin/notices', [NoticeController::class, 'store']);
$router->get('/admin/notices/{id:\d+}/edit', [NoticeController::class, 'edit']);
$router->post('/admin/notices/{id:\d+}', [NoticeController::class, 'update']);
$router->post('/admin/notices/{id:\d+}/delete', [NoticeController::class, 'destroy']);

// 자주 묻는 질문
$router->get('/admin/faqs', [FaqController::class, 'index']);
$router->post('/admin/faqs', [FaqController::class, 'store']);
$router->get('/admin/faqs/{id:\d+}/edit', [FaqController::class, 'edit']);
$router->post('/admin/faqs/{id:\d+}', [FaqController::class, 'update']);
$router->post('/admin/faqs/{id:\d+}/delete', [FaqController::class, 'destroy']);
$router->post('/admin/faqs/{id:\d+}/toggle', [FaqController::class, 'toggle']);
$router->post('/admin/faqs/{id:\d+}/move', [FaqController::class, 'move']);

// 1:1 문의
$router->get('/admin/inquiries', [InquiryController::class, 'index']);
$router->get('/admin/inquiries/{id:\d+}', [InquiryController::class, 'show']);
$router->post('/admin/inquiries/{id:\d+}', [InquiryController::class, 'answer']);
$router->post('/admin/inquiries/{id:\d+}/status', [InquiryController::class, 'status']);

// 관리자 계정
$router->get('/admin/admins', [AdminAccountController::class, 'index']);
$router->post('/admin/admins', [AdminAccountController::class, 'store']);
$router->post('/admin/admins/{id:\d+}/status', [AdminAccountController::class, 'status']);
$router->post('/admin/admins/{id:\d+}/password', [AdminAccountController::class, 'resetPassword']);
$router->get('/admin/account/password', [AdminAccountController::class, 'passwordForm']);
$router->post('/admin/account/password', [AdminAccountController::class, 'updatePassword']);

// 감사 로그
$router->get('/admin/audit', [AuditController::class, 'index']);
