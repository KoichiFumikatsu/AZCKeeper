<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/migrations/run.php';

final class CollationProbeStatement extends PDOStatement
{
    public function __construct(private bool $available) {}
    public function fetchColumn(int $column = 0): mixed { return $this->available ? 1 : false; }
}
final class CollationProbeDatabase extends PDO
{
    public function __construct(private bool $available) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($query !== "SELECT 1 FROM information_schema.collations WHERE collation_name='utf8mb4_0900_ai_ci'") { throw new RuntimeException('Must detect actual collation availability'); }
        return new CollationProbeStatement($this->available);
    }
}
if (($argv[1] ?? '') === 'probe') {
    echo migrationCollation(new CollationProbeDatabase($argv[2] === 'present'));
    exit;
}
foreach (['present', 'missing'] as $availability) {
    $process = proc_open([PHP_BINARY, __FILE__, 'probe', $availability], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Collation probe failed'); }
    if ($availability === 'present') {
        if ($stdout !== 'utf8mb4_0900_ai_ci' || $stderr !== '') { throw new RuntimeException('Available collation must be preserved without warning'); }
    } elseif ($stdout !== 'utf8mb4_unicode_ci' || !str_contains($stderr, 'WARNING: utf8mb4_0900_ai_ci is unavailable; using utf8mb4_unicode_ci')) {
        throw new RuntimeException('Fallback must explicitly warn on stderr');
    }
}
$pdo = connectDatabase();
$collation = migrationCollation($pdo);
$pdo->exec('CREATE TEMPORARY TABLE collation_probe (name VARCHAR(160)) CHARACTER SET utf8mb4 COLLATE ' . $collation);
$expected = $pdo->query('SHOW FULL COLUMNS FROM collation_probe')->fetch(PDO::FETCH_ASSOC)['Collation'];
foreach (['tenants', 'org_units', 'roles', 'permissions'] as $table) {
    foreach ($pdo->query('SHOW FULL COLUMNS FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC) as $column) {
        if (str_starts_with($column['Collation'] ?? '', 'utf8mb4_') && $column['Collation'] !== $expected) { throw new RuntimeException('Unexpected migrated collation: ' . $table . '.' . $column['Field']); }
    }
}
foreach ($pdo->query('SELECT filename,sha256 FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (!hash_equals($row['sha256'], hash_file('sha256', dirname(__DIR__) . '/migrations/' . $row['filename'], true))) { throw new RuntimeException('Migration hash changed'); }
}
echo "PASS migration collation: availability, explicit stderr fallback, actual table collations and original hashes\n";
