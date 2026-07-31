<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';
require_once __DIR__ . '/../../src/Services/TierResolver.php';

use Keeper\Repos\AuditRepo;
use Keeper\Services\TierResolver;

// DOBLE CANDADO del control remoto:
//  1. RBAC: el rol debe tener el modulo de panel 'remote-control'.
//  2. Tier: la firma del equipo debe incluir el modulo de catalogo del comando.
if (!panelCan($adminUser, 'remote-control')) { http_response_code(403); exit('No autorizado'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: devices.php'); exit; }

$deviceId = (int)($_POST['device_id'] ?? 0);
$type     = (string)($_POST['command_type'] ?? '');
$back     = ($_POST['back'] ?? 'devices.php') === 'index.php' ? 'index.php' : 'devices.php';

// Comando -> modulo de catalogo requerido en el tier (rename_computer es op de IT, sin tier).
$moduleFor = [
    'lock'            => 'deviceLock',
    'shutdown'        => 'remoteShutdown',
    'restart'         => 'remoteShutdown',
    'logoff'          => 'remoteShutdown',
    'network_diag'    => 'networkDiagnostic',
    'screenshot_now'  => 'screenshots',
    'rename_computer' => null,
];

if ($deviceId > 0 && array_key_exists($type, $moduleFor)) {
    // Resolver el usuario/firma del equipo (para scope y para el gate de tier).
    $st = $pdo->prepare("SELECT user_id FROM keeper_devices WHERE id = :id LIMIT 1");
    $st->execute([':id' => $deviceId]);
    $ownerId = (int)($st->fetchColumn() ?: 0);
    $ok = $ownerId > 0;

    // Scope por firma: un admin de firma no comanda equipos ajenos.
    $firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
    if ($ok && $firmScope) {
        $chk = $pdo->prepare("SELECT 1 FROM keeper_user_assignments WHERE user_id = :u AND firma_id = :f LIMIT 1");
        $chk->execute([':u' => $ownerId, ':f' => (int)$firmScope]);
        $ok = (bool)$chk->fetchColumn();
    }

    // Candado de tier: la firma del equipo debe tener el modulo del comando.
    if ($ok && $moduleFor[$type] !== null && !TierResolver::userAllows($pdo, $ownerId, $moduleFor[$type])) {
        $ok = false;
    }

    // params por tipo.
    $params = null;
    if ($type === 'rename_computer') {
        $new = strtoupper(trim((string)($_POST['new_name'] ?? '')));
        if (!preg_match('/^[A-Z0-9\-]{1,15}$/', $new)) $ok = false;
        else $params = ['newName' => $new];
    } elseif (in_array($type, ['shutdown', 'restart'], true)) {
        // Ventana de gracia acotada (10..600s), 60 por defecto.
        $grace = (int)($_POST['grace_seconds'] ?? 60);
        $params = ['graceSeconds' => max(10, min(600, $grace))];
    }

    if ($ok) {
        // Caduca en 3 dias: un comando no recogido no se ejecuta semanas despues.
        $st = $pdo->prepare("
            INSERT INTO keeper_device_command (device_id, command_type, params_json, status, created_by, expires_at)
            VALUES (:d, :t, :p, 'pending', :by, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY))");
        $st->execute([
            ':d' => $deviceId, ':t' => $type,
            ':p' => $params !== null ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            ':by' => (int)$adminUser['admin_id'],
        ]);
        try {
            AuditRepo::log($pdo, (int)$adminUser['admin_id'], $ownerId, $deviceId, 'admin', 'command_enqueued',
                "Comando {$type} encolado", $params);
        } catch (\Throwable $e) { error_log('device-command audit: ' . $e->getMessage()); }
    }
}

header('Location: ' . $back);
exit;
