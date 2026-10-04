<?php
namespace App\Core;

/** 서버의 mail() 로 텍스트 메일을 보낸다. 실패해도 예외를 던지지 않고 false 를 돌려준다. */
class Mailer
{
    public static function send(string $to, string $subject, string $text): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = (string) config('mail_from', '');
        if ($from === '') {
            $host = parse_url((string) config('site_url', ''), PHP_URL_HOST);
            $from = 'no-reply@' . ($host ?: 'localhost');
        }
        $brand = (string) setting('app.brand', '르멤버');
        $headers = [
            'From: =?UTF-8?B?' . base64_encode($brand) . '?= <' . $from . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        if (config('mail_disabled') || config('providers_fake')) {
            app_log('info', '메일 발송 생략(개발 모드)', ['to' => $to, 'subject' => $subject]);

            return true;
        }
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($text)), implode("\r\n", $headers));
        if (!$ok) {
            app_log('error', '메일 발송 실패', ['to' => $to, 'subject' => $subject]);
        }

        return (bool) $ok;
    }
}
