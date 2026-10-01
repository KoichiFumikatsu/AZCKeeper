<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// Auditoria legible (quien y sobre que, filtro por tipo de actor) y retencion editable en Ajustes.
function opsTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $person = expect(adminRequest($platform, 'POST', '/users', ['display_name' => 'Persona auditada', 'email' => null, 'firm_id' => Util::id($u['firm_id']), 'site_id' => Util::id($u['site_id']),
        'area_id' => Util::id($u['area_id']), 'position_id' => Util::id($u['position_id']), 'schedule_id' => Util::id($u['schedule_id'])]), 201, 'create person to audit');
    $from = gmdate('Y-m-d', time() - 86400); $to = gmdate('Y-m-d', time() + 2 * 86400);
    $rows = expect(adminRequest($platform, 'GET', "/audit?from=$from&to=$to&actor_type=admin"), 200, 'audit by people')['data'];
    $created = array_values(array_filter($rows, fn ($r) => $r['action'] === 'createUser' && $r['resource_id'] === $person['id']));
    check(count($created) === 1 && $created[0]['resource_name'] === 'Persona auditada' && $created[0]['actor_name'] !== null, 'audit says who and on what');
    check(!array_filter($rows, fn ($r) => $r['actor_type'] !== 'admin'), 'actor filter applied');
    check(adminRequest($platform, 'PUT', '/tenants/panel-settings', ['install_coverage_heartbeat_days' => 7, 'raw_days' => 30, 'log_days' => 15])[0] === 200, 'retention saved');
    $cfg = expect(adminRequest($platform, 'GET', '/tenants/panel-settings'), 200, 'read settings');
    check($cfg['raw_days'] === 30 && $cfg['log_days'] === 15 && $cfg['aggregate_months'] === 13, 'retention values returned');
    // Reglas "todo" en descargas, instalaciones y USB (lo que envia la pantalla Reglas) se aceptan; el panel no se bloquea.
    $rules = [['id' => Util::uuid(), 'kind' => 'web', 'effect' => 'deny', 'targets' => ['facebook.com', 'instagram.com', 'netflix.com', 'disneyplus.com'], 'schedule_id' => null, 'priority' => 0],
        ['id' => Util::uuid(), 'kind' => 'download', 'effect' => 'deny', 'targets' => ['*'], 'schedule_id' => null, 'priority' => 0],
        ['id' => Util::uuid(), 'kind' => 'usb', 'effect' => 'deny', 'targets' => ['write'], 'schedule_id' => null, 'priority' => 0]];
    expect(adminRequest($platform, 'POST', '/policies', ['name' => 'Bloqueos generales', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => $rules]), 201, 'blanket download and usb rules accepted');
    $bad = adminRequest($platform, 'POST', '/policies', ['name' => 'Mala', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => [['id' => Util::uuid(), 'kind' => 'installation', 'effect' => 'deny', 'targets' => ['no valido!'], 'schedule_id' => null, 'priority' => 0]]]);
    check($bad[0] === 422 && $bad[1]['detail'] === 'invalid_target:installation:no valido!', 'validation says which value failed');
    // Guardia (2026-10-01): toda ruta que el panel recorre por paginas (allPages agrega ?limit=100) debe aceptar limit;
    // si no, la pantalla entera falla con validation_failed (paso en Alta de equipos y en Roles).
    $contract = json_decode(file_get_contents(dirname(__DIR__) . '/config/contract.json'), true);
    foreach (glob(dirname(__DIR__) . '/public/assets/*.js') as $file) {
        preg_match_all("/allPages\('(\/[a-z\/-]+)'/", file_get_contents($file), $m);
        foreach (array_unique($m[1]) as $path) {
            $params = array_map(fn ($x) => strtolower(basename($x['$ref'] ?? $x['name'] ?? '')), $contract['paths'][$path]['get']['parameters'] ?? []);
            check(in_array('limit', $params, true), basename($file) . " pages $path, which must accept limit");
        }
    }
    // Guardia: toda operacion de escritura del contrato tiene su traduccion en Auditoria (si no, sale en ingles tecnico).
    $js = file_get_contents(dirname(__DIR__) . '/public/assets/k3.js');
    $block = substr($js, strpos($js, 'const AUDIT_ACTIONS = {'), strpos($js, 'const AUDIT_VERBS') - strpos($js, 'const AUDIT_ACTIONS = {'));
    foreach ($contract['paths'] as $path => $ops) {
        foreach (['post', 'put', 'patch', 'delete'] as $m) {
            $op = $ops[$m]['operationId'] ?? null;
            if ($op !== null) { check(preg_match("/[\\s{,]'?" . preg_quote($op, '/') . "'?:\\s*'/", $block) === 1, "audit label in Spanish for $op"); }
        }
    }
    echo 'PASS ops (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
