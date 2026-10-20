<?php
declare(strict_types=1);

// Endpoint de SOLO LECTURA que sirve el roster de One legacy con el contrato exacto del conector REST de Keeper
// (backend-v4/src/DataSync.php restFetch/pages): GET <base>/<endpoint>?empresa=..&updated_since=..&cursor=..,
// Authorization: Bearer <token>, sobre keeper.roster.v1, complete=true solo sin updated_since, mismo generated_at en todas
// las paginas, next_cursor opaco. Falla cerrado: cualquier error es 4xx/5xx, nunca 200 con una lista vacia.
// No lista directorios y no registra datos personales, credenciales ni el token.
//
// Dos modos (clave 'mode' de la configuracion):
//   files: sirve la instantanea que escribio extract.php (snapshot_dir). Es el modo del 2026-10-06.
//   live:  construye el roster AL MOMENTO desde la BD viva de One (RosterBuilder, sesion READ ONLY) y lo guarda en una
//          cache privada con TTL. generated_at = UTC_TIMESTAMP() de MySQL tomado antes de leer (decision 2026-10-20:
//          Keeper se alimenta en continuo desde One v1; el cron de devkeep llama cada 10 min, aqui no hay cron).
//
// Configuracion (fuera del docroot): archivo PHP que devuelve un array; ver README §3 y serve.config.example.php.
// Ruta del archivo: variable de entorno ONE_SNAPSHOT_CONFIG, si no la constante ONE_BRIDGE_CONFIG (la define la entrada
// publica minima, public-entry.php) y si no CONFIG_FILE.
// Requiere PHP >= 8.0 con pdo_mysql y mbstring (el hosting de One sirve 8.0 por defecto): sin el tipo never ni enums.

const CONFIG_FILE = '/home/keeper/one-snapshot/serve.config.php';
const SCHEMA = 'keeper.roster.v1';
const SERVED = ['org-units', 'schedules', 'people'];
const NOT_SERVED = ['holidays', 'absences'];  // Keeper es dueno de festivos y ausencias: si alguien se los asigna a esta fuente, la vuelta falla visible
const CACHE_FORMAT = 1;

final class HttpFail extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode) { parent::__construct($errorCode); }
}

/** @return void (no retorna: termina la peticion) */
function reply(int $status, array $body)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Strict-Transport-Security: max-age=31536000');
    if ($status === 401) { header('WWW-Authenticate: Bearer'); }
    if ($status === 405) { header('Allow: GET'); }
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $status, string $code, string $endpoint = '-')
{
    error_log("one-snapshot endpoint=$endpoint status=$status error=$code");
    reply($status, ['error' => $code]);
}
function readJson(string $file): array
{
    $raw = @file_get_contents($file);
    if ($raw === false) { throw new RuntimeException('snapshot file unreadable'); }
    $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new RuntimeException('snapshot file invalid'); }
    return $data;
}
function b64url(string $v): string { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }

// ---- Modo files: la instantanea de extract.php -----------------------------------------------------------------------
final class FileSnapshot
{
    public array $manifest;
    public function __construct(private string $dir)
    {
        $this->manifest = readJson($dir . '/manifest.json');
        if (($this->manifest['schema'] ?? null) !== SCHEMA || !is_string($this->manifest['generated_at'] ?? null) || !is_string($this->manifest['company'] ?? null)) { throw new RuntimeException('manifest invalid'); }
    }
    public function company(): string { return $this->manifest['company']; }
    public function generatedAt(): string { return $this->manifest['generated_at']; }
    public function pages(string $endpoint): int { return (int) ($this->manifest['endpoints'][$endpoint]['pages'] ?? 0); }
    public function page(string $endpoint, int $page): array
    {
        $data = readJson(sprintf('%s/%s/%04d.json', $this->dir, $endpoint, $page));
        if (!is_array($data['items'] ?? null)) { throw new RuntimeException('page invalid'); }
        return $data['items'];
    }
    public function health(): array
    {
        return ['ok' => true, 'schema' => SCHEMA, 'company' => $this->company(), 'generated_at' => $this->generatedAt(),
            'counts' => array_map(static fn ($e) => (int) ($e['items'] ?? 0), $this->manifest['endpoints'] ?? [])];
    }
    /** En modo files una instantanea solo coincide consigo misma. */
    public function forCursor(string $generated): ?self { return $generated === $this->generatedAt() ? $this : null; }
}

// ---- Modo live: roster construido desde la BD viva con cache privada ------------------------------------------------
final class LiveRoster
{
    private array $build;
    private bool $stale = false;
    private string $db = 'ok';
    private int $pageSize;

    public function __construct(private array $config)
    {
        $this->pageSize = (int) ($config['page_size'] ?? 500);
        if ($this->pageSize < 1 || $this->pageSize > 500) { throw new RuntimeException('page_size invalid'); }
    }

    private function dir(): string { return $this->config['cache_dir']; }
    private function file(string $name): string { return $this->dir() . DIRECTORY_SEPARATOR . $name; }

    private function load(string $name): ?array
    {
        $raw = @file_get_contents($this->file($name));
        if ($raw === false) { return null; }
        try { $c = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException $e) { return null; }
        return is_array($c) && ($c['format'] ?? null) === CACHE_FORMAT && is_string($c['generated_at'] ?? null) && is_int($c['built_at'] ?? null) ? $c : null;
    }

    /** Escritura atomica (temporal + rename) con permisos 600: la cache tiene datos personales. */
    private function store(string $name, array $build): void
    {
        $tmp = $this->file($name . '.' . bin2hex(random_bytes(6)) . '.tmp');
        $old = umask(0077);
        try {
            if (file_put_contents($tmp, json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) { throw new RuntimeException('cache write failed'); }
            @chmod($tmp, 0600);
            if (!rename($tmp, $this->file($name))) { throw new RuntimeException('cache rename failed'); }
        } finally { umask($old); if (is_file($tmp)) { @unlink($tmp); } }
    }

    private function fresh(?array $c): bool
    {
        $ttl = (int) ($this->config['cache_ttl'] ?? 300);
        return $c !== null && time() - $c['built_at'] < $ttl && $c['built_at'] <= time() + 60;
    }

    /** Construye desde One. Lanza RosterError (sin datos personales) si la BD falla o la carga no pasa el freno. */
    private function construct(): array
    {
        require_once __DIR__ . '/RosterBuilder.php';
        $db = null; $warnings = 0;
        try {
            $maps = RosterBuilder::loadMaps((string) ($this->config['maps_dir'] ?? __DIR__));
            $credentials = RosterBuilder::oneCredentials((string) ($this->config['one_config'] ?? ''));
            $db = RosterBuilder::connect($credentials, isset($this->config['db_port']) ? (int) $this->config['db_port'] : null, (int) ($this->config['db_timeout'] ?? 5));
            unset($credentials);
            $generated = RosterBuilder::readOnlySession($db);  // hora de MySQL tomada antes de abrir la instantanea
            $builder = new RosterBuilder($db, $maps[0], $maps[1], ($this->config['lunch'] ?? true) === true, (int) ($this->config['min_active'] ?? 150), static function () use (&$warnings): void { $warnings++; });
            $roster = $builder->build($generated);
        } catch (RosterError $e) {
            throw $e;
        } catch (PDOException $e) {
            throw new RosterError('db query failed', 'db_unavailable');
        } catch (Throwable $e) {
            throw new RosterError('build failed ' . get_class($e), 'build_failed');
        } finally {
            // Fin de la transaccion de solo lectura (no hay nada que confirmar). La conexion se cierra al soltar el PDO.
            if ($db !== null) { try { $db->exec('ROLLBACK'); } catch (Throwable $e) { } }
            $db = null;
        }
        $p = $roster['counts']['people'];
        return ['format' => CACHE_FORMAT, 'generated_at' => $generated, 'built_at' => time(), 'company' => $roster['company'],
            'endpoints' => ['org-units' => $roster['org-units'], 'schedules' => $roster['schedules'], 'people' => $roster['people']],
            'stats' => ['warnings' => $warnings, 'active' => $p['active'], 'inactive' => $p['inactive'], 'unmapped_company' => $p['unmapped_company'],
                'invalid_schedule_days' => $roster['counts']['schedules']['invalid_days_discarded']]];
    }

    /**
     * Roster vigente: la cache si no vencio; si vencio, se reconstruye bajo bloqueo exclusivo (una sola peticion construye,
     * las demas esperan y usan el resultado). Si One no responde, se sirve la cache vieja (hasta cache_stale_max) con
     * aviso en el log; sin cache, 503.
     */
    public function current(): self
    {
        $cur = $this->load('roster.json');
        if ($this->fresh($cur)) { $this->build = $cur; return $this; }
        $lock = fopen($this->file('build.lock'), 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) { throw new RuntimeException('cache lock failed'); }
        try {
            $cur = $this->load('roster.json');
            if ($this->fresh($cur)) { $this->build = $cur; return $this; }
            try {
                $new = $this->construct();
            } catch (RosterError $e) {
                $age = $cur === null ? null : time() - $cur['built_at'];
                if ($cur !== null && $age <= (int) ($this->config['cache_stale_max'] ?? 86400)) {
                    error_log("one-snapshot live build failed reason={$e->reason}; serving stale cache age_s=$age");
                    $this->build = $cur; $this->stale = true; $this->db = $e->reason;
                    return $this;
                }
                error_log("one-snapshot live build failed reason={$e->reason}; no usable cache");
                throw new HttpFail(503, $e->reason === 'sanity_failed' ? 'sanity_failed' : 'source_unavailable');
            }
            // La anterior queda como roster.prev.json: una vuelta que ya pidio la pagina 1 puede terminar con su version.
            if ($cur !== null) { $this->store('roster.prev.json', $cur); }
            $this->store('roster.json', $new);
            error_log("one-snapshot live build ok active={$new['stats']['active']} warnings={$new['stats']['warnings']}");
            $this->build = $new;
            return $this;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    /** Version que corresponde a un cursor (la vigente o la anterior); null si ya no existe. No reconstruye. */
    public function forCursor(string $generated): ?self
    {
        foreach (['roster.json', 'roster.prev.json'] as $name) {
            $c = $this->load($name);
            if ($c !== null && hash_equals($c['generated_at'], $generated)) { $copy = clone $this; $copy->build = $c; return $copy; }
        }
        return null;
    }

    public function company(): string { return $this->build['company']; }
    public function generatedAt(): string { return $this->build['generated_at']; }
    public function pages(string $endpoint): int { return max(1, (int) ceil(count($this->build['endpoints'][$endpoint] ?? []) / $this->pageSize)); }
    public function page(string $endpoint, int $page): array
    {
        $list = $this->build['endpoints'][$endpoint] ?? null;
        if (!is_array($list)) { throw new RuntimeException('cache endpoint missing'); }
        return array_slice($list, ($page - 1) * $this->pageSize, $this->pageSize);
    }
    public function health(): array
    {
        return ['ok' => true, 'schema' => SCHEMA, 'company' => $this->company(), 'generated_at' => $this->generatedAt(), 'mode' => 'live',
            'db' => $this->db, 'stale' => $this->stale, 'cache_age_s' => time() - $this->build['built_at'],
            'counts' => array_map('count', $this->build['endpoints']), 'stats' => $this->build['stats'] ?? []];
    }
}

ini_set('display_errors', '0');
// Todo aviso de PHP es un error (falla cerrado), salvo los silenciados con @ (lecturas de cache que pueden no existir).
set_error_handler(static function (int $no, string $msg): bool { if (!(error_reporting() & $no)) { return true; } throw new ErrorException($msg, 0, $no); });
$endpoint = '-';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { fail(405, 'method_not_allowed'); }

    $configFile = getenv('ONE_SNAPSHOT_CONFIG') ?: (defined('ONE_BRIDGE_CONFIG') ? ONE_BRIDGE_CONFIG : CONFIG_FILE);
    $config = is_file($configFile) ? require $configFile : null;
    $hashes = is_array($config) ? array_values(array_filter((array) ($config['token_sha256'] ?? []), static fn ($h) => is_string($h) && preg_match('/^[0-9a-f]{64}$/', $h))) : [];
    $mode = is_array($config) ? ($config['mode'] ?? 'files') : null;
    if ($mode === 'files') {
        $dir = is_string($config['snapshot_dir'] ?? null) ? realpath($config['snapshot_dir']) : false;
        $configured = $dir !== false && is_dir($dir);
    } elseif ($mode === 'live') {
        $configured = is_string($config['one_config'] ?? null) && is_string($config['cache_dir'] ?? null) && is_dir($config['cache_dir']) && is_writable($config['cache_dir']);
    } else { $configured = false; }
    if (!$hashes || !$configured) { fail(503, 'not_configured'); }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (!$https && ($config['allow_http'] ?? false) !== true) { fail(403, 'https_required'); }
    // Lista opcional de IPs (la de devkeep). Complementa al .htaccess: funciona aunque el hosting ignore Require ip.
    $ips = (array) ($config['allowed_ips'] ?? []);
    if ($ips && !in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), $ips, true)) { fail(403, 'forbidden'); }

    // Bearer: se compara el SHA-256 contra TODOS los hashes configurados (tiempo constante, sin cortar al primer acierto).
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth === '' && function_exists('getallheaders')) { foreach (getallheaders() as $k => $v) { if (strcasecmp($k, 'Authorization') === 0) { $auth = $v; } } }
    $ok = false;
    if (preg_match('/^Bearer ([\x21-\x7E]{16,512})$/', (string) $auth, $m)) {
        $given = hash('sha256', $m[1]);
        foreach ($hashes as $h) { $ok = hash_equals($h, $given) || $ok; }
    }
    if (!$ok) { fail(401, 'unauthorized'); }

    // Ruta: ultimo segmento de PATH_INFO (o de la URI), con o sin el prefijo /api/keeper/v1.
    $path = $_SERVER['PATH_INFO'] ?? (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $endpoint = basename(rtrim($path, '/'));
    if (in_array($endpoint, NOT_SERVED, true)) { fail(404, 'not_served', $endpoint); }
    if ($endpoint !== 'health' && !in_array($endpoint, SERVED, true)) { $endpoint = '-'; fail(404, 'not_found'); }

    // Los parametros se validan ANTES de tocar la BD: una peticion mal formada no dispara una reconstruccion.
    if ($endpoint !== 'health') {
        foreach (array_keys($_GET) as $k) { if (!in_array($k, ['empresa', 'updated_since', 'cursor'], true)) { fail(400, 'unknown_parameter', $endpoint); } }
        $empresa = $_GET['empresa'] ?? null;
        if (!is_string($empresa) || $empresa === '') { fail(400, 'missing_empresa', $endpoint); }
        $since = $_GET['updated_since'] ?? null;
        if ($since !== null && (!is_string($since) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $since))) { fail(400, 'invalid_updated_since', $endpoint); }
        $cursor = $_GET['cursor'] ?? null;
        $c = null;
        if ($cursor !== null) {
            $c = is_string($cursor) && strlen($cursor) <= 1024 ? json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true) : null;
            if (!is_array($c) || !is_int($c['p'] ?? null) || !is_string($c['g'] ?? null) || ($c['e'] ?? null) !== $endpoint || $c['p'] < 2 || $c['p'] > 500) { fail(400, 'invalid_cursor', $endpoint); }
        }
    }

    if ($mode === 'files') { $snap = new FileSnapshot($dir); }
    else { $snap = new LiveRoster($config); }

    if ($endpoint !== 'health' && $c !== null) {
        // Pagina 2..n: la MISMA version que la pagina 1 (en vivo, la vigente o la anterior). Si ya no existe, la vuelta
        // debe fallar, no mezclar dos versiones.
        $snap = $snap->forCursor($c['g']);
        if ($snap === null) { fail(409, 'snapshot_changed', $endpoint); }
    } elseif ($snap instanceof LiveRoster) { $snap->current(); }

    if ($endpoint === 'health') { reply(200, $snap->health()); }

    if (!hash_equals($snap->company(), $empresa)) { fail(404, 'unknown_empresa', $endpoint); }
    $generated = $snap->generatedAt();
    $pages = $snap->pages($endpoint);
    if ($pages < 1 || $pages > 500) { throw new RuntimeException('pages invalid'); }
    $page = 1;
    if ($c !== null) {
        if ($c['p'] > $pages) { fail(400, 'invalid_cursor', $endpoint); }
        $page = $c['p'];
    }

    $items = $snap->page($endpoint, $page);
    // Incremental: personas cambiadas desde updated_since (inclusivo: Keeper trunca a segundos). Los catalogos van enteros.
    if ($since !== null && $endpoint === 'people') {
        $items = array_values(array_filter($items, static fn ($i) => is_string($i['updated_at'] ?? null) && $i['updated_at'] >= $since));
    }
    $next = $page < $pages ? b64url(json_encode(['g' => $generated, 'e' => $endpoint, 'p' => $page + 1])) : null;
    error_log("one-snapshot endpoint=$endpoint status=200 page=$page items=" . count($items) . ($since !== null ? ' incremental' : ' full') . ($mode === 'live' ? ' live' : ''));
    reply(200, ['schema' => SCHEMA, 'company' => $snap->company(), 'generated_at' => $generated, 'complete' => $since === null, 'next_cursor' => $next, 'items' => $items]);
} catch (HttpFail $e) {
    fail($e->status, $e->errorCode, $endpoint);
} catch (Throwable $e) {
    error_log('one-snapshot endpoint=' . $endpoint . ' status=503 error=internal ' . get_class($e));
    if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
    echo '{"error":"internal_error"}';
}
