<?php
declare(strict_types=1);
namespace Keeper;

final class ExternalAuth
{
    public const SCOPES = ['dashboard:read','team:read','members:read','devices:read','activity:read','activity-titles:read','productivity:read','presence:read','location:read','tiers:read','organization:read','notifications:read','expected-devices:write'];
    public function __construct(private Database $db, private RateLimiter $limiter) {}

    private function integration(array $credential): array
    {
        $c = $this->db->one("SELECT i.tenant_id,i.id integration_id,i.auth_version,t.auth_version tenant_auth_version,t.timezone,p.id principal_id FROM integrations i JOIN tenants t ON t.tenant_id=i.tenant_id JOIN principals p ON p.tenant_id=i.tenant_id AND p.integration_id=i.id AND p.kind='integration' WHERE i.tenant_id=? AND i.id=? AND i.active=TRUE AND i.created_at<=UTC_TIMESTAMP(6) AND i.expires_at>UTC_TIMESTAMP(6) AND t.status='active'", [$credential['tenant_id'], $credential['integration_id']]);
        if (!$c) { throw new ApiError(401, 'invalid_credentials', ['WWW-Authenticate'=>'Bearer realm="keeper-external"']); }
        $c['actor_type'] = 'integration';
        return $c;
    }
    private function grants(array $c): array
    {
        $rows = $this->db->run('SELECT g.scope_code FROM integration_scopes g JOIN scopes s ON s.code=g.scope_code WHERE g.tenant_id=? AND g.integration_id=? ORDER BY g.scope_code', [$c['tenant_id'], $c['integration_id']])->fetchAll();
        return array_values(array_intersect(array_column($rows, 'scope_code'), self::SCOPES));
    }
    private function unknown(string $identity = 'unknown'): void { $this->limiter->take([['bootstrap','external:abuse:'.hash('sha256',$identity),5,5]]); }

    private function secretMatches(?array $credential, string $secret): bool
    {
        $row=$credential??['revoked_at'=>'1970-01-01','created_at'=>'1970-01-01','expires_at'=>'1970-01-01'];
        $valid=$this->valid($row);
        $dummy=hash('sha256',str_repeat("\0",32),true);
        $matches=hash_equals($valid?$row['secret_hash']:$dummy,hash('sha256',$secret,true));
        return (bool) ($valid & $matches);
    }

    public function issue(Request $r): array
    {
        if ($r->header('x-api-key') !== '') { throw new OAuthError(400, 'invalid_request'); }
        if (!preg_match('/^Basic ([A-Za-z0-9+\/=]+)$/iD', $r->header('authorization'), $m) || strlen($m[1])>2048 || ($basic=base64_decode($m[1],true))===false || !str_contains($basic, ':')) {
            $this->unknown(); throw new OAuthError(401, 'invalid_client');
        }
        [$client, $secret] = array_map('urldecode', explode(':', $basic, 2));
        $credential=$this->db->one('SELECT * FROM oauth_clients WHERE client_id=?',[$client]);
        $authenticated=$this->secretMatches($credential,$secret);
        $c=null;
        if ($authenticated) { try { $c=$this->integration($credential); } catch (ApiError) {} }
        if ($c===null) { $this->unknown('oauth:'.$client); throw new OAuthError(401,'invalid_client'); }
        $c['client_id']=$credential['id'];
        $this->limiter->external($c,true);
        $result=$this->db->transaction(function () use ($r, $client, $secret): ?array {
            // Recheck rotation/revocation under lock after limiter I/O has finished.
            $credential=$this->db->one('SELECT * FROM oauth_clients WHERE client_id=? FOR UPDATE',[$client]);
            if (!$this->secretMatches($credential,$secret)) { return null; }
            try { $c=$this->integration($credential); } catch (ApiError) { return null; }
            $c['client_id']=$credential['id'];
            $grants=$this->grants($c);
            $scope=$r->body->scope ?? null;
            if ($scope!==null && !preg_match('/^[\x21\x23-\x5B\x5D-\x7E]+(?: [\x21\x23-\x5B\x5D-\x7E]+)*$/D',$scope)) { throw new OAuthError(400,'invalid_scope'); }
            $requested=$scope===null?$grants:array_values(array_unique(explode(' ',$scope)));
            if (array_diff($requested,$grants)) { throw new OAuthError(400,'invalid_scope'); }
            sort($requested);
            $token=Util::b64(random_bytes(32)); $id=Util::bin(Util::uuid());
            $this->db->run('INSERT INTO oauth_access_tokens (tenant_id,id,client_id,integration_id,token_hash,auth_version,created_at,expires_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 15 MINUTE)',[$c['tenant_id'],$id,$credential['id'],$c['integration_id'],hash('sha256',$token,true),$c['auth_version']]);
            foreach ($requested as $scope) { $this->db->run('INSERT INTO oauth_token_scopes (tenant_id,token_id,integration_id,scope_code) VALUES (?,?,?,?)',[$c['tenant_id'],$id,$c['integration_id'],$scope]); }
            (new Audit($this->db))->record($c,$r,'oauth.token_issued','integration',$c['integration_id'],['scopes']);
            return ['access_token'=>$token,'token_type'=>'Bearer','expires_in'=>900,'scope'=>implode(' ',$requested)];
        });
        if ($result===null) { $this->unknown('oauth:'.$client); throw new OAuthError(401,'invalid_client'); }
        return $result;
    }
    private function valid(array $row): bool
    {
        $now=Util::sqlTime((new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP'));
        $created=Util::sqlTime(Util::time($row['created_at']));
        $expires=Util::sqlTime(Util::time($row['expires_at']));
        return (bool) (($row['revoked_at']===null) & ($created<=$now) & ($expires>$now));
    }
    public function context(Request $r): array
    {
        $key=$r->header('x-api-key'); $authorization=$r->header('authorization');
        if ($key!=='' && $authorization!=='') { throw new ApiError(400,'ambiguous_credentials'); }
        $credential=null; $token=null; $c=null; $abuse='unknown';
        if ($key!=='' && preg_match('/^([A-Za-z0-9_-]{1,160})\.([A-Za-z0-9_-]{43})$/D',$key,$m)) {
            $abuse='api:'.$m[1];
            $row=$this->db->one('SELECT * FROM api_keys WHERE public_prefix=?',[$m[1]]);
            if ($this->secretMatches($row,$m[2])) {
                $credential=$row;
                try { $c=$this->integration($credential); } catch (ApiError) {}
            }
        } elseif ($key==='' && preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/iD',$authorization,$m)) {
            $rows=$this->db->run('SELECT * FROM oauth_access_tokens WHERE token_hash=? LIMIT 2',[hash('sha256',$m[1],true)])->fetchAll();
            if (count($rows)===1) {
                $token=$rows[0];
                try { $c=$this->integration($token); } catch (ApiError) {}
                if ($c!==null && $this->valid($token) && $token['audience']==='keeper-external-v1' && $token['auth_version']===$c['auth_version']) {
                    $credential=$this->db->one('SELECT * FROM oauth_clients WHERE tenant_id=? AND id=? AND integration_id=?',[$c['tenant_id'],$token['client_id'],$c['integration_id']]);
                }
            }
        }
        if ($c===null || !$credential || !$this->valid($credential)) { $this->unknown($abuse); throw new ApiError(401,'invalid_credentials',['WWW-Authenticate'=>'Bearer realm="keeper-external"']); }
        $this->limiter->external($c);
        $c['scopes']=$this->grants($c);
        if ($token) {
            $issued=array_column($this->db->run('SELECT scope_code FROM oauth_token_scopes WHERE tenant_id=? AND token_id=? AND integration_id=?',[$c['tenant_id'],$token['id'],$c['integration_id']])->fetchAll(),'scope_code');
            $c['scopes']=array_values(array_intersect($c['scopes'],$issued));
        }
        $required=$r->operation['x-required-scopes']??[];
        foreach ($r->operation['x-conditional-scopes']??[] as $flag=>$scopes) { if (($_GET[$flag]??'false')==='true') { $required=array_merge($required,$scopes); } }
        if (array_diff($required,$c['scopes'])) { throw new ApiError(403,'insufficient_scope'); }
        return $c;
    }
}
