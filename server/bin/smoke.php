<?php
/**
 * 화면 점검(smoke test). 실행 중인 사이트에 실제 HTTP 요청을 보내 주요 화면이 오류 없이 열리는지 확인한다.
 *   php server/bin/smoke.php http://127.0.0.1:8000 [회원이메일:비밀번호] [관리자아이디:비밀번호]
 * 회원, 관리자 정보를 주면 로그인해서 로그인 후 화면까지 확인한다(데모 데이터: server/bin/demo-seed.php).
 * 상태 코드가 기대와 다르거나 본문에 PHP 오류 문구가 있으면 실패(종료 코드 1).
 */
if (PHP_SAPI !== 'cli') {
    exit;
}

$base = rtrim(isset($argv[1]) ? $argv[1] : 'http://127.0.0.1:8000', '/');
$userCred = isset($argv[2]) ? $argv[2] : '';
$adminCred = isset($argv[3]) ? $argv[3] : '';
$jar = tempnam(sys_get_temp_dir(), 'smoke');
$failures = 0;

function http(string $method, string $url, array $form = null, string $jar = '', array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_PROXY => '',
        CURLOPT_NOPROXY => '*',
    ]);
    if ($form !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    $body = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    return [$status, $body, $location];
}

function token_from(string $html): string
{
    return preg_match('/name="csrf-token" content="([^"]+)"/', $html, $m) ? $m[1] : '';
}

function check(string $label, int $status, string $body, array $expect): void
{
    global $failures;
    $bad = '';
    if (!in_array($status, $expect, true)) {
        $bad = 'HTTP ' . $status . ' (기대 ' . implode('/', $expect) . ')';
    } elseif (preg_match('/(Fatal error|Warning: |Notice: |Deprecated: |Parse error|Uncaught )/', $body, $m)) {
        $bad = 'PHP 오류 문구: ' . $m[1];
    }
    if ($bad !== '') {
        $failures++;
        echo "  FAIL  $label  $bad\n";
    } else {
        echo "  ok    $label  ($status)\n";
    }
}

function login(string $base, string $path, string $idField, string $cred, string $jar): bool
{
    list($id, $pw) = array_pad(explode(':', $cred, 2), 2, '');
    list($s, $html) = http('GET', $base . $path, null, $jar);
    $token = token_from($html);
    list($status, $body, $loc) = http('POST', $base . $path, ['_token' => $token, $idField => $id, 'password' => $pw], $jar);
    $ok = $status === 302 && strpos($loc, 'login') === false;
    echo ($ok ? '  ok    ' : '  FAIL  ') . "로그인 $path ($status → $loc)\n";

    return $ok;
}

echo "공개 화면\n";
foreach ([['/', [200]], ['/login', [200]], ['/signup', [200]], ['/password/forgot', [200]], ['/healthz', [200]],
          ['/manifest.webmanifest', [200]], ['/home', [302]], ['/admin', [302]], ['/admin/login', [200, 302]], ['/없는-주소', [404]]] as $c) {
    list($status, $body) = http('GET', $base . $c[0], null, $jar);
    check('GET ' . $c[0], $status, $body, $c[1]);
}

if ($userCred !== '') {
    echo "회원 화면\n";
    if (login($base, '/login', 'email', $userCred, $jar)) {
        foreach (['/home', '/stories', '/player', '/report', '/report?range=30', '/voice-lab', '/voice-lab/new', '/settings',
                  '/settings/profile', '/settings/password', '/settings/children', '/settings/playback', '/settings/notices',
                  '/settings/support', '/settings/terms', '/settings/privacy', '/settings/withdraw'] as $p) {
            list($status, $body) = http('GET', $base . $p, null, $jar);
            check('GET ' . $p, $status, $body, [200]);
        }
        list($status, $body) = http('GET', $base . '/api/voice-lab/status', null, $jar, ['Accept: application/json']);
        check('GET /api/voice-lab/status', $status, $body, [200]);
    } else {
        $failures++;
    }
}

if ($adminCred !== '') {
    echo "관리자 화면\n";
    if (login($base, '/admin/login', 'login_id', $adminCred, $jar)) {
        foreach (['/admin/dashboard', '/admin/voices', '/admin/voices?status=pending', '/admin/stories', '/admin/stories/new',
                  '/admin/members', '/admin/settings', '/admin/notices', '/admin/faqs', '/admin/inquiries', '/admin/admins',
                  '/admin/audit'] as $p) {
            list($status, $body) = http('GET', $base . $p, null, $jar);
            check('GET ' . $p, $status, $body, [200]);
        }
        foreach (['/admin/api/dashboard'] as $p) {
            list($status, $body) = http('GET', $base . $p, null, $jar, ['Accept: application/json']);
            check('GET ' . $p, $status, $body, [200]);
        }
        list($status, $body) = http('GET', $base . '/admin/members/export.csv', null, $jar);
        check('GET /admin/members/export.csv', $status, $body, [200]);
    } else {
        $failures++;
    }
}

@unlink($jar);
echo $failures === 0 ? "\n모든 화면 정상\n" : "\n실패 $failures 건\n";
exit($failures === 0 ? 0 : 1);
