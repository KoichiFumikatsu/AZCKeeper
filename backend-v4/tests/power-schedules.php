<?php
declare(strict_types=1);
use Keeper\{Database, Util};

// Apagado programado: CRUD, validación, permisos por acción, alcance y auditoría.
function powerScheduleAdminTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    accessResetPreloginQuota();   // 4 logins de fixture seguidos
    $a = secondTenant($db);
    $tenant = $a['tenant_id']; $tenantId = Util::id($tenant);
    $both = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar', 'equipos.reiniciar']);
    $offOnly = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar']);
    $scopedMember = adminMember($db, $a);
    $scoped = adminFixture($db, $scopedMember, ['equipos.ver', 'equipos.apagar'], false, 'self');
    $base = ['name' => 'Viernes', 'recurrence' => 'weekly', 'days' => [5], 'time_local' => '19:00',
        'valid_from' => '2026-11-01', 'targets' => [['kind' => 'tenant', 'id' => $tenantId]]];
    $path = '/admin/power-schedules';

    $s = expect(adminRequest($both, 'POST', $path, $base), 201, 'create weekly shutdown');
    check($s['action'] === 'shutdown' && $s['idle_minutes'] === 15 && $s['notice_minutes'] === 10 && $s['postpone_minutes'] === 60 && $s['window_minutes'] === 180, 'defaults applied');
    check($s['days'] === [5] && $s['exclusions'] === [] && $s['status'] === 'active' && $s['version'] === 1, 'shape');

    expect(adminRequest($both, 'POST', $path, $base + ['extra' => 1]), 422, 'closed body');
    foreach (['name', 'recurrence', 'time_local', 'targets'] as $missing) {
        $partial = $base; unset($partial[$missing]);
        expect(adminRequest($both, 'POST', $path, $partial), 422, 'create needs ' . $missing);
    }
    $defaulted = expect(adminRequest($both, 'POST', $path, array_diff_key($base, ['valid_from' => 1])), 201, 'valid_from defaults to today');
    check(preg_match('/^\d{4}-\d{2}-\d{2}$/', $defaulted['valid_from']) === 1, 'valid_from default is a date');
    expect(adminRequest($both, 'DELETE', "$path/" . $defaulted['id'], null, ['if-match' => '"1"']), 204, 'delete defaulted');
    check(adminRequest($both, 'POST', $path, array_merge($base, ['days' => []]))[1]['detail'] === 'invalid_days', 'weekly needs days');
    check(adminRequest($both, 'POST', $path, array_merge($base, ['recurrence' => 'once']))[1]['detail'] === 'invalid_run_on', 'once needs run_on');
    check(adminRequest($both, 'POST', $path, array_merge($base, ['valid_until' => '2026-10-01']))[1]['detail'] === 'invalid_range', 'until before from');
    $once = expect(adminRequest($both, 'POST', $path, array_merge($base, ['recurrence' => 'once', 'days' => [], 'run_on' => '2026-11-05', 'name' => 'Una vez'])), 201, 'once schedule');
    check($once['run_on'] === '2026-11-05' && $once['days'] === [] && $once['valid_until'] === null, 'once shape');

    // Reiniciar exige equipos.reiniciar.
    expect(adminRequest($offOnly, 'POST', $path, $base + ['action' => 'restart']), 403, 'restart needs equipos.reiniciar');
    $off = expect(adminRequest($offOnly, 'POST', $path, $base), 201, 'shutdown with equipos.apagar');
    expect(adminRequest($offOnly, 'PATCH', "$path/" . $off['id'], ['action' => 'restart'], ['if-match' => '"' . $off['version'] . '"']), 403, 'patch to restart needs equipos.reiniciar');
    // Alcance: un admin 'self' no puede apuntar a toda la empresa.
    check(adminRequest($scoped, 'POST', $path, $base)[1]['detail'] === 'scope_denied', 'tenant target needs full scope');
    expect(adminRequest($scoped, 'POST', $path, array_merge($base, ['targets' => [['kind' => 'user', 'id' => Util::id($a['id'])]]])), 404, 'user outside scope hidden');
    $own = expect(adminRequest($scoped, 'POST', $path, array_merge($base, ['targets' => [['kind' => 'user', 'id' => Util::id($scopedMember['id'])]]])), 201, 'own user target allowed');
    $scopedList = expect(adminRequest($scoped, 'GET', $path), 200, 'scoped admin lists');
    check(array_column($scopedList['data'], 'id') === [$own['id']], 'scoped list only shows in-scope schedules');
    expect(adminRequest($scoped, 'GET', "$path/" . $s['id']), 403, 'scoped admin cannot read tenant-wide schedule');
    expect(adminRequest($scoped, 'PATCH', "$path/" . $s['id'], ['status' => 'paused'], ['if-match' => '"1"']), 403, 'scoped admin cannot patch tenant-wide schedule');
    expect(adminRequest($scoped, 'DELETE', "$path/" . $s['id'], null, ['if-match' => '"1"']), 403, 'scoped admin cannot delete tenant-wide schedule');
    expect(adminRequest($scoped, 'GET', "$path/" . $own['id']), 200, 'scoped admin reads own schedule');

    $p = expect(adminRequest($both, 'PATCH', "$path/" . $s['id'], ['status' => 'paused'], ['if-match' => '"' . $s['version'] . '"']), 200, 'pause');
    check($p['status'] === 'paused' && $p['version'] === $s['version'] + 1 && $p['targets'] === $s['targets'], 'version bumped, targets kept');
    expect(adminRequest($both, 'PATCH', "$path/" . $s['id'], ['status' => 'active'], ['if-match' => '"' . $s['version'] . '"']), 412, 'stale if-match');
    expect(adminRequest($both, 'PATCH', "$path/" . $s['id'], ['recurrence' => 'once'], ['if-match' => '"' . $p['version'] . '"']), 422, 'patch to once without run_on');
    expect(adminRequest($both, 'GET', "$path/" . $s['id']), 200, 'get');
    check(count(expect(adminRequest($both, 'GET', $path), 200, 'list')['data']) === 4, 'list all tenant schedules');

    // Ejecuciones: última fila por equipo, filtrada por alcance.
    $key = keypair(); $token = enroll($db, $a, $key); policy($db, $token);
    $device = Util::bin($token['device_id']);
    foreach ([$s['id'], $own['id']] as $sid) { foreach ([['2026-11-06 00:00:00', 'postponed', '2026-11-06 00:05:00'], ['2026-11-06 00:00:00', 'executed_idle', '2026-11-06 00:20:00']] as [$occ, $outcome, $rep]) {
        $db->run('INSERT INTO power_schedule_runs (tenant_id,id,schedule_id,device_id,occurrence_at,outcome,event_id,reported_at) VALUES (?,?,?,?,?,?,?,?)',
            [$tenant, Util::bin(Util::uuid()), Util::bin($sid), $device, $occ, $outcome, Util::bin(Util::uuid()), $rep]);
    } }
    $runs = expect(adminRequest($both, 'GET', "$path/" . $s['id'] . '/runs'), 200, 'runs');
    check(count($runs['data']) === 1 && $runs['data'][0]['outcome'] === 'executed_idle' && $runs['data'][0]['device_id'] === $token['device_id'], 'latest run per device');
    check(count(expect(adminRequest($both, 'GET', "$path/" . $own['id'] . '/runs'), 200, 'full admin runs of own')['data']) === 1, 'full admin sees device run');
    check(expect(adminRequest($scoped, 'GET', "$path/" . $own['id'] . '/runs'), 200, 'scoped runs')['data'] === [], 'runs outside scope hidden');
    expect(adminRequest($scoped, 'GET', "$path/" . $s['id'] . '/runs'), 403, 'scoped admin cannot read runs of tenant-wide schedule');
    expect(adminRequest($both, 'GET', "$path/" . Util::uuid() . '/runs'), 404, 'runs of unknown schedule');

    expect(adminRequest($both, 'DELETE', "$path/" . $s['id'], null, ['if-match' => '"1"']), 412, 'delete stale');
    expect(adminRequest($both, 'DELETE', "$path/" . $s['id'], null, ['if-match' => '"' . $p['version'] . '"']), 204, 'delete');
    expect(adminRequest($both, 'GET', "$path/" . $s['id']), 404, 'deleted is gone');
    check((int) $db->one('SELECT COUNT(*) n FROM power_schedule_runs WHERE tenant_id=? AND schedule_id=?', [$tenant, Util::bin($s['id'])])['n'] === 0, 'runs cascade');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND resource_type='power_schedule'", [$tenant])['n'] >= 5, 'audited');

    // --- Ronda de correcciones 1: alcance por sede/area, exclusiones, reemplazo de destinos, permisos y duplicados ---
    accessResetPreloginQuota();
    $siteId = Util::id($a['site_id']); $areaId = Util::id($a['area_id']);
    $outSite = Util::bin(Util::uuid()); $outArea = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','PS otra sede')", [$tenant, $outSite]);
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','PS otra area')", [$tenant, $outArea]);
    $outSiteId = Util::id($outSite); $outAreaId = Util::id($outArea);
    $siteAdmin = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar'], false, 'site', [$a['site_id']]);
    $otherSiteAdmin = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar'], false, 'site', [$outSite]);
    $areaAdmin = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar'], false, 'area', [$a['area_id']]);
    $restartOnly = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.reiniciar']);
    $t = fn (string $kind, string $id) => ['kind' => $kind, 'id' => $id];
    $with = fn (array $over) => array_merge($base, $over);

    $siteOk = adminRequest($siteAdmin, 'POST', $path, $with(['name' => 'Sede propia', 'targets' => [$t('site', $siteId)]]));
    check($siteOk[0] === 201 && ($siteOk[2]['etag'] ?? null) === '"' . $siteOk[1]['version'] . '"', 'site admin creates for own site; ETag matches version');
    check(adminRequest($siteAdmin, 'POST', $path, $with(['targets' => [$t('site', $outSiteId)]]))[1]['detail'] === 'scope_denied', 'site admin: other site denied');
    check(adminRequest($siteAdmin, 'POST', $path, $with(['targets' => [$t('site', $siteId)], 'exclusions' => [$t('site', $outSiteId)]]))[1]['detail'] === 'scope_denied', 'exclusion outside scope denied');
    expect(adminRequest($siteAdmin, 'POST', $path, $with(['targets' => [$t('site', $siteId)], 'exclusions' => [$t('user', Util::id($a['id']))]])), 201, 'exclusion inside scope allowed');
    $areaOk = expect(adminRequest($areaAdmin, 'POST', $path, $with(['name' => 'Area propia', 'targets' => [$t('area', $areaId)]])), 201, 'area admin own area');
    check(adminRequest($areaAdmin, 'POST', $path, $with(['targets' => [$t('area', $outAreaId)]]))[1]['detail'] === 'scope_denied', 'area admin: other area denied');

    $key2 = keypair(); $token2 = enroll($db, $a, $key2); policy($db, $token2);
    expect(adminRequest($siteAdmin, 'POST', $path, $with(['targets' => [$t('device', $token2['device_id'])]])), 201, 'device target in scope');
    expect(adminRequest($otherSiteAdmin, 'POST', $path, $with(['targets' => [$t('device', $token2['device_id'])]])), 404, 'device target out of scope hidden');

    // Listado filtrado: la programacion de la sede propia se ve; las de empresa, mixtas o con exclusion fuera, no.
    $mixed = expect(adminRequest($both, 'POST', $path, $with(['name' => 'Mixta', 'targets' => [$t('site', $siteId), $t('site', $outSiteId)]])), 201, 'mixed targets by full admin');
    $exOut = expect(adminRequest($both, 'POST', $path, $with(['name' => 'Excl fuera', 'targets' => [$t('site', $siteId)], 'exclusions' => [$t('site', $outSiteId)]])), 201, 'exclusion outside by full admin');
    $siteList = array_column(expect(adminRequest($siteAdmin, 'GET', $path), 200, 'site admin list')['data'], 'id');
    check(in_array($siteOk[1]['id'], $siteList, true) && !in_array($off['id'], $siteList, true) && !in_array($mixed['id'], $siteList, true) && !in_array($exOut['id'], $siteList, true), 'site admin list excludes out-of-scope schedules');
    check(!in_array($areaOk['id'], $siteList, true), 'site admin does not see area-scoped schedule');
    expect(adminRequest($siteAdmin, 'GET', "$path/" . $mixed['id']), 403, 'site admin cannot read mixed schedule');

    // PATCH reemplaza destinos y exclusiones.
    $rep = expect(adminRequest($both, 'PATCH', "$path/" . $mixed['id'], ['targets' => [$t('user', Util::id($a['id']))], 'exclusions' => [$t('site', $outSiteId)]], ['if-match' => '"' . $mixed['version'] . '"']), 200, 'replace targets');
    check($rep['targets'] === [$t('user', Util::id($a['id']))] && $rep['exclusions'] === [$t('site', $outSiteId)], 'old targets gone, new present');
    $patchRes = adminRequest($both, 'PATCH', "$path/" . $mixed['id'], ['status' => 'paused'], ['if-match' => '"' . $rep['version'] . '"']);
    check($patchRes[0] === 200 && $patchRes[2]['etag'] === '"' . $patchRes[1]['version'] . '"', 'PATCH ETag matches version');

    // Duplicados.
    foreach ([[[$t('site', $siteId), $t('site', $siteId)], []], [[$t('site', $siteId)], [$t('site', $siteId)]]] as [$tg, $ex]) {
        $dup = adminRequest($both, 'POST', $path, $with(['targets' => $tg, 'exclusions' => $ex]));
        check($dup[0] === 422 && $dup[1]['detail'] === 'duplicate_target', 'duplicate target rejected');
    }

    // Permisos por accion en delete/create.
    $rs = expect(adminRequest($both, 'POST', $path, $with(['name' => 'Reinicio', 'action' => 'restart'])), 201, 'restart schedule by full admin');
    expect(adminRequest($offOnly, 'DELETE', "$path/" . $rs['id'], null, ['if-match' => '"999"']), 403, 'delete restart without reiniciar: 403 before If-Match');
    expect(adminRequest($offOnly, 'DELETE', "$path/" . $rs['id'], null, ['if-match' => '"' . $rs['version'] . '"']), 403, 'delete restart without reiniciar');
    expect(adminRequest($restartOnly, 'POST', $path, $with(['action' => 'restart'])), 403, 'reiniciar alone cannot create (contract needs apagar)');
    expect(adminRequest($restartOnly, 'POST', $path, $base), 403, 'reiniciar alone cannot create shutdown');

    // 'once' guarda valid_from = run_on.
    $o2 = expect(adminRequest($both, 'POST', $path, $with(['recurrence' => 'once', 'days' => [], 'run_on' => '2026-12-24', 'valid_from' => '2026-11-01', 'name' => 'Una'])), 201, 'once consistent');
    check($o2['valid_from'] === '2026-12-24', 'once stores valid_from = run_on');

    // Nombre vacio tras recortar espacios: rechazado.
    expect(adminRequest($both, 'POST', $path, $with(['name' => " \t  "])), 422, 'blank name rejected');

    // Pausar/reanudar (solo status) no revalida destinos como escritura: una sede desactivada despues no bloquea.
    $closing = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','PS sede que cierra')", [$tenant, $closing]);
    $cs = expect(adminRequest($both, 'POST', $path, $with(['name' => 'Sede que cierra', 'targets' => [$t('site', Util::id($closing))]])), 201, 'schedule on closing site');
    $db->run('UPDATE org_units SET active=0 WHERE tenant_id=? AND id=?', [$tenant, $closing]);
    $csPaused = expect(adminRequest($both, 'PATCH', "$path/" . $cs['id'], ['status' => 'paused'], ['if-match' => '"' . $cs['version'] . '"']), 200, 'pause with deactivated site');
    check($csPaused['status'] === 'paused' && $csPaused['targets'] === $cs['targets'], 'pause keeps targets');
    $csActive = expect(adminRequest($both, 'PATCH', "$path/" . $cs['id'], ['status' => 'active'], ['if-match' => '"' . $csPaused['version'] . '"']), 200, 'resume with deactivated site');
    expect(adminRequest($both, 'PATCH', "$path/" . $cs['id'], ['name' => 'Otro nombre'], ['if-match' => '"' . $csActive['version'] . '"']), 422, 'other edits still need active site');
    expect(adminRequest($siteAdmin, 'PATCH', "$path/" . $mixed['id'], ['status' => 'active'], ['if-match' => '"' . $patchRes[1]['version'] . '"']), 403, 'status-only patch keeps scope check');

    // La funcion 'comandos' (x-permission equipos.apagar) se exige al crear, editar y borrar; ver, listar y ejecuciones no.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='comandos'", [$tenant]);
    $nc = adminRequest($both, 'POST', $path, $with(['name' => 'Sin comandos']));
    check($nc[0] === 403 && $nc[1]['detail'] === 'feature_not_contracted:comandos', 'create needs the comandos function');
    $nc = adminRequest($both, 'PATCH', "$path/" . $o2['id'], ['status' => 'paused'], ['if-match' => '"' . $o2['version'] . '"']);
    check($nc[0] === 403 && $nc[1]['detail'] === 'feature_not_contracted:comandos', 'patch needs the comandos function');
    $nc = adminRequest($both, 'DELETE', "$path/" . $o2['id'], null, ['if-match' => '"' . $o2['version'] . '"']);
    check($nc[0] === 403 && $nc[1]['detail'] === 'feature_not_contracted:comandos', 'delete needs the comandos function');
    expect(adminRequest($both, 'GET', $path), 200, 'list without comandos');
    expect(adminRequest($both, 'GET', "$path/" . $o2['id']), 200, 'get without comandos');
    expect(adminRequest($both, 'GET', "$path/" . $o2['id'] . '/runs'), 200, 'runs without comandos');
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'comandos')", [$tenant]);

    // Otra empresa: el id no existe para ella.
    $foreign = adminFixture($db, adminMember($db, secondTenant($db)), ['equipos.ver', 'equipos.apagar', 'equipos.reiniciar']);
    expect(adminRequest($foreign, 'GET', "$path/" . $own['id']), 404, 'cross-tenant get');
    expect(adminRequest($foreign, 'PATCH', "$path/" . $own['id'], ['status' => 'paused'], ['if-match' => '"1"']), 404, 'cross-tenant patch');
    expect(adminRequest($foreign, 'DELETE', "$path/" . $own['id'], null, ['if-match' => '"1"']), 404, 'cross-tenant delete');
    echo 'PASS power schedules (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Apagado programado: resolucion por equipo en /client/sync, version minima del agente y resultados idempotentes.
function powerScheduleSyncTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    accessResetPreloginQuota();
    $a = secondTenant($db); $tenant = $a['tenant_id']; $tenantId = Util::id($tenant);
    $admin = adminFixture($db, adminMember($db, $a), ['equipos.ver', 'equipos.apagar', 'equipos.reiniciar']);
    $key = keypair(); $token = enroll($db, $a, $key); policy($db, $token);
    $device = $token['device_id']; $deviceId = Util::bin($device);
    $seq = 0;
    $sync = static function (array $identity, array $session, array $extra = []) use (&$seq) {
        return expect(request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => ++$seq, 'policy_version' => 1, 'release_id' => null] + $extra,
            $identity, $session['access_token'], Util::uuid()), 200, 'power schedule sync');
    };
    $setVersion = fn (string $v) => $db->run('UPDATE devices SET agent_version=? WHERE tenant_id=? AND id=?', [$v, $tenant, $deviceId]);
    $ids = fn (array $r) => array_column($r['power_schedules'] ?? [], 'id');
    $path = '/admin/power-schedules';
    $base = ['name' => 'Todos', 'recurrence' => 'weekly', 'days' => [1, 5], 'time_local' => '19:00', 'valid_from' => '2026-01-01',
        'targets' => [['kind' => 'tenant', 'id' => $tenantId]], 'message' => 'Hasta el lunes'];
    $s = expect(adminRequest($admin, 'POST', $path, $base), 201, 'create');

    $setVersion('4.0.29');
    $old = $sync($key, $token);
    check(!array_key_exists('power_schedules', $old), 'agent 4.0.29 never receives power_schedules');
    $oldRun = ['event_id' => Util::uuid(), 'schedule_id' => $s['id'], 'occurrence_at' => '2026-10-30T00:00:00.000000Z', 'outcome' => 'executed_idle'];
    $old = $sync($key, $token, ['power_schedule_runs' => [$oldRun]]);
    check(!array_key_exists('power_schedule_acks', $old) && !array_key_exists('power_schedules', $old), 'agent 4.0.29 gets no acks');
    check((int) $db->one('SELECT COUNT(*) n FROM power_schedule_runs WHERE tenant_id=?', [$tenant])['n'] === 0, 'old agent runs not ingested');

    $setVersion('4.0.30');
    $got = $sync($key, $token)['power_schedules'];
    check(count($got) === 1 && $got[0]['id'] === $s['id'] && $got[0]['version'] === 1 && $got[0]['days'] === [1, 5] && $got[0]['timezone'] === 'America/Bogota'
        && $got[0]['message'] === 'Hasta el lunes' && $got[0]['action'] === 'shutdown' && $got[0]['time_local'] === '19:00' && $got[0]['valid_from'] === '2026-01-01', 'resolved directive');
    check($got[0]['idle_minutes'] === 15 && $got[0]['notice_minutes'] === 10 && $got[0]['postpone_minutes'] === 60 && $got[0]['window_minutes'] === 180, 'defaults in directive');
    check(!array_key_exists('targets', $got[0]) && !array_key_exists('exclusions', $got[0]) && !array_key_exists('name', $got[0]) && !array_key_exists('run_on', $got[0]) && !array_key_exists('valid_until', $got[0]), 'no target lists or absent optional keys');
    check(is_string($got[0]['updated_at'] ?? null) && $got[0]['updated_at'] === $s['updated_at'] && str_ends_with($got[0]['updated_at'], 'Z'), 'directive carries updated_at (UTC)');

    // Excepcion por equipo gana sobre la empresa.
    expect(adminRequest($admin, 'PATCH', "$path/" . $s['id'], ['exclusions' => [['kind' => 'device', 'id' => $device]]], ['if-match' => '"1"']), 200, 'exclude device');
    check($sync($key, $token)['power_schedules'] === [], 'excluded device gets nothing');
    expect(adminRequest($admin, 'PATCH', "$path/" . $s['id'], ['exclusions' => []], ['if-match' => '"2"']), 200, 'include again');
    check($ids($sync($key, $token)) === [$s['id']], 'included again');

    // Pausada: no se envia.
    expect(adminRequest($admin, 'PATCH', "$path/" . $s['id'], ['status' => 'paused'], ['if-match' => '"3"']), 200, 'pause');
    check($sync($key, $token)['power_schedules'] === [], 'paused schedule not sent');
    expect(adminRequest($admin, 'PATCH', "$path/" . $s['id'], ['status' => 'active'], ['if-match' => '"4"']), 200, 'resume');

    // Sin la funcion 'comandos' el equipo recibe la lista vacia: el agente cancela lo pendiente y deja de ejecutar.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='comandos'", [$tenant]);
    check($sync($key, $token)['power_schedules'] === [], 'tenant without comandos sends empty list');
    $db->run("INSERT INTO tenant_features (tenant_id,feature_code) VALUES (?,'comandos')", [$tenant]);
    check($ids($sync($key, $token)) === [$s['id']], 'comandos back: list sent again');

    // Vencida: no se envia; una sola vez de hoy si, de hace 10 dias no.
    $expired = expect(adminRequest($admin, 'POST', $path, array_merge($base, ['name' => 'Vencida', 'valid_from' => '2025-01-01', 'valid_until' => '2025-02-01'])), 201, 'expired');
    $today = new \DateTimeImmutable('now', new \DateTimeZone('America/Bogota'));
    $onceToday = expect(adminRequest($admin, 'POST', $path, array_merge($base, ['name' => 'Hoy', 'recurrence' => 'once', 'days' => [], 'run_on' => $today->format('Y-m-d')])), 201, 'once today');
    $oncePast = expect(adminRequest($admin, 'POST', $path, array_merge($base, ['name' => 'Pasada', 'recurrence' => 'once', 'days' => [], 'run_on' => $today->modify('-10 days')->format('Y-m-d')])), 201, 'once past');
    $r = $sync($key, $token);
    check(in_array($onceToday['id'], $ids($r), true) && !in_array($expired['id'], $ids($r), true) && !in_array($oncePast['id'], $ids($r), true), 'expired and past schedules not sent; today once sent');
    $once = array_column($r['power_schedules'], null, 'id')[$onceToday['id']];
    check($once['run_on'] === $today->format('Y-m-d') && $once['recurrence'] === 'once', 'once directive carries run_on');
    foreach ([$expired, $onceToday, $oncePast] as $x) { expect(adminRequest($admin, 'DELETE', "$path/" . $x['id'], null, ['if-match' => '"1"']), 204, 'cleanup'); }

    // Mensaje vacio: texto por defecto segun la accion.
    $e1 = expect(adminRequest($admin, 'POST', $path, array_merge($base, ['name' => 'Sin msg', 'message' => ''])), 201, 'empty message');
    $e2 = expect(adminRequest($admin, 'POST', $path, array_merge($base, ['name' => 'Reinicio', 'message' => '', 'action' => 'restart'])), 201, 'empty restart message');
    $by = array_column($sync($key, $token)['power_schedules'], null, 'id');
    check($by[$e1['id']]['message'] === 'TI programó el apagado de este equipo.' && $by[$e2['id']]['message'] === 'TI programó el reinicio de este equipo.' && $by[$e2['id']]['action'] === 'restart', 'default message text');
    foreach ([$e1, $e2] as $x) { expect(adminRequest($admin, 'DELETE', "$path/" . $x['id'], null, ['if-match' => '"1"']), 204, 'cleanup'); }

    // Alcance por sede: el equipo cuya persona esta en la sede lo recibe; el de otra sede no.
    $outSite = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','PS sync otra sede')", [$tenant, $outSite]);
    $member = adminMember($db, $a);
    $db->run('UPDATE users SET site_id=? WHERE tenant_id=? AND id=?', [$outSite, $tenant, $member['id']]);
    $key2 = keypair(); $token2 = enroll($db, $member, $key2); policy($db, $token2);
    $db->run("UPDATE devices SET agent_version='4.0.30' WHERE tenant_id=? AND id=?", [$tenant, Util::bin($token2['device_id'])]);
    $siteS = expect(adminRequest($admin, 'POST', $path, array_merge($base, ['name' => 'Sede', 'targets' => [['kind' => 'site', 'id' => Util::id($a['site_id'])]]])), 201, 'site schedule');
    check(in_array($siteS['id'], $ids($sync($key, $token)), true), 'site member device receives site schedule');
    $other = $ids($sync($key2, $token2));
    check(!in_array($siteS['id'], $other, true) && in_array($s['id'], $other, true), 'device of other site misses site schedule, gets tenant one');
    expect(adminRequest($admin, 'DELETE', "$path/" . $siteS['id'], null, ['if-match' => '"1"']), 204, 'cleanup site');

    // Resultados idempotentes por event_id y por (programacion, equipo, ocurrencia, resultado).
    $ev = Util::uuid();
    $run = ['event_id' => $ev, 'schedule_id' => $s['id'], 'occurrence_at' => '2026-10-30T00:00:00.000000Z', 'outcome' => 'executed_idle'];
    $r1 = $sync($key, $token, ['power_schedule_runs' => [$run]]);
    check($r1['power_schedule_acks'] === [$ev], 'run acked');
    $r2 = $sync($key, $token, ['power_schedule_runs' => [$run]]);
    check($r2['power_schedule_acks'] === [$ev], 'duplicate acked');
    $ev2 = Util::uuid();
    $r3 = $sync($key, $token, ['power_schedule_runs' => [['event_id' => $ev2] + $run]]);
    check($r3['power_schedule_acks'] === [$ev2], 'same outcome with another event_id acked, no 500');
    check((int) $db->one('SELECT COUNT(*) n FROM power_schedule_runs WHERE tenant_id=? AND schedule_id=?', [$tenant, Util::bin($s['id'])])['n'] === 1, 'no duplicate row');
    $runs = expect(adminRequest($admin, 'GET', "$path/" . $s['id'] . '/runs'), 200, 'runs');
    check(count($runs['data']) === 1 && $runs['data'][0]['outcome'] === 'executed_idle' && $runs['data'][0]['device_id'] === $device
        && $runs['data'][0]['occurrence_at'] === '2026-10-30T00:00:00.000000Z', 'run visible in panel');
    $ev3 = Util::uuid();
    $r4 = $sync($key, $token, ['power_schedule_runs' => [['event_id' => $ev3, 'outcome' => 'postponed', 'detail' => 'persona'] + $run]]);
    check($r4['power_schedule_acks'] === [$ev3] && (int) $db->one('SELECT COUNT(*) n FROM power_schedule_runs WHERE tenant_id=? AND schedule_id=?', [$tenant, Util::bin($s['id'])])['n'] === 2, 'other outcome stored');

    // Equipos alcanzados con agente < 4.0.30 y sin resultados: fila sintetica unsupported/agent_too_old (occurrence_at =
    // updated_at de la programacion). Comparacion numerica: 4.0.9 < 4.0.30.
    $device2 = $token2['device_id'];
    $setVersion2 = fn (string $v) => $db->run('UPDATE devices SET agent_version=? WHERE tenant_id=? AND id=?', [$v, $tenant, Util::bin($device2)]);
    $runsBy = fn () => array_column(expect(adminRequest($admin, 'GET', "$path/" . $s['id'] . '/runs'), 200, 'runs by device')['data'], null, 'device_id');
    $setVersion2('4.0.9'); $setVersion('4.0.9');
    $cur = expect(adminRequest($admin, 'GET', "$path/" . $s['id']), 200, 'current schedule');
    $by = $runsBy();
    $syn = $by[$device2] ?? null;
    check($syn !== null && $syn['outcome'] === 'unsupported' && $syn['detail'] === 'agent_too_old' && $syn['occurrence_at'] === $cur['updated_at']
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $syn['reported_at']) === 1 && is_string($syn['hostname']), 'old agent device without runs shows agent_too_old');
    check(count($by) === 2 && $by[$device]['outcome'] !== 'unsupported', 'device with real runs keeps its real row');
    expect(adminRequest($admin, 'PATCH', "$path/" . $s['id'], ['exclusions' => [['kind' => 'device', 'id' => $device2]]], ['if-match' => '"' . $cur['version'] . '"']), 200, 'exclude old device');
    check(!isset($runsBy()[$device2]), 'excluded old device not listed');
    expect(adminRequest($admin, 'PATCH', "$path/" . $s['id'], ['exclusions' => []], ['if-match' => '"' . ($cur['version'] + 1) . '"']), 200, 'include old device again');
    $setVersion2('4.0.30');
    check(!isset($runsBy()[$device2]), 'up-to-date device without runs not listed');
    $setVersion('4.0.30');

    // Programacion ajena o inexistente: se confirma sin guardar.
    $b = secondTenant($db); $foreignAdmin = adminFixture($db, adminMember($db, $b), ['equipos.ver', 'equipos.apagar']);
    $foreign = expect(adminRequest($foreignAdmin, 'POST', $path, array_merge($base, ['targets' => [['kind' => 'tenant', 'id' => Util::id($b['tenant_id'])]]])), 201, 'foreign schedule');
    $evF = Util::uuid(); $evG = Util::uuid();
    $r5 = $sync($key, $token, ['power_schedule_runs' => [['event_id' => $evF, 'schedule_id' => $foreign['id']] + $run, ['event_id' => $evG, 'schedule_id' => Util::uuid()] + $run]]);
    check($r5['power_schedule_acks'] === [$evF, $evG], 'foreign and unknown schedules acked');
    check((int) $db->one('SELECT COUNT(*) n FROM power_schedule_runs WHERE event_id IN (?,?)', [Util::bin($evF), Util::bin($evG)])['n'] === 0, 'foreign run not stored');
    check(!array_key_exists('power_schedule_acks', $sync($key, $token)), 'no acks key without runs');
    echo 'PASS power schedule sync (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
