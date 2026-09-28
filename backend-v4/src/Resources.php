<?php
declare(strict_types=1);
namespace Keeper;

final class Resources
{
    public function __construct(private Database $db, private array $c, private Validator $validator) {}
    public function policy(): array
    {
        $p = $this->db->one('SELECT policy_version,document FROM effective_policies WHERE tenant_id=? AND device_id=? ORDER BY policy_version DESC LIMIT 1', [$this->c['tenant_id'], $this->c['device_id']]);
        if (!$p) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '120']); }
        $doc = json_decode($p['document'], false, 64, JSON_THROW_ON_ERROR);
        $version = (int) $p['policy_version'];
        if (($doc->tenant_id ?? null) !== Util::id($this->c['tenant_id']) || ($doc->device_id ?? null) !== Util::id($this->c['device_id']) || ($doc->version ?? null) !== (string) $version || ($doc->etag ?? null) !== '"' . $version . '"') {
            throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '120']);
        }
        try { $this->validator->named('EffectivePolicy', $doc); }
        catch (ApiError) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '120']); }
        return [$version, $doc];
    }
    private function cursorContext(): string
    {
        if (isset($this->c['cursor_context'])) { return $this->c['cursor_context']; }
        return Util::json([bin2hex($this->c['tenant_id']), bin2hex($this->c['principal_id']), $this->c['device_auth_version'], $this->c['user_auth_version'], $this->c['tenant_auth_version'], 'commands:created_at,id']);
    }
    public function encodeCursor(array $data): string
    {
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt(Util::json($data), 'aes-256-gcm', Config::key(), OPENSSL_RAW_DATA, $iv, $tag, $this->cursorContext());
        if ($cipher === false) { throw new \RuntimeException('Cursor encryption failed'); }
        return Util::b64($iv . $tag . $cipher);
    }
    public function decodeCursor(string $value): array
    {
        try { $b = Util::unb64($value); } catch (ApiError) { throw new ApiError(404, 'resource_not_found'); }
        if (strlen($b) < 29 || strlen($b) > 1500) { throw new ApiError(404, 'resource_not_found'); }
        $s = openssl_decrypt(substr($b, 28), 'aes-256-gcm', Config::key(), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16), $this->cursorContext());
        if ($s === false) { throw new ApiError(404, 'resource_not_found'); }
        $v = json_decode($s, true, 16, JSON_THROW_ON_ERROR);
        if ($v['exp'] < time()) { throw new ApiError(422, 'cursor_expired'); }
        return $v;
    }
    public function commands(int $limit = 20, ?string $cursor = null): array
    {
        $page = $cursor !== null ? $this->decodeCursor($cursor) : ['at' => '', 'id' => null, 'snapshot' => Util::sqlTime(Util::now()), 'exp' => time() + 900];
        if (isset($page['limit']) && $page['limit'] !== $limit) { throw new ApiError(422, 'validation_failed'); }
        $args = [$this->c['tenant_id'], $this->c['device_id'], $page['snapshot']];
        $condition = '';
        if ($page['id'] !== null) { $condition = ' AND (created_at>? OR (created_at=? AND id>?))'; array_push($args, $page['at'], $page['at'], Util::bin($page['id'])); }
        $args[] = $limit + 1;
        $rows = $this->db->run('SELECT * FROM device_command WHERE tenant_id=? AND device_id=? AND created_at<=? AND status IN (\'pending\',\'delivered\',\'running\') AND expires_at>UTC_TIMESTAMP(6)' . $condition . ' ORDER BY created_at,id LIMIT ?', $args)->fetchAll();
        $more = count($rows) > $limit;
        if ($more) { array_pop($rows); }
        $next = null;
        if ($more) { $last = end($rows); $next = $this->encodeCursor(['at' => $last['created_at'], 'id' => Util::id($last['id']), 'snapshot' => $page['snapshot'], 'exp' => $page['exp'], 'limit' => $limit]); }
        $data = array_map(static fn (array $row): array => ['id' => Util::id($row['id']), 'tenant_id' => Util::id($row['tenant_id']), 'device_id' => Util::id($row['device_id']), 'type' => $row['type'], 'status' => $row['status'], 'created_at' => Util::time($row['created_at']), 'expires_at' => Util::time($row['expires_at']), 'result' => null], $rows);
        return ['data' => $data, 'next_cursor' => $next, 'generated_at' => Util::now(), 'data_through' => Util::time($page['snapshot'])];
    }
    public function release(?string $id = null): ?array
    {
        $architecture = self::releaseArchitecture($this->c['specs'] ?? null);
        if ($architecture === null) { return null; }
        $args = [$this->c['tenant_id'], $this->c['release_ring'], $architecture];
        $where = '';
        if ($id !== null) { $where = ' AND r.id=?'; $args[] = Util::bin($id); }
        $q = $this->db->run('SELECT r.*,d.percentage FROM release_deployments d JOIN client_releases r ON r.tenant_id=d.release_tenant_id AND r.id=d.release_id WHERE d.tenant_id=? AND d.ring=? AND d.enabled=TRUE AND r.architecture=? AND r.published_at<=UTC_TIMESTAMP(6)' . $where . ' ORDER BY r.sequence DESC LIMIT 100', $args);
        while ($row = $q->fetch()) {
            $bucket = hexdec(substr(hash('sha256', $this->c['tenant_id'] . $this->c['device_id'] . $row['id']), 0, 8)) % 100;
            if ($bucket >= (int) $row['percentage'] || version_compare($this->c['agent_version'], $row['min_agent_version'], '<')) { continue; }
            $result = [];
            foreach (['version', 'channel', 'min_agent_version', 'architecture', 'artifact_url', 'key_id', 'manifest_jws'] as $key) { $result[$key] = $row[$key]; }
            $result += ['id' => Util::id($row['id']), 'sequence' => (int) $row['sequence'], 'size_bytes' => (int) $row['size_bytes'], 'sha256' => bin2hex($row['sha256']), 'published_at' => Util::time($row['published_at'])];
            $this->verifyRelease($result);
            return $result;
        }
        return null;
    }
    // devices.specs solo lo llena el importador de K3: el agente v4 no reporta specs (SyncRequest no las
    // admite), asi que un equipo enrolado por v4 tiene specs NULL y nunca recibiria una release. Sin
    // arquitectura reportada se asume x64 (la flota Windows actual). Es seguro: el agente vuelve a verificar
    // la arquitectura del manifest firmado y rechaza la release si no coincide con la suya.
    // Una arquitectura reportada pero desconocida sigue sin recibir release.
    public static function releaseArchitecture(?string $specs): ?string
    {
        $decoded = $specs === null || $specs === '' ? null : json_decode($specs);
        $architecture = is_object($decoded) ? ($decoded->architecture ?? null) : null;
        if ($architecture === null) { return 'x64'; }
        return in_array($architecture, ['x64', 'arm64'], true) ? $architecture : null;
    }
    public function verifyRelease(array $release): void
    {
        $keysFile = Config::get('RELEASE_KEYS_FILE', dirname(__DIR__) . '/config/release-keys.json');
        $keys = is_file($keysFile) ? json_decode(file_get_contents($keysFile), true, 32, JSON_THROW_ON_ERROR) : [];
        $parts = explode('.', $release['manifest_jws']);
        try {
            if (count($parts) !== 3 || !isset($keys[$release['key_id']])) { throw new \RuntimeException('Untrusted release'); }
            $header = json_decode(Util::unb64($parts[0]), true, 16, JSON_THROW_ON_ERROR);
            if (($header['alg'] ?? null) !== 'ES256' || ($header['kid'] ?? null) !== $release['key_id'] || isset($header['crit']) || isset($header['b64'])) { throw new \RuntimeException('Invalid release header'); }
            $jwk = (object) $keys[$release['key_id']];
            if (openssl_verify($parts[0] . '.' . $parts[1], Signature::der(Util::unb64($parts[2])), Signature::pem($jwk), OPENSSL_ALGO_SHA256) !== 1) { throw new \RuntimeException('Invalid release signature'); }
            $payload = json_decode(Util::unb64($parts[1]), false, 32, JSON_THROW_ON_ERROR);
            $expected = $release; unset($expected['manifest_jws']);
            // RFC3339 permits equivalent UTC encodings; compare instants for publication time.
            if (isset($payload->published_at)) { $payload->published_at = Util::time(Util::sqlTime($payload->published_at)); }
            if (Util::canonical($payload) !== Util::canonical((object) $expected)) { throw new \RuntimeException('Manifest does not match release'); }
        } catch (\Throwable) { throw new ApiError(503, 'temporarily_unavailable', ['Retry-After' => '120']); }
    }
}
