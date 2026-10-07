<?php
declare(strict_types=1);
namespace Keeper;

final class UnlockPin
{
    public function __construct(private Database $db, private array $c, private Request $r) {}

    private function key(): string { return hash_hkdf('sha256', Config::key(), 32, 'keeper:unlock-pin:v1'); }
    private function aad(): string { return 'keeper:unlock-pin:v1:' . $this->c['tenant_id']; }

    public function clientSettings(): array
    {
        $row = $this->db->one('SELECT salt,verifier,revision FROM tenant_unlock_pin WHERE tenant_id=?', [$this->c['tenant_id']]);
        return $row === null ? ['revision' => 0] : ['revision' => (int) $row['revision'],
            'salt' => base64_encode($row['salt']), 'hash' => base64_encode($row['verifier'])];
    }

    public function admin(AdminAccess $access): array
    {
        $access->requireFull();
        $tenant = $this->c['tenant_id'];
        if (Util::bin($this->r->resourceId) !== $tenant) { throw new ApiError(404, 'resource_not_found'); }
        $op = $this->r->operation['operationId'];
        if ($op !== 'getTenantUnlockPin' && ($this->c['reauthenticated_at'] === null
            || strtotime($this->c['reauthenticated_at'] . ' UTC') < time() - 300)) { throw new ApiError(403, 'reauth_required'); }
        if ($op === 'setTenantUnlockPin') {
            $pin = $this->r->body->pin;
            if (!preg_match('/^[0-9]{6,32}$/D', $pin)) { throw new ApiError(422, 'validation_failed'); }
            $iv = random_bytes(12);
            $cipher = openssl_encrypt($pin, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $this->aad(), 16);
            if ($cipher === false) { throw new \RuntimeException('Unlock PIN encryption failed'); }
            $salt = random_bytes(32);
            $hash = hash_pbkdf2('sha256', $pin, $salt, 210000, 32, true);
            $this->db->run('INSERT INTO tenant_unlock_pin (tenant_id,pin_enc,salt,verifier,updated_by,updated_at)
                VALUES (?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE pin_enc=VALUES(pin_enc),salt=VALUES(salt),
                verifier=VALUES(verifier),revision=revision+1,updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',
                [$tenant, "\x01" . $iv . $tag . $cipher, $salt, $hash, $this->c['principal_id']]);
            (new Audit($this->db))->record($this->c, $this->r, 'unlock_pin.changed', 'tenant', $tenant, ['pin']);
        }
        $row = $this->db->one('SELECT pin_enc,revision,updated_at FROM tenant_unlock_pin WHERE tenant_id=?', [$tenant]);
        if ($op === 'revealTenantUnlockPin') {
            if ($row === null) { throw new ApiError(409, 'invalid_transition'); }
            $blob = $row['pin_enc'];
            if (strlen($blob) < 35 || $blob[0] !== "\x01") { throw new \RuntimeException('Invalid unlock PIN ciphertext'); }
            $pin = openssl_decrypt(substr($blob, 29), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA,
                substr($blob, 1, 12), substr($blob, 13, 16), $this->aad());
            if ($pin === false) { throw new \RuntimeException('Invalid unlock PIN ciphertext'); }
            (new Audit($this->db))->record($this->c, $this->r, 'unlock_pin.revealed', 'tenant', $tenant, ['pin']);
            return AdminApi::response(['pin' => $pin]);
        }
        return AdminApi::response(['configured' => $row !== null, 'revision' => (int) ($row['revision'] ?? 0),
            'updated_at' => $row === null ? null : Util::time($row['updated_at'])]);
    }
}
