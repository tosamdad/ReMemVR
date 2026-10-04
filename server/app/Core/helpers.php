<?php
/**
 * 전역 도우미 함수. 컨트롤러와 화면 템플릿에서 공통으로 쓴다.
 * PHP 7.4 호환: match, nullsafe(?->), 이름 있는 인자, str_contains 등은 쓰지 않는다.
 */

use App\Core\AdminAuth;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Session;
use App\Core\Settings;
use App\Core\View;

// mbstring 이 없는 서버를 대비한 최소 대체 함수
if (!function_exists('mb_strlen')) {
    function mb_strlen($s, $encoding = null)
    {
        return (int) preg_match_all('/./us', (string) $s);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $length = null, $encoding = null)
    {
        preg_match_all('/./us', (string) $s, $m);
        return implode('', array_slice($m[0], $start, $length));
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower($s, $encoding = null)
    {
        return strtolower((string) $s);
    }
}

// ───────────────────────── 설정, 경로 ─────────────────────────

/** config.php 값을 점 표기로 읽는다. 예) config('gemini.api_key') */
function config(string $key, $default = null)
{
    $value = app_config();
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function is_debug(): bool
{
    return (bool) config('debug', false);
}

/** 관리자 설정값(settings 테이블, 없으면 기본값). 예) setting('qa.max_questions') */
function setting(string $key, $default = null)
{
    return Settings::get($key, $default);
}

/**
 * 업로드 파일, 생성 오디오, 세션, 로그를 두는 폴더. 웹 루트 밖이다.
 * 기본값은 앱 폴더의 상위(호스팅 홈)에 있는 rememvr_data 이며, 만들 수 없으면 앱 폴더 안 storage 를 쓴다.
 */
function storage_path(string $relative = ''): string
{
    static $base = null;
    if ($base === null) {
        $candidates = [];
        $configured = config('storage_dir');
        if (is_string($configured) && $configured !== '') {
            $candidates[] = $configured[0] === '/' ? $configured : APP_ROOT . '/' . $configured;
        }
        $candidates[] = dirname(APP_ROOT) . '/rememvr_data';
        $candidates[] = APP_ROOT . '/storage';
        foreach ($candidates as $dir) {
            if ((is_dir($dir) || @mkdir($dir, 0775, true)) && is_writable($dir)) {
                $base = rtrim($dir, '/');
                break;
            }
        }
        if ($base === null) {
            $base = rtrim($candidates[0], '/');
        }
        if (!is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\n");
        }
    }
    $relative = ltrim($relative, '/');

    return $relative === '' ? $base : $base . '/' . $relative;
}

/** 폴더가 없으면 만든다. 만든(또는 이미 있는) 경로를 돌려준다. */
function ensure_dir(string $dir): string
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    return $dir;
}

/** 사이트가 하위 경로에 있을 때를 대비한 기준 경로. 루트에서 서비스하면 빈 문자열이다. */
function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $script = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', $_SERVER['SCRIPT_NAME']) : '';
        $dir = rtrim(dirname($script), '/');
        if (substr($dir, -5) === '/_ops') {
            $dir = substr($dir, 0, -5);
        }
        $base = ($dir === '.' || $dir === '/') ? '' : $dir;
    }

    return $base;
}

/** 사이트 내부 주소. url('/stories', ['tag' => '모험']) */
function url(string $path = '/', array $query = []): string
{
    $path = '/' . ltrim($path, '/');
    $q = $query ? '?' . http_build_query($query) : '';

    return base_path() . $path . $q;
}

/** 외부에서 접근하는 전체 주소(OAuth 콜백, 메일 링크용) */
function absolute_url(string $path = '/', array $query = []): string
{
    $site = rtrim((string) config('site_url', ''), '/');
    if ($site === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $site = ($https ? 'https' : 'http') . '://' . $host . base_path();
    }

    return $site . '/' . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
}

/** public/assets 아래 정적 파일 주소. 수정 시각을 붙여 캐시를 갱신한다. */
function asset(string $path): string
{
    $path = ltrim($path, '/');
    $file = defined('PUBLIC_ROOT') ? PUBLIC_ROOT . '/assets/' . $path : '';
    $v = ($file !== '' && is_file($file)) ? '?v=' . filemtime($file) : '';

    return base_path() . '/assets/' . $path . $v;
}

/** 동화 표지 주소. cover_image_path 가 assets: 로 시작하면 정적 파일, storage: 면 업로드 파일이다. */
function cover_url(array $story): string
{
    $path = isset($story['cover_image_path']) ? (string) $story['cover_image_path'] : '';
    if (strpos($path, 'assets:') === 0) {
        return asset(substr($path, 7));
    }
    if (strpos($path, 'storage:') === 0 && isset($story['id'])) {
        $v = isset($story['updated_at']) ? substr(md5((string) $story['updated_at']), 0, 8) : '';

        return url('/media/cover/' . (int) $story['id'], $v !== '' ? ['v' => $v] : []);
    }

    return asset('covers/default.svg');
}

// ───────────────────────── DB ─────────────────────────

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = Db::connect(app_config()['db']);
    }

    return $pdo;
}

/**
 * 준비문을 실행한다. 정수는 PDO::PARAM_INT 로 묶으므로 LIMIT ? 에 (int) 값을 넘기면 된다.
 * 네이티브 준비문이라 같은 이름의 :param 을 한 쿼리에서 두 번 쓸 수 없다.
 */
function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $name = is_int($key) ? $key + 1 : (strpos($key, ':') === 0 ? $key : ':' . $key);
        if (is_int($value)) {
            $stmt->bindValue($name, $value, PDO::PARAM_INT);
        } elseif (is_bool($value)) {
            $stmt->bindValue($name, $value ? 1 : 0, PDO::PARAM_INT);
        } elseif ($value === null) {
            $stmt->bindValue($name, null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue($name, (string) $value, PDO::PARAM_STR);
        }
    }
    $stmt->execute();

    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();

    return $row === false ? null : $row;
}

function db_value(string $sql, array $params = [])
{
    $value = db_query($sql, $params)->fetchColumn();

    return $value === false ? null : $value;
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')';
    db_query($sql, array_values($data));

    return (int) db()->lastInsertId();
}

/** UPDATE table SET ... WHERE $where. $where 에는 ? 자리표시자만 쓴다. */
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = '`' . $col . '` = ?';
    }
    $sql = 'UPDATE `' . $table . '` SET ' . implode(', ', $sets) . ' WHERE ' . $where;

    return db_exec($sql, array_merge(array_values($data), array_values($whereParams)));
}

/** 트랜잭션 안에서 실행한다. 예외가 나면 되돌리고 다시 던진다. */
function db_tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();

        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

// ───────────────────────── 요청, 응답 ─────────────────────────

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function input(string $key, $default = null)
{
    return Request::input($key, $default);
}

function is_post(): bool
{
    return Request::method() === 'POST';
}

function redirect(string $path, int $status = 302): void
{
    $location = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $location, true, $status);
    exit;
}

/** 직전 페이지로 돌아간다. Referer 가 없거나 외부 주소면 $fallback 으로 간다. */
function redirect_back(string $fallback = '/'): void
{
    $ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
    if ($ref !== '' && $host !== '' && parse_url($ref, PHP_URL_HOST) === parse_url('http://' . $host, PHP_URL_HOST)) {
        header('Location: ' . $ref, true, 302);
        exit;
    }
    redirect($fallback);
}

/** 내부 경로만 허용하는 next 파라미터 정리(오픈 리다이렉트 방지) */
function safe_next(?string $next, string $fallback = '/home'): string
{
    $next = (string) $next;
    if ($next === '' || $next[0] !== '/' || strpos($next, '//') === 0 || strpos($next, '/\\') === 0) {
        return $fallback;
    }

    return $next;
}

function abort(int $status, string $message = ''): void
{
    throw new HttpException($status, $message);
}

function json_ok(array $data = [], int $status = 200): void
{
    json_response(array_merge(['ok' => true], $data), $status);
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

function client_ip(): string
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
}

// ───────────────────────── 화면 ─────────────────────────

/** 템플릿을 렌더링해 문자열로 돌려준다. view('user/home', [...]) → server/views/user/home.php */
function view(string $template, array $data = []): string
{
    return View::render($template, $data);
}

/** 템플릿 안에서 감쌀 레이아웃을 정한다. layout('user/layout', ['title' => '홈', 'nav' => 'home']) */
function layout(string $name, array $vars = []): void
{
    View::setLayout($name, $vars);
}

function section(string $name): void
{
    View::startSection($name);
}

function endsection(): void
{
    View::endSection();
}

function yield_section(string $name, string $default = ''): string
{
    return View::getSection($name, $default);
}

/** 부분 템플릿을 렌더링해 돌려준다(레이아웃 없음). 템플릿에서는 <?= partial(...) ?> 로 쓴다. */
function partial(string $template, array $data = []): string
{
    return View::renderPartial($template, $data);
}

function csrf_token(): string
{
    return Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(Csrf::token()) . '">';
}

/** 다음 요청에 한 번 보여 줄 알림. type: success | error | info */
function flash(string $type, string $message): void
{
    Session::flash($type, $message);
}

function flash_messages(): array
{
    return Session::pullFlash();
}

/** 검증 실패 후 되돌아온 폼의 이전 입력값 */
function old(string $key, $default = '')
{
    return Session::old($key, $default);
}

/** 검증 오류. errors() 는 전체, errors('email') 은 해당 항목의 첫 메시지 */
function errors(?string $key = null)
{
    $all = Session::errors();
    if ($key === null) {
        return $all;
    }

    return isset($all[$key]) ? $all[$key] : null;
}

/** 오류와 입력값을 세션에 담고 직전 페이지로 돌아간다(비밀번호 항목은 담지 않는다). */
function back_with_errors(array $errors, string $fallback = '/'): void
{
    $input = $_POST;
    foreach (array_keys($input) as $k) {
        if (stripos((string) $k, 'password') !== false || $k === '_token') {
            unset($input[$k]);
        }
    }
    Session::setOldInput($input, $errors);
    redirect_back($fallback);
}

// ───────────────────────── 로그인 ─────────────────────────

function current_user(): ?array
{
    return Auth::user();
}

/** 로그인한 회원을 돌려준다. 아니면 로그인 화면(또는 JSON 401)으로 보낸다. */
function require_user(): array
{
    $user = Auth::user();
    if ($user === null) {
        if (Request::wantsJson()) {
            json_error('로그인이 필요합니다.', 401);
            exit;
        }
        redirect('/login?' . http_build_query(['next' => Request::uri()]));
    }

    return $user;
}

function current_admin(): ?array
{
    return AdminAuth::admin();
}

/** 로그인한 관리자를 돌려준다. $role 이 super 면 최고 관리자만 허용한다. */
function require_admin(?string $role = null): array
{
    $admin = AdminAuth::admin();
    if ($admin === null) {
        if (Request::wantsJson()) {
            json_error('관리자 로그인이 필요합니다.', 401);
            exit;
        }
        redirect('/admin/login?' . http_build_query(['next' => Request::uri()]));
    }
    if ($role === 'super' && $admin['role'] !== 'super') {
        abort(403, '최고 관리자만 사용할 수 있습니다.');
    }

    return $admin;
}

/** 관리자 작업 기록(감사 로그) */
function admin_audit(string $action, ?string $targetType = null, $targetId = null, $detail = null): void
{
    try {
        $admin = AdminAuth::admin();
        db_insert('admin_audit_logs', [
            'admin_id' => $admin ? (int) $admin['id'] : null,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (int) $targetId,
            'detail' => $detail === null ? null : (is_string($detail) ? $detail : json_encode($detail, JSON_UNESCAPED_UNICODE)),
            'ip' => client_ip(),
        ]);
    } catch (Throwable $e) {
        app_log('error', '감사 로그 기록 실패: ' . $e->getMessage());
    }
}

// ───────────────────────── 로그, 서식 ─────────────────────────

function app_log(string $level, string $message, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . strtoupper($level) . ' ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $dir = ensure_dir(storage_path('logs'));
    @file_put_contents($dir . '/app-' . date('Ymd') . '.log', $line . "\n", FILE_APPEND | LOCK_EX);
}

/** 밀리초를 m:ss (1시간 이상이면 h:mm:ss) 로 */
function fmt_duration($ms): string
{
    $s = (int) round(((int) $ms) / 1000);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    $sec = $s % 60;

    return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $sec) : sprintf('%02d:%02d', $m, $sec);
}

function fmt_krw($amount): string
{
    return '₩' . number_format((float) $amount);
}

function fmt_number($n, int $decimals = 0): string
{
    return number_format((float) $n, $decimals);
}

/** "3분 전", "2시간 전", "어제" 같은 상대 시각 */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $diff = time() - strtotime($datetime);
    if ($diff < 60) {
        return '방금 전';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . '분 전';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . '시간 전';
    }
    if ($diff < 172800) {
        return '어제';
    }
    if ($diff < 86400 * 30) {
        return intdiv($diff, 86400) . '일 전';
    }

    return date('Y.m.d', strtotime($datetime));
}

/** 만 나이. birth_date(Y-m-d) 우선, 없으면 birth_year 로 계산한다. */
function child_age(array $child): ?int
{
    if (!empty($child['birth_date'])) {
        $b = new DateTime($child['birth_date']);
        $age = (int) $b->diff(new DateTime('today'))->y;

        return $age;
    }
    if (!empty($child['birth_year'])) {
        return max(0, (int) date('Y') - (int) $child['birth_year'] - 1);
    }

    return null;
}

/** 문자열 앞부분만 남기고 말줄임 */
function str_limit(?string $text, int $limit = 40): string
{
    $text = (string) $text;

    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
}

/** 전화번호, 이메일 가림 처리 */
function mask_phone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string) $phone);
    if (strlen($digits) < 8) {
        return (string) $phone;
    }

    return substr($digits, 0, 3) . '-' . substr($digits, 3, strlen($digits) - 7) . '-****';
}

function mask_email(?string $email): string
{
    $email = (string) $email;
    $at = strpos($email, '@');
    if ($at === false || $at < 2) {
        return $email;
    }

    return substr($email, 0, 2) . str_repeat('*', max(1, $at - 2)) . substr($email, $at);
}

/** 목소리 프로필 아이콘(Material Symbols 이름). 지정이 없으면 라벨로 추정한다. */
function voice_icon(array $voice): string
{
    if (!empty($voice['icon'])) {
        return (string) $voice['icon'];
    }
    $label = isset($voice['label']) ? (string) $voice['label'] : '';
    if (strpos($label, '아빠') !== false || strpos($label, '삼촌') !== false) {
        return 'face_6';
    }
    if (strpos($label, '할머니') !== false) {
        return 'elderly_woman';
    }
    if (strpos($label, '할아버지') !== false) {
        return 'elderly';
    }

    return 'face_3';
}

/** 목소리 상태 한글 이름 */
function voice_status_label(string $status): string
{
    $map = [
        'draft' => '녹음 중',
        'pending' => '검토 대기',
        'cloning' => '목소리 생성 중',
        'processing' => '동화 준비 중',
        'completed' => '준비됨',
        'rejected' => '재녹음 필요',
        'failed' => '생성 실패',
    ];

    return isset($map[$status]) ? $map[$status] : $status;
}

/** JSON 문자열을 배열로. 실패하면 $default */
function json_decode_array($json, array $default = []): array
{
    if (is_array($json)) {
        return $json;
    }
    if (!is_string($json) || $json === '') {
        return $default;
    }
    $data = json_decode($json, true);

    return is_array($data) ? $data : $default;
}

function json_encode_u($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** 외부 API 키가 등록되어 있는지 */
function provider_ready(string $provider): bool
{
    if (config('providers_fake')) {
        return true;
    }
    if ($provider === 'elevenlabs') {
        return (string) config('elevenlabs.api_key', '') !== '';
    }
    if ($provider === 'gemini') {
        return (string) config('gemini.api_key', '') !== '';
    }
    if ($provider === 'kakao') {
        return (string) config('kakao.rest_api_key', '') !== '';
    }
    if ($provider === 'google') {
        return (string) config('google.client_id', '') !== '' && (string) config('google.client_secret', '') !== '';
    }

    return false;
}

// ───────────────────────── 아바타 ─────────────────────────

/** 자녀 아바타 프리셋(이모지와 배경색 토큰) */
function avatar_presets(): array
{
    return [
        'bear' => ['emoji' => '🐻', 'bg' => 'bg-tertiary-fixed'],
        'rabbit' => ['emoji' => '🐰', 'bg' => 'bg-primary-fixed'],
        'fox' => ['emoji' => '🦊', 'bg' => 'bg-secondary-fixed'],
        'cat' => ['emoji' => '🐱', 'bg' => 'bg-primary-container'],
        'dog' => ['emoji' => '🐶', 'bg' => 'bg-tertiary-container'],
        'panda' => ['emoji' => '🐼', 'bg' => 'bg-surface-container-high'],
        'tiger' => ['emoji' => '🐯', 'bg' => 'bg-secondary-container'],
        'chick' => ['emoji' => '🐥', 'bg' => 'bg-tertiary-fixed'],
        'frog' => ['emoji' => '🐸', 'bg' => 'bg-secondary-fixed'],
        'unicorn' => ['emoji' => '🦄', 'bg' => 'bg-primary-fixed'],
        'dino' => ['emoji' => '🦖', 'bg' => 'bg-secondary-container'],
        'whale' => ['emoji' => '🐳', 'bg' => 'bg-surface-container'],
    ];
}

/** 자녀 아바타 HTML. $size 는 Tailwind 크기 클래스 */
function child_avatar(?array $child, string $size = 'w-10 h-10 text-xl', string $extra = ''): string
{
    $presets = avatar_presets();
    $key = $child && !empty($child['avatar']) && isset($presets[$child['avatar']]) ? $child['avatar'] : 'bear';
    $p = $presets[$key];

    return '<span class="inline-flex shrink-0 items-center justify-center rounded-full ' . $p['bg'] . ' ' . $size . ' ' . $extra . '" aria-hidden="true">' . $p['emoji'] . '</span>';
}
