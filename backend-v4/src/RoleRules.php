<?php
declare(strict_types=1);
namespace Keeper;

// Asignacion automatica por cargo (§4): propone el rol, no el alcance. Solo a personas sin acceso manual; si deja de
// cumplirse, vuelve el rol por defecto con el mismo alcance. One (o Keeper) dice el cargo y a quien reporta cada persona
// (spec de integraciones §8.3). Una regla solo puede usar roles "asignables por regla".
final class RoleRules
{
    private const SENSITIVE = ['usuarios.editar', 'roles.gestionar', 'roles.reglas', 'integraciones.aprobar', 'integraciones.gestionar'];
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) {}

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        $this->access->requireFull();
        $tenant = $this->c['tenant_id'];
        return match ($op) {
            'listRoleRules' => AdminApi::response(['data' => array_map(fn ($x) => $this->dto($x), $this->db->run('SELECT * FROM panel_role_rules WHERE tenant_id=? ORDER BY name', [$tenant])->fetchAll())]),
            'createRoleRule' => $this->save(null, $b),
            'patchRoleRule' => $this->save($id, $b),
            'getMonitoringExclusions' => $this->exclusions(),
            'putMonitoringExclusions' => $this->putExclusions($b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    /** Personas (ids binarios) que hoy cumplen la condicion de una regla. */
    public static function matching(Database $db, string $tenant, array $rule): array
    {
        return array_column((match ($rule['condition_kind']) {
            'position' => $db->run("SELECT id FROM users WHERE tenant_id=? AND status='active' AND position_id=?", [$tenant, $rule['position_id']]),
            'position_in_area' => $db->run("SELECT id FROM users WHERE tenant_id=? AND status='active' AND position_id=? AND area_id=?", [$tenant, $rule['position_id'], $rule['area_id']]),
            'manages' => $db->run("SELECT DISTINCT m.id FROM users r JOIN users m ON m.tenant_id=r.tenant_id AND m.id=r.reports_to_user_id AND m.status='active' WHERE r.tenant_id=? AND r.status='active'", [$tenant]),
        })->fetchAll(), 'id');
    }

    /**
     * Recalcula los roles puestos por reglas en toda la empresa. Devuelve [añadidos, quitados, ids de los afectados].
     * $by: quien hizo el cambio desde el panel. Un rol que la regla le daria a alguien por ese cambio pasa por su
     * delegacion (§4); si no podria darlo, 403 (con la persona y el rol) y no cambia nada. Sin $by (sync, sistema) o
     * con la plataforma, la regla aplica en toda la empresa.
     * $concerns(user, rol deseado|null, grant actual|null, regla deseada|null): lo que cambia por la peticion (la persona
     * editada y su jefe, la regla o el rol guardado). Para quien no es plataforma solo eso se valida y se aplica; el
     * desfase de otras personas (sync sin jerarquia, pendientes resueltos, migracion) queda para el recalculo del
     * sistema y no se le cobra a quien edita. null = toda la empresa (fail-closed).
     */
    public static function apply(Database $db, string $tenant, ?AdminAccess $by = null, ?\Closure $concerns = null): array
    {
        $only = $by !== null && $concerns !== null && !$by->c['platform'];
        $desired = [];
        foreach ($db->run('SELECT p.* FROM panel_role_rules p JOIN roles r ON r.tenant_id=p.tenant_id AND r.id=p.role_id AND r.active=TRUE AND r.assignable_by_rule=TRUE WHERE p.tenant_id=? AND p.active=TRUE ORDER BY p.created_at', [$tenant])->fetchAll() as $rule) {
            foreach (self::matching($db, $tenant, $rule) as $user) { $desired[bin2hex($user)] ??= [$user, $rule['role_id'], $rule['id']]; }
        }
        $added = 0; $removed = 0; $affected = [];
        foreach ($db->run("SELECT user_id FROM access_grants WHERE tenant_id=? AND source='rule' AND user_id IS NOT NULL", [$tenant])->fetchAll() as $row) {
            if (isset($desired[bin2hex($row['user_id'])])) { continue; }
            if ($only && !$concerns($row['user_id'], null, AccessGrants::forUser($db, $tenant, $row['user_id']), null)) { continue; }
            AccessGrants::revertToDefault($db, $tenant, $row['user_id']); $removed++;
            $affected[bin2hex($row['user_id'])] = $row['user_id'];
        }
        foreach ($desired as [$user, $role, $rule]) {
            $g = AccessGrants::forUser($db, $tenant, $user);
            if ($g !== null && ($g['source'] === 'manual' || ($g['role_id'] === $role && $g['rule_id'] === $rule))) { continue; }
            if ($only && !$concerns($user, $role, $g, $rule)) { continue; }
            $scope = $g['scope'] ?? AccessScope::own();
            if ($by !== null && ($g === null || $g['role_id'] !== $role)) { self::delegate($db, $tenant, $by, $user, $role, $scope); }
            AccessGrants::setForUser($db, $tenant, $user, $role, $scope, 'rule', $rule);
            $added++;
            $affected[bin2hex($user)] = $user;
        }
        // Tercer elemento: ids binarios de las personas que esta recomputacion acaba de cambiar (anadidas o revertidas).
        // Quien edita un rol las re-autentica una por una; setForUser/revertToDefault no invalidan sesiones por si mismas.
        return [$added, $removed, array_values($affected)];
    }

    /** Delegacion de un rol que una regla le daria a $user; el 403 dice a quien y que rol (Problem.errors, en español). */
    private static function delegate(Database $db, string $tenant, AdminAccess $by, string $user, string $role, AccessScope $scope): void
    {
        $permissions = AccessGrants::permissions($db, $tenant, $role);
        try { $by->within($permissions, $scope, $user); } catch (ApiError $e) {
            if ($e->detail !== 'delegation_exceeds_own_access') { throw $e; }
            $roleName = $db->one('SELECT name FROM roles WHERE tenant_id=? AND id=?', [$tenant, $role])['name'] ?? 'nuevo';
            // Solo se nombra a quien el que edita puede ver (el jefe nuevo puede estar fuera de su alcance).
            $person = $by->sees($user) ? ($db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?', [$tenant, $user])['display_name'] ?? 'esa persona') : 'una persona fuera de tu alcance';
            $why = $by->lacking($permissions) ? 'tiene permisos que tú no tienes' : 'su alcance no está dentro del tuyo';
            $message = mb_substr("No puedes dar el rol $roleName a $person: $why.", 0, 200);
            throw new ApiError(403, 'permission_denied', [], 'delegation_exceeds_own_access', [['pointer' => '', 'code' => 'delegation_exceeds_own_access', 'message' => $message]]);
        }
    }

    /** Monitoreo efectivo: la excepcion por persona manda; si no, los cargos excluidos (directivos) no se monitorean. */
    public static function monitoring(Database $db, array $user): string
    {
        if (($user['monitoring_override'] ?? 'auto') !== 'auto') { return $user['monitoring_override']; }
        return $db->one('SELECT 1 FROM monitoring_excluded_positions WHERE tenant_id=? AND position_id=?', [$user['tenant_id'], $user['position_id']]) ? 'excluded' : 'monitored';
    }

    private function dto(array $x): array
    {
        $id = static fn (?string $v): ?string => $v === null ? null : Util::id($v);
        return ['id' => Util::id($x['id']), 'name' => $x['name'], 'condition_kind' => $x['condition_kind'], 'position_id' => $id($x['position_id']), 'area_id' => $id($x['area_id']),
            'role_id' => Util::id($x['role_id']), 'active' => (bool) $x['active'], 'matches' => count(self::matching($this->db, $this->c['tenant_id'], $x)), 'version' => (int) $x['version']];
    }
    private function save(?string $id, object $b): array
    {
        $tenant = $this->c['tenant_id'];
        if ($id !== null) {
            $old = $this->db->one('SELECT * FROM panel_role_rules WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $id]);
            if (!$old) { throw new ApiError(404, 'resource_not_found'); }
            if ($this->r->header('if-match') !== '"' . $old['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        }
        $role = $this->db->one('SELECT * FROM roles WHERE tenant_id=? AND id=? AND active=TRUE', [$tenant, Util::bin($b->role_id)]);
        if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
        if (!$role['assignable_by_rule']) { throw new ApiError(409, 'invalid_transition', [], 'role_not_assignable'); }
        // La regla da el rol a cualquiera del cliente que cumpla la condicion: quien la guarda debe tener todos sus permisos (§4).
        $this->access->within(AccessGrants::permissions($this->db, $tenant, $role['id']), AccessScope::all(), null);
        $position = $b->condition_kind === 'manages' ? null : ($b->position_id === null ? null : Util::bin($b->position_id));
        $area = $b->condition_kind === 'position_in_area' && $b->area_id !== null ? Util::bin($b->area_id) : null;
        if (($b->condition_kind !== 'manages' && $position === null) || ($b->condition_kind === 'position_in_area' && $area === null)) { throw new ApiError(422, 'validation_failed', [], 'condition_incomplete'); }
        foreach ([['position', $position], ['area', $area]] as [$kind, $unit]) {
            if ($unit !== null && !$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=?', [$tenant, $unit, $kind])) { throw new ApiError(422, 'validation_failed', [], 'unknown_' . $kind); }
        }
        $row = ['tenant_id' => $tenant, 'condition_kind' => $b->condition_kind, 'position_id' => $position, 'area_id' => $area, 'role_id' => $role['id']];
        // Dar permisos de administracion a mucha gente de golpe exige confirmarlo (§8.3).
        $perms = array_column($this->db->run('SELECT permission_code FROM role_permissions WHERE tenant_id=? AND role_id=?', [$tenant, $role['id']])->fetchAll(), 'permission_code');
        if ($b->active && array_intersect($perms, self::SENSITIVE) && count(self::matching($this->db, $tenant, $row)) > 10 && !($b->confirm_sensitive ?? false)) {
            throw new ApiError(409, 'invalid_transition', [], 'confirm_sensitive');
        }
        if ($id === null) {
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO panel_role_rules (tenant_id,id,name,condition_kind,position_id,area_id,role_id,active) VALUES (?,?,?,?,?,?,?,?)', [$tenant, $id, $b->name, $b->condition_kind, $position, $area, $role['id'], (int) $b->active]);
        } else {
            $this->db->run('UPDATE panel_role_rules SET name=?,condition_kind=?,position_id=?,area_id=?,role_id=?,active=?,version=version+1 WHERE tenant_id=? AND id=?', [$b->name, $b->condition_kind, $position, $area, $role['id'], (int) $b->active, $tenant, $id]);
            // Cambiar el rol de una regla: lo que puso con el rol anterior se recalcula abajo.
        }
        // Lo que cambia por esta regla: quienes la cumplen ahora o la tenian (al desactivarla caen en otra regla).
        self::apply($this->db, $tenant, $this->access, fn (string $user, ?string $role, ?array $grant, ?string $rule): bool => $rule === $id || ($grant['rule_id'] ?? null) === $id);
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'role_rule', $id, array_keys(get_object_vars($b)));
        return AdminApi::response($this->dto($this->db->one('SELECT * FROM panel_role_rules WHERE tenant_id=? AND id=?', [$tenant, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
    private function exclusions(): array
    {
        return AdminApi::response(['position_ids' => array_map([Util::class, 'id'], array_column($this->db->run('SELECT position_id FROM monitoring_excluded_positions WHERE tenant_id=? ORDER BY position_id', [$this->c['tenant_id']])->fetchAll(), 'position_id'))]);
    }
    private function putExclusions(object $b): array
    {
        $tenant = $this->c['tenant_id'];
        $ids = array_map([Util::class, 'bin'], array_values(array_unique($b->position_ids)));
        foreach ($ids as $p) { if (!$this->db->one("SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind='position'", [$tenant, $p])) { throw new ApiError(422, 'validation_failed', [], 'unknown_position'); } }
        $this->db->run('DELETE FROM monitoring_excluded_positions WHERE tenant_id=?', [$tenant]);
        foreach ($ids as $p) { $this->db->run('INSERT INTO monitoring_excluded_positions (tenant_id,position_id) VALUES (?,?)', [$tenant, $p]); }
        (new Audit($this->db))->record($this->c, $this->r, 'putMonitoringExclusions', 'monitoring_exclusions', $tenant, ['position_ids']);
        return $this->exclusions();
    }
}
