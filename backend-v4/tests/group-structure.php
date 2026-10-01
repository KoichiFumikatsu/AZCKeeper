<?php
declare(strict_types=1);
use Keeper\{ActivityCalendar,Database,HolidaySeeds,Util};

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
    echo 'PASS group structure (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
