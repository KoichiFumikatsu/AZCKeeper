<?php
declare(strict_types=1);
namespace Keeper;

// Apagado/reinicio programado (docs/superpowers/specs/2026-10-27-apagado-programado-design.md).
final class PowerSchedules
{
    public const MIN_AGENT = '4.0.30';
    public const OPERATIONS = ['listPowerSchedules', 'getPowerSchedule', 'createPowerSchedule', 'patchPowerSchedule', 'deletePowerSchedule', 'listPowerScheduleRuns'];
    private const KINDS = ['tenant', 'site', 'area', 'user', 'device'];
    private string $tenant;

    public function __construct(private Database $db, private array $c, private ?AdminAccess $access, private Request $r)
    {
        $this->tenant = $c['tenant_id'];
    }

    /**
     * Crear, editar y borrar exigen equipos.apagar (x-permission) y, por eso, la funcion contratada 'comandos'
     * (AdminAccess via Features::forOperation); reiniciar exige ademas equipos.reiniciar. Listar, ver y ejecuciones
     * solo exigen equipos.ver.
     */
    public static function permissionFor(string $action): string
    {
        return $action === 'restart' ? 'equipos.reiniciar' : 'equipos.apagar';
    }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        return match ($op) {
            'listPowerSchedules' => $this->list(),
            'getPowerSchedule' => $this->get($id),
            'createPowerSchedule' => $this->save(null, $b),
            'patchPowerSchedule' => $this->save($id, $b),
            'deletePowerSchedule' => $this->delete($id),
            'listPowerScheduleRuns' => $this->runs($id),
        };
    }

    private function etag(array $s): array { return ['ETag' => '"' . $s['version'] . '"']; }

    private function get(string $id): array
    {
        $s = $this->load($id, false, true);
        return AdminApi::response($this->dto($s), 200, $this->etag($s));
    }

    /** Alcance total ve todo; el resto, solo programaciones cuyos destinos y exclusiones caen todos en su alcance. Los destinos se cargan por lotes, no una consulta por fila. */
    private function list(): array
    {
        $rows = $this->db->run('SELECT * FROM power_schedules WHERE tenant_id=? ORDER BY created_at DESC, id LIMIT 500', [$this->tenant])->fetchAll();
        $targets = $this->targetsFor(array_column($rows, 'id'));
        if (!$this->access->full()) {
            $allowed = $this->allowedRefs(array_merge([], ...array_values($targets)));
            $rows = array_values(array_filter($rows, function ($s) use ($targets, $allowed) {
                foreach ($targets[$s['id']] ?? [] as $t) { if (!isset($allowed[$t['kind'] . '|' . bin2hex($t['ref_id'])])) { return false; } }
                return true;
            }));
        }
        return AdminApi::response(['data' => array_map(fn ($s) => $this->dto($s, $targets[$s['id']] ?? []), $rows)]);
    }

    /** Destinos y exclusiones de varias programaciones en una sola consulta, agrupados por schedule_id. */
    private function targetsFor(array $ids): array
    {
        if (!$ids) { return []; }
        $out = [];
        foreach ($this->db->run('SELECT schedule_id, kind, ref_id, excluded FROM power_schedule_targets WHERE tenant_id=? AND schedule_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY kind, ref_id',
            [$this->tenant, ...$ids])->fetchAll() as $t) { $out[$t['schedule_id']][] = $t; }
        return $out;
    }

    /** Destinos (filas kind/ref_id) dentro del alcance del administrador, resueltos por lotes: clave "kind|hex(ref_id)". */
    private function allowedRefs(array $rows): array
    {
        $byKind = [];
        foreach ($rows as $t) { $byKind[$t['kind']][$t['ref_id']] = true; }
        $ok = [];
        [$where, $args] = $this->access->usersSql('u');
        foreach (['user' => 'SELECT u.id FROM users u WHERE u.tenant_id=? AND u.id IN (%s) AND ',
                  'device' => 'SELECT d.id FROM devices d JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id WHERE d.tenant_id=? AND d.id IN (%s) AND '] as $kind => $sql) {
            $refs = array_keys($byKind[$kind] ?? []);
            if (!$refs) { continue; }
            foreach ($this->db->run(sprintf($sql, implode(',', array_fill(0, count($refs), '?'))) . "($where)", [$this->tenant, ...$refs, ...$args])->fetchAll() as $r) { $ok[$kind . '|' . bin2hex($r['id'])] = true; }
        }
        foreach (['site', 'area'] as $kind) {
            foreach (array_keys($byKind[$kind] ?? []) as $ref) { if ($this->access->allowsUnitTarget($kind, $ref)) { $ok[$kind . '|' . bin2hex($ref)] = true; } }
        }
        return $ok;   // 'tenant' nunca: exige alcance total, y quien lo tiene no pasa por aqui.
    }

    /** $manage: quien no tiene alcance total solo ve y toca programaciones cuyos destinos y exclusiones caen todos en su alcance. */
    private function load(string $id, bool $lock, bool $manage = false): array
    {
        $s = $this->db->one('SELECT * FROM power_schedules WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$this->tenant, $id]);
        if (!$s) { throw new ApiError(404, 'resource_not_found'); }
        if ($manage && !$this->access->full()) {
            foreach ($this->db->run('SELECT kind, ref_id FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=?', [$this->tenant, $id])->fetchAll() as $t) { $this->checkTarget($t['kind'], $t['ref_id'], false); }
        }
        return $s;
    }

    private function date(mixed $v): void
    {
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v) : false;
        if (!$d || $d->format('Y-m-d') !== $v) { throw new ApiError(422, 'validation_failed'); }
    }

    private function save(?string $id, object $b): array
    {
        $old = $id === null ? null : $this->load($id, true, true);
        if ($old && $this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        $cur = $old ? $this->dto($old) : ['action' => 'shutdown', 'days' => [], 'run_on' => null,
            'valid_from' => (new \DateTimeImmutable('now', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d'),
            'valid_until' => null, 'message' => '', 'idle_minutes' => 15, 'notice_minutes' => 10, 'postpone_minutes' => 60,
            'window_minutes' => 180, 'status' => 'active', 'targets' => [], 'exclusions' => []];
        $v = array_merge($cur, get_object_vars($b));
        // PowerScheduleInput no tiene "required" (se comparte con PATCH): al crear, la presencia se exige aqui.
        foreach (['name', 'recurrence', 'time_local'] as $k) { if (!isset($v[$k])) { throw new ApiError(422, 'validation_failed'); } }
        if (preg_match('/^\s*$/u', (string) $v['name'])) { throw new ApiError(422, 'validation_failed', [], 'invalid_name'); }
        // Pausar/reanudar (PATCH solo con status): los destinos guardados no se revalidan como escritura (una sede o area
        // desactivada despues no impide pausar) ni se reescriben; load() ya comprobo el alcance.
        $statusOnly = $old !== null && array_keys(get_object_vars($b)) === ['status'];
        if ($v['recurrence'] === 'weekly') {
            if (!$v['days']) { throw new ApiError(422, 'validation_failed', [], 'invalid_days'); }
            $v['run_on'] = null;
        } else {
            if ($v['run_on'] === null) { throw new ApiError(422, 'validation_failed', [], 'invalid_run_on'); }
            $v['days'] = []; $v['valid_until'] = null;
        }
        foreach (['valid_from', 'valid_until', 'run_on'] as $k) { if ($v[$k] !== null) { $this->date($v[$k]); } }
        // Una sola vez: la vigencia es el dia de la ejecucion; se guarda asi para que la fila sea coherente (valid_from = run_on).
        if ($v['recurrence'] === 'once') { $v['valid_from'] = $v['run_on']; }
        if ($v['valid_until'] !== null && $v['valid_until'] < $v['valid_from']) { throw new ApiError(422, 'validation_failed', [], 'invalid_range'); }
        if (preg_match('/\p{Cc}/u', (string) $v['message'])) { throw new ApiError(422, 'validation_failed'); }
        // El permiso del contrato es equipos.apagar; reiniciar exige ademas el suyo (y cambiar de reinicio a apagado, ambos).
        foreach (array_unique([$v['action'], $old['action'] ?? $v['action']]) as $act) {
            if (!$this->access->has(self::permissionFor($act))) { throw new ApiError(403, 'permission_denied'); }
        }
        $targets = array_map(fn ($t) => (object) $t, $v['targets']);
        $excl = array_map(fn ($t) => (object) $t, $v['exclusions']);
        if (!$targets) { throw new ApiError(422, 'validation_failed'); }
        // Un destino no puede repetirse ni estar a la vez como destino y exclusion.
        $seen = [];
        foreach (array_merge($targets, $excl) as $t) {
            $key = $t->kind . '|' . strtolower($t->id);
            if (isset($seen[$key])) { throw new ApiError(422, 'validation_failed', [], 'duplicate_target'); }
            $seen[$key] = true;
        }
        if (!$statusOnly) { foreach (array_merge($targets, $excl) as $t) { $this->checkTarget($t->kind, Util::bin($t->id)); } }

        $mask = 0;
        foreach ($v['days'] as $d) { $mask |= 1 << ($d - 1); }
        $cols = ['name' => $v['name'], 'action' => $v['action'], 'recurrence' => $v['recurrence'], 'days_mask' => $mask, 'time_local' => $v['time_local'],
            'run_on' => $v['run_on'], 'valid_from' => $v['valid_from'], 'valid_until' => $v['valid_until'], 'message' => $v['message'],
            'idle_minutes' => $v['idle_minutes'], 'notice_minutes' => $v['notice_minutes'], 'postpone_minutes' => $v['postpone_minutes'],
            'window_minutes' => $v['window_minutes'], 'status' => $v['status']];
        if ($old) {
            $set = implode(',', array_map(fn ($k) => "$k=?", array_keys($cols)));
            $this->db->run("UPDATE power_schedules SET $set, version=version+1 WHERE tenant_id=? AND id=?", [...array_values($cols), $this->tenant, $id]);
        } else {
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO power_schedules (tenant_id,id,created_by,' . implode(',', array_keys($cols)) . ') VALUES (?,?,?' . str_repeat(',?', count($cols)) . ')',
                [$this->tenant, $id, $this->c['principal_id'], ...array_values($cols)]);
        }
        if (!$statusOnly) {
            $this->db->run('DELETE FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=?', [$this->tenant, $id]);
            foreach ([[$targets, 0], [$excl, 1]] as [$list, $ex]) {
                foreach ($list as $t) {
                    $this->db->run('INSERT INTO power_schedule_targets (tenant_id,id,schedule_id,kind,ref_id,excluded) VALUES (?,?,?,?,?,?)',
                        [$this->tenant, Util::bin(Util::uuid()), $id, $t->kind, Util::bin($t->id), $ex]);
                }
            }
        }
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'power_schedule', $id, array_keys(get_object_vars($b)));
        $saved = $this->load($id, false);
        return AdminApi::response($this->dto($saved), $old ? 200 : 201, $this->etag($saved));
    }

    // Mismo criterio que AdminApi::policyTarget(): empresa exige alcance total; persona/equipo dentro del alcance; sede/area activas y permitidas.
    private function checkTarget(string $kind, string $ref, bool $write = true): void
    {
        if (!in_array($kind, self::KINDS, true)) { throw new ApiError(422, 'validation_failed'); }
        if ($kind === 'tenant') {
            if ($ref !== $this->tenant) { throw new ApiError(404, 'resource_not_found'); }
            $this->access->requireFull();
            return;
        }
        if ($kind === 'user') { $this->access->user($ref); return; }
        if ($kind === 'device') {
            $d = $this->db->one('SELECT user_id FROM devices WHERE tenant_id=? AND id=?', [$this->tenant, $ref]);
            if (!$d) { throw new ApiError(404, 'resource_not_found'); }
            $this->access->user($d['user_id']);
            return;
        }
        // Al leer, una sede/area desactivada despues sigue visible; solo escribir exige que este activa.
        if ($write && !$this->db->one('SELECT 1 FROM org_units WHERE tenant_id=? AND id=? AND kind=? AND active=1', [$this->tenant, $ref, $kind])) { throw new ApiError(422, 'validation_failed'); }
        if (!$this->access->allowsUnitTarget($kind, $ref)) { throw new ApiError(403, 'permission_denied', [], 'scope_denied'); }
    }

    private function delete(string $id): array
    {
        $old = $this->load($id, true, true);
        // Permiso antes que If-Match: sin permiso no se revela nada (ni la version).
        if (!$this->access->has(self::permissionFor($old['action']))) { throw new ApiError(403, 'permission_denied'); }
        if ($this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        $this->db->run('DELETE FROM power_schedules WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'power_schedule', $id, ['deleted']);
        return ['status' => 204, 'json' => '', 'headers' => []];
    }

    private function runs(string $id): array
    {
        $this->load($id, false, true);
        [$scope, $args] = $this->access->usersSql('u');
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        [$major, $minor, $patch] = array_map('intval', explode('.', self::MIN_AGENT));
        // Version del agente comparada por numero (4.0.9 < 4.0.30), no como texto.
        $version = implode(', ', array_map(fn (int $n) => "CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(d.agent_version,'.',$n),'.',-1) AS UNSIGNED)", [1, 2, 3]));
        // Mismo alcance que clientSchedules(): persona por device_assignments vigente (o devices.user_id), sede/area por
        // user_assignments vigente (o users); con asignaciones pero ninguna vigente, el equipo no recibe nada.
        $site = 'IF(ua.user_id IS NULL, eu.site_id, ua.site_id)';
        $area = 'IF(ua.user_id IS NULL, eu.area_id, ua.area_id)';
        $match = "(t.kind='tenant' AND t.ref_id=d.tenant_id) OR (t.kind='device' AND t.ref_id=d.id) OR (t.kind='user' AND t.ref_id=eu.id)
            OR (t.kind='site' AND t.ref_id<=>$site) OR (t.kind='area' AND t.ref_id<=>$area)";
        // Ultima fila por equipo (la mas reciente por occurrence_at y reported_at), solo de las personas del alcance; mas una
        // fila sintetica unsupported/agent_too_old (occurrence_at = updated_at de la programacion) por cada equipo alcanzado
        // con agente < MIN_AGENT que aun no tiene resultados: «No soportado: actualizar agente».
        $rows = $this->db->run("SELECT x.* FROM (
            SELECT r.device_id, r.occurrence_at, r.outcome, r.detail, r.reported_at, d.hostname
            FROM power_schedule_runs r
            JOIN devices d ON d.tenant_id=r.tenant_id AND d.id=r.device_id
            LEFT JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id
            WHERE r.tenant_id=? AND r.schedule_id=? AND ($scope)
            AND NOT EXISTS (SELECT 1 FROM power_schedule_runs n WHERE n.tenant_id=r.tenant_id AND n.schedule_id=r.schedule_id AND n.device_id=r.device_id
                AND (n.occurrence_at>r.occurrence_at OR (n.occurrence_at=r.occurrence_at AND (n.reported_at>r.reported_at OR (n.reported_at=r.reported_at AND n.id>r.id)))))
            UNION ALL
            SELECT DISTINCT d.id, s.updated_at, 'unsupported', 'agent_too_old', CAST(? AS DATETIME(6)), d.hostname
            FROM power_schedules s
            JOIN devices d ON d.tenant_id=s.tenant_id AND d.status='active'
            LEFT JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id
            LEFT JOIN device_assignments da ON da.tenant_id=d.tenant_id AND da.device_id=d.id AND da.starts_at<=? AND (da.ends_at IS NULL OR da.ends_at>?)
            JOIN users eu ON eu.tenant_id=d.tenant_id AND eu.id=COALESCE(da.user_id, d.user_id)
            LEFT JOIN user_assignments ua ON ua.tenant_id=d.tenant_id AND ua.user_id=eu.id AND ua.starts_at<=? AND (ua.ends_at IS NULL OR ua.ends_at>?)
            WHERE s.tenant_id=? AND s.id=? AND ($scope)
            AND ($version) < (?, ?, ?)
            AND NOT EXISTS (SELECT 1 FROM power_schedule_runs r WHERE r.tenant_id=s.tenant_id AND r.schedule_id=s.id AND r.device_id=d.id)
            AND (da.device_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM device_assignments x WHERE x.tenant_id=d.tenant_id AND x.device_id=d.id))
            AND (ua.user_id IS NOT NULL OR NOT EXISTS (SELECT 1 FROM user_assignments x WHERE x.tenant_id=d.tenant_id AND x.user_id=eu.id))
            AND EXISTS (SELECT 1 FROM power_schedule_targets t WHERE t.tenant_id=s.tenant_id AND t.schedule_id=s.id AND t.excluded=FALSE AND ($match))
            AND NOT EXISTS (SELECT 1 FROM power_schedule_targets t WHERE t.tenant_id=s.tenant_id AND t.schedule_id=s.id AND t.excluded=TRUE AND ($match))
            ) x ORDER BY x.hostname, x.device_id LIMIT 1000",
            [$this->tenant, $id, ...$args, $now, $now, $now, $now, $now, $this->tenant, $id, ...$args, $major, $minor, $patch])->fetchAll();
        return AdminApi::response(['data' => array_map(fn ($x) => ['device_id' => Util::id($x['device_id']), 'hostname' => $x['hostname'],
            'occurrence_at' => Util::time($x['occurrence_at']), 'outcome' => $x['outcome'], 'detail' => $x['detail'],
            'reported_at' => Util::time($x['reported_at'])], $rows)]);
    }

    /**
     * Programaciones activas y vigentes que aplican a este equipo: incluidas por algun alcance y sin ninguna exclusion.
     * Equipo -> persona por device_assignments vigente (o devices.user_id); persona -> sede/area por user_assignments vigente (o users),
     * como PolicyCompiler::snapshot(). Si hay asignaciones pero ninguna cubre ahora (el compilador lanza), no se envia nada.
     * La directiva no lleva alcance ni exclusiones. Sin la funcion contratada 'comandos' no se envia nada: el agente
     * recibe la lista vacia y cancela lo pendiente.
     */
    public function clientSchedules(string $device): array
    {
        if (!Features::has($this->db, $this->tenant, 'comandos')) { return []; }
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $d = $this->db->one('SELECT user_id FROM devices WHERE tenant_id=? AND id=?', [$this->tenant, $device]);
        if (!$d) { return []; }
        $assignment = $this->current('device_assignments', 'device_id', $device, $now);
        if ($assignment === false) { return []; }
        $userId = $assignment['user_id'] ?? $d['user_id'];
        $org = $this->current('user_assignments', 'user_id', $userId, $now);
        if ($org === false) { return []; }
        $org ??= $this->db->one('SELECT site_id, area_id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $userId]);
        $today = (new \DateTimeImmutable('now', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
        $match = "(t.kind='tenant' AND t.ref_id=s.tenant_id) OR (t.kind='device' AND t.ref_id=?) OR (t.kind='user' AND t.ref_id=?) OR (t.kind='site' AND t.ref_id<=>?) OR (t.kind='area' AND t.ref_id<=>?)";
        $args = [$device, $userId, $org['site_id'] ?? null, $org['area_id'] ?? null];
        $rows = $this->db->run("SELECT s.* FROM power_schedules s WHERE s.tenant_id=? AND s.status='active'
            AND ((s.recurrence='once' AND s.run_on>=DATE_SUB(?, INTERVAL 1 DAY)) OR (s.recurrence='weekly' AND s.valid_from<=DATE_ADD(?, INTERVAL 7 DAY) AND (s.valid_until IS NULL OR s.valid_until>=DATE_SUB(?, INTERVAL 1 DAY))))
            AND EXISTS (SELECT 1 FROM power_schedule_targets t WHERE t.tenant_id=s.tenant_id AND t.schedule_id=s.id AND t.excluded=FALSE AND ($match))
            AND NOT EXISTS (SELECT 1 FROM power_schedule_targets t WHERE t.tenant_id=s.tenant_id AND t.schedule_id=s.id AND t.excluded=TRUE AND ($match))
            ORDER BY s.created_at, s.id LIMIT 50", [$this->tenant, $today, $today, $today, ...$args, ...$args])->fetchAll();
        return array_map(function ($s) {
            $dto = $this->dto($s, []);   // sin consultar destinos: la directiva no los lleva
            $out = ['id' => $dto['id'], 'version' => $dto['version'], 'action' => $dto['action'], 'recurrence' => $dto['recurrence'], 'time_local' => $dto['time_local'],
                'valid_from' => $dto['valid_from'], 'timezone' => $this->c['timezone'],
                'message' => $dto['message'] !== '' ? $dto['message'] : ($dto['action'] === 'restart' ? 'TI programó el reinicio de este equipo.' : 'TI programó el apagado de este equipo.'),
                'idle_minutes' => $dto['idle_minutes'], 'notice_minutes' => $dto['notice_minutes'], 'window_minutes' => $dto['window_minutes'],
                // Version vigente (UTC): el agente ignora sin reportar una ocurrencia anterior a la ultima edicion.
                'updated_at' => $dto['updated_at']];
            if ($dto['days']) { $out['days'] = $dto['days']; }
            if ($dto['run_on'] !== null) { $out['run_on'] = $dto['run_on']; }
            if ($dto['valid_until'] !== null) { $out['valid_until'] = $dto['valid_until']; }
            if ($dto['postpone_minutes'] !== null) { $out['postpone_minutes'] = $dto['postpone_minutes']; }
            return $out;
        }, $rows);
    }

    /** Asignacion vigente de la tabla; null si no hay ninguna fila (se usa el fallback); false si hay filas pero ninguna cubre ahora. */
    private function current(string $table, string $owner, string $id, string $at): array|null|false
    {
        $row = $this->db->one("SELECT * FROM $table WHERE tenant_id=? AND $owner=? AND starts_at<=? AND (ends_at IS NULL OR ends_at>?)", [$this->tenant, $id, $at, $at]);
        if ($row) { return $row; }
        return $this->db->one("SELECT 1 x FROM $table WHERE tenant_id=? AND $owner=? LIMIT 1", [$this->tenant, $id]) ? false : null;
    }

    /**
     * Resultados del agente: idempotentes por event_id y por (programacion, equipo, ocurrencia, resultado) —INSERT IGNORE cubre ambas
     * UNIQUE—; una programacion de otra empresa o borrada se confirma sin guardar. Devuelve los event_id confirmados.
     */
    public function ingestRuns(string $device, array $runs): array
    {
        $acks = [];
        foreach ($runs as $x) {
            $sid = Util::bin($x->schedule_id);
            if ($this->db->one('SELECT 1 FROM power_schedules WHERE tenant_id=? AND id=?', [$this->tenant, $sid])) {
                $this->db->run('INSERT IGNORE INTO power_schedule_runs (tenant_id,id,schedule_id,device_id,occurrence_at,outcome,detail,event_id) VALUES (?,?,?,?,?,?,?,?)',
                    [$this->tenant, Util::bin(Util::uuid()), $sid, $device, Util::sqlTime($x->occurrence_at), $x->outcome, $x->detail ?? null, Util::bin($x->event_id)]);
            }
            $acks[] = strtolower($x->event_id);
        }
        return $acks;
    }

    /** $t: filas de destinos ya cargadas (listado); null las consulta (fila suelta). */
    private function dto(array $s, ?array $t = null): array
    {
        $t ??= $this->db->run('SELECT kind, ref_id, excluded FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=? ORDER BY kind, ref_id', [$this->tenant, $s['id']])->fetchAll();
        $map = fn (bool $ex) => array_values(array_map(fn ($x) => ['kind' => $x['kind'], 'id' => Util::id($x['ref_id'])],
            array_filter($t, fn ($x) => (bool) $x['excluded'] === $ex)));
        $days = [];
        for ($d = 1; $d <= 7; $d++) { if ((int) $s['days_mask'] & (1 << ($d - 1))) { $days[] = $d; } }
        return ['id' => Util::id($s['id']), 'name' => $s['name'], 'action' => $s['action'], 'recurrence' => $s['recurrence'], 'days' => $days,
            'time_local' => $s['time_local'], 'run_on' => $s['run_on'], 'valid_from' => $s['valid_from'], 'valid_until' => $s['valid_until'],
            'targets' => $map(false), 'exclusions' => $map(true), 'message' => $s['message'], 'idle_minutes' => (int) $s['idle_minutes'],
            'notice_minutes' => (int) $s['notice_minutes'], 'postpone_minutes' => $s['postpone_minutes'] === null ? null : (int) $s['postpone_minutes'],
            'window_minutes' => (int) $s['window_minutes'], 'status' => $s['status'], 'version' => (int) $s['version'],
            'created_at' => Util::time($s['created_at']), 'updated_at' => Util::time($s['updated_at'])];
    }
}
