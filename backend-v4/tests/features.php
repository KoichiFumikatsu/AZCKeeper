<?php
declare(strict_types=1);
use Keeper\{Database,Features,Util};

// Fase 1 de v4-clientes-funciones-accesos: catalogo de funciones vendibles (2026-10-02).
function featureCatalogTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    check(count(Features::CATALOG) === 23, 'catalog has 23 functions');
    // Todo permiso existente pertenece a una funcion, al nucleo o a la plataforma (nunca a dos).
    $byFeature = array_merge(...array_values(array_map(fn ($f) => $f['permissions'], Features::CATALOG)));
    foreach ($db->run('SELECT code FROM permissions')->fetchAll() as $p) {
        $code = $p['code'];
        $places = (int) in_array($code, $byFeature, true) + (int) in_array($code, Features::CORE_PERMISSIONS, true) + (int) in_array($code, Features::PLATFORM_PERMISSIONS, true);
        check($places === 1, "permission $code classified once");
    }
    // Toda operacion nombrada en el catalogo existe en el contrato.
    $contract = json_decode(file_get_contents(dirname(__DIR__) . '/config/contract.json'), true);
    $ops = [];
    foreach ($contract['paths'] as $path) { foreach ($path as $op) { if (is_array($op) && isset($op['operationId'])) { $ops[] = $op['operationId']; } } }
    foreach (Features::CATALOG as $code => $f) {
        foreach ($f['ops'] as $op) { check(in_array($op, $ops, true), "$code covers existing operation $op"); }
        // Ninguna funcion sin uso, salvo las marcadas pendientes (sin endpoints todavia).
        check($f['pending'] || $f['ops'] || $f['permissions'] || $f['rules'], "$code is used somewhere");
        check(in_array($f['level'], ['client', 'person'], true), "$code has a level");
    }
    // Resolucion: la operacion manda sobre el permiso; el permiso sobre nada.
    check(Features::forOperation('listDoors', 'organizacion.ver') === 'puertas', 'doors by operation');
    check(Features::forOperation('listOrganization', 'organizacion.ver') === null, 'organization is core');
    check(Features::forOperation('listHolidays', 'festivos.ver') === 'festivos', 'holidays by permission');
    check(Features::forOperation('getPresenceReport', 'reportes.ver') === 'presencia', 'presence report by operation');
    check(Features::forOperation('getProductivity', 'reportes.ver') === 'productividad', 'productivity by permission');
    check(Features::forOperation('listPanelAccounts', 'usuarios.editar') === 'autogestion_accesos', 'panel accounts are self-managed access');
    check(Features::forOperation('patchUser', 'usuarios.editar') === 'autogestion_personas', 'editing people is self-managed people');
    check(Features::forRuleKind('usb') === 'usb' && Features::forRuleKind('download') === 'descargas' && Features::forRuleKind('installation') === 'instalaciones' && Features::forRuleKind('web') === 'web', 'rule kinds');
    echo 'PASS feature catalog (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}

function featureStorageTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    // Los clientes de las pruebas (como los existentes al migrar) tienen todas las funciones.
    check(Features::enabled($db, $tenant) === (function () { $c = Features::codes(); sort($c); return $c; })(), 'fixture tenant has every function');
    check(Features::has($db, $tenant, 'usb') && !Features::has($db, $tenant, 'inexistente'), 'has()');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='usb'", [$tenant]);
    check(!Features::has($db, $tenant, 'usb'), 'function can be turned off');
    echo 'PASS feature storage (' . ($GLOBALS['checks'] - $before) . " assertions)
";
}

function featureEnforcementTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $admin = adminFixture($db, $u, ['festivos.ver', 'organizacion.ver', 'reglas.editar', 'reglas.ver'], false);
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    check(adminRequest($admin, 'GET', '/schedules/holidays')[0] === 200, 'contracted function works');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('festivos','puertas','usb')", [$tenant]);
    $off = adminRequest($admin, 'GET', '/schedules/holidays');
    check($off[0] === 403 && $off[1]['detail'] === 'feature_not_contracted:festivos', 'function off is refused even with permission');
    check(adminRequest($admin, 'GET', '/doors')[1]['detail'] === 'feature_not_contracted:puertas', 'shared permission resolved by operation');
    check(adminRequest($admin, 'GET', '/organization')[0] === 200, 'core keeps working');
    check(adminRequest($platform, 'GET', '/schedules/holidays')[0] === 200, 'superadmin never blocked');
    $rule = ['id' => Util::uuid(), 'kind' => 'usb', 'effect' => 'deny', 'targets' => ['*'], 'schedule_id' => null, 'priority' => 0];
    $usb = adminRequest($admin, 'POST', '/policies', ['name' => 'USB', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => [$rule]]);
    check($usb[0] === 403 && $usb[1]['detail'] === 'feature_not_contracted:usb', 'rule kind needs its function');
    $web = ['id' => Util::uuid(), 'kind' => 'web', 'effect' => 'deny', 'targets' => ['facebook.com'], 'schedule_id' => null, 'priority' => 0];
    check(adminRequest($admin, 'POST', '/policies', ['name' => 'Web', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => [$web]])[0] === 201, 'other rule kinds still allowed');
    echo 'PASS feature enforcement (' . ($GLOBALS['checks'] - $before) . " assertions)
";
}

function featureExternalAndCommandTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id']; $uid = Util::id($u['id']);
    $data = externalData($db, $u, 120); adminMember($db, $u);
    $key = externalCredential($db, $u, 'api_key', Keeper\ExternalAuth::SCOPES);
    $range = '?from=2026-09-10&to=2026-09-12';
    $activity = '/ext/v1/members/' . $uid . '/activity' . $range;
    check(externalRequest('/ext/v1/tiers', $key['headers'])[0] === 200, 'external API works while contracted');
    check(externalRequest($activity . '&include_titles=true', $key['headers'])[0] === 200, 'titles served while contracted');
    check(externalRequest('/ext/v1/members/' . $uid . '/check-ins' . $range . '&include_location=true', $key['headers'])[0] === 200, 'location served while contracted');
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code IN ('titulos','ubicacion','borrado')", [$tenant]);
    $titles = externalRequest($activity . '&include_titles=true', $key['headers']);
    check($titles[0] === 403 && $titles[1]['detail'] === 'feature_not_contracted:titulos', 'titles need the titulos function');
    check(externalRequest($activity, $key['headers'])[0] === 200, 'activity without titles still works');
    $loc = externalRequest('/ext/v1/members/' . $uid . '/check-ins' . $range . '&include_location=true', $key['headers']);
    check($loc[0] === 403 && $loc[1]['detail'] === 'feature_not_contracted:ubicacion', 'location needs the ubicacion function');
    check(externalRequest('/ext/v1/members/' . $uid . '/check-ins' . $range, $key['headers'])[0] === 200, 'check-ins without location still work');
    // Ordenes remotas: el borrado depende de su funcion; bloquear no.
    $admin = adminFixture($db, $u, ['equipos.ver', 'equipos.borrar', 'equipos.bloquear'], false);
    $device = Util::id($data['device']);
    check(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']])[0] === 200, 'reauth for wipe');
    $base = ['reason' => 'Feature test', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)];
    $wipe = adminRequest($admin, 'POST', '/devices/' . $device . '/commands', $base + ['type' => 'wipe', 'confirmation' => 'external-device'], ['idempotency-key' => Util::uuid()]);
    check($wipe[0] === 403 && $wipe[1]['detail'] === 'feature_not_contracted:borrado', 'wipe needs the borrado function');
    $lock = adminRequest($admin, 'POST', '/devices/' . $device . '/commands', $base + ['type' => 'lock'], ['idempotency-key' => Util::uuid()]);
    check($lock[0] === 202, 'lock unaffected by borrado being off');
    // La funcion 'api' apagada corta la API externa completa.
    $db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='api'", [$tenant]);
    $off = externalRequest('/ext/v1/tiers', $key['headers']);
    check($off[0] === 403 && $off[1]['detail'] === 'feature_not_contracted:api', 'external API needs the api function');
    echo 'PASS feature external and commands (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
