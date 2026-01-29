<?php
require_once __DIR__ . '/../../src/bootstrap.php';
 
$pdo = Keeper\Db::pdo();
 
// Obtener política global actual
$globalPolicy = $pdo->query("
    SELECT * FROM keeper_policy_assignments 
    WHERE scope = 'global' AND is_active = 1 
    ORDER BY priority DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
 
// Configuración por defecto - ALINEADA CON ClientConfig C# (PascalCase)
$defaultConfig = [    
    'ApiBaseUrl' => 'https://inventario.azc.com.co/keeper/public/index.php/api/',
    
    'Logging' => [
        'GlobalLevel' => 'Info',
        'ClientOverrideLevel' => null,
        'EnableFileLogging' => true,
        'EnableDiscordLogging' => false,
        'DiscordWebhookUrl' => null
    ],
    
    'Modules' => [
        'EnableActivityTracking' => true,
        'EnableWindowTracking' => true,
        'EnableProcessTracking' => false,
        'EnableBlocking' => false,
        'EnableKeyboardHook' => false,
        'EnableMouseHook' => false,
        'EnableUpdateManager' => true,
        'EnableDebugWindow' => false
    ],
    
    'Activity' => [
        'ActivityIntervalSeconds' => 1.0,
        'ActivityInactivityThresholdSeconds' => 15.0,
        'CountCallsAsActive' => true,
        'CallActiveMaxIdleSeconds' => 1800.0
    ],
    
    'Window' => [
        'WindowIntervalSeconds' => 2.0,
        'EnableCallTracking' => true,
        'CallProcessKeywords' => ['zoom', 'teams', 'skype', 'meet', 'webex', '3cx', 'zoiper'],
        'CallTitleKeywords' => ['meeting', 'call', 'reunión', 'llamada', 'videollamada']
    ],
    
    'Blocking' => [
        'EnableDeviceLock' => false,
        'LockMessage' => "Este equipo ha sido bloqueado por IT.\n\nContacta al administrador.",
        'AllowUnlockWithPin' => true,
        'UnlockPin' => null
    ],
    
    'Startup' => [
        'EnableAutoStartup' => true,
        'StartMinimized' => false
    ],
    
    'Updates' => [
        'EnableAutoUpdate' => true,
        'CheckIntervalMinutes' => 60,
        'AutoDownload' => false,
        'AllowBetaVersions' => false
    ],
    
    'Timers' => [
        'ActivityFlushIntervalSeconds' => 6,
        'HandshakeIntervalMinutes' => 5,
        'OfflineQueueRetrySeconds' => 30
    ]
];
 
// Mergear con política existente
if ($globalPolicy) {
    $savedConfig = json_decode($globalPolicy['policy_json'], true);
    $config = array_replace_recursive($defaultConfig, $savedConfig ?: []);
} else {
    $config = $defaultConfig;
}

// Helper para acceder a config anidada
function getNestedConfig($config, $section, $key, $default = null) {
    return $config[$section][$key] ?? $default;
}
 
// PROCESAR FORMULARIO
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newConfig = [
        'ApiBaseUrl' => trim($_POST['api_base_url'] ?? $config['ApiBaseUrl']),
        
        'Logging' => [
            'GlobalLevel' => $_POST['log_level'] ?? 'Info',
            'ClientOverrideLevel' => $_POST['log_override'] ?: null,
            'EnableFileLogging' => isset($_POST['log_file']),
            'EnableDiscordLogging' => isset($_POST['log_discord']),
            'DiscordWebhookUrl' => $_POST['discord_webhook'] ?: null
        ],
        
        'Modules' => [
            'EnableActivityTracking' => isset($_POST['mod_activity']),
            'EnableWindowTracking' => isset($_POST['mod_window']),
            'EnableProcessTracking' => isset($_POST['mod_process']),
            'EnableBlocking' => isset($_POST['mod_blocking']),
            'EnableKeyboardHook' => isset($_POST['mod_keyboard']),
            'EnableMouseHook' => isset($_POST['mod_mouse']),
            'EnableUpdateManager' => isset($_POST['mod_updates']),
            'EnableDebugWindow' => isset($_POST['mod_debug'])
        ],
        
        'Activity' => [
            'ActivityIntervalSeconds' => (float)($_POST['activity_interval'] ?? 1.0),
            'ActivityInactivityThresholdSeconds' => (float)($_POST['activity_threshold'] ?? 15.0),
            'CountCallsAsActive' => isset($_POST['activity_count_calls']),
            'CallActiveMaxIdleSeconds' => (float)($_POST['activity_call_max_idle'] ?? 1800.0)
        ],
        
        'Window' => [
            'WindowIntervalSeconds' => (float)($_POST['window_interval'] ?? 2.0),
            'EnableCallTracking' => isset($_POST['window_call_tracking']),
            'CallProcessKeywords' => array_filter(array_map('trim', explode(',', $_POST['window_call_processes'] ?? ''))),
            'CallTitleKeywords' => array_filter(array_map('trim', explode(',', $_POST['window_call_titles'] ?? '')))
        ],
        
        'Blocking' => [
            'EnableDeviceLock' => isset($_POST['blocking_enable']),
            'LockMessage' => $_POST['blocking_message'] ?? 'Equipo bloqueado',
            'AllowUnlockWithPin' => isset($_POST['blocking_allow_pin']),
            'UnlockPin' => !empty($_POST['blocking_pin']) 
                ? $_POST['blocking_pin'] 
                : getNestedConfig($config, 'Blocking', 'UnlockPin')
        ],
        
        'Startup' => [
            'EnableAutoStartup' => isset($_POST['startup_auto']),
            'StartMinimized' => isset($_POST['startup_minimized'])
        ],
        
        'Updates' => [
            'EnableAutoUpdate' => isset($_POST['updates_auto']),
            'CheckIntervalMinutes' => (int)($_POST['updates_interval'] ?? 60),
            'AutoDownload' => isset($_POST['updates_auto_download']),
            'AllowBetaVersions' => isset($_POST['updates_beta'])
        ],
        
        'Timers' => [
            'ActivityFlushIntervalSeconds' => (int)($_POST['timer_flush'] ?? 6),
            'HandshakeIntervalMinutes' => (int)($_POST['timer_handshake'] ?? 5),
            'OfflineQueueRetrySeconds' => (int)($_POST['timer_retry'] ?? 30)
        ]
    ];
    
    // Desactivar políticas globales anteriores
    $pdo->query("UPDATE keeper_policy_assignments SET is_active = 0 WHERE scope = 'global'");
    
    // Crear nueva política global
    $stmt = $pdo->prepare("
        INSERT INTO keeper_policy_assignments 
        (scope, user_id, device_id, priority, policy_json, version, is_active)
        VALUES ('global', NULL, NULL, 1, ?, 1, 1)
    ");
    $stmt->execute([json_encode($newConfig, JSON_UNESCAPED_UNICODE)]);
    
    header('Location: policies.php?msg=saved');
    exit;
}
 
// Contar usuarios afectados
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
    <link rel="stylesheet" href="assets/style.css">
    <style>
    .config-section { background: white; padding: 2rem; margin-bottom: 1rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .config-section h3 { color: #3498db; margin-bottom: 1.5rem; border-bottom: 2px solid #eee; padding-bottom: 0.5rem; }
    .form-row { display: grid; grid-template-columns: 280px 1fr; gap: 1rem; margin-bottom: 1rem; align-items: center; }
    .form-row label { font-weight: 600; }
    .form-row input[type="checkbox"] { width: 20px; height: 20px; justify-self: start; }
    .form-row input[type="number"], .form-row input[type="text"], .form-row select, .form-row textarea { 
        padding: 0.5rem; border: 1px solid #ddd; border-radius: 4px; width: 100%;
    }
    .form-row small { grid-column: 2; color: #666; margin-top: -0.5rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="devices.php">Dispositivos</a>
            <a href="policies.php" class="active">Política Global</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>🌐 Política Global</h1>
        <p style="font-size: 1.1rem; color: #666; margin-bottom: 2rem;">
            Esta política se aplica a <strong style="color: #3498db;"><?= $affectedUsers ?> usuario(s)</strong> que NO tienen política personalizada.
        </p>
 
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'saved'): ?>
            <div class="alert alert-success" style="background: #d4edda; padding: 1rem; border-radius: 4px; margin-bottom: 1rem;">
                ✓ Política global guardada. Se aplicará en próximo handshake de todos los clientes.
            </div>
        <?php endif; ?>
 
        <form method="POST">
            <!-- API BASE URL -->
            <div style="background: #e3f2fd; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; border-left: 4px solid #2196f3;">
                <h3 style="margin-bottom: 1rem; color: #1976d2;">🌐 Endpoint de API</h3>
                <div>
                    <label style="display: block; margin-bottom: 0.5rem; font-weight: bold;">URL Base de la API:</label>
                    <input type="text" name="api_base_url" 
                           value="<?= htmlspecialchars($config['ApiBaseUrl'] ?? '') ?>" 
                           style="width: 100%; padding: 0.6rem; border: 1px solid #ddd; border-radius: 4px; font-family: monospace;"
                           placeholder="https://tudominio.com/keeper/public/index.php/api/"
                           required>
                    <small style="color: #666; display: block; margin-top: 0.5rem;">
                        ⚠️ Incluir <code>/api/</code> al final.
                    </small>
                </div>
            </div>

            <!-- ========== LOGGING ========== -->
            <div class="config-section">
                <h3>📋 Configuración de Logs</h3>
                
                <div class="form-row">
                    <label>Nivel de log global:</label>
                    <select name="log_level">
                        <?php foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level): ?>
                            <option <?= getNestedConfig($config, 'Logging', 'GlobalLevel', 'Info') === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
 
                <div class="form-row">
                    <label>Override por cliente:</label>
                    <select name="log_override">
                        <option value="">-- Sin override --</option>
                        <?php foreach (['Trace', 'Debug', 'Info', 'Warn', 'Error'] as $level): ?>
                            <option <?= getNestedConfig($config, 'Logging', 'ClientOverrideLevel') === $level ? 'selected' : '' ?>><?= $level ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small>Si se especifica, sobreescribe el nivel global</small>
                </div>
 
                <div class="form-row">
                    <label>Guardar logs en archivo:</label>
                    <input type="checkbox" name="log_file" <?= getNestedConfig($config, 'Logging', 'EnableFileLogging', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Enviar logs a Discord:</label>
                    <input type="checkbox" name="log_discord" <?= getNestedConfig($config, 'Logging', 'EnableDiscordLogging', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Discord Webhook URL:</label>
                    <input type="text" name="discord_webhook" 
                           value="<?= htmlspecialchars(getNestedConfig($config, 'Logging', 'DiscordWebhookUrl', '') ?: '') ?>" 
                           placeholder="https://discord.com/api/webhooks/...">
                </div>
            </div>
 
            <!-- ========== MÓDULOS ========== -->
            <div class="config-section">
                <h3>🔧 Módulos (Activar/Desactivar)</h3>
                
                <div class="form-row">
                    <label>Activity Tracking:</label>
                    <input type="checkbox" name="mod_activity" <?= getNestedConfig($config, 'Modules', 'EnableActivityTracking', true) ? 'checked' : '' ?>>
                    <small>Rastreo de actividad/inactividad del usuario</small>
                </div>
 
                <div class="form-row">
                    <label>Window Tracking:</label>
                    <input type="checkbox" name="mod_window" <?= getNestedConfig($config, 'Modules', 'EnableWindowTracking', true) ? 'checked' : '' ?>>
                    <small>Rastreo de ventanas y aplicaciones activas</small>
                </div>
 
                <div class="form-row">
                    <label>Process Tracking:</label>
                    <input type="checkbox" name="mod_process" <?= getNestedConfig($config, 'Modules', 'EnableProcessTracking', false) ? 'checked' : '' ?>>
                    <small>Rastreo de procesos en ejecución</small>
                </div>
 
                <div class="form-row">
                    <label>Módulo de Bloqueo:</label>
                    <input type="checkbox" name="mod_blocking" <?= getNestedConfig($config, 'Modules', 'EnableBlocking', false) ? 'checked' : '' ?>>
                    <small>Habilita la capacidad de bloquear equipos remotamente</small>
                </div>
 
                <div class="form-row">
                    <label>Keyboard Hook:</label>
                    <input type="checkbox" name="mod_keyboard" <?= getNestedConfig($config, 'Modules', 'EnableKeyboardHook', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Mouse Hook:</label>
                    <input type="checkbox" name="mod_mouse" <?= getNestedConfig($config, 'Modules', 'EnableMouseHook', false) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>Update Manager:</label>
                    <input type="checkbox" name="mod_updates" <?= getNestedConfig($config, 'Modules', 'EnableUpdateManager', true) ? 'checked' : '' ?>>
                    <small>Sistema de auto-actualización del cliente</small>
                </div>
 
                <div class="form-row">
                    <label>Debug Window:</label>
                    <input type="checkbox" name="mod_debug" <?= getNestedConfig($config, 'Modules', 'EnableDebugWindow', false) ? 'checked' : '' ?>>
                    <small>Ventana de debug con estadísticas en tiempo real</small>
                </div>
            </div>

            <!-- ========== ACTIVITY CONFIG ========== -->
            <div class="config-section">
                <h3>⏱️ Configuración de Activity Tracking</h3>
 
                <div class="form-row">
                    <label>Intervalo de muestreo (segundos):</label>
                    <input type="number" name="activity_interval" 
                           value="<?= getNestedConfig($config, 'Activity', 'ActivityIntervalSeconds', 1.0) ?>" 
                           step="0.1" min="0.1" max="60">
                    <small>Cada cuánto se verifica actividad del usuario</small>
                </div>
 
                <div class="form-row">
                    <label>Umbral de inactividad (segundos):</label>
                    <input type="number" name="activity_threshold" 
                           value="<?= getNestedConfig($config, 'Activity', 'ActivityInactivityThresholdSeconds', 15.0) ?>" 
                           step="1" min="1" max="3600">
                    <small>Segundos sin input para considerar usuario inactivo</small>
                </div>
 
                <div class="form-row">
                    <label>Contar llamadas como activo:</label>
                    <input type="checkbox" name="activity_count_calls" <?= getNestedConfig($config, 'Activity', 'CountCallsAsActive', true) ? 'checked' : '' ?>>
                    <small>Marca al usuario como activo durante videollamadas aunque no haya input</small>
                </div>
 
                <div class="form-row">
                    <label>Max idle en llamada (segundos):</label>
                    <input type="number" name="activity_call_max_idle" 
                           value="<?= getNestedConfig($config, 'Activity', 'CallActiveMaxIdleSeconds', 1800.0) ?>" 
                           min="60" max="7200">
                    <small>Tiempo máximo sin input antes de marcar inactivo incluso en llamada</small>
                </div>
            </div>

            <!-- ========== WINDOW CONFIG ========== -->
            <div class="config-section">
                <h3>🪟 Configuración de Window Tracking</h3>
 
                <div class="form-row">
                    <label>Intervalo de captura (segundos):</label>
                    <input type="number" name="window_interval" 
                           value="<?= getNestedConfig($config, 'Window', 'WindowIntervalSeconds', 2.0) ?>" 
                           step="0.1" min="0.1" max="60">
                    <small>Cada cuánto se captura la ventana activa</small>
                </div>
 
                <div class="form-row">
                    <label>Habilitar Call Tracking:</label>
                    <input type="checkbox" name="window_call_tracking" <?= getNestedConfig($config, 'Window', 'EnableCallTracking', true) ? 'checked' : '' ?>>
                    <small>Detecta cuando el usuario está en apps de videollamada</small>
                </div>
 
                <div class="form-row">
                    <label>Procesos de llamada:</label>
                    <input type="text" name="window_call_processes" 
                           value="<?= htmlspecialchars(implode(', ', getNestedConfig($config, 'Window', 'CallProcessKeywords', []) ?: [])) ?>" 
                           placeholder="zoom, teams, skype, meet, webex">
                    <small>Nombres de procesos separados por coma</small>
                </div>
 
                <div class="form-row">
                    <label>Palabras clave en título:</label>
                    <input type="text" name="window_call_titles" 
                           value="<?= htmlspecialchars(implode(', ', getNestedConfig($config, 'Window', 'CallTitleKeywords', []) ?: [])) ?>" 
                           placeholder="meeting, call, reunión, llamada">
                    <small>Palabras clave en títulos de ventana separadas por coma</small>
                </div>
            </div>
 
            <!-- ========== STARTUP ========== -->
            <div class="config-section">
                <h3>🚀 Configuración de Inicio</h3>
 
                <div class="form-row">
                    <label>Iniciar con Windows:</label>
                    <input type="checkbox" name="startup_auto" <?= getNestedConfig($config, 'Startup', 'EnableAutoStartup', true) ? 'checked' : '' ?>>
                    <small>Registra el cliente en el inicio de Windows</small>
                </div>
 
                <div class="form-row">
                    <label>Iniciar minimizado:</label>
                    <input type="checkbox" name="startup_minimized" <?= getNestedConfig($config, 'Startup', 'StartMinimized', false) ? 'checked' : '' ?>>
                    <small>Inicia el cliente en la bandeja del sistema</small>
                </div>
            </div>
 
            <!-- ========== UPDATES ========== -->
            <div class="config-section">
                <h3>🔄 Sistema de Actualizaciones</h3>
 
                <div class="form-row">
                    <label>Auto-actualización:</label>
                    <input type="checkbox" name="updates_auto" <?= getNestedConfig($config, 'Updates', 'EnableAutoUpdate', true) ? 'checked' : '' ?>>
                    <small>Verifica actualizaciones automáticamente</small>
                </div>
 
                <div class="form-row">
                    <label>Intervalo de verificación (min):</label>
                    <input type="number" name="updates_interval" 
                           value="<?= getNestedConfig($config, 'Updates', 'CheckIntervalMinutes', 60) ?>" 
                           min="5" max="1440">
                    <small>Cada cuántos minutos verifica si hay nueva versión</small>
                </div>
 
                <div class="form-row">
                    <label>Descarga automática:</label>
                    <input type="checkbox" name="updates_auto_download" <?= getNestedConfig($config, 'Updates', 'AutoDownload', false) ? 'checked' : '' ?>>
                    <small>Descarga e instala sin preguntar al usuario</small>
                </div>
 
                <div class="form-row">
                    <label>Permitir versiones beta:</label>
                    <input type="checkbox" name="updates_beta" <?= getNestedConfig($config, 'Updates', 'AllowBetaVersions', false) ? 'checked' : '' ?>>
                </div>
            </div>
 
            <!-- ========== BLOCKING ========== -->
            <div class="config-section">
                <h3>🔒 Bloqueo Remoto (Configuración Global)</h3>
                <div class="alert alert-warning" style="margin-bottom: 1.5rem; padding: 1rem; background: #fff3cd; border-left: 4px solid #ffc107;">
                    ⚠️ <strong>NOTA:</strong> Esta configuración establece los valores por defecto. 
                    Para bloquear un equipo específico, usa la configuración por dispositivo.
                </div>
 
                <div class="form-row">
                    <label style="color: #e74c3c;">Habilitar bloqueo global:</label>
                    <input type="checkbox" name="blocking_enable" <?= getNestedConfig($config, 'Blocking', 'EnableDeviceLock', false) ? 'checked' : '' ?>>
                    <small>⚠️ Si está activo, bloquea TODOS los equipos con política global</small>
                </div>
 
                <div class="form-row">
                    <label>Mensaje de bloqueo:</label>
                    <textarea name="blocking_message" rows="3"><?= htmlspecialchars(getNestedConfig($config, 'Blocking', 'LockMessage', '') ?: '') ?></textarea>
                    <small>Mensaje que verá el usuario cuando su equipo sea bloqueado</small>
                </div>
 
                <div class="form-row">
                    <label>Permitir desbloqueo con PIN:</label>
                    <input type="checkbox" name="blocking_allow_pin" <?= getNestedConfig($config, 'Blocking', 'AllowUnlockWithPin', true) ? 'checked' : '' ?>>
                </div>
 
                <div class="form-row">
                    <label>PIN de desbloqueo:</label>
                    <input type="text" name="blocking_pin" 
                           value="" 
                           placeholder="Dejar vacío para mantener el actual"
                           autocomplete="off">
                    <small>PIN que usará IT para desbloquear equipos</small>
                </div>
            </div>
 
            <!-- ========== TIMERS ========== -->
            <div class="config-section">
                <h3>⏱️ Intervalos de Sistema</h3>
 
                <div class="form-row">
                    <label>Activity Flush (segundos):</label>
                    <input type="number" name="timer_flush" 
                           value="<?= getNestedConfig($config, 'Timers', 'ActivityFlushIntervalSeconds', 6) ?>" 
                           min="1" max="300">
                    <small>Cada cuánto se envía actividad al servidor</small>
                </div>
 
                <div class="form-row">
                    <label>Handshake (minutos):</label>
                    <input type="number" name="timer_handshake" 
                           value="<?= getNestedConfig($config, 'Timers', 'HandshakeIntervalMinutes', 5) ?>" 
                           min="1" max="60">
                    <small>Cada cuánto sincroniza configuración con servidor</small>
                </div>
 
                <div class="form-row">
                    <label>Reintentos offline (segundos):</label>
                    <input type="number" name="timer_retry" 
                           value="<?= getNestedConfig($config, 'Timers', 'OfflineQueueRetrySeconds', 30) ?>" 
                           min="5" max="300">
                    <small>Cada cuánto reintenta enviar datos cuando no hay conexión</small>
                </div>
            </div>
 
            <div style="text-align: center; padding: 2rem 0;">
                <button type="submit" class="btn btn-primary" style="font-size: 1.3rem; padding: 1.2rem 3rem;">
                    💾 GUARDAR POLÍTICA GLOBAL
                </button>
            </div>
        </form>
    </div>
</body>
</html>