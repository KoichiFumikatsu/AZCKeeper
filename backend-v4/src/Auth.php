<?php
declare(strict_types=1);
namespace Keeper;

final class Auth
{
    public function __construct(private Database $db, private Signature $signature, private RateLimiter $limiter) {}
    public function admin(): AdminAuth { return new AdminAuth($this->db, $this->limiter); }
    public function external(): ExternalAuth { return new ExternalAuth($this->db, $this->limiter); }

    private function identity(object $body): array
    {
        $ticket = isset($body->enrollment_ticket);
        $sql = $ticket
            ? 'SELECT e.* FROM enrollments e JOIN tenants t ON t.tenant_id=e.tenant_id JOIN users u ON u.tenant_id=e.tenant_id AND u.id=e.user_id WHERE e.ticket_hash=? AND e.status=\'pending\' AND e.expires_at>UTC_TIMESTAMP(6) AND t.status=\'active\' AND u.status=\'active\' LIMIT 2 FOR UPDATE'
            : 'SELECT d.* FROM devices d JOIN tenants t ON t.tenant_id=d.tenant_id JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id WHERE d.id=? AND d.status=\'active\' AND t.status=\'active\' AND u.status=\'active\' LIMIT 2 FOR UPDATE';
        $rows = $this->db->run($sql, [$ticket ? hash('sha256', $body->enrollment_ticket, true) : Util::bin($body->device_id)])->fetchAll();
        $identity = count($rows) === 1 ? $rows[0] : null;
        $this->limiter->bootstrap($identity);
        if (!$identity) { throw new ApiError(401, 'invalid_credentials'); }
        return $identity;
    }

    public function challenge(Request $r): array
    {
        return $this->db->transaction(function () use ($r): array {
            $i = $this->identity($r->body);
            $field = isset($r->body->enrollment_ticket) ? 'enrollment_id' : 'device_id';
            $count = $this->db->one("SELECT COUNT(*) n FROM device_challenges WHERE tenant_id=? AND $field=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP(6)", [$i['tenant_id'], $i['id']]);
            if ((int) $count['n'] >= Config::number('CHALLENGE_OUTSTANDING', 20)) { throw new ApiError(429, 'rate_limited', ['Retry-After' => '60']); }
            $nonce = Util::b64(random_bytes(32));
            $this->db->run("INSERT INTO device_challenges (tenant_id,id,$field,nonce_hash,created_at,expires_at) VALUES (?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 60 SECOND)", [$i['tenant_id'], Util::bin(Util::uuid()), $i['id'], hash('sha256', $nonce, true)]);
            return ['nonce' => $nonce, 'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)];
        });
    }

    public function login(Request $r): array
    {
        if ($r->header('authorization') !== '') { throw new ApiError(400, 'ambiguous_credentials'); }
        return $this->db->transaction(function () use ($r): array {
            $i = $this->identity($r->body);
            $ticket = isset($r->body->enrollment_ticket);
            $thumb = Signature::thumbprint($r->body->public_key);
            if ($ticket) {
                if (!hash_equals($i['public_key_thumbprint'], $thumb)) { throw new ApiError(401, 'invalid_signature'); }
            } else {
                $key = $this->db->one('SELECT * FROM device_keys WHERE tenant_id=? AND device_id=? AND thumbprint=? AND revoked_at IS NULL FOR UPDATE', [$i['tenant_id'], $i['id'], $thumb]);
                if (!$key) { throw new ApiError(401, 'invalid_signature'); }
            }
            $nonce = $this->signature->verify($r, $r->body->public_key);
            $field = $ticket ? 'enrollment_id' : 'device_id';
            $q = $this->db->run("UPDATE device_challenges SET consumed_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND $field=? AND nonce_hash=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP(6)", [$i['tenant_id'], $i['id'], hash('sha256', $nonce, true)]);
            if ($q->rowCount() !== 1) { throw new ApiError(401, 'replayed_proof'); }
            $tenant = $i['tenant_id'];
            if ($ticket) {
                $device = $i['device_id'] ?? Util::bin(Util::uuid());
                if ($i['device_id'] === null) {
                    $this->db->run('INSERT INTO devices (tenant_id,id,user_id,hostname,agent_version,os_edition,cpu,ram_bytes,capabilities) VALUES (?,?,?,?,?,\'unknown\',\'unknown\',0,\'[]\')', [$tenant, $device, $i['user_id'], $r->body->hostname, $r->body->agent_version]);
                    $this->db->run('INSERT INTO device_assignments (tenant_id,id,device_id,user_id,starts_at,reason) VALUES (?,?,?,?,UTC_TIMESTAMP(6),\'enrollment\')', [$tenant, Util::bin(Util::uuid()), $device, $i['user_id']]);
                    $this->db->run('INSERT INTO principals (tenant_id,id,kind,device_id) VALUES (?,?,\'device\',?)', [$tenant, Util::bin(Util::uuid()), $device]);
                } else {
                    $d = $this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=? FOR UPDATE', [$tenant, $device]);
                    if (!$d || $d['status'] !== 'active' || $d['user_id'] !== $i['user_id']) { throw new ApiError(409, 'assignment_conflict'); }
                    $this->db->run('UPDATE devices SET auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$tenant, $device]);
                    $this->db->run('UPDATE device_keys SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND revoked_at IS NULL', [$tenant, $device]);
                    $this->db->run('UPDATE device_sessions SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND device_id=? AND revoked_at IS NULL', [$tenant, $device]);
                }
                if ($this->db->one('SELECT id FROM device_keys WHERE tenant_id=? AND thumbprint=?', [$tenant, $thumb])) { throw new ApiError(409, 'invalid_transition'); }
                $keyId = Util::bin(Util::uuid());
                $this->db->run('INSERT INTO device_keys (tenant_id,id,device_id,thumbprint,jwk_x,jwk_y) VALUES (?,?,?,?,?,?)', [$tenant, $keyId, $device, $thumb, Util::unb64($r->body->public_key->x), Util::unb64($r->body->public_key->y)]);
                $this->db->run('UPDATE enrollments SET status=\'consumed\',consumed_at=UTC_TIMESTAMP(6),device_id=? WHERE tenant_id=? AND id=?', [$device, $tenant, $i['id']]);
                $this->db->run('INSERT INTO device_sync_state (tenant_id,device_id,enrollment_id,updated_at) VALUES (?,?,?,UTC_TIMESTAMP(6))', [$tenant, $device, $i['id']]);
            } else { $device = $i['id']; $keyId = $key['id']; }
            $this->db->run('UPDATE devices SET hostname=?,agent_version=?,last_seen_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?', [$r->body->hostname, $r->body->agent_version, $tenant, $device]);
            $c = $this->deviceContext($tenant, $device, $keyId);
            $token = $this->issue($c);
            $action = $ticket ? ($i['device_id'] === null ? 'device.enrolled' : 'device.key_recovered') : 'device.login';
            (new Audit($this->db))->record($c, $r, $action, 'device', $device, ['session']);
            return $token;
        });
    }

    private function deviceContext(string $tenant, string $device, string $key): array
    {
        $c = $this->db->one('SELECT d.tenant_id,d.id device_id,d.user_id,d.auth_version device_auth_version,d.agent_version,d.release_ring,d.specs,t.auth_version tenant_auth_version,t.timezone,u.auth_version user_auth_version,a.id assignment_id,a.starts_at assignment_start,a.ends_at assignment_end,k.id key_id,k.jwk_x,k.jwk_y,k.thumbprint,p.id principal_id,e.id enrollment_id FROM devices d JOIN tenants t ON t.tenant_id=d.tenant_id JOIN users u ON u.tenant_id=d.tenant_id AND u.id=d.user_id JOIN device_assignments a ON a.tenant_id=d.tenant_id AND a.device_id=d.id AND a.user_id=d.user_id AND a.starts_at<=UTC_TIMESTAMP(6) AND (a.ends_at IS NULL OR a.ends_at>UTC_TIMESTAMP(6)) JOIN device_keys k ON k.tenant_id=d.tenant_id AND k.device_id=d.id AND k.id=? AND k.revoked_at IS NULL JOIN principals p ON p.tenant_id=d.tenant_id AND p.device_id=d.id AND p.kind=\'device\' JOIN enrollments e ON e.tenant_id=d.tenant_id AND e.device_id=d.id AND e.public_key_thumbprint=k.thumbprint AND e.status=\'consumed\' WHERE d.tenant_id=? AND d.id=? AND d.status=\'active\' AND t.status=\'active\' AND u.status=\'active\' FOR UPDATE', [$key, $tenant, $device]);
        if (!$c) { throw new ApiError(401, 'invalid_credentials'); }
        return $c;
    }

    public function authenticate(Request $r, bool $proof = true): array
    {
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43,512})$/D', $r->header('authorization'), $m)) { throw new ApiError(401, 'invalid_credentials'); }
        $rows = $this->db->run('SELECT *,expires_at<=UTC_TIMESTAMP(6) expired,TIMESTAMPDIFF(SECOND,UTC_TIMESTAMP(6),expires_at) remaining FROM device_sessions WHERE token_hash=? LIMIT 2 FOR UPDATE', [hash('sha256', $m[1], true)])->fetchAll();
        if (count($rows) !== 1 || $rows[0]['revoked_at'] !== null) { throw new ApiError(401, 'invalid_credentials'); }
        $s = $rows[0];
        if ($s['expired']) { throw new ApiError(401, 'token_expired'); }
        $c = $this->deviceContext($s['tenant_id'], $s['device_id'], $s['key_id']);
        foreach (['user_id', 'assignment_id', 'device_auth_version', 'user_auth_version', 'tenant_auth_version'] as $field) {
            if ($c[$field] !== $s[$field]) { throw new ApiError(401, 'invalid_credentials'); }
        }
        $c['session_remaining'] = (int) $s['remaining'];
        if ($proof) {
            $jwk = (object) ['kty' => 'EC', 'crv' => 'P-256', 'x' => Util::b64($c['jwk_x']), 'y' => Util::b64($c['jwk_y'])];
            $nonce = $this->signature->verify($r, $jwk);
            try {
                $this->db->run('INSERT INTO device_signature_nonces (tenant_id,key_id,nonce_hash,created_at,expires_at) VALUES (?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 120 SECOND)', [$c['tenant_id'], $c['key_id'], hash('sha256', $nonce, true)]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) { throw new ApiError(401, 'replayed_proof'); }
                throw $e;
            }
        }
        return $c;
    }

    public function issue(array $c): array
    {
        $token = Util::b64(random_bytes(32));
        $this->db->run('INSERT INTO device_sessions (tenant_id,id,device_id,user_id,assignment_id,key_id,token_hash,device_auth_version,user_auth_version,tenant_auth_version,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 HOUR)', [$c['tenant_id'], Util::bin(Util::uuid()), $c['device_id'], $c['user_id'], $c['assignment_id'], $c['key_id'], hash('sha256', $token, true), $c['device_auth_version'], $c['user_auth_version'], $c['tenant_auth_version']]);
        return ['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600, 'device_id' => Util::id($c['device_id']), 'tenant_id' => Util::id($c['tenant_id'])];
    }
}
