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
    echo 'PASS ops (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
