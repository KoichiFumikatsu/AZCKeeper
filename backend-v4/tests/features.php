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
