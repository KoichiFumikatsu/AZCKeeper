<?php
declare(strict_types=1);

use Keeper\Config;
use Keeper\Util;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/k3-backfill.php';

final class K3ReadOnly
{
    private PDO $pdo;

    public function __construct()
    {
        $this->reconnect();
    }

    private function reconnect(): void
    {
        $this->pdo = K3Import::connect(K3Import::env('K3_DSN'), K3Import::env('K3_USER'),
            K3Import::password('K3_PASSWORD', 'K3_PASSWORD_FILE'));
        // Fail closed: no source connection is exposed without server-enforced read-only mode.
        $this->pdo->prepare('SET SESSION TRANSACTION READ ONLY')->execute();
        $version=(string)$this->select('SELECT VERSION()')->fetchColumn();
        $readOnly=stripos($version,'MariaDB')!==false?'tx_read_only':'transaction_read_only';
        if ((int) $this->select('SELECT @@session.'.$readOnly)->fetchColumn() !== 1) {
            throw new RuntimeException('K3 no confirmó el modo de solo lectura.');
        }
        $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        foreach (['net_read_timeout' => 120, 'net_write_timeout' => 120, 'wait_timeout' => 600] as $setting => $seconds) {
            try { @$this->pdo->prepare("SET SESSION $setting = $seconds")->execute(); }
            catch (PDOException) { /* Optional session settings may be denied to the reader. */ }
        }
    }

    public function select(string $sql, array $args = []): PDOStatement
    {
        if (!preg_match('/^SELECT\s/i', $sql) || preg_match('/;|--|\/\*|\b(INTO|OUTFILE|DUMPFILE|FOR\s+UPDATE|LOCK|SLEEP|GET_LOCK)\b/i', $sql)) {
            throw new LogicException('Consulta K3 no permitida.');
        }
        return K3Import::statement($this->pdo, $sql, $args);
    }

    public static function disconnected(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1040, 1203, 2002, 2003, 2006, 2013, 2014, 2027, 2055], true)
            || str_starts_with((string) $e->getCode(), '08');
    }

    public function page(string $sql, array $args): array
    {
        for ($attempt = 0; ; $attempt++) {
            try {
                if ($attempt > 0) { @$this->reconnect(); }
                $stmt = @$this->select($sql, $args);
                $rows = @$stmt->fetchAll();
                $stmt->closeCursor();
                return $rows;
            } catch (PDOException $e) {
                if (!self::disconnected($e) || $attempt >= 3) { throw $e; }
                unset($stmt);
                usleep(250000 * ($attempt + 1));
            }
        }
    }
}

final class K3ReadInterrupted extends RuntimeException {}

final class K3Import
{
    use K3Backfill;
    private const BATCH = 500;
    private const PAGE = 1000;
    private const HISTORY_START = '1970-01-01 00:00:00.000000';
    private const PLATFORM = '00000000-0000-4000-8000-000000000001';
    private const NAMESPACE = '6c40ce1e-ff53-51be-b17c-88e792106073';
    private const VERSION = 'k3-import-v2';
    private K3ReadOnly $source;
    private PDO $dev;
    private string $tenant;
    private string $now;
    private bool $dry;
    private array $only;
    private int $episodeDays;
    private int $episodeMax;
    private array $stats = [];
    private array $reads = [];
    private array $reasons = [];
    private array $org = [];
    private array $aliases = [];
    private array $schedules = [];
    private array $scheduleForUser = [];
    private array $users = [];
    private array $devices = [];
    private array $assignments = [];
    private array $deviceAssignments = [];
    private array $virtual = [];
    private array $pending = [];
    private array $holidayDays = [];
    private ?string $lock = null;
    private ?string $exportBackfill = null;
    private ?string $importBackfill = null;
    private array $backfillStats = [];
    private string $datetimeZone = 'UTC';

    public static function env(string $name, string $default = ''): string
    {
        $value = getenv($name);
        return $value === false || $value === '' ? $default : $value;
    }

    public static function password(string $variable, string $fileVariable): string
    {
        $file = self::env($fileVariable);
        if ($file === '') { return self::env($variable); }
        $value = @file_get_contents($file);
        if ($value === false) { throw new RuntimeException('No se pudo leer ' . $fileVariable . '.'); }
        return rtrim($value, "\r\n");
    }

    public static function connect(string $dsn, string $user, string $password): PDO
    {
        if (!str_starts_with($dsn, 'mysql:') || !preg_match('/(?:^|;)dbname=[^;]+/', substr($dsn, 6))) {
            throw new RuntimeException('Se requiere un DSN MySQL con dbname.');
        }
        return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
    }

    public static function statement(PDO $pdo, string $sql, array $args = []): PDOStatement
    {
        $stmt = $pdo->prepare($sql);
        foreach (array_values($args) as $i => $value) {
            $stmt->bindValue($i + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        $stmt->execute();
        return $stmt;
    }

    private function sql(string $sql, array $args = []): PDOStatement { return self::statement($this->dev, $sql, $args); }
    private function selected(string $group): bool { return in_array($group, $this->only, true); }

    public function __construct(array $argv)
    {
        $this->dry = false;
        $this->only = ['org', 'users', 'devices', 'holidays', 'daily', 'episodes', 'compliance', 'coverage'];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '--dry-run') { $this->dry = true; }
            elseif (str_starts_with($arg, '--export-backfill=')) { $this->exportBackfill = substr($arg, 18); }
            elseif (str_starts_with($arg, '--import-backfill=')) { $this->importBackfill = substr($arg, 18); }
            elseif (str_starts_with($arg, '--only=')) {
                $this->only = array_values(array_unique(explode(',', substr($arg, 7))));
                if (!$this->only || array_diff($this->only, ['org', 'users', 'devices', 'holidays', 'daily', 'episodes', 'compliance', 'coverage'])) {
                    throw new RuntimeException('--only admite org,users,devices,holidays,daily,episodes,compliance,coverage.');
                }
            } else { throw new RuntimeException('Flag desconocido. Use --help.'); }
        }
        if ($this->exportBackfill !== null || $this->importBackfill !== null) {
            if (($this->exportBackfill !== null && $this->importBackfill !== null)
                || count(array_filter($argv, static fn($v) => str_starts_with($v, '--only=')))
                || ($this->exportBackfill !== null && $this->dry)) {
                throw new RuntimeException('Use un solo modo backfill, sin --only; --dry-run solo admite importación.');
            }
            $path = $this->exportBackfill ?? $this->importBackfill;
            if ($path === '' || str_contains($path, '://')) { throw new RuntimeException('Backfill requiere una ruta de archivo local.'); }
        }
        $this->datetimeZone = $this->importBackfill === null ? self::env('K3_DATETIME_TIMEZONE', 'UTC') : 'UTC';
        new DateTimeZone($this->datetimeZone);
        if ($this->exportBackfill !== null) { $this->source = new K3ReadOnly(); return; }
        $this->episodeDays = $this->importBackfill === null ? self::positive('K3_EPISODE_DAYS', 3, 90) : 3;
        $this->episodeMax = $this->importBackfill === null ? self::positive('K3_EPISODE_MAX', 50000, 50000) : 50000;
        if ($this->importBackfill === null) { $this->source = new K3ReadOnly(); }
        $this->dev = self::connect(Config::get('DB_DSN'), Config::get('DB_USER'),
            self::password('KEEPER_DB_PASSWORD', 'KEEPER_DB_PASSWORD_FILE'));
        $origin = $this->importBackfill === null ? $this->source->select('SELECT DATABASE()')->fetchColumn() : '';
        $target = $this->sql('SELECT DATABASE()')->fetchColumn();
        // Reject even same-named databases on different hosts; aliases/tunnels cannot bypass this guard.
        if (strcasecmp((string) $origin, (string) $target) === 0 || $this->sql(
            "SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='keeper_users' LIMIT 1"
        )->fetchColumn()) { throw new RuntimeException('Destino rechazado: coincide con K3 o contiene keeper_users.'); }
        $this->sql("SET time_zone = '+00:00'");
        $this->sql("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION,NO_ZERO_DATE,NO_ZERO_IN_DATE,ONLY_FULL_GROUP_BY'");
        $this->now = (string) $this->sql('SELECT UTC_TIMESTAMP(6)')->fetchColumn();
        $requested = self::env('IMPORT_TENANT_ID');
        if ($requested !== '' && !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $requested)) {
            throw new RuntimeException('IMPORT_TENANT_ID debe ser un UUID.');
        }
        $rows = $requested === ''
            ? $this->sql('SELECT tenant_id FROM tenants WHERE tenant_id<>? LIMIT 2', [Util::bin(self::PLATFORM)])->fetchAll()
            : $this->sql('SELECT tenant_id FROM tenants WHERE tenant_id=? AND tenant_id<>?', [Util::bin($requested), Util::bin(self::PLATFORM)])->fetchAll();
        if (count($rows) !== 1) { throw new RuntimeException('Indique IMPORT_TENANT_ID de un tenant existente no-plataforma; la selección no es única.'); }
        $this->tenant = $rows[0]['tenant_id'];
        if (!$this->dry) {
            $lock = 'k3-import:' . substr(hash('sha256', $target . bin2hex($this->tenant)), 0, 48);
            if ((int) $this->sql('SELECT GET_LOCK(?,0)', [$lock])->fetchColumn() !== 1) {
                throw new RuntimeException('Ya hay una importación activa para este tenant.');
            }
            $this->lock = $lock;
        }
    }

    private static function positive(string $name, int $default, int $max): int
    {
        $value = self::env($name, (string) $default);
        if (!ctype_digit($value) || (int) $value < 1 || (int) $value > $max) {
            throw new RuntimeException($name . ' debe estar entre 1 y ' . $max . '.');
        }
        return (int) $value;
    }

    private function id(string $type, mixed $legacy): string
    {
        $hash = substr(sha1(Util::bin(self::NAMESPACE) . bin2hex($this->tenant) . ':' . $type . ':' . (string) $legacy, true), 0, 16);
        $hash[6] = chr((ord($hash[6]) & 15) | 80);
        $hash[8] = chr((ord($hash[8]) & 63) | 128);
        return $hash;
    }

    private static function cut(mixed $value, int $length): string { return mb_substr(trim((string) $value), 0, $length, 'UTF-8'); }
    private static function json(mixed $value): string { return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); }
    private static function stamp(mixed $value): ?string { return $value === null ? null : gmdate('Y-m-d H:i:s', (int) $value) . '.000000'; }
    private static function validDate(mixed $value): bool
    {
        if (!is_string($value)) { return false; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function sourceRows(string $table, string $sql, array $args = [], array $order = ['id' => 'ASC'], ?int $limit = null): Generator
    {
        $this->reads[$table] ??= 0;
        $cursor = null;
        $processed = 0;
        do {
            $size = min(self::PAGE, $limit === null ? self::PAGE : $limit - $processed);
            if ($size <= 0) { return; }
            $query = $sql;
            $params = $args;
            if ($cursor !== null) {
                $terms = [];
                $equal = [];
                $values = [];
                foreach ($order as $column => $direction) {
                    $terms[] = '(' . implode(' AND ', [...$equal, $column . ($direction === 'DESC' ? '<?' : '>?')]) . ')';
                    array_push($params, ...[...$values, $cursor[$column]]);
                    $equal[] = "$column=?";
                    $values[] = $cursor[$column];
                }
                $query .= (stripos($sql, ' WHERE ') === false ? ' WHERE ' : ' AND ') . '(' . implode(' OR ', $terms) . ')';
            }
            $query .= ' ORDER BY ' . implode(',', array_map(static fn($column, $direction) => "$column $direction", array_keys($order), $order)) . ' LIMIT ?';
            try { $rows = $this->source->page($query, [...$params, $size]); }
            catch (PDOException $e) {
                if (!K3ReadOnly::disconnected($e)) { throw $e; }
                throw new K3ReadInterrupted('Lectura K3 incompleta: ' . $table . ', procesadas ' . $processed
                    . ', último id ' . ($cursor['id'] ?? 'ninguno') . '; agotados 3 reintentos. Reejecute para completar.');
            }
            // Release the source cursor before any destination work; failed pages are never yielded.
            foreach ($rows as $row) {
                $this->reads[$table]++;
                $processed++;
                $cursor = $row;
                yield $row;
            }
        } while (count($rows) === $size);
    }

    private function stat(string $table): void
    {
        $this->stats[$table] ??= ['leidas' => 0, 'insertadas' => 0, 'actualizadas' => 0, 'omitidas' => 0];
    }

    private function skip(string $table, string $reason, int $count = 1): void
    {
        $this->stat($table);
        $this->stats[$table]['leidas'] += $count;
        $this->stats[$table]['omitidas'] += $count;
        $this->reasons[$table . ': ' . $reason] = ($this->reasons[$table . ': ' . $reason] ?? 0) + $count;
    }

    private static function key(array $row, array $keys): string
    {
        return serialize(array_map(static fn($key) => (string) $row[$key], $keys));
    }

    private function rowFailure(PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [1048, 1062, 1264, 1292, 1366, 1406, 1452, 1644, 3819, 4025], true);
    }

    /** Multi-row inserts; explicit updates avoid BEFORE INSERT overlap triggers on assignments. */
    private function write(string $table, array $rows, array $keys, bool $immutable = false): array
    {
        $accepted = [];
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $this->stat($table);
            $where = implode(' AND ', array_map(static fn($key) => "`$key`=?", $keys));
            $params = [];
            foreach ($chunk as &$row) {
                $row = ['tenant_id' => $this->tenant] + $row;
                foreach ($keys as $key) { $params[] = $row[$key]; }
            }
            unset($row);
            $lookup = $immutable ? 'episode_ingest_keys' : $table;
            $existing = [];
            foreach ($this->sql("SELECT * FROM `$lookup` WHERE tenant_id=? AND (" . implode(' OR ', array_fill(0, count($chunk), "($where)")) . ')', [$this->tenant, ...$params])->fetchAll() as $row) {
                $existing[self::key($row, $keys)] = true;
            }
            $insert = [];
            $update = [];
            foreach ($chunk as $row) {
                $key = self::key($row, $keys);
                if (isset($existing[$key]) || isset($this->virtual[$table][$key])) {
                    if ($immutable) { $this->skip($table, 'ya importada (inmutable)'); }
                    else { $update[] = $row; }
                } else { $insert[] = $row; }
            }
            if ($this->dry) {
                foreach (array_merge($insert, $update) as $row) {
                    $accepted[] = $row;
                    $this->virtual[$table][self::key($row, $keys)] = true;
                }
                $this->stats[$table]['leidas'] += count($insert) + count($update);
                $this->stats[$table]['insertadas'] += count($insert);
                $this->stats[$table]['actualizadas'] += count($update);
                continue;
            }
            $before = $this->stats;
            $reasonsBefore = $this->reasons;
            $acceptedBefore = count($accepted);
            $this->dev->beginTransaction();
            try {
                foreach ($update as $row) {
                    $columns = array_diff(array_keys($row), ['tenant_id', ...$keys]);
                    $values = array_map(static fn($key) => $row[$key], $columns);
                    $this->sql('SAVEPOINT import_row');
                    try {
                        if ($columns) {
                            $this->sql("UPDATE `$table` SET " . implode(',', array_map(static fn($key) => "`$key`=?", $columns))
                                . " WHERE tenant_id=? AND $where", [...$values, $this->tenant, ...array_map(static fn($key) => $row[$key], $keys)]);
                        }
                        $accepted[] = $row;
                        $this->stats[$table]['leidas']++;
                        $this->stats[$table]['actualizadas']++;
                    } catch (PDOException $e) {
                        $this->sql('ROLLBACK TO SAVEPOINT import_row');
                        if (!$this->rowFailure($e)) { throw $e; }
                        $this->skip($table, 'restricción SQL ' . (int) $e->errorInfo[1]);
                    }
                }
                if ($insert) {
                    $columns = array_keys($insert[0]);
                    $prefix = "INSERT INTO `$table` (`" . implode('`,`', $columns) . '`) VALUES ';
                    $tuple = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
                    $this->sql('SAVEPOINT import_batch');
                    try {
                        $this->sql($prefix . implode(',', array_fill(0, count($insert), $tuple)), array_merge(...array_map('array_values', $insert)));
                        array_push($accepted, ...$insert);
                        $this->stats[$table]['leidas'] += count($insert);
                        $this->stats[$table]['insertadas'] += count($insert);
                    } catch (PDOException $e) {
                        $this->sql('ROLLBACK TO SAVEPOINT import_batch');
                        if (!$this->rowFailure($e)) { throw $e; }
                        foreach ($insert as $row) {
                            $this->sql('SAVEPOINT import_row');
                            try {
                                $this->sql($prefix . $tuple, array_values($row));
                                $accepted[] = $row;
                                $this->stats[$table]['leidas']++;
                                $this->stats[$table]['insertadas']++;
                            } catch (PDOException $e) {
                                $this->sql('ROLLBACK TO SAVEPOINT import_row');
                                if (!$this->rowFailure($e)) { throw $e; }
                                $this->skip($table, 'restricción SQL ' . (int) $e->errorInfo[1]);
                            }
                        }
                    }
                }
                if (in_array($table, ['episode_daily', 'day_summary', 'focus_daily'], true)) {
                    foreach (array_slice($accepted, $acceptedBefore) as $row) {
                        // A corrected assignment must move an imported daily total, not double it.
                        $extra = $table === 'episode_daily' ? ' AND device_id=? AND process_name=?' : '';
                        $args = [$this->tenant, $row['day'], $row['user_id'], self::VERSION, $row['user_assignment_id']];
                        if ($extra !== '') { array_push($args, $row['device_id'], $row['process_name']); }
                        $this->sql("DELETE FROM `$table` WHERE tenant_id=? AND day=? AND user_id=? AND calculation_version=? AND user_assignment_id<>?" . $extra, $args);
                    }
                }
                $this->dev->commit();
            } catch (Throwable $e) {
                if ($this->dev->inTransaction()) { $this->dev->rollBack(); }
                $this->stats = $before;
                $this->reasons = $reasonsBefore;
                throw $e;
            }
        }
        return $accepted;
    }

    private function queue(string $table, array $row, array $keys, bool $immutable = false): void
    {
        $this->pending[$table] ??= ['rows' => [], 'keys' => $keys, 'immutable' => $immutable];
        $this->pending[$table]['rows'][] = $row;
        if (count($this->pending[$table]['rows']) >= self::BATCH) { $this->flush($table); }
    }

    private function flush(?string $table = null): void
    {
        foreach ($table === null ? array_keys($this->pending) : [$table] as $name) {
            $p = $this->pending[$name];
            $this->write($name, $p['rows'], $p['keys'], $p['immutable']);
            $this->pending[$name]['rows'] = [];
        }
    }

    public function run(): void
    {
        if ($this->exportBackfill !== null) { $this->exportBackfill(); return; }
        if ($this->importBackfill !== null) { $this->importBackfill(); return; }
        try { $this->runGroups(); }
        catch (K3ReadInterrupted $e) {
            $this->flush();
            throw $e;
        }
    }

    private function runGroups(): void
    {
        echo ($this->dry ? 'DRY-RUN: conteos proyectados, sin escrituras. ' : 'Importación dev. ') . 'Tenant ' . Util::id($this->tenant) . "\n";
        $name = self::env('IMPORT_TENANT_NAME');
        if ($name !== '') {
            if (mb_strlen(trim($name)) > 120 || trim($name) === '') { throw new RuntimeException('IMPORT_TENANT_NAME debe tener 1–120 caracteres.'); }
            $this->write('tenants', [['tenant_id' => $this->tenant, 'name' => trim($name)]], ['tenant_id']);
            $this->write('branding', [['display_name' => trim($name)]], ['tenant_id']);
        }
        $this->loadExisting();
        if ($this->selected('org')) { $this->importOrg(); }
        if ($this->selected('org') || $this->selected('users')) { $this->importDefaults(); $this->importSchedules(); }
        if ($this->selected('users')) { $this->importUsers(); }
        if ($this->selected('devices')) { $this->importDevices(); }
        if ($this->selected('holidays')) { $this->importHolidays(); }
        if ($this->selected('daily')) { $this->importDaily(); }
        if ($this->selected('episodes')) { $this->importEpisodes(); }
        if ($this->selected('compliance')) { $this->importCompliance(); }
        if ($this->selected('coverage')) { $this->importCoverage(); }
        $this->flush();
    }

    private function loadExisting(): void
    {
        foreach ($this->sql('SELECT id,kind FROM org_units WHERE tenant_id=?', [$this->tenant]) as $row) { $this->org[$row['id']] = $row['kind']; }
        foreach ($this->sql('SELECT * FROM schedules WHERE tenant_id=?', [$this->tenant]) as $row) { $this->schedules[$row['id']] = $row + ['days' => []]; }
        foreach ($this->sql('SELECT schedule_id,weekday FROM schedule_days WHERE tenant_id=?', [$this->tenant]) as $row) { $this->schedules[$row['schedule_id']]['days'][] = (int) $row['weekday']; }
        foreach ($this->sql('SELECT id,schedule_id FROM users WHERE tenant_id=?', [$this->tenant]) as $row) { $this->users[$row['id']] = $row; }
        foreach ($this->sql('SELECT id,user_id FROM devices WHERE tenant_id=?', [$this->tenant]) as $row) { $this->devices[$row['id']] = $row; }
        foreach ($this->sql('SELECT * FROM user_assignments WHERE tenant_id=? ORDER BY starts_at,id', [$this->tenant]) as $row) { $this->assignments[$row['user_id']][] = $row; }
        foreach ($this->sql('SELECT * FROM device_assignments WHERE tenant_id=? ORDER BY starts_at,id', [$this->tenant]) as $row) { $this->deviceAssignments[$row['device_id']][] = $row; }
    }

    private function importOrg(): void
    {
        foreach (['firm' => ['keeper_firmas', 'activa', 'legacy_firm_id'], 'site' => ['keeper_sedes', 'activa', 'legacy_sede_id'],
            'area' => ['keeper_areas', 'activa', 'legacy_area_id'], 'position' => ['keeper_cargos', 'activo', 'legacy_cargo_id']] as $kind => [$table, $active, $legacy]) {
            $rows = [];
            $parents = $kind === 'area' ? ',padre_id' : ',NULL AS padre_id';
            foreach ($this->sourceRows($table, "SELECT id,nombre,$active AS active,$legacy AS legacy_id $parents FROM $table") as $r) {
                if (self::cut($r['nombre'], 120) === '') { $this->skip('org_units', 'nombre vacío'); continue; }
                $rows[(string) $r['id']] = $r;
                if ($r['legacy_id'] !== null) {
                    $alias = $kind . ':' . $r['legacy_id'];
                    $this->aliases[$alias] = array_key_exists($alias, $this->aliases) ? null : $this->id($kind, $r['id']);
                }
            }
            $done = [];
            while ($rows) {
                $batch = [];
                foreach ($rows as $legacyId => $r) {
                    $parent = $r['padre_id'];
                    if ($parent !== null && !isset($done[(string) $parent])) { continue; }
                    $batch[] = ['id' => $this->id($kind, $legacyId), 'kind' => $kind, 'name' => self::cut($r['nombre'], 120),
                        'parent_id' => $parent === null ? null : $this->id($kind, $parent), 'active' => (int) (bool) $r['active']];
                }
                if (!$batch) { $this->skip('org_units', 'padre inexistente, fallido o ciclo', count($rows)); break; }
                $accepted = [];
                $attempted = array_fill_keys(array_column($batch, 'id'), true);
                foreach ($this->write('org_units', $batch, ['id']) as $r) { $accepted[$r['id']] = true; $this->org[$r['id']] = $kind; }
                foreach ($rows as $legacyId => $r) {
                    if (isset($attempted[$this->id($kind, $legacyId)])) {
                        if (isset($accepted[$this->id($kind, $legacyId)])) { $done[(string) $legacyId] = true; }
                        unset($rows[$legacyId]);
                    }
                }
            }
        }
    }

    private function orgId(string $kind, mixed $legacy): ?string
    {
        if ($legacy === null) { return null; }
        $id = $this->id($kind, $legacy);
        if (($this->org[$id] ?? null) === $kind) { return $id; }
        $id = $this->aliases[$kind . ':' . $legacy] ?? null;
        return $id !== null && ($this->org[$id] ?? null) === $kind ? $id : null;
    }

    private static function days(?string $value): ?array
    {
        if ($value === null || trim($value) === '') { return null; }
        $tokens = preg_split('/[\s,;]+/', trim($value, " []\t\r\n"));
        $days = [];
        foreach ($tokens as $token) {
            if (!preg_match('/^[0-7]$/D', $token)) { return null; }
            $days[] = $token === '0' ? 7 : (int) $token;
        }
        sort($days);
        return array_values(array_unique($days));
    }

    private function importDefaults(): void
    {
        $rows = [];
        foreach (['area' => 'Sin área', 'site' => 'Sin sede', 'position' => 'Sin cargo', 'firm' => 'Sin firma'] as $kind => $name) {
            $rows[] = ['id' => $this->id($kind, 'default'), 'kind' => $kind, 'name' => $name, 'parent_id' => null, 'active' => 1];
        }
        foreach ($this->write('org_units', $rows, ['id']) as $r) { $this->org[$r['id']] = $r['kind']; }
        // v4 has no active/lunch columns; retain the lunch convention in the schedule name.
        $row = ['id' => $this->id('schedule', 'default'), 'name' => 'K3 por defecto (almuerzo 12:00-13:00)',
            'timezone' => 'America/Bogota', 'start_local' => '08:00:00', 'end_local' => '18:00:00'];
        foreach ($this->write('schedules', [$row], ['id']) as $r) {
            if (!$this->dry) { $this->sql('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=? AND weekday>5', [$this->tenant, $r['id']]); }
            $this->write('schedule_days', array_map(static fn($day) => ['schedule_id' => $r['id'], 'weekday' => $day], range(1, 5)), ['schedule_id', 'weekday']);
            $this->schedules[$r['id']] = $r + ['days' => range(1, 5)];
            $this->scheduleForUser['*'] = $r['id'];
        }
    }

    private function importSchedules(): void
    {
        $candidates = [];
        foreach ($this->sourceRows('keeper_work_schedules', 'SELECT id,user_id,work_start_time,work_end_time,applicable_days,timezone,is_active FROM keeper_work_schedules') as $r) {
            $days = self::days($r['applicable_days']);
            if (!(bool) $r['is_active'] || $days === null || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', (string) $r['work_start_time'])
                || !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/D', (string) $r['work_end_time'])) {
                $this->skip('schedules', 'inactivo o horario/días inválidos'); continue;
            }
            try { new DateTimeZone((string) $r['timezone']); }
            catch (Exception) { $this->skip('schedules', 'zona horaria inválida'); continue; }
            $candidates[$this->id('schedule', $r['id'])] = $r + ['days' => $days];
        }
        $rows = [];
        foreach ($candidates as $id => $r) {
            $rows[] = ['id' => $id, 'name' => 'K3 horario ' . $r['id'], 'timezone' => $r['timezone'], 'start_local' => $r['work_start_time'], 'end_local' => $r['work_end_time']];
        }
        foreach ($this->write('schedules', $rows, ['id']) as $row) {
            $r = $candidates[$row['id']];
            // Reconcile removed weekdays, too; otherwise a rerun leaves obsolete schedule days.
            if (!$this->dry) {
                $this->dev->beginTransaction();
                try {
                    $this->sql('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=? AND weekday NOT IN (' . implode(',', array_fill(0, count($r['days']), '?')) . ')', [$this->tenant, $row['id'], ...$r['days']]);
                    $this->dev->commit();
                } catch (Throwable $e) { $this->dev->rollBack(); throw $e; }
            }
            $dayRows = array_map(static fn($day) => ['schedule_id' => $row['id'], 'weekday' => $day], $r['days']);
            $this->write('schedule_days', $dayRows, ['schedule_id', 'weekday']);
            $this->schedules[$row['id']] = $row + ['days' => $r['days']];
            if ($r['user_id'] !== null) { $this->scheduleForUser[$r['user_id']] = $row['id']; }
        }
    }

    private function assignmentFields(array $r): ?array
    {
        $fields = [];
        foreach (['firm' => 'firm_id', 'site' => 'sede_id', 'area' => 'area_id', 'position' => 'cargo_id'] as $kind => $column) {
            $value = $this->orgId($kind, $r[$column] ?? null) ?? $this->orgId($kind, 'default');
            if ($value === null) { return null; }
            $fields[$kind . '_id'] = $value;
        }
        return $fields;
    }

    private function importUsers(): void
    {
        // --only=users must resolve legacy catalog aliases even when org is not selected.
        if (!$this->selected('org')) {
            foreach (['firm' => ['keeper_firmas', 'legacy_firm_id'], 'site' => ['keeper_sedes', 'legacy_sede_id'], 'area' => ['keeper_areas', 'legacy_area_id'], 'position' => ['keeper_cargos', 'legacy_cargo_id']] as $kind => [$table, $column]) {
                foreach ($this->sourceRows($table, "SELECT id,$column AS legacy_id FROM $table") as $r) {
                    if ($r['legacy_id'] !== null) {
                        $alias = $kind . ':' . $r['legacy_id'];
                        $this->aliases[$alias] = array_key_exists($alias, $this->aliases) ? null : $this->id($kind, $r['id']);
                    }
                }
            }
        }
        $history = [];
        foreach ($this->sourceRows('keeper_user_assignments', 'SELECT id,keeper_user_id,firm_id,area_id,cargo_id,sede_id,UNIX_TIMESTAMP(assigned_at) AS assigned_epoch FROM keeper_user_assignments') as $r) {
            $r['starts_at'] = self::stamp($r['assigned_epoch']) ?? self::HISTORY_START;
            $user = (string) $r['keeper_user_id'];
            if (isset($history[$user][$r['starts_at']])) { $this->skip('user_assignments', 'misma fecha: se conserva el mayor id'); }
            $history[$user][$r['starts_at']] = $r;
        }
        $candidates = [];
        $rows = [];
        foreach ($this->sourceRows('keeper_users', 'SELECT id,legacy_employee_id,cc,email,display_name,status,employment_status,UNIX_TIMESTAMP(created_at) AS created_epoch FROM keeper_users') as $r) {
            $assignments = $history[(string) $r['id']] ?? [];
            ksort($assignments);
            $assignments = array_values($assignments);
            unset($history[(string) $r['id']]);
            $latest = $assignments ? $assignments[count($assignments) - 1] : null;
            $fields = $this->assignmentFields($latest ?? []);
            $schedule = $this->scheduleForUser[$r['id']] ?? $this->scheduleForUser['*'] ?? null;
            $name = self::cut($r['display_name'], 160);
            if ($name === '') { $name = self::cut($r['cc'], 160); }
            if ($name === '') { $name = self::cut($r['email'], 160); }
            if ($fields === null || $schedule === null || $name === '') {
                $this->skip('users', $name === '' ? 'sin display_name, cc ni email' : 'dependencias rechazadas por destino');
                if ($assignments) { $this->skip('user_assignments', 'usuario omitido', count($assignments)); }
                continue;
            }
            $id = $this->id('user', $r['id']);
            $candidates[$id] = $r + ['history' => $assignments, 'schedule_id' => $schedule];
            $rows[] = ['id' => $id, 'display_name' => $name, 'email' => trim((string) $r['email']) ?: null,
                'status' => $r['status'] === 'active' && $r['employment_status'] === 'active' ? 'active' : 'inactive',
                'schedule_id' => $schedule, 'created_at' => self::stamp($r['created_epoch']) ?? ($latest['starts_at'] ?? $this->now)] + $fields;
        }
        $refs = [];
        $assignmentRows = [];
        foreach ($history as $orphan) { $this->skip('user_assignments', 'usuario inexistente en K3', count($orphan)); }
        $acceptedUsers = [];
        foreach ($this->write('users', $rows, ['id']) as $row) {
            $acceptedUsers[$row['id']] = true;
            $this->users[$row['id']] = $row;
            $r = $candidates[$row['id']];
            foreach (['k3' => (string) $r['id'], 'k3:cc' => $r['cc'], 'k3:legacy_employee_id' => $r['legacy_employee_id'],
                'k3:status' => $r['id'] . ':' . $r['status'], 'k3:employment_status' => $r['id'] . ':' . $r['employment_status']] as $source => $value) {
                if ($value !== null && (string) $value !== '') { $refs[] = ['user_id' => $row['id'], 'source' => $source, 'external_ref' => (string) $value]; }
            }
            $first = $r['history'][0]['starts_at'] ?? null;
            if ($first === null || $first > self::HISTORY_START) {
                $assignmentRows[] = ['id' => $this->id('assignment-default', $r['id']), 'user_id' => $row['id'],
                    'schedule_id' => $r['schedule_id'], 'starts_at' => self::HISTORY_START, 'ends_at' => $first]
                    + $this->assignmentFields([]);
            }
            foreach ($r['history'] as $i => $a) {
                $fields = $this->assignmentFields($a);
                if ($fields === null) { $this->skip('user_assignments', 'organización incompleta'); continue; }
                $assignmentRows[] = ['id' => $this->id('assignment', $a['id']), 'user_id' => $row['id'], 'schedule_id' => $r['schedule_id'],
                    'starts_at' => $a['starts_at'], 'ends_at' => $r['history'][$i + 1]['starts_at'] ?? null] + $fields;
            }
        }
        foreach ($candidates as $id => $r) {
            if (!isset($acceptedUsers[$id])) { $this->skip('user_assignments', 'usuario rechazado por destino', count($r['history'])); }
        }
        $this->write('user_external_refs', $refs, ['user_id', 'source']);
        foreach ($this->write('user_assignments', $assignmentRows, ['id']) as $r) {
            $this->replaceAssignment($this->assignments, $r['user_id'], $r);
        }
    }

    private function replaceAssignment(array &$map, string $owner, array $row): void
    {
        $map[$owner] = array_values(array_filter($map[$owner] ?? [], static fn($old) => $old['id'] !== $row['id']));
        $map[$owner][] = $row;
        usort($map[$owner], static fn($a, $b) => strcmp($a['starts_at'], $b['starts_at']));
    }

    private function importDevices(): void
    {
        $rows = [];
        $legacy = [];
        foreach ($this->sourceRows('keeper_devices', 'SELECT id,user_id,device_guid,device_name,client_version,serial_hint,status,device_status,day_summary_json,UNIX_TIMESTAMP(last_seen_at) AS seen_epoch,UNIX_TIMESTAMP(created_at) AS created_epoch,UNIX_TIMESTAMP(decommissioned_at) AS ended_epoch FROM keeper_devices') as $r) {
            $user = $this->id('user', $r['user_id']);
            if (!isset($this->users[$user])) { $this->skip('devices', 'usuario no importado'); continue; }
            $id = $this->id('device', $r['id']);
            $legacy[$id] = $r;
            $rows[] = ['id' => $id, 'user_id' => $user, 'hostname' => self::cut($r['device_name'], 120) ?: (self::cut($r['device_guid'], 120) ?: (string) $r['id']),
                'status' => $r['ended_epoch'] !== null ? 'decommissioned' : $r['status'], 'agent_version' => (string) ($r['client_version'] ?? 'unknown'),
                'last_seen_at' => self::stamp($r['seen_epoch']), 'os_edition' => 'unknown', 'cpu' => 'unknown', 'ram_bytes' => 0,
                'specs' => self::json(['k3' => ['id' => (string) $r['id'], 'device_guid' => $r['device_guid'], 'serial_hint' => $r['serial_hint'],
                    'created_at' => self::stamp($r['created_epoch']), 'device_status' => $r['device_status'], 'day_summary' => json_decode((string) $r['day_summary_json'], true)]]), 'capabilities' => '[]'];
            if (count($rows) >= self::BATCH) { $this->saveDevices($rows, $legacy); $rows = []; $legacy = []; }
        }
        $this->saveDevices($rows, $legacy);
    }

    private function saveDevices(array $rows, array $legacy): void
    {
        $assignments = [];
        foreach ($this->write('devices', $rows, ['id']) as $r) {
            $this->devices[$r['id']] = $r;
            $old = $legacy[$r['id']];
            $start = self::HISTORY_START;
            $end = self::stamp($old['ended_epoch']);
            if ($start === null || ($end !== null && $end <= $start)) { $this->skip('device_assignments', 'intervalo inválido'); continue; }
            $assignments[] = ['id' => $this->id('device-assignment', $old['id']), 'device_id' => $r['id'], 'user_id' => $r['user_id'],
                'starts_at' => $start, 'ends_at' => $end, 'reason' => 'Importación K3: propietario reportado por keeper_devices; inicio convencional 1970, sin historial de propietarios'];
        }
        foreach ($this->write('device_assignments', $assignments, ['id']) as $r) { $this->replaceAssignment($this->deviceAssignments, $r['device_id'], $r); }
    }

    private function assignmentForDay(string $user, string $day): ?array
    {
        foreach (array_reverse($this->assignments[$user] ?? []) as $a) {
            $schedule = $this->schedules[$a['schedule_id']] ?? null;
            if ($schedule === null) { continue; }
            $start = new DateTimeImmutable($day, new DateTimeZone($schedule['timezone']));
            $from = $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $until = $start->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            if ($a['starts_at'] < $until && ($a['ends_at'] === null || $a['ends_at'] > $from)) { return $a; }
        }
        return null;
    }

    private function pair(array $r): ?array
    {
        $user = $this->id('user', $r['user_id']);
        $device = $this->id('device', $r['device_id']);
        if (!self::validDate($r['day_date']) || !isset($this->users[$user], $this->devices[$device])) { return null; }
        $assignment = $this->assignmentForDay($user, $r['day_date']);
        return $assignment === null ? null : [$user, $device, $assignment];
    }

    private function importDaily(): void
    {
        $focus = [];
        foreach ($this->sourceRows('keeper_focus_daily', 'SELECT id,user_id,device_id,day_date,deep_work_seconds,focus_score,productivity_pct,context_switches,deep_work_sessions,distraction_seconds,longest_focus_streak_seconds,constancy_pct,first_activity_time,scheduled_start,punctuality_minutes,UNIX_TIMESTAMP(COALESCE(updated_at,created_at)) AS changed_epoch FROM keeper_focus_daily', [], ['id' => 'DESC']) as $r) {
            $pair = $this->pair($r);
            if ($pair === null || (int) $r['deep_work_seconds'] < 0 || (int) $r['focus_score'] > 100 || (int) $r['productivity_pct'] > 100) { $this->skip('focus_daily', 'referencia o métrica inválida'); continue; }
            [$user, $device, $a] = $pair;
            $key = bin2hex($user) . ':' . $r['day_date'];
            if (isset($focus[$key]['devices'][$device])) { $this->skip('focus_daily', 'duplicado usuario/device/día'); continue; }
            $focus[$key] ??= ['user' => $user, 'assignment' => $a, 'day' => $r['day_date'], 'devices' => []];
            $focus[$key]['devices'][$device] = $r;
        }
        $group = [];
        $groupKey = null;
        foreach ($this->sourceRows('keeper_activity_day', 'SELECT id,user_id,device_id,day_date,active_seconds,idle_seconds,first_event_at,last_event_at,UNIX_TIMESTAMP(COALESCE(updated_at,created_at)) AS changed_epoch FROM keeper_activity_day', [], ['user_id' => 'ASC', 'day_date' => 'ASC', 'device_id' => 'ASC', 'id' => 'DESC']) as $r) {
            $key = $r['user_id'] . ':' . $r['day_date'];
            if ($groupKey !== null && $groupKey !== $key) { $this->dailyGroup($group, $focus); $group = []; }
            $groupKey = $key;
            if (isset($group[$r['device_id']])) { $this->skip('episode_daily', 'duplicado usuario/device/día'); continue; }
            $group[$r['device_id']] = $r;
        }
        $this->dailyGroup($group, $focus);
        foreach ($focus as $f) {
            $rows = $f['devices'];
            $metrics = self::focusPresence(array_values($rows));
            if ($this->nonWorking($f['day'], $f['assignment'])) { $metrics['punctuality_minutes'] = null; }
            $this->queue('focus_daily', ['day' => $f['day'], 'user_id' => $f['user'], 'user_assignment_id' => $f['assignment']['id'],
                'focus_seconds' => array_sum(array_column($rows, 'deep_work_seconds')), 'focus_score' => round(array_sum(array_column($rows, 'focus_score')) / count($rows), 2),
                ...$metrics,
                'context_switches'=>array_sum(array_column($rows,'context_switches')),'deep_work_seconds'=>array_sum(array_column($rows,'deep_work_seconds')),
                'distraction_seconds'=>array_sum(array_column($rows,'distraction_seconds')),
                'calculation_version' => self::VERSION, 'calculated_at' => $this->now,
                'data_through' => min($this->now, self::stamp(max(array_column($rows, 'changed_epoch'))) ?? $this->now)], ['day', 'user_id', 'user_assignment_id']);
        }
        $this->flush();
    }

    private function nonWorking(string $day,array $a): bool
    {
        $schedule=$this->schedules[$a['schedule_id']];
        if (!in_array((int)(new DateTimeImmutable($day))->format('N'),$schedule['days'],true)) { return true; }
        $key=$day.':'.bin2hex($a['sociedad_id']??'');
        if (!array_key_exists($key,$this->holidayDays)) {
            $this->holidayDays[$key]=(bool)$this->sql('SELECT 1 FROM holidays h WHERE h.tenant_id=? AND h.day=? AND (NOT EXISTS (SELECT 1 FROM holiday_society_links l WHERE l.tenant_id=h.tenant_id AND l.holiday_id=h.id) OR EXISTS (SELECT 1 FROM holiday_society_links l WHERE l.tenant_id=h.tenant_id AND l.holiday_id=h.id AND l.society_id=?)) LIMIT 1',[$this->tenant,$day,$a['sociedad_id']??null])->fetchColumn();
        }
        return $this->holidayDays[$key];
    }

    private function sourceColumns(string $table): array
    {
        return array_column($this->source->page('SELECT column_name AS name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?',[$table]),'name');
    }

    private function importHolidays(): void
    {
        $columns=$this->sourceColumns('keeper_holidays');
        if (!$columns) { $this->skip('holidays','tabla fuente ausente'); return; }
        $pick=static function(array $names,array $columns): string {
            foreach ($names as $name) { if (in_array($name,$columns,true)) { return $name; } }
            throw new RuntimeException('Esquema de festivos no reconocido; consulte information_schema.columns antes de importar.');
        };
        $date=$pick(['holiday_date','day_date','date','fecha'],$columns);
        $name=$pick(['name','nombre','description','descripcion'],$columns);
        $links=$this->sourceColumns('keeper_holiday_sociedades');
        if ($links && (!in_array('holiday_id',$links,true) || !in_array('sociedad_id',$links,true))) { throw new RuntimeException('Vinculación de festivos no reconocida.'); }
        $societies=[]; $legacy=[];
        if ($this->sourceColumns('keeper_sociedades')) {
            foreach ($this->sourceRows('keeper_sociedades','SELECT id,nombre,activa FROM keeper_sociedades') as $r) { $legacy[(string)$r['id']]=$r; }
            foreach ($this->sourceRows('keeper_user_assignments','SELECT id,keeper_user_id,sociedad_id FROM keeper_user_assignments WHERE sociedad_id IS NOT NULL') as $r) {
                if (!isset($legacy[(string)$r['sociedad_id']])) { continue; }
                $user=$this->id('user',$r['keeper_user_id']); $aid=$this->id('assignment',$r['id']);
                foreach ($this->assignments[$user]??[] as $a) {
                    if ($a['id']!==$aid) { continue; }
                    $s=$legacy[(string)$r['sociedad_id']];
                    $sid=$this->id('society',$r['sociedad_id'].':'.bin2hex($a['firm_id']));
                    $saved=$this->write('sociedades',[['id'=>$sid,'name'=>self::cut($s['nombre'],120),'external_ref'=>'k3:'.$r['sociedad_id'].':'.Util::id($a['firm_id']),'active'=>(int)$s['activa'],'firm_id'=>$a['firm_id']]],['id']);
                    if (!$saved) { continue; }
                    $societies[(string)$r['sociedad_id']][$sid]=true;
                    $a['sociedad_id']=$sid; $this->write('user_assignments',[['id'=>$aid,'sociedad_id'=>$sid]],['id']);
                    $this->replaceAssignment($this->assignments,$user,$a);
                    if ($a['ends_at']===null) { $this->write('users',[['id'=>$user,'sociedad_id'=>$sid]],['id']); }
                }
            }
        }
        $byHoliday=[];
        if ($links) { foreach ($this->source->page('SELECT holiday_id,sociedad_id FROM keeper_holiday_sociedades',[]) as $r) { $byHoliday[(string)$r['holiday_id']][]=(string)$r['sociedad_id']; } }
        $changedDays=[];
        foreach ($this->sourceRows('keeper_holidays',"SELECT id,`$date` AS day_date,`$name` AS name FROM keeper_holidays") as $r) {
            if (!self::validDate($r['day_date'])) { $this->skip('holidays','fecha inválida'); continue; }
            $targets=[]; $missing=false;
            foreach ($byHoliday[(string)$r['id']]??[] as $legacyId) {
                if (!isset($societies[$legacyId])) { $missing=true; break; }
                foreach (array_keys($societies[$legacyId]) as $sid) { $targets[$sid]=true; }
            }
            if ($missing) { $this->skip('holidays','sociedad sin asignación importada; no se amplía a todo el tenant'); continue; }
            $id=$this->id('holiday',$r['id']);
            if ($this->dry) { $this->write('holidays',[['id'=>$id,'day'=>$r['day_date'],'name'=>self::cut($r['name'],160)]],['id']); continue; }
            $this->dev->beginTransaction();
            try {
                $old=$this->sql('SELECT day FROM holidays WHERE tenant_id=? AND id=?',[$this->tenant,$id])->fetchColumn();
                $this->sql('INSERT INTO holidays VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE day=VALUES(day),name=VALUES(name)',[$this->tenant,$id,$r['day_date'],self::cut($r['name'],160)]);
                $this->sql('DELETE FROM holiday_society_links WHERE tenant_id=? AND holiday_id=?',[$this->tenant,$id]);
                foreach (array_keys($targets) as $sid) { $this->sql('INSERT INTO holiday_society_links VALUES (?,?,?)',[$this->tenant,$id,$sid]); }
                $this->dev->commit();
                $changedDays[]=$r['day_date']; if ($old!==false) { $changedDays[]=$old; }
            } catch (Throwable $e) { $this->dev->rollBack(); throw $e; }
            $this->stat('holidays'); $this->stats['holidays']['leidas']++; $this->stats['holidays'][$old===false?'insertadas':'actualizadas']++;
        }
        if (!$this->dry && $changedDays) { \Keeper\ActivityCalendar::refresh(new \Keeper\Database(),$this->tenant,$changedDays); }
    }

    private function importCompliance(): void
    {
        if ($this->sourceColumns('keeper_suspicious_apps')) {
            foreach ($this->sourceRows('keeper_suspicious_apps','SELECT id,app_pattern,category,description,is_active FROM keeper_suspicious_apps') as $r) {
                $this->queue('suspicious_apps',['id'=>$this->id('suspicious-app',$r['id']),'app_pattern'=>$r['app_pattern'],'category'=>$r['category'],'description'=>$r['description']??'','active'=>(int)$r['is_active']],['id']);
            }
        }
        $this->flush();
        if (!$this->sourceColumns('keeper_dual_job_alerts')) { return; }
        foreach ($this->sourceRows('keeper_dual_job_alerts','SELECT id,user_id,day_date,alert_type,severity,evidence_json,is_reviewed,notes FROM keeper_dual_job_alerts') as $r) {
            $user=$this->id('user',$r['user_id']);
            if (!isset($this->users[$user])) { $this->skip('dual_job_alerts','usuario no importado'); continue; }
            $this->queue('dual_job_alerts',['id'=>$this->id('dual-job-alert',$r['id']),'user_id'=>$user,'day'=>$r['day_date'],'alert_type'=>$r['alert_type'],'severity'=>$r['severity'],'evidence'=>$r['evidence_json'],'reviewed'=>(int)$r['is_reviewed'],'notes'=>$r['notes']===null?null:self::cut($r['notes'],4000),'source'=>'k3'],['id']);
        }
        $this->flush();
    }

    private function importCoverage(): void
    {
        $columns=$this->sourceColumns('keeper_install_coverage_notes');
        if (!$columns) { return; }
        $refs=[];
        foreach ($this->sql("SELECT user_id,external_ref FROM user_external_refs WHERE tenant_id=? AND source='k3:legacy_employee_id'",[$this->tenant]) as $r) { $refs[$r['external_ref']]=$r['user_id']; }
        $exempt=in_array('is_exempt',$columns,true)?'is_exempt':'0 AS is_exempt';
        foreach ($this->sourceRows('keeper_install_coverage_notes',"SELECT id,legacy_employee_id,note_text,$exempt FROM keeper_install_coverage_notes") as $r) {
            $user=$refs[(string)$r['legacy_employee_id']]??null;
            if ($user===null) { $this->skip('install_coverage_notes','referencia legacy no importada'); continue; }
            $this->queue('install_coverage_notes',['user_id'=>$user,'note_text'=>self::cut($r['note_text']??'',4000),'is_exempt'=>(int)$r['is_exempt']],['user_id']);
        }
        $this->flush();
    }

    private function dailyGroup(array $rows, array $focus): void
    {
        $summary = null;
        $productive = 0;
        $known = true;
        foreach ($rows as $r) {
            $pair = $this->pair($r);
            if ($pair === null || (int) $r['active_seconds'] < 0 || (int) $r['idle_seconds'] < 0) { $this->skip('episode_daily', 'referencia o segundos inválidos'); continue; }
            [$user, $device, $a] = $pair;
            $r['first_event_at'] = $this->activityUtc($r['first_event_at']);
            $r['last_event_at'] = $this->activityUtc($r['last_event_at']);
            $through = min($this->now, $r['last_event_at'] ?? self::stamp($r['changed_epoch']) ?? $this->now);
            $this->queue('episode_daily', ['day' => $r['day_date'], 'user_id' => $user, 'user_assignment_id' => $a['id'], 'device_id' => $device,
                'process_name' => '__k3_daily__', 'category' => 'unclassified', 'active_seconds' => (int) $r['active_seconds'],
                'idle_seconds' => (int) $r['idle_seconds'], 'episode_count' => 0, 'calculation_version' => self::VERSION,
                'first_activity'=>$r['first_event_at'],'last_activity'=>$r['last_event_at'],
                'calculated_at' => $this->now, 'data_through' => $through], ['day', 'user_id', 'user_assignment_id', 'device_id', 'process_name']);
            $summary ??= ['day' => $r['day_date'], 'user_id' => $user, 'user_assignment_id' => $a['id'], 'active_seconds' => 0, 'idle_seconds' => 0,
                'first_activity' => null, 'last_activity' => null, 'data_through' => $through];
            $summary['active_seconds'] += (int) $r['active_seconds'];
            $summary['idle_seconds'] += (int) $r['idle_seconds'];
            $summary['data_through'] = max($summary['data_through'], $through);
            if ($r['first_event_at']!==null) { $summary['first_activity']=min($summary['first_activity']??$r['first_event_at'],$r['first_event_at']); }
            if ($r['last_event_at']!==null) { $summary['last_activity']=max($summary['last_activity']??$r['last_event_at'],$r['last_event_at']); }
            $metric = $focus[bin2hex($user) . ':' . $r['day_date']]['devices'][$device]['productivity_pct'] ?? null;
            if ($metric === null) { $known = false; } else { $productive += (int) $r['active_seconds'] * (int) $metric / 100; }
        }
        if ($summary === null) { return; }
        $schedule = $this->schedules[$a['schedule_id']];
        $start = new DateTimeImmutable($summary['day'] . ' ' . $schedule['start_local'], new DateTimeZone($schedule['timezone']));
        $end = new DateTimeImmutable($summary['day'] . ' ' . $schedule['end_local'], new DateTimeZone($schedule['timezone']));
        if ($end < $start) { $end = $end->modify('+1 day'); }
        $expected = $this->nonWorking($summary['day'],$a) ? 0 : $end->getTimestamp() - $start->getTimestamp();
        $this->queue('day_summary', $summary + ['productive_seconds' => $known ? (int) round($productive) : 0,
            'expected_seconds' => $expected, 'coverage_percent' => $expected > 0 ? min(100, round(($summary['active_seconds'] + $summary['idle_seconds']) * 100 / $expected, 2)) : 0,
            'productivity_percent' => $known && $summary['active_seconds'] > 0 ? round($productive * 100 / $summary['active_seconds'], 2) : null,
            'calculation_version' => self::VERSION, 'timezone' => $schedule['timezone'], 'calculated_at' => $this->now], ['day', 'user_id', 'user_assignment_id']);
    }

    private static function interval(array $assignments, string $start, string $end, string $user): ?array
    {
        foreach (array_reverse($assignments) as $a) {
            if ($a['user_id'] === $user && $a['starts_at'] <= $start && ($a['ends_at'] === null || $a['ends_at'] >= $end)) { return $a; }
        }
        return null;
    }

    private function importEpisodes(): void
    {
        $retention = $this->sql('SELECT late_arrival_days FROM retention_settings WHERE tenant_id=?', [$this->tenant])->fetchColumn();
        if ($retention === false) {
            $this->write('retention_settings', [['late_arrival_days' => 30]], ['tenant_id']);
            $retention = 30;
        }
        $clock = new DateTimeImmutable($this->now, new DateTimeZone('UTC'));
        $cutoff = $clock->modify('-' . $this->episodeDays . ' days')->format('Y-m-d H:i:s.u');
        $oldest = $clock->modify('-' . (int) $retention . ' days')->format('Y-m-d H:i:s.u');
        echo 'Episodes: ventana UTC desde ' . $cutoff . '; máximo ' . $this->episodeMax . " filas.\n";
        foreach ($this->sourceRows('keeper_window_episode', 'SELECT id,user_id,device_id,start_at,end_at,duration_seconds,process_name,app_name,window_title FROM keeper_window_episode WHERE start_at>=? AND start_at<=?', [$cutoff, $this->now], ['start_at' => 'DESC', 'id' => 'DESC'], $this->episodeMax) as $r) {
            $user = $this->id('user', $r['user_id']);
            $device = $this->id('device', $r['device_id']);
            $start = (string) $r['start_at'] . '.000000';
            $end = (string) $r['end_at'] . '.000000';
            $seconds = strtotime((string) $r['end_at']) - strtotime((string) $r['start_at']);
            $a = self::interval($this->assignments[$user] ?? [], $start, $end, $user);
            $d = self::interval($this->deviceAssignments[$device] ?? [], $start, $end, $user);
            if (!isset($this->users[$user], $this->devices[$device]) || $a === null || $d === null) { $this->skip('episodes', 'usuario/device/asignación incompatible'); continue; }
            if ($start < $oldest || $end > $this->now || $seconds <= 0 || $seconds > 86400 || (int) $r['duration_seconds'] < 0 || (int) $r['duration_seconds'] > $seconds) {
                $this->skip('episodes', 'retención, fecha o duración inválida'); continue;
            }
            $this->queue('episodes', ['device_id' => $device, 'event_id' => $this->id('episode', $r['id']), 'user_id' => $user,
                'device_assignment_id' => $d['id'], 'user_assignment_id' => $a['id'], 'event_date' => substr($start, 0, 10), 'started_at' => $start, 'ended_at' => $end,
                'process_name' => self::cut($r['process_name'] ?: ($r['app_name'] ?: 'unknown'), 160), 'window_title' => $r['window_title'],
                'active_seconds' => (int) $r['duration_seconds'], 'idle_seconds' => 0, 'body_hash' => hash('sha256', self::json($r), true), 'received_at' => $this->now], ['device_id', 'event_id'], true);
        }
        $this->flush();
    }

    public function summary(float $started): void
    {
        foreach ($this->backfillStats as $table => $counts) { echo $table . ': ' . self::json($counts) . "\n"; }
        echo "\nRESUMEN" . ($this->dry ? ' (proyección; no se ejecutaron escrituras ni triggers)' : '') . "\n";
        printf("%-24s %10s %12s %13s %10s\n", 'Destino', 'Leídas', 'Insertadas', 'Actualizadas', 'Omitidas');
        foreach ($this->stats as $table => $s) { printf("%-24s %10d %12d %13d %10d\n", $table, ...array_values($s)); }
        echo "Filas leídas de K3: " . self::json($this->reads) . "\n";
        foreach ($this->reasons as $reason => $count) { echo '  ' . $reason . ': ' . $count . "\n"; }
        printf("Tiempo: %.2f s\n", microtime(true) - $started);
        if ($this->lock !== null) { $this->sql('SELECT RELEASE_LOCK(?)', [$this->lock]); $this->lock = null; }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) { return; }

if (in_array('--help', $argv, true)) {
    echo "Uso: php backend-v4/tools/import-k3.php [--dry-run] [--only=org,users,devices,holidays,daily,episodes,compliance,coverage]\n"
        . "     php backend-v4/tools/import-k3.php --export-backfill=archivo.jsonl\n"
        . "     php backend-v4/tools/import-k3.php --import-backfill=archivo.jsonl [--dry-run]\n"
        . "K3_DSN, K3_USER, K3_PASSWORD o K3_PASSWORD_FILE; destino KEEPER_DB_* del bootstrap/.env.\n"
        . "IMPORT_TENANT_ID opcional si existe un único tenant no-plataforma; IMPORT_TENANT_NAME opcional.\n"
        . "K3_EPISODE_DAYS=3 (1–90), K3_EPISODE_MAX=50000 (1–50000). Ver README-import-k3.md.\n";
    exit(0);
}

$started = microtime(true);
$import = null;
$code = 0;
try {
    $import = new K3Import($argv);
    $import->run();
} catch (PDOException $e) {
    // Driver messages can contain DSNs, usernames, row values and credentials.
    fwrite(STDERR, 'Importación detenida: SQLSTATE ' . $e->getCode() . ', código ' . (int) ($e->errorInfo[1] ?? 0) . ". No se imprimen datos ni credenciales.\n");
    $code = 1;
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof RuntimeException && !($e instanceof PDOException) ? $e->getMessage() : 'Importación detenida por un error interno.') . "\n");
    $code = 1;
} finally {
    if ($import !== null) { $import->summary($started); }
}
exit($code);
