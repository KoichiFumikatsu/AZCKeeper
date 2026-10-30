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
        $rows = $this->db->run('SELECT s.*,a.user_id,a.group_id,a.is_platform_admin,a.active,a.auth_version account_version,a.password_hash,a.must_change_password,a.email FROM admin_sessions s JOIN admin_accounts a ON a.tenant_id=s.tenant_id AND a.id=s.admin_id JOIN tenants t ON t.tenant_id=a.tenant_id WHERE s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>UTC_TIMESTAMP(6) AND s.idle_expires_at>UTC_TIMESTAMP(6) AND t.status=\'active\' LIMIT 2 FOR UPDATE', [hash('sha256', $cookie, true)])->fetchAll();
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
        // Contraseña temporal: solo /auth/password, /auth/logout y /auth/csrf hasta que la persona la cambie.
        if ($s['must_change_password']) { throw new ApiError(403, 'password_change_required'); }
        $tenantRoute = str_starts_with($r->route, '/tenants/{id}') || str_starts_with($r->route, '/admin/tenants/{id}');
        $tenant = $r->operation['operationId']==='getSupervisorOverview' ? $s['tenant_id'] : ($tenantRoute ? Util::bin($r->resourceId) : ($r->header('x-tenant-id') !== '' ? Util::bin($r->header('x-tenant-id')) : $s['tenant_id']));
        // Una cuenta de grupo (§8.5) entra tambien a las otras empresas de su grupo donde tiene roles; nunca a otras.
        $foreign = !$s['is_platform_admin'] && $tenant !== $s['tenant_id'];
        if ($foreign && !in_array($tenant, $this->groupTenants($s), true)) { throw new ApiError(404, 'resource_not_found'); }
        if ($tenantRoute && $r->header('x-tenant-id') !== '' && Util::bin($r->header('x-tenant-id')) !== $tenant) { throw new ApiError(404, 'resource_not_found'); }
        $t = $this->db->one('SELECT * FROM tenants WHERE tenant_id=? FOR UPDATE', [$tenant]);
        // Empresa eliminada: solo queda su registro minimo para la auditoria; para el panel no existe.
        if (!$t || $t['deleted_at'] !== null) { throw new ApiError(404, 'resource_not_found'); }
        if (!$s['is_platform_admin'] && $t['status'] !== 'active') { throw new ApiError(403, 'permission_denied'); }
        // Empresa archivada: solo lectura para superadmin; lo unico que se le puede hacer es desarchivarla o eliminarla.
        if ($t['archived_at'] !== null && $r->method !== 'GET' && !($r->method === 'PATCH' && $r->route === '/tenants/{id}') && $r->route !== '/tenants/{id}/purge') { throw new ApiError(409, 'invalid_transition', [], 'tenant_archived'); }
        $this->limiter->take([['admin', bin2hex($s['tenant_id'] . $s['admin_id']), Config::number('ADMIN_RATE',120), Config::number('ADMIN_BURST',30)], ['tenant', 'admin:' . bin2hex($tenant), Config::number('ADMIN_TENANT_RATE',600), Config::number('ADMIN_TENANT_BURST',100)]]);
        $c = ['tenant_id' => $tenant, 'admin_tenant_id' => $s['tenant_id'], 'admin_id' => $s['admin_id'], 'user_id' => $foreign ? null : $s['user_id'], 'group_id' => $s['group_id'], 'platform' => (bool) $s['is_platform_admin'], 'actor_type' => 'admin', 'timezone' => $t['timezone'], 'auth_revision' => (int) $t['auth_version'], 'session_id' => $s['id'], 'reauthenticated_at' => $s['reauthenticated_at']];
        $c['principal_id'] = $this->principal($c);
        return $c;
    }
    /** Contextos candidatos; cada destino debe pasar además usuarios.ver y su propio alcance. */
    public function supervisorContexts(array $origin): array
    {
        $ids=$origin['platform']?null:array_values(array_unique([$origin['admin_tenant_id'],...$this->groupTenants(array_replace($origin,['tenant_id'=>$origin['admin_tenant_id']]))]));
        $where=$ids===null?'1=1':'t.tenant_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
        $rows=$this->db->run("SELECT t.tenant_id,t.timezone,t.auth_version,COALESCE(b.display_name,t.name) name FROM tenants t LEFT JOIN branding b ON b.tenant_id=t.tenant_id
            WHERE t.status='active' AND t.deleted_at IS NULL AND t.archived_at IS NULL AND t.tenant_id<>? AND $where ORDER BY t.tenant_id",[Util::bin(self::PLATFORM),...($ids??[])])->fetchAll();
        return array_map(static fn($t)=>array_replace($origin,['tenant_id'=>$t['tenant_id'],'timezone'=>$t['timezone'],'auth_revision'=>(int)$t['auth_version'],'tenant_name'=>$t['name'],
            'user_id'=>$t['tenant_id']===$origin['admin_tenant_id']?$origin['user_id']:null]),$rows);
    }
    /** Empresas (ids binarios) a las que una cuenta de grupo puede entrar: su casa y las del grupo donde tiene roles. */
    public function groupTenants(array $a): array
    {
        if (($a['group_id'] ?? null) === null) { return []; }
        $adminId = $a['admin_id'] ?? $a['id'];
        return array_column($this->db->run('SELECT DISTINCT g.tenant_id FROM access_grants g JOIN company_group_members m ON m.tenant_id=g.tenant_id AND m.group_id=? JOIN tenants t ON t.tenant_id=g.tenant_id AND t.status=\'active\' WHERE g.admin_tenant_id=? AND g.admin_id=? ORDER BY g.tenant_id', [$a['group_id'], $a['tenant_id'], $adminId])->fetchAll(), 'tenant_id');
    }
    /** Datos del grupo para la sesion del panel (selector de empresa y tablero consolidado). */
    private function groupInfo(array $a): array
    {
        if (($a['group_id'] ?? null) === null) { return []; }
        $g = $this->db->one('SELECT name FROM company_groups WHERE id=?', [$a['group_id']]);
        if (!$g) { return []; }
        $ids = array_values(array_unique([$a['tenant_id'], ...$this->groupTenants($a)]));
        // Con nombre: el selector de empresa no depende del permiso empresas.ver.
        $names = []; foreach ($ids as $t) { $names[] = ['id' => Util::id($t), 'name' => $this->db->one('SELECT COALESCE(b.display_name,t.name) n FROM tenants t LEFT JOIN branding b ON b.tenant_id=t.tenant_id WHERE t.tenant_id=?', [$t])['n']]; }
        return ['group' => ['id' => Util::id($a['group_id']), 'name' => $g['name'], 'tenants' => $names]];
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
                $this->db->run('UPDATE admin_accounts SET last_login_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$a['tenant_id'],$a['id']]);
                $this->db->run('INSERT INTO admin_sessions (tenant_id,id,admin_id,token_hash,csrf_hash,auth_version,created_at,last_seen_at,expires_at,idle_expires_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 8 HOUR,UTC_TIMESTAMP(6)+INTERVAL 30 MINUTE)',[$a['tenant_id'],$id,$a['id'],hash('sha256',$token,true),hash('sha256',$csrf,true),$a['auth_version']]);
                $s=$this->db->one('SELECT * FROM admin_sessions WHERE tenant_id=? AND id=?',[$a['tenant_id'],$id]);
                $c=['tenant_id'=>$a['tenant_id'],'admin_tenant_id'=>$a['tenant_id'],'admin_id'=>$a['id'],'actor_type'=>'admin']; $c['principal_id']=$this->principal($c);
                (new Audit($this->db))->record($c,$r,'admin.login','admin_session',$id,['session']);
                return AdminApi::response(['admin_id'=>Util::id($a['id']),'tenant_id'=>$a['is_platform_admin']?null:Util::id($a['tenant_id']),'is_platform_admin'=>(bool)$a['is_platform_admin'],'must_change_password'=>(bool)$a['must_change_password'],'expires_at'=>Util::time($s['expires_at']),'idle_expires_at'=>Util::time($s['idle_expires_at']),'csrf_token'=>$csrf]+$this->groupInfo($a),200,['Set-Cookie'=>$this->cookie('__Host-keeper_admin',$token,28800)]);
            }
            $s=$this->identity($r);
            $c=['tenant_id'=>$s['tenant_id'],'admin_tenant_id'=>$s['tenant_id'],'admin_id'=>$s['admin_id'],'actor_type'=>'admin']; $c['principal_id']=$this->principal($c);
            if ($r->route==='/auth/logout') {
                $this->db->run('UPDATE admin_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
                (new Audit($this->db))->record($c,$r,'admin.logout','admin_session',$s['id'],['session']);
                return AdminApi::response(null,204,['Set-Cookie'=>$this->cookie('__Host-keeper_admin','',0)]);
            }
            if ($r->route==='/auth/password') {
                if (!password_verify($r->body->current_password,$s['password_hash'])) { throw new ApiError(401,'invalid_credentials'); }
                $new=$r->body->new_password;
                if (password_verify($new,$s['password_hash']) || strcasecmp($new,$s['email'])===0) { throw new ApiError(422,'weak_password',[], 'La contraseña nueva debe ser distinta de la actual y del correo.'); }
                // Nueva version de la cuenta: cierra las demas sesiones y esta sigue viva con la version nueva.
                $this->db->run('UPDATE admin_accounts SET password_hash=?,must_change_password=FALSE,auth_version=auth_version+1 WHERE tenant_id=? AND id=?',[password_hash($new,PASSWORD_DEFAULT),$s['tenant_id'],$s['admin_id']]);
                $version=$this->db->one('SELECT auth_version FROM admin_accounts WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['admin_id']])['auth_version'];
                $this->db->run('UPDATE admin_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND admin_id=? AND id<>? AND revoked_at IS NULL',[$s['tenant_id'],$s['admin_id'],$s['id']]);
                $this->db->run('UPDATE admin_sessions SET auth_version=?,reauthenticated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$version,$s['tenant_id'],$s['id']]);
                (new Audit($this->db))->record($c,$r,'admin.password_changed','admin_account',$s['admin_id'],['password']);
                $fresh=$this->db->one('SELECT expires_at,idle_expires_at FROM admin_sessions WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
                return AdminApi::response(['admin_id'=>Util::id($s['admin_id']),'tenant_id'=>$s['is_platform_admin']?null:Util::id($s['tenant_id']),'is_platform_admin'=>(bool)$s['is_platform_admin'],'must_change_password'=>false,'expires_at'=>Util::time($fresh['expires_at']),'idle_expires_at'=>Util::time($fresh['idle_expires_at']),'csrf_token'=>$this->csrf($_COOKIE['__Host-keeper_admin'])]+$this->groupInfo($s),200,['Cache-Control'=>'no-store']);
            }
            if (!password_verify($r->body->password,$s['password_hash'])) { throw new ApiError(401,'invalid_credentials'); }
            $this->db->run('UPDATE admin_sessions SET reauthenticated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
            (new Audit($this->db))->record($c,$r,'admin.reauthenticated','admin_session',$s['id'],['reauthentication']);
            $fresh=$this->db->one('SELECT expires_at,idle_expires_at FROM admin_sessions WHERE tenant_id=? AND id=?',[$s['tenant_id'],$s['id']]);
            return AdminApi::response(['admin_id'=>Util::id($s['admin_id']),'tenant_id'=>$s['is_platform_admin']?null:Util::id($s['tenant_id']),'is_platform_admin'=>(bool)$s['is_platform_admin'],'must_change_password'=>(bool)$s['must_change_password'],'expires_at'=>Util::time($fresh['expires_at']),'idle_expires_at'=>Util::time($fresh['idle_expires_at']),'csrf_token'=>$this->csrf($_COOKIE['__Host-keeper_admin'])]+$this->groupInfo($s));
        });
    }
}
