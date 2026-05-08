<?php
/**
 * server-health.php — Salud del Servidor
 *
 * Solo superadmin. Muestra recursos del servidor, estadísticas MySQL,
 * actividad de la API, estado de la base de datos y clientes conectados.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('server-health');

$pageTitle   = 'Salud del Servidor';
$currentPage = 'server-health';

/* ═══════════════════════════════════════════════
   1. INFORMACIÓN DEL SERVIDOR
   ═══════════════════════════════════════════════ */

$phpVersion     = PHP_VERSION;
$phpSapi        = php_sapi_name();
$phpMemoryLimit = ini_get('memory_limit');
$phpMemoryUsed  = memory_get_usage(true);
$phpMemoryPeak  = memory_get_peak_usage(true);
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown';
$serverOs       = PHP_OS_FAMILY;
$phpExtensions  = get_loaded_extensions();
sort($phpExtensions);

// Disco
$diskFree  = @disk_free_space('/') ?: @disk_free_space('C:\\');
$diskTotal = @disk_total_space('/') ?: @disk_total_space('C:\\');
$diskUsed  = $diskTotal ? ($diskTotal - $diskFree) : 0;
$diskPct   = $diskTotal ? round(($diskUsed / $diskTotal) * 100, 1) : 0;

/* ═══════════════════════════════════════════════
   2. MYSQL STATUS
   ═══════════════════════════════════════════════ */

$mysqlVersion = '';
$mysqlUptime  = 0;
$mysqlStatus  = [];
$mysqlVars    = [];

try {
    $mysqlVersion = $pdo->query("SELECT VERSION()")->fetchColumn();

    // Global status (queries, connections, threads, etc.)
    $statusKeys = [
        'Queries', 'Questions', 'Connections', 'Threads_connected',
        'Threads_running', 'Max_used_connections', 'Slow_queries',
        'Open_tables', 'Opened_tables', 'Uptime', 'Bytes_received',
        'Bytes_sent', 'Com_select', 'Com_insert', 'Com_update', 'Com_delete',
        'Aborted_connects', 'Aborted_clients',
    ];
    $st = $pdo->query("SHOW GLOBAL STATUS");
    while ($row = $st->fetch(PDO::FETCH_NUM)) {
        if (in_array($row[0], $statusKeys)) {
            $mysqlStatus[$row[0]] = (int)$row[1];
        }
    }
    $mysqlUptime = $mysqlStatus['Uptime'] ?? 0;

    // Key variables
    $varKeys = ['max_connections', 'innodb_buffer_pool_size', 'query_cache_size', 'wait_timeout', 'max_allowed_packet'];
    $st = $pdo->query("SHOW GLOBAL VARIABLES");
    while ($row = $st->fetch(PDO::FETCH_NUM)) {
        if (in_array($row[0], $varKeys)) {
            $mysqlVars[$row[0]] = $row[1];
        }
    }
} catch (\Throwable $e) {
    $mysqlVersion = 'Error: ' . $e->getMessage();
}

// MySQL health evaluations
$h_threads = ['ok', 'Normal'];
$h_slow    = ['ok', 'Sin queries lentas'];
$h_conn    = ['ok', 'Normal'];

$maxConn = (int)($mysqlVars['max_connections'] ?? 151);
if ($maxConn > 0) {
    $tPct = round((($mysqlStatus['Threads_connected'] ?? 0) / $maxConn) * 100, 1);
    if ($tPct > 80)      $h_threads = ['bad',  "{$tPct}% — aumentar max_connections"];
    elseif ($tPct > 50)  $h_threads = ['warn', "{$tPct}% — monitorear"];
    else                 $h_threads = ['ok',   "{$tPct}% uso"];
}

$slowQ = $mysqlStatus['Slow_queries'] ?? 0;
if ($slowQ > 100)    $h_slow = ['bad',  "{$slowQ} — revisar índices"];
elseif ($slowQ > 0)  $h_slow = ['warn', "{$slowQ} — considere optimizar"];

$abortedConn = $mysqlStatus['Aborted_connects'] ?? 0;
$totalConnNum = max(1, $mysqlStatus['Connections'] ?? 1);
$aPct = round(($abortedConn / $totalConnNum) * 100, 2);
if ($aPct > 5)       $h_conn = ['bad',  "{$aPct}% rechazadas — revisar auth/red"];
elseif ($aPct > 1)   $h_conn = ['warn', "{$aPct}% rechazadas"];
else                 $h_conn = ['ok',   "{$aPct}% rechazadas"];

/* ═══════════════════════════════════════════════
   3. TAMAÑOS DE TABLAS
   ═══════════════════════════════════════════════ */

$tables = [];
try {
    $dbName = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $st = $pdo->prepare("
        SELECT TABLE_NAME AS table_name, TABLE_ROWS AS table_rows,
               DATA_LENGTH AS data_length, INDEX_LENGTH AS index_length,
               (DATA_LENGTH + INDEX_LENGTH) AS total_size,
               ENGINE AS engine, UPDATE_TIME AS update_time
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = ?
        ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
    ");
    $st->execute([$dbName]);
    $tables = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$totalDbSize = array_sum(array_column($tables, 'total_size'));
$totalRows   = array_sum(array_column($tables, 'table_rows'));

/* ═══════════════════════════════════════════════
   4. ACTIVIDAD DE LA API (desde tablas existentes)
   ═══════════════════════════════════════════════ */

$apiStats = [
    'sessions_active'  => 0,
    'sessions_total'   => 0,
    'devices_active'   => 0,
    'devices_total'    => 0,
];

$versionDist = [];

try {
    // Sesiones
    $apiStats['sessions_active'] = (int)$pdo->query("
        SELECT COUNT(*) FROM keeper_sessions WHERE expires_at > NOW()
    ")->fetchColumn();

    $apiStats['sessions_total'] = (int)$pdo->query("SELECT COUNT(*) FROM keeper_sessions")->fetchColumn();

    // Dispositivos
    $apiStats['devices_active'] = (int)$pdo->query("
        SELECT COUNT(*) FROM keeper_devices WHERE last_seen_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) AND status = 'active'
    ")->fetchColumn();

    $apiStats['devices_total'] = (int)$pdo->query("SELECT COUNT(*) FROM keeper_devices")->fetchColumn();

    // Distribución de versiones de cliente
    $st = $pdo->query("
        SELECT client_version, COUNT(*) AS cnt
        FROM keeper_devices
        WHERE status = 'active' AND client_version IS NOT NULL AND client_version != ''
        GROUP BY client_version
        ORDER BY cnt DESC
        LIMIT 10
    ");
    $versionDist = $st->fetchAll(PDO::FETCH_ASSOC);



} catch (\Throwable $e) {
    // Table might not exist; fail silently
}

/* ═══════════════════════════════════════════════
   5. HEALTH CHECK DE LA API
   ═══════════════════════════════════════════════ */

$healthResult = null;
$healthLatency = 0;
try {
    $healthUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
               . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
               . str_replace('/admin/server-health.php', '/index.php/api/health', $_SERVER['SCRIPT_NAME']);

    $t0 = microtime(true);
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $raw = @file_get_contents($healthUrl, false, $ctx);
    $healthLatency = round((microtime(true) - $t0) * 1000, 1);
    $healthResult = $raw ? json_decode($raw, true) : null;
} catch (\Throwable $e) {
    $healthResult = null;
}

/* ═══════════════════════════════════════════════
   HELPERS
   ═══════════════════════════════════════════════ */

function fmtBytes(int $bytes, int $dec = 1): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = (int)floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), $dec) . ' ' . $units[$i];
}

function fmtUptime(int $seconds): string {
    $d = (int)($seconds / 86400);
    $h = (int)(($seconds % 86400) / 3600);
    $m = (int)(($seconds % 3600) / 60);
    $parts = [];
    if ($d) $parts[] = "{$d}d";
    if ($h) $parts[] = "{$h}h";
    $parts[] = "{$m}m";
    return implode(' ', $parts);
}

function fmtNumber(int $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000) return round($n / 1_000, 1) . 'K';
    return (string)$n;
}

function healthLabel(array $h): string {
    $icons  = ['ok' => '✓', 'warn' => '⚠', 'bad' => '✕'];
    $colors = ['ok' => 'text-emerald-600', 'warn' => 'text-amber-600', 'bad' => 'text-red-600'];
    return '<p class="mt-0.5 text-[9px] font-medium ' . ($colors[$h[0]] ?? '') . '">' 
         . ($icons[$h[0]] ?? '') . ' ' . htmlspecialchars($h[1]) . '</p>';
}

require_once __DIR__ . '/partials/layout_header.php';
?>

<div x-data="{ autoRefresh: false, lastRefresh: '<?= date('H:i:s') ?>' }"
     x-init="
        setInterval(() => { if (autoRefresh) location.reload(); }, 30000);
     ">

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 sm:w-10 sm:h-10 bg-emerald-100 rounded-lg flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
        </div>
        <div>
            <h2 class="text-lg sm:text-xl font-bold text-dark">Salud del Servidor</h2>
            <p class="text-xs sm:text-sm text-muted">Recursos, queries, API y estado general del sistema.</p>
        </div>
    </div>
    <div class="flex items-center gap-3">
        <label class="flex items-center gap-2 text-xs text-muted cursor-pointer">
            <input type="checkbox" x-model="autoRefresh" class="w-3.5 h-3.5 rounded border-gray-300 text-emerald-600 focus:ring-emerald-200">
            Auto-refresh 30s
        </label>
        <span class="text-[10px] text-muted">Último: <span x-text="lastRefresh"></span></span>
        <a href="server-health.php" class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-50 transition-colors flex items-center gap-1.5">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Refrescar
        </a>
    </div>
</div>

<!-- ═════════ FILA 1: KPI STATUS ═════════ -->
<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-4 mb-6">
    <!-- API Health -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 <?= $healthResult && ($healthResult['ok'] ?? false) ? 'bg-emerald-50' : 'bg-red-50' ?> rounded-lg flex items-center justify-center flex-shrink-0">
                <?php if ($healthResult && ($healthResult['ok'] ?? false)): ?>
                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <?php else: ?>
                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <?php endif; ?>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold <?= $healthResult && ($healthResult['ok'] ?? false) ? 'text-emerald-600' : 'text-red-600' ?>">
                    <?= $healthResult && ($healthResult['ok'] ?? false) ? 'OK' : 'DOWN' ?>
                </p>
                <p class="text-[10px] sm:text-xs text-muted cursor-help" title="Resultado del endpoint /api/health. OK = la API responde correctamente. El tiempo muestra la latencia.">API Health · <?= $healthLatency ?>ms</p>
            </div>
        </div>
    </div>

    <!-- MySQL Uptime -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-blue-50 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= fmtUptime($mysqlUptime) ?></p>
                <p class="text-[10px] sm:text-xs text-muted cursor-help" title="Tiempo desde el último reinicio de MySQL. Un reinicio reciente puede indicar un problema.">MySQL Uptime</p>
            </div>
        </div>
    </div>

    <!-- Active Devices -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-corp-50 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $apiStats['devices_active'] ?></p>
                <p class="text-[10px] sm:text-xs text-muted cursor-help" title="Clientes AZCKeeper con estado activo que se conectaron en las últimas 24 horas.">Dispositivos Activos (24h)</p>
            </div>
        </div>
    </div>
</div>

<!-- ═════════ FILA 2: SERVER + MYSQL ═════════ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5 mb-6">

    <!-- Servidor -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
            Servidor
        </h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Versión del intérprete PHP y modo de ejecución (SAPI: Server API)">PHP</span>
                <span class="font-medium text-dark"><?= $phpVersion ?> (<?= $phpSapi ?>)</span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Versión del motor de base de datos MySQL">MySQL</span>
                <span class="font-medium text-dark"><?= htmlspecialchars($mysqlVersion) ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Software del servidor web que ejecuta la aplicación">Web Server</span>
                <span class="font-medium text-dark text-right text-xs max-w-[60%] truncate"><?= htmlspecialchars($serverSoftware) ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Sistema operativo del servidor donde se ejecuta PHP">OS</span>
                <span class="font-medium text-dark"><?= $serverOs ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Máximo de memoria que PHP puede usar por solicitud (php.ini: memory_limit)">Memoria PHP (límite)</span>
                <span class="font-medium text-dark"><?= $phpMemoryLimit ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Memoria RAM usada por PHP en esta solicitud actual">Memoria PHP (actual)</span>
                <span class="font-medium text-dark"><?= fmtBytes($phpMemoryUsed) ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Máximo de memoria que PHP ha consumido durante esta solicitud">Memoria PHP (pico)</span>
                <span class="font-medium text-dark"><?= fmtBytes($phpMemoryPeak) ?></span>
            </div>

            <!-- Disco -->
            <div class="pt-2 border-t border-gray-100">
                <div class="flex justify-between items-center text-sm mb-1.5">
                    <span class="text-muted cursor-help" title="Espacio en disco del servidor. Alerta si supera 75% (ámbar) o 90% (rojo)">Disco</span>
                    <span class="text-xs text-muted"><?= fmtBytes((int)$diskUsed) ?> / <?= fmtBytes((int)$diskTotal) ?></span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-2">
                    <div class="h-2 rounded-full transition-all <?= $diskPct > 90 ? 'bg-red-500' : ($diskPct > 75 ? 'bg-amber-500' : 'bg-emerald-500') ?>"
                         style="width: <?= $diskPct ?>%"></div>
                </div>
                <p class="text-[10px] text-muted mt-1"><?= $diskPct ?>% usado — <?= fmtBytes((int)$diskFree) ?> libre</p>
            </div>
        </div>
    </div>

    <!-- MySQL Statistics -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            MySQL Global Status
        </h3>
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-gray-50 rounded-lg p-3 cursor-help" title="Total de consultas SQL ejecutadas por MySQL desde el inicio (incluye internas del servidor).">
                <p class="text-lg sm:text-xl font-bold text-dark"><?= fmtNumber($mysqlStatus['Queries'] ?? 0) ?></p>
                <p class="text-[10px] text-muted">Total Queries</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 cursor-help" title="Consultas ejecutadas solo por clientes (excluye consultas internas de MySQL).">
                <p class="text-lg sm:text-xl font-bold text-dark"><?= fmtNumber($mysqlStatus['Questions'] ?? 0) ?></p>
                <p class="text-[10px] text-muted">Questions (client)</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 cursor-help" title="Intentos de conexión totales al servidor MySQL. Incluye exitosas y fallidas.">
                <p class="text-lg sm:text-xl font-bold text-dark"><?= fmtNumber($mysqlStatus['Connections'] ?? 0) ?></p>
                <p class="text-[10px] text-muted">Total Connections</p>
                <?= healthLabel($h_conn) ?>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 cursor-help" title="Hilos actualmente conectados vs máximo permitido. Si se acerca al límite, nuevas conexiones serán rechazadas.">
                <p class="text-lg sm:text-xl font-bold text-dark"><?= $mysqlStatus['Threads_connected'] ?? 0 ?> / <?= $mysqlVars['max_connections'] ?? '?' ?></p>
                <p class="text-[10px] text-muted">Threads / Max Conn</p>
                <?= healthLabel($h_threads) ?>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 cursor-help" title="Consultas que tardaron más del tiempo configurado en long_query_time. Indica queries que necesitan optimización.">
                <p class="text-lg sm:text-xl font-bold text-dark"><?= $mysqlStatus['Slow_queries'] ?? 0 ?></p>
                <p class="text-[10px] text-muted">Slow Queries</p>
                <?= healthLabel($h_slow) ?>
            </div>
            <div class="bg-gray-50 rounded-lg p-3 cursor-help" title="Tablas actualmente abiertas en la caché de MySQL. Si es muy alto, considere aumentar table_open_cache.">
                <p class="text-lg sm:text-xl font-bold text-dark"><?= $mysqlStatus['Open_tables'] ?? 0 ?></p>
                <p class="text-[10px] text-muted">Open Tables</p>
            </div>
        </div>

        <!-- Query breakdown -->
        <div class="mt-4 pt-3 border-t border-gray-100">
            <p class="text-xs font-semibold text-dark mb-2">Queries por tipo</p>
            <div class="flex flex-wrap gap-2">
                <span class="px-2 py-1 bg-blue-50 text-blue-700 rounded text-[10px] font-medium cursor-help" title="Total de consultas SELECT (lectura de datos)">SELECT <?= fmtNumber($mysqlStatus['Com_select'] ?? 0) ?></span>
                <span class="px-2 py-1 bg-emerald-50 text-emerald-700 rounded text-[10px] font-medium cursor-help" title="Total de consultas INSERT (inserción de registros)">INSERT <?= fmtNumber($mysqlStatus['Com_insert'] ?? 0) ?></span>
                <span class="px-2 py-1 bg-amber-50 text-amber-700 rounded text-[10px] font-medium cursor-help" title="Total de consultas UPDATE (actualización de registros)">UPDATE <?= fmtNumber($mysqlStatus['Com_update'] ?? 0) ?></span>
                <span class="px-2 py-1 bg-red-50 text-red-700 rounded text-[10px] font-medium cursor-help" title="Total de consultas DELETE (eliminación de registros)">DELETE <?= fmtNumber($mysqlStatus['Com_delete'] ?? 0) ?></span>
            </div>
        </div>

        <!-- Network -->
        <div class="mt-3 pt-3 border-t border-gray-100">
            <p class="text-xs font-semibold text-dark mb-2 cursor-help" title="Bytes totales transferidos entre MySQL y los clientes desde el inicio del servidor">Tráfico de red MySQL</p>
            <div class="flex justify-between text-xs text-muted">
                <span>Recibido: <strong class="text-dark"><?= fmtBytes($mysqlStatus['Bytes_received'] ?? 0) ?></strong></span>
                <span>Enviado: <strong class="text-dark"><?= fmtBytes($mysqlStatus['Bytes_sent'] ?? 0) ?></strong></span>
            </div>
        </div>
    </div>
</div>

<!-- ═════════ FILA 3: VERSIONES + API STATS ═════════ -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 sm:gap-5 mb-6">

    <!-- Distribución de versiones -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
            Versiones de Cliente
        </h3>
        <?php if (empty($versionDist)): ?>
        <p class="text-sm text-muted py-4 text-center">Sin datos de versiones</p>
        <?php else: ?>
        <?php $maxV = max(1, max(array_column($versionDist, 'cnt'))); ?>
        <div class="space-y-2">
            <?php foreach ($versionDist as $v): $vPct = ($v['cnt'] / $maxV) * 100; ?>
            <div>
                <div class="flex justify-between text-xs mb-0.5">
                    <span class="font-mono font-medium text-dark">v<?= htmlspecialchars($v['client_version']) ?></span>
                    <span class="text-muted"><?= (int)$v['cnt'] ?> dispositivo<?= (int)$v['cnt'] !== 1 ? 's' : '' ?></span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1.5">
                    <div class="h-1.5 rounded-full bg-corp-400" style="width: <?= $vPct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- API Summary -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            Actividad API
        </h3>
        <div class="space-y-3">
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Sesiones de autenticación con token no expirado">Sesiones activas</span>
                <span class="font-bold text-emerald-600"><?= $apiStats['sessions_active'] ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Número total de sesiones creadas en keeper_sessions">Sesiones totales</span>
                <span class="font-medium text-dark"><?= number_format($apiStats['sessions_total']) ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Dispositivos con estado activo que se conectaron en las últimas 24 horas">Dispositivos activos</span>
                <span class="font-bold text-emerald-600"><?= $apiStats['devices_active'] ?></span>
            </div>
            <div class="flex justify-between items-center text-sm">
                <span class="text-muted cursor-help" title="Total de dispositivos registrados en keeper_devices">Dispositivos totales</span>
                <span class="font-medium text-dark"><?= $apiStats['devices_total'] ?></span>
            </div>
        </div>
    </div>
</div>


<!-- ═════════ FILA 4: TABLAS DE LA BD ═════════ -->
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
        <h3 class="text-sm font-bold text-dark flex items-center gap-2">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
            Tablas de la Base de Datos
        </h3>
        <div class="flex items-center gap-3 text-xs text-muted">
            <span><strong class="text-dark"><?= count($tables) ?></strong> tablas</span>
            <span><strong class="text-dark"><?= fmtNumber($totalRows) ?></strong> filas</span>
            <span><strong class="text-dark"><?= fmtBytes($totalDbSize) ?></strong> total</span>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-2 sm:px-3 py-2.5 text-left text-xs font-semibold text-muted uppercase tracking-wider">Tabla</th>
                    <th class="px-2 sm:px-3 py-2.5 text-right text-xs font-semibold text-muted uppercase tracking-wider">Filas</th>
                    <th class="px-2 sm:px-3 py-2.5 text-right text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">Datos</th>
                    <th class="px-2 sm:px-3 py-2.5 text-right text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Índices</th>
                    <th class="px-2 sm:px-3 py-2.5 text-right text-xs font-semibold text-muted uppercase tracking-wider">Total</th>
                    <th class="px-2 sm:px-3 py-2.5 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Engine</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($tables as $t):
                    $isKeeper = str_starts_with($t['table_name'], 'keeper_');
                ?>
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="px-2 sm:px-3 py-2">
                        <span class="font-mono text-xs <?= $isKeeper ? 'text-corp-800 font-medium' : 'text-gray-500' ?>"><?= htmlspecialchars($t['table_name']) ?></span>
                    </td>
                    <td class="px-2 sm:px-3 py-2 text-right text-xs text-dark"><?= number_format((int)$t['table_rows']) ?></td>
                    <td class="px-2 sm:px-3 py-2 text-right text-xs text-muted hidden sm:table-cell"><?= fmtBytes((int)$t['data_length']) ?></td>
                    <td class="px-2 sm:px-3 py-2 text-right text-xs text-muted hidden md:table-cell"><?= fmtBytes((int)$t['index_length']) ?></td>
                    <td class="px-2 sm:px-3 py-2 text-right text-xs font-medium text-dark"><?= fmtBytes((int)$t['total_size']) ?></td>
                    <td class="px-2 sm:px-3 py-2 text-xs text-muted hidden lg:table-cell"><?= htmlspecialchars($t['engine'] ?? '') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ═════════ PHP EXTENSIONS ═════════ -->
<details class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6">
    <summary class="text-sm font-bold text-dark cursor-pointer flex items-center gap-2">
        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
        Extensiones PHP cargadas (<?= count($phpExtensions) ?>)
    </summary>
    <div class="mt-3 flex flex-wrap gap-1.5">
        <?php foreach ($phpExtensions as $ext): ?>
        <span class="px-2 py-0.5 bg-gray-50 border border-gray-100 text-gray-600 text-[10px] font-mono rounded"><?= htmlspecialchars($ext) ?></span>
        <?php endforeach; ?>
    </div>
</details>

</div><!-- /x-data -->

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
