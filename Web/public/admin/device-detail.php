<?php

require_once __DIR__ . '/../../src/bootstrap.php';
 
$deviceId = (int)($_GET['id'] ?? 0);
if (!$deviceId) die('Device ID requerido');
 
$pdo = Keeper\Db::pdo();
 
// Obtener info del dispositivo
$device = $pdo->prepare("
    SELECT d.*, u.display_name, u.legacy_employee_id, u.email
    FROM keeper_devices d
    LEFT JOIN keeper_users u ON d.user_id = u.id
    WHERE d.id = ?
");
$device->execute([$deviceId]);
$device = $device->fetch(PDO::FETCH_ASSOC);
 
if (!$device) die('Dispositivo no encontrado');
 
// Verificar bloqueo activo
$lock = $pdo->prepare("SELECT * FROM keeper_device_locks WHERE device_id = ? AND is_active = 1");
$lock->execute([$deviceId]);
$lock = $lock->fetch(PDO::FETCH_ASSOC);
 
// Obtener política aplicada
$policy = $pdo->prepare("
    SELECT pa.*
    FROM keeper_policy_assignments pa
    WHERE pa.scope = 'device' AND pa.device_id = ? AND pa.is_active = 1
    ORDER BY pa.priority DESC
    LIMIT 1
");
$policy->execute([$deviceId]);
$policy = $policy->fetch(PDO::FETCH_ASSOC);
 
// Procesar acciones
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'lock') {
        $reason = $_POST['reason'] ?? 'Bloqueado por administrador';
        $pin = $_POST['pin'] ?? '123456';
        $pinHash = hash('sha256', $pin);
        
        $stmt = $pdo->prepare("
            INSERT INTO keeper_device_locks 
            (device_id, user_id, lock_reason, unlock_pin_hash, is_active)
            VALUES (?, ?, ?, ?, 1)
        ");
        $stmt->execute([$deviceId, $device['user_id'], $reason, $pinHash]);
        
        header('Location: device-detail.php?id=' . $deviceId . '&msg=locked');
        exit;
    }
    
    if ($action === 'unlock') {
        $stmt = $pdo->prepare("
            UPDATE keeper_device_locks 
            SET is_active = 0, unlocked_at = NOW()
            WHERE device_id = ? AND is_active = 1
        ");
        $stmt->execute([$deviceId]);
        
        header('Location: device-detail.php?id=' . $deviceId . '&msg=unlocked');
        exit;
    }
    
    if ($action === 'update_policy') {
        $policyJson = $_POST['policy_json'] ?? '{}';
        
        // Validar JSON
        json_decode($policyJson);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = 'JSON inválido: ' . json_last_error_msg();
        } else {
            // Desactivar políticas anteriores del dispositivo
            $pdo->prepare("
                UPDATE keeper_policy_assignments 
                SET is_active = 0 
                WHERE scope = 'device' AND device_id = ?
            ")->execute([$deviceId]);
            
            // Crear nueva política
            $stmt = $pdo->prepare("
                INSERT INTO keeper_policy_assignments 
                (scope, device_id, priority, policy_json, version, is_active)
                VALUES ('device', ?, 100, ?, 1, 1)
            ");
            $stmt->execute([$deviceId, $policyJson]);
            
            header('Location: device-detail.php?id=' . $deviceId . '&msg=policy_updated');
            exit;
        }
    }
}
 
$currentPolicy = $policy['policy_json'] ?? '{}';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dispositivo: <?= htmlspecialchars($device['device_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="devices.php">Dispositivos</a>
            <a href="policies.php">Políticas</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>Dispositivo: <?= htmlspecialchars($device['device_name']) ?></h1>
 
        <?php if (isset($_GET['msg'])): ?>
            <div class="alert alert-success">
                <?php
                    $messages = [
                        'locked' => '🔒 Dispositivo bloqueado correctamente',
                        'unlocked' => '✓ Dispositivo desbloqueado',
                        'policy_updated' => '✓ Política actualizada (se aplicará en próximo handshake)'
                    ];
                    echo $messages[$_GET['msg']] ?? 'Operación completada';
                ?>
            </div>
        <?php endif; ?>
 
        <?php if (isset($error)): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
 
        <div class="info-grid">
            <div class="info-card">
                <h3>Información del Usuario</h3>
                <p><strong>Nombre:</strong> <?= htmlspecialchars($device['display_name']) ?></p>
                <p><strong>CC:</strong> <?= htmlspecialchars($device['legacy_employee_id']) ?></p>
                <p><strong>Email:</strong> <?= htmlspecialchars($device['email'] ?? 'N/A') ?></p>
            </div>
 
            <div class="info-card">
                <h3>Información del Dispositivo</h3>
                <p><strong>GUID:</strong> <code><?= htmlspecialchars($device['device_guid']) ?></code></p>
                <p><strong>Estado:</strong> <span class="badge badge-<?= $device['status'] ?>"><?= $device['status'] ?></span></p>
                <p><strong>Última conexión:</strong> <?= $device['last_seen_at'] ?></p>
            </div>
        </div>
 
        <!-- SECCIÓN DE BLOQUEO -->
        <div class="section">
            <h2>🔒 Control de Bloqueo</h2>
 
            <?php if ($lock): ?>
                <div class="alert alert-warning">
                    <strong>⚠️ Dispositivo bloqueado</strong><br>
                    Motivo: <?= htmlspecialchars($lock['lock_reason']) ?><br>
                    Bloqueado: <?= $lock['locked_at'] ?>
                </div>
 
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="unlock">
                    <button type="submit" class="btn btn-success">Desbloquear Dispositivo</button>
                </form>
            <?php else: ?>
                <form method="POST" class="form">
                    <input type="hidden" name="action" value="lock">
                    
                    <div class="form-group">
                        <label>Motivo del bloqueo:</label>
                        <textarea name="reason" rows="3" class="form-control" required>Dispositivo bloqueado por política de seguridad.</textarea>
                    </div>
 
                    <div class="form-group">
                        <label>PIN de desbloqueo (para IT):</label>
                        <input type="text" name="pin" class="form-control" value="123456" required>
                        <small>Este PIN se requerirá para desbloquear desde el cliente.</small>
                    </div>
 
                    <button type="submit" class="btn btn-danger">🔒 Bloquear Dispositivo</button>
                </form>
            <?php endif; ?>
        </div>
 
        <!-- SECCIÓN DE POLÍTICA -->
        <div class="section">
            <h2>⚙️ Política de Dispositivo</h2>
            <p>Los cambios se aplicarán en el próximo handshake (máx. 5 minutos).</p>
 
            <form method="POST" class="form">
                <input type="hidden" name="action" value="update_policy">
                
                <div class="form-group">
                    <label>JSON de Política:</label>
                    <textarea name="policy_json" rows="20" class="form-control code"><?= htmlspecialchars(json_encode(json_decode($currentPolicy), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></textarea>
                </div>
 
                <button type="submit" class="btn btn-primary">💾 Guardar Política</button>
                <button type="button" class="btn btn-secondary" onclick="loadTemplate()">📋 Cargar Template</button>
            </form>
        </div>
    </div>
 
    <script>
    function loadTemplate() {
        const template = {
            "modules": {
                "enableActivityTracking": true,
                "enableWindowTracking": true,
                "enableBlocking": false
            },
            "startup": {
                "enableAutoStartup": true,
                "startMinimized": false
            },
            "updates": {
                "enableAutoUpdate": true,
                "checkIntervalMinutes": 60,
                "autoDownload": false
            },
            "blocking": {
                "enableDeviceLock": false,
                "lockMessage": "Equipo bloqueado por IT.",
                "allowUnlockWithPin": true,
                "unlockPin": "123456"
            }
        };
        
        document.querySelector('textarea[name="policy_json"]').value = JSON.stringify(template, null, 2);
    }
    </script>
</body>
</html>