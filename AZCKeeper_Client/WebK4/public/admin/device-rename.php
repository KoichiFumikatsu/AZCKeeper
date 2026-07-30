<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

// Renombrar equipos es acción de IT/superadmin (el módulo 'devices').
if (!panelCan($adminUser, 'devices')) { http_response_code(403); exit('No autorizado'); }
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Location: index.php'); exit; }

$deviceId = (int)($_POST['device_id'] ?? 0);
$label    = trim((string)($_POST['label'] ?? ''));
$label    = $label === '' ? null : mb_substr($label, 0, 190, 'UTF-8'); // vacío = limpiar (vuelve al nombre de máquina)

if ($deviceId > 0) {
    // Scope por firma: un firma-admin solo renombra equipos de SU firma (superadmin/IT global).
    $firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
    $ok = true;
    if ($firmScope) {
        $st = $pdo->prepare("
            SELECT 1 FROM keeper_devices d
            INNER JOIN keeper_user_assignments a ON a.user_id = d.user_id
            WHERE d.id = :id AND a.firma_id = :f LIMIT 1");
        $st->execute([':id'=>$deviceId, ':f'=>(int)$firmScope]);
        $ok = (bool)$st->fetchColumn();
    }
    if ($ok) {
        $pdo->prepare("UPDATE keeper_devices SET label = :l WHERE id = :id")
            ->execute([':l'=>$label, ':id'=>$deviceId]);
        try {
            AuditRepo::log($pdo, (int)$adminUser['admin_id'], null, $deviceId, 'admin', 'device_renamed',
                'Equipo renombrado desde el panel', ['label' => $label]);
        } catch (\Throwable $e) { error_log('device-rename audit: '.$e->getMessage()); }
    }
}

$back = $_POST['back'] ?? 'index.php';
if (!preg_match('#^[a-z0-9_\-]+\.php#i', (string)$back)) $back = 'index.php'; // solo páginas locales
header('Location: ' . $back);
exit;
