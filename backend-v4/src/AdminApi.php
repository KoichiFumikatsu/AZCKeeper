<?php
declare(strict_types=1);
namespace Keeper;

final class AdminApi
{
    private AdminAccess $access;
    private string $tenant;
    private ?array $authorizedDevice = null;
    private ?array $deviceUser = null;
    public function __construct(private Database $db, private array $c, private Request $r, private Validator $validator)
    {
        $this->tenant=$c['tenant_id'];
        if (($r->operation['x-platform-only'] ?? false) && !$c['platform']) { throw new ApiError(403,'permission_denied'); }
        $permission=$r->operation['x-permission'];
        if ($r->operation['operationId']==='createDeviceCommand') {
            $permission=match($r->body->type) { 'lock'=>'equipos.bloquear','unlock'=>'equipos.desbloquear','restart'=>'equipos.reiniciar','shutdown'=>'equipos.apagar','wipe'=>'equipos.borrar','refresh_policy'=>'reglas.aplicar',default=>throw new ApiError(422,'validation_failed') };
        }
        $this->access=new AdminAccess($db,$c,$permission);
        if ($permission==='roles.gestionar') { $this->access->rolesGate(); }
        if (in_array($r->operation['operationId'],['createDeviceCommand','createHardeningCommand','assignDevice'],true)) { $this->authorizedDevice=$this->row('devices',Util::bin($r->resourceId)); $this->deviceUser=$this->access->user($this->authorizedDevice['user_id']); }
        if ($r->operation['operationId']==='deployRelease') { $this->access->requireFull(); }
    }
    public static function response(mixed $body, int $status=200, array $headers=[]): array { return ['status'=>$status,'json'=>Util::json($body),'headers'=>$headers]; }
    public static function compilePending(Database $db, string $tenant): void
    {
        $q=$db->one('SELECT revision FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant]);
        if (!$q) { return; }
        try { (new PolicyCompiler($db))->recompileForTenant(Util::id($tenant)); }
        catch (\Throwable) { throw new ApiError(503,'temporarily_unavailable',['Retry-After'=>'5']); }
        $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=? AND revision=?',[$tenant,$q['revision']]);
    }
    public static function validatePending(Database $db,string $tenant): void
    {
        if (!$db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant])) { return; }
        try { (new PolicyCompiler($db))->validateForTenant(Util::id($tenant)); }
        catch (PolicyConflict) { throw new ApiError(409,'invalid_transition'); }
    }
    private function dirty(): void { $this->db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)',[$this->tenant]); }
    private function row(string $table, string $id, string $key='id'): array
    {
        $row=$this->db->one("SELECT * FROM $table WHERE tenant_id=? AND $key=? FOR UPDATE",[$this->tenant,$id]);
        if (!$row) { throw new ApiError(404,'resource_not_found'); } return $row;
    }
    private function match(array $row): void
    {
        if ($this->r->header('if-match') !== '"' . $row['version'] . '"') { throw new ApiError(412,'version_conflict'); }
    }
    private function audit(string $type,string $id,array $fields): void { (new Audit($this->db))->record($this->c,$this->r,$this->r->operation['operationId'],$type,$id,$fields); }
    private function result(array $row, int $status=200): array { return self::response($row,$status,isset($row['version'])?['ETag'=>'"'.$row['version'].'"']:[]); }
    private function unique(array $items): void { if (count(array_unique($items))!==count($items)) { throw new ApiError(422,'validation_failed'); } }
    private function timezone(string $zone): void { try { new \DateTimeZone($zone); } catch (\Exception) { throw new ApiError(422,'validation_failed'); } }
    private function org(string $id,string $kind): array
    {
        $row=$this->row('org_units',$id);
        if ($row['kind']!==$kind || !$row['active']) { throw new ApiError(422,'validation_failed'); } return $row;
    }
    private function update(string $table,string $id,array $values): void
    {
        if (!$values) { return; }
        $set=implode(',',array_map(static fn($x)=>"$x=?",array_keys($values)));
        $this->db->run("UPDATE $table SET $set,version=version+1 WHERE tenant_id=? AND id=?",[...array_values($values),$this->tenant,$id]);
    }
    private function page(string $select,array $args,callable $map,string $at='created_at',string $key='id'): array
    {
        $filters=$_GET; unset($filters['cursor']); ksort($filters);
        $resources=new Resources($this->db,['cursor_context'=>Util::json([Util::id($this->tenant),Util::id($this->c['principal_id']),$this->c['auth_revision'],$this->r->path,$filters,$at,$key])],$this->validator);
        $limit=max(1,min(100,(int)($_GET['limit']??50)));
        $page=isset($_GET['cursor'])?$resources->decodeCursor($_GET['cursor']):['at'=>'','id'=>null,'snapshot'=>$this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'],'exp'=>time()+900];
        $where=$at==='code'?'1=1':"p.$at<=?";
        if ($at!=='code') { $args[]=$page['snapshot']; }
        if ($page['id']!==null) { $where.=" AND (p.$at>? OR (p.$at=? AND p.$key>?))"; array_push($args,$page['at'],$page['at'],$key==='code'?$page['id']:Util::bin($page['id'])); }
        $args[]=$limit+1;
        $rows=$this->db->run("SELECT p.* FROM ($select) p WHERE $where ORDER BY p.$at,p.$key LIMIT ?",$args)->fetchAll();
        $more=count($rows)>$limit; if ($more) { array_pop($rows); }
        $next=null;
        if ($more) { $last=end($rows); $next=$resources->encodeCursor(['at'=>$last[$at],'id'=>$key==='code'?$last[$key]:Util::id($last[$key]),'snapshot'=>$page['snapshot'],'exp'=>$page['exp']]); }
        return self::response(['data'=>array_map($map,$rows),'next_cursor'=>$next,'generated_at'=>Util::now(),'data_through'=>Util::time($page['snapshot'])]);
    }
    public function dispatch(): array
    {
        try { return $this->handle(); }
        catch (\PDOException $e) {
            if (in_array($e->errorInfo[1]??0,[1062,1451,1452,1644,3819],true)) { throw new ApiError(409,'invalid_transition'); }
            throw $e;
        }
    }
    private function handle(): array
    {
        $op=$this->r->operation['operationId']; $b=$this->r->body;
        $id=$this->r->resourceId!==null && !str_starts_with($op,'getPermission') && $op!=='patchPermission'?Util::bin($this->r->resourceId):null;
        return match($op) {
            'getTenantHardening','setTenantHardening','revealTenantHardening','getDeviceHardening','createHardeningCommand'=>(new Hardening($this->db,$this->c,$this->r))->admin($this->access),
            'listHolidays','createHoliday','getHoliday','putHoliday','deleteHoliday','listHolidaySocieties','createHolidaySociety','getHolidaySociety','putHolidaySociety','deleteHolidaySociety',
            'listSuspiciousApps','createSuspiciousApp','getSuspiciousApp','putSuspiciousApp','deleteSuspiciousApp','listDualJobAlerts','listSuspiciousDetections','listComplianceSignals',
            'listInstallCoverage','putInstallCoverage','listClientLogs','getServerHealth','getPanelSettings','putPanelSettings'=>(new OperationsApi($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'getAppsReport','getPresenceReport','getUserActivity','getUserPolicies'=>(new ActivityReports($this->db,$this->c,$this->access))->dispatch($op,$id),
            'issueMigrationAuthorization','validateMigrationAuthorization','getEscrowKey','persistDeviceEscrow','verifyDeviceEscrow','recoverDeviceEscrow','getDeviceMigration','setDeviceMigration'=>(new MigrationApi($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'listTenants','createTenant','getTenant','patchTenant','setRbacGate'=>$this->tenants($op,$b),
            'listOrganization','createOrgUnit','getOrgUnit','patchOrgUnit'=>$this->organization($op,$id,$b),
            'listSchedules','createSchedule','getSchedule','putSchedule'=>$this->schedules($op,$id,$b),
            'listUsers','createUser','getUser','patchUser','setUserRoles'=>$this->users($op,$id,$b),
            'listTenantDevices','getDevice','patchDevice','assignDevice','createDeviceCommand','listAdminCommands','createEnrollment'=>$this->devices($op,$id,$b),
            'listRoles','getRole','createRole','patchRole'=>$this->roles($op,$id,$b),
            'listPermissions','getPermission','patchPermission'=>$this->permissions($op,$b),
            'listPolicies','getPolicy','createPolicy','patchPolicy'=>$this->policies($op,$id,$b),
            'listTiers','getTier','createTier','patchTier'=>$this->tiers($op,$id,$b),
            'getSubscription','setSubscription'=>$this->subscription($op,$id,$b),
            'listReleases','registerRelease','deployRelease'=>$this->releases($op,$b),
            'getProductivity'=>$this->report(), 'listAudit'=>$this->auditPage(),
            default=>throw new ApiError(404,'resource_not_found')
        };
    }
    private function tenantDto(array $row): array
    {
        $brand=$this->db->one('SELECT display_name,logo_url,primary_color,accent_color FROM branding WHERE tenant_id=?',[$row['tenant_id']]);
        $brand??=['display_name'=>$row['name'],'logo_url'=>null,'primary_color'=>'#003A5D','accent_color'=>'#BE1622'];
        $brand['display_name']=mb_substr($brand['display_name'],0,100);
        $brand['logo_url']??=rtrim(Config::get('ORIGIN'),'/').'/branding.svg';
        return ['id'=>Util::id($row['tenant_id']),'name'=>$row['name'],'status'=>$row['status'],'timezone'=>$row['timezone'],'rbac_self_management'=>(bool)$row['rbac_self_management'],'branding'=>$brand,'version'=>(int)$row['version']];
    }
    private function tenants(string $op,?object $b): array
    {
        $this->access->requireFull();
        if ($op==='listTenants') { return $this->page('SELECT t.*,tenant_id id FROM tenants t'.($this->c['platform']?'':' WHERE tenant_id=?'),$this->c['platform']?[]:[$this->tenant],fn($x)=>$this->tenantDto($x)); }
        if ($op==='createTenant') {
            $this->timezone($b->timezone); $id=Util::bin(Util::uuid());
            $this->db->run('INSERT INTO tenants (tenant_id,name,timezone) VALUES (?,?,?)',[$id,$b->name,$b->timezone]);
            $this->db->run('INSERT INTO retention_settings (tenant_id) VALUES (?)',[$id]);
            $this->db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')",[$id,Util::bin(Util::uuid())]);
            $this->tenant=$id; $this->c['tenant_id']=$id;
            $this->c['principal_id']=(new AdminAuth($this->db,new RateLimiter()))->principal($this->c);
            foreach ($this->db->run('SELECT * FROM role_templates WHERE platform_only=FALSE')->fetchAll() as $template) {
                $role=Util::bin(Util::uuid());
                $this->db->run('INSERT INTO roles (tenant_id,id,name,seed_key,seed_version,scope_kind) VALUES (?,?,?,?,?,?)',[$id,$role,$template['name'],$template['seed_key'],$template['version'],$template['scope_kind']]);
                $this->db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) SELECT ?,?,permission_code FROM role_template_permissions WHERE seed_key=?',[$id,$role,$template['seed_key']]);
            }
        } else { $id=$this->tenant; $row=$this->row('tenants',$id,'tenant_id'); if ($op==='getTenant') { return $this->result($this->tenantDto($row)); } $this->match($row); }
        if ($op==='setRbacGate') { $this->db->run('UPDATE tenants SET rbac_self_management=?,auth_version=auth_version+1,version=version+1 WHERE tenant_id=?',[(int)$b->enabled,$id]); }
        elseif ($op!=='createTenant') {
            if (isset($b->status) && !$this->c['platform']) { throw new ApiError(403,'permission_denied'); }
            if (isset($b->timezone)) { $this->timezone($b->timezone); }
            foreach (['name','timezone','status'] as $field) { if (isset($b->$field)) { $this->db->run("UPDATE tenants SET $field=? WHERE tenant_id=?",[$b->$field,$id]); } }
            $this->db->run('UPDATE tenants SET version=version+1,auth_version=auth_version+1 WHERE tenant_id=?',[$id]); $this->dirty();
        }
        if (isset($b->branding)) {
            $v=$b->branding;
            $this->db->run('INSERT INTO branding (tenant_id,display_name,logo_url,primary_color,accent_color) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),logo_url=VALUES(logo_url),primary_color=VALUES(primary_color),accent_color=VALUES(accent_color)',[$id,$v->display_name,$v->logo_url,$v->primary_color,$v->accent_color]);
        }
        $this->audit('tenant',$id,array_keys(get_object_vars($b)));
        return $this->result($this->tenantDto($this->row('tenants',$id,'tenant_id')),$op==='createTenant'?201:200);
    }
    private function orgDto(array $r): array { return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'kind'=>$r['kind'],'name'=>$r['name'],'parent_id'=>$r['parent_id']===null?null:Util::id($r['parent_id']),'active'=>(bool)$r['active'],'version'=>(int)$r['version']]; }
    private function organization(string $op,?string $id,?object $b): array
    {
        $this->access->requireFull();
        if ($op==='listOrganization') { return $this->page('SELECT * FROM org_units WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->orgDto($x)); }
        if ($id) { $old=$this->row('org_units',$id); if ($op==='getOrgUnit') { return $this->result($this->orgDto($old)); } $this->match($old); if ($old['kind']!==$b->kind) { throw new ApiError(409,'invalid_transition'); } }
        $parent=$b->parent_id===null?null:Util::bin($b->parent_id);
        $requiresActiveParent=!$id || (!$old['active'] && $b->active) || $parent!==$old['parent_id'];
        $seen=[$id]; $p=$parent;
        while ($p!==null) { if (in_array($p,$seen,true)) { throw new ApiError(422,'validation_failed'); } $seen[]=$p; $pr=$this->row('org_units',$p); if ($requiresActiveParent && !$pr['active']) { throw new ApiError(422,'inactive_org_ancestor',[], 'No se puede crear, reactivar o mover una unidad bajo un ancestro inactivo.'); } $p=$pr['parent_id']; }
        if ($id && $old['active'] && !$b->active) {
            if ($this->db->one('SELECT id FROM users WHERE tenant_id=? AND (firm_id=? OR site_id=? OR area_id=? OR position_id=?) LIMIT 1',[$this->tenant,$id,$id,$id,$id])
                || $this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND parent_id=? AND active=TRUE LIMIT 1',[$this->tenant,$id])
                || $this->db->one('SELECT role_id FROM role_area_scopes WHERE tenant_id=? AND area_id=? LIMIT 1',[$this->tenant,$id])
                || $this->db->one('SELECT role_id FROM role_site_scopes WHERE tenant_id=? AND site_id=? LIMIT 1',[$this->tenant,$id])
                || $this->db->one('SELECT id FROM policy_assignments WHERE tenant_id=? AND (area_id=? OR site_id=?) LIMIT 1',[$this->tenant,$id,$id])) { throw new ApiError(409,'org_unit_in_use',[], 'La unidad tiene usuarios, unidades hijas activas, scopes de roles o asignaciones de políticas.'); }
        }
        $values=['kind'=>$b->kind,'name'=>$b->name,'parent_id'=>$parent,'active'=>(int)$b->active];
        if ($id) { $this->update('org_units',$id,$values); } else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO org_units (tenant_id,id,kind,name,parent_id,active) VALUES (?,?,?,?,?,?)',[$this->tenant,$id,...array_values($values)]); }
        $this->audit('org_unit',$id,array_keys($values)); $this->dirty();
        return $this->result($this->orgDto($this->row('org_units',$id)),$op==='createOrgUnit'?201:200);
    }
    private function userDto(array $r): array
    {
        $roles=$this->db->run('SELECT ur.role_id FROM user_roles ur JOIN roles r ON r.tenant_id=ur.tenant_id AND r.id=ur.role_id AND r.active=TRUE WHERE ur.tenant_id=? AND ur.user_id=? ORDER BY ur.role_id',[$this->tenant,$r['id']])->fetchAll();
        $sub=$this->currentSubscription($r['id']);
        $out=['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'display_name'=>$r['display_name'],'email'=>$r['email'],'status'=>$r['status'],'role_ids'=>array_map([Util::class,'id'],array_column($roles,'role_id')),'panel_login_enabled'=>(bool)$r['panel_login_enabled'],'tier_id'=>$sub?Util::id($sub['tier_id']):null,'version'=>(int)$r['version']];
        foreach (['firm_id','site_id','area_id','position_id','schedule_id'] as $f) { $out[$f]=$r[$f]===null?null:Util::id($r[$f]); } return $out;
    }
    private function filterUsers(string $alias='u'): array
    {
        [$where,$args]=$this->access->usersSql($alias);
        foreach (['area_id','site_id','user_id','status'] as $f) {
            if (!isset($_GET[$f])) { continue; }
            $column=$f==='user_id'?'id':$f;
            if ($f==='area_id' || $f==='site_id') { $this->org(Util::bin($_GET[$f]),substr($f,0,-3)); }
            if ($f==='user_id') { $this->access->user(Util::bin($_GET[$f])); }
            $where.=" AND $alias.$column=?"; $args[]=$f==='status'?$_GET[$f]:Util::bin($_GET[$f]);
        }
        return [$where,$args];
    }
    private function invalidateUser(string $id): void
    {
        $this->db->run('UPDATE users SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]);
        $this->db->run('UPDATE admin_accounts SET auth_version=auth_version+1 WHERE tenant_id=? AND user_id=?',[$this->tenant,$id]);
        $this->db->run('UPDATE admin_sessions s JOIN admin_accounts a ON a.tenant_id=s.tenant_id AND a.id=s.admin_id SET s.revoked_at=UTC_TIMESTAMP(6) WHERE a.tenant_id=? AND a.user_id=? AND s.revoked_at IS NULL',[$this->tenant,$id]);
        $this->db->run('UPDATE device_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND user_id=? AND revoked_at IS NULL',[$this->tenant,$id]);
        $this->db->run('UPDATE tenants SET auth_version=auth_version+1 WHERE tenant_id=?',[$this->tenant]);
    }
    private function assignment(string $id,array $v): void
    {
        $at=$this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'];
        $this->db->run('UPDATE user_assignments SET ends_at=? WHERE tenant_id=? AND user_id=? AND ends_at IS NULL',[$at,$this->tenant,$id]);
        $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?)',[$this->tenant,Util::bin(Util::uuid()),$id,$v['firm_id'],$v['site_id'],$v['area_id'],$v['position_id'],$v['schedule_id'],$at]);
    }
    private function users(string $op,?string $id,?object $b): array
    {
        if ($op==='listUsers') { [$where,$args]=$this->filterUsers(); return $this->page("SELECT u.* FROM users u WHERE u.tenant_id=? AND $where",[$this->tenant,...$args],fn($x)=>$this->userDto($x)); }
        if ($id) { $this->access->user($id); $old=$this->row('users',$id); if ($op==='getUser') { return $this->result($this->userDto($old)); } $this->match($old); }
        if ($op==='setUserRoles') {
            $this->unique($b->role_ids);
            $oldIds=array_column($this->db->run('SELECT role_id FROM user_roles WHERE tenant_id=? AND user_id=?',[$this->tenant,$id])->fetchAll(),'role_id');
            foreach (array_unique([...$b->role_ids,...array_map([Util::class,'id'],$oldIds)]) as $rid) {
                $role=$this->row('roles',Util::bin($rid)); $dto=$this->roleDto($role);
                if (in_array($rid,$b->role_ids,true) && !$role['active']) { throw new ApiError(409,'invalid_transition'); }
                $this->access->delegate($dto['permissions'],(object)$dto['scope']);
            }
            $this->db->run('DELETE FROM user_roles WHERE tenant_id=? AND user_id=?',[$this->tenant,$id]);
            foreach ($b->role_ids as $rid) { $this->db->run('INSERT INTO user_roles (tenant_id,user_id,role_id) VALUES (?,?,?)',[$this->tenant,$id,Util::bin($rid)]); }
            $this->db->run('UPDATE users SET version=version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]); $this->invalidateUser($id);
        } else {
            $values=[];
            foreach (get_object_vars($b) as $f=>$value) { $values[$f]=$value!==null && str_ends_with($f,'_id')?Util::bin($value):$value; }
            foreach (['firm','site','area','position'] as $kind) { if (isset($values[$kind.'_id'])) { $this->org($values[$kind.'_id'],$kind); } }
            if (isset($values['schedule_id'])) { $this->row('schedules',$values['schedule_id']); }
            $next=($values+($old??[]));
            if (!$this->access->full()) {
                $allowed=false;
                foreach (['area','site'] as $kind) {
                    if (!$this->access->hasScopeKind($kind)) { continue; }
                    $field=$kind.'_id'; $target=$next[$field]??null;
                    if ($target===null) { continue; }
                    $within=$this->access->allowsScope((object)['kind'=>$kind,'resource_ids'=>[Util::id($target)]]);
                    if (array_key_exists($field,$values) && !$within) { throw new ApiError(403,'permission_denied'); }
                    $allowed=$allowed || $within;
                }
                if (!$allowed) { throw new ApiError(403,'permission_denied'); }
                $p=$next['area_id']??null; $seen=[];
                while ($p!==null) {
                    if (in_array($p,$seen,true)) { throw new ApiError(422,'validation_failed'); } $seen[]=$p;
                    $unit=$this->row('org_units',$p);
                    if ($unit['kind']==='site' && $unit['id']!==($next['site_id']??null)) { throw new ApiError(422,'validation_failed'); }
                    $p=$unit['parent_id'];
                }
            }
            if (!$id) {
                $id=Util::bin(Util::uuid()); $columns=implode(',',array_keys($values)); $marks=implode(',',array_fill(0,count($values),'?'));
                $this->db->run("INSERT INTO users (tenant_id,id,$columns) VALUES (?,?,$marks)",[$this->tenant,$id,...array_values($values)]);
                $role=$this->db->one("SELECT id FROM roles WHERE tenant_id=? AND seed_key='colaborador' AND active=TRUE",[$this->tenant]);
                if (!$role) { $role=$this->db->one("SELECT id FROM roles WHERE tenant_id=? AND seed_key='collaborator' AND active=TRUE",[$this->tenant]); }
                if ($role) { $this->db->run('INSERT INTO user_roles (tenant_id,user_id,role_id) VALUES (?,?,?)',[$this->tenant,$id,$role['id']]); }
                $this->assignment($id,$next);
            } else {
                $this->update('users',$id,$values);
                if (array_intersect(array_keys($values),['firm_id','site_id','area_id','position_id','schedule_id'])) { $this->assignment($id,$next); }
                $this->invalidateUser($id);
                if (($b->status??null)==='inactive') {
                    $this->db->run("UPDATE enrollments SET status='revoked' WHERE tenant_id=? AND user_id=? AND status='pending'",[$this->tenant,$id]);
                    $this->db->run("UPDATE devices SET status='revoked',auth_version=auth_version+1,version=version+1 WHERE tenant_id=? AND user_id=? AND status='active'",[$this->tenant,$id]);
                    $this->db->run("UPDATE device_command c JOIN devices d ON d.tenant_id=c.tenant_id AND d.id=c.device_id SET c.status='cancelled' WHERE d.tenant_id=? AND d.user_id=? AND c.status IN ('pending','delivered')",[$this->tenant,$id]);
                }
            }
        }
        $this->audit('user',$id,array_keys(get_object_vars($b))); $this->dirty();
        return $this->result($this->userDto($this->row('users',$id)),$op==='createUser'?201:200);
    }
    private function roleDto(array $r): array
    {
        $permissions=array_column($this->db->run('SELECT permission_code FROM role_permissions WHERE tenant_id=? AND role_id=? ORDER BY permission_code',[$this->tenant,$r['id']])->fetchAll(),'permission_code');
        $ids=[]; $kind=$r['scope_kind'];
        if (in_array($kind,['area','site'],true)) { $ids=array_map([Util::class,'id'],array_column($this->db->run("SELECT {$kind}_id id FROM role_{$kind}_scopes WHERE tenant_id=? AND role_id=? ORDER BY {$kind}_id",[$this->tenant,$r['id']])->fetchAll(),'id')); }
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'permissions'=>$permissions,'scope'=>['kind'=>$kind,'resource_ids'=>$ids],'active'=>(bool)$r['active'],'seed_key'=>$r['seed_key'],'version'=>(int)$r['version']];
    }
    private function roles(string $op,?string $id,?object $b): array
    {
        if ($op==='listRoles') { $this->access->requireFull(); return $this->page('SELECT * FROM roles WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->roleDto($x)); }
        if ($id) { $old=$this->row('roles',$id); $dto=$this->roleDto($old); if (!$this->access->allowsScope((object)$dto['scope'])) { throw new ApiError(404,'resource_not_found'); } if ($op==='getRole') { return $this->result($dto); } $this->match($old); $this->access->delegate($dto['permissions'],(object)$dto['scope']); }
        $this->unique($b->permissions); $this->unique($b->scope->resource_ids);
        $kind=$b->scope->kind;
        if ((in_array($kind,['tenant','self'],true) && $b->scope->resource_ids) || (in_array($kind,['area','site'],true) && !$b->scope->resource_ids)) { throw new ApiError(422,'validation_failed'); }
        foreach ($b->scope->resource_ids as $rid) { $this->org(Util::bin($rid),$kind); }
        $this->access->delegate($b->permissions,$b->scope);
        if ($id) {
            foreach (['role_area_scopes','role_site_scopes','role_permissions'] as $table) { $this->db->run("DELETE FROM $table WHERE tenant_id=? AND role_id=?",[$this->tenant,$id]); }
            $this->update('roles',$id,['name'=>$b->name,'scope_kind'=>$kind,'active'=>(int)$b->active]);
        } else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO roles (tenant_id,id,name,scope_kind,active) VALUES (?,?,?,?,?)',[$this->tenant,$id,$b->name,$kind,(int)$b->active]); }
        foreach ($b->permissions as $permission) { $this->db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)',[$this->tenant,$id,$permission]); }
        foreach ($b->scope->resource_ids as $rid) { $this->db->run("INSERT INTO role_{$kind}_scopes (tenant_id,role_id,{$kind}_id) VALUES (?,?,?)",[$this->tenant,$id,Util::bin($rid)]); }
        foreach ($this->db->run('SELECT user_id FROM user_roles WHERE tenant_id=? AND role_id=?',[$this->tenant,$id])->fetchAll() as $user) { $this->invalidateUser($user['user_id']); }
        $this->db->run('UPDATE tenants SET auth_version=auth_version+1 WHERE tenant_id=?',[$this->tenant]);
        $this->audit('role',$id,array_keys(get_object_vars($b))); return $this->result($this->roleDto($this->row('roles',$id)),$op==='createRole'?201:200);
    }
    private function permissionDto(array $r): array { return ['code'=>$r['code'],'label'=>$r['label'],'resource'=>$r['resource'],'action'=>$r['action'],'delegable'=>(bool)$r['delegable'],'version'=>(int)$r['version']]; }
    private function permissions(string $op,?object $b): array
    {
        if ($op==='listPermissions') { return $this->page('SELECT * FROM permissions',[],fn($x)=>$this->permissionDto($x),'code','code'); }
        $code=$this->r->resourceId; $row=$this->db->one('SELECT * FROM permissions WHERE code=? FOR UPDATE',[$code]); if (!$row) { throw new ApiError(404,'resource_not_found'); }
        if ($op==='patchPermission') {
            $this->match($row);
            if ($b->delegable && ($row['platform_only'] || $code==='roles.gestionar')) { throw new ApiError(403,'permission_denied'); }
            $this->db->run('UPDATE permissions SET label=?,delegable=?,version=version+1 WHERE code=?',[$b->label,(int)$b->delegable,$code]);
            $this->audit('permission',substr(hash('sha256',$code,true),0,16),['label','delegable']);
            $row=$this->db->one('SELECT * FROM permissions WHERE code=?',[$code]);
        }
        return $this->result($this->permissionDto($row));
    }
    private function deviceDto(array $r): array
    {
        $out=[]; foreach (['id','tenant_id','user_id'] as $k) { $out[$k]=Util::id($r[$k]); }
        foreach (['hostname','status','agent_version','os_edition','cpu','encryption_state'] as $k) { $out[$k]=$r[$k]; }
        $out+=['last_seen_at'=>$r['last_seen_at']===null?null:Util::time($r['last_seen_at']),'ram_bytes'=>(int)$r['ram_bytes'],'capabilities'=>json_decode($r['capabilities']),'policy_version'=>$r['policy_version']===null?null:(int)$r['policy_version'],'version'=>(int)$r['version']]; return $out;
    }
    private function revokeDevice(string $id): void
    {
        $this->db->run('UPDATE devices SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]);
        foreach (['device_sessions','device_keys'] as $table) { $this->db->run("UPDATE $table SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND revoked_at IS NULL",[$this->tenant,$id]); }
        $this->db->run("UPDATE enrollments SET status='revoked' WHERE tenant_id=? AND device_id=? AND status='pending'",[$this->tenant,$id]);
        $this->db->run("UPDATE device_command SET status='cancelled' WHERE tenant_id=? AND device_id=? AND status IN ('pending','delivered')",[$this->tenant,$id]);
    }
    private function commandDto(array $r): array
    {
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'device_id'=>Util::id($r['device_id']),'type'=>$r['type'],'status'=>$r['status'],'created_at'=>Util::time($r['created_at']),'expires_at'=>Util::time($r['expires_at']),'result'=>$r['completed_at']===null?null:['code'=>$r['result_code'],'completed_at'=>Util::time($r['completed_at'])]];
    }
    private function devices(string $op,?string $id,?object $b): array
    {
        if ($op==='listTenantDevices') { [$where,$args]=$this->filterUsers(); return $this->page("SELECT d.* FROM devices d JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id WHERE d.tenant_id=? AND $where",[$this->tenant,...$args],fn($x)=>$this->deviceDto($x)); }
        if ($op==='createEnrollment') { return $this->enrollment($b); }
        $d=$this->authorizedDevice??$this->row('devices',$id);
        if ($this->deviceUser===null) { $this->deviceUser=$this->access->user($d['user_id']); }
        if ($op==='getDevice') { return $this->result($this->deviceDto($d)); }
        if ($op==='listAdminCommands') { return $this->page('SELECT * FROM device_command WHERE tenant_id=? AND device_id=?',[$this->tenant,$id],fn($x)=>$this->commandDto($x)); }
        if ($op==='createDeviceCommand') {
            if ($d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            $expires=Util::sqlTime($b->expires_at); $delta=strtotime($b->expires_at)-time();
            if ($delta<=0 || $delta>86400 || trim($b->reason)==='') { throw new ApiError(422,'validation_failed'); }
            if ($b->type==='wipe') { $this->reauth(); if (($b->confirmation??'')!==$d['hostname']) { throw new ApiError(422,'validation_failed'); } }
            $seq=(int)$this->db->one('SELECT COALESCE(MAX(sequence),0)+1 n FROM device_command WHERE tenant_id=? AND device_id=?',[$this->tenant,$id])['n']; $cid=Util::bin(Util::uuid());
            $seq=max($seq,(int)$d['command_sequence']+1);
            $this->db->run('UPDATE devices SET command_sequence=? WHERE tenant_id=? AND id=?',[$seq,$this->tenant,$id]);
            $this->db->run('INSERT INTO device_command (tenant_id,id,device_id,sequence,type,reason,created_at,expires_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),?)',[$this->tenant,$cid,$id,$seq,$b->type,$b->reason,$expires]);
            $this->audit('device_command',$cid,['type','device_id','expires_at']); return self::response($this->commandDto($this->row('device_command',$cid)),202);
        }
        $this->match($d);
        if ($op==='assignDevice') {
            $target=Util::bin($b->user_id); $u=$target===$this->deviceUser['id']?$this->deviceUser:$this->access->user($target); if ($u['status']!=='active' || $d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            $at=$this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'];
            $this->db->run('UPDATE device_assignments SET ends_at=? WHERE tenant_id=? AND device_id=? AND ends_at IS NULL',[$at,$this->tenant,$id]);
            $this->db->run('INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,?,?)',[$this->tenant,Util::bin(Util::uuid()),$id,$u['id'],$at,$b->reason]);
            $this->update('devices',$id,['user_id'=>$u['id']]); $this->revokeDevice($id); $this->dirty();
        } else {
            if (isset($b->status) && $b->status==='active' && $d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            $this->update('devices',$id,get_object_vars($b)); if (isset($b->status) && $b->status!=='active') { $this->revokeDevice($id); }
        }
        $this->audit('device',$id,array_keys(get_object_vars($b))); return $this->result($this->deviceDto($this->row('devices',$id)));
    }
    private function reauth(): void
    {
        if ($this->c['reauthenticated_at']===null || strtotime($this->c['reauthenticated_at'].' UTC')<time()-300) { throw new ApiError(403,'reauth_required'); }
    }
    private function enrollment(object $b): array
    {
        $u=$this->access->user(Util::bin($b->user_id)); if ($u['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
        $device=isset($b->device_id)?Util::bin($b->device_id):null;
        if ($device) {
            $this->reauth();
            $d=$this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=? FOR UPDATE',[$this->tenant,$device]);
            if ($d===null) {
                // Modelo B: el aprovisionamiento fija el device_id. Si el device aún no existe se crea
                // aquí (para poder pre-asignarle reglas antes de que el equipo enrole); queda 'active'
                // por defecto y el login por ticket lo toma como device del propio user.
                $this->db->run("INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,'pending','0.0.0','unknown','unknown',0,'[]')",[$this->tenant,$device,$u['id']]);
                $this->db->run("INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP(6),'enrollment')",[$this->tenant,Util::bin(Util::uuid()),$device,$u['id']]);
                $this->db->run("INSERT INTO principals (tenant_id,id,kind,device_id) VALUES (?,?,'device',?)",[$this->tenant,Util::bin(Util::uuid()),$device]);
                (new Audit($this->db))->record($this->c,$this->r,'device.created','device',$device,['user_id']);
            } else {
                $this->access->user($d['user_id']);
                if ($d['user_id']!==$u['id'] || $d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            }
        }
        try { $thumb=Util::unb64($b->public_key_thumbprint); } catch (ApiError) { throw new ApiError(422,'validation_failed'); }
        if (strlen($thumb)!==32) { throw new ApiError(422,'validation_failed'); }
        $ticket=Util::b64(random_bytes(32)); $id=Util::bin(Util::uuid());
        $this->db->run("INSERT INTO enrollments (tenant_id,id,user_id,device_id,ticket_hash,public_key_thumbprint,reason,created_at,expires_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 10 MINUTE)",[$this->tenant,$id,$u['id'],$device,hash('sha256',$ticket,true),$thumb,$b->reason]);
        $this->audit('enrollment',$id,['user_id','device_id']);
        return self::response(['ticket'=>$ticket,'expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)],201);
    }
    private function policyDto(array $r): array
    {
        $a=$this->db->one('SELECT * FROM policy_assignments WHERE tenant_id=? AND policy_id=? ORDER BY id LIMIT 1',[$this->tenant,$r['id']]);
        $v=$this->db->one('SELECT document FROM policy_versions WHERE tenant_id=? AND policy_id=? AND policy_version=?',[$this->tenant,$r['id'],$r['version']]);
        if (!$a || !$v || $a['scope']==='global') { throw new ApiError(404,'resource_not_found'); }
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'target_type'=>$a['scope'],'target_id'=>Util::id($a['scope_target']),'enabled'=>(bool)$r['enabled'],'rules'=>json_decode($v['document'])->rules,'version'=>(int)$r['version']];
    }
    private function policyTarget(string $kind,string $target): void
    {
        if ($kind==='tenant') { if ($target!==$this->tenant) { throw new ApiError(404,'resource_not_found'); } $this->access->requireFull(); }
        elseif ($kind==='user') { $this->access->user($target); }
        elseif ($kind==='device') { $d=$this->row('devices',$target); $this->access->user($d['user_id']); }
        else { $this->org($target,$kind); if (!$this->access->allowsScope((object)['kind'=>$kind,'resource_ids'=>[Util::id($target)]])) { throw new ApiError(404,'resource_not_found'); } }
    }
    private function validateRules(array $rules): void
    {
        $this->unique(array_map(static fn($x)=>$x->id,$rules));
        $host=strtolower(parse_url(Config::get('ORIGIN'),PHP_URL_HOST));
        foreach ($rules as $rule) {
            if ($rule->schedule_id!==null) { $this->row('schedules',Util::bin($rule->schedule_id)); }
            $this->unique($rule->targets);
            foreach ($rule->targets as $target) {
                $pattern=match($rule->kind) {
                    'web'=>'/^(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD',
                    'download'=>'/^\.?[a-z0-9]{1,20}$/iD',
                    default=>'/^[a-z0-9][a-z0-9._:\\-]{0,254}$/iD'
                };
                if (!preg_match($pattern,$target)) { throw new ApiError(422,'validation_failed'); }
                $domain=strtolower(ltrim($target,'*.'));
                if ($rule->kind==='web' && $rule->effect==='deny' && ($domain===$host || str_ends_with($host,'.'.$domain))) { throw new ApiError(422,'validation_failed'); }
            }
        }
    }
    private function policies(string $op,?string $id,?object $b): array
    {
        if ($op==='listPolicies') { $this->access->requireFull(); return $this->page("SELECT p.* FROM policy_documents p WHERE p.tenant_id=? AND EXISTS (SELECT 1 FROM policy_assignments a WHERE a.tenant_id=p.tenant_id AND a.policy_id=p.id AND a.scope<>'global')",[$this->tenant],fn($x)=>$this->policyDto($x)); }
        if ($id) { $old=$this->row('policy_documents',$id); $dto=$this->policyDto($old); $this->policyTarget($dto['target_type'],Util::bin($dto['target_id'])); if ($op==='getPolicy') { return $this->result($dto); } $this->match($old); }
        $target=Util::bin($b->target_id); $this->policyTarget($b->target_type,$target); $this->validateRules($b->rules);
        if ($id) { $this->update('policy_documents',$id,['name'=>$b->name,'enabled'=>(int)$b->enabled]); }
        else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO policy_documents (tenant_id,id,name,enabled) VALUES (?,?,?,?)',[$this->tenant,$id,$b->name,(int)$b->enabled]); }
        $version=(int)$this->row('policy_documents',$id)['version'];
        $doc=Util::canonical($b);
        $this->db->run('INSERT INTO policy_versions (tenant_id,policy_id,policy_version,document,content_hash) VALUES (?,?,?,?,?)',[$this->tenant,$id,$version,$doc,hash('sha256',$doc,true)]);
        foreach ($b->rules as $rule) { $this->db->run('INSERT INTO policy_rules (tenant_id,id,policy_id,policy_version,kind,effect,schedule_id,priority,targets) VALUES (?,?,?,?,?,?,?,?,?)',[$this->tenant,Util::bin($rule->id),$id,$version,$rule->kind,$rule->effect,$rule->schedule_id===null?null:Util::bin($rule->schedule_id),$rule->priority,Util::json($rule->targets)]); }
        if (isset($dto) && $dto['target_type']===$b->target_type && Util::bin($dto['target_id'])===$target) {
            $this->db->run('UPDATE policy_assignments SET policy_version=?,enabled=? WHERE tenant_id=? AND policy_id=?',[$version,(int)$b->enabled,$this->tenant,$id]);
        } else {
            $this->db->run('DELETE FROM policy_assignments WHERE tenant_id=? AND policy_id=?',[$this->tenant,$id]);
            $priority=(int)$this->db->one('SELECT COALESCE(MAX(priority),-1)+1 n FROM policy_assignments WHERE tenant_id=? AND scope=? AND scope_target=?',[$this->tenant,$b->target_type,$target])['n'];
            if ($priority>10000) { throw new ApiError(409,'invalid_transition'); }
            $this->db->run('INSERT INTO policy_assignments (tenant_id,id,policy_id,policy_version,scope,area_id,site_id,user_id,device_id,priority,enabled) VALUES (?,?,?,?,?,?,?,?,?,?,?)',[$this->tenant,Util::bin(Util::uuid()),$id,$version,$b->target_type,$b->target_type==='area'?$target:null,$b->target_type==='site'?$target:null,$b->target_type==='user'?$target:null,$b->target_type==='device'?$target:null,$priority,(int)$b->enabled]);
        }
        $this->audit('policy',$id,['name','enabled','rules','assignments','version']); $this->dirty();
        return $this->result($this->policyDto($this->row('policy_documents',$id)),$op==='createPolicy'?201:200);
    }
    private function tierDto(array $r): array
    {
        $modules=array_column($this->db->run('SELECT module_code FROM tier_module WHERE tenant_id=? AND tier_id=? ORDER BY module_code',[$this->tenant,$r['id']])->fetchAll(),'module_code');
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'badge_label'=>$r['badge_label'],'entitlements'=>$modules,'active'=>(bool)$r['active'],'version'=>(int)$r['version']];
    }
    private function tiers(string $op,?string $id,?object $b): array
    {
        $this->access->requireFull();
        if ($op==='listTiers') { return $this->page('SELECT * FROM tier WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->tierDto($x)); }
        if ($id) { $old=$this->row('tier',$id); if ($op==='getTier') { return $this->result($this->tierDto($old)); } $this->match($old); }
        $this->unique($b->entitlements);
        foreach ($b->entitlements as $module) { if (!$this->db->one('SELECT code FROM modules WHERE code=? AND active=TRUE',[$module])) { throw new ApiError(422,'validation_failed'); } }
        if ($id) { $this->update('tier',$id,['name'=>$b->name,'badge_label'=>$b->badge_label,'active'=>(int)$b->active]); $this->db->run('DELETE FROM tier_module WHERE tenant_id=? AND tier_id=?',[$this->tenant,$id]); }
        else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO tier (tenant_id,id,name,badge_label,active) VALUES (?,?,?,?,?)',[$this->tenant,$id,$b->name,$b->badge_label,(int)$b->active]); }
        foreach ($b->entitlements as $module) { $this->db->run('INSERT INTO tier_module (tenant_id,tier_id,module_code) VALUES (?,?,?)',[$this->tenant,$id,$module]); }
        $this->audit('tier',$id,array_keys(get_object_vars($b))); $this->dirty(); return $this->result($this->tierDto($this->row('tier',$id)),$op==='createTier'?201:200);
    }
    private function currentSubscription(string $user,bool $includeFuture=false): ?array
    {
        return $this->db->one("SELECT * FROM subscriptions WHERE tenant_id=? AND user_id=? AND status IN ('active','scheduled')".($includeFuture?'':' AND starts_at<=UTC_TIMESTAMP(6)')." AND (ends_at IS NULL OR ends_at>UTC_TIMESTAMP(6)) ORDER BY starts_at DESC LIMIT 1".($includeFuture?' FOR UPDATE':''),[$this->tenant,$user]);
    }
    private function subscriptionDto(array $r): array
    {
        $status=$r['status']==='scheduled' && strtotime($r['starts_at'].' UTC')<=time()?'active':$r['status'];
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'user_id'=>Util::id($r['user_id']),'tier_id'=>Util::id($r['tier_id']),'starts_at'=>Util::time($r['starts_at']),'ends_at'=>$r['ends_at']===null?null:Util::time($r['ends_at']),'status'=>$status,'version'=>(int)$r['version']];
    }
    private function subscription(string $op,string $user,?object $b): array
    {
        $this->access->user($user); $u=$this->row('users',$user); $current=$this->currentSubscription($user,true);
        if ($op==='getSubscription') { if (!$current) { throw new ApiError(404,'resource_not_found'); } return $this->result($this->subscriptionDto($current)); }
        $this->match($current??['version'=>0]);
        $tier=$this->row('tier',Util::bin($b->tier_id)); if (!$tier['active'] || $u['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
        $start=Util::sqlTime($b->starts_at); $end=$b->ends_at===null?null:Util::sqlTime($b->ends_at);
        if ($end!==null && $end<=$start) { throw new ApiError(422,'validation_failed'); }
        if ($current) {
            if ($start<=$current['starts_at']) { throw new ApiError(409,'invalid_transition'); }
            if ($current['ends_at']===null || $current['ends_at']>$start) { $this->db->run('UPDATE subscriptions SET ends_at=?,version=version+1 WHERE tenant_id=? AND id=?',[$start,$this->tenant,$current['id']]); }
        }
        $id=Util::bin(Util::uuid());
        $status=strtotime($b->starts_at)>time()?'scheduled':'active';
        $this->db->run('INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at,ends_at,status,version) VALUES (?,?,?,?,?,?,?,?)',[$this->tenant,$id,$user,$tier['id'],$start,$end,$status,(int)($current['version']??0)+1]);
        $this->audit('subscription',$id,['tier_id','starts_at','ends_at']); $this->dirty();
        return $this->result($this->subscriptionDto($this->row('subscriptions',$id)));
    }
    private function releaseDto(array $r): array
    {
        $out=[]; foreach (['version','channel','min_agent_version','architecture','artifact_url','key_id','manifest_jws'] as $f) { $out[$f]=$r[$f]; }
        return $out+['id'=>Util::id($r['id']),'sequence'=>(int)$r['sequence'],'size_bytes'=>(int)$r['size_bytes'],'sha256'=>bin2hex($r['sha256']),'published_at'=>Util::time($r['published_at'])];
    }
    private function releases(string $op,?object $b): array
    {
        $this->access->requireFull(); $platform=Util::bin(AdminAuth::PLATFORM);
        if ($op==='listReleases') {
            $where=$this->c['platform']?'':' AND EXISTS (SELECT 1 FROM release_deployments d WHERE d.tenant_id=? AND d.release_tenant_id=r.tenant_id AND d.release_id=r.id)';
            return $this->page('SELECT r.* FROM client_releases r WHERE r.tenant_id=?'.$where,$this->c['platform']?[$platform]:[$platform,$this->tenant],fn($x)=>$this->releaseDto($x),'published_at');
        }
        if ($op==='registerRelease') {
            if (parse_url($b->artifact_url,PHP_URL_SCHEME)!=='https' || $b->key_id==='' || $b->manifest_jws==='') { throw new ApiError(422,'validation_failed'); }
            $manifest=get_object_vars($b); $manifest['id']=Util::id(Util::bin($b->id)); $manifest['published_at']=Util::time(Util::sqlTime($b->published_at));
            try { (new Resources($this->db,$this->c,$this->validator))->verifyRelease($manifest); } catch (ApiError) { throw new ApiError(422,'validation_failed'); }
            $id=Util::bin($b->id);
            $this->db->run('INSERT INTO client_releases (tenant_id,id,version,channel,sequence,min_agent_version,architecture,artifact_url,size_bytes,sha256,key_id,manifest_jws,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',[$platform,$id,$b->version,$b->channel,$b->sequence,$b->min_agent_version,$b->architecture,$b->artifact_url,$b->size_bytes,hex2bin($b->sha256),$b->key_id,$b->manifest_jws,Util::sqlTime($b->published_at)]);
            $this->audit('release',$id,['version','sha256','key_id','manifest_jws']); return self::response($manifest,201);
        }
        $id=Util::bin($b->release_id);
        if (!$this->db->one('SELECT id FROM client_releases WHERE tenant_id=? AND id=?',[$platform,$id])) { throw new ApiError(404,'resource_not_found'); }
        if (!$this->c['platform'] && !$this->db->one('SELECT release_id FROM release_deployments WHERE tenant_id=? AND release_id=?',[$this->tenant,$id])) { throw new ApiError(404,'resource_not_found'); }
        $this->db->run('INSERT INTO release_deployments (tenant_id,release_id,ring,percentage,enabled) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE percentage=VALUES(percentage),enabled=VALUES(enabled)',[$this->tenant,$id,$b->ring,$b->percentage,(int)$b->enabled]);
        $this->audit('release_deployment',$id,['ring','percentage','enabled']); return self::response($b,201);
    }
    private function dates(int $maximum=366): array
    {
        $from=$_GET['from']; $to=$_GET['to']; $days=(strtotime($to)-strtotime($from))/86400;
        if ($days<1 || $days>$maximum) { throw new ApiError(422,'validation_failed'); } return [$from,$to];
    }
    private function report(): array
    {
        [$from,$to]=$this->dates(); [$scope,$args]=$this->access->usersSql('a','user_id');
        $filters=''; $fargs=[];
        foreach (['area_id','site_id','user_id'] as $f) {
            if (!isset($_GET[$f])) { continue; } $id=Util::bin($_GET[$f]);
            if ($f==='user_id') { $this->access->user($id); } else { $this->org($id,substr($f,0,-3)); }
            $filters.=" AND a.$f=?"; $fargs[]=$id;
        }
        $base="FROM %s x JOIN user_assignments a ON a.tenant_id=x.tenant_id AND a.id=x.user_assignment_id AND a.user_id=x.user_id JOIN users u ON u.tenant_id=x.tenant_id AND u.id=x.user_id WHERE x.tenant_id=? AND x.day>=? AND x.day<? AND $scope$filters";
        $params=[$this->tenant,$from,$to,...$args,...$fargs];
        $summary=$this->db->run('SELECT x.day,SUM(x.active_seconds) active_seconds,SUM(x.idle_seconds) idle_seconds,SUM(x.productive_seconds) productive_seconds,SUM(x.expected_seconds) expected_seconds,MIN(x.calculated_at) calculated_at,MIN(x.data_through) data_through,GROUP_CONCAT(DISTINCT x.calculation_version) calculation_version '.sprintf($base,'day_summary').' GROUP BY x.day ORDER BY x.day',$params)->fetchAll();
        $episodes=$this->db->run("SELECT x.day,SUM(x.active_seconds) active_seconds,SUM(x.idle_seconds) idle_seconds,SUM(IF(x.category='productive',x.active_seconds,0)) productive_seconds,MIN(x.calculated_at) calculated_at,MIN(x.data_through) data_through,GROUP_CONCAT(DISTINCT x.calculation_version) calculation_version ".sprintf($base,'episode_daily').' GROUP BY x.day ORDER BY x.day',$params)->fetchAll();
        $focus=$this->db->run('SELECT x.day,AVG(x.focus_score) score,MIN(x.calculated_at) calculated_at,MIN(x.data_through) data_through '.sprintf($base,'focus_daily').' GROUP BY x.day',$params)->fetchAll();
        $rows=[]; foreach ($episodes as $row) { $rows[$row['day']]=$row; } foreach ($summary as $row) { $rows[$row['day']]=$row; } ksort($rows);
        $scores=array_column($focus,'score','day'); $series=[]; $calculated=null; $through=null; $versions=[]; $active=0; $expected=0;
        foreach ($rows as $day=>$row) {
            $a=(int)$row['active_seconds']; $i=(int)$row['idle_seconds'];
            if (isset($row['expected_seconds'])) { $active+=$a; $expected+=(int)$row['expected_seconds']; }
            $series[]=['day'=>$day,'active_seconds'=>$a,'idle_seconds'=>$i,'focus_score'=>isset($scores[$day])?(float)$scores[$day]:null,'productivity_percent'=>$a>0?min(100,round(100*(int)$row['productive_seconds']/$a,2)):null];
            $calculated=$calculated===null?$row['calculated_at']:min($calculated,$row['calculated_at']); $through=$through===null?$row['data_through']:min($through,$row['data_through']);
            foreach (explode(',',$row['calculation_version']) as $version) { $versions[$version]=true; }
        }
        foreach ($focus as $row) { if ($calculated!==null) { $calculated=min($calculated,$row['calculated_at']); $through=min($through,$row['data_through']); } }
        return self::response(['tenant_id'=>Util::id($this->tenant),'from'=>$from,'to'=>$to,'timezone'=>$this->c['timezone'],'calculated_at'=>$calculated===null?null:Util::time($calculated),'data_through'=>$through===null?null:Util::time($through),'calculation_version'=>count($versions)===1?substr(array_key_first($versions),0,80):($versions?'mixed':'unavailable'),'coverage_percent'=>$expected>0?min(100,round(100*$active/$expected,2)):0,'series'=>$series]);
    }
    private function auditPage(): array
    {
        $this->access->requireFull(); [$from,$to]=$this->dates(366);
        $zone=new \DateTimeZone($this->c['timezone']); $start=Util::sqlTime((new \DateTimeImmutable($from,$zone))->format('c')); $end=Util::sqlTime((new \DateTimeImmutable($to,$zone))->format('c'));
        return $this->page('SELECT * FROM audit_log WHERE tenant_id=? AND at>=? AND at<?',[$this->tenant,$start,$end],static function($r) {
            $out=[]; foreach (['id','tenant_id','actor_id','resource_id','request_id'] as $f) { $out[$f]=Util::id($r[$f]); }
            foreach (['actor_type','action','resource_type','outcome'] as $f) { $out[$f]=$r[$f]; }
            return $out+['at'=>Util::time($r['at']),'changed_fields'=>json_decode($r['changed_fields'])];
        },'at');
    }
    private function scheduleDto(array $r): array
    {
        $days=$this->db->run('SELECT weekday FROM schedule_days WHERE tenant_id=? AND schedule_id=? ORDER BY weekday',[$this->tenant,$r['id']])->fetchAll();
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'timezone'=>$r['timezone'],'days'=>array_map('intval',array_column($days,'weekday')),'start_local'=>substr($r['start_local'],0,5),'end_local'=>substr($r['end_local'],0,5),'version'=>(int)$r['version']];
    }
    private function schedules(string $op,?string $id,?object $b): array
    {
        $this->access->requireFull();
        if ($op==='listSchedules') { return $this->page('SELECT * FROM schedules WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->scheduleDto($x)); }
        if ($id) { $old=$this->row('schedules',$id); if ($op==='getSchedule') { return $this->result($this->scheduleDto($old)); } $this->match($old); }
        $this->timezone($b->timezone); $this->unique($b->days);
        $values=['name'=>$b->name,'timezone'=>$b->timezone,'start_local'=>$b->start_local,'end_local'=>$b->end_local];
        if ($id) { $this->update('schedules',$id,$values); $this->db->run('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=?',[$this->tenant,$id]); }
        else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local) VALUES (?,?,?,?,?,?)',[$this->tenant,$id,...array_values($values)]); }
        foreach ($b->days as $day) { $this->db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday) VALUES (?,?,?)',[$this->tenant,$id,$day]); }
        $this->audit('schedule',$id,array_keys(get_object_vars($b))); $this->dirty();
        return $this->result($this->scheduleDto($this->row('schedules',$id)),$op==='createSchedule'?201:200);
    }
}
