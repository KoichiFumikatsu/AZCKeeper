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

    // Otra empresa: el id no existe para ella.
    $foreign = adminFixture($db, adminMember($db, secondTenant($db)), ['equipos.ver', 'equipos.apagar', 'equipos.reiniciar']);
    expect(adminRequest($foreign, 'GET', "$path/" . $own['id']), 404, 'cross-tenant get');
    expect(adminRequest($foreign, 'PATCH', "$path/" . $own['id'], ['status' => 'paused'], ['if-match' => '"1"']), 404, 'cross-tenant patch');
    expect(adminRequest($foreign, 'DELETE', "$path/" . $own['id'], null, ['if-match' => '"1"']), 404, 'cross-tenant delete');
    echo 'PASS power schedules (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
