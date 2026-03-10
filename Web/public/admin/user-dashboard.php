<?php
/**
 * User Dashboard — vista detallada de métricas por usuario.
 * Accedido desde users.php → user-dashboard.php?id=X
 */
require_once __DIR__ . '/admin_auth.php';

// ==================== VALIDACIÓN ====================
$userId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$userId) {
    header('Location: users.php');
    exit;
}

if (!canViewUser($pdo, $userId)) {
    header('Location: users.php');
    exit;
}

// ==================== DATOS DEL USUARIO ====================
$st = $pdo->prepare("
    SELECT
        u.id,
        u.cc,
        u.display_name,
        u.email,
        u.status AS user_status,
        u.created_at AS user_created_at,
        ua.firm_id,
        ua.area_id,
        ua.cargo_id,
        f.nombre AS firm_name,
        ar.nombre AS area_name,
        c.nombre AS cargo_name,
        soc.nombre AS sociedad_name
    FROM keeper_users u
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    LEFT JOIN keeper_sociedades soc ON soc.id = ua.sociedad_id
    LEFT JOIN keeper_firmas f ON f.id = ua.firm_id
    LEFT JOIN keeper_areas ar ON ar.id = ua.area_id
    LEFT JOIN keeper_cargos c ON c.id = ua.cargo_id
    WHERE u.id = :uid
    LIMIT 1
");
$st->execute([':uid' => $userId]);
$user = $st->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php');
    exit;
}

$pageTitle   = $user['display_name'] ?? 'Usuario';
$currentPage = 'users';

// ==================== PERÍODO SELECCIONADO ====================
$period = $_GET['period'] ?? 'today';
if (!in_array($period, ['today', 'week', 'month'])) $period = 'today';

// ==================== RANGO DE EPISODIOS (independiente) ====================
$epFrom = $_GET['ep_from'] ?? date('Y-m-d', strtotime('-30 days'));
$epTo   = $_GET['ep_to']   ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $epFrom)) $epFrom = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $epTo))   $epTo   = date('Y-m-d');

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
    default:
        $dateFrom = date('Y-m-d');
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Hoy';
        break;
}

// ==================== DISPOSITIVO ====================
$st = $pdo->prepare("
    SELECT d.id, d.device_name, d.client_version, d.last_seen_at, d.status, d.created_at
    FROM keeper_devices d
    WHERE d.user_id = :uid AND d.status = 'active'
    ORDER BY d.last_seen_at DESC
    LIMIT 1
");
$st->execute([':uid' => $userId]);
$device = $st->fetch(PDO::FETCH_ASSOC);

$seenAgo = ($device && $device['last_seen_at']) ? time() - strtotime($device['last_seen_at']) : 99999;
if ($seenAgo < 120)       $onlineStatus = 'Online';
elseif ($seenAgo < 900)   $onlineStatus = 'Ausente';
else                       $onlineStatus = 'Offline';

// ==================== ACTIVIDAD DEL PERÍODO ====================
$st = $pdo->prepare("
    SELECT
        COALESCE(SUM(a.active_seconds), 0) AS active_sec,
        COALESCE(SUM(a.idle_seconds), 0) AS idle_sec,
        COALESCE(SUM(a.call_seconds), 0) AS call_sec,
        COALESCE(SUM(a.work_hours_active_seconds), 0) AS work_sec,
        COALESCE(SUM(a.work_hours_idle_seconds), 0) AS work_idle_sec,
        COALESCE(SUM(a.lunch_active_seconds), 0) AS lunch_sec,
        COALESCE(SUM(a.lunch_idle_seconds), 0) AS lunch_idle_sec,
        COALESCE(SUM(a.after_hours_active_seconds), 0) AS after_sec,
        COALESCE(SUM(a.after_hours_idle_seconds), 0) AS after_idle_sec,
        (SELECT MIN(we.start_at) FROM keeper_window_episode we
            WHERE we.user_id = :uid_w AND we.day_date BETWEEN :from_w AND :to_w
              AND TIME(we.start_at) >= '05:00:00') AS first_event,
        MAX(a.last_event_at) - INTERVAL 5 HOUR AS last_event,
        COUNT(DISTINCT a.day_date) AS days_count
    FROM keeper_activity_day a
    WHERE a.user_id = :uid
      AND a.day_date BETWEEN :from AND :to
");
$st->execute([':uid' => $userId, ':from' => $dateFrom, ':to' => $dateTo, ':uid_w' => $userId, ':from_w' => $dateFrom, ':to_w' => $dateTo]);
$activity = $st->fetch(PDO::FETCH_ASSOC);

$activeSec = (int)($activity['active_sec'] ?? 0);
$idleSec   = (int)($activity['idle_sec'] ?? 0);
$callSec   = (int)($activity['call_sec'] ?? 0);
$workSec   = (int)($activity['work_sec'] ?? 0);
$workIdleSec = (int)($activity['work_idle_sec'] ?? 0);
$lunchSec  = (int)($activity['lunch_sec'] ?? 0);
$afterSec  = (int)($activity['after_sec'] ?? 0);
$totalSec  = $activeSec + $idleSec;

// Leisure deduction for this user/period (apps + windows)
$leisureSec = 0;
$leisureData = getLeisureApps();
$lApps = $leisureData['apps'];
$lWins = $leisureData['windows'];
if (!empty($lApps) || !empty($lWins)) {
    $conditions = [];
    $lParams    = [$userId, $dateFrom, $dateTo];
    if (!empty($lApps)) {
        $phA = implode(',', array_fill(0, count($lApps), '?'));
        $conditions[] = "w.process_name IN ($phA)";
        $lParams = array_merge($lParams, array_values($lApps));
    }
    if (!empty($lWins)) {
        $likes = [];
        foreach ($lWins as $win) {
            $likes[] = "w.window_title LIKE ?";
            $lParams[] = '%' . $win . '%';
        }
        $conditions[] = '(' . implode(' OR ', $likes) . ')';
    }
    $orClause = implode(' OR ', $conditions);
    $stL = $pdo->prepare("
        SELECT COALESCE(SUM(w.duration_seconds), 0) AS leisure_sec
        FROM keeper_window_episode w
        WHERE w.user_id = ?
          AND w.day_date BETWEEN ? AND ?
          AND ($orClause)
    ");
    $stL->execute($lParams);
    $leisureSec = (int)$stL->fetchColumn();
}

// Productividad: solo horario laboral, descontando apps de descanso
$workTotal = $workSec + $workIdleSec;
$productiveSec = max(0, $workSec - $leisureSec);
$productivityPct = $workTotal > 0 ? round(($productiveSec / $workTotal) * 100) : 0;
$focusScore = $workTotal > 0 ? round(($productiveSec / $workTotal) * 10, 1) : 0;

$firstEvent = $activity['first_event']
    ? date('g:i A', strtotime($activity['first_event']))
    : '--:--';
$lastEvent = $activity['last_event']
    ? date('g:i A', strtotime($activity['last_event']))
    : '--:--';

// ==================== ÚLTIMOS 7 DÍAS (para gráfico) ====================
$st = $pdo->prepare("
    SELECT
        a.day_date,
        SUM(a.active_seconds) AS active_sec,
        SUM(a.idle_seconds) AS idle_sec,
        SUM(a.work_hours_active_seconds) AS work_sec,
        (SELECT MIN(we.start_at) FROM keeper_window_episode we
            WHERE we.user_id = a.user_id AND we.day_date = a.day_date
              AND TIME(we.start_at) >= '05:00:00') AS first_event,
        MAX(a.last_event_at) - INTERVAL 5 HOUR AS last_event
    FROM keeper_activity_day a
    WHERE a.user_id = :uid
      AND a.day_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
      AND a.day_date <= CURDATE()
    GROUP BY a.day_date
    ORDER BY a.day_date ASC
");
$st->execute([':uid' => $userId]);
$weekDays = $st->fetchAll(PDO::FETCH_ASSOC);

// Armar array de 7 días completos
$weekChart = [];
$dayNames = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $dayOfWeek = (int)date('w', strtotime($d));
    $weekChart[$d] = [
        'label'  => $dayNames[$dayOfWeek] . ' ' . date('d', strtotime($d)),
        'active' => 0,
        'idle'   => 0,
        'work'   => 0,
    ];
}
foreach ($weekDays as $wd) {
    if (isset($weekChart[$wd['day_date']])) {
        $weekChart[$wd['day_date']]['active'] = (int)$wd['active_sec'];
        $weekChart[$wd['day_date']]['idle']   = (int)$wd['idle_sec'];
        $weekChart[$wd['day_date']]['work']   = (int)$wd['work_sec'];
    }
}
$maxBarSec = max(1, max(array_map(fn($d) => $d['active'] + $d['idle'], $weekChart)));

// ==================== TOP APLICACIONES ====================
$st = $pdo->prepare("
    SELECT
        w.process_name,
        w.app_name,
        SUM(w.duration_seconds) AS total_sec,
        COUNT(*) AS episodes
    FROM keeper_window_episode w
    WHERE w.user_id = :uid
      AND w.day_date BETWEEN :from AND :to
      AND w.process_name IS NOT NULL
      AND w.process_name != ''
    GROUP BY w.process_name, w.app_name
    ORDER BY total_sec DESC
    LIMIT 8
");
$st->execute([':uid' => $userId, ':from' => $dateFrom, ':to' => $dateTo]);
$topApps = $st->fetchAll(PDO::FETCH_ASSOC);
$totalAppsSec = array_sum(array_column($topApps, 'total_sec')) ?: 1;

// ==================== EPISODIOS (último mes por defecto, personalizable) ====================
$st = $pdo->prepare("
    SELECT
        w.process_name,
        w.app_name,
        w.window_title,
        w.start_at,
        w.end_at,
        w.duration_seconds,
        w.is_in_call,
        w.day_date
    FROM keeper_window_episode w
    WHERE w.user_id = :uid
      AND w.day_date BETWEEN :from AND :to
    ORDER BY w.start_at DESC
");
$st->execute([':uid' => $userId, ':from' => $epFrom, ':to' => $epTo]);
$recentEpisodes = $st->fetchAll(PDO::FETCH_ASSOC);

// ==================== AJAX: episodios JSON ====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'episodes') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_map(function($i, $ep) {
        $dur = (int)($ep['duration_seconds'] ?? 0);
        if ($dur >= 3600) $durStr = floor($dur/3600) . 'h ' . floor(($dur%3600)/60) . 'm';
        elseif ($dur >= 60) $durStr = floor($dur/60) . 'm ' . ($dur%60) . 's';
        else $durStr = $dur . 's';
        return [
            'idx'       => $i,
            'date'      => $ep['day_date'] ?? '',
            'start'     => $ep['start_at'] ? date('H:i', strtotime($ep['start_at'])) : '--',
            'end'       => $ep['end_at']   ? date('H:i', strtotime($ep['end_at']))   : '--',
            'app'       => $ep['app_name'] ?? $ep['process_name'] ?? '--',
            'title'     => mb_strimwidth($ep['window_title'] ?? '', 0, 60, '...'),
            'titleFull' => $ep['window_title'] ?? '',
            'dur'       => $durStr,
            'durSec'    => $dur,
            'call'      => (bool)$ep['is_in_call'],
        ];
    }, array_keys($recentEpisodes), $recentEpisodes), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE);
    exit;
}

// ==================== AJAX: CSV descarga ====================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="episodios_' . ($user['display_name'] ?? 'usuario') . '_' . $epFrom . '_' . $epTo . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM UTF-8
    fputcsv($out, ['Fecha', 'Inicio', 'Fin', 'Aplicación', 'Ventana', 'Duración (s)', 'En Llamada']);
    foreach ($recentEpisodes as $ep) {
        fputcsv($out, [
            $ep['day_date'] ?? '',
            $ep['start_at'] ?? '',
            $ep['end_at'] ?? '',
            $ep['app_name'] ?? $ep['process_name'] ?? '',
            $ep['window_title'] ?? '',
            (int)($ep['duration_seconds'] ?? 0),
            $ep['is_in_call'] ? 'Sí' : 'No',
        ]);
    }
    fclose($out);
    exit;
}

// ==================== RESUMEN MENSUAL ====================
$st = $pdo->prepare("
    SELECT
        COALESCE(SUM(a.active_seconds), 0) AS month_active,
        COALESCE(SUM(a.work_hours_active_seconds), 0) AS month_work,
        COUNT(DISTINCT a.day_date) AS month_days
    FROM keeper_activity_day a
    WHERE a.user_id = :uid
      AND a.day_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
      AND a.day_date <= CURDATE()
");
$st->execute([':uid' => $userId]);
$monthSummary = $st->fetch(PDO::FETCH_ASSOC);
$monthHours = round((int)($monthSummary['month_active'] ?? 0) / 3600);
$monthWorkHours = round((int)($monthSummary['month_work'] ?? 0) / 3600);
$monthDays = (int)($monthSummary['month_days'] ?? 0);
$avgDailyHours = $monthDays > 0 ? round(((int)$monthSummary['month_active'] / $monthDays) / 3600, 1) : 0;

// ==================== HORARIO ====================
$st = $pdo->prepare("
    SELECT work_start_time, work_end_time, lunch_start_time, lunch_end_time
    FROM keeper_work_schedules
    WHERE user_id = :uid AND is_active = 1
    LIMIT 1
");
$st->execute([':uid' => $userId]);
$schedule = $st->fetch(PDO::FETCH_ASSOC);

// ==================== HELPERS ====================
function fmtHM(int $seconds): string {
    if ($seconds <= 0) return '0h 0m';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return "{$h}h {$m}m";
}

function fmtHours(int $seconds): string {
    if ($seconds <= 0) return '0h';
    return round($seconds / 3600) . 'h';
}

function focusBadge(float $score): string {
    if ($score >= 8) return '<span class="inline-flex items-center text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full">Excelente</span>';
    if ($score >= 6) return '<span class="inline-flex items-center text-xs font-semibold text-corp-800 bg-corp-50 px-2.5 py-1 rounded-full">Bueno</span>';
    if ($score >= 4) return '<span class="inline-flex items-center text-xs font-semibold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full">Regular</span>';
    return '<span class="inline-flex items-center text-xs font-semibold text-accent-500 bg-red-50 px-2.5 py-1 rounded-full">Bajo</span>';
}

function focusColor(float $score): string {
    if ($score >= 8) return 'text-emerald-600';
    if ($score >= 6) return 'text-corp-800';
    if ($score >= 4) return 'text-amber-600';
    return 'text-accent-500';
}

function statusDotClass(string $status): string {
    return match ($status) {
        'Online'  => 'bg-emerald-500',
        'Ausente' => 'bg-amber-400',
        default   => 'bg-gray-300',
    };
}

function statusBadge(string $status): string {
    return match ($status) {
        'Online'  => '<span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>Online</span>',
        'Ausente' => '<span class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 bg-amber-50 px-2.5 py-1 rounded-full"><span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>Ausente</span>',
        default   => '<span class="inline-flex items-center gap-1.5 text-xs font-semibold text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full"><span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>Offline</span>',
    };
}

$appColors = ['#003a5d', '#2d87ad', '#198754', '#f59e0b', '#be1622', '#9d9d9c', '#6366f1', '#ec4899'];

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Back + User Header -->
<div class="mb-6">
    <a href="users.php" class="inline-flex items-center gap-1.5 text-sm text-muted hover:text-corp-800 transition-colors mb-4">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        Volver a Usuarios
    </a>

    <div class="bg-white rounded-2xl border border-gray-100 p-6">
        <div class="flex items-center gap-5">
            <!-- Avatar -->
            <div class="relative flex-shrink-0">
                <div class="w-16 h-16 bg-corp-50 rounded-full flex items-center justify-center">
                    <span class="text-2xl font-bold text-corp-800"><?= strtoupper(substr($user['display_name'] ?? 'U', 0, 1)) ?></span>
                </div>
                <span class="absolute -bottom-0.5 -right-0.5 w-4.5 h-4.5 <?= statusDotClass($onlineStatus) ?> rounded-full border-2 border-white"></span>
            </div>

            <!-- Info -->
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1">
                    <h2 class="text-xl font-bold text-dark truncate"><?= htmlspecialchars($user['display_name'] ?? '') ?></h2>
                    <?= statusBadge($onlineStatus) ?>
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted">
                    <?php if ($user['cargo_name']): ?>
                        <span class="flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                            <?= htmlspecialchars($user['cargo_name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($user['sociedad_name'] ?? null): ?>
                        <span class="flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <?= htmlspecialchars($user['sociedad_name']) ?>
                        </span>
                    <?php endif; ?>
                    <?php if ($user['firm_name']): ?>
                        <span class="flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                            <?= htmlspecialchars($user['firm_name']) ?>
                            <?php if ($user['area_name']): ?>
                                — <?= htmlspecialchars($user['area_name']) ?>
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <span class="flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <?= htmlspecialchars($user['email'] ?? '') ?>
                    </span>
                    <?php if ($user['cc']): ?>
                    <span class="flex items-center gap-1">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/></svg>
                        CC: <?= htmlspecialchars($user['cc']) ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Device info -->
            <?php if ($device): ?>
            <div class="hidden lg:block flex-shrink-0 text-right">
                <p class="text-sm font-medium text-dark"><?= htmlspecialchars($device['device_name'] ?? 'Sin nombre') ?></p>
                <p class="text-xs text-muted">v<?= htmlspecialchars($device['client_version'] ?? '?') ?></p>
                <?php if ($device['last_seen_at']): ?>
                    <p class="text-xs text-muted mt-0.5">Visto: <?= date('d/m H:i', strtotime($device['last_seen_at'])) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Period Selector -->
<div class="flex items-center justify-between mb-6">
    <div class="flex items-center gap-1 bg-white rounded-lg border border-gray-200 p-1">
        <a href="?id=<?= $userId ?>&period=today"
           class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors <?= $period === 'today' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">
            Hoy
        </a>
        <a href="?id=<?= $userId ?>&period=week"
           class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors <?= $period === 'week' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">
            Semana
        </a>
        <a href="?id=<?= $userId ?>&period=month"
           class="px-4 py-1.5 rounded-md text-sm font-medium transition-colors <?= $period === 'month' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">
            Mes
        </a>
    </div>
    <div class="flex items-center gap-2 text-sm text-muted">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span><?= $periodLabel ?>: <?= date('d/m', strtotime($dateFrom)) ?><?= $dateFrom !== $dateTo ? ' — ' . date('d/m', strtotime($dateTo)) : '' ?></span>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <!-- Active Time -->
    <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
        <div class="w-9 h-9 bg-corp-50 rounded-lg flex items-center justify-center mx-auto mb-2">
            <svg class="w-4.5 h-4.5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl font-bold text-dark"><?= fmtHM($activeSec) ?></p>
        <p class="text-xs text-muted mt-0.5">Tiempo Activo</p>
    </div>

    <!-- Work Hours -->
    <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
        <div class="w-9 h-9 bg-emerald-50 rounded-lg flex items-center justify-center mx-auto mb-2">
            <svg class="w-4.5 h-4.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl font-bold text-dark"><?= fmtHM($workSec) ?></p>
        <p class="text-xs text-muted mt-0.5">Horario Laboral</p>
    </div>

    <!-- Productivity -->
    <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
        <div class="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center mx-auto mb-2">
            <svg class="w-4.5 h-4.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
        </div>
        <p class="text-2xl font-bold text-dark"><?= $productivityPct ?>%</p>
        <p class="text-xs text-muted mt-0.5">Productividad</p>
    </div>

    <!-- Focus Score -->
    <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
        <div class="w-9 h-9 bg-amber-50 rounded-lg flex items-center justify-center mx-auto mb-2">
            <svg class="w-4.5 h-4.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
        </div>
        <p class="text-2xl font-bold <?= focusColor($focusScore) ?>"><?= number_format($focusScore, 1) ?></p>
        <p class="text-xs text-muted mt-0.5">Focus Score</p>
    </div>

    <!-- First Login -->
    <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
        <div class="w-9 h-9 bg-purple-50 rounded-lg flex items-center justify-center mx-auto mb-2">
            <svg class="w-4.5 h-4.5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
        </div>
        <p class="text-2xl font-bold text-dark"><?= $firstEvent ?></p>
        <p class="text-xs text-muted mt-0.5">Primer Ingreso</p>
    </div>
</div>

<!-- Focus + Productivity + Time Breakdown -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-6">
    <!-- Focus Score Detail -->
    <div class="bg-white rounded-xl border border-gray-100 p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            <h3 class="text-base font-bold text-dark">Focus Score</h3>
        </div>
        <p class="text-xs text-muted mb-4">Nivel de dedicación del usuario</p>

        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <?= focusBadge($focusScore) ?>
            </div>
            <span class="text-3xl font-bold <?= focusColor($focusScore) ?>"><?= number_format($focusScore, 1) ?></span>
        </div>

        <!-- Score bar -->
        <div class="relative h-3 rounded-full overflow-hidden bg-gray-100 mb-2">
            <div class="absolute inset-y-0 left-0 rounded-full" style="width: <?= $productivityPct ?>%; background: linear-gradient(to right, #be1622, #f59e0b, #198754);"></div>
            <div class="absolute inset-y-0 rounded-full w-3 h-3 bg-white border-2 border-corp-800 shadow-sm" style="left: calc(<?= $productivityPct ?>% - 6px); top: 0;"></div>
        </div>
        <div class="flex justify-between text-xs text-muted mb-4">
            <span>1</span><span>5</span><span>10</span>
        </div>

        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-muted">Primer ingreso</span>
                <span class="font-medium text-dark"><?= $firstEvent ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted">Último evento</span>
                <span class="font-medium text-dark"><?= $lastEvent ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-muted">En llamadas</span>
                <span class="font-medium text-dark"><?= fmtHM($callSec) ?></span>
            </div>
        </div>
    </div>

    <!-- Productivity Donut -->
    <div class="bg-white rounded-xl border border-gray-100 p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            <h3 class="text-base font-bold text-dark">Productividad</h3>
        </div>
        <p class="text-xs text-muted mb-5">Tiempo activo vs inactivo</p>

        <div class="flex justify-center mb-4">
            <div class="relative w-36 h-36">
                <svg viewBox="0 0 36 36" class="w-36 h-36 transform -rotate-90">
                    <circle cx="18" cy="18" r="14" fill="none" stroke="#e5e7eb" stroke-width="4"/>
                    <circle cx="18" cy="18" r="14" fill="none" stroke="#003a5d" stroke-width="4" stroke-dasharray="<?= round($productivityPct * 0.88) ?> 88" stroke-linecap="round"/>
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="text-2xl font-bold text-dark"><?= $productivityPct ?>%</span>
                    <span class="text-xs text-muted">Productivo</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 bg-corp-800 rounded-full flex-shrink-0"></span>
                <div>
                    <p class="text-muted text-xs">Activo</p>
                    <p class="font-semibold text-dark"><?= fmtHM($activeSec) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="w-2.5 h-2.5 bg-gray-200 rounded-full flex-shrink-0"></span>
                <div>
                    <p class="text-muted text-xs">Inactivo</p>
                    <p class="font-semibold text-dark"><?= fmtHM($idleSec) ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Time Breakdown -->
    <div class="bg-white rounded-xl border border-gray-100 p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <h3 class="text-base font-bold text-dark">Desglose de Tiempo</h3>
        </div>
        <p class="text-xs text-muted mb-5">Distribución por franjas horarias</p>

        <?php
        $breakdownTotal = $workSec + $lunchSec + $afterSec + $callSec;
        $breakdownTotal = $breakdownTotal ?: 1;
        $breakdown = [
            ['label' => 'Horario Laboral', 'sec' => $workSec,  'color' => '#003a5d'],
            ['label' => 'Almuerzo',        'sec' => $lunchSec, 'color' => '#198754'],
            ['label' => 'Fuera de Horario', 'sec' => $afterSec, 'color' => '#f59e0b'],
            ['label' => 'En Llamadas',      'sec' => $callSec,  'color' => '#be1622'],
        ];
        ?>
        <div class="space-y-3">
            <?php foreach ($breakdown as $b):
                $pct = round(($b['sec'] / $breakdownTotal) * 100);
            ?>
            <div>
                <div class="flex justify-between items-center text-sm mb-1">
                    <span class="text-dark font-medium"><?= $b['label'] ?></span>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-muted"><?= fmtHM($b['sec']) ?></span>
                        <span class="text-xs font-semibold text-dark w-8 text-right"><?= $pct ?>%</span>
                    </div>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full transition-all" style="width: <?= $pct ?>%; background: <?= $b['color'] ?>;"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($schedule): ?>
        <div class="mt-4 pt-3 border-t border-gray-100 text-xs text-muted">
            <p>Horario: <?= substr($schedule['work_start_time'], 0, 5) ?> – <?= substr($schedule['work_end_time'], 0, 5) ?></p>
            <p>Almuerzo: <?= substr($schedule['lunch_start_time'], 0, 5) ?> – <?= substr($schedule['lunch_end_time'], 0, 5) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Weekly Activity Chart -->
<div class="bg-white rounded-xl border border-gray-100 p-6 mb-6">
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            <h3 class="text-base font-bold text-dark">Actividad Últimos 7 Días</h3>
        </div>
        <div class="flex items-center gap-4 text-xs">
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 bg-corp-800 rounded-sm"></span>
                <span class="text-muted">Activo</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-2.5 h-2.5 bg-gray-200 rounded-sm"></span>
                <span class="text-muted">Inactivo</span>
            </div>
        </div>
    </div>
    <p class="text-xs text-muted mb-5">Horas de actividad por día</p>

    <div class="flex items-end gap-3 h-52">
        <?php foreach ($weekChart as $date => $day):
            $totalDay = $day['active'] + $day['idle'];
            $barH  = $maxBarSec > 0 ? round(($day['active'] / $maxBarSec) * 100) : 0;
            $idleH = $maxBarSec > 0 ? round(($day['idle']   / $maxBarSec) * 100) : 0;
            $isToday = ($date === date('Y-m-d'));
        ?>
        <div class="flex-1 flex flex-col items-center gap-1 h-full justify-end">
            <!-- Stacked bars -->
            <div class="w-full flex flex-col items-center flex-1 justify-end">
                <?php if ($day['idle'] > 0): ?>
                <div class="w-full max-w-[40px] bg-gray-200 rounded-t-md transition-all" style="height: <?= max($idleH, 2) ?>%;"></div>
                <?php endif; ?>
                <?php if ($day['active'] > 0 || $day['idle'] === 0): ?>
                <div class="w-full max-w-[40px] <?= $isToday ? 'bg-corp-800' : 'bg-corp-800/70' ?> <?= $day['idle'] > 0 ? '' : 'rounded-t-md' ?> transition-all" style="height: <?= max($barH, 2) ?>%;"></div>
                <?php endif; ?>
            </div>
            <!-- Hours labels -->
            <div class="text-center leading-tight">
                <p class="text-xs font-semibold text-dark"><?= $day['active'] > 0 ? round($day['active'] / 3600, 1) . 'h' : '0' ?></p>
                <?php if ($day['idle'] > 0): ?>
                <p class="text-[10px] text-gray-400"><?= round($day['idle'] / 3600, 1) ?>h</p>
                <?php endif; ?>
            </div>
            <!-- Day label -->
            <p class="text-xs text-muted <?= $isToday ? 'font-bold text-corp-800' : '' ?>"><?= $day['label'] ?></p>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Apps & Episodes Row -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">
    <!-- Top Applications -->
    <div class="bg-white rounded-xl border border-gray-100 p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <h3 class="text-base font-bold text-dark">Aplicaciones Más Usadas</h3>
        </div>
        <p class="text-xs text-muted mb-5"><?= $periodLabel ?></p>

        <div class="space-y-3">
            <?php if (empty($topApps)): ?>
                <p class="text-sm text-muted text-center py-4">Sin datos de aplicaciones</p>
            <?php else: ?>
                <?php foreach ($topApps as $idx => $app):
                    $appPct = round(($app['total_sec'] / $totalAppsSec) * 100);
                    $color = $appColors[$idx % count($appColors)];
                ?>
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gray-50 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="text-xs font-bold text-gray-400"><?= $idx + 1 ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($app['process_name']) ?></span>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <span class="text-xs text-muted"><?= fmtHM((int)$app['total_sec']) ?></span>
                                <span class="text-xs font-semibold text-dark"><?= $appPct ?>%</span>
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

    <!-- Monthly Summary + Device -->
    <div class="space-y-5">
        <!-- Month Summary -->
        <div class="bg-white rounded-xl border border-gray-100 p-6">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <h3 class="text-base font-bold text-dark">Resumen del Mes</h3>
            </div>

            <div class="grid grid-cols-3 sm:gap-4 gap-2">
                <div class="text-center">
                    <p class="text-xl sm:text-2xl font-bold text-corp-800"><?= $monthHours ?>h</p>
                    <p class="text-xs text-muted">Total Activo</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-dark"><?= $monthWorkHours ?>h</p>
                    <p class="text-xs text-muted">En Horario</p>
                </div>
                <div class="text-center">
                    <p class="text-2xl font-bold text-dark"><?= $avgDailyHours ?>h</p>
                    <p class="text-xs text-muted">Promedio/Día</p>
                </div>
            </div>

            <div class="mt-3 pt-3 border-t border-gray-100 text-xs text-muted text-center">
                <?= $monthDays ?> días con actividad este mes
            </div>
        </div>

        <!-- Device Card -->
        <div class="bg-white rounded-xl border border-gray-100 p-6">
            <div class="flex items-center gap-2 mb-4">
                <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <h3 class="text-base font-bold text-dark">Dispositivo</h3>
            </div>

            <?php if ($device): ?>
            <div class="space-y-3">
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Nombre</span>
                    <span class="font-medium text-dark"><?= htmlspecialchars($device['device_name'] ?? '--') ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Versión Cliente</span>
                    <span class="font-medium text-dark">v<?= htmlspecialchars($device['client_version'] ?? '?') ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Estado</span>
                    <span><?= statusBadge($onlineStatus) ?></span>
                </div>
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Registrado</span>
                    <span class="font-medium text-dark"><?= $device['created_at'] ? date('d/m/Y', strtotime($device['created_at'])) : '--' ?></span>
                </div>
                <?php if ($device['last_seen_at']): ?>
                <div class="flex justify-between text-sm">
                    <span class="text-muted">Última conexión</span>
                    <span class="font-medium text-dark"><?= date('d/m/Y H:i', strtotime($device['last_seen_at'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
                <p class="text-sm text-muted text-center py-3">Sin dispositivo activo</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Window Episodes (paginated via Alpine.js + AJAX date range) -->
<div class="bg-white rounded-xl border border-gray-100 p-6 mb-6"
     x-data="episodePager()"
     x-init="init()">
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            <h3 class="text-base font-bold text-dark">Actividad de Ventanas</h3>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-xs text-muted" x-text="total + ' episodios'"></span>
        </div>
    </div>

    <!-- Date range + actions bar -->
    <div class="flex flex-wrap items-center gap-3 mb-4 mt-3">
        <div class="flex items-center gap-2">
            <label class="text-xs text-muted">Desde</label>
            <input type="date" x-model="dateFrom" @change="fetchData()"
                   class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
        </div>
        <div class="flex items-center gap-2">
            <label class="text-xs text-muted">Hasta</label>
            <input type="date" x-model="dateTo" @change="fetchData()"
                   class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
        </div>
        <!-- Quick presets -->
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-0.5">
            <button @click="setRange('7d')" class="px-2.5 py-1 rounded-md text-xs font-medium transition-colors"
                    :class="preset==='7d' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark'">7 días</button>
            <button @click="setRange('30d')" class="px-2.5 py-1 rounded-md text-xs font-medium transition-colors"
                    :class="preset==='30d' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark'">30 días</button>
            <button @click="setRange('90d')" class="px-2.5 py-1 rounded-md text-xs font-medium transition-colors"
                    :class="preset==='90d' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark'">3 meses</button>
        </div>
        <!-- Loading indicator -->
        <svg x-show="loading" class="w-4 h-4 text-corp-800 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        <!-- Spacer -->
        <div class="flex-1"></div>
        <!-- Search filter -->
        <div class="relative">
            <svg class="w-3.5 h-3.5 text-muted absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-model="searchEp" placeholder="Buscar app o ventana…"
                   class="pl-8 pr-3 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none w-52">
        </div>
        <!-- CSV Download -->
        <button @click="downloadCsv()"
                class="inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium text-muted hover:text-dark hover:bg-gray-50 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Descargar CSV
        </button>
    </div>

    <!-- Table -->
    <template x-if="total === 0 && !loading">
        <p class="text-sm text-muted text-center py-6">Sin episodios registrados en este rango</p>
    </template>
    <template x-if="total > 0">
    <div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Fecha</th>
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Hora</th>
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Aplicación</th>
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Ventana</th>
                    <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Duración</th>
                    <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Llamada</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <template x-for="ep in paged()" :key="ep.idx">
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="py-2.5 px-3 text-muted whitespace-nowrap text-xs" x-text="ep.date"></td>
                    <td class="py-2.5 px-3 text-muted whitespace-nowrap">
                        <span x-text="ep.start"></span>
                        <span class="text-gray-300">→</span>
                        <span x-text="ep.end"></span>
                    </td>
                    <td class="py-2.5 px-3 font-medium text-dark whitespace-nowrap" x-text="ep.app"></td>
                    <td class="py-2.5 px-3 text-muted max-w-xs truncate" :title="ep.titleFull" x-text="ep.title"></td>
                    <td class="py-2.5 px-3 text-right font-medium text-dark whitespace-nowrap" x-text="ep.dur"></td>
                    <td class="py-2.5 px-3 text-center">
                        <template x-if="ep.call">
                            <span class="inline-flex items-center text-xs font-medium text-accent-500 bg-red-50 px-2 py-0.5 rounded-full">📞 Sí</span>
                        </template>
                        <template x-if="!ep.call">
                            <span class="text-gray-300">—</span>
                        </template>
                    </td>
                </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Paginador -->
    <div class="flex items-center justify-between mt-4 pt-3 border-t border-gray-100">
        <p class="text-xs text-muted">
            Mostrando <span class="font-semibold text-dark" x-text="rangeFrom"></span>–<span class="font-semibold text-dark" x-text="rangeTo"></span> de <span class="font-semibold text-dark" x-text="total"></span>
        </p>
        <div class="flex items-center gap-1">
            <button @click="prev()" :disabled="page === 1"
                    class="px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    :class="page === 1 ? 'text-gray-300 cursor-not-allowed' : 'text-muted hover:text-dark hover:bg-gray-100'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </button>
            <template x-for="p in pageButtons()" :key="p">
                <button @click="goTo(p)"
                        class="min-w-[32px] px-2 py-1.5 rounded-lg text-xs font-medium transition-colors"
                        :class="p === page ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark hover:bg-gray-100'"
                        x-text="p"></button>
            </template>
            <button @click="next()" :disabled="page === pages"
                    class="px-2.5 py-1.5 rounded-lg text-xs font-medium transition-colors"
                    :class="page === pages ? 'text-gray-300 cursor-not-allowed' : 'text-muted hover:text-dark hover:bg-gray-100'">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </button>
        </div>
    </div>
    </div>
    </template>
</div>

<script>
function episodePager() {
    return {
        rows: [],
        searchEp: '',
        page: 1,
        perPage: 10,
        total: 0,
        pages: 1,
        loading: false,
        dateFrom: '<?= htmlspecialchars($epFrom) ?>',
        dateTo:   '<?= htmlspecialchars($epTo) ?>',
        preset:   '30d',
        userId:   <?= (int)$userId ?>,
        get rangeFrom() { return this.total > 0 ? Math.min((this.page - 1) * this.perPage + 1, this.total) : 0; },
        get rangeTo()   { return Math.min(this.page * this.perPage, this.total); },
        init() {
            // Carga inicial con datos ya renderizados por PHP
            this.rows = <?= json_encode(array_map(function($i, $ep) {
                $dur = (int)($ep['duration_seconds'] ?? 0);
                if ($dur >= 3600) $durStr = floor($dur/3600) . 'h ' . floor(($dur%3600)/60) . 'm';
                elseif ($dur >= 60) $durStr = floor($dur/60) . 'm ' . ($dur%60) . 's';
                else $durStr = $dur . 's';
                return [
                    'idx'       => $i,
                    'date'      => $ep['day_date'] ?? '',
                    'start'     => $ep['start_at'] ? date('H:i', strtotime($ep['start_at'])) : '--',
                    'end'       => $ep['end_at']   ? date('H:i', strtotime($ep['end_at']))   : '--',
                    'app'       => $ep['app_name'] ?? $ep['process_name'] ?? '--',
                    'title'     => mb_strimwidth($ep['window_title'] ?? '', 0, 60, '...'),
                    'titleFull' => $ep['window_title'] ?? '',
                    'dur'       => $durStr,
                    'call'      => (bool)$ep['is_in_call'],
                ];
            }, array_keys($recentEpisodes), $recentEpisodes), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            this.recalc();
            this.$watch('searchEp', () => { this.page = 1; this.recalc(); });
        },
        filteredRows() {
            if (!this.searchEp.trim()) return this.rows;
            const q = this.searchEp.toLowerCase().trim();
            return this.rows.filter(ep =>
                (ep.app || '').toLowerCase().includes(q) ||
                (ep.titleFull || '').toLowerCase().includes(q)
            );
        },
        recalc() {
            const data = this.filteredRows();
            this.total = data.length;
            this.pages = Math.max(1, Math.ceil(this.total / this.perPage));
            if (this.page > this.pages) this.page = 1;
        },
        async fetchData() {
            this.loading = true;
            this.preset = '';
            try {
                const url = `?id=${this.userId}&ep_from=${this.dateFrom}&ep_to=${this.dateTo}&ajax=episodes`;
                const res = await fetch(url);
                this.rows = await res.json();
                this.page = 1;
                this.recalc();
            } catch (e) {
                console.error('Error fetching episodes:', e);
            } finally {
                this.loading = false;
            }
        },
        setRange(key) {
            this.preset = key;
            const today = new Date();
            const fmt = d => d.toISOString().slice(0, 10);
            this.dateTo = fmt(today);
            if (key === '7d')  today.setDate(today.getDate() - 7);
            if (key === '30d') today.setDate(today.getDate() - 30);
            if (key === '90d') today.setDate(today.getDate() - 90);
            this.dateFrom = fmt(today);
            this.fetchData();
        },
        downloadCsv() {
            window.location.href = `?id=${this.userId}&ep_from=${this.dateFrom}&ep_to=${this.dateTo}&ajax=csv`;
        },
        paged() {
            const data = this.filteredRows();
            const start = (this.page - 1) * this.perPage;
            return data.slice(start, start + this.perPage);
        },
        prev()  { if (this.page > 1) this.page--; },
        next()  { if (this.page < this.pages) this.page++; },
        goTo(p) { this.page = p; },
        pageButtons() {
            const btns = [];
            const maxVisible = 7;
            if (this.pages <= maxVisible) {
                for (let i = 1; i <= this.pages; i++) btns.push(i);
            } else {
                btns.push(1);
                let start = Math.max(2, this.page - 1);
                let end   = Math.min(this.pages - 1, this.page + 1);
                if (this.page <= 3) { start = 2; end = 4; }
                if (this.page >= this.pages - 2) { start = this.pages - 3; end = this.pages - 1; }
                for (let i = start; i <= end; i++) btns.push(i);
                btns.push(this.pages);
            }
            return btns;
        }
    };
}
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
