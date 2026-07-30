<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

// Encolar comandos a un equipo es acción de IT/superadmin.
if (!panelCan($adminUser, 'devices')) { http_response_code(403); exit('No autorizado'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: devices.php'); exit; }

$deviceId = (int)($_POST['device_id'] ?? 0);
$type     = (string)($_POST['command_type'] ?? '');
$allowed  = ['rename_computer','shutdown','network_diag','screenshot_now'];

if ($deviceId > 0 && in_array($type, $allowed, true)) {
    // Scope por firma.
    $firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
    $ok = true;
    if ($firmScope) {
        $st = $pdo->prepare("SELECT 1 FROM keeper_devices d INNER JOIN keeper_user_assignments a ON a.user_id=d.user_id WHERE d.id=:id AND a.firma_id=:f LIMIT 1");
        $st->execute([':id'=>$deviceId, ':f'=>(int)$firmScope]);
        $ok = (bool)$st->fetchColumn();
    }

    // params por tipo. rename_computer valida el nombre NetBIOS (<=15, sin caracteres ilegales).
    $params = null;
    if ($type === 'rename_computer') {
        $new = strtoupper(trim((string)($_POST['new_name'] ?? '')));
        if (!preg_match('/^[A-Z0-9\-]{1,15}$/', $new)) { $ok = false; }
        else $params = ['newName' => $new];
    }

    if ($ok) {
        // El comando caduca en 3 días: un apagado/renombre no recogido no se ejecuta semanas después.
        $st = $pdo->prepare("
            INSERT INTO keeper_device_command (device_id, command_type, params_json, status, created_by, expires_at)
            VALUES (:d, :t, :p, 'pending', :by, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 3 DAY))");
        $st->execute([
            ':d'=>$deviceId, ':t'=>$type,
            ':p'=>$params!==null ? json_encode($params, JSON_UNESCAPED_UNICODE) : null,
            ':by'=>(int)$adminUser['admin_id'],
        ]);
        try {
            AuditRepo::log($pdo, (int)$adminUser['admin_id'], null, $deviceId, 'admin', 'command_enqueued',
                "Comando {$type} encolado", $params);
        } catch (\Throwable $e) { error_log('device-command audit: '.$e->getMessage()); }
    }
}

header('Location: devices.php');
exit;
