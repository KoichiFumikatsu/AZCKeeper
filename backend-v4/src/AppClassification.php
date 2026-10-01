<?php
declare(strict_types=1);
namespace Keeper;

// Clasificacion de aplicaciones y sitios (decision 2026-10-01). Tres niveles: base de la plataforma (vale para todas),
// la de la empresa (manda sobre la base) y excepciones por area o cargo (mandan sobre la empresa). Cambiar algo marca
// los ultimos 30 dias para recalcular: el cron de productividad los vuelve a calcular con la clasificacion nueva.
final class AppClassification
{
    public const CATEGORIES = ['productive', 'neutral', 'unproductive', 'unclassified'];
    private string $tenant; private string $platform;
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r)
    {
        $this->tenant = $c['tenant_id']; $this->platform = Util::bin(AdminAuth::PLATFORM);
    }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        $this->access->requireFull();
        return match ($op) {
            'getAppClassification' => $this->overview(),
            'putAppClassification' => $this->classify($b),
            'createAppClassificationOverride' => $this->override($b),
            'deleteAppClassificationOverride' => $this->deleteOverride($id),
            'createAppSite' => $this->site($b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    private function overview(): array
    {
        $apps = [];
        $put = function (string $key, array $values) use (&$apps): void { $apps[$key] = ($apps[$key] ?? ['key' => $key, 'label' => null, 'base_category' => null, 'company_category' => null, 'active_seconds_30d' => 0, 'people_30d' => 0, 'overrides' => []]) + []; foreach ($values as $k => $v) { $apps[$key][$k] = $v; } };
        foreach ($this->db->run('SELECT process_name,category,label FROM app_classification WHERE tenant_id=?', [$this->platform])->fetchAll() as $r) { $put($r['process_name'], ['base_category' => $r['category'], 'label' => $r['label']]); }
        foreach ($this->db->run('SELECT process_name,category,label FROM app_classification WHERE tenant_id=?', [$this->tenant])->fetchAll() as $r) { $put($r['process_name'], ['company_category' => $r['category']] + ($r['label'] ? ['label' => $r['label']] : [])); }
        // Uso real de los ultimos 30 dias: lo que la gente usa aparece aunque nadie lo haya clasificado.
        foreach ($this->db->run("SELECT IF(site IS NULL,process_name,CONCAT('site:',site)) k,SUM(active_seconds) a,COUNT(DISTINCT user_id) p FROM episodes WHERE tenant_id=? AND event_date>=UTC_DATE()-INTERVAL 30 DAY GROUP BY k ORDER BY a DESC LIMIT 300", [$this->tenant])->fetchAll() as $r) {
            $put($r['k'], ['active_seconds_30d' => (int) $r['a'], 'people_30d' => (int) $r['p']]);
        }
        foreach ($this->db->run('SELECT * FROM app_classification_overrides WHERE tenant_id=? ORDER BY process_name', [$this->tenant])->fetchAll() as $o) {
            $put($o['process_name'], []);
            $apps[$o['process_name']]['overrides'][] = ['id' => Util::id($o['id']), 'category' => $o['category'], 'area_id' => $o['area_id'] === null ? null : Util::id($o['area_id']), 'position_id' => $o['position_id'] === null ? null : Util::id($o['position_id'])];
        }
        usort($apps, static fn ($a, $b) => $b['active_seconds_30d'] <=> $a['active_seconds_30d'] ?: strcmp($a['key'], $b['key']));
        $sites = array_map(fn ($s) => ['name' => $s['name'], 'pattern' => $s['pattern'], 'origin' => $s['tenant_id'] === $this->platform ? 'base' : 'company'],
            $this->db->run('SELECT * FROM app_sites WHERE tenant_id IN (?,?) AND active=TRUE ORDER BY name', [$this->tenant, $this->platform])->fetchAll());
        return AdminApi::response(['apps' => array_values($apps), 'sites' => $sites]);
    }

    private function dirty(): void
    {
        $this->db->run('INSERT INTO rollup_days (tenant_id,day) SELECT ?,d FROM (SELECT UTC_DATE()-INTERVAL n DAY d FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7 UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11 UNION SELECT 12 UNION SELECT 13 UNION SELECT 14 UNION SELECT 15 UNION SELECT 16 UNION SELECT 17 UNION SELECT 18 UNION SELECT 19 UNION SELECT 20 UNION SELECT 21 UNION SELECT 22 UNION SELECT 23 UNION SELECT 24 UNION SELECT 25 UNION SELECT 26 UNION SELECT 27 UNION SELECT 28 UNION SELECT 29 UNION SELECT 30) x) days ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+1,finalized_at=NULL', [$this->tenant]);
    }
    private function audit(string $type, string $id, array $fields): void { (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], $type, $id, $fields); }

    /** Clasificacion de la empresa para una app o sitio; "unclassified" la quita (vuelve a la base). */
    private function classify(object $b): array
    {
        if ($b->category === 'unclassified') { $this->db->run('DELETE FROM app_classification WHERE tenant_id=? AND process_name=?', [$this->tenant, $b->key]); }
        else {
            $this->db->run('INSERT INTO app_classification (tenant_id,id,process_name,category,label) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE category=VALUES(category),label=COALESCE(VALUES(label),label),version=version+1',
                [$this->tenant, Util::bin(Util::uuid()), $b->key, $b->category, $b->label ?? null]);
        }
        $this->dirty(); $this->audit('app_classification', substr(hash('sha256', $b->key, true), 0, 16), ['key', 'category']);
        return $this->overview();
    }
    private function override(object $b): array
    {
        $area = $b->area_id === null ? null : Util::bin($b->area_id); $position = $b->position_id === null ? null : Util::bin($b->position_id);
        if (($area === null) === ($position === null)) { throw new ApiError(422, 'validation_failed', [], 'one_target'); }
        foreach ([['area', $area], ['position', $position]] as [$kind, $unit]) {
            if ($unit !== null && !$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=?', [$this->tenant, $unit, $kind])) { throw new ApiError(422, 'validation_failed', [], 'unknown_' . $kind); }
        }
        $old = $this->db->one('SELECT id FROM app_classification_overrides WHERE tenant_id=? AND process_name=? AND area_id<=>? AND position_id<=>?', [$this->tenant, $b->key, $area, $position]);
        if ($old) { $this->db->run('UPDATE app_classification_overrides SET category=? WHERE tenant_id=? AND id=?', [$b->category, $this->tenant, $old['id']]); $id = $old['id']; }
        else { $id = Util::bin(Util::uuid()); $this->db->run('INSERT INTO app_classification_overrides (tenant_id,id,process_name,category,area_id,position_id) VALUES (?,?,?,?,?,?)', [$this->tenant, $id, $b->key, $b->category, $area, $position]); }
        $this->dirty(); $this->audit('app_classification_override', $id, ['key', 'category', 'area_id', 'position_id']);
        return $this->overview();
    }
    private function deleteOverride(?string $id): array
    {
        if (!$this->db->one('SELECT id FROM app_classification_overrides WHERE tenant_id=? AND id=?', [$this->tenant, $id])) { throw new ApiError(404, 'resource_not_found'); }
        $this->db->run('DELETE FROM app_classification_overrides WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        $this->dirty(); $this->audit('app_classification_override', $id, ['deleted']);
        return $this->overview();
    }
    /** Sitio propio de la empresa (p. ej. el sistema de gestion de casos en el navegador). */
    private function site(object $b): array
    {
        $this->db->run('INSERT INTO app_sites (tenant_id,id,name,pattern) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE pattern=VALUES(pattern),active=TRUE', [$this->tenant, Util::bin(Util::uuid()), $b->name, $b->pattern]);
        if (isset($b->category) && $b->category !== 'unclassified') {
            $this->db->run('INSERT INTO app_classification (tenant_id,id,process_name,category,label) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE category=VALUES(category),label=VALUES(label)', [$this->tenant, Util::bin(Util::uuid()), 'site:' . $b->name, $b->category, $b->name]);
        }
        $this->audit('app_site', substr(hash('sha256', $b->name, true), 0, 16), ['name', 'pattern']);
        return $this->overview();
    }
}
