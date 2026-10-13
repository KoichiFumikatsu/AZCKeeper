<?php
declare(strict_types=1);
namespace Keeper;

// Mudanza de servidor (agente 4.0.24): manifiesto JWS ES256 firmado con la clave de RELEASES (tools/sign-relocation.ps1)
// que mueve los equipos de este servidor (from_base = ORIGIN) a otro (to_base). El servidor solo lo valida, lo guarda y lo
// entrega; quien decide es el agente, que lo verifica con su propia clave de confianza y prueba login en el destino antes
// de cambiar. Que lo diga el servidor no basta: sin la firma de releases el agente lo ignora.
// Modo mudado (enforced_at): las llamadas /v1/client/* de los equipos a los que aplica responden 409 device_relocated con
// el JWS y no se acepta ni guarda nada (el agente conserva su cola). Solo agentes 4.0.24+: uno anterior no sabe mudarse,
// y con 409 tampoco podria recibir la actualizacion que si sabe. Con la BD copiada al destino (otro ORIGIN) la fila no
// aplica alli: from_base ya no coincide.
final class Relocations
{
    public const MIN_AGENT = '4.0.24';
    // Destinos permitidos (los mismos que acepta el agente, RelocationVerifier.AllowedHostSuffixes).
    public const HOST_SUFFIXES = ['.azclegal.com'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public function __construct(private Database $db) {}

    public static function origin(): string { return rtrim(Config::get('ORIGIN'), '/') . '/'; }

    /** La mudanza vigente de mayor sequence que aplica al equipo (tenant incluido, equipo no excluido), o null. */
    public function forDevice(string $tenant, string $device, bool $enforcedOnly = false): ?array
    {
        $rows = $this->db->run('SELECT * FROM relocations WHERE tenant_id=? AND from_base=? AND not_before<=UTC_TIMESTAMP(6) AND expires_at>UTC_TIMESTAMP(6)'
            . ($enforcedOnly ? ' AND enforced_at IS NOT NULL' : '') . ' ORDER BY sequence DESC', [Util::bin(AdminAuth::PLATFORM), self::origin()])->fetchAll();
        $t = Util::id($tenant); $d = Util::id($device);
        foreach ($rows as $row) {
            if (in_array($t, json_decode($row['tenants'], true), true) && !in_array($d, json_decode($row['exclude_devices'], true), true)) { return $row; }
        }
        return null;
    }

    /** JWS para /client/sync (agentes 4.0.24+; los anteriores rechazan campos desconocidos). */
    public function deliver(array $c): ?string
    {
        if (version_compare($c['agent_version'], self::MIN_AGENT, '<')) { return null; }
        return $this->forDevice($c['tenant_id'], $c['device_id'])['relocation_jws'] ?? null;
    }

    /** Modo mudado: 409 device_relocated (con el JWS) si aplica al equipo. */
    public function enforce(string $tenant, string $device, ?string $agentVersion): void
    {
        if ($agentVersion === null || version_compare($agentVersion, self::MIN_AGENT, '<')) { return; }
        $row = $this->forDevice($tenant, $device, true);
        if ($row !== null) { throw new ApiError(409, 'device_relocated', [], null, [], ['relocation' => $row['relocation_jws']]); }
    }

    /** Challenge/login por device_id (sin token). Con enrollment_ticket es un alta: no aplica. En login manda la version que declara. */
    public function enforceBootstrap(Request $r): void
    {
        $b = $r->body;
        if (!is_object($b) || isset($b->enrollment_ticket) || !is_string($b->device_id ?? null) || !preg_match(self::UUID, strtolower($b->device_id))) { return; }
        $row = $this->db->one('SELECT tenant_id,id,agent_version FROM devices WHERE id=? LIMIT 1', [Util::bin($b->device_id)]);
        if ($row) { $this->enforce($row['tenant_id'], $row['id'], is_string($b->agent_version ?? null) ? $b->agent_version : $row['agent_version']); }
    }

    /** Verifica firma (RELEASE_KEYS_FILE, como las releases) y forma del payload. Cualquier fallo: 422. */
    public static function verify(string $jws): array
    {
        $fail = static fn (): ApiError => new ApiError(422, 'validation_failed');
        $parts = explode('.', $jws);
        if (count($parts) !== 3) { throw $fail(); }
        try {
            $header = json_decode(Util::unb64($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            $keys = Resources::releaseKeys();
            if (!is_array($header) || ($header['alg'] ?? null) !== 'ES256' || !is_string($header['kid'] ?? null) || !isset($keys[$header['kid']]) || isset($header['crit']) || isset($header['b64'])) { throw $fail(); }
            if (openssl_verify($parts[0] . '.' . $parts[1], Signature::der(Util::unb64($parts[2])), Signature::pem((object) $keys[$header['kid']]), OPENSSL_ALGO_SHA256) !== 1) { throw $fail(); }
            $p = json_decode(Util::unb64($parts[1]), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { throw $fail(); }
        $allowed = ['type', 'id', 'sequence', 'from_base', 'to_base', 'tenants', 'exclude_devices', 'not_before', 'expires_at'];
        if (!is_array($p) || array_diff(array_keys($p), $allowed) || ($p['type'] ?? null) !== 'relocation' || !is_string($p['id'] ?? null) || !preg_match(self::UUID, $p['id'])
            || !is_int($p['sequence'] ?? null) || $p['sequence'] < 1 || !self::base($p['from_base'] ?? null, false) || !self::base($p['to_base'] ?? null, true)
            || $p['from_base'] === $p['to_base'] || !self::ids($p['tenants'] ?? null, 1) || !self::ids($p['exclude_devices'] ?? [], 0)
            || !self::instant($p['not_before'] ?? null) || !self::instant($p['expires_at'] ?? null) || strtotime($p['expires_at']) <= strtotime($p['not_before'])) { throw $fail(); }
        return ['id' => $p['id'], 'sequence' => $p['sequence'], 'from_base' => $p['from_base'], 'to_base' => $p['to_base'], 'tenants' => array_values($p['tenants']),
            'exclude_devices' => array_values($p['exclude_devices'] ?? []), 'not_before' => $p['not_before'], 'expires_at' => $p['expires_at'], 'key_id' => $header['kid']];
    }

    // https://host[:puerto]/ sin ruta, query ni credenciales; el destino ademas HTTPS y bajo un sufijo permitido.
    private static function base(mixed $v, bool $destination): bool
    {
        if (!is_string($v) || strlen($v) > 255 || !preg_match('~^(https?)://([a-z0-9.-]+)(?::\d{1,5})?/$~D', $v, $m)) { return false; }
        if (!$destination) { return true; }
        if ($m[1] !== 'https') { return false; }
        foreach (self::HOST_SUFFIXES as $suffix) { if (str_ends_with($m[2], $suffix) && strlen($m[2]) > strlen($suffix)) { return true; } }
        return false;
    }
    private static function ids(mixed $v, int $min): bool
    {
        if (!is_array($v) || !array_is_list($v) || count($v) < $min || count($v) > 1000 || count(array_unique($v)) !== count($v)) { return false; }
        foreach ($v as $id) { if (!is_string($id) || !preg_match(self::UUID, $id)) { return false; } }
        return true;
    }
    private static function instant(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $v) && strtotime($v) !== false;
    }

    public function register(string $jws, string $actor): array
    {
        $m = self::verify($jws);
        $platform = Util::bin(AdminAuth::PLATFORM);
        // Solo se registra en el servidor de origen, y vigente: una mudanza ajena o vencida nunca aplicaria aqui.
        if ($m['from_base'] !== self::origin() || strtotime($m['expires_at']) <= time()) { throw new ApiError(422, 'validation_failed'); }
        $max = $this->db->one('SELECT MAX(sequence) n FROM relocations WHERE tenant_id=? FOR UPDATE', [$platform])['n'];
        if ($max !== null && $m['sequence'] <= (int) $max) { throw new ApiError(409, 'invalid_transition'); }
        $this->db->run('INSERT INTO relocations (tenant_id,id,sequence,from_base,to_base,tenants,exclude_devices,not_before,expires_at,key_id,relocation_jws,registered_by,registered_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(6))',
            [$platform, Util::bin($m['id']), $m['sequence'], $m['from_base'], $m['to_base'], Util::json($m['tenants']), Util::json($m['exclude_devices']), Util::sqlTime($m['not_before']), Util::sqlTime($m['expires_at']), $m['key_id'], $jws, $actor]);
        return $this->row($m['id']);
    }

    public function enforcement(string $id, bool $enforced, string $actor): array
    {
        $this->row($id);
        $this->db->run('UPDATE relocations SET enforced_at=IF(?,COALESCE(enforced_at,UTC_TIMESTAMP(6)),NULL),enforced_by=IF(?,COALESCE(enforced_by,?),NULL) WHERE tenant_id=? AND id=?',
            [(int) $enforced, (int) $enforced, $actor, Util::bin(AdminAuth::PLATFORM), Util::bin($id)]);
        return $this->row($id);
    }

    public function row(string $id): array
    {
        return $this->db->one('SELECT * FROM relocations WHERE tenant_id=? AND id=? FOR UPDATE', [Util::bin(AdminAuth::PLATFORM), Util::bin($id)]) ?? throw new ApiError(404, 'resource_not_found');
    }

    public static function dto(array $r): array
    {
        return ['id' => Util::id($r['id']), 'sequence' => (int) $r['sequence'], 'from_base' => $r['from_base'], 'to_base' => $r['to_base'],
            'tenants' => json_decode($r['tenants'], true), 'exclude_devices' => json_decode($r['exclude_devices'], true),
            'not_before' => Util::time($r['not_before']), 'expires_at' => Util::time($r['expires_at']), 'key_id' => $r['key_id'], 'relocation_jws' => $r['relocation_jws'],
            'registered_at' => Util::time($r['registered_at']), 'enforced_at' => $r['enforced_at'] === null ? null : Util::time($r['enforced_at'])];
    }
}
