<?php
declare(strict_types=1);
// Deshace un lote de tools/import-one-snapshot.php: quita SOLO lo que ese lote creo y sigue exactamente como quedo.
// Uso: php tools/wipe-import.php --tenant <uuid> --confirm-tenant "<nombre exacto>" --batch <uuid> [--force] [--json]
//   Sin --force: dry-run (conteos exactos, nada se escribe). Muestra el cliente y el resumen del lote antes de actuar.
//   Nunca toca lo emparejado (ya existia) ni lo que el dueño cambio despues de la carga. Se informa y se deja:
//     persona editada (otra version), con equipos, cuenta de panel, acceso propio, historial, o jefe de alguien que se queda;
//     horario o unidad editados, en uso por alguien que se queda, con hijas que se quedan o con historial ajeno.
//   No retira ni reasigna a nadie: lo que se queda lo decide el dueño en el panel. La auditoria nunca se borra; cada
//   borrado deja su fila.
use Keeper\{Database, Util};
use Keeper\Tools\OneImport;

require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/OneImport.php';

if (PHP_SAPI !== 'cli') { exit(1); }
$usage = "Uso: php tools/wipe-import.php --tenant <uuid> --confirm-tenant \"<nombre exacto>\" --batch <uuid> [--force] [--json]\n";
$opt = ['force' => false, 'json' => false];
$uuid = '/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i';
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (preg_match('/^--(tenant|confirm-tenant|batch)(?:=(.*))?$/s', $a, $m)) { $opt[$m[1]] = $m[2] ?? ($argv[++$i] ?? ''); }
    elseif (in_array($a, ['--force', '--json'], true)) { $opt[substr($a, 2)] = true; }
    elseif ($a === '--dry-run') { $opt['force'] = false; }
    else { fwrite(STDERR, "Opción no reconocida: $a\n$usage"); exit(2); }
}
if (!preg_match($uuid, $opt['batch'] ?? '') || !preg_match($uuid, $opt['tenant'] ?? '') || ($opt['confirm-tenant'] ?? '') === '') { fwrite(STDERR, $usage); exit(2); }

try {
    $db = new Database();
    $tenant = OneImport::guardTenant($db, Util::bin($opt['tenant']), $opt['confirm-tenant']);
    if (!OneImport::hasManifestTables($db)) { throw new RuntimeException('migration_missing'); }
    $summary = OneImport::batchSummary($db, $tenant['tenant_id'], Util::bin($opt['batch']));
    if (!$opt['json']) {
        echo 'Cliente: ' . $tenant['name'] . ' (' . Util::id($tenant['tenant_id']) . ")\n";
        echo "Lote {$opt['batch']}: {$summary['kind']} status={$summary['status']} creado={$summary['created_at']} instantánea={$summary['source_generated_at']}\n";
        foreach ($summary['items'] as $type => $c) { echo "  $type " . implode(' ', array_map(fn ($k, $v) => "$k=$v", array_keys($c), $c)) . "\n"; }
        echo "Resultado:\n";
    }
    $result = (new OneImport($db))->wipe($tenant['tenant_id'], Util::bin($opt['batch']), $opt['force']);
} catch (Throwable $e) {
    $msg = $e instanceof PDOException ? 'database_error ' . ($e->errorInfo[1] ?? $e->getCode()) : ($e instanceof RuntimeException ? $e->getMessage() : $e::class . ' ' . $e->getCode());
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}
$mode = $result['applied'] ? 'APLICADO' : 'DRY-RUN';
if ($opt['json']) { echo Util::json(['mode' => $mode, 'tenant' => $tenant['name'], 'batch' => $summary, 'left' => $result['left'], 'counts' => $result['counts']]), "\n"; exit(0); }
foreach ($result['counts'] as $entity => $c) {
    $line = sprintf('  %-14s', $entity);
    foreach ($c as $k => $v) { if ($k !== 'codes') { $line .= " $k=$v"; } }
    if (!empty($c['codes'])) { ksort($c['codes']); $line .= '  [' . implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($c['codes']), $c['codes'])) . ']'; }
    echo $line, "\n";
}
echo $mode . ($result['left'] ? ": quedan {$result['left']} elementos creados (ver motivos; decidir en el panel)" : ': todo lo creado por el lote queda quitado') . ($result['applied'] ? '' : '; repetir con --force') . "\n";
exit(0);
