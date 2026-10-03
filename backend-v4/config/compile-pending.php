<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Keeper\{Database,PolicyCompiler,Util};

if (PHP_SAPI !== 'cli') { exit(1); }
$db=new Database(); $failed=false; $count=0;
// Misma semantica que AdminApi::compilePending (compilar el tenant entero y borrar el pendiente solo si la
// revision no cambio), pero sin envolver el error: aqui el motivo se escribe en STDERR, una linea por tenant.
foreach ($db->run('SELECT tenant_id FROM admin_policy_recompiles ORDER BY requested_at')->fetchAll() as $row) {
    $tenant=$row['tenant_id'];
    try {
        $q=$db->one('SELECT revision FROM admin_policy_recompiles WHERE tenant_id=?',[$tenant]);
        if (!$q) { continue; }
        (new PolicyCompiler($db))->recompileForTenant(Util::id($tenant));
        $db->run('DELETE FROM admin_policy_recompiles WHERE tenant_id=? AND revision=?',[$tenant,$q['revision']]);
        $count++;
    }
    catch (Throwable $e) {
        $failed=true;
        // PolicyConflict ya lleva nivel, tipo, hash del objetivo, prioridad y asignacion/regla en su mensaje.
        fwrite(STDERR, bin2hex($tenant).' '.$e::class.': '.str_replace(["\r","\n"],' ',$e->getMessage())."\n");
    }
}
echo "Compiled pending tenants: $count\n";
exit($failed?1:0);
