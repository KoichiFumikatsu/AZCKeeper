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
        'handshakeIntervalSeconds'     => 60,  // reemplaza handshakeIntervalMinutes
        'handshakeIntervalMinutes'     => 5,   // legacy — ignorado si handshakeIntervalSeconds > 0
        'offlineQueueRetrySeconds'     => 30
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
            'activityInactivityThresholdSeconds' => InputValidator::validateInt($_POST['activity_threshold'
