<?php
declare(strict_types=1);
use Keeper\{ActivityCalendar,AdminAuth,Database,HolidaySeeds,Util};

// Grupo con empresas internas, sedes por pais y calendarios de festivos por persona (2026-10-01).
function groupStructureTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    for ($w = 1; $w <= 5; $w++) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $u['schedule_id'], $w]); }
    $expected = function (string $day, string $user) use ($db, $tenant): int {
        ActivityCalendar::create($db, $tenant, $day, (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'));
        $e = ActivityCalendar::expected('a');
        $v = (int) $db->one("SELECT $e x FROM user_assignments a JOIN activity_calendar c ON c.schedule_id=a.schedule_id AND c.day=? WHERE a.tenant_id=? AND a.user_id=? AND a.ends_at IS NULL", [$day, $tenant, $user])['x'];
        $db->run('DROP TEMPORARY TABLE IF EXISTS activity_calendar');
        return $v;
    };

    // Festivos calculados: Pascua y ley Emiliani (Colombia), observados (EE. UU.).
    check(HolidaySeeds::easter(2026)->format('Y-m-d') === '2026-04-05' && HolidaySeeds::easter(2027)->format('Y-m-d') === '2027-03-28', 'easter computed');
    $co = HolidaySeeds::year('CO', 2026);
    check(count($co) === 18 && ($co['2026-01-12'] ?? '') === 'Día de los Reyes Magos' && isset($co['2026-05-18'], $co['2026-06-08'], $co['2026-06-15'], $co['2026-11-16']), 'colombian holidays moved to monday');
    $us = HolidaySeeds::year('US', 2026);
    check(isset($us['2026-07-03'], $us['2026-11-26'], $us['2026-11-27'], $us['2026-01-19']) && !isset($us['2026-07-04']), 'us holidays observed');
    check(isset(HolidaySeeds::year('HN', 2026)['2026-09-15']), 'honduras independence');

    // Empresas del grupo y sedes con pais.
    $azc = expect(adminRequest($platform, 'POST', '/organization', ['kind' => 'company', 'name' => 'AZC Legal', 'parent_id' => null, 'active' => true]), 201, 'create group company');
    $bil = expect(adminRequest($platform, 'POST', '/organization', ['kind' => 'company', 'name' => 'BilingueLaw', 'parent_id' => null, 'active' => true]), 201, 'second group company');
    check($azc['kind'] === 'company' && $azc['country_code'] === null, 'company has no country');
    check(adminRequest($platform, 'POST', '/organization', ['kind' => 'area', 'name' => 'Mala', 'parent_id' => null, 'active' => true, 'country_code' => 'CO'])[1]['detail'] === 'country_only_for_sites', 'country only on sites');
    $hn = expect(adminRequest($platform, 'POST', '/organization', ['kind' => 'site', 'name' => 'Remoto – Honduras', 'parent_id' => null, 'active' => true, 'country_code' => 'HN']), 201, 'remote site per country');
    $site = $db->one('SELECT * FROM org_units WHERE tenant_id=? AND id=?', [$tenant, $u['site_id']]);
    $co_site = expect(adminRequest($platform, 'PATCH', '/organization/' . Util::id($u['site_id']), ['kind' => 'site', 'name' => 'Tequendama', 'parent_id' => null, 'active' => true, 'country_code' => 'CO'], ['if-match' => '"' . $site['version'] . '"']), 200, 'set site country');
    $renamed = expect(adminRequest($platform, 'PATCH', '/organization/' . Util::id($u['site_id']), ['kind' => 'site', 'name' => 'Sede Tequendama', 'parent_id' => null, 'active' => true], ['if-match' => '"' . $co_site['version'] . '"']), 200, 'rename site keeps country');
    check($renamed['country_code'] === 'CO', 'country kept when not sent');

    // Personas: una de AZC Legal en Bogota y una de BilingueLaw en Honduras (comparten firma, area y cargo).
    $me = expect(adminRequest($platform, 'PATCH', '/users/' . $uid, ['company_id' => $azc['id']], ['if-match' => '"' . $u['version'] . '"']), 200, 'assign group company');
    check($me['company_id'] === $azc['id'] && $me['holiday_calendar_id'] === null, 'person shows company');
    check($db->one('SELECT company_id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL', [$tenant, $u['id']])['company_id'] === Util::bin($azc['id']), 'assignment history keeps company');
    $other = expect(adminRequest($platform, 'POST', '/users', ['display_name' => 'Persona Honduras', 'email' => null, 'firm_id' => Util::id($u['firm_id']), 'site_id' => $hn['id'], 'area_id' => Util::id($u['area_id']),
        'position_id' => Util::id($u['position_id']), 'schedule_id' => Util::id($u['schedule_id']), 'company_id' => $bil['id']]), 201, 'create person in honduras');
    $bad = adminRequest($platform, 'PATCH', '/users/' . $uid, ['company_id' => Util::id($u['area_id'])], ['if-match' => '"' . $me['version'] . '"']);
    check($bad[0] === 422, 'company must be a group company');

    // Filtros: empresa y pais.
    $ids = fn ($path) => array_column(expect(adminRequest($platform, 'GET', $path), 200, "list $path")['data'], 'id');
    check($ids('/users?company_id=' . $bil['id']) === [$other['id']], 'filter people by company');
    check($ids('/users?country=CO') === [$uid] && $ids('/users?country=HN') === [$other['id']], 'filter people by country');
    check(count($ids('/users')) === 2, 'no filter = whole group');
    check(adminRequest($platform, 'GET', '/reports/people?from=2026-09-01&to=2026-09-08&company_id=' . $azc['id'])[0] === 200, 'reports accept company filter');
    check(adminRequest($platform, 'GET', '/reports/productivity?from=2026-09-01&to=2026-09-08&country=HN')[0] === 200, 'reports accept country filter');
    check(adminRequest($platform, 'GET', '/reports/people?from=2026-09-01&to=2026-09-08&company_id=' . Util::id($u['area_id']))[0] === 404, 'unknown company is 404');
    check(adminRequest($platform, 'GET', '/reports/productivity?from=2026-09-01&to=2026-09-08&company_id=' . Util::id($u['area_id']))[0] === 422, 'productivity validates the company');

    // Calendarios: Colombia (por defecto) y Honduras. Cada persona usa el de su sede; uno asignado a mano manda.
    $cal = fn ($name, $country, $default) => expect(adminRequest($platform, 'POST', '/schedules/holiday-calendars', ['name' => $name, 'country_code' => $country, 'is_default' => $default, 'active' => true]), 201, "calendar $name");
    $colombia = $cal('Colombia', 'CO', true); $honduras = $cal('Honduras', 'HN', false);
    check(adminRequest($platform, 'POST', '/schedules/holiday-calendars', ['name' => 'Colombia', 'country_code' => 'CO', 'is_default' => false, 'active' => true])[0] === 409, 'calendar names unique');
    expect(adminRequest($platform, 'POST', '/schedules/holidays', ['day' => '2026-10-12', 'name' => 'Día de la Raza', 'calendar_id' => $colombia['id']]), 201, 'colombian holiday');
    expect(adminRequest($platform, 'POST', '/schedules/holidays', ['day' => '2026-10-14', 'name' => 'Semana Morazánica', 'calendar_id' => $honduras['id']]), 201, 'honduran holiday');
    $hid = Util::bin($other['id']);
    check($expected('2026-10-12', $u['id']) === 0 && $expected('2026-10-12', $hid) === 9 * 3600, 'colombian holiday only for the colombian site');
    check($expected('2026-10-14', $u['id']) === 9 * 3600 && $expected('2026-10-14', $hid) === 0, 'honduran holiday only for the honduran site');
    // Asignacion en grupo: la persona de Bogota pasa al calendario de Honduras (p. ej. trabaja para esa oficina).
    $after = expect(adminRequest($platform, 'POST', '/schedules/holiday-calendars/' . $honduras['id'] . '/people', ['user_ids' => [$uid]]), 200, 'assign calendar to people');
    check($after['people'] === 1 && $after['holidays'] === 1, 'calendar counts');
    check($expected('2026-10-12', $u['id']) === 9 * 3600 && $expected('2026-10-14', $u['id']) === 0, 'assigned calendar wins over site country');
    $me = expect(adminRequest($platform, 'GET', '/users/' . $uid), 200, 'read person');
    check($me['holiday_calendar_id'] === $honduras['id'], 'person shows own calendar');
    expect(adminRequest($platform, 'PATCH', '/users/' . $uid, ['holiday_calendar_id' => null], ['if-match' => '"' . $me['version'] . '"']), 200, 'back to automatic');
    check($expected('2026-10-12', $u['id']) === 0, 'automatic again uses site country');
    // Sede sin pais: calendario por defecto. Calendario inactivo: no aplica.
    $nocountry = expect(adminRequest($platform, 'POST', '/organization', ['kind' => 'site', 'name' => 'Sin país', 'parent_id' => null, 'active' => true]), 201, 'site without country');
    $third = expect(adminRequest($platform, 'POST', '/users', ['display_name' => 'Persona sin país', 'email' => null, 'firm_id' => Util::id($u['firm_id']), 'site_id' => $nocountry['id'], 'area_id' => Util::id($u['area_id']),
        'position_id' => Util::id($u['position_id']), 'schedule_id' => Util::id($u['schedule_id'])]), 201, 'person without country');
    check($expected('2026-10-12', Util::bin($third['id'])) === 0, 'default calendar for sites without country');
    check(adminRequest($platform, 'PUT', '/schedules/holiday-calendars/' . $colombia['id'], ['name' => 'Colombia', 'country_code' => 'CO', 'is_default' => true, 'active' => false])[1]['detail'] === 'default_calendar_inactive', 'default must stay active');
    expect(adminRequest($platform, 'PUT', '/schedules/holiday-calendars/' . $honduras['id'], ['name' => 'Honduras', 'country_code' => 'HN', 'is_default' => false, 'active' => false]), 200, 'deactivate calendar');
    check($expected('2026-10-14', $hid) === 9 * 3600, 'inactive calendar does not apply');
    $list = expect(adminRequest($platform, 'GET', '/schedules/holiday-calendars'), 200, 'list calendars')['data'];
    check(count($list) === 2 && $list[0]['name'] === 'Colombia' && $list[0]['is_default'], 'default calendar listed first');

    // Carga inicial (herramienta): crea los calendarios que faltan y no duplica festivos.
    $added = HolidaySeeds::load($db, $tenant, ['CO', 'US'], [2026]);
    check($added['CO'] === 17 && $added['US'] === 12, 'seed adds the missing holidays only');
    check(HolidaySeeds::load($db, $tenant, ['CO', 'US'], [2026]) === ['CO' => 0, 'US' => 0], 'seed is repeatable');
    // No se desactiva una empresa del grupo con personas.
    $c = $db->one('SELECT version FROM org_units WHERE tenant_id=? AND id=?', [$tenant, Util::bin($bil['id'])]);
    check(adminRequest($platform, 'PATCH', '/organization/' . $bil['id'], ['kind' => 'company', 'name' => 'BilingueLaw', 'parent_id' => null, 'active' => false], ['if-match' => '"' . $c['version'] . '"'])[1]['code'] === 'org_unit_in_use', 'company in use cannot be deactivated');
    // Personas sin firma ni horario (directivos): "Sin firma" y "Sin horario" (sin dias: jornada esperada cero).
    $boss = expect(adminRequest($platform, 'POST', '/users', ['display_name' => 'Presidente', 'email' => null, 'site_id' => Util::id($u['site_id']), 'area_id' => Util::id($u['area_id']), 'position_id' => Util::id($u['position_id'])]), 201, 'person without firm or schedule');
    $boss2 = expect(adminRequest($platform, 'POST', '/users', ['display_name' => 'Socio', 'email' => null, 'firm_id' => null, 'schedule_id' => null, 'site_id' => Util::id($u['site_id']), 'area_id' => Util::id($u['area_id']), 'position_id' => Util::id($u['position_id'])]), 201, 'explicit nulls');
    check($boss['firm_id'] === $boss2['firm_id'] && $boss['schedule_id'] === $boss2['schedule_id'], 'placeholders reused');
    check($db->one('SELECT name FROM org_units WHERE tenant_id=? AND id=?', [$tenant, Util::bin($boss['firm_id'])])['name'] === 'Sin firma', 'firm placeholder');
    check($db->one('SELECT name FROM schedules WHERE tenant_id=? AND id=?', [$tenant, Util::bin($boss['schedule_id'])])['name'] === 'Sin horario', 'schedule placeholder');
    check($expected('2026-10-05', Util::bin($boss['id'])) === 0, 'no schedule means no expected day');
    $edited = expect(adminRequest($platform, 'PATCH', '/users/' . $boss['id'], ['schedule_id' => Util::id($u['schedule_id'])], ['if-match' => '"' . $boss['version'] . '"']), 200, 'assign schedule later');
    check($edited['schedule_id'] === Util::id($u['schedule_id']) && $edited['firm_id'] === $boss['firm_id'], 'edit later keeps the rest');
    echo 'PASS group structure (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Empresas archivadas: ocultas, de solo lectura y solo superadmin las archiva o desarchiva (2026-10-01).
function tenantArchiveTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $tid = Util::id($tenant);
    $platform = sharedPlatform($db); $mine = $platform; $mine['tenant'] = $tenant;
    $row = fn () => expect(adminRequest($mine, 'GET', '/tenants/' . $tid), 200, 'read tenant');
    $t = $row();
    check($t['archived'] === false, 'tenant not archived');
    check(adminRequest($mine, 'PATCH', '/tenants/' . $tid, ['archived' => true, 'name' => 'x'], ['if-match' => '"' . $t['version'] . '"'])[1]['detail'] === 'archive_alone', 'archive is its own change');
    $archived = expect(adminRequest($mine, 'PATCH', '/tenants/' . $tid, ['archived' => true], ['if-match' => '"' . $t['version'] . '"']), 200, 'archive tenant');
    check($archived['archived'] === true && $archived['status'] === 'suspended', 'archived tenant is suspended');
    $ids = fn ($q) => array_column(expect(adminRequest($platform, 'GET', '/tenants' . $q), 200, 'list tenants')['data'], 'id');
    check(!in_array($tid, $ids(''), true) && in_array($tid, $ids('?include_archived=true'), true), 'archived hidden unless asked');
    check(adminRequest($mine, 'GET', '/users')[0] === 200, 'archived stays readable for superadmin');
    $w = adminRequest($mine, 'POST', '/organization', ['kind' => 'site', 'name' => 'Nueva', 'parent_id' => null, 'active' => true]);
    check($w[0] === 409 && $w[1]['detail'] === 'tenant_archived', 'archived tenant is read only');
    check(adminRequest($mine, 'PATCH', '/tenants/' . $tid, ['name' => 'Otro'], ['if-match' => '"' . $archived['version'] . '"'])[1]['detail'] === 'tenant_archived', 'archived tenant cannot be renamed');
    check(adminRequest($platform, 'PATCH', '/tenants/' . AdminAuth::PLATFORM, ['archived' => true], ['if-match' => '"' . $row()['version'] . '"'])[0] !== 200, 'platform cannot be archived');
    $back = expect(adminRequest($mine, 'PATCH', '/tenants/' . $tid, ['archived' => false], ['if-match' => '"' . $archived['version'] . '"']), 200, 'unarchive');
    check($back['archived'] === false && $back['status'] === 'suspended', 'unarchived stays suspended until activated');
    check(adminRequest($mine, 'POST', '/organization', ['kind' => 'site', 'name' => 'Nueva', 'parent_id' => null, 'active' => true])[0] === 201, 'writable again');
    echo 'PASS tenant archive (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Menu segun permisos: /me/access devuelve permisos y alcances; cada item del menu tiene su regla (2026-10-01).
function myAccessTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db);
    $admin = adminFixture($db, $u, ['usuarios.ver', 'festivos.ver'], false, 'area', [$u['area_id']]);
    $mine = expect(adminRequest($admin, 'GET', '/me/access'), 200, 'my access');
    $codes = array_column($mine['permissions'], 'scopes', 'code');
    check($mine['platform'] === false && isset($codes['usuarios.ver'], $codes['festivos.ver']) && !isset($codes['reglas.ver']), 'only granted permissions');
    check($codes['festivos.ver'] === ['area'], 'scope reported (festivos needs whole company, so the menu hides it)');
    $platform = sharedPlatform($db); $platform['tenant'] = $u['tenant_id'];
    check(expect(adminRequest($platform, 'GET', '/me/access'), 200, 'platform access')['platform'] === true, 'platform sees everything');
    // Guardia: todo item de empresa del menu tiene regla y apunta a un permiso que existe.
    $js = file_get_contents(dirname(__DIR__) . '/public/assets/nav-access.js');
    preg_match_all("/'?([a-z-]+)'?: \['([a-z_.]+)', (true|false)\]/", $js, $m, PREG_SET_ORDER);
    $rules = []; foreach ($m as $x) { $rules[$x[1]] = $x[2]; }
    foreach ($rules as $key => $code) { check((bool) $db->one('SELECT code FROM permissions WHERE code=?', [$code]), "menu $key uses existing permission $code"); }
    preg_match_all("/\['([a-z-]+)', '[^']+', '\/[^']*'/", file_get_contents(dirname(__DIR__) . '/public/partials/sidebar.php'), $s);
    $platformKeys = ['platform-home', 'tenants', 'releases', 'server-health', 'group', 'home'];
    foreach (array_diff($s[1], $platformKeys) as $key) { check(isset($rules[$key]), "menu item $key has a permission rule"); }
    echo 'PASS my access (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

// Eliminar empresas (2026-10-02): solo archivadas, con contrasena y nombre exacto; se borra todo salvo su auditoria.
function tenantPurgeTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $tid = Util::id($tenant);
    $platform = sharedPlatform($db); $mine = $platform; $mine['tenant'] = $tenant;
    expect(adminRequest($mine, 'POST', '/organization', ['kind' => 'site', 'name' => 'Sede a borrar', 'parent_id' => null, 'active' => true]), 201, 'data with audit');
    $audit = (int) $db->one('SELECT COUNT(*) n FROM audit_log WHERE tenant_id=?', [$tenant])['n'];
    check($audit > 0, 'tenant has audit');
    $t = expect(adminRequest($mine, 'GET', '/tenants/' . $tid), 200, 'read tenant');
    $body = ['confirm_name' => $t['name'], 'accept_hardening_loss' => false];
    // La contrasena (reauth de 5 minutos) es el mismo mecanismo que prueban las cuentas del panel.
    expect(adminRequest($platform, 'POST', '/auth/reauth', ['password' => $platform['password']]), 200, 'reauth');
    check(adminRequest($mine, 'POST', '/tenants/' . $tid . '/purge', $body)[1]['detail'] === 'archive_first', 'only archived companies');
    $archived = expect(adminRequest($mine, 'PATCH', '/tenants/' . $tid, ['archived' => true], ['if-match' => '"' . $t['version'] . '"']), 200, 'archive');
    check(adminRequest($mine, 'POST', '/tenants/' . $tid . '/purge', ['confirm_name' => 'otra', 'accept_hardening_loss' => false])[1]['detail'] === 'confirm_name_mismatch', 'exact name required');
    check(adminRequest($mine, 'POST', '/tenants/' . $tid . '/purge', $body)[0] === 204, 'purge');
    foreach (['users', 'org_units', 'user_assignments', 'schedules', 'roles', 'retention_settings'] as $table) {
        check((int) $db->one("SELECT COUNT(*) n FROM `$table` WHERE tenant_id=?", [$tenant])['n'] === 0, "$table emptied");
    }
    check((int) $db->one('SELECT COUNT(*) n FROM audit_log WHERE tenant_id=?', [$tenant])['n'] >= $audit, 'audit kept');
    check((bool) $db->one('SELECT 1 x FROM tenants WHERE tenant_id=? AND deleted_at IS NOT NULL', [$tenant]), 'minimal record kept');
    check(!(bool) $db->one('SELECT 1 x FROM principals WHERE tenant_id=? AND detached=FALSE AND kind<>\'system\'', [$tenant]), 'identities detached');
    $ids = array_column(expect(adminRequest($platform, 'GET', '/tenants?include_archived=true'), 200, 'list')['data'], 'id');
    check(!in_array($tid, $ids, true), 'deleted company never listed');
    check(adminRequest($mine, 'GET', '/tenants/' . $tid)[0] === 404, 'deleted company unreachable');
    check((bool) $db->one("SELECT 1 x FROM audit_log WHERE tenant_id=0x00000000000040008000000000000001 AND action='purgeTenant' AND resource_id=?", [$tenant]), 'platform audit records the deletion');
    check(adminRequest($platform, 'POST', '/tenants/' . AdminAuth::PLATFORM . '/purge', ['confirm_name' => 'AZC Keeper', 'accept_hardening_loss' => true])[0] >= 400, 'platform cannot be deleted');
    try { $db->run('DELETE FROM audit_log WHERE tenant_id=? LIMIT 1', [$tenant]); check(false, 'audit still protected'); }
    catch (PDOException $e) { check((int) ($e->errorInfo[1] ?? 0) === 1644, 'audit still protected'); }
    echo 'PASS tenant purge (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
