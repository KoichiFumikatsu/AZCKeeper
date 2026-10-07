<?php
declare(strict_types=1);
namespace Keeper\Tools;

use Keeper\{AccessGrants, Database, DeviceIntake, Placeholders, Util};

/**
 * Carga unica de la instantanea de AZC One legacy (keeper.roster.v1, tools/one-legacy-snapshot) como datos LOCALES y
 * editables: sin fuente de datos, sin entity_links y sin dominios con dueño. Todo queda en un lote (import_batches,
 * migracion 0047) para que wipe() quite despues SOLO lo que la carga creo y nadie toco despues.
 *
 * Lo que ya existia en Keeper manda (decision del dueño, 2026-10-06): una unidad emparejada por nombre exacto o una
 * persona emparejada por cedula solo se VINCULA (item 'matched'); no se le cambia nada (ni ubicacion, jefe, correo,
 * estado, plan, acceso, asignaciones ni pais/ciudad). Si One trae otro valor, el dry-run lo cuenta (codigo differs_*).
 * Lo creado por un lote anterior tambien se vincula sin cambios: repetir la carga nunca deshace ediciones del panel.
 *
 * Dry-run = la misma carga dentro de una transaccion que se revierte: los conteos son exactos. Sin datos personales en
 * conteos, salida ni auditoria: solo codigos, ids y nombres de campo.
 */
final class OneImport
{
    public const KIND = 'one-snapshot';
    public const SCHEMA = 'keeper.roster.v1';
    private const ORG = ['company' => 'empresas', 'firm' => 'firmas', 'site' => 'sedes', 'area' => 'areas', 'position' => 'cargos'];
    private const REFS = ['company' => 'company_external_id', 'firm' => 'firm_external_id', 'site' => 'site_external_id',
        'area' => 'area_external_id', 'position' => 'position_external_id', 'schedule' => 'schedule_external_id'];
    private const AUDIT_FIELDS = [
        'org_unit' => ['kind', 'name', 'parent_id', 'country_code', 'city'],
        'schedule' => ['name', 'timezone', 'start_local', 'end_local', 'days'],
        'user' => ['display_name', 'email', 'status', 'company_id', 'firm_id', 'site_id', 'area_id', 'position_id', 'schedule_id', 'document', 'reports_to_user_id', 'access'],
    ];

    private string $tenant = '';
    private array $counts = [];
    /** Lote: "tipo|hexid" => [tipo, id, external_id, accion] */
    private array $items = [];
    /** "org_unit|ext", "schedule|ext", "user|ext" => id */
    private array $map = [];
    /** Unidades y horarios que existian antes de esta corrida: [id => fila] */
    private array $existingUnits = [];
    private array $existingSchedules = [];
    private array $seenDocuments = [];
    private bool $manifestTable = false;

    public function __construct(private Database $db) {}

    // ---- Lectura y guardas ------------------------------------------------------------------------------------------
    /** Lee <dir>/manifest.json y las paginas NNNN.json de org-units, schedules y people. */
    public static function readSnapshot(string $dir): array
    {
        $dir = rtrim($dir, '/\\');
        $manifest = json_decode((string) @file_get_contents("$dir/manifest.json"), true);
        if (!is_array($manifest) || ($manifest['schema'] ?? null) !== self::SCHEMA) { throw new \RuntimeException('snapshot_invalid: manifest.json ausente o schema distinto de ' . self::SCHEMA); }
        $out = ['manifest' => $manifest];
        foreach (['org-units', 'schedules', 'people'] as $endpoint) {
            $files = glob("$dir/$endpoint/[0-9][0-9][0-9][0-9].json") ?: [];
            sort($files, SORT_STRING);
            $items = [];
            foreach ($files as $f) {
                $page = json_decode((string) file_get_contents($f), true);
                if (!is_array($page) || !is_array($page['items'] ?? null)) { throw new \RuntimeException("snapshot_invalid: pagina sin items en $endpoint"); }
                foreach ($page['items'] as $it) { $items[] = $it; }
            }
            $expected = $manifest['endpoints'][$endpoint]['items'] ?? null;
            if ($expected !== null && (int) $expected !== count($items)) { throw new \RuntimeException("snapshot_invalid: $endpoint trae " . count($items) . " items y el manifest dice $expected"); }
            $out[$endpoint] = $items;
        }
        return $out;
    }

    /** "Grupo AZC" -> "grupo-azc" (lo que la instantanea trae en manifest.company). */
    public static function slug(string $name): string
    {
        $v = mb_strtolower(trim($name), 'UTF-8');
        $v = strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $v), '-');
    }

    /**
     * El cliente existe, no esta eliminado y se llama EXACTAMENTE como --confirm-tenant; con instantanea, su
     * manifest.company es el slug de ese nombre. Devuelve la fila del cliente.
     */
    public static function guardTenant(Database $db, string $tenant, string $confirmName, ?array $manifest = null): array
    {
        $row = $db->one('SELECT tenant_id,name FROM tenants WHERE tenant_id=? AND deleted_at IS NULL', [$tenant]);
        if (!$row) { throw new \RuntimeException('tenant_not_found'); }
        if ($row['name'] !== $confirmName) { throw new \RuntimeException('tenant_name_mismatch: --confirm-tenant no coincide con el nombre del cliente'); }
        if ($manifest !== null && ($manifest['company'] ?? null) !== self::slug($row['name'])) { throw new \RuntimeException('manifest_company_mismatch: la instantanea es de otra empresa'); }
        return $row;
    }

    public static function hasManifestTables(Database $db): bool
    {
        return (int) $db->one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('import_batches','import_batch_items')")['n'] === 2;
    }

    /** Resumen de un lote (sin datos personales) para mostrar antes de borrar. */
    public static function batchSummary(Database $db, string $tenant, string $batch): array
    {
        $b = $db->one('SELECT * FROM import_batches WHERE tenant_id=? AND id=?', [$tenant, $batch]);
        if (!$b) { throw new \RuntimeException('batch_not_found: el lote no existe en este cliente'); }
        $items = [];
        foreach ($db->run('SELECT entity_type,action,wiped_at IS NOT NULL w,COUNT(*) n FROM import_batch_items WHERE tenant_id=? AND batch_id=? GROUP BY entity_type,action,w', [$tenant, $batch])->fetchAll() as $r) {
            $items[$r['entity_type']][$r['action'] . ($r['w'] ? '_wiped' : '')] = (int) $r['n'];
        }
        return ['kind' => $b['kind'], 'status' => $b['status'], 'created_at' => $b['created_at'], 'source_generated_at' => $b['source_generated_at'], 'items' => $items];
    }

    private function lock(): void
    {
        if ((int) ($this->db->one('SELECT GET_LOCK(?,0) l', ['keeper-one-import-' . bin2hex($this->tenant)])['l'] ?? 0) !== 1) { throw new \RuntimeException('import_locked: otra carga o borrado corre en este cliente'); }
    }
    private function unlock(): void { $this->db->run('DO RELEASE_LOCK(?)', ['keeper-one-import-' . bin2hex($this->tenant)]); }

    // ---- Importacion ------------------------------------------------------------------------------------------------
    /**
     * @return array{applied:bool,batch:?string,counts:array,errors:int,changed:bool}
     * Sin $apply todo se revierte. Con $apply y errores por item, se niega salvo $allowErrors.
     */
    public function import(string $tenant, array $snap, bool $apply, bool $allowErrors = false): array
    {
        $this->tenant = $tenant; $this->counts = []; $this->items = []; $this->map = []; $this->seenDocuments = [];
        if (!$this->db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? AND deleted_at IS NULL', [$tenant])) { throw new \RuntimeException('tenant_not_found'); }
        $this->manifestTable = self::hasManifestTables($this->db);
        if ($apply && !$this->manifestTable) { throw new \RuntimeException('migration_missing: aplicar 0047_import_batches.sql antes de --apply'); }
        $this->lock();
        $this->db->pdo->beginTransaction();
        try {
            foreach ($this->db->run('SELECT * FROM org_units WHERE tenant_id=?', [$tenant])->fetchAll() as $o) { $this->existingUnits[$o['id']] = $o; }
            foreach ($this->db->run('SELECT * FROM schedules WHERE tenant_id=?', [$tenant])->fetchAll() as $s) { $this->existingSchedules[$s['id']] = $s; }
            $this->orgUnits($snap['org-units']);
            $this->schedules($snap['schedules']);
            $this->people($snap['people']);
            $this->hierarchy($snap['people']);
            $changed = false;
            foreach ($this->items as $i) { if ($i[3] === 'created') { $changed = true; break; } }
            $errors = 0; foreach ($this->counts as $c) { $errors += $c['error'] ?? 0; }
            if (!$apply || !$changed || ($errors > 0 && !$allowErrors)) {
                $this->db->pdo->rollBack();
                return ['applied' => false, 'batch' => null, 'counts' => $this->counts, 'errors' => $errors, 'changed' => $changed];
            }
            $batch = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO import_batches (tenant_id,id,kind,source_generated_at,counts) VALUES (?,?,?,?,?)',
                [$tenant, $batch, self::KIND, is_string($snap['manifest']['generated_at'] ?? null) ? substr($snap['manifest']['generated_at'], 0, 32) : null, Util::json($this->counts)]);
            $audit = $this->auditor(Util::bin(Util::uuid()));
            foreach ($this->items as [$type, $id, $ext, $action]) {
                // Lo creado guarda la version con que quedo: si el panel la cambia despues, el borrado no lo toca.
                $version = $action === 'created' ? (int) $this->db->one('SELECT version FROM ' . self::table($type) . ' WHERE tenant_id=? AND id=?', [$tenant, $id])['version'] : null;
                $this->db->run('INSERT INTO import_batch_items (tenant_id,batch_id,entity_type,entity_id,external_id,action,previous) VALUES (?,?,?,?,?,?,?)',
                    [$tenant, $batch, $type, $id, $ext, $action, $version === null ? null : Util::json(['imported_version' => $version])]);
                if ($action === 'created') { $audit('import.one_snapshot.create', $type, $id, self::AUDIT_FIELDS[$type]); }
            }
            $audit('import.one_snapshot.applied', 'import_batch', $batch, ['org_units', 'schedules', 'users'], self::summary($this->counts));
            $this->db->pdo->commit();
            return ['applied' => true, 'batch' => Util::id($batch), 'counts' => $this->counts, 'errors' => $errors, 'changed' => true];
        } catch (\Throwable $e) {
            if ($this->db->pdo->inTransaction()) { $this->db->pdo->rollBack(); }
            throw $e;
        } finally { $this->unlock(); }
    }

    private static function table(string $type): string { return ['org_unit' => 'org_units', 'schedule' => 'schedules', 'user' => 'users'][$type]; }
    private function count(string $entity, string $action, ?string $code = null): void
    {
        $this->counts[$entity][$action] = ($this->counts[$entity][$action] ?? 0) + 1;
        if ($code !== null) { $this->note($entity, $code); }
    }
    private function note(string $entity, string $code): void { $this->counts[$entity]['codes'][$code] = ($this->counts[$entity]['codes'][$code] ?? 0) + 1; }
    private function record(string $type, string $id, ?string $ext, string $action): void
    {
        $key = "$type|" . bin2hex($id);
        if (($this->items[$key][3] ?? null) === 'created') { return; }
        $this->items[$key] = [$type, $id, $ext, $action];
    }
    private static function ext(mixed $v): ?string { return is_string($v) && $v !== '' && strlen($v) <= 160 ? $v : null; }
    private static function clean(mixed $v): string { return is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', $v)) : ''; }
    private static function fold(string $v): string
    {
        $v = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $v)), 'UTF-8');
        return strtr($v, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
    private static function hm(mixed $v): ?string { return is_string($v) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $v) ? $v . ':00' : null; }

    /** Entidad que un lote anterior (sin borrar) ya creo o vinculo para ese external_id y que sigue viva. */
    private function previous(string $type, string $ext): ?string
    {
        if (!$this->manifestTable) { return null; }
        foreach ($this->db->run("SELECT i.entity_id FROM import_batch_items i JOIN import_batches b ON b.tenant_id=i.tenant_id AND b.id=i.batch_id WHERE i.tenant_id=? AND b.kind=? AND i.entity_type=? AND i.external_id=? AND i.wiped_at IS NULL ORDER BY b.created_at DESC", [$this->tenant, self::KIND, $type, $ext])->fetchAll() as $r) {
            $alive = $this->db->one('SELECT id FROM ' . self::table($type) . ' WHERE tenant_id=? AND id=?' . ($type === 'org_unit' ? ' AND deleted_at IS NULL' : ''), [$this->tenant, $r['entity_id']]);
            if ($alive) { return $r['entity_id']; }
        }
        return null;
    }

    // ---- Unidades -------------------------------------------------------------------------------------------------
    private function orgUnits(array $items): void
    {
        $byKind = [];
        foreach ($items as $it) {
            $kind = is_array($it) ? ($it['kind'] ?? null) : null;
            if (!is_string($kind) || !isset(self::ORG[$kind])) { $this->count('unidades', 'error', 'invalid_kind'); continue; }
            $byKind[$kind][] = $it;
        }
        foreach (['company', 'firm', 'site', 'position', 'area'] as $kind) {
            $pending = $byKind[$kind] ?? [];
            // Areas: padres primero; lo que no resuelve su padre cuando ya nada avanza es error.
            while ($pending) {
                $next = [];
                foreach ($pending as $it) { if ($this->orgUnit($kind, $it, false) === 'retry') { $next[] = $it; } }
                if (count($next) === count($pending)) { foreach ($next as $it) { $this->orgUnit($kind, $it, true); } break; }
                $pending = $next;
            }
        }
    }
    private function orgUnit(string $kind, array $it, bool $final): string
    {
        $entity = self::ORG[$kind]; $ext = self::ext($it['external_id'] ?? null); $name = self::clean($it['name'] ?? null);
        if ($ext === null || $name === '' || mb_strlen($name) > 120) { $this->count($entity, 'error', 'invalid_item'); return 'done'; }
        if (isset($this->map["org_unit|$ext"])) { $this->count($entity, 'error', 'duplicate_external_id'); return 'done'; }
        if (($it['active'] ?? true) === false) { $this->count($entity, 'skip', 'inactive_in_source'); return 'done'; }
        $parent = null;
        if ($kind === 'area' && is_string($it['parent_external_id'] ?? null)) {
            $parent = $this->map['org_unit|' . $it['parent_external_id']] ?? null;
            if ($parent === null) { if (!$final) { return 'retry'; } $this->count($entity, 'error', 'unknown_parent'); return 'done'; }
        }
        $geo = [];
        if ($kind === 'site') {
            $cc = $it['country_code'] ?? null; $city = self::clean($it['city'] ?? null);
            $geo['country_code'] = is_string($cc) && preg_match('/^[A-Za-z]{2}$/', $cc) ? strtoupper($cc) : null;
            $geo['city'] = $city === '' ? null : mb_substr($city, 0, 80);
        }
        $id = $this->previous('org_unit', $ext); $how = 'previous_import';
        if ($id === null) {
            // Solo contra lo que existia antes de esta corrida (dos unidades de One con el mismo nombre no se funden).
            $same = []; $similar = false;
            foreach ($this->existingUnits as $o) {
                if ($o['kind'] !== $kind || $o['deleted_at'] !== null || $o['placeholder']) { continue; }
                if (self::clean($o['name']) === $name) { $same[] = $o; } elseif (self::fold($o['name']) === self::fold($name)) { $similar = true; }
            }
            if (count($same) > 1) { $active = array_values(array_filter($same, fn ($o) => (bool) $o['active'])); $same = count($active) === 1 ? $active : $same; }
            if (count($same) > 1) { $this->count($entity, 'error', 'name_ambiguous'); return 'done'; }
            if ($same) { $id = $same[0]['id']; $how = 'name'; }
            elseif ($similar) { $this->note($entity, 'similar_name_exists'); }
        }
        if ($id === null) {
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO org_units (tenant_id,id,kind,name,parent_id,active,country_code,city) VALUES (?,?,?,?,?,TRUE,?,?)', [$this->tenant, $id, $kind, $name, $parent, $geo['country_code'] ?? null, $geo['city'] ?? null]);
            $this->map["org_unit|$ext"] = $id;
            $this->record('org_unit', $id, $ext, 'created');
            $this->count($entity, 'create');
            return 'done';
        }
        // Emparejada: solo se vincula. Lo distinto en One se cuenta, no se escribe.
        $this->map["org_unit|$ext"] = $id;
        $unit = $this->db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        if (!$unit['active']) { $this->note($entity, 'matched_inactive'); }
        foreach ($geo as $f => $v) { if ($v !== null && $unit[$f] !== $v) { $this->note($entity, "differs_$f"); } }
        if ($kind === 'area' && $unit['parent_id'] !== $parent) { $this->note($entity, 'differs_parent'); }
        $this->record('org_unit', $id, $ext, 'matched');
        $this->count($entity, 'match', $how);
        return 'done';
    }

    // ---- Horarios -------------------------------------------------------------------------------------------------
    private function schedules(array $items): void
    {
        foreach ($items as $it) {
            $ext = self::ext($it['external_id'] ?? null); $name = self::clean($it['name'] ?? null);
            $days = []; $valid = $ext !== null && $name !== '' && mb_strlen($name) <= 120 && is_array($it['days'] ?? null) && is_string($it['timezone'] ?? null);
            if ($valid) { try { new \DateTimeZone($it['timezone']); } catch (\Exception) { $valid = false; } }
            foreach ($valid ? $it['days'] : [] as $d) {
                $w = $d['weekday'] ?? null; $s = self::hm($d['start_local'] ?? null); $e = self::hm($d['end_local'] ?? null);
                $ls = isset($d['lunch']) ? self::hm($d['lunch']['start_local'] ?? null) : null; $le = isset($d['lunch']) ? self::hm($d['lunch']['end_local'] ?? null) : null;
                if (!is_int($w) || $w < 1 || $w > 7 || isset($days[$w]) || $s === null || $e === null || ($ls === null) !== ($le === null) || ($ls !== null && $ls >= $le)) { $valid = false; break; }
                $days[$w] = [$s, $e, $ls, $le];
            }
            if (!$valid || !$days) { $this->count('horarios', 'error', 'invalid_item'); continue; }
            if (isset($this->map["schedule|$ext"])) { $this->count('horarios', 'error', 'duplicate_external_id'); continue; }
            if (($it['active'] ?? true) === false) { $this->count('horarios', 'skip', 'inactive_in_source'); continue; }
            $id = $this->previous('schedule', $ext); $how = 'previous_import';
            if ($id === null) {
                $same = array_values(array_filter($this->existingSchedules, fn ($s) => !$s['placeholder'] && self::clean($s['name']) === $name));
                if (count($same) > 1) { $this->count('horarios', 'error', 'name_ambiguous'); continue; }
                if ($same) { $id = $same[0]['id']; $how = 'name'; }
            }
            if ($id !== null) {
                $this->map["schedule|$ext"] = $id; $this->record('schedule', $id, $ext, 'matched'); $this->count('horarios', 'match', $how);
                continue;
            }
            ksort($days);
            // Franja general = la mas comun; los dias distintos quedan con franja propia (igual que DataSync).
            $freq = []; foreach ($days as $v) { $k = implode('|', $v); $freq[$k] = ($freq[$k] ?? 0) + 1; }
            arsort($freq); $default = array_map(static fn ($x) => $x === '' ? null : $x, explode('|', (string) array_key_first($freq)));
            $id = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local,lunch_start_local,lunch_end_local) VALUES (?,?,?,?,?,?,?,?)', [$this->tenant, $id, $name, $it['timezone'], ...$default]);
            foreach ($days as $w => $v) {
                $own = $v == $default ? [null, null, null, null] : $v;
                $this->db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday,start_local,end_local,lunch_start_local,lunch_end_local) VALUES (?,?,?,?,?,?,?)', [$this->tenant, $id, $w, ...$own]);
            }
            $this->map["schedule|$ext"] = $id; $this->record('schedule', $id, $ext, 'created'); $this->count('horarios', 'create');
        }
    }

    // ---- Personas -------------------------------------------------------------------------------------------------
    private function people(array $items): void
    {
        foreach ($items as $it) {
            $ext = self::ext($it['external_id'] ?? null); $name = self::clean($it['display_name'] ?? null); $status = $it['status'] ?? null;
            if ($ext === null || $name === '' || mb_strlen($name) > 160 || !in_array($status, ['active', 'inactive'], true)) { $this->count('personas', 'error', 'invalid_item'); continue; }
            if (isset($this->map["user|$ext"])) { $this->count('personas', 'error', 'duplicate_external_id'); continue; }
            $email = $it['email'] ?? null;
            if ($email !== null && (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) { $email = null; $this->note('personas', 'invalid_email_dropped'); }
            $document = is_string($it['document'] ?? null) ? DeviceIntake::document($it['document']) : null;
            if ($document !== null && (strlen($document) < 3 || strlen($document) > 40)) { $document = null; $this->note('personas', 'invalid_document_dropped'); }
            // La misma cedula dos veces en la instantanea: falla la segunda persona, no el lote.
            if ($document !== null && isset($this->seenDocuments[$document])) { $this->count('personas', 'error', 'document_duplicate_in_snapshot'); continue; }
            if ($document !== null) { $this->seenDocuments[$document] = true; }
            // Lo anotado dentro del SAVEPOINT (p. ej. un placeholder creado) se descarta si la persona se revierte.
            [$items0, $counts0] = [$this->items, $this->counts];
            $this->db->run('SAVEPOINT one_import_person');
            try { $this->person($it, $ext, $name, $email, $status, $document); $this->db->run('RELEASE SAVEPOINT one_import_person'); }
            catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
                $this->db->run('ROLLBACK TO SAVEPOINT one_import_person');
                [$this->items, $this->counts] = [$items0, $counts0];
                unset($this->map["user|$ext"]);
                $this->count('personas', 'error', 'duplicate_value');
            }
        }
    }
    private function person(array $it, string $ext, string $name, ?string $email, string $status, ?string $document): void
    {
        $retired = $status === 'inactive';
        $id = $this->previous('user', $ext); $how = 'previous_import';
        if ($id === null && $document !== null) {
            $match = array_column($this->db->run("SELECT DISTINCT user_id FROM user_external_refs WHERE tenant_id=? AND source IN ('document','k3:cc') AND external_ref=?", [$this->tenant, $document])->fetchAll(), 'user_id');
            if (count($match) > 1) { $this->count('personas', 'error', 'document_ambiguous'); return; }
            if ($match) { $id = $match[0]; $how = 'document'; }
        }
        if ($id !== null) { $this->linkMatched($id, $it, $ext, $email, $status, $how); return; }
        $refs = $this->refs($it, $retired);
        if (is_string($refs)) { $this->count('personas', 'error', $refs); return; }
        // Correo de otra persona (activa o retirada): entra sin correo; se completa luego en el panel.
        if ($email !== null && $this->db->one('SELECT id FROM users WHERE tenant_id=? AND email=?', [$this->tenant, $email])) { $email = null; $this->note('personas', 'email_in_use_dropped'); }
        $id = Util::bin(Util::uuid());
        $at = $this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'];
        $this->db->run('INSERT INTO users (tenant_id,id,display_name,email,status,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$this->tenant, $id, $name, $email, $status, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id']]);
        $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$this->tenant, Util::bin(Util::uuid()), $id, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id'], $at]);
        AccessGrants::grantDefault($this->db, $this->tenant, $id);
        if ($document !== null) {
            if ($this->db->one("SELECT user_id FROM user_external_refs WHERE tenant_id=? AND source='document' AND external_ref=?", [$this->tenant, $document])) { $this->note('personas', 'document_in_use_dropped'); }
            else { $this->db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)", [$this->tenant, $id, $document]); }
        } else { $this->note('personas', 'without_document'); }
        $this->map["user|$ext"] = $id;
        $this->record('user', $id, $ext, 'created');
        $this->count('personas', 'create', $retired ? 'inactive_created' : 'active_created');
    }
    /**
     * Persona que ya estaba (por cedula) o que creo un lote anterior: solo se vincula, nada se escribe. Lo que One trae
     * distinto se cuenta (differs_*), sin valores.
     */
    private function linkMatched(string $id, array $it, string $ext, ?string $email, string $status, string $how): void
    {
        $this->map["user|$ext"] = $id;
        $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        if ($u['status'] !== $status) { $this->note('personas', 'differs_status'); }
        if ($email !== null && $u['email'] !== $email) { $this->note('personas', 'differs_email'); }
        $refs = $status === 'active' ? $this->refs($it, false, false) : [];
        if (is_string($refs)) { $this->note('personas', "differs_$refs"); $refs = []; }
        foreach ($refs as $col => $v) { if ($v !== null && $u[$col] !== $v) { $this->note('personas', "differs_$col"); } }
        $this->record('user', $id, $ext, 'matched');
        $this->count('personas', 'match', $how);
    }
    /**
     * Columnas de ubicacion o el codigo de error. Sin dato: empresa vacia; «Sin firma/área/cargo/horario»
     * (Placeholders, el mismo de panel y DataSync, por la marca de 0046). Una referencia que no resuelve: error en una
     * activa, placeholder en una retirada. Sin sede: error en una activa, «Sin sede» en una retirada (como DataSync).
     * $placeholders=false (solo comparar): lo que faltaria queda null y no se crea nada.
     */
    private function refs(array $it, bool $lenient, bool $placeholders = true): array|string
    {
        $out = [];
        foreach (self::REFS as $kind => $field) {
            $v = $it[$field] ?? null;
            if ($v !== null && self::ext($v) === null) { return 'invalid_reference'; }
            $id = $v === null ? null : ($this->map[($kind === 'schedule' ? 'schedule|' : 'org_unit|') . $v] ?? null);
            if ($id !== null && $kind !== 'schedule' && !$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=?', [$this->tenant, $id, $kind])) { $id = null; }
            if ($id === null && $v !== null && !$lenient) { return 'unknown_reference'; }
            if ($id === null && $kind === 'site' && !$lenient) { return 'missing_site'; }
            $out[$kind . '_id'] = $id ?? ($placeholders ? $this->placeholder($kind) : null);
        }
        return $out;
    }
    private function placeholder(string $kind): ?string
    {
        if ($kind === 'company') { return null; }
        if ($kind === 'schedule') {
            $id = Placeholders::schedule($this->db, $this->tenant, $this->db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$this->tenant])['timezone'] ?? 'UTC');
            $this->placeholderSeen('schedule', $id, isset($this->existingSchedules[$id]), 'schedule');
            return $id;
        }
        $id = Placeholders::unit($this->db, $this->tenant, $kind);
        $this->placeholderSeen('org_unit', $id, isset($this->existingUnits[$id]), $kind);
        return $id;
    }
    private function placeholderSeen(string $type, string $id, bool $existed, string $kind): void
    {
        if ($existed) { if (!isset($this->counts['placeholders']['codes']["reused_$kind"])) { $this->note('placeholders', "reused_$kind"); } return; }
        if (isset($this->items["$type|" . bin2hex($id)])) { return; }
        $this->record($type, $id, null, 'created'); $this->count('placeholders', 'create', $kind);
    }

    // ---- Jerarquia --------------------------------------------------------------------------------------------------
    /** Solo personas CREADAS en esta corrida reciben jefe (puede ser una emparejada). A una emparejada no se le cambia. */
    private function hierarchy(array $items): void
    {
        foreach ($items as $it) {
            $ext = self::ext($it['external_id'] ?? null);
            if ($ext === null || ($it['status'] ?? null) !== 'active' || !is_string($it['reports_to_external_id'] ?? null)) { continue; }
            $id = $this->map["user|$ext"] ?? null; if ($id === null) { continue; }
            $boss = $this->map['user|' . $it['reports_to_external_id']] ?? null;
            $created = ($this->items['user|' . bin2hex($id)][3] ?? null) === 'created';
            $u = $this->db->one('SELECT reports_to_user_id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            if (!$created) { $this->count('jerarquia', 'skip', $boss !== null && $u['reports_to_user_id'] === $boss ? 'matched_same' : 'matched_kept_differs'); continue; }
            if ($boss === null) { $this->count('jerarquia', 'error', 'unknown_reference'); continue; }
            for ($m = $boss, $n = 0; $m !== null && $n < 60; $n++) {
                if ($m === $id) { $this->count('jerarquia', 'error', 'hierarchy_cycle'); continue 2; }
                $m = $this->db->one('SELECT reports_to_user_id r FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $m])['r'] ?? null;
            }
            $this->db->run('UPDATE users SET reports_to_user_id=?,version=version+1 WHERE tenant_id=? AND id=?', [$boss, $this->tenant, $id]);
            $this->count('jerarquia', 'update', ($this->items['user|' . bin2hex($boss)][3] ?? null) === 'created' ? 'boss_created' : 'boss_matched');
        }
    }

    // ---- Borrado de un lote -------------------------------------------------------------------------------------------
    /**
     * Quita SOLO lo que el lote creo y sigue exactamente como quedo (misma version). Nunca toca lo emparejado ni lo que el
     * dueño edito despues. Se informa y se deja: persona editada, con equipos, cuenta de panel, acceso propio, historial o
     * jefe de alguien que se queda; horario o unidad editados, en uso, con hijas que se quedan o con historial ajeno.
     * No hay retiros ni reasignaciones: si algo se queda, lo decide el dueño en el panel. La auditoria nunca se borra.
     */
    public function wipe(string $tenant, string $batch, bool $apply): array
    {
        if (!self::hasManifestTables($this->db)) { throw new \RuntimeException('migration_missing'); }
        if (!$this->db->one('SELECT id FROM import_batches WHERE tenant_id=? AND id=?', [$tenant, $batch])) { throw new \RuntimeException('batch_not_found: el lote no existe en este cliente'); }
        $this->tenant = $t = $tenant; $this->counts = [];
        $this->lock();
        $this->db->pdo->beginTransaction();
        try {
            $items = $this->db->run('SELECT * FROM import_batch_items WHERE tenant_id=? AND batch_id=? AND wiped_at IS NULL', [$t, $batch])->fetchAll();
            $created = ['org_unit' => [], 'schedule' => [], 'user' => []];
            foreach ($items as $i) {
                if ($i['action'] !== 'created') { $this->count('emparejados', 'keep', $i['entity_type']); continue; }
                $created[$i['entity_type']][$i['entity_id']] = (int) (json_decode((string) $i['previous'], true)['imported_version'] ?? 0);
            }
            $result = [];
            $audit = $this->auditor(Util::bin(Util::uuid()));
            $keep = function (string $type, string $id, string $entity, string $why) use (&$result): void { $result["$type|" . bin2hex($id)] = "skip:$why"; $this->count($entity, 'skip', $why); };

            // 1) Personas creadas.
            $remaining = [];
            foreach ($created['user'] as $id => $version) {
                $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$t, $id]);
                if (!$u) { $result['user|' . bin2hex($id)] = 'gone'; continue; }
                $why = null;
                if ((int) $u['version'] !== $version) { $why = 'owner_edited'; }
                elseif ($this->db->one('SELECT id FROM devices WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id]) || $this->db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id]) || $this->db->one('SELECT id FROM enrollments WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id])) { $why = 'has_devices'; }
                elseif ($this->db->one('SELECT id FROM admin_accounts WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id]) || $u['panel_login_enabled']) { $why = 'has_panel_account'; }
                elseif ($this->db->one("SELECT g.id FROM access_grants g WHERE g.tenant_id=? AND g.user_id=? AND (g.source<>'default' OR EXISTS (SELECT 1 FROM access_grant_units x WHERE x.tenant_id=g.tenant_id AND x.grant_id=g.id) OR EXISTS (SELECT 1 FROM access_grant_cities y WHERE y.tenant_id=g.tenant_id AND y.grant_id=g.id)) LIMIT 1", [$t, $id])) { $why = 'has_access_grant'; }
                if ($why !== null) { $keep('user', $id, 'personas', $why); continue; }
                $remaining[$id] = true;
            }
            // Hojas primero: quien tiene a alguien que le reporta espera; si ese alguien se queda, el jefe tambien.
            while ($remaining) {
                $progress = false;
                foreach (array_keys($remaining) as $id) {
                    if ($this->db->one('SELECT id FROM users WHERE tenant_id=? AND reports_to_user_id=? LIMIT 1', [$t, $id])) { continue; }
                    unset($remaining[$id]); $progress = true;
                    $this->db->run('SAVEPOINT one_wipe_user');
                    try {
                        AccessGrants::removeForUser($this->db, $t, $id);
                        foreach (['user_assignments', 'user_external_refs'] as $table) { $this->db->run("DELETE FROM $table WHERE tenant_id=? AND user_id=?", [$t, $id]); }
                        $this->db->run('DELETE FROM users WHERE tenant_id=? AND id=?', [$t, $id]);
                        $this->db->run('RELEASE SAVEPOINT one_wipe_user');
                        $this->count('personas', 'delete'); $result['user|' . bin2hex($id)] = 'deleted';
                        $audit('import.one_snapshot.delete', 'user', $id, self::AUDIT_FIELDS['user']);
                    } catch (\PDOException $e) {
                        if (($e->errorInfo[1] ?? 0) !== 1451) { throw $e; }
                        $this->db->run('ROLLBACK TO SAVEPOINT one_wipe_user');
                        $keep('user', $id, 'personas', 'has_history');
                    }
                }
                if (!$progress) { foreach (array_keys($remaining) as $id) { $keep('user', $id, 'personas', 'boss_of_kept_person'); } break; }
            }

            // 2) Horarios creados.
            foreach ($created['schedule'] as $id => $version) {
                $s = $this->db->one('SELECT version FROM schedules WHERE tenant_id=? AND id=?', [$t, $id]);
                if (!$s) { $result['schedule|' . bin2hex($id)] = 'gone'; continue; }
                if ((int) $s['version'] !== $version) { $keep('schedule', $id, 'horarios', 'owner_edited'); continue; }
                if ($this->db->one('SELECT id FROM users WHERE tenant_id=? AND schedule_id=? LIMIT 1', [$t, $id])) { $keep('schedule', $id, 'horarios', 'in_use_people'); continue; }
                if ($this->db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND schedule_id=? LIMIT 1', [$t, $id])) { $keep('schedule', $id, 'horarios', 'has_history'); continue; }
                $this->db->run('SAVEPOINT one_wipe_schedule');
                try {
                    $this->db->run('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=?', [$t, $id]);
                    $this->db->run('DELETE FROM schedules WHERE tenant_id=? AND id=?', [$t, $id]);
                    $this->db->run('RELEASE SAVEPOINT one_wipe_schedule');
                    $this->count('horarios', 'delete'); $result['schedule|' . bin2hex($id)] = 'deleted';
                    $audit('import.one_snapshot.delete', 'schedule', $id, self::AUDIT_FIELDS['schedule']);
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1451) { throw $e; }
                    $this->db->run('ROLLBACK TO SAVEPOINT one_wipe_schedule');
                    $keep('schedule', $id, 'horarios', 'in_use');
                }
            }

            // 3) Unidades creadas: hijas primero. Una hija que no se borra (ajena, editada, en uso) deja al padre.
            $pending = $created['org_unit'];
            while ($pending) {
                $progress = false;
                foreach ($pending as $id => $version) {
                    $key = 'org_unit|' . bin2hex($id);
                    $unit = $this->db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$t, $id]);
                    if (!$unit || $unit['deleted_at'] !== null) { $result[$key] = 'gone'; unset($pending[$id]); $progress = true; continue; }
                    $entity = $unit['placeholder'] ? 'placeholders' : (self::ORG[$unit['kind']] ?? 'unidades');
                    $children = array_column($this->db->run('SELECT id FROM org_units WHERE tenant_id=? AND parent_id=? AND deleted_at IS NULL', [$t, $id])->fetchAll(), 'id');
                    if ($children && array_diff($children, array_keys($pending)) === [] ) { continue; }  // esperan sus hijas del lote
                    unset($pending[$id]); $progress = true;
                    if ((int) $unit['version'] !== $version) { $keep('org_unit', $id, $entity, 'owner_edited'); continue; }
                    if ($children) { $keep('org_unit', $id, $entity, 'has_children'); continue; }
                    if ($this->db->one('SELECT id FROM users WHERE tenant_id=? AND ? IN (firm_id,site_id,area_id,position_id,company_id) LIMIT 1', [$t, $id])) { $keep('org_unit', $id, $entity, 'in_use_people'); continue; }
                    if ($this->db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND ? IN (firm_id,site_id,area_id,position_id,company_id) LIMIT 1', [$t, $id])) { $keep('org_unit', $id, $entity, 'has_history'); continue; }
                    $busy = $this->unitBusy($id);
                    if ($busy !== null) { $keep('org_unit', $id, $entity, "in_use_$busy"); continue; }
                    $this->db->run('SAVEPOINT one_wipe_unit');
                    try {
                        $this->db->run('DELETE FROM org_units WHERE tenant_id=? AND id=?', [$t, $id]);
                        $this->db->run('RELEASE SAVEPOINT one_wipe_unit');
                        $this->count($entity, 'delete'); $result[$key] = 'deleted';
                        $audit('import.one_snapshot.delete', 'org_unit', $id, self::AUDIT_FIELDS['org_unit']);
                    } catch (\PDOException $e) {
                        if (($e->errorInfo[1] ?? 0) !== 1451) { throw $e; }
                        $this->db->run('ROLLBACK TO SAVEPOINT one_wipe_unit');
                        $keep('org_unit', $id, $entity, 'has_history');
                    }
                }
                if (!$progress) {
                    foreach (array_keys($pending) as $id) { $u = $this->db->one('SELECT kind,placeholder FROM org_units WHERE tenant_id=? AND id=?', [$t, $id]); $keep('org_unit', $id, $u['placeholder'] ? 'placeholders' : (self::ORG[$u['kind']] ?? 'unidades'), 'has_children'); }
                    break;
                }
            }

            $left = 0;
            foreach ($items as $i) {
                $r = $i['action'] === 'created' ? ($result[$i['entity_type'] . '|' . bin2hex($i['entity_id'])] ?? 'skip:unknown') : 'kept_matched';
                $done = in_array($r, ['deleted', 'gone', 'kept_matched'], true);
                if (!$done) { $left++; }
                $this->db->run('UPDATE import_batch_items SET wipe_result=?,wiped_at=' . ($done ? 'UTC_TIMESTAMP(6)' : 'NULL') . ' WHERE tenant_id=? AND batch_id=? AND entity_type=? AND entity_id=?', [substr($r, 0, 40), $t, $batch, $i['entity_type'], $i['entity_id']]);
            }
            $this->db->run('UPDATE import_batches SET status=?,wipe_counts=?,wiped_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$left ? 'partially_wiped' : 'wiped', Util::json($this->counts), $t, $batch]);
            if (!$apply) { $this->db->pdo->rollBack(); return ['applied' => false, 'counts' => $this->counts, 'left' => $left]; }
            $audit('import.one_snapshot.wiped', 'import_batch', $batch, ['org_units', 'schedules', 'users'], self::summary($this->counts));
            $this->db->pdo->commit();
            return ['applied' => true, 'counts' => $this->counts, 'left' => $left];
        } catch (\Throwable $e) {
            if ($this->db->pdo->inTransaction()) { $this->db->pdo->rollBack(); }
            throw $e;
        } finally { $this->unlock(); }
    }
    /** Mismos usos que bloquean «Eliminar» en el panel (personas e hijas se miran aparte). */
    private function unitBusy(string $id): ?string
    {
        $t = $this->tenant;
        $checks = [
            'access' => ['SELECT COUNT(*) n FROM access_grant_units WHERE tenant_id=? AND unit_id=?', [$t, $id]],
            'policies' => ['SELECT COUNT(*) n FROM policy_assignments WHERE tenant_id=? AND (area_id=? OR site_id=?)', [$t, $id, $id]],
            'doors' => ['SELECT COUNT(*) n FROM doors WHERE tenant_id=? AND site_id=?', [$t, $id]],
            'role_rules' => ['SELECT COUNT(*) n FROM panel_role_rules WHERE tenant_id=? AND (area_id=? OR position_id=?)', [$t, $id, $id]],
            'monitoring' => ['SELECT COUNT(*) n FROM monitoring_excluded_positions WHERE tenant_id=? AND position_id=?', [$t, $id]],
            'classification' => ['SELECT COUNT(*) n FROM app_classification_overrides WHERE tenant_id=? AND (area_id=? OR position_id=?)', [$t, $id, $id]],
            'sociedades' => ['SELECT (SELECT COUNT(*) FROM sociedades WHERE tenant_id=? AND firm_id=?)+(SELECT COUNT(*) FROM firma_module_override WHERE tenant_id=? AND firm_id=?) n', [$t, $id, $t, $id]],
        ];
        foreach ($checks as $what => [$sql, $args]) { if ((int) $this->db->one($sql, $args)['n'] > 0) { return $what; } }
        return null;
    }

    // ---- Auditoria y salida -------------------------------------------------------------------------------------------
    /** Escritor de auditoria del actor system del cliente; una fila por entidad (solo nombres de campo, sin valores). */
    private function auditor(string $request): \Closure
    {
        $actor = $this->db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system' ORDER BY id LIMIT 1", [$this->tenant]);
        if (!$actor) { $actor = ['id' => Util::bin(Util::uuid())]; $this->db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')", [$this->tenant, $actor['id']]); }
        return function (string $action, string $type, string $id, array $fields, ?string $reason = null) use ($actor, $request): void {
            $this->db->run("INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields,reason) VALUES (?,?,?,'system',?,?,?,?,'allowed',?,?)",
                [$this->tenant, Util::bin(Util::uuid()), $actor['id'], $action, $type, $id, $request, Util::json($fields), $reason === null ? null : mb_substr($reason, 0, 500)]);
        };
    }
    /** Una linea por entidad: "personas create=219 match=3 ..." (solo conteos y codigos). */
    public static function summary(array $counts): string
    {
        $out = [];
        foreach ($counts as $entity => $c) {
            $parts = [];
            foreach ($c as $k => $v) { if ($k !== 'codes') { $parts[] = "$k=$v"; } }
            if (!empty($c['codes'])) { ksort($c['codes']); $parts[] = '(' . implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($c['codes']), $c['codes'])) . ')'; }
            $out[] = "$entity " . implode(' ', $parts);
        }
        return implode('; ', $out);
    }
}
