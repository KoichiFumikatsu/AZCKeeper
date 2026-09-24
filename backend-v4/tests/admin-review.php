<?php
declare(strict_types=1);

use Keeper\{AdminApi, ApiError, Database, Request, Util, Validator};

final class AdminReviewStatement extends PDOStatement
{
    public static ?Closure $before = null;
    public static array $queries = [];
    public function execute(?array $params = null): bool
    {
        self::$queries[] = $this->queryString;
        if (self::$before !== null) { (self::$before)($this->queryString); }
        return parent::execute($params);
    }
}

function adminReviewRequest(string $route, string $method, ?string $id = null, mixed $body = null, array $headers = []): Request
{
    $reflection = new ReflectionClass(Request::class);
    $r = $reflection->newInstanceWithoutConstructor();
    foreach (['operation' => (new Validator())->contract['paths'][$route][strtolower($method)], 'resourceId' => $id,
        'body' => $body, 'headers' => $headers, 'method' => $method, 'path' => '/v1' . $route, 'requestId' => Util::uuid()] as $field => $value) {
        $reflection->getProperty($field)->setValue($r, $value);
    }
    return $r;
}

function adminReviewTests(Database $db, array $admin, array $base, array $memberBody, string $device): void
{
    $tenant = $base['tenant_id'];
    $member = expect(adminRequest($admin, 'POST', '/users', $memberBody), 201, 'review member');
    foreach ([['area_id' => null], ['site_id' => null], ['area_id' => null, 'site_id' => null]] as $nulls) {
        expect(adminRequest($admin, 'POST', '/users', array_replace($memberBody, $nulls)), 422, 'null create preserves HTTP contract without 500');
        expect(adminRequest($admin, 'PATCH', '/users/' . $member['id'], $nulls, ['if-match' => '"1"']), 422, 'null patch preserves HTTP contract without 500');
    }

    $siteUser = adminMember($db, $base);
    $siteAdmin = adminFixture($db, $siteUser, ['usuarios.ver', 'usuarios.crear', 'usuarios.editar'], false, 'site', [$base['site_id']]);
    $outside = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','Review outside area')", [$tenant, $outside]);
    $outsideSite = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'site','Review outside site')", [$tenant, $outsideSite]);
    $areaAdmin = adminFixture($db, adminMember($db, $base), ['usuarios.ver', 'usuarios.crear', 'usuarios.editar'], false, 'area', [$base['area_id']]);
    foreach ([[$siteAdmin, 'site', $outsideSite], [$areaAdmin, 'area', $outside]] as [$scopedAdmin, $kind, $outsideId]) {
        $created = expect(adminRequest($scopedAdmin, 'POST', '/users', $memberBody), 201, $kind . ' admin creates with both dimensions');
        expect(adminRequest($scopedAdmin, 'PATCH', '/users/' . $created['id'], ['site_id' => $memberBody['site_id'], 'area_id' => $memberBody['area_id']], ['if-match' => '"1"']), 200, $kind . ' admin patches both dimensions');
        expect(adminRequest($scopedAdmin, 'POST', '/users', array_replace($memberBody, [$kind . '_id' => Util::id($outsideId)])), 403, $kind . ' creation outside grant denied');
        expect(adminRequest($scopedAdmin, 'PATCH', '/users/' . $member['id'], [$kind . '_id' => Util::id($outsideId)], ['if-match' => '"1"']), 403, $kind . ' move outside grant denied');
    }
    expect(adminRequest($siteAdmin, 'POST', '/users', array_replace($memberBody, ['area_id' => Util::id($outside)])), 201, 'site admin accepts independent area in tenant');
    expect(adminRequest($areaAdmin, 'POST', '/users', array_replace($memberBody, ['site_id' => Util::id($outsideSite)])), 201, 'area admin accepts independent site in tenant');
    $linkedArea = Util::bin(Util::uuid());
    $db->run("INSERT INTO org_units (tenant_id,id,kind,name,parent_id) VALUES (?,?,'area','Area in another site',?)", [$tenant, $linkedArea, $outsideSite]);
    expect(adminRequest($siteAdmin, 'POST', '/users', array_replace($memberBody, ['area_id' => Util::id($linkedArea)])), 422, 'site must agree with explicit area ancestry');
    expect(adminRequest($siteAdmin, 'PATCH', '/users/' . $member['id'], ['area_id' => Util::id($linkedArea)], ['if-match' => '"1"']), 422, 'patch preserves area and site hierarchy');
    $foreign = secondTenant($db);
    expect(adminRequest($siteAdmin, 'POST', '/users', array_replace($memberBody, ['area_id' => Util::id($foreign['area_id'])])), 404, 'unscoped dimension still belongs to tenant');
    $unchanged = expect(adminRequest($admin, 'GET', '/users/' . $member['id']), 200, 'rejected scope changes persisted nothing');
    check($unchanged['version'] === 1 && $unchanged['area_id'] === $memberBody['area_id'], 'scope bypass rolls back');
    $member = expect(adminRequest($siteAdmin, 'PATCH', '/users/' . $member['id'], ['display_name' => 'Scoped rename'], ['if-match' => '"1"']), 200, 'scope edit need not redelegate unchanged area');
    $member = expect(adminRequest($siteAdmin, 'PATCH', '/users/' . $member['id'], ['site_id' => $memberBody['site_id']], ['if-match' => '"2"']), 200, 'site admin can set own site');

    $principal = $db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system'", [$tenant])['id'];
    $context = ['tenant_id' => $tenant, 'principal_id' => $principal, 'platform' => true, 'auth_revision' => 1, 'actor_type' => 'system'];
    $validator = new Validator();
    $savedOrigin = getenv('KEEPER_ORIGIN');
    try {
        putenv('KEEPER_ORIGIN=https://panel.azc.com');
        $api = new AdminApi($db, $context, adminReviewRequest('/policies', 'POST'), $validator);
        $validateRules = new ReflectionMethod(AdminApi::class, 'validateRules');
        foreach (['panel.azc.com', '*.azc.com', 'azc.com', 'AZC.COM', 'com'] as $target) {
            try { $validateRules->invoke($api, [compilerRule('deny', [$target])]); check(false, 'management host ancestor must be rejected'); }
            catch (ApiError $e) { check($e->status === 422, 'bare and wildcard host ancestors rejected'); }
        }
        foreach (['otherazc.com', 'azc.com.example.test', 'other.azc.com'] as $target) { $validateRules->invoke($api, [compilerRule('deny', [$target])]); }
        $validateRules->invoke($api, [compilerRule('allow', ['azc.com'])]);
        check(true, 'host guard preserves unrelated domains and allow rules');
    } finally { putenv($savedOrigin === false ? 'KEEPER_ORIGIN' : 'KEEPER_ORIGIN=' . $savedOrigin); }

    $parentBody = ['kind' => 'site', 'name' => 'Parent for review', 'parent_id' => null, 'active' => true];
    $parent = expect(adminRequest($admin, 'POST', '/organization', $parentBody), 201, 'review parent');
    $childBody = ['kind' => 'area', 'name' => 'Child for review', 'parent_id' => $parent['id'], 'active' => true];
    $child = expect(adminRequest($admin, 'POST', '/organization', $childBody), 201, 'review child');
    expect(adminRequest($admin, 'PATCH', '/organization/' . $parent['id'], array_replace($parentBody, ['active' => false]), ['if-match' => '"1"']), 409, 'active children prevent deactivation');
    $db->run('UPDATE org_units SET active=FALSE WHERE tenant_id=? AND id=?', [$tenant, Util::bin($parent['id'])]);
    $child = expect(adminRequest($admin, 'PATCH', '/organization/' . $child['id'], array_replace($childBody, ['name' => 'Renamed under inactive parent']), ['if-match' => '"1"']), 200, 'rename under inactive ancestor');
    $child = expect(adminRequest($admin, 'PATCH', '/organization/' . $child['id'], array_replace($childBody, ['active' => false]), ['if-match' => '"2"']), 200, 'deactivate under inactive ancestor');
    $rejected = expect(adminRequest($admin, 'PATCH', '/organization/' . $child['id'], $childBody, ['if-match' => '"3"']), 422, 'reactivation under inactive ancestor rejected');
    check($rejected['code'] === 'inactive_org_ancestor' && str_contains($rejected['detail'], 'ancestro inactivo'), 'inactive ancestry error is explanatory');
    expect(adminRequest($admin, 'POST', '/organization', $childBody), 422, 'creation under inactive ancestor rejected');
    $child = expect(adminRequest($admin, 'PATCH', '/organization/' . $child['id'], array_replace($childBody, ['parent_id' => null, 'active' => false]), ['if-match' => '"3"']), 200, 'move child out of inactive hierarchy');
    expect(adminRequest($admin, 'PATCH', '/organization/' . $child['id'], array_replace($childBody, ['active' => false]), ['if-match' => '"4"']), 422, 'reparenting inactive child under inactive ancestor rejected');
    $db->run('UPDATE org_units SET parent_id=? WHERE tenant_id=? AND id=?', [Util::bin($parent['id']), $tenant, Util::bin($child['id'])]);
    $grandchildBody = ['kind' => 'position', 'name' => 'Grandchild', 'parent_id' => $child['id'], 'active' => true];
    $db->run('UPDATE org_units SET active=TRUE WHERE tenant_id=? AND id=?', [$tenant, Util::bin($child['id'])]);
    expect(adminRequest($admin, 'POST', '/organization', $grandchildBody), 422, 'inactive grandparent also rejected');

    foreach (['area', 'site'] as $kind) {
        $unitBody = ['kind' => $kind, 'name' => 'Referenced ' . $kind, 'parent_id' => null, 'active' => true];
        $unit = expect(adminRequest($admin, 'POST', '/organization', $unitBody), 201, 'scope reference unit');
        $roleId = Util::bin(Util::uuid());
        $db->run('INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,?,?)', [$tenant, $roleId, 'Reference ' . $kind, $kind]);
        $db->run("INSERT INTO role_{$kind}_scopes (tenant_id,role_id,{$kind}_id) VALUES (?,?,?)", [$tenant, $roleId, Util::bin($unit['id'])]);
        expect(adminRequest($admin, 'PATCH', '/organization/' . $unit['id'], array_replace($unitBody, ['active' => false]), ['if-match' => '"1"']), 409, $kind . ' role scope prevents deactivation');
        $db->run("DELETE FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=?", [$tenant, $roleId]);
        expect(adminRequest($admin, 'POST', '/policies', ['name' => 'Reference policy ' . $kind, 'target_type' => $kind, 'target_id' => $unit['id'], 'enabled' => true, 'rules' => []]), 201, 'policy reference unit');
        expect(adminRequest($admin, 'PATCH', '/organization/' . $unit['id'], array_replace($unitBody, ['active' => false]), ['if-match' => '"1"']), 409, $kind . ' policy assignment prevents deactivation');
    }
    $other = new Database();
    $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [AdminReviewStatement::class]);
    try {
        foreach ([false, true] as $fresh) {
            $version = (int) $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, Util::bin($member['id'])])['version'];
            $request = adminReviewRequest('/users/{id}', 'PATCH', $member['id'], (object) ['display_name' => 'Losing edit'], ['if-match' => '"' . ($version + (int) $fresh) . '"']);
            AdminReviewStatement::$before = static function (string $sql) use ($other, $tenant, $member): void {
                if ($sql !== 'SELECT * FROM users WHERE tenant_id=? AND id=? FOR UPDATE') { return; }
                AdminReviewStatement::$before = null;
                // A second MySQL connection commits between the access snapshot and the locking read.
                $other->transaction(fn () => $other->run('UPDATE users SET display_name=?,version=version+1 WHERE tenant_id=? AND id=?', ['Concurrent winner', $tenant, Util::bin($member['id'])]));
            };
            $db->pdo->beginTransaction();
            try {
                $result = (new AdminApi($db, $context, $request, $validator))->dispatch();
                check($fresh && $result['status'] === 200, 'If-Match can match the locked version newer than the access snapshot');
                check(json_decode($result['json'], true)['version'] === $version + 2, 'patch increments the locked version');
            } catch (ApiError $e) {
                check(!$fresh && $e->status === 412, 'concurrent stale If-Match rejected using locked row');
            } finally { $db->pdo->rollBack(); }
            check(AdminReviewStatement::$before === null, 'race reached actual FOR UPDATE');
            $winner = $other->one('SELECT display_name,version FROM users WHERE tenant_id=? AND id=?', [$tenant, Util::bin($member['id'])]);
            check($winner['display_name'] === 'Concurrent winner' && (int) $winner['version'] === $version + 1, 'competing committed edit survives');
        }

        foreach (['createDeviceCommand', 'assignDevice'] as $operation) {
            $assign = $operation === 'assignDevice';
            $route = $assign ? '/devices/{id}/assignment' : '/devices/{id}/commands';
            $body = $assign ? (object) ['user_id' => Util::id($base['id']), 'reason' => 'Review assignment'] : (object) ['type' => 'lock', 'reason' => 'Review command', 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600)];
            $v = $db->one('SELECT version FROM devices WHERE tenant_id=? AND id=?', [$tenant, Util::bin($device)])['version'];
            $request = adminReviewRequest($route, 'POST', $device, $body, ['if-match' => '"' . $v . '"']);
            $db->pdo->beginTransaction();
            try {
                $api = new AdminApi($db, $context, $request, $validator);
                AdminReviewStatement::$queries = [];
                $result = $api->dispatch();
                check($result['status'] === ($assign ? 200 : 202), 'cached device context dispatches');
                check(count(array_filter(AdminReviewStatement::$queries, static fn ($sql) => str_starts_with($sql, 'SELECT u.* FROM users u '))) === 0, 'handler reuses constructor user access');
                check(count(array_filter(AdminReviewStatement::$queries, static fn ($sql) => $sql === 'SELECT * FROM devices WHERE tenant_id=? AND id=? FOR UPDATE')) === ($assign ? 1 : 0), 'only assignment response reloads changed device');
            } finally { $db->pdo->rollBack(); }
        }
    } finally {
        AdminReviewStatement::$before = null;
        AdminReviewStatement::$queries = [];
        $db->pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PDOStatement::class]);
    }

    $unknown = adminReviewRequest('/devices/{id}/commands', 'POST', $device, (object) ['type' => 'future_command']);
    try { new AdminApi($db, $context, $unknown, $validator); check(false, 'unmapped command must fail'); }
    catch (ApiError $e) { check($e->status === 422, 'expanded enum fails safely before permission lookup'); }

    $scopedContext = array_replace($context, ['platform' => false, 'user_id' => $siteUser['id']]);
    foreach (['POST', 'PATCH'] as $method) {
        $body = (object) ($method === 'POST' ? array_replace($memberBody, ['site_id' => null, 'area_id' => null]) : ['site_id' => null, 'area_id' => null]);
        $v = $db->one('SELECT version FROM users WHERE tenant_id=? AND id=?', [$tenant, Util::bin($member['id'])])['version'];
        $r = adminReviewRequest($method === 'POST' ? '/users' : '/users/{id}', $method, $method === 'POST' ? null : $member['id'], $body, ['if-match' => '"' . $v . '"']);
        try { $db->transaction(fn () => (new AdminApi($db, $scopedContext, $r, $validator))->dispatch()); check(false, 'scope removal must fail'); }
        catch (ApiError $e) { check($e->status === 403, 'internal null IDs reach scope denial without TypeError'); }
    }

    $savedGet = $_GET;
    $db->pdo->beginTransaction();
    try {
        for ($n = 0; $n < 105; $n++) { $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area',?)", [$tenant, Util::bin(Util::uuid()), 'Page fixture ' . $n]); }
        foreach (['-10' => 1, '0' => 1, '1' => 1, '100' => 100, '101' => 100, '999999999999999999999999' => 100] as $limit => $expected) {
            $_GET = ['limit' => (string) $limit];
            $r = adminReviewRequest('/organization', 'GET');
            $page = json_decode((new AdminApi($db, $context, $r, $validator))->dispatch()['json'], true);
            check(count($page['data']) === $expected && $page['next_cursor'] !== null, 'internal page clamps limit ' . $limit);
            $_GET['cursor'] = $page['next_cursor'];
            $next = json_decode((new AdminApi($db, $context, $r, $validator))->dispatch()['json'], true);
            check(count($next['data']) > 0 && !array_intersect(array_column($page['data'], 'id'), array_column($next['data'], 'id')), 'clamped cursor advances without overlap');
        }
    } finally { $_GET = $savedGet; $db->pdo->rollBack(); }
    foreach (['-10', '0', '101', '999999999999999999999999'] as $limit) { expect(adminRequest($admin, 'GET', '/organization?limit=' . $limit), 422, 'HTTP limit contract unchanged'); }

    $body = ['name' => 'Review policy', 'target_type' => 'tenant', 'target_id' => Util::id($tenant), 'enabled' => true, 'rules' => [compilerRule('deny', ['review.example.test'])]];
    $db->run('ALTER TABLE policy_documents ALTER COLUMN version SET DEFAULT 7');
    try { $policy = expect(adminRequest($admin, 'POST', '/policies', $body), 201, 'create policy uses actual database version'); }
    finally { $db->run('ALTER TABLE policy_documents ALTER COLUMN version SET DEFAULT 1'); }
    check($policy['version'] === 7, 'create response reports actual version');
    $second = expect(adminRequest($admin, 'POST', '/policies', array_replace($body, ['name' => 'Later policy'])), 201, 'later policy creates competing assignment');
    $assignments = $db->run('SELECT id,policy_id,scope,scope_target,priority FROM policy_assignments WHERE tenant_id=? ORDER BY scope,scope_target,priority', [$tenant])->fetchAll();
    foreach ([['name' => 'Renamed policy'], ['enabled' => false], ['enabled' => true]] as $changes) {
        $body = array_replace($body, $changes);
        $policy = expect(adminRequest($admin, 'PATCH', '/policies/' . $policy['id'], $body, ['if-match' => '"' . $policy['version'] . '"']), 200, 'policy metadata edit');
        check($db->run('SELECT id,policy_id,scope,scope_target,priority FROM policy_assignments WHERE tenant_id=? ORDER BY scope,scope_target,priority', [$tenant])->fetchAll() === $assignments, 'rename or enabled toggle preserves assignment identity and precedence');
        $a = $db->one('SELECT policy_version,enabled FROM policy_assignments WHERE tenant_id=? AND policy_id=?', [$tenant, Util::bin($policy['id'])]);
        check((int) $a['policy_version'] === $policy['version'] && (bool) $a['enabled'] === $body['enabled'], 'preserved assignment follows revision and enabled state');
    }
    $body = array_replace($body, ['target_type' => 'site', 'target_id' => Util::id($base['site_id'])]);
    $policy = expect(adminRequest($admin, 'PATCH', '/policies/' . $policy['id'], $body, ['if-match' => '"' . $policy['version'] . '"']), 200, 'explicit policy target change');
    check($policy['target_type'] === 'site' && $policy['target_id'] === $body['target_id'], 'explicit reassignment still works');
    echo "PASS admin review: null guards, concurrent If-Match, scoped create/edit success and denial, clamp, policy assignment/version and cached device access\n";
}
