<?php
declare(strict_types=1);

// Instantanea UNICA de AZC One legacy -> formato canonico keeper.roster.v1 (docs/architecture/v4-puente-one-legacy.md §2).
// Lee una copia local de la BD de One (cargada desde un volcado) y escribe una pagina JSON por endpoint del conector REST
// de Keeper (org-units, schedules, people) mas manifest.json y counts.json. Solo consulta columnas permitidas: nunca
// password, pin, photo, contacto personal, tarjeta, archivos ni datos de firmas cliente (manager, mail_manager).
// La salida contiene datos personales: va FUERA del repo. Por consola solo salen conteos.
//
// Uso:
//   php extract.php --out=DIR --generated-at=2026-09-22T20:29:35Z [--dsn=mysql:host=127.0.0.1;port=3306;dbname=one_legacy;charset=utf8mb4]
//                   [--user=root] [--no-lunch] [--page-size=500] [--min-active=150] [--force]
//   Contrasena de la BD: variable de entorno ONE_DB_PASSWORD (vacia por defecto).

const SCHEMA = 'keeper.roster.v1';
const ACTIVE_ROLES = ['empleado', 'administrador', 'nomina', 'talento_humano', 'it'];
const ONE_DEFAULT_START = '08:00';  // admin/registros.php de One: HORA_ENTRADA
const ONE_DEFAULT_END = '16:30';    // admin/registros.php de One: HORA_SALIDA
const LUNCH = ['12:00', '13:00'];
const LUNCH_MIN_MINUTES = 480;      // almuerzo solo en jornadas de 8 h o mas (decision P4)
const DAY_LETTERS = [1 => 'L', 2 => 'M', 3 => 'X', 4 => 'J', 5 => 'V', 6 => 'S', 7 => 'D'];

function fail(string $msg): never { fwrite(STDERR, "ERROR: $msg\n"); exit(1); }

$opts = getopt('', ['out:', 'generated-at:', 'dsn:', 'user:', 'no-lunch', 'page-size:', 'min-active:', 'force', 'config-dir:']);
$out = $opts['out'] ?? fail('falta --out=DIR (fuera del repo: la salida tiene datos personales)');
$generatedAt = $opts['generated-at'] ?? fail('falta --generated-at=YYYY-MM-DDTHH:MM:SSZ (momento del volcado, ver README)');
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $generatedAt) || strtotime($generatedAt) === false) { fail('--generated-at debe ser YYYY-MM-DDTHH:MM:SSZ'); }
$dsn = $opts['dsn'] ?? 'mysql:host=127.0.0.1;port=3306;dbname=one_legacy;charset=utf8mb4';
$lunch = !isset($opts['no-lunch']);
$pageSize = (int) ($opts['page-size'] ?? 500);
$minActive = (int) ($opts['min-active'] ?? 150);
if ($pageSize < 1 || $pageSize > 500) { fail('--page-size entre 1 y 500'); }
$configDir = $opts['config-dir'] ?? __DIR__;

$repo = realpath(__DIR__ . '/../..');
$outReal = realpath(dirname(rtrim($out, '/\\'))) ?: fail('la carpeta padre de --out no existe');
if ($repo !== false && str_starts_with(strtolower($outReal . DIRECTORY_SEPARATOR), strtolower($repo . DIRECTORY_SEPARATOR))) { fail('--out no puede estar dentro del repo (datos personales)'); }

$companyMap = json_decode((string) file_get_contents("$configDir/company-map.json"), true, 16, JSON_THROW_ON_ERROR);
$siteMap = json_decode((string) file_get_contents("$configDir/sites-location.json"), true, 16, JSON_THROW_ON_ERROR);
$tenantSlug = $companyMap['tenant_slug'];
if (!preg_match('/^[a-z0-9-]{1,64}$/', $tenantSlug)) { fail('tenant_slug invalido'); }

$counts = ['generated_at' => $generatedAt, 'tenant' => $tenantSlug, 'lunch_rule' => $lunch ? '12:00-13:00 en dias >= 8 h' : 'sin almuerzo', 'warnings' => []];
$warn = function (string $w) use (&$counts): void { $counts['warnings'][] = $w; fwrite(STDERR, "AVISO: $w\n"); };

$db = new PDO($dsn, $opts['user'] ?? 'root', getenv('ONE_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$db->exec("SET time_zone = '+00:00'");
$q = fn (string $sql): array => $db->query($sql)->fetchAll();

function clean(?string $v, int $max): string
{
    $v = trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    return mb_substr($v, 0, $max);
}
function iso(?string $ts): ?string { return $ts === null ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($ts . ' UTC')); }
function minutes(string $hm): int { return (int) substr($hm, 0, 2) * 60 + (int) substr($hm, 3, 2); }

// ---- Empresas (kind company) ----------------------------------------------------------------------------------------
$orgUnits = [];
$codeToCompany = [];
foreach ($companyMap['companies'] as $c) {
    if (!preg_match('/^[a-z0-9-]{1,48}$/', $c['slug'])) { fail('slug de empresa invalido'); }
    $ext = 'one-company-' . $c['slug'];
    $orgUnits[] = ['external_id' => $ext, 'kind' => 'company', 'name' => clean($c['name'], 120), 'parent_external_id' => null, 'active' => true];
    foreach ($c['one_codes'] as $code) {
        if (isset($codeToCompany[(int) $code])) { fail("codigo de compania $code mapeado dos veces"); }
        $codeToCompany[(int) $code] = $ext;
    }
}
$counts['org-units']['company'] = count($companyMap['companies']);

// ---- Firmas cliente (sin manager ni mail_manager) -----------------------------------------------------------------------
$n = 0; $skipped = 0;
foreach ($q('SELECT id, name, updated_at FROM firm ORDER BY id') as $r) {
    $name = clean($r['name'], 120);
    if ($name === '') { $skipped++; continue; }
    $orgUnits[] = ['external_id' => 'one-firm-' . $r['id'], 'kind' => 'firm', 'name' => $name, 'parent_external_id' => null, 'active' => true, 'updated_at' => iso($r['updated_at'])];
    $n++;
}
$counts['org-units']['firm'] = $n; if ($skipped) { $counts['org-units']['firm_skipped_empty_name'] = $skipped; }

// ---- Sedes (+ pais y ciudad del mapa editable) --------------------------------------------------------------------------
$siteTz = [];
$n = 0;
foreach ($q('SELECT id, nombre, activa FROM sedes ORDER BY id') as $r) {
    $name = clean($r['nombre'], 120);
    $loc = $siteMap['sites'][(string) $r['id']] ?? null;
    $item = ['external_id' => 'one-sede-' . $r['id'], 'kind' => 'site', 'name' => $name, 'parent_external_id' => null, 'active' => $r['activa'] === null || (int) $r['activa'] === 1];
    if ($loc === null) {
        $warn("sede one-sede-{$r['id']} sin entrada en sites-location.json: se envia sin pais ni ciudad y con la zona por defecto");
        $counts['org-units']['site_unmapped'] = ($counts['org-units']['site_unmapped'] ?? 0) + 1;
    } else {
        if (($loc['one_name'] ?? $name) !== $name) { $warn("sede one-sede-{$r['id']}: el nombre en One no coincide con one_name del mapa; revisar sites-location.json"); }
    }
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

// ---- Areas (jerarquicas) y cargos ---------------------------------------------------------------------------------------
$n = 0;
foreach ($q('SELECT id, nombre, padre_id, updated_at FROM areas ORDER BY id') as $r) {
    $orgUnits[] = ['external_id' => 'one-area-' . $r['id'], 'kind' => 'area', 'name' => clean($r['nombre'], 120),
        'parent_external_id' => $r['padre_id'] === null ? null : 'one-area-' . $r['padre_id'], 'active' => true, 'updated_at' => iso($r['updated_at'])];
    $n++;
}
$counts['org-units']['area'] = $n;
$n = 0;
foreach ($q('SELECT id, nombre FROM cargos ORDER BY id') as $r) {
    $orgUnits[] = ['external_id' => 'one-cargo-' . $r['id'], 'kind' => 'position', 'name' => clean($r['nombre'], 120), 'parent_external_id' => null, 'active' => true];
    $n++;
}
$counts['org-units']['position'] = $n;

// ---- Horarios: One los guarda por persona; se agrupan por patron (§2.5) ---------------------------------------------------
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
    if (!in_array($p['role'], ACTIVE_ROLES, true)) { continue; }
    $id = (int) $p['id'];
    $days = [];
    if (!isset($rows[$id])) {
        $noRows++;
        foreach ([1, 2, 3, 4, 5] as $w) { $days[$w] = [ONE_DEFAULT_START, ONE_DEFAULT_END]; }
    } else {
        foreach ($rows[$id] as $d) {
            $w = (int) $d['dia_semana'];
            if ((int) $d['activo'] !== 1 || $w < 1 || $w > 7) { continue; }
            $s = $d['hora_entrada'] === null ? ONE_DEFAULT_START : substr($d['hora_entrada'], 0, 5);
            $e = $d['hora_salida'] === null ? ONE_DEFAULT_END : substr($d['hora_salida'], 0, 5);
            if (minutes($s) >= minutes($e)) { $invalidDays++; continue; }
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
        $label = implode(' · ', array_map(fn ($g) => DAY_LETTERS[$g['from']] . ($g['to'] > $g['from'] ? '-' . DAY_LETTERS[$g['to']] : '') . " {$g['v'][0]}-{$g['v'][1]}", $groups));
        $tzLabel = str_replace('_', ' ', substr($tz, strrpos($tz, '/') + 1));
        $canon = [];
        foreach ($days as $w => [$s, $e]) {
            $withLunch = $lunch && minutes($e) - minutes($s) >= LUNCH_MIN_MINUTES && minutes($s) <= minutes(LUNCH[0]) && minutes($e) >= minutes(LUNCH[1]);
            $canon[] = ['weekday' => $w, 'start_local' => $s, 'end_local' => $e, 'lunch' => $withLunch ? ['start_local' => LUNCH[0], 'end_local' => LUNCH[1]] : null];
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

// ---- Personas (§2.6) --------------------------------------------------------------------------------------------------------
$items = [];
$c = ['total' => 0, 'active' => 0, 'inactive' => 0, 'active_by_company' => [], 'active_without_firm' => 0, 'active_without_area' => 0, 'active_without_schedule' => 0,
    'active_with_reports_to' => 0, 'email_null' => 0, 'document_null' => 0, 'unmapped_company' => 0];
$maxUpdated = null;
foreach ($people as $p) {
    $id = (int) $p['id'];
    $active = in_array($p['role'], ACTIVE_ROLES, true);
    $doc = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', (string) $p['CC']));
    $name = clean(implode(' ', array_filter([trim((string) $p['first_Name']), trim((string) $p['second_Name']), trim((string) $p['first_LastName']), trim((string) $p['second_LastName'])], fn ($x) => $x !== '')), 160);
    $email = strtolower(trim((string) $p['mail']));
    if ($email === '' || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { $email = null; $c['email_null']++; }
    $company = $p['company'] === null ? null : ($codeToCompany[(int) $p['company']] ?? null);
    if ($company === null) { $c['unmapped_company']++; }
    $boss = $p['supervisor_id'] === null ? null : (int) $p['supervisor_id'];
    $reportsTo = $boss !== null && $boss !== $id && in_array($roleById[$boss] ?? null, ACTIVE_ROLES, true) ? 'one-emp-' . $boss : null;
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
        'updated_at' => iso($updated),
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
if ($c['active'] < $minActive) { fail("solo {$c['active']} personas activas (< --min-active=$minActive): la copia parece incompleta"); }
if ($maxUpdated > $generatedAt) { $warn("hay filas actualizadas despues de --generated-at ($maxUpdated): use un generated_at posterior o la vuelta incremental de One v2 las tratara como viejas"); }
$docs = array_filter(array_column(array_filter($items, fn ($i) => $i['status'] === 'active'), 'document'));
if (count($docs) !== count(array_unique($docs))) { $warn('hay documentos repetidos entre personas activas'); }
$mails = array_filter(array_column($items, 'email'));
if (count($mails) !== count(array_unique($mails))) { $warn('hay correos repetidos (Keeper dara email_in_use a la segunda persona)'); }

// ---- Escritura: <out>/<tenant>/<endpoint>/NNNN.json + manifest.json + counts.json -------------------------------------------
$root = rtrim($out, '/\\') . DIRECTORY_SEPARATOR . $tenantSlug;
if (is_dir($root)) {
    if (!isset($opts['force'])) { fail("$root ya existe (use --force para reemplazarla)"); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($root);
}
$endpoints = ['org-units' => $orgUnits, 'schedules' => $schedules, 'people' => $items];
$manifest = ['schema' => SCHEMA, 'company' => $tenantSlug, 'generated_at' => $generatedAt, 'source' => 'one-legacy-snapshot', 'lunch' => $lunch, 'page_size' => $pageSize, 'endpoints' => []];
$json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
foreach ($endpoints as $endpoint => $list) {
    $dir = "$root/$endpoint";
    mkdir($dir, 0700, true);
    $pages = array_chunk($list, $pageSize) ?: [[]];
    foreach ($pages as $i => $page) { file_put_contents(sprintf('%s/%04d.json', $dir, $i + 1), json_encode(['items' => $page], $json)); }
    $manifest['endpoints'][$endpoint] = ['pages' => count($pages), 'items' => count($list)];
}
file_put_contents("$root/manifest.json", json_encode($manifest, $json | JSON_PRETTY_PRINT));
file_put_contents(rtrim($out, '/\\') . "/counts-$tenantSlug.json", json_encode($counts, $json | JSON_PRETTY_PRINT));

echo json_encode(['out' => $root, 'endpoints' => $manifest['endpoints'], 'counts' => $counts], $json | JSON_PRETTY_PRINT), "\n";
