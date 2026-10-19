<?php
declare(strict_types=1);
use Keeper\{ApiError,Auth,Database,RateLimiter,Signature,Util};

require_once __DIR__ . '/auth-locks-request.php';
require_once __DIR__ . '/legacy/auth-legacy-context.php';

// 2026-10-19 (flota K3 -> v4, 38 equipos y subiendo): Auth::deviceContext tomaba la fila del cliente (tenants) en exclusivo
// en cada envio. Serializaba los envios de todos los equipos de un cliente y chocaba con el calculo de productividad
// (deadlock 1213). Ahora: tenants y users en compartido, devices en exclusivo (ver Auth::lockDevice).

function authLockConn(int $timeout): Database
{
    $c = new Database();
    $c->run('SET SESSION innodb_lock_wait_timeout=' . $timeout);
    return $c;
}

function authLockAuth(Database $c): Auth { return new Auth($c, new Signature(), new RateLimiter()); }

/** [resultado, error ('1205', '1213', '401 invalid_credentials'...) o null, milisegundos]. */
function authLockTry(callable $fn): array
{
    $t = microtime(true);
    try { $v = $fn(); $err = null; }
    catch (PDOException $e) { $v = null; $err = (string) (int) ($e->errorInfo[1] ?? 0); }
    catch (ApiError $e) { $v = null; $err = $e->status . ' ' . $e->errorCode; }
    return [$v, $err, (int) round(1000 * (microtime(true) - $t))];
}

/** Alguna transaccion esperando un bloqueo (hasta ~6 s). INNODB_TRX solo se refresca si nadie lo leyo en los ultimos 100 ms. */
function authLockWaiting(Database $db): ?string
{
    for ($i = 0; $i < 40; $i++) {
        $row = $db->one("SELECT COALESCE(trx_query,'') q FROM information_schema.INNODB_TRX WHERE trx_state='LOCK WAIT' LIMIT 1");
        if ($row) { return $row['q']; }
        usleep(150000);
    }
    return null;
}

function authLockRelogin(array $token, array $key): array
{
    $ch = expect(request('POST', '/client/auth/challenges', ['device_id' => $token['device_id']]), 200, 'auth-locks challenge');
    return expect(request('POST', '/client/login', ['device_id' => $token['device_id'], 'public_key' => $key[1], 'hostname' => 'smoke-device', 'agent_version' => '4.0.0'], $key, null, null, $ch['nonce']), 200, 'auth-locks login');
}

function authLockSecondUser(Database $db, array $u): array
{
    $id = Util::bin(Util::uuid());
    $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,\'auth locks 2\',?,?,?,?,?)', [$u['tenant_id'], $id, $u['firm_id'], $u['site_id'], $u['area_id'], $u['position_id'], $u['schedule_id']]);
    $db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 2 DAY)', [$u['tenant_id'], Util::bin(Util::uuid()), $id, $u['firm_id'], $u['site_id'], $u['area_id'], $u['position_id'], $u['schedule_id']]);
    return $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?', [$u['tenant_id'], $id]);
}

function authLockKeyId(Database $db, array $token): string
{
    return $db->one('SELECT key_id FROM device_sessions WHERE token_hash=?', [hash('sha256', $token['access_token'], true)])['key_id'];
}

/** Proceso hijo; devuelve el handle y sus tuberias. */
function authLockSpawn(array $argv): array
{
    $p = proc_open([PHP_BINARY, ...$argv], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    return [$p, $pipes];
}

function authLockCollect(array $child): array
{
    [$p, $pipes] = $child;
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return json_decode($out, true) ?? ['raw' => $out . $err];
}

function authLockTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $n = [];

    // (a) Dos equipos del mismo cliente en paralelo: el segundo no espera el bloqueo del cliente.
    $u1 = secondTenant($db); $u2 = authLockSecondUser($db, $u1); $t = $u1['tenant_id'];
    $kA = keypair(); $A = enroll($db, $u1, $kA);
    $kB = keypair(); $B = enroll($db, $u2, $kB); policy($db, $B);
    $kC = keypair(); $C = enroll($db, $u1, $kC);
    $a = authLockConn(10); $b = authLockConn(1);
    // Antes (sentencia congelada): el segundo equipo espera la fila del cliente hasta el timeout.
    $a->pdo->beginTransaction(); $a->one(AUTH_LEGACY_CONTEXT_SQL, [authLockKeyId($db, $A), $t, Util::bin($A['device_id'])]);
    $b->pdo->beginTransaction();
    [, $err, $ms] = authLockTry(fn () => $b->one(AUTH_LEGACY_CONTEXT_SQL, [authLockKeyId($db, $B), $t, Util::bin($B['device_id'])]));
    $b->pdo->rollBack(); $a->pdo->rollBack();
    check($err === '1205', "previous auth: second device of the same tenant waits for the first ($err, $ms ms)");
    $n['a_legacy'] = "$err tras $ms ms";
    // Ahora: autenticacion real del equipo A abierta; B (otra persona) y C (misma persona) autentican sin esperar.
    $a->pdo->beginTransaction();
    $ctx = authLockAuth($a)->authenticate(authLockRequest($A['access_token']), false);
    check($ctx['device_id'] === Util::bin($A['device_id']), 'device A authenticated, transaction kept open');
    foreach (['other person' => $B, 'same person' => $C] as $label => $tok) {
        $b->pdo->beginTransaction();
        [$c, $err, $ms] = authLockTry(fn () => authLockAuth($b)->authenticate(authLockRequest($tok['access_token']), false));
        $b->pdo->commit();
        check($err === null && $c['device_id'] === Util::bin($tok['device_id']) && $ms < 500, "$label: second device authenticates while the first is open ($err, $ms ms)");
        $n['a_' . str_replace(' ', '_', $label)] = "$ms ms";
    }
    // De punta a punta: un /client/sync real (servidor HTTP, su propia conexion) del equipo B con A abierto.
    $t0 = microtime(true);
    $res = request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => null, 'release_id' => null], $kB, $B['access_token'], Util::uuid());
    $httpMs = (int) round(1000 * (microtime(true) - $t0));
    $a->pdo->commit();
    check($res[0] === 200 && $httpMs < 3000, "HTTP sync of device B completes while device A holds its auth transaction (HTTP {$res[0]}, $httpMs ms)");
    $n['a_http_sync'] = "HTTP {$res[0]} en $httpMs ms";

    // (b1) Carrera real: envio (Auth::authenticate) contra el calculo de productividad en otro proceso.
    $fx = productivityBatchFixture($db); $day = $fx['days'][2]; $tt = $fx['tenant']; $dev = $fx['devices'][0][0];
    $db->run('UPDATE rollup_days SET dirty=FALSE WHERE tenant_id=?', [$tt]);
    productivityMarkDirty($db, $tt, [$day]);
    $gate = new Database(); $gate->one("SELECT GET_LOCK('productivity-race',0) g");
    $gateId = (int) $gate->one('SELECT CONNECTION_ID() id')['id'];
    $child = authLockSpawn([__DIR__ . '/productivity-child.php', 'new', $fx['tid']]);
    $midway = false;
    for ($i = 0; $i < 200 && !$midway; $i++) { usleep(25000); $midway = (bool) $db->one("SELECT 1 FROM information_schema.PROCESSLIST WHERE INFO LIKE '%productivity-race%' AND ID<>CONNECTION_ID() AND ID<>?", [$gateId]); }
    $sync = authLockConn(10); $sync->pdo->beginTransaction();
    authLockAuth($sync)->authenticate(authLockRequest($fx['tokens'][0]['access_token']), false);
    $gate->one("SELECT RELEASE_LOCK('productivity-race') r");
    usleep(300000);
    $waited = authLockWaiting($db);
    [, $syncErr] = authLockTry(function () use ($sync, $tt, $day): void {
        $sync->run('INSERT INTO rollup_days (tenant_id,day,dirty,input_revision) VALUES (?,?,TRUE,1) ON DUPLICATE KEY UPDATE dirty=TRUE,input_revision=input_revision+VALUES(input_revision),finalized_at=NULL', [$tt, $day]);
        $sync->pdo->commit();
    });
    if ($sync->pdo->inTransaction()) { $sync->pdo->rollBack(); }
    $calc = authLockCollect($child);
    check($midway && $waited !== null && $syncErr === null && ($calc['ok']['requeued'] ?? null) === 1, 'real auth vs productivity calculation: no deadlock, calculation waits and keeps the day pending ' . json_encode([$midway, substr((string) $waited, 0, 40), $syncErr, $calc]));
    $n['b1'] = 'sync=' . var_export($syncErr, true) . ' calc=' . json_encode($calc) . ' espera del calculo: ' . substr((string) $waited, 0, 40);

    // (b2) El orden exacto del deadlock de produccion: el calculo ya tiene tenants (compartido) y luego pide devices.
    $calcConn = authLockConn(1); $sync = authLockConn(1);
    $calcConn->pdo->beginTransaction(); $calcConn->one('SELECT tenant_id FROM tenants WHERE tenant_id=? LOCK IN SHARE MODE', [$tt]);
    $sync->pdo->beginTransaction();
    [, $legacyErr, $legacyMs] = authLockTry(fn () => $sync->one(AUTH_LEGACY_CONTEXT_SQL, [authLockKeyId($db, $fx['tokens'][0]), $tt, $dev]));
    $sync->pdo->rollBack();
    check($legacyErr === '1205', "previous auth waits for the calculation's tenant lock while holding devices: the cycle of the production deadlock ($legacyErr)");
    $sync->pdo->beginTransaction();
    [, $err, $ms] = authLockTry(fn () => authLockAuth($sync)->authenticate(authLockRequest($fx['tokens'][0]['access_token']), false));
    check($err === null && $ms < 500, "new auth does not wait for the calculation's tenant lock ($err, $ms ms)");
    // Lo que pide la llave foranea al insertar en episode_daily: espera al envio (no al reves), sin ciclo.
    $fk = fn () => $calcConn->one('SELECT id FROM devices WHERE tenant_id=? AND id=? LOCK IN SHARE MODE', [$tt, $dev]);
    [, $calcErr] = authLockTry($fk);
    $sync->pdo->commit();
    [, $calcErr2] = authLockTry($fk);
    $calcConn->pdo->rollBack();
    check($calcErr === '1205' && $calcErr2 === null, "calculation waits for the sync's device lock, then proceeds after its commit ($calcErr / $calcErr2)");
    $n['b2'] = "antes: envio $legacyErr (espera al calculo); ahora: envio sin espera ($ms ms), calculo espera al envio ($calcErr) y sigue tras el commit";

    // (c) Revocacion concurrente: sigue rechazando como antes. Solo cambios de estado (sin subir auth_version) incluidos: son
    // los que una lectura sin bloqueo de tenants/users dejaria pasar.
    $cases = [
        'tenant auth_version' => fn (array $x) => ['UPDATE tenants SET auth_version=auth_version+1 WHERE tenant_id=?', [$x['t']], null],
        'tenant suspended (status only)' => fn (array $x) => ["UPDATE tenants SET status='suspended' WHERE tenant_id=?", [$x['t']], "UPDATE tenants SET status='active' WHERE tenant_id=?"],
        'person auth_version' => fn (array $x) => ['UPDATE users SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$x['t'], $x['u']], null],
        'person inactive (status only)' => fn (array $x) => ["UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$x['t'], $x['u']], "UPDATE users SET status='active' WHERE tenant_id=? AND id=?"],
        'device auth_version' => fn (array $x) => ['UPDATE devices SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$x['t'], $x['d']], null],
        'device revoked (status only)' => fn (array $x) => ["UPDATE devices SET status='revoked' WHERE tenant_id=? AND id=?", [$x['t'], $x['d']], "UPDATE devices SET status='active' WHERE tenant_id=? AND id=?"],
    ];
    foreach ($cases as $label => $case) {
        $u = secondTenant($db); $key = keypair(); $tok = enroll($db, $u, $key);
        [$sql, $args, $restore] = $case(['t' => $u['tenant_id'], 'u' => $u['id'], 'd' => Util::bin($tok['device_id'])]);
        // 1. Envio en curso y llega la revocacion: la revocacion espera al envio; despues, el token viejo se rechaza.
        $s = authLockConn(10); $r = authLockConn(1);
        $s->pdo->beginTransaction();
        authLockAuth($s)->authenticate(authLockRequest($tok['access_token']), false);
        $r->pdo->beginTransaction();
        [, $revErr, $revMs] = authLockTry(fn () => $r->run($sql, $args));
        $r->pdo->rollBack();
        $s->pdo->commit();
        $r->run($sql, $args);
        [, $after] = authLockTry(fn () => $db->transaction(fn () => authLockAuth($db)->authenticate(authLockRequest($tok['access_token']), false)));
        check($revErr === '1205' && $after === '401 invalid_credentials', "$label: revocation waits for the sync in flight ($revErr, $revMs ms), then the old token is rejected ($after)");
        if ($restore !== null) { $db->run($restore, $args); }
        $tok = authLockRelogin($tok, $key);
        // 2. Revocacion en curso y llega el envio (otro proceso): el envio espera y, al confirmar la revocacion, se rechaza.
        $r = authLockConn(10); $r->pdo->beginTransaction(); $r->run($sql, $args);
        $child = authLockSpawn([__DIR__ . '/auth-locks-child.php', $tok['access_token']]);
        $waited = authLockWaiting($db);
        $r->pdo->commit();
        $res = authLockCollect($child);
        check($waited !== null && ($res['status'] ?? null) === 401 && ($res['code'] ?? null) === 'invalid_credentials', "$label: sync arriving during the revocation waits and is rejected " . json_encode($res));
        $n['c'][$label] = "revocacion espera al envio ($revErr tras $revMs ms) y luego 401; envio durante la revocacion espera y da {$res['status']} {$res['code']} ({$res['ms']} ms)";
        if ($restore !== null) { $db->run($restore, $args); }
    }

    foreach ($n as $k => $v) { echo "  $k: " . (is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : $v) . "\n"; }
    echo 'PASS device auth locks (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
