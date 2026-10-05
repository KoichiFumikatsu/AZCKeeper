<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Autorizacion de una peticion del panel (docs/architecture/v4-clientes-funciones-accesos.md §4 y §7). La persona (o la
 * cuenta de grupo en otra empresa) tiene un solo acceso: 1 rol (permisos) + 1 alcance (datos). Superadmin: todo.
 * 403 permission_denied con detalle feature_not_contracted:<f>, scope_denied o delegation_exceeds_own_access.
 */
final class AdminAccess
{
    private ?array $grant = null;
    private AccessScope $scope;
    /** @var list<string> */
    private array $permissions = [];

    public function __construct(private Database $db, public readonly array $c, public readonly string $permission, ?string $operation = null)
    {
        if ($c['platform']) {
            if (!$db->one('SELECT code FROM permissions WHERE code=?', [$permission])) { throw new ApiError(403, 'permission_denied'); }
            $this->scope = AccessScope::all();
            return;
        }
        $this->grant = AccessGrants::forContext($db, $c);
        $this->permissions = $this->grant === null ? [] : AccessGrants::permissions($db, $c['tenant_id'], $this->grant['role_id']);
        if (!in_array($permission, $this->permissions, true)) { throw new ApiError(403, 'permission_denied'); }
        $this->scope = $this->grant['scope'];
        // Funcion no contratada por el cliente: aunque el rol tenga el permiso (superadmin nunca queda bloqueado).
        $feature = Features::forOperation($operation ?? '', $permission);
        if ($feature !== null && !Features::has($db, $c['tenant_id'], $feature)) { throw new ApiError(403, 'permission_denied', [], "feature_not_contracted:$feature"); }
    }

    public function scope(): AccessScope { return $this->scope; }
    public function full(): bool { return $this->scope->kind === 'all'; }
    public function requireFull(): void { if (!$this->full()) { throw new ApiError(403, 'permission_denied', [], 'scope_denied'); } }
    public function rolesGate(): void
    {
        if (!$this->c['platform'] && !$this->c['gate']) { throw new ApiError(403, 'tenant_rbac_disabled'); }
    }

    /** Condicion SQL de las personas del alcance sobre $alias (users, o user_assignments con $userKey='user_id'). */
    public function usersSql(string $alias = 'u', string $userKey = 'id'): array
    {
        return match ($this->scope->kind) {
            'all' => ['1=1', []],
            'self' => ($this->c['user_id'] ?? null) === null ? ['1=0', []] : ["$alias.$userKey=?", [$this->c['user_id']]],
            'team' => ($team = $this->team()) ? ["$alias.$userKey IN (" . implode(',', array_fill(0, count($team), '?')) . ')', $team] : ['1=0', []],
            'filters' => $this->scope->filterSql($alias),
        };
    }

    private ?array $teamIds = null;
    /** La persona de la cuenta y quienes le reportan, directa o indirectamente (alcance "team", §4). */
    public function team(): array
    {
        if ($this->teamIds !== null) { return $this->teamIds; }
        if (($this->c['user_id'] ?? null) === null) { return $this->teamIds = []; }
        $seen = [bin2hex($this->c['user_id']) => $this->c['user_id']]; $level = [$this->c['user_id']];
        for ($depth = 0; $level && $depth < 20; $depth++) {
            $next = [];
            foreach ($this->db->run('SELECT id FROM users WHERE tenant_id=? AND reports_to_user_id IN (' . implode(',', array_fill(0, count($level), '?')) . ')', [$this->c['tenant_id'], ...$level])->fetchAll() as $row) {
                if (!isset($seen[bin2hex($row['id'])])) { $seen[bin2hex($row['id'])] = $row['id']; $next[] = $row['id']; }
            }
            $level = $next;
        }
        return $this->teamIds = array_values($seen);
    }

    /** Persona dentro del alcance; fuera, 404 (no se revela que existe). */
    public function user(string $id): array
    {
        [$where, $args] = $this->usersSql();
        $u = $this->db->one("SELECT u.* FROM users u WHERE u.tenant_id=? AND u.id=? AND $where", [$this->c['tenant_id'], $id, ...$args]);
        if (!$u) { throw new ApiError(404, 'resource_not_found'); }
        return $u;
    }

    /** Crear o mover una persona: su nueva ubicacion debe caer dentro del alcance (equipo o propio no crean ni mueven). */
    public function allowsPlacement(array $person): bool
    {
        return $this->scope->matches($this->db, $this->c['tenant_id'], $person);
    }

    /** Objetivo de una regla (sede, area o empresa): afecta a todos los de esa unidad, asi que el alcance debe limitarse a ella. */
    public function allowsUnitTarget(string $kind, string $id): bool
    {
        if ($this->full()) { return true; }
        if ($this->scope->kind !== 'filters' || !in_array($id, $this->scope->list($kind), true)) { return false; }
        foreach (['company', 'city', 'site', 'area'] as $other) { if ($other !== $kind && $this->scope->list($other)) { return false; } }
        return true;
    }

    /** Delegacion segura (§4): nadie da permisos que no tiene ni un alcance mas amplio que el propio. */
    public function delegate(array $permissions, AccessScope $scope, ?string $target): void
    {
        $this->rolesGate();
        foreach ($permissions as $code) {
            $p = $this->db->one('SELECT * FROM permissions WHERE code=?', [$code]);
            if (!$p || $p['platform_only']) { throw new ApiError(403, 'permission_denied'); }
            if (!$this->c['platform'] && ($code === 'roles.gestionar' || !$p['delegable'] || !in_array($code, $this->permissions, true))) {
                throw new ApiError(403, 'permission_denied', [], 'delegation_exceeds_own_access');
            }
        }
        if (!$this->c['platform'] && !$this->covers($scope, $target)) { throw new ApiError(403, 'permission_denied', [], 'delegation_exceeds_own_access'); }
    }

    private function covers(AccessScope $child, ?string $target): bool
    {
        if ($this->full()) { return true; }
        return match ($child->kind) {
            'all' => false,
            'self' => $target !== null && $this->sees($target),
            'team' => $target !== null && $this->scope->kind === 'team' && in_array($target, $this->team(), true),
            'filters' => $this->scope->covers($child),
        };
    }

    private function sees(string $user): bool
    {
        [$where, $args] = $this->usersSql();
        return (bool) $this->db->one("SELECT u.id FROM users u WHERE u.tenant_id=? AND u.id=? AND $where", [$this->c['tenant_id'], $user, ...$args]);
    }
}
