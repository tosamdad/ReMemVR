<?php
/**
 * PHP 만으로 DB 전체를 SQL 덤프로 만든다.
 * cafe24 호스팅은 exec() 와 mysqldump 를 쓸 수 없는 경우가 많아 직접 SELECT 해서 INSERT 문을 만든다.
 * 출력은 $write 콜백으로 조금씩 흘려보내므로 메모리를 크게 쓰지 않는다.
 * 덤프 마지막 줄의 "-- Dump completed" 로 덤프가 끝까지 만들어졌는지 확인할 수 있다.
 */
final class Backup
{
    const ROWS_PER_INSERT = 200;
    const COMPLETED_MARKER = '-- Dump completed';

    /** @var PDO */
    private $pdo;
    /** @var callable */
    private $write;

    /**
     * @param PDO $pdo 버퍼를 쓰지 않는 연결(Db::connect($db, false))을 권장한다.
     */
    public function __construct(PDO $pdo, callable $write)
    {
        $this->pdo = $pdo;
        $this->write = $write;
    }

    public function dump(string $dbName): void
    {
        $this->out(sprintf(
            "-- ReMemVR database dump\n-- Database: %s\n-- Created: %s\n\n",
            $dbName,
            date('Y-m-d H:i:s')
        ));
        $this->out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $tables = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as $row) {
            $this->dumpTable($row[0]);
        }

        $this->out("SET FOREIGN_KEY_CHECKS=1;\n" . self::COMPLETED_MARKER . ' ' . date('Y-m-d H:i:s') . "\n");
    }

    private function dumpTable(string $table): void
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $create = $this->pdo->query('SHOW CREATE TABLE ' . $quoted)->fetchAll(PDO::FETCH_NUM)[0];

        $this->out("-- Table {$quoted}\nDROP TABLE IF EXISTS {$quoted};\n{$create[1]};\n\n");

        $stmt = $this->pdo->query('SELECT * FROM ' . $quoted);
        $columns = null;
        $values = [];

        while ($record = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = implode(', ', array_map(function ($c) {
                    return '`' . str_replace('`', '``', $c) . '`';
                }, array_keys($record)));
            }
            $values[] = '(' . implode(', ', array_map([$this, 'literal'], array_values($record))) . ')';
            if (count($values) >= self::ROWS_PER_INSERT) {
                $this->out("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
                $values = [];
            }
        }
        $stmt->closeCursor();

        if ($values) {
            $this->out("INSERT INTO {$quoted} ({$columns}) VALUES\n" . implode(",\n", $values) . ";\n");
        }
        $this->out("\n");
    }

    private function literal($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if ($value !== '' && !preg_match('//u', $value)) {
            // 바이너리 값은 16진수로 남겨 문자셋 변환 없이 그대로 복원되게 한다.
            return '0x' . bin2hex($value);
        }

        return $this->pdo->quote((string) $value);
    }

    private function out(string $chunk): void
    {
        call_user_func($this->write, $chunk);
    }
}
