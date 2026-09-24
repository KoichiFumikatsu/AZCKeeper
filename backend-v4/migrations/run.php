<?php
declare(strict_types=1);

// CLI only; credentials are read from the environment or a password file.
function connectDatabase(): PDO
{
    $dsn = getenv('KEEPER_DB_DSN');
    if (!$dsn || !str_starts_with($dsn, 'mysql:') || !str_contains($dsn, 'dbname=')) {
        throw new RuntimeException('Set KEEPER_DB_DSN=mysql:host=...;port=3306;dbname=keeper_v4;charset=utf8mb4');
    }
    $passwordFile = getenv('KEEPER_DB_PASSWORD_FILE');
    $password = $passwordFile ? rtrim((string) file_get_contents($passwordFile), "\r\n") : (getenv('KEEPER_DB_PASSWORD') ?: '');
    $pdo = new PDO($dsn, getenv('KEEPER_DB_USER') ?: 'keeper_migrator', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
    ]);
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    // MariaDB >= 10.5 y MySQL >= 8.0.30 cumplen lo que exige el esquema:
    // CHECK aplicado, CREATE TRIGGER IF NOT EXISTS y LOCK IN SHARE MODE.
    if (stripos($version, 'MariaDB') !== false) {
        if (!preg_match('/(\d+\.\d+\.\d+)/', $version, $m) || version_compare($m[1], '10.5', '<')) {
            throw new RuntimeException('Requires MariaDB >= 10.5 (CHECK enforcement and CREATE TRIGGER IF NOT EXISTS).');
        }
    } elseif (version_compare($version, '8.0.30', '<')) {
        throw new RuntimeException('Requires MySQL >= 8.0.30 (CHECK enforcement and CREATE TRIGGER IF NOT EXISTS).');
    }
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY'");
    return $pdo;
}

/** Split MySQL scripts without interpreting delimiters inside strings or comments. */
function statements(string $sql): Generator
{
    $delimiter = ';';
    $buffer = '';
    $state = 'sql';
    $hasSql = false;
    foreach (preg_split('/(?<=\n)|(?<=\r)(?!\n)/', $sql) as $line) {
        if ($state === 'sql' && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $match)) {
            if ($hasSql) {
                throw new RuntimeException('DELIMITER inside an unfinished statement');
            }
            $delimiter = $match[1];
            $buffer = '';
            continue;
        }
        for ($i = 0, $length = strlen($line); $i < $length; ++$i) {
            $char = $line[$i];
            $next = $line[$i + 1] ?? '';
            if ($state === 'line') {
                $buffer .= $char;
                if ($char === "\n" || $char === "\r") {
                    $state = 'sql';
                }
            } elseif ($state === 'block') {
                $buffer .= $char;
                if ($char === '*' && $next === '/') {
                    $buffer .= $line[++$i];
                    $state = 'sql';
                }
            } elseif ($state !== 'sql') {
                $buffer .= $char;
                if (($char === '\\' && $state !== '`') || ($char === $state && $next === $state)) {
                    if ($next !== '') {
                        $buffer .= $line[++$i];
                    }
                } elseif ($char === $state) {
                    $state = 'sql';
                }
            } elseif (substr($line, $i, strlen($delimiter)) === $delimiter) {
                if ($hasSql) {
                    yield trim($buffer);
                }
                $buffer = '';
                $hasSql = false;
                $i += strlen($delimiter) - 1;
            } elseif ($char === '#' || ($char === '-' && $next === '-' && ord($line[$i + 2] ?? "\n") <= 32)) {
                $buffer .= $char;
                $state = 'line';
            } elseif ($char === '/' && $next === '*') {
                $buffer .= $char . $line[++$i];
                $state = 'block';
                // MySQL version comments may contain executable SQL.
                $hasSql = $hasSql || ($line[$i + 1] ?? '') === '!';
            } else {
                $buffer .= $char;
                $hasSql = $hasSql || !ctype_space($char);
                if ($char === "'" || $char === '"' || $char === '`') {
                    $state = $char;
                }
            }
        }
    }
    if ($hasSql || !in_array($state, ['sql', 'line'], true)) {
        throw new RuntimeException('Unterminated SQL statement');
    }
}

function migrationCollation(PDO $pdo): string
{
    if ($pdo->query("SELECT 1 FROM information_schema.collations WHERE collation_name='utf8mb4_0900_ai_ci'")->fetchColumn() !== false) {
        return 'utf8mb4_0900_ai_ci';
    }
    fwrite(STDERR, "WARNING: utf8mb4_0900_ai_ci is unavailable; using utf8mb4_unicode_ci for new migration statements.\n");
    return 'utf8mb4_unicode_ci';
}

function migrate(PDO $pdo): void
{
    $lock = 'keeper_v4:' . substr(hash('sha256', (string) $pdo->query('SELECT DATABASE()')->fetchColumn()), 0, 48);
    $query = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $query->execute([$lock]);
    if ((int) $query->fetchColumn() !== 1) {
        throw new RuntimeException('Another migration runner holds the database lock');
    }
    try {
        $collation = migrationCollation($pdo);
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            filename VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            sha256 BINARY(32) NOT NULL,
            applied_at DATETIME(6) NOT NULL
        ) ENGINE=InnoDB');
        $files = glob(__DIR__ . '/[0-9][0-9][0-9][0-9]_*.sql');
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $name = basename($file);
            $hash = hash_file('sha256', $file, true);
            $query = $pdo->prepare('SELECT sha256 FROM schema_migrations WHERE filename = ?');
            $query->execute([$name]);
            $previous = $query->fetchColumn();
            if ($previous !== false) {
                if (!hash_equals($previous, $hash)) {
                    throw new RuntimeException("Applied migration changed: $name");
                }
                echo "SKIP $name\n";
                continue;
            }
            $transactional = $name === '0008_seeds.sql';
            if ($transactional) {
                $pdo->beginTransaction();
            }
            foreach (statements((string) file_get_contents($file)) as $sql) {
                if ($collation !== 'utf8mb4_0900_ai_ci') { $sql = str_replace('utf8mb4_0900_ai_ci', $collation, $sql); }
                $pdo->exec($sql);
            }
            $query = $pdo->prepare('INSERT INTO schema_migrations (filename, sha256, applied_at) VALUES (?, ?, UTC_TIMESTAMP(6))');
            $query->execute([$name, $hash]);
            if ($transactional) {
                $pdo->commit();
            }
            echo "APPLIED $name\n";
        }
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $query = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $query->execute([$lock]);
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    try {
        migrate(connectDatabase());
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
