<?php
declare(strict_types=1);
namespace Keeper;

final class Hardening
{
    public function __construct(private Database $db, private array $c, private Request $r) {}

    private function key(): string { return hash_hkdf('sha256', Config::key(), 32, 'keeper:hardening:v1'); }
    private function aad(): string { return 'keeper:hardening:v1:' . $this->c['tenant_id']; }
    private function encrypt(string $password): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($password, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $this->aad(), 16);
        if ($cipher === false) { throw new \RuntimeException('Hardening encryption failed'); }
        return "\x01" . $iv . $tag . $cipher;
    }
    private function password(): string
    {
        $row = $this->db->one('SELECT shared_password_enc FROM tenant_hardening_settings WHERE tenant_id=?', [$this->c['tenant_id']]);
        $blob = $row['shared_password_enc'] ?? null;
        if ($blob === null) { throw new ApiError(409, 'invalid_transition'); }
        if (strlen($blob) < 41 || $blob[0] !== "\x01") { throw new \RuntimeException('Invalid hardening ciphertext'); }
        $plain = openssl_decrypt(substr($blob, 29), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($blob, 1, 12), substr($blob, 13, 16), $this->aad());
        if ($plain === false) { throw new \RuntimeException('Invalid hardening ciphertext'); }
        return $plain;
    }
    private function settings(): array
    {
        $row = $this->db->one('SELECT admin_name,hardening_mode,deny_network_logon,shared_password_enc IS NOT NULL configured,updated_by,updated_at FROM tenant_hardening_settings WHERE tenant_id=?', [$this->c['tenant_id']]);
        return ['tenant_id' => Util::id($this->c['tenant_id']), 'admin_name' => $row['admin_name'] ?? 'azcadmin',
            'hardening_mode' => $row['hardening_mode'] ?? 'panel', 'deny_network_logon' => (bool) ($row['deny_network_logon'] ?? true),
            'shared_password' => ($row['configured'] ?? false) ? '********' : null,
            'updated_by' => isset($row['updated_by']) ? Util::id($row['updated_by']) : null,
            'updated_at' => isset($row['updated_at']) ? Util::time($row['updated_at']) : null];
    }
    public function clientSettings(): array
    {
        $s = $this->settings();
        return ['admin_name' => $s['admin_name'], 'hardening_mode' => $s['hardening_mode'],
            'deny_network_logon' => $s['deny_network_logon'], 'shared_password' => $this->password()];
    }
    private function status(string $device): array
    {
        $row = $this->db->one('SELECT state,last_step,detail,hardened_at,reported_at FROM device_hardening_status WHERE tenant_id=? AND device_id=?', [$this->c['tenant_id'], $device]);
        return ['device_id' => Util::id($device), 'tenant_id' => Util::id($this->c['tenant_id']),
            'state' => $row['state'] ?? 'none', 'last_step' => $row['last_step'] ?? null, 'detail' => $row['detail'] ?? null,
            'hardened_at' => isset($row['hardened_at']) ? Util::time($row['hardened_at']) : null,
            'reported_at' => isset($row['reported_at']) ? Util::time($row['reported_at']) : null];
    }
    public function report(): array
    {
        $b = $this->r->body;
        $this->db->run("INSERT INTO device_hardening_status (tenant_id,device_id,state,last_step,detail,hardened_at,reported_at)
            VALUES (?,?,?,?,?,IF(?='hardened',UTC_TIMESTAMP(6),NULL),UTC_TIMESTAMP(6))
            ON DUPLICATE KEY UPDATE hardened_at=COALESCE(hardened_at,VALUES(hardened_at)),
            state=VALUES(state),last_step=COALESCE(VALUES(last_step),last_step),detail=COALESCE(VALUES(detail),detail),reported_at=VALUES(reported_at)",
            [$this->c['tenant_id'], $this->c['device_id'], $b->state, isset($b->last_step) ? (string) $b->last_step : null, $b->detail ?? null, $b->state]);
        return $this->status($this->c['device_id']);
    }
    public function admin(AdminAccess $access): array
    {
        $op = $this->r->operation['operationId'];
        $tenant = $this->c['tenant_id'];
        $id = Util::bin($this->r->resourceId);
        $b = $this->r->body;
        $audit = new Audit($this->db);
        if (str_starts_with($this->r->route, '/admin/tenants/')) {
            $access->requireFull();
            if ($id !== $tenant) { throw new ApiError(404, 'resource_not_found'); }
            if ($op === 'setTenantHardening') {
                $adminName = trim($b->admin_name);
                if ($adminName === '' || str_ends_with($adminName, '.')) { throw new ApiError(422, 'validation_failed'); }
                if (!isset($b->shared_password) && !($this->db->one('SELECT shared_password_enc IS NOT NULL configured FROM tenant_hardening_settings WHERE tenant_id=?', [$tenant])['configured'] ?? false)) { throw new ApiError(409, 'invalid_transition'); }
                $cipher = isset($b->shared_password) ? $this->encrypt($b->shared_password) : null;
                $this->db->run('INSERT INTO tenant_hardening_settings (tenant_id,admin_name,shared_password_enc,hardening_mode,deny_network_logon,updated_by,updated_at)
                    VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE admin_name=VALUES(admin_name),
                    shared_password_enc=COALESCE(VALUES(shared_password_enc),shared_password_enc),hardening_mode=VALUES(hardening_mode),
                    deny_network_logon=VALUES(deny_network_logon),updated_by=VALUES(updated_by),updated_at=VALUES(updated_at)',
                    [$tenant, $adminName, $cipher, $b->hardening_mode, (int) $b->deny_network_logon, $this->c['principal_id']]);
                $audit->record($this->c, $this->r, 'hardening.settings.set', 'tenant', $tenant, array_keys(get_object_vars($b)));
            }
            if ($op === 'revealTenantHardening') {
                $password = $this->password();
                $audit->record($this->c, $this->r, 'hardening.password.reveal', 'tenant', $tenant, ['shared_password']);
                return AdminApi::response(['shared_password' => $password]);
            }
            return AdminApi::response($this->settings());
        }
        $device = $this->db->one('SELECT * FROM devices WHERE tenant_id=? AND id=?' . ($op === 'createHardeningCommand' ? ' FOR UPDATE' : ''), [$tenant, $id]);
        if (!$device) { throw new ApiError(404, 'resource_not_found'); }
        $access->user($device['user_id']);
        if ($op === 'getDeviceHardening') { return AdminApi::response($this->status($id)); }
        if ($device['status'] !== 'active') { throw new ApiError(409, 'invalid_transition'); }
        if ($b->action === 'harden' && (
            !($this->db->one('SELECT shared_password_enc IS NOT NULL configured FROM tenant_hardening_settings WHERE tenant_id=?', [$tenant])['configured'] ?? false)
            || ($this->db->one('SELECT state FROM device_hardening_status WHERE tenant_id=? AND device_id=?', [$tenant, $id])['state'] ?? 'none') === 'recovery_required'
        )) {
            throw new ApiError(409, 'invalid_transition');
        }
        // Antes de 4.0.10 el agente rechazaba harden/unharden (unsupported_command) y no conoce el parametro admin_name
        // (los agentes rechazan campos desconocidos en todo el sync): se niega en vez de dejar el equipo sin sincronizar.
        if (version_compare($device['agent_version'], '4.0.10', '<')) { throw new ApiError(409, 'agent_too_old'); }
        $parameters = Util::json(['admin_name' => $this->settings()['admin_name']]);
        $sequence = max((int) $device['command_sequence'] + 1, (int) $this->db->one('SELECT COALESCE(MAX(sequence),0)+1 n FROM device_command WHERE tenant_id=? AND device_id=?', [$tenant, $id])['n']);
        $command = Util::bin(Util::uuid());
        $this->db->run('UPDATE devices SET command_sequence=? WHERE tenant_id=? AND id=?', [$sequence, $tenant, $id]);
        $this->db->run("INSERT INTO device_command (tenant_id,id,device_id,sequence,type,reason,parameters,created_at,expires_at)
            VALUES (?,?,?,?,?,'Panel hardening command',?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL 1 DAY)", [$tenant, $command, $id, $sequence, $b->action, $parameters]);
        $audit->record($this->c, $this->r, 'hardening.command.' . $b->action, 'device_command', $command, ['type', 'device_id', 'expires_at']);
        return AdminApi::response(['command_id' => Util::id($command), 'device_id' => Util::id($id), 'action' => $b->action, 'status' => 'pending'], 202);
    }
}
