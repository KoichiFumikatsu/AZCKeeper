<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\InputValidator;
 
$pdo = Keeper\Db::pdo();
 
// Obtener política global actual
$globalPolicy = $pdo->query("
    SELECT * FROM keeper_policy_assignments 
    WHERE scope = 'global' AND is_active = 1 
    ORDER BY priority DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
 
// Configuración por defecto
$defaultConfig = [    
    'apiBaseUrl' => 'http://localhost/AZCKeeper/AZCKeeper_Client/Web/public/index.php/api/', 
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
        'enableProcessTracking' => false,
        'enableBlocking' => false,
        'enableUpdateManager' => true,
        'enableDebugWindow' => false,
        'enableCallTracking' => false,
        'countCallsAsActive' => false,
        'callActiveMaxIdleSeconds' => 1800,
        'activityIntervalSeconds' => 1,
        'activityInactivityThresholdSeconds' => 900,
        'windowTrackingIntervalSeconds' => 1,
        'callProcessKeywords' => ['zoom', 'teams', 'skype', 'meet', 'webex'],
        'callTitleKeywords' => ['meeting', 'call', 'reunión', 'llamada']
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
        'lockMessage' => 'Dispositivo bloqueado por políticas de seguridad.',
        'allowUnlockWithPin' => false,
        'unlockPin' => null
    ],
    'timers' => [
        'activityFlushIntervalSeconds' => 10,
        'handshakeIntervalMinutes' => 5,
        'offlineQueueRetrySeconds' => 30
    ]
];
 
// Mergear con política existente
if ($globalPolicy) {
    $savedConfig = json_decode($globalPolicy['policy_json'], true);
    $config = array_replace_recursive($defaultConfig, $savedConfig ?: []);
} else {
    $config = $defaultConfig;
}
 
function getConfig($array, $key, $default = null) {
    return $array[$key] ?? $default;
}
 
// PROCESAR FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validar inputs
    $apiBaseUrl = filter_var(trim($_POST['api_base_url'] ?? ''), FILTER_VALIDATE_URL) ?: $config['apiBaseUrl'];
    $logLevel = InputValidator::validateEnum($_POST['log_level'] ?? 'Info', ['Trace', 'Debug', 'Info', 'Warn', 'Error'], 'Info');
    $logOverride = InputValidator::validateEnum($_POST['log_override'] ?? '', ['Trace', 'Debug', 'Info', 'Warn', 'Error', ''], '');
    
    $newConfig = [
        'apiBaseUrl' => $apiBaseUrl,
        'logging' => [
            'globalLevel' => $logLevel,
            'clientOverrideLevel' => $logOverride ?: null,
            'enableFileLogging' => isset($_POST['log_file']),
            'enableDiscordLogging' => isset($_POST['log_discord']),
            'discordWebhookUrl' => filter_var($_POST['discord_webhook'] ?? '', FILTER_VALIDATE_URL) ?: null
        ],
        'modules' => [
            'enableActivityTracking' => isset($_POST['mod_activity']),
            'enableWindowTracking' => isset($_POST['mod_window']),
            'enableProcessTracking' => isset($_POST['mod_process']),
            'enableBlocking' => isset($_POST['mod_blocking']),
            'enableUpdateManager' => isset($_POST['mod_updates']),
            'enableDebugWindow' => isset($_POST['mod_debug']),
            'enableCallTracking' => isset($_POST['mod_call_tracking']),
            'countCallsAsActive' => isset($_POST['mod_count_calls']),
            'callActiveMaxIdleSeconds' => InputValidator::validateInt($_POST['call_max_idle'] ?? 1800, 1800, 60, 86400),
            'activityIntervalSeconds' => InputValidator::validateInt($_POST['activity_interval'] ?? 1, 1, 1, 60),
            'activityInactivityThresholdSeconds' => InputValidator::validateInt($_POST['activity_threshold'] ?? 15, 15, 5, 3600),
            'windowTrackingIntervalSeconds' => InputValidator::validateInt($_POST['window_interval'] ?? 2, 2, 1, 60),
            'callProcessKeywords' => array_filter(array_map('trim', explode(',', $_POST['call_processes'] ?? ''))),
            'callTitleKeywords' => array_filter(array_map('trim', explode(',', $_POST['call_titles'] ?? '')))
        ],
        'startup' => [
            'enableAutoStartup' => isset($_POST['startup_auto']),
            'startMinimized' => isset($_POST['startup_minimized'])
        ],
        'updates' => [
            'enableAutoUpdate' => isset($_POST['updates_auto']),
            'checkIntervalMinutes' => InputValidator::validateInt($_POST['updates_interval'] ?? 60, 60, 5, 1440),
            'autoDownload' => isset($_POST['updates_auto_download']),
            'allowBetaVersions' => isset($_POST['updates_beta'])
        ],
        'blocking' => [
            'enableDeviceLock' => isset($_POST['blocking_enable']),
            'lockMessage' => substr(trim($_POST['blocking_message'] ?? 'Bloqueado'), 0, 500),
            'allowUnlockWithPin' => isset($_POST['blocking_allow_pin']),
            'unlockPin' => !empty($_POST['blocking_pin']) ? preg_replace('/[^0-9]/', '', $_POST['blocking_pin']) : ($config['blocking']['unlockPin'] ?? null)
        ],
        'timers' => [
            'activityFlushIntervalSeconds' => InputValidator::validateInt($_POST['timer_flush'] ?? 6, 6, 1, 60),
            'handshakeIntervalMinutes' => InputValidator::validateInt($_POST['timer_handshake'] ?? 5, 5, 1, 60),
            'offlineQueueRetrySeconds' => InputValidator::validateInt($_POST['timer_retry'] ?? 30, 30, 5, 300)
        ]
    ];
    
    // Desactivar políticas globales anteriores usando prepared statement
    $stmt = $pdo->prepare("UPDATE keeper_policy_assignments SET is_active = 0 WHERE scope = ?");
    $stmt->execute(['global']);
    
    // Crear nueva política global
    $stmt = $pdo->prepare("
        INSERT INTO keeper_policy_assignments 
        (scope, user_id, device_id, priority, policy_json, version, is_active)
        VALUES ('global', NULL, NULL, 1, ?, 1, 1)
    ");
    $stmt->execute([json_encode($newConfig)]);
    
    header('Location: policies.php?msg=saved');
    exit;
}
 
// Contar usuarios afectados (query sin parámetros)
$affectedUsers = $pdo->query("
    SELECT COUNT(*) as count FROM keeper_users u
    WHERE u.status = 'active' 
    AND NOT EXISTS (
        SELECT 1 FROM keeper_policy_assignments pa 
        WHERE pa.scope = 'user' AND pa.user_id = u.id AND pa.is_active = 1
    )
")->fetch(PDO::FETCH_ASSOC)['count'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Política Global - AZCKeeper Admin</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="stylesheet" href="assets/style.css">
    <style>
    .config-section { background: white; padding: 2rem; margin-bottom: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(15, 23, 42, 0.1); }
    .config-section h3 { color: #1E3A8A; margin-bottom: 1.5rem; border-bottom: 2px solid #94A3B8; padding-bottom: 0.5rem; }
    .form-row { display: grid; grid-template-columns: 250px 1fr; gap: 1rem; margin-bottom: 1rem; align-items: center; }
    .form-row label { font-weight: 600; }
    .form-row input[type="checkbox"] { width: 20px; height: 20px; justify-self: start; }
    .form-row input[type="number"], .form-row input[type="text"], .form-row select, .form-row textarea { 
        padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; width: 100%;
    }
    .form-row small { grid-column: 2; color: #94A3B8; margin-top: -0.5rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand"><img src="assets/Icon White.png" alt="AZC" style="height: 24px; vertical-align: middle; margin-right: 8px;"> AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="users.php">Usuarios</a>
            <a href="policies.php" class="active">Política Global</a>
            <a href="releases.php">Releases</a>
        </div>
    </nav>
 
    <div class="container">
        <h1><i class="bi bi-shield-check"></i> Política Global</h1>
        <p style="font-size: 1.1rem; color: #94A3B8; margin-bottom: 2rem;">
            Esta política se aplica a <strong style="color: #1E3A8A;"><?= $affectedUsers ?> usuario(s)</strong> que NO tienen política personalizada.
        </p>
 
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle"></i> Política global guardada. Se aplicará en próximo handshake de todos los clientes (máx 5 min)</div>
        <?php endif; ?>
 
        <form method="POST">
            <!-- API BASE URL -->
            <div style="background: #e3f2fd; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; border-left: 4px solid #2196f3;">
                <h3 style="margin-bottom: 1rem;"><i class="bi bi-globe"></i> Endpoint de API</h3>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: bold;">URL Base de la API:</label>
                    <input type="text" name="api_base_url" 
                           value="<?= htmlspecialchars($config['apiBaseUrl'] ?? 'http://localhost/keeper/public/index.php/api/') ?>" 
                           style="width: 100%; padding: 0.6rem; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;"
                           placeholder="https://tudominio.com/keeper/public/index.php/api/"
                           required>
                    <small style="color: #94A3B8; display: block; margin-top: 0.5rem;">
                        <i class="bi bi-exclamation-triangle"></i> Incluir <code>/api/</code> al final. Ejemplo: <code>https://ejemplo.com/keeper/public/index.php/api/</code>
                    </small>
                </div>
            </div>
            <!-- ========== LOGGING ========== -->
            <div class="config-section">
                <h3><i class="bi bi-clipboard-data"></i> Configuración de Logs</h3>
                
                <div class="form-row">
                    <label>Nivel de log global:</label>
                    <select name="log_level">
                        <?php foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level): ?>
                            <option <?= getConfig($config['logging'], 'globalLevel', 'Info') === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
 
                <div class="form-row">
                    <label>Override por cliente:</label>
                    <select name="log_override">
                        <option value="">-- Sin override --</option>
                        <?php foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level): ?>
                            <option <?= getConfig($config['logging'], 'clientOverrideLevel') === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Si se especifica, sobreescribe el nivel global solo para este cliente</small>
                </div>
 
                <div class="form-row">
                    <label>Guardar logs en archivo:</label>
                    <input type="checkbox" name="log_file" <?= getConfig($config['logging'], 'enableFileLogging', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Enviar logs a Discord:</label>
                    <input type="checkbox" name="log_discord" <?= getConfig($config['logging'], 'enableDiscordLogging', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Discord Webhook URL:</label>
                    <input type="text" name="discord_webhook" value="<?= htmlspecialchars(getConfig($config['logging'], 'discordWebhookUrl', '') ?: '') ?>" placeholder="https://discord.com/api/webhooks/...">
                </div>
            </div>
 
            <!-- ========== MÓDULOS ========== -->
            <div class="config-section">
                <h3><i class="bi bi-wrench"></i> Módulos y Funcionalidades</h3>
                
                <div class="form-row">
                    <label>Activity Tracking:</label>
                    <input type="checkbox" name="mod_activity" <?= getConfig($config['modules'], 'enableActivityTracking', true) ? 'checked' : '' ?>>
                    <small>Rastreo de actividad/inactividad del usuario</small>
                </div>
 
                <div class="form-row">
                    <label>Window Tracking:</label>
                    <input type="checkbox" name="mod_window" <?= getConfig($config['modules'], 'enableWindowTracking', true) ? 'checked' : '' ?>>
                    <small>Rastreo de ventanas y aplicaciones activas</small>
                </div>
 
                <div class="form-row">
                    <label>Process Tracking:</label>
                    <input type="checkbox" name="mod_process" <?= getConfig($config['modules'], 'enableProcessTracking', false) ? 'checked' : '' ?>>
                    <small>Rastreo de procesos en ejecución</small>
                </div>
 
                <div class="form-row">
                    <label>Módulo de Bloqueo:</label>
                    <input type="checkbox" name="mod_blocking" <?= getConfig($config['modules'], 'enableBlocking', false) ? 'checked' : '' ?>>
                    <small>Habilita la capacidad de bloquear equipos remotamente</small>
                </div>
 
                <div class="form-row">
                    <label>Update Manager:</label>
                    <input type="checkbox" name="mod_updates" <?= getConfig($config['modules'], 'enableUpdateManager', true) ? 'checked' : '' ?>>
                    <small>Sistema de auto-actualización del cliente</small>
                </div>
 
                <div class="form-row">
                    <label>Debug Window:</label>
                    <input type="checkbox" name="mod_debug" <?= getConfig($config['modules'], 'enableDebugWindow', false) ? 'checked' : '' ?>>
                    <small>Ventana de debug con estadísticas en tiempo real</small>
                </div>
 
                <h4 style="margin-top: 2rem; color: #0F172A;">Configuración de Activity Tracking</h4>
 
                <div class="form-row">
                    <label>Intervalo (segundos):</label>
                    <input type="number" name="activity_interval" value="<?= getConfig($config['modules'], 'activityIntervalSeconds', 1) ?>" step="0.1" min="0.1" max="60">
                    <small>Cada cuánto se verifica actividad del usuario</small>
                </div>
 
                <div class="form-row">
                    <label>Umbral inactividad (seg):</label>
                    <input type="number" name="activity_threshold" value="<?= getConfig($config['modules'], 'activityInactivityThresholdSeconds', 15) ?>" step="1" min="1">
                    <small>Segundos sin input para considerar inactivo</small>
                </div>
 
                <h4 style="margin-top: 2rem; color: #0F172A;">Configuración de Window Tracking</h4>
 
                <div class="form-row">
                    <label>Intervalo (segundos):</label>
                    <input type="number" name="window_interval" value="<?= getConfig($config['modules'], 'windowTrackingIntervalSeconds', 2) ?>" step="0.1" min="0.1" max="60">
                    <small>Cada cuánto se captura la ventana activa</small>
                </div>
 
                <h4 style="margin-top: 2rem; color: #0F172A;">Call Tracking (Apps de Llamadas)</h4>
 
                <div class="form-row">
                    <label>Habilitar Call Tracking:</label>
                    <input type="checkbox" name="mod_call_tracking" <?= getConfig($config['modules'], 'enableCallTracking', false) ? 'checked' : '' ?>>
                    <small>Detecta cuando el usuario está en apps de videollamada</small>
                </div>
 
                <div class="form-row">
                    <label>Contar llamadas como activo:</label>
                    <input type="checkbox" name="mod_count_calls" <?= getConfig($config['modules'], 'countCallsAsActive', false) ? 'checked' : '' ?>>
                    <small>Marca al usuario como activo aunque no haya input durante llamadas</small>
                </div>
 
                <div class="form-row">
                    <label>Max idle en llamada (seg):</label>
                    <input type="number" name="call_max_idle" value="<?= getConfig($config['modules'], 'callActiveMaxIdleSeconds', 1800) ?>" min="60">
                    <small>Tiempo máximo sin input antes de marcar inactivo incluso en llamada</small>
                </div>
 
                <div class="form-row">
                    <label>Procesos de llamada:</label>
                    <input type="text" name="call_processes" value="<?= htmlspecialchars(implode(', ', getConfig($config['modules'], 'callProcessKeywords', []))) ?>" placeholder="zoom, teams, skype">
                    <small>Nombres de procesos separados por coma</small>
                </div>
 
                <div class="form-row">
                    <label>Palabras en título:</label>
                    <input type="text" name="call_titles" value="<?= htmlspecialchars(implode(', ', getConfig($config['modules'], 'callTitleKeywords', []))) ?>" placeholder="meeting, call, reunión">
                    <small>Palabras clave en títulos de ventana separadas por coma</small>
                </div>
            </div>
 
            <!-- ========== STARTUP ========== -->
            <div class="config-section">
                <h3><i class="bi bi-rocket-takeoff"></i> Configuración de Inicio</h3>
 
                <div class="form-row">
                    <label>Iniciar con Windows:</label>
                    <input type="checkbox" name="startup_auto" <?= getConfig($config['startup'], 'enableAutoStartup', true) ? 'checked' : '' ?>>
                    <small>Registra el cliente en el inicio de Windows</small>
                </div>
 
                <div class="form-row">
                    <label>Iniciar minimizado:</label>
                    <input type="checkbox" name="startup_minimized" <?= getConfig($config['startup'], 'startMinimized', false) ? 'checked' : '' ?>>
                    <small>Inicia el cliente en la bandeja del sistema</small>
                </div>
            </div>
 
            <!-- ========== UPDATES ========== -->
            <div class="config-section">
                <h3><i class="bi bi-arrow-repeat"></i> Sistema de Actualizaciones</h3>
 
                <div class="form-row">
                    <label>Auto-actualización:</label>
                    <input type="checkbox" name="updates_auto" <?= getConfig($config['updates'], 'enableAutoUpdate', true) ? 'checked' : '' ?>>
                    <small>Verifica y descarga actualizaciones automáticamente</small>
                </div>
 
                <div class="form-row">
                    <label>Intervalo de verificación (min):</label>
                    <input type="number" name="updates_interval" value="<?= getConfig($config['updates'], 'checkIntervalMinutes', 60) ?>" min="5" max="1440">
                    <small>Cada cuántos minutos verifica si hay nueva versión</small>
                </div>
 
                <div class="form-row">
                    <label>Descarga automática:</label>
                    <input type="checkbox" name="updates_auto_download" <?= getConfig($config['updates'], 'autoDownload', false) ? 'checked' : '' ?>>
                    <small>Descarga e instala sin preguntar al usuario</small>
                </div>
 
                <div class="form-row">
                    <label>Permitir versiones beta:</label>
                    <input type="checkbox" name="updates_beta" <?= getConfig($config['updates'], 'allowBetaVersions', false) ? 'checked' : '' ?>>
                </div>
            </div>
 
            <!-- ========== BLOCKING ========== -->
            <div class="config-section">
                <h3><i class="bi bi-lock"></i> Bloqueo Remoto (Por Defecto)</h3>
                <div class="alert alert-warning" style="margin-bottom: 1.5rem; padding: 1rem; background: #fff3cd; border-left: 4px solid #ffc107;">
                    <i class="bi bi-exclamation-triangle"></i> <strong>NOTA:</strong> Esta configuración NO bloquea equipos. Solo establece los valores por defecto. Para bloquear, usa la configuración por usuario.
                </div>

                <div class="form-row">
                    <label>Habilitar bloqueo remoto (global):</label>
                    <input type="checkbox" name="blocking_enable" <?= getConfig($config['blocking'], 'enableDeviceLock', false) ? 'checked' : '' ?>>
                    <small>Si está activo, bloquea TODOS los equipos con la política global</small>
                </div>

                <div class="form-row">
                    <label>Mensaje de bloqueo:</label>
                    <textarea name="blocking_message" rows="3"><?= htmlspecialchars(getConfig($config['blocking'], 'lockMessage', '')) ?></textarea>
                    <small>Mensaje que verá el usuario cuando su equipo sea bloqueado</small>
                </div>
 
                <div class="form-row">
                    <label>Permitir desbloqueo con PIN:</label>
                    <input type="checkbox" name="blocking_allow_pin" <?= getConfig($config['blocking'], 'allowUnlockWithPin', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>PIN por defecto:</label>
                    <input type="text" name="blocking_pin" value="<?= htmlspecialchars(getConfig($config['blocking'], 'unlockPin', '')) ?>" placeholder="Cambiar PIN aquí">
                    <small>PIN que usará IT para desbloquear equipos (se guarda con hash en BD)</small>
                </div>
            </div>
 
            <!-- ========== TIMERS ========== -->
            <div class="config-section">
                <h3><i class="bi bi-stopwatch"></i> Intervalos de Sistema</h3>
 
                <div class="form-row">
                    <label>Activity Flush (segundos):</label>
                    <input type="number" name="timer_flush" value="<?= getConfig($config['timers'], 'activityFlushIntervalSeconds', 6) ?>" min="1" max="60">
                    <small>Cada cuánto se envía actividad al servidor</small>
                </div>
 
                <div class="form-row">
                    <label>Handshake (minutos):</label>
                    <input type="number" name="timer_handshake" value="<?= getConfig($config['timers'], 'handshakeIntervalMinutes', 5) ?>" min="1" max="60">
                    <small>Cada cuánto sincroniza configuración con servidor</small>
                </div>
 
                <div class="form-row">
                    <label>Reintentos offline (seg):</label>
                    <input type="number" name="timer_retry" value="<?= getConfig($config['timers'], 'offlineQueueRetrySeconds', 30) ?>" min="5" max="300">
                    <small>Cada cuánto reintenta enviar datos cuando no hay conexión</small>
                </div>
            </div>
 
            <div style="text-align: center; padding: 2rem 0;">
                <button type="submit" class="btn btn-primary" style="font-size: 1.3rem; padding: 1.2rem 3rem;">
                    <i class="bi bi-save"></i> GUARDAR POLÍTICA GLOBAL
                </button>
                <button type="button" onclick="forceHandshake()" class="btn btn-primary" style="font-size: 1.3rem; padding: 1.2rem 3rem;">
                    <i class="bi bi-arrow-clockwise"></i> Forzar Actualización Inmediata
                </button>
 
                <script>
                async function forceHandshake() {
                    if (!confirm('¿Forzar actualización en todos los clientes conectados?')) return;
                    
                    const response = await fetch('/keeper/public/index.php/api/client/force-handshake', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' }
                    });
                    
                    const data = await response.json();
                    alert(data.message || 'Actualización forzada');
                }
                </script>
            </div>
        </form>
    </div>
</body>
</html>
