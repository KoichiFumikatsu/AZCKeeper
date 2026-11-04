<?php
declare(strict_types=1);
use Keeper\{AccessScope,Config,Database,Portal,Util,Validator};

// Encargo 4 (2026-10-13): puerta del Portal. /portal/v1/<ruta> = la misma operacion de /v1/<ruta> con token de persona.
function portalRequest(string $method, string $path, mixed $body = null, ?string $token = null, array $headers = [], string $prefix = '/portal/v1'): array
{
    $uri = Config::get('ORIGIN') . $prefix . $path;
    if ($body !== null) { $headers['content-type'] = 'application/json'; }
    if ($token !== null) { $headers['authorization'] = 'Bearer ' . $token; }
    $received = [];
    $curl = curl_init($uri);
    curl_setopt_array($curl, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_map(fn ($k, $v) => $k . ': ' . $v, array_keys($headers), $headers),
        CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$received): int { if (str_contains($line, ':')) { [$k, $v] = explode(':', $line, 2); $received[strtolower($k)] = trim($v); } return strlen($line); }]);
    if ($body !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, Util::json($body)); }
    $response = curl_exec($curl);
    if ($response === false) { throw new RuntimeException('HTTP connection failed'); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
    $json = $response === '' ? null : json_decode($response, true, 64, JSON_THROW_ON_ERROR);
    if ($status >= 400) { (new Validator())->named('Problem', json_decode($response)); }
    return [$status, $json, $received, $response];
}

/** Repone el cupo de intentos de login del Portal de un correo y de la IP local (las pruebas entran muchas veces seguidas). */
function portalResetQuota(string $email): void
{
    foreach (['portal-auth:' . hash('sha256', '127.0.0.1|' . strtolower($email)), 'portal-auth-ip:' . hash('sha256', '127.0.0.1')] as $identity) {
        $key = hash('sha256', 'bootstrap:' . $identity);
        @unlink(Config::get('RATE_DIR') . '/' . substr($key, 0, 2) . '/' . $key . '.json');
    }
}
function portalLogin(string $email, string $password): array
{
    portalResetQuota($email);
    return portalRequest('POST', '/login', ['email' => $email, 'password' => $password]);
}

function portalEffectivePlanTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $tenant = $o['tenant']; $tid = Util::id($tenant);
    $foreign = secondTenant($db); $foreignTid = Util::id($foreign['tenant_id']);
    $platform = sharedPlatform($db); $platformId = Util::bin(Keeper\AdminAuth::PLATFORM);
    accessResetPreloginQuota();
    $team = accessAccount($db, $o, 'coord2', ['reportes.ver'], new AccessScope('team'));
    $own = accessAccount($db, $o, 'agTorre', ['reportes.ver'], AccessScope::own());
    $denied = accessAccount($db, $o, 'juan', ['usuarios.ver'], AccessScope::all());
    $other = adminFixture($db, $foreign, ['reportes.ver']);
    foreach ([$o['coord2'], $o['agTorre'], $o['juan'], $foreign] as $u) {
        $db->run('UPDATE users SET portal_login_enabled=TRUE WHERE tenant_id=? AND id=?', [$u['tenant_id'], $u['id']]);
    }
    $tokens = [];
    foreach (['team' => $team, 'own' => $own, 'denied' => $denied, 'other' => $other, 'platform' => $platform] as $key => $account) {
        $tokens[$key] = expect(portalLogin($account['email'], $account['password']), 200, 'effective plan login ' . $key)['access_token'];
    }
    $plans = [];
    foreach (['default' => ['presencia', 'productividad'], 'own' => ['productividad', 'usb'], 'foreign' => ['usb']] as $key => $functions) {
        $id = Util::bin(Util::uuid()); $name = 'Portal effective ' . $key;
        $db->run('INSERT INTO person_plans (tenant_id,id,name) VALUES (?,?,?)', [$platformId, $id, $name]);
        foreach ($functions as $function) { $db->run('INSERT INTO person_plan_items (tenant_id,plan_id,feature_code) VALUES (?,?,?)', [$platformId, $id, $function]); }
        $plans[$key] = ['plan_id' => Util::id($id), 'name' => $name, 'functions' => $functions];
    }
    $db->run('UPDATE tenants SET default_plan_tenant_id=?,default_plan_id=? WHERE tenant_id=?', [$platformId, Util::bin($plans['default']['plan_id']), $tenant]);
    $db->run('UPDATE tenants SET default_plan_tenant_id=?,default_plan_id=? WHERE tenant_id=?', [$platformId, Util::bin($plans['foreign']['plan_id']), $foreign['tenant_id']]);
    $db->run('UPDATE users SET plan_tenant_id=?,plan_id=? WHERE tenant_id=? AND id=?', [$platformId, Util::bin($plans['own']['plan_id']), $tenant, $o['agTorre']['id']]);
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$tenant]);
    $ownPlan = $plans['own']; $ownPlan['functions'] = ['productividad'];
    $path = fn (string $id) => '/users/' . Util::id($id) . '/effective-plan';
    $v = new Validator(); $op = $v->contract['paths']['/users/{id}/effective-plan']['get'];
    check($op['operationId'] === 'getUserEffectivePlan' && $op['x-permission'] === $v->contract['paths']['/users/{id}/activity']['get']['x-permission'], 'effective plan uses the activity permission');
    check($op['security'] === $v->contract['paths']['/users/{id}/activity']['get']['security'], 'effective plan uses both activity authentication channels');
    $cases = 0;
    $read = function (array $account, string $token, string $route, int $status, ?array $expected, string $label, array $headers = []) use ($v, $op, &$cases): void {
        $portal = portalRequest('GET', $route, null, $token, $headers);
        $panel = adminRequest($account, 'GET', $route, null, $headers);
        $a = expect($portal, $status, 'effective plan portal ' . $label);
        $b = expect($panel, $status, 'effective plan panel ' . $label);
        if ($status === 200) {
            $v->check($op['responses']['200']['content']['application/json']['schema'], json_decode($portal[3]));
            check($a === $expected && $b === $expected, 'effective plan exact DTO and channel parity: ' . $label);
        } else {
            $code = $status === 404 ? 'resource_not_found' : 'permission_denied';
            check($a['code'] === $code && $b['code'] === $code && !array_key_exists('plan_id', $a) && !array_key_exists('plan_id', $b), 'effective plan denial without plan data: ' . $label);
        }
        $cases++;
    };
    foreach ([['own', $own, $o['agTorre']['id'], 200, $ownPlan], ['own', $own, $o['ag808']['id'], 404, null],
        ['team', $team, $o['coord2']['id'], 200, $plans['default']], ['team', $team, $o['ag808']['id'], 200, $plans['default']],
        ['team', $team, $o['jur808']['id'], 200, $plans['default']], ['team', $team, $o['zafra']['id'], 404, null],
        ['denied', $denied, $o['juan']['id'], 403, null]] as [$key, $account, $user, $status, $expected]) {
        $read($account, $tokens[$key], $path($user), $status, $expected, $key . ' ' . Util::id($user));
        $activity = '/users/' . Util::id($user) . '/activity?from=2026-09-10&to=2026-09-11';
        check(portalRequest('GET', $activity, null, $tokens[$key])[0] === $status && adminRequest($account, 'GET', $activity)[0] === $status, 'effective plan scope and permission match activity');
    }
    $read($platform, $tokens['platform'], $path($o['agTorre']['id']), 200, $ownPlan, 'platform tenant A', ['x-tenant-id' => $tid]);
    $read($platform, $tokens['platform'], $path($foreign['id']), 200, $plans['foreign'], 'platform tenant B', ['x-tenant-id' => $foreignTid]);
    $read($other, $tokens['other'], $path($foreign['id']), 200, $plans['foreign'], 'tenant B own person');
    $read($team, $tokens['team'], $path($foreign['id']), 404, null, 'tenant A cannot read B');
    $read($other, $tokens['other'], $path($o['agTorre']['id']), 404, null, 'tenant B cannot read A');
    $read($team, $tokens['team'], $path($foreign['id']), 404, null, 'foreign tenant header', ['x-tenant-id' => $foreignTid]);
    $read($team, $tokens['team'], $path($o['coord2']['id']) . '?tenant=' . $foreignTid, 404, null, 'foreign tenant query');
    $read($platform, $tokens['platform'], $path($foreign['id']), 404, null, 'platform wrong tenant A', ['x-tenant-id' => $tid]);
    $read($platform, $tokens['platform'], $path($o['agTorre']['id']), 404, null, 'platform wrong tenant B', ['x-tenant-id' => $foreignTid]);
    $read($team, $tokens['team'], $path(Util::bin(Util::uuid())), 404, null, 'unknown person');
    $db->run('UPDATE person_plans SET active=FALSE WHERE tenant_id=? AND id=?', [$platformId, Util::bin($plans['own']['plan_id'])]);
    $read($own, $tokens['own'], $path($o['agTorre']['id']), 200, $plans['default'], 'inactive own plan falls back');
    $db->run('UPDATE tenants SET default_plan_tenant_id=NULL,default_plan_id=NULL WHERE tenant_id=?', [$tenant]);
    $empty = ['plan_id' => null, 'name' => null, 'functions' => []];
    $read($own, $tokens['own'], $path($o['agTorre']['id']), 200, $empty, 'no effective plan for self');
    $read($team, $tokens['team'], $path($o['ag808']['id']), 200, $empty, 'no assigned or default plan');
    $read($other, $tokens['other'], $path($foreign['id']), 200, $plans['foreign'], 'tenant B unaffected');
    Keeper\PersonPlans::markRecompile($db, $tenant);
    $pending = $db->one('SELECT * FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]);
    $policies = $db->run('SELECT * FROM effective_policies WHERE tenant_id=? ORDER BY device_id,policy_version', [$tenant])->fetchAll();
    $read($platform, $tokens['platform'], $path($o['ag808']['id']), 200, $empty, 'read only with pending policies', ['x-tenant-id' => $tid]);
    check($pending === $db->one('SELECT * FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]), 'effective plan leaves pending compilation untouched');
    check($policies === $db->run('SELECT * FROM effective_policies WHERE tenant_id=? ORDER BY device_id,policy_version', [$tenant])->fetchAll(), 'effective plan leaves compiled policies untouched');
    $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=?', [$tenant]);
    expect(portalRequest('GET', $path($o['agTorre']['id'])), 401, 'effective plan requires portal authentication');
    expect(portalRequest('GET', $path($o['agTorre']['id']), null, null, ['x-tenant-id' => $tid], '/v1'), 401, 'effective plan requires panel authentication');
    echo 'PASS portal effective plan (' . ($GLOBALS['checks'] - $before) . ' assertions; ' . $cases . " paired cases)\n";
}

function portalTests(Database $db): void
{
    portalEffectivePlanTests($db);
    $before = $GLOBALS['checks'];
    $o = accessOrg($db); $tid = Util::id($o['tenant']);
    $perms = ['usuarios.ver', 'usuarios.editar', 'equipos.ver', 'presencia.ver', 'reportes.ver', 'horarios.ver', 'reglas.ver'];
    accessResetPreloginQuota();
    $team = accessAccount($db, $o, 'coord2', $perms, new AccessScope('team'));
    $own = accessAccount($db, $o, 'agTorre', $perms, AccessScope::own());
    $all = accessAccount($db, $o, 'zafra', $perms, AccessScope::all());
    $flag = fn (string $key, bool $on) => $db->run('UPDATE users SET portal_login_enabled=? WHERE tenant_id=? AND id=?', [(int) $on, $o['tenant'], $o[$key]['id']]);

    // Contrato: la lista blanca del codigo es exactamente lo que el contrato marca con portalBearer.
    $declared = [];
    foreach ((new Validator())->contract['paths'] as $path => $ops) {
        foreach ($ops as $method => $op) { if (is_array($op) && in_array('portalBearer', array_merge(...array_map('array_keys', $op['security'] ?? [[]])), true)) { $declared[] = strtoupper($method) . ' ' . $path; } }
    }
    $code = array_merge(Portal::ROUTES, array_values(array_filter(Portal::SESSION, fn ($r) => !in_array($r, ['POST /portal/v1/login', 'POST /portal/v1/refresh'], true))));
    sort($declared); sort($code);
    check($declared === $code, 'portal whitelist matches the contract (portalBearer)');

    // Login: credenciales malas, persona sin interruptor, correcto.
    $bad = portalLogin($team['email'], 'wrong-password');
    check($bad[0] === 401 && $bad[1]['code'] === 'invalid_credentials', 'portal login rejects a wrong password');
    $off = portalLogin($team['email'], $team['password']);
    check($off[0] === 403 && $off[1]['code'] === 'portal_not_enabled', 'person without the portal switch gets 403');
    $flag('coord2', true); $flag('agTorre', true); $flag('zafra', true);
    $s = expect(portalLogin($team['email'], $team['password']), 200, 'portal login');
    check($s['expires_in'] === 900 && $s['token_type'] === 'Bearer' && strlen($s['access_token']) === 43 && strlen($s['refresh_token']) === 43, 'portal tokens issued');
    check($s['user']['id'] === Util::id($o['coord2']['id']) && $s['user']['tenant_id'] === $tid, 'login returns the person');
    check($db->one('SELECT COUNT(*) n FROM portal_tokens WHERE token_hash=?', [hash('sha256', $s['access_token'], true)])['n'] === 1
        && !$db->one('SELECT 1 x FROM portal_tokens WHERE token_hash=?', [$s['access_token']]), 'only the token hash is stored');
    $tok = $s['access_token'];

    // Quien soy.
    $me = expect(portalRequest('GET', '/me', null, $tok), 200, 'portal me');
    check($me['user_id'] === Util::id($o['coord2']['id']) && $me['scope']['kind'] === 'team' && $me['tenant']['id'] === $tid && isset($me['tenant']['branding']['display_name']), 'me reports person, scope and brand');
    check(in_array('usuarios.ver', $me['permissions'], true) && in_array('presencia', $me['features'], true), 'me reports permissions and functions');
    $access = expect(portalRequest('GET', '/me/access', null, $tok), 200, 'portal my access');
    check(array_column($access['permissions'], 'scopes', 'code')['usuarios.ver'] === ['team'], 'my access through the portal');

    // Mismo alcance que el panel, mismos 404 fuera de el.
    $ids = fn (array $r) => (function ($x) { sort($x); return $x; })(array_column($r['data'], 'id'));
    $panelUsers = expect(adminRequest($team, 'GET', '/users?limit=100'), 200, 'panel team users');
    $portalUsers = expect(portalRequest('GET', '/users?limit=100', null, $tok), 200, 'portal team users');
    check($ids($panelUsers) === $ids($portalUsers) && count($portalUsers['data']) === 3, 'team scope identical to the panel');
    $outside = Util::id($o['zafra']['id']);
    check(adminRequest($team, 'GET', '/users/' . $outside)[0] === 404 && portalRequest('GET', '/users/' . $outside, null, $tok)[0] === 404, 'outside person 404 in both doors');
    check(portalRequest('GET', '/users/' . Util::id($o['ag808']['id']), null, $tok)[0] === 200, 'team member visible');
    $devices = expect(portalRequest('GET', '/tenants/' . $tid . '/devices?limit=100', null, $tok), 200, 'portal devices');
    check(count($devices['data']) === 3, 'devices follow the team scope');
    check(portalRequest('GET', '/devices/' . $devices['data'][0]['id'], null, $tok)[0] === 200, 'device detail through the portal');
    $foreign = secondTenant($db);
    check(portalRequest('GET', '/tenants/' . Util::id($foreign['tenant_id']) . '/devices', null, $tok)[0] === 404, 'other tenant devices 404');
    check(portalRequest('GET', '/users', null, $tok, ['x-tenant-id' => Util::id($foreign['tenant_id'])])[0] === 404, 'other tenant header 404');
    $range = '?from=2026-09-10&to=2026-09-11';
    foreach (['/dashboard', '/dashboard/problems', '/reports/people' . $range, '/reports/presence' . $range, '/reports/productivity' . $range, '/reports/apps' . $range, '/absences', '/users/' . Util::id($o['coord2']['id']) . '/activity' . $range] as $path) {
        $status = portalRequest('GET', $path, null, $tok)[0];
        check($status === adminRequest($team, 'GET', $path)[0] && $status === 200, "portal read $path = panel ($status)");
    }
    $ownS = expect(portalLogin($own['email'], $own['password']), 200, 'own login');
    $mine = expect(portalRequest('GET', '/users', null, $ownS['access_token']), 200, 'own users');
    check(array_column($mine['data'], 'id') === [Util::id($o['agTorre']['id'])], 'self scope sees only self');
    check(portalRequest('GET', '/users/' . Util::id($o['ag808']['id']), null, $ownS['access_token'])[0] === 404, 'self scope: others 404');

    // Este equipo comparte horario: aparece una sola vez (el panel sigue exigiendo alcance completo).
    $sched = expect(portalRequest('GET', '/schedules', null, $tok), 200, 'portal team schedule');
    check(array_column($sched['data'], 'id') === [Util::id($o['coord2']['schedule_id'])], 'portal schedules: shared team schedule deduplicated');
    check(adminRequest($team, 'GET', '/schedules')[0] === 403, 'panel schedules unchanged (scope all)');
    $allS = expect(portalLogin($all['email'], $all['password']), 200, 'all login');
    check(count(expect(portalRequest('GET', '/schedules', null, $allS['access_token']), 200, 'all schedules')['data']) >= 1, 'scope all lists schedules');
    // Empresas: con el mismo permiso que en el panel (empresas.ver); estas personas no lo tienen.
    check(portalRequest('GET', '/tenants', null, $allS['access_token'])[0] === adminRequest($all, 'GET', '/tenants')[0], 'person lists companies like in the panel');

    // Superadmin de plataforma (decision 2026-10-14): entra sin persona ni interruptor y ve lo del panel de la empresa elegida.
    $platform = sharedPlatform($db);
    $sp = expect(portalLogin($platform['email'], $platform['password']), 200, 'superadmin portal login');
    check($sp['user']['platform'] === true && $sp['user']['id'] === null && $sp['user']['tenant_id'] === null, 'superadmin login has no person nor company');
    $spt = $sp['access_token']; $in = ['x-tenant-id' => $tid];
    check(in_array($tid, array_column(expect(portalRequest('GET', '/tenants?limit=100', null, $spt), 200, 'superadmin tenants')['data'], 'id'), true), 'superadmin lists companies');
    $spMe = expect(portalRequest('GET', '/me', null, $spt, $in), 200, 'superadmin me');
    check($spMe['platform'] === true && $spMe['user_id'] === null && $spMe['tenant']['id'] === $tid && in_array('usuarios.ver', $spMe['permissions'], true), 'superadmin me in the chosen company');
    $spUsers = expect(portalRequest('GET', '/users?limit=100', null, $spt, $in), 200, 'superadmin users');
    check($ids($spUsers) === $ids(expect(portalRequest('GET', '/users?limit=100', null, $allS['access_token']), 200, 'all users')), 'superadmin sees the whole company');
    check(portalRequest('GET', '/tenants/' . $tid . '/devices', null, $spt)[0] === 200, 'superadmin company devices by route');
    check(portalRequest('GET', '/tenants/' . $tid . '/devices', null, $spt, ['x-tenant-id' => Util::id($foreign['tenant_id'])])[0] === 404, 'route and header must agree');
    check(portalRequest('GET', '/users', null, $spt, ['x-tenant-id' => Util::id($foreign['tenant_id'])])[0] === 200, 'superadmin reaches any company');
    check(portalRequest('GET', '/users', null, $spt, ['x-tenant-id' => Util::uuid()])[0] === 404, 'unknown company 404');
    $spAbs = portalRequest('POST', '/absences', ['kind' => 'permiso', 'starts_at' => '2026-09-15T13:00:00Z', 'ends_at' => '2026-09-15T15:00:00Z', 'all_day' => false, 'user_id' => Util::id($o['coord2']['id'])], $spt, $in);
    check($spAbs[0] === 403 && $spAbs[1]['detail'] === 'portal_self_only', 'superadmin cannot register absences in the portal');
    check(portalRequest('GET', '/policies', null, $spt, $in)[0] === 404, 'superadmin still limited to the portal whitelist');
    $spR = expect(portalRequest('POST', '/refresh', ['refresh_token' => $sp['refresh_token']]), 200, 'superadmin refresh');
    check(portalRequest('GET', '/me', null, $spR['access_token'], $in)[0] === 200, 'superadmin refreshed token works');
    check(portalRequest('POST', '/logout', null, $spR['access_token'])[0] === 204, 'superadmin logout');

    // Lista blanca: lo demas es 404, incluso con token valido; el panel no atiende rutas del Portal.
    foreach ([['GET', '/reports/dual-job-alerts'], ['GET', '/data-sources'], ['GET', '/sync-runs'], ['GET', '/app-classification'], ['GET', '/person-plans'], ['GET', '/person-plans/available'], ['GET', '/policies'], ['GET', '/roles'], ['GET', '/audit'], ['POST', '/devices/' . $devices['data'][0]['id'] . '/commands'], ['GET', '/auth/csrf'], ['PATCH', '/users/' . Util::id($o['coord2']['id'])], ['GET', '/client/policy']] as [$m, $p]) {
        check(portalRequest($m, $p, null, $tok)[0] === 404, "portal hides $m $p");
    }
    check(request('POST', '/portal/v1/login', ['email' => $team['email'], 'password' => $team['password']])[0] === 404, 'portal session not served under /v1');
    check(portalRequest('GET', '/users')[0] === 401 && portalRequest('GET', '/users', null, str_repeat('A', 43))[0] === 401, 'portal data needs a valid token');
    check(portalRequest('GET', '/users', null, null, ['cookie' => '__Host-keeper_admin=' . $team['cookie']])[0] === 401, 'panel cookie does not open the portal');

    // Escrituras: ausencia solo propia; auditada con canal portal.
    $absence = ['kind' => 'permiso', 'starts_at' => '2026-09-15T13:00:00Z', 'ends_at' => '2026-09-15T15:00:00Z', 'all_day' => false];
    $other = portalRequest('POST', '/absences', $absence + ['user_id' => Util::id($o['ag808']['id'])], $tok);
    check($other[0] === 403 && $other[1]['detail'] === 'portal_self_only', 'portal cannot register absences for someone else');
    $mineA = expect(portalRequest('POST', '/absences', $absence + ['user_id' => Util::id($o['coord2']['id'])], $tok), 201, 'own absence');
    $audit = $db->one("SELECT channel,actor_id FROM audit_log WHERE tenant_id=? AND resource_id=? AND action='createAbsence'", [$o['tenant'], Util::bin($mineA['id'])]);
    $principal = $db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='admin' AND admin_id=?", [$o['tenant'], $team['id']])['id'];
    check($audit['channel'] === 'portal' && $audit['actor_id'] === $principal, 'portal writes audited with channel portal and the person principal');
    check($db->one("SELECT channel FROM audit_log WHERE tenant_id=? AND action='portal.login' ORDER BY at DESC LIMIT 1", [$o['tenant']])['channel'] === 'portal', 'portal login audited');

    // Refresh: rota; el viejo queda invalido; reutilizarlo revoca la familia.
    $r1 = expect(portalRequest('POST', '/refresh', ['refresh_token' => $s['refresh_token']]), 200, 'refresh');
    check($r1['refresh_token'] !== $s['refresh_token'] && $r1['access_token'] !== $tok, 'refresh rotates both tokens');
    check(portalRequest('GET', '/me', null, $tok)[0] === 401, 'previous access token stops working after refresh');
    check(portalRequest('GET', '/me', null, $r1['access_token'])[0] === 200, 'new access token works');
    $reuse = portalRequest('POST', '/refresh', ['refresh_token' => $s['refresh_token']]);
    check($reuse[0] === 401, 'reused refresh token rejected');
    check(portalRequest('GET', '/me', null, $r1['access_token'])[0] === 401 && portalRequest('POST', '/refresh', ['refresh_token' => $r1['refresh_token']])[0] === 401, 'reuse revokes the whole family');
    check((bool) $db->one("SELECT 1 x FROM audit_log WHERE tenant_id=? AND action='portal.refresh_reused' AND outcome='denied'", [$o['tenant']]), 'refresh reuse audited');

    // Logout.
    $s2 = expect(portalLogin($team['email'], $team['password']), 200, 'login again');
    check(portalRequest('POST', '/logout', null, $s2['access_token'])[0] === 204, 'logout');
    check(portalRequest('GET', '/me', null, $s2['access_token'])[0] === 401 && portalRequest('POST', '/refresh', ['refresh_token' => $s2['refresh_token']])[0] === 401, 'logout revokes the session');

    // Token vencido.
    $s3 = expect(portalLogin($team['email'], $team['password']), 200, 'login expiry');
    $db->run('UPDATE portal_tokens SET created_at=UTC_TIMESTAMP(6)-INTERVAL 2 HOUR,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 HOUR WHERE token_hash=?', [hash('sha256', $s3['access_token'], true)]);
    check(portalRequest('GET', '/me', null, $s3['access_token'])[0] === 401, 'expired access token rejected');
    $s3 = expect(portalRequest('POST', '/refresh', ['refresh_token' => $s3['refresh_token']]), 200, 'refresh after expiry');

    // Invalidaciones: interruptor, persona inactiva, auth_version de la cuenta, contrasena temporal.
    $flag('coord2', false);
    check(portalRequest('GET', '/me', null, $s3['access_token'])[0] === 401, 'switch off invalidates tokens');
    $flag('coord2', true);
    check(portalRequest('GET', '/me', null, $s3['access_token'])[0] === 200, 'switch back on');
    $db->run('UPDATE admin_accounts SET must_change_password=TRUE WHERE tenant_id=? AND id=?', [$o['tenant'], $team['id']]);
    check(portalRequest('GET', '/me', null, $s3['access_token'])[1]['code'] === 'password_change_required', 'temporary password blocks data');
    $db->run('UPDATE admin_accounts SET must_change_password=FALSE WHERE tenant_id=? AND id=?', [$o['tenant'], $team['id']]);
    $db->run('UPDATE admin_accounts SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$o['tenant'], $team['id']]);
    check(portalRequest('GET', '/me', null, $s3['access_token'])[0] === 401, 'auth_version bump invalidates tokens');
    $s4 = expect(portalLogin($own['email'], $own['password']), 200, 'own login 2');
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$o['tenant'], $o['agTorre']['id']]);
    check(portalRequest('GET', '/me', null, $s4['access_token'])[0] === 401, 'inactive person invalidates tokens');
    check(portalLogin($own['email'], $own['password'])[0] === 401, 'inactive person cannot log in');
    $db->run("UPDATE users SET status='active' WHERE tenant_id=? AND id=?", [$o['tenant'], $o['agTorre']['id']]);

    // Cambio de contrasena: invalida los tokens anteriores y entrega un par nuevo.
    $s5 = expect(portalLogin($team['email'], $team['password']), 200, 'login before password');
    check(portalRequest('POST', '/password', ['current_password' => 'nope-nope-nope', 'new_password' => 'Portal-Nueva-2026!'], $s5['access_token'])[0] === 401, 'password change needs the current password');
    $p = expect(portalRequest('POST', '/password', ['current_password' => $team['password'], 'new_password' => 'Portal-Nueva-2026!'], $s5['access_token']), 200, 'password change');
    check(portalRequest('GET', '/me', null, $s5['access_token'])[0] === 401 && portalRequest('POST', '/refresh', ['refresh_token' => $s5['refresh_token']])[0] === 401, 'password change invalidates previous tokens');
    check(portalRequest('GET', '/me', null, $p['access_token'])[0] === 200, 'new pair after password change works');
    check(adminRequest($team, 'GET', '/users')[0] === 401, 'password change closes panel sessions too');
    check(portalLogin($team['email'], 'Portal-Nueva-2026!')[0] === 200 && portalLogin($team['email'], $team['password'])[0] === 401, 'new password is the panel password');

    // Limite de intentos por IP + correo: bloqueo temporal con Retry-After.
    portalResetQuota($own['email']); $statuses = []; for ($n = 0; $n < 7; $n++) { $statuses[] = portalRequest('POST', '/login', ['email' => $own['email'], 'password' => 'wrong-' . $n]); }
    $last = end($statuses);
    check($last[0] === 429 && isset($last[2]['retry-after']), 'repeated failed logins are temporarily blocked');

    // Bloqueo: 10 contrasenas fallidas seguidas para un correo lo bloquean 15 min, aun con la contrasena correcta.
    $fail = function (string $email, int $n): void { for ($i = 0; $i < $n; $i++) { portalLogin($email, 'wrong-lock-' . $i); } };
    $fail($all['email'], 9);
    check(portalLogin($all['email'], $all['password'])[0] === 200, 'nine failures do not lock; a good login resets the count');
    check(!$db->one('SELECT 1 x FROM portal_login_lockouts WHERE email_hash=?', [hash('sha256', strtolower($all['email']), true)]), 'good login clears the failures');
    $fail($all['email'], 10);
    $locked = portalLogin($all['email'], $all['password']);
    check($locked[0] === 429 && $locked[1]['detail'] === 'account_locked' && (int) $locked[2]['retry-after'] > 800, 'ten failures lock the account for 15 minutes');
    $ghost = Util::uuid() . '@example.test';
    $fail($ghost, 10);
    $ghostLocked = portalLogin($ghost, 'whatever');
    check($ghostLocked[0] === 429 && $ghostLocked[1]['detail'] === 'account_locked', 'unknown emails lock the same (no account enumeration)');
    $db->run('UPDATE portal_login_lockouts SET locked_until=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE email_hash=?', [hash('sha256', strtolower($all['email']), true)]);
    check(portalLogin($all['email'], $all['password'])[0] === 200, 'the lock expires');
    $db->run('UPDATE portal_login_lockouts SET window_started_at=UTC_TIMESTAMP(6)-INTERVAL 20 MINUTE WHERE email_hash=?', [hash('sha256', strtolower($ghost), true)]);

    // CORS: solo origenes autorizados por superadmin, exactos; nunca en /v1.
    $origin = 'https://portal-' . substr(Util::uuid(), 0, 8) . '.example.test';
    check(adminRequest($platform, 'POST', '/admin/portal-origins', ['origin' => 'https://*.example.test'])[0] === 422, 'wildcard origins rejected');
    check(adminRequest($platform, 'POST', '/admin/portal-origins', ['origin' => 'http://evil.example.test'])[0] === 422, 'plain http origins rejected');
    $created = expect(adminRequest($platform, 'POST', '/admin/portal-origins', ['origin' => strtoupper($origin)]), 201, 'add origin');
    check($created['origin'] === $origin, 'origin normalized');
    check(adminRequest($platform, 'POST', '/admin/portal-origins', ['origin' => $origin])[0] === 409, 'duplicate origin');
    check(in_array($origin, array_column(expect(adminRequest($platform, 'GET', '/admin/portal-origins'), 200, 'list origins')['data'], 'origin'), true), 'origin listed');
    check(adminRequest($all, 'GET', '/admin/portal-origins')[0] === 403, 'origins are platform-only');
    $allowed = portalRequest('GET', '/me', null, $p['access_token'], ['origin' => $origin]);
    check($allowed[0] === 200 && ($allowed[2]['access-control-allow-origin'] ?? '') === $origin && ($allowed[2]['vary'] ?? '') === 'Origin', 'authorized origin gets CORS');
    $denied = portalRequest('GET', '/me', null, $p['access_token'], ['origin' => 'https://otro.example.test']);
    check($denied[0] === 200 && !isset($denied[2]['access-control-allow-origin']), 'other origins get no CORS');
    check(portalRequest('GET', '/me', null, null, ['origin' => $origin])[2]['access-control-allow-origin'] === $origin, 'errors carry CORS for authorized origins');
    $pre = portalRequest('OPTIONS', '/users', null, null, ['origin' => $origin, 'access-control-request-method' => 'GET', 'access-control-request-headers' => 'authorization']);
    check($pre[0] === 204 && $pre[2]['access-control-allow-origin'] === $origin && str_contains($pre[2]['access-control-allow-methods'], 'POST') && str_contains($pre[2]['access-control-allow-headers'], 'Authorization'), 'preflight answered');
    check(!isset(portalRequest('OPTIONS', '/users', null, null, ['origin' => 'https://otro.example.test'])[2]['access-control-allow-origin']), 'preflight from other origins gets no CORS');
    check(!isset(request('GET', '/me/access', null, null, null, null, null, ['origin' => $origin])[2]['access-control-allow-origin']), 'never CORS on /v1');
    check(adminRequest($platform, 'DELETE', '/admin/portal-origins/' . $created['id'])[0] === 204, 'remove origin');
    check(!isset(portalRequest('GET', '/me', null, $p['access_token'], ['origin' => $origin])[2]['access-control-allow-origin']), 'removed origin loses CORS');
    echo 'PASS portal (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
