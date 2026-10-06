<?php
declare(strict_types=1);
namespace Keeper\Tools;

use Keeper\{AccessGrants, Database, DeviceIntake, HolidayCalendars, Placeholders, Util};

/**
 * Carga unica de la instantanea de AZC One legacy (keeper.roster.v1, tools/one-legacy-snapshot) como datos LOCALES y
 * editables: sin fuente de datos, sin entity_links y sin dominios con dueño. Todo queda en un lote (import_batches,
 * migracion 0047) para que wipe() quite despues exactamente lo creado, antes de cargar desde la API de One v2.
 *
 * Emparejamiento: unidades y horarios por nombre exacto (mismo tipo, sin eliminar); personas por cedula
 * (user_external_refs 'document' o 'k3:cc'). Un lote anterior sin borrar manda sobre el nombre (renombrar en el panel
 * no duplica). Lo emparejado nunca pierde su acceso, su cuenta de panel, su nombre ni su estado: solo se actualiza su
 * ubicacion (empresa, firma, sede, area, cargo, horario) y su jefe, y se anota lo anterior (solo ids) en el lote.
 *
 * Dry-run = la misma carga dentro de una transaccion que se revierte: los conteos son exactos. Sin datos personales en
 * conteos ni salida: solo codigos.
 */
final class OneImport
{
    public const KIND = 'one-snapshot';
    public const SCHEMA = 'keeper.roster.v1';
    private const ORG = ['company' => 'empresas', 'firm' => 'firmas', 'site' => 'sedes', 'area' => 'areas', 'position' => 'cargos'];
    private const REFS = ['company' => 'company_external_id', 'firm' => 'firm_external_id', 'site' => 'site_external_id',
        'area' => 'area_external_id', 'position' => 'position_external_id', 'schedule' => 'schedule_external_id'];
    private const PLACE = ['company_id', 'firm_id', 'site_id', 'area_id', 'position_id', 'schedule_id'];

    private string $tenant = '';
    private array $counts = [];
    /** Lote: "tipo|id" => [tipo, id, external_id, accion, previous] */
    private array $items = [];
    /** "org_unit|ext", "schedule|ext", "user|ext" => id */
    private array $map = [];
    private array $existingUnits = [];
    private array $existingSchedules = [];
    private bool $manifestTable = false;

    public function __construct(private Database $db) {}

    // ---- Lectura de la instantanea --------------------------------------------------------------------------------
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

    public static function hasManifestTables(Database $db): bool
    {
        return (int) $db->one("SELECT COUNT(*) n FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('import_batches','import_batch_items')")['n'] === 2;
    }

    // ---- Importacion ------------------------------------------------------------------------------------------------
    /**
     * @return array{applied:bool,batch:?string,counts:array}
     * Sin $apply todo se revierte. Con $apply y errores, se niega salvo $allowErrors.
     */
    public function import(string $tenant, array $snap, bool $apply, bool $allowErrors = false): array
    {
        $this->tenant = $tenant; $this->counts = []; $this->items = []; $this->map = [];
        if (!$this->db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? AND deleted_at IS NULL', [$tenant])) { throw new \RuntimeException('tenant_not_found'); }
        $this->manifestTable = self::hasManifestTables($this->db);
        if ($apply && !$this->manifestTable) { throw new \RuntimeException('migration_missing: aplicar 0047_import_batches.sql antes de --apply'); }
        $this->db->pdo->beginTransaction();
        try {
            $this->existingUnits = array_fill_keys(array_column($this->db->run('SELECT id FROM org_units WHERE tenant_id=?', [$tenant])->fetchAll(), 'id'), true);
            $this->existingSchedules = array_fill_keys(array_column($this->db->run('SELECT id FROM schedules WHERE tenant_id=?', [$tenant])->fetchAll(), 'id'), true);
            $this->orgUnits($snap['org-units']);
            $this->schedules($snap['schedules']);
            $this->people($snap['people']);
            $this->hierarchy($snap['people']);
            $changed = false;
            foreach ($this->items as $i) { if ($i[3] !== 'matched') { $changed = true; break; } }
            $errors = 0; foreach ($this->counts as $c) { $errors += $c['error'] ?? 0; }
            if (!$apply || !$changed || ($errors > 0 && !$allowErrors)) {
                $this->db->pdo->rollBack();
                return ['applied' => false, 'batch' => null, 'counts' => $this->counts, 'errors' => $errors, 'changed' => $changed];
            }
            $batch = Util::bin(Util::uuid());
            $this->db->run('INSERT INTO import_batches (tenant_id,id,kind,source_generated_at,counts) VALUES (?,?,?,?,?)',
                [$tenant, $batch, self::KIND, is_string($snap['manifest']['generated_at'] ?? null) ? substr($snap['manifest']['generated_at'], 0, 32) : null, Util::json($this->counts)]);
            foreach ($this->items as [$type, $id, $ext, $action, $prev]) {
                $this->db->run('INSERT INTO import_batch_items (tenant_id,batch_id,entity_type,entity_id,external_id,action,previous) VALUES (?,?,?,?,?,?,?)',
                    [$tenant, $batch, $type, $id, $ext, $action, $prev ? Util::json($prev) : null]);
            }
            $this->audit($batch, 'import.one_snapshot.applied', ['org_units', 'schedules', 'users'], self::summary($this->counts));
            $this->db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)', [$tenant]);
            HolidayCalendars::refresh($this->db, $tenant);
            $this->db->pdo->commit();
            return ['applied' => true, 'batch' => Util::id($batch), 'counts' => $this->counts, 'errors' => $errors, 'changed' => true];
        } catch (\Throwable $e) {
            if ($this->db->pdo->inTransaction()) { $this->db->pdo->rollBack(); }
            throw $e;
        }
    }

    private function count(string $entity, string $action, ?string $code = null): void
    {
        $this->counts[$entity][$action] = ($this->counts[$entity][$action] ?? 0) + 1;
        if ($code !== null) { $this->counts[$entity]['codes'][$code] = ($this->counts[$entity]['codes'][$code] ?? 0) + 1; }
    }
    private function note(string $entity, string $code): void { $this->counts[$entity]['codes'][$code] = ($this->counts[$entity]['codes'][$code] ?? 0) + 1; }
    private function record(string $type, string $id, ?string $ext, string $action, ?array $prev = null): void
    {
        $key = "$type|" . bin2hex($id);
        if (isset($this->items[$key])) {
            // Ya anotado (p. ej. la persona y luego su jefe): creado manda; lo anterior se conserva del primer cambio.
            [, , $e0, $a0, $p0] = $this->items[$key];
            $action = $a0 === 'created' ? 'created' : ($a0 === 'updated' || $action === 'updated' ? 'updated' : 'matched');
            $prev = ($p0 ?? []) + ($prev ?? []);
            $ext ??= $e0;
        }
        $this->items[$key] = [$type, $id, $ext, $action, $prev ?: null];
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
            $alive = match ($type) {
                'org_unit' => $this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$this->tenant, $r['entity_id']]),
                'schedule' => $this->db->one('SELECT id FROM schedules WHERE tenant_id=? AND id=?', [$this->tenant, $r['entity_id']]),
                default => $this->db->one('SELECT id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $r['entity_id']]),
            };
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
            $same = []; $similar = false;
            // Los placeholders (marca de 0046) nunca se emparejan por nombre.
            foreach ($this->db->run('SELECT id,name,active FROM org_units WHERE tenant_id=? AND kind=? AND deleted_at IS NULL AND placeholder=FALSE', [$this->tenant, $kind])->fetchAll() as $o) {
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
        $this->map["org_unit|$ext"] = $id;
        $unit = $this->db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        if (!$unit['active']) { $this->note($entity, 'matched_inactive'); }
        // Sede emparejada: solo se completan pais y ciudad vacios; nunca se pisa lo que el panel ya tiene.
        $prev = [];
        foreach ($geo as $f => $v) { if ($v !== null && $unit[$f] === null) { $prev[$f] = null; } }
        if ($prev) {
            $this->db->run('UPDATE org_units SET country_code=COALESCE(country_code,?),city=COALESCE(city,?),version=version+1 WHERE tenant_id=? AND id=?', [$geo['country_code'], $geo['city'], $this->tenant, $id]);
            $this->record('org_unit', $id, $ext, 'updated', $prev);
            $this->count($entity, 'update', "match_$how");
        } else {
            $this->record('org_unit', $id, $ext, 'matched');
            $this->count($entity, 'match', $how);
        }
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
                $same = array_values(array_filter($this->db->run('SELECT id,name FROM schedules WHERE tenant_id=? AND placeholder=FALSE', [$this->tenant])->fetchAll(), fn ($s) => self::clean($s['name']) === $name));
                if (count($same) > 1) { $this->count('horarios', 'error', 'name_ambiguous'); continue; }
                if ($same) { $id = $same[0]['id']; $how = 'name'; }
            }
            if ($id !== null) {
                // Emparejado: el horario de Keeper se conserva tal cual.
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
        $tier = $this->db->one('SELECT id FROM tier WHERE tenant_id=? AND is_default=1 AND active=TRUE', [$this->tenant])['id'] ?? null;
        foreach ($items as $it) {
            $ext = self::ext($it['external_id'] ?? null); $name = self::clean($it['display_name'] ?? null); $status = $it['status'] ?? null;
            if ($ext === null || $name === '' || mb_strlen($name) > 160 || !in_array($status, ['active', 'inactive'], true)) { $this->count('personas', 'error', 'invalid_item'); continue; }
            if (isset($this->map["user|$ext"])) { $this->count('personas', 'error', 'duplicate_external_id'); continue; }
            $email = $it['email'] ?? null;
            if ($email !== null && (!is_string($email) || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) { $email = null; $this->note('personas', 'invalid_email_dropped'); }
            $document = is_string($it['document'] ?? null) ? DeviceIntake::document($it['document']) : null;
            if ($document !== null && (strlen($document) < 3 || strlen($document) > 40)) { $document = null; $this->note('personas', 'invalid_document_dropped'); }
            $this->db->run('SAVEPOINT one_import_person');
            try { $this->person($it, $ext, $name, $email, $status, $document, $tier); $this->db->run('RELEASE SAVEPOINT one_import_person'); }
            catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
                $this->db->run('ROLLBACK TO SAVEPOINT one_import_person');
                unset($this->map["user|$ext"]);
                $this->count('personas', 'error', 'duplicate_value');
            }
        }
    }
    private function person(array $it, string $ext, string $name, ?string $email, string $status, ?string $document, ?string $tier): void
    {
        $retired = $status === 'inactive';
        $id = $this->previous('user', $ext); $how = 'previous_import';
        if ($id === null && $document !== null) {
            $match = array_column($this->db->run("SELECT DISTINCT user_id FROM user_external_refs WHERE tenant_id=? AND source IN ('document','k3:cc') AND external_ref=?", [$this->tenant, $document])->fetchAll(), 'user_id');
            if (count($match) > 1) { $this->count('personas', 'error', 'document_ambiguous'); return; }
            if ($match) { $id = $match[0]; $how = 'document'; }
        }
        $refs = $this->refs($it, $retired);
        if ($id !== null) { $this->updateMatched($id, $ext, $email, $retired, $refs, $how); return; }
        if (is_string($refs)) { $this->count('personas', 'error', $refs); return; }
        // Correo de otra persona (activa o retirada): entra sin correo; se completa luego en el panel.
        if ($email !== null && $this->db->one('SELECT id FROM users WHERE tenant_id=? AND email=?', [$this->tenant, $email])) { $email = null; $this->note('personas', 'email_in_use_dropped'); }
        $id = Util::bin(Util::uuid());
        $at = $this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'];
        $this->db->run('INSERT INTO users (tenant_id,id,display_name,email,status,firm_id,site_id,area_id,position_id,schedule_id,company_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$this->tenant, $id, $name, $email, $status, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id']]);
        $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$this->tenant, Util::bin(Util::uuid()), $id, $refs['firm_id'], $refs['site_id'], $refs['area_id'], $refs['position_id'], $refs['schedule_id'], $refs['company_id'], $at]);
        AccessGrants::grantDefault($this->db, $this->tenant, $id);
        if (!$retired && $tier !== null) { $this->db->run("INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at,status) VALUES (?,?,?,?,UTC_TIMESTAMP(6),'active')", [$this->tenant, Util::bin(Util::uuid()), $id, $tier]); }
        if ($document !== null) {
            if ($this->db->one("SELECT user_id FROM user_external_refs WHERE tenant_id=? AND source='document' AND external_ref=?", [$this->tenant, $document])) { $this->note('personas', 'document_in_use_dropped'); }
            else { $this->db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)", [$this->tenant, $id, $document]); }
        } else { $this->note('personas', 'without_document'); }
        $this->map["user|$ext"] = $id;
        $this->record('user', $id, $ext, 'created');
        $this->count('personas', 'create', $retired ? 'inactive_created' : 'active_created');
    }
    /**
     * Persona que ya estaba en Keeper (por cedula) o que creo un lote anterior. Nunca se tocan su acceso, cuenta de panel,
     * nombre ni estado. Activa en One: su ubicacion pasa a la de One (lo anterior queda en el lote, solo ids) y el correo
     * solo se completa si estaba vacio. Retirada en One: se vincula sin cambios.
     */
    private function updateMatched(string $id, string $ext, ?string $email, bool $retired, array|string $refs, string $how): void
    {
        $this->map["user|$ext"] = $id;
        if ($retired) { $this->record('user', $id, $ext, 'matched'); $this->count('personas', 'match', "{$how}_inactive_in_source_kept"); return; }
        if (is_string($refs)) { $this->record('user', $id, $ext, 'matched'); $this->count('personas', 'error', "matched_$refs"); return; }
        $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        $prev = []; $set = [];
        foreach (self::PLACE as $col) { if ($u[$col] !== $refs[$col]) { $prev[$col] = $u[$col] === null ? null : bin2hex($u[$col]); $set[$col] = $refs[$col]; } }
        if ($u['email'] === null && $email !== null && !$this->db->one('SELECT id FROM users WHERE tenant_id=? AND email=?', [$this->tenant, $email])) { $prev['email'] = null; $set['email'] = $email; }
        if (!$set) { $this->record('user', $id, $ext, 'matched'); $this->count('personas', 'match', $how); return; }
        $cols = implode(',', array_map(fn ($c) => "$c=?", array_keys($set)));
        $this->db->run("UPDATE users SET $cols,version=version+1 WHERE tenant_id=? AND id=?", [...array_values($set), $this->tenant, $id]);
        if (array_intersect(array_keys($set), self::PLACE)) { $this->reassign($id); }
        $this->record('user', $id, $ext, 'updated', $prev);
        $this->count('personas', 'update', "match_$how");
    }
    /** Cierra la asignacion abierta y abre otra con la ubicacion actual (igual que el panel). */
    private function reassign(string $id): void
    {
        $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
        $at = $this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'];
        $this->db->run('UPDATE user_assignments SET ends_at=? WHERE tenant_id=? AND user_id=? AND ends_at IS NULL AND starts_at<?', [$at, $this->tenant, $id, $at]);
        $this->db->run('DELETE FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$this->tenant, $id]);
        $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$this->tenant, Util::bin(Util::uuid()), $id, $u['firm_id'], $u['site_id'], $u['area_id'], $u['position_id'], $u['schedule_id'], $u['company_id'], $at]);
    }
    /**
     * Columnas de ubicacion o el codigo de error. Sin dato: empresa vacia; «Sin firma/área/cargo/horario»
     * (Placeholders, el mismo de panel y DataSync, por la marca de 0046). Una referencia que no resuelve: error en una
     * activa, placeholder en una retirada. Sin sede: error en una activa, «Sin sede» en una retirada (como DataSync).
     */
    private function refs(array $it, bool $lenient): array|string
    {
        $out = [];
        foreach (self::REFS as $kind => $field) {
            $v = $it[$field] ?? null;
            if ($v !== null && self::ext($v) === null) { return 'invalid_reference'; }
            $id = $v === null ? null : ($this->map[($kind === 'schedule' ? 'schedule|' : 'org_unit|') . $v] ?? null);
            if ($id !== null && $kind !== 'schedule' && !$this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND kind=?', [$this->tenant, $id, $kind])) { $id = null; }
            if ($id === null && $v !== null && !$lenient) { return 'unknown_reference'; }
            if ($id === null && $kind === 'site' && !$lenient) { return 'missing_site'; }
            $out[$kind . '_id'] = $id ?? $this->placeholder($kind);
        }
        return $out;
    }
    private function placeholder(string $kind): ?string
    {
        if ($kind === 'company') { return null; }
        if ($kind === 'schedule') {
            $id = Placeholders::schedule($this->db, $this->tenant, $this->db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$this->tenant])['timezone'] ?? 'UTC');
            if (!isset($this->existingSchedules[$id]) && !isset($this->items['schedule|' . bin2hex($id)])) { $this->record('schedule', $id, null, 'created'); $this->count('placeholders', 'create', 'schedule'); }
            elseif (isset($this->existingSchedules[$id]) && !isset($this->counts['placeholders']['codes']['reused_schedule'])) { $this->note('placeholders', 'reused_schedule'); }
            return $id;
        }
        $id = Placeholders::unit($this->db, $this->tenant, $kind);
        if (!isset($this->existingUnits[$id]) && !isset($this->items['org_unit|' . bin2hex($id)])) { $this->record('org_unit', $id, null, 'created'); $this->count('placeholders', 'create', $kind); }
        elseif (isset($this->existingUnits[$id]) && !isset($this->counts['placeholders']['codes']["reused_$kind"])) { $this->note('placeholders', "reused_$kind"); }
        return $id;
    }

    // ---- Jerarquia --------------------------------------------------------------------------------------------------
    private function hierarchy(array $items): void
    {
        foreach ($items as $it) {
            $ext = self::ext($it['external_id'] ?? null);
            if ($ext === null || ($it['status'] ?? null) !== 'active' || !array_key_exists('reports_to_external_id', $it)) { continue; }
            $id = $this->map["user|$ext"] ?? null; if ($id === null) { continue; }
            $bossExt = $it['reports_to_external_id'];
            if ($bossExt === null) { continue; }  // Sin jefe en One: se conserva lo que haya en Keeper.
            $boss = is_string($bossExt) ? ($this->map["user|$bossExt"] ?? null) : null;
            if ($boss === null) { $this->count('jerarquia', 'error', 'unknown_reference'); continue; }
            $u = $this->db->one('SELECT reports_to_user_id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            if ($u['reports_to_user_id'] === $boss) { $this->count('jerarquia', 'match'); continue; }
            for ($m = $boss, $n = 0; $m !== null && $n < 60; $n++) {
                if ($m === $id) { $this->count('jerarquia', 'error', 'hierarchy_cycle'); continue 2; }
                $m = $this->db->one('SELECT reports_to_user_id r FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $m])['r'] ?? null;
            }
            $this->db->run('UPDATE users SET reports_to_user_id=?,version=version+1 WHERE tenant_id=? AND id=?', [$boss, $this->tenant, $id]);
            $created = ($this->items['user|' . bin2hex($id)][3] ?? null) === 'created';
            $this->record('user', $id, $ext, $created ? 'created' : 'updated', $created ? null : ['reports_to_user_id' => $u['reports_to_user_id'] === null ? null : bin2hex($u['reports_to_user_id'])]);
            $this->count('jerarquia', 'update', $created ? 'created_person' : 'matched_person');
        }
    }

    // ---- Borrado de un lote -------------------------------------------------------------------------------------------
    /**
     * Quita SOLO lo que el lote creo. Lo emparejado se deja (con $forceUnassign, las personas emparejadas que apuntan a
     * algo que se borra vuelven a su valor anterior anotado, o al placeholder). Personas creadas con equipos o cuenta de
     * panel: se informan y se saltan; con $forceUnassign se retiran (como en el panel). Unidades: misma regla que el
     * panel (en uso = no; solo historial = deleted_at). Nunca se borra auditoria.
     */
    public function wipe(string $batch, bool $apply, bool $forceUnassign = false): array
    {
        if (!self::hasManifestTables($this->db)) { throw new \RuntimeException('migration_missing'); }
        $b = $this->db->one('SELECT * FROM import_batches WHERE id=?', [$batch]);
        if (!$b) { throw new \RuntimeException('batch_not_found'); }
        $this->tenant = $t = $b['tenant_id']; $this->counts = [];
        $this->db->pdo->beginTransaction();
        try {
            $items = $this->db->run('SELECT * FROM import_batch_items WHERE tenant_id=? AND batch_id=? AND wiped_at IS NULL', [$t, $batch])->fetchAll();
            $created = ['org_unit' => [], 'schedule' => [], 'user' => []];
            foreach ($items as $i) { if ($i['action'] === 'created') { $created[$i['entity_type']][$i['entity_id']] = true; } }
            $result = [];  // "tipo|hexid" => wipe_result
            $doomed = fn (string $col, ?string $v): bool => $v !== null && isset($created[$col === 'schedule_id' ? 'schedule' : ($col === 'reports_to_user_id' ? 'user' : 'org_unit')][$v]);

            // 1) Personas que NO creo el lote y apuntan a algo que se borra.
            $blockers = [];
            foreach ($this->db->run('SELECT * FROM users WHERE tenant_id=?', [$t])->fetchAll() as $u) {
                if (isset($created['user'][$u['id']])) { continue; }
                $hit = array_values(array_filter([...self::PLACE, 'reports_to_user_id'], fn ($c) => $doomed($c, $u[$c])));
                if (!$hit) { continue; }
                if (!$forceUnassign) { foreach ($hit as $c) { $blockers[$c][$u[$c]] = true; } $this->count('personas_existentes', 'skip', 'points_to_import'); continue; }
                $prev = $this->previousFor($u['id']);
                $set = [];
                foreach ($hit as $c) {
                    $p = isset($prev[$c]) && $prev[$c] !== null ? hex2bin($prev[$c]) : null;
                    if ($p !== null && ($doomed($c, $p) || !$this->exists($c, $p))) { $p = null; }
                    if ($p === null && $c !== 'reports_to_user_id' && $c !== 'company_id') {
                        $p = $c === 'site_id' ? null : ($c === 'schedule_id' ? Placeholders::schedule($this->db, $t, $this->db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$t])['timezone'] ?? 'UTC') : Placeholders::unit($this->db, $t, substr($c, 0, -3)));
                        if ($p !== null && $doomed($c, $p)) { unset($created[$c === 'schedule_id' ? 'schedule' : 'org_unit'][$p]); $this->note('placeholders', 'kept_in_use'); }
                    }
                    if ($p === null && $c === 'site_id') { $blockers[$c][$u[$c]] = true; $this->count('personas_existentes', 'error', 'site_without_previous'); continue 2; }
                    $set[$c] = $p;
                }
                $cols = implode(',', array_map(fn ($c) => "$c=?", array_keys($set)));
                $this->db->run("UPDATE users SET $cols,version=version+1 WHERE tenant_id=? AND id=?", [...array_values($set), $t, $u['id']]);
                if (array_intersect(array_keys($set), self::PLACE)) { $this->tenant = $t; $this->reassign($u['id']); }
                $this->count('personas_existentes', 'update', 'restored_previous');
            }

            // 2) Personas creadas.
            $deletable = [];
            foreach (array_keys($created['user']) as $id) {
                $u = $this->db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$t, $id]);
                if (!$u) { $result["user|" . bin2hex($id)] = 'gone'; continue; }
                $why = null;
                if ($this->db->one('SELECT id FROM devices WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id]) || $this->db->one('SELECT id FROM device_assignments WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id])) { $why = 'has_devices'; }
                elseif ($this->db->one('SELECT id FROM admin_accounts WHERE tenant_id=? AND user_id=? LIMIT 1', [$t, $id]) || $u['panel_login_enabled']) { $why = 'has_panel_account'; }
                elseif (isset($blockers['reports_to_user_id'][$id])) { $why = 'boss_of_existing_person'; }
                if ($why !== null) {
                    if (!$forceUnassign || $why === 'boss_of_existing_person') { $this->count('personas', 'skip', $why); $result["user|" . bin2hex($id)] = "skip:$why"; $this->keepRefs($u, $created); continue; }
                    $this->retire($id); $this->count('personas', 'retire', $why); $result["user|" . bin2hex($id)] = 'retired'; $this->keepRefs($u, $created); continue;
                }
                $deletable[$id] = $u;
            }
            foreach (array_keys($deletable) as $id) { $this->db->run('UPDATE users SET reports_to_user_id=NULL WHERE tenant_id=? AND reports_to_user_id=?', [$t, $id]); }
            foreach ($deletable as $id => $u) {
                $this->db->run('SAVEPOINT one_wipe_user');
                try {
                    AccessGrants::removeForUser($this->db, $t, $id);
                    foreach (['subscriptions', 'user_assignments', 'user_external_refs'] as $table) { $this->db->run("DELETE FROM $table WHERE tenant_id=? AND user_id=?", [$t, $id]); }
                    $this->db->run('DELETE FROM users WHERE tenant_id=? AND id=?', [$t, $id]);
                    $this->db->run('RELEASE SAVEPOINT one_wipe_user');
                    $this->count('personas', 'delete'); $result["user|" . bin2hex($id)] = 'deleted';
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1451) { throw $e; }
                    $this->db->run('ROLLBACK TO SAVEPOINT one_wipe_user');
                    $this->retire($id); $this->keepRefs($u, $created);
                    $this->count('personas', 'retire', 'has_history'); $result["user|" . bin2hex($id)] = 'retired';
                }
            }

            // 3) Horarios creados.
            foreach (array_keys($created['schedule']) as $id) {
                $key = 'schedule|' . bin2hex($id);
                if (isset($blockers['schedule_id'][$id]) || $this->db->one('SELECT id FROM users WHERE tenant_id=? AND schedule_id=? LIMIT 1', [$t, $id])) { $this->count('horarios', 'skip', 'in_use'); $result[$key] = 'skip:in_use'; continue; }
                $this->db->run('SAVEPOINT one_wipe_schedule');
                try {
                    $this->db->run('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=?', [$t, $id]);
                    $this->db->run('DELETE FROM schedules WHERE tenant_id=? AND id=?', [$t, $id]);
                    $this->db->run('RELEASE SAVEPOINT one_wipe_schedule');
                    $this->count('horarios', 'delete'); $result[$key] = 'deleted';
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) !== 1451) { throw $e; }
                    $this->db->run('ROLLBACK TO SAVEPOINT one_wipe_schedule');
                    $this->count('horarios', 'skip', 'in_use'); $result[$key] = 'skip:in_use';
                }
            }

            // 4) Unidades creadas: hijas primero; misma regla que «Eliminar» del panel.
            $pending = array_keys($created['org_unit']);
            while ($pending) {
                $next = [];
                foreach ($pending as $id) {
                    $key = 'org_unit|' . bin2hex($id);
                    $unit = $this->db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$t, $id]);
                    if (!$unit || $unit['deleted_at'] !== null) { $result[$key] = 'gone'; continue; }
                    $busy = $this->unitBusy($id);
                    foreach ($blockers as $col => $ids) { if ($busy === null && $col !== 'reports_to_user_id' && isset($ids[$id])) { $busy = 'existing_person'; } }
                    if ($busy === 'child') { $next[] = $id; continue; }
                    $entity = self::ORG[$unit['kind']] ?? 'unidades';
                    if ($busy !== null) { $this->count($entity, 'skip', "in_use_$busy"); $result[$key] = "skip:in_use_$busy"; continue; }
                    $this->db->run('SAVEPOINT one_wipe_unit');
                    try { $this->db->run('DELETE FROM org_units WHERE tenant_id=? AND id=?', [$t, $id]); $this->db->run('RELEASE SAVEPOINT one_wipe_unit'); $this->count($entity, 'delete'); $result[$key] = 'deleted'; }
                    catch (\PDOException $e) {
                        if (($e->errorInfo[1] ?? 0) !== 1451) { throw $e; }
                        $this->db->run('ROLLBACK TO SAVEPOINT one_wipe_unit');
                        $this->db->run('UPDATE org_units SET deleted_at=UTC_TIMESTAMP(6),active=FALSE,version=version+1 WHERE tenant_id=? AND id=?', [$t, $id]);
                        $this->count($entity, 'delete', 'marked_deleted_history'); $result[$key] = 'marked_deleted';
                    }
                }
                if (count($next) === count($pending)) {
                    foreach ($next as $id) { $u = $this->db->one('SELECT kind FROM org_units WHERE tenant_id=? AND id=?', [$t, $id]); $this->count(self::ORG[$u['kind']] ?? 'unidades', 'skip', 'in_use_child'); $result['org_unit|' . bin2hex($id)] = 'skip:in_use_child'; }
                    break;
                }
                $pending = $next;
            }
            foreach ($items as $i) { if ($i['action'] !== 'created') { $this->count('emparejados', 'keep', $i['entity_type']); } }

            $left = 0;
            foreach ($items as $i) {
                $r = $i['action'] === 'created' ? ($result[$i['entity_type'] . '|' . bin2hex($i['entity_id'])] ?? 'skip:used_by_kept_person') : 'kept_matched';
                if ($r === 'skip:used_by_kept_person') { $this->count($i['entity_type'] === 'schedule' ? 'horarios' : 'unidades', 'skip', 'used_by_kept_person'); }
                $done = in_array($r, ['deleted', 'marked_deleted', 'gone', 'kept_matched'], true);
                if (!$done) { $left++; }
                $this->db->run('UPDATE import_batch_items SET wipe_result=?,wiped_at=' . ($done ? 'UTC_TIMESTAMP(6)' : 'NULL') . ' WHERE tenant_id=? AND batch_id=? AND entity_type=? AND entity_id=?', [substr($r, 0, 40), $t, $batch, $i['entity_type'], $i['entity_id']]);
            }
            $this->db->run('UPDATE import_batches SET status=?,wipe_counts=?,wiped_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$left ? 'partially_wiped' : 'wiped', Util::json($this->counts), $t, $batch]);
            if (!$apply) { $this->db->pdo->rollBack(); return ['applied' => false, 'tenant' => Util::id($t), 'counts' => $this->counts, 'left' => $left]; }
            $this->audit($batch, 'import.one_snapshot.wiped', ['org_units', 'schedules', 'users'], self::summary($this->counts));
            $this->db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)', [$t]);
            HolidayCalendars::refresh($this->db, $t);
            $this->db->pdo->commit();
            return ['applied' => true, 'tenant' => Util::id($t), 'counts' => $this->counts, 'left' => $left];
        } catch (\Throwable $e) {
            if ($this->db->pdo->inTransaction()) { $this->db->pdo->rollBack(); }
            throw $e;
        }
    }
    /** Lo que una persona retirada o saltada sigue usando no se puede borrar. */
    private function keepRefs(array $u, array &$created): void
    {
        foreach (self::PLACE as $c) { if ($u[$c] !== null) { unset($created[$c === 'schedule_id' ? 'schedule' : 'org_unit'][$u[$c]]); } }
    }
    private function exists(string $col, string $id): bool
    {
        if ($col === 'reports_to_user_id') { return (bool) $this->db->one('SELECT id FROM users WHERE tenant_id=? AND id=?', [$this->tenant, $id]); }
        if ($col === 'schedule_id') { return (bool) $this->db->one('SELECT id FROM schedules WHERE tenant_id=? AND id=?', [$this->tenant, $id]); }
        return (bool) $this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND id=? AND deleted_at IS NULL', [$this->tenant, $id]);
    }
    /** Valores anteriores anotados para una persona emparejada (el primer lote que la cambio manda). */
    private function previousFor(string $user): array
    {
        $out = [];
        foreach ($this->db->run("SELECT i.previous FROM import_batch_items i JOIN import_batches b ON b.tenant_id=i.tenant_id AND b.id=i.batch_id WHERE i.tenant_id=? AND i.entity_type='user' AND i.entity_id=? AND i.action='updated' AND i.previous IS NOT NULL ORDER BY b.created_at", [$this->tenant, $user])->fetchAll() as $r) {
            $out += json_decode($r['previous'], true) ?: [];
        }
        return $out;
    }
    private function unitBusy(string $id): ?string
    {
        $t = $this->tenant;
        $checks = [
            'child' => ['SELECT COUNT(*) n FROM org_units WHERE tenant_id=? AND parent_id=? AND deleted_at IS NULL', [$t, $id]],
            'people' => ["SELECT COUNT(*) n FROM users WHERE tenant_id=? AND status='active' AND ? IN (firm_id,site_id,area_id,position_id,company_id)", [$t, $id]],
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
    /** Retiro con la misma semantica que el panel (estado inactivo, equipos revocados, sesiones cerradas). */
    private function retire(string $id): void
    {
        $t = $this->tenant;
        $this->db->run("UPDATE users SET status='inactive',panel_login_enabled=FALSE,version=version+1,auth_version=auth_version+1 WHERE tenant_id=? AND id=?", [$t, $id]);
        $this->db->run("UPDATE enrollments SET status='revoked' WHERE tenant_id=? AND user_id=? AND status='pending'", [$t, $id]);
        $this->db->run("UPDATE devices SET status='revoked',auth_version=auth_version+1,version=version+1 WHERE tenant_id=? AND user_id=? AND status='active'", [$t, $id]);
        $this->db->run("UPDATE device_command c JOIN devices d ON d.tenant_id=c.tenant_id AND d.id=c.device_id SET c.status='cancelled' WHERE d.tenant_id=? AND d.user_id=? AND c.status IN ('pending','delivered')", [$t, $id]);
        $this->db->run('UPDATE device_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND user_id=? AND revoked_at IS NULL', [$t, $id]);
        $this->db->run('UPDATE admin_accounts SET auth_version=auth_version+1 WHERE tenant_id=? AND user_id=?', [$t, $id]);
    }

    // ---- Auditoria y salida -------------------------------------------------------------------------------------------
    private function audit(string $batch, string $action, array $fields, string $reason): void
    {
        $actor = $this->db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system' ORDER BY id LIMIT 1", [$this->tenant]);
        if (!$actor) { $actor = ['id' => Util::bin(Util::uuid())]; $this->db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')", [$this->tenant, $actor['id']]); }
        $this->db->run("INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields,reason) VALUES (?,?,?,'system',?,'import_batch',?,?,'allowed',?,?)",
            [$this->tenant, Util::bin(Util::uuid()), $actor['id'], $action, $batch, Util::bin(Util::uuid()), Util::json($fields), mb_substr($reason, 0, 500)]);
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
