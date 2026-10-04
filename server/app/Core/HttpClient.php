<?php
namespace App\Core;

/**
 * 외부 API 호출. curl 확장이 있으면 curl, 없으면 PHP 스트림을 쓴다.
 * $res = HttpClient::request('POST', $url, ['headers' => [...], 'json' => [...], 'timeout' => 30]);
 * 옵션: headers(이름 => 값), query, json, form, multipart(아래 형식), body(문자열), timeout(초)
 * multipart: [['name' => 'name', 'contents' => '엄마'], ['name' => 'files', 'filename' => 'a.wav', 'contents' => $bytes, 'type' => 'audio/wav']]
 * 반환: ['status' => int(실패 시 0), 'headers' => [...소문자 이름], 'body' => string, 'ms' => int, 'error' => ?string]
 */
class HttpClient
{
    public static function request(string $method, string $url, array $opts = []): array
    {
        $headers = isset($opts['headers']) ? $opts['headers'] : [];
        if (!empty($opts['query'])) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($opts['query']);
        }
        $body = null;
        if (array_key_exists('json', $opts)) {
            $body = json_encode($opts['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers['Content-Type'] = 'application/json';
        } elseif (isset($opts['form'])) {
            $body = http_build_query($opts['form']);
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        } elseif (isset($opts['multipart'])) {
            $boundary = '----rememvr' . bin2hex(random_bytes(8));
            $body = self::multipart($opts['multipart'], $boundary);
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;
        } elseif (isset($opts['body'])) {
            $body = (string) $opts['body'];
        }
        $timeout = isset($opts['timeout']) ? (int) $opts['timeout'] : 30;
        $started = microtime(true);
        $result = function_exists('curl_init')
            ? self::viaCurl($method, $url, $headers, $body, $timeout)
            : self::viaStream($method, $url, $headers, $body, $timeout);
        $result['ms'] = (int) round((microtime(true) - $started) * 1000);

        return $result;
    }

    /** JSON 응답을 배열로 (실패하면 빈 배열) */
    public static function json(array $response): array
    {
        $data = json_decode((string) $response['body'], true);

        return is_array($data) ? $data : [];
    }

    private static function multipart(array $parts, string $boundary): string
    {
        $out = '';
        foreach ($parts as $p) {
            $out .= '--' . $boundary . "\r\n";
            $disposition = 'Content-Disposition: form-data; name="' . addcslashes((string) $p['name'], '"\\') . '"';
            if (isset($p['filename'])) {
                $disposition .= '; filename="' . addcslashes((string) $p['filename'], '"\\') . '"';
            }
            $out .= $disposition . "\r\n";
            if (isset($p['filename'])) {
                $out .= 'Content-Type: ' . (isset($p['type']) ? $p['type'] : 'application/octet-stream') . "\r\n";
            }
            $out .= "\r\n" . $p['contents'] . "\r\n";
        }

        return $out . '--' . $boundary . "--\r\n";
    }

    private static function viaCurl(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$respHeaders) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $respHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $error = $resp === false ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $resp === false ? 0 : $status, 'headers' => $respHeaders, 'body' => $resp === false ? '' : (string) $resp, 'error' => $error];
    }

    private static function viaStream(string $method, string $url, array $headers, ?string $body, int $timeout): array
    {
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body === null ? '' : $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 0,
        ]]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        $respHeaders = [];
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                } elseif (strpos($line, ':') !== false) {
                    list($k, $v) = explode(':', $line, 2);
                    $respHeaders[strtolower(trim($k))] = trim($v);
                }
            }
        }
        $error = $resp === false ? 'HTTP 요청 실패' : null;

        return ['status' => $resp === false ? 0 : $status, 'headers' => $respHeaders, 'body' => $resp === false ? '' : (string) $resp, 'error' => $error];
    }
}
