<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use Keeper\{AdminApi,Database};

if (PHP_SAPI !== 'cli') { exit(1); }
$db=new Database(); $failed=false; $count=0;
foreach ($db->run('SELECT tenant_id FROM admin_policy_recompiles ORDER BY requested_at')->fetchAll() as $row) {
    try { AdminApi::compilePending($db,$row['tenant_id']); $count++; }
    catch (Throwable) { $failed=true; }
}
echo "Compiled pending tenants: $count\n";
exit($failed?1:0);
