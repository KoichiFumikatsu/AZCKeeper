<?php
declare(strict_types=1);
// Carga UNICA de la instantanea de AZC One legacy como datos locales y editables (sin fuente de datos ni bloqueos).
// Uso: php tools/import-one-snapshot.php --tenant <uuid> --confirm-tenant "<nombre exacto>" --dir <carpeta grupo-azc>
//                                        [--apply] [--allow-errors] [--json]
//   Exige el nombre exacto del cliente y que manifest.company sea su slug ("Grupo AZC" -> grupo-azc).
//   Sin --apply: dry-run (todo en una transaccion que se revierte; conteos exactos, nada se escribe).
//   --apply: una sola transaccion; deja un lote (import_batches, migracion 0047) para tools/wipe-import.php y una
//            fila de auditoria por entidad creada. Con errores por item se niega salvo --allow-errors.
// Lo que ya existia (por nombre o cedula) solo se vincula: nunca se cambia. Repetible: no duplica ni deshace ediciones.
// Salida: solo conteos y codigos, nunca nombres de personas, cedulas ni correos.
use Keeper\{Database, Util};
use Keeper\Tools\OneImport;

require dirname(__DIR__) . '/config/bootstrap.php';
require __DIR__ . '/OneImport.php';

if (PHP_SAPI !== 'cli') { exit(1); }
$usage = "Uso: php tools/import-one-snapshot.php --tenant <uuid> --confirm-tenant \"<nombre exacto>\" --dir <carpeta> [--apply] [--allow-errors] [--json]\n";
$opt = ['apply' => false, 'allow-errors' => false, 'json' => false];
for ($i = 1; $i < $argc; $i++) {
    $a = $argv[$i];
    if (preg_match('/^--(tenant|confirm-tenant|dir)(?:=(.*))?$/s', $a, $m)) { $opt[$m[1]] = $m[2] ?? ($argv[++$i] ?? ''); }
    elseif (in_array($a, ['--apply', '--allow-errors', '--json'], true)) { $opt[substr($a, 2)] = true; }
    elseif ($a === '--dry-run') { $opt['apply'] = false; }
    else { fwrite(STDERR, "Opción no reconocida: $a\n$usage"); exit(2); }
}
if (!preg_match('/^[0-9a-f]{8}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{4}-?[0-9a-f]{12}$/i', $opt['tenant'] ?? '') || ($opt['dir'] ?? '') === '' || ($opt['confirm-tenant'] ?? '') === '') { fwrite(STDERR, $usage); exit(2); }

try {
    $snap = OneImport::readSnapshot($opt['dir']);
    $db = new Database();
    $tenant = OneImport::guardTenant($db, Util::bin($opt['tenant']), $opt['confirm-tenant'], $snap['manifest']);
    if (!$opt['json']) {
        echo 'Cliente: ' . $tenant['name'] . ' (' . Util::id($tenant['tenant_id']) . ")\n";
        echo 'Instantánea: schema=' . $snap['manifest']['schema'] . ' company=' . $snap['manifest']['company'] . ' generated_at=' . ($snap['manifest']['generated_at'] ?? '?')
            . ' org-units=' . count($snap['org-units']) . ' schedules=' . count($snap['schedules']) . ' people=' . count($snap['people']) . "\n";
    }
    $result = (new OneImport($db))->import($tenant['tenant_id'], $snap, $opt['apply'], $opt['allow-errors']);
} catch (Throwable $e) {
    // Solo codigos: los errores propios (RuntimeException) los escribe esta herramienta; el resto, clase y codigo.
    $msg = $e instanceof PDOException ? 'database_error ' . ($e->errorInfo[1] ?? $e->getCode()) : ($e instanceof RuntimeException ? $e->getMessage() : $e::class . ' ' . $e->getCode());
    fwrite(STDERR, "ERROR: $msg\n");
    exit(1);
}
$mode = $result['applied'] ? 'APLICADO' : ($opt['apply'] ? 'NO APLICADO' : 'DRY-RUN');
if ($opt['json']) {
    echo Util::json(['mode' => $mode, 'tenant' => $tenant['name'], 'batch' => $result['batch'], 'errors' => $result['errors'], 'changed' => $result['changed'], 'counts' => $result['counts']]), "\n";
} else {
    foreach ($result['counts'] as $entity => $c) {
        $line = sprintf('  %-14s', $entity);
        foreach (['create', 'match', 'update', 'skip', 'error'] as $k) { $line .= sprintf(' %s=%d', $k, $c[$k] ?? 0); }
        if (!empty($c['codes'])) { ksort($c['codes']); $line .= '  [' . implode(' ', array_map(fn ($k, $v) => "$k:$v", array_keys($c['codes']), $c['codes'])) . ']'; }
        echo $line, "\n";
    }
    echo $mode;
    if ($result['applied']) { echo ": lote {$result['batch']} (deshacer: php tools/wipe-import.php --tenant " . Util::id($tenant['tenant_id']) . ' --confirm-tenant "' . $tenant['name'] . "\" --batch {$result['batch']})"; }
    elseif ($opt['apply'] && !$result['changed']) { echo ': nada nuevo que cargar (ya importado)'; }
    elseif ($opt['apply']) { echo ": {$result['errors']} errores; revisar o repetir con --allow-errors"; }
    else { echo ': no se escribió nada; repetir con --apply'; }
    echo "\n";
}
exit($opt['apply'] && !$result['applied'] && $result['changed'] ? 1 : 0);
