<?php
declare(strict_types=1);
namespace Keeper;

/** Plan efectivo de una persona y sus funciones activas (v4-clientes-funciones-accesos §3). */
final class PersonPlans
{
    public const FULL_PLAN = 'Completo';

    /** Plan propio activo, si no el por defecto activo del cliente, si no null (ids binarios). */
    public static function effectivePlan(Database $db, string $tenant, string $user): ?string
    {
        $own = $db->one('SELECT p.id FROM users u JOIN person_plans p ON p.tenant_id=u.plan_tenant_id AND p.id=u.plan_id AND p.active=TRUE WHERE u.tenant_id=? AND u.id=?', [$tenant, $user]);
        if ($own) { return $own['id']; }
        $default = $db->one('SELECT p.id FROM tenants t JOIN person_plans p ON p.tenant_id=t.default_plan_tenant_id AND p.id=t.default_plan_id AND p.active=TRUE WHERE t.tenant_id=?', [$tenant]);
        return $default['id'] ?? null;
    }

    /** @return list<string> funciones de nivel persona que el cliente compro y el plan efectivo incluye */
    public static function functions(Database $db, string $tenant, string $user): array
    {
        $u = $db->one('SELECT status FROM users WHERE tenant_id=? AND id=?', [$tenant, $user]);
        if (!$u || $u['status'] !== 'active') { return []; }
        $plan = self::effectivePlan($db, $tenant, $user);
        if ($plan === null) { return []; }
        return array_column($db->run('SELECT i.feature_code FROM person_plan_items i JOIN tenant_features f ON f.tenant_id=? AND f.feature_code=i.feature_code
            WHERE i.tenant_id=0x00000000000040008000000000000001 AND i.plan_id=? ORDER BY i.feature_code', [$tenant, $plan])->fetchAll(), 'feature_code');
    }

    /** Pide recompilar las politicas del cliente (la request actual o config/compile-pending.php la procesan). */
    public static function markRecompile(Database $db, string $tenant): void
    {
        $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)', [$tenant]);
    }
}
