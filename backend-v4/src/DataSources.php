<?php
declare(strict_types=1);
namespace Keeper;

// Fuentes de datos de una empresa: configuracion, dueño por dominio, vueltas de sincronizacion y pendientes (§3, §10).
final class DataSources
{
    public const DOMAINS = ['empresas', 'firmas','sedes', 'areas', 'cargos', 'horarios', 'festivos', 'personas', 'jerarquia', 'ausencias', 'entradas'];
    private string $tenant;
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) { $this->tenant = $c['tenant_id']; }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        $this->access->requireFull();
        return match ($op) {
            'listDataSources' => AdminApi::response(['data' => array_map(fn ($s) => $this->dto($s), $this->db->run('SELECT * FROM data_sources WHERE tenant_id=? ORDER BY created_at', [$this->tenant])->fetchAll())]),
            'createDataSource' => $this->create($b),
            'patchDataSource' => $this->patch($this->find($id), $b),
            'deleteDataSource' => $this->delete($this->find($id)),
            'getDataDomains' => $this->domains(),
            'putDataDomains' => $this->putDomains($b),
            'runDataSource' => $this->runNow($this->find($id), $b),
            'listSyncRuns' => $this->runs(),
            'listSyncItems' => $this->items($id),
            'approveSyncRun' => $this->approve($id),
            'listPendingSyncItems' => $this->pending(),
            'resolveSyncItem' => $this->resolve($id, $b),
            'resolveMatchedSyncItems' => $this->resolveMatched($b),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    /** Fuente duena de un dominio en una empresa (null = Keeper). */
    public static function owner(Database $db, string $tenant, string $domain): ?array
    {
        return $db->one('SELECT s.* FROM data_domain_owners o JOIN data_sources s ON s.tenant_id=o.tenant_id AND s.id=o.source_id WHERE o.tenant_id=? AND o.domain=?', [$tenant, $domain]);
    }
    /** Rechaza crear o editar algo de un dominio con dueño externo (§5.3). $entity null = crear. */
    public static function guardWrite(Database $db, string $tenant, string $domain, ?string $entityType = null, ?string $entity = null): void
    {
        $owner = self::owner($db, $tenant, $domain);
        if ($owner === null) { return; }
        if ($entity !== null && !$db->one('SELECT 1 FROM entity_links WHERE tenant_id=? AND entity_type=? AND entity_id=? AND source_id=?', [$tenant, $entityType, $entity, $owner['id']])) { return; }
        throw new ApiError(409, 'invalid_transition', [], 'managed_by_source');
    }
    /** Nombre de la fuente que gestiona una persona (vinculada a la duena de "personas"), o null. */
    public static function managedBy(Database $db, string $tenant, string $user): ?string
    {
        return $db->one("SELECT s.name FROM data_domain_owners o JOIN data_sources s ON s.tenant_id=o.tenant_id AND s.id=o.source_id JOIN entity_links l ON l.tenant_id=o.tenant_id AND l.source_id=s.id AND l.entity_type='user' AND l.entity_id=? WHERE o.tenant_id=? AND o.domain='personas'", [$user, $tenant])['name'] ?? null;
    }

    private function find(?string $id): array
    {
        $s = $this->db->one('SELECT * FROM data_sources WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant, $id]);
        if (!$s) { throw new ApiError(404, 'resource_not_found'); }
        return $s;
    }
    private function audit(string $op, string $type, string $id, array $fields): void { (new Audit($this->db))->record($this->c, $this->r, $op, $type, $id, $fields); }

    private function dto(array $s): array
    {
        $config = DataSync::open($s['config_ciphertext']);
        $domains = array_column($this->db->run('SELECT domain FROM data_domain_owners WHERE tenant_id=? AND source_id=? ORDER BY domain', [$this->tenant, $s['id']])->fetchAll(), 'domain');
        $t = static fn (?string $v): ?string => $v === null ? null : Util::time($v);
        return ['id' => Util::id($s['id']), 'name' => $s['name'], 'kind' => $s['kind'], 'base_url' => $config['base_url'] ?? null, 'company' => $config['company'] ?? null,
            'door_events_from_date' => $s['door_events_from_date'],
            'has_token' => ($config['token'] ?? '') !== '', 'interval_minutes' => $s['interval_minutes'] === null ? null : (int) $s['interval_minutes'],
            'full_reconcile_local' => substr($s['full_reconcile_local'], 0, 5), 'mode' => $s['mode'], 'approval_required' => (bool) $s['approval_required'], 'active' => (bool) $s['active'],
            'max_deactivations' => (int) $s['max_deactivations'], 'max_deactivation_percent' => (int) $s['max_deactivation_percent'], 'min_list_percent' => (int) $s['min_list_percent'],
            'domains' => $domains, 'last_run_at' => $t($s['last_run_at']), 'last_success_at' => $t($s['last_success_at']), 'last_full_at' => $t($s['last_full_at']), 'version' => (int) $s['version']];
    }
    private static function url(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST)) { throw new ApiError(422, 'validation_failed', [], 'https_required'); }
        return $url;
    }
    private function create(object $b): array
    {
        $id = Util::bin(Util::uuid());
        $config = ['base_url' => self::url($b->base_url), 'company' => $b->company ?? null, 'token' => $b->token ?? ''];
        $this->db->run("INSERT INTO data_sources (tenant_id,id,name,kind,config_ciphertext,interval_minutes,full_reconcile_local,mode) VALUES (?,?,?,'rest',?,?,?,'simulate')",
            [$this->tenant, $id, $b->name, DataSync::seal($config), $b->interval_minutes ?? 10, ($b->full_reconcile_local ?? '02:00') . ':00']);
        if (isset($b->door_events_from_date)) { $this->db->run('UPDATE data_sources SET door_events_from_date=? WHERE tenant_id=? AND id=?', [$b->door_events_from_date, $this->tenant, $id]); }
        $this->audit('createDataSource', 'data_source', $id, ['name', 'base_url', 'company', 'token', ...(isset($b->door_events_from_date) ? ['door_events_from_date'] : [])]);
        return AdminApi::response($this->dto($this->find($id)), 201);
    }
    private function patch(array $s, object $b): array
    {
        if ($this->r->header('if-match') !== '"' . $s['version'] . '"') { throw new ApiError(412, 'version_conflict'); }
        $config = DataSync::open($s['config_ciphertext']);
        if (isset($b->base_url)) { $config['base_url'] = self::url($b->base_url); }
        if (property_exists($b, 'company')) { $config['company'] = $b->company; }
        if (isset($b->token)) { $config['token'] = $b->token; }
        $set = ['config_ciphertext' => DataSync::seal($config)];
        foreach (['name', 'interval_minutes', 'mode', 'max_deactivations', 'max_deactivation_percent', 'min_list_percent', 'door_events_from_date'] as $f) { if (property_exists($b, $f)) { $set[$f] = $b->$f; } }
        // Una fecha anterior explicitamente configurada permite releer el historial, deduplicado por fuente/evento.
        if (isset($b->door_events_from_date) && $s['door_events_from_date'] !== null && $b->door_events_from_date < $s['door_events_from_date']) {
            $this->db->run('UPDATE data_source_door_cursors SET last_log_id=0 WHERE tenant_id=? AND source_id=?', [$this->tenant, $s['id']]);
        }
        if (isset($b->active)) { $set['active'] = (int) $b->active; }
        if (isset($b->full_reconcile_local)) { $set['full_reconcile_local'] = $b->full_reconcile_local . ':00'; }
        // Pasar a "real" exige haber revisado al menos una simulacion completa (§5.5).
        if (($set['mode'] ?? null) === 'live' && $s['mode'] === 'simulate' && !$this->db->one("SELECT id FROM sync_runs WHERE tenant_id=? AND source_id=? AND kind='full' AND status IN ('simulated','applied','blocked') LIMIT 1", [$this->tenant, $s['id']])) {
            throw new ApiError(409, 'invalid_transition', [], 'simulation_required');
        }
        $cols = implode(',', array_map(static fn ($k) => "$k=?", array_keys($set)));
        $this->db->run("UPDATE data_sources SET $cols,version=version+1 WHERE tenant_id=? AND id=?", [...array_values($set), $this->tenant, $s['id']]);
        $this->audit('patchDataSource', 'data_source', $s['id'], array_keys(get_object_vars($b)));
        return AdminApi::response($this->dto($this->find($s['id'])));
    }
    private function delete(array $s): array
    {
        if ($s['mode'] !== 'simulate' || $s['last_success_at'] !== null || $this->db->one('SELECT 1 FROM data_domain_owners WHERE tenant_id=? AND source_id=?', [$this->tenant, $s['id']])) {
            throw new ApiError(409, 'invalid_transition', [], 'source_in_use');
        }
        $this->db->run('DELETE i FROM sync_items i JOIN sync_runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id WHERE i.tenant_id=? AND r.source_id=?', [$this->tenant, $s['id']]);
        $this->db->run('DELETE FROM sync_runs WHERE tenant_id=? AND source_id=?', [$this->tenant, $s['id']]);
        $this->db->run('DELETE FROM entity_links WHERE tenant_id=? AND source_id=?', [$this->tenant, $s['id']]);
        $this->db->run('DELETE FROM data_sources WHERE tenant_id=? AND id=?', [$this->tenant, $s['id']]);
        $this->audit('deleteDataSource', 'data_source', $s['id'], []);
        return ['status' => 204, 'json' => '', 'headers' => []];
    }
    private function domains(): array
    {
        $owners = [];
        foreach ($this->db->run('SELECT domain,source_id FROM data_domain_owners WHERE tenant_id=?', [$this->tenant])->fetchAll() as $o) { $owners[$o['domain']] = Util::id($o['source_id']); }
        return AdminApi::response(['data' => array_map(static fn ($d) => ['domain' => $d, 'source_id' => $owners[$d] ?? null], self::DOMAINS)]);
    }
    private function putDomains(object $b): array
    {
        $seen = [];
        foreach ($b->domains as $d) {
            if (isset($seen[$d->domain])) { throw new ApiError(422, 'validation_failed'); } $seen[$d->domain] = true;
            if ($d->source_id !== null && !$this->db->one('SELECT id FROM data_sources WHERE tenant_id=? AND id=?', [$this->tenant, Util::bin($d->source_id)])) { throw new ApiError(422, 'validation_failed', [], 'unknown_source'); }
        }
        $gained = [];
        foreach ($b->domains as $d) {
            if ($d->source_id === null) { $this->db->run('DELETE FROM data_domain_owners WHERE tenant_id=? AND domain=?', [$this->tenant, $d->domain]); continue; }
            $source = Util::bin($d->source_id);
            if ($d->domain === 'entradas') {
                $today = (new \DateTimeImmutable('today', new \DateTimeZone($this->c['timezone'])))->format('Y-m-d');
                $this->db->run('UPDATE data_sources SET door_events_from_date=COALESCE(door_events_from_date,?),version=version+1 WHERE tenant_id=? AND id=?', [$today, $this->tenant, $source]);
                $this->db->run('INSERT IGNORE INTO data_source_door_cursors (tenant_id,source_id) VALUES (?,?)', [$this->tenant, $source]);
            } elseif (!$this->db->one('SELECT 1 FROM data_domain_owners WHERE tenant_id=? AND domain=? AND source_id=?', [$this->tenant, $d->domain, $source])) { $gained[$source] = true; }
            $this->db->run('INSERT INTO data_domain_owners (tenant_id,domain,source_id) VALUES (?,?,?) ON DUPLICATE KEY UPDATE source_id=VALUES(source_id)', [$this->tenant, $d->domain, $source]);
        }
        // Una fuente que gana un dominio (I4): su siguiente vuelta es completa (sin updated_since de antes, que dejaria la
        // carga a medias) y, en real, queda frenada (domains_changed) hasta que alguien la apruebe. El cron nunca aplica
        // solo la primera carga de un dominio. Quitar un dominio no pide nada.
        foreach (array_keys($gained) as $source) {
            $this->db->run('UPDATE data_sources SET approval_required=TRUE,updated_since=NULL,version=version+1 WHERE tenant_id=? AND id=?', [$this->tenant, $source]);
        }
        $this->audit('putDataDomains', 'data_domains', $this->tenant, array_keys($seen));
        return $this->domains();
    }
    private function runDto(array $r): array
    {
        $t = static fn (?string $v): ?string => $v === null ? null : Util::time($v);
        $pending = (int) $this->db->one("SELECT COUNT(*) n FROM sync_items WHERE tenant_id=? AND run_id=? AND action='pending' AND resolution IS NULL", [$this->tenant, $r['id']])['n'];
        return ['id' => Util::id($r['id']), 'source_id' => Util::id($r['source_id']), 'kind' => $r['kind'], 'trigger' => $r['trigger_kind'], 'simulate' => (bool) $r['simulate'],
            'status' => $r['status'], 'started_at' => $t($r['started_at']), 'finished_at' => $t($r['finished_at']), 'counts' => json_decode((string) ($r['counts'] ?? '{}'), false) ?? new \stdClass(),
            'blocked_reason' => $r['blocked_reason'], 'error_code' => $r['error_code'], 'pending' => $pending];
    }
    private function runNow(array $s, object $b): array
    {
        if (!$s['active']) { throw new ApiError(409, 'invalid_transition', [], 'source_inactive'); }
        $id = (new DataSync($this->db))->run($this->tenant, $s['id'], $b->kind, 'manual', false, ($b->simulate ?? false) ? true : null);
        $this->audit('runDataSource', 'sync_run', $id, ['kind', 'simulate']);
        return AdminApi::response($this->runDto($this->db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$this->tenant, $id])), 201);
    }
    private function runs(): array
    {
        $args = [$this->tenant]; $where = '';
        if (isset($_GET['source_id'])) { $where = ' AND source_id=?'; $args[] = Util::bin($_GET['source_id']); }
        return AdminApi::response(['data' => array_map(fn ($r) => $this->runDto($r), $this->db->run("SELECT * FROM sync_runs WHERE tenant_id=?$where ORDER BY started_at DESC LIMIT 50", $args)->fetchAll())]);
    }
    private function itemDto(array $i): array
    {
        $type = json_decode((string) ($i['payload'] ?? ''), true)['type'] ?? null;
        $table = ['org_unit' => 'org_units', 'schedule' => 'schedules'][$type ?? ''] ?? null;
        $localName = $table === null ? null : ($this->db->one("SELECT name FROM $table WHERE tenant_id=? AND id=?", [$this->tenant, $i['entity_id']])['name'] ?? null);
        return ['id' => Util::id($i['id']), 'run_id' => Util::id($i['run_id']), 'domain' => $i['domain'], 'external_id' => $i['external_id'], 'entity_id' => $i['entity_id'] === null ? null : Util::id($i['entity_id']),
            'entity_type' => $type, 'local_name' => $localName,
            'action' => $i['action'], 'reason' => $i['reason'], 'label' => $i['label'], 'fields' => $i['fields'] === null ? null : json_decode($i['fields'], false),
            'resolution' => $i['resolution'], 'resolved_at' => $i['resolved_at'] === null ? null : Util::time($i['resolved_at'])];
    }
    private function items(?string $run): array
    {
        if (!$this->db->one('SELECT id FROM sync_runs WHERE tenant_id=? AND id=?', [$this->tenant, $run])) { throw new ApiError(404, 'resource_not_found'); }
        return AdminApi::response(['data' => array_map(fn ($i) => $this->itemDto($i), $this->db->run("SELECT * FROM sync_items WHERE tenant_id=? AND run_id=? ORDER BY FIELD(action,'error','pending','deactivate','create','update','link'),domain LIMIT 2000", [$this->tenant, $run])->fetchAll())]);
    }
    private function approve(?string $run): array
    {
        $r = $this->db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant, $run]);
        if (!$r) { throw new ApiError(404, 'resource_not_found'); }
        if ($r['status'] !== 'blocked') { throw new ApiError(409, 'invalid_transition', [], 'not_blocked'); }
        // Se vuelve a leer la fuente (pudo cambiar) y se aplica sin frenos; queda auditado quién lo aprobó.
        $id = (new DataSync($this->db))->run($this->tenant, $r['source_id'], $r['kind'], 'approval', true, false);
        $this->audit('approveSyncRun', 'sync_run', $id, ['approved_from']);
        return AdminApi::response($this->runDto($this->db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$this->tenant, $id])), 201);
    }
    private function pending(): array
    {
        $rows = $this->db->run("SELECT i.* FROM sync_items i JOIN sync_runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id WHERE i.tenant_id=? AND i.action='pending' AND i.resolution IS NULL AND r.status='applied' ORDER BY r.started_at DESC LIMIT 500", [$this->tenant])->fetchAll();
        return AdminApi::response(['data' => array_map(fn ($i) => $this->itemDto($i), $rows)]);
    }
    private function resolve(?string $id, object $b): array
    {
        $i = $this->db->one("SELECT i.* FROM sync_items i JOIN sync_runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id WHERE i.tenant_id=? AND i.id=? AND i.action='pending' AND r.status='applied' FOR UPDATE", [$this->tenant, $id]);
        if (!$i) { throw new ApiError(404, 'resource_not_found'); }
        if ($i['resolution'] !== null) { throw new ApiError(409, 'invalid_transition', [], 'already_resolved'); }
        (new DataSync($this->db))->resolve($this->tenant, $i, $b->approve);
        $this->audit('resolveSyncItem', 'sync_item', $i['id'], ['approve']);
        return AdminApi::response($this->itemDto($this->db->one('SELECT * FROM sync_items WHERE tenant_id=? AND id=?', [$this->tenant, $i['id']])));
    }
    private function resolveMatched(object $b): array
    {
        $ids = array_map(static fn ($id) => Util::bin($id), $b->ids);
        if (count(array_unique($ids)) !== count($ids)) { throw new ApiError(422, 'validation_failed'); }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->run("SELECT i.* FROM sync_items i JOIN sync_runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id WHERE i.tenant_id=? AND i.id IN ($marks) AND i.action='pending' AND r.status='applied' ORDER BY i.id FOR UPDATE", [$this->tenant, ...$ids])->fetchAll();
        if (count($rows) !== count($ids)) { throw new ApiError(404, 'resource_not_found'); }
        foreach ($rows as $i) {
            $type = json_decode((string) $i['payload'], true)['type'] ?? null;
            if ($i['resolution'] !== null || $i['reason'] !== 'matched_name' || !in_array($type, ['org_unit', 'schedule'], true)) {
                throw new ApiError(409, 'invalid_transition', [], 'sync_batch_not_eligible');
            }
        }
        // Application envuelve toda la petición y su auditoría en una única transacción.
        $sync = new DataSync($this->db);
        foreach ($rows as $i) {
            $sync->resolve($this->tenant, $i, true);
            $this->audit('resolveMatchedSyncItems', 'sync_item', $i['id'], ['approve']);
        }
        return AdminApi::response(['resolved' => count($rows)]);
    }
}
