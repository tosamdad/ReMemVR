<?php
/**
 * config.php 의 db 설정으로 PDO 연결을 만든다.
 * cafe24 호스팅 MariaDB 는 서버 내부(localhost)에서만 접속되므로 host 기본값은 localhost 이다.
 */
final class Db
{
    public static function connect(array $db, bool $buffered = true): PDO
    {
        $host = isset($db['host']) && $db['host'] !== '' ? $db['host'] : 'localhost';
        $port = isset($db['port']) && $db['port'] !== '' ? (int) $db['port'] : 3306;
        $charset = isset($db['charset']) ? $db['charset'] : 'utf8mb4';

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db['name'], $charset);

        $pdo = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => $buffered,
        ]);
        $pdo->exec("SET time_zone = '+09:00'");

        return $pdo;
    }
}
