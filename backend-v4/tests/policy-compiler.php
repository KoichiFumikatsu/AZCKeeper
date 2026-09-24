<?php
declare(strict_types=1);

use Keeper\{Database, PolicyCompiler, PolicyComposer, PolicyConflict, Util, Validator};

function compilerRule(string $effect = 'deny', array $targets = ['example.test'], int $priority = 0, string $kind = 'web', ?string $schedule = null): object
{
    return (object) ['id' => Util::uuid(), 'kind' => $kind, 'effect' => $effect, 'targets' => $targets, 'schedule_id' => $schedule, 'priority' => $priority];
}

function compilerPublish(Database $db, string $tenant, array $rules, ?string $id = null, int $version = 1, array $hosts = []): string
{
    $id ??= Util::bin(Util::uuid());
    if ($version === 1) { $db->run("INSERT INTO policy_documents (tenant_id,id,name) VALUES (?,?,'compiler fixture')", [$tenant, $id]); }
    $json = PolicyComposer::canonical((object) ['rules' => $rules, 'management_hosts' => $hosts]);
    $db->run('INSERT INTO policy_versions (tenant_id,policy_id,policy_version,document,content_hash) VALUES (?,?,?,?,?)', [$tenant, $id, $version, $json, hash('sha256', $json, true)]);
    foreach ($rules as $rule) {
        $db->run('INSERT INTO policy_rules (tenant_id,id,policy_id,policy_version,kind,effect,schedule_id,priority,targets) VALUES (?,?,?,?,?,?,?,?,?)', [$tenant, Util::bin($rule->id), $id, $version, $rule->kind, $rule->effect, $rule->schedule_id === null ? null : Util::bin($rule->schedule_id), $rule->priority, Util::json($rule->targets)]);
    }
    return $id;
}

function compilerAssign(Database $db, string $tenant, string $policy, string $scope, ?string $target = null, int $priority = 100): string
{
    $id = Util::bin(Util::uuid());
    $field = ['area' => 'area_id', 'site' => 'site_id', 'user' => 'user_id', 'device' => 'device_id'][$scope] ?? null;
    $db->run('INSERT INTO policy_assignments (tenant_id,id,policy_id,policy_version,scope,priority' . ($field ? ',' . $field : '') . ') VALUES (?,?,?,1,?,?' . ($field ? ',?' : '') . ')', array_merge([$tenant, $id, $policy, $scope, $priority], $field ? [$target] : []));
    return $id;
}

function compilerDocument(Database $db, array $result): object
{
    $row = $db->one('SELECT * FROM effective_policies WHERE tenant_id=? AND device_id=? AND policy_version=?', [Util::bin($result['tenant_id']), Util::bin($result['device_id']), $result['policy_version']]);
    $doc = json_decode($row['document'], false, 64, JSON_THROW_ON_ERROR);
    (new Validator())->named('EffectivePolicy', $doc);
    check(hash_equals($row['content_hash'], hash('sha256', PolicyComposer::canonical($doc), true)), 'content hash covers the stored wire document');
    $composition = clone $doc; unset($composition->version, $composition->etag);
    check(hash_equals($row['composition_hash'], hash('sha256', PolicyComposer::canonical($composition), true)), 'composition hash excludes only version and ETag');
    return $doc;
}

function policyCompilerTests(Database $db): void
{
    $user = secondTenant($db); $tenant = $user['tenant_id']; $tenantId = Util::id($tenant);
    $db->run("INSERT INTO tier (tenant_id,id,name,badge_label) VALUES (?,?,'Compiler tier','Compiler tier')", [$tenant, $tier = Util::bin(Util::uuid())]);
    $db->run("INSERT INTO tier_module (tenant_id,tier_id,module_code) VALUES (?,?,'policies')", [$tenant, $tier]);
    $db->run("INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6)-INTERVAL 1 DAY)", [$tenant, $subscription = Util::bin(Util::uuid()), $user['id'], $tier]);
    foreach ([5, 1, 3] as $day) { $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)', [$tenant, $user['schedule_id'], $day]); }
    $key = keypair(); $token = enroll($db, $user, $key); $device = Util::bin($token['device_id']);
    $compiler = new PolicyCompiler($db);
    $platform = Util::bin('00000000-0000-4000-8000-000000000001');
    $assignments = []; $rules = []; $policies = [];
    foreach (['global', 'tenant', 'site', 'area', 'user', 'device'] as $scope) {
        $owner = $scope === 'global' ? $platform : $tenant;
        $rules[$scope] = compilerRule(in_array($scope, ['area', 'device'], true) ? 'allow' : 'deny', ['example.test', $scope . '.test']);
        $policies[$scope] = compilerPublish($db, $owner, [$rules[$scope]], hosts: ['management.example.test']);
        $target = match ($scope) { 'area' => $user['area_id'], 'site' => $user['site_id'], 'user' => $user['id'], 'device' => $device, default => null };
        $assignments[$scope] = compilerAssign($db, $owner, $policies[$scope], $scope, $target);
    }
    $initial = $compiler->recompile($token['device_id']);
    check($initial['changed'] && is_int($initial['policy_version']), 'initial compilation publishes integer version');
    $doc = compilerDocument($db, $initial);
    check($doc->rules[0]->id === $rules['device']->id && $doc->rules[0]->effect === 'allow', 'device beats every scope');
    check(array_column($doc->composition, 'level') === ['platform', 'tenant', 'site', 'area', 'user', 'device'], 'composition follows platform tenant site area user device');
    check($doc->schedules[0]->days === [1, 3, 5] && $doc->schedules[0]->start_local === '08:00' && $doc->schedules[0]->timezone === 'America/Bogota', 'work schedule and sorted weekdays applied');
    check($doc->management_hosts === ['management.example.test'], 'management hosts deduplicated');
    $policyAdmin=adminFixture($db,$user,['reglas.ver']);
    $explained=expect(adminRequest($policyAdmin,'GET','/users/'.Util::id($user['id']).'/policies'),200,'effective policy provenance endpoint');
    check(count($explained['devices'][0]['rules'])===6,'each winning scope has provenance');
    foreach ($explained['devices'][0]['rules'] as $source) {
        check($source['rule']['id']===$rules[$source['scope']]->id && in_array(Util::id($assignments[$source['scope']]),$source['assignment_ids'],true),'winning rule retains exact assignment and scope');
    }
    $audit = (int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='policy.compiled'", [$tenant])['n'];
    $same = $compiler->recompile($token['device_id']);
    check(!$same['changed'] && $same['policy_version'] === $initial['policy_version'] && $same['hash'] === $initial['hash'], 'unchanged compilation keeps version and hash');
    check((int) $db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='policy.compiled'", [$tenant])['n'] === $audit, 'no-op compilation creates no audit row');
    compilerPublish($db, $tenant, [$rules['device']], $policies['device'], 2, ['management.example.test']);
    $db->run('UPDATE policy_assignments SET policy_version=2 WHERE tenant_id=? AND id=?', [$tenant, $assignments['device']]);
    check(!$compiler->recompile($token['device_id'])['changed'], 'identical source publication does not change effective version');

    $sync = ['protocol_version' => 1, 'sequence' => 1, 'policy_version' => null, 'release_id' => null];
    $response = expect(request('POST', '/client/sync', $sync, $key, $token['access_token'], Util::uuid()), 200, 'compiled policy sync');
    check(PolicyComposer::canonical($response['policy']) === PolicyComposer::canonical($doc), 'sync returns exact materialized EffectivePolicy');
    check($response['policy_version'] === $initial['policy_version'] && $response['policy']['version'] === (string) $initial['policy_version'], 'integer sync version and OpenAPI string document version');
    $sync['policy_version'] = $initial['policy_version']; $sync['sequence']++;
    check(expect(request('POST', '/client/sync', $sync, $key, $token['access_token'], Util::uuid()), 200, 'compiled unchanged sync')['policy'] === null, 'compiled version match omits policy');
    $get = request('GET', '/client/policy', null, $key, $token['access_token']);
    check(PolicyComposer::canonical(expect($get, 200, 'compiled policy GET')) === PolicyComposer::canonical($doc), 'GET returns stored compiled document');
    expect(request('GET', '/client/policy', null, $key, $token['access_token'], null, null, ['if-none-match' => $doc->etag]), 304, 'compiled ETag conditional GET');
    expect(request('GET', '/client/sync', null, $key, $token['access_token']), 404, 'sync remains POST-only');

    $last = $initial;
    foreach (['device' => 'user', 'user' => 'area', 'area' => 'site', 'site' => 'tenant', 'tenant' => 'global'] as $disable => $winner) {
        $db->run('UPDATE policy_assignments SET enabled=FALSE WHERE tenant_id=? AND id=?', [$tenant, $assignments[$disable]]);
        $next = $compiler->recompile($token['device_id']);
        check($next['changed'] && $next['policy_version'] === $last['policy_version'] + 1, 'real scope change increments exactly once');
        $scopeDoc = compilerDocument($db, $next);
        check($scopeDoc->rules[0]->id === $rules[$winner]->id, $winner . ' wins after removing ' . $disable);
        if ($winner === 'area') { check($scopeDoc->rules[0]->effect === 'allow' && in_array('example.test', $scopeDoc->rules[0]->targets, true), 'area allow beats overlapping site deny'); }
        $last = $next;
    }
    $sync['policy_version'] = $initial['policy_version']; $sync['sequence']++;
    check(expect(request('POST', '/client/sync', $sync, $key, $token['access_token'], Util::uuid()), 200, 'changed compiled sync')['policy_version'] === $last['policy_version'], 'sync observes new compiled version');
    $high = compilerRule('allow', ['example.test'], 10000);
    $low = compilerRule('deny', ['example.test', 'other.test'], 0);
    $lowPolicy = compilerPublish($db, $tenant, [$low]);
    compilerAssign($db, $tenant, $lowPolicy, 'device', $device, 501);
    $highPolicy = compilerPublish($db, $tenant, [$high]);
    $highAssignment = compilerAssign($db, $tenant, $highPolicy, 'device', $device, 500);
    $priorityDoc = compilerDocument($db, $compiler->recompile($token['device_id']));
    check($priorityDoc->rules[0]->id === $high->id && $priorityDoc->rules[1]->targets === ['other.test'], 'highest explicit rule priority wins across assignments; unrelated targets survive');
    check($priorityDoc->rules[0]->priority === 10000 && $priorityDoc->rules[1]->priority === 0, 'configured rule priorities preserved in output');
    try { compilerAssign($db, $tenant, $highPolicy, 'device', $device, 501); throw new RuntimeException('Missing priority conflict'); }
    catch (PDOException $e) { check($e->getCode() === '23000', 'schema rejects equal assignment priorities for same target'); }
    $tieA = compilerRule('deny', ['tie.test'], 40); $tieB = compilerRule('allow', ['tie.test'], 40);
    $tieA->id = '20000000-0000-4000-8000-000000000001'; $tieB->id = '20000000-0000-4000-8000-000000000002';
    $tiePolicy = compilerPublish($db, $tenant, [$tieB, $tieA]);
    $tieAssignment = compilerAssign($db, $tenant, $tiePolicy, 'device', $device, 502);
    $storedBefore = $db->run('SELECT * FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version', [$tenant, $device])->fetchAll();
    $countersBefore = [$db->one('SELECT policy_version FROM tenants WHERE tenant_id=?', [$tenant]), $db->one('SELECT policy_version FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device])];
    $conflictMessage = null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try { $compiler->recompile($token['device_id']); throw new RuntimeException('Missing rule conflict rejection'); }
        catch (PolicyConflict $e) {
            check(str_starts_with($e->getMessage(), 'policy_conflict:') && str_contains($e->getMessage(), $tieA->id) && str_contains($e->getMessage(), $tieB->id), 'conflict identifies both rules');
            check($conflictMessage === null || $conflictMessage === $e->getMessage(), 'conflict diagnostic deterministic');
            $conflictMessage = $e->getMessage();
        }
        if ($attempt === 0) {
            compilerPublish($db, $tenant, [$tieA, $tieB], $tiePolicy, 2);
            $db->run('UPDATE policy_assignments SET policy_version=2 WHERE tenant_id=? AND id=?', [$tenant, $tieAssignment]);
        }
    }
    check($db->run('SELECT * FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version', [$tenant, $device])->fetchAll() === $storedBefore, 'conflict leaves every prior policy byte intact and publishes no version');
    check([$db->one('SELECT policy_version FROM tenants WHERE tenant_id=?', [$tenant]), $db->one('SELECT policy_version FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device])] === $countersBefore, 'conflict leaves tenant and device counters intact');
    $conflictAudits = $db->run("SELECT outcome,reason,changed_fields,actor_type,resource_id FROM audit_log WHERE tenant_id=? AND action='policy.conflict'", [$tenant])->fetchAll();
    check(count($conflictAudits) === 2, 'each rejected compilation commits a conflict audit');
    foreach ($conflictAudits as $entry) {
        check($entry['outcome'] === 'denied' && $entry['reason'] === $conflictMessage && $entry['changed_fields'] === '[]' && $entry['actor_type'] === 'system' && $entry['resource_id'] === $device, 'audit identifies device, system actor and exact conflict without changes');
    }
    $preserved = expect(request('GET', '/client/policy', null, $key, $token['access_token']), 200, 'prior policy remains available after conflict');
    check(PolicyComposer::canonical($preserved) === PolicyComposer::canonical($priorityDoc), 'GET still serves last valid policy after conflict');
    $db->run('UPDATE policy_assignments SET enabled=FALSE WHERE tenant_id=? AND id=?', [$tenant, $tieAssignment]);
    check(!$compiler->recompile($token['device_id'])['changed'], 'removing conflict reuses prior valid version');

    $db->run("DELETE FROM tier_module WHERE tenant_id=? AND tier_id=? AND module_code='policies'", [$tenant, $tier]);
    check(compilerDocument($db, $compiler->recompile($token['device_id']))->rules === [], 'module outside tier removed');
    $db->run("INSERT INTO firma_module_override (tenant_id,firm_id,module_code,enabled,reason) VALUES (?,?,'policies',TRUE,'test')", [$tenant, $user['firm_id']]);
    check(!$compiler->recompile($token['device_id'])['changed'], 'positive firm override cannot grant outside tier');
    $db->run("INSERT INTO tier_module (tenant_id,tier_id,module_code) VALUES (?,?,'policies')", [$tenant, $tier]);
    check(count(compilerDocument($db, $compiler->recompile($token['device_id']))->rules) > 0, 'tier and positive override permit rules');
    $db->run("UPDATE firma_module_override SET enabled=FALSE WHERE tenant_id=? AND firm_id=? AND module_code='policies'", [$tenant, $user['firm_id']]);
    check(compilerDocument($db, $compiler->recompile($token['device_id']))->rules === [], 'negative firm override vetoes tier');
    $db->run("UPDATE firma_module_override SET enabled=TRUE WHERE tenant_id=? AND firm_id=? AND module_code='policies'", [$tenant, $user['firm_id']]);
    $db->run('UPDATE tier SET active=FALSE WHERE tenant_id=? AND id=?', [$tenant, $tier]);
    check(!$compiler->recompile($token['device_id'])['changed'], 'inactive tier cannot grant policies');
    $db->run('UPDATE tier SET active=TRUE WHERE tenant_id=? AND id=?', [$tenant, $tier]);
    $db->run("UPDATE subscriptions SET status='cancelled' WHERE tenant_id=? AND id=?", [$tenant, $subscription]);
    check(!$compiler->recompile($token['device_id'])['changed'], 'cancelled subscription cannot grant policies');
    $db->run("UPDATE subscriptions SET status='active',ends_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND WHERE tenant_id=? AND id=?", [$tenant, $subscription]);
    check(!$compiler->recompile($token['device_id'])['changed'], 'expired subscription cannot grant policies');
    $db->run('UPDATE subscriptions SET ends_at=NULL WHERE tenant_id=? AND id=?', [$tenant, $subscription]);

    $night = Util::bin(Util::uuid());
    $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'Night','Asia/Tokyo','22:00:00','06:00:00')", [$tenant, $night]);
    $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,7)', [$tenant, $night]);
    $db->run('UPDATE user_assignments SET schedule_id=? WHERE tenant_id=? AND user_id=?', [$night, $tenant, $user['id']]);
    $nightResult = $compiler->recompile($token['device_id']); $nightDoc = compilerDocument($db, $nightResult);
    check($nightDoc->schedules[0]->id === Util::id($night) && $nightDoc->schedules[0]->end_local === '06:00', 'current assignment overrides stale users schedule; overnight preserved');
    $db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,6)', [$tenant, $night]);
    check($compiler->recompile($token['device_id'])['policy_version'] === $nightResult['policy_version'] + 1, 'schedule content change increments even without schedule revision bump');
    $conditional = compilerRule('deny', ['conditional.test'], 0, 'web', Util::id($user['schedule_id']));
    compilerAssign($db, $tenant, compilerPublish($db, $tenant, [$conditional]), 'device', $device, 503);
    $conditionalDoc = compilerDocument($db, $compiler->recompile($token['device_id']));
    check(count($conditionalDoc->schedules) === 2 && $conditionalDoc->schedules[1]->id === $conditional->schedule_id, 'winning rule schedule copied alongside work schedule');

    $capRules = []; $capScheduleIds = [$conditional->schedule_id]; $capHosts = []; $capAssignments = [];
    for ($i = 0; $i < 105; $i++) {
        $scheduleId = Util::uuid(); $capScheduleIds[] = $scheduleId;
        $db->run("INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,'Cap fixture','UTC','08:00:00','17:00:00')", [$tenant, Util::bin($scheduleId)]);
        $capRules[] = compilerRule('deny', ['cap-' . $i . '.test'], $i, 'web', $scheduleId);
    }
    for ($i = 0; $i < 45; $i++) { $capHosts[] = sprintf('host-%02d.test', $i); }
    foreach (array_chunk($capRules, 35) as $i => $chunk) {
        $hosts = array_slice($capHosts, $i * 15, 15); $hosts[] = 'management.example.test';
        $capAssignments[] = compilerAssign($db, $tenant, compilerPublish($db, $tenant, $chunk, hosts: $hosts), 'device', $device, 600 + $i);
    }
    $capResult = $compiler->recompile($token['device_id']); $capDoc = compilerDocument($db, $capResult);
    check($capDoc->management_hosts === array_slice($capHosts, 0, 30), 'union above 30 hosts deduplicates and caps lexically without aborting');
    sort($capScheduleIds, SORT_STRING);
    check(array_column($capDoc->schedules, 'id') === array_merge([Util::id($night)], array_slice($capScheduleIds, 0, 99)), 'union above 100 schedules caps lexically and reserves work schedule');
    check(count($capDoc->rules) === count($conditionalDoc->rules) + 105, 'schedule cap does not truncate rules');
    check(!$compiler->recompile($token['device_id'])['changed'], 'capped unions recompile without a new version');
    foreach ($capAssignments as $id) { $db->run('UPDATE policy_assignments SET enabled=FALSE WHERE tenant_id=? AND id=?', [$tenant, $id]); }

    $beforeFailure = $compiler->recompile($token['device_id']);
    $broken = compilerPublish($db, $tenant, []);
    $brokenRule = compilerRule();
    $db->run("INSERT INTO policy_rules (tenant_id,id,policy_id,policy_version,kind,effect,priority,targets) VALUES (?,?,?,1,'web','deny',0,?)", [$tenant, Util::bin($brokenRule->id), $broken, Util::json($brokenRule->targets)]);
    $brokenAssignment = compilerAssign($db, $tenant, $broken, 'device', $device, 504);
    try { $compiler->recompile($token['device_id']); throw new RuntimeException('Missing inconsistent publication rejection'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'Published document and policy_rules disagree', 'inconsistent source publication rejected'); }
    $db->run('UPDATE policy_assignments SET enabled=FALSE WHERE tenant_id=? AND id=?', [$tenant, $brokenAssignment]);
    check($compiler->recompile($token['device_id'])['policy_version'] === $beforeFailure['policy_version'], 'failed compilation preserves prior version');
    $beforeTenant = $db->one('SELECT policy_version FROM tenants WHERE tenant_id=?', [$tenant]);
    $beforeDevice = $db->one('SELECT policy_version FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device]);
    $batch = $compiler->recompileForTenant($tenantId);
    check(count($batch) === 1 && !$batch[0]['changed'], 'tenant recompilation selects its active devices');
    check($db->one('SELECT policy_version FROM tenants WHERE tenant_id=?', [$tenant]) === $beforeTenant && $db->one('SELECT policy_version FROM devices WHERE tenant_id=? AND id=?', [$tenant, $device]) === $beforeDevice, 'compiler leaves tenant and device counters untouched');
    try { $compiler->recompile($token['device_id'], Util::id($platform)); throw new RuntimeException('Missing tenant isolation'); }
    catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'absent or ambiguous'), 'explicit tenant cannot select foreign device'); }

    $input = ['tenant_id' => $tenantId, 'device_id' => $token['device_id'], 'modules' => ['policies'], 'assignments' => [
        ['id' => Util::uuid(), 'scope' => 'global', 'priority' => 9, 'rules' => [$low], 'management_hosts' => ['b.test', 'a.test']],
        ['id' => Util::uuid(), 'scope' => 'device', 'priority' => 0, 'rules' => [$high], 'management_hosts' => ['a.test']],
    ], 'work_schedule' => null, 'rule_schedules' => []];
    $pure = PolicyComposer::canonical(PolicyComposer::compose($input));
    $input['assignments'] = array_reverse($input['assignments']);
    $input['assignments'][1]['rules'][0] = clone $low;
    $input['assignments'][1]['rules'][0]->targets = ['other.test', 'example.test', 'other.test'];
    check(PolicyComposer::canonical(PolicyComposer::compose($input)) === $pure, 'pure composition ignores SQL order, target order and duplicates');
    check($low->targets === ['example.test', 'other.test'], 'pure composer does not mutate inputs');

    $compatible = compilerRule('allow', ['compatible.test'], 40);
    $required = compilerRule('require', ['compatible.test'], 40);
    $input['assignments'] = [
        ['id' => Util::uuid(), 'scope' => 'device', 'priority' => 0, 'rules' => [$compatible, $compatible, $required], 'management_hosts' => []],
    ];
    $compatibleDoc = PolicyComposer::compose($input);
    check(count($compatibleDoc->rules) === 2, 'identical rules deduplicate and compatible effects coexist');
    $input['assignments'][0]['rules'] = [$tieA, compilerRule('allow', ['tie.test'], 41)];
    $input['assignments'][] = ['id' => Util::uuid(), 'scope' => 'device', 'priority' => 999, 'rules' => [$tieB], 'management_hosts' => []];
    $crossAssignmentMessage = null;
    for ($attempt = 0; $attempt < 2; $attempt++) {
        try { PolicyComposer::compose($input); throw new RuntimeException('Missing cross-assignment conflict'); }
        catch (PolicyConflict $e) {
            check($crossAssignmentMessage === null || $crossAssignmentMessage === $e->getMessage(), 'cross-assignment conflict ignores input order and higher-priority masking');
            $crossAssignmentMessage = $e->getMessage();
        }
        $input['assignments'] = array_reverse($input['assignments']);
        foreach ($input['assignments'] as &$a) { $a['rules'] = array_reverse($a['rules']); }
        unset($a);
    }
    $input['assignments'] = [];
    $input['rule_schedules'] = [];
    foreach ($capRules as $i => $rule) {
        $schedule = clone $conditionalDoc->schedules[0]; $schedule->id = $rule->schedule_id;
        $input['rule_schedules']['tenant/' . $schedule->id] = $schedule;
    }
    foreach (array_chunk($capRules, 35) as $i => $chunk) {
        $input['assignments'][] = ['id' => Util::uuid(), 'scope' => 'device', 'priority' => $i, 'rules' => $chunk, 'management_hosts' => array_slice($capHosts, $i * 15, 15)];
    }
    $pureCapDoc = PolicyComposer::compose($input);
    check(count($pureCapDoc->schedules) === 100 && count($pureCapDoc->management_hosts) === 30, 'caps also hold without work schedule');
    $input['assignments'] = array_reverse($input['assignments']);
    foreach ($input['assignments'] as &$a) { $a['rules'] = array_reverse($a['rules']); $a['management_hosts'] = array_reverse($a['management_hosts']); }
    unset($a);
    check(PolicyComposer::canonical(PolicyComposer::compose($input)) === PolicyComposer::canonical($pureCapDoc), 'capped document is invariant under assignment rule and host ordering');

    $otherUser = Util::bin(Util::uuid());
    $db->run("INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) SELECT tenant_id,?,'Second compiler member',firm_id,site_id,area_id,position_id,schedule_id FROM users WHERE tenant_id=? AND id=?", [$otherUser, $tenant, $user['id']]);
    $withoutDevice=expect(adminRequest($policyAdmin,'GET','/users/'.Util::id($otherUser).'/policies'),200,'policy resolution without a device');
    check($withoutDevice['devices'][0]['device_id']===null && $withoutDevice['devices'][0]['policy']['device_id']===null,'no invented device in person policy');
    $boundary = $db->one('SELECT UTC_TIMESTAMP(6)+INTERVAL 1 MINUTE instant')['instant'];
    $db->run('UPDATE device_assignments SET ends_at=? WHERE tenant_id=? AND device_id=?', [$boundary, $tenant, $device]);
    $db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,?,'compiler reassignment')", [$tenant, Util::bin(Util::uuid()), $device, $otherUser, $boundary]);
    $assigned = $compiler->recompile($token['device_id'], $tenantId, new DateTimeImmutable($boundary, new DateTimeZone('UTC')));
    $assignedDoc = compilerDocument($db, $assigned);
    check($assignedDoc->rules === [] && $assignedDoc->schedules[0]->id === Util::id($user['schedule_id']), 'device assignment boundary selects new member tier and schedule despite stale devices.user_id');
    check($assigned['policy_version'] > $beforeFailure['policy_version'], 'reassignment never resets version');
    $compiler->recompile($token['device_id']);

    $db->run("UPDATE schedules SET end_local='07:00:00' WHERE tenant_id=? AND id=?", [$tenant, $night]);
    $db->pdo->beginTransaction();
    $db->one('SELECT tenant_id FROM tenants WHERE tenant_id=? FOR UPDATE', [$tenant]);
    $workers = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/config/compile.php', '--tenant=' . $tenantId, '--device=' . $token['device_id']], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) { throw new RuntimeException('Cannot start compiler worker'); }
            fclose($pipes[0]); $workers[] = [$process, $pipes];
        }
        $db->pdo->commit();
        $results = [];
        foreach ($workers as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            check(proc_close($process) === 0 && $error === '', 'compiler CLI worker succeeds');
            $results[] = json_decode(explode("\n", trim($output))[0], true, 64, JSON_THROW_ON_ERROR);
        }
        $workers = [];
        check($results[0]['policy_version'] === $results[1]['policy_version'] && $results[0]['hash'] === $results[1]['hash'], 'concurrent compilers converge on one version and hash');
        check((int) $results[0]['changed'] + (int) $results[1]['changed'] === 1, 'concurrent recompilation publishes exactly once');
    } finally {
        if ($db->pdo->inTransaction()) { $db->pdo->rollBack(); }
        foreach ($workers as [$process, $pipes]) {
            if (is_resource($process)) { proc_terminate($process); }
            foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
            if (is_resource($process)) { proc_close($process); }
        }
    }
    $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/config/compile.php', '--all'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    check(proc_close($process) === 0 && $error === '', 'CLI --all succeeds on all smoke tenants');
    $lines = explode("\n", trim($output)); $summary = json_decode(end($lines), true, 64, JSON_THROW_ON_ERROR);
    check($summary['compiled'] >= 3, 'CLI --all covers multiple tenants');
    $db->run('UPDATE policy_assignments SET enabled=FALSE WHERE tenant_id=? AND id=?', [$platform, $assignments['global']]);
    echo "PASS policy compiler precedence, tier, schedule, hashes, versioning and signed sync\n";
}
