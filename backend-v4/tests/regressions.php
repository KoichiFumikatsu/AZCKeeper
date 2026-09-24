<?php
declare(strict_types=1);

use Keeper\{ApiError, Config, Database, Ingest, RateLimiter, Request, Util, Validator};

final class RaceStatement extends PDOStatement
{
    public static ?Closure $beforeInsert = null;
    public function execute(?array $params = null): bool
    {
        if (self::$beforeInsert !== null && str_starts_with($this->queryString, 'INSERT INTO activity_snapshots ')) {
            $callback = self::$beforeInsert; self::$beforeInsert = null;
            $callback();
        }
        return parent::execute($params);
    }
}

function snapshotRace(Database $db, string $tenant, string $device, string $user): void
{
    $assignment = $db->one('SELECT id,starts_at,ends_at FROM device_assignments WHERE tenant_id=? AND device_id=? AND ends_at IS NULL', [$tenant, $device]);
    $context = ['tenant_id' => $tenant, 'device_id' => $device, 'user_id' => $user, 'assignment_id' => $assignment['id'], 'assignment_start' => $assignment['starts_at'], 'timezone' => 'America/Bogota'];
    $request = (new ReflectionClass(Request::class))->newInstanceWithoutConstructor();
    $ingest = new Ingest($db, $context, $request);
    $other = new Database();
    $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RaceStatement::class]);
    try {
        foreach ([false, true] as $partial) {
            $event = (object) ['snapshot_id' => Util::uuid(), 'sequence' => 1, 'day' => (new DateTimeImmutable('yesterday', new DateTimeZone('America/Bogota')))->format('Y-m-d'), 'active_seconds' => 10, 'idle_seconds' => 0];
            RaceStatement::$beforeInsert = static function () use ($other, $tenant, $device, $user, $assignment, $event): void {
                // Commit the competing row after the pre-check, before the actual MySQL INSERT.
                $winner = clone $event; $winner->active_seconds = 20;
                $winner->day = (new DateTimeImmutable($event->day))->modify('-1 day')->format('Y-m-d');
                $other->run('INSERT INTO activity_snapshots (tenant_id,device_id,day,snapshot_id,sequence,user_id,device_assignment_id,active_seconds,idle_seconds,body_hash,received_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$tenant, $device, $winner->day, Util::bin($winner->snapshot_id), 1, $user, $assignment['id'], 20, 0, Util::hash($winner)]);
            };
            try {
                $ack = $db->transaction(fn () => $partial ? $ingest->partial($event, fn () => $ingest->activity($event), 'snapshot_id') : $ingest->activity($event));
                check($partial && $ack['status'] === 'rejected' && $ack['code'] === 'event_conflict', 'snapshot race becomes a partial conflict ACK');
            } catch (ApiError $error) {
                check(!$partial && $error->status === 409 && $error->errorCode === 'event_conflict', 'actual MySQL snapshot duplicate key becomes 409 event_conflict');
            }
            check(RaceStatement::$beforeInsert === null, 'snapshot race reached INSERT after pre-check');
            $row = $other->one('SELECT active_seconds FROM activity_snapshots WHERE tenant_id=? AND device_id=? AND snapshot_id=?', [$tenant, $device, Util::bin($event->snapshot_id)]);
            check((int) $row['active_seconds'] === 20, 'losing snapshot cannot overwrite the committed winner');
            $other->run('DELETE FROM activity_snapshots WHERE tenant_id=? AND device_id=? AND snapshot_id=?', [$tenant, $device, Util::bin($event->snapshot_id)]);
        }
    } finally {
        RaceStatement::$beforeInsert = null;
        $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }
    echo "PASS READ COMMITTED snapshot race, 409 and partial conflict ACK\n";
}

function rateRecovery(): void
{
    $limiter = new RateLimiter();
    $bucket = ['device', 'retry-' . Util::uuid(), 120, 3];
    for ($n = 0; $n < 3; $n++) { $limiter->take([$bucket]); }
    $start = microtime(true); $accepted = 0; $rejected = 0;
    do {
        usleep(20000);
        try { $limiter->take([$bucket]); $accepted++; }
        catch (ApiError $error) {
            if ($error->status !== 429 || (int) $error->headers['Retry-After'] !== 1) { throw $error; }
            $rejected++;
        }
    } while (microtime(true) - $start < 1.25);
    check($rejected > 0 && $accepted >= 2, 'frequent rejected retries preserve fractional refill and recover tokens');
    check(abs($accepted - (int) floor((microtime(true) - $start) * 2)) <= 1, 'effective refill matches configured rate');
    usleep(1600000);
    $limiter->take([$bucket]);
    check((int) $limiter->headers['RateLimit-Remaining'] === 2, 'bucket refills beyond one token up to burst');
    if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        $hash = hash('sha256', $bucket[0] . ':' . $bucket[1]);
        check(is_file(Config::get('RATE_DIR') . '/' . substr($hash, 0, 2) . '/' . $hash . '.json') && !is_file(Config::get('RATE_DIR') . '/buckets.json'), 'per-key sharded file replaces global bucket file');
    } else {
        check(!is_file(Config::get('RATE_DIR') . '/buckets.json'), 'APCu does not use a global bucket file');
    }
    echo "PASS fractional rate-limit recovery and configured burst\n";
}

function uriReferences(): void
{
    $validator = new Validator();
    $schema = ['type' => 'string', 'format' => 'uri-reference'];
    foreach (['about:blank', 'urn:example:test', 'https://example.test/a%20b?q=1#f', '../policy', '/client/sync', '//example.test/path', '?page=2', '#section', '', 'https://[::1]/', '//[v1.host]/'] as $value) {
        $validator->check($schema, $value); check(true, 'valid URI reference: ' . $value);
    }
    foreach (['has space', '/bad%escape', "bad\npath", '1bad:scheme', 'https://[:::]/', 'https://host:bad/', 'https://[not-ip]/', '/path#one#two', '/bad\\path', '//[v1.%FF]/'] as $value) {
        try { $validator->check($schema, $value); throw new RuntimeException('Invalid URI reference accepted'); }
        catch (ApiError $error) { check($error->status === 422, 'invalid URI reference rejected'); }
    }
    echo "PASS absolute and relative URI references\n";
}
