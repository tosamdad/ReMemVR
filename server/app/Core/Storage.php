<?php
namespace App\Core;

/**
 * 저장 폴더(storage_path) 안의 파일을 다룬다. DB 에는 저장 폴더 기준 상대 경로를 남긴다.
 * 예) voice-samples/12/3f2a.wav, story-audio/7/5-ab12cd.mp3
 */
class Storage
{
    const MIME = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'mp4' => 'audio/mp4',
        'aac' => 'audio/aac',
        'ogg' => 'audio/ogg',
        'oga' => 'audio/ogg',
        'webm' => 'audio/webm',
        'flac' => 'audio/flac',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'gif' => 'image/gif',
        'json' => 'application/json',
        'txt' => 'text/plain',
    ];

    public static function path(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        if ($relative === '' || strpos($relative, '..') !== false) {
            throw new \InvalidArgumentException('잘못된 저장 경로: ' . $relative);
        }

        return storage_path($relative);
    }

    public static function exists(?string $relative): bool
    {
        return $relative !== null && $relative !== '' && is_file(self::path($relative));
    }

    public static function put(string $relative, string $bytes): string
    {
        $full = self::path($relative);
        ensure_dir(dirname($full));
        if (file_put_contents($full, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('파일을 저장하지 못했다: ' . $relative);
        }

        return $relative;
    }

    /** 업로드된 임시 파일을 옮긴다. */
    public static function putUploaded(string $tmpPath, string $relative): string
    {
        $full = self::path($relative);
        ensure_dir(dirname($full));
        $ok = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $full) : rename($tmpPath, $full);
        if (!$ok) {
            throw new \RuntimeException('업로드 파일을 저장하지 못했다: ' . $relative);
        }

        return $relative;
    }

    public static function get(string $relative): string
    {
        $data = @file_get_contents(self::path($relative));
        if ($data === false) {
            throw new \RuntimeException('파일을 읽지 못했다: ' . $relative);
        }

        return $data;
    }

    public static function delete(?string $relative): void
    {
        if ($relative !== null && $relative !== '' && is_file(self::path($relative))) {
            @unlink(self::path($relative));
        }
    }

    public static function size(string $relative): int
    {
        return is_file(self::path($relative)) ? (int) filesize(self::path($relative)) : 0;
    }

    /** 무작위 파일 이름. ext 는 점 없이 */
    public static function randomName(string $ext): string
    {
        return bin2hex(random_bytes(12)) . '.' . strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $ext));
    }

    public static function mimeFor(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return isset(self::MIME[$ext]) ? self::MIME[$ext] : 'application/octet-stream';
    }

    /** MIME 으로 확장자 추정(업로드 오디오) */
    public static function extForMime(string $mime, string $fallback = 'bin'): string
    {
        $mime = strtolower(trim(explode(';', $mime)[0]));
        $map = [
            'audio/mpeg' => 'mp3', 'audio/mp3' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/wave' => 'wav',
            'audio/mp4' => 'm4a', 'audio/x-m4a' => 'm4a', 'audio/m4a' => 'm4a', 'audio/aac' => 'aac', 'audio/ogg' => 'ogg',
            'audio/webm' => 'webm', 'video/webm' => 'webm', 'audio/flac' => 'flac', 'audio/x-flac' => 'flac',
            'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/svg+xml' => 'svg',
        ];

        return isset($map[$mime]) ? $map[$mime] : $fallback;
    }

    /**
     * 파일을 내려보낸다. Range 요청을 지원한다(iOS Safari 오디오 재생과 구간 이동에 필요).
     * 이 함수는 출력을 끝내고 종료한다.
     */
    public static function stream(string $relative, ?string $mime = null, ?string $downloadName = null, int $maxAge = 86400): void
    {
        $full = self::path($relative);
        if (!is_file($full)) {
            throw new HttpException(404, '파일을 찾을 수 없습니다.');
        }
        $size = (int) filesize($full);
        $mime = $mime ?: self::mimeFor($full);
        $etag = '"' . md5($relative . '|' . $size . '|' . filemtime($full)) . '"';

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=' . $maxAge);
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        if ($downloadName !== null) {
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($downloadName));
        }
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        $start = 0;
        $end = $size - 1;
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', (string) $_SERVER['HTTP_RANGE'], $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int) $m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        $length = $end - $start + 1;
        header('Content-Length: ' . $length);
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
            exit;
        }
        $fp = fopen($full, 'rb');
        fseek($fp, $start);
        $remaining = $length;
        while ($remaining > 0 && !feof($fp)) {
            $chunk = fread($fp, (int) min(65536, $remaining));
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            $remaining -= strlen($chunk);
            flush();
        }
        fclose($fp);
        exit;
    }
}
