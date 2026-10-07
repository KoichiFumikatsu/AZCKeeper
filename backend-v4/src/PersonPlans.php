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

    /** SQL del plan efectivo de cada fila `$alias` de users (propio activo, si no el por defecto activo del cliente). */
    public static function effectiveSql(string $alias): string
    {
        return "(SELECT COALESCE(op.id, dp.id) FROM tenants pt
            LEFT JOIN person_plans op ON op.tenant_id=$alias.plan_tenant_id AND op.id=$alias.plan_id AND op.active=TRUE
            LEFT JOIN person_plans dp ON dp.tenant_id=pt.default_plan_tenant_id AND dp.id=pt.default_plan_id AND dp.active=TRUE
            WHERE pt.tenant_id=$alias.tenant_id)";
    }

    /** Planes que se pueden asignar en este cliente: cada uno con las funciones de persona que de verdad da aqui (plan ∩ cliente). */
    public static function available(Database $db, string $tenant): array
    {
        $platform = Util::bin(AdminAuth::PLATFORM);
        $bought = array_flip(Features::enabled($db, $tenant));
        $person = array_values(array_filter(Features::codes(), fn ($code) => Features::CATALOG[$code]['level'] === 'person' && isset($bought[$code])));
        $default = $db->one('SELECT p.id FROM tenants t JOIN person_plans p ON p.tenant_id=t.default_plan_tenant_id AND p.id=t.default_plan_id AND p.active=TRUE WHERE t.tenant_id=?', [$tenant]);
        $plans = $db->run('SELECT id,name,badge_label FROM person_plans WHERE tenant_id=? AND active=TRUE ORDER BY name', [$platform])->fetchAll();
        return ['default_plan_id' => $default ? Util::id($default['id']) : null,
            'features' => array_map(fn ($code) => ['code' => $code, 'name' => Features::CATALOG[$code]['name']], $person),
            'data' => array_map(function ($p) use ($db, $platform, $bought) {
                $items = array_column($db->run('SELECT feature_code FROM person_plan_items WHERE tenant_id=? AND plan_id=? ORDER BY feature_code', [$platform, $p['id']])->fetchAll(), 'feature_code');
                return ['id' => Util::id($p['id']), 'name' => $p['name'], 'badge_label' => $p['badge_label'], 'features' => array_values(array_filter($items, fn ($code) => isset($bought[$code])))];
            }, $plans)];
    }

    /** Pide recompilar las politicas del cliente (la request actual o config/compile-pending.php la procesan). */
    public static function markRecompile(Database $db, string $tenant): void
    {
        $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)', [$tenant]);
    }
}
