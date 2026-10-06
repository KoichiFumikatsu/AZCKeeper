<?php
declare(strict_types=1);
// Carga UNICA de la instantanea de AZC One legacy como datos locales y editables (sin fuente de datos ni bloqueos).
// Uso: php tools/import-one-snapshot.php --tenant <uuid> --dir <carpeta grupo-azc> [--apply] [--allow-errors] [--json]
//   Sin --apply: dry-run (todo en una transaccion que se revierte; conteos exactos, nada se escribe).
//   --apply: una sola transaccion; deja un lote (import_batches, migracion 0047) para tools/wipe-import.php y una
//            entrada de auditoria. Con errores por item se niega salvo --allow-errors (esos items se saltan).
// Repetible: un segundo --apply empareja lo ya cargado (lote anterior, nombre o cedula) y no duplica.
// Salida: solo conteos y codigos, nunca nombres, cedulas ni correos.
use Keeper\{Database, Util};
use Keeper\Tools\OneImport;

require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/OneImport.php';

if (PHP_SAPI !== 'cli') { exit(1); }
$usage = "Uso: php tools/import-one-snapshot.php --tenant <uuid> --dir <carpeta> [--apply] [--dry-run] [--allow-errors] [--json]\n";
$opt = ['apply' => false, 'allow-errors' => false, 'json' => false];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (preg_match('/^--(tenant|dir)(?:=(.*))?$/s', $a, $m)) { $opt[$m[1]] = $m[2] ?? ($argv[++$i] ?? ''); }
    elseif (in_array($a, ['--apply', '--allow-errors', '--json'], true)) { $opt[substr($a, 2)] = true; }
    elseif ($a === '--dry-run') { $opt['apply'] = false; }
    else { fwrite(STDERR, "Opción no reconocida: $a\n$usage"); exit(2); }
}
if (!preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $opt['tenant'] ?? '') || ($opt['dir'] ?? '') === '') { fwrite(STDERR, $usage); exit(2); }

try {
    $snap = OneImport::readSnapshot($opt['dir']);
    $db = new Database();
    $result = (new OneImport($db))->import(Util::bin($opt['tenant']), $snap, $opt['apply'], $opt['allow-errors']);
} catch (Throwable $e) {
    // El mensaje nunca lleva datos de personas (codigos y conteos); los PDOException se reducen a su codigo.
    fwrite(STDERR, 'ERROR: ' . ($e instanceof PDOException ? 'database_error ' . ($e->errorInfo[1] ?? $e->getCode()) : $e->getMessage()) . "\n");
    exit(1);
}
$mode = $result['applied'] ? 'APLICADO' : ($opt['apply'] ? 'NO APLICADO' : 'DRY-RUN');
if ($opt['json']) {
    echo Util::json(['mode' => $mode, 'batch' => $result['batch'], 'errors' => $result['errors'], 'changed' => $result['changed'], 'counts' => $result['counts']]), "\n";
} else {
    echo "Instantánea: schema=" . $snap['manifest']['schema'] . ' generated_at=' . ($snap['manifest']['generated_at'] ?? '?')
        . ' org-units=' . count($snap['org-units']) . ' schedules=' . count($snap['schedules']) . ' people=' . count($snap['people']) . "\n";
    foreach ($result['counts'] as $entity => $c) {
        $line = sprintf('  %-14s', $entity);
        foreach (['create', 'match', 'update', 'skip', 'error'] as $k) { $line .= sprintf(' %s=%d', $k, $c[$k] ?? 0); }
        if (!empty($c['codes'])) { ksort($c['codes']); $line .= '  [' . implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($c['codes']), $c['codes'])) . ']'; }
        echo $line, "\n";
    }
    echo "$mode";
    if ($result['applied']) { echo ": lote {$result['batch']} (para deshacer: php tools/wipe-import.php --batch {$result['batch']})"; }
    elseif ($opt['apply'] && !$result['changed']) { echo ': nada nuevo que cargar (ya importado)'; }
    elseif ($opt['apply']) { echo ": {$result['errors']} errores; revisar o repetir con --allow-errors"; }
    else { echo ': no se escribió nada; repetir con --apply'; }
    echo "\n";
}
exit($opt['apply'] && !$result['applied'] && $result['changed'] ? 1 : 0);
