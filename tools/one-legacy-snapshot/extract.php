<?php
declare(strict_types=1);

// Instantanea UNICA de AZC One legacy -> formato canonico keeper.roster.v1 (docs/architecture/v4-puente-one-legacy.md §2).
// Lee una copia local de la BD de One (cargada desde un volcado) y escribe una pagina JSON por endpoint del conector REST
// de Keeper (org-units, schedules, people) mas manifest.json y counts.json. Solo consulta columnas permitidas: nunca
// password, pin, photo, contacto personal, tarjeta, archivos ni datos de firmas cliente (manager, mail_manager).
// La salida contiene datos personales: va FUERA del repo. Por consola solo salen conteos.
//
// Uso:
//   php extract.php --out=DIR --generated-at=2026-09-22T20:29:35Z [--dsn=mysql:host=127.0.0.1;port=3306;dbname=one_legacy;charset=utf8mb4]
//                   [--user=root] [--no-lunch] [--page-size=500] [--min-active=150] [--force]
//   Contrasena de la BD: variable de entorno ONE_DB_PASSWORD (vacia por defecto).

require __DIR__ . '/RosterBuilder.php';

function fail(string $msg): never { fwrite(STDERR, "ERROR: $msg\n"); exit(1); }

$opts = getopt('', ['out:', 'generated-at:', 'dsn:', 'user:', 'no-lunch', 'page-size:', 'min-active:', 'force', 'config-dir:']);
$out = $opts['out'] ?? fail('falta --out=DIR (fuera del repo: la salida tiene datos personales)');
$generatedAt = $opts['generated-at'] ?? fail('falta --generated-at=YYYY-MM-DDTHH:MM:SSZ (momento del volcado, ver README)');
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $generatedAt) || strtotime($generatedAt) === false) { fail('--generated-at debe ser YYYY-MM-DDTHH:MM:SSZ'); }
$dsn = $opts['dsn'] ?? 'mysql:host=127.0.0.1;port=3306;dbname=one_legacy;charset=utf8mb4';
$lunch = !isset($opts['no-lunch']);
$pageSize = (int) ($opts['page-size'] ?? 500);
$minActive = (int) ($opts['min-active'] ?? 150);
if ($pageSize < 1 || $pageSize > 500) { fail('--page-size entre 1 y 500'); }
$configDir = $opts['config-dir'] ?? __DIR__;

$repo = realpath(__DIR__ . '/../..');
$outReal = realpath(dirname(rtrim($out, '/\\'))) ?: fail('la carpeta padre de --out no existe');
if ($repo !== false && str_starts_with(strtolower($outReal . DIRECTORY_SEPARATOR), strtolower($repo . DIRECTORY_SEPARATOR))) { fail('--out no puede estar dentro del repo (datos personales)'); }

[$companyMap, $siteMap] = RosterBuilder::loadMaps($configDir);

// La misma construccion que serve.php en modo vivo (RosterBuilder), con sesion de solo lectura.
try {
    $db = new PDO($dsn, $opts['user'] ?? 'root', getenv('ONE_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    RosterBuilder::readOnlySession($db);
    $roster = (new RosterBuilder($db, $companyMap, $siteMap, $lunch, $minActive, static function (string $w): void { fwrite(STDERR, "AVISO: $w\n"); }))->build($generatedAt);
} catch (RosterError $e) { fail($e->getMessage()); }
$tenantSlug = $roster['company'];
$counts = $roster['counts'];

// ---- Escritura: <out>/<tenant>/<endpoint>/NNNN.json + manifest.json + counts.json -------------------------------------------
$root = rtrim($out, '/\\') . DIRECTORY_SEPARATOR . $tenantSlug;
if (is_dir($root)) {
    if (!isset($opts['force'])) { fail("$root ya existe (use --force para reemplazarla)"); }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($root);
}
$endpoints = ['org-units' => $roster['org-units'], 'schedules' => $roster['schedules'], 'people' => $roster['people']];
$manifest = ['schema' => RosterBuilder::SCHEMA, 'company' => $tenantSlug, 'generated_at' => $generatedAt, 'source' => 'one-legacy-snapshot', 'lunch' => $lunch, 'page_size' => $pageSize, 'endpoints' => []];
$json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
foreach ($endpoints as $endpoint => $list) {
    $dir = "$root/$endpoint";
    mkdir($dir, 0700, true);
    $pages = array_chunk($list, $pageSize) ?: [[]];
    foreach ($pages as $i => $page) { file_put_contents(sprintf('%s/%04d.json', $dir, $i + 1), json_encode(['items' => $page], $json)); }
    $manifest['endpoints'][$endpoint] = ['pages' => count($pages), 'items' => count($list)];
}
file_put_contents("$root/manifest.json", json_encode($manifest, $json | JSON_PRETTY_PRINT));
file_put_contents(rtrim($out, '/\\') . "/counts-$tenantSlug.json", json_encode($counts, $json | JSON_PRETTY_PRINT));

echo json_encode(['out' => $root, 'endpoints' => $manifest['endpoints'], 'counts' => $counts], $json | JSON_PRETTY_PRINT), "\n";
