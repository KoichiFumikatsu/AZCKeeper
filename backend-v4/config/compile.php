<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Keeper\{Database, PolicyCompiler, Util};

try {
    $tenant = null; $device = null; $all = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--all') { $all = true; }
        elseif (str_starts_with($arg, '--tenant=') && $tenant === null) { $tenant = substr($arg, 9); }
        elseif (str_starts_with($arg, '--device=') && $device === null) { $device = substr($arg, 9); }
        else { throw new RuntimeException('Usage: php config/compile.php [--all | --tenant=UUID [--device=UUID] | --device=UUID]'); }
    }
    if ($all && ($tenant !== null || $device !== null)) { throw new RuntimeException('--all cannot be combined with selectors'); }
    $compiler = new PolicyCompiler(new Database());
    $results = $device !== null ? [$compiler->recompile($device, $tenant)] : ($tenant !== null ? $compiler->recompileForTenant($tenant) : $compiler->recompileAll());
    foreach ($results as $result) { echo Util::json($result) . "\n"; }
    echo Util::json(['compiled' => count($results), 'changed' => count(array_filter($results, static fn ($r) => $r['changed']))]) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof PDOException ? 'Policy compilation database failure' : $e->getMessage()) . "\n");
    exit(1);
}
