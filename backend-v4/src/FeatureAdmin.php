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
                'level' => Features::CATALOG[$code]['level'], 'pending' => Features::CATALOG[$code]['pending']], Features::codes())]),
            'listFeaturePackages' => AdminApi::response(['data' => array_map(fn ($p) => $this->packageDto($p), $this->db->run('SELECT * FROM feature_packages WHERE tenant_id=? ORDER BY name', [$this->platform])->fetchAll())]),
            'createFeaturePackage', 'patchFeaturePackage' => $this->savePackage($id, $b),
            'getTenantFeatures' => AdminApi::response($this->tenantDto($this->c['tenant_id'])),
            'putTenantFeatures' => $this->putTenant($b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }
    /** @param list<string> $codes */
    private function valid(array $codes): array
    {
        foreach ($codes as $code) { if (!isset(Features::CATALOG[$code])) { throw new ApiError(422, 'validation_failed', [], "unknown_feature:$code"); } }
        $codes = array_values(array_unique($codes)); sort($codes); return $codes;
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
        $t = $this->db->one('SELECT feature_package_id FROM tenants WHERE tenant_id=?', [$tenant]);
        $name = $t['feature_package_id'] === null ? null : ($this->db->one('SELECT name FROM feature_packages WHERE tenant_id=? AND id=?', [$this->platform, $t['feature_package_id']])['name'] ?? null);
        return ['package_id' => $t['feature_package_id'] === null ? null : Util::id($t['feature_package_id']), 'package_name' => $name, 'features' => Features::enabled($this->db, $tenant)];
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
        $this->apply($this->c['tenant_id'], $b->package_id === null ? null : Util::bin($b->package_id), $b->features);
        (new Audit($this->db))->record($this->c, $this->r, 'putTenantFeatures', 'tenant', $this->c['tenant_id'], ['package_id', 'features']);
        return AdminApi::response($this->tenantDto($this->c['tenant_id']));
    }
}
