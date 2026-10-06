<?php
declare(strict_types=1);
// Deshace un lote de tools/import-one-snapshot.php: quita SOLO lo que ese lote creo (personas, horarios, unidades).
// Uso: php tools/wipe-import.php --batch <uuid> [--apply] [--force-unassign] [--json]
//   Sin --apply: dry-run (conteos exactos, nada se escribe).
//   Lo emparejado (ya existia) se deja tal cual. Una persona creada con equipos o cuenta de panel, o que es jefe de
//   alguien que no creo el lote, se informa y se salta; con --force-unassign se retira (como en el panel) y las personas
//   existentes que apuntan a algo del lote vuelven a su valor anterior (o a «Sin ...»). Unidades en uso: se saltan;
//   solo con historial: quedan eliminadas (deleted_at), como «Eliminar» del panel. La auditoria nunca se borra.
use Keeper\{Database, Util};
use Keeper\Tools\OneImport;

require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/OneImport.php';

if (PHP_SAPI !== 'cli') { exit(1); }
$usage = "Uso: php tools/wipe-import.php --batch <uuid> [--apply] [--dry-run] [--force-unassign] [--json]\n";
$opt = ['apply' => false, 'force-unassign' => false, 'json' => false];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (preg_match('/^--batch(?:=(.*))?$/s', $a, $m)) { $opt['batch'] = $m[1] ?? ($argv[++$i] ?? ''); }
    elseif (in_array($a, ['--apply', '--force-unassign', '--json'], true)) { $opt[substr($a, 2)] = true; }
    elseif ($a === '--dry-run') { $opt['apply'] = false; }
    else { fwrite(STDERR, "Opción no reconocida: $a\n$usage"); exit(2); }
}
if (!preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $opt['batch'] ?? '')) { fwrite(STDERR, $usage); exit(2); }

try {
    $result = (new OneImport(new Database()))->wipe(Util::bin($opt['batch']), $opt['apply'], $opt['force-unassign']);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . ($e instanceof PDOException ? 'database_error ' . ($e->errorInfo[1] ?? $e->getCode()) : $e->getMessage()) . "\n");
    exit(1);
}
$mode = $result['applied'] ? 'APLICADO' : 'DRY-RUN';
if ($opt['json']) { echo Util::json(['mode' => $mode, 'tenant' => $result['tenant'], 'left' => $result['left'], 'counts' => $result['counts']]), "\n"; exit(0); }
foreach ($result['counts'] as $entity => $c) {
    $line = sprintf('  %-20s', $entity);
    foreach ($c as $k => $v) { if ($k !== 'codes') { $line .= " $k=$v"; } }
    if (!empty($c['codes'])) { ksort($c['codes']); $line .= '  [' . implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($c['codes']), $c['codes'])) . ']'; }
    echo $line, "\n";
}
echo $mode . ($result['left'] ? ": quedan {$result['left']} elementos creados sin quitar (ver motivos)" : ': todo lo creado por el lote queda quitado') . ($result['applied'] ? '' : '; repetir con --apply') . "\n";
exit(0);
