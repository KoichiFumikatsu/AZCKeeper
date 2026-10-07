<?php
declare(strict_types=1);
namespace Keeper;

/** Administracion de funciones (solo plataforma): catalogo, paquetes y funciones de cada cliente. */
final class FeatureAdmin
{
    private string $platform;
    public function __construct(private Database $db, private array $c, private Request $r) { $this->platform = Util::bin(AdminAuth::PLATFORM); }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        return match ($op) {
            'listFeatures' => AdminApi::response(['data' => array_map(fn ($code) => ['code' => $code, 'name' => Features::CATALOG[$code]['name'], 'group' => Features::CATALOG[$code]['group'],
                'level' => Features::CATALOG[$code]['level'], 'pending' => Features::CATALOG[$code]['pending'], 'description' => Features::CATALOG[$code]['description']], Features::codes())]),
            'listFeaturePackages' => AdminApi::response(['data' => array_map(fn ($p) => $this->packageDto($p), $this->db->run('SELECT * FROM feature_packages WHERE tenant_id=? ORDER BY name', [$this->platform])->fetchAll())]),
            'createFeaturePackage', 'patchFeaturePackage' => $this->savePackage($id, $b),
            'getTenantFeatures' => AdminApi::response($this->tenantDto($this->c['tenant_id'])),
            'putTenantFeatures' => $this->putTenant($b),
            'listPersonPlans' => AdminApi::response(['data' => array_map(fn ($p) => $this->planDto($p), $this->db->run('SELECT * FROM person_plans WHERE tenant_id=? ORDER BY name', [$this->platform])->fetchAll())]),
            'createPersonPlan', 'patchPersonPlan' => $this->savePlan($id, $b),
            'getTenantPlanUsage' => AdminApi::response($this->usage($this->c['tenant_id'])),
            'listAvailablePersonPlans' => AdminApi::response(PersonPlans::available($this->db, $this->c['tenant_id'])),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }
    /** @param list<string> $codes */
    private function valid(array $codes): array
    {
        foreach ($codes as $code) { if (!isset(Features::CATALOG[$code])) { throw new ApiError(422, 'validation_failed', [], "unknown_feature:$code"); } }
        $codes = array_values(array_unique($codes)); sort($codes); return $codes;
    }
    /** @param list<string> $codes funciones de nivel persona */
    private function personFeatures(array $codes): array
    {
        $codes = $this->valid($codes);
        foreach ($codes as $code) { if (Features::CATALOG[$code]['level'] !== 'person') { throw new ApiError(422, 'validation_failed', [], "not_person_feature:$code"); } }
        return $codes;
    }
    private function planDto(array $p): array
    {
        $features = array_column($this->db->run('SELECT feature_code FROM person_plan_items WHERE tenant_id=? AND plan_id=? ORDER BY feature_code', [$this->platform, $p['id']])->fetchAll(), 'feature_code');
        $people = (int) $this->db->one("SELECT COUNT(*) n FROM users u JOIN tenants t ON t.tenant_id=u.tenant_id AND t.deleted_at IS NULL
            LEFT JOIN person_plans op ON op.tenant_id=u.plan_tenant_id AND op.id=u.plan_id AND op.active=TRUE
            LEFT JOIN person_plans dp ON dp.tenant_id=t.default_plan_tenant_id AND dp.id=t.default_plan_id AND dp.active=TRUE
            WHERE u.status='active' AND COALESCE(op.id, dp.id)=?", [$p['id']])['n'];
        return ['id' => Util::id($p['id']), 'name' => $p['name'], 'badge_label' => $p['badge_label'], 'features' => $features, 'active' => (bool) $p['active'], 'people' => $people, 'version' => (int) $p['version']];
    }
    private function savePlan(?string $id, object $b): array
    {
        $codes = $this->personFeatures($b->features);
        if ($id !== null) {
            $p = $this->db->one('SELECT * FROM person_plans WHERE tenant_id=? AND id=? FOR UPDATE', [$this->platform, $id]);
            if (!$p) { throw new ApiError(404, 'resource_not_found'); }
            if ($this->r->header('if-match') !== '"' . $p['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
            $dup = $this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND name=? AND id<>?', [$this->platform, $b->name, $id]);
            if ($dup) { throw new ApiError(409, 'invalid_transition', [], 'duplicate_name'); }
            $this->db->run('UPDATE person_plans SET name=?,badge_label=?,active=?,version=version+1 WHERE tenant_id=? AND id=?', [$b->name, $b->badge_label, (int) $b->active, $this->platform, $id]);
            $this->db->run('DELETE FROM person_plan_items WHERE tenant_id=? AND plan_id=?', [$this->platform, $id]);
        } else {
            if ($this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND name=?', [$this->platform, $b->name])) { throw new ApiError(409, 'invalid_transition', [], 'duplicate_name'); }
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO person_plans (tenant_id,id,name,badge_label,active) VALUES (?,?,?,?,?)', [$this->platform, $id, $b->name, $b->badge_label, (int) $b->active]);
        }
        foreach ($codes as $code) { $this->db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$this->platform, $id, $code]); }
        // Todo cliente con alguien en este plan (propio o por defecto) recompila sus politicas.
        foreach ($this->db->run('SELECT DISTINCT t.tenant_id FROM tenants t LEFT JOIN users u ON u.tenant_id=t.tenant_id AND u.plan_id=? WHERE t.deleted_at IS NULL AND (t.default_plan_id=? OR u.id IS NOT NULL)', [$id, $id])->fetchAll() as $t) {
            PersonPlans::markRecompile($this->db, $t['tenant_id']);
        }
        $c = $this->c; $c['tenant_id'] = $this->platform; $c['principal_id'] = (new AdminAuth($this->db, new RateLimiter()))->principal($c);
        (new Audit($this->db))->record($c, $this->r, $this->r->operation['operationId'], 'person_plan', $id, ['name', 'features', 'active']);
        return AdminApi::response($this->planDto($this->db->one('SELECT * FROM person_plans WHERE tenant_id=? AND id=?', [$this->platform, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
    private function usage(string $tenant): array
    {
        $default = $this->db->one('SELECT default_plan_id FROM tenants WHERE tenant_id=?', [$tenant])['default_plan_id'];
        $rows = $this->db->run("SELECT COALESCE(op.id, dp.id) plan, COUNT(*) people FROM users u JOIN tenants t ON t.tenant_id=u.tenant_id AND t.deleted_at IS NULL
            LEFT JOIN person_plans op ON op.tenant_id=u.plan_tenant_id AND op.id=u.plan_id AND op.active=TRUE
            LEFT JOIN person_plans dp ON dp.tenant_id=t.default_plan_tenant_id AND dp.id=t.default_plan_id AND dp.active=TRUE
            WHERE u.tenant_id=? AND u.status='active' GROUP BY plan ORDER BY people DESC", [$tenant])->fetchAll();
        return ['default_plan_id' => $default === null ? null : Util::id($default), 'data' => array_map(fn ($r) => ['plan_id' => $r['plan'] === null ? null : Util::id($r['plan']),
            'name' => $r['plan'] === null ? 'Sin plan' : ($this->db->one('SELECT name FROM person_plans WHERE tenant_id=? AND id=?', [$this->platform, $r['plan']])['name'] ?? 'Sin plan'), 'people' => (int) $r['people']], $rows)];
    }
    private function packageDto(array $p): array
    {
        $features = array_column($this->db->run('SELECT feature_code FROM feature_package_items WHERE tenant_id=? AND package_id=? ORDER BY feature_code', [$this->platform, $p['id']])->fetchAll(), 'feature_code');
        $tenants = (int) $this->db->one('SELECT COUNT(*) n FROM tenants WHERE feature_package_id=? AND deleted_at IS NULL', [$p['id']])['n'];
        return ['id' => Util::id($p['id']), 'name' => $p['name'], 'features' => $features, 'tenants' => $tenants, 'version' => (int) $p['version']];
    }
    private function savePackage(?string $id, object $b): array
    {
        $codes = $this->valid($b->features);
        if ($id !== null) {
            $p = $this->db->one('SELECT * FROM feature_packages WHERE tenant_id=? AND id=? FOR UPDATE', [$this->platform, $id]);
            if (!$p) { throw new ApiError(404, 'resource_not_found'); }
            if ($this->r->header('if-match') !== '"' . $p['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
            $this->db->run('UPDATE feature_packages SET name=?,version=version+1 WHERE tenant_id=? AND id=?', [$b->name, $this->platform, $id]);
            $this->db->run('DELETE FROM feature_package_items WHERE tenant_id=? AND package_id=?', [$this->platform, $id]);
        } else {
            if ($this->db->one('SELECT id FROM feature_packages WHERE tenant_id=? AND name=?', [$this->platform, $b->name])) { throw new ApiError(409, 'invalid_transition', [], 'duplicate_name'); }
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO feature_packages (tenant_id,id,name) VALUES (?,?,?)', [$this->platform, $id, $b->name]);
        }
        foreach ($codes as $code) { $this->db->run('INSERT INTO feature_package_items (tenant_id,package_id,feature_code) VALUES (?,?,?)', [$this->platform, $id, $code]); }
        $c = $this->c; $c['tenant_id'] = $this->platform; $c['principal_id'] = (new AdminAuth($this->db, new RateLimiter()))->principal($c);
        (new Audit($this->db))->record($c, $this->r, $this->r->operation['operationId'], 'feature_package', $id, ['name', 'features']);
        return AdminApi::response($this->packageDto($this->db->one('SELECT * FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
    public function tenantDto(string $tenant): array
    {
        $t = $this->db->one('SELECT feature_package_id,default_plan_id FROM tenants WHERE tenant_id=?', [$tenant]);
        $name = $t['feature_package_id'] === null ? null : ($this->db->one('SELECT name FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $t['feature_package_id']])['name'] ?? null);
        return ['package_id' => $t['feature_package_id'] === null ? null : Util::id($t['feature_package_id']), 'package_name' => $name, 'features' => Features::enabled($this->db, $tenant),
            'default_plan_id' => $t['default_plan_id'] === null ? null : Util::id($t['default_plan_id'])];
    }
    /** Funciones del cliente = paquete de origen + ajustes de la venta. Apagar no borra datos. */
    public function apply(string $tenant, ?string $package, array $features): void
    {
        $codes = $this->valid($features);
        if ($package !== null && !$this->db->one('SELECT id FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $package])) { throw new ApiError(422, 'validation_failed', [], 'unknown_package'); }
        $this->db->run('UPDATE tenants SET feature_package_tenant_id=?,feature_package_id=?,auth_version=auth_version+1,version=version+1 WHERE tenant_id=?', [$package === null ? null : $this->platform, $package, $tenant]);
        $this->db->run('DELETE FROM tenant_features WHERE tenant_id=?', [$tenant]);
        foreach ($codes as $code) { $this->db->run('INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [$tenant, $code]); }
    }
    private function putTenant(object $b): array
    {
        if (property_exists($b, 'default_plan_id')) {
            $plan = $b->default_plan_id === null ? null : Util::bin($b->default_plan_id);
            if ($plan !== null && !$this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND id=? AND active=TRUE', [$this->platform, $plan])) { throw new ApiError(422, 'validation_failed', [], 'unknown_plan'); }
            $this->db->run('UPDATE tenants SET default_plan_tenant_id=?,default_plan_id=? WHERE tenant_id=?', [$plan === null ? null : $this->platform, $plan, $this->c['tenant_id']]);
        }
        $this->apply($this->c['tenant_id'], $b->package_id === null ? null : Util::bin($b->package_id), $b->features);
        PersonPlans::markRecompile($this->db, $this->c['tenant_id']);
        (new Audit($this->db))->record($this->c, $this->r, 'putTenantFeatures', 'tenant', $this->c['tenant_id'], property_exists($b, 'default_plan_id') ? ['package_id', 'features', 'default_plan_id'] : ['package_id', 'features']);
        return AdminApi::response($this->tenantDto($this->c['tenant_id']));
    }
}
