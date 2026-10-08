<?php
declare(strict_types=1);
use Keeper\{Config,Database,Util};

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
    $off = expect(intakeAsk($key['key'], $pairG, null, 'DESKTOP-SELF', '1020304050'), 200, 'self identify off');
    check($off['status'] === 'pending' && $off['ask_document'] === false, 'declared document not auto-approved and not asked when disabled');
    check(expect(adminRequest($admin, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => true, 'self_identify' => true, 'self_identify_auto_confirm' => false]), 200, 'enable self identify')['self_identify'], 'settings saved');
    check(expect(intakeAsk($key['key'], keypair(), null, 'DESKTOP-ASK'), 200, 'ask for document')['ask_document'] === true, 'agent asked for document when enabled and missing');
    expect(intakeAsk($key['key'], $pairG, null, 'DESKTOP-SELF', '1020304050'), 200, 'self identify suggestion');
    $g = expect(adminRequest($admin, 'GET', '/enrollments/requests?status=pending'), 200, 'queue after self identify');
    $gRow = array_values(array_filter($g['data'], fn ($x) => $x['hostname'] === 'DESKTOP-SELF'))[0];
    check($gRow['match_kind'] === 'document' && $gRow['suggested_user_id'] === Util::id($u['id']), 'document becomes a suggestion');
    expect(adminRequest($admin, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => true, 'self_identify' => true, 'self_identify_auto_confirm' => true]), 200, 'auto confirm');
    check(expect(intakeAsk($key['key'], $pairG, null, 'DESKTOP-SELF', '1020304050'), 200, 'self identify auto')['status'] === 'approved', 'document auto-confirmed when enabled');

    // Auto-aprobacion por serie apagada: queda como sugerencia.
    expect(adminRequest($admin, 'PUT', '/enrollments/settings', ['auto_approve_serial_match' => false, 'self_identify' => false, 'self_identify_auto_confirm' => true]), 200, 'disable auto approve');
    $read = expect(adminRequest($admin, 'GET', '/enrollments/settings'), 200, 'read settings');
    check($read['self_identify_auto_confirm'] === true && $read['self_identify'] === false, 'auto confirm stored without self identify');

    // Migracion desde K3: el instalador declara la CC (installation.json -> claimed_document) con self_identify apagado.
    // Una persona activa => aprobada y asignada sola; a un equipo sin cedula no se le pregunta nada.
    $k3 = adminMember($db, $u);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'k3:cc','7700112233')", [$tenant, $k3['id']]);
    $pairK = keypair();
    $k = expect(intakeAsk($key['key'], $pairK, null, 'DESKTOP-K3', '7700112233', '4.0.20'), 200, 'k3 bridge declared document');
    check($k['status'] === 'approved' && is_string($k['enrollment_ticket']) && $k['ask_document'] === false, 'k3 declared document auto-approved without self identify');
    $kToken = intakeLogin($k['enrollment_ticket'], $pairK, 'DESKTOP-K3', '4.0.20');
    check($db->one('SELECT user_id FROM devices WHERE tenant_id=? AND id=?', [$tenant, Util::bin($kToken['device_id'])])['user_id'] === $k3['id'], 'k3 device assigned to the declared person');
    $kRow = $db->one('SELECT match_kind,user_id,status FROM enrollment_requests WHERE tenant_id=? AND id=?', [$tenant, Util::bin($k['request_id'])]);
    check($kRow['match_kind'] === 'document' && $kRow['status'] === 'enrolled' && $kRow['user_id'] === $k3['id'], 'k3 request recorded as document match');
    $none = expect(intakeAsk($key['key'], keypair(), null, 'DESKTOP-NODOC'), 200, 'generic package without document');
    check($none['status'] === 'pending' && $none['ask_document'] === false, 'device without document not asked when self identify off');
    // Sin coincidencia, persona inactiva o cedula repetida => pendiente para IT con la cedula visible.
    $inactive = adminMember($db, $u);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'k3:cc','7700445566')", [$tenant, $inactive['id']]);
    $db->run("UPDATE users SET status='inactive' WHERE tenant_id=? AND id=?", [$tenant, $inactive['id']]);
    $twinA = adminMember($db, $u); $twinB = adminMember($db, $u);
    $db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document','7700778899'),(?,?,'k3:cc','7700778899')", [$tenant, $twinA['id'], $tenant, $twinB['id']]);
    $cases = ['DESKTOP-K3-NONE' => ['7700000001', 'document_not_found'], 'DESKTOP-K3-INACTIVE' => ['7700445566', 'document_not_found'], 'DESKTOP-K3-TWINS' => ['7700778899', 'document_ambiguous']];
    foreach ($cases as $host => [$doc, $alert]) {
        $res = expect(intakeAsk($key['key'], keypair(), null, $host, $doc, '4.0.20'), 200, "k3 $host");
        check($res['status'] === 'pending' && $res['enrollment_ticket'] === null, "$host stays pending");
    }
    $queue = expect(adminRequest($admin, 'GET', '/enrollments/requests?status=pending'), 200, 'queue with k3 leftovers');
    $byHost = array_column($queue['data'], null, 'hostname');
    foreach ($cases as $host => [$doc, $alert]) {
        check(isset($byHost[$host]) && $byHost[$host]['claimed_document'] === $doc && $byHost[$host]['alert'] === $alert && $byHost[$host]['user_id'] === null, "$host visible to IT with document and $alert");
    }
    expect(adminRequest($admin, 'POST', '/enrollments/expected-devices', ['person' => '1020304050', 'asset_code' => 'ACT_0040', 'serial_number' => 'SN-MANUAL']), 201, 'expected for manual confirm');
    $pairH = keypair();
    check(expect(intakeAsk($key['key'], $pairH, 'SN-MANUAL', 'DESKTOP-MANUAL'), 200, 'serial without auto approve')['status'] === 'pending', 'serial match waits for IT when disabled');

    // Revocar la clave corta las solicitudes nuevas.
    expect(adminRequest($admin, 'DELETE', '/enrollments/keys/' . $key['id']), 200, 'revoke enrollment key');
    expect(intakeAsk($key['key'], keypair(), 'SN-NEW'), 401, 'revoked key rejected');
    // API externa: un inventario carga esperados con su scope; sin el scope, 403; lecturas no pueden escribir.
    $writer = externalCredential($db, $u, 'api_key', ['expected-devices:write']);
    $reader = externalCredential($db, $u, 'api_key', ['devices:read', 'members:read']);
    $rows = Util::json(['rows' => [['person' => '1020304050', 'asset_code' => 'ACT_0500', 'serial_number' => 'SN-EXT-1'], ['person' => '000', 'asset_code' => 'ACT_0501', 'serial_number' => null]]]);
    $denied = externalRequest('/ext/v1/expected-devices:import', $reader['headers'] + ['Content-Type' => 'application/json'], 'POST', $rows);
    check($denied[0] === 403, 'external import requires write scope');
    $ext = externalRequest('/ext/v1/expected-devices:import', $writer['headers'] + ['Content-Type' => 'application/json'], 'POST', $rows);
    check($ext[0] === 200 && $ext[1]['created'] === 1 && $ext[1]['errors'] === [['row' => 2, 'code' => 'person_not_found']], 'external import creates and reports rows');
    check($db->one("SELECT source FROM expected_devices WHERE tenant_id=? AND asset_code='ACT_0500'", [$tenant])['source'] === 'api', 'external rows tagged as api');
    $again = externalRequest('/ext/v1/expected-devices:import', $writer['headers'] + ['Content-Type' => 'application/json'], 'POST', $rows);
    check($again[1]['created'] === 0 && $again[1]['errors'][0]['code'] === 'duplicate_asset', 'external re-send never overwrites');
    echo 'PASS device intake (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
    installerTests($db, $admin, $u);
}
// Instalador listo para la empresa: el zip publicado con una clave nueva dentro de installation.json. Sin el archivo
// publicado (o con otro tamano) responde 409 sin crear clave. Deja una release que no se puede borrar: corre despues de las pruebas de releases.
function installerTests(Database $db, array $admin, array $u): void
{
    // Reutiliza la sesion de intakeTests: cada login nuevo gasta el cupo de prelogin compartido por toda la suite.
    $before = $GLOBALS['checks']; $tenant = $u['tenant_id'];
    $db->run("UPDATE tenants SET name='Compañía Ñandú S.A.S.' WHERE tenant_id=?", [$tenant]);
    $start = (int) $db->one('SELECT COUNT(*) n FROM enrollment_keys WHERE tenant_id=?', [$tenant])['n'];
    $keys = fn (): int => (int) $db->one('SELECT COUNT(*) n FROM enrollment_keys WHERE tenant_id=?', [$tenant])['n'] - $start;
    check(\Keeper\Features::forOperation('downloadInstaller', 'equipos.enrolar') === null, 'installer download is core, like createEnrollmentKey');
    $db->run("DELETE FROM role_permissions WHERE tenant_id=? AND role_id=? AND permission_code='equipos.enrolar'", [$tenant, $admin['role']]);
    expect(adminRequest($admin, 'POST', '/enrollments/keys/installer'), 403, 'installer needs equipos.enrolar');
    $db->run("INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,'equipos.enrolar')", [$tenant, $admin['role']]);
    $db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6)-INTERVAL 301 SECOND WHERE tenant_id=? AND admin_id=?', [$tenant, $admin['id']]);
    check(expect(adminRequest($admin, 'POST', '/enrollments/keys/installer'), 403, 'installer needs recent reauth')['code'] === 'reauth_required', 'installer reauth code');
    expect(adminRequest($admin, 'POST', '/auth/reauth', ['password' => $admin['password']]), 200, 'reauth for installer');
    $dir = dirname(__DIR__) . '/public/releases'; $made = !is_dir($dir) && mkdir($dir, 0755, true);
    $seq = (int) $db->one("SELECT COALESCE(MAX(sequence),0) s FROM client_releases WHERE channel='stable' AND architecture='x64'")['s'] + 1000;
    $version = '9.9.' . $seq; $name = "AZCKeeper_v4_bootstrap_$version.zip"; $file = "$dir/$name";
    $build = tempnam(sys_get_temp_dir(), 'kzip'); $got = tempnam(sys_get_temp_dir(), 'kzip');
    // Temporales que dejaria el servidor si volviera a copiar el zip (en Windows tempnam recorta el prefijo: kee*.tmp).
    $temps = fn (): array => glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'kee*' : 'keeper-installer*')) ?: [];
    $tempsBefore = $temps(); $leftovers = fn (): array => array_values(array_diff($temps(), $tempsBefore));
    try {
        $none = adminRequest($admin, 'POST', '/enrollments/keys/installer');
        check($none[0] === 409 && ($none[1]['detail'] ?? null) === 'installer_unavailable' && $keys() === 0, 'no published installer: 409 and no key created');
        // Como el publicado: varias entradas agent/ comprimidas (texto y binario), installation.json de la raiz comprimido.
        $zip = new ZipArchive(); $zip->open($build, ZipArchive::OVERWRITE);
        $zip->addFromString('agent/x.txt', 'contenido firmado');
        $zip->addFromString('installation.json', "{\n  \"api_base\": \"https://backend.example.invalid/v1/\",\n  \"tenant_id\": \"11111111-1111-1111-1111-111111111111\",\n  \"device_id\": \"22222222-2222-2222-2222-222222222222\",\n  \"enable_hklm\": true\n}");
        $zip->addEmptyDir('agent/lib');
        $zip->addFromString('agent/lib/AZCKeeper.dll', str_repeat(random_bytes(4096) . str_repeat("\0", 4096), 40));
        $zip->addFromString('agent/manifest.json', str_repeat('{"file":"agent/lib/AZCKeeper.dll","sha256":"' . bin2hex(random_bytes(32)) . "\"}\n", 50));
        $zip->addFromString('agent/README.txt', 'stored'); $zip->setCompressionName('agent/README.txt', ZipArchive::CM_STORE);
        $zip->close();
        $bytes = file_get_contents($build);
        $db->run("INSERT INTO client_releases (tenant_id,id,version,channel,sequence,min_agent_version,architecture,artifact_url,size_bytes,sha256,key_id,manifest_jws,published_at) VALUES (0x00000000000040008000000000000001,?,?,'stable',?,'4.0.0','x64',?,?,?,'smoke-1',?,UTC_TIMESTAMP(6))",
            [Util::bin(Util::uuid()), $version, $seq, "https://releases.example.test/$name", strlen($bytes), hash('sha256', $bytes, true), str_repeat('x', 32)]);
        $missing = adminRequest($admin, 'POST', '/enrollments/keys/installer');
        check($missing[0] === 409 && ($missing[1]['detail'] ?? null) === 'installer_unavailable' && $keys() === 0, 'release without file: 409 and no key created');
        file_put_contents($file, $bytes . 'x');
        $wrong = adminRequest($admin, 'POST', '/enrollments/keys/installer');
        check($wrong[0] === 409 && ($wrong[1]['detail'] ?? null) === 'installer_unavailable' && $keys() === 0, 'file with another size: 409 and no key created');
        check($leftovers() === [], 'no installer temp file left after the 409 paths');
        // Zip no soportado (bit de cifrado en el directorio central; mismo tamano, la release es inmutable): 409 sin clave.
        $flag = strpos($bytes, "PK\x01\x02", unpack('V', $bytes, strlen($bytes) - 6)[1]) + 8;
        file_put_contents($file, substr_replace($bytes, chr(ord($bytes[$flag]) | 1), $flag, 1));
        $locked = adminRequest($admin, 'POST', '/enrollments/keys/installer');
        check($locked[0] === 409 && ($locked[1]['detail'] ?? null) === 'installer_unavailable' && $keys() === 0, 'unsupported zip (encrypted entry): 409 and no key created');
        file_put_contents($file, $bytes);
        $r = adminRequest($admin, 'POST', '/enrollments/keys/installer');
        check($r[0] === 200 && $r[2]['content-type'] === 'application/zip' && $r[2]['cache-control'] === 'no-store', 'installer served as zip, no-store');
        check($r[2]['content-disposition'] === 'attachment; filename="AZCKeeper-' . $version . '-compania-nandu-s-a-s.zip"', 'installer file name: version and ascii tenant slug');
        check(($r[2]['content-length'] ?? null) === (string) strlen($r[3]) && ($r[2]['x-accel-buffering'] ?? null) === 'no', 'installer Content-Length is the exact body length; proxy buffering off');
        $cd = unpack('V', $bytes, strlen($bytes) - 6)[1]; // offset del directorio central (EOCD sin comentario)
        check(substr($r[3], 0, $cd) === substr($bytes, 0, $cd), 'installer streams the published bytes up to the central directory unchanged');
        check($leftovers() === [], 'no installer temp file left after a download');
        file_put_contents($got, $r[3]); $out = new ZipArchive(); check($out->open($got, ZipArchive::RDONLY | ZipArchive::CHECKCONS) === true, 'downloaded installer is a valid zip (libzip consistency check)');
        $raw = $out->getFromName('installation.json'); $cfg = json_decode($raw, true);
        check(!str_starts_with($raw, "\xEF\xBB\xBF") && str_contains($raw, "\n    ") && array_keys($cfg) === ['api_base', 'enrollment_key', 'enable_hklm'] && $cfg['enable_hklm'] === true, 'installation.json pretty, no BOM, only the generic fields');
        check(str_starts_with($cfg['api_base'], 'https://') && str_ends_with($cfg['api_base'], '/v1/') && parse_url($cfg['api_base'], PHP_URL_HOST) === parse_url(Config::get('ORIGIN'), PHP_URL_HOST), 'api_base is this backend over https ending in /v1/');
        $k = $db->one('SELECT * FROM enrollment_keys WHERE tenant_id=? AND key_hash=?', [$tenant, hash('sha256', $cfg['enrollment_key'], true)]);
        check(str_starts_with($cfg['enrollment_key'], 'kek_') && $k && $k['revoked_at'] === null && $keys() === 1, 'installer key stored (hash) for this tenant and active');
        check(($r[2]['x-enrollment-key-id'] ?? null) === Util::id($k['id']), 'installer response names the new key id (panel revokes it if the download fails)');
        check($out->getFromName('agent/x.txt') === 'contenido firmado', 'signed agent content untouched');
        $out->close(); installerZipMatches($build, $got, $raw, 'downloaded installer');
        $audit = $db->one("SELECT changed_fields FROM audit_log WHERE tenant_id=? AND action='downloadInstaller' AND resource_id=?", [$tenant, $k['id']]);
        check($audit && in_array('source:installer', json_decode($audit['changed_fields'], true), true), 'installer key audited as installer download');
        check(expect(intakeAsk($cfg['enrollment_key'], keypair(), 'SN-INSTALLER', 'DESKTOP-INSTALLER'), 200, 'intake with installer key')['status'] === 'pending', 'installer key accepted by intake');
    } finally { foreach ([$file, $build, $got] as $f) { if (is_file($f)) { unlink($f); } } if ($made) { rmdir($dir); } }
    // El artefacto real (si esta en el repo): misma transformacion a un archivo local y las mismas comprobaciones.
    $real = dirname(__DIR__, 2) . '/client-v4/artifacts/AZCKeeper_v4_bootstrap_4.0.15.zip';
    if (is_file($real)) {
        $h = fopen($real, 'rb'); $zip = \Keeper\InstallerZip::read($h, filesize($real)); $out = tempnam(sys_get_temp_dir(), 'kzip');
        try {
            check($zip !== null && count($zip['records']) === 306 && $zip['target'] !== null, 'real 4.0.15 artifact is a supported zip with installation.json');
            $cfg = json_encode(['api_base' => 'https://keeper.example.test/v1/', 'enrollment_key' => 'kek_' . Util::b64(random_bytes(32)), 'enable_hklm' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $o = fopen($out, 'wb'); fseek($h, 0); stream_copy_to_stream($h, $o, $zip['cd_offset']); fwrite($o, \Keeper\InstallerZip::tail($zip, $cfg, time())); fclose($o);
            installerZipMatches($real, $out, $cfg, 'real 4.0.15 installer');
        } finally { fclose($h); if (is_file($out)) { unlink($out); } }
    }
    echo 'PASS installer download (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
// $got es $orig con installation.json = $config: lo abre ZipArchive (con chequeo de consistencia) y un lector propio que
// recorre directorio central -> cabecera local -> datos; mismas entradas, y todo lo demas igual (nombre, CRC, tamano, contenido).
function installerZipMatches(string $orig, string $got, string $config, string $label): void
{
    $a = new ZipArchive(); $b = new ZipArchive();
    check($a->open($orig, ZipArchive::RDONLY) === true && $b->open($got, ZipArchive::RDONLY | ZipArchive::CHECKCONS) === true, "$label opens with ZipArchive");
    $raw = rawZipEntries((string) file_get_contents($got));
    check($raw !== null && count($raw) === $b->numFiles && $b->numFiles === $a->numFiles, "$label: same entry count in ZipArchive, the raw reader and the original");
    check($b->getFromName('installation.json') === $config && ($raw['installation.json'] ?? null) === $config, "$label: installation.json is the new config");
    $same = true;
    for ($i = 0; $i < $a->numFiles; $i++) {
        $s = $a->statIndex($i); if ($s['name'] === 'installation.json') { continue; }
        $t = $b->statName($s['name']); $content = $a->getFromIndex($i);
        $same = $same && $t !== false && $t['crc'] === $s['crc'] && $t['size'] === $s['size'] && $t['comp_method'] === $s['comp_method'] && $b->getFromName($s['name']) === $content && ($raw[$s['name']] ?? null) === $content;
    }
    check($same, "$label: every other entry keeps name, CRC, size, method and content");
    $a->close(); $b->close();
}
// Lector minimo e independiente de libzip (EOCD -> directorio central -> cabecera local -> stored/deflate, CRC verificado).
function rawZipEntries(string $z): ?array
{
    $e = strrpos($z, "PK\x05\x06"); if ($e === false) { return null; }
    $eocd = unpack('ventries/Vsize/Voffset', $z, $e + 10); $p = $eocd['offset']; $out = [];
    if ($p + $eocd['size'] !== $e) { return null; }
    for ($n = 0; $n < $eocd['entries']; $n++) {
        if (substr($z, $p, 4) !== "PK\x01\x02") { return null; }
        $c = unpack('vmethod/x4/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/x8/Voffset', $z, $p + 10); $name = substr($z, $p + 46, $c['nlen']);
        $l = unpack('vnlen/vxlen', $z, $c['offset'] + 26);
        if (substr($z, $c['offset'], 4) !== "PK\x03\x04" || substr($z, $c['offset'] + 30, $l['nlen']) !== $name) { return null; }
        $data = substr($z, $c['offset'] + 30 + $l['nlen'] + $l['xlen'], $c['csize']);
        $data = $c['method'] === 0 ? $data : ($c['method'] === 8 ? @gzinflate($data) : false);
        if ($data === false || strlen($data) !== $c['usize'] || crc32($data) !== $c['crc']) { return null; }
        $out[$name] = $data; $p += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
    }
    return $out;
}
