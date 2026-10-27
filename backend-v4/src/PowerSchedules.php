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

    /** El contrato exige equipos.apagar en todas las operaciones; reiniciar exige ademas equipos.reiniciar. */
    public static function permissionFor(string $action): string
    {
        return $action === 'restart' ? 'equipos.reiniciar' : 'equipos.apagar';
    }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        return match ($op) {
            'listPowerSchedules' => $this->list(),
            'getPowerSchedule' => AdminApi::response($this->dto($this->load($id, false, true)), 200, $this->etag($this->load($id, false))),
            'createPowerSchedule' => $this->save(null, $b),
            'patchPowerSchedule' => $this->save($id, $b),
            'deletePowerSchedule' => $this->delete($id),
            'listPowerScheduleRuns' => $this->runs($id),
        };
    }

    private function etag(array $s): array { return ['ETag' => '"' . $s['version'] . '"']; }

    // Como las politicas (listPolicies): son configuracion de la empresa, asi que listarlas exige alcance total.
    private function list(): array
    {
        $this->access->requireFull();
        $rows = $this->db->run('SELECT * FROM power_schedules WHERE tenant_id=? ORDER BY created_at DESC, id LIMIT 500', [$this->tenant])->fetchAll();
        return AdminApi::response(['data' => array_map(fn ($s) => $this->dto($s), $rows)]);
    }

    /** $manage: quien no tiene alcance total solo ve y toca programaciones cuyos destinos y exclusiones caen todos en su alcance. */
    private function load(string $id, bool $lock, bool $manage = false): array
    {
        $s = $this->db->one('SELECT * FROM power_schedules WHERE tenant_id=? AND id=?' . ($lock ? ' FOR UPDATE' : ''), [$this->tenant, $id]);
        if (!$s) { throw new ApiError(404, 'resource_not_found'); }
        if ($manage && !$this->access->full()) {
            foreach ($this->db->run('SELECT kind, ref_id FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=?', [$this->tenant, $id])->fetchAll() as $t) { $this->checkTarget($t['kind'], $t['ref_id']); }
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
        if ($v['recurrence'] === 'weekly') {
            if (!$v['days']) { throw new ApiError(422, 'validation_failed', [], 'invalid_days'); }
            $v['run_on'] = null;
        } else {
            if ($v['run_on'] === null) { throw new ApiError(422, 'validation_failed', [], 'invalid_run_on'); }
            $v['days'] = []; $v['valid_until'] = null;
        }
        foreach (['valid_from', 'valid_until', 'run_on'] as $k) { if ($v[$k] !== null) { $this->date($v[$k]); } }
        if ($v['valid_until'] !== null && $v['valid_until'] < $v['valid_from']) { throw new ApiError(422, 'validation_failed', [], 'invalid_range'); }
        if (preg_match('/\p{Cc}/u', (string) $v['message'])) { throw new ApiError(422, 'validation_failed'); }
        // El permiso del contrato es equipos.apagar; reiniciar exige ademas el suyo (y cambiar de reinicio a apagado, ambos).
        foreach (array_unique([$v['action'], $old['action'] ?? $v['action']]) as $act) {
            if (!$this->access->has(self::permissionFor($act))) { throw new ApiError(403, 'permission_denied'); }
        }
        $targets = array_map(fn ($t) => (object) $t, $v['targets']);
        $excl = array_map(fn ($t) => (object) $t, $v['exclusions']);
        if (!$targets) { throw new ApiError(422, 'validation_failed'); }
        foreach (array_merge($targets, $excl) as $t) { $this->checkTarget($t->kind, Util::bin($t->id)); }

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
        $this->db->run('DELETE FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=?', [$this->tenant, $id]);
        foreach ([[$targets, 0], [$excl, 1]] as [$list, $ex]) {
            foreach ($list as $t) {
                $this->db->run('INSERT IGNORE INTO power_schedule_targets (tenant_id,id,schedule_id,kind,ref_id,excluded) VALUES (?,?,?,?,?,?)',
                    [$this->tenant, Util::bin(Util::uuid()), $id, $t->kind, Util::bin($t->id), $ex]);
            }
        }
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'power_schedule', $id, array_keys(get_object_vars($b)));
        $saved = $this->load($id, false);
        return AdminApi::response($this->dto($saved), $old ? 200 : 201, $this->etag($saved));
    }

    // Mismo criterio que AdminApi::policyTarget(): empresa exige alcance total; persona/equipo dentro del alcance; sede/area activas y permitidas.
    private function checkTarget(string $kind, string $ref): void
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
        if (!$this->db->one('SELECT 1 FROM org_units WHERE tenant_id=? AND id=? AND kind=? AND active=1', [$this->tenant, $ref, $kind])) { throw new ApiError(422, 'validation_failed'); }
        if (!$this->access->allowsUnitTarget($kind, $ref)) { throw new ApiError(403, 'permission_denied', [], 'scope_denied'); }
    }

    private function delete(string $id): array
    {
        $old = $this->load($id, true, true);
        if ($this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        if (!$this->access->has(self::permissionFor($old['action']))) { throw new ApiError(403, 'permission_denied'); }
        $this->db->run('DELETE FROM power_schedules WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'power_schedule', $id, ['deleted']);
        return ['status' => 204, 'json' => '', 'headers' => []];
    }

    private function runs(string $id): array
    {
        $this->load($id, false, true);
        // Ultima fila por equipo (la mas reciente por occurrence_at y reported_at), solo de las personas del alcance.
        [$scope, $args] = $this->access->usersSql('u');
        $rows = $this->db->run("SELECT r.device_id, r.occurrence_at, r.outcome, r.detail, r.reported_at, d.hostname
            FROM power_schedule_runs r
            JOIN devices d ON d.tenant_id=r.tenant_id AND d.id=r.device_id
            LEFT JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id
            WHERE r.tenant_id=? AND r.schedule_id=? AND ($scope)
            AND NOT EXISTS (SELECT 1 FROM power_schedule_runs n WHERE n.tenant_id=r.tenant_id AND n.schedule_id=r.schedule_id AND n.device_id=r.device_id
                AND (n.occurrence_at>r.occurrence_at OR (n.occurrence_at=r.occurrence_at AND n.reported_at>r.reported_at)))
            ORDER BY d.hostname, r.device_id LIMIT 1000", [$this->tenant, $id, ...$args])->fetchAll();
        return AdminApi::response(['data' => array_map(fn ($x) => ['device_id' => Util::id($x['device_id']), 'hostname' => $x['hostname'],
            'occurrence_at' => Util::time($x['occurrence_at']), 'outcome' => $x['outcome'], 'detail' => $x['detail'],
            'reported_at' => Util::time($x['reported_at'])], $rows)]);
    }

    private function dto(array $s): array
    {
        $t = $this->db->run('SELECT kind, ref_id, excluded FROM power_schedule_targets WHERE tenant_id=? AND schedule_id=? ORDER BY kind, ref_id', [$this->tenant, $s['id']])->fetchAll();
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
