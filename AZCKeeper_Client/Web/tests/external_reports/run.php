<?php
/**
 * Pruebas de autorización y contrato de /api/external/reports (K3-ADP-01…06)
 * contra un ENTORNO DE EVALUACIÓN local: base MySQL creada desde
 * migrations/ y sembrada con datos sintéticos (correos .invalid), servida con
 * el servidor embebido de PHP. Nunca toca producción ni el legacy.
 *
 *   php tests/external_reports/run.php            # sembrar, servir, probar, apagar
 *   php tests/external_reports/run.php --serve    # sembrar y dejar el servidor arriba (Ctrl+C para parar)
 *
 * Variables (todas opcionales): KEEPER_TEST_DB_HOST (127.0.0.1), KEEPER_TEST_DB_USER (root),
 * KEEPER_TEST_DB_PASS (''), KEEPER_TEST_DB_NAME (keeper_eval), KEEPER_TEST_PORT (8089),
 * KEEPER_TEST_SECRET (secreto de evaluación).
 */
declare(strict_types=1);

date_default_timezone_set('America/Bogota');

$cfg = [
    'host'   => getenv('KEEPER_TEST_DB_HOST') ?: '127.0.0.1',
    'user'   => getenv('KEEPER_TEST_DB_USER') ?: 'root',
    'pass'   => getenv('KEEPER_TEST_DB_PASS') ?: '',
    'name'   => getenv('KEEPER_TEST_DB_NAME') ?: 'keeper_eval',
    'port'   => (int)(getenv('KEEPER_TEST_PORT') ?: 8089),
    'secret' => getenv('KEEPER_TEST_SECRET') ?: 'eval-secret-' . substr(hash('sha256', 'keeper-eval'), 0, 16),
];
$serveOnly = in_array('--serve', $argv, true);
$root = dirname(__DIR__, 2);

// ─── 1. Base de evaluación ────────────────────────────────────────────────
$pdo = new PDO("mysql:host={$cfg['host']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("DROP DATABASE IF EXISTS `{$cfg['name']}`");
$pdo->exec("CREATE DATABASE `{$cfg['name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$pdo->exec("USE `{$cfg['name']}`");
$pdo->exec("SET time_zone = '-05:00'");
foreach (['keeper_full_schema.sql', 'add_productivity_focus.sql', 'add_install_coverage.sql', 'add_install_coverage_exempt.sql', 'add_firma_historial_desde.sql', 'add_employment_status.sql', 'add_assignment_source.sql'] as $file) {
    $sql = (string)file_get_contents("{$root}/migrations/{$file}");
    // Ejecuta sentencia a sentencia (el driver no admite varias en un prepare con emulación apagada).
    foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
        $statement = trim((string)preg_replace('/^\s*--[^\n]*$/m', '', $statement)); // fuera comentarios de línea
        if ($statement === '') continue;
        try { $pdo->exec($statement); } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) throw $e; // migraciones ya consolidadas
        }
    }
}

$today = new DateTimeImmutable('today');
$d = static fn(int $daysAgo): string => $today->modify("-{$daysAgo} days")->format('Y-m-d');
$now = new DateTimeImmutable('now');
$ts = static fn(string $modify): string => $now->modify($modify)->format('Y-m-d H:i:s');

$seed = [
    "INSERT INTO keeper_sociedades (id, nombre) VALUES (1, 'Sociedad sintética 1'), (2, 'Sociedad sintética 2')",
    "INSERT INTO keeper_firmas (id, nombre, historial_desde) VALUES (1, 'Firma sintética 1', NULL), (2, 'Firma sintética 2', '{$d(3)}')",
    "INSERT INTO keeper_areas (id, nombre) VALUES (10, 'Operaciones'), (11, 'Contabilidad'), (12, 'Tecnología'), (13, 'Legal')",
    "INSERT INTO keeper_cargos (id, nombre, nivel_jerarquico) VALUES (20, 'Analista', 3), (21, 'Coordinador', 2)",
    "INSERT INTO keeper_sedes (id, nombre, codigo, activa) VALUES (1, 'Sede sintética Norte', 'N', 1), (2, 'Sede sintética Centro', 'C', 1)",
    "INSERT INTO keeper_client_releases (version, download_url, is_active) VALUES ('3.4.1', 'https://keeper-eval.invalid/releases/3.4.1.zip', 1)",
    "INSERT INTO keeper_panel_settings (setting_key, setting_value) VALUES ('leisure_apps', '[\"YouTube\"]')",
    "UPDATE keeper_panel_settings SET setting_value = '3' WHERE setting_key = 'install_coverage_heartbeat_days'",
];
$people = [
    // id, legacy, nombre, firm, area, sede, sociedad, dispositivo (last_seen modificador | null), versión
    [101, 1001, 'Colaborador sintético 1', 1, 10, 1, 1, '-30 seconds', '3.4.1'],
    [102, 1002, 'Colaborador sintético 2', 1, 10, 1, 1, '-5 minutes', '3.3.0'],
    [103, 1003, 'Colaborador sintético 3', 1, 11, 2, 1, '-1 hour', '3.4.1'],
    [104, 1004, 'Colaborador sintético 4', 1, 11, 2, 1, null, null],
    [105, 1005, 'Colaborador sintético 5', 2, 12, 1, 2, '-20 seconds', '3.4.1'],
    [106, 1006, 'Colaborador sintético 6', 2, 12, 1, 2, '-10 days', '3.4.1'],
    [107, 1007, 'Colaborador sintético 7', 2, 12, 2, 2, '-1 minute', '3.4.1'],
    [108, 1008, 'Colaborador sintético 8', 2, 13, 2, 2, null, null],
];
foreach ($people as [$id, $legacy, $name, $firm, $area, $sede, $soc, $seen, $version]) {
    $seed[] = "INSERT INTO keeper_users (id, legacy_employee_id, cc, email, display_name, status) VALUES ({$id}, {$legacy}, '{$legacy}', 'sintetico" . ($id - 100) . "@keeper-eval.invalid', '{$name}', 'active')";
    $seed[] = "INSERT INTO keeper_user_assignments (keeper_user_id, sociedad_id, firm_id, area_id, sede_id) VALUES ({$id}, {$soc}, {$firm}, {$area}, {$sede})";
    if ($seen === null) continue;
    $seed[] = "INSERT INTO keeper_devices (id, user_id, device_guid, device_name, client_version, status, last_seen_at) VALUES ({$id}, {$id}, '00000000-0000-4000-8000-0000000000" . ($id - 100) . "', 'PC-{$id}', '{$version}', 'active', '{$ts($seen)}')";
    foreach ([0, 1, 2] as $ago) {
        $seed[] = "INSERT INTO keeper_activity_day (user_id, device_id, day_date, active_seconds, idle_seconds, call_seconds, work_hours_active_seconds, work_hours_idle_seconds, lunch_active_seconds, after_hours_active_seconds, first_event_at, last_event_at) VALUES ({$id}, {$id}, '{$d($ago)}', 14400, 1800, 600, 12600, 1200, 300, 600, '{$d($ago)} 07:42:00', '{$d($ago)} 17:10:00')";
        $seed[] = "INSERT INTO keeper_window_episode (user_id, device_id, start_at, end_at, duration_seconds, process_name, app_name, window_title, is_in_call, day_date) VALUES ({$id}, {$id}, '{$d($ago)} 08:00:00', '{$d($ago)} 08:45:00', 2700, 'Word', 'Microsoft Word', 'Documento sintético', 0, '{$d($ago)}'), ({$id}, {$id}, '{$d($ago)} 09:00:00', '{$d($ago)} 09:45:00', 2700, 'YouTube', 'YouTube', 'Vídeo sintético', 0, '{$d($ago)}')";
        $seed[] = "INSERT INTO keeper_focus_daily (user_id, device_id, day_date, context_switches, deep_work_seconds, distraction_seconds, focus_score, productivity_pct, constancy_pct, punctuality_minutes) VALUES ({$id}, {$id}, '{$d($ago)}', " . (20 + $id % 7) . ", 5400, 2700, " . (50 + $id % 40) . ", " . (60 + $id % 30) . ", " . (55 + $id % 40) . ", " . ($id % 2 === 0 ? 5 : -3) . ")";
    }
}
// Episodio antiguo del usuario 105 (firma 2), anterior al piso de historial de su firma.
$seed[] = "INSERT INTO keeper_window_episode (user_id, device_id, start_at, end_at, duration_seconds, process_name, app_name, window_title, is_in_call, day_date) VALUES (105, 105, '{$d(6)} 08:00:00', '{$d(6)} 08:30:00', 1800, 'Word', 'Microsoft Word', 'Antiguo', 0, '{$d(6)}')";
// Un segundo dispositivo del usuario 105, ausente: la presencia debe contarlo una sola vez (por su último latido).
$seed[] = "INSERT INTO keeper_devices (id, user_id, device_guid, device_name, client_version, status, last_seen_at) VALUES (205, 105, '00000000-0000-4000-8000-000000000205', 'PORTATIL-105', '3.4.1', 'active', '{$ts('-5 minutes')}')";
// Cuentas del panel: dirección (superadmin) = usuario 103; supervisor de firma 1 = 101; supervisor de firma 2 = 105; cuenta inactiva = 107.
$seed[] = "INSERT INTO keeper_admin_accounts (keeper_user_id, panel_role, firm_scope_id, is_active) VALUES (103, 'superadmin', NULL, 1), (101, 'admin', 1, 1), (105, 'admin', 2, 1), (107, 'admin', 2, 0)";
$seed[] = "UPDATE keeper_users SET email = 'direccion@keeper-eval.invalid' WHERE id = 103";
$seed[] = "UPDATE keeper_users SET email = 'supervisor1@keeper-eval.invalid' WHERE id = 101";
$seed[] = "UPDATE keeper_users SET email = 'supervisor2@keeper-eval.invalid' WHERE id = 105";
$seed[] = "UPDATE keeper_users SET email = 'inactivo@keeper-eval.invalid' WHERE id = 107";
// Señales para revisión: dos pendientes (una anterior al piso de la firma 2) y una revisada.
$seed[] = "INSERT INTO keeper_dual_job_alerts (user_id, day_date, alert_type, severity, evidence_json, is_reviewed) VALUES (102, '{$d(1)}', 'remote_desktop', 'high', '{\"note\":\"sintética\"}', 0), (105, '{$d(5)}', 'after_hours_pattern', 'medium', NULL, 0), (106, '{$d(1)}', 'foreign_app', 'low', NULL, 1)";
$seed[] = "UPDATE keeper_dual_job_alerts SET reviewed_at = '{$d(0)} 09:15:00' WHERE user_id = 106";
$seed[] = "INSERT INTO keeper_install_coverage_notes (legacy_employee_id, note_text, is_exempt) VALUES (1008, 'Gerencia: no aplica', 1)";
foreach ($seed as $sql) $pdo->exec($sql);
echo "[eval] base {$cfg['name']} sembrada (8 colaboradores sintéticos, 4 cuentas de panel)\n";

// Db::connectPrimary() exige contraseña no vacía: con root sin contraseña (local), el servidor usa una cuenta
// de evaluación efímera con permisos SOLO sobre esta base.
$serverUser = $cfg['user'];
$serverPass = $cfg['pass'];
if ($serverPass === '') {
    $serverUser = 'keeper_eval';
    $serverPass = bin2hex(random_bytes(12));
    $pdo->exec("DROP USER IF EXISTS 'keeper_eval'@'127.0.0.1'");
    $pdo->exec("CREATE USER 'keeper_eval'@'127.0.0.1' IDENTIFIED BY '{$serverPass}'");
    $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON `{$cfg['name']}`.* TO 'keeper_eval'@'127.0.0.1'");
}

// ─── 2. Servidor de evaluación ────────────────────────────────────────────
// La configuración va en un .env PROPIO del entorno de evaluación (KEEPER_ENV_FILE): así el servidor nunca lee el
// Web/.env habitual ni su respaldo, aunque existan, y las peticiones consultan exactamente la base sembrada.
$envFile = sys_get_temp_dir() . '/keeper-eval-' . getmypid() . '.env';
file_put_contents($envFile, "APP_ENV=eval\nAPP_BASE_URL=\nAPI_PREFIX=/api\nDB_HOST={$cfg['host']}\nDB_NAME={$cfg['name']}\nDB_USER={$serverUser}\nDB_PASS={$serverPass}\nMOAZC_BRIDGE_SECRET={$cfg['secret']}\n");
$env = array_filter(array_merge($_SERVER, ['KEEPER_ENV_FILE' => $envFile]), 'is_string');
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'MOAZC_BRIDGE_SECRET'] as $k) unset($env[$k]); // nada heredado del entorno de quien lanza
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$cfg['port']}", '-t', "{$root}/public", "{$root}/public/index.php"], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/keeper-eval-server.log', 'a'], 2 => ['file', sys_get_temp_dir() . '/keeper-eval-server.log', 'a']], $pipes, $root, $env);
if (!is_resource($proc)) { fwrite(STDERR, "No se pudo iniciar el servidor\n"); exit(2); }
$base = "http://127.0.0.1:{$cfg['port']}/api";
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) { usleep(200000); $up = @file_get_contents("{$base}/health") !== false; }
if (!$up) { proc_terminate($proc); fwrite(STDERR, "El servidor no respondió en {$base}\n"); exit(2); }
echo "[eval] servidor en {$base} (secreto de evaluación: {$cfg['secret']})\n";

if ($serveOnly) {
    echo "[eval] --serve: servidor arriba hasta Ctrl+C. Cuentas actuantes: direccion@ / supervisor1@ / supervisor2@ keeper-eval.invalid\n";
    while (proc_get_status($proc)['running']) sleep(1);
    exit(0);
}

// ─── 3. Pruebas ───────────────────────────────────────────────────────────
$failures = [];
$passes = 0;
function call(string $path, array $headers = [], string $method = 'GET'): array
{
    global $base;
    $h = '';
    foreach ($headers as $k => $v) $h .= "{$k}: {$v}\r\n";
    $ctx = stream_context_create(['http' => ['method' => $method, 'header' => $h, 'ignore_errors' => true, 'timeout' => 10]]);
    $body = (string)@file_get_contents($base . $path, false, $ctx);
    preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? 'HTTP/1.1 000', $m);
    return [(int)$m[1], json_decode($body, true) ?? ['raw' => $body]];
}
function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures, $passes;
    if ($ok) { $passes++; echo "  ✓ {$label}\n"; return; }
    $failures[] = $label . ($detail !== '' ? " — {$detail}" : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}
$S = ['X-Bridge-Secret' => $cfg['secret']];
$as = static fn(string $who): array => $S + ['X-Acting-Admin' => "{$who}@keeper-eval.invalid"];

echo "\nIdentidad\n";
[$c] = call('/external/reports/summary');                                         check('sin secreto → 401', $c === 401, "código {$c}");
[$c] = call('/external/reports/summary', ['X-Bridge-Secret' => 'otro']);            check('secreto equivocado → 401', $c === 401, "código {$c}");
[$c, $b] = call('/external/reports/contract', $S);                                   check('contrato: k3-reports-1 con 7 informes', $c === 200 && $b['contract'] === 'k3-reports-1' && count($b['reports']) === 7, json_encode($b));
[$c, $b] = call('/external/reports/summary', $S);                                    check('sin cuenta actuante → 400', $c === 400 && $b['error'] === 'acting_admin_required', "código {$c}");
[$c, $b] = call('/external/reports/summary', $as('nadie'));                          check('cuenta desconocida → 403', $c === 403 && ($b['reason'] ?? '') === 'acting_admin_unknown', "código {$c}");
[$c] = call('/external/reports/summary', $as('inactivo'));                           check('cuenta inactiva → 403', $c === 403, "código {$c}");
[$c] = call('/external/reports/summary', $as('direccion'), 'POST');                  check('POST → 405', $c === 405, "código {$c}");
[$c] = call('/external/reports/nada', $as('direccion'));                             check('informe desconocido → 404', $c === 404, "código {$c}");

echo "\nResumen (K3-ADP-01) y ámbito\n";
[$c, $b] = call('/external/reports/summary?period=today', $as('direccion'));
check('superadmin: 8 usuarios, 7 dispositivos (105 tiene dos), 6 con actividad', $c === 200 && $b['data']['kpis']['total_users'] === 8 && $b['data']['kpis']['total_devices'] === 7 && $b['data']['kpis']['users_with_activity'] === 6, json_encode($b['data']['kpis'] ?? $b));
check('superadmin: activo del día = 6 × 4 h', ($b['data']['totals']['active_seconds'] ?? 0) === 86400, (string)($b['data']['totals']['active_seconds'] ?? 'null'));
check('superadmin: YouTube es ocio y el ocio suma 6 × 45 min', ($b['data']['leisure_seconds'] ?? 0) === 16200 && (bool)array_filter($b['data']['top_apps'] ?? [], static fn($a) => $a['process_name'] === 'YouTube' && $a['leisure'] === true), json_encode($b['data']['top_apps'] ?? null));
check('superadmin: 2 señales pendientes, Focus medio entero, fecha del dato y periodo de hoy', ($b['data']['alerts_pending'] ?? -1) === 2 && is_int($b['data']['focus_avg'] ?? null) && isset($b['generated_at']) && $b['period'] === ['from' => $d(0), 'to' => $d(0)], json_encode([$b['data']['alerts_pending'] ?? null, $b['data']['focus_avg'] ?? null, $b['period'] ?? null]));
check('superadmin: primer evento del día en ISO con desfase -05:00', str_ends_with((string)($b['data']['totals']['first_event_at'] ?? ''), '-05:00'), (string)($b['data']['totals']['first_event_at'] ?? 'null'));
[$c, $b] = call('/external/reports/summary?period=today', $as('supervisor1'));
check('supervisor firma 1: 4 usuarios, 3 dispositivos (sin pedir ámbito)', $c === 200 && $b['data']['kpis']['total_users'] === 4 && $b['data']['kpis']['total_devices'] === 3, json_encode($b['data']['kpis'] ?? $b));
[$c, $b] = call('/external/reports/summary?scope_kind=firm&scope_ref=2', $as('supervisor1'));            check('supervisor firma 1 pide firma 2 → 403 scope_forbidden', $c === 403 && ($b['reason'] ?? '') === 'scope_forbidden', "código {$c}");
[$c] = call('/external/reports/summary?scope_kind=sociedad&scope_ref=1', $as('supervisor1'));            check('supervisor firma 1 pide una sociedad → 403', $c === 403, "código {$c}");
[$c, $b] = call('/external/reports/summary?scope_kind=firm&scope_ref=1', $as('supervisor1'));            check('supervisor firma 1 pide su firma → 200, 4 usuarios', $c === 200 && $b['data']['kpis']['total_users'] === 4, "código {$c}");
[$c, $b] = call('/external/reports/summary?scope_kind=area&scope_ref=12', $as('supervisor1'));           check('supervisor firma 1 pide el área 12 (de la firma 2) → intersección vacía, 200 con 0 usuarios', $c === 200 && $b['data']['kpis']['total_users'] === 0, json_encode($b['data']['kpis'] ?? $b));
[$c, $b] = call('/external/reports/summary?scope_kind=area&scope_ref=12', $as('direccion'));             check('superadmin pide el área 12 → 3 usuarios', $c === 200 && $b['data']['kpis']['total_users'] === 3, json_encode($b['data']['kpis'] ?? $b));
[$c, $b] = call('/external/reports/summary?scope_kind=sociedad&scope_ref=2', $as('direccion'));          check('superadmin pide la sociedad 2 → 4 usuarios y scope en la respuesta', $c === 200 && $b['data']['kpis']['total_users'] === 4 && $b['scope'] === ['kind' => 'sociedad', 'ref' => 2], json_encode($b['scope'] ?? $b));
[$c, $b] = call('/external/reports/summary?scope_kind=planeta&scope_ref=1', $as('direccion'));           check('ámbito inválido → 400', $c === 400 && $b['error'] === 'invalid_scope', "código {$c}");

[$c, $b] = call('/external/reports/summary?period=today', $as('supervisor2'));                                       check('supervisor firma 2: las señales pendientes del resumen respetan el piso (0, no 1)', $c === 200 && ($b['data']['alerts_pending'] ?? -1) === 0, json_encode($b['data']['alerts_pending'] ?? $b));

echo "\nPeriodos y piso de historial\n";
[$c, $b] = call('/external/reports/summary?period=week', $as('direccion'));                                         check('semana desde el lunes', $c === 200 && $b['period']['from'] === $today->modify('monday this week')->format('Y-m-d') && $b['period']['to'] === $d(0), json_encode($b['period'] ?? $b));
[$c, $b] = call('/external/reports/summary?period=month', $as('direccion'));                                        check('mes desde el día 1', $c === 200 && $b['period']['from'] === $today->format('Y-m-01'), json_encode($b['period'] ?? $b));
[$c, $b] = call("/external/reports/summary?period=custom&from={$d(10)}&to={$d(0)}", $as('direccion'));            check('fechas: 11 días de actividad para superadmin (3 sembrados × 6 = 72 h)', $c === 200 && $b['period']['from'] === $d(10) && $b['data']['totals']['active_seconds'] === 259200, json_encode([$b['period'] ?? null, $b['data']['totals']['active_seconds'] ?? null]));
[$c, $b] = call("/external/reports/summary?period=custom&from={$d(10)}&to={$d(0)}", $as('supervisor2'));          check('la firma 2 tiene piso de historial: from sube al piso', $c === 200 && $b['period']['from'] === $d(3), json_encode($b['period'] ?? $b));
[$c, $b] = call('/external/reports/summary?period=custom&from=2026-02-30&to=2026-03-01', $as('direccion'));        check('fecha inexistente → 400 invalid_date', $c === 400 && $b['error'] === 'invalid_date', "código {$c}");
[$c, $b] = call("/external/reports/summary?period=custom&from={$d(0)}&to={$d(5)}", $as('direccion'));             check('rango al revés → 400 invalid_period', $c === 400 && $b['error'] === 'invalid_period', "código {$c}");
[$c] = call('/external/reports/summary?period=year', $as('direccion'));                                             check('periodo desconocido → 400', $c === 400, "código {$c}");

echo "\nColaboradores y detalle (K3-ADP-02)\n";
[$c, $b] = call('/external/reports/users?period=today', $as('direccion'));
$byId = [];
foreach ($b['data']['users'] ?? [] as $u) $byId[$u['keeper_user_id']] = $u;
check('superadmin: 8 colaboradores en una página', $c === 200 && $b['data']['total'] === 8 && $b['data']['pages'] === 1, json_encode([$b['data']['total'] ?? null, $b['data']['pages'] ?? null]));
check('estados: 101 online, 102 away, 103 offline, 104 sin dispositivo', ($byId[101]['status'] ?? '') === 'online' && ($byId[102]['status'] ?? '') === 'away' && ($byId[103]['status'] ?? '') === 'offline' && ($byId[104]['status'] ?? '') === 'no_device', json_encode(array_map(static fn($u) => $u['status'], $byId)));
check('sin dispositivo: 0 s y Focus null (no 0)', ($byId[104]['active_seconds'] ?? -1) === 0 && array_key_exists('focus_score', $byId[104] ?? []) && $byId[104]['focus_score'] === null, json_encode($byId[104] ?? null));
[$c, $b] = call('/external/reports/users?page=2', $as('direccion'));                                                 check('página fuera de rango → 200 vacía', $c === 200 && $b['data']['users'] === [], json_encode($b['data'] ?? $b));
[$c] = call('/external/reports/users?page=0', $as('direccion'));                                                     check('página 0 → 400', $c === 400, "código {$c}");
[$c, $b] = call('/external/reports/users?period=today', $as('supervisor1'));                                         check('supervisor firma 1: sólo sus 4', $c === 200 && $b['data']['total'] === 4, json_encode($b['data']['total'] ?? $b));
[$c] = call('/external/reports/users/105?period=today', $as('supervisor1'));                                         check('detalle de otra firma → 404 (no 403: no se revela)', $c === 404, "código {$c}");
[$c] = call('/external/reports/users/999?period=today', $as('direccion'));                                           check('detalle inexistente → 404', $c === 404, "código {$c}");
[$c, $b] = call("/external/reports/users/101?period=custom&from={$d(2)}&to={$d(0)}", $as('supervisor1'));
check('detalle propio: 3 días, Focus diario, 6 episodios en el rango por defecto', $c === 200 && count($b['data']['days'] ?? []) === 3 && count($b['data']['focus'] ?? []) === 3 && count($b['data']['episodes'] ?? []) === 6 && $b['data']['user']['full_name'] === 'Colaborador sintético 1', json_encode([count($b['data']['days'] ?? []), count($b['data']['focus'] ?? []), count($b['data']['episodes'] ?? [])]));
check('episodios en ISO con desfase y en llamada como booleano', str_ends_with((string)($b['data']['episodes'][0]['start_at'] ?? ''), '-05:00') && is_bool($b['data']['episodes'][0]['is_in_call'] ?? null), json_encode($b['data']['episodes'][0] ?? null));
[$c, $b] = call("/external/reports/users/105?period=today&ep_from={$d(10)}&ep_to={$d(0)}", $as('supervisor2'));    check('episodios del usuario 105 para su firma: el rango sube al piso y el episodio antiguo no aparece', $c === 200 && $b['data']['episodes_range']['from'] === $d(3) && count($b['data']['episodes']) === 6, json_encode([$b['data']['episodes_range'] ?? null, count($b['data']['episodes'] ?? [])]));
[$c, $b] = call("/external/reports/users/105?period=today&ep_from={$d(10)}&ep_to={$d(0)}", $as('direccion'));      check('los mismos episodios para superadmin incluyen el antiguo (7)', $c === 200 && count($b['data']['episodes']) === 7, (string)count($b['data']['episodes'] ?? []));

echo "\nProductividad (K3-ADP-03)\n";
[$c, $b] = call("/external/reports/productivity?period=custom&from={$d(2)}&to={$d(0)}", $as('direccion'));
check('superadmin: 6 filas con pesos del dueño y umbral', $c === 200 && count($b['data']['rows']) === 6 && $b['data']['weights'] === ['context_switches' => 20, 'deep_work' => 25, 'distraction' => 20, 'punctuality' => 15, 'constancy' => 20] && $b['data']['deep_work_threshold_minutes'] === 25, json_encode([count($b['data']['rows'] ?? []), $b['data']['weights'] ?? null]));
check('orden descendente por Focus; kpis con 6 personas y Focus medio entero', ($b['data']['rows'][0]['focus_score'] ?? 0) >= ($b['data']['rows'][5]['focus_score'] ?? 100) && ($b['data']['kpis']['users'] ?? 0) === 6 && is_int($b['data']['kpis']['focus_score'] ?? null), json_encode($b['data']['kpis'] ?? null));
check('tendencias semanales: al menos una semana con personas y week_start en fecha', count($b['data']['trends'] ?? []) >= 1 && ($b['data']['trends'][0]['users'] ?? 0) >= 1 && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', (string)($b['data']['trends'][0]['week_start'] ?? '')) === 1, json_encode($b['data']['trends'] ?? null));
[$c, $b] = call("/external/reports/productivity?period=custom&from={$d(0)}&to={$d(0)}&scope_kind=area&scope_ref=13", $as('direccion')); check('sin días con métricas: kpis en null (no 0) y filas vacías', $c === 200 && $b['data']['rows'] === [] && $b['data']['kpis']['focus_score'] === null && $b['data']['kpis']['users'] === 0, json_encode($b['data']['kpis'] ?? $b));
[$c, $b] = call("/external/reports/productivity?period=custom&from={$d(2)}&to={$d(0)}&sort=asc", $as('supervisor1')); check('supervisor firma 1: 3 filas ascendentes', $c === 200 && count($b['data']['rows']) === 3 && $b['data']['rows'][0]['focus_score'] <= $b['data']['rows'][2]['focus_score'], json_encode(array_column($b['data']['rows'] ?? [], 'focus_score')));

echo "\nCobertura (K3-ADP-04)\n";
[$c, $b] = call('/external/reports/coverage', $as('direccion'));
$cov = [];
foreach ($b['data']['rows'] ?? [] as $r) $cov[$r['keeper_user_id']] = $r;
check('superadmin: 8 filas, umbral 3 días, release 3.4.1, sin periodo', $c === 200 && count($cov) === 8 && $b['data']['heartbeat_days'] === 3 && $b['data']['active_release'] === '3.4.1' && $b['period'] === null, json_encode([count($cov), $b['data']['heartbeat_days'] ?? null, $b['period'] ?? 'x']));
check('104 sin dispositivo, 106 sin reporte reciente, 102 versión vieja, 101 reporta, 108 exento con nota', ($cov[104]['coverage'] ?? '') === 'no_device' && ($cov[106]['coverage'] ?? '') === 'stale' && ($cov[102]['coverage'] ?? '') === 'outdated' && ($cov[101]['coverage'] ?? '') === 'ok' && ($cov[108]['exempt'] ?? false) === true && ($cov[108]['note'] ?? '') === 'Gerencia: no aplica', json_encode(array_map(static fn($r) => $r['coverage'], $cov)));
[$c, $b] = call('/external/reports/coverage', $as('supervisor2'));                                                    check('supervisor firma 2: 4 filas', $c === 200 && count($b['data']['rows']) === 4, (string)count($b['data']['rows'] ?? []));

echo "\nPresencia por sede (K3-ADP-05)\n";
[$c, $b] = call('/external/reports/presence?period=today', $as('direccion'));
$sites = [];
foreach ($b['data']['sites'] ?? [] as $s) $sites[$s['sede_id']] = $s;
check('superadmin: 2 sedes con 4 personas cada una', $c === 200 && count($sites) === 2 && $sites[1]['users'] === 4 && $sites[2]['users'] === 4, json_encode($b['data'] ?? $b));
check('Norte: 4 con dispositivo, activos ahora 2 (101 y 105 —105 con un segundo dispositivo ausente cuenta UNA vez—), primer inicio y ocio', ($sites[1]['without_device'] ?? -1) === 0 && ($sites[1]['active'] ?? -1) === 2 && str_ends_with((string)($sites[1]['first_login_at'] ?? ''), '-05:00') && ($sites[1]['leisure_seconds'] ?? 0) === 4 * 2700, json_encode($sites[1] ?? null));
[$c, $b] = call('/external/reports/presence?period=today', $as('supervisor1'));
$sites = [];
foreach ($b['data']['sites'] ?? [] as $s) $sites[$s['sede_id']] = $s;
check('supervisor firma 1: la presencia SÍ recorta por ámbito (2 y 2), a diferencia de la página', $c === 200 && ($sites[1]['users'] ?? 0) === 2 && ($sites[2]['users'] ?? 0) === 2, json_encode($b['data'] ?? $b));

echo "\nSeñales para revisión (K3-ADP-06, sólo lectura)\n";
[$c, $b] = call('/external/reports/alerts', $as('direccion'));                                                          check('superadmin: 3 señales con tipo, gravedad y evidencia decodificada', $c === 200 && $b['data']['total'] === 3 && count($b['data']['alerts']) === 3 && in_array('remote_desktop', array_column($b['data']['alerts'], 'alert_type'), true), json_encode($b['data'] ?? $b));
[$c, $b] = call('/external/reports/alerts?status=pending', $as('direccion'));                                           check('pendientes: 2', $c === 200 && $b['data']['total'] === 2, json_encode($b['data']['total'] ?? $b));
[$c, $b] = call('/external/reports/alerts?severity=high', $as('direccion'));                                            check('gravedad alta: 1, del colaborador 102', $c === 200 && $b['data']['total'] === 1 && $b['data']['alerts'][0]['keeper_user_id'] === 102, json_encode($b['data'] ?? $b));
[$c, $b] = call('/external/reports/alerts', $as('supervisor2'));                                                        check('supervisor firma 2: la señal anterior al piso no aparece (1 de 2) y reviewed_at va en ISO −05:00', $c === 200 && $b['data']['total'] === 1 && $b['data']['alerts'][0]['keeper_user_id'] === 106 && str_ends_with((string)$b['data']['alerts'][0]['reviewed_at'], '-05:00'), json_encode($b['data'] ?? $b));
[$c] = call('/external/reports/alerts?severity=enorme', $as('direccion'));                                              check('filtro inválido → 400', $c === 400, "código {$c}");
$pending = (int)$pdo->query("SELECT COUNT(*) FROM keeper_dual_job_alerts WHERE is_reviewed = 0")->fetchColumn();
check('nada se marcó como revisado: siguen 2 pendientes', $pending === 2, (string)$pending);

echo "\nSincronización compartida (K3-ADP-07/08/09)\n";
function put(string $path, array $headers, array $body): array
{
    global $base;
    $h = "Content-Type: application/json\r\n";
    foreach ($headers as $k => $v) $h .= "{$k}: {$v}\r\n";
    $ctx = stream_context_create(['http' => ['method' => 'PUT', 'header' => $h, 'content' => json_encode($body), 'ignore_errors' => true, 'timeout' => 10]]);
    $raw = (string)@file_get_contents($base . $path, false, $ctx);
    preg_match('#HTTP/\S+ (\d{3})#', $http_response_header[0] ?? 'HTTP/1.1 000', $m);
    return [(int)$m[1], json_decode($raw, true) ?? ['raw' => $raw]];
}
$intent = ['firm_id' => 2, 'area_id' => 12, 'cargo_id' => 21, 'sede_id' => 2, 'sociedad_id' => 2, 'intent_version' => 'one-1001-v7'];
[$c, $b] = call('/external/roster', $S);
$roster = [];
foreach ($b['users'] ?? [] as $u) $roster[$u['keeper_user_id']] = $u;
check('roster (K3-ADP-09) devuelve legacy_employee_id, area_id, cargo_id, sociedad_id, manual_override y source', $c === 200 && ($roster[101]['legacy_employee_id'] ?? null) === 1001 && ($roster[101]['area_id'] ?? null) === 10 && ($roster[101]['sociedad_id'] ?? null) === 1 && array_key_exists('manual_override', $roster[101] ?? []) && ($roster[101]['assignment_source'] ?? '') === 'legacy', json_encode($roster[101] ?? $b));
[$c] = put('/external/assignments/1001', $S, $intent);                                                       check('aplicar sin cuenta actuante → 400', $c === 400, "código {$c}");
[$c, $b] = put('/external/assignments/1001', $as('supervisor2'), $intent);                                  check('supervisor de firma 2 aplica sobre alguien de la firma 1 → 404 not_mapped (no se revela)', $c === 404 && ($b['result'] ?? '') === 'not_mapped', "código {$c}");
[$c, $b] = put('/external/assignments/9999', $as('direccion'), $intent);                                    check('legacy sin usuario en Keeper → 404 not_mapped', $c === 404 && ($b['result'] ?? '') === 'not_mapped', "código {$c}");
[$c, $b] = put('/external/assignments/1001', $as('direccion'), ['firm_id' => 2, 'area_id' => 12, 'cargo_id' => 21, 'sede_id' => 2, 'sociedad_id' => 2]); check('sin intent_version → 400', $c === 400 && ($b['error'] ?? '') === 'invalid_intent_version', "código {$c}");
[$c, $b] = put('/external/assignments/1001', $as('direccion'), [...$intent, 'cargo_id' => 999]);            check('referencia inexistente → 422 con el campo', $c === 422 && ($b['field'] ?? '') === 'cargo_id', "código {$c}");
[$c, $b] = put('/external/assignments/1001', $as('direccion'), $intent);
check('aplicar → applied con los valores de Keeper (incluida sociedad_id) y source one', $c === 200 && $b['result'] === 'applied' && $b['keeper_values'] === ['firm_id' => 2, 'area_id' => 12, 'cargo_id' => 21, 'sede_id' => 2, 'sociedad_id' => 2] && $b['source'] === 'one' && $b['source_version'] === 'one-1001-v7' && str_ends_with((string)$b['updated_at'], '-05:00'), json_encode($b));
[$c, $b] = put('/external/assignments/1001', $as('direccion'), $intent);                                    check('misma intent_version → unchanged (idempotente)', $c === 200 && $b['result'] === 'unchanged', json_encode($b));
// El legacy no la revierte: syncOne (login) y syncAllFromPanel (panel) dejan la asignación de One como está.
require_once "{$root}/src/bootstrap.php";
\Keeper\LegacySyncService::syncOne($pdo, 101, ['firm_id' => 1, 'area_id' => 10, 'cargo_id' => 20, 'sede_id' => 1]);
$after = $pdo->query("SELECT firm_id, area_id, sociedad_id, source FROM keeper_user_assignments WHERE keeper_user_id = 101")->fetch(PDO::FETCH_ASSOC);
check('syncOne desde el legacy no revierte una asignación de One', (int)$after['firm_id'] === 2 && (int)$after['area_id'] === 12 && (int)$after['sociedad_id'] === 2 && $after['source'] === 'one', json_encode($after));
\Keeper\LegacySyncService::syncOne($pdo, 102, ['firm_id' => 1, 'area_id' => 11, 'cargo_id' => 20, 'sede_id' => 1, 'sociedad_id' => 2]);
$after = $pdo->query("SELECT area_id, sociedad_id, source FROM keeper_user_assignments WHERE keeper_user_id = 102")->fetch(PDO::FETCH_ASSOC);
check('syncOne sí aplica sociedad_id cuando el origen la envía (ampliación K3-ADP-07b) y la fuente sigue legacy', (int)$after['area_id'] === 11 && (int)$after['sociedad_id'] === 2 && $after['source'] === 'legacy', json_encode($after));
[$c, $b] = put('/external/assignments/1001', $as('direccion'), [...$intent, 'area_id' => 13, 'intent_version' => 'one-1001-v8']); check('cambio posterior con nueva intent_version → applied', $c === 200 && $b['result'] === 'applied' && $b['keeper_values']['area_id'] === 13 && $b['source_version'] === 'one-1001-v8', json_encode($b));
$pdo->exec("UPDATE keeper_user_assignments SET manual_override = 1, source = 'panel', firm_id = 1 WHERE keeper_user_id = 103");
[$c, $b] = put('/external/assignments/1003', $as('direccion'), [...$intent, 'intent_version' => 'one-1003-v1']);
check('excepción manual del panel → kept_override con los valores de Keeper, sin tocarla', $c === 200 && $b['result'] === 'kept_override' && $b['manual_override'] === true && $b['keeper_values']['firm_id'] === 1 && $b['source'] === 'panel', json_encode($b));
[$c, $b] = call('/external/assignments', $as('direccion'));
$rows = [];
foreach ($b['data']['assignments'] ?? [] as $r) $rows[$r['keeper_user_id']] = $r;
check('listado (K3-ADP-08): 8 asignaciones con identidad, fuente y excepción', $c === 200 && $b['data']['total'] === 8 && ($rows[101]['source'] ?? '') === 'one' && ($rows[101]['source_version'] ?? '') === 'one-1001-v8' && ($rows[103]['manual_override'] ?? false) === true && ($rows[101]['legacy_employee_id'] ?? null) === 1001, json_encode($rows[101] ?? $b));
[$c, $b] = call('/external/assignments?updated_since=' . urlencode((new DateTimeImmutable('+1 hour'))->format(DATE_ATOM)), $as('direccion')); check('updated_since en el futuro → ninguna', $c === 200 && $b['data']['total'] === 0, json_encode($b['data'] ?? $b));
[$c, $b] = call('/external/assignments?updated_since=' . urlencode((new DateTimeImmutable('-1 hour'))->format(DATE_ATOM)), $as('supervisor1')); check('supervisor firma 1 lista sólo su firma (3 tras mover a 101 a la firma 2)', $c === 200 && $b['data']['total'] === 3, json_encode($b['data']['total'] ?? $b));
[$c] = call('/external/assignments?updated_since=ayer', $as('direccion'));                                   check('updated_since inválido → 400', $c === 400, "código {$c}");

// ─── 4. Cierre ────────────────────────────────────────────────────────────
proc_terminate($proc);
@unlink($envFile);
echo "\n{$passes} comprobaciones correctas, " . count($failures) . " fallidas\n";
foreach ($failures as $f) echo "  - {$f}\n";
exit($failures === [] ? 0 : 1);
