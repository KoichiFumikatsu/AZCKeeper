<?php
/**
 * Pruebas del tope de descargas simultáneas de GET /api/client/version (ClientVersion::updateGate).
 *
 * No se ejecuta solo: lo lanza tests/update-gate-test.ps1, que arranca un mysqld AISLADO
 * (datadir temporal, puerto propio) y dos servidores php -S sobre public/index.php:
 *   - GATE_URL_ON : UPDATE_GATE_MAX=5, UPDATE_GATE_WINDOW_SECONDS=300
 *   - GATE_URL_OFF: UPDATE_GATE_MAX=0 (tope desactivado = comportamiento original)
 *
 * Variables: GATE_DB_PORT, GATE_DB_NAME, GATE_DB_USER, GATE_DB_PASS, GATE_URL_ON, GATE_URL_OFF.
 * Este script crea la base, el usuario de la app, carga keeper_full_schema.sql y siembra los datos.
 */
declare(strict_types=1);

$port   = (int)getenv('GATE_DB_PORT');
$dbName = (string)getenv('GATE_DB_NAME');
$dbUser = (string)getenv('GATE_DB_USER');
$dbPass = (string)getenv('GATE_DB_PASS');
$urlOn  = rtrim((string)getenv('GATE_URL_ON'), '/');
$urlOff = rtrim((string)getenv('GATE_URL_OFF'), '/');
if ($port <= 0 || $dbName === '' || $urlOn === '' || $urlOff === '') {
    fwrite(STDERR, "Faltan variables GATE_*; lanzar con tests/update-gate-test.ps1\n");
    exit(2);
}
$root = dirname(__DIR__);

// ─── Utilidades ─────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASA  {$name}\n"; }
    else     { $fail++; echo "  FALLA {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n"; }
}

/** GET /api/client/version; devuelve [status, json decodificado, cuerpo crudo, segundos]. */
function version(string $base, ?string $token = null): array
{
    $ch = curl_init($base . '/api/client/version');
    $headers = [];
    if ($token !== null) $headers[] = "Authorization: Bearer {$token}";
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 20]);
    $t0 = microtime(true);
    $body = curl_exec($ch);
    $secs = microtime(true) - $t0;
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($json) ? $json : [], (string)$body, $secs];
}

function isOffer(array $j): bool
{
    return ($j['ok'] ?? null) === true && ($j['latestVersion'] ?? null) === '3.0.3.4'
        && !array_key_exists('deferred', $j) && ($j['downloadUrl'] ?? '') !== '';
}

function isDeferred(array $j, string $latest): bool
{
    return ($j['ok'] ?? null) === true && ($j['deferred'] ?? null) === true
        && ($j['latestVersion'] ?? null) === $latest && ($j['minimumVersion'] ?? null) === '0.0.0.0'
        && ($j['forceUpdate'] ?? null) === false && ($j['downloadUrl'] ?? null) === '';
}

// ─── 1. Base de prueba ──────────────────────────────────────────────────────
$admin = new PDO("mysql:host=127.0.0.1;port={$port};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$admin->exec("CREATE USER '{$dbUser}'@'%' IDENTIFIED BY " . $admin->quote($dbPass));
$admin->exec("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$dbUser}'@'%'");
$admin->exec("USE `{$dbName}`");

$sql = (string)file_get_contents("{$root}/migrations/keeper_full_schema.sql");
foreach (preg_split('/;\s*\n/', $sql) ?: [] as $statement) {
    $statement = trim((string)preg_replace('/^\s*--[^\n]*$/m', '', $statement));
    if ($statement === '') continue;
    $admin->exec($statement);
}
// Las migraciones posteriores solo AÑADEN columnas/índices a keeper_devices/sessions/releases;
// el tope usa columnas que ya están en keeper_full_schema.sql.

$admin->exec("INSERT INTO keeper_client_releases (version, download_url, file_size, release_notes, is_beta, force_update, minimum_version, is_active, release_date)
              VALUES ('3.0.3.4', 'https://keeper-test.invalid/releases/AZCKeeper_v3.0.3.4.zip', 82837504, 'Notas 3.0.3.4', 0, 0, '3.0.0.0', 1, '2026-10-19')");

// 8 usuarios/equipos: d1 ya en 3.0.3.4; d2..d8 en 3.0.3.2. Más un usuario con sesión sin equipo.
$tokens = [];
$devIds = [];
for ($i = 1; $i <= 8; $i++) {
    $admin->exec("INSERT INTO keeper_users (id, legacy_employee_id, cc, display_name) VALUES ({$i}, " . (1000 + $i) . ", 'cc{$i}', 'Usuario prueba {$i}')");
    $ver = $i === 1 ? '3.0.3.4' : '3.0.3.2';
    $guid = sprintf('00000000-0000-0000-0000-%012d', $i);
    $admin->exec("INSERT INTO keeper_devices (id, user_id, device_guid, device_name, client_version) VALUES (" . (100 + $i) . ", {$i}, '{$guid}', 'PC-{$i}', '{$ver}')");
    $tokens[$i] = bin2hex(random_bytes(32));
    $devIds[$i] = 100 + $i;
    $h = hash('sha256', $tokens[$i]);
    $admin->exec("INSERT INTO keeper_sessions (user_id, device_id, token_hash, expires_at) VALUES ({$i}, " . (100 + $i) . ", '{$h}', '2037-12-31 00:00:00')");
}

$offers = static fn(): int => (int)$admin->query("SELECT COUNT(*) FROM keeper_audit_log WHERE event_type = 'update_offered'")->fetchColumn();
$offersOf = static fn(int $dev): int => (int)$admin->query("SELECT COUNT(*) FROM keeper_audit_log WHERE event_type = 'update_offered' AND device_id = {$dev}")->fetchColumn();

// ─── 2. Casos ───────────────────────────────────────────────────────────────
echo "Referencia: tope desactivado (UPDATE_GATE_MAX=0)\n";
[$s, $ref] = version($urlOff, $tokens[2]);
check('referencia 200 con oferta real', $s === 200 && isOffer($ref), json_encode($ref));
check('referencia no registra oferta', $offers() === 0);

echo "(a) equipo ya en 3.0.3.4\n";
[$s, $j, $raw] = version($urlOn, $tokens[1]);
check('(a) 200 y respuesta idéntica a la original', $s === 200 && $j === $ref, $raw);
check('(a) no registra oferta', $offers() === 0, (string)$offers());

echo "(e) sin token / token inválido con cupo libre\n";
[$s, $j, $raw] = version($urlOn, null);
check('(e) sin token: oferta real', $s === 200 && $j === $ref, $raw);
check('(e) sin token: registra oferta con device_id NULL', (int)$admin->query("SELECT COUNT(*) FROM keeper_audit_log WHERE event_type='update_offered' AND device_id IS NULL AND user_id IS NULL")->fetchColumn() === 1);
[$s, $j, $raw] = version($urlOn, 'token-que-no-existe');
check('(e) token inválido: 200 (no 401) y oferta real', $s === 200 && $j === $ref, "{$s} {$raw}");
check('(e) dos desconocidos = dos filas', $offers() === 2, (string)$offers());
$admin->exec("DELETE FROM keeper_audit_log");

echo "(b) 5 equipos 3.0.3.2 admitidos, el 6º espera\n";
for ($i = 2; $i <= 6; $i++) {
    [$s, $j, $raw] = version($urlOn, $tokens[$i]);
    check("(b) equipo d{$i}: oferta real idéntica a la original", $s === 200 && $j === $ref, $raw);
}
check('(b) 5 filas update_offered', $offers() === 5, (string)$offers());
$row = $admin->query("SELECT user_id, device_id, message, meta_json FROM keeper_audit_log WHERE device_id = 102")->fetch(PDO::FETCH_ASSOC);
$meta = json_decode((string)($row['meta_json'] ?? ''), true);
check('(b) fila con user_id, device_id, message y meta_json', $row && (int)$row['user_id'] === 2 && $row['message'] === '3.0.3.4'
    && ($meta['version'] ?? null) === '3.0.3.4' && ($meta['client_version'] ?? null) === '3.0.3.2', json_encode($row));
[$s, $j, $raw] = version($urlOn, $tokens[7]);
check('(b) 6º equipo: deferred=true, latestVersion=3.0.3.2, minimumVersion=0.0.0.0', $s === 200 && isDeferred($j, '3.0.3.2'), $raw);
check('(b) deferred conserva el formato (mismas claves + deferred)', array_keys($j) === array_merge(array_keys($ref), ['deferred']), implode(',', array_keys($j)));
check('(b) el 6º no registra oferta', $offers() === 5 && $offersOf(107) === 0);

echo "(c) equipo admitido vuelve a preguntar dentro de la ventana\n";
[$s, $j, $raw] = version($urlOn, $tokens[2]);
check('(c) d2 otra vez: oferta real', $s === 200 && $j === $ref, $raw);
check('(c) no duplica fila', $offersOf(102) === 1 && $offers() === 5, (string)$offers());

echo "(e) sin cupo: sin token / token inválido\n";
[$s, $j, $raw] = version($urlOn, null);
check('(e) sin token sin cupo: deferred con latestVersion 0.0.0.0', $s === 200 && isDeferred($j, '0.0.0.0'), $raw);
[$s, $j, $raw] = version($urlOn, 'token-que-no-existe');
check('(e) token inválido sin cupo: 200 deferred (no 401)', $s === 200 && isDeferred($j, '0.0.0.0'), "{$s} {$raw}");
check('(e) los desconocidos en espera no registran fila', $offers() === 5);

echo "(i) lock ocupado por otra conexión > 3 s\n";
$holder = new PDO("mysql:host=127.0.0.1;port={$port};dbname={$dbName};charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$holder->query("SELECT GET_LOCK('k3_update_gate', 0)")->fetchColumn();
[$s, $j, $raw, $secs] = version($urlOn, $tokens[8]);
check('(i) responde "espera" tras ~3 s', $s === 200 && isDeferred($j, '3.0.3.2') && $secs >= 2.5, sprintf('%.2fs %s', $secs, $raw));
check('(i) no registra oferta', $offersOf(108) === 0);
$holder->query("SELECT RELEASE_LOCK('k3_update_gate')")->fetchColumn();
$holder = null;

echo "(d) tras la ventana el 6º sí entra\n";
$admin->exec("UPDATE keeper_audit_log SET created_at = NOW() - INTERVAL 301 SECOND");
[$s, $j, $raw] = version($urlOn, $tokens[7]);
check('(d) d7 recibe oferta real', $s === 200 && $j === $ref, $raw);
check('(d) registra su fila', $offersOf(107) === 1 && $offers() === 6, (string)$offers());
check('(d) lock liberado tras la petición', (int)$admin->query("SELECT IS_FREE_LOCK('k3_update_gate')")->fetchColumn() === 1);

echo "(f) UPDATE_GATE_MAX=0: comportamiento original para todos\n";
$admin->exec("DELETE FROM keeper_audit_log");
for ($k = 0; $k < 10; $k++) {
    $admin->exec("INSERT INTO keeper_audit_log (event_type, message, created_at) VALUES ('update_offered', '3.0.3.4', NOW())");
}
foreach ([3, 7, 8] as $i) {
    [$s, $j, $raw] = version($urlOff, $tokens[$i]);
    check("(f) d{$i} con ventana llena: oferta real", $s === 200 && $j === $ref, $raw);
}
[$s, $j, $raw] = version($urlOff, null);
check('(f) sin token: oferta real', $s === 200 && $j === $ref, $raw);
check('(f) no registra filas', $offers() === 10, (string)$offers());
[$s, $j, $raw] = version($urlOn, $tokens[8]);
check('(f) control: con el tope activo y 10 desconocidos, d8 espera', $s === 200 && isDeferred($j, '3.0.3.2'), $raw);

echo "(h) fallo de BD en el tope (keeper_audit_log renombrada): fail-open\n";
$admin->exec("RENAME TABLE keeper_audit_log TO keeper_audit_log_off");
[$s, $j, $raw] = version($urlOn, $tokens[8]);
check('(h) d8: respuesta normal pese al error', $s === 200 && $j === $ref, "{$s} {$raw}");
[$s, $j, $raw] = version($urlOn, null);
check('(h) sin token: respuesta normal pese al error', $s === 200 && $j === $ref, "{$s} {$raw}");
check('(h) lock liberado tras el error', (int)$admin->query("SELECT IS_FREE_LOCK('k3_update_gate')")->fetchColumn() === 1);
$admin->exec("RENAME TABLE keeper_audit_log_off TO keeper_audit_log");

echo "(g) sin release activa\n";
$admin->exec("UPDATE keeper_client_releases SET is_active = 0");
foreach (['ON' => $urlOn, 'OFF' => $urlOff] as $label => $u) {
    [$s, $j, $raw] = version($u, $tokens[2]);
    check("(g) servidor {$label}: 500 No releases available", $s === 500 && $j === ['ok' => false, 'error' => 'No releases available'], "{$s} {$raw}");
}
$admin->exec("UPDATE keeper_client_releases SET is_active = 1");

echo "\nResultado: {$pass} pasan, {$fail} fallan\n";
exit($fail === 0 ? 0 : 1);
