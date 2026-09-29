<?php
declare(strict_types=1);
use Keeper\{AdminAuth,Database,Util,Validator,Config};

function adminRequest(array $a,string $method,string $path,mixed $body=null,array $headers=[]): array
{
    $r=request($method,$path,$body,null,null,null,null,$headers+['cookie'=>'__Host-keeper_admin='.$a['cookie'],'x-csrf-token'=>$a['csrf'],'origin'=>Config::get('ORIGIN'),'x-tenant-id'=>Util::id($a['tenant'])]);
    if ($r[0]<300 && $r[0]!==204) {
        $route=explode('?',$path)[0]; $v=new Validator();
        foreach ($v->contract['paths'] as $template=>$ops) {
            $pattern=str_replace(['\{id\}','\{code\}'],'[^/]+',preg_quote($template,'~'));
            if (preg_match('~^'.$pattern.'$~D',$route) && isset($ops[strtolower($method)])) {
                $schema=$ops[strtolower($method)]['responses'][(string)$r[0]]['content']['application/json']['schema']??null;
                check($schema!==null,'admin success status declared: '.$method.' '.$template);
                $v->check($schema,json_decode($r[3])); break;
            }
        }
    }
    return $r;
}
function adminFixture(Database $db,array $u,array $permissions,bool $platform=false,string $scope='tenant',array $scopeIds=[]): array
{
    $tenant=$platform?Util::bin(AdminAuth::PLATFORM):$u['tenant_id']; $admin=Util::bin(Util::uuid()); $role=Util::bin(Util::uuid()); $password=Util::b64(random_bytes(20)); $email=Util::uuid().'@example.test';
    if (!$platform) {
        $db->run('UPDATE users SET panel_login_enabled=TRUE WHERE tenant_id=? AND id=?',[$tenant,$u['id']]);
        $db->run('INSERT INTO roles (tenant_id,id,name,scope_kind) VALUES (?,?,?,?)',[$tenant,$role,Util::uuid(),$scope]);
        foreach ($permissions as $p) { $db->run('INSERT INTO role_permissions (tenant_id,role_id,permission_code) VALUES (?,?,?)',[$tenant,$role,$p]); }
        foreach ($scopeIds as $id) { $db->run("INSERT INTO role_{$scope}_scopes (tenant_id,role_id,{$scope}_id) VALUES (?,?,?)",[$tenant,$role,$id]); }
        $db->run('INSERT INTO user_roles (tenant_id,user_id,role_id) VALUES (?,?,?)',[$tenant,$u['id'],$role]);
    }
    $db->run('INSERT INTO admin_accounts (tenant_id,id,user_id,email,password_hash,is_platform_admin) VALUES (?,?,?,?,?,?)',[$tenant,$admin,$platform?null:$u['id'],$email,password_hash($password,PASSWORD_DEFAULT),(int)$platform]);
    return adminLogin(['tenant'=>$tenant,'id'=>$admin,'email'=>$email,'password'=>$password,'role'=>$role]);
}
function adminLogin(array $a): array
{
    $pre=request('GET','/auth/csrf'); $csrf=expect($pre,200,'prelogin CSRF')['csrf_token'];
    check(str_contains($pre[2]['set-cookie'],'Secure') && str_contains($pre[2]['set-cookie'],'HttpOnly') && str_contains($pre[2]['set-cookie'],'SameSite=Strict'),'secure cookie attributes');
    $cookie=explode(';',$pre[2]['set-cookie'])[0];
    $login=request('POST','/auth/login',['email'=>$a['email'],'password'=>$a['password']],null,null,null,null,['cookie'=>$cookie,'x-csrf-token'=>$csrf,'origin'=>Config::get('ORIGIN')]);
    $body=expect($login,200,'admin login');
    $a['cookie']=explode('=',explode(';',$login[2]['set-cookie'])[0],2)[1]; $a['csrf']=$body['csrf_token']; return $a;
}
function adminMember(Database $db,array $base): array
{
    $id=Util::bin(Util::uuid());
    $db->run('INSERT INTO users (tenant_id,id,display_name,firm_id,site_id,area_id,position_id,schedule_id) VALUES (?,?,?,?,?,?,?,?)',[$base['tenant_id'],$id,'Admin test member',$base['firm_id'],$base['site_id'],$base['area_id'],$base['position_id'],$base['schedule_id']]);
    return $db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$base['tenant_id'],$id]);
}
function adminTests(Database $db): void
{
    $before=$GLOBALS['checks'];
    $aUser=secondTenant($db); $bUser=secondTenant($db); $tenant=$aUser['tenant_id']; $tid=Util::id($tenant); $uid=Util::id($aUser['id']);
    $permissions=array_column($db->run('SELECT code FROM permissions WHERE platform_only=FALSE')->fetchAll(),'code');
    $admin=adminFixture($db,$aUser,$permissions); $other=adminFixture($db,$bUser,$permissions);
    $readerUser=adminMember($db,$aUser); $reader=adminFixture($db,$readerUser,['usuarios.ver']);
    $delegateUser=adminMember($db,$aUser); $delegate=adminFixture($db,$delegateUser,['roles.gestionar','roles.ver','usuarios.ver']);
    $platform=adminFixture($db,[],[],true); $platformTenant=$platform; $platformTenant['tenant']=$tenant;
    $brand=['display_name'=>'Admin tenant','logo_url'=>'https://example.test/logo.svg','primary_color'=>'#003A5D','accent_color'=>'#BE1622'];
    $newTenant=expect(adminRequest($platform,'POST','/tenants',['name'=>'Created tenant','timezone'=>'America/Bogota','branding'=>$brand]),201,'create tenant');
    check(!$newTenant['rbac_self_management'],'new tenant gate defaults off');
    $listed=expect(adminRequest($admin,'GET','/tenants'),200,'company tenant list'); check(count($listed['data'])===1 && $listed['data'][0]['id']===$tid,'company list contains only own tenant');
    expect(adminRequest($platform,'GET','/tenants?limit=2'),200,'platform tenant list');
    expect(adminRequest($platform,'PATCH','/tenants/'.$newTenant['id'],['name'=>'Edited tenant'],['if-match'=>'"1"','x-tenant-id'=>$newTenant['id']]),200,'edit tenant');
    $scopedUser=adminMember($db,$aUser); $scoped=adminFixture($db,$scopedUser,['usuarios.ver','equipos.ver','reglas.editar','roles.gestionar','reportes.ver'],false,'area',[$aUser['area_id']]);
    $outsideArea=Util::bin(Util::uuid()); $db->run("INSERT INTO org_units (tenant_id,id,kind,name) VALUES (?,?,'area','Outside delegated area')",[$tenant,$outsideArea]);
    $outside=adminMember($db,array_replace($aUser,['area_id'=>$outsideArea]));
    $scopedList=expect(adminRequest($scoped,'GET','/users?limit=100'),200,'area scope list'); check(!in_array(Util::id($outside['id']),array_column($scopedList['data'],'id'),true),'area scope hides outside members');
    expect(adminRequest($scoped,'GET','/users/'.Util::id($outside['id'])),404,'same tenant outside area ID');
    expect(adminRequest($reader,'POST','/roles',['name'=>'Forbidden','permissions'=>[],'scope'=>['kind'=>'tenant','resource_ids'=>[]],'active'=>true]),403,'missing permission');
    expect(adminRequest($admin,'GET','/users/'.$uid,null,['x-tenant-id'=>Util::id($bUser['tenant_id'])]),404,'tenant selector cannot select foreign company');
    expect(adminRequest($admin,'GET','/users/'.Util::id($bUser['id'])),404,'foreign user ID');
    expect(adminRequest($other,'GET','/users/'.$uid),404,'reverse foreign user ID');
    expect(adminRequest($admin,'GET','/users',null,['x-tenant-id'=>'']),422,'selector required');
    $roleBody=['name'=>'Read only','permissions'=>['usuarios.ver'],'scope'=>['kind'=>'tenant','resource_ids'=>[]],'active'=>true];
    expect(adminRequest($delegate,'POST','/roles',$roleBody),403,'company gate off');
    $t=expect(adminRequest($platformTenant,'GET','/tenants/'.$tid),200,'tenant read');
    expect(adminRequest($admin,'PATCH','/tenants/'.$tid.'/rbac-gate',['enabled'=>true,'reason'=>'Forbidden'],['if-match'=>'"'.$t['version'].'"']),403,'only platform changes gate');
    $t=expect(adminRequest($platformTenant,'PATCH','/tenants/'.$tid.'/rbac-gate',['enabled'=>true,'reason'=>'Test delegation'],['if-match'=>'"'.$t['version'].'"']),200,'enable gate');
    expect(adminRequest($reader,'POST','/roles',$roleBody),403,'gate on still requires meta permission');
    $role=expect(adminRequest($delegate,'POST','/roles',$roleBody),201,'delegated creation');
    expect(adminRequest($scoped,'POST','/roles',$roleBody),403,'scoped delegate cannot create tenant-wide role');
    expect(adminRequest($delegate,'POST','/roles',array_replace($roleBody,['name'=>'Escalate','permissions'=>['equipos.apagar']])),403,'cannot grant missing permission');
    expect(adminRequest($delegate,'POST','/roles',array_replace($roleBody,['name'=>'Meta clone','permissions'=>['roles.gestionar']])),403,'cannot clone meta permission');
    $self=expect(adminRequest($delegate,'GET','/roles/'.Util::id($delegate['role'])),200,'read own role');
    expect(adminRequest($delegate,'PATCH','/roles/'.$self['id'],array_replace($roleBody,['name'=>'Own escalated','permissions'=>['roles.gestionar','usuarios.ver','equipos.apagar']]),['if-match'=>'"'.$self['version'].'"']),403,'editing own role cannot escalate');
    expect(adminRequest($delegate,'PUT','/users/'.Util::id($delegateUser['id']).'/roles',['role_ids'=>[Util::id($admin['role'])]],['if-match'=>'"1"']),403,'indirect meta assignment blocked');
    $u=expect(adminRequest($admin,'GET','/users/'.Util::id($readerUser['id'])),200,'read role recipient');
    expect(adminRequest($platformTenant,'PUT','/users/'.Util::id($readerUser['id']).'/roles',['role_ids'=>[$role['id']]],['if-match'=>'"'.$u['version'].'"']),200,'platform assigns role');
    expect(adminRequest($reader,'GET','/users'),401,'privilege change revokes session'); $reader=adminLogin($reader);
    expect(adminRequest($delegate,'PATCH','/roles/'.$role['id'],array_replace($roleBody,['active'=>false]),['if-match'=>'"1"']),200,'deactivate role');
    expect(adminRequest($reader,'GET','/users'),401,'role edit revokes sessions');
    $units=expect(adminRequest($admin,'GET','/organization?limit=1'),200,'org cursor first');
    check($units['next_cursor']!==null,'org cursor exists');
    expect(adminRequest($other,'GET','/organization?limit=1&cursor='.rawurlencode($units['next_cursor'])),404,'cursor cannot cross tenant');
    $next=expect(adminRequest($admin,'GET','/organization?limit=1&cursor='.rawurlencode($units['next_cursor'])),200,'org cursor next');
    check($next['data'][0]['id']!==$units['data'][0]['id'],'cursor advances');
    $orgBody=['kind'=>'area','name'=>'New area','parent_id'=>null,'active'=>true];
    expect(adminRequest($admin,'POST','/organization',$orgBody,['x-csrf-token'=>'']),403,'POST CSRF required');
    expect(adminRequest($admin,'POST','/organization',$orgBody,['origin'=>'https://attacker.invalid']),403,'origin rejected');
    $area=expect(adminRequest($admin,'POST','/organization',$orgBody),201,'create org');
    expect(adminRequest($admin,'GET','/organization/'.$area['id']),200,'get org');
    expect(adminRequest($admin,'PATCH','/organization/'.$area['id'],array_replace($orgBody,['active'=>false]),['if-match'=>'"1"']),200,'deactivate unused org');
    expect(adminRequest($admin,'PATCH','/organization/'.$area['id'],$orgBody,['if-match'=>'"1"']),412,'stale version rejected');
    expect(adminRequest($admin,'PATCH','/organization/'.$area['id'],$orgBody),428,'If-Match required');
    $scheduleBody=['name'=>'Admin schedule','timezone'=>'America/Bogota','days'=>[1,2,3,4,5],'start_local'=>'08:00','end_local'=>'17:00'];
    $schedule=expect(adminRequest($admin,'POST','/schedules',$scheduleBody),201,'create schedule');
    expect(adminRequest($admin,'GET','/schedules'),200,'list schedules'); expect(adminRequest($admin,'GET','/schedules/'.$schedule['id']),200,'get schedule');
    expect(adminRequest($admin,'PUT','/schedules/'.$schedule['id'],array_replace($scheduleBody,['end_local'=>'18:00']),['if-match'=>'"1"']),200,'put schedule');
    $memberBody=['display_name'=>'Colaborador con firma','firm_id'=>Util::id($aUser['firm_id']),'site_id'=>Util::id($aUser['site_id']),'area_id'=>Util::id($aUser['area_id']),'position_id'=>Util::id($aUser['position_id']),'schedule_id'=>$schedule['id']];
    expect(adminRequest($admin,'POST','/users',array_replace($memberBody,['firm_id'=>Util::id($bUser['firm_id'])])),404,'foreign firm rejected');
    $member=expect(adminRequest($admin,'POST','/users',$memberBody),201,'create collaborator'); check(!$member['panel_login_enabled'],'collaborator has no login');
    expect(adminRequest($admin,'GET','/users?limit=2'),200,'list users');
    $member=expect(adminRequest($admin,'PATCH','/users/'.$member['id'],['display_name'=>'Changed'],['if-match'=>'"1"']),200,'edit user');
    $key=keypair(); $token=enroll($db,$aUser,$key); $device=$token['device_id'];
    $foreign=enroll($db,$bUser,keypair());
    expect(adminRequest($admin,'GET','/tenants/'.$tid.'/devices'),200,'list tenant devices');
    expect(adminRequest($admin,'GET','/devices/'.$foreign['device_id']),404,'foreign device ID');
    $d=expect(adminRequest($admin,'GET','/devices/'.$device),200,'get device');
    $d=expect(adminRequest($admin,'PATCH','/devices/'.$device,['hostname'=>'admin-device'],['if-match'=>'"'.$d['version'].'"']),200,'rename device');
    adminReviewTests($db,$admin,$aUser,$memberBody,$device);
    $command=['type'=>'lock','reason'=>'Admin smoke','expires_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+600)]; $idem=Util::uuid();
    $cmd=expect(adminRequest($admin,'POST','/devices/'.$device.'/commands',$command,['idempotency-key'=>$idem]),202,'queue remote action');
    $replay=expect(adminRequest($admin,'POST','/devices/'.$device.'/commands',$command,['idempotency-key'=>$idem]),202,'replay remote action'); check($cmd['id']===$replay['id'],'command replay no duplicate');
    expect(adminRequest($admin,'POST','/devices/'.$device.'/commands',array_replace($command,['type'=>'shutdown']),['idempotency-key'=>$idem]),409,'command idempotency conflict');
    check((int)$db->one('SELECT COUNT(*) n FROM device_command WHERE tenant_id=? AND id=?',[$tenant,Util::bin($cmd['id'])])['n']===1,'command persisted');
    check((int)$db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND resource_id=? AND actor_type='admin'",[$tenant,Util::bin($cmd['id'])])['n']===1,'queued action audited once');
    expect(adminRequest($admin,'GET','/devices/'.$device.'/commands'),200,'list command history');
    expect(adminRequest($delegate,'POST','/devices/'.$device.'/commands',$command,['idempotency-key'=>Util::uuid()]),403,'action named permission required');
    expect(adminRequest($admin,'POST','/devices/'.$foreign['device_id'].'/commands',$command,['idempotency-key'=>Util::uuid()]),404,'cannot command foreign device');
    expect(adminRequest($admin,'POST','/devices/'.$device.'/commands',array_replace($command,['type'=>'wipe','confirmation'=>'admin-device']),['idempotency-key'=>Util::uuid()]),403,'wipe needs reauth');
    expect(adminRequest($admin,'POST','/auth/reauth',['password'=>$admin['password']]),200,'reauth');
    expect(adminRequest($admin,'POST','/devices/'.$device.'/commands',array_replace($command,['type'=>'wipe','confirmation'=>'wrong']),['idempotency-key'=>Util::uuid()]),422,'wipe confirmation');
    $tierBody=['name'=>'Admin policies','badge_label'=>'Admin','entitlements'=>['policies','devices'],'active'=>true];
    $tier=expect(adminRequest($admin,'POST','/tiers',$tierBody),201,'create tier');
    expect(adminRequest($admin,'GET','/tiers'),200,'list tiers'); expect(adminRequest($admin,'GET','/tiers/'.$tier['id']),200,'get tier');
    expect(adminRequest($admin,'GET','/users/'.$uid.'/subscription'),404,'empty subscription');
    $sub=expect(adminRequest($admin,'PUT','/users/'.$uid.'/subscription',['tier_id'=>$tier['id'],'starts_at'=>gmdate('Y-m-d\TH:i:s\Z',time()-60),'ends_at'=>null],['if-match'=>'"0"']),200,'assign subscription');
    expect(adminRequest($admin,'GET','/users/'.$uid.'/subscription'),200,'get subscription');
    $futureBody=['tier_id'=>$tier['id'],'starts_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'ends_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+10800)];
    $futurePath='/users/'.$member['id'].'/subscription';
    $future=expect(adminRequest($admin,'PUT',$futurePath,$futureBody,['if-match'=>'"0"']),200,'schedule finite subscription');
    $readFuture=expect(adminRequest($admin,'GET',$futurePath),200,'scheduled subscription visible for If-Match');
    check($readFuture['id']===$future['id'],'GET returns pending subscription');
    $futureBody['starts_at']=gmdate('Y-m-d\TH:i:s\Z',time()+7200);
    expect(adminRequest($admin,'PUT',$futurePath,$futureBody,['if-match'=>'"0"']),412,'future subscription invalidates empty If-Match');
    $nextFuture=expect(adminRequest($admin,'PUT',$futurePath,$futureBody,['if-match'=>'"'.$future['version'].'"']),200,'close overlapping future subscription');
    $closedFuture=$db->one('SELECT ends_at FROM subscriptions WHERE tenant_id=? AND id=?',[$tenant,Util::bin($future['id'])]);
    check($closedFuture['ends_at']===Util::sqlTime($nextFuture['starts_at']),'future intervals meet without overlap');
    $futureBody['starts_at']=$future['starts_at'];
    expect(adminRequest($admin,'PUT',$futurePath,$futureBody,['if-match'=>'"'.$nextFuture['version'].'"']),409,'cannot insert before pending subscription');
    $futureBody['starts_at']=gmdate('Y-m-d\TH:i:s\Z',time()+14400); $futureBody['ends_at']=null;
    expect(adminRequest($admin,'PUT',$futurePath,$futureBody,['if-match'=>'"'.$nextFuture['version'].'"']),200,'later subscription preserves gap');
    check($db->one('SELECT ends_at FROM subscriptions WHERE tenant_id=? AND id=?',[$tenant,Util::bin($nextFuture['id'])])['ends_at']===Util::sqlTime($nextFuture['ends_at']),'finite subscription never extended');
    $v1=(int)$db->one('SELECT MAX(policy_version) v FROM effective_policies WHERE tenant_id=? AND device_id=?',[$tenant,Util::bin($device)])['v'];
    $policyBody=['name'=>'Web policy','target_type'=>'tenant','target_id'=>$tid,'enabled'=>true,'rules'=>[compilerRule()]];
    $policy=expect(adminRequest($admin,'POST','/policies',$policyBody),201,'create policy compiles');
    $v2=(int)$db->one('SELECT MAX(policy_version) v FROM effective_policies WHERE tenant_id=? AND device_id=?',[$tenant,Util::bin($device)])['v']; check($v2>$v1,'policy save raises effective version');
    expect(adminRequest($admin,'GET','/policies'),200,'list policies'); expect(adminRequest($admin,'GET','/policies/'.$policy['id']),200,'get policy');
    $policyBody['rules'][0]->targets=['another.example.test'];
    $policy=expect(adminRequest($admin,'PATCH','/policies/'.$policy['id'],$policyBody,['if-match'=>'"1"']),200,'new immutable policy revision');
    $v3=(int)$db->one('SELECT MAX(policy_version) v FROM effective_policies WHERE tenant_id=? AND device_id=?',[$tenant,Util::bin($device)])['v']; check($v3>$v2,'policy edit recompiles');
    $conflict=$policyBody; $conflict['rules'][]=compilerRule('allow',['another.example.test']);
    expect(adminRequest($admin,'PATCH','/policies/'.$policy['id'],$conflict,['if-match'=>'"2"']),409,'conflicting policy rolls back');
    check((int)$db->one('SELECT version FROM policy_documents WHERE tenant_id=? AND id=?',[$tenant,Util::bin($policy['id'])])['version']===2,'invalid policy did not commit');
    check((int)$db->one('SELECT COUNT(*) n FROM policy_versions WHERE tenant_id=? AND policy_id=?',[$tenant,Util::bin($policy['id'])])['n']===2,'policy versions retained');
    expect(adminRequest($admin,'POST','/policies',array_replace($policyBody,['target_id'=>Util::id($bUser['tenant_id'])])),404,'foreign policy assignment rejected');
    expect(adminRequest($admin,'PATCH','/tiers/'.$tier['id'],array_replace($tierBody,['entitlements'=>['devices']]),['if-match'=>'"1"']),200,'tier module change compiles');
    expect(adminRequest($admin,'GET','/permissions?limit=3'),200,'permission catalog');
    $p=expect(adminRequest($admin,'GET','/permissions/usuarios.ver'),200,'permission detail');
    expect(adminRequest($platform,'PATCH','/permissions/usuarios.ver',['label'=>'View users','delegable'=>true],['if-match'=>'"'.$p['version'].'"']),200,'platform metadata');
    $assignment=$db->one('SELECT id FROM user_assignments WHERE tenant_id=? AND user_id=? AND ends_at IS NULL',[$tenant,$aUser['id']])['id'];
    $db->run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,?,'editor','productive',999,1,1,'admin-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$tenant,$aUser['id'],$assignment,Util::bin($device)]);
    $db->run("INSERT INTO day_summary (tenant_id,day,user_id,user_assignment_id,active_seconds,idle_seconds,productive_seconds,expected_seconds,coverage_percent,productivity_percent,calculation_version,timezone,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,120,30,90,240,50,75,'admin-test','America/Bogota',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$tenant,$aUser['id'],$assignment]);
    $db->run("INSERT INTO focus_daily (tenant_id,day,user_id,user_assignment_id,focus_seconds,focus_score,calculation_version,calculated_at,data_through) VALUES (?,'2026-09-10',?,?,80,66.5,'admin-test',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$tenant,$aUser['id'],$assignment]);
    $report=expect(adminRequest($admin,'GET','/reports/productivity?from=2026-09-01&to=2026-10-01'),200,'aggregate report');
    check($report['series'][0]['active_seconds']===120 && $report['series'][0]['focus_score']===66.5 && $report['series'][0]['productivity_percent']===75 && $report['coverage_percent']===50,'report aggregates summary and focus without multiplying episode rows');
    foreach (['v2','v3'] as $version) {
        $db->run("INSERT INTO episode_daily (tenant_id,day,user_id,user_assignment_id,device_id,process_name,category,active_seconds,idle_seconds,episode_count,calculation_version,calculated_at,data_through) VALUES (?,'2026-09-11',?,?,?,?, 'productive',100,0,1,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6))",[$tenant,$aUser['id'],$assignment,Util::bin($device),'editor-'.$version,$version]);
    }
    $lagging=expect(adminRequest($admin,'GET','/reports/productivity?from=2026-09-01&to=2026-10-01'),200,'lagging daily rollups');
    check(count($lagging['series'])===2 && $lagging['coverage_percent']===50,'episode-only day contributes activity but not coverage numerator');
    $mixed=expect(adminRequest($admin,'GET','/reports/productivity?from=2026-09-11&to=2026-09-12'),200,'same-day mixed versions');
    check($mixed['calculation_version']==='mixed' && $mixed['coverage_percent']===0,'same-day versions are mixed and absent expected time has zero coverage');
    $foreignReport=expect(adminRequest($other,'GET','/reports/productivity?from=2026-09-01&to=2026-10-01'),200,'foreign aggregate report'); check($foreignReport['series']===[],'aggregates isolated by tenant');
    $db->run('UPDATE users SET area_id=? WHERE tenant_id=? AND id=?',[$outsideArea,$tenant,$aUser['id']]);
    $historical=expect(adminRequest($scoped,'GET','/reports/productivity?from=2026-09-01&to=2026-10-01'),200,'historical area scope'); check($historical['series'][0]['active_seconds']===120,'historical assignment controls report scope');
    $db->run('UPDATE users SET area_id=? WHERE tenant_id=? AND id=?',[$aUser['area_id'],$tenant,$aUser['id']]);
    expect(adminRequest($admin,'GET','/reports/productivity?from=2026-09-01&to=2026-10-01&user_id='.Util::id($bUser['id'])),404,'report foreign filter');
    expect(adminRequest($admin,'GET','/audit?from=2026-01-01&to=2027-01-01&limit=2'),200,'audit paged');
    expect(adminRequest($admin,'GET','/releases'),200,'visible releases');
    $unsigned=['id'=>Util::uuid(),'version'=>'4.5.0','channel'=>'admin-test','sequence'=>1,'min_agent_version'=>'4.0.0','architecture'=>'x64','artifact_url'=>'https://example.test/package.zip','size_bytes'=>128,'sha256'=>str_repeat('a',64),'key_id'=>'admin-test','manifest_jws'=>'','published_at'=>Util::now()];
    expect(adminRequest($platform,'POST','/releases',$unsigned),422,'unsigned release rejected');
    [$signer,$jwk]=keypair(); $file=Config::get('RELEASE_KEYS_FILE'); $keys=json_decode(file_get_contents($file),true); $keys['admin-test']=$jwk; file_put_contents($file,Util::json($keys));
    $payload=$unsigned; unset($payload['manifest_jws']); $input=Util::b64(Util::json(['alg'=>'ES256','kid'=>'admin-test'])).'.'.Util::b64(Util::json($payload)); openssl_sign($input,$signature,$signer,OPENSSL_ALGO_SHA256); $release=$unsigned; $release['manifest_jws']=$input.'.'.Util::b64(rawSignature($signature));
    $upperPayload=$payload; $upperPayload['id']=strtoupper($payload['id']);
    $upperInput=Util::b64(Util::json(['alg'=>'ES256','kid'=>'admin-test'])).'.'.Util::b64(Util::json($upperPayload)); openssl_sign($upperInput,$upperSignature,$signer,OPENSSL_ALGO_SHA256);
    expect(adminRequest($platform,'POST','/releases',array_replace($release,['id'=>$upperPayload['id'],'manifest_jws'=>$upperInput.'.'.Util::b64(rawSignature($upperSignature))])),422,'uppercase-signed UUID cannot create undeliverable release');
    $registered=expect(adminRequest($platform,'POST','/releases',array_replace($release,['id'=>strtoupper($release['id'])])),201,'uppercase body UUID normalized before signed manifest verification');
    check($registered['id']===$release['id'],'release response has canonical UUID');
    $deployment=['release_id'=>$release['id'],'ring'=>'stable','percentage'=>50,'enabled'=>true];
    expect(adminRequest($platformTenant,'POST','/release-deployments',$deployment,['idempotency-key'=>Util::uuid()]),201,'platform makes release visible');
    expect(adminRequest($admin,'POST','/release-deployments',array_replace($deployment,['percentage'=>100]),['idempotency-key'=>Util::uuid()]),201,'tenant rollout');
    expect(adminRequest($other,'POST','/release-deployments',$deployment,['idempotency-key'=>Util::uuid()]),404,'foreign deployment visibility');
    $releaseAgentKey=keypair(); $releaseAgent=enroll($db,$aUser,$releaseAgentKey);
    policy($db,$releaseAgent);
    $db->run('UPDATE devices SET specs=? WHERE tenant_id=? AND id=?',['{"architecture":"x64"}',$tenant,Util::bin($releaseAgent['device_id'])]);
    $delivered=expect(request('GET','/client/releases/'.$release['id'],null,$releaseAgentKey,$releaseAgent['access_token']),200,'normalized release delivered');
    check($delivered['id']===$release['id'],'delivery verifies canonical signed release');
    $synced=expect(request('POST','/client/sync',['protocol_version'=>1,'sequence'=>1,'policy_version'=>null,'release_id'=>null],$releaseAgentKey,$releaseAgent['access_token'],Util::uuid()),200,'normalized release delivered by sync');
    check($synced['release']['id']===$release['id'],'sync verifies canonical signed release');
    // Ficha del equipo: ultimo SecurityReport por observed_at (no por orden de llegada), alcance por empresa.
    $secPath='/devices/'.$releaseAgent['device_id'].'/security';
    expect(adminRequest($admin,'GET',$secPath),404,'no security report yet');
    $ago=gmdate('Y-m-d\TH:i:s\Z',time()-60);
    $newer=['event_id'=>Util::uuid(),'observed_at'=>Util::now(),'report_hash'=>hash('sha256','newer'),'controls'=>[['control_id'=>'web','state'=>'applied','observed_at'=>Util::now()],['control_id'=>'usb','state'=>'failed','observed_at'=>Util::now(),'error_code'=>'dry_run']]];
    $older=['event_id'=>Util::uuid(),'observed_at'=>$ago,'report_hash'=>hash('sha256','older'),'controls'=>[['control_id'=>'web','state'=>'unknown','observed_at'=>$ago]]];
    expect(request('POST','/client/security/report',$newer,$releaseAgentKey,$releaseAgent['access_token'],Util::uuid()),200,'newer security report');
    expect(request('POST','/client/security/report',$older,$releaseAgentKey,$releaseAgent['access_token'],Util::uuid()),200,'late older security report');
    $sec=expect(adminRequest($admin,'GET',$secPath),200,'device security report');
    check($sec['event_id']===$newer['event_id'] && $sec['device_id']===$releaseAgent['device_id'] && count($sec['controls'])===2 && $sec['controls'][1]['state']==='failed' && $sec['controls'][1]['error_code']==='dry_run','latest security report by observed_at');
    expect(adminRequest($other,'GET',$secPath),404,'foreign device security hidden');
    // Recompilacion acotada: un cambio de persona recompila SUS equipos (no el tenant entero) y, si habia
    // trabajo pendiente anterior, hace la compilacion total para no perderlo. Se usa $member (no vinculada al
    // admin de prueba: editar esa persona invalidaria la sesion del admin).
    $memberRow=$db->one('SELECT * FROM users WHERE tenant_id=? AND id=?',[$tenant,Util::bin($member['id'])]);
    $memberKey=keypair(); $memberAgent=enroll($db,$memberRow,$memberKey); policy($db,$memberAgent);
    $memberDevice=Util::bin($memberAgent['device_id']);
    $policyVersion=fn()=>(int)$db->one('SELECT MAX(policy_version) v FROM effective_policies WHERE tenant_id=? AND device_id=?',[$tenant,$memberDevice])['v'];
    $originalSchedule=Util::id($memberRow['schedule_id']);
    $otherSchedule=$originalSchedule===$schedule['id'] ? Util::id($aUser['schedule_id']) : $schedule['id'];
    check($otherSchedule!==$originalSchedule,'fixture has two schedules');
    $before=$policyVersion();
    $member=expect(adminRequest($admin,'PATCH','/users/'.$member['id'],['schedule_id'=>$otherSchedule],['if-match'=>'"'.$member['version'].'"']),200,'scoped user schedule change');
    check($policyVersion()>$before,'scoped change recompiles the user device');
    check($db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant])===null,'scoped change clears its compilation work');
    $db->run('INSERT INTO admin_policy_recompiles (tenant_id) VALUES (?)',[$tenant]);
    $member=expect(adminRequest($admin,'PATCH','/users/'.$member['id'],['schedule_id'=>$originalSchedule],['if-match'=>'"'.$member['version'].'"']),200,'scoped change with prior pending work');
    check($db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant])===null,'prior pending work compiled in full, not dropped');
    $system=$db->one("SELECT id FROM principals WHERE tenant_id=? AND kind='system'",[$bUser['tenant_id']]);
    $db->run('DELETE FROM principals WHERE tenant_id=? AND id=?',[$bUser['tenant_id'],$system['id']]);
    $foreignSchedule=expect(adminRequest($other,'GET','/schedules/'.Util::id($bUser['schedule_id'])),200,'foreign tenant own schedule');
    expect(adminRequest($other,'PUT','/schedules/'.$foreignSchedule['id'],$scheduleBody,['if-match'=>'"'.$foreignSchedule['version'].'"']),503,'post-commit compilation failure');
    check($db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$bUser['tenant_id']])!==null,'compilation work survives failure');
    $db->run("INSERT INTO principals (tenant_id,id,kind) VALUES (?,?,'system')",[$bUser['tenant_id'],$system['id']]);
    expect(adminRequest($other,'GET','/schedules/'.$foreignSchedule['id']),200,'next request retries committed compilation');
    check($db->one('SELECT tenant_id FROM admin_policy_recompiles WHERE tenant_id=?',[$bUser['tenant_id']])===null,'successful retry removes compilation work');
    $ticket=expect(adminRequest($admin,'POST','/enrollments',['user_id'=>$member['id'],'device_id'=>null,'public_key_thumbprint'=>Util::b64(thumb(keypair()[1])),'reason'=>'Test enrollment']),201,'admin enrollment'); check(isset($ticket['ticket']),'enrollment token schema');
    $assignmentKey=keypair(); $assignmentDevice=enroll($db,$aUser,$assignmentKey); $assignmentId=$assignmentDevice['device_id'];
    expect(adminRequest($admin,'POST','/devices/'.$assignmentId.'/assignment',['user_id'=>Util::id($bUser['id']),'reason'=>'Foreign'],['idempotency-key'=>Util::uuid(),'if-match'=>'"1"']),404,'foreign assignment rejected');
    expect(adminRequest($admin,'POST','/devices/'.$assignmentId.'/assignment',['user_id'=>$member['id'],'reason'=>'Assignment smoke'],['idempotency-key'=>Util::uuid(),'if-match'=>'"1"']),200,'same tenant assignment');
    expect(request('GET','/client/policy',null,$assignmentKey,$assignmentDevice['access_token']),401,'assignment revokes former device credentials');
    expect(adminRequest($admin,'PUT','/users/'.$member['id'].'/subscription',['tier_id'=>$tier['id'],'starts_at'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600),'ends_at'=>null],['if-match'=>'"0"','x-csrf-token'=>'']),403,'PUT CSRF required');
    expect(adminRequest($admin,'PATCH','/users/'.$member['id'],['display_name'=>'Forbidden'],['if-match'=>'"'.$member['version'].'"','x-csrf-token'=>'']),403,'PATCH CSRF required');
    expect(adminRequest($admin,'PATCH','/users/'.$member['id'],['status'=>'inactive'],['if-match'=>'"'.$member['version'].'"']),200,'guided deactivation');
    expect(adminRequest($admin,'PATCH','/devices/'.$device,['status'=>'revoked'],['if-match'=>'"'.$d['version'].'"']),200,'revoke device');
    expect(request('GET','/client/policy',null,$key,$token['access_token']),401,'revocation cuts client access');
    $t=expect(adminRequest($platformTenant,'GET','/tenants/'.$tid),200,'latest gate version');
    expect(adminRequest($platformTenant,'PATCH','/tenants/'.$tid.'/rbac-gate',['enabled'=>false,'reason'=>'Revoke'],['if-match'=>'"'.$t['version'].'"']),200,'disable gate');
    expect(adminRequest($delegate,'POST','/roles',array_replace($roleBody,['name'=>'After revoked gate'])),403,'gate revocation immediate');
    $db->run('UPDATE admin_sessions SET idle_expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 SECOND,last_seen_at=UTC_TIMESTAMP(6)-INTERVAL 2 SECOND WHERE tenant_id=? AND admin_id=?',[$delegate['tenant'],$delegate['id']]);
    expect(adminRequest($delegate,'GET','/roles'),401,'idle session expiration');
    $db->run('UPDATE admin_sessions SET created_at=UTC_TIMESTAMP(6)-INTERVAL 8 HOUR-INTERVAL 1 MINUTE,expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE,last_seen_at=UTC_TIMESTAMP(6)-INTERVAL 2 MINUTE,idle_expires_at=UTC_TIMESTAMP(6)-INTERVAL 1 MINUTE WHERE tenant_id=? AND admin_id=?',[$scoped['tenant'],$scoped['id']]);
    expect(adminRequest($scoped,'GET','/users'),401,'absolute session expiration');
    expect(adminRequest($scoped,'GET','/auth/csrf'),200,'expired session can obtain prelogin CSRF');
    expect(adminRequest($admin,'POST','/auth/logout',null,['x-csrf-token'=>'']),403,'logout CSRF');
    expect(adminRequest($admin,'POST','/auth/logout'),204,'logout'); expect(adminRequest($admin,'GET','/users'),401,'logout session revoked');
    echo 'ADMIN PASS: '.($GLOBALS['checks']-$before)." assertions\n";
}
