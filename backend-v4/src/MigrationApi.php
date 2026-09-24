<?php
declare(strict_types=1);
namespace Keeper;

final class MigrationApi
{
    private const PHASES=['instalado','enrolado','escrow_ok','degradado','pendiente_reinicio','completo'];
    public function __construct(private Database $db,private array $c,private AdminAccess $access,private Request $r) {}
    private function device(string $id): array
    {
        $d=$this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=? FOR UPDATE',[$this->c['tenant_id'],$id]);
        if (!$d) { throw new ApiError(404,'resource_not_found'); }
        $this->access->user($d['user_id']); return $d;
    }
    private function reauth(): void
    {
        if ($this->c['reauthenticated_at']===null || strtotime($this->c['reauthenticated_at'])<time()-300) { throw new ApiError(403,'reauth_required'); }
    }
    private function audit(string $action,string $device,array $fields=[]): void { (new Audit($this->db))->record($this->c,$this->r,$action,'device',$device,$fields); }
    public function dispatch(string $op,?string $id,?object $b): array
    {
        if ($op==='getEscrowKey') {
            [$key,$keyId]=$this->key(); $public=sodium_crypto_box_publickey($key); sodium_memzero($key);
            return AdminApi::response(['key_id'=>$keyId,'algorithm'=>'crypto_box_seal','public_key'=>base64_encode($public)]);
        }
        $id??=Util::bin($b->device_id); $d=$this->device($id);
        return match($op) {
            'issueMigrationAuthorization'=>$this->authorize($d,$b),
            'validateMigrationAuthorization'=>$this->validateToken($d,$b),
            'persistDeviceEscrow'=>$this->persist($id,$b),
            'verifyDeviceEscrow'=>$this->verify($id,$b),
            'recoverDeviceEscrow'=>$this->recover($id,$b),
            'getDeviceMigration'=>AdminApi::response($this->state($id)),
            'setDeviceMigration'=>$this->advance($id,$b),
            default=>throw new ApiError(404,'resource_not_found')
        };
    }
    private function authorize(array $d,object $b): array
    {
        $this->reauth();
        if ($d['status']!=='active') { throw new ApiError(409,'invalid_transition'); }
        $tenant=$this->c['tenant_id']; $id=Util::bin(Util::uuid()); $token=Util::b64(random_bytes(32));
        // A fresh authorization revokes any previous outstanding migration ticket for this device.
        $this->db->run("UPDATE enrollments e JOIN migration_authorizations m ON m.tenant_id=e.tenant_id AND m.enrollment_id=e.id SET e.status='revoked' WHERE m.tenant_id=? AND m.device_id=? AND e.status='pending'",[$tenant,$d['id']]);
        $this->db->run("INSERT INTO enrollments (tenant_id,id,user_id,device_id,public_key_thumbprint,ticket_hash,reason,created_at,expires_at) VALUES (?,?,?,?,?,?,'K3 migration',UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL ? SECOND)",[$tenant,$id,$d['user_id'],$d['id'],hex2bin($b->public_key_thumbprint),hash('sha256',$token,true),$b->expires_in]);
        $this->db->run('INSERT INTO migration_authorizations VALUES (?,?,?)',[$tenant,$id,$d['id']]);
        $this->audit('migration.authorization_issued',$d['id'],['enrollment_id']);
        $row=$this->db->one('SELECT expires_at FROM enrollments WHERE tenant_id=? AND id=?',[$tenant,$id]);
        return AdminApi::response(['token'=>$token,'device_id'=>Util::id($d['id']),'tenant_id'=>Util::id($tenant),'expires_at'=>Util::time($row['expires_at'])]);
    }
    private function validateToken(array $d,object $b): array
    {
        $row=$this->db->one("SELECT e.expires_at FROM migration_authorizations m JOIN enrollments e ON e.tenant_id=m.tenant_id AND e.id=m.enrollment_id WHERE m.tenant_id=? AND m.device_id=? AND e.user_id=? AND e.ticket_hash=? AND e.status='pending' AND e.expires_at>UTC_TIMESTAMP(6) LOCK IN SHARE MODE",[$this->c['tenant_id'],$d['id'],$d['user_id'],hash('sha256',$b->token,true)]);
        if (!$row) { throw new ApiError(409,'invalid_transition'); }
        return AdminApi::response(['valid'=>true,'device_id'=>Util::id($d['id']),'expires_at'=>Util::time($row['expires_at'])]);
    }
    private function key(?string $required=null): array
    {
        if (!extension_loaded('sodium')) { throw new ApiError(503,'temporarily_unavailable'); }
        $file=Config::get('ESCROW_KEY_FILE');
        if ($file==='' || !is_readable($file) || filesize($file)!==SODIUM_CRYPTO_BOX_KEYPAIRBYTES) { throw new ApiError(503,'temporarily_unavailable'); }
        $key=file_get_contents($file);
        $id=hash('sha256',sodium_crypto_box_publickey($key));
        if ($required!==null && !hash_equals($id,$required)) { sodium_memzero($key); throw new ApiError(503,'temporarily_unavailable'); }
        return [$key,$id];
    }
    private function open(array $row): array
    {
        [$key]=$this->key($row['key_id']);
        try { $plain=sodium_crypto_box_seal_open($row['envelope'],$key); }
        finally { sodium_memzero($key); }
        if ($plain===false) { throw new ApiError(409,'invalid_transition'); }
        try { $v=json_decode($plain,true,8,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new ApiError(409,'invalid_transition'); }
        finally { sodium_memzero($plain); }
        if (!is_array($v) || count($v)!==5 || ($v['tenant_id']??null)!==Util::id($row['tenant_id']) || ($v['device_id']??null)!==Util::id($row['device_id']) || ($v['account_sid']??null)!==$row['account_sid'] || ($v['revision']??null)!==(int)$row['revision'] || !is_string($v['password']??null) || !preg_match('/^[\x21-\x7e]{32}$/D',$v['password'])) { throw new ApiError(409,'invalid_transition'); }
        return $v;
    }
    private function currentEscrow(string $device): ?array
    {
        return $this->db->one('SELECT * FROM device_escrow WHERE tenant_id=? AND device_id=? ORDER BY revision DESC LIMIT 1 FOR UPDATE',[$this->c['tenant_id'],$device]);
    }
    private function durable(): void
    {
        if ((int)$this->db->one('SELECT @@innodb_flush_log_at_trx_commit durable')['durable']!==1) { throw new ApiError(503,'temporarily_unavailable'); }
    }
    private function persist(string $device,object $b): array
    {
        $tenant=$this->c['tenant_id'];
        if (Util::bin($b->tenant_id)!==$tenant || Util::bin($b->device_id)!==$device) { throw new ApiError(404,'resource_not_found'); }
        $this->durable();
        if (!extension_loaded('sodium')) { throw new ApiError(503,'temporarily_unavailable'); }
        $cipher=base64_decode($b->envelope,true);
        if ($cipher===false || strlen($cipher)>6144 || strlen($cipher)<=SODIUM_CRYPTO_BOX_SEALBYTES) { throw new ApiError(422,'validation_failed'); }
        $hash=hash('sha256',$cipher,true); $old=$this->currentEscrow($device);
        if ($old && (int)$old['revision']===$b->revision) {
            if (!hash_equals($old['envelope_hash'],$hash) || $old['account_sid']!==$b->account_sid || $old['key_id']!==$b->key_id) { throw new ApiError(409,'invalid_transition'); }
            return AdminApi::response($this->ack($old));
        }
        if ($b->revision!==(int)($old['revision']??0)+1 || ($old && $old['account_sid']!==$b->account_sid)) { throw new ApiError(409,'invalid_transition'); }
        $row=['tenant_id'=>$tenant,'device_id'=>$device,'account_sid'=>$b->account_sid,'revision'=>$b->revision,'key_id'=>$b->key_id,'envelope'=>$cipher];
        $plain=$this->open($row); sodium_memzero($plain['password']);
        $id=Util::bin(Util::uuid());
        $this->db->run('INSERT INTO device_escrow (tenant_id,device_id,revision,id,account_sid,key_id,envelope,envelope_hash) VALUES (?,?,?,?,?,?,?,?)',[$tenant,$device,$b->revision,$id,$b->account_sid,$b->key_id,$cipher,$hash]);
        $this->db->run("UPDATE device_migrations SET phase='excepcion',safe_phase='enrolado',escrow_revision=NULL,revision=revision+1,report_hash=?,updated_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND safe_phase IN ('escrow_ok','degradado','pendiente_reinicio','completo')",[hash('sha256','escrow-revision:'.$b->revision,true),$tenant,$device]);
        $this->audit('migration.escrow_persisted',$device,['revision','envelope_hash']);
        return AdminApi::response($this->ack($this->currentEscrow($device)));
    }
    private function ack(array $row): array
    {
        return ['persisted'=>true,'escrow_id'=>Util::id($row['id']),'revision'=>(int)$row['revision'],'sha256'=>bin2hex($row['envelope_hash']),'persisted_at'=>Util::time($row['persisted_at'])];
    }
    private function verify(string $device,object $b): array
    {
        $this->durable(); $row=$this->currentEscrow($device);
        if (!$row || (int)$row['revision']!==$b->revision || !hash_equals(bin2hex($row['envelope_hash']),$b->sha256) || !hash_equals($row['envelope_hash'],hash('sha256',$row['envelope'],true))) { throw new ApiError(409,'invalid_transition'); }
        $plain=$this->open($row); sodium_memzero($plain['password']);
        $this->db->run('UPDATE device_escrow SET verified_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND revision=?',[$this->c['tenant_id'],$device,$b->revision]);
        $this->audit('migration.escrow_verified',$device,['revision']);
        $row=$this->currentEscrow($device);
        return AdminApi::response(['recoverable'=>true,'revision'=>$b->revision,'sha256'=>$b->sha256,'verified_at'=>Util::time($row['verified_at'])]);
    }
    private function recover(string $device,object $b): array
    {
        $this->reauth(); $this->durable();
        $row=$this->db->one('SELECT * FROM device_escrow WHERE tenant_id=? AND device_id=? AND revision=? LOCK IN SHARE MODE',[$this->c['tenant_id'],$device,$b->revision]);
        if (!$row) { throw new ApiError(404,'resource_not_found'); }
        $plain=$this->open($row); $audit=Util::uuid();
        $this->db->run("INSERT INTO audit_log (tenant_id,id,actor_id,actor_type,action,resource_type,resource_id,request_id,outcome,changed_fields,reason) VALUES (?,?,?,'admin','migration.escrow_recovered','device_escrow',?,?,'allowed',?,?)",[$this->c['tenant_id'],Util::bin($audit),$this->c['principal_id'],$row['id'],Util::bin($this->r->requestId),Util::json(['revision']),'reason_sha256:'.hash('sha256',$b->reason)]);
        $response=AdminApi::response(['account_sid'=>$row['account_sid'],'revision'=>(int)$row['revision'],'password'=>$plain['password'],'audit_id'=>$audit],200,['Cache-Control'=>'no-store','Pragma'=>'no-cache']);
        sodium_memzero($plain['password']); return $response;
    }
    private function state(string $id): array
    {
        $r=$this->db->one('SELECT * FROM device_migrations WHERE tenant_id=? AND device_id=?',[$this->c['tenant_id'],$id]);
        return ['device_id'=>Util::id($id),'phase'=>$r['phase']??null,'revision'=>(int)($r['revision']??0),'escrow_revision'=>isset($r['escrow_revision'])?(int)$r['escrow_revision']:null,'updated_at'=>isset($r['updated_at'])?Util::time($r['updated_at']):null];
    }
    private function advance(string $device,object $b): array
    {
        $tenant=$this->c['tenant_id']; $hash=Util::hash($b);
        $old=$this->db->one('SELECT * FROM device_migrations WHERE tenant_id=? AND device_id=? FOR UPDATE',[$tenant,$device]);
        if ($old && (int)$old['revision']===$b->revision && hash_equals($old['report_hash'],$hash)) { return AdminApi::response($this->state($device)); }
        if ($b->revision!==(int)($old['revision']??0)+1) { throw new ApiError(409,'invalid_transition'); }
        $previous=$old['safe_phase']??''; $phase=$b->phase;
        $index=array_search($phase,self::PHASES,true); $before=array_search($previous,self::PHASES,true);
        if ($phase!=='excepcion' && !(($before===false && $index===0) || ($before!==false && $index===$before+1) || (($old['phase']??null)==='excepcion' && $phase===$previous))) { throw new ApiError(409,'invalid_transition'); }
        if ($index!==false && $index>=1 && !$this->db->one("SELECT e.id FROM migration_authorizations m JOIN enrollments e ON e.tenant_id=m.tenant_id AND e.id=m.enrollment_id JOIN device_keys k ON k.tenant_id=e.tenant_id AND k.device_id=e.device_id AND k.thumbprint=e.public_key_thumbprint AND k.revoked_at IS NULL WHERE m.tenant_id=? AND m.device_id=? AND e.status='consumed' LIMIT 1",[$tenant,$device])) { throw new ApiError(409,'invalid_transition'); }
        $escrow=$this->currentEscrow($device);
        if ($index!==false && $index>=2 && (!$escrow || $escrow['verified_at']===null)) { throw new ApiError(409,'invalid_transition'); }
        if ($index!==false && $index>=2) { $plain=$this->open($escrow); sodium_memzero($plain['password']); }
        if ($phase==='completo' && !($b->standard_user_verified??false)) { throw new ApiError(409,'invalid_transition'); }
        $this->db->run('INSERT INTO device_migrations (tenant_id,device_id,phase,safe_phase,revision,escrow_revision,report_hash) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE phase=VALUES(phase),safe_phase=VALUES(safe_phase),revision=VALUES(revision),escrow_revision=VALUES(escrow_revision),report_hash=VALUES(report_hash),updated_at=UTC_TIMESTAMP(6)',[$tenant,$device,$phase,$phase==='excepcion'?$previous:$phase,$b->revision,$escrow && $escrow['verified_at']!==null?$escrow['revision']:null,$hash]);
        $this->audit('migration.phase_reported',$device,['phase','revision']);
        return AdminApi::response($this->state($device));
    }
}
