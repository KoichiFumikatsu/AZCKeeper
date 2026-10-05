<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Alcance de un acceso (docs/architecture/v4-clientes-funciones-accesos.md §4): que datos ve una persona.
 * all = todo el cliente; filters = Empresa, Ciudad, Sede y Area (lista vacia = «todas»; dentro de un filtro se suman (O),
 * entre filtros se cruzan (Y)); team = el y quienes le reportan; self = solo el (por defecto).
 * Ids en binario; ciudades como el texto de la sede (org_units.city). Un filters sin valores no ve nada.
 */
final class AccessScope
{
    /** @param list<string> $companies @param list<string> $cities @param list<string> $sites @param list<string> $areas */
    public function __construct(public readonly string $kind, public readonly array $companies = [], public readonly array $cities = [], public readonly array $sites = [], public readonly array $areas = []) {}

    public static function all(): self { return new self('all'); }
    public static function own(): self { return new self('self'); }

    /** AccessScope del contrato; las unidades deben existir (no eliminadas) en el cliente y la ciudad, en alguna sede. */
    public static function fromInput(Database $db, string $tenant, object $o): self
    {
        $lists = ['company_ids' => array_values($o->company_ids), 'cities' => array_values($o->cities), 'site_ids' => array_values($o->site_ids), 'area_ids' => array_values($o->area_ids)];
        $any = (bool) array_filter($lists);
        if ($o->kind !== 'filters') {
            if ($any) { throw new ApiError(422, 'validation_failed', [], 'scope_lists_only_for_filters'); }
            return new self($o->kind);
        }
        if (!$any) { throw new ApiError(422, 'validation_failed', [], 'scope_filters_empty'); }
        $units = [];
        foreach (['company_ids' => 'company', 'site_ids' => 'site', 'area_ids' => 'area'] as $key => $kind) {
            $units[$kind] = [];
            foreach ($lists[$key] as $id) {
                $bin = Util::bin($id);
                if (!$db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=? AND deleted_at IS NULL', [$tenant, $bin, $kind])) { throw new ApiError(422, 'validation_failed', [], "unknown_$kind"); }
                if (!in_array($bin, $units[$kind], true)) { $units[$kind][] = $bin; }
            }
        }
        $cities = [];
        foreach ($lists['cities'] as $city) {
            $clean = preg_replace('/\s+/u', ' ', trim($city));
            if (!$db->one("SELECT id FROM org_units WHERE tenant_id=? AND kind='site' AND deleted_at IS NULL AND city=? LIMIT 1", [$tenant, $clean])) { throw new ApiError(422, 'validation_failed', [], 'unknown_city'); }
            if (!in_array(self::cityKey($clean), array_map([self::class, 'cityKey'], $cities), true)) { $cities[] = $clean; }
        }
        return new self('filters', $units['company'], $cities, $units['site'], $units['area']);
    }

    public static function load(Database $db, string $tenant, string $grantId, string $kind): self
    {
        if ($kind !== 'filters') { return new self($kind); }
        $u = ['company' => [], 'site' => [], 'area' => []];
        foreach ($db->run('SELECT unit_id,unit_kind FROM access_grant_units WHERE tenant_id=? AND grant_id=? ORDER BY unit_id', [$tenant, $grantId])->fetchAll() as $r) { $u[$r['unit_kind']][] = $r['unit_id']; }
        $cities = array_column($db->run('SELECT city FROM access_grant_cities WHERE tenant_id=? AND grant_id=? ORDER BY city', [$tenant, $grantId])->fetchAll(), 'city');
        return new self('filters', $u['company'], $cities, $u['site'], $u['area']);
    }

    public function save(Database $db, string $tenant, string $grantId): void
    {
        $db->run('DELETE FROM access_grant_units WHERE tenant_id=? AND grant_id=?', [$tenant, $grantId]);
        $db->run('DELETE FROM access_grant_cities WHERE tenant_id=? AND grant_id=?', [$tenant, $grantId]);
        foreach (['company' => $this->companies, 'site' => $this->sites, 'area' => $this->areas] as $kind => $ids) {
            foreach ($ids as $id) { $db->run('INSERT INTO access_grant_units (tenant_id,grant_id,unit_id,unit_kind) VALUES (?,?,?,?)', [$tenant, $grantId, $id, $kind]); }
        }
        foreach ($this->cities as $city) { $db->run('INSERT INTO access_grant_cities (tenant_id,grant_id,city) VALUES (?,?,?)', [$tenant, $grantId, $city]); }
    }

    public function dto(): array
    {
        $ids = static fn (array $v): array => array_map([Util::class, 'id'], $v);
        return ['kind' => $this->kind, 'company_ids' => $ids($this->companies), 'cities' => $this->cities, 'site_ids' => $ids($this->sites), 'area_ids' => $ids($this->areas)];
    }

    public function isEmptyFilter(): bool { return $this->kind === 'filters' && !$this->companies && !$this->cities && !$this->sites && !$this->areas; }

    /** @return list<string> */
    public function list(string $kind): array
    {
        return match ($kind) { 'company' => $this->companies, 'city' => $this->cities, 'site' => $this->sites, 'area' => $this->areas };
    }

    /** Condicion de filtros sobre una fila con tenant_id, company_id, site_id y area_id (users o user_assignments). */
    public function filterSql(string $alias): array
    {
        if ($this->kind !== 'filters' || $this->isEmptyFilter()) { return ['1=0', []]; }
        $parts = []; $args = [];
        $marks = static fn (array $v): string => implode(',', array_fill(0, count($v), '?'));
        foreach (['company' => $this->companies, 'site' => $this->sites, 'area' => $this->areas] as $kind => $ids) {
            if ($ids) { $parts[] = "$alias.{$kind}_id IN (" . $marks($ids) . ')'; array_push($args, ...$ids); }
        }
        if ($this->cities) {
            $parts[] = "EXISTS (SELECT 1 FROM org_units scope_city WHERE scope_city.tenant_id=$alias.tenant_id AND scope_city.id=$alias.site_id AND scope_city.city IN (" . $marks($this->cities) . '))';
            array_push($args, ...$this->cities);
        }
        return ['(' . implode(' AND ', $parts) . ')', $args];
    }

    /** ¿Una persona ubicada asi (company_id, site_id, area_id binarios o null) cae dentro de este alcance? */
    public function matches(Database $db, string $tenant, array $person): bool
    {
        if ($this->kind === 'all') { return true; }
        if ($this->kind !== 'filters' || $this->isEmptyFilter()) { return false; }
        foreach (['company' => $this->companies, 'site' => $this->sites, 'area' => $this->areas] as $kind => $ids) {
            if ($ids && !in_array($person[$kind . '_id'] ?? null, $ids, true)) { return false; }
        }
        if ($this->cities) {
            if (($person['site_id'] ?? null) === null) { return false; }
            $marks = implode(',', array_fill(0, count($this->cities), '?'));
            return (bool) $db->one("SELECT id FROM org_units WHERE tenant_id=? AND id=? AND city IN ($marks)", [$tenant, $person['site_id'], ...$this->cities]);
        }
        return true;
    }

    /** Delegacion: ¿este alcance cubre al otro? Cada filtro que este limita, el otro tambien lo limita, a un subconjunto. */
    public function covers(self $child): bool
    {
        if ($this->kind === 'all') { return true; }
        if ($this->kind !== 'filters' || $child->kind !== 'filters' || $this->isEmptyFilter() || $child->isEmptyFilter()) { return false; }
        foreach ([[$this->companies, $child->companies], [$this->sites, $child->sites], [$this->areas, $child->areas]] as [$mine, $theirs]) {
            if ($mine && (!$theirs || array_diff($theirs, $mine))) { return false; }
        }
        if ($this->cities) {
            $mine = array_map([self::class, 'cityKey'], $this->cities); $theirs = array_map([self::class, 'cityKey'], $child->cities);
            if (!$theirs || array_diff($theirs, $mine)) { return false; }
        }
        return true;
    }

    /** Ciudad comparable en PHP como la compara la base (sin mayusculas ni tildes). */
    public static function cityKey(string $city): string
    {
        return strtr(mb_strtolower(preg_replace('/\s+/u', ' ', trim($city))), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
