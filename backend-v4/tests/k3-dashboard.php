<?php
declare(strict_types=1);
use Keeper\{Database,Util};

function k3DashboardTests(Database $db): void
{
    $before=$GLOBALS['checks']; $u=secondTenant($db); $tenant=$u['tenant_id'];
    $child=adminMember($db,$u); $outside=adminMember($db,$u);
    $db->run('UPDATE users SET reports_to_user_id=? WHERE tenant_id=? AND id=?',[$u['id'],$tenant,$child['id']]);
    $token=enroll($db,$u,keypair());
    $db->run('UPDATE devices SET last_seen_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?',[$tenant,Util::bin($token['device_id'])]);
    $day=(new DateTimeImmutable('now',new DateTimeZone('America/Bogota')))->format('Y-m-d');
    $to=(new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d'); $query="?from=$day&to=$to";
    $a=adminFixture($db,$u,['reportes.ver','cumplimiento.ver','cumplimiento.editar','cobertura.ver'],false,'self');
    $alerts=[];
    foreach ([$u,$child,$outside] as $person) {
        $alert=Util::bin(Util::uuid()); $alerts[$person['id']]=$alert;
        $db->run("INSERT INTO dual_job_alerts (tenant_id,id,user_id,day,alert_type,severity,source) VALUES (?,?,?,?,'foreign_app','high','k3')",[$tenant,$alert,$person['id'],$day]);
    }
    foreach (['self'=>1,'team'=>2,'all'=>3] as $scope=>$count) {
        Keeper\AccessGrants::setForUser($db,$tenant,$u['id'],$a['role'],new Keeper\AccessScope($scope),'manual');
        $r=expect(adminRequest($a,'GET','/dashboard'.$query),200,'dashboard '.$scope);
        check($r['total_members']===$count && count($r['roster'])===$count,'dashboard scoped roster '.$scope);
        check($r['online_members']===1 && $r['away_members']===0 && $r['offline_members']===$count-1,'dashboard connection counts '.$scope);
        check($r['active_seconds']===0 && $r['productivity_percent']===null,'dashboard empty activity is not invented');
        expect(adminRequest($a,'GET','/users/'.Util::id($u['id']).'/activity'.$query),200,'activity own in '.$scope);
        $coverage=expect(adminRequest($a,'GET','/devices/install-coverage?site_id='.Util::id($u['site_id'])),200,'coverage scoped site '.$scope);
        check(count($coverage['data'])===$count,'coverage applies scope and site');
        $alertPath='/reports/dual-job-alerts/'.Util::id($alerts[$u['id']]);
        expect(adminRequest($a,'PATCH',$alertPath,['reviewed'=>true]),204,'review own alert '.$scope);
        if ($scope==='self') { expect(adminRequest($a,'PATCH','/reports/dual-job-alerts/'.Util::id($alerts[$child['id']]),['reviewed'=>true]),404,'review outside own scope'); }
        else { expect(adminRequest($a,'PATCH','/reports/dual-job-alerts/'.Util::id($alerts[$child['id']]),['reviewed'=>true]),204,'review team member '.$scope); }
        if ($scope!=='all') { expect(adminRequest($a,'GET','/dashboard'.$query.'&user_id='.Util::id($outside['id'])),404,'dashboard out of scope filter'); }
        if ($scope!=='all') { expect(adminRequest($a,'PATCH','/reports/dual-job-alerts/'.Util::id($alerts[$outside['id']]),['reviewed'=>true]),404,'review outside scope'); }
    }
    expect(adminRequest($a,'GET',"/dashboard?from=$to&to=$day"),422,'dashboard reversed range');
    expect(adminRequest($a,'GET','/dashboard?from=2026-01-01&to=2026-03-01'),422,'dashboard range capped');
    $other=secondTenant($db);
    expect(adminRequest($a,'GET','/dashboard'.$query.'&user_id='.Util::id($other['id'])),404,'dashboard tenant boundary');
    $reader=adminFixture($db,$outside,['usuarios.ver']);
    expect(adminRequest($reader,'GET','/dashboard'.$query),403,'dashboard requires reports permission');
    expect(adminRequest($reader,'PATCH',$alertPath,['reviewed'=>true]),403,'review permission required');
    expect(adminRequest($a,'PATCH',$alertPath,['reviewed'=>false]),422,'review cannot silently reopen');
    $reviewed=expect(adminRequest($a,'GET','/reports/dual-job-alerts'.$query.'&reviewed=1&severity=high'),200,'reviewed filter');
    check(count($reviewed['data'])===2,'review filter persisted across scopes');
    check((int)$db->one("SELECT COUNT(*) n FROM audit_log WHERE tenant_id=? AND action='reviewDualJobAlert'",[$tenant])['n']===5,'reviews audited');
    expect(adminRequest($a,'GET','/devices/install-coverage?site_id='.Util::id($other['site_id'])),404,'coverage site tenant boundary');
    expect(adminRequest($a,'GET','/dashboard'),200,'dashboard defaults to today');
    $db->run('UPDATE devices SET last_seen_at=UTC_TIMESTAMP(6)-INTERVAL 10 MINUTE WHERE tenant_id=? AND id=?',[$tenant,Util::bin($token['device_id'])]);
    $r=expect(adminRequest($a,'GET','/dashboard'.$query),200,'dashboard away'); check($r['away_members']===1,'dashboard away threshold');
    echo 'PASS K3 dashboard ('.($GLOBALS['checks']-$before)." assertions)\n";
}
