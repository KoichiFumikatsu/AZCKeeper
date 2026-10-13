<?php
declare(strict_types=1);
namespace Keeper;

// Motor de sincronizacion con fuentes externas (docs/architecture/v4-integraciones-fuentes-de-datos.md §5).
// Lee la nomina en formato canonico keeper.roster.v1, la empareja con lo que hay (vinculo externo, cedula o nombre),
// calcula y aplica los cambios SOLO en los dominios de los que la fuente es duena, y frena retiros masivos o listas
// incompletas. Simular o bloquear = mismo calculo dentro de un SAVEPOINT que se revierte: lo que se muestra es
// exactamente lo que se aplicaria. Nada de lo que falle en la fuente se interpreta como "todos se fueron".
final class DataSync
{
    public const SCHEMA = 'keeper.roster.v1';
    private const ORG_DOMAINS = ['company' => 'empresas', 'firm' => 'firmas', 'site' => 'sedes', 'area' => 'areas', 'position' => 'cargos'];
    private const ENDPOINTS = ['org-units' => ['empresas', 'firmas', 'sedes', 'areas', 'cargos'], 'schedules' => ['horarios'], 'holidays' => ['festivos'], 'people' => ['personas', 'jerarquia'], 'absences' => ['ausencias']];
    // Referencias de una persona: campo canonico y dominio del que sale (§4.5).
    private const PERSON_REFS = ['company' => ['company_external_id', 'empresas'], 'firm' => ['firm_external_id', 'firmas'], 'site' => ['site_external_id', 'sedes'],
        'area' => ['area_external_id', 'areas'], 'position' => ['position_external_id', 'cargos'], 'schedule' => ['schedule_external_id', 'horarios']];
    private const MAX_PAGES = 500;

    private string $tenant; private string $source; private array $owned = []; private bool $calendars = false;
    private ?string $onlyUser = null;
    private array $items = []; private array $counts = []; private int $baseline = 0; private int $incomingActive = 0;
    /** @var callable(array $source, string $endpoint, ?string $since, ?string $cursor): array */
    private $fetch;

    public function __construct(private Database $db, ?callable $fetch = null)
    {
        $this->fetch = $fetch ?? [self::class, 'restFetch'];
    }

    // ---- Configuracion cifrada (URL, empresa, token): nunca se devuelve por la API --------------------------------
    public static function seal(array $config): string
    {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt(Util::json($config), 'aes-256-gcm', Config::key(), OPENSSL_RAW_DATA, $iv, $tag, 'keeper.data_source', 16);
        return $iv . $tag . $cipher;
    }
    public static function open(string $sealed): array
    {
        $plain = openssl_decrypt(substr($sealed, 28), 'aes-256-gcm', Config::key(), OPENSSL_RAW_DATA, substr($sealed, 0, 12), substr($sealed, 12, 16), 'keeper.data_source');
        if ($plain === false) { throw new \RuntimeException('data source config unreadable'); }
        return json_decode($plain, true, 8, JSON_THROW_ON_ERROR);
    }

    // ---- Conector REST canonico ----------------------------------------------------------------------------------
    public static function restFetch(array $source, string $endpoint, ?string $since, ?string $cursor): array
    {
        $config = self::open($source['config_ciphertext']);
        $query = array_filter(['empresa' => $config['company'] ?? null, 'updated_since' => $since, 'cursor' => $cursor], static fn ($v) => $v !== null && $v !== '');
        $url = rtrim($config['base_url'], '/') . '/' . $endpoint . ($query ? '?' . http_build_query($query) : '');
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') { throw new SyncFailure('insecure_url'); }
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_MAXFILESIZE => 50 * 1024 * 1024,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . ($config['token'] ?? ''), 'User-Agent: AZCKeeper-sync/1']]);
        $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
        if ($body === false) { throw new SyncFailure('source_unreachable'); }
        if ($status !== 200) { throw new SyncFailure('source_http_' . $status); }
        try { return json_decode($body, true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new SyncFailure('source_invalid_json'); }
    }

    // ---- Vuelta ----------------------------------------------------------------------------------------------------
    /** Ejecuta una vuelta y devuelve el id de sync_runs. $kind: full|incremental. */
    public function run(string $tenant, string $sourceId, string $kind, string $trigger, bool $override = false, ?bool $simulate = null, ?string $onlyUser = null): string
    {
        $this->onlyUser = $onlyUser;
        $this->tenant = $tenant; $this->source = $sourceId; $this->items = []; $this->counts = []; $this->calendars = false;
        $source = $this->db->one('SELECT * FROM data_sources WHERE tenant_id=? AND id=?', [$tenant, $sourceId]);
        if (!$source) { throw new ApiError(404, 'resource_not_found'); }
        $this->owned = array_column($this->db->run('SELECT domain FROM data_domain_owners WHERE tenant_id=? AND source_id=?', [$tenant, $sourceId])->fetchAll(), 'domain');
        $simulate ??= $source['mode'] === 'simulate';
        if ($kind === 'incremental' && $source['updated_since'] === null) { $kind = 'full'; }
        if ($onlyUser !== null) { $kind = 'incremental'; }
        $runId = Util::bin(Util::uuid()); $started = $this->now();
        $status = 'applied'; $blocked = null; $error = null; $generated = null;
        try {
            $data = [];
            foreach (self::ENDPOINTS as $endpoint => $domains) {
                if ($onlyUser !== null && $endpoint !== 'people') { continue; }
                if ($onlyUser === null && !array_intersect($domains, $this->owned)) { continue; }
                [$data[$endpoint], $gen] = $this->pages($source, $endpoint, $onlyUser === null && $kind === 'incremental' ? $source['updated_since'] : null, $onlyUser !== null || $kind === 'full');
                $generated = $generated === null || $gen < $generated ? $gen : $generated;
            }
            if ($onlyUser !== null) {
                $external=$this->db->one("SELECT external_id FROM entity_links WHERE tenant_id=? AND source_id=? AND entity_type='user' AND entity_id=?",[$tenant,$sourceId,$onlyUser])['external_id']??null;
                $data['people']=array_values(array_filter($data['people']??[],static fn($p)=>($p['external_id']??null)===$external && $external!==null));
                if (count($data['people'])!==1) { throw new SyncFailure('source_person_not_found'); }
                if (!in_array('personas',$this->owned,true)) {
                    $local=$this->db->one('SELECT display_name,email,status FROM users WHERE tenant_id=? AND id=?',[$tenant,$onlyUser]);
                    $data['people'][0]=array_replace($data['people'][0],$local,['document'=>null]);
                }
            }
            // Personas vinculadas y activas ANTES de la vuelta: base de los frenos.
            $this->baseline = (int) $this->db->one("SELECT COUNT(*) n FROM entity_links l JOIN users u ON u.tenant_id=l.tenant_id AND u.id=l.entity_id WHERE l.tenant_id=? AND l.source_id=? AND l.entity_type='user' AND u.status='active'", [$tenant, $sourceId])['n'];
            $this->incomingActive = 0;
            $this->db->run('SAVEPOINT keeper_sync');
            try {
                $this->apply($data, $kind === 'full');
                if ($onlyUser !== null && array_filter($this->items,static fn($i)=>in_array($i['action'],['error','pending'],true))) { throw new SyncFailure('person_requires_review'); }
                $blocked = $override ? null : $this->guards($source, $kind === 'full', $simulate);
            } catch (\Throwable $e) { $this->db->run('ROLLBACK TO SAVEPOINT keeper_sync'); throw $e; }
            if ($blocked !== null || $simulate) { $this->db->run('ROLLBACK TO SAVEPOINT keeper_sync'); $status = $blocked !== null ? 'blocked' : 'simulated'; }
            else { $this->db->run('RELEASE SAVEPOINT keeper_sync'); if ($this->changed()) { $this->markDirty(); } }
        } catch (SyncFailure $e) { $status = 'failed'; $error = $e->getMessage(); $this->items = []; }
        $genSql = $generated === null ? null : $generated->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
        $this->db->run('INSERT INTO sync_runs (tenant_id,id,source_id,kind,trigger_kind,simulate,status,started_at,finished_at,counts,blocked_reason,error_code,generated_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),?,?,?,?)',
            [$tenant, $runId, $sourceId, $kind, $trigger, (int) $simulate, $status, $started, Util::json((object) $this->counts), $blocked, $error, $genSql]);
        foreach ($this->items as $i) {
            $this->db->run('INSERT INTO sync_items (tenant_id,id,run_id,domain,external_id,entity_id,action,reason,label,fields,payload) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$tenant, Util::bin(Util::uuid()), $runId, $i['domain'], $i['external_id'], $i['entity_id'], $i['action'], $i['reason'], mb_substr((string) $i['label'], 0, 200), $i['fields'] === null ? null : Util::json($i['fields']), $i['payload'] === null ? null : Util::json($i['payload'])]);
        }
        // Una vuelta aplicada (con dominios nuevos solo puede serlo aprobada) cumple la aprobacion pendiente.
        if ($onlyUser === null) $this->db->run('UPDATE data_sources SET last_run_at=UTC_TIMESTAMP(6)' . ($status === 'applied' ? ',last_success_at=UTC_TIMESTAMP(6),approval_required=FALSE,updated_since=COALESCE(?,updated_since)' . ($kind === 'full' ? ',last_full_at=UTC_TIMESTAMP(6)' : '') : '') . ' WHERE tenant_id=? AND id=?',
            $status === 'applied' ? [$genSql, $tenant, $sourceId] : [$tenant, $sourceId]);
        return $runId;
    }

    private function now(): string { return $this->db->one('SELECT UTC_TIMESTAMP(6) t')['t']; }

    /** Recorre todas las paginas; una lista completa exige complete=true y el mismo generated_at en todas. */
    private function pages(array $source, string $endpoint, ?string $since, bool $full): array
    {
        $items = []; $cursor = null; $generated = null;
        for ($n = 0; $n < self::MAX_PAGES; $n++) {
            $page = ($this->fetch)($source, $endpoint, $since === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($since . ' UTC')), $cursor);
            if (!is_array($page) || ($page['schema'] ?? null) !== self::SCHEMA || !is_array($page['items'] ?? null) || !is_string($page['generated_at'] ?? null)) { throw new SyncFailure('invalid_envelope'); }
            try { $at = new \DateTimeImmutable($page['generated_at']); } catch (\Exception) { throw new SyncFailure('invalid_envelope'); }
            if ($generated !== null && $full && $at != $generated) { throw new SyncFailure('inconsistent_pages'); }
            $generated ??= $at;
            if ($full && ($page['complete'] ?? false) !== true) { throw new SyncFailure('incomplete_list'); }
            array_push($items, ...$page['items']);
            $cursor = $page['next_cursor'] ?? null;
            if ($cursor === null) { return [$items, $generated]; }
            if (!is_string($cursor) || strlen($cursor) > 1024) { throw new SyncFailure('invalid_envelope'); }
        }
        throw new SyncFailure('too_many_pages');
    }

    private function apply(array $data, bool $full): void
    {
        if (isset($data['org-units'])) { $this->orgUnits($data['org-units'], $full); }
        if (isset($data['schedules']) && in_array('horarios', $this->owned, true)) { $this->schedules($data['schedules']); }
        if (isset($data['holidays']) && in_array('festivos', $this->owned, true)) { $this->holidays($data['holidays'], $full); }
        if (isset($data['people']) && ($this->onlyUser!==null || in_array('personas', $this->owned, true))) { $this->people($data['people'], $full); }
        if (isset($data['people']) && in_array('jerarquia', $this->owned, true)) { $this->hierarchy($data['people']); }
        // Cargos y jefes pudieron cambiar: los roles puestos por reglas se recalculan (dentro del mismo SAVEPOINT).
        if (isset($data['people'])) { [$added, $removed] = RoleRules::apply($this->db, $this->tenant, null, $this->onlyUser===null?null:fn(string $id):bool=>$id===$this->onlyUser); if ($added + $removed) { $this->counts['roles']['update'] = $added + $removed; } }
        if (isset($data['absences']) && in_array('ausencias', $this->owned, true)) { $this->absences($data['absences']); }
        // Una sede cambio de pais o una persona de sede: cambia que festivos le tocan (como al editar desde el panel).
        if ($this->calendars) { HolidayCalendars::refresh($this->db, $this->tenant); }
    }

    // ---- Ausencias de RR. HH. (eventos con id; una cancelada se reenvia con status cancelled) ----------------------------
    private function absences(array $items): void
    {
        $days = [];
        foreach ($items as $it) {
            $external = self::ext($it['external_id'] ?? null);
            $user = is_string($it['person_external_id'] ?? null) ? $this->linked('user', $it['person_external_id']) : null;
            $kind = in_array($it['kind'] ?? null, Absences::KINDS, true) ? $it['kind'] : null;
            $status = in_array($it['status'] ?? null, ['approved', 'cancelled'], true) ? $it['status'] : null;
            try { $from = new \DateTimeImmutable((string) ($it['starts_at'] ?? '')); $to = new \DateTimeImmutable((string) ($it['ends_at'] ?? '')); } catch (\Exception) { $from = $to = null; }
            if ($external === null || $kind === null || $status === null || $from === null || $to <= $from) { $this->log('ausencias', 'error', $external, null, 'invalid_item'); continue; }
            if ($user === null) { $this->log('ausencias', 'error', $external, null, 'unknown_reference'); continue; }
            $utc = new \DateTimeZone('UTC'); $s = $from->setTimezone($utc)->format('Y-m-d H:i:s.u'); $e = $to->setTimezone($utc)->format('Y-m-d H:i:s.u');
            $id = $this->linked('absence', $external);
            $label = (string) $this->db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $user])['display_name'];
            if ($id === null) {
                if ($status === 'cancelled') { continue; }
                $id = Util::bin(Util::uuid());
                $this->db->run('INSERT INTO absences (tenant_id,id,user_id,kind,starts_at,ends_at,all_day,status) VALUES (?,?,?,?,?,?,?,?)', [$this->tenant, $id, $user, $kind, $s, $e, (int) (($it['all_day'] ?? true) !== false), $status]);
                $this->link('absence', $id, $external, 'created'); $this->log('ausencias', 'create', $external, $id, $kind, $label);
            } else {
                $old = $this->db->one('SELECT * FROM absences WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
                if ($old['user_id'] === $user && $old['kind'] === $kind && $old['status'] === $status && substr($old['starts_at'], 0, 19) === substr($s, 0, 19) && substr($old['ends_at'], 0, 19) === substr($e, 0, 19)) { continue; }
                $days = array_merge($days, Absences::days($this->db, $this->tenant, $old['user_id'], $old['starts_at'], $old['ends_at']));
                $this->db->run('UPDATE absences SET user_id=?,kind=?,starts_at=?,ends_at=?,status=?,version=version+1 WHERE tenant_id=? AND id=?', [$user, $kind, $s, $e, $status, $this->tenant, $id]);
                $this->log('ausencias', $status === 'cancelled' ? 'deactivate' : 'update', $external, $id, $kind, $label);
            }
            $days = array_merge($days, Absences::days($this->db, $this->tenant, $user, $s, $e));
        }
        if ($days) { ActivityCalendar::refresh($this->db, $this->tenant, array_slice(array_values(array_unique($days)), 0, 400)); }
    }

    // ---- Jerarquia: a quien reporta cada persona (§2.1) -------------------------------------------------------------
    private function hierarchy(array $items): void
    {
        foreach ($items as $it) {
            $external = self::ext($it['external_id'] ?? null); if ($external === null || ($it['status'] ?? null) !== 'active') { continue; }
            $id = $this->linked('user', $external); if ($id === null) { continue; }
            $boss = is_string($it['reports_to_external_id'] ?? null) ? $this->linked('user', $it['reports_to_external_id']) : null;
            if (is_string($it['reports_to_external_id'] ?? null) && $boss === null) { $this->log('jerarquia', 'error', $external, $id, 'unknown_reference'); continue; }
            $u = $this->db->one('SELECT display_name,reports_to_user_id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            if ($u['reports_to_user_id'] === $boss) { continue; }
            for ($m = $boss, $n = 0; $m !== null && $n < 50; $n++) {
                if ($m === $id) { $this->log('jerarquia', 'error', $external, $id, 'hierarchy_cycle', $u['display_name']); continue 2; }
                $m = $this->db->one('SELECT reports_to_user_id r FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $m])['r'] ?? null;
            }
            $this->db->run('UPDATE users SET reports_to_user_id=?,version=version+1 WHERE tenant_id=? AND id=?', [$boss, $this->tenant, $id]);
            $this->log('jerarquia', 'update', $external, $id, null, $u['display_name']);
        }
    }

    // ---- Registro --------------------------------------------------------------------------------------------------
    private function log(string $domain, string $action, ?string $external, ?string $entity, ?string $reason = null, ?string $label = null, ?array $fields = null, ?array $payload = null): void
    {
        $this->items[] = ['domain' => $domain, 'action' => $action, 'external_id' => $external === null ? null : mb_substr($external, 0, 160), 'entity_id' => $entity, 'reason' => $reason, 'label' => $label, 'fields' => $fields, 'payload' => $payload];
        $this->counts[$domain][$action] = ($this->counts[$domain][$action] ?? 0) + 1;
    }
    /**
     * Lo que ya se pregunto en una vuelta aplicada: 'open' (sin responder) o 'rejected'. Evita repetir el mismo pendiente
     * cada 10 minutos y respeta un "no" (p. ej. "no es la misma sede", "dejarla como local").
     */
    private function asked(string $reason, ?string $entity, ?string $external, ?string $document = null): ?string
    {
        $row = $this->db->one("SELECT i.resolution FROM sync_items i JOIN sync_runs r ON r.tenant_id=i.tenant_id AND r.id=i.run_id WHERE i.tenant_id=? AND r.source_id=? AND r.status='applied' AND i.action='pending' AND i.reason=? AND i.entity_id<=>? AND i.external_id<=>? AND (i.resolution IS NULL OR i.resolution='rejected')"
            . ($document === null ? '' : " AND JSON_UNQUOTE(JSON_EXTRACT(i.payload,'$.document'))=?") . ' ORDER BY i.resolution IS NULL DESC LIMIT 1',
            $document === null ? [$this->tenant, $this->source, $reason, $entity, $external] : [$this->tenant, $this->source, $reason, $entity, $external, $document]);
        return $row === null ? null : ($row['resolution'] === null ? 'open' : 'rejected');
    }
    private function changed(): bool
    {
        foreach ($this->counts as $c) { if (($c['create'] ?? 0) + ($c['update'] ?? 0) + ($c['deactivate'] ?? 0) + ($c['link'] ?? 0) > 0) { return true; } }
        return false;
    }
    private function markDirty(): void
    {
        $this->db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)', [$this->tenant]);
    }

    // ---- Vinculos ---------------------------------------------------------------------------------------------------
    private function linked(string $type, string $external): ?string
    {
        return $this->db->one('SELECT entity_id FROM entity_links WHERE tenant_id=? AND source_id=? AND entity_type=? AND external_id=?', [$this->tenant, $this->source, $type, $external])['entity_id'] ?? null;
    }
    private function link(string $type, string $entity, string $external, string $method): void
    {
        $this->db->run('INSERT INTO entity_links (tenant_id,entity_type,entity_id,source_id,external_id,link_method) VALUES (?,?,?,?,?,?)', [$this->tenant, $type, $entity, $this->source, $external, $method]);
    }
    private function linkedIds(string $type): array
    {
        $out = [];
        foreach ($this->db->run('SELECT entity_id,external_id FROM entity_links WHERE tenant_id=? AND source_id=? AND entity_type=?', [$this->tenant, $this->source, $type])->fetchAll() as $r) { $out[$r['external_id']] = $r['entity_id']; }
        return $out;
    }
    public static function fold(string $v): string
    {
        $v = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)), 'UTF-8');
        return strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u']);
    }
    private static function ext(mixed $v): ?string { return is_string($v) && $v !== '' && strlen($v) <= 160 ? $v : null; }
    private static function hm(mixed $v): ?string { return is_string($v) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v) ? $v . ':00' : null; }

    // ---- Unidades organizacionales ------------------------------------------------------------------------------------
    private function orgUnits(array $items, bool $full): void
    {
        $byKind = [];
        foreach ($items as $it) {
            $kind = is_array($it) ? ($it['kind'] ?? null) : null; $domain = self::ORG_DOMAINS[$kind] ?? null;
            if ($domain === null || !in_array($domain, $this->owned, true)) { continue; }
            $byKind[$kind][] = $it;
        }
        foreach (['company', 'firm', 'site', 'position', 'area'] as $kind) {
            $seen = [];
            foreach ($byKind[$kind] ?? [] as $it) { if (is_string($it['external_id'] ?? null)) { $seen[$it['external_id']] = true; } }
            // Las areas se procesan padres primero; lo que no resuelve su padre cuando ya nada avanza es error.
            $pending = $byKind[$kind] ?? [];
            while ($pending) {
                $next = [];
                foreach ($pending as $it) { if ($this->orgUnit($kind, $it, $kind !== 'area') === 'retry') { $next[] = $it; } }
                if (count($next) === count($pending)) { foreach ($next as $it) { $this->orgUnit($kind, $it, true); } break; }
                $pending = $next;
            }
            if ($full && in_array(self::ORG_DOMAINS[$kind], $this->owned, true)) {
                foreach ($this->linkedIds('org_unit') as $external => $id) {
                    $unit = $this->db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
                    if ($unit && $unit['kind'] === $kind && $unit['active'] && !isset($seen[$external])) { $this->deactivateOrg($unit, (string) $external); }
                }
            }
        }
    }
    private function orgUnit(string $kind, array $it, bool $final): string
    {
        $domain = self::ORG_DOMAINS[$kind]; $external = self::ext($it['external_id'] ?? null);
        $name = is_string($it['name'] ?? null) ? trim($it['name']) : '';
        $geo = $kind === 'site' ? self::geo($it) : [];
        if ($external === null || $name === '' || mb_strlen($name) > 120 || !is_bool($it['active'] ?? null) || $geo === null) { $this->log($domain, 'error', $external, null, 'invalid_item', $name ?: null); return 'done'; }
        $parent = null;
        if ($kind === 'area' && ($it['parent_external_id'] ?? null) !== null) {
            $parent = $this->linked('org_unit', (string) $it['parent_external_id']);
            if ($parent === null) { if (!$final) { return 'retry'; } $this->log($domain, 'error', $external, null, 'unknown_reference', $name); return 'done'; }
        }
        $id = $this->linked('org_unit', $external);
        if ($id === null) {
            if (!$it['active']) { return 'done'; }
            $candidate = null;
            foreach ($this->db->run('SELECT o.id,o.name FROM org_units o WHERE o.tenant_id=? AND o.kind=? AND o.deleted_at IS NULL AND o.placeholder=FALSE AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id=o.tenant_id AND l.entity_type=\'org_unit\' AND l.entity_id=o.id AND l.source_id=?)', [$this->tenant, $kind, $this->source])->fetchAll() as $o) {
                if (self::fold($o['name']) === self::fold($name) && $this->asked('matched_name', $o['id'], $external) !== 'rejected') { $candidate = $o['id']; break; }
            }
            if ($candidate !== null) {
                // Mismo nombre que una unidad local: no se adivina, se pide confirmacion (§5.2). Un "no es la misma" crea una aparte.
                if ($this->asked('matched_name', $candidate, $external) === null) { $this->log($domain, 'pending', $external, $candidate, 'matched_name', $name, null, ['type' => 'org_unit', 'item' => $it, 'kind' => $kind]); }
                return 'done';
            }
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO org_units (tenant_id,id,kind,name,parent_id,active,country_code,city) VALUES (?,?,?,?,?,TRUE,?,?)', [$this->tenant, $id, $kind, $name, $parent, $geo['country_code'] ?? null, $geo['city'] ?? null]);
            $this->link('org_unit', $id, $external, 'created');
            $this->log($domain, 'create', $external, $id, null, $name);
            return 'done';
        }
        $unit = $this->db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        if (!$it['active']) { if ($unit['active']) { $this->deactivateOrg($unit, $external); } return 'done'; }
        $changes = [];
        if ($unit['name'] !== $name) { $changes['name'] = [$unit['name'], $name]; }
        if ($kind === 'area' && $unit['parent_id'] !== $parent) { $changes['parent'] = ['', '']; }
        if (!$unit['active']) { $changes['active'] = [false, true]; }
        // Pais y ciudad: un campo que no viene se conserva; null lo borra.
        $geo += ['country_code' => $unit['country_code'], 'city' => $unit['city']];
        foreach ($geo as $f => $v) { if ($unit[$f] !== $v) { $changes[$f] = [$unit[$f], $v]; } }
        if ($changes) {
            $this->db->run('UPDATE org_units SET name=?,parent_id=?,active=TRUE,country_code=?,city=?,version=version+1 WHERE tenant_id=? AND id=?', [$name, $kind === 'area' ? $parent : $unit['parent_id'], $geo['country_code'], $geo['city'], $this->tenant, $id]);
            $this->log($domain, 'update', $external, $id, null, $name, $changes);
            if (isset($changes['country_code'])) { $this->calendars = true; }
        }
        return 'done';
    }
    /** Pais (ISO 3166-1 alfa-2) y ciudad de una sede. Solo los campos que vienen; null = dato invalido. */
    private static function geo(array $it): ?array
    {
        $out = [];
        if (array_key_exists('country_code', $it)) {
            $c = $it['country_code'];
            if ($c !== null && (!is_string($c) || !preg_match('/^[A-Za-z]{2}$/', $c))) { return null; }
            $out['country_code'] = $c === null ? null : strtoupper($c);
        }
        if (array_key_exists('city', $it)) {
            $c = $it['city'];
            if ($c !== null && !is_string($c)) { return null; }
            $c = $c === null ? '' : trim((string) preg_replace('/\s+/u', ' ', $c));
            if (mb_strlen($c) > 80) { return null; }
            $out['city'] = $c === '' ? null : $c;
        }
        return $out;
    }
    private function deactivateOrg(array $unit, string $external): void
    {
        $domain = self::ORG_DOMAINS[$unit['kind']]; $id = $unit['id'];
        $inUse = $this->db->one("SELECT id FROM users WHERE tenant_id=? AND status='active' AND (firm_id=? OR site_id=? OR area_id=? OR position_id=? OR company_id=?) LIMIT 1", [$this->tenant, $id, $id, $id, $id, $id])
            || $this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND parent_id=? AND active=TRUE LIMIT 1', [$this->tenant, $id]);
        if ($inUse) { if ($this->asked('deactivate_in_use', $id, $external) === null) { $this->log($domain, 'pending', $external, $id, 'deactivate_in_use', $unit['name'], null, ['type' => 'org_deactivate']); } return; }
        $this->db->run('UPDATE org_units SET active=FALSE,version=version+1 WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        $this->log($domain, 'deactivate', $external, $id, null, $unit['name']);
    }

    // ---- Horarios -----------------------------------------------------------------------------------------------------
    private function schedules(array $items): void
    {
        foreach ($items as $it) {
            $external = self::ext($it['external_id'] ?? null); $name = is_string($it['name'] ?? null) ? trim($it['name']) : '';
            $days = []; $valid = $external !== null && $name !== '' && mb_strlen($name) <= 120 && is_array($it['days'] ?? null) && is_string($it['timezone'] ?? null);
            if ($valid) { try { new \DateTimeZone($it['timezone']); } catch (\Exception) { $valid = false; } }
            foreach ($valid ? $it['days'] : [] as $d) {
                $w = $d['weekday'] ?? null; $s = self::hm($d['start_local'] ?? null); $e = self::hm($d['end_local'] ?? null);
                $ls = isset($d['lunch']) ? self::hm($d['lunch']['start_local'] ?? null) : null; $le = isset($d['lunch']) ? self::hm($d['lunch']['end_local'] ?? null) : null;
                if (!is_int($w) || $w < 1 || $w > 7 || isset($days[$w]) || $s === null || $e === null || ($ls === null) !== ($le === null) || ($ls !== null && $ls >= $le)) { $valid = false; break; }
                $days[$w] = [$s, $e, $ls, $le];
            }
            if (!$valid || !$days) { $this->log('horarios', 'error', $external, null, 'invalid_item', $name ?: null); continue; }
            if (($it['active'] ?? true) === false) { continue; }  // Keeper no desactiva horarios: dejan de asignarse.
            ksort($days);
            // Franja general = la mas comun; los dias distintos quedan con franja propia (§4.3).
            $freq = []; foreach ($days as $v) { $k = implode('|', $v); $freq[$k] = ($freq[$k] ?? 0) + 1; }
            arsort($freq); $default = explode('|', (string) array_key_first($freq)); $default = array_map(static fn ($x) => $x === '' ? null : $x, $default);
            $id = $this->linked('schedule', $external);
            if ($id === null) {
                foreach ($this->db->run('SELECT s.id,s.name FROM schedules s WHERE s.tenant_id=? AND s.placeholder=FALSE AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id=s.tenant_id AND l.entity_type=\'schedule\' AND l.entity_id=s.id AND l.source_id=?)', [$this->tenant, $this->source])->fetchAll() as $s) {
                    if (self::fold($s['name']) !== self::fold($name)) { continue; }
                    $state = $this->asked('matched_name', $s['id'], $external);
                    if ($state === 'rejected') { continue; }
                    if ($state === null) { $this->log('horarios', 'pending', $external, $s['id'], 'matched_name', $name, null, ['type' => 'schedule', 'item' => $it]); }
                    continue 2;
                }
                $id = Util::bin(Util::uuid());
                $this->db->run('INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local,lunch_start_local,lunch_end_local) VALUES (?,?,?,?,?,?,?,?)', [$this->tenant, $id, $name, $it['timezone'], ...$default]);
                $this->writeDays($id, $days, $default);
                $this->link('schedule', $id, $external, 'created');
                $this->log('horarios', 'create', $external, $id, null, $name);
                continue;
            }
            $old = $this->db->one('SELECT * FROM schedules WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            $oldDays = [];
            foreach ($this->db->run('SELECT * FROM schedule_days WHERE tenant_id=? AND schedule_id=? ORDER BY weekday', [$this->tenant, $id])->fetchAll() as $d) {
                $oldDays[(int) $d['weekday']] = $d['start_local'] === null ? [$old['start_local'], $old['end_local'], $old['lunch_start_local'], $old['lunch_end_local']] : [$d['start_local'], $d['end_local'], $d['lunch_start_local'], $d['lunch_end_local']];
            }
            if ($old['name'] !== $name || $old['timezone'] !== $it['timezone'] || $oldDays != $days) {
                $this->db->run('UPDATE schedules SET name=?,timezone=?,start_local=?,end_local=?,lunch_start_local=?,lunch_end_local=?,version=version+1 WHERE tenant_id=? AND id=?', [$name, $it['timezone'], ...$default, ...[$this->tenant, $id]]);
                $this->db->run('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=?', [$this->tenant, $id]);
                $this->writeDays($id, $days, $default);
                $this->log('horarios', 'update', $external, $id, null, $name);
            }
        }
    }
    private function writeDays(string $id, array $days, array $default): void
    {
        foreach ($days as $w => $v) {
            $own = $v == $default ? [null, null, null, null] : $v;
            $this->db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday,start_local,end_local,lunch_start_local,lunch_end_local) VALUES (?,?,?,?,?,?,?)', [$this->tenant, $id, $w, ...$own]);
        }
    }

    // ---- Festivos -------------------------------------------------------------------------------------------------------
    private function holidays(array $items, bool $full): void
    {
        $seen = []; $before = count($this->items);
        foreach ($items as $it) {
            $external = self::ext($it['external_id'] ?? null); $name = is_string($it['name'] ?? null) ? trim($it['name']) : '';
            $day = is_string($it['day'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $it['day']) ? $it['day'] : null;
            if ($external === null || $day === null || $name === '' || mb_strlen($name) > 160) { $this->log('festivos', 'error', $external, null, 'invalid_item', $name ?: null); continue; }
            $seen[$external] = true; $active = ($it['active'] ?? true) !== false;
            $id = $this->linked('holiday', $external);
            if ($id === null) {
                if (!$active) { continue; }
                $same = $this->db->one('SELECT h.id FROM holidays h WHERE h.tenant_id=? AND h.day=? AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id=h.tenant_id AND l.entity_type=\'holiday\' AND l.entity_id=h.id AND l.source_id=?) LIMIT 1', [$this->tenant, $day, $this->source]);
                if ($same) { $this->link('holiday', $same['id'], $external, 'matched_name'); $this->log('festivos', 'link', $external, $same['id'], null, "$day $name"); continue; }
                $id = Util::bin(Util::uuid());
                $this->db->run('INSERT INTO holidays (tenant_id,id,day,name) VALUES (?,?,?,?)', [$this->tenant, $id, $day, $name]);
                $this->link('holiday', $id, $external, 'created'); $this->log('festivos', 'create', $external, $id, null, "$day $name");
                continue;
            }
            $h = $this->db->one('SELECT * FROM holidays WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            if (!$h) { continue; }
            if (!$active) { $this->removeHoliday($id, $external, $h); continue; }
            if ($h['day'] !== $day || $h['name'] !== $name) {
                $this->db->run('UPDATE holidays SET day=?,name=? WHERE tenant_id=? AND id=?', [$day, $name, $this->tenant, $id]);
                $this->log('festivos', 'update', $external, $id, null, "$day $name");
            }
        }
        if ($full) {
            foreach ($this->linkedIds('holiday') as $external => $id) {
                if (!isset($seen[$external]) && ($h = $this->db->one('SELECT * FROM holidays WHERE tenant_id=? AND id=?', [$this->tenant, $id]))) { $this->removeHoliday($id, (string) $external, $h); }
            }
        }
        // Un festivo nuevo, movido o quitado cambia el tiempo esperado de ese dia en los resumenes ya calculados.
        $days = [];
        foreach (array_slice($this->items, $before) as $i) { if (in_array($i['action'], ['create', 'update', 'deactivate'], true) && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $i['label'], $m)) { $days[] = $m[0]; } }
        if ($days) { ActivityCalendar::refresh($this->db, $this->tenant, $days); }
    }
    private function removeHoliday(string $id, string $external, array $h): void
    {
        $this->db->run('DELETE FROM entity_links WHERE tenant_id=? AND entity_type=\'holiday\' AND entity_id=?', [$this->tenant, $id]);
        $this->db->run('DELETE FROM holidays WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        $this->log('festivos', 'deactivate', $external, null, null, $h['day'] . ' ' . $h['name']);
    }

    // ---- Personas -----------------------------------------------------------------------------------------------------
    private function people(array $items, bool $full): void
    {
        $seen = [];
        foreach ($items as $it) {
            $external = self::ext($it['external_id'] ?? null);
            $name = is_string($it['display_name'] ?? null) ? trim($it['display_name']) : '';
            $status = $it['status'] ?? null;
            $email = $it['email'] ?? null;
            if ($external === null || $name === '' || mb_strlen($name) > 160 || !in_array($status, ['active', 'inactive'], true)
                || ($email !== null && (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false))) {
                $this->log('personas', 'error', $external, null, 'invalid_item', $name ?: null); continue;
            }
            $seen[$external] = true;
            if ($status === 'active') { $this->incomingActive++; }
            $document = is_string($it['document'] ?? null) ? DeviceIntake::document($it['document']) : null;
            if ($document !== null && (strlen($document) < 3 || strlen($document) > 40)) { $document = null; }
            // Cada persona en su propio SAVEPOINT: un choque inesperado con un UNIQUE la deja como error y la vuelta sigue.
            $mark = count($this->items);
            $this->db->run('SAVEPOINT keeper_person');
            try { $this->person($it, $external, $name, $email, $status, $document); $this->db->run('RELEASE SAVEPOINT keeper_person'); }
            catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
                $this->db->run('ROLLBACK TO SAVEPOINT keeper_person');
                $this->unlog($mark);
                $this->log('personas', 'error', $external, null, 'duplicate_value', $name);
            }
        }
        if ($full) {
            // Vinculadas que la fuente ya no trae: retiro. Locales activas sin vinculo: huerfanas, a revision (nunca solas).
            foreach ($this->linkedIds('user') as $external => $id) {
                if (isset($seen[$external])) { continue; }
                $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
                if ($u && $u['status'] === 'active') { $this->deactivatePerson($u, (string) $external, 'absent_from_source'); }
            }
            foreach ($this->db->run("SELECT u.id,u.display_name FROM users u WHERE u.tenant_id=? AND u.status='active' AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id=u.tenant_id AND l.entity_type='user' AND l.entity_id=u.id AND l.source_id=?)", [$this->tenant, $this->source])->fetchAll() as $u) {
                if ($this->asked('orphan', $u['id'], null) === null) { $this->log('personas', 'pending', null, $u['id'], 'orphan', $u['display_name'], null, ['type' => 'orphan']); }
            }
        }
    }
    private function person(array $it, string $external, string $name, ?string $email, string $status, ?string $document): void
    {
        $id = $this->linked('user', $external);
        if ($id !== null) {
            $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            $refs = $status === 'active' ? $this->personRefs($it, $u) : [];
            if (is_string($refs)) { $this->log('personas', 'error', $external, $id, $refs, $name); return; }
            $this->updatePerson($id, $external, $name, $email, $status, $refs, $document);
            return;
        }
        // Una persona retirada en la fuente tambien entra (como retirada): historial completo y cedula/vinculo listos si
        // vuelve. Referencias con tolerancia (lo que no resuelve va a "Sin ..."; sin sede -> "Sin sede"), sin plan, y
        // cuenta como alta para el freno too_many_creations. Solo la fuente la reactiva (status active).
        $retired = $status === 'inactive';
        $match = $document === null ? [] : $this->db->run("SELECT DISTINCT r.user_id FROM user_external_refs r WHERE r.tenant_id=? AND r.source IN ('document','k3:cc') AND r.external_ref=? AND NOT EXISTS (SELECT 1 FROM entity_links l WHERE l.tenant_id=r.tenant_id AND l.entity_type='user' AND l.entity_id=r.user_id AND l.source_id=?)", [$this->tenant, $document, $this->source])->fetchAll();
        if (count($match) > 1) { $this->log('personas', 'error', $external, null, 'document_ambiguous', $name); return; }
        if (!$match && $document !== null && $this->db->one("SELECT user_id FROM user_external_refs WHERE tenant_id=? AND source IN ('document','k3:cc') AND external_ref=?", [$this->tenant, $document])) {
            $this->log('personas', 'error', $external, null, 'document_in_use', $name); return;
        }
        $current = $match ? $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $match[0]['user_id']]) : null;
        $refs = $this->personRefs($it, $current, $retired);
        if (is_string($refs)) { $this->log('personas', 'error', $external, null, $refs, $name); return; }
        if ($match) {
            $id = $match[0]['user_id'];
            $this->link('user', $id, $external, 'matched_document');
            $this->log('personas', 'link', $external, $id, 'matched_document', $name);
            $this->updatePerson($id, $external, $name, $email, $status, $refs, $document);
            return;
        }
        // Una retirada nunca le quita el correo a nadie: si ya esta en uso, entra sin correo.
        if ($retired) { if ($email !== null && $this->db->one('SELECT id FROM users WHERE tenant_id=? AND email=?', [$this->tenant, $email])) { $email = null; } }
        elseif (!$this->claimEmail($email, null)) { $this->log('personas', 'error', $external, null, 'email_in_use', $name); return; }
        $id = $this->createPerson($name, $email, $refs, $document, $status);
        $this->link('user', $id, $external, 'created');
        $this->log('personas', 'create', $external, $id, $retired ? 'inactive_in_source' : null, $name);
    }
    /**
     * Ubicacion de la persona (columnas de users) o el codigo de error (K1/K2). Un id enviado que no resuelve a una unidad
     * de ese tipo es unknown_reference. Sin id: empresa vacia, "Sin firma/área/cargo/horario"; la sede es obligatoria.
     * De un dominio que la fuente no gestiona se conserva lo que la persona ya tiene en Keeper.
     */
    private function personRefs(array $it, ?array $current, bool $lenient = false): array|string
    {
        $refs = [];
        foreach (self::PERSON_REFS as $kind => [$field, $domain]) {
            $col = $kind . '_id'; $value = $it[$field] ?? null;
            if ($value !== null && self::ext($value) === null) { return 'invalid_reference'; }
            $owned = in_array($domain, $this->owned, true);
            if (!$owned && $current !== null) { $refs[$col] = $current[$col]; continue; }
            $id = $value === null ? null : $this->ref($kind, $value);
            // $lenient (alta de una retirada): lo que no resuelve va al placeholder, tambien la sede ("Sin sede").
            if ($id === null && $value !== null && $owned && !$lenient) { return 'unknown_reference'; }
            if ($id === null && $kind === 'site' && !$lenient) { return 'missing_site'; }
            $refs[$col] = $id ?? $this->placeholder($kind);
        }
        return $refs;
    }
    private function ref(string $kind, string $external): ?string
    {
        if ($kind === 'schedule') { return $this->linked('schedule', $external); }
        return $this->db->one("SELECT l.entity_id FROM entity_links l JOIN org_units o ON o.tenant_id=l.tenant_id AND o.id=l.entity_id WHERE l.tenant_id=? AND l.source_id=? AND l.entity_type='org_unit' AND l.external_id=? AND o.kind=?", [$this->tenant, $this->source, $external, $kind])['entity_id'] ?? null;
    }
    private function placeholder(string $kind): ?string
    {
        return match ($kind) {
            'company' => null,
            'schedule' => Placeholders::schedule($this->db, $this->tenant, $this->db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$this->tenant])['timezone'] ?? 'UTC'),
            default => Placeholders::unit($this->db, $this->tenant, $kind),
        };
    }
    /**
     * El correo es unico por empresa (incluye a las retiradas). Si lo usa otra persona ACTIVA, esta queda como error (K4)
     * y la vuelta sigue. Si solo lo conserva una persona retirada, se le quita (email NULL, update `email_released`) y lo
     * toma la activa: un correo reasignado tras un retiro no queda atascado para siempre. Devuelve false = ocupado.
     */
    private function claimEmail(?string $email, ?string $user): bool
    {
        if ($email === null) { return true; }
        $holders = $this->db->run('SELECT id,status,display_name,email FROM users WHERE tenant_id=? AND email=?' . ($user === null ? '' : ' AND id<>?'), $user === null ? [$this->tenant, $email] : [$this->tenant, $email, $user])->fetchAll();
        if ($this->onlyUser !== null && $holders) { return false; }
        foreach ($holders as $h) { if ($h['status'] === 'active') { return false; } }
        foreach ($holders as $h) {
            $this->db->run('UPDATE users SET email=NULL,version=version+1 WHERE tenant_id=? AND id=?', [$this->tenant, $h['id']]);
            $ext = $this->db->one("SELECT external_id FROM entity_links WHERE tenant_id=? AND source_id=? AND entity_type='user' AND entity_id=?", [$this->tenant, $this->source, $h['id']])['external_id'] ?? null;
            $this->log('personas', 'update', $ext, $h['id'], 'email_released', $h['display_name'], ['email' => [$h['email'], null]]);
        }
        return true;
    }
    /** Quita del registro lo anotado desde $mark (la persona se revirtio). */
    private function unlog(int $mark): void
    {
        foreach (array_splice($this->items, $mark) as $i) {
            if (--$this->counts[$i['domain']][$i['action']] === 0) { unset($this->counts[$i['domain']][$i['action']]); }
            if ($this->counts[$i['domain']] === []) { unset($this->counts[$i['domain']]); }
        }
    }
    private function createPerson(string $name, ?string $email, array $refs, ?string $document, string $status = 'active'): string
    {
        $id = Util::bin(Util::uuid()); $at = $this->now();
        $this->db->run('INSERT INTO users (tenant_id,id,display_name,email,status,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$this->tenant, $id, $name, $email, $status, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id']]);
        if ($status==='inactive') { $this->db->run("UPDATE users SET employment_status='retirado' WHERE tenant_id=? AND id=?",[$this->tenant,$id]); }
        $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$this->tenant, Util::bin(Util::uuid()), $id, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id'], $at]);
        AccessGrants::grantDefault($this->db, $this->tenant, $id);
        if ($document !== null) { $this->db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)", [$this->tenant, $id, $document]); }
        return $id;
    }
    private function updatePerson(string $id, string $external, string $name, ?string $email, string $status, array $refs, ?string $document): void
    {
        $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        if ($status === 'inactive') { if ($u['status'] === 'active') { $this->deactivatePerson($u, $external, 'inactive_in_source'); } return; }
        if ($u['email'] !== $email && !$this->claimEmail($email, $id)) { $this->log('personas', 'error', $external, $id, 'email_in_use', $name); return; }
        $changes = [];
        if ($u['display_name'] !== $name) { $changes['display_name'] = [$u['display_name'], $name]; }
        if ($u['email'] !== $email) { $changes['email'] = [$u['email'], $email]; }
        if ($u['status'] !== 'active') { $changes['status'] = ['inactive', 'active']; }
        // Cada referencia que cambia se registra con el nombre anterior y el nuevo ("Litigio" -> "Sin área"): una
        // simulacion o una vuelta frenada muestran que se pierde, y el freno too_many_org_changes las cuenta (I1).
        $org = false; $monitoring = $u['monitoring_override'];
        foreach ($refs as $f => $v) { if ($u[$f] !== $v) { $changes[$f] = [$this->refName($f, $u[$f]), $this->refName($f, $v)]; $org = true; } }
        // Pasar a "Sin cargo" no cambia el monitoreo como efecto lateral (un directivo excluido no empieza a ser
        // monitoreado): si el cargo nuevo es placeholder y el estado efectivo cambiaria, se fija el anterior por persona.
        if (($refs['position_id'] ?? $u['position_id']) !== $u['position_id'] && $monitoring === 'auto' && Placeholders::is($this->db, $this->tenant, $refs['position_id'], 'position_id')) {
            $was = RoleRules::monitoring($this->db, $u);
            if (RoleRules::monitoring($this->db, ['position_id' => $refs['position_id']] + $u) !== $was) { $monitoring = $was; $changes['monitoring_override'] = ['auto', $was]; }
        }
        // La cedula identifica a la persona en el alta de equipos: un cambio sobre una ya cargada se aprueba a mano.
        $current = $this->db->one("SELECT external_ref FROM user_external_refs WHERE tenant_id=? AND user_id=? AND source IN ('document','k3:cc') ORDER BY source='document' DESC LIMIT 1", [$this->tenant, $id])['external_ref'] ?? null;
        if ($document !== null && $current !== $document) {
            if ($current !== null) { if ($this->asked('document_change', $id, $external, $document) === null) $this->log('personas', 'pending', $external, $id, 'document_change', $name, ['document' => [self::mask($current), self::mask($document)]], ['type' => 'document', 'document' => $document]); }
            elseif ($this->db->one("SELECT user_id FROM user_external_refs WHERE tenant_id=? AND source IN ('document','k3:cc') AND external_ref=? AND user_id<>?", [$this->tenant, $document, $id])) { $this->log('personas', 'error', $external, $id, 'document_in_use', $name); }
            else { $this->db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?) ON DUPLICATE KEY UPDATE external_ref=VALUES(external_ref)", [$this->tenant, $id, $document]); $changes['document'] = [null, self::mask($document)]; }
        }
        if (!$changes) { return; }
        $this->db->run("UPDATE users SET display_name=?,email=?,employment_status=IF(status='inactive','contratado',employment_status),status='active',firm_id=?,site_id=?,area_id=?,position_id=?,schedule_id=?,company_id=?,monitoring_override=?,version=version+1 WHERE tenant_id=? AND id=?", [$name, $email, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id'], $monitoring, $this->tenant, $id]);
        if ($org) {
            $at = $this->now();
            $this->db->run('UPDATE user_assignments SET ends_at=? WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$at, $this->tenant, $id]);
            $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$this->tenant, Util::bin(Util::uuid()), $id, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id'], $at]);
            if ($refs['site_id'] !== $u['site_id']) { $this->calendars = true; }
        }
        $this->log('personas', 'update', $external, $id, null, $name, $changes);
    }
    /** Nombre de la unidad u horario de una referencia (para el registro); null = sin valor. */
    private function refName(string $col, ?string $id): ?string
    {
        if ($id === null) { return null; }
        return $this->db->one('SELECT name FROM ' . ($col === 'schedule_id' ? 'schedules' : 'org_units') . ' WHERE tenant_id=? AND id=?', [$this->tenant, $id])['name'] ?? null;
    }
    private function deactivatePerson(array $u, ?string $external, string $reason): void
    {
        $id = $u['id'];
        $this->db->run("UPDATE users SET status='inactive',employment_status='retirado',version=version+1,auth_version=auth_version+1 WHERE tenant_id=? AND id=?", [$this->tenant, $id]);
        // Mismo efecto que retirarla desde el panel: sin enrolamientos pendientes, equipos revocados y comandos cancelados.
        $this->db->run("UPDATE enrollments SET status='revoked' WHERE tenant_id=? AND user_id=? AND status='pending'", [$this->tenant, $id]);
        $this->db->run("UPDATE devices SET status='revoked',auth_version=auth_version+1,version=version+1 WHERE tenant_id=? AND user_id=? AND status='active'", [$this->tenant, $id]);
        $this->db->run("UPDATE device_command c JOIN devices d ON d.tenant_id=c.tenant_id AND d.id=c.device_id SET c.status='cancelled' WHERE d.tenant_id=? AND d.user_id=? AND c.status IN ('pending','delivered')", [$this->tenant, $id]);
        $this->db->run('UPDATE device_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND user_id=? AND revoked_at IS NULL', [$this->tenant, $id]);
        $this->db->run('UPDATE admin_accounts SET auth_version=auth_version+1 WHERE tenant_id=? AND user_id=?', [$this->tenant, $id]);
        $this->log('personas', 'deactivate', $external, $id, $reason, $u['display_name']);
    }
    private static function mask(string $doc): string { return strlen($doc) <= 4 ? $doc : str_repeat('•', strlen($doc) - 4) . substr($doc, -4); }

    // ---- Frenos (§5.4) --------------------------------------------------------------------------------------------------
    /**
     * Motivo para frenar la vuelta (nada se aplica; se desbloquea con approveSyncRun) o null. Mismo limite para retiros,
     * altas y cambios de organizacion: max(5 personas, 5 % de las vinculadas activas), configurable por fuente. Con base
     * 0 (primera carga) el limite es 5: la carga inicial nunca se aplica sin aprobacion (K5).
     */
    private function guards(array $source, bool $full, bool $simulate): ?string
    {
        if (in_array('personas', $this->owned, true)) {
            // Se cuenta sobre el estado ANTERIOR a la vuelta: las vinculadas activas antes de aplicar.
            $linked = $this->baseline;
            $limit = max((int) $source['max_deactivations'], (int) ceil($linked * (int) $source['max_deactivation_percent'] / 100));
            if ($full && $linked > 0 && ($this->incomingActive === 0 || ($linked >= 10 && $this->incomingActive * 100 < $linked * (int) $source['min_list_percent']))) { return 'list_too_small'; }
            if (($this->counts['personas']['deactivate'] ?? 0) > $limit) { return 'too_many_deactivations'; }
            if (($this->counts['personas']['create'] ?? 0) > $limit) { return 'too_many_creations'; }
            if ($this->orgChanges() > $limit) { return 'too_many_org_changes'; }
        }
        // Dominio recien asignado (DataSources::putDomains): la primera vuelta real espera aprobacion (I4). Una simulacion
        // no aplica nada, asi que no se frena por esto.
        if (!$simulate && $source['approval_required']) { return 'domains_changed'; }
        return null;
    }
    /** Personas ya existentes cuya empresa, firma, sede, area, cargo u horario cambia en esta vuelta. */
    private function orgChanges(): int
    {
        $cols = array_map(static fn ($k) => $k . '_id', array_keys(self::PERSON_REFS)); $n = 0;
        foreach ($this->items as $i) { if ($i['domain'] === 'personas' && $i['action'] === 'update' && is_array($i['fields']) && array_intersect_key($i['fields'], array_flip($cols))) { $n++; } }
        return $n;
    }

    // ---- Resolver pendientes ----------------------------------------------------------------------------------------------
    /** Aprueba o rechaza un pendiente de una vuelta aplicada. */
    public function resolve(string $tenant, array $item, bool $approve): void
    {
        $this->tenant = $tenant; $run = $this->db->one('SELECT * FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $item['run_id']]);
        $this->source = $run['source_id']; $this->items = []; $this->counts = [];
        if ($approve) {
            $p = json_decode((string) $item['payload'], true);
            $type = $p['type'] ?? '';
            if ($type === 'org_unit' || $type === 'schedule') {
                $entityType = $type === 'org_unit' ? 'org_unit' : 'schedule';
                if ($this->linked($entityType, $item['external_id']) === null) { $this->link($entityType, $item['entity_id'], $item['external_id'], 'matched_name'); }
                $this->owned = array_column($this->db->run('SELECT domain FROM data_domain_owners WHERE tenant_id=? AND source_id=?', [$tenant, $this->source])->fetchAll(), 'domain');
                $this->calendars = false;
                if ($type === 'org_unit') { $this->orgUnit($p['kind'], $p['item'], true); } else { $this->schedules([$p['item']]); }
                if ($this->calendars) { HolidayCalendars::refresh($this->db, $tenant); }
            } elseif ($type === 'org_deactivate') {
                $id = $item['entity_id'];
                if ($this->db->one("SELECT id FROM users WHERE tenant_id=? AND status='active' AND (firm_id=? OR site_id=? OR area_id=? OR position_id=? OR company_id=?) LIMIT 1", [$tenant, $id, $id, $id, $id, $id])
                    || $this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND parent_id=? AND active=TRUE LIMIT 1', [$tenant, $id])) { throw new ApiError(409, 'org_unit_in_use'); }
                $this->db->run('UPDATE org_units SET active=FALSE,version=version+1 WHERE tenant_id=? AND id=?', [$tenant, $item['entity_id']]);
            } elseif ($type === 'document') {
                $this->db->run("DELETE FROM user_external_refs WHERE tenant_id=? AND user_id=? AND source='document'", [$tenant, $item['entity_id']]);
                $this->db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)", [$tenant, $item['entity_id'], $p['document']]);
            } elseif ($type === 'orphan') {
                // Aprobar una huerfana = retirarla. Rechazar = dejarla como persona local.
                $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$tenant, $item['entity_id']]);
                if ($u && $u['status'] === 'active') { $this->deactivatePerson($u, null, 'orphan_retired'); }
            }
            $this->markDirty();
        }
        $this->db->run('UPDATE sync_items SET resolution=?,resolved_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$approve ? 'approved' : 'rejected', $tenant, $item['id']]);
    }

    // ---- Cron: vueltas vencidas de todas las empresas ----------------------------------------------------------------------
    public function due(): array
    {
        $out = [];
        foreach ($this->db->run("SELECT s.* FROM data_sources s WHERE s.active=TRUE AND s.kind='rest' AND s.interval_minutes IS NOT NULL
            AND EXISTS (SELECT 1 FROM tenant_features f WHERE f.tenant_id=s.tenant_id AND f.feature_code='one')")->fetchAll() as $s) {
            $tz = new \DateTimeZone($this->db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$s['tenant_id']])['timezone'] ?? 'UTC');
            $now = new \DateTimeImmutable('now', $tz);
            $fullAt = new \DateTimeImmutable($now->format('Y-m-d') . ' ' . $s['full_reconcile_local'], $tz);
            $lastFull = $s['last_full_at'] === null ? null : new \DateTimeImmutable($s['last_full_at'] . ' UTC');
            $lastRun = $s['last_run_at'] === null ? null : new \DateTimeImmutable($s['last_run_at'] . ' UTC');
            if ($lastFull === null || ($now >= $fullAt && $lastFull < $fullAt)) { $out[] = [$s, 'full']; }
            elseif ($lastRun === null || $lastRun->getTimestamp() + 60 * (int) $s['interval_minutes'] <= time()) { $out[] = [$s, 'incremental']; }
        }
        return $out;
    }
}
