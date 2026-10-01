<?php
declare(strict_types=1);
namespace Keeper;

// Puertas de la empresa (spec de integraciones §7): cada referencia del sistema de acceso (p. ej. el OID de uhppoted)
// se asocia a una sede. Solo las puertas de acceso al edificio cuentan para la presencia.
final class DoorsApi
{
    private string $tenant;
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) { $this->tenant = $c['tenant_id']; }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        $this->access->requireFull();
        return match ($op) {
            'listDoors' => AdminApi::response(['data' => array_map(fn ($d) => $this->dto($d), $this->db->run('SELECT * FROM doors WHERE tenant_id=? ORDER BY name', [$this->tenant])->fetchAll())]),
            'createDoor' => $this->save(null, $b),
            'patchDoor' => $this->save($id, $b),
            'getDoorQuarantine' => $this->quarantine(),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }
    private function dto(array $d): array
    {
        return ['id' => Util::id($d['id']), 'name' => $d['name'], 'source_ref' => $d['source_ref'], 'site_id' => Util::id($d['site_id']), 'active' => (bool) $d['active'], 'counts_for_presence' => (bool) $d['counts_for_presence']];
    }
    private function save(?string $id, object $b): array
    {
        $old = $id === null ? null : $this->db->one('SELECT * FROM doors WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant, $id]);
        if ($id !== null && !$old) { throw new ApiError(404, 'resource_not_found'); }
        $site = isset($b->site_id) ? Util::bin($b->site_id) : $old['site_id'];
        if (!$this->db->one("SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind='site'", [$this->tenant, $site])) { throw new ApiError(422, 'validation_failed', [], 'unknown_site'); }
        $values = ['name' => $b->name ?? $old['name'], 'site_id' => $site, 'active' => (int) ($b->active ?? $old['active'] ?? true), 'counts_for_presence' => (int) ($b->counts_for_presence ?? $old['counts_for_presence'] ?? true)];
        if ($old && $old['site_id'] !== $site && $this->db->one('SELECT id FROM check_ins WHERE tenant_id=? AND door_id=? LIMIT 1', [$this->tenant, $id])) {
            throw new ApiError(409, 'invalid_transition', [], 'door_has_history');
        }
        if ($id === null) {
            if ($this->db->one('SELECT id FROM doors WHERE tenant_id=? AND source_ref=?', [$this->tenant, $b->source_ref])) { throw new ApiError(409, 'invalid_transition', [], 'door_exists'); }
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO doors (tenant_id,id,site_id,name,source_ref,active,counts_for_presence) VALUES (?,?,?,?,?,?,?)', [$this->tenant, $id, $site, $values['name'], $b->source_ref, $values['active'], $values['counts_for_presence']]);
        } else {
            $this->db->run('UPDATE doors SET name=?,site_id=?,active=?,counts_for_presence=? WHERE tenant_id=? AND id=?', [...array_values($values), $this->tenant, $id]);
        }
        (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'door', $id, array_keys(get_object_vars($b)));
        // Las entradas que esperaban esta puerta se emparejan ahora.
        (new DoorEvents($this->db))->rematch($this->tenant);
        return AdminApi::response($this->dto($this->db->one('SELECT * FROM doors WHERE tenant_id=? AND id=?', [$this->tenant, $id])), $this->r->method === 'POST' ? 201 : 200);
    }
    private function quarantine(): array
    {
        $doors = array_map(static fn ($r) => ['door_ref' => $r['door_ref'], 'events' => (int) $r['n'], 'last_at' => Util::time($r['last_at'])],
            $this->db->run("SELECT door_ref,COUNT(*) n,MAX(occurred_at) last_at FROM unmatched_check_ins WHERE tenant_id=? AND reason='unknown_door' GROUP BY door_ref ORDER BY n DESC LIMIT 200", [$this->tenant])->fetchAll());
        $people = array_map(static fn ($r) => ['document_hint' => '…' . substr($r['document'], -4), 'events' => (int) $r['n'], 'last_at' => Util::time($r['last_at'])],
            $this->db->run("SELECT document,COUNT(*) n,MAX(occurred_at) last_at FROM unmatched_check_ins WHERE tenant_id=? AND reason='unknown_person' GROUP BY document ORDER BY n DESC LIMIT 200", [$this->tenant])->fetchAll());
        return AdminApi::response(['unknown_doors' => $doors, 'unknown_people' => $people]);
    }
}
