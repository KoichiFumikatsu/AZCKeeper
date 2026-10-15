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
        // Permisos de la propia cuenta (menu del panel): cualquier sesion valida de la empresa los puede leer.
        if (in_array($r->operation['operationId'],['getMyAccess','getMe'],true)) { return; }
        if ($r->operation['operationId']==='patchDevice' && (property_exists($r->body,'release_ring') || property_exists($r->body,'release_pause_reason')) && !$c['platform']) { throw new ApiError(403,'permission_denied'); }
        if ($r->operation['operationId']==='createDeviceCommand') {
            $permission=match($r->body->type) { 'lock'=>'equipos.bloquear','unlock'=>'equipos.desbloquear','restart'=>'equipos.reiniciar','shutdown'=>'equipos.apagar','wipe'=>'equipos.borrar','refresh_policy'=>'reglas.aplicar','rename_computer'=>'equipos.editar',default=>throw new ApiError(422,'validation_failed') };
        }
        // Lote de personas: el permiso del paso principal (datos > acceso > plan); los demas pasos se exigen en batchUsers().
        if ($r->operation['operationId']==='batchUpdateUsers' && !isset($r->body->changes)) { $permission=isset($r->body->access)?'roles.gestionar':(isset($r->body->plan)?'planes.asignar':$permission); }
        // Puesta en marcha: cualquier permiso de administracion del cliente (Onboarding::permission); el alcance se exige alli.
        if (in_array($r->operation['operationId'],Onboarding::OPERATIONS,true)) { $permission=Onboarding::permission($db,$c); }
        $this->access=new AdminAccess($db,$c,$permission,$r->operation['operationId']);
        if ($permission==='roles.gestionar') { $this->access->rolesGate(); }
        if (in_array($r->operation['operationId'],['createDeviceCommand','createHardeningCommand','assignDevice'],true)) { $this->authorizedDevice=$this->row('devices',Util::bin($r->resourceId)); $this->deviceUser=$this->access->user($this->authorizedDevice['user_id']); }
        // El plan del DUEÑO del equipo gobierna las ordenes remotas (encargo 4): sin la funcion, 403 feature_not_in_plan auditado.
        if ($r->operation['operationId']==='createDeviceCommand' && !in_array($r->body->type,$this->allowedCommands($this->authorizedDevice),true)) {
            $feature=self::COMMAND_FEATURES[$r->body->type]; $device=$this->authorizedDevice['id'];
            $e=new ApiError(403,'feature_not_in_plan',[],$feature);
            $e->afterRollback=function () use ($db,$c,$r,$device,$feature): void {
                $c['principal_id']=(new AdminAuth($db,new RateLimiter()))->principal($c);
                (new Audit($db))->record($c,$r,'createDeviceCommand','device',$device,['type'],'denied',"feature_not_in_plan:$feature");
            };
            throw $e;
        }
        if ($r->operation['operationId']==='deployRelease') { $this->access->requireFull(); }
    }
    /** Funcion del plan que exige cada orden remota; las que no aparecen (refresh_policy, rename_computer) no dependen del plan. */
    public const COMMAND_FEATURES=['lock'=>'comandos','unlock'=>'comandos','restart'=>'comandos','shutdown'=>'comandos','wipe'=>'borrado'];
    private const COMMAND_TYPES=['lock','unlock','restart','shutdown','wipe','refresh_policy','rename_computer'];
    private array $deviceFunctions=[];
    /**
     * Ordenes que el plan permite para este equipo: las funciones del plan efectivo de su dueño (PersonPlans::functions);
     * sin dueño activo (persona retirada sin reasignar el equipo), las funciones del cliente (Features).
     */
    private function allowedCommands(array $d): array
    {
        $key=bin2hex((string)$d['user_id']);
        if (!isset($this->deviceFunctions[$key])) {
            $owner=$this->db->one('SELECT status FROM users WHERE tenant_id=? AND id=?',[$this->tenant,$d['user_id']]);
            $this->deviceFunctions[$key]=($owner['status']??null)==='active' ? PersonPlans::functions($this->db,$this->tenant,$d['user_id']) : Features::enabled($this->db,$this->tenant);
        }
        $functions=$this->deviceFunctions[$key];
        return array_values(array_filter(self::COMMAND_TYPES,fn($t)=>!isset(self::COMMAND_FEATURES[$t]) || in_array(self::COMMAND_FEATURES[$t],$functions,true)));
    }
    public static function response(mixed $body, int $status=200, array $headers=[]): array { return ['status'=>$status,'json'=>Util::json($body),'headers'=>$headers]; }
    // $devices: null = todo el tenant; lista (ids binarios) = solo esos equipos (ver recompileScope()).
    public static function compilePending(Database $db, string $tenant, ?array $devices=null): void
    {
        $q=$db->one('SELECT revision FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant]);
        if (!$q) { return; }
        try {
            $compiler=new PolicyCompiler($db);
            if ($devices===null) { $compiler->recompileForTenant(Util::id($tenant)); }
            else { foreach ($devices as $device) { $compiler->recompile(Util::id($device), Util::id($tenant)); } }
        }
        catch (\Throwable) { throw new ApiError(503,'temporarily_unavailable',['Retry-After'=>'5']); }
        $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=? AND revision=?',[$tenant,$q['revision']]);
    }
    public static function validatePending(Database $db,string $tenant,?array $devices=null): void
    {
        if (!$db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant])) { return; }
        try {
            $compiler=new PolicyCompiler($db);
            if ($devices===null) { $compiler->validateForTenant(Util::id($tenant)); } else { $compiler->validateDevices(Util::id($tenant),$devices); }
        }
        catch (PolicyConflict) { throw new ApiError(409,'invalid_transition'); }
    }
    // Recompilar el tenant entero costaba ~20 ms por equipo (medido: 10,6 s con 296 equipos) en CADA cambio de
    // admin, incluido crear una persona. Los cambios de una persona, su suscripcion o la asignacion de un equipo
    // solo afectan a sus equipos; el resto (tenant, organizacion, politicas, horarios) sigue siendo total.
    // Si al marcar ya habia trabajo pendiente de otra peticion (p. ej. una compilacion fallida) se hace total,
    // para no borrar ese pendiente tras compilar solo una parte.
    private bool $dirtied=false; private bool $recompileAll=false; private array $recompileDevices=[];
    private function dirty(?array $devices=null): void
    {
        if (!$this->dirtied && $this->db->one('SELECT revision FROM admin_policy_recompiles WHERE tenant_id=? FOR UPDATE',[$this->tenant])) { $this->recompileAll=true; }
        $this->db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE revision=revision+1,requested_at=UTC_TIMESTAMP(6)',[$this->tenant]);
        $this->dirtied=true;
        if ($devices===null) { $this->recompileAll=true; } else { foreach ($devices as $device) { $this->recompileDevices[bin2hex($device)]=$device; } }
    }
    private function userDevices(string $user): array
    {
        return array_column($this->db->run("SELECT id FROM devices WHERE tenant_id=? AND user_id=? AND status='active'",[$this->tenant,$user])->fetchAll(),'id');
    }
    // null = todo el tenant. Sin cambios marcados en esta peticion tambien es null: asi cualquier peticion
    // completa un pendiente que haya quedado de una compilacion fallida (autocorreccion existente).
    public function recompileScope(): ?array { return !$this->dirtied || $this->recompileAll ? null : array_values($this->recompileDevices); }
    private function row(string $table, string $id, string $key='id'): array
    {
        $row=$this->db->one("SELECT * FROM $table WHERE tenant_id=? AND $key=? FOR UPDATE",[$this->tenant,$id]);
        if (!$row) { throw new ApiError(404,'resource_not_found'); } return $row;
    }
    // En lote (batchUsers) no hay If-Match: la version de cada persona se compara alli, una vez, antes de sus pasos.
    private bool $batch=false; private ?string $auditOp=null; private bool $batchCalendars=false;
    private function match(array $row): void
    {
        if ($this->batch) { return; }
        if ($this->r->header('if-match') !== '"' . $row['version'] . '"') { throw new ApiError(412,'version_conflict'); }
    }
    // En lote cada persona queda auditada con la operacion individual equivalente (patchUser, setUserPlan, setUserAccess).
    private function audit(string $type,string $id,array $fields): void { (new Audit($this->db))->record($this->c,$this->r,$this->auditOp??$this->r->operation['operationId'],$type,$id,$fields); }
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
    // Errores de MySQL que son conflicto de datos (duplicado, clave foranea, SIGNAL, CHECK): 409 invalid_transition.
    private const CONFLICT_ERRORS=[1062,1451,1452,1644,3819,4025];
    public function dispatch(): array
    {
        try { return $this->handle(); }
        catch (\PDOException $e) {
            if (in_array($e->errorInfo[1]??0,self::CONFLICT_ERRORS,true)) { throw new ApiError(409,'invalid_transition'); }
            throw $e;
        }
    }
    private function handle(): array
    {
        $op=$this->r->operation['operationId']; $b=$this->r->body;
        $id=$this->r->resourceId!==null && !str_starts_with($op,'getPermission') && $op!=='patchPermission'?Util::bin($this->r->resourceId):null;
        return match($op) {
            'refreshUserSource'=>$this->refreshUserSource($id,$b),
            'setUserPortalAccess'=>$this->portalAccess($id,$b),
            'getScheduleSettings','putScheduleSettings'=>(new ScheduleSettings($this->db,$this->c,$this->access,$this->r))->dispatch($b),
            'getDashboardProblems','getUserWindows','getUserTimeline'=>(new PanelActivity($this->db,$this->c,$this->access))->dispatch($op,$id),
            'getTenantUnlockPin','setTenantUnlockPin','revealTenantUnlockPin'=>(new UnlockPin($this->db,$this->c,$this->r))->admin($this->access),
            'getTenantWindowsSecurity','setTenantWindowsSecurity'=>(new WindowsSecurity($this->db,$this->c,$this->r))->admin($this->access),
            'getTenantHardening','setTenantHardening','revealTenantHardening','getDeviceHardening','createHardeningCommand'=>(new Hardening($this->db,$this->c,$this->r))->admin($this->access),
            'listHolidays','createHoliday','getHoliday','putHoliday','deleteHoliday','listHolidayCalendars','createHolidayCalendar','getHolidayCalendar','putHolidayCalendar','assignHolidayCalendar','listHolidaySocieties','createHolidaySociety','getHolidaySociety','putHolidaySociety','deleteHolidaySociety',
            'listSuspiciousApps','createSuspiciousApp','getSuspiciousApp','putSuspiciousApp','deleteSuspiciousApp','reviewDualJobAlert','listDualJobAlerts','listSuspiciousDetections','listComplianceSignals',
            'listInstallCoverage','putInstallCoverage','listClientLogs','getServerHealth','getPanelSettings','putPanelSettings'=>(new OperationsApi($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'getAdminDashboard','getAppsReport','getPresenceReport','getUserActivity','getUserPolicies','getPeopleReport'=>(new ActivityReports($this->db,$this->c,$this->access))->dispatch($op,$id),
            'issueMigrationAuthorization','validateMigrationAuthorization','getEscrowKey','persistDeviceEscrow','verifyDeviceEscrow','recoverDeviceEscrow','getDeviceMigration','setDeviceMigration'=>(new MigrationApi($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'listTenants','createTenant','getTenant','patchTenant','setRbacGate'=>$this->tenants($op,$b), 'uploadTenantLogo'=>$this->tenantLogo($b),
            'provisionTenant'=>$this->provisionTenant($b), 'listRoleTemplates'=>$this->roleTemplates(),
            'listOrganization','createOrgUnit','getOrgUnit','patchOrgUnit','deleteOrgUnit'=>$this->organization($op,$id,$b),
            'listSchedules','createSchedule','getSchedule','putSchedule'=>$this->schedules($op,$id,$b),
            'listUsers','createUser','getUser','patchUser'=>$this->users($op,$id,$b),
            'batchUpdateUsers'=>$this->batchUsers($b),
            'listTenantDevices','getDevice','getDeviceSecurity','patchDevice','assignDevice','createDeviceCommand','listAdminCommands','createEnrollment'=>$this->devices($op,$id,$b),
            'listRoles','getRole','createRole','patchRole'=>$this->roles($op,$id,$b),
            'listPermissions','getPermission','patchPermission'=>$this->permissions($op,$b),
            'listPolicies','getPolicy','createPolicy','patchPolicy'=>$this->policies($op,$id,$b),
            'listReleases','registerRelease','deployRelease'=>$this->releases($op,$b),
            'listRelocations','registerRelocation','setRelocationEnforcement'=>$this->relocations($op,$b),
            'listExpectedDevices','createExpectedDevice','importExpectedDevices','cancelExpectedDevice','listEnrollmentRequests','approveEnrollmentRequest','rejectEnrollmentRequest',
            'getEnrollmentSettings','putEnrollmentSettings','listEnrollmentKeys','createEnrollmentKey','revokeEnrollmentKey','downloadInstaller'=>(new DeviceIntake($this->db))->admin($op,$id,$b,$this->access,$this->c,$this->r),
            'listPanelAccounts','createPanelAccount','patchPanelAccount','resetPanelAccountPassword'=>(new PanelAccounts($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'getAppClassification','putAppClassification','createAppClassificationOverride','deleteAppClassificationOverride','createAppSite'=>(new AppClassification($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'listAbsences','createAbsence','patchAbsence'=>(new Absences($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'listDoors','createDoor','patchDoor','getDoorQuarantine'=>(new DoorsApi($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'listRoleRules','createRoleRule','patchRoleRule','getMonitoringExclusions','putMonitoringExclusions'=>(new RoleRules($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'getMyAccess'=>$this->myAccess(), 'getMe'=>$this->me(),
            'listPortalOrigins','createPortalOrigin','deletePortalOrigin'=>$this->portalOrigins($op,$id,$b),
            'setUserPlan'=>$this->userPlan($id,$b),
            'setUserAccess'=>$this->userAccess($id,$b),
            'listFeatures','listFeaturePackages','createFeaturePackage','patchFeaturePackage','getTenantFeatures','putTenantFeatures','listPersonPlans','createPersonPlan','patchPersonPlan','getTenantPlanUsage','listAvailablePersonPlans'=>(new FeatureAdmin($this->db,$this->c,$this->r))->dispatch($op,$id,$b),
            'purgeTenant'=>$this->purgeTenant($b),
            'listCompanyGroups','createCompanyGroup','patchCompanyGroup','deleteCompanyGroup'=>(new CompanyGroups($this->db,$this->c,$this->r))->dispatch($op,$id,$b),
            'listDataSources','createDataSource','patchDataSource','getDataDomains','putDataDomains','runDataSource','listSyncRuns','listSyncItems','approveSyncRun','listPendingSyncItems','resolveSyncItem'=>(new DataSources($this->db,$this->c,$this->access,$this->r))->dispatch($op,$id,$b),
            'getOnboarding','patchOnboarding','closeOnboarding','reopenOnboarding','getOnboardingSummary'=>(new Onboarding($this->db,$this->c,$this->access,$this->r))->dispatch($op,$b),
            'getProductivity'=>$this->report(), 'listAudit'=>$this->auditPage(),
            default=>throw new ApiError(404,'resource_not_found')
        };
    }
    private function tenantDto(array $row): array
    {
        $brand=$this->db->one('SELECT display_name,logo_url,primary_color,accent_color FROM branding WHERE tenant_id=?',[$row['tenant_id']]);
        $brand??=['display_name'=>$row['name'],'logo_url'=>null,'primary_color'=>'#3C4082','accent_color'=>'#5A61B3'];
        $brand['display_name']=mb_substr($brand['display_name'],0,100);
        $brand['logo_url']??=rtrim(Config::get('ORIGIN'),'/').'/branding.svg';
        return ['id'=>Util::id($row['tenant_id']),'name'=>$row['name'],'status'=>$row['status'],'timezone'=>$row['timezone'],'rbac_self_management'=>Features::has($this->db,$row['tenant_id'],'autogestion_accesos'),'branding'=>$brand,'version'=>(int)$row['version'],'archived'=>$row['archived_at']!==null];
    }
    private function tenants(string $op,?object $b): array
    {
        $this->access->requireFull();
        if ($op==='listTenants') {
            // Cuenta de grupo: su empresa y las del grupo donde tiene roles (selector de empresa del panel).
            if (!$this->c['platform'] && ($this->c['group_id']??null)!==null) {
                $ids=array_values(array_unique([$this->c['admin_tenant_id'],...array_column($this->db->run('SELECT DISTINCT g.tenant_id FROM access_grants g JOIN company_group_members m ON m.tenant_id=g.tenant_id AND m.group_id=? WHERE g.admin_tenant_id=? AND g.admin_id=?',[$this->c['group_id'],$this->c['admin_tenant_id'],$this->c['admin_id']])->fetchAll(),'tenant_id')]));
                return $this->page('SELECT t.*,tenant_id id FROM tenants t WHERE tenant_id IN ('.implode(',',array_fill(0,count($ids),'?')).')',$ids,fn($x)=>$this->tenantDto($x));
            }
            // Archivadas: ocultas salvo que superadmin pida verlas (Empresas > "Mostrar archivadas").
            if ($this->c['platform']) { return $this->page('SELECT t.*,tenant_id id FROM tenants t WHERE deleted_at IS NULL'.(($_GET['include_archived']??'false')==='true'?'':' AND archived_at IS NULL'),[],fn($x)=>$this->tenantDto($x)); }
            return $this->page('SELECT t.*,tenant_id id FROM tenants t WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->tenantDto($x));
        }
        if ($op==='createTenant') {
            $this->timezone($b->timezone); $id=Util::bin(Util::uuid());
            $this->db->run('INSERT INTO tenants (tenant_id,name,timezone) VALUES (?,?,?)',[$id,$b->name,$b->timezone]);
            $this->db->run('INSERT INTO retention_settings (tenant_id) VALUES (?)',[$id]);
            $this->db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')",[$id,Util::bin(Util::uuid())]);
            $this->tenant=$id; $this->c['tenant_id']=$id;
            $this->c['principal_id']=(new AdminAuth($this->db,new RateLimiter()))->principal($this->c);
            // Plan por defecto Completo: las funciones reales se limitan a las que el cliente compro (tenant_features), asi que no regala nada.
            $full=$this->db->one('SELECT id FROM person_plans WHERE tenant_id=0x00000000000040008000000000000001 AND name=? AND active=TRUE',[PersonPlans::FULL_PLAN]);
            if ($full) { $this->db->run('UPDATE tenants SET default_plan_tenant_id=0x00000000000040008000000000000001,default_plan_id=? WHERE tenant_id=?',[$full['id'],$id]); }
            // Funciones del cliente nuevo: las de su paquete (sin paquete, solo el nucleo). El alta completa (provisionTenant)
            // pasa ademas los ajustes de la venta en `features` (no es parte de TenantCreate): asi los roles base nacen con ellas.
            if (isset($b->package_id) || isset($b->features)) {
                $pkg=isset($b->package_id)?Util::bin($b->package_id):null;
                $codes=$b->features??array_column($this->db->run('SELECT feature_code FROM feature_package_items WHERE tenant_id=? AND package_id=?',[Util::bin(AdminAuth::PLATFORM),$pkg])->fetchAll(),'feature_code');
                (new FeatureAdmin($this->db,$this->c,$this->r))->apply($id,$pkg,$codes);
            }
            // Roles base del cliente nuevo (§4): las plantillas ofrecidas, solo con permisos de sus funciones (o del nucleo).
            foreach ($this->db->run('SELECT * FROM role_templates WHERE platform_only=FALSE AND offered=TRUE ORDER BY seed_key')->fetchAll() as $template) { $this->seedRole($id,$template); }
        } else { $id=$this->tenant; $row=$this->row('tenants',$id,'tenant_id'); if ($op==='getTenant') { return $this->result($this->tenantDto($row)); } $this->match($row); }
        if ($op==='setRbacGate') {
            // Q3 (2026-10-15): una sola llave. Activar o revocar la autogestion es encender o apagar la funcion vendible
            // autogestion_accesos; la columna rbac_self_management queda como espejo (0063).
            if ($b->enabled) { $this->db->run("INSERT IGNORE INTO tenant_features (tenant_id,feature_code) VALUES (?,'autogestion_accesos')",[$id]); }
            else { $this->db->run("DELETE FROM tenant_features WHERE tenant_id=? AND feature_code='autogestion_accesos'",[$id]); }
            $this->db->run('UPDATE tenants SET rbac_self_management=?,auth_version=auth_version+1,version=version+1 WHERE tenant_id=?',[(int)$b->enabled,$id]);
        }
        elseif ($op!=='createTenant') {
            if (isset($b->status) && !$this->c['platform']) { throw new ApiError(403,'permission_denied'); }
            if (isset($b->archived)) {
                if (!$this->c['platform']) { throw new ApiError(403,'permission_denied'); }
                if ($id===Util::bin(AdminAuth::PLATFORM)) { throw new ApiError(409,'invalid_transition',[],'platform_cannot_archive'); }
                if (array_diff(array_keys(get_object_vars($b)),['archived'])) { throw new ApiError(422,'validation_failed',[],'archive_alone'); }
                if ($b->archived && $row['archived_at']===null) {
                    // Archivar: suspendida (sus equipos y cuentas dejan de entrar), fuera de su grupo y de solo lectura.
                    $this->db->run("UPDATE tenants SET archived_at=UTC_TIMESTAMP(6),status='suspended' WHERE tenant_id=?",[$id]);
                    $this->db->run('DELETE FROM company_group_members WHERE tenant_id=?',[$id]);
                } elseif (!$b->archived) { $this->db->run('UPDATE tenants SET archived_at=NULL WHERE tenant_id=?',[$id]); }
            } elseif ($row['archived_at']!==null) { throw new ApiError(409,'invalid_transition',[],'tenant_archived'); }
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
    // Logo subido desde el panel: solo PNG/JPEG/WebP verificados por su contenido (nunca SVG: podria llevar script). Se guarda con
    // nombre derivado del hash en public/uploads/brand y queda como logo de la marca. El de la empresa duena es el logo del producto.
    private function tenantLogo(object $b): array
    {
        $this->access->requireFull();
        $row=$this->row('tenants',$this->tenant,'tenant_id'); $this->match($row);
        $bytes=base64_decode($b->data,true);
        $types=['image/png'=>[IMAGETYPE_PNG,'png'],'image/jpeg'=>[IMAGETYPE_JPEG,'jpg'],'image/webp'=>[IMAGETYPE_WEBP,'webp']];
        $info=$bytes===false||strlen($bytes)>524288?false:@getimagesizefromstring($bytes);
        if ($info===false || $info[2]!==$types[$b->content_type][0] || $info[0]<16 || $info[1]<16 || $info[0]>4000 || $info[1]>4000) { throw new ApiError(422,'validation_failed',[],'invalid_image'); }
        $dir=dirname(__DIR__).'/public/uploads/brand'; if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) { throw new \RuntimeException('uploads not writable'); }
        $name=Util::id($this->tenant).'-'.substr(hash('sha256',$bytes),0,16).'.'.$types[$b->content_type][1];
        if (file_put_contents("$dir/$name",$bytes,LOCK_EX)!==strlen($bytes)) { throw new \RuntimeException('logo write failed'); }
        $url=rtrim(Config::get('ORIGIN'),'/').'/uploads/brand/'.$name;
        $this->db->run('INSERT INTO branding (tenant_id,display_name,logo_url,primary_color,accent_color) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE logo_url=VALUES(logo_url)',[$this->tenant,mb_substr($row['name'],0,100),$url,'#3C4082','#5A61B3']);
        $this->db->run('UPDATE tenants SET version=version+1 WHERE tenant_id=?',[$this->tenant]);
        $this->audit('tenant',$this->tenant,['branding.logo_url']);
        return $this->result($this->tenantDto($this->row('tenants',$this->tenant,'tenant_id')));
    }
    /** Rol base de un cliente desde su plantilla: solo con permisos de funciones que el cliente compro (o del nucleo). */
    private function seedRole(string $tenant,array $template): string
    {
        $role=Util::bin(Util::uuid());
        $this->db->run("INSERT INTO roles (tenant_id,id,name,seed_key,seed_version) VALUES (?,?,?,?,?)",[$tenant,$role,$template['name'],$template['seed_key'],$template['version']]);
        foreach (array_column($this->db->run('SELECT permission_code FROM role_template_permissions WHERE seed_key=?',[$template['seed_key']])->fetchAll(),'permission_code') as $code) {
            $f=Features::forOperation('',$code);
            if ($f===null || Features::has($this->db,$tenant,$f)) { $this->db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)',[$tenant,$role,$code]); }
        }
        return $role;
    }
    /** Plantillas de rol que recibe un cliente nuevo (asistente de alta: rol del primer administrador). */
    private function roleTemplates(): array
    {
        $rows=$this->db->run('SELECT seed_key,name FROM role_templates WHERE platform_only=FALSE AND offered=TRUE ORDER BY name')->fetchAll();
        return self::response(['data'=>array_map(static fn($t)=>['seed_key'=>$t['seed_key'],'name'=>$t['name']],$rows)]);
    }
    /**
     * Alta completa de un cliente (asistente «Dar de alta un cliente»), todo en la transaccion de la peticion: si un paso
     * falla no queda nada. Cada paso pasa por el mismo codigo y deja la misma auditoria que su operacion suelta
     * (createTenant, putTenantFeatures, createOrgUnit, createUser, createPanelAccount); ademas queda provisionTenant en la
     * auditoria de la plataforma. Exige reautenticacion reciente, como crear una cuenta del panel.
     */
    private function provisionTenant(object $b): array
    {
        $this->reauth(); $this->access->requireFull();
        $platform=$this->c; $name=trim($b->name);
        if ($name==='') { throw new ApiError(422,'validation_failed'); }
        // Idempotencia razonable: el nombre identifica al cliente (repetir el envio no crea un segundo cliente).
        if ($this->db->one('SELECT tenant_id FROM tenants WHERE deleted_at IS NULL AND TRIM(name)=?',[$name])) { throw new ApiError(409,'invalid_transition',[],'tenant_name_taken'); }
        $step=function (string $op,string $permission,callable $run) {
            $this->auditOp=$op; $this->access=new AdminAccess($this->db,$this->c,$permission,$op);
            try { return $run(); } finally { $this->auditOp=null; }
        };
        $step('createTenant','empresas.gestionar',fn()=>$this->tenants('createTenant',(object)['name'=>$name,'timezone'=>$b->timezone,'package_id'=>$b->package_id,'features'=>$b->features,
            'branding'=>(object)['display_name'=>mb_substr($name,0,100),'logo_url'=>rtrim(Config::get('ORIGIN'),'/').'/branding.svg','primary_color'=>'#3C4082','accent_color'=>'#5A61B3']]));
        $tenant=$this->tenant;
        // Plan por defecto: createTenant deja «Completo»; si se eligio otro (o ninguno) se guarda como en Funciones del cliente.
        if (($this->db->one('SELECT default_plan_id FROM tenants WHERE tenant_id=?',[$tenant])['default_plan_id']??null)!==($b->default_plan_id===null?null:Util::bin($b->default_plan_id))) {
            (new FeatureAdmin($this->db,$this->c,$this->r))->dispatch('putTenantFeatures',null,(object)['package_id'=>$b->package_id,'features'=>$b->features,'default_plan_id'=>$b->default_plan_id]);
        }
        // Rol del primer administrador (Q4: «Corporativo» por defecto). Si su plantilla no se ofrece a clientes nuevos, se siembra igual.
        $role=$this->db->one('SELECT id FROM roles WHERE tenant_id=? AND seed_key=?',[$tenant,$b->admin->role])['id']??null;
        if ($role===null && $b->admin->role==='corporativo') {
            $template=$this->db->one("SELECT * FROM role_templates WHERE seed_key='corporativo' AND platform_only=FALSE");
            if (!$template) { throw new ApiError(409,'invalid_transition',[],'role_template_missing'); }
            $role=$this->seedRole($tenant,$template);
        }
        if ($role===null) { throw new ApiError(422,'validation_failed',[],'unknown_role'); }
        // Primera sede (con el pais del cliente): toda persona activa tiene sede. Area y cargo quedan «Sin área» / «Sin cargo».
        $site=$step('createOrgUnit','organizacion.editar',fn()=>json_decode($this->organization('createOrgUnit',null,(object)['kind'=>'site','name'=>trim($b->site_name??'')?:'Sede principal','parent_id'=>null,'active'=>true,'country_code'=>$b->country_code,'city'=>null])['json'],true));
        $user=$step('createUser','usuarios.crear',fn()=>json_decode($this->users('createUser',null,(object)['display_name'=>trim($b->admin->display_name),'email'=>strtolower(trim($b->admin->email)),'document'=>$b->admin->document,
            'site_id'=>$site['id'],'area_id'=>Util::id(Placeholders::unit($this->db,$tenant,'area')),'position_id'=>Util::id(Placeholders::unit($this->db,$tenant,'position'))])['json'],true));
        $this->access=new AdminAccess($this->db,$this->c,'usuarios.editar','createPanelAccount');
        $account=json_decode((new PanelAccounts($this->db,$this->c,$this->access,$this->r))->dispatch('createPanelAccount',null,(object)['kind'=>'company','email'=>$b->admin->email,'display_name'=>null,'user_id'=>$user['id'],
            'access'=>(object)['role_id'=>Util::id($role),'scope'=>(object)['kind'=>$b->admin->scope_kind,'company_ids'=>[],'cities'=>[],'site_ids'=>[],'area_ids'=>[]]]])['json'],true);
        (new Audit($this->db))->record($platform,$this->r,'provisionTenant','tenant',$tenant,['name','timezone','package_id','features','default_plan_id','site','admin']);
        $user=$this->userDto($this->row('users',Util::bin($user['id'])));
        return self::response(['tenant'=>$this->tenantDto($this->row('tenants',$tenant,'tenant_id')),'features'=>(new FeatureAdmin($this->db,$this->c,$this->r))->tenantDto($tenant),'site_id'=>$site['id'],
            'user'=>$user,'account'=>$account['account'],'temporary_password'=>$account['temporary_password']],201,['Cache-Control'=>'no-store']);
    }
    /** Eliminar unidad: bloqueada si algo la usa hoy; si solo la menciona el historial, queda como eliminada. */
    private function deleteOrgUnit(array $old): array
    {
        $this->match($old); $id=$old['id'];
        DataSources::guardWrite($this->db,$this->tenant,['firm'=>'firmas','company'=>'empresas','site'=>'sedes','area'=>'areas','position'=>'cargos'][$old['kind']]??'areas','org_unit',$id);
        $uses=[
            'personas activas'=>["SELECT COUNT(*) n FROM users WHERE tenant_id=? AND status='active' AND ? IN (firm_id,site_id,area_id,position_id,company_id)",[$this->tenant,$id]],
            'unidades que dependen de ella'=>['SELECT COUNT(*) n FROM org_units WHERE tenant_id=? AND parent_id=? AND deleted_at IS NULL',[$this->tenant,$id]],
            'accesos'=>['SELECT COUNT(*) n FROM access_grant_units WHERE tenant_id=? AND unit_id=?',[$this->tenant,$id]],
            'reglas de los equipos'=>['SELECT COUNT(*) n FROM policy_assignments WHERE tenant_id=? AND (area_id=? OR site_id=?)',[$this->tenant,$id,$id]],
            'puertas'=>['SELECT COUNT(*) n FROM doors WHERE tenant_id=? AND site_id=?',[$this->tenant,$id]],
            'asignaciones automáticas de roles'=>['SELECT COUNT(*) n FROM panel_role_rules WHERE tenant_id=? AND (area_id=? OR position_id=?)',[$this->tenant,$id,$id]],
            'cargos que no se monitorean'=>['SELECT COUNT(*) n FROM monitoring_excluded_positions WHERE tenant_id=? AND position_id=?',[$this->tenant,$id]],
            'excepciones de clasificación'=>['SELECT COUNT(*) n FROM app_classification_overrides WHERE tenant_id=? AND (area_id=? OR position_id=?)',[$this->tenant,$id,$id]],
            'sociedades'=>['SELECT (SELECT COUNT(*) FROM sociedades WHERE tenant_id=? AND firm_id=?)+(SELECT COUNT(*) FROM firma_module_override WHERE tenant_id=? AND firm_id=?) n',[$this->tenant,$id,$this->tenant,$id]],
        ];
        $busy=[]; foreach ($uses as $what=>[$sql,$args]) { if ((int)$this->db->one($sql,$args)['n']>0) { $busy[]=$what; } }
        if ($busy) { throw new ApiError(409,'invalid_transition',[],'org_unit_in_use:'.implode(', ',$busy)); }
        $this->db->run("DELETE FROM entity_links WHERE tenant_id=? AND entity_type='org_unit' AND entity_id=?",[$this->tenant,$id]);
        // Nunca usada: se borra. Mencionada por el historial: queda como eliminada (el historial sigue valido).
        $this->db->run('SAVEPOINT org_delete');
        try { $this->db->run('DELETE FROM org_units WHERE tenant_id=? AND id=?',[$this->tenant,$id]); }
        catch (\PDOException $e) {
            if (($e->errorInfo[1]??0)!==1451) { throw $e; }
            $this->db->run('ROLLBACK TO SAVEPOINT org_delete');
            $this->db->run('UPDATE org_units SET deleted_at=UTC_TIMESTAMP(6),active=FALSE,version=version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]);
        }
        $this->audit('org_unit',$id,['name']); $this->dirty();
        return ['status'=>204,'json'=>'','headers'=>[]];
    }
    private function orgDto(array $r): array { return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'kind'=>$r['kind'],'name'=>$r['name'],'parent_id'=>$r['parent_id']===null?null:Util::id($r['parent_id']),'active'=>(bool)$r['active'],'version'=>(int)$r['version'],'country_code'=>$r['country_code'],'city'=>$r['city'],'placeholder'=>(bool)$r['placeholder']]; }
    private function organization(string $op,?string $id,?object $b): array
    {
        // Leer la estructura (empresas, sedes, areas, cargos) no es dato de personas: quien delega la necesita para elegir el alcance.
        if (!in_array($op,['listOrganization','getOrgUnit'],true)) { $this->access->requireFull(); }
        if ($op==='listOrganization') { return $this->page('SELECT * FROM org_units WHERE tenant_id=? AND deleted_at IS NULL',[$this->tenant],fn($x)=>$this->orgDto($x)); }
        if ($id) { $old=$this->row('org_units',$id); if ($old['deleted_at']!==null) { throw new ApiError(404,'resource_not_found'); } if ($op==='deleteOrgUnit') { return $this->deleteOrgUnit($old); } if ($op==='getOrgUnit') { return $this->result($this->orgDto($old)); } $this->match($old); if ($old['kind']!==$b->kind) { throw new ApiError(409,'invalid_transition'); } }
        $parent=$b->parent_id===null?null:Util::bin($b->parent_id);
        $requiresActiveParent=!$id || (!$old['active'] && $b->active) || $parent!==$old['parent_id'];
        $seen=[$id]; $p=$parent;
        while ($p!==null) { if (in_array($p,$seen,true)) { throw new ApiError(422,'validation_failed'); } $seen[]=$p; $pr=$this->row('org_units',$p); if ($requiresActiveParent && !$pr['active']) { throw new ApiError(422,'inactive_org_ancestor',[], 'No se puede crear, reactivar o mover una unidad bajo un ancestro inactivo.'); } $p=$pr['parent_id']; }
        if ($id && $old['active'] && !$b->active) {
            if ($this->db->one('SELECT id FROM users WHERE tenant_id=? AND (firm_id=? OR site_id=? OR area_id=? OR position_id=? OR company_id=?) LIMIT 1',[$this->tenant,$id,$id,$id,$id,$id])
                || $this->db->one('SELECT id FROM org_units WHERE tenant_id=? AND parent_id=? AND active=TRUE LIMIT 1',[$this->tenant,$id])
                || $this->db->one('SELECT grant_id FROM access_grant_units WHERE tenant_id=? AND unit_id=? LIMIT 1',[$this->tenant,$id])
                || $this->db->one('SELECT id FROM policy_assignments WHERE tenant_id=? AND (area_id=? OR site_id=?) LIMIT 1',[$this->tenant,$id,$id])) { throw new ApiError(409,'org_unit_in_use',[], 'La unidad tiene usuarios, unidades hijas activas, scopes de roles o asignaciones de políticas.'); }
        }
        // Pais: solo las sedes lo tienen (las remotas son "Remoto - <pais>"); de el sale el calendario de festivos por defecto.
        $country=property_exists($b,'country_code')?$b->country_code:($id?$old['country_code']:null);
        if ($country!==null && $b->kind!=='site') { throw new ApiError(422,'validation_failed',[],'country_only_for_sites'); }
        // Ciudad (solo sedes): se guarda limpia (sin espacios de mas) para que los filtros agrupen bien.
        $city=property_exists($b,'city')?($b->city===null?null:(preg_replace('/\s+/u',' ',trim($b->city))?:null)):($id?$old['city']:null);
        if ($city!==null && $b->kind!=='site') { throw new ApiError(422,'validation_failed',[],'city_only_for_sites'); }
        $values=['kind'=>$b->kind,'name'=>$b->name,'parent_id'=>$parent,'active'=>(int)$b->active,'country_code'=>$country,'city'=>$city];
        DataSources::guardWrite($this->db,$this->tenant,['firm'=>'firmas','company'=>'empresas','site'=>'sedes','area'=>'areas','position'=>'cargos'][$b->kind]??'areas','org_unit',$id);
        if ($id) { $this->update('org_units',$id,$values); } else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO org_units (tenant_id,id,kind,name,parent_id,active,country_code,city) VALUES (?,?,?,?,?,?,?,?)',[$this->tenant,$id,...array_values($values)]); }
        if (isset($old) && $b->kind==='site' && $old['country_code']!==$country) { (new HolidayCalendars($this->db,$this->c,$this->access,$this->r))->recompute(array_column($this->db->run('SELECT id FROM holiday_calendars WHERE tenant_id=?',[$this->tenant])->fetchAll(),'id')); }
        $this->audit('org_unit',$id,array_keys($values)); $this->dirty();
        return $this->result($this->orgDto($this->row('org_units',$id)),$op==='createOrgUnit'?201:200);
    }
    /** Plan propio de una persona (null = el por defecto de su cliente). */
    private function userPlan(string $id, object $b): array
    {
        $this->access->user($id); $old=$this->row('users',$id); $this->match($old);
        $plan=$b->plan_id===null?null:Util::bin($b->plan_id);
        if ($plan!==null && !$this->db->one('SELECT id FROM person_plans WHERE tenant_id=? AND id=? AND active=TRUE',[Util::bin(AdminAuth::PLATFORM),$plan])) { throw new ApiError(422,'validation_failed',[],'unknown_plan'); }
        $this->db->run('UPDATE users SET plan_tenant_id=?,plan_id=?,version=version+1 WHERE tenant_id=? AND id=?',[$plan===null?null:Util::bin(AdminAuth::PLATFORM),$plan,$this->tenant,$id]);
        $this->audit('user',$id,['plan_id']); $this->dirty($this->userDevices($id));
        return $this->result($this->userDto($this->row('users',$id)));
    }
    /** Acceso de una persona = 1 rol + 1 alcance (§4), con delegacion segura. */
    private function userAccess(string $id, object $b): array
    {
        $this->access->user($id); $old=$this->row('users',$id); $this->match($old);
        AccessGrants::assign($this->db,$this->access,$this->tenant,$id,$b);
        $this->db->run('UPDATE users SET version=version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]); $this->invalidateUser($id);
        $this->audit('user',$id,['access']);
        return $this->result($this->userDto($this->row('users',$id)));
    }
    /**
     * Edicion en lote (POST /users/batch). Cada persona pasa por los mismos metodos que PATCH /users/{id}, PUT …/plan y
     * PUT …/access (validaciones, alcance, bloqueo por fuente, delegacion, reglas por cargo, auditoria) dentro de su propio
     * savepoint: lo que falle deja a esa persona como estaba y se informa; las demas siguen. dry_run deshace todo.
     */
    private function batchUsers(object $b): array
    {
        $changes=isset($b->changes) && get_object_vars($b->changes)?$b->changes:null; $plan=$b->plan??null; $grant=$b->access??null; $dry=$b->dry_run??false;
        if ($changes===null && $plan===null && $grant===null) { throw new ApiError(422,'validation_failed',[],'nothing_to_change'); }
        $this->unique(array_map(static fn($x)=>strtolower($x->id),$b->users));
        // Cada paso con el permiso de su endpoint (sin el, 403 para todo el lote); el acceso, ademas, con la compuerta de roles.
        $gate=function (string $permission,string $op): AdminAccess {
            if ($permission===$this->access->permission) { return $this->access; }
            $a=new AdminAccess($this->db,$this->c,$permission,$op); if ($permission==='roles.gestionar') { $a->rolesGate(); } return $a;
        };
        $steps=[];
        if ($changes!==null) { $steps[]=[$gate('usuarios.editar','patchUser'),'patchUser',fn(string $id)=>$this->users('patchUser',$id,clone $changes)]; }
        if ($plan!==null) { $steps[]=[$gate('planes.asignar','setUserPlan'),'setUserPlan',fn(string $id)=>$this->userPlan($id,$plan)]; }
        if ($grant!==null) { $steps[]=[$gate('roles.gestionar','setUserAccess'),'setUserAccess',fn(string $id)=>$this->userAccess($id,$grant)]; }
        $own=$this->access; $results=[]; $applied=0;
        foreach ($b->users as $item) {
            $id=Util::bin($item->id); $error=null;
            $this->db->run('SAVEPOINT keeper_batch_user');
            try {
                $current=$this->access->user($id); // fuera del alcance: resource_not_found, como el 404 individual
                if (isset($item->version) && (int)$current['version']!==$item->version) { throw new ApiError(412,'version_conflict'); }
                $this->batch=true;
                foreach ($steps as [$access,$op,$run]) { $this->access=$access; $this->auditOp=$op; $run($id); }
                // Reglas de sus equipos aqui, dentro de su savepoint: un choque deja solo a esta persona como estaba
                // (antes lo detectaba validatePending al final y deshacia el lote entero).
                try { (new PolicyCompiler($this->db))->validateDevices(Util::id($this->tenant),$this->userDevices($id)); }
                catch (PolicyConflict) { throw new ApiError(409,'invalid_transition',[],'policy_conflict'); }
            }
            catch (ApiError $e) { $error=$e; }
            catch (\PDOException $e) { if (!in_array($e->errorInfo[1]??0,self::CONFLICT_ERRORS,true)) { throw $e; } $error=new ApiError(409,'invalid_transition'); }
            finally { $this->access=$own; $this->batch=false; $this->auditOp=null; }
            $this->db->run($error!==null || $dry?'ROLLBACK TO SAVEPOINT keeper_batch_user':'RELEASE SAVEPOINT keeper_batch_user');
            if ($error===null) { $applied++; }
            $message=$error?->errors[0]['message']??null;
            $results[]=['id'=>Util::id($id),'ok'=>$error===null,'error_code'=>$error?->errorCode,'detail'=>$error?->detail,'message'=>is_string($message)?mb_substr($message,0,500):null];
        }
        if ($this->batchCalendars && !$dry) { (new HolidayCalendars($this->db,$this->c,$this->access,$this->r))->recompute(array_column($this->db->run('SELECT id FROM holiday_calendars WHERE tenant_id=?',[$this->tenant])->fetchAll(),'id')); }
        $this->batchCalendars=false;
        return self::response(['dry_run'=>$dry,'applied'=>$applied,'failed'=>count($results)-$applied,'results'=>$results]);
    }
    private function userDto(array $r): array
    {
        $grant=AccessGrants::forUser($this->db,$this->tenant,$r['id']);
        $out=['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'display_name'=>$r['display_name'],'email'=>$r['email'],'status'=>$r['status'],'access'=>AccessGrants::dto($grant),'panel_login_enabled'=>(bool)$r['panel_login_enabled'],'version'=>(int)$r['version']];
        foreach (['firm_id','site_id','area_id','position_id','schedule_id','company_id','holiday_calendar_id'] as $f) { $out[$f]=$r[$f]===null?null:Util::id($r[$f]); }
        // Cedula: la cargada en v4 ('document') o, si no hay, la importada de K3 ('k3:cc').
        $doc=$this->db->one("SELECT external_ref FROM user_external_refs WHERE tenant_id=? AND user_id=? AND source IN ('document','k3:cc') ORDER BY source='document' DESC LIMIT 1",[$this->tenant,$r['id']]);
        $out['plan_id']=$r['plan_id']===null?null:Util::id($r['plan_id']);
        $out['document']=$doc['external_ref']??null;
        $out['employment_status']=$r['employment_status']; $out['portal_login_enabled']=(bool)$r['portal_login_enabled'];
        $effective=$r['schedule_id'];
        if ($effective===null || Placeholders::is($this->db,$this->tenant,$effective,'schedule_id')) { $effective=$this->db->one('SELECT default_schedule_id FROM tenants WHERE tenant_id=?',[$this->tenant])['default_schedule_id']??$effective; }
        $out['effective_schedule_id']=$effective===null?null:Util::id($effective);
        $out['source_owners']=array_map(static fn($s)=>['domain'=>$s['domain'],'source_id'=>Util::id($s['id']),'name'=>$s['name']],$this->db->run("SELECT o.domain,s.id,s.name FROM data_domain_owners o JOIN data_sources s ON s.tenant_id=o.tenant_id AND s.id=o.source_id JOIN entity_links l ON l.tenant_id=o.tenant_id AND l.source_id=o.source_id AND l.entity_type='user' AND l.entity_id=? WHERE o.tenant_id=?",[$r['id'],$this->tenant])->fetchAll());
        $out['managed_by']=DataSources::managedBy($this->db,$this->tenant,$r['id']);
        $out['reports_to_user_id']=$r['reports_to_user_id']===null?null:Util::id($r['reports_to_user_id']);
        $out['monitoring_override']=$r['monitoring_override']; $out['monitoring']=RoleRules::monitoring($this->db,$r);
        return $out;
    }
    private function portalAccess(string $id,object $b): array
    {
        $this->access->requireFull(); $this->access->user($id);
        if (!$this->c['platform'] && !Features::has($this->db,$this->tenant,'autogestion_accesos')) { throw new ApiError(403,'permission_denied'); }
        $this->db->run('UPDATE users SET portal_login_enabled=?,version=version+1 WHERE tenant_id=? AND id=?',[(int)$b->portal_login_enabled,$this->tenant,$id]);
        $this->audit('user',$id,['portal_login_enabled']);
        return self::response(['portal_login_enabled'=>$b->portal_login_enabled]);
    }
    private function refreshUserSource(string $id,object $b): array
    {
        $this->access->user($id); $this->access->requireFull();
        $source=Util::bin($b->source_id);
        $s=$this->db->one("SELECT s.* FROM data_sources s JOIN entity_links l ON l.tenant_id=s.tenant_id AND l.source_id=s.id AND l.entity_type='user' AND l.entity_id=? JOIN data_domain_owners o ON o.tenant_id=s.tenant_id AND o.source_id=s.id AND o.domain IN ('personas','jerarquia','empresas','firmas','sedes','areas','cargos','horarios') WHERE s.tenant_id=? AND s.id=? AND s.active=TRUE",[$id,$this->tenant,$source]);
        if (!$s) { throw new ApiError(404,'resource_not_found'); }
        $run=(new DataSync($this->db))->run($this->tenant,$source,'incremental','manual',false,null,$id);
        $r=$this->db->one('SELECT status FROM sync_runs WHERE tenant_id=? AND id=?',[$this->tenant,$run]);
        $this->audit('user',$id,['refresh_source']);
        return self::response(['status'=>$r['status'],'source_name'=>$s['name']]);
    }
    private function filterUsers(string $alias='u'): array
    {
        [$where,$args]=$this->access->usersSql($alias);
        if (isset($_GET['company_id'])) { $this->org(Util::bin($_GET['company_id']),'company'); $this->access->requireFilter('company',Util::bin($_GET['company_id'])); $where.=" AND $alias.company_id=?"; $args[]=Util::bin($_GET['company_id']); }
        if (isset($_GET['country'])) { $where.=" AND EXISTS (SELECT 1 FROM org_units cs WHERE cs.tenant_id=$alias.tenant_id AND cs.id=$alias.site_id AND cs.country_code=?)"; $args[]=$_GET['country']; }
        if (isset($_GET['city'])) { $this->access->requireFilter('city',trim($_GET['city'])); $where.=" AND EXISTS (SELECT 1 FROM org_units cy WHERE cy.tenant_id=$alias.tenant_id AND cy.id=$alias.site_id AND cy.city=?)"; $args[]=trim($_GET['city']); }
        foreach (['area_id','site_id','user_id','status'] as $f) {
            if (!isset($_GET[$f])) { continue; }
            $column=$f==='user_id'?'id':$f;
            if ($f==='area_id' || $f==='site_id') { $this->org(Util::bin($_GET[$f]),substr($f,0,-3)); $this->access->requireFilter(substr($f,0,-3),Util::bin($_GET[$f])); }
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
        $this->db->run('INSERT INTO user_assignments (tenant_id,id,user_id,firm_id,site_id,area_id,position_id,schedule_id,company_id,starts_at) VALUES (?,?,?,?,?,?,?,?,?,?)',[$this->tenant,Util::bin(Util::uuid()),$id,$v['firm_id'],$v['site_id'],$v['area_id'],$v['position_id'],$v['schedule_id'],$v['company_id']??null,$at]);
    }
    private function users(string $op,?string $id,?object $b): array
    {
        $document=false; // false = no se envio; null = borrar la cedula
        if ($op==='listUsers') { [$where,$args]=$this->filterUsers(); return $this->page("SELECT u.* FROM users u WHERE u.tenant_id=? AND $where",[$this->tenant,...$args],fn($x)=>$this->userDto($x)); }
        if ($id) { $this->access->user($id); $old=$this->row('users',$id); if ($op==='getUser') { return $this->result($this->userDto($old)); } $this->match($old); }
        // Bloqueo por dominio (§5.3): crear exige que Keeper sea dueño de "personas"; editar a una persona vinculada solo se
        // rechaza en los campos cuyo dominio gestiona su fuente (sin dueño de "areas", su area se edita aqui). Los campos
        // propios de Keeper (calendario de festivos, excepcion de monitoreo) nunca se bloquean.
        if (!$id) { DataSources::guardWrite($this->db,$this->tenant,'personas','user',null); }
        else {
            $domains=['display_name'=>'personas','email'=>'personas','status'=>'personas','employment_status'=>'personas','document'=>'personas','company_id'=>'empresas','firm_id'=>'firmas','site_id'=>'sedes','area_id'=>'areas','position_id'=>'cargos','schedule_id'=>'horarios','reports_to_user_id'=>'jerarquia'];
            foreach (array_unique(array_values(array_intersect_key($domains,get_object_vars($b)))) as $domain) { DataSources::guardWrite($this->db,$this->tenant,$domain,'user',$id); }
        }
        $values=[]; $fields=get_object_vars($b);
        if (($fields['employment_status']??null)==='retirado') { $fields['status']='inactive'; }
        if (($fields['status']??null)==='inactive') { $fields['employment_status']='retirado'; }
        if (($fields['status']??null)==='active' && ($old['employment_status']??null)==='retirado' && !isset($fields['employment_status'])) { $fields['employment_status']='contratado'; }
        $document=array_key_exists('document',$fields)?$fields['document']:false; unset($fields['document']);
        if (array_key_exists('reports_to_user_id',$fields) && $fields['reports_to_user_id']!==null) { $this->managerOk(Util::bin($fields['reports_to_user_id']),$id); }
        if (is_string($document)) { $document=DeviceIntake::document($document); $this->documentFree($document,$id); }
        foreach ($fields as $f=>$value) { $values[$f]=$value!==null && str_ends_with($f,'_id')?Util::bin($value):$value; }
        // Firma u horario vacios (directivos, personas sin jornada): "Sin firma" / "Sin horario"; se editan despues.
        if (!$id || array_key_exists('firm_id',$values)) { $values['firm_id']??=$this->placeholderFirm(); }
        if (!$id || array_key_exists('schedule_id',$values)) { $values['schedule_id']??=$this->placeholderSchedule(); }
        foreach (['firm','site','area','position','company'] as $kind) { if (isset($values[$kind.'_id'])) { $this->org($values[$kind.'_id'],$kind); } }
        if (isset($values['schedule_id'])) { $this->row('schedules',$values['schedule_id']); }
        if (isset($values['holiday_calendar_id'])) { $this->row('holiday_calendars',$values['holiday_calendar_id']); }
        $next=($values+($old??[]));
        if (!$this->access->full()) {
            // Crear o mover una persona: su ubicacion debe quedar dentro del alcance (§4). Equipo o propio no crean ni mueven.
            if (!$this->access->allowsPlacement($next)) { throw new ApiError(403,'permission_denied',[],'scope_denied'); }
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
            AccessGrants::grantDefault($this->db,$this->tenant,$id);
            $this->assignment($id,$next);
        } else {
            if ($values || $document===false) { $this->update('users',$id,$values); } else { $this->db->run('UPDATE users SET version=version+1 WHERE tenant_id=? AND id=?',[$this->tenant,$id]); }
            if (array_intersect(array_keys($values),['firm_id','site_id','area_id','position_id','schedule_id','company_id'])) { $this->assignment($id,$next); }
            if (($values['holiday_calendar_id']??$old['holiday_calendar_id'])!==$old['holiday_calendar_id'] || ($values['site_id']??$old['site_id'])!==$old['site_id'] || (array_key_exists('holiday_calendar_id',$values) && $values['holiday_calendar_id']===null && $old['holiday_calendar_id']!==null)) {
                // En lote se recalcula una sola vez al final (batchUsers), no por cada persona.
                if ($this->batch) { $this->batchCalendars=true; } else { (new HolidayCalendars($this->db,$this->c,$this->access,$this->r))->recompute(array_column($this->db->run('SELECT id FROM holiday_calendars WHERE tenant_id=?',[$this->tenant])->fetchAll(),'id')); }
            }
            $this->invalidateUser($id);
            if (($values['status']??null)==='inactive') {
                $this->db->run("UPDATE enrollments SET status='revoked' WHERE tenant_id=? AND user_id=? AND status='pending'",[$this->tenant,$id]);
                $this->db->run("UPDATE devices SET status='revoked',auth_version=auth_version+1,version=version+1 WHERE tenant_id=? AND user_id=? AND status='active'",[$this->tenant,$id]);
                $this->db->run("UPDATE device_command c JOIN devices d ON d.tenant_id=c.tenant_id AND d.id=c.device_id SET c.status='cancelled' WHERE d.tenant_id=? AND d.user_id=? AND c.status IN ('pending','delivered')",[$this->tenant,$id]);
            }
        }
        if ($document!==false) {
            $this->db->run("DELETE FROM user_external_refs WHERE tenant_id=? AND user_id=? AND source='document'",[$this->tenant,$id]);
            if ($document!==null) { $this->db->run("INSERT INTO user_external_refs (tenant_id,user_id,source,external_ref) VALUES (?,?,'document',?)",[$this->tenant,$id,$document]); }
        }
        // Las reglas por cargo solo miran a esta persona y a sus jefes (antes y despues, por la regla «manages»).
        $focus=array_values(array_filter([$id,$old['reports_to_user_id']??null,$this->row('users',$id)['reports_to_user_id']]));
        RoleRules::apply($this->db,$this->tenant,$this->access,fn(string $user):bool=>in_array($user,$focus,true));
        $this->audit('user',$id,array_keys(get_object_vars($b))); $this->dirty($this->userDevices($id));
        return $this->result($this->userDto($this->row('users',$id)),$op==='createUser'?201:200);
    }
    /** Eliminar una empresa archivada: confirma contrasena y nombre; la auditoria se conserva (TenantPurge). */
    private function purgeTenant(object $b): array
    {
        $this->reauth();
        $row=$this->row('tenants',$this->tenant,'tenant_id');
        if ($b->confirm_name!==$row['name']) { throw new ApiError(422,'validation_failed',[],'confirm_name_mismatch'); }
        $purge=new TenantPurge($this->db);
        $hardened=$purge->hardened($this->tenant);
        if ($hardened && !$b->accept_hardening_loss) { throw new ApiError(409,'invalid_transition',[],"hardened_devices:$hardened"); }
        $purge->purge($this->tenant);
        // En la auditoria de la plataforma: quien elimino que empresa y cuando.
        $c=$this->c; $c['tenant_id']=Util::bin(AdminAuth::PLATFORM); $c['principal_id']=(new AdminAuth($this->db,new RateLimiter()))->principal($c);
        (new Audit($this->db))->record($c,$this->r,'purgeTenant','tenant',$this->tenant,['name']);
        return ['status'=>204,'json'=>'','headers'=>[]];
    }
    /** Permisos efectivos y sus alcances, con las mismas reglas que AdminAccess (roles de la persona o roles de grupo). */
    private function myAccess(): array
    {
        if ($this->c['platform']) {
            $rows=array_map(fn($x)=>['code'=>$x['code'],'scopes'=>['tenant']],$this->db->run('SELECT code FROM permissions ORDER BY code')->fetchAll());
            return self::response(['platform'=>true,'features'=>Features::enabled($this->db,$this->tenant),'permissions'=>$rows]);
        }
        $grant=AccessGrants::forContext($this->db,$this->c);
        $codes=$grant===null?[]:AccessGrants::permissions($this->db,$this->tenant,$grant['role_id']);
        $kind=$grant===null?null:($grant['scope']->kind==='all'?'tenant':$grant['scope']->kind);
        $features=Features::enabled($this->db,$this->tenant);
        $codes=array_values(array_filter($codes,function ($code) use ($features) { $f=Features::forOperation('',$code); return $f===null || in_array($f,$features,true); }));
        return self::response(['platform'=>false,'features'=>$features,'permissions'=>array_map(fn($code)=>['code'=>$code,'scopes'=>[$kind]],$codes)]);
    }
    /** Quien soy (panel y Portal): persona, rol, alcance, funciones de la empresa, permisos efectivos y empresa con su marca. */
    private function me(): array
    {
        $a=$this->db->one('SELECT email FROM admin_accounts WHERE tenant_id=? AND id=?',[$this->c['admin_tenant_id'],$this->c['admin_id']]);
        $user=($this->c['user_id']??null)===null?null:$this->db->one('SELECT display_name FROM users WHERE tenant_id=? AND id=?',[$this->tenant,$this->c['user_id']]);
        $access=json_decode($this->myAccess()['json'],true);
        $grant=$this->c['platform']?null:AccessGrants::forContext($this->db,$this->c);
        $role=$grant===null?null:$this->db->one('SELECT id,name FROM roles WHERE tenant_id=? AND id=?',[$this->tenant,$grant['role_id']]);
        return self::response(['user_id'=>($this->c['user_id']??null)===null?null:Util::id($this->c['user_id']),'admin_id'=>Util::id($this->c['admin_id']),
            'display_name'=>$user['display_name']??null,'email'=>$a['email'],'platform'=>$this->c['platform'],
            'role'=>$role?['id'=>Util::id($role['id']),'name'=>$role['name']]:null,'scope'=>$grant===null?null:$grant['scope']->dto(),
            'features'=>$access['features'],'permissions'=>array_column($access['permissions'],'code'),
            'tenant'=>$this->tenantDto($this->db->one('SELECT * FROM tenants WHERE tenant_id=?',[$this->tenant]))]);
    }
    /** Direcciones autorizadas del Portal (CORS de /portal/v1). Solo superadmin (x-platform-only). */
    private function portalOrigins(string $op,?string $id,?object $b): array
    {
        $platform=Util::bin(AdminAuth::PLATFORM);
        $dto=static fn(array $r)=>['id'=>Util::id($r['id']),'origin'=>$r['origin'],'created_at'=>Util::time($r['created_at'])];
        if ($op==='listPortalOrigins') { return self::response(['data'=>array_map($dto,$this->db->run('SELECT * FROM portal_origins WHERE tenant_id=? ORDER BY origin LIMIT 500',[$platform])->fetchAll())]); }
        if ($op==='deletePortalOrigin') {
            $row=$this->db->one('SELECT * FROM portal_origins WHERE tenant_id=? AND id=? FOR UPDATE',[$platform,$id]);
            if (!$row) { throw new ApiError(404,'resource_not_found'); }
            $this->db->run('DELETE FROM portal_origins WHERE tenant_id=? AND id=?',[$platform,$id]);
            $this->audit('portal_origin',$id,['origin']);
            return ['status'=>204,'json'=>'','headers'=>[]];
        }
        $origin=Portal::normalizeOrigin($b->origin);
        if ($origin===null) { throw new ApiError(422,'validation_failed',[],'invalid_origin'); }
        if ($this->db->one('SELECT id FROM portal_origins WHERE origin=?',[$origin])) { throw new ApiError(409,'invalid_transition',[],'origin_exists'); }
        $new=Util::bin(Util::uuid());
        $this->db->run('INSERT INTO portal_origins (tenant_id,id,origin) VALUES (?,?,?)',[$platform,$new,$origin]);
        $this->audit('portal_origin',$new,['origin']);
        return self::response($dto($this->db->one('SELECT * FROM portal_origins WHERE tenant_id=? AND id=?',[$platform,$new])),201);
    }
    private function placeholderFirm(): string { return Placeholders::unit($this->db,$this->tenant,'firm'); }
    private function placeholderSchedule(): string { return Placeholders::schedule($this->db,$this->tenant,$this->c['timezone']); }
    /** Jefe valido: persona de la empresa y sin ciclos (A reporta a B que reporta a A). */
    private function managerOk(string $manager,?string $user): void
    {
        if (!$this->db->one("SELECT id FROM users WHERE tenant_id=? AND id=?",[$this->tenant,$manager])) { throw new ApiError(422,'validation_failed',[],'unknown_manager'); }
        for ($m=$manager,$n=0; $m!==null && $n<50; $n++) {
            if ($user!==null && $m===$user) { throw new ApiError(422,'validation_failed',[],'hierarchy_cycle'); }
            $m=$this->db->one('SELECT reports_to_user_id r FROM users WHERE tenant_id=? AND id=?',[$this->tenant,$m])['r']??null;
        }
    }
    // Una cedula identifica a una sola persona de la empresa (el alta de equipos la usa para asignar el equipo).
    private function documentFree(string $document,?string $user): void
    {
        if (strlen($document)<3) { throw new ApiError(422,'validation_failed',[],'invalid_document'); }
        $other=$this->db->one("SELECT user_id FROM user_external_refs WHERE tenant_id=? AND source IN ('document','k3:cc') AND external_ref=?".($user?' AND user_id<>?':''),$user?[$this->tenant,$document,$user]:[$this->tenant,$document]);
        if ($other) { throw new ApiError(409,'invalid_transition',[],'document_in_use'); }
    }
    private function roleDto(array $r): array
    {
        $permissions=array_column($this->db->run('SELECT permission_code FROM role_permissions WHERE tenant_id=? AND role_id=? ORDER BY permission_code',[$this->tenant,$r['id']])->fetchAll(),'permission_code');
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'permissions'=>$permissions,'active'=>(bool)$r['active'],'assignable_by_rule'=>(bool)$r['assignable_by_rule'],'seed_key'=>$r['seed_key'],'version'=>(int)$r['version']];
    }
    private function roles(string $op,?string $id,?object $b): array
    {
        if ($op==='listRoles') { return $this->page('SELECT * FROM roles WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->roleDto($x)); }
        if ($op==='getRole') { return $this->result($this->roleDto($this->row('roles',$id))); }
        // Los roles son de todo el cliente: crearlos o editarlos exige alcance «todo» (§4).
        $this->access->requireFull();
        if ($id) { $old=$this->row('roles',$id); $this->match($old); $this->access->delegate(AccessGrants::permissions($this->db,$this->tenant,$id),AccessScope::all(),null); }
        $this->unique($b->permissions);
        $this->contracted($b->permissions);
        $this->access->delegate($b->permissions,AccessScope::all(),null);
        if ($id) {
            $this->db->run('DELETE FROM role_permissions WHERE tenant_id=? AND role_id=?',[$this->tenant,$id]);
            $this->update('roles',$id,['name'=>$b->name,'active'=>(int)$b->active,'assignable_by_rule'=>(int)($b->assignable_by_rule??$old['assignable_by_rule'])]);
        } else {
            $id=Util::bin(Util::uuid()); $this->db->run("INSERT INTO roles (tenant_id,id,name,active,assignable_by_rule) VALUES (?,?,?,?,?)",[$this->tenant,$id,$b->name,(int)$b->active,(int)($b->assignable_by_rule??false)]);
        }
        foreach ($b->permissions as $permission) { $this->db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)',[$this->tenant,$id,$permission]); }
        foreach ($this->db->run('SELECT user_id FROM access_grants WHERE tenant_id=? AND role_id=? AND user_id IS NOT NULL',[$this->tenant,$id])->fetchAll() as $user) { $this->invalidateUser($user['user_id']); }
        $this->db->run('UPDATE tenants SET auth_version=auth_version+1 WHERE tenant_id=?',[$this->tenant]);
        // Lo que cambia por este rol: quienes lo recibirian por regla o lo tenian (al desactivarlo caen en otra regla).
        RoleRules::apply($this->db,$this->tenant,$this->access,fn(string $user,?string $role,?array $grant):bool=>$role===$id || ($grant['role_id']??null)===$id);
        $this->audit('role',$id,array_keys(get_object_vars($b))); return $this->result($this->roleDto($this->row('roles',$id)),$op==='createRole'?201:200);
    }
    /** Un rol nunca incluye permisos de funciones que el cliente no compro (§4), ni siquiera si lo guarda superadmin. */
    private function contracted(array $permissions): void
    {
        foreach ($permissions as $code) {
            $f=Features::forOperation('',$code);
            if ($f!==null && !Features::has($this->db,$this->tenant,$f)) { throw new ApiError(403,'permission_denied',[],"feature_not_contracted:$f"); }
        }
    }
    private function permissionDto(array $r): array { return ['code'=>$r['code'],'label'=>$r['label'],'resource'=>$r['resource'],'action'=>$r['action'],'delegable'=>(bool)$r['delegable'],'platform_only'=>(bool)$r['platform_only'],'version'=>(int)$r['version']]; }
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
        foreach (['hostname','asset_code','status','agent_version','os_edition','cpu','encryption_state','release_ring','release_pause_reason'] as $k) { $out[$k]=$r[$k]; }
        // Smart App Control declarado por el agente (4.0.19+) en su inventario; null si nunca lo reporto.
        $specs=$r['specs']===null?null:json_decode($r['specs'],true); $out['smart_app_control']=is_array($specs)&&in_array($specs['smart_app_control']??null,['off','on','evaluation','unknown'],true)?$specs['smart_app_control']:null;
        // K3 residual segun el inventario del agente: {running,autostart,installed} con bool o null (desconocido); null si nunca lo reporto.
        $k3=is_array($specs)&&is_array($specs['legacy_k3']??null)?$specs['legacy_k3']:null; $out['legacy_k3']=$k3===null?null:['running'=>is_bool($k3['running']??null)?$k3['running']:null,'autostart'=>is_bool($k3['autostart']??null)?$k3['autostart']:null,'installed'=>is_bool($k3['installed']??null)?$k3['installed']:null];
        $out+=['last_seen_at'=>$r['last_seen_at']===null?null:Util::time($r['last_seen_at']),'ram_bytes'=>(int)$r['ram_bytes'],'capabilities'=>json_decode($r['capabilities']),'policy_version'=>$r['policy_version']===null?null:(int)$r['policy_version'],'version'=>(int)$r['version']];
        // Ordenes que el plan de su dueño permite: el panel deshabilita las demas sin un intento fallido.
        $out['allowed_commands']=$this->allowedCommands($r); return $out;
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
        if ($op==='getDeviceSecurity') {
            // Ultimo reporte declarado por el agente; el alcance ya se valido arriba con access->user().
            $r=$this->db->one('SELECT event_id,observed_at,controls FROM security_reports WHERE tenant_id=? AND device_id=? ORDER BY observed_at DESC,event_id DESC LIMIT 1',[$this->tenant,$d['id']]);
            if (!$r) { throw new ApiError(404,'resource_not_found'); }
            return $this->result(['device_id'=>Util::id($d['id']),'event_id'=>Util::id($r['event_id']),'observed_at'=>Util::time($r['observed_at']),'controls'=>json_decode($r['controls'])]);
        }
        if ($op==='listAdminCommands') { return $this->page('SELECT * FROM device_command WHERE tenant_id=? AND device_id=?',[$this->tenant,$id],fn($x)=>$this->commandDto($x)); }
        if ($op==='createDeviceCommand') {
            if ($d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            $expires=Util::sqlTime($b->expires_at); $delta=strtotime($b->expires_at)-time();
            if ($delta<=0 || $delta>86400 || trim($b->reason)==='') { throw new ApiError(422,'validation_failed'); }
            if ($b->type==='wipe') { $this->reauth(); if (($b->confirmation??'')!==$d['hostname']) { throw new ApiError(422,'validation_failed'); } }
            // Parametros solo en rename_computer. Los agentes < 4.0.8 rechazan campos desconocidos en TODO el sync,
            // asi que el renombre se niega a esos agentes en vez de dejarlos sin poder sincronizar.
            $parameters=null;
            if ($b->type==='rename_computer') {
                $name=$b->parameters->computer_name??null;
                if (!is_string($name) || !preg_match('/^(?![0-9]+$)[A-Za-z0-9-]{1,15}$/',$name)) { throw new ApiError(422,'validation_failed'); }
                if (version_compare($d['agent_version'],'4.0.8','<')) { throw new ApiError(409,'agent_too_old'); }
                $parameters=Util::json(['computer_name'=>$name,'restart_now'=>(bool)($b->parameters->restart_now??false)]);
            } elseif (($b->type==='restart' || $b->type==='shutdown') && isset($b->parameters)) {
                // Aviso previo (1, 5 o 10 min). Solo notice_seconds; 0 es inmediato y se guarda sin parametros para
                // que lo ejecute cualquier agente. Con aviso, solo agentes >= 4.0.17 (los anteriores no conocen el campo).
                $keys=array_keys(get_object_vars($b->parameters)); $notice=$b->parameters->notice_seconds??null;
                if ($keys!==['notice_seconds'] || !in_array($notice,[0,60,300,600],true)) { throw new ApiError(422,'validation_failed'); }
                if ($notice>0) {
                    if (version_compare($d['agent_version'],'4.0.17','<')) { throw new ApiError(409,'agent_too_old'); }
                    $parameters=Util::json(['notice_seconds'=>$notice]);
                }
            } elseif (isset($b->parameters)) { throw new ApiError(422,'validation_failed'); }
            $cid=DeviceIntake::enqueueCommand($this->db,$this->tenant,$d,$b->type,$b->reason,$parameters,$expires);
            $this->audit('device_command',$cid,['type','device_id','expires_at']); return self::response($this->commandDto($this->row('device_command',$cid)),202);
        }
        $this->match($d);
        if ($op==='assignDevice') {
            $target=Util::bin($b->user_id); $u=$target===$this->deviceUser['id']?$this->deviceUser:$this->access->user($target); if ($u['status']!=='active' || $d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            $at=$this->db->one('SELECT UTC_TIMESTAMP(6) at')['at'];
            $this->db->run('UPDATE device_assignments SET ends_at=? WHERE tenant_id=? AND device_id=? AND ends_at IS NULL',[$at,$this->tenant,$id]);
            $this->db->run('INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,?,?)',[$this->tenant,Util::bin(Util::uuid()),$id,$u['id'],$at,$b->reason]);
            $this->update('devices',$id,['user_id'=>$u['id']]); $this->revokeDevice($id); $this->dirty([$id]);
        } else {
            if (isset($b->status) && $b->status==='active' && $d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
            if (property_exists($b,'release_pause_reason') && !isset($b->release_ring)) { throw new ApiError(422,'validation_failed'); }
            if (isset($b->release_ring)) {
                $reason=trim($b->release_pause_reason??'');
                if ($b->release_ring==='pausado' && $reason==='') { throw new ApiError(422,'validation_failed'); }
                $b->release_pause_reason=$b->release_ring==='pausado'?$reason:null;
            }
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
        else { $this->org($target,$kind); if (!$this->access->allowsUnitTarget($kind,$target)) { throw new ApiError(403,'permission_denied',[],'scope_denied'); } }
    }
    private function validateRules(array $rules): void
    {
        $this->unique(array_map(static fn($x)=>$x->id,$rules));
        $host=strtolower(parse_url(Config::get('ORIGIN'),PHP_URL_HOST));
        foreach ($rules as $rule) {
            // Cada tipo de regla del agente es una funcion vendible (web, usb, descargas, instalaciones).
            $feature=Features::forRuleKind($rule->kind);
            if ($feature!==null && !$this->c['platform'] && !Features::has($this->db,$this->tenant,$feature)) { throw new ApiError(403,'permission_denied',[],"feature_not_contracted:$feature"); }
            if ($rule->schedule_id!==null) { $this->row('schedules',Util::bin($rule->schedule_id)); }
            $this->unique($rule->targets);
            foreach ($rule->targets as $target) {
                $pattern=match($rule->kind) {
                    'web'=>'/^(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/iD',
                    'download'=>'/^\.?[a-z0-9]{1,20}$/iD',
                    default=>'/^[a-z0-9][a-z0-9._:\\-]{0,254}$/iD'
                };
                // "*" = todo: es lo que el agente aplica en descargas, instalaciones y USB (bloquear o permitir todo).
                $blanket=$target==='*' && in_array($rule->kind,['download','installation','usb'],true);
                // El detalle dice que valor fallo: el panel lo muestra en vez de un error generico.
                if (!$blanket && !preg_match($pattern,$target)) { throw new ApiError(422,'validation_failed',[],'invalid_target:'.$rule->kind.':'.mb_substr($target,0,80)); }
                $domain=strtolower(ltrim($target,'*.'));
                if ($rule->kind==='web' && $rule->effect==='deny' && ($domain===$host || str_ends_with($host,'.'.$domain))) { throw new ApiError(422,'validation_failed',[],'blocks_panel:'.$target); }
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
    // Mudanzas de servidor (Relocations): solo plataforma, en su propio contexto; registrar y activar el modo mudado piden reauth.
    private function relocations(string $op,?object $b): array
    {
        $this->access->requireFull(); $platform=Util::bin(AdminAuth::PLATFORM);
        if ($this->tenant!==$platform) { throw new ApiError(404,'resource_not_found'); }
        if ($op==='listRelocations') { return $this->page('SELECT * FROM relocations WHERE tenant_id=?',[$platform],fn($x)=>Relocations::dto($x),'registered_at'); }
        $this->reauth(); $relocations=new Relocations($this->db);
        if ($op==='registerRelocation') {
            $row=$relocations->register($b->relocation_jws,$this->c['principal_id']);
            (new Audit($this->db))->record($this->c,$this->r,'relocation.registered','relocation',$row['id'],['sequence','from_base','to_base','tenants','exclude_devices']);
            return self::response(Relocations::dto($row),201);
        }
        $row=$relocations->enforcement($this->r->resourceId,$b->enforced,$this->c['principal_id']);
        (new Audit($this->db))->record($this->c,$this->r,$b->enforced?'relocation.enforced':'relocation.released','relocation',$row['id'],['enforced_at']);
        return self::response(Relocations::dto($row));
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
            if ($f==='user_id') { $this->access->user($id); } else { $this->org($id,substr($f,0,-3)); $this->access->requireFilter(substr($f,0,-3),$id); }
            $filters.=" AND a.$f=?"; $fargs[]=$id;
        }
        // Empresa del grupo y pais de la sede (sin filtro = todo el grupo).
        if (isset($_GET['company_id'])) { $id=Util::bin($_GET['company_id']); $this->org($id,'company'); $this->access->requireFilter('company',$id); $filters.=' AND a.company_id=?'; $fargs[]=$id; }
        if (isset($_GET['country'])) { $filters.=' AND EXISTS (SELECT 1 FROM org_units cs WHERE cs.tenant_id=a.tenant_id AND cs.id=a.site_id AND cs.country_code=?)'; $fargs[]=$_GET['country']; }
        if (isset($_GET['city'])) { $this->access->requireFilter('city',trim($_GET['city'])); $filters.=' AND EXISTS (SELECT 1 FROM org_units cy WHERE cy.tenant_id=a.tenant_id AND cy.id=a.site_id AND cy.city=?)'; $fargs[]=trim($_GET['city']); }
        $base="FROM %s x JOIN user_assignments a ON a.tenant_id=x.tenant_id AND a.id=x.user_assignment_id AND a.user_id=x.user_id JOIN users u ON u.tenant_id=x.tenant_id AND u.id=x.user_id WHERE x.tenant_id=? AND x.day>=? AND x.day<? AND $scope$filters";
        $params=[$this->tenant,$from,$to,...$args,...$fargs];
        $summary=$this->db->run('SELECT x.day,SUM(x.active_seconds) active_seconds,SUM(x.idle_seconds) idle_seconds,SUM(x.productive_seconds) productive_seconds,SUM(COALESCE(x.window_active_seconds,x.active_seconds)) window_active_seconds,SUM(COALESCE(x.unproductive_seconds,0)) unproductive_seconds,SUM(x.unclassified_seconds) unclassified_seconds,SUM(x.simulated_seconds) simulated_seconds,SUM(x.locked_seconds) locked_seconds,SUM(x.expected_seconds) expected_seconds,MIN(x.calculated_at) calculated_at,MIN(x.data_through) data_through,GROUP_CONCAT(DISTINCT x.calculation_version) calculation_version '.sprintf($base,'day_summary').' GROUP BY x.day ORDER BY x.day',$params)->fetchAll();
        $episodes=$this->db->run("SELECT x.day,SUM(x.active_seconds) active_seconds,SUM(x.idle_seconds) idle_seconds,SUM(IF(x.category='productive',x.active_seconds,0)) productive_seconds,MIN(x.calculated_at) calculated_at,MIN(x.data_through) data_through,GROUP_CONCAT(DISTINCT x.calculation_version) calculation_version ".sprintf($base,'episode_daily').' GROUP BY x.day ORDER BY x.day',$params)->fetchAll();
        $focus=$this->db->run('SELECT x.day,AVG(x.focus_score) score,MIN(x.calculated_at) calculated_at,MIN(x.data_through) data_through '.sprintf($base,'focus_daily').' GROUP BY x.day',$params)->fetchAll();
        $rows=[]; foreach ($episodes as $row) { $rows[$row['day']]=$row; } foreach ($summary as $row) { $rows[$row['day']]=$row; } ksort($rows);
        $scores=array_column($focus,'score','day'); $series=[]; $calculated=null; $through=null; $versions=[]; $active=0; $expected=0;
        foreach ($rows as $day=>$row) {
            $a=(int)$row['active_seconds']; $i=(int)$row['idle_seconds'];
            if (isset($row['expected_seconds'])) { $active+=$a; $expected+=(int)$row['expected_seconds']; }
            // Productividad y distraccion sobre el uso medido en ventanas (misma medicion que el numerador).
            $w=(int)($row['window_active_seconds']??$a);
            $series[]=['day'=>$day,'active_seconds'=>$a,'idle_seconds'=>$i,'focus_score'=>isset($scores[$day])?(float)$scores[$day]:null,'productivity_percent'=>$w>0?min(100,round(100*(int)$row['productive_seconds']/$w,2)):null,
                'distraction_percent'=>$w>0&&isset($row['unproductive_seconds'])?min(100,round(100*(int)$row['unproductive_seconds']/$w,2)):null,
                'unclassified_percent'=>$w>0&&isset($row['unclassified_seconds'])&&$row['unclassified_seconds']!==null?min(100,round(100*(int)$row['unclassified_seconds']/$w,2)):null,
                'simulated_seconds'=>isset($row['simulated_seconds'])?(int)$row['simulated_seconds']:null,
                'locked_seconds'=>isset($row['locked_seconds'])?(int)$row['locked_seconds']:null];
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
        // Filtro opcional por tipo de actor: el registro es casi todo automatico (reportes del agente, compilaciones).
        $actor=$_GET['actor_type']??null; $extra=$actor===null?'':' AND actor_type=?';
        $names=[];
        return $this->page('SELECT * FROM audit_log WHERE tenant_id=? AND at>=? AND at<?'.$extra,$actor===null?[$this->tenant,$start,$end]:[$this->tenant,$start,$end,$actor],function($r) use (&$names) {
            $out=[]; foreach (['id','tenant_id','actor_id','resource_id','request_id'] as $f) { $out[$f]=Util::id($r[$f]); }
            foreach (['actor_type','action','resource_type','outcome'] as $f) { $out[$f]=$r[$f]; }
            $actorKey='a'.$r['actor_id']; $resKey=$r['resource_type'].$r['resource_id'];
            $names[$actorKey]??=$this->auditActor($r); $names[$resKey]??=$this->auditResource($r['resource_type'],$r['resource_id']);
            return $out+['at'=>Util::time($r['at']),'changed_fields'=>json_decode($r['changed_fields']),'actor_name'=>$names[$actorKey],'resource_name'=>$names[$resKey]];
        },'at');
    }
    /** Quien hizo la accion, en palabras: correo de la cuenta, nombre del equipo o de la integracion. */
    private function auditActor(array $r): ?string
    {
        $p=$this->db->one('SELECT * FROM principals WHERE tenant_id=? AND id=?',[$r['tenant_id'],$r['actor_id']]);
        if (!$p) { return null; }
        return match ($p['kind']) {
            'admin'=>$this->db->one('SELECT COALESCE(u.display_name,a.display_name,a.email) n FROM admin_accounts a LEFT JOIN users u ON u.tenant_id=a.tenant_id AND u.id=a.user_id WHERE a.tenant_id=? AND a.id=?',[$p['admin_tenant_id'],$p['admin_id']])['n']??null,
            'device'=>$this->db->one('SELECT hostname n FROM devices WHERE tenant_id=? AND id=?',[$p['tenant_id'],$p['device_id']])['n']??null,
            'integration'=>$this->db->one('SELECT name n FROM integrations WHERE tenant_id=? AND id=?',[$p['tenant_id'],$p['integration_id']])['n']??null,
            default=>'Keeper (automático)',
        };
    }
    /** Sobre que se hizo, en palabras (cuando el recurso tiene nombre). */
    private function auditResource(string $type,string $id): ?string
    {
        // [consulta, argumentos]: los recursos de la empresa se buscan con su tenant; cuentas y versiones son globales.
        $q=match ($type) {
            'device'=>['SELECT hostname n FROM devices WHERE tenant_id=? AND id=?',[$this->tenant,$id]],
            'user'=>['SELECT display_name n FROM users WHERE tenant_id=? AND id=?',[$this->tenant,$id]],
            'role'=>['SELECT name n FROM roles WHERE tenant_id=? AND id=?',[$this->tenant,$id]],
            'org_unit'=>['SELECT name n FROM org_units WHERE tenant_id=? AND id=?',[$this->tenant,$id]],
            'schedule'=>['SELECT name n FROM schedules WHERE tenant_id=? AND id=?',[$this->tenant,$id]],
            'holiday_calendar'=>['SELECT name n FROM holiday_calendars WHERE tenant_id=? AND id=?',[$this->tenant,$id]],
            'tenant'=>['SELECT name n FROM tenants WHERE tenant_id=?',[$id]],
            'admin_account'=>['SELECT email n FROM admin_accounts WHERE id=?',[$id]],
            'release'=>['SELECT version n FROM client_releases WHERE id=?',[$id]],
            default=>null,
        };
        if ($q===null) { return null; }
        try { return $this->db->one($q[0],$q[1])['n']??null; } catch (\Throwable) { return null; }
    }
    private function scheduleDto(array $r): array
    {
        $days=$this->db->run('SELECT * FROM schedule_days WHERE tenant_id=? AND schedule_id=? ORDER BY weekday',[$this->tenant,$r['id']])->fetchAll();
        $hm=static fn(?string $v): ?string => $v===null?null:substr($v,0,5);
        return ['id'=>Util::id($r['id']),'tenant_id'=>Util::id($r['tenant_id']),'name'=>$r['name'],'timezone'=>$r['timezone'],'days'=>array_map('intval',array_column($days,'weekday')),'start_local'=>$hm($r['start_local']),'end_local'=>$hm($r['end_local']),
            'lunch_start_local'=>$hm($r['lunch_start_local']),'lunch_end_local'=>$hm($r['lunch_end_local']),
            'day_hours'=>array_values(array_map(fn($d)=>['weekday'=>(int)$d['weekday'],'start_local'=>$hm($d['start_local']),'end_local'=>$hm($d['end_local']),'lunch_start_local'=>$hm($d['lunch_start_local']),'lunch_end_local'=>$hm($d['lunch_end_local'])],array_filter($days,fn($d)=>$d['start_local']!==null))),
            'version'=>(int)$r['version']];
    }
    private function schedules(string $op,?string $id,?object $b): array
    {
        // Portal sin alcance de todo el cliente: solo su propio horario efectivo (el suyo o, si es el provisional, el de la empresa).
        if ($op==='listSchedules' && ($this->c['channel']??null)==='portal' && !$this->access->full()) {
            $own=$this->db->one('SELECT schedule_id FROM users WHERE tenant_id=? AND id=?',[$this->tenant,$this->c['user_id']])['schedule_id']??null;
            if ($own===null || Placeholders::is($this->db,$this->tenant,$own,'schedule_id')) { $own=$this->db->one('SELECT default_schedule_id FROM tenants WHERE tenant_id=?',[$this->tenant])['default_schedule_id']??$own; }
            return $this->page('SELECT * FROM schedules WHERE tenant_id=? AND id=?',[$this->tenant,$own],fn($x)=>$this->scheduleDto($x));
        }
        $this->access->requireFull();
        if ($op==='listSchedules') { return $this->page('SELECT * FROM schedules WHERE tenant_id=?',[$this->tenant],fn($x)=>$this->scheduleDto($x)); }
        if ($id) { $old=$this->row('schedules',$id); if ($op==='getSchedule') { return $this->result($this->scheduleDto($old)); } $this->match($old); }
        DataSources::guardWrite($this->db,$this->tenant,'horarios','schedule',$id);
        $this->timezone($b->timezone); $this->unique($b->days);
        // Almuerzo y franjas por dia son opcionales: si no vienen se conservan (clientes viejos no los conocen).
        $lunch=property_exists($b,'lunch_start_local')||property_exists($b,'lunch_end_local')
            ? [$b->lunch_start_local??null,$b->lunch_end_local??null] : [$old['lunch_start_local']??null,$old['lunch_end_local']??null];
        if (($lunch[0]===null)!==($lunch[1]===null) || ($lunch[0]!==null && substr($lunch[0],0,5)>=substr($lunch[1],0,5))) { throw new ApiError(422,'validation_failed',[],'invalid_lunch'); }
        $hours=[];
        if (property_exists($b,'day_hours')) {
            foreach ($b->day_hours as $h) {
                if (!in_array($h->weekday,$b->days,true) || isset($hours[$h->weekday])) { throw new ApiError(422,'validation_failed',[],'invalid_day_hours'); }
                if (($h->lunch_start_local===null)!==($h->lunch_end_local===null) || ($h->lunch_start_local!==null && $h->lunch_start_local>=$h->lunch_end_local)) { throw new ApiError(422,'validation_failed',[],'invalid_lunch'); }
                $hours[$h->weekday]=[$h->start_local,$h->end_local,$h->lunch_start_local,$h->lunch_end_local];
            }
        } elseif ($id) {
            foreach ($this->db->run('SELECT * FROM schedule_days WHERE tenant_id=? AND schedule_id=? AND start_local IS NOT NULL',[$this->tenant,$id])->fetchAll() as $d) {
                if (in_array((int)$d['weekday'],$b->days,true)) { $hours[(int)$d['weekday']]=[$d['start_local'],$d['end_local'],$d['lunch_start_local'],$d['lunch_end_local']]; }
            }
        }
        $values=['name'=>$b->name,'timezone'=>$b->timezone,'start_local'=>$b->start_local,'end_local'=>$b->end_local,'lunch_start_local'=>$lunch[0],'lunch_end_local'=>$lunch[1]];
        if ($id) { $this->update('schedules',$id,$values); $this->db->run('DELETE FROM schedule_days WHERE tenant_id=? AND schedule_id=?',[$this->tenant,$id]); }
        else { $id=Util::bin(Util::uuid()); $this->db->run('INSERT INTO schedules (tenant_id,id,name,timezone,start_local,end_local,lunch_start_local,lunch_end_local) VALUES (?,?,?,?,?,?,?,?)',[$this->tenant,$id,...array_values($values)]); }
        foreach ($b->days as $day) { $this->db->run('INSERT INTO schedule_days (tenant_id,schedule_id,weekday,start_local,end_local,lunch_start_local,lunch_end_local) VALUES (?,?,?,?,?,?,?)',[$this->tenant,$id,$day,...($hours[$day]??[null,null,null,null])]); }
        $this->audit('schedule',$id,array_keys(get_object_vars($b))); $this->dirty();
        return $this->result($this->scheduleDto($this->row('schedules',$id)),$op==='createSchedule'?201:200);
    }
}
