<?php
/**
 * Dashboard principal del panel admin — estilo LawyerDesk Client Portal.
 */
require_once __DIR__ . '/admin_auth.php';

$pageTitle   = 'Dashboard';
$currentPage = 'dashboard';

// ==================== PERÍODO SELECCIONADO ====================
$period = $_GET['period'] ?? 'today';
if (!in_array($period, ['today', 'week', 'month', 'custom'])) $period = 'today';

$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom)) $customFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo))   $customTo = '';

switch ($period) {
    case 'week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Esta Semana';
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Este Mes';
        break;
    case 'custom':
        $dateFrom = $customFrom ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo   = $customTo ?: date('Y-m-d');
        $periodLabel = date('d/m', strtotime($dateFrom)) . ' — ' . date('d/m', strtotime($dateTo));
        break;
    default: // today
        $dateFrom = date('Y-m-d');
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Hoy';
        break;
}

// ==================== QUERIES ====================
$scope  = scopeFilter();
$params = $scope['params'];

// KPIs
$sql = "
    SELECT
        COUNT(DISTINCT u.id) AS total_users,
        COUNT(DISTINCT d.id) AS total_devices,
        COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 15 MINUTE THEN d.id END) AS online_now,
        COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 2 MINUTE THEN d.id END) AS active_now,
        COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 15 MINUTE
                              AND d.last_seen_at < NOW() - INTERVAL 2 MINUTE THEN d.id END) AS away_now
    FROM keeper_users u
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
    WHERE u.status = 'active'
    {$scope['sql']}
";
$st = $pdo->prepare($sql);
$st->execute($params);
$kpi = $st->fetch(PDO::FETCH_ASSOC);

// Actividad del período
$sqlToday = "
    SELECT
        COALESCE(SUM(a.active_seconds), 0) AS today_active,
        COALESCE(SUM(a.idle_seconds), 0) AS today_idle,
        COALESCE(SUM(a.call_seconds), 0) AS today_calls,
        COALESCE(SUM(a.work_hours_active_seconds), 0) AS today_work,
        COALESCE(SUM(a.work_hours_idle_seconds), 0) AS today_work_idle,
        COALESCE(SUM(a.lunch_active_seconds), 0) AS today_lunch,
        COALESCE(SUM(a.after_hours_active_seconds), 0) AS today_after,
        COUNT(DISTINCT a.user_id) AS users_with_activity
    FROM keeper_activity_day a
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = a.user_id
    WHERE a.day_date BETWEEN :dfrom AND :dto
    {$scope['sql']}
";
$st = $pdo->prepare($sqlToday);
$st->execute(array_merge($params, [':dfrom' => $dateFrom, ':dto' => $dateTo]));
$today = $st->fetch(PDO::FETCH_ASSOC);

// Primer ingreso: primera ventana después de las 5 AM
$stFirstLogin = $pdo->prepare("
    SELECT MIN(we.start_at)
    FROM keeper_window_episode we
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = we.user_id
    WHERE we.day_date BETWEEN :dfrom AND :dto
      AND TIME(we.start_at) >= '05:00:00'
    {$scope['sql']}
");
$stFirstLogin->execute(array_merge($params, [':dfrom' => $dateFrom, ':dto' => $dateTo]));
$today['first_login_today'] = $stFirstLogin->fetchColumn() ?: null;

// Horas del mes (siempre mes actual para KPI)
$sqlMonth = "
    SELECT
        COALESCE(SUM(a.active_seconds), 0) AS month_active,
        COALESCE(SUM(a.work_hours_active_seconds), 0) AS month_work,
        COUNT(DISTINCT a.day_date) AS month_days
    FROM keeper_activity_day a
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = a.user_id
    WHERE a.day_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND a.day_date <= CURDATE()
    {$scope['sql']}
";
$st = $pdo->prepare($sqlMonth);
$st->execute($params);
$month = $st->fetch(PDO::FETCH_ASSOC);

// Top aplicaciones del período (por proceso)
$sqlApps = "
    SELECT
        w.process_name,
        SUM(w.duration_seconds) AS total_sec
    FROM keeper_window_episode w
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id
    WHERE w.day_date BETWEEN :dfrom AND :dto
      AND w.process_name IS NOT NULL
      AND w.process_name != ''
    {$scope['sql']}
    GROUP BY w.process_name
    ORDER BY total_sec DESC
    LIMIT 6
";
$st = $pdo->prepare($sqlApps);
$st->execute(array_merge($params, [':dfrom' => $dateFrom, ':dto' => $dateTo]));
$topApps = $st->fetchAll(PDO::FETCH_ASSOC);
$totalAppsSec = array_sum(array_column($topApps, 'total_sec')) ?: 1;

// Últimos dispositivos conectados (siempre en tiempo real)
$sqlRecent = "
    SELECT
        u.display_name,
        d.device_name,
        d.last_seen_at
    FROM keeper_devices d
    INNER JOIN keeper_users u ON u.id = d.user_id
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    WHERE d.status = 'active'
    {$scope['sql']}
    ORDER BY d.last_seen_at DESC
    LIMIT 8
";
$st = $pdo->prepare($sqlRecent);
$st->execute($params);
$recentDevices = $st->fetchAll(PDO::FETCH_ASSOC);

foreach ($recentDevices as &$dev) {
    $seenAgo = $dev['last_seen_at'] ? time() - strtotime($dev['last_seen_at']) : 99999;
    if ($seenAgo < 120) $dev['device_status'] = 'active';
    elseif ($seenAgo < 900) $dev['device_status'] = 'away';
    else $dev['device_status'] = 'inactive';
}
unset($dev);

// Top 5 usuarios del período — solo horario laboral
$sqlTop = "
    SELECT
        u.display_name,
        SUM(a.active_seconds) AS active_sec,
        SUM(a.work_hours_active_seconds) AS work_sec
    FROM keeper_activity_day a
    INNER JOIN keeper_users u ON u.id = a.user_id
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    WHERE a.day_date BETWEEN :dfrom AND :dto
    {$scope['sql']}
    GROUP BY u.id
    ORDER BY work_sec DESC
    LIMIT 5
";
$st = $pdo->prepare($sqlTop);
$st->execute(array_merge($params, [':dfrom' => $dateFrom, ':dto' => $dateTo]));
$topUsers = $st->fetchAll(PDO::FETCH_ASSOC);

// ==================== HELPERS ====================
function fmtHours(int $seconds): string {
    if ($seconds <= 0) return '0h 0m';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return "{$h}h {$m}m";
}

function fmtHoursShort(int $seconds): string {
    if ($seconds <= 0) return '0h';
    $h = floor($seconds / 3600);
    return "{$h}h";
}

function statusDot(?string $status): string {
    return match ($status) {
        'active'   => '<span class="w-2.5 h-2.5 bg-emerald-500 rounded-full inline-block"></span>',
        'away'     => '<span class="w-2.5 h-2.5 bg-amber-400 rounded-full inline-block"></span>',
        default    => '<span class="w-2.5 h-2.5 bg-gray-300 rounded-full inline-block"></span>',
    };
}

function statusBadge(?string $status): string {
    return match ($status) {
        'active'   => '<span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>Activo</span>',
        'away'     => '<span class="inline-flex items-center gap-1 text-xs font-medium text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>Ausente</span>',
        default    => '<span class="inline-flex items-center gap-1 text-xs font-medium text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full"><span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>Inactivo</span>',
    };
}

function timeAgo(?string $datetime): string {
    if (!$datetime) return 'Nunca';
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Ahora';
    if ($diff < 3600) return floor($diff / 60) . 'm';
    if ($diff < 86400) return floor($diff / 3600) . 'h';
    return floor($diff / 86400) . 'd';
}

// Productivity calc — solo horario laboral, descontando apps de descanso
$todayActive = (int)($today['today_active'] ?? 0);
$todayIdle   = (int)($today['today_idle'] ?? 0);
$todayTotal  = $todayActive + $todayIdle;
$todayWorkActive = (int)($today['today_work'] ?? 0);
$todayWorkIdle   = (int)($today['today_work_idle'] ?? 0);

// Leisure deduction (aggregate for all scoped users — apps + windows)
$leisureTotal = 0;
$leisureData = getLeisureApps();
$lApps = $leisureData['apps'];
$lWins = $leisureData['windows'];
if (!empty($lApps) || !empty($lWins)) {
    $conditions = [];
    $leisParams = [':l_dfrom' => $dateFrom, ':l_dto' => $dateTo];
    if (!empty($lApps)) {
        $phL = [];
        foreach ($lApps as $i => $app) {
            $phL[] = ":lapp_{$i}";
            $leisParams[":lapp_{$i}"] = $app;
        }
        $conditions[] = 'w.process_name IN (' . implode(',', $phL) . ')';
    }
    if (!empty($lWins)) {
        $likes = [];
        foreach ($lWins as $i => $win) {
            $likes[] = "w.window_title LIKE :lwin_{$i}";
            $leisParams[":lwin_{$i}"] = '%' . $win . '%';
        }
        $conditions[] = '(' . implode(' OR ', $likes) . ')';
    }
    $orClause = implode(' OR ', $conditions);
    $leisureSql = "
        SELECT COALESCE(SUM(w.duration_seconds), 0) AS leisure_sec
        FROM keeper_window_episode w
        LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id
        WHERE w.day_date BETWEEN :l_dfrom AND :l_dto
          AND ($orClause)
        {$scope['sql']}
    ";
    $stLeis = $pdo->prepare($leisureSql);
    $stLeis->execute(array_merge($leisParams, $scope['params']));
    $leisureTotal = (int)$stLeis->fetchColumn();
}

$workTotal = $todayWorkActive + $todayWorkIdle;
$productiveSec = max(0, $todayWorkActive - $leisureTotal);
$productivityPct = $workTotal > 0 ? round(($productiveSec / $workTotal) * 100) : 0;

// Time breakdown
$workSec  = (int)($today['today_work'] ?? 0);
$lunchSec = (int)($today['today_lunch'] ?? 0);
$afterSec = (int)($today['today_after'] ?? 0);
$callSec  = (int)($today['today_calls'] ?? 0);
$breakdownTotal = $workSec + $lunchSec + $afterSec + $callSec;
$breakdownTotal = $breakdownTotal ?: 1;

$firstLogin = $today['first_login_today']
    ? date('g:i A', strtotime($today['first_login_today']))
    : '--:--';

$monthHours = round((int)($month['month_active'] ?? 0) / 3600);

// App colors
$appColors = ['#003a5d', '#2d87ad', '#198754', '#f59e0b', '#be1622', '#9d9d9c'];

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Welcome Hero -->
<div class="bg-gradient-to-b from-corp-50 to-white rounded-2xl border border-corp-100 px-4 py-4 sm:px-8 sm:py-6 mb-4 sm:mb-8">
    <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3 sm:gap-4">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-dark mb-1">Panel de Control</h2>
            <p class="text-sm text-muted">Período: <span class="font-semibold text-corp-800"><?= $periodLabel ?></span></p>
        </div>
        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2 sm:gap-3">
            <!-- Period pills -->
            <div class="flex items-center gap-1 bg-white rounded-lg border border-gray-200 p-1">
                <a href="?period=today" class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors <?= $period === 'today' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">Hoy</a>
                <a href="?period=week" class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors <?= $period === 'week' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">Semana</a>
                <a href="?period=month" class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors <?= $period === 'month' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">Mes</a>
            </div>
            <!-- Custom range -->
            <form method="get" class="flex items-center gap-2">
                <input type="hidden" name="period" value="custom">
                <input type="date" name="from" value="<?= htmlspecialchars($period === 'custom' ? $dateFrom : '') ?>" class="w-[7.5rem] px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" title="Desde">
                <input type="date" name="to" value="<?= htmlspecialchars($period === 'custom' ? $dateTo : '') ?>" class="w-[7.5rem] px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" title="Hasta">
                <button type="submit" class="px-3 py-1.5 bg-corp-800 text-white text-xs font-medium rounded-lg hover:bg-corp-900 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- KPI Cards Row -->
<div class="grid grid-cols-2 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <!-- Active Staff -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-corp-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-dark"><?= (int)($kpi['active_now'] ?? 0) ?></p>
        <p class="text-xs text-muted mt-1">Personal Activo</p>
    </div>

    <!-- First Login Today -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-emerald-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
        </div>
        <p class="text-xl sm:text-3xl font-bold text-dark"><?= $firstLogin ?></p>
        <p class="text-xs text-muted mt-1">Primer Ingreso Hoy</p>
    </div>

    <!-- Total Break Time -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-amber-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-xl sm:text-3xl font-bold text-dark"><?= fmtHours((int)($today['today_idle'] ?? 0)) ?></p>
        <p class="text-xs text-muted mt-1">Tiempo Inactivo</p>
    </div>

    <!-- Hours This Month -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-dark"><?= $monthHours ?>h</p>
        <p class="text-xs text-muted mt-1">Horas Este Mes</p>
    </div>
</div>

<!-- Focus / Productivity / Breakdown Row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <!-- Focus Score -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <h3 class="text-base font-bold text-dark">Focus Score</h3>
        </div>
        <p class="text-xs text-muted mb-4">Nivel de dedicación promedio del equipo</p>

        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                <span class="text-sm font-medium text-dark">Focus Guard</span>
            </div>
            <span class="text-2xl font-bold text-corp-800"><?= number_format($productivityPct / 10, 1) ?></span>
        </div>

        <!-- Score bar -->
        <div class="relative h-3 rounded-full overflow-hidden bg-gray-100 mb-2">
            <div class="absolute inset-y-0 left-0 rounded-full" style="width: <?= $productivityPct ?>%; background: linear-gradient(to right, #be1622, #f59e0b, #198754);"></div>
            <div class="absolute inset-y-0 rounded-full w-3 h-3 bg-white border-2 border-corp-800 shadow-sm" style="left: calc(<?= $productivityPct ?>% - 6px); top: 0;"></div>
        </div>
        <div class="flex justify-between text-xs text-muted">
            <span>1</span><span>5</span><span>10</span>
        </div>

        <p class="text-xs text-muted mt-4 leading-relaxed">Keeper analiza patrones de interacción y metadatos de ventanas para evaluar la dedicación del servicio e identificar patrones de atención dividida.</p>
    </div>

    <!-- Productivity Donut -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            <h3 class="text-base font-bold text-dark">Productividad</h3>
        </div>
        <p class="text-xs text-muted mb-4 sm:mb-6">Tiempo productivo vs no productivo</p>

        <!-- Donut chart with CSS -->
        <div class="flex justify-center mb-4">
            <div class="relative w-32 h-32 sm:w-40 sm:h-40">
                <svg viewBox="0 0 36 36" class="w-32 h-32 sm:w-40 sm:h-40 transform -rotate-90">
                    <circle cx="18" cy="18" r="14" fill="none" stroke="#e5e7eb" stroke-width="4"/>
                    <circle cx="18" cy="18" r="14" fill="none" stroke="#003a5d" stroke-width="4" stroke-dasharray="<?= round($productivityPct * 0.88) ?> 88" stroke-linecap="round"/>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-2xl font-bold text-dark"><?= $productivityPct ?>%</span>
                    <span class="text-xs text-muted">Productivo</span>
                </div>
            </div>
        </div>

        <div class="flex items-center justify-center gap-6 text-xs">
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 bg-corp-800 rounded-full"></span>
                <span class="text-muted">Productivo</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 bg-gray-200 rounded-full"></span>
                <span class="text-muted">Otro</span>
            </div>
        </div>
    </div>

    <!-- Time Breakdown -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 01-2 2z"/></svg>
            <h3 class="text-base font-bold text-dark">Desglose de Tiempo</h3>
        </div>
        <p class="text-xs text-muted mb-5">Cómo se distribuye el tiempo</p>

        <div class="space-y-4">
            <?php
            $breakdown = [
                ['label' => 'Horario Laboral', 'sec' => $workSec,  'color' => '#003a5d'],
                ['label' => 'Almuerzo',        'sec' => $lunchSec, 'color' => '#198754'],
                ['label' => 'Fuera de Horario', 'sec' => $afterSec, 'color' => '#f59e0b'],
                ['label' => 'En Llamadas',      'sec' => $callSec,  'color' => '#be1622'],
            ];
            foreach ($breakdown as $b):
                $pct = round(($b['sec'] / $breakdownTotal) * 100);
            ?>
            <div>
                <div class="flex justify-between text-sm mb-1">
                    <span class="text-dark font-medium"><?= $b['label'] ?></span>
                    <span class="text-muted"><?= $pct ?>%</span>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width: <?= $pct ?>%; background: <?= $b['color'] ?>;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Apps & Active Staff Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <!-- Most Used Applications -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <h3 class="text-base font-bold text-dark">Aplicaciones Más Usadas</h3>
        </div>
        <p class="text-xs text-muted mb-5">Uso de aplicaciones — <?= $periodLabel ?></p>

        <div class="space-y-4">
            <?php if (empty($topApps)): ?>
                <p class="text-sm text-muted text-center py-4">Sin datos de aplicaciones</p>
            <?php else: ?>
                <?php foreach ($topApps as $idx => $app):
                    $appPct = round(($app['total_sec'] / $totalAppsSec) * 100);
                    $color = $appColors[$idx % count($appColors)];
                ?>
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gray-50 rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($app['process_name']) ?></span>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="text-xs text-muted"><?= $appPct ?>%</span>
                                <?= statusDot('active') ?>
                            </div>
                        </div>
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full" style="width: <?= $appPct ?>%; background: <?= $color ?>;"></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active Staff / Recent Activity -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <h3 class="text-base font-bold text-dark">Personal Activo</h3>
        </div>
        <p class="text-xs text-muted mb-5">Actividad reciente de conexiones</p>

        <div class="space-y-3">
            <?php if (empty($recentDevices)): ?>
                <p class="text-sm text-muted text-center py-4">Sin dispositivos conectados</p>
            <?php else: ?>
                <?php foreach ($recentDevices as $idx => $dev): ?>
                <div class="flex items-center justify-between py-1">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-6 h-6 bg-corp-50 text-corp-800 rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"><?= $idx + 1 ?></span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($dev['display_name'] ?? '') ?></p>
                            <p class="text-xs text-muted truncate"><?= htmlspecialchars($dev['device_name'] ?? '') ?></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0">
                        <span class="text-xs text-muted"><?= timeAgo($dev['last_seen_at'] ?? null) ?></span>
                        <?= statusDot($dev['device_status'] ?? null) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Top Users -->
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-4 sm:mb-8">
    <div class="flex items-center gap-2 mb-1">
        <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        <h3 class="text-base font-bold text-dark">Top Usuarios — <?= $periodLabel ?></h3>
    </div>
    <p class="text-xs text-muted mb-5">Los empleados con más horas en horario laboral</p>

    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-3 sm:gap-4">
        <?php if (empty($topUsers)): ?>
            <div class="col-span-full text-sm text-muted text-center py-4">Sin actividad registrada hoy</div>
        <?php else: ?>
            <?php foreach ($topUsers as $idx => $u): ?>
            <div class="text-center p-4 rounded-xl <?= $idx === 0 ? 'bg-corp-50 border border-corp-100' : 'bg-gray-50' ?>">
                <div class="w-10 h-10 <?= $idx === 0 ? 'bg-corp-800 text-white' : 'bg-white text-corp-800 border border-gray-200' ?> rounded-full flex items-center justify-center text-sm font-bold mx-auto mb-2">
                    <?= $idx + 1 ?>
                </div>
                <p class="text-sm font-semibold text-dark truncate"><?= htmlspecialchars($u['display_name'] ?? '') ?></p>
                <p class="text-lg font-bold text-corp-800 mt-1"><?= fmtHours((int)($u['work_sec'] ?? 0)) ?></p>
                <p class="text-xs text-muted">horario laboral</p>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
