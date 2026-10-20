<?php
declare(strict_types=1);

// Construye en memoria el roster canonico keeper.roster.v1 de AZC One legacy (docs/architecture/v4-puente-one-legacy.md §2)
// a partir de un PDO conectado a la BD de One. Lo usan extract.php (CLI, escribe archivos) y serve.php en modo vivo.
// Solo consulta columnas permitidas: nunca password, pin, photo, contacto personal, tarjeta, archivos ni datos de firmas
// cliente (manager, mail_manager). Todas las consultas pasan por select(), que rechaza lo que no sea un SELECT simple.
// Compatible con PHP 8.0 (el hosting de One sirve 8.0 por defecto): sin enums, readonly ni el tipo never.

final class RosterError extends RuntimeException
{
    // $reason: codigo corto sin datos personales (se puede registrar en el log y devolver por HTTP).
    public function __construct(string $message, public string $reason = 'build_failed') { parent::__construct($message); }
}

final class RosterBuilder
{
    public const SCHEMA = 'keeper.roster.v1';
    public const ACTIVE_ROLES = ['empleado', 'administrador', 'nomina', 'talento_humano', 'it'];
    private const ONE_DEFAULT_START = '08:00';  // admin/registros.php de One: HORA_ENTRADA
    private const ONE_DEFAULT_END = '16:30';    // admin/registros.php de One: HORA_SALIDA
    private const LUNCH = ['12:00', '13:00'];
    private const LUNCH_MIN_MINUTES = 480;      // almuerzo solo en jornadas de 8 h o mas (decision P4)
    private const DAY_LETTERS = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];
    private const ONE_CONSTANTS = ['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_PORT'];

    /** @var callable(string): void|null */
    private $onWarn;

    public function __construct(private PDO $db, private array $companyMap, private array $siteMap, private bool $lunch = true, private int $minActive = 150, ?callable $onWarn = null)
    {
        $this->onWarn = $onWarn;
    }

    /** company-map.json y sites-location.json de $dir. */
    public static function loadMaps(string $dir): array
    {
        return [
            json_decode((string) file_get_contents("$dir/company-map.json"), true, 16, JSON_THROW_ON_ERROR),
            json_decode((string) file_get_contents("$dir/sites-location.json"), true, 16, JSON_THROW_ON_ERROR),
        ];
    }

    // ---- Conexion de solo lectura -------------------------------------------------------------------------------------

    /**
     * Lee DB_HOST, DB_NAME, DB_USER, DB_PASS (y DB_PORT si existe) del config.php de One SIN ejecutarlo: el archivo es
     * parte de la app de One y puede abrir sesiones, conectar o mandar cabeceras. Solo acepta define('DB_X', <literal>)
     * con comillas simples, dobles sin '$' ni '\', o un entero. Una constante definida dos veces con valores distintos
     * (p. ej. en un if por entorno) o con una expresion se rechaza: mejor fallar que conectar a la BD equivocada.
     * Nunca se registran ni se devuelven los valores.
     */
    public static function oneCredentials(string $file): array
    {
        $text = is_file($file) && is_readable($file) ? @file_get_contents($file) : false;
        if ($text === false) { throw new RosterError('one config unreadable', 'one_config_unreadable'); }
        $found = [];
        $re = '/\bdefine\s*\(\s*([\'"])(' . implode('|', self::ONE_CONSTANTS) . ')\1\s*,\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"[^"$\\\\]*"|\d+)\s*(?:,\s*(?:true|false)\s*)?\)/i';
        preg_match_all($re, $text, $m, PREG_SET_ORDER);
        foreach ($m as $d) {
            $raw = $d[3];
            $value = $raw[0] === '\'' ? strtr(substr($raw, 1, -1), ['\\\\' => '\\', '\\\'' => '\'']) : ($raw[0] === '"' ? substr($raw, 1, -1) : $raw);
            $name = strtoupper($d[2]);
            if (isset($found[$name]) && $found[$name] !== $value) { throw new RosterError('one config ambiguous', 'one_config_ambiguous'); }
            $found[$name] = $value;
        }
        // Una definicion que no es literal (getenv(), $_ENV, concatenacion) no la captura la regex: se detecta y se rechaza.
        foreach (self::ONE_CONSTANTS as $name) {
            $total = preg_match_all('/\bdefine\s*\(\s*([\'"])' . $name . '\1\s*,/i', $text);
            if ($total > 0 && !isset($found[$name])) { throw new RosterError('one config not literal', 'one_config_unparseable'); }
        }
        foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
            if (!isset($found[$name])) { throw new RosterError('one config incomplete', 'one_config_incomplete'); }
        }
        if (isset($found['DB_PORT']) && !preg_match('/^\d{1,5}$/', $found['DB_PORT'])) { throw new RosterError('one config invalid port', 'one_config_unparseable'); }
        return $found;
    }

    /** PDO a la BD de One. Antes de leer hay que llamar a readOnlySession(). $port (config del puente) gana sobre DB_PORT. */
    public static function connect(array $credentials, ?int $port = null, int $timeout = 5): PDO
    {
        $port ??= isset($credentials['DB_PORT']) ? (int) $credentials['DB_PORT'] : null;
        $dsn = 'mysql:host=' . $credentials['DB_HOST'] . ($port !== null ? ';port=' . $port : '') . ';dbname=' . $credentials['DB_NAME'] . ';charset=utf8mb4';
        try {
            $db = self::pdo($dsn, $credentials['DB_USER'], $credentials['DB_PASS'], $timeout);
        } catch (PDOException $e) {
            // El mensaje de PDO trae el usuario: no se propaga, solo el SQLSTATE.
            throw new RosterError('db connect failed sqlstate=' . preg_replace('/[^0-9A-Z]/', '', (string) $e->getCode()), 'db_unavailable');
        }
        return $db;
    }

    private static function pdo(string $dsn, string $user, #[\SensitiveParameter] string $password, int $timeout): PDO
    {
        return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => $timeout, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false]);
    }

    /**
     * Sesion de solo lectura ANTES de cualquier consulta de datos: UTC, transacciones READ ONLY por defecto y una
     * transaccion READ ONLY abierta con instantanea consistente (todas las tablas se leen en el mismo punto). Comprueba
     * que el motor la acepto. Las sentencias SET/START son fijas y no pasan por select() porque no son consultas de datos.
     * Devuelve la hora UTC de MySQL (mismo reloj que los updated_at, formato generated_at) tomada ANTES de abrir la
     * instantanea: asi generated_at <= momento de lectura y un cambio concurrente entra en la vuelta siguiente.
     */
    public static function readOnlySession(PDO $db): string
    {
        $db->exec("SET time_zone = '+00:00'");
        $db->exec('SET SESSION TRANSACTION READ ONLY');
        $now = self::query($db, "SELECT DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%dT%H:%i:%sZ') AS t")[0]['t'];
        try { $db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); }
        catch (PDOException $e) { $db->exec('START TRANSACTION READ ONLY'); }
        $flag = null;
        foreach (['@@SESSION.transaction_read_only', '@@SESSION.tx_read_only'] as $var) {
            try { $flag = self::query($db, "SELECT $var AS ro")[0]['ro'] ?? null; break; } catch (PDOException $e) { continue; }
        }
        if ((int) $flag !== 1) { throw new RosterError('read only session not active', 'read_only_unavailable'); }
        return $now;
    }

    private static function query(PDO $db, string $sql): array
    {
        self::assertSelect($sql);
        return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Unica puerta a la BD: un SELECT, sin ';', sin INTO (OUTFILE/DUMPFILE/variables) y sin bloqueos de filas. */
    public function select(string $sql): array { return self::query($this->db, $sql); }

    public static function assertSelect(string $sql): void
    {
        if (!preg_match('/^\s*SELECT\s/i', $sql) || str_contains($sql, ';') || preg_match('/\bINTO\b|\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\bFOR\s+SHARE\b/i', $sql)) {
            throw new RosterError('non-select sql rejected', 'sql_rejected');
        }
    }

    // ---- Roster ---------------------------------------------------------------------------------------------------------

    private static function clean(?string $v, int $max): string
    {
        $v = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
        return mb_substr($v, 0, $max);
    }
    private static function iso(?string $ts): ?string { return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($ts . ' UTC')); }
    private static function minutes(string $hm): int { return (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2); }

    /**
     * Devuelve ['company' => slug, 'org-units' => [...], 'schedules' => [...], 'people' => [...], 'counts' => [...]].
     * counts no tiene datos personales (es lo que extract.php escribe en counts-<tenant>.json).
     */
    public function build(string $generatedAt): array
    {
        $companyMap = $this->companyMap; $siteMap = $this->siteMap; $lunch = $this->lunch;
        $tenantSlug = $companyMap['tenant_slug'];
        if (!is_string($tenantSlug) || !preg_match('/^[a-z0-9-]{1,64}$/', $tenantSlug)) { throw new RosterError('tenant_slug invalido', 'config_invalid'); }

        $counts = ['generated_at' => $generatedAt, 'tenant' => $tenantSlug, 'lunch_rule' => $lunch ? '12:00-13:00 en dias >= 8 h' : 'sin almuerzo', 'warnings' => []];
        $warn = function (string $w) use (&$counts): void { $counts['warnings'][] = $w; if ($this->onWarn !== null) { ($this->onWarn)($w); } };
        $q = fn (string $sql): array => $this->select($sql);

        // ---- Empresas (kind company) ------------------------------------------------------------------------------------
        $orgUnits = [];
        $codeToCompany = [];
        foreach ($companyMap['companies'] as $c) {
            if (!preg_match('/^[a-z0-9-]{1,48}$/', $c['slug'])) { throw new RosterError('slug de empresa invalido', 'config_invalid'); }
            $ext = 'one-company-' . $c['slug'];
            $orgUnits[] = ['external_id' => $ext, 'kind' => 'company', 'name' => self::clean($c['name'], 120), 'parent_external_id' => null, 'active' => true];
            foreach ($c['one_codes'] as $code) {
                if (isset($codeToCompany[(int) $code])) { throw new RosterError("codigo de compania $code mapeado dos veces", 'config_invalid'); }
                $codeToCompany[(int) $code] = $ext;
            }
        }
        $counts['org-units']['company'] = count($companyMap['companies']);

        // ---- Firmas cliente (sin manager ni mail_manager) -------------------------------------------------------------------
        $n = 0; $skipped = 0;
        foreach ($q('SELECT id, name, updated_at FROM firm ORDER BY id') as $r) {
            $name = self::clean($r['name'], 120);
            if ($name === '') { $skipped++; continue; }
            $orgUnits[] = ['external_id' => 'one-firm-' . $r['id'], 'kind' => 'firm', 'name' => $name, 'parent_external_id' => null, 'active' => true, 'updated_at' => self::iso($r['updated_at'])];
            $n++;
        }
        $counts['org-units']['firm'] = $n; if ($skipped) { $counts['org-units']['firm_skipped_empty_name'] = $skipped; }

        // ---- Sedes (+ pais y ciudad del mapa editable) ----------------------------------------------------------------------
        $siteTz = [];
        $n = 0;
        foreach ($q('SELECT id, nombre, activa FROM sedes ORDER BY id') as $r) {
            $name = self::clean($r['nombre'], 120);
            $loc = $siteMap['sites'][(string) $r['id']] ?? null;
            $item = ['external_id' => 'one-sede-' . $r['id'], 'kind' => 'site', 'name' => $name, 'parent_external_id' => null, 'active' => $r['activa'] === null || (int) $r['activa'] === 1];
            if ($loc === null) {
                $warn("sede one-sede-{$r['id']} sin entrada en sites-location.json: se envia sin pais ni ciudad y con la zona por defecto");
                $counts['org-units']['site_unmapped'] = ($counts['org-units']['site_unmapped'] ?? 0) + 1;
            } else {
                if (($loc['one_name'] ?? $name) !== $name) { $warn("sede one-sede-{$r['id']}: el nombre en One no coincide con one_name del mapa; revisar sites-location.json"); }
            }
            // keeper_name: nombre con el que se envia la sede (decision del dueno 2026-10-06: conservar los nombres de Keeper).
            if ($loc !== null && isset($loc['keeper_name'])) { $item['name'] = self::clean($loc['keeper_name'], 120); }
            // Keeper: campo ausente = conserva, null = borra. Se envian siempre ambos (one-keeper-changes-report.md).
            $item['country_code'] = $loc['country_code'] ?? null;
            $item['city'] = $loc['city'] ?? null;
            if ($item['country_code'] === null) { $counts['org-units']['site_without_country'] = ($counts['org-units']['site_without_country'] ?? 0) + 1; }
            if ($item['city'] === null) { $counts['org-units']['site_without_city'] = ($counts['org-units']['site_without_city'] ?? 0) + 1; }
            $tz = $loc['timezone'] ?? $siteMap['default_timezone'];
            new DateTimeZone($tz);  // lanza si la zona no existe
            $siteTz[(int) $r['id']] = $tz;
            $orgUnits[] = $item;
            $n++;
        }
        $counts['org-units']['site'] = $n;

        // ---- Areas (jerarquicas) y cargos -----------------------------------------------------------------------------------
        $n = 0;
        foreach ($q('SELECT id, nombre, padre_id, updated_at FROM areas ORDER BY id') as $r) {
            $orgUnits[] = ['external_id' => 'one-area-' . $r['id'], 'kind' => 'area', 'name' => self::clean($r['nombre'], 120),
                'parent_external_id' => $r['padre_id'] === null ? null : 'one-area-' . $r['padre_id'], 'active' => true, 'updated_at' => self::iso($r['updated_at'])];
            $n++;
        }
        $counts['org-units']['area'] = $n;
        $n = 0;
        foreach ($q('SELECT id, nombre FROM cargos ORDER BY id') as $r) {
            $orgUnits[] = ['external_id' => 'one-cargo-' . $r['id'], 'kind' => 'position', 'name' => self::clean($r['nombre'], 120), 'parent_external_id' => null, 'active' => true];
            $n++;
        }
        $counts['org-units']['position'] = $n;

        // ---- Horarios: One los guarda por persona; se agrupan por patron (§2.5) -----------------------------------------------
        $rows = [];
        foreach ($q('SELECT employee_id, dia_semana, hora_entrada, hora_salida, activo, updated_at FROM employee_schedule ORDER BY employee_id, dia_semana') as $r) {
            $rows[(int) $r['employee_id']][] = $r;
        }
        $people = $q("SELECT e.id, e.CC, e.first_Name, e.second_Name, e.first_LastName, e.second_LastName, e.mail, e.company, e.id_firm, e.sede_id,
                             e.area_id, e.position_id, e.supervisor_id, e.role, e.created_at, e.updated_at
                      FROM employee e WHERE e.role IS NULL OR e.role <> 'candidato' ORDER BY e.id");
        $roleById = [];
        foreach ($q('SELECT id, role FROM employee') as $r) { $roleById[(int) $r['id']] = $r['role']; }

        $schedules = [];
        $personSchedule = [];
        $invalidDays = 0; $noRows = 0; $noActiveDays = 0;
        foreach ($people as $p) {
            if (!in_array($p['role'], self::ACTIVE_ROLES, true)) { continue; }
            $id = (int) $p['id'];
            $days = [];
            if (!isset($rows[$id])) {
                $noRows++;
                foreach ([1, 2, 3, 4, 5] as $w) { $days[$w] = [self::ONE_DEFAULT_START, self::ONE_DEFAULT_END]; }
            } else {
                foreach ($rows[$id] as $d) {
                    $w = (int) $d['dia_semana'];
                    if ((int) $d['activo'] !== 1 || $w < 1 || $w > 7) { continue; }
                    $s = $d['hora_entrada'] === null ? self::ONE_DEFAULT_START : substr($d['hora_entrada'], 0, 5);
                    $e = $d['hora_salida'] === null ? self::ONE_DEFAULT_END : substr($d['hora_salida'], 0, 5);
                    if (self::minutes($s) >= self::minutes($e)) { $invalidDays++; continue; }
                    $days[$w] = [$s, $e];
                }
                if (!$days) { $noActiveDays++; $personSchedule[$id] = null; continue; }
            }
            ksort($days);
            $tz = $siteTz[(int) $p['sede_id']] ?? $siteMap['default_timezone'];
            $pattern = implode(',', array_map(fn ($w, $v) => "$w={$v[0]}-{$v[1]}", array_keys($days), $days));
            $ext = 'one-hor-' . substr(sha1($tz . '|' . $pattern), 0, 12);
            $personSchedule[$id] = $ext;
            if (!isset($schedules[$ext])) {
                // Nombre: dias consecutivos con la misma franja se agrupan ("L-J 08:00-17:30 · V 08:00-17:00 (Bogota)").
                $groups = [];
                foreach ($days as $w => $v) {
                    $last = array_key_last($groups);
                    if ($last !== null && $groups[$last]['v'] === $v && $groups[$last]['to'] === $w - 1) { $groups[$last]['to'] = $w; }
                    else { $groups[] = ['from' => $w, 'to' => $w, 'v' => $v]; }
                }
                $label = implode(' · ', array_map(fn ($g) => self::DAY_LETTERS[$g['from']] . ($g['to'] > $g['from'] ? '-' . self::DAY_LETTERS[$g['to']] : '') . " {$g['v'][0]}-{$g['v'][1]}", $groups));
                $tzLabel = str_replace('_', ' ', substr($tz, strrpos($tz, '/') + 1));
                $canon = [];
                foreach ($days as $w => [$s, $e]) {
                    $withLunch = $lunch && self::minutes($e) - self::minutes($s) >= self::LUNCH_MIN_MINUTES && self::minutes($s) <= self::minutes(self::LUNCH[0]) && self::minutes($e) >= self::minutes(self::LUNCH[1]);
                    $canon[] = ['weekday' => $w, 'start_local' => $s, 'end_local' => $e, 'lunch' => $withLunch ? ['start_local' => self::LUNCH[0], 'end_local' => self::LUNCH[1]] : null];
                }
                $schedules[$ext] = ['external_id' => $ext, 'name' => mb_substr("$label ($tzLabel)", 0, 120), 'timezone' => $tz, 'days' => $canon, 'active' => true, 'people' => 0];
            }
            $schedules[$ext]['people']++;
        }
        $counts['schedules'] = ['patterns' => count($schedules), 'people_default_one_pattern' => $noRows, 'people_without_active_days' => $noActiveDays, 'invalid_days_discarded' => $invalidDays,
            'people_per_pattern' => array_values(array_map(fn ($s) => $s['people'], $schedules))];
        rsort($counts['schedules']['people_per_pattern']);
        $schedules = array_values(array_map(function ($s) { unset($s['people']); return $s; }, $schedules));
        usort($schedules, fn ($a, $b) => strcmp($a['external_id'], $b['external_id']));

        // ---- Personas (§2.6) ------------------------------------------------------------------------------------------------
        $items = [];
        $c = ['total' => 0, 'active' => 0, 'inactive' => 0, 'active_by_company' => [], 'active_without_firm' => 0, 'active_without_area' => 0, 'active_without_schedule' => 0,
            'active_with_reports_to' => 0, 'email_null' => 0, 'document_null' => 0, 'unmapped_company' => 0];
        $maxUpdated = null;
        foreach ($people as $p) {
            $id = (int) $p['id'];
            $active = in_array($p['role'], self::ACTIVE_ROLES, true);
            $doc = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $p['CC']));
            $name = self::clean(implode(' ', array_filter([trim((string) $p['first_Name']), trim((string) $p['second_Name']), trim((string) $p['first_LastName']), trim((string) $p['second_LastName'])], fn ($x) => $x !== '')), 160);
            $email = strtolower(trim((string) $p['mail']));
            if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { $email = null; $c['email_null']++; }
            $company = $p['company'] === null ? null : ($codeToCompany[(int) $p['company']] ?? null);
            if ($company === null) { $c['unmapped_company']++; }
            $boss = $p['supervisor_id'] === null ? null : (int) $p['supervisor_id'];
            $reportsTo = $boss !== null && $boss !== $id && in_array($roleById[$boss] ?? null, self::ACTIVE_ROLES, true) ? 'one-emp-' . $boss : null;
            $sched = $rows[$id] ?? [];
            $stamps = array_filter([$p['updated_at'] ?? $p['created_at'], ...array_column($sched, 'updated_at')]);
            $updated = $stamps ? max($stamps) : null;
            $item = [
                'external_id' => 'one-emp-' . $id,
                'document' => $doc === '' ? null : $doc,
                'display_name' => $name,
                'email' => $email,
                'company_external_id' => $company,
                'firm_external_id' => $p['id_firm'] === null ? null : 'one-firm-' . $p['id_firm'],
                'site_external_id' => $p['sede_id'] === null ? null : 'one-sede-' . $p['sede_id'],
                'area_external_id' => $p['area_id'] === null ? null : 'one-area-' . $p['area_id'],
                'position_external_id' => $p['position_id'] === null ? null : 'one-cargo-' . $p['position_id'],
                'schedule_external_id' => $active ? $personSchedule[$id] : null,
                'reports_to_external_id' => $active ? $reportsTo : null,
                'status' => $active ? 'active' : 'inactive',
                'hired_on' => null,
                'terminated_on' => null,
                'updated_at' => self::iso($updated),
            ];
            if ($item['document'] === null) { $c['document_null']++; }
            $c['total']++;
            if ($active) {
                $c['active']++;
                $key = $company ?? 'sin_empresa';
                $c['active_by_company'][$key] = ($c['active_by_company'][$key] ?? 0) + 1;
                if ($item['firm_external_id'] === null) { $c['active_without_firm']++; }
                if ($item['area_external_id'] === null) { $c['active_without_area']++; }
                if ($item['schedule_external_id'] === null) { $c['active_without_schedule']++; }
                if ($item['reports_to_external_id'] !== null) { $c['active_with_reports_to']++; }
                if ($item['site_external_id'] === null) { $c['active_without_site'] = ($c['active_without_site'] ?? 0) + 1; }
            } else { $c['inactive']++; }
            $maxUpdated = max($maxUpdated ?? '', $item['updated_at'] ?? '');
            $items[] = $item;
        }
        ksort($c['active_by_company']);
        $counts['people'] = $c;
        if ($c['active'] < $this->minActive) { throw new RosterError("solo {$c['active']} personas activas (< --min-active={$this->minActive}): la copia parece incompleta", 'sanity_failed'); }
        if ($maxUpdated > $generatedAt) { $warn("hay filas actualizadas despues de --generated-at ($maxUpdated): use un generated_at posterior o la vuelta incremental de One v2 las tratara como viejas"); }
        $docs = array_filter(array_column(array_filter($items, fn ($i) => $i['status'] === 'active'), 'document'));
        if (count($docs) !== count(array_unique($docs))) { $warn('hay documentos repetidos entre personas activas'); }
        $mails = array_filter(array_column($items, 'email'));
        if (count($mails) !== count(array_unique($mails))) { $warn('hay correos repetidos (Keeper dara email_in_use a la segunda persona)'); }

        return ['company' => $tenantSlug, 'org-units' => $orgUnits, 'schedules' => $schedules, 'people' => $items, 'counts' => $counts];
    }
}
