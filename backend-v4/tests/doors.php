<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// F3: entradas por puerta empujadas por el sistema de acceso; cuarentena y re-emparejamiento.
function doorEventTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $platform = sharedPlatform($db); $platform['tenant'] = $tenant;
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','8080001')", [$tenant, $u['id']]);
    $writer = externalCredential($db, $u, 'api_key', ['check-ins:write']);
    $reader = externalCredential($db, $u, 'api_key', ['presence:read']);
    $at = gmdate('Y-m-d\TH:i:s\Z', time() - 3600);
    $event = fn (string $id, string $door, string $doc, string $dir = 'in', bool $granted = true) => ['event_id' => $id, 'door_ref' => $door, 'document' => $doc, 'direction' => $dir, 'occurred_at' => $at, 'granted' => $granted];
    $send = fn (array $creds, array $events) => externalRequest('/ext/v1/check-ins:batch', $creds['headers'] + ['Content-Type' => 'application/json'], 'POST', Util::json(['schema' => 'keeper.checkins.v1', 'events' => $events]));

    check($send($reader, [$event('e0', '0.3.1', '8080001')])[0] === 403, 'check-ins require write scope');
    // Puerta aun no registrada: todo a cuarentena.
    $r1 = $send($writer, [$event('e1', '0.3.1', '8.080.001'), $event('e2', '0.3.1', '9999999'), $event('e3', '0.3.1', '8080001', 'in', false)]);
    check($r1[0] === 200 && $r1[1]['quarantined'] === 2 && $r1[1]['ignored'] === 1, 'unknown door quarantined; denied swipe ignored');
    $q = expect(adminRequest($platform, 'GET', '/doors/quarantine'), 200, 'quarantine view');
    check($q['unknown_doors'][0]['door_ref'] === '0.3.1' && $q['unknown_doors'][0]['events'] === 2, 'quarantine lists unknown door');
    // Registrar la puerta empareja lo que esperaba (la persona conocida); la cedula desconocida sigue esperando.
    $door = expect(adminRequest($platform, 'POST', '/doors', ['name' => 'Portería Palmetto', 'source_ref' => '0.3.1', 'site_id' => Util::id($u['site_id'])]), 201, 'register door');
    check((int) $db->one("SELECT COUNT(*) n FROM check_ins WHERE tenant_id=? AND source='door' AND user_id=?", [$tenant, $u['id']])['n'] === 1, 'quarantined entry matched after door registration');
    $q2 = expect(adminRequest($platform, 'GET', '/doors/quarantine'), 200, 'quarantine after door');
    check($q2['unknown_doors'] === [] && count($q2['unknown_people']) === 1 && $q2['unknown_people'][0]['document_hint'] === '…9999', 'unknown person still waiting, masked');
    // Los pasos asignados se listan por dia local de la empresa, sin cedula.
    $passAt = $db->one("SELECT occurred_at FROM check_ins WHERE tenant_id=? AND source='door' ORDER BY occurred_at DESC LIMIT 1", [$tenant])['occurred_at'];
    $passDay = (new DateTimeImmutable($passAt . ' UTC'))->setTimezone(new DateTimeZone($db->one('SELECT timezone FROM tenants WHERE tenant_id=?', [$tenant])['timezone']))->format('Y-m-d');
    $passes = expect(adminRequest($platform, 'GET', '/doors/passes?date=' . $passDay), 200, 'door passes view');
    check($passes['date'] === $passDay && !$passes['truncated'] && count($passes['data']) === 1 && $passes['data'][0]['door_name'] === 'Portería Palmetto' && $passes['data'][0]['user_id'] === Util::id($u['id']), 'passes list the matched entry');
    check(!array_key_exists('document', $passes['data'][0]), 'passes hide the document');
    check(expect(adminRequest($platform, 'GET', '/doors/passes?date=' . $passDay . '&door_id=' . $door['id']), 200, 'passes by door')['data'] !== [], 'door filter keeps its entries');
    check(expect(adminRequest($platform, 'GET', '/doors/passes?date=' . $passDay . '&door_id=' . Util::uuid()), 200, 'passes by other door')['data'] === [], 'door filter drops the rest');
    check(expect(adminRequest($platform, 'GET', '/doors/passes?date=2001-01-01'), 200, 'passes of another day')['data'] === [], 'date filter drops other days');
    check(adminRequest($platform, 'GET', '/doors/passes?date=2026-02-31')[0] === 422, 'impossible date rejected');
    // Idempotencia y nuevas entradas.
    $r2 = $send($writer, [$event('e1', '0.3.1', '8080001'), $event('e4', '0.3.1', '8080001', 'out')]);
    check($r2[1]['duplicate'] === 1 && $r2[1]['accepted'] === 1, 'resend is duplicate; new event accepted');
    // La persona aparece (p. ej. tras sincronizar con One): el cron re-empareja.
    $db->run("UPDATE user_external_refs SET external_ref='9999999' WHERE tenant_id=? AND user_id=? AND source='document'", [$tenant, $u['id']]);
    check((new Keeper\DoorEvents($db))->rematch($tenant) === 1, 'rematch after person appears');
    // Puerta interna: no cuenta para la presencia.
    expect(adminRequest($platform, 'PATCH', '/doors/' . $door['id'], ['counts_for_presence' => false]), 200, 'mark internal door');
    check($send($writer, [$event('e5', '0.3.1', '9999999')])[1]['ignored'] === 1, 'internal door ignored');
    check(adminRequest($platform, 'POST', '/doors', ['name' => 'Otra', 'source_ref' => '0.3.1', 'site_id' => Util::id($u['site_id'])])[1]['detail'] === 'door_exists', 'door reference unique');
    // Presencia ve la entrada por puerta.
    check((bool) $db->one("SELECT 1 FROM check_ins WHERE tenant_id=? AND source='door' AND door_id=? AND site_id=?", [$tenant, Util::bin($door['id']), $u['site_id']]), 'door entry carries its site');
    echo 'PASS door events (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
