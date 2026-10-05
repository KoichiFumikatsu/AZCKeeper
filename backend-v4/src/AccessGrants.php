<?php
declare(strict_types=1);
namespace Keeper;

/** Acceso al panel = 1 rol + 1 alcance (§4): de una persona en su cliente, o de una cuenta de grupo en otra empresa del grupo. */
final class AccessGrants
{
    private const SELECT = 'SELECT g.*,r.name role_name FROM access_grants g JOIN roles r ON r.tenant_id=g.tenant_id AND r.id=g.role_id';

    public static function forUser(Database $db, string $tenant, string $user): ?array
    {
        return self::withScope($db, $db->one(self::SELECT . ' WHERE g.tenant_id=? AND g.user_id=?', [$tenant, $user]));
    }

    public static function forGroupAccount(Database $db, string $tenant, string $adminTenant, string $admin): ?array
    {
        return self::withScope($db, $db->one(self::SELECT . ' WHERE g.tenant_id=? AND g.admin_tenant_id=? AND g.admin_id=?', [$tenant, $adminTenant, $admin]));
    }

    /** Acceso de la sesion: cuenta de grupo fuera de su empresa casa, o la persona de la cuenta. */
    public static function forContext(Database $db, array $c): ?array
    {
        if (($c['group_id'] ?? null) !== null && $c['tenant_id'] !== $c['admin_tenant_id']) { return self::forGroupAccount($db, $c['tenant_id'], $c['admin_tenant_id'], $c['admin_id']); }
        return ($c['user_id'] ?? null) === null ? null : self::forUser($db, $c['tenant_id'], $c['user_id']);
    }

    private static function withScope(Database $db, ?array $g): ?array
    {
        if ($g === null) { return null; }
        $g['scope'] = AccessScope::load($db, $g['tenant_id'], $g['id'], $g['scope_kind']);
        return $g;
    }

    /** @return list<string> permisos del rol activo, sin los de plataforma */
    public static function permissions(Database $db, string $tenant, string $role): array
    {
        return array_column($db->run('SELECT p.permission_code code FROM roles r JOIN role_permissions p ON p.tenant_id=r.tenant_id AND p.role_id=r.id JOIN permissions m ON m.code=p.permission_code AND m.platform_only=FALSE WHERE r.tenant_id=? AND r.id=? AND r.active=TRUE ORDER BY p.permission_code', [$tenant, $role])->fetchAll(), 'code');
    }

    public static function setForUser(Database $db, string $tenant, string $user, string $role, AccessScope $scope, string $source, ?string $rule = null): void
    {
        $old = $db->one('SELECT id FROM access_grants WHERE tenant_id=? AND user_id=? FOR UPDATE', [$tenant, $user]);
        $id = $old['id'] ?? Util::bin(Util::uuid());
        if ($old) { $db->run('UPDATE access_grants SET role_id=?,scope_kind=?,source=?,rule_id=?,version=version+1 WHERE tenant_id=? AND id=?', [$role, $scope->kind, $source, $rule, $tenant, $id]); }
        else { $db->run('INSERT INTO access_grants (tenant_id,id,user_id,role_id,scope_kind,source,rule_id) VALUES (?,?,?,?,?,?,?)', [$tenant, $id, $user, $role, $scope->kind, $source, $rule]); }
        $scope->save($db, $tenant, $id);
    }

    public static function setForGroupAccount(Database $db, string $tenant, string $adminTenant, string $admin, string $role, AccessScope $scope): void
    {
        if (!in_array($scope->kind, ['all', 'filters'], true)) { throw new ApiError(422, 'validation_failed', [], 'group_scope_invalid'); }
        $db->run('DELETE FROM access_grants WHERE tenant_id=? AND admin_tenant_id=? AND admin_id=?', [$tenant, $adminTenant, $admin]);
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO access_grants (tenant_id,id,admin_tenant_id,admin_id,role_id,scope_kind,source) VALUES (?,?,?,?,?,?,'manual')", [$tenant, $id, $adminTenant, $admin, $role, $scope->kind]);
        $scope->save($db, $tenant, $id);
    }

    public static function removeForUser(Database $db, string $tenant, string $user): void
    {
        $db->run('DELETE FROM access_grants WHERE tenant_id=? AND user_id=?', [$tenant, $user]);
    }

    public static function defaultRole(Database $db, string $tenant): ?string
    {
        return $db->one("SELECT id FROM roles WHERE tenant_id=? AND seed_key IN ('colaborador','collaborator') AND active=TRUE ORDER BY seed_key='colaborador' DESC LIMIT 1", [$tenant])['id'] ?? null;
    }

    /** Persona nueva: Colaborador, solo lo propio (§4). Sin rol Colaborador activo, sin acceso. */
    public static function grantDefault(Database $db, string $tenant, string $user): void
    {
        $role = self::defaultRole($db, $tenant);
        if ($role === null) { self::removeForUser($db, $tenant, $user); return; }
        self::setForUser($db, $tenant, $user, $role, AccessScope::own(), 'default');
    }

    /** Dar el acceso de una persona (AccessInput) con delegacion segura. Cambiar solo el alcance no le quita el rol a la regla que lo puso. */
    public static function assign(Database $db, AdminAccess $by, string $tenant, string $user, object $input): void
    {
        $role = $db->one('SELECT * FROM roles WHERE tenant_id=? AND id=?', [$tenant, Util::bin($input->role_id)]);
        if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
        if (!$role['active']) { throw new ApiError(409, 'invalid_transition', [], 'role_inactive'); }
        $scope = AccessScope::fromInput($db, $tenant, $input->scope);
        $current = self::forUser($db, $tenant, $user);
        // Cambiar un acceso tambien es delegar: no se toca el de alguien con mas de lo que uno tiene.
        if ($current !== null) { $by->delegate(self::permissions($db, $tenant, $current['role_id']), $current['scope'], $user); }
        $by->delegate(self::permissions($db, $tenant, $role['id']), $scope, $user);
        $keepRule = $current !== null && $current['source'] === 'rule' && $current['role_id'] === $role['id'];
        self::setForUser($db, $tenant, $user, $role['id'], $scope, $keepRule ? 'rule' : 'manual', $keepRule ? $current['rule_id'] : null);
    }

    /**
     * PUENTE de las Tareas 3 a 7 (se borra en la Tarea 8). Atiende los role_ids viejos (0 o 1) de las cuentas.
     * Copia el alcance viejo del rol a la persona. [] quita el acceso manual (vuelve al por defecto); lo que puso una regla se queda.
     */
    public static function assignLegacy(Database $db, AdminAccess $by, string $tenant, string $user, array $roleIds): void
    {
        $roleIds = array_values(array_unique($roleIds));
        if (count($roleIds) > 1) { throw new ApiError(422, 'validation_failed', [], 'one_role_only'); }
        $current = self::forUser($db, $tenant, $user);
        if ($current !== null && (!$roleIds && $current['source'] !== 'manual')) { return; }
        if ($current !== null) { $by->delegate(self::permissions($db, $tenant, $current['role_id']), $current['scope'], $user); }
        if (!$roleIds) { self::grantDefault($db, $tenant, $user); return; }
        $role = $db->one('SELECT * FROM roles WHERE tenant_id=? AND id=?', [$tenant, Util::bin($roleIds[0])]);
        if (!$role) { throw new ApiError(422, 'validation_failed', [], 'role_not_found'); }
        if (!$role['active']) { throw new ApiError(409, 'invalid_transition'); }
        $scope = AccessScope::fromLegacyRole($db, $tenant, $role['id']);
        $by->delegate(self::permissions($db, $tenant, $role['id']), $scope, $user);
        self::setForUser($db, $tenant, $user, $role['id'], $scope, 'manual');
    }

    /** Access del contrato. */
    public static function dto(?array $g): ?array
    {
        return $g === null ? null : ['role_id' => Util::id($g['role_id']), 'role_name' => $g['role_name'], 'scope' => $g['scope']->dto(), 'source' => $g['source']];
    }
}
