<?php
require_once __DIR__ . '/../../src/bootstrap.php';
 
$deviceId = (int)($_GET['id'] ?? 0);
if (!$deviceId) die('Device ID requerido');
 
$pdo = Keeper\Db::pdo();
 
// Obtener dispositivo
$device = $pdo->prepare("SELECT d.*, u.display_name FROM keeper_devices d LEFT JOIN keeper_users u ON d.user_id = u.id WHERE d.id = ?");
$device->execute([$deviceId]);
$device = $device->fetch(PDO::FETCH_ASSOC);
if (!$device) die('Dispositivo no encontrado');
 
// Obtener política actual
$policy = $pdo->prepare("SELECT * FROM keeper_policy_assignments WHERE scope='device' AND device_id=? AND is_active=1 ORDER BY priority DESC LIMIT 1");
$policy->execute([$deviceId]);
$policy = $policy->fetch(PDO::FETCH_ASSOC);
 
// Configuración por defecto
$defaultConfig = [
    'logging' => [
        'globalLevel' => 'Info',
        'clientOverrideLevel' => null,
        'enableFileLogging' => true,
        'enableDiscordLogging' => false,
        'discordWebhookUrl' => null
    ],
    'modules' => [
        'enableActivityTracking' => true,
        'enableWindowTracking' => true,
        'enableProcessTracking' => true,
        'enableBlocking' => true,
        'enableKeyboardHook' => false,
        'enableMouseHook' => false,
        'enableUpdateManager' => true,
        'enableDebugWindow' => true,
        'enableCallTracking' => true,
        'countCallsAsActive' => true,
        'callActiveMaxIdleSeconds' => 1800,
        'activityIntervalSeconds' => 1,
        'activityInactivityThresholdSeconds' => 15,
        'windowTrackingIntervalSeconds' => 2,
        'callProcessKeywords' => ['zoom', 'teams', 'skype'],
        'callTitleKeywords' => ['meeting', 'call']
    ],
    'startup' => [
        'enableAutoStartup' => true,
        'startMinimized' => false
    ],
    'updates' => [
        'enableAutoUpdate' => true,
        'checkIntervalMinutes' => 60,
        'autoDownload' => false,
        'allowBetaVersions' => false
    ],
    'blocking' => [
        'enableDeviceLock' => false,
        'lockMessage' => 'Este equipo ha sido bloqueado por IT.\n\nContacta al administrador.',
        'allowUnlockWithPin' => true,
        'unlockPin' => null
    ]
];
 
// Mergear con política existente (si existe)
if ($policy) {
    $savedConfig = json_decode($policy['policy_json'], true);
    $config = array_replace_recursive($defaultConfig, $savedConfig ?: []);
} else {
    $config = $defaultConfig;
}
 
// Helper para acceso seguro
function getConfig($array, $key, $default = null) {
    return $array[$key] ?? $default;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newConfig = [
        'logging' => [
            'globalLevel' => $_POST['log_level'] ?? 'Info',
            'enableFileLogging' => isset($_POST['log_file']),
            'enableDiscordLogging' => isset($_POST['log_discord']),
            'discordWebhookUrl' => $_POST['discord_webhook'] ?? null
        ],
        'modules' => [
            'enableActivityTracking' => isset($_POST['mod_activity']),
            'enableWindowTracking' => isset($_POST['mod_window']),
            'enableBlocking' => isset($_POST['mod_blocking']),
            'enableUpdateManager' => isset($_POST['mod_updates']),
            'enableDebugWindow' => isset($_POST['mod_debug']),
            'activityIntervalSeconds' => (float)($_POST['activity_interval'] ?? 1),
            'windowTrackingIntervalSeconds' => (float)($_POST['window_interval'] ?? 2)
        ],
        'startup' => [
            'enableAutoStartup' => isset($_POST['startup_auto']),
            'startMinimized' => isset($_POST['startup_minimized'])
        ],
        'blocking' => [
            'enableDeviceLock' => isset($_POST['lock_enable']),
            'lockMessage' => $_POST['lock_message'] ?? 'Bloqueado',
            'allowUnlockWithPin' => isset($_POST['lock_allow_pin']),
            'unlockPin' => $_POST['lock_pin'] ?? null
        ]
    ];
    
    $pdo->prepare("UPDATE keeper_policy_assignments SET is_active=0 WHERE scope='device' AND device_id=?")->execute([$deviceId]);
    $stmt = $pdo->prepare("INSERT INTO keeper_policy_assignments (scope, device_id, priority, policy_json, version, is_active) VALUES ('device', ?, 100, ?, 1, 1)");
    $stmt->execute([$deviceId, json_encode($newConfig)]);
    
    header('Location: device-config.php?id=' . $deviceId . '&msg=saved');
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Configurar: <?= htmlspecialchars($device['device_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
    .config-section { background: white; padding: 2rem; margin-bottom: 1rem; border-radius: 8px; }
    .config-section h3 { color: #3498db; margin-bottom: 1.5rem; border-bottom: 2px solid #eee; padding-bottom: 0.5rem; }
    .form-row { display: flex; gap: 2rem; margin-bottom: 1.5rem; align-items: center; }
    .form-row label { flex: 1; font-weight: 600; }
    .form-row input[type="checkbox"] { width: 20px; height: 20px; }
    .form-row input[type="number"], .form-row input[type="text"], .form-row select { flex: 2; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; }
    .radio-group { display: flex; gap: 1rem; flex: 2; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="devices.php">Dispositivos</a>
            <a href="device-config.php?id=<?= $deviceId ?>" class="active">Configurar</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>⚙️ Configurar: <?= htmlspecialchars($device['device_name']) ?></h1>
        <p>Usuario: <strong><?= htmlspecialchars($device['display_name']) ?></strong></p>
 
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
            <div class="alert alert-success">✓ Configuración guardada. Se aplicará en próximo handshake (máx 5 min)</div>
        <?php endif; ?>
 
        <form method="POST"><!-- LOGGING -->
            <div class="config-section">
                <h3>📋 Logs</h3>
                <div class="form-row">
                    <label>Nivel de log:</label>
                    <select name="log_level">
                        <?php
                        $logLevel = getConfig($config['logging'] ?? [], 'globalLevel', 'Info');
                        foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level):
                        ?>
                            <option <?= $logLevel === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-row">
                    <label>Guardar logs en archivo:</label>
                    <input type="checkbox" name="log_file" <?= getConfig($config['logging'] ?? [], 'enableFileLogging', true) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Logs a Discord:</label>
                    <input type="checkbox" name="log_discord" <?= getConfig($config['logging'] ?? [], 'enableDiscordLogging', false) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Discord Webhook URL:</label>
                    <input type="text" name="discord_webhook" value="<?= htmlspecialchars(getConfig($config['logging'] ?? [], 'discordWebhookUrl', '') ?: '') ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
            </div>
             
            <!-- MÓDULOS -->
            <div class="config-section">
                <h3>🔧 Módulos</h3>
                <div class="form-row">
                    <label>Activity Tracking:</label>
                    <input type="checkbox" name="mod_activity" <?= getConfig($config['modules'] ?? [], 'enableActivityTracking', true) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Window Tracking:</label>
                    <input type="checkbox" name="mod_window" <?= getConfig($config['modules'] ?? [], 'enableWindowTracking', true) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Bloqueo remoto (módulo):</label>
                    <input type="checkbox" name="mod_blocking" <?= getConfig($config['modules'] ?? [], 'enableBlocking', false) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Auto-actualización:</label>
                    <input type="checkbox" name="mod_updates" <?= getConfig($config['modules'] ?? [], 'enableUpdateManager', true) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Ventana de debug:</label>
                    <input type="checkbox" name="mod_debug" <?= getConfig($config['modules'] ?? [], 'enableDebugWindow', false) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Intervalo Activity (segundos):</label>
                    <input type="number" name="activity_interval" value="<?= getConfig($config['modules'] ?? [], 'activityIntervalSeconds', 1) ?>" step="0.1" min="0.1">
                </div>
                <div class="form-row">
                    <label>Intervalo Window (segundos):</label>
                    <input type="number" name="window_interval" value="<?= getConfig($config['modules'] ?? [], 'windowTrackingIntervalSeconds', 2) ?>" step="0.1" min="0.1">
                </div>
            </div>
             
            <!-- STARTUP -->
            <div class="config-section">
                <h3>🚀 Inicio</h3>
                <div class="form-row">
                    <label>Iniciar con Windows:</label>
                    <input type="checkbox" name="startup_auto" <?= getConfig($config['startup'] ?? [], 'enableAutoStartup', true) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Iniciar minimizado:</label>
                    <input type="checkbox" name="startup_minimized" <?= getConfig($config['startup'] ?? [], 'startMinimized', false) ? 'checked' : '' ?>>
                </div>
            </div>
             
            <!-- BLOQUEO -->
            <div class="config-section">
                <h3>🔒 Bloqueo Remoto</h3>
                <div class="alert alert-warning" style="margin-bottom: 1rem;">
                    ⚠️ <strong>ADVERTENCIA:</strong> Al activar "BLOQUEAR ESTE EQUIPO", el dispositivo se bloqueará completamente en el próximo handshake (máx 5 minutos).
                </div>
                <div class="form-row">
                    <label style="color: #e74c3c; font-weight: bold;">🔒 BLOQUEAR ESTE EQUIPO:</label>
                    <input type="checkbox" name="lock_enable" <?= getConfig($config['blocking'] ?? [], 'enableDeviceLock', false) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>Mensaje de bloqueo:</label>
                    <textarea name="lock_message" rows="3" style="flex: 2; padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px;"><?= htmlspecialchars(getConfig($config['blocking'] ?? [], 'lockMessage', 'Bloqueado por IT')) ?></textarea>
                </div>
                <div class="form-row">
                    <label>Permitir desbloqueo con PIN:</label>
                    <input type="checkbox" name="lock_allow_pin" <?= getConfig($config['blocking'] ?? [], 'allowUnlockWithPin', true) ? 'checked' : '' ?>>
                </div>
                <div class="form-row">
                    <label>PIN de desbloqueo:</label>
                    <input type="text" name="lock_pin" placeholder="Dejar vacío para no cambiar" autocomplete="off">
                    <small style="color: #666;">Solo llenar si deseas cambiar el PIN actual</small>
                </div>
            </div>
 
            <button type="submit" class="btn btn-primary" style="font-size: 1.2rem; padding: 1rem 2rem;">💾 GUARDAR CONFIGURACIÓN</button>
        </form>
    </div>
</body>
</html>