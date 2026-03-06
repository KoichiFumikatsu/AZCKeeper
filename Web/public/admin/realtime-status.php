<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\InputValidator;

header('Content-Type: application/json; charset=utf-8');

/**
 * realtime-status.php
 *
 * Retorna el estado en tiempo real de un usuario para el panel admin.
 * El estado (active/away/inactive/offline) lo calcula el handshake del cliente
 * cada 60s y lo guarda en keeper_devices.device_status.
 *
 * Este endpoint solo hace 2 SELECTs:
 *   1. keeper_users (verificar que existe)
 *   2. keeper_devices (last_seen_at + device_status + resumen del día)
 *
 * Ya no necesita consultar keeper_activity_day directamente —
 * el resumen del día lo lee desde keeper_devices.day_summary_json
 * que el handshake actualiza cada 60s.
 */

$userId = InputValidator::validateInt($_GET['user_id'] ?? 0, 0, 1);
if (!$userId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'User ID requerido']);
    exit;
}

$pdo = Keeper\Db::pdo();

// 1. Verificar usuario
$userCheck = $pdo->prepare("SELECT id FROM keeper_users WHERE id = ?");
$userCheck->execute([$userId]);
if (!$userCheck->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado']);
    exit;
}

// 2. Leer todos los devices activos del usuario con su estado pre-calculado
$st = $pdo->prepare("
    SELECT id, last_seen_at, device_status, day_summary_json
    FROM keeper_devices
    WHERE user_id = ? AND status = 'active'
    ORDER BY last_seen_at DESC
");
$st->execute([$userId]);
$devices = $st->fetchAll(PDO::FETCH_ASSOC);

if (empty($devices)) {
    echo json_encode(['ok' => true, 'status' => 'offline', 'lastSeen' => null, 'todayData' => null]);
    exit;
}

// Tomar el device más reciente
$primary = $devices[0];
$lastSeenTs = $primary['last_seen_at'] ? strtotime($primary['last_seen_at']) : 0;
$secondsSinceLastSeen = time() - $lastSeenTs;

// Si no hay heartbeat en 15 min -> offline (app cerrada o sin internet)
if ($secondsSinceLastSeen >= 900) {
    $finalStatus = 'offline';
} else {
    // El handshake ya calculó el estado (active/away/inactive)
    $finalStatus = $primary['device_status'] ?? 'inactive';
}

// Resumen del día: el handshake lo guardó como JSON en day_summary_json
$todayData = null;
if (!empty($primary['day_summary_json'])) {
    $todayData = json_decode($primary['day_summary_json'], true);
}

echo json_encode([
    'ok'                   => true,
    'status'               => $finalStatus,
    'lastSeen'             => $primary['last_seen_at'],
    'secondsSinceLastSeen' => $secondsSinceLastSeen,
    'todayData'            => $todayData,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
