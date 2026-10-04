<?php
namespace App\Core;

/** 현재 HTTP 요청 정보 */
class Request
{
    /** @var array|null */
    private static $json = null;

    public static function method(): string
    {
        $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if ($m === 'POST' && isset($_POST['_method'])) {
            $override = strtoupper((string) $_POST['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $m;
    }

    /** 기준 경로를 뺀 요청 경로. 예) /player/3 */
    public static function path(): string
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $path = rawurldecode($path);
        $base = base_path();
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, 10);
        }
        $path = '/' . trim($path, '/');

        return $path;
    }

    /** 쿼리 문자열을 포함한 요청 주소(기준 경로 제외). 로그인 후 돌아갈 곳으로 쓴다. */
    public static function uri(): string
    {
        $q = isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';

        return self::path() . $q;
    }

    /** POST, JSON 본문, 쿼리 순으로 값을 찾는다. */
    public static function input(string $key, $default = null)
    {
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        $json = self::json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $default;
    }

    public static function query(string $key, $default = null)
    {
        return isset($_GET[$key]) ? $_GET[$key] : $default;
    }

    /** 문자열 입력값(앞뒤 공백 제거). 배열이 오면 빈 문자열 */
    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);

        return is_array($v) ? '' : trim((string) $v);
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, null);

        return is_numeric($v) ? (int) $v : $default;
    }

    /** application/json 본문 */
    public static function json(): array
    {
        if (self::$json === null) {
            self::$json = [];
            $type = isset($_SERVER['CONTENT_TYPE']) ? (string) $_SERVER['CONTENT_TYPE'] : '';
            if (stripos($type, 'application/json') !== false) {
                $data = json_decode((string) file_get_contents('php://input'), true);
                if (is_array($data)) {
                    self::$json = $data;
                }
            }
        }

        return self::$json;
    }

    /** 업로드 파일. 정상 업로드가 아니면 null */
    public static function file(string $key): ?array
    {
        if (!isset($_FILES[$key]) || !is_array($_FILES[$key]) || is_array($_FILES[$key]['name'])) {
            return null;
        }
        $f = $_FILES[$key];
        if ((int) $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            return null;
        }

        return $f;
    }

    /** 업로드 오류 코드를 한글 메시지로 */
    public static function uploadError(string $key): ?string
    {
        if (!isset($_FILES[$key])) {
            $len = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
            if ($len > 0 && empty($_POST) && empty($_FILES)) {
                return '파일이 너무 큽니다. 서버 허용 크기(' . ini_get('post_max_size') . ')를 넘었습니다.';
            }

            return '파일이 전송되지 않았습니다.';
        }
        $code = (int) $_FILES[$key]['error'];
        $map = [
            UPLOAD_ERR_INI_SIZE => '파일이 너무 큽니다. 서버 허용 크기(' . ini_get('upload_max_filesize') . ')를 넘었습니다.',
            UPLOAD_ERR_FORM_SIZE => '파일이 너무 큽니다.',
            UPLOAD_ERR_PARTIAL => '파일이 일부만 전송되었습니다. 다시 시도해 주세요.',
            UPLOAD_ERR_NO_FILE => '파일을 선택해 주세요.',
            UPLOAD_ERR_NO_TMP_DIR => '서버 임시 폴더 오류입니다.',
            UPLOAD_ERR_CANT_WRITE => '서버에 파일을 저장하지 못했습니다.',
        ];

        return $code === UPLOAD_ERR_OK ? null : (isset($map[$code]) ? $map[$code] : '업로드 오류(' . $code . ')');
    }

    public static function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($_SERVER[$key]) ? (string) $_SERVER[$key] : null;
    }

    /** fetch 요청이거나 JSON 을 원하는 요청인지 */
    public static function wantsJson(): bool
    {
        $accept = (string) self::header('Accept');
        $xhr = (string) self::header('X-Requested-With');

        return stripos($accept, 'application/json') !== false || strcasecmp($xhr, 'XMLHttpRequest') === 0
            || strpos(self::path(), '/api/') === 0 || strpos(self::path(), '/admin/api/') === 0;
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443);
    }
}
