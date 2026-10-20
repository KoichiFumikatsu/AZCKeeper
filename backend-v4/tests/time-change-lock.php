<?php
declare(strict_types=1);
use Keeper\{Database,PolicyCompiler,PolicyComposer,Util,Validator};

// Bloqueo del cambio manual de la hora (decision 2026-10-20, agente 4.0.28): time_change_lock en la politica. Gana el
// alcance mas cercano; solo viaja con true y solo a agentes >= 4.0.28 (los anteriores rechazan campos desconocidos);
// editar sin el campo lo conserva; enviarlo exige admin de toda la empresa.
function timeChangeLockTests(Database $db): void
{
    $before = $GLOBALS['checks'];

    // 1) Composicion pura.
    $at = fn (string $scope, ?bool $lock, int $priority = 0) => ['id' => Util::uuid(), 'scope' => $scope, 'priority' => $priority, 'rules' => [], 'management_hosts' => [], 'time_change_lock' => $lock];
    $input = fn (array $assignments, bool $supported = true) => ['tenant_id' => Util::uuid(), 'device_id' => Util::uuid(), 'reported_version' => 0, 'functions' => [],
        'time_change_lock_supported' => $supported, 'assignments' => $assignments, 'work_schedule' => null, 'rule_schedules' => []];
    $lock = fn (array $assignments, bool $supported = true) => PolicyComposer::compose($input($assignments, $supported))->time_change_lock ?? null;
    check($lock([$at('tenant', null)]) === null, 'sin el campo: no viaja (desbloqueado)');
    check($lock([$at('tenant', true)]) === true, 'empresa con bloqueo: viaja true');
    check($lock([$at('tenant', true), $at('device', false)]) === null, 'el equipo (mas cercano) lo apaga: no viaja');
    check($lock([$at('tenant', false), $at('user', true)]) === true, 'la persona lo enciende sobre la empresa');
    check($lock([$at('device', false, 0), $at('device', true, 1)]) === true && $lock([$at('device', true, 0), $at('device', false, 1)]) === null, 'mismo alcance: mayor prioridad');
    check($lock([$at('site', false, 3), $at('site', true, 3)]) === true && $lock([$at('site', true, 3), $at('site', false, 3)]) === true, 'empate: true');
    check($lock([$at('tenant', true)], false) === null, 'agente sin soporte: nunca recibe el campo');
    $doc = PolicyComposer::versioned(PolicyComposer::compose($input([$at('tenant', true)])), 1);
    (new Validator())->named('EffectivePolicy', $doc);
    check($doc->time_change_lock === true, 'documento valido contra EffectivePolicy');
    $same = PolicyComposer::compose($input([$at('tenant', null)]));
    check(PolicyComposer::canonical($same) !== PolicyComposer::canonical(PolicyComposer::compose($input([$at('tenant', true)]))), 'entra al hash de composicion');
    PolicyComposer::compose($input([$at('tenant', true), $at('device', false)]), $prov, $explain);
    check($explain['time_change_lock']['value'] === false && $explain['time_change_lock']['level'] === 'device', 'explain dice que gano y desde donde');

    // 2) Compilador con equipo real: 4.0.27 no lo recibe; 4.0.28 si; el equipo piloto lo enciende solo para el.
    $u = secondTenant($db); $t = $u['tenant_id'];
    $key = keypair(); $token = enroll($db, $u, $key); $device = Util::bin($token['device_id']);
    $other = enroll($db, adminMember($db, $u), keypair()); $otherDevice = Util::bin($other['device_id']);
    $version = fn (string $v) => $db->run('UPDATE devices SET agent_version=? WHERE tenant_id=?', [$v, $t]);
    $publish = function (?bool $lockValue) use ($db, $t): string {
        $id = Util::bin(Util::uuid());
        $db->run("INSERT INTO policy_documents (tenant_id,id,name) VALUES (?,?,'Piloto bloqueo de hora')", [$t, $id]);
        $json = PolicyComposer::canonical((object) (['rules' => []] + ($lockValue === null ? [] : ['time_change_lock' => $lockValue])));
        $db->run('INSERT INTO policy_versions (tenant_id,policy_id,policy_version,document,content_hash) VALUES (?,?,1,?,?)', [$t, $id, $json, hash('sha256', $json, true)]);
        return $id;
    };
    $pilot = $publish(true);
    $db->run("INSERT INTO policy_assignments (tenant_id,id,policy_id,policy_version,scope,device_id,priority) VALUES (?,?,?,1,'device',?,0)", [$t, Util::bin(Util::uuid()), $pilot, $device]);
    $compiler = new PolicyCompiler($db);
    $current = function (string $dev) use ($db, $t): object {
        $row = $db->one('SELECT document FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1', [$t, $dev]);
        $doc = json_decode($row['document'], false, 64, JSON_THROW_ON_ERROR);
        (new Validator())->named('EffectivePolicy', $doc);
        return $doc;
    };
    $version('4.0.27');
    $compiler->recompile($token['device_id']); $compiler->recompile($other['device_id']);
    check(!property_exists($current($device), 'time_change_lock'), '4.0.27 nunca recibe time_change_lock');
    $version('4.0.28');
    $r = $compiler->recompile($token['device_id']);
    check($r['changed'] && $current($device)->time_change_lock === true, '4.0.28: el equipo piloto recibe true y sube la version');
    $compiler->recompile($other['device_id']);
    check(!property_exists($current($otherDevice), 'time_change_lock'), 'otro equipo del mismo cliente no se bloquea');
    check(!$compiler->recompile($token['device_id'])['changed'], 'recompilar sin cambios no publica otra version');

    // Lo que entrega el sync al agente piloto.
    $sync = expect(request('POST', '/client/sync', ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => null, 'release_id' => null], $key, $token['access_token'], Util::uuid()), 200, 'sync del piloto');
    $delivered = $sync['policy'] ?? expect(request('GET', '/client/policy', null, $key, $token['access_token']), 200, 'politica del piloto');
    check(($delivered['time_change_lock'] ?? null) === true, 'el sync entrega time_change_lock al piloto');

    // Un documento publicado con un valor no booleano no compila (no se envia basura al agente).
    $bad = Util::bin(Util::uuid());
    $db->run("INSERT INTO policy_documents (tenant_id,id,name) VALUES (?,?,'mala')", [$t, $bad]);
    $json = '{"rules":[],"time_change_lock":"si"}';
    $db->run('INSERT INTO policy_versions (tenant_id,policy_id,policy_version,document,content_hash) VALUES (?,?,1,?,?)', [$t, $bad, $json, hash('sha256', $json, true)]);
    $assignment = Util::bin(Util::uuid());
    $db->run("INSERT INTO policy_assignments (tenant_id,id,policy_id,policy_version,scope,priority) VALUES (?,?,?,1,'tenant',0)", [$t, $assignment, $bad]);
    try { $compiler->recompile($token['device_id']); check(false, 'time_change_lock no booleano rechazado'); }
    catch (\RuntimeException $e) { check(str_contains($e->getMessage(), 'time_change_lock'), 'time_change_lock no booleano rechazado'); }
    $db->run('DELETE FROM policy_assignments WHERE tenant_id=? AND id=?', [$t, $assignment]);

    // 3) API de administracion: crear/editar con el campo, conservarlo al editar sin el, y solo admin de toda la empresa.
    accessResetPreloginQuota();
    $admin = adminFixture($db, adminMember($db, $u), ['reglas.ver', 'reglas.editar', 'usuarios.ver', 'equipos.ver']);
    $body = ['name' => 'Bloqueo de hora del equipo', 'target_type' => 'device', 'target_id' => $other['device_id'], 'enabled' => true, 'rules' => [], 'time_change_lock' => true];
    $policy = expect(adminRequest($admin, 'POST', '/policies', $body), 201, 'crear politica con time_change_lock');
    check(($policy['time_change_lock'] ?? null) === true, 'la politica devuelve time_change_lock');
    check(($current($otherDevice)->time_change_lock ?? null) === true, 'guardar recompila y el equipo lo recibe');
    unset($body['time_change_lock']); $body['name'] = 'Bloqueo de hora (renombrada desde el panel)';
    $policy = expect(adminRequest($admin, 'PATCH', '/policies/' . $policy['id'], $body, ['if-match' => '"' . $policy['version'] . '"']), 200, 'editar sin el campo');
    check(($policy['time_change_lock'] ?? null) === true && ($current($otherDevice)->time_change_lock ?? null) === true, 'editar sin el campo lo conserva');
    $body['time_change_lock'] = false;
    $policy = expect(adminRequest($admin, 'PATCH', '/policies/' . $policy['id'], $body, ['if-match' => '"' . $policy['version'] . '"']), 200, 'apagarlo');
    check(($policy['time_change_lock'] ?? null) === false && !property_exists($current($otherDevice), 'time_change_lock'), 'false lo apaga: el campo deja de viajar');
    $body['time_change_lock'] = 'si';
    expect(adminRequest($admin, 'PATCH', '/policies/' . $policy['id'], $body, ['if-match' => '"' . $policy['version'] . '"']), 422, 'solo booleano');
    $scoped = adminFixture($db, adminMember($db, $u), ['reglas.ver', 'reglas.editar', 'usuarios.ver', 'equipos.ver'], false, 'area', [$u['area_id']]);
    $scopedBody = ['name' => 'Regla de la persona', 'target_type' => 'user', 'target_id' => Util::id($u['id']), 'enabled' => true, 'rules' => []];
    expect(adminRequest($scoped, 'POST', '/policies', $scopedBody + ['time_change_lock' => true]), 403, 'admin con alcance parcial no lo cambia');
    expect(adminRequest($scoped, 'POST', '/policies', $scopedBody), 201, 'admin con alcance parcial sigue creando reglas sin el campo');
    echo 'PASS time change lock (' . ($GLOBALS['checks'] - $before) . " assertions)\n";
}
