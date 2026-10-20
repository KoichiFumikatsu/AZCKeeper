<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Retención aplicada (decisión del dueño 2026-10-07). Cada noche revisa, cliente por cliente, lo que quedó fuera de
 * sus plazos (retention_settings) y lo deja anotado en retention_runs. Solo borra en los clientes con enforce = TRUE;
 * en los demás (piloto, por defecto) solo cuenta actividad. Ubicación aplica siempre su plazo propio de 90 días.
 *
 * Lo único que toca: el detalle de actividad (episodes, su llave de deduplicación episode_ingest_keys y
 * activity_snapshots), los resúmenes diarios (episode_daily, day_summary, focus_daily y su control rollup_days) y los
 * registros técnicos de los equipos (client_logs), además de device_locations sin caso de pérdida abierto.
 * Nunca auditoría, personas, equipos, organización, reglas ni altas.
 *
 * Nada fechado dentro de la ventana de llegada tardía (late_arrival_days + 2 días, la misma que cierra los resúmenes)
 * se borra, aunque el plazo configurado sea menor: lo que todavía puede llegar no se pierde antes de tiempo.
 * Borra en lotes pequeños (cada sentencia es su propia transacción) y se detiene al agotar el tiempo; lo pendiente
 * sigue la noche siguiente.
 */
final class RetentionWorker
{
    public const LOCK_PREFIX = 'keeper_v4_retention:';

    public function __construct(private Database $db) {}

    /** Nombre del lock por base: dos revisiones sobre la misma base nunca se pisan. */
    public function lockName(): string
    {
        return self::LOCK_PREFIX . $this->db->one('SELECT LEFT(SHA2(DATABASE(),256),40) h')['h'];
    }

    /**
     * @return array<int,array<string,mixed>>|null null si otra revisión ya está corriendo.
     */
    public function run(?string $tenant = null, int $batch = 5000, int $budgetSeconds = 900): ?array
    {
        $lock = $this->lockName();
        if ((int) ($this->db->one('SELECT GET_LOCK(?,0) l', [$lock])['l'] ?? 0) !== 1) { return null; }
        try {
            $deadline = microtime(true) + $budgetSeconds;
            $sql = 'SELECT r.tenant_id FROM retention_settings r JOIN tenants t ON t.tenant_id=r.tenant_id WHERE t.deleted_at IS NULL';
            $ids = array_column($this->db->run($sql . ($tenant === null ? '' : ' AND r.tenant_id=?') . ' ORDER BY r.tenant_id', $tenant === null ? [] : [$tenant])->fetchAll(), 'tenant_id');
            $out = [];
            foreach ($ids as $id) {
                // Un cliente que falla (incluso al anotar su revisión) no detiene a los demás.
                try { $out[] = $this->tenant($id, $batch, $deadline); }
                catch (\Throwable $e) { $out[] = ['tenant_id' => Util::id($id), 'complete' => false, 'error' => substr(get_class($e), 0, 200)]; }
            }
            return $out;
        } finally {
            $this->db->run('DO RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Qué se borra de cada tabla: [tipo, tabla, condición, corte]. Orden: el detalle antes que su llave. */
    private static function targets(array $cut): array
    {
        $raw = $cut['raw_before']; $agg = $cut['aggregate_before'];
        return [
            ['raw', 'episodes', 'tenant_id=? AND started_at<? AND event_date<?', fn ($t) => [$t, $raw . ' 00:00:00', $raw]],
            ['raw', 'activity_snapshots', 'tenant_id=? AND day<?', fn ($t) => [$t, $raw]],
            ['raw_key', 'episode_ingest_keys', 'event_date<? AND tenant_id=?', fn ($t) => [$raw, $t]],
            ['aggregate', 'episode_daily', 'tenant_id=? AND day<?', fn ($t) => [$t, $agg]],
            ['aggregate', 'focus_daily', 'tenant_id=? AND day<?', fn ($t) => [$t, $agg]],
            ['aggregate', 'day_summary', 'tenant_id=? AND day<?', fn ($t) => [$t, $agg]],
            // El control de recálculo de un día solo sobra cuando ya no queda ni detalle ni resumen de ese día.
            ['aggregate_control', 'rollup_days', 'tenant_id=? AND day<?', fn ($t) => [$t, min($raw, $agg)]],
            ['log', 'client_logs', 'tenant_id=? AND at<?', fn ($t) => [$t, $cut['logs_before']]],
        ];
    }

    private function tenant(string $tenant, int $batch, float $deadline): array
    {
        $started = microtime(true);
        $cut = $this->db->one("SELECT r.enforce,
              LEAST(UTC_DATE()-INTERVAL r.raw_days DAY, UTC_DATE()-INTERVAL (r.late_arrival_days+2) DAY) raw_before,
              LEAST(DATE_SUB(UTC_DATE(),INTERVAL r.aggregate_months MONTH), UTC_DATE()-INTERVAL (r.late_arrival_days+2) DAY) aggregate_before,
              LEAST(UTC_TIMESTAMP(6)-INTERVAL r.log_days DAY, TIMESTAMP(UTC_DATE()-INTERVAL (r.late_arrival_days+2) DAY)) logs_before
            FROM retention_settings r WHERE r.tenant_id=?", [$tenant]);
        $mode = (bool) $cut['enforce'] ? 'applied' : 'dry_run';
        $run = Util::bin(Util::uuid());
        $this->db->run('INSERT INTO retention_runs (tenant_id,id,run_date,started_at,mode,raw_before,aggregate_before,logs_before) VALUES (?,?,UTC_DATE(),UTC_TIMESTAMP(6),?,?,?,?)',
            [$tenant, $run, $mode, $cut['raw_before'], $cut['aggregate_before'], $cut['logs_before']]);
        $rows = []; $complete = true; $error = null; $days = ['raw' => 0, 'aggregate' => 0];
        try {
            $raw = $cut['raw_before']; $agg = $cut['aggregate_before'];
            $days['raw'] = (int) $this->db->one('SELECT COUNT(*) n FROM (SELECT event_date d FROM episodes WHERE tenant_id=? AND started_at<? AND event_date<?
                UNION SELECT day FROM activity_snapshots WHERE tenant_id=? AND day<?) x', [$tenant, $raw . ' 00:00:00', $raw, $tenant, $raw])['n'];
            $days['aggregate'] = (int) $this->db->one('SELECT COUNT(*) n FROM (SELECT day d FROM day_summary WHERE tenant_id=? AND day<?
                UNION SELECT day FROM episode_daily WHERE tenant_id=? AND day<? UNION SELECT day FROM focus_daily WHERE tenant_id=? AND day<?) x', [$tenant, $agg, $tenant, $agg, $tenant, $agg])['n'];
            foreach (self::targets($cut) as [, $table, $where, $args]) {
                $rows[$table] = 0;
                if ($mode === 'dry_run') {
                    $rows[$table] = (int) $this->db->one("SELECT COUNT(*) n FROM $table WHERE $where", $args($tenant))['n'];
                    continue;
                }
                // Cada DELETE ... LIMIT se confirma solo (autocommit): bloqueos cortos y nada a medias que deshacer.
                while ($complete) {
                    if (microtime(true) >= $deadline) { $complete = false; break; }
                    $n = $this->db->run("DELETE FROM $table WHERE $where LIMIT $batch", $args($tenant))->rowCount();
                    $rows[$table] += $n;
                    if ($n < $batch) { break; }
                }
                if (!$complete) { break; }
            }
            $rows['device_locations'] = (new DeviceLocations($this->db))->purge($tenant,$batch,$deadline);
        } catch (\Throwable $e) {
            $complete = false;
            $error = mb_substr(get_class($e) . ($e instanceof \PDOException ? ' ' . ($e->errorInfo[0] ?? $e->getCode()) . '/' . ($e->errorInfo[1] ?? '') : '') . ': ' . CronRun::clean($e->getMessage()), 0, 200);
        }
        $sum = fn (string $kind) => array_sum(array_map(fn ($t) => $rows[$t[1]] ?? 0, array_filter(self::targets($cut), fn ($t) => $t[0] === $kind)));
        $result = ['tenant_id' => Util::id($tenant), 'mode' => $mode, 'raw_before' => $cut['raw_before'], 'aggregate_before' => $cut['aggregate_before'],
            'logs_before' => Util::time($cut['logs_before']), 'raw_rows' => $sum('raw'), 'raw_days' => $days['raw'], 'aggregate_rows' => $sum('aggregate'),
            'aggregate_days' => $days['aggregate'], 'log_rows' => $sum('log'), 'tables' => $rows, 'complete' => $complete,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000), 'error' => $error];
        $this->db->run('UPDATE retention_runs SET finished_at=UTC_TIMESTAMP(6),raw_rows=?,raw_days=?,aggregate_rows=?,aggregate_days=?,log_rows=?,table_rows=?,complete=?,duration_ms=?,error=? WHERE tenant_id=? AND id=?',
            [$result['raw_rows'], $result['raw_days'], $result['aggregate_rows'], $result['aggregate_days'], $result['log_rows'], Util::json((object) $rows), (int) $complete,
             min($result['duration_ms'], 4294967295), $error, $tenant, $run]);
        return $result;
    }

    /** Última revisión del cliente para Ajustes (null si nunca corrió). */
    public static function lastRun(Database $db, string $tenant): ?array
    {
        $r = $db->one('SELECT * FROM retention_runs WHERE tenant_id=? ORDER BY started_at DESC, id DESC LIMIT 1', [$tenant]);
        if (!$r) { return null; }
        return ['started_at' => Util::time($r['started_at']), 'finished_at' => $r['finished_at'] === null ? null : Util::time($r['finished_at']), 'mode' => $r['mode'],
            'complete' => (bool) $r['complete'], 'failed' => $r['error'] !== null, 'raw_rows' => (int) $r['raw_rows'], 'raw_days' => (int) $r['raw_days'],
            'aggregate_rows' => (int) $r['aggregate_rows'], 'aggregate_days' => (int) $r['aggregate_days'], 'log_rows' => (int) $r['log_rows']];
    }
}
