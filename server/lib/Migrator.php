<?php
/**
 * migrations 폴더의 번호 붙은 SQL 파일을 순서대로 한 번씩만 적용한다.
 *
 * - 파일 이름 규칙: 0001_설명.sql (숫자 4자리 이상 + 밑줄 + 영문 소문자/숫자/밑줄)
 * - 적용 이력은 schema_migrations 테이블에 버전, 파일명, SHA-256 체크섬으로 남긴다.
 * - 이미 적용된 파일이 수정되면 체크섬이 달라지므로 오류로 멈춘다. 변경은 항상 새 파일로 추가한다.
 * - MariaDB 의 DDL 은 자동 커밋되므로 파일 하나가 중간에 실패하면 그 앞 문장은 이미 반영된 상태이다.
 *   실패한 파일은 기록되지 않으므로, 원인을 고친 새 파일을 올리거나 수동 정리 후 다시 배포한다.
 */
final class Migrator
{
    const LOCK_NAME = 'rememvr_schema_migrate';
    const FILE_PATTERN = '/^(\d{4,})_[a-z0-9_]+\.sql$/';

    /** @var PDO */
    private $pdo;
    /** @var string */
    private $dir;

    public function __construct(PDO $pdo, string $dir)
    {
        $this->pdo = $pdo;
        $this->dir = rtrim($dir, '/');
    }

    /**
     * 파일별 상태를 돌려준다. state: applied | pending | modified
     */
    public function status(): array
    {
        $this->ensureTable();
        $applied = $this->appliedVersions();
        $rows = [];

        foreach ($this->files() as $version => $file) {
            $checksum = hash_file('sha256', $file);
            $name = basename($file);
            if (!isset($applied[$version])) {
                $rows[] = ['version' => $version, 'file' => $name, 'state' => 'pending'];
            } elseif ($applied[$version]['checksum'] !== $checksum) {
                $rows[] = ['version' => $version, 'file' => $name, 'state' => 'modified',
                    'applied_at' => $applied[$version]['applied_at']];
            } else {
                $rows[] = ['version' => $version, 'file' => $name, 'state' => 'applied',
                    'applied_at' => $applied[$version]['applied_at']];
            }
            unset($applied[$version]);
        }

        // DB 에는 기록이 있지만 저장소에서 사라진 파일
        foreach ($applied as $version => $row) {
            $rows[] = ['version' => $version, 'file' => $row['filename'], 'state' => 'missing',
                'applied_at' => $row['applied_at']];
        }

        return $rows;
    }

    /**
     * 미적용 파일을 순서대로 적용한다.
     *
     * @return array{applied: string[], skipped: int}
     */
    public function migrate(): array
    {
        $this->ensureTable();

        $locked = (int) $this->pdo->query("SELECT GET_LOCK('" . self::LOCK_NAME . "', 30)")->fetchColumn();
        if ($locked !== 1) {
            throw new RuntimeException('다른 마이그레이션이 실행 중이다. 잠시 후 다시 시도한다.');
        }

        try {
            $problems = [];
            foreach ($this->status() as $row) {
                if ($row['state'] === 'modified') {
                    $problems[] = $row['file'] . ' 파일이 적용 후 수정되었다. 기존 파일은 되돌리고 변경은 새 번호 파일로 추가한다.';
                }
            }
            if ($problems) {
                throw new RuntimeException(implode(' ', $problems));
            }

            $applied = $this->appliedVersions();
            $done = [];
            $skipped = 0;

            foreach ($this->files() as $version => $file) {
                if (isset($applied[$version])) {
                    $skipped++;
                    continue;
                }
                $this->applyFile($version, $file);
                $done[] = basename($file);
            }

            return ['applied' => $done, 'skipped' => $skipped];
        } finally {
            $this->pdo->query("SELECT RELEASE_LOCK('" . self::LOCK_NAME . "')");
        }
    }

    private function applyFile(string $version, string $file): void
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException(basename($file) . ' 파일을 읽을 수 없다.');
        }

        $started = microtime(true);
        $statements = SqlSplitter::split($sql);

        foreach ($statements as $index => $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (PDOException $e) {
                throw new RuntimeException(sprintf(
                    '%s 의 %d번째 문장에서 실패했다: %s',
                    basename($file),
                    $index + 1,
                    $e->getMessage()
                ), 0, $e);
            }
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO schema_migrations (version, filename, checksum, applied_at, execution_ms)
             VALUES (?, ?, ?, NOW(), ?)'
        );
        $stmt->execute([
            $version,
            basename($file),
            hash_file('sha256', $file),
            (int) round((microtime(true) - $started) * 1000),
        ]);
    }

    /**
     * @return array<string, string> 버전 => 파일 경로 (버전 오름차순)
     */
    private function files(): array
    {
        $files = [];
        foreach (glob($this->dir . '/*.sql') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match(self::FILE_PATTERN, $name, $m)) {
                throw new RuntimeException($name . ' 은 마이그레이션 파일 이름 규칙(0001_설명.sql)에 맞지 않는다.');
            }
            $version = $m[1];
            if (isset($files[$version])) {
                throw new RuntimeException('버전 ' . $version . ' 이 중복되었다: ' . basename($files[$version]) . ', ' . $name);
            }
            $files[$version] = $path;
        }
        uksort($files, function ($a, $b) {
            return strnatcmp($a, $b);
        });

        return $files;
    }

    private function appliedVersions(): array
    {
        $rows = $this->pdo->query('SELECT version, filename, checksum, applied_at FROM schema_migrations')->fetchAll();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['version']] = $row;
        }

        return $result;
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(32) NOT NULL PRIMARY KEY,
                filename VARCHAR(191) NOT NULL,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL,
                execution_ms INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
