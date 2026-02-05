<?php
require_once __DIR__ . '/../../src/bootstrap.php';
 
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) die('User ID requerido');
 
$pdo = Keeper\Db::pdo();
 
// Obtener usuario
$user = $pdo->prepare("SELECT id, cc, display_name, email, status FROM keeper_users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch(PDO::FETCH_ASSOC);
if (!$user) die('Usuario no encontrado');
 
// Dispositivos del usuario
$devices = $pdo->prepare("SELECT * FROM keeper_devices WHERE user_id = ? ORDER BY last_seen_at DESC");
$devices->execute([$userId]);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);
 
// Obtener política del usuario
$policy = $pdo->prepare("SELECT * FROM keeper_policy_assignments WHERE scope='user' AND user_id=? AND is_active=1 ORDER BY priority DESC LIMIT 1");
$policy->execute([$userId]);
$policy = $policy->fetch(PDO::FETCH_ASSOC);
 
// Obtener política global para mostrar "Usando global"
$globalPolicy = $pdo->query("SELECT * FROM keeper_policy_assignments WHERE scope='global' AND is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
 
// Default config
$defaultConfig = [
    'logging' => ['globalLevel' => 'Info', 'clientOverrideLevel' => null, 'enableFileLogging' => true, 'enableDiscordLogging' => false, 'discordWebhookUrl' => null],
    'modules' => [
        'enableActivityTracking' => true, 'enableWindowTracking' => true, 'enableProcessTracking' => false,
        'enableBlocking' => false, 'enableKeyboardHook' => false, 'enableMouseHook' => false,
        'enableUpdateManager' => true, 'enableDebugWindow' => false, 'enableCallTracking' => false,
        'countCallsAsActive' => false, 'callActiveMaxIdleSeconds' => 1800,
        'activityIntervalSeconds' => 1, 'activityInactivityThresholdSeconds' => 15,
        'windowTrackingIntervalSeconds' => 2,
        'callProcessKeywords' => ['zoom', 'teams', 'skype'], 'callTitleKeywords' => ['meeting', 'call']
    ],
    'startup' => ['enableAutoStartup' => true, 'startMinimized' => false],
    'updates' => ['enableAutoUpdate' => true, 'checkIntervalMinutes' => 60, 'autoDownload' => false, 'allowBetaVersions' => false],
    'blocking' => ['enableDeviceLock' => false, 'lockMessage' => 'Bloqueado por IT', 'allowUnlockWithPin' => true, 'unlockPin' => null],
    'timers' => ['activityFlushIntervalSeconds' => 6, 'handshakeIntervalMinutes' => 5, 'offlineQueueRetrySeconds' => 30]
];
 
$usingGlobal = !$policy;
 
if ($policy) {
    $savedConfig = json_decode($policy['policy_json'], true);
    $config = array_replace_recursive($defaultConfig, $savedConfig ?: []);
} elseif ($globalPolicy) {
    $globalConfig = json_decode($globalPolicy['policy_json'], true);
    $config = array_replace_recursive($defaultConfig, $globalConfig ?: []);
} else {
    $config = $defaultConfig;
}
 
function getConfig($array, $key, $default = null) {
    return $array[$key] ?? $default;
}
 
// PROCESAR FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    
    if ($action === 'reset') {
        // RESETEAR A GLOBAL: Desactivar política personalizada
        $pdo->prepare("UPDATE keeper_policy_assignments SET is_active=0 WHERE scope='user' AND user_id=?")->execute([$userId]);
        header('Location: user-config.php?id=' . $userId . '&msg=reset');
        exit;
    }
    
    if ($action === 'save') {
        $newConfig = [
            'logging' => [
                'globalLevel' => $_POST['log_level'] ?? 'Info',
                'clientOverrideLevel' => $_POST['log_override'] ?: null,
                'enableFileLogging' => isset($_POST['log_file']),
                'enableDiscordLogging' => isset($_POST['log_discord']),
                'discordWebhookUrl' => $_POST['discord_webhook'] ?: null
            ],
            'modules' => [
                'enableActivityTracking' => isset($_POST['mod_activity']),
                'enableWindowTracking' => isset($_POST['mod_window']),
                'enableProcessTracking' => isset($_POST['mod_process']),
                'enableBlocking' => isset($_POST['mod_blocking']),
                'enableKeyboardHook' => isset($_POST['mod_keyboard']),
                'enableMouseHook' => isset($_POST['mod_mouse']),
                'enableUpdateManager' => isset($_POST['mod_updates']),
                'enableDebugWindow' => isset($_POST['mod_debug']),
                'enableCallTracking' => isset($_POST['mod_call_tracking']),
                'countCallsAsActive' => isset($_POST['mod_count_calls']),
                'callActiveMaxIdleSeconds' => (float)($_POST['call_max_idle'] ?? 1800),
                'activityIntervalSeconds' => (float)($_POST['activity_interval'] ?? 1),
                'activityInactivityThresholdSeconds' => (float)($_POST['activity_threshold'] ?? 15),
                'windowTrackingIntervalSeconds' => (float)($_POST['window_interval'] ?? 2),
                'callProcessKeywords' => array_filter(array_map('trim', explode(',', $_POST['call_processes'] ?? ''))),
                'callTitleKeywords' => array_filter(array_map('trim', explode(',', $_POST['call_titles'] ?? '')))
            ],
            'startup' => [
                'enableAutoStartup' => isset($_POST['startup_auto']),
                'startMinimized' => isset($_POST['startup_minimized'])
            ],
            'updates' => [
                'enableAutoUpdate' => isset($_POST['updates_auto']),
                'checkIntervalMinutes' => (int)($_POST['updates_interval'] ?? 60),
                'autoDownload' => isset($_POST['updates_auto_download']),
                'allowBetaVersions' => isset($_POST['updates_beta'])
            ],
            'blocking' => [
                'enableDeviceLock' => isset($_POST['lock_enable']),
                'lockMessage' => $_POST['lock_message'] ?? 'Bloqueado',
                'allowUnlockWithPin' => isset($_POST['lock_allow_pin']),
                'unlockPin' => $_POST['lock_pin'] ?: null
            ],
            'timers' => [
                'activityFlushIntervalSeconds' => (int)($_POST['timer_flush'] ?? 6),
                'handshakeIntervalMinutes' => (int)($_POST['timer_handshake'] ?? 5),
                'offlineQueueRetrySeconds' => (int)($_POST['timer_retry'] ?? 30)
            ]
        ];
        
        // Desactivar políticas anteriores
        $pdo->prepare("UPDATE keeper_policy_assignments SET is_active=0 WHERE scope='user' AND user_id=?")->execute([$userId]);
        
        // Crear nueva política
        $stmt = $pdo->prepare("INSERT INTO keeper_policy_assignments (scope, user_id, device_id, priority, policy_json, version, is_active) VALUES ('user', ?, NULL, 50, ?, 1, 1)");
        $stmt->execute([$userId, json_encode($newConfig)]);
        
        header('Location: user-config.php?id=' . $userId . '&msg=saved');
        exit;
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Configurar: <?= htmlspecialchars($user['display_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
    .config-section { background: white; padding: 2rem; margin-bottom: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .config-section h3 { color: #3498db; margin-bottom: 1.5rem; border-bottom: 2px solid #eee; padding-bottom: 0.5rem; }
    .form-row { display: grid; grid-template-columns: 250px 1fr; gap: 1rem; margin-bottom: 1rem; align-items: center; }
    .form-row label { font-weight: 600; }
    .form-row input[type="checkbox"] { width: 20px; height: 20px; justify-self: start; }
    .form-row input[type="number"], .form-row input[type="text"], .form-row select, .form-row textarea { 
        padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; width: 100%;
    }
    .form-row small { grid-column: 2; color: #666; margin-top: -0.5rem; }
    .device-list { background: #f8f9fa; padding: 1rem; border-radius: 4px; margin-top: 1rem; }
    .device-item { padding: 0.5rem; border-bottom: 1px solid #ddd; }
    .policy-status { padding: 1rem; border-radius: 4px; margin-bottom: 1rem; font-weight: 600; }
    .policy-custom { background: #d4edda; color: #155724; }
    .policy-global { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="users.php">Usuarios</a>
            <a href="user-config.php?id=<?= $userId ?>" class="active">Configurar</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>⚙️ Configurar Usuario</h1>
        
        <div class="info-card" style="background: white; padding: 1.5rem; border-radius: 8px; margin-bottom: 1rem;">
            <h3 style="margin-bottom: 1rem; color: #3498db;">Información del Usuario</h3>
            <p><strong>CC (Cédula):</strong> <?= htmlspecialchars($user['cc'] ?? 'N/A') ?></p>
            <p><strong>Nombre:</strong> <?= htmlspecialchars($user['display_name']) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($user['email'] ?? 'N/A') ?></p>
            
            <div class="device-list">
                <strong>Dispositivos registrados (<?= count($devices) ?>):</strong>
                <?php if (empty($devices)): ?>
                    <p style="color: #666; margin-top: 0.5rem;">Sin dispositivos conectados</p>
                <?php else: ?>
                    <?php foreach ($devices as $dev): ?>
                        <div class="device-item">
                            🖥️ <?= htmlspecialchars($dev['device_name']) ?> 
                            <small style="color: #666;">(Última vez: <?= htmlspecialchars($dev['last_seen_at']) ?>)</small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
 
        <?php if ($usingGlobal): ?>
            <div class="policy-status policy-global">
                ℹ️ Este usuario está usando la <strong>política global</strong>. Los valores mostrados son los de la configuración global actual.
            </div>
        <?php else: ?>
            <div class="policy-status policy-custom">
                ✓ Este usuario tiene una <strong>política personalizada</strong>.
            </div>
        <?php endif; ?>
 
        <?php if (isset($_GET['msg'])): ?>
            <?php if ($_GET['msg'] === 'saved'): ?>
                <div class="alert alert-success">✓ Configuración guardada. Se aplicará en próximo handshake (máx 5 min)</div>
            <?php elseif ($_GET['msg'] === 'reset'): ?>
                <div class="alert alert-success">✓ Política personalizada eliminada. Ahora usa la configuración global.</div>
            <?php endif; ?>
        <?php endif; ?>
 
        <form method="POST">
            <input type="hidden" name="action" value="save">
            
            <!-- ========== LOGGING ========== -->
            <div class="config-section">
                <h3>📋 Configuración de Logs</h3>
                
                <div class="form-row">
                    <label>Nivel de log:</label>
                    <select name="log_level">
                        <?php foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level): ?>
                            <option <?= getConfig($config['logging'], 'globalLevel', 'Info') === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
 
                <div class="form-row">
                    <label>Override de nivel:</label>
                    <select name="log_override">
                        <option value="">-- Sin override --</option>
                        <?php foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level): ?>
                            <option <?= getConfig($config['logging'], 'clientOverrideLevel') === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
 
                <div class="form-row">
                    <label>Guardar en archivo:</label>
                    <input type="checkbox" name="log_file" <?= getConfig($config['logging'], 'enableFileLogging', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Enviar a Discord:</label>
                    <input type="checkbox" name="log_discord" <?= getConfig($config['logging'], 'enableDiscordLogging', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Discord Webhook:</label>
                    <input type="text" name="discord_webhook" value="<?= htmlspecialchars(getConfig($config['logging'], 'discordWebhookUrl', '') ?: '') ?>">
                </div>
            </div>
 
            <!-- ========== MÓDULOS ========== -->
            <div class="config-section">
                <h3>🔧 Módulos y Funcionalidades</h3>
                
                <div class="form-row">
                    <label>Activity Tracking:</label>
                    <input type="checkbox" name="mod_activity" <?= getConfig($config['modules'], 'enableActivityTracking', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Window Tracking:</label>
                    <input type="checkbox" name="mod_window" <?= getConfig($config['modules'], 'enableWindowTracking', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Process Tracking:</label>
                    <input type="checkbox" name="mod_process" <?= getConfig($config['modules'], 'enableProcessTracking', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Módulo de Bloqueo:</label>
                    <input type="checkbox" name="mod_blocking" <?= getConfig($config['modules'], 'enableBlocking', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Keyboard Hook:</label>
                    <input type="checkbox" name="mod_keyboard" <?= getConfig($config['modules'], 'enableKeyboardHook', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Mouse Hook:</label>
                    <input type="checkbox" name="mod_mouse" <?= getConfig($config['modules'], 'enableMouseHook', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Update Manager:</label>
                    <input type="checkbox" name="mod_updates" <?= getConfig($config['modules'], 'enableUpdateManager', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Debug Window:</label>
                    <input type="checkbox" name="mod_debug" <?= getConfig($config['modules'], 'enableDebugWindow', false) ? 'checked' : '' ?>>
                </div>
 
                <h4 style="margin-top: 2rem; color: #2c3e50;">Activity Tracking</h4>
 
                <div class="form-row">
                    <label>Intervalo (seg):</label>
                    <input type="number" name="activity_interval" value="<?= getConfig($config['modules'], 'activityIntervalSeconds', 1) ?>" step="0.1" min="0.1">
                </div>
 
                <div class="form-row">
                    <label>Umbral inactividad (seg):</label>
                    <input type="number" name="activity_threshold" value="<?= getConfig($config['modules'], 'activityInactivityThresholdSeconds', 15) ?>" min="1">
                </div>
 
                <h4 style="margin-top: 2rem; color: #2c3e50;">Window Tracking</h4>
 
                <div class="form-row">
                    <label>Intervalo (seg):</label>
                    <input type="number" name="window_interval" value="<?= getConfig($config['modules'], 'windowTrackingIntervalSeconds', 2) ?>" step="0.1" min="0.1">
                </div>
 
                <h4 style="margin-top: 2rem; color: #2c3e50;">Call Tracking</h4>
 
                <div class="form-row">
                    <label>Habilitar:</label>
                    <input type="checkbox" name="mod_call_tracking" <?= getConfig($config['modules'], 'enableCallTracking', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Contar como activo:</label>
                    <input type="checkbox" name="mod_count_calls" <?= getConfig($config['modules'], 'countCallsAsActive', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Max idle en llamada (seg):</label>
                    <input type="number" name="call_max_idle" value="<?= getConfig($config['modules'], 'callActiveMaxIdleSeconds', 1800) ?>" min="60">
                </div>
 
                <div class="form-row">
                    <label>Procesos:</label>
                    <input type="text" name="call_processes" value="<?= htmlspecialchars(implode(', ', getConfig($config['modules'], 'callProcessKeywords', []))) ?>">
                    <small>Separados por coma: zoom, teams, skype</small>
                </div>
 
                <div class="form-row">
                    <label>Palabras en título:</label>
                    <input type="text" name="call_titles" value="<?= htmlspecialchars(implode(', ', getConfig($config['modules'], 'callTitleKeywords', []))) ?>">
                    <small>Separadas por coma: meeting, call, reunión</small>
                </div>
            </div>
 
            <!-- ========== STARTUP ========== -->
            <div class="config-section">
                <h3>🚀 Configuración de Inicio</h3>
 
                <div class="form-row">
                    <label>Iniciar con Windows:</label>
                    <input type="checkbox" name="startup_auto" <?= getConfig($config['startup'], 'enableAutoStartup', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Iniciar minimizado:</label>
                    <input type="checkbox" name="startup_minimized" <?= getConfig($config['startup'], 'startMinimized', false) ? 'checked' : '' ?>>
                </div>
            </div>
 
            <!-- ========== UPDATES ========== -->
            <div class="config-section">
                <h3>🔄 Sistema de Actualizaciones</h3>
 
                <div class="form-row">
                    <label>Auto-actualización:</label>
                    <input type="checkbox" name="updates_auto" <?= getConfig($config['updates'], 'enableAutoUpdate', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Intervalo verificación (min):</label>
                    <input type="number" name="updates_interval" value="<?= getConfig($config['updates'], 'checkIntervalMinutes', 60) ?>" min="5">
                </div>
 
                <div class="form-row">
                    <label>Descarga automática:</label>
                    <input type="checkbox" name="updates_auto_download" <?= getConfig($config['updates'], 'autoDownload', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Permitir versiones beta:</label>
                    <input type="checkbox" name="updates_beta" <?= getConfig($config['updates'], 'allowBetaVersions', false) ? 'checked' : '' ?>>
                </div>
            </div>
 
            <!-- ========== BLOCKING ========== -->
            <div class="config-section">
                <h3>🔒 Bloqueo Remoto</h3>
                <div class="alert alert-warning" style="margin-bottom: 1rem; padding: 1rem; background: #fff3cd; border-left: 4px solid #ffc107;">
                    ⚠️ Al activar esto, <strong>TODOS</strong> los dispositivos del usuario se bloquearán.
                </div>
 
                <div class="form-row">
                    <label style="color: #e74c3c; font-weight: bold;">🔒 BLOQUEAR EQUIPOS:</label>
                    <input type="checkbox" name="lock_enable" <?= getConfig($config['blocking'], 'enableDeviceLock', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Mensaje:</label>
                    <textarea name="lock_message" rows="3"><?= htmlspecialchars(getConfig($config['blocking'], 'lockMessage', 'Bloqueado por IT')) ?></textarea>
                </div>
 
                <div class="form-row">
                    <label>Permitir desbloqueo con PIN:</label>
                    <input type="checkbox" name="lock_allow_pin" <?= getConfig($config['blocking'], 'allowUnlockWithPin', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>PIN:</label>
                    <input type="text" name="lock_pin" placeholder="Dejar vacío para no cambiar">
                </div>
            </div>
 
            <!-- ========== TIMERS ========== -->
            <div class="config-section">
                <h3>⏱️ Intervalos de Sistema</h3>
 
                <div class="form-row">
                    <label>Activity Flush (seg):</label>
                    <input type="number" name="timer_flush" value="<?= getConfig($config['timers'], 'activityFlushIntervalSeconds', 6) ?>" min="1">
                </div>
 
                <div class="form-row">
                    <label>Handshake (min):</label>
                    <input type="number" name="timer_handshake" value="<?= getConfig($config['timers'], 'handshakeIntervalMinutes', 5) ?>" min="1">
                </div>
 
                <div class="form-row">
                    <label>Reintentos offline (seg):</label>
                    <input type="number" name="timer_retry" value="<?= getConfig($config['timers'], 'offlineQueueRetrySeconds', 30) ?>" min="5">
                </div>
            </div>
 
            <div style="display: flex; gap: 1rem; justify-content: center; padding: 2rem 0;">
                <button type="submit" name="action" value="save" class="btn btn-primary" style="font-size: 1.2rem; padding: 1rem 2rem;">
                    💾 GUARDAR CONFIGURACIÓN PERSONALIZADA
                </button>
                
                <?php if (!$usingGlobal): ?>
                <button type="submit" name="action" value="reset" class="btn btn-secondary" style="font-size: 1.2rem; padding: 1rem 2rem;" 
                        onclick="return confirm('¿Seguro que deseas eliminar la configuración personalizada y usar la global?')">
                    🔄 USAR CONFIGURACIÓN GLOBAL
                </button>
                <?php endif; ?>
            </div>
        </form>
    </div>
</body>
</html>