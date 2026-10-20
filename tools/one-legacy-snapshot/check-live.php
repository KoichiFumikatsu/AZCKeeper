<?php
declare(strict_types=1);

// Prueba local del modo vivo de serve.php (README §6). Necesita, en el mismo MySQL desechable:
//   - la copia de One cargada segun README §1 (BD --one-db, usuario root sin clave: tiene todos los permisos, que es
//     justo el caso de produccion que la sesion READ ONLY debe contener);
//   - una BD de Keeper desechable keeper_e2e* ya migrada (KEEPER_DB_DSN), para correr DataSync del repo;
//   - la instantanea de extract.php de esa misma copia (--snapshot=<out>/grupo-azc), para comparar con el modo files.
// Levanta varios `php -S` propios (los detiene por su handle al terminar) con la entrada publica minima y configuraciones
// distintas, y comprueba: DataSync contra el modo vivo = contra el modo archivos (tambien paginado), la guarda de
// solo-SELECT, la sesion READ ONLY (una escritura falla), cache, TTL, bloqueo, version anterior para cursores, 401 sin
// token, 503 sin BD ni cache y cache vieja con aviso. Imprime solo conteos y resultados (sin nombres, cedulas ni correos).
//
// Uso: KEEPER_DB_DSN='mysql:host=127.0.0.1;port=13397;dbname=keeper_e2e_live;charset=utf8mb4' KEEPER_DB_USER=root
//      KEEPER_RESPONSE_KEY=<base64 de 32 bytes> php check-live.php --mysql-port=13397 --one-db=one_legacy
//      --snapshot=<out>/grupo-azc --work=<carpeta temporal vacia FUERA del repo> [--http-port=18400]

use Keeper\{Database, DataSync, Features, SyncFailure, Util};

$opts = getopt('', ['mysql-port:', 'one-db:', 'snapshot:', 'work:', 'http-port:', 'backend:']);
$backend = $opts['backend'] ?? dirname(__DIR__, 2) . '/backend-v4';
require $backend . '/config/bootstrap.php';
require __DIR__ . '/RosterBuilder.php';
if (!preg_match('/dbname=keeper_e2e[a-z0-9_]*;/', (string) getenv('KEEPER_DB_DSN') . ';')) { fwrite(STDERR, "KEEPER_DB_DSN debe apuntar a una BD desechable keeper_e2e*\n"); exit(1); }
$mysqlPort = (int) ($opts['mysql-port'] ?? exit("falta --mysql-port\n"));
$oneDb = $opts['one-db'] ?? 'one_legacy';
$snapshot = realpath($opts['snapshot'] ?? exit("falta --snapshot\n")) ?: exit("--snapshot no existe\n");
$work = $opts['work'] ?? exit("falta --work\n");
$port = (int) ($opts['http-port'] ?? 18400);
$repo = realpath(dirname(__DIR__, 2));
if (!is_dir($work)) { mkdir($work, 0700, true); }
$work = realpath($work);
if (str_starts_with(strtolower($work . DIRECTORY_SEPARATOR), strtolower($repo . DIRECTORY_SEPARATOR))) { exit("--work no puede estar dentro del repo (la cache tiene datos personales)\n"); }
if (array_diff(scandir($work), ['.', '..'])) { exit("--work debe estar vacia\n"); }

$checks = [];
$check = function (string $name, bool $ok) use (&$checks): void { $checks[$name] = $ok; if (!$ok) { fwrite(STDERR, "FALLA: $name\n"); } };
$token = bin2hex(random_bytes(32));
$php = PHP_BINARY;
$servers = [];

// ---- Configs de One falsas: el archivo real de One tambien tiene codigo con efectos; no debe ejecutarse ---------------
@mkdir("$work/one", 0700);
file_put_contents("$work/one/config.php", "<?php\nsession_start();\ndefine('DB_HOST', '127.0.0.1');\ndefine('DB_PORT', $mysqlPort);\ndefine(\"DB_NAME\", \"$oneDb\");\ndefine('DB_USER', 'root');\ndefine('DB_PASS', '');\necho 'EFECTO_LATERAL_DE_ONE';\nexit(9);\n");
file_put_contents("$work/one/config-nodb.php", "<?php\ndefine('DB_HOST', '127.0.0.1');\ndefine('DB_PORT', 1);\ndefine('DB_NAME', 'one_x');\ndefine('DB_USER', 'usuario_secreto_x');\ndefine('DB_PASS', 'clave_secreta_y');\n");
file_put_contents("$work/one/config-ambiguous.php", "<?php\nif (getenv('X')) { define('DB_HOST', 'a'); } else { define('DB_HOST', 'b'); }\ndefine('DB_NAME','n'); define('DB_USER','u'); define('DB_PASS','p');\n");
file_put_contents("$work/one/config-env.php", "<?php\ndefine('DB_HOST', getenv('H'));\ndefine('DB_NAME','n'); define('DB_USER','u'); define('DB_PASS','p');\n");
file_put_contents("$work/one/config-partial.php", "<?php\ndefine('DB_HOST','h'); define('DB_NAME','n');\n");

// ---- 1) Guarda de solo-SELECT, lectura segura del config y sesion READ ONLY (en proceso) -------------------------------
$rejects = ['UPDATE firm SET name=name', 'DELETE FROM firm', ' INSERT INTO firm (name) VALUES (1)', 'SELECT 1; DROP TABLE firm', "SELECT id FROM firm INTO OUTFILE '/tmp/x'",
    'SELECT id INTO @x FROM firm', 'SELECT id FROM firm FOR UPDATE', 'SELECT id FROM firm LOCK IN SHARE MODE', 'WITH x AS (SELECT 1) SELECT * FROM x', 'CALL p()', 'SET @a=1', 'SELECTX 1'];
$rejected = 0;
foreach ($rejects as $sql) { try { RosterBuilder::assertSelect($sql); } catch (RosterError $e) { if ($e->reason === 'sql_rejected') { $rejected++; } } }
$check('guarda: rechaza todo lo que no es SELECT simple (' . count($rejects) . ' casos)', $rejected === count($rejects));
$accepted = true;
try { RosterBuilder::assertSelect("  SELECT id, name FROM firm ORDER BY id"); RosterBuilder::assertSelect("select e.id\nfrom employee e"); } catch (RosterError $e) { $accepted = false; }
$check('guarda: acepta los SELECT del builder', $accepted);

$reason = function (string $file): string { try { RosterBuilder::oneCredentials($file); return 'ok'; } catch (RosterError $e) { return $e->reason; } };
$cred = RosterBuilder::oneCredentials("$work/one/config.php");
$check('config One: lee las 5 constantes como texto', array_keys($cred) === ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] && $cred['DB_PORT'] === (string) $mysqlPort);
$check('config One: definicion ambigua -> one_config_ambiguous', $reason("$work/one/config-ambiguous.php") === 'one_config_ambiguous');
$check('config One: getenv() -> one_config_unparseable', $reason("$work/one/config-env.php") === 'one_config_unparseable');
$check('config One: incompleta -> one_config_incomplete', $reason("$work/one/config-partial.php") === 'one_config_incomplete');
$check('config One: inexistente -> one_config_unreadable', $reason("$work/one/no-existe.php") === 'one_config_unreadable');

$plain = new PDO("mysql:host=127.0.0.1;port=$mysqlPort;dbname=$oneDb;charset=utf8mb4", 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$fingerprint = fn (): string => json_encode($plain->query('SELECT COUNT(*) n, COALESCE(SUM(CRC32(name)),0) s FROM firm')->fetch(PDO::FETCH_ASSOC)) . json_encode($plain->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
$before = $fingerprint();
$ro = RosterBuilder::connect($cred);
$now = RosterBuilder::readOnlySession($ro);
$check('read only: readOnlySession devuelve generated_at UTC', (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $now));
$denied = function (string $sql) use ($ro): bool { try { $ro->exec($sql); return false; } catch (PDOException $e) { return (int) ($e->errorInfo[1] ?? 0) === 1792; } };
$check('read only: UPDATE dentro de la transaccion falla (1792)', $denied('UPDATE firm SET name = name WHERE id > 0'));
$check('read only: INSERT dentro de la transaccion falla (1792)', $denied("INSERT INTO firm (name) VALUES ('probe')"));
$ro->exec('ROLLBACK');
$check('read only: tras cerrar la transaccion, la sesion sigue READ ONLY (DELETE falla)', $denied('DELETE FROM firm WHERE id < 0'));
$ddl = (function () use ($ro): string { try { $ro->exec('CREATE TABLE kb_ro_probe (id int)'); return 'permitido'; } catch (PDOException $e) { return 'rechazado:' . ($e->errorInfo[1] ?? '?'); } })();
if ($ddl === 'permitido') { $plain->exec('DROP TABLE kb_ro_probe'); }
$builderRo = new RosterBuilder($ro, ...RosterBuilder::loadMaps(__DIR__));
$check('read only: el builder rechaza DELETE antes de llegar a la BD', (function () use ($builderRo): bool { try { $builderRo->select('DELETE FROM firm'); return false; } catch (RosterError $e) { return $e->reason === 'sql_rejected'; } })());
$ro = null; $builderRo = null;
$check('read only: la BD de One queda igual', $fingerprint() === $before);

// ---- 2) Servidores locales con la entrada publica minima ----------------------------------------------------------
$entry = file_get_contents(__DIR__ . '/public-entry.php');
$scenario = function (string $name, array $config, int $extraServers = 0) use (&$servers, &$port, $work, $entry, $token, $php): array {
    $root = "$work/$name";
    $public = "$root/public/ob-" . bin2hex(random_bytes(8));
    mkdir($public, 0700, true);
    mkdir("$root/cache", 0700);
    $config += ['token_sha256' => [hash('sha256', $token)], 'allow_http' => true, 'cache_dir' => "$root/cache"];
    file_put_contents("$root/serve.config.php", "<?php\nreturn " . var_export($config, true) . ";\n");
    $stub = str_replace(["'/home/www/keep.azclegal.com/one-bridge/serve.config.php'", "'/home/www/keep.azclegal.com/one-bridge/serve.php'"],
        [var_export("$root/serve.config.php", true), var_export(__DIR__ . DIRECTORY_SEPARATOR . 'serve.php', true)], $entry);
    file_put_contents("$public/index.php", $stub);
    $bases = [];
    for ($i = 0; $i <= $extraServers; $i++) {
        $p = $port++;
        $log = fopen("$root/server-$i.log", 'w');
        $proc = proc_open([$php, '-d', 'display_errors=0', '-S', "127.0.0.1:$p", '-t', "$root/public"], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, $root, null, ['bypass_shell' => true]);
        $servers[] = $proc;
        for ($t = 0; $t < 50 && !@fsockopen('127.0.0.1', $p, $en, $es, 0.2); $t++) { usleep(100000); }
        $bases[] = "http://127.0.0.1:$p/" . basename($public) . '/index.php/api/keeper/v1';
    }
    return ['root' => $root, 'base' => $bases[0], 'bases' => $bases];
};
$logs = fn (array $s): string => implode("\n", array_map('file_get_contents', glob($s['root'] . '/server-*.log')));
$builds = fn (array $s): int => substr_count($logs($s), 'live build ok');

$get = function (string $base, string $endpoint, array $query = [], ?string $tok = null) use ($token): array {
    $ch = curl_init(rtrim($base, '/') . '/' . $endpoint . ($query ? '?' . http_build_query($query) : ''));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => array_filter(['Accept: application/json', $tok === '' ? null : 'Authorization: Bearer ' . ($tok ?? $token), 'User-Agent: AZCKeeper-sync/1'])]);
    $body = curl_exec($ch); $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); curl_close($ch);
    return [$status, $body === false ? null : json_decode((string) $body, true), (string) $body];
};
// Recorre todas las paginas como DataSync::pages y valida el sobre.
$all = function (string $base, string $endpoint, ?string $since = null) use ($get): array {
    $items = []; $cursor = null; $gens = []; $completes = []; $pages = 0;
    do {
        [$st, $j] = $get($base, $endpoint, array_filter(['empresa' => 'grupo-azc', 'updated_since' => $since, 'cursor' => $cursor], static fn ($v) => $v !== null));
        if ($st !== 200 || ($j['schema'] ?? null) !== 'keeper.roster.v1' || !is_array($j['items'] ?? null)) { return ['ok' => false, 'status' => $st]; }
        array_push($items, ...$j['items']); $gens[] = $j['generated_at']; $completes[] = $j['complete']; $pages++;
        $cursor = $j['next_cursor'];
    } while ($cursor !== null && $pages < 500);
    return ['ok' => true, 'items' => $items, 'pages' => $pages, 'generated_at' => array_values(array_unique($gens)), 'complete' => array_values(array_unique($completes))];
};

$live = ['mode' => 'live', 'one_config' => "$work/one/config.php", 'cache_ttl' => 300];
$sFiles = $scenario('files', ['mode' => 'files', 'snapshot_dir' => $snapshot]);
$sLive = $scenario('live', $live);
$sLive50 = $scenario('live50', ['page_size' => 50] + $live);
$sTtl = $scenario('livettl', ['page_size' => 50, 'cache_ttl' => 2] + $live, 2);
$sNoDb = $scenario('nodb', ['mode' => 'live', 'one_config' => "$work/one/config-nodb.php", 'db_timeout' => 2]);
$sStale = $scenario('stalemax', ['mode' => 'live', 'one_config' => "$work/one/config-nodb.php", 'db_timeout' => 2, 'cache_stale_max' => 60]);
$sIp = $scenario('ipdeny', ['allowed_ips' => ['10.255.255.1']] + $live);

try {
    // ---- 3) 401 sin token: no toca la BD -------------------------------------------------------------------------
    $check('401 sin token (vivo)', $get($sLive['base'], 'people', ['empresa' => 'grupo-azc'], '')[0] === 401);
    $check('401 token malo (vivo)', $get($sLive['base'], 'people', ['empresa' => 'grupo-azc'], str_repeat('x', 40))[0] === 401);
    $check('401 no construye el roster', $builds($sLive) === 0 && !is_file($sLive['root'] . '/cache/roster.json'));
    [$sIpSt, $jIp] = $get($sIp['base'], 'people', ['empresa' => 'grupo-azc']);
    $check('IP fuera de allowed_ips: 403 forbidden sin construir', $sIpSt === 403 && ($jIp['error'] ?? null) === 'forbidden' && $builds($sIp) === 0);

    // ---- 4) Contrato: vivo = archivos (completo, paginado e incremental) -----------------------------------------
    $counts = [];
    foreach (['org-units', 'schedules', 'people'] as $e) {
        $f = $all($sFiles['base'], $e); $l = $all($sLive['base'], $e); $l50 = $all($sLive50['base'], $e);
        $counts[$e] = ['items' => count($f['items'] ?? []), 'pages_live50' => $l50['pages'] ?? null];
        $check("$e: vivo = archivos (items identicos)", $f['ok'] && $l['ok'] && $f['items'] === $l['items']);
        $check("$e: vivo paginado de a 50 = archivos", $l50['ok'] && $l50['items'] === $f['items'] && $l50['pages'] === max(1, (int) ceil(count($f['items']) / 50)));
        $check("$e: complete=true y un solo generated_at en todas las paginas", $l50['complete'] === [true] && count($l50['generated_at']) === 1 && $l['complete'] === [true]);
    }
    $since = '2026-10-06T11:27:29Z';
    $fi = $all($sFiles['base'], 'people', $since); $li = $all($sLive50['base'], 'people', $since);
    $counts['people_incremental_since_extraction'] = count($fi['items'] ?? []);
    $check('incremental: complete=false y mismos items que archivos', $li['ok'] && $li['complete'] === [false] && $li['items'] === $fi['items']);
    $gLive = $get($sLive['base'], 'health')[1]['generated_at'] ?? '';
    $check('generated_at vivo = hora de construccion (posterior a la copia)', $gLive > '2026-10-06T11:27:29Z');
    $check('cache: muchas peticiones dentro del TTL = 1 construccion', $builds($sLive) === 1);
    $h = $get($sLive['base'], 'health');
    $check('health vivo: 200, db ok, no stale, conteos', $h[0] === 200 && $h[1]['db'] === 'ok' && $h[1]['stale'] === false && $h[1]['counts']['people'] === $counts['people']['items']);
    $check('config de One no se ejecuto (sin su salida en la respuesta)', !str_contains($h[2], 'EFECTO_LATERAL'));

    // ---- 5) DataSync del repo: mismo resultado contra vivo, vivo paginado y archivos ------------------------------
    $db = new Database();
    $runFor = function (string $base) use ($db, $get): array {
        $tenant = Util::bin(Util::uuid());
        $db->run("INSERT INTO tenants (tenant_id,name) VALUES (?,'Grupo AZC (e2e vivo)')", [$tenant]);
        $db->run('INSERT INTO retention_settings (tenant_id) VALUES (?)', [$tenant]);
        $db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')", [$tenant, Util::bin(Util::uuid())]);
        foreach (Features::codes() as $code) { $db->run('INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,?)', [$tenant, $code]); }
        $db->run("UPDATE tenants SET default_plan_tenant_id=0x00000000000040008000000000000001, default_plan_id=(SELECT id FROM person_plans WHERE tenant_id=0x00000000000040008000000000000001 AND name='Completo') WHERE tenant_id=?", [$tenant]);
        $source = Util::bin(Util::uuid());
        $db->run("INSERT INTO data_sources (tenant_id,id,name,kind,config_ciphertext,interval_minutes,full_reconcile_local,mode) VALUES (?,?,'AZC One (vivo e2e)','rest',?,10,'02:00:00','simulate')",
            [$tenant, $source, DataSync::seal(['base_url' => $base, 'company' => 'grupo-azc', 'token' => 'x'])]);
        foreach (['empresas', 'firmas', 'sedes', 'areas', 'cargos', 'horarios', 'personas', 'jerarquia'] as $d) { $db->run('INSERT INTO data_domain_owners (tenant_id,domain,source_id) VALUES (?,?,?)', [$tenant, $d, $source]); }
        // Mismo armado de URL y mismas reglas de estado que DataSync::restFetch (el transporte aqui es http local).
        $fetch = function (array $src, string $endpoint, ?string $since, ?string $cursor) use ($get, $base): array {
            [$status, $json] = $get($base, $endpoint, array_filter(['empresa' => 'grupo-azc', 'updated_since' => $since, 'cursor' => $cursor], static fn ($v) => $v !== null && $v !== ''));
            if ($status !== 200) { throw new SyncFailure('source_http_' . $status); }
            if (!is_array($json)) { throw new SyncFailure('source_invalid_json'); }
            return $json;
        };
        $sync = new DataSync($db, $fetch);
        $run = $db->transaction(fn () => $sync->run($tenant, $source, 'full', 'manual', false, true));
        $r = $db->one('SELECT status,error_code,blocked_reason FROM sync_runs WHERE tenant_id=? AND id=?', [$tenant, $run]);
        $items = array_map(static fn ($x) => implode('|', $x), $db->run('SELECT domain,external_id,action,COALESCE(reason,\'-\') reason FROM sync_items WHERE tenant_id=? AND run_id=? ORDER BY domain,external_id,action,reason', [$tenant, $run])->fetchAll(PDO::FETCH_ASSOC));
        $agg = [];
        foreach ($db->run('SELECT domain,action,COALESCE(reason,\'-\') reason,COUNT(*) n FROM sync_items WHERE tenant_id=? AND run_id=? GROUP BY domain,action,reason ORDER BY domain,action,reason', [$tenant, $run])->fetchAll() as $x) {
            $agg[$x['domain']][$x['action'] . ($x['reason'] === '-' ? '' : ':' . $x['reason'])] = (int) $x['n'];
        }
        return ['status' => $r['status'], 'error' => $r['error_code'], 'blocked' => $r['blocked_reason'], 'items' => $items, 'agg' => $agg];
    };
    $dsFiles = $runFor($sFiles['base']); $dsLive = $runFor($sLive['base']); $dsLive50 = $runFor($sLive50['base']);
    $check('DataSync: la vuelta contra archivos no falla', $dsFiles['error'] === null && count($dsFiles['items']) > 0);
    $check('DataSync: vivo = archivos (estado, freno e items por external_id)', [$dsLive['status'], $dsLive['blocked'], $dsLive['items']] === [$dsFiles['status'], $dsFiles['blocked'], $dsFiles['items']]);
    $check('DataSync: vivo paginado de a 50 = archivos', [$dsLive50['status'], $dsLive50['blocked'], $dsLive50['items']] === [$dsFiles['status'], $dsFiles['blocked'], $dsFiles['items']]);

    // ---- 6) TTL, version anterior para cursores y bloqueo --------------------------------------------------------
    [, $p1] = $get($sTtl['base'], 'people', ['empresa' => 'grupo-azc']);
    $g1 = $p1['generated_at'];
    sleep(3);
    $g2 = $get($sTtl['base'], 'health')[1]['generated_at'] ?? null;
    $check('TTL vencido: reconstruye con generated_at nuevo', $g2 !== null && $g2 > $g1 && $builds($sTtl) === 2 && is_file($sTtl['root'] . '/cache/roster.prev.json'));
    [$st2, $p2] = $get($sTtl['base'], 'people', ['empresa' => 'grupo-azc', 'cursor' => $p1['next_cursor']]);
    $check('cursor de la version anterior: 200 con su generated_at y sus items', $st2 === 200 && $p2['generated_at'] === $g1 && $p2['items'] === array_slice($all($sFiles['base'], 'people')['items'], 50, 50));
    $fake = rtrim(strtr(base64_encode(json_encode(['g' => '2000-01-01T00:00:00Z', 'e' => 'people', 'p' => 2])), '+/', '-_'), '=');
    $check('cursor de una version que ya no existe: 409 snapshot_changed', $get($sTtl['base'], 'people', ['empresa' => 'grupo-azc', 'cursor' => $fake])[0] === 409);

    // Bloqueo: con build.lock tomado por esta prueba y la cache vencida, 3 servidores reciben una peticion a la vez;
    // ninguno responde mientras dure el bloqueo y, al soltarlo, solo uno reconstruye.
    sleep(3);
    $before = $builds($sTtl);
    $lock = fopen($sTtl['root'] . '/cache/build.lock', 'c');
    flock($lock, LOCK_EX);
    $mh = curl_multi_init(); $hs = [];
    foreach ($sTtl['bases'] as $b) {
        $ch = curl_init("$b/health"); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
        curl_multi_add_handle($mh, $ch); $hs[] = $ch;
    }
    $t = microtime(true); $done = 0;
    while (microtime(true) - $t < 2.0) { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); while (curl_multi_info_read($mh)) { $done++; } }
    $check('bloqueo: con build.lock tomado nadie reconstruye ni responde', $done === 0 && $builds($sTtl) === $before);
    flock($lock, LOCK_UN); fclose($lock);
    do { curl_multi_exec($mh, $running); curl_multi_select($mh, 0.1); } while ($running > 0);
    $gens = array_map(static fn ($ch) => json_decode((string) curl_multi_getcontent($ch), true)['generated_at'] ?? null, $hs);
    $codes = array_map(static fn ($ch) => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), $hs);
    foreach ($hs as $ch) { curl_multi_remove_handle($mh, $ch); curl_close($ch); }
    curl_multi_close($mh);
    $check('bloqueo: al soltarlo, 3 respuestas 200 con el mismo generated_at y 1 sola construccion', $codes === [200, 200, 200] && count(array_unique($gens)) === 1 && $builds($sTtl) === $before + 1);

    // ---- 7) Sin BD: 503 sin cache; cache vieja con aviso; vencida mas alla de cache_stale_max: 503 ----------------
    [$s503, $j503] = $get($sNoDb['base'], 'people', ['empresa' => 'grupo-azc']);
    $check('sin BD ni cache: 503 source_unavailable', $s503 === 503 && ($j503['error'] ?? null) === 'source_unavailable');
    $check('sin BD ni cache: health 503', $get($sNoDb['base'], 'health')[0] === 503);
    $cache = json_decode(file_get_contents($sLive['root'] . '/cache/roster.json'), true);
    $cache['built_at'] = time() - 400;  // vencida (TTL 300) pero dentro de cache_stale_max
    file_put_contents($sNoDb['root'] . '/cache/roster.json', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents($sStale['root'] . '/cache/roster.json', json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    [$sOld, $jOld] = $get($sNoDb['base'], 'people', ['empresa' => 'grupo-azc']);
    $hOld = $get($sNoDb['base'], 'health')[1];
    $check('sin BD con cache vieja: 200 con el generated_at de la cache', $sOld === 200 && $jOld['generated_at'] === $cache['generated_at'] && count($jOld['items']) === $counts['people']['items']);
    $check('sin BD con cache vieja: health stale=true, db=db_unavailable', ($hOld['stale'] ?? null) === true && ($hOld['db'] ?? null) === 'db_unavailable');
    $check('sin BD con cache vieja: aviso en el log', str_contains($logs($sNoDb), 'serving stale cache'));
    $check('cache mas vieja que cache_stale_max: 503', $get($sStale['base'], 'people', ['empresa' => 'grupo-azc'])[0] === 503);

    // ---- 8) Los logs no tienen credenciales, token ni datos personales --------------------------------------------
    $allLogs = implode("\n", array_map($logs, [$sFiles, $sLive, $sLive50, $sTtl, $sNoDb, $sStale, $sIp]));
    $people = $all($sFiles['base'], 'people')['items'];
    $leak = str_contains($allLogs, $token) || str_contains($allLogs, 'usuario_secreto_x') || str_contains($allLogs, 'clave_secreta_y');
    foreach ($people as $p) { foreach (['document', 'email', 'display_name'] as $k) { if ($p[$k] !== null && $p[$k] !== '' && str_contains($allLogs, $p[$k])) { $leak = true; } } }
    $check('logs sin token, credenciales, cedulas, correos ni nombres', !$leak);

    echo json_encode(['counts' => $counts, 'ddl_en_sesion_read_only' => $ddl,
        'datasync_full_simulada' => ['status' => $dsLive['status'], 'blocked_reason' => $dsLive['blocked'], 'items' => $dsLive['agg']],
        'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
} finally {
    // Solo los procesos que levanto esta prueba, por su handle (nunca por nombre).
    foreach ($servers as $proc) { proc_terminate($proc); proc_close($proc); }
}
exit(in_array(false, $checks, true) ? 1 : 0);
