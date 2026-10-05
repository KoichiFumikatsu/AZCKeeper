<?php
declare(strict_types=1);
namespace Keeper;

// Grupos empresariales (§8.5): empresas de un mismo dueño. Solo la plataforma los administra.
final class CompanyGroups
{
    public function __construct(private Database $db, private array $c, private Request $r) {}

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        if (!$this->c['platform']) { throw new ApiError(403, 'permission_denied'); }
        return match ($op) {
            'listCompanyGroups' => AdminApi::response(['data' => array_map(fn ($g) => $this->dto($g), $this->db->run('SELECT * FROM company_groups ORDER BY name')->fetchAll())]),
            'createCompanyGroup' => $this->save(null, $b),
            'patchCompanyGroup' => $this->save($id, $b),
            'deleteCompanyGroup' => $this->delete($id),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }
    /** Elimina el grupo: sus empresas quedan independientes. Si hay cuentas de grupo atadas, se rechaza (reasignarlas antes). */
    private function delete(string $id): array
    {
        $g = $this->db->one('SELECT * FROM company_groups WHERE id=? FOR UPDATE', [$id]);
        if (!$g) { throw new ApiError(404, 'resource_not_found'); }
        if ($this->r->header('if-match') !== '"' . $g['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        $accounts = (int) $this->db->one('SELECT COUNT(*) n FROM admin_accounts WHERE group_id=?', [$id])['n'];
        if ($accounts) { throw new ApiError(409, 'invalid_transition', [], "group_has_accounts:$accounts"); }
        $this->db->run('DELETE FROM company_group_members WHERE group_id=?', [$id]);
        $this->db->run('DELETE FROM company_groups WHERE id=?', [$id]);
        (new Audit($this->db))->record($this->c, $this->r, 'deleteCompanyGroup', 'company_group', $id, ['name']);
        return ['status' => 204, 'json' => '', 'headers' => []];
    }
    private function dto(array $g): array
    {
        $ids = array_column($this->db->run('SELECT tenant_id FROM company_group_members WHERE group_id=? ORDER BY tenant_id', [$g['id']])->fetchAll(), 'tenant_id');
        return ['id' => Util::id($g['id']), 'name' => $g['name'], 'tenant_ids' => array_map([Util::class, 'id'], $ids), 'version' => (int) $g['version']];
    }
    private function save(?string $id, object $b): array
    {
        if ($id !== null) {
            $g = $this->db->one('SELECT * FROM company_groups WHERE id=? FOR UPDATE', [$id]);
            if (!$g) { throw new ApiError(404, 'resource_not_found'); }
            if ($this->r->header('if-match') !== '"' . $g['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        }
        $tenants = array_map([Util::class, 'bin'], array_values(array_unique($b->tenant_ids ?? [])));
        foreach ($tenants as $t) {
            if ($t === Util::bin(AdminAuth::PLATFORM) || !$this->db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? AND deleted_at IS NULL', [$t])) { throw new ApiError(422, 'validation_failed', [], 'unknown_company'); }
            $other = $this->db->one('SELECT group_id FROM company_group_members WHERE tenant_id=?', [$t]);
            if ($other && $other['group_id'] !== $id) { throw new ApiError(409, 'invalid_transition', [], 'company_in_other_group'); }
        }
        if ($id === null) {
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO company_groups (id,name) VALUES (?,?)', [$id, $b->name]);
        } else {
            if (isset($b->name)) { $this->db->run('UPDATE company_groups SET name=? WHERE id=?', [$b->name, $id]); }
            $this->db->run('UPDATE company_groups SET version=version+1 WHERE id=?', [$id]);
        }
        if (isset($b->tenant_ids)) {
            $old = array_column($this->db->run('SELECT tenant_id FROM company_group_members WHERE group_id=?', [$id])->fetchAll(), 'tenant_id');
            // Sacar una empresa del grupo quita los roles que las cuentas del grupo tenian alli.
            foreach (array_diff($old, $tenants) as $gone) {
                $this->db->run('DELETE g FROM access_grants g JOIN admin_accounts a ON a.tenant_id=g.admin_tenant_id AND a.id=g.admin_id WHERE a.group_id=? AND g.tenant_id=?', [$id, $gone]);
                $this->db->run('DELETE FROM company_group_members WHERE group_id=? AND tenant_id=?', [$id, $gone]);
            }
            foreach (array_diff($tenants, $old) as $new) { $this->db->run('INSERT INTO company_group_members (group_id,tenant_id) VALUES (?,?)', [$id, $new]); }
        }
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'company_group', $id, array_keys(get_object_vars($b)));
        return AdminApi::response($this->dto($this->db->one('SELECT * FROM company_groups WHERE id=?', [$id])), $this->r->method === 'POST' ? 201 : 200);
    }
}
