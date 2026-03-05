<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\InputValidator;

header('Content-Type: application/json; charset=utf-8');

$userId = InputValidator::validateInt($_GET['user_id'] ?? 0, 0, 1);
if (!$userId) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'User ID requerido']);
    exit;
}

$pdo = Keeper\Db::pdo();

// Verificar que el usuario existe
$userCheck = $pdo->prepare("SELECT id FROM keeper_users WHERE id = ?");
$userCheck->execute([$userId]);
if (!$userCheck->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Usuario no encontrado']);
    exit;
}

// Obtener dispositivos del usuario
$devices = $pdo->prepare("SELECT id, last_seen_at FROM keeper_devices WHERE user_id = ? AND status = 'active'");
$devices->execute([$userId]);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);

if (empty($devices)) {
    echo json_encode([
        'ok' => true,
        'status' => 'offline',
        'lastSeen' => null,
        'todayData' => null
    ]);
    exit;
}

$deviceIds = array_column($devices, 'id');
$deviceIdsStr = implode(',', $deviceIds);

// Determinar estado basado en last_seen_at (heartbeat del dispositivo)
$lastSeenTimes = array_column($devices, 'last_seen_at');
$mostRecentSeen = max(array_map('strtotime', array_filter($lastSeenTimes)));
$nowTimestamp = time();
$secondsSinceLastSeen = $nowTimestamp - $mostRecentSeen;

// Obtener datos de actividad del día actual para determinar si hay actividad reciente
$today = date('Y-m-d');
$todayData = $pdo->query("
    SELECT 
        SUM(active_seconds) as active_seconds,
        SUM(idle_seconds) as idle_seconds,
        SUM(call_seconds) as call_seconds,
        SUM(work_hours_active_seconds) as work_active_seconds,
        SUM(work_hours_idle_seconds) as work_idle_seconds,
        SUM(lunch_active_seconds) as lunch_active_seconds,
        SUM(lunch_idle_seconds) as lunch_idle_seconds,
        SUM(after_hours_active_seconds) as after_active_seconds,
        SUM(after_hours_idle_seconds) as after_idle_seconds,
        MAX(last_event_at) as last_event_at
    FROM keeper_activity_day
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date = '{$today}'
")->fetch(PDO::FETCH_ASSOC);

// Si no hay datos de hoy, retornar ceros
if (!$todayData || $todayData['active_seconds'] === null) {
    $todayData = [
        'active_seconds' => 0,
        'idle_seconds' => 0,
        'call_seconds' => 0,
        'work_active_seconds' => 0,
        'work_idle_seconds' => 0,
        'lunch_active_seconds' => 0,
        'lunch_idle_seconds' => 0,
        'after_active_seconds' => 0,
        'after_idle_seconds' => 0,
        'last_event_at' => null
    ];
} else {
    // Convertir a enteros
    $todayData = array_map(function($v) {
        return is_numeric($v) ? (int)$v : $v;
    }, $todayData);
}

// Determinar estado:
// 1. Sin conexión: dispositivo sin heartbeat >15 min O (sin actividad hoy Y sin heartbeat muy reciente)
// 2. Activo: heartbeat reciente Y última actividad <2min
// 3. Away/Ausente: heartbeat reciente Y (sin actividad reciente O inactivo) = timer corriendo

if ($secondsSinceLastSeen >= 900) {
    // >15 min sin heartbeat = dispositivo desconectado (PC apagado, sin internet, app cerrada)
    $status = 'inactive';
} else {
    // Dispositivo conectado (tiene heartbeat < 15min)
    $lastEventAt = $todayData['last_event_at'];
    
    // Si no hay actividad registrada hoy
    if (!$lastEventAt || $todayData['active_seconds'] == 0) {
        // Si el heartbeat es MUY reciente (<2min), significa que acaba de iniciar o está iniciando
        // De lo contrario, probablemente la app estuvo cerrada y solo envió un handshake viejo
        if ($secondsSinceLastSeen < 120) {
            $status = 'away';  // App activa pero sin actividad todavía
        } else {
            $status = 'inactive';  // No ha trabajado hoy y heartbeat no tan reciente = app cerrada
        }
    } else {
        // Hay actividad registrada hoy, verificar qué tan reciente
        $secondsSinceLastEvent = $nowTimestamp - strtotime($lastEventAt);
        
        if ($secondsSinceLastEvent < 120) {
            // Actividad reciente <2 min
            $status = 'active';
        } else {
            // Sin actividad reciente pero app conectada = ausente (timer de inactividad corriendo)
            $status = 'away';
        }
    }
}

echo json_encode([
    'ok' => true,
    'status' => $status,
    'lastSeen' => date('Y-m-d H:i:s', $mostRecentSeen),
    'secondsSinceLastSeen' => $secondsSinceLastSeen,
    'todayData' => $todayData
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
