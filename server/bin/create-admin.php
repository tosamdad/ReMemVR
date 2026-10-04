<?php
/**
 * 관리자 계정을 만든다(이미 있으면 비밀번호, 이름, 권한을 바꾼다).
 *   php server/bin/create-admin.php <아이디> <비밀번호> [이름] [권한=super|admin]
 * 예) php server/bin/create-admin.php admin 'S3cure!pass' 운영관리팀
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/bootstrap.php';

$loginId = isset($argv[1]) ? trim($argv[1]) : '';
$password = isset($argv[2]) ? (string) $argv[2] : '';
$name = isset($argv[3]) && trim($argv[3]) !== '' ? trim($argv[3]) : '관리자';
$role = isset($argv[4]) && trim($argv[4]) !== '' ? trim($argv[4]) : 'super';

if ($loginId === '' || $password === '') {
    fwrite(STDERR, "사용법: php server/bin/create-admin.php <아이디> <비밀번호> [이름] [권한=super|admin]\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_.\-]{3,50}$/', $loginId)) {
    fwrite(STDERR, "오류: 아이디는 영문, 숫자, _ . - 로 3~50자여야 한다.\n");
    exit(1);
}
if (strlen($password) < 8) {
    fwrite(STDERR, "오류: 비밀번호는 8자 이상이어야 한다.\n");
    exit(1);
}
if (!in_array($role, ['super', 'admin'], true)) {
    fwrite(STDERR, "오류: 권한은 super 또는 admin 이다.\n");
    exit(1);
}
$name = mb_substr($name, 0, 50);

try {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $existing = db_one('SELECT id FROM admins WHERE login_id = ?', [$loginId]);
    if ($existing) {
        db_update('admins', [
            'password_hash' => $hash,
            'name' => $name,
            'role' => $role,
            'status' => 'active',
        ], 'id = ?', [(int) $existing['id']]);
        echo "기존 관리자 '{$loginId}' 의 비밀번호와 정보를 바꿨다. (id {$existing['id']}, 권한 {$role})\n";
    } else {
        $id = db_insert('admins', [
            'login_id' => $loginId,
            'password_hash' => $hash,
            'name' => $name,
            'role' => $role,
        ]);
        echo "관리자 '{$loginId}' 를 만들었다. (id {$id}, 이름 {$name}, 권한 {$role})\n";
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, '오류: ' . $e->getMessage() . "\n");
    exit(1);
}
