<?php
declare(strict_types=1);
// Backfill de suscripciones: asigna el tier por defecto (tier.is_default) a todo user activo
// que no tenga una suscripción vigente. Idempotente: sólo inserta donde falta.
// Uso (CLI): php tools/backfill-subscriptions.php [--dry-run]
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
require dirname(__DIR__) . '/config/bootstrap.php';
use Keeper\Util; use Keeper\Database;
$dry = in_array('--dry-run', $argv, true);
$db = new Database();
$total = 0;
foreach ($db->run("SELECT tenant_id FROM tenants WHERE status='active' ORDER BY tenant_id")->fetchAll() as $t) {
    $tenant = $t['tenant_id'];
    $default = $db->one("SELECT id FROM tier WHERE tenant_id=? AND is_default=1 AND active=TRUE", [$tenant]);
    if (!$default) { echo "tenant " . Util::id($tenant) . ": SIN tier default -> omitido\n"; continue; }
    $users = $db->run(
        "SELECT u.id FROM users u WHERE u.tenant_id=? AND u.status='active'
         AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.tenant_id=u.tenant_id AND s.user_id=u.id
           AND s.status IN ('active','scheduled') AND s.starts_at<=UTC_TIMESTAMP(6)
           AND (s.ends_at IS NULL OR s.ends_at>UTC_TIMESTAMP(6)))",
        [$tenant]
    )->fetchAll();
    if (!$dry) {
        foreach ($users as $u) {
            $db->run("INSERT INTO subscriptions (tenant_id,id,user_id,tier_id,starts_at,status) VALUES (?,?,?,?,UTC_TIMESTAMP(6),'active')",
                [$tenant, Util::bin(Util::uuid()), $u['id'], $default['id']]);
        }
    }
    $total += count($users);
    echo "tenant " . Util::id($tenant) . ": " . count($users) . ($dry ? " pendientes (dry-run)" : " asignados") . "\n";
}
echo ($dry ? "TOTAL pendientes: " : "TOTAL asignados: ") . $total . "\n";
