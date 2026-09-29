<?php
declare(strict_types=1);
use Keeper\{Database,Util};

// Alta de equipos a escala: clave de alta de la empresa + registro propio de equipos esperados.
function intakeAsk(string $key, array $pair, ?string $serial, string $host = 'DESKTOP-INTAKE', ?string $document = null, string $version = '4.0.9'): array
{
    return request('POST', '/client/enrollment-requests', ['enrollment_key' => $key, 'public_key' => $pair[1], 'serial_number' => $serial, 'hostname' => $host, 'agent_version' => $version, 'claimed_document' => $document], $pair);
}
function intakeLogin(string $ticket, array $pair, string $host = 'DESKTOP-INTAKE', string $version = '4.0.9'): array
{
    $challenge = expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $ticket]), 200, 'intake challenge');
    return expect(request('POST', '/client/login', ['enrollment_ticket' => $ticket, 'public_key' => $pair[1], 'hostname' => $host, 'agent_version' => $version], $pair, null, null, $challenge['nonce']), 200, 'intake ticket login');
}
function intakeTests(Database $db): void
{
    $before = $GLOBALS['checks'];
    $u = secondTenant($db); $tenant = $u['tenant_id'];
    $permissions = array_column($db->run('SELECT code FROM permissions WHERE platform_only=FALSE')->fetchAll(), 'code');
    $admin = adminFixture($db, $u, $permissions);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','1020304050')", [$tenant, $u['id']]);
    $other = adminMember($db, $u); $db->run("UPDATE users SET email='otra.persona@example.test' WHERE tenant_id=? AND id=?", [$tenant, $other['id']]);
    $scopedUser = adminMember($db, $u); $scoped = adminFixture($db, $scopedUser, ['equipos.enrolar', 'equipos.ver'], false, 'self');

    // Clave de alta: requiere reautenticacion, se muestra una vez, solo se guarda el hash.
    expect(adminRequest($admin, 'POST', '/enrollments/keys'), 403, 'enrollment key needs reauth');
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']]), 200, 'reauth for enrollment key');
    $key = expect(adminRequest($admin, 'POST', '/enrollments/keys'), 201, 'create enrollment key');
    check(str_starts_with($key['key'], 'kek_') && !$db->one('SELECT id FROM enrollment_keys WHERE hint=? AND key_hash=?', [$key['hint'], $key['key']]), 'enrollment key stored only as hash');
    $keys = expect(adminRequest($admin, 'GET', '/enrollments/keys'), 200, 'list enrollment keys'); check(!isset($keys['data'][0]['key']), 'listed keys never include the secret');
    expect(adminRequest($scoped, 'GET', '/enrollments/requests'), 403, 'request queue needs tenant scope');

    // Registro de esperados: manual por documento (con puntos) y por correo; placa y serie unicas entre pendientes.
    $e1 = expect(adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => '1.020.304.050', 'asset_code' => 'act_0015', 'serial_number' => ' sn-intake-1 ']), 201, 'expected by document');
    check($e1['asset_code'] === 'ACT_0015' && $e1['serial_number'] === 'SN-INTAKE-1' && $e1['status'] === 'pending' && $e1['user_id'] === Util::id($u['id']), 'expected normalized and linked to person');
    $dup = adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => '1020304050', 'asset_code' => 'ACT_0015', 'serial_number' => null]);
    check($dup[0] === 409 && ($dup[1]['detail'] ?? null) === 'duplicate_asset', 'duplicate asset rejected with reason');
    check(adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => '999', 'asset_code' => 'ACT_0099', 'serial_number' => null])[1]['detail'] === 'person_not_found', 'unknown person reported');
    check(adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => '1020304050', 'asset_code' => null, 'serial_number' => null])[0] === 422, 'asset or serial required');
    $import = expect(adminRequest($admin, 'POST', '/enrollments/expected-devices:import', ['rows' => [
        ['person' => 'otra.persona@example.test', 'asset_code' => 'ACT_0016', 'serial_number' => 'SN-INTAKE-2'],
        ['person' => 'nadie@example.test', 'asset_code' => 'ACT_0017', 'serial_number' => null],
        ['person' => '1020304050', 'asset_code' => 'ACT_0018', 'serial_number' => 'SN-INTAKE-1'],
        ['person' => '1020304050', 'asset_code' => 'ACT_0019', 'serial_number' => 'SN-INTAKE-3'],
    ]]), 200, 'import expected devices');
    check($import['created'] === 2 && $import['errors'] === [['row' => 2, 'code' => 'person_not_found'], ['row' => 3, 'code' => 'duplicate_serial']], 'import reports each failing row');
    $list = expect(adminRequest($admin, 'GET', '/enrollments/expected-devices?status=pending'), 200, 'list expected');
    check(count($list['data']) === 3, 'three pending expected devices');
    $selfList = expect(adminRequest($scoped, 'GET', '/enrollments/expected-devices'), 200, 'self scope expected list');
    check($selfList['data'] === [], 'self scope sees none of the others');
    $e4 = array_values(array_filter($list['data'], fn ($x) => $x['asset_code'] === 'ACT_0019'))[0];
    check(expect(adminRequest($admin, 'DELETE', '/enrollments/expected-devices/' . $e4['id']), 200, 'cancel expected')['status'] === 'cancelled', 'expected cancelled');
    expect(adminRequest($admin, 'DELETE', '/enrollments/expected-devices/' . $e4['id']), 409, 'cancel twice');

    // Cliente: clave desconocida y firma ajena se rechazan sin revelar nada.
    $pairA = keypair();
    expect(intakeAsk('kek_' . str_repeat('A', 43), $pairA, 'SN-INTAKE-1'), 401, 'unknown enrollment key');
    $unsigned = request('POST', '/client/enrollment-requests', ['enrollment_key' => $key['key'], 'public_key' => $pairA[1], 'serial_number' => 'SN-INTAKE-1', 'hostname' => 'X', 'agent_version' => '4.0.9', 'claimed_document' => null]);
    check($unsigned[0] === 401, 'unsigned enrollment request rejected');
    $pairX = keypair();
    $stolen = request('POST', '/client/enrollment-requests', ['enrollment_key' => $key['key'], 'public_key' => $pairA[1], 'serial_number' => 'SN-INTAKE-1', 'hostname' => 'X', 'agent_version' => '4.0.9', 'claimed_document' => null], $pairX);
    check($stolen[0] === 401, 'request signed with another key rejected');

    // Serie de un esperado: aprobada sola, ticket ligado a la clave, login crea el equipo con placa y renombre.
    $a = expect(intakeAsk($key['key'], $pairA, 'sn-intake-1'), 200, 'serial match request');
    check($a['status'] === 'approved' && is_string($a['enrollment_ticket']) && $a['retry_after_seconds'] === 0, 'serial match auto-approved with ticket');
    $again = expect(intakeAsk($key['key'], $pairA, 'SN-INTAKE-1'), 200, 'repeat approved request');
    check($again['request_id'] === $a['request_id'] && $again['enrollment_ticket'] !== $a['enrollment_ticket'], 'idempotent request, fresh ticket');
    expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $a['enrollment_ticket']]), 401, 'previous ticket revoked on reissue');
    $pairB = keypair();
    $stealTicket = expect(request('POST', '/client/auth/challenges', ['enrollment_ticket' => $again['enrollment_ticket']]), 200, 'challenge for theft attempt');
    expect(request('POST', '/client/login', ['enrollment_ticket' => $again['enrollment_ticket'], 'public_key' => $pairB[1], 'hostname' => 'THIEF', 'agent_version' => '4.0.9'], $pairB, null, null, $stealTicket['nonce']), 401, 'ticket unusable with another key');
    $token = intakeLogin($again['enrollment_ticket'], $pairA);
    $device = $db->one('SELECT * FROM devices WHERE tenant_id=? AND id=?', [$tenant, Util::bin($token['device_id'])]);
    check($device['user_id'] === $u['id'] && $device['asset_code'] === 'ACT_0015', 'device enrolled to expected person with asset code');
    $rename = $db->one("SELECT * FROM device_command WHERE tenant_id=? AND device_id=? AND type='rename_computer'", [$tenant, $device['id']]);
    check($rename && json_decode($rename['parameters'], true) == ['computer_name' => 'ACT-0015', 'restart_now' => false], 'rename to ACT-0015 queued without restart');
    $done = expect(intakeAsk($key['key'], $pairA, 'SN-INTAKE-1'), 200, 'request after enrollment');
    check($done['status'] === 'enrolled' && $done['device_id'] === $token['device_id'] && $done['enrollment_ticket'] === null, 'enrolled request points to device');
    check($db->one('SELECT status FROM expected_devices WHERE tenant_id=? AND id=?', [$tenant, Util::bin($e1['id'])])['status'] === 'enrolled', 'expected consumed');
    $fetched = expect(adminRequest($admin, 'GET', '/devices/' . $token['device_id']), 200, 'device dto with asset'); check($fetched['asset_code'] === 'ACT_0015', 'device dto exposes asset code');

    // Misma serie desde otro equipo: nunca se aprueba sola.
    $pairC = keypair();
    $c = expect(intakeAsk($key['key'], $pairC, 'SN-INTAKE-1', 'DESKTOP-CLONE'), 200, 'same serial second device');
    check($c['status'] === 'pending' && $c['enrollment_ticket'] === null && $c['retry_after_seconds'] > 0, 'second device with consumed serial stays pending');

    // Sin coincidencia: pendiente; IT carga el esperado despues y el siguiente reintento se aprueba solo.
    $pairD = keypair();
    $d = expect(intakeAsk($key['key'], $pairD, 'SN-LATE', 'DESKTOP-LATE'), 200, 'unknown serial');
    check($d['status'] === 'pending', 'unknown serial pending');
    expect(adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => 'otra.persona@example.test', 'asset_code' => 'ACT_0020', 'serial_number' => 'SN-LATE']), 201, 'late expected');
    check(expect(intakeAsk($key['key'], $pairD, 'SN-LATE', 'DESKTOP-LATE'), 200, 'late retry')['status'] === 'approved', 'retry re-evaluates against new expected');

    // Cola de IT: aprobar eligiendo persona, rechazar.
    $pairE = keypair(); $pairF = keypair();
    $eReq = expect(intakeAsk($key['key'], $pairE, null, 'DESKTOP-NOSERIAL'), 200, 'no serial request');
    $fReq = expect(intakeAsk($key['key'], $pairF, 'SN-REJECT', 'DESKTOP-REJECT'), 200, 'to be rejected');
    $queue = expect(adminRequest($admin, 'GET', '/enrollments/requests?status=pending'), 200, 'pending queue');
    $byId = array_column($queue['data'], null, 'id');
    check(isset($byId[$eReq['request_id']], $byId[$c['request_id']]) && $byId[$c['request_id']]['alert'] === 'serial_already_enrolled' && $byId[$c['request_id']]['suggested_user_name'] === $u['display_name'], 'queue shows duplicate alert and suggestion');
    expect(adminRequest($admin, 'POST', '/enrollments/requests/' . $eReq['request_id'] . '/approve', ['person' => null, 'asset_code' => null]), 422, 'approval needs a person');
    $approved = expect(adminRequest($admin, 'POST', '/enrollments/requests/' . $eReq['request_id'] . '/approve', ['person' => '1020304050', 'asset_code' => 'act_0030']), 200, 'approve with person');
    check($approved['status'] === 'approved' && $approved['match_kind'] === 'manual' && $approved['asset_code'] === 'ACT_0030', 'manual approval recorded');
    $eTicket = expect(intakeAsk($key['key'], $pairE, null, 'DESKTOP-NOSERIAL', null, '4.0.7'), 200, 'approved poll');
    $eToken = intakeLogin($eTicket['enrollment_ticket'], $pairE, 'DESKTOP-NOSERIAL', '4.0.7');
    check(!$db->one("SELECT id FROM device_command WHERE tenant_id=? AND device_id=? AND type='rename_computer'", [$tenant, Util::bin($eToken['device_id'])]), 'old agent gets asset code but no rename command');
    expect(adminRequest($admin, 'POST', '/enrollments/requests/' . $fReq['request_id'] . '/reject', null), 200, 'reject request');
    $rejected = expect(intakeAsk($key['key'], $pairF, 'SN-REJECT', 'DESKTOP-REJECT'), 200, 'rejected poll');
    check($rejected['status'] === 'rejected' && $rejected['enrollment_ticket'] === null, 'rejected device gets no ticket');
    expect(adminRequest($admin, 'POST', '/enrollments/requests/' . $fReq['request_id'] . '/approve', ['person' => '1020304050', 'asset_code' => null]), 409, 'rejected cannot be approved');

    // Autoidentificacion por cedula: apagada no sugiere; sugerencia; confirmacion automatica.
    $pairG = keypair();
    check(expect(intakeAsk($key['key'], $pairG, null, 'DESKTOP-SELF', '1020304050'), 200, 'self identify off')['status'] === 'pending', 'document ignored when disabled');
    check(expect(adminRequest($admin, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => true, 'self_identify' => true, 'self_identify_auto_confirm' => false]), 200, 'enable self identify')['self_identify'], 'settings saved');
    expect(intakeAsk($key['key'], $pairG, null, 'DESKTOP-SELF', '1020304050'), 200, 'self identify suggestion');
    $g = expect(adminRequest($admin, 'GET', '/enrollments/requests?status=pending'), 200, 'queue after self identify');
    $gRow = array_values(array_filter($g['data'], fn ($x) => $x['hostname'] === 'DESKTOP-SELF'))[0];
    check($gRow['match_kind'] === 'document' && $gRow['suggested_user_id'] === Util::id($u['id']), 'document becomes a suggestion');
    expect(adminRequest($admin, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => true, 'self_identify' => true, 'self_identify_auto_confirm' => true]), 200, 'auto confirm');
    check(expect(intakeAsk($key['key'], $pairG, null, 'DESKTOP-SELF', '1020304050'), 200, 'self identify auto')['status'] === 'approved', 'document auto-confirmed when enabled');

    // Auto-aprobacion por serie apagada: queda como sugerencia.
    expect(adminRequest($admin, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => false, 'self_identify' => false, 'self_identify_auto_confirm' => true]), 200, 'disable auto approve');
    check(!expect(adminRequest($admin, 'GET', '/enrollments/settings'), 200, 'read settings')['self_identify_auto_confirm'], 'auto confirm requires self identify');
    expect(adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => '1020304050', 'asset_code' => 'ACT_0040', 'serial_number' => 'SN-MANUAL']), 201, 'expected for manual confirm');
    $pairH = keypair();
    check(expect(intakeAsk($key['key'], $pairH, 'SN-MANUAL', 'DESKTOP-MANUAL'), 200, 'serial without auto approve')['status'] === 'pending', 'serial match waits for IT when disabled');

    // Revocar la clave corta las solicitudes nuevas.
    expect(adminRequest($admin, 'DELETE', '/enrollments/keys/' . $key['id']), 200, 'revoke enrollment key');
    expect(intakeAsk($key['key'], keypair(), 'SN-NEW'), 401, 'revoked key rejected');
    echo 'PASS device intake (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
