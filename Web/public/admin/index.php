<?php
require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Keeper\Db::pdo();

/**
 * Función para calcular el estado de conexión de un usuario
 * basándose en segundos calculados directamente por MySQL (sin problemas de timezone)
 */
function calculateUserStatus($secondsSinceLastSeen, $secondsSinceLastEvent) {
    // Sin dispositivos o sin last_seen
    if ($secondsSinceLastSeen === null || $secondsSinceLastSeen > 900000) {
        return 'offline';
    }
    
    // Sin heartbeat reciente (>15 min) = desconectado
    if ($secondsSinceLastSeen >= 900) {
        return 'inactive';
    }
    
    // Dispositivo conectado, verificar actividad
    if ($secondsSinceLastEvent === null || $secondsSinceLastEvent > 900000) {
        // Heartbeat reciente pero sin actividad hoy
        return ($secondsSinceLastSeen < 120) ? 'away' : 'inactive';
    }
    
    // Actividad reciente (<2 min) = activo
    if ($secondsSinceLastEvent < 120) {
        return 'active';
    }
    
    // Sin actividad reciente pero conectado = ausente
    return 'away';
}

// ==================== ESTADÍSTICAS GLOBALES ====================
$stats = $pdo->query("
    SELECT 
        COUNT(DISTINCT u.id) as total_users,
        COUNT(DISTINCT d.id) as total_devices,
        COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 24 HOUR THEN d.id END) as active_devices_24h,
        (SELECT COUNT(*) FROM keeper_policy_assignments WHERE scope='user' AND is_active=1) as custom_policies
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id
    WHERE u.status = 'active'
")->fetch(PDO::FETCH_ASSOC);

// ==================== ACTIVIDAD AGREGADA ====================
$activity = $pdo->query("
    SELECT 
        SUM(active_seconds) as total_active,
        SUM(idle_seconds) as total_idle,
        SUM(work_hours_active_seconds) as work_active,
        SUM(work_hours_idle_seconds) as work_idle,
        SUM(lunch_active_seconds) as lunch_active,
        SUM(lunch_idle_seconds) as lunch_idle,
        SUM(after_hours_active_seconds) as after_active,
        SUM(after_hours_idle_seconds) as after_idle,
        SUM(call_seconds) as total_call,
        COUNT(DISTINCT day_date) as total_days,
        COUNT(DISTINCT CASE WHEN is_workday = 1 THEN day_date END) as workdays,
        COUNT(DISTINCT CASE WHEN is_workday = 0 THEN day_date END) as non_workdays,
        COUNT(DISTINCT user_id) as users_with_activity
    FROM keeper_activity_day
")->fetch(PDO::FETCH_ASSOC);

// ==================== ESTADOS DE CONEXIÓN ====================
// Usar TIMESTAMPDIFF de MySQL para evitar problemas de timezone entre PHP y MySQL
$connectionStats = $pdo->query("
    SELECT 
        u.id as user_id,
        u.display_name,
        u.cc,
        MAX(d.last_seen_at) as last_seen,
        TIMESTAMPDIFF(SECOND, MAX(d.last_seen_at), NOW()) as seconds_since_seen,
        (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
         WHERE a.user_id = u.id AND a.day_date = CURDATE()) as last_event,
        TIMESTAMPDIFF(SECOND, 
            (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
             WHERE a.user_id = u.id AND a.day_date = CURDATE()), 
            NOW()) as seconds_since_event
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
    WHERE u.status = 'active'
    GROUP BY u.id, u.display_name, u.cc
")->fetchAll(PDO::FETCH_ASSOC);

// Calcular estados y contadores usando los segundos calculados por MySQL
$statusCounts = ['active' => 0, 'away' => 0, 'inactive' => 0, 'offline' => 0];
$connectedUsers = [];

foreach ($connectionStats as &$user) {
    $status = calculateUserStatus($user['seconds_since_seen'], $user['seconds_since_event']);
    $user['status'] = $status;
    $statusCounts[$status]++;
    
    // Solo incluir usuarios conectados (active o away) en la lista
    if ($status === 'active' || $status === 'away') {
        $connectedUsers[] = $user;
    }
}
unset($user);

// ==================== ESTADÍSTICAS CONSOLIDADAS (OPTIMIZADO) ====================
// Combinar múltiples queries en una sola para mejorar rendimiento
$statsConsolidated = $pdo->query("
    SELECT 
        -- Diarias
        COUNT(DISTINCT CASE WHEN day_date = CURDATE() THEN user_id END) as active_staff_today,
        SUM(CASE WHEN day_date = CURDATE() THEN work_hours_active_seconds ELSE 0 END) as work_active_today,
        SUM(CASE WHEN day_date = CURDATE() THEN work_hours_idle_seconds ELSE 0 END) as work_idle_today,
        SUM(CASE WHEN day_date = CURDATE() THEN lunch_active_seconds + lunch_idle_seconds ELSE 0 END) as break_time_today,
        SUM(CASE WHEN day_date = CURDATE() THEN call_seconds ELSE 0 END) as meeting_time_today,
        SUM(CASE WHEN day_date = CURDATE() THEN after_hours_active_seconds ELSE 0 END) as after_hours_today,
        -- Mensuales
        SUM(CASE WHEN YEAR(day_date) = YEAR(CURDATE()) AND MONTH(day_date) = MONTH(CURDATE()) 
            THEN active_seconds + idle_seconds ELSE 0 END) as total_seconds_month,
        SUM(CASE WHEN YEAR(day_date) = YEAR(CURDATE()) AND MONTH(day_date) = MONTH(CURDATE()) 
            THEN lunch_active_seconds + lunch_idle_seconds ELSE 0 END) as total_break_time_month
    FROM keeper_activity_day
")->fetch(PDO::FETCH_ASSOC);

// Calcular productividad
$totalWorkTime = ($statsConsolidated['work_active_today'] ?? 0) + 
                 ($statsConsolidated['work_idle_today'] ?? 0) + 
                 ($statsConsolidated['break_time_today'] ?? 0);
                 
$productivityPercentage = $totalWorkTime > 0 
    ? round((($statsConsolidated['work_active_today'] ?? 0) / $totalWorkTime) * 100) 
    : 0;

// Desglose de tiempo
$totalBreakdownTime = ($statsConsolidated['work_active_today'] ?? 0) + 
                       ($statsConsolidated['break_time_today'] ?? 0) + 
                       ($statsConsolidated['meeting_time_today'] ?? 0) + 
                       ($statsConsolidated['after_hours_today'] ?? 0);

// First login hoy (simplificado)
$firstLoginResult = $pdo->query("
    SELECT MIN(start_at) as first_login_utc
    FROM keeper_window_episode
    WHERE day_date = CURDATE()
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$firstLoginDisplay = '--:--';
if ($firstLoginResult && $firstLoginResult['first_login_utc']) {
    $utcTime = new DateTime($firstLoginResult['first_login_utc'], new DateTimeZone('UTC'));
    $utcTime->setTimezone(new DateTimeZone('America/Bogota'));
    $hour = (int)$utcTime->format('G');
    // Solo mostrar si está entre 7am-7pm Colombia
    if ($hour >= 7 && $hour <= 19) {
        $firstLoginDisplay = $utcTime->format('g:i A');
    }
}

// ==================== APLICACIONES MÁS USADAS (HOY) ====================
$topApplications = $pdo->query("
    SELECT 
        COALESCE(app_name, process_name, 'Desconocido') as app_name,
        SUM(duration_seconds) as total_seconds
    FROM keeper_window_episode
    WHERE day_date = CURDATE()
    GROUP BY app_name
    ORDER BY total_seconds DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

$totalAppSeconds = array_sum(array_column($topApplications, 'total_seconds'));

// ==================== SITIOS WEB MÁS VISITADOS (HOY) ====================
// Extraer URLs de window_title de navegadores comunes
$topWebsites = $pdo->query("
    SELECT 
        SUBSTRING_INDEX(SUBSTRING_INDEX(window_title, ' - ', 1), ' | ', 1) as website_name,
        COUNT(*) as visit_count,
        SUM(duration_seconds) as total_seconds
    FROM keeper_window_episode
    WHERE day_date = CURDATE()
        AND (
            app_name LIKE '%Chrome%' OR 
            app_name LIKE '%Firefox%' OR 
            app_name LIKE '%Edge%' OR 
            app_name LIKE '%Safari%' OR
            process_name LIKE '%chrome%' OR
            process_name LIKE '%firefox%' OR
            process_name LIKE '%msedge%'
        )
        AND window_title IS NOT NULL
        AND window_title != ''
    GROUP BY website_name
    ORDER BY visit_count DESC
    LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ==================== TOP USUARIOS ACTIVOS ====================
$topUsers = $pdo->query("
    SELECT 
        u.id,
        u.cc,
        u.display_name,
        SUM(a.active_seconds) as total_active,
        SUM(a.work_hours_active_seconds) as work_active,
        COUNT(DISTINCT CASE WHEN a.is_workday = 1 THEN a.day_date END) as days_worked,
        COUNT(DISTINCT CASE WHEN a.is_workday = 0 THEN a.day_date END) as weekend_days
    FROM keeper_users u
    INNER JOIN keeper_activity_day a ON a.user_id = u.id
    GROUP BY u.id
    ORDER BY total_active DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

function formatSeconds($seconds) {
    if (!$seconds) return '00h 00m';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf('%02dh %02dm', $h, $m);
}

function formatHours($seconds) {
    if (!$seconds) return 0;
    return round($seconds / 3600, 1);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AZCKeeper - Dashboard</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Estilos de indicadores de estado - Minimalista */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.25rem 0.5rem;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        
        .status-active {
            background: #ECFDF5;
            color: #065F46;
        }
        
        .status-active .status-dot {
            background: #059669;
            animation: pulse 2s infinite;
        }
        
        .status-away {
            background: #FEF3C7;
            color: #92400E;
        }
        
        .status-away .status-dot {
            background: #F59E0B;
        }
        
        .status-inactive {
            background: #F3F4F6;
            color: #0F172A;
        }
        
        .status-inactive .status-dot {
            background: #94A3B8;
        }
        
        .status-offline {
            background: #FFFFFF;
            color: #94A3B8;
        }
        
        .status-offline .status-dot {
            background: #94A3B8;
        }
        
        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.6;
                transform: scale(1.3);
            }
        }
        
        /* Estilos del dashboard */
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(4, 1fr); 
            gap: 0.75rem; 
            margin: 1.5rem 0; 
        }
        
        .stat-card { 
            background: white; 
            padding: 0.75rem; 
            border-radius: 6px; 
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.1); 
            text-align: center; 
            transition: transform 0.2s, box-shadow 0.2s; 
            border: 1px solid #94A3B8;
        }
        
        .stat-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 4px 6px rgba(15, 23, 42, 0.15); 
            border-color: #1E3A8A;
        }
        
        .stat-value { 
            font-size: 1.75rem; 
            font-weight: bold; 
            color: #1E3A5F; 
            margin: 0.25rem 0; 
        }
        
        .stat-label { 
            color: #94A3B8; 
            font-size: 0.8rem; 
        }
        
        .chart-container { 
            background: white; 
            padding: 1.5rem; 
            border-radius: 8px; 
            margin: 1.5rem 0; 
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.1);
            border: 1px solid #94A3B8;
        }
        
        .data-table { 
            width: 100%; 
            background: white; 
            border-radius: 8px; 
            overflow: hidden; 
            border: 1px solid #94A3B8;
        }
        
        .data-table th { 
            background: #FFFFFF; 
            color: #0F172A; 
            padding: 1rem; 
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #94A3B8;
        }
        
        .data-table td { 
            padding: 0.8rem; 
            border-bottom: 1px solid #F3F4F6;
            color: #0F172A;
        }
        
        .data-table tr:hover { 
            background: #FFFFFF; 
        }
        
        /* Estilos para gráficos y widgets */
        .widget-grid {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 1rem;
            margin: 1.5rem 0;
        }
        
        .productivity-circle {
            position: relative;
            width: 150px;
            height: 150px;
            margin: 1rem auto;
        }
        
        .productivity-circle svg {
            transform: rotate(-90deg);
        }
        
        .productivity-circle .percentage {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            font-size: 2rem;
            font-weight: bold;
            color: #1E3A5F;
        }
        
        .productivity-circle .label {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 0.75rem;
            color: #94A3B8;
        }
        
        .legend {
            display: flex;
            justify-content: center;
            gap: 1.5rem;
            margin-top: 1rem;
            font-size: 0.85rem;
        }
        
        .legend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        
        .progress-bar-container {
            margin: 1rem 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }
        
        .progress-bar {
            height: 8px;
            background: #e0e0e0;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #1E3A5F, #1E40AF);
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .app-list, .website-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .app-item, .website-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem;
            margin: 0.5rem 0;
            background: #f8f9fa;
            border-radius: 6px;
            transition: background 0.2s;
        }
        
        .app-item:hover, .website-item:hover {
            background: #e9ecef;
        }
        
        .app-icon, .website-rank {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #1E3A5F;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            margin-right: 1rem;
        }
        
        .app-info, .website-info {
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .app-name, .website-name {
            font-weight: 600;
            font-size: 0.9rem;
            color: #0F172A;
        }
        
        .app-usage, .visit-count {
            font-size: 0.75rem;
            color: #94A3B8;
        }
        
        .app-percentage {
            font-weight: bold;
            color: #1E3A5F;
        }
        
        @media (max-width: 768px) {
            .widget-grid {
                grid-template-columns: 1fr;
            }
            .dashboard-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand"><img src="assets/Icon White.png" alt="AZC" style="height: 24px; vertical-align: middle; margin-right: 8px;"> AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php" class="active">Dashboard</a>
            <a href="users.php">Usuarios</a>
            <a href="policies.php">Política Global</a>
            <a href="releases.php">Releases</a>
        </div>
    </nav>

    <div class="container">
        <h1><i class="bi bi-graph-up"></i> Dashboard AZCKeeper</h1>
        
        <!-- ESTADOS DE CONEXIÓN -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-value" style="color: #059669;"><?= $statusCounts['active'] ?></div>
                <div class="stat-label">
                    <span class="status-indicator status-active">
                        <span class="status-dot"></span>
                        Active
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #F59E0B;"><?= $statusCounts['away'] ?></div>
                <div class="stat-label">
                    <span class="status-indicator status-away">
                        <span class="status-dot"></span>
                        Away
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #94A3B8;"><?= $statusCounts['inactive'] ?></div>
                <div class="stat-label">
                    <span class="status-indicator status-inactive">
                        <span class="status-dot"></span>
                        Disconnected
                    </span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= htmlspecialchars($stats['custom_policies']) ?></div>
                <div class="stat-label">Custom Policies</div>
            </div>
        </div>
        
        <!-- ESTADÍSTICAS DIARIAS Y MENSUALES -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <i class="bi bi-people-fill" style="font-size: 2rem; color: #1E3A5F; margin-bottom: 0.5rem;"></i>
                <div class="stat-value"><?= $statsConsolidated['active_staff_today'] ?></div>
                <div class="stat-label">Staff Conectado Hoy</div>
            </div>
            <div class="stat-card">
                <i class="bi bi-box-arrow-in-right" style="font-size: 2rem; color: #1E3A5F; margin-bottom: 0.5rem;"></i>
                <div class="stat-value">
                    <?= $firstLoginDisplay ?>
                </div>
                <div class="stat-label">First Login Today</div>
            </div>
            <div class="stat-card">
                <i class="bi bi-clock-history" style="font-size: 2rem; color: #1E3A5F; margin-bottom: 0.5rem;"></i>
                <div class="stat-value"><?= formatHours($statsConsolidated['total_seconds_month']) ?>h</div>
                <div class="stat-label">Hours This Month</div>
            </div>
            <div class="stat-card">
                <i class="bi bi-cup-hot-fill" style="font-size: 2rem; color: #1E3A5F; margin-bottom: 0.5rem;"></i>
                <div class="stat-value"><?= formatHours($statsConsolidated['total_break_time_month']) ?>h</div>
                <div class="stat-label">Break Time This Month</div>
            </div>
        </div>

        <!-- PRODUCTIVIDAD Y TIME BREAKDOWN -->
        <div class="widget-grid">
            <!-- Productivity Chart -->
            <div class="chart-container">
                <h4 style="margin-bottom: 1.5rem;"><i class="bi bi-graph-up-arrow"></i> Productivity</h4>
                <p style="color: #94A3B8; font-size: 0.85rem; margin-bottom: 1rem;">Productive vs non-productive time</p>
                <div class="productivity-circle">
                    <svg width="150" height="150">
                        <circle cx="75" cy="75" r="60" fill="none" stroke="#94A3B8" stroke-width="15"/>
                        <circle cx="75" cy="75" r="60" fill="none" stroke="#1E3A5F" stroke-width="15" 
                                stroke-dasharray="<?= $productivityPercentage * 3.77 ?> 377" 
                                stroke-linecap="round"/>
                    </svg>
                    <div class="percentage"><?= $productivityPercentage ?>%</div>
                    <div class="label">Productive</div>
                </div>
                <div class="legend">
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #1E3A5F;"></span>
                        <span>Productive</span>
                    </div>
                    <div class="legend-item">
                        <span class="legend-dot" style="background: #94A3B8;"></span>
                        <span>Other</span>
                    </div>
                </div>
            </div>

            <!-- Time Breakdown -->
            <div class="chart-container">
                <h4 style="margin-bottom: 1.5rem;"><i class="bi bi-calendar3"></i> Time Breakdown</h4>
                <p style="color: #94A3B8; font-size: 0.85rem; margin-bottom: 1rem;">How time is being spent</p>
                
                <?php 
                $breakdownItems = [
                    ['label' => 'Work Time', 'seconds' => $statsConsolidated['work_active_today'], 'color' => '#1E3A5F'],
                    ['label' => 'Break Time', 'seconds' => $statsConsolidated['break_time_today'], 'color' => '#059669'],
                    ['label' => 'Meetings', 'seconds' => $statsConsolidated['meeting_time_today'], 'color' => '#F59E0B'],
                    ['label' => 'After Hours', 'seconds' => $statsConsolidated['after_hours_today'], 'color' => '#94A3B8']
                ];
                
                foreach ($breakdownItems as $item): 
                    $percentage = $totalBreakdownTime > 0 
                        ? round(($item['seconds'] / $totalBreakdownTime) * 100) 
                        : 0;
                ?>
                <div class="progress-bar-container">
                    <div class="progress-label">
                        <span><?= $item['label'] ?></span>
                        <strong><?= $percentage ?>%</strong>
                    </div>
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $percentage ?>%; background: <?= $item['color'] ?>;"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- APLICACIONES Y SITIOS WEB -->
        <div class="widget-grid">
            <!-- Most Used Applications -->
            <div class="chart-container">
                <h4 style="margin-bottom: 1rem;"><i class="bi bi-laptop"></i> Most Used Applications</h4>
                <p style="color: #666; font-size: 0.85rem; margin-bottom: 1rem;">Today's application usage</p>
                <ul class="app-list">
                    <?php foreach ($topApplications as $app): 
                        $appPercentage = $totalAppSeconds > 0 
                            ? round(($app['total_seconds'] / $totalAppSeconds) * 100) 
                            : 0;
                        // Icono basado en el nombre de la app
                        $iconClass = 'bi-file-earmark';
                        if (stripos($app['app_name'], 'chrome') !== false || stripos($app['app_name'], 'firefox') !== false) $iconClass = 'bi-globe';
                        elseif (stripos($app['app_name'], 'word') !== false) $iconClass = 'bi-file-word';
                        elseif (stripos($app['app_name'], 'excel') !== false) $iconClass = 'bi-file-excel';
                        elseif (stripos($app['app_name'], 'outlook') !== false) $iconClass = 'bi-envelope';
                        elseif (stripos($app['app_name'], 'teams') !== false || stripos($app['app_name'], 'zoom') !== false) $iconClass = 'bi-camera-video';
                    ?>
                    <li class="app-item">
                        <div class="app-icon"><i class="bi <?= $iconClass ?>"></i></div>
                        <div class="app-info">
                            <span class="app-name"><?= htmlspecialchars($app['app_name']) ?></span>
                            <span class="app-usage"><?= formatSeconds($app['total_seconds']) ?></span>
                        </div>
                        <span class="app-percentage"><?= $appPercentage ?>%</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($topApplications)): ?>
                        <li style="text-align: center; padding: 2rem; color: #999;">
                            No hay datos de aplicaciones hoy
                        </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Most Visited Websites -->
            <div class="chart-container">
                <h4 style="margin-bottom: 1rem;"><i class="bi bi-globe"></i> Most Visited Websites</h4>
                <p style="color: #666; font-size: 0.85rem; margin-bottom: 1rem;">Today's browsing activity</p>
                <ul class="website-list">
                    <?php foreach ($topWebsites as $idx => $website): ?>
                    <li class="website-item">
                        <div class="website-rank"><?= $idx + 1 ?></div>
                        <div class="website-info">
                            <span class="website-name"><?= htmlspecialchars($website['website_name']) ?></span>
                            <span class="visit-count"><?= formatSeconds($website['total_seconds']) ?></span>
                        </div>
                        <span class="app-percentage"><?= $website['visit_count'] ?> visits</span>
                    </li>
                    <?php endforeach; ?>
                    <?php if (empty($topWebsites)): ?>
                        <li style="text-align: center; padding: 2rem; color: #999;">
                            No hay datos de navegación hoy
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

