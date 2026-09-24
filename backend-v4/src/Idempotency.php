<?php
declare(strict_types=1);
namespace Keeper;

final class Idempotency
{
    public function __construct(private Database $db) {}
    private function scope(array $c, Request $r): array { return [$c['tenant_id'], $c['principal_id'], $r->method, hash('sha256', $r->path, true), Util::bin($r->header('idempotency-key'))]; }
    public function execute(array $c, Request $r, callable $handler): array
    {
        $args = $this->scope($c, $r);
        $where = 'tenant_id=? AND principal_id=? AND method=? AND route_hash=? AND idempotency_key=?';
        $row = $this->db->one('SELECT *,expires_at<=UTC_TIMESTAMP(6) expired FROM idempotency_keys WHERE ' . $where . ' FOR UPDATE', $args);
        $hash = hash('sha256', $r->raw, true);
        if ($row && $row['expired']) { $this->db->run('DELETE FROM idempotency_keys WHERE ' . $where, $args); $row = null; }
        if ($row) {
            if (!hash_equals($row['request_hash'], $hash)) { throw new ApiError(409, 'idempotency_conflict'); }
            if ($row['state'] !== 'completed' || $row['response_key_id'] !== Config::get('RESPONSE_KEY_ID', 'local-1')) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '5']); }
            $blob = $row['response_ciphertext'];
            $plain = openssl_decrypt(substr($blob, 28), 'aes-256-gcm', Config::key(), OPENSSL_RAW_DATA, substr($blob, 0, 12), substr($blob, 12, 16), implode('', $args));
            if ($plain === false) { throw new \RuntimeException('Invalid idempotency ciphertext'); }
            $saved = json_decode($plain, false, 64, JSON_THROW_ON_ERROR);
            return ['status' => $saved->status, 'json' => Util::json($saved->body), 'headers' => (array) $saved->headers];
        }
        $this->db->run('INSERT INTO idempotency_keys (tenant_id,principal_id,method,route_hash,idempotency_key,route,request_hash,created_at,expires_at) VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 24 HOUR)', [...$args, $r->path, $hash]);
        $response = $handler();
        $iv = random_bytes(12); $tag = '';
        $plain = '{"status":' . $response['status'] . ',"body":' . $response['json'] . ',"headers":' . Util::json($response['headers']) . '}';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', Config::key(), OPENSSL_RAW_DATA, $iv, $tag, implode('', $args), 16);
        if ($cipher === false) { throw new \RuntimeException('Response encryption failed'); }
        $this->db->run('UPDATE idempotency_keys SET state=\'completed\',response_status=?,response_ciphertext=?,response_key_id=? WHERE ' . $where, [$response['status'], $iv . $tag . $cipher, Config::get('RESPONSE_KEY_ID', 'local-1'), ...$args]);
        return $response;
    }
}
