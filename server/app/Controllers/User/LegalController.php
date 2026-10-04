<?php
namespace App\Controllers\User;

use App\Core\Auth;

/**
 * 이용약관, 개인정보 처리방침. 관리자 설정(legal.terms, legal.privacy)에 내용이 있으면 그것을,
 * 없으면 기본 문서(views/user/legal/*_default.php)를 보여 준다. 로그인 없이 볼 수 있다.
 */
class LegalController
{
    public function terms(): string
    {
        return $this->render('terms', '이용약관', (string) setting('legal.terms', ''));
    }

    public function privacy(): string
    {
        return $this->render('privacy', '개인정보 처리방침', (string) setting('legal.privacy', ''));
    }

    private function render(string $kind, string $title, string $custom): string
    {
        $text = trim($custom);
        if ($text === '') {
            $text = partial('user/legal/' . $kind . '_default', [
                'brand' => (string) setting('app.brand', '르멤버'),
                'email' => trim((string) setting('support.email', '')),
                'phone' => trim((string) setting('support.phone', '')),
                'hours' => trim((string) setting('support.hours', '')),
            ]);
        }

        return view('user/legal/document', [
            'kind' => $kind,
            'docTitle' => $title,
            'blocks' => self::parse($text),
            'loggedIn' => Auth::user() !== null,
        ]);
    }

    /**
     * 일반 텍스트를 문단 목록으로 나눈다. 빈 줄이 문단 경계이고,
     * "제1조 (목적)", "1. 수집 항목" 처럼 짧은 첫 줄은 소제목으로 본다.
     * 반환: [['heading' => ?string, 'lines' => string[]], ...]
     */
    public static function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $blocks = [];
        foreach (preg_split('/\n\s*\n/u', trim($text)) as $chunk) {
            $lines = array_values(array_filter(array_map('trim', explode("\n", $chunk)), static function ($l) {
                return $l !== '';
            }));
            if (!$lines) {
                continue;
            }
            $heading = null;
            if (mb_strlen($lines[0]) <= 40 && preg_match('/^(제\s*\d+\s*조|부\s*칙|\d+\.\s)/u', $lines[0])) {
                $heading = array_shift($lines);
            }
            $blocks[] = ['heading' => $heading, 'lines' => $lines];
        }

        return $blocks;
    }
}
