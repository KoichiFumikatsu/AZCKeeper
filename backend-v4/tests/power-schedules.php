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
    expect(adminRequest($scoped, 'GET', $path), 403, 'scoped admin cannot list tenant config');
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
    echo 'PASS power schedules (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
