<?php
declare(strict_types=1);
namespace Keeper;

final class AdminAuth
{
    public const PLATFORM = '00000000-0000-4000-8000-000000000001';
    public function __construct(private Database $db, private RateLimiter $limiter) {}
    private function csrf(string $cookie): string { return Util::b64(hash_hmac('sha256', 'admin-csrf:' . $cookie, Config::key(), true)); }
    private function cookie(string $name, string $token, int $age): string
    {
        return $name . '=' . $token . '; Path=/; Secure; HttpOnly; SameSite=Strict; Max-Age=' . $age;
    }
    private function verifyCsrf(Request $r, string $cookie, string $hash): void
    {
        $token = $r->header('x-csrf-token');
        if ($r->header('origin') !== rtrim(Config::get('ORIGIN'), '/') || !hash_equals($this->csrf($cookie), $token) || !hash_equals($hash, hash('sha256', $token, true))) { throw new ApiError(403, 'csrf_invalid'); }
    }
    public function identity(Request $r): array
    {
        $cookie = $_COOKIE['__Host-keeper_admin'] ?? '';
        $rows = $this->db->run('SELECT s.*,a.user_id,a.is_platform_admin,a.active,a.auth_version account_version,a.password_hash FROM admin_sessions s JOIN admin_accounts a ON a.tenant_id=s.tenant_id AND a.id=s.admin_id JOIN tenants t ON t.tenant_id=a.tenant_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP(6) AND s.idle_expires_at>UTC_TIMESTAMP(6) AND t.status=\'active\' LIMIT 2 FOR UPDATE', [hash('sha256', $cookie, true)])->fetchAll();
        if (count($rows) !== 1 || !$rows[0]['active'] || $rows[0]['auth_version'] !== $rows[0]['account_version']) { throw new ApiError(401, 'session_expired'); }
        $s = $rows[0];
        if (!$s['is_platform_admin'] && !$this->db->one("SELECT id FROM users WHERE tenant_id=? AND id=? AND status='active' AND panel_login_enabled=TRUE", [$s['tenant_id'], $s['user_id']])) { throw new ApiError(401, 'session_expired'); }
        if ($r->method !== 'GET') { $this->verifyCsrf($r, $cookie, $s['csrf_hash']); }
        $this->db->run('UPDATE admin_sessions SET last_seen_at=UTC_TIMESTAMP(6),idle_expires_at=LEAST(expires_at,UTC_TIMESTAMP(6)+INTERVAL 30 MINUTE) WHERE tenant_id=? AND id=?', [$s['tenant_id'], $s['id']]);
        return $s;
    }
    public function context(Request $r): array
    {
        $s = $this->identity($r);
        $tenantRoute = str_starts_with($r->route, '/tenants/{id}') || str_starts_with($r->route, '/admin/tenants/{id}');
        $tenant = $tenantRoute ? Util::bin($r->resourceId) : ($r->header('x-tenant-id') !== '' ? Util::bin($r->header('x-tenant-id')) : $s['tenant_id']);
        if (!$s['is_platform_admin'] && $tenant !== $s['tenant_id']) { throw new ApiError(404, 'resource_not_found'); }
        if ($tenantRoute && $r->header('x-tenant-id') !== '' && Util::bin($r->header('x-tenant-id')) !== $tenant) { throw new ApiError(404, 'resource_not_found'); }
        $t = $this->db->one('SELECT * FROM tenants WHERE tenant_id=? FOR UPDATE', [$tenant]);
        if (!$t) { throw new ApiError(404, 'resource_not_found'); }
        if (!$s['is_platform_admin'] && $t['status'] !== 'active') { throw new ApiError(403, 'permission_denied'); }
        $this->limiter->take([['admin', bin2hex($s['tenant_id'] . $s['admin_id']), Config::number('ADMIN_RATE',120), Config::number('ADMIN_BURST',30)], ['tenant', 'admin:' . bin2hex($tenant), Config::number('ADMIN_TENANT_RATE',600), Config::number('ADMIN_TENANT_BURST',100)]]);
        $c = ['tenant_id' => $tenant, 'admin_tenant_id' => $s['tenant_id'], 'admin_id' => $s['admin_id'], 'user_id' => $s['user_id'], 'platform' => (bool) $s['is_platform_admin'], 'actor_type' => 'admin', 'timezone' => $t['timezone'], 'gate' => (bool) $t['rbac_self_management'], 'auth_revision' => (int) $t['auth_version'], 'session_id' => $s['id'], 'reauthenticated_at' => $s['reauthenticated_at']];
        $c['principal_id'] = $this->principal($c);
        return $c;
    }
    public function principal(array $c): string
    {
        $p = $this->db->one("SELECT id FROM principals WHERE tenant_id=? AND admin_tenant_id=? AND admin_id=? AND kind='admin'", [$c['tenant_id'], $c['admin_tenant_id'], $c['admin_id']]);
        if ($p) { return $p['id']; }
        $id = Util::bin(Util::uuid());
        $this->db->run("INSERT INTO principals (tenant_id,id,kind,admin_tenant_id,admin_id) VALUES (?,?,'admin',?,?)", [$c['tenant_id'], $id, $c['admin_tenant_id'], $c['admin_id']]);
        return $id;
    }
    public function handle(Request $r): array
    {
        $this->limiter->take([['bootstrap', 'admin-auth:' . hash('sha256', strtolower($r->body->email ?? ($_COOKIE['__Host-keeper_admin'] ?? 'prelogin'))), 20, 10]]);
        return $this->db->transaction(function () use ($r): array {
            if ($r->route === '/auth/csrf') {
                if (isset($_COOKIE['__Host-keeper_admin'])) {
                    try { $this->identity($r); return AdminApi::response(['csrf_token' => $this->csrf($_COOKIE['__Host-keeper_admin'])]); }
                    catch (ApiError $e) { if ($e->status!==401) { throw $e; } }
                }
                $token = Util::b64(random_bytes(32)); $csrf = $this->csrf($token);
                $this->db->run('INSERT INTO admin_pre_sessions (id,token_hash,csrf_hash,created_at,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 5 MINUTE)', [Util::bin(Util::uuid()), hash('sha256',$token,true), hash('sha256',$csrf,true)]);
                return AdminApi::response(['csrf_token' => $csrf], 200, ['Set-Cookie' => [$this->cookie('__Host-keeper_admin','',0),$this->cookie('__Host-keeper_prelogin',$token,300)]]);
            }
            if ($r->route === '/auth/login') {
                $token = $_COOKIE['__Host-keeper_prelogin'] ?? '';
                $pre = $this->db->one('SELECT * FROM admin_pre_sessions WHERE token_hash=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP(6) FOR UPDATE',[hash('sha256',$token,true)]);
                if (!$pre) { throw new ApiError(401,'invalid_credentials'); }
                $this->verifyCsrf($r,$token,$pre['csrf_hash']);
                $accounts = $this->db->run("SELECT a.* FROM admin_accounts a JOIN tenants t ON t.tenant_id=a.tenant_id LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.email=? AND a.active=TRUE AND t.status='active' AND (a.is_platform_admin=TRUE OR (u.status='active' AND u.panel_login_enabled=TRUE)) LIMIT 2 FOR UPDATE",[$r->body->email])->fetchAll();
                $hash = $accounts[0]['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
                $valid = password_verify($r->body->password,$hash);
                if (count($accounts)!==1 || !$valid) { throw new ApiError(401,'invalid_credentials'); }
                $a=$accounts[0]; $token=Util::b64(random_bytes(32)); $csrf=$this->csrf($token); $id=Util::bin(Util::uuid());
                if (isset($_COOKIE['__Host-keeper_admin'])) { $this->db->run('UPDATE admin_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND admin_id=? AND token_hash=? AND revoked_at IS NULL',[$a['tenant_id'],$a['id'],hash('sha256',$_COOKIE['__Host-keeper_admin'],true)]); }
                $this->db->run('UPDATE admin_pre_sessions SET consumed_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$pre['tenant_id'],$pre['id']]);
                $this->db->run('INSERT INTO admin_sessions (tenant_id,id,admin_id,token_hash,csrf_hash,auth_version,created_at,last_seen_at,expires_at,idle_expires_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 8 HOUR,UTC_TIMESTAMP(6)+INTERVAL 30 MINUTE)',[$a['tenant_id'],$id,$a['id'],hash('sha256',$token,true),hash('sha256',$csrf,true),$a['auth_version']]);
                $s=$this->db->one('SELECT * FROM admin_sessions WHERE tenant_id=? AND id=?',[$a['tenant_id'],$id]);
                $c=['tenant_id'=>$a['tenant_id'],'admin_tenant_id'=>$a['tenant_id'],'admin_id'=>$a['id'],'actor_type'=>'admin']; $c['principal_id']=$this->principal($c);
                (new Audit($this->db))->record($c,$r,'admin.login','admin_session',$id,['session']);
                return AdminApi::response(['admin_id'=>Util::id($a['id']),'tenant_id'=>$a['is_platform_admin']?null:Util::id($a['tenant_id']),'is_platform_admin'=>(bool)$a['is_platform_admin'],'expires_at'=>Util::time($s['expires_at']),'idle_expires_at'=>Util::time($s['idle_expires_at']),'csrf_token'=>$csrf],200,['Set-Cookie'=>$this->cookie('__Host-keeper_admin',$token,28800)]);
            }
            $s=$this->identity($r);
            $c=['tenant_id'=>$s['tenant_id'],'admin_tenant_id'=>$s['tenant_id'],'admin_id'=>$s['admin_id'],'actor_type'=>'admin']; $c['principal_id']=$this->principal($c);
            if ($r->route==='/auth/logout') {
                $this->db->run('UPDATE admin_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
                (new Audit($this->db))->record($c,$r,'admin.logout','admin_session',$s['id'],['session']);
                return AdminApi::response(null,204,['Set-Cookie'=>$this->cookie('__Host-keeper_admin','',0)]);
            }
            if (!password_verify($r->body->password,$s['password_hash'])) { throw new ApiError(401,'invalid_credentials'); }
            $this->db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
            (new Audit($this->db))->record($c,$r,'admin.reauthenticated','admin_session',$s['id'],['reauthentication']);
            $fresh=$this->db->one('SELECT expires_at,idle_expires_at FROM admin_sessions WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
            return AdminApi::response(['admin_id'=>Util::id($s['admin_id']),'tenant_id'=>$s['is_platform_admin']?null:Util::id($s['tenant_id']),'is_platform_admin'=>(bool)$s['is_platform_admin'],'expires_at'=>Util::time($fresh['expires_at']),'idle_expires_at'=>Util::time($fresh['idle_expires_at']),'csrf_token'=>$this->csrf($_COOKIE['__Host-keeper_admin'])]);
        });
    }
}
