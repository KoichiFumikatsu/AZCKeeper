<?php
declare(strict_types=1);

// Endpoint de SOLO LECTURA que sirve la instantanea de One legacy (extract.php) con el contrato exacto del conector REST de
// Keeper (backend-v4/src/DataSync.php restFetch/pages): GET <base>/<endpoint>?empresa=..&updated_since=..&cursor=..,
// Authorization: Bearer <token>, sobre keeper.roster.v1, complete=true solo sin updated_since, mismo generated_at en todas
// las paginas, next_cursor opaco. Falla cerrado: cualquier error es 4xx/5xx, nunca 200 con una lista vacia.
// No lista directorios, no escribe nada y no registra datos personales ni el token.
//
// Configuracion (fuera del docroot): archivo PHP que devuelve
//   ['snapshot_dir' => '/home/keeper/one-snapshot/grupo-azc', 'token_sha256' => ['<hex>', '<hex de rotacion opcional>'],
//    'allow_http' => false]
// Ruta del archivo: variable de entorno ONE_SNAPSHOT_CONFIG o, si no esta, CONFIG_FILE (fuera de cualquier docroot).

const CONFIG_FILE = '/home/keeper/one-snapshot/serve.config.php';
const SCHEMA = 'keeper.roster.v1';
const SERVED = ['org-units', 'schedules', 'people'];
const NOT_SERVED = ['holidays', 'absences'];  // Keeper es dueno de festivos y ausencias: si alguien se los asigna a esta fuente, la vuelta falla visible

function reply(int $status, array $body): never
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
function fail(int $status, string $code, string $endpoint = '-'): never
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

set_error_handler(static function (int $no, string $msg): bool { throw new ErrorException($msg, 0, $no); });
$endpoint = '-';
try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') { fail(405, 'method_not_allowed'); }

    $configFile = getenv('ONE_SNAPSHOT_CONFIG') ?: CONFIG_FILE;
    $config = is_file($configFile) ? require $configFile : null;
    $hashes = is_array($config) ? array_values(array_filter((array) ($config['token_sha256'] ?? []), static fn ($h) => is_string($h) && preg_match('/^[0-9a-f]{64}$/', $h))) : [];
    $dir = is_array($config) && is_string($config['snapshot_dir'] ?? null) ? realpath($config['snapshot_dir']) : false;
    if (!$hashes || $dir === false || !is_dir($dir)) { fail(503, 'not_configured'); }

    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
    if (!$https && ($config['allow_http'] ?? false) !== true) { fail(403, 'https_required'); }

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

    $manifest = readJson($dir . '/manifest.json');
    $generated = $manifest['generated_at'] ?? null;
    if (($manifest['schema'] ?? null) !== SCHEMA || !is_string($generated) || !is_string($manifest['company'] ?? null)) { throw new RuntimeException('manifest invalid'); }

    if ($endpoint === 'health') {
        reply(200, ['ok' => true, 'schema' => SCHEMA, 'company' => $manifest['company'], 'generated_at' => $generated,
            'counts' => array_map(static fn ($e) => (int) ($e['items'] ?? 0), $manifest['endpoints'] ?? [])]);
    }

    foreach (array_keys($_GET) as $k) { if (!in_array($k, ['empresa', 'updated_since', 'cursor'], true)) { fail(400, 'unknown_parameter', $endpoint); } }
    $empresa = $_GET['empresa'] ?? null;
    if (!is_string($empresa) || $empresa === '') { fail(400, 'missing_empresa', $endpoint); }
    if (!hash_equals($manifest['company'], $empresa)) { fail(404, 'unknown_empresa', $endpoint); }
    $since = $_GET['updated_since'] ?? null;
    if ($since !== null && (!is_string($since) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $since))) { fail(400, 'invalid_updated_since', $endpoint); }

    $pages = (int) ($manifest['endpoints'][$endpoint]['pages'] ?? 0);
    if ($pages < 1 || $pages > 500) { throw new RuntimeException('manifest pages invalid'); }
    $page = 1;
    $cursor = $_GET['cursor'] ?? null;
    if ($cursor !== null) {
        $c = is_string($cursor) && strlen($cursor) <= 1024 ? json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true) : null;
        if (!is_array($c) || !is_int($c['p'] ?? null) || !is_string($c['g'] ?? null) || ($c['e'] ?? null) !== $endpoint || $c['p'] < 2 || $c['p'] > $pages) { fail(400, 'invalid_cursor', $endpoint); }
        // La instantanea se reemplazo entre paginas: la vuelta debe fallar, no mezclar dos versiones.
        if ($c['g'] !== $generated) { fail(409, 'snapshot_changed', $endpoint); }
        $page = $c['p'];
    }

    $data = readJson(sprintf('%s/%s/%04d.json', $dir, $endpoint, $page));
    if (!is_array($data['items'] ?? null)) { throw new RuntimeException('page invalid'); }
    $items = $data['items'];
    // Incremental: personas cambiadas desde updated_since (inclusivo: Keeper trunca a segundos). Los catalogos van enteros.
    if ($since !== null && $endpoint === 'people') {
        $items = array_values(array_filter($items, static fn ($i) => is_string($i['updated_at'] ?? null) && $i['updated_at'] >= $since));
    }
    $next = $page < $pages ? b64url(json_encode(['g' => $generated, 'e' => $endpoint, 'p' => $page + 1])) : null;
    error_log("one-snapshot endpoint=$endpoint status=200 page=$page items=" . count($items) . ($since !== null ? ' incremental' : ' full'));
    reply(200, ['schema' => SCHEMA, 'company' => $manifest['company'], 'generated_at' => $generated, 'complete' => $since === null, 'next_cursor' => $next, 'items' => $items]);
} catch (Throwable $e) {
    error_log('one-snapshot endpoint=' . $endpoint . ' status=503 error=internal ' . get_class($e));
    if (!headers_sent()) { http_response_code(503); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); }
    echo '{"error":"internal_error"}';
}
