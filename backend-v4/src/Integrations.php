<?php
declare(strict_types=1);
namespace Keeper;

/**
 * Credenciales de la API externa de una empresa (v4-api.md §3.3 y §6.2): API key (X-API-Key: <prefijo>.<secreto>) o
 * cliente OAuth client_credentials. Solo la plataforma (integraciones.gestionar es platform_only, 0044). El secreto se
 * genera aqui (32 bytes), se guarda solo su SHA-256 y sale una unica vez: al crear y al rotar. Revocar apaga la
 * integracion y sube auth_version: ExternalAuth rechaza la key y los access tokens OAuth ya emitidos en la siguiente
 * peticion. Rotar deja la credencial anterior viva como mucho 24 horas.
 */
final class Integrations
{
    /** Funcion contratada que exige cada scope (ExternalAuth::context: las entradas por puerta van por "puertas"). */
    private const SCOPE_FEATURE = ['check-ins:write' => 'puertas'];
    /** Scopes que solo tienen sentido junto a su base (v4-api.md §3.3). */
    private const SCOPE_REQUIRES = ['activity-titles:read' => 'activity:read', 'location:read' => 'presence:read'];
    /** Caducidad elegible en dias. La API key tiene maximo 90 dias (§3.3 y CHECK de api_keys en 0003). */
    private const DAYS = ['api_key' => [30, 90], 'oauth2' => [30, 90, 180, 365]];
    private const OVERLAP_HOURS = 24;

    private string $tenant;
    public function __construct(private Database $db, private array $c, private AdminAccess $access, private Request $r) { $this->tenant = $c['tenant_id']; }

    public function dispatch(string $op, ?string $id, ?object $b): array
    {
        $this->access->requireFull();
        return match ($op) {
            'listIntegrations' => $this->list(),
            'createIntegration' => $this->create($b),
            'rotateIntegration' => $this->rotate($id, $b),
            'revokeIntegration' => $this->revoke($id),
            default => throw new ApiError(404, 'resource_not_found'),
        };
    }

    /** Scopes que la empresa puede conceder hoy: los de la API si tiene "api"; check-ins:write si tiene "puertas". */
    private function available(): array
    {
        $features = Features::enabled($this->db, $this->tenant);
        return array_values(array_filter(ExternalAuth::SCOPES, fn ($s) => in_array(self::SCOPE_FEATURE[$s] ?? 'api', $features, true)));
    }

    private function list(): array
    {
        $rows = $this->db->run('SELECT * FROM integrations WHERE tenant_id=? ORDER BY created_at DESC,id LIMIT 200', [$this->tenant])->fetchAll();
        return AdminApi::response(['data' => array_map(fn ($i) => $this->dto($i), $rows), 'available_scopes' => $this->available(),
            'expiry_days' => self::DAYS, 'token_url' => self::tokenUrl()]);
    }

    private static function tokenUrl(): string { return rtrim(Config::get('ORIGIN'), '/') . '/v1/oauth/token'; }
    private static function optTime(?string $v): ?string { return $v === null ? null : Util::time($v); }

    private function dto(array $i): array
    {
        $scopes = array_column($this->db->run('SELECT scope_code FROM integration_scopes WHERE tenant_id=? AND integration_id=? ORDER BY scope_code', [$this->tenant, $i['id']])->fetchAll(), 'scope_code');
        [$table, $field] = $i['auth_type'] === 'api_key' ? ['api_keys', 'public_prefix'] : ['oauth_clients', 'client_id'];
        $credentials = array_map(fn ($k) => ['credential_id' => $k[$field], 'created_at' => Util::time($k['created_at']), 'expires_at' => Util::time($k['expires_at']), 'revoked_at' => self::optTime($k['revoked_at'])],
            $this->db->run("SELECT $field,created_at,expires_at,revoked_at FROM $table WHERE tenant_id=? AND integration_id=? ORDER BY created_at DESC,id LIMIT 10", [$this->tenant, $i['id']])->fetchAll());
        $author = $i['created_by'] === null ? null : $this->db->one('SELECT a.email,a.display_name FROM principals p JOIN admin_accounts a ON a.tenant_id=p.admin_tenant_id AND a.id=p.admin_id WHERE p.tenant_id=? AND p.id=?', [$this->tenant, $i['created_by']]);
        $expired = Util::sqlTime(Util::time($i['expires_at'])) <= Util::sqlTime('now');
        return ['id' => Util::id($i['id']), 'tenant_id' => Util::id($i['tenant_id']), 'name' => $i['name'], 'auth_type' => $i['auth_type'], 'scopes' => $scopes,
            'created_at' => Util::time($i['created_at']), 'expires_at' => Util::time($i['expires_at']), 'revoked_at' => self::optTime($i['revoked_at']),
            'last_used_at' => self::optTime($i['last_used_at']), 'created_by' => $author === null ? null : ($author['display_name'] ?: $author['email']),
            'active' => (bool) $i['active'] && $i['revoked_at'] === null, 'status' => $i['revoked_at'] !== null || !$i['active'] ? 'revoked' : ($expired ? 'expired' : 'active'),
            'credential_id' => $credentials[0]['credential_id'] ?? null, 'credentials' => $credentials];
    }

    private function row(string $id): array
    {
        $i = $this->db->one('SELECT * FROM integrations WHERE tenant_id=? AND id=? FOR UPDATE', [$this->tenant, $id]);
        if (!$i) { throw new ApiError(404, 'resource_not_found'); }
        return $i;
    }

    private function requireReauth(): void
    {
        if (($this->c['reauthenticated_at'] ?? null) === null || strtotime($this->c['reauthenticated_at'] . ' UTC') < time() - 300) { throw new ApiError(403, 'reauth_required'); }
    }

    private function days(string $type, ?int $days): int
    {
        $days ??= 90;
        if (!in_array($days, self::DAYS[$type], true)) { throw new ApiError(422, 'validation_failed', [], $type === 'api_key' ? 'api_key_max_90_days' : 'invalid_expiry'); }
        return $days;
    }

    private function scopes(array $scopes): array
    {
        $scopes = array_values(array_unique($scopes)); sort($scopes);
        if (!$scopes) { throw new ApiError(422, 'validation_failed', [], 'scopes_required'); }
        foreach ($scopes as $s) {
            if (!in_array($s, ExternalAuth::SCOPES, true)) { throw new ApiError(422, 'validation_failed', [], 'unknown_scope'); }
            if (isset(self::SCOPE_REQUIRES[$s]) && !in_array(self::SCOPE_REQUIRES[$s], $scopes, true)) { throw new ApiError(422, 'validation_failed', [], 'scope_requires:' . self::SCOPE_REQUIRES[$s]); }
        }
        // Una credencial con scopes de una funcion no contratada responderia 403 a todo: se rechaza al crearla.
        $available = $this->available();
        foreach ($scopes as $s) { if (!in_array($s, $available, true)) { throw new ApiError(422, 'validation_failed', [], 'feature_not_contracted:' . (self::SCOPE_FEATURE[$s] ?? 'api')); } }
        return $scopes;
    }

    /** [id binario de la fila, identificador publico, secreto]. Prefijo y client_id son globalmente unicos (0010). */
    private function credential(string $integration, string $type, int $days): array
    {
        $secret = Util::b64(random_bytes(32)); $row = Util::bin(Util::uuid());
        [$table, $field, $public] = $type === 'api_key' ? ['api_keys', 'public_prefix', 'kpk_' . bin2hex(random_bytes(10))] : ['oauth_clients', 'client_id', 'kci_' . bin2hex(random_bytes(12))];
        $this->db->run("INSERT INTO $table (tenant_id,id,integration_id,$field,secret_hash,created_at,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL ? DAY)",
            [$this->tenant, $row, $integration, $public, hash('sha256', $secret, true), $days]);
        return [$row, $public, $secret];
    }

    private function secretResponse(string $id, string $public, string $secret, int $status, ?string $previousUntil = null): array
    {
        $i = $this->dto($this->db->one('SELECT * FROM integrations WHERE tenant_id=? AND id=?', [$this->tenant, $id]));
        $body = ['integration' => $i, 'credential_id' => $public, 'secret' => $secret,
            'api_key' => $i['auth_type'] === 'api_key' ? $public . '.' . $secret : null,
            'token_url' => $i['auth_type'] === 'oauth2' ? self::tokenUrl() : null, 'previous_valid_until' => $previousUntil];
        return AdminApi::response($body, $status, ['Cache-Control' => 'no-store']);
    }

    private function audit(string $id, array $fields): void { (new Audit($this->db))->record($this->c, $this->r, $this->r->operation['operationId'], 'integration', $id, $fields); }

    private function create(object $b): array
    {
        $this->requireReauth();
        $name = trim(preg_replace('/\s+/u', ' ', $b->name));
        if ($name === '' || mb_strlen($name) > 100) { throw new ApiError(422, 'validation_failed', [], 'invalid_name'); }
        $type = $b->auth_type ?? 'api_key';
        $days = $this->days($type, $b->expires_in_days ?? null);
        $scopes = $this->scopes($b->scopes);
        if ($this->db->one('SELECT id FROM integrations WHERE tenant_id=? AND name=? AND revoked_at IS NULL AND active=TRUE', [$this->tenant, $name])) { throw new ApiError(409, 'invalid_transition', [], 'name_in_use'); }
        $id = Util::bin(Util::uuid());
        $this->db->run('INSERT INTO integrations (tenant_id,id,name,created_by,auth_type,created_at,expires_at) VALUES (?,?,?,?,?,UTC_TIMESTAMP(6),UTC_TIMESTAMP(6)+INTERVAL ? DAY)',
            [$this->tenant, $id, $name, $this->c['principal_id'], $type, $days]);
        $this->db->run("INSERT INTO principals (tenant_id,id,kind,integration_id) VALUES (?,?,'integration',?)", [$this->tenant, Util::bin(Util::uuid()), $id]);
        foreach ($scopes as $s) { $this->db->run('INSERT INTO integration_scopes (tenant_id,integration_id,scope_code) VALUES (?,?,?)', [$this->tenant, $id, $s]); }
        [, $public, $secret] = $this->credential($id, $type, $days);
        // Auditoria sin secreto: que se creo, de que tipo, con que scopes y cuanto dura.
        $this->audit($id, ['name', 'auth_type', ...array_map(fn ($s) => 'scope:' . $s, $scopes), 'expires_in_days:' . $days]);
        return $this->secretResponse($id, $public, $secret, 201);
    }

    private function rotate(string $id, ?object $b): array
    {
        $this->requireReauth();
        $i = $this->row($id);
        if ($i['revoked_at'] !== null || !$i['active']) { throw new ApiError(409, 'invalid_transition', [], 'integration_revoked'); }
        $type = $i['auth_type'];
        $days = $this->days($type, $b->expires_in_days ?? null);
        [$table] = $type === 'api_key' ? ['api_keys'] : ['oauth_clients'];
        $live = $this->db->run("SELECT id FROM $table WHERE tenant_id=? AND integration_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP(6) ORDER BY created_at DESC,id FOR UPDATE", [$this->tenant, $id])->fetchAll();
        // Solo la credencial vigente mas reciente conserva el solapamiento (<= 24 h); las anteriores ya en solapamiento se revocan.
        $previous = null;
        foreach ($live as $n => $k) {
            if ($n === 0) {
                $this->db->run("UPDATE $table SET expires_at=LEAST(expires_at,UTC_TIMESTAMP(6)+INTERVAL " . self::OVERLAP_HOURS . " HOUR) WHERE tenant_id=? AND id=?", [$this->tenant, $k['id']]);
                $previous = Util::time($this->db->one("SELECT expires_at FROM $table WHERE tenant_id=? AND id=?", [$this->tenant, $k['id']])['expires_at']);
            } else { $this->db->run("UPDATE $table SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND id=?", [$this->tenant, $k['id']]); }
        }
        [, $public, $secret] = $this->credential($id, $type, $days);
        // Rotar es la renovacion explicita: la integracion vence con la credencial nueva.
        $this->db->run('UPDATE integrations SET expires_at=UTC_TIMESTAMP(6)+INTERVAL ? DAY WHERE tenant_id=? AND id=?', [$days, $this->tenant, $id]);
        $this->audit($id, ['secret', 'expires_in_days:' . $days, 'previous_valid_until:' . ($previous ?? 'none')]);
        return $this->secretResponse($id, $public, $secret, 200, $previous);
    }

    private function revoke(string $id): array
    {
        $i = $this->row($id);
        if ($i['revoked_at'] === null || $i['active']) {
            $this->db->run('UPDATE integrations SET active=FALSE,revoked_at=COALESCE(revoked_at,UTC_TIMESTAMP(6)),auth_version=auth_version+1 WHERE tenant_id=? AND id=?', [$this->tenant, $id]);
            foreach (['api_keys', 'oauth_clients'] as $table) { $this->db->run("UPDATE $table SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND integration_id=? AND revoked_at IS NULL", [$this->tenant, $id]); }
            $this->db->run('UPDATE oauth_access_tokens SET revoked_at=UTC_TIMESTAMP(6) WHERE tenant_id=? AND integration_id=? AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP(6)', [$this->tenant, $id]);
            $this->audit($id, ['active', 'revoked_at']);
        }
        return ['status' => 204, 'json' => '', 'headers' => []];
    }
}
