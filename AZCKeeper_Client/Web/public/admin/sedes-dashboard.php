<?php
/**
 * Dashboard por Sede — vista de KPIs agregados por sede.
 * Solo superadmin o roles con acceso al módulo "sedes-dashboard".
 * URL: sedes-dashboard.php  (cards)  |  sedes-dashboard.php?sede=X  (detalle)
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('sedes-dashboard');

$currentPage = 'sedes-dashboard';
$sedeId      = filter_input(INPUT_GET, 'sede', FILTER_VALIDATE_INT);

// ==================== HELPERS ====================
function fmtH(int $sec): string {
    if ($sec <= 0) return '0h 0m';
    return floor($sec / 3600) . 'h ' . floor(($sec % 3600) / 60) . 'm';
}

// ==================== PERÍODO ====================
$period = $_GET['period'] ?? 'today';
if (!in_array($period, ['today','week','month','custom'])) $period = 'today';
$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom)) $customFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo))   $customTo   = '';

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
    default:
        $dateFrom = date('Y-m-d');
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Hoy';
        break;
}

// ==================== SEDES ACTIVAS ====================
$sedesSt = $pdo->query("SELECT id, nombre, codigo, descripcion FROM keeper_sedes WHERE activa = 1 ORDER BY nombre");
$allSedes = $sedesSt->fetchAll(PDO::FETCH_ASSOC);

// Si se eligió una sede, validar que exista
$currentSede = null;
if ($sedeId) {
    foreach ($allSedes as $s) {
        if ((int)$s['id'] === $sedeId) { $currentSede = $s; break; }
    }
    if (!$currentSede) { header('Location: sedes-dashboard.php'); exit; }
}

$pageTitle = $currentSede
    ? 'Sede: ' . $currentSede['nombre']
    : 'Dashboard por Sede';

// ==================== BUILD PERIOD PARAMS ====================
function buildPeriodQS(array $extra = []): string {
    $p = $_GET;
    $p = array_merge($p, $extra);
    unset($p['ajax']);
    return http_build_query($p);
}

if (!$sedeId) {
    // ================================================================
    // VISTA: CARDS POR SEDE
    // ================================================================

    // KPIs por sede
    $sql = "
        SELECT
            ua.sede_id,
            COUNT(DISTINCT u.id) AS total_users,
            COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 2 MINUTE THEN u.id END) AS online_now,
            COALESCE(SUM(a.active_seconds), 0) AS total_active,
            COALESCE(SUM(a.idle_seconds), 0)   AS total_idle,
            COALESCE(SUM(a.work_hours_active_seconds), 0) AS total_work,
            COALESCE(SUM(a.work_hours_idle_seconds), 0) AS total_work_idle,
            COALESCE(SUM(a.call_seconds), 0) AS total_calls,
            COUNT(DISTINCT a.user_id) AS users_with_activity
        FROM keeper_users u
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id AND ua.sede_id IS NOT NULL
        LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
        LEFT JOIN keeper_activity_day a ON a.user_id = u.id AND a.day_date BETWEEN :dfrom AND :dto
        WHERE u.status = 'active'
        GROUP BY ua.sede_id
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':dfrom' => $dateFrom, ':dto' => $dateTo]);
    $sedeKpis = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sedeKpis[(int)$row['sede_id']] = $row;
    }

    // Primer ingreso por sede: primera ventana después de las 5 AM
    $stFL = $pdo->prepare("
        SELECT ua2.sede_id, MIN(we.start_at) AS first_login
        FROM keeper_window_episode we
        INNER JOIN keeper_user_assignments ua2 ON ua2.keeper_user_id = we.user_id AND ua2.sede_id IS NOT NULL
        WHERE we.day_date BETWEEN :dfrom AND :dto
          AND TIME(we.start_at) >= '05:00:00'
        GROUP BY ua2.sede_id
    ");
    $stFL->execute([':dfrom' => $dateFrom, ':dto' => $dateTo]);
    $firstLoginPerSede = [];
    foreach ($stFL->fetchAll(PDO::FETCH_ASSOC) as $flr) {
        $firstLoginPerSede[(int)$flr['sede_id']] = $flr['first_login'];
    }

    // Leisure seconds per sede (apps + windows)
    $leisurePerSede = [];
    $leisureData = getLeisureApps();
    $lApps = $leisureData['apps'];
    $lWins = $leisureData['windows'];
    if (!empty($lApps) || !empty($lWins)) {
        $conditions = [];
        $lParams = [':l_dfrom' => $dateFrom, ':l_dto' => $dateTo];
        if (!empty($lApps)) {
            $phL = [];
            foreach ($lApps as $i => $app) {
                $phL[] = ":lapp_{$i}";
                $lParams[":lapp_{$i}"] = $app;
            }
            $conditions[] = 'w.process_name IN (' . implode(',', $phL) . ')';
        }
        if (!empty($lWins)) {
            $likes = [];
            foreach ($lWins as $i => $win) {
                $likes[] = "w.window_title LIKE :lwin_{$i}";
                $lParams[":lwin_{$i}"] = '%' . $win . '%';
            }
            $conditions[] = '(' . implode(' OR ', $likes) . ')';
        }
        $orClause = implode(' OR ', $conditions);
        $stLs = $pdo->prepare("
            SELECT ua.sede_id, COALESCE(SUM(w.duration_seconds), 0) AS leisure_sec
            FROM keeper_window_episode w
            INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id
            WHERE w.day_date BETWEEN :l_dfrom AND :l_dto
              AND ($orClause)
              AND ua.sede_id IS NOT NULL
            GROUP BY ua.sede_id
        ");
        $stLs->execute($lParams);
        foreach ($stLs->fetchAll(PDO::FETCH_ASSOC) as $lr) {
            $leisurePerSede[(int)$lr['sede_id']] = (int)$lr['leisure_sec'];
        }
    }

    // Usuarios sin sede asignada
    $sqlNoSede = "
        SELECT COUNT(DISTINCT u.id) AS total
        FROM keeper_users u
        LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
        WHERE u.status = 'active' AND (ua.sede_id IS NULL OR ua.id IS NULL)
    ";
    $noSedeCount = (int)$pdo->query($sqlNoSede)->fetchColumn();

    require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Period selector -->
<div class="flex flex-wrap items-center gap-3 mb-6">
    <div class="flex items-center gap-1 bg-white rounded-xl border border-gray-100 p-1">
        <?php foreach (['today'=>'Hoy','week'=>'Semana','month'=>'Mes'] as $k=>$lbl): ?>
        <a href="?period=<?= $k ?>"
           class="px-4 py-2 rounded-lg text-xs font-semibold transition-colors <?= $period === $k ? 'bg-corp-800 text-white shadow-sm' : 'text-muted hover:text-dark hover:bg-gray-50' ?>">
            <?= $lbl ?>
        </a>
        <?php endforeach; ?>
    </div>
    <!-- Custom range -->
    <form method="get" class="flex items-center gap-2">
        <input type="hidden" name="period" value="custom">
        <input type="date" name="from" value="<?= htmlspecialchars($customFrom ?: $dateFrom) ?>"
               class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
        <span class="text-xs text-muted">—</span>
        <input type="date" name="to" value="<?= htmlspecialchars($customTo ?: $dateTo) ?>"
               class="px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
        <button type="submit" class="px-3 py-1.5 bg-corp-800 text-white text-xs font-medium rounded-lg hover:bg-corp-900 transition-colors">Aplicar</button>
    </form>
    <span class="text-xs text-muted font-medium bg-gray-100 px-3 py-1.5 rounded-lg"><?= $periodLabel ?></span>
</div>

<!-- Sede Cards Grid -->
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-5">
    <?php foreach ($allSedes as $sede):
        $k = $sedeKpis[(int)$sede['id']] ?? null;
        $totalUsers     = $k ? (int)$k['total_users'] : 0;
        $onlineNow      = $k ? (int)$k['online_now'] : 0;
        $totalActive     = $k ? (int)$k['total_active'] : 0;
        $totalIdle       = $k ? (int)$k['total_idle'] : 0;
        $totalWork       = $k ? (int)$k['total_work'] : 0;
        $totalWorkIdle   = $k ? (int)$k['total_work_idle'] : 0;
        $totalCalls      = $k ? (int)$k['total_calls'] : 0;
        $usersWithAct    = $k ? (int)$k['users_with_activity'] : 0;
        $leisureSec      = $leisurePerSede[(int)$sede['id']] ?? 0;
        $workTotal       = $totalWork + $totalWorkIdle;
        $productiveSec   = max(0, $totalWork - $leisureSec);
        $productivity    = $workTotal > 0 ? round(($productiveSec / $workTotal) * 100) : 0;
        $firstLogin      = ($firstLoginPerSede[(int)$sede['id']] ?? null) ? date('g:i A', strtotime($firstLoginPerSede[(int)$sede['id']])) : '--:--';
        $prodColor = $productivity >= 70 ? 'text-emerald-600' : ($productivity >= 40 ? 'text-amber-500' : 'text-red-500');
        $linkQS = buildPeriodQS(['sede' => $sede['id']]);
    ?>
    <a href="?<?= $linkQS ?>" class="group block bg-white rounded-xl border border-gray-100 hover:border-corp-200 hover:shadow-lg transition-all p-5">
        <!-- Header -->
        <div class="flex items-start justify-between mb-4">
            <div>
                <h3 class="text-base font-bold text-dark group-hover:text-corp-800 transition-colors"><?= htmlspecialchars($sede['nombre']) ?></h3>
                <p class="text-xs text-muted mt-0.5"><?= htmlspecialchars($sede['codigo']) ?><?= $sede['descripcion'] ? ' — ' . htmlspecialchars(mb_strimwidth($sede['descripcion'], 0, 50, '…')) : '' ?></p>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full <?= $onlineNow > 0 ? 'bg-emerald-500' : 'bg-gray-300' ?>"></span>
                <span class="text-xs font-medium <?= $onlineNow > 0 ? 'text-emerald-600' : 'text-muted' ?>"><?= $onlineNow ?> online</span>
            </div>
        </div>

        <!-- KPI grid 2x2 -->
        <div class="grid grid-cols-2 gap-3">
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] text-muted uppercase tracking-wider font-semibold">Usuarios</p>
                <p class="text-xl font-bold text-dark mt-0.5"><?= $totalUsers ?></p>
                <p class="text-[10px] text-muted mt-0.5"><?= $usersWithAct ?> activos hoy</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] text-muted uppercase tracking-wider font-semibold">Productividad</p>
                <p class="text-xl font-bold <?= $prodColor ?> mt-0.5"><?= $productivity ?>%</p>
                <p class="text-[10px] text-muted mt-0.5"><?= fmtH($totalWork) ?> laboral</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] text-muted uppercase tracking-wider font-semibold">Primer Ingreso</p>
                <p class="text-lg font-bold text-dark mt-0.5"><?= $firstLogin ?></p>
            </div>
            <div class="bg-gray-50 rounded-lg p-3">
                <p class="text-[10px] text-muted uppercase tracking-wider font-semibold">En Llamada</p>
                <p class="text-lg font-bold text-dark mt-0.5"><?= fmtH($totalCalls) ?></p>
            </div>
        </div>

        <!-- Footer action hint -->
        <div class="mt-4 pt-3 border-t border-gray-100 flex items-center justify-between">
            <span class="text-xs text-muted">Horas totales: <span class="font-semibold text-dark"><?= fmtH($totalActive) ?></span></span>
            <span class="text-xs font-medium text-corp-800 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1">
                Ver detalle
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($noSedeCount > 0): ?>
<div class="mt-4 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3 flex items-center gap-3">
    <svg class="w-5 h-5 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
    <p class="text-xs text-amber-800"><span class="font-semibold"><?= $noSedeCount ?></span> usuario(s) no tienen sede asignada y no aparecen en estas métricas.</p>
</div>
<?php endif; ?>

<?php
    require_once __DIR__ . '/partials/layout_footer.php';

} else {
    // ================================================================
    // VISTA: DETALLE DE UNA SEDE
    // ================================================================

    // KPIs de la sede seleccionada
    $sedeScope = " AND ua.sede_id = :sede_id";
    $sedeParams = [':sede_id' => $sedeId, ':dfrom' => $dateFrom, ':dto' => $dateTo];

    // General KPIs
    $sql = "
        SELECT
            COUNT(DISTINCT u.id) AS total_users,
            COUNT(DISTINCT d.id) AS total_devices,
            COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 2 MINUTE THEN d.id END) AS active_now,
            COUNT(DISTINCT CASE WHEN d.last_seen_at >= NOW() - INTERVAL 15 MINUTE
                                  AND d.last_seen_at < NOW() - INTERVAL 2 MINUTE THEN d.id END) AS away_now
        FROM keeper_users u
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
        LEFT JOIN keeper_devices d ON d.user_id = u.id AND d.status = 'active'
        WHERE u.status = 'active' {$sedeScope}
    ";
    $st = $pdo->prepare($sql);
    $st->execute([':sede_id' => $sedeId]);
    $kpi = $st->fetch(PDO::FETCH_ASSOC);

    // Activity
    $sqlAct = "
        SELECT
            COALESCE(SUM(a.active_seconds), 0) AS total_active,
            COALESCE(SUM(a.idle_seconds), 0) AS total_idle,
            COALESCE(SUM(a.call_seconds), 0) AS total_calls,
            COALESCE(SUM(a.work_hours_active_seconds), 0) AS total_work,
            COALESCE(SUM(a.work_hours_idle_seconds), 0) AS total_work_idle,
            COALESCE(SUM(a.lunch_active_seconds), 0) AS total_lunch,
            COALESCE(SUM(a.after_hours_active_seconds), 0) AS total_after,
            COUNT(DISTINCT a.user_id) AS users_with_activity
        FROM keeper_activity_day a
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = a.user_id
        WHERE a.day_date BETWEEN :dfrom AND :dto {$sedeScope}
    ";
    $st = $pdo->prepare($sqlAct);
    $st->execute($sedeParams);
    $act = $st->fetch(PDO::FETCH_ASSOC);

    // Primer ingreso de esta sede: primera ventana después de las 5 AM
    $stFL = $pdo->prepare("
        SELECT MIN(we.start_at)
        FROM keeper_window_episode we
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = we.user_id
        WHERE we.day_date BETWEEN :dfrom AND :dto
          AND TIME(we.start_at) >= '05:00:00'
          {$sedeScope}
    ");
    $stFL->execute($sedeParams);
    $act['first_login'] = $stFL->fetchColumn() ?: null;

    $totalActive = (int)($act['total_active'] ?? 0);
    $totalIdle   = (int)($act['total_idle'] ?? 0);
    $totalCalls  = (int)($act['total_calls'] ?? 0);
    $totalWork   = (int)($act['total_work'] ?? 0);
    $totalWorkIdle = (int)($act['total_work_idle'] ?? 0);
    $totalLunch  = (int)($act['total_lunch'] ?? 0);
    $totalAfter  = (int)($act['total_after'] ?? 0);

    // Leisure deduction for this sede (apps + windows)
    $leisureSede = 0;
    $leisureData = getLeisureApps();
    $lApps = $leisureData['apps'];
    $lWins = $leisureData['windows'];
    if (!empty($lApps) || !empty($lWins)) {
        $conditions = [];
        $lp = [':ls_dfrom' => $dateFrom, ':ls_dto' => $dateTo, ':ls_sede' => $sedeId];
        if (!empty($lApps)) {
            $phL = [];
            foreach ($lApps as $i => $app) {
                $phL[] = ":lsa_{$i}";
                $lp[":lsa_{$i}"] = $app;
            }
            $conditions[] = 'w.process_name IN (' . implode(',', $phL) . ')';
        }
        if (!empty($lWins)) {
            $likes = [];
            foreach ($lWins as $i => $win) {
                $likes[] = "w.window_title LIKE :lsw_{$i}";
                $lp[":lsw_{$i}"] = '%' . $win . '%';
            }
            $conditions[] = '(' . implode(' OR ', $likes) . ')';
        }
        $orClause = implode(' OR ', $conditions);
        $stLs = $pdo->prepare("
            SELECT COALESCE(SUM(w.duration_seconds), 0)
            FROM keeper_window_episode w
            INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id
            WHERE w.day_date BETWEEN :ls_dfrom AND :ls_dto
              AND ($orClause)
              AND ua.sede_id = :ls_sede
        ");
        $stLs->execute($lp);
        $leisureSede = (int)$stLs->fetchColumn();
    }

    $workTotalSede = $totalWork + $totalWorkIdle;
    $productiveSede = max(0, $totalWork - $leisureSede);
    $totalAll    = $totalActive + $totalIdle;
    $productivity = $workTotalSede > 0 ? round(($productiveSede / $workTotalSede) * 100) : 0;
    $focusScore  = $workTotalSede > 0 ? round(($productiveSede / $workTotalSede) * 10, 1) : 0;
    $firstLogin  = ($act['first_login']) ? date('g:i A', strtotime($act['first_login'])) : '--:--';
    $usersAct    = (int)($act['users_with_activity'] ?? 0);

    // Top apps
    $sqlApps = "
        SELECT w.process_name, SUM(w.duration_seconds) AS total_sec
        FROM keeper_window_episode w
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = w.user_id
        WHERE w.day_date BETWEEN :dfrom AND :dto
          AND w.process_name IS NOT NULL AND w.process_name != ''
          {$sedeScope}
        GROUP BY w.process_name
        ORDER BY total_sec DESC
        LIMIT 8
    ";
    $st = $pdo->prepare($sqlApps);
    $st->execute($sedeParams);
    $topApps = $st->fetchAll(PDO::FETCH_ASSOC);
    $totalAppsSec = array_sum(array_column($topApps, 'total_sec')) ?: 1;

    // Top users
    $sqlTopUsers = "
        SELECT u.id, u.display_name,
               SUM(a.active_seconds) AS active_sec,
               SUM(a.work_hours_active_seconds) AS work_sec
        FROM keeper_activity_day a
        INNER JOIN keeper_users u ON u.id = a.user_id
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
        WHERE a.day_date BETWEEN :dfrom AND :dto {$sedeScope}
        GROUP BY u.id
        ORDER BY work_sec DESC
        LIMIT 8
    ";
    $st = $pdo->prepare($sqlTopUsers);
    $st->execute($sedeParams);
    $topUsers = $st->fetchAll(PDO::FETCH_ASSOC);
    $maxWork = !empty($topUsers) ? max(array_column($topUsers, 'work_sec')) : 1;

    // Recent devices
    $sqlDevices = "
        SELECT u.display_name, d.device_name, d.last_seen_at
        FROM keeper_devices d
        INNER JOIN keeper_users u ON u.id = d.user_id
        INNER JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
        WHERE d.status = 'active' {$sedeScope}
        ORDER BY d.last_seen_at DESC
        LIMIT 8
    ";
    $st = $pdo->prepare($sqlDevices);
    $st->execute([':sede_id' => $sedeId]);
    $recentDevices = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recentDevices as &$dev) {
        $seenAgo = $dev['last_seen_at'] ? time() - strtotime($dev['last_seen_at']) : 99999;
        if ($seenAgo < 120) $dev['device_status'] = 'active';
        elseif ($seenAgo < 900) $dev['device_status'] = 'away';
        else $dev['device_status'] = 'inactive';
    }
    unset($dev);

    require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Breadcrumb + period selector -->
<div class="flex flex-wrap items-center justify-between gap-3 mb-6">
    <div class="flex items-center gap-2 text-sm">
        <a href="sedes-dashboard.php" class="text-corp-800 hover:underline font-medium">Sedes</a>
        <svg class="w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-dark font-semibold"><?= htmlspecialchars($currentSede['nombre']) ?></span>
        <span class="text-muted text-xs">(<?= htmlspecialchars($currentSede['codigo']) ?>)</span>
    </div>
    <div class="flex items-center gap-2">
        <div class="flex items-center gap-1 bg-white rounded-xl border border-gray-100 p-1">
            <?php foreach (['today'=>'Hoy','week'=>'Semana','month'=>'Mes'] as $k=>$lbl): ?>
            <a href="?sede=<?= $sedeId ?>&period=<?= $k ?>"
               class="px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors <?= $period === $k ? 'bg-corp-800 text-white shadow-sm' : 'text-muted hover:text-dark hover:bg-gray-50' ?>">
                <?= $lbl ?>
            </a>
            <?php endforeach; ?>
        </div>
        <span class="text-xs text-muted font-medium bg-gray-100 px-3 py-1.5 rounded-lg"><?= $periodLabel ?></span>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <!-- Usuarios -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-corp-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
            <p class="text-xs text-muted font-semibold uppercase tracking-wider">Usuarios</p>
        </div>
        <p class="text-2xl font-bold text-dark"><?= (int)$kpi['total_users'] ?></p>
        <p class="text-xs text-muted mt-0.5">
            <span class="text-emerald-600 font-medium"><?= (int)$kpi['active_now'] ?></span> activos ·
            <span class="text-amber-500 font-medium"><?= (int)$kpi['away_now'] ?></span> ausentes
        </p>
    </div>
    <!-- Productividad -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-emerald-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </div>
            <p class="text-xs text-muted font-semibold uppercase tracking-wider">Productividad</p>
        </div>
        <p class="text-2xl font-bold <?= $productivity >= 70 ? 'text-emerald-600' : ($productivity >= 40 ? 'text-amber-500' : 'text-red-500') ?>"><?= $productivity ?>%</p>
        <p class="text-xs text-muted mt-0.5">Focus Score: <span class="font-medium text-dark"><?= number_format($focusScore, 1) ?></span></p>
    </div>
    <!-- Primer ingreso -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-xs text-muted font-semibold uppercase tracking-wider">Primer Ingreso</p>
        </div>
        <p class="text-2xl font-bold text-dark"><?= $firstLogin ?></p>
        <p class="text-xs text-muted mt-0.5"><?= $usersAct ?> con actividad</p>
    </div>
    <!-- Horas totales -->
    <div class="bg-white rounded-xl border border-gray-100 p-4">
        <div class="flex items-center gap-2 mb-2">
            <div class="w-8 h-8 rounded-lg bg-purple-50 flex items-center justify-center">
                <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-xs text-muted font-semibold uppercase tracking-wider">Total Activo</p>
        </div>
        <p class="text-2xl font-bold text-dark"><?= fmtH($totalActive) ?></p>
        <p class="text-xs text-muted mt-0.5">
            Laboral: <span class="font-medium"><?= fmtH($totalWork) ?></span> ·
            Llamadas: <span class="font-medium"><?= fmtH($totalCalls) ?></span>
        </p>
    </div>
</div>

<!-- Two-column: hours breakdown + Top Apps -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mb-6">

    <!-- Hours breakdown -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Distribución de Horas
        </h3>
        <?php
        $segments = [
            ['label' => 'Horario Laboral', 'val' => $totalWork, 'color' => 'bg-corp-800'],
            ['label' => 'Almuerzo',        'val' => $totalLunch, 'color' => 'bg-amber-400'],
            ['label' => 'Extra Horario',   'val' => $totalAfter, 'color' => 'bg-purple-500'],
            ['label' => 'Inactividad',     'val' => $totalIdle,  'color' => 'bg-gray-300'],
        ];
        $barTotal = max(array_sum(array_column($segments, 'val')), 1);
        ?>
        <!-- Stacked bar -->
        <div class="flex rounded-full h-4 overflow-hidden mb-4">
            <?php foreach ($segments as $seg): ?>
            <?php if ($seg['val'] > 0): ?>
            <div class="<?= $seg['color'] ?>" style="width:<?= round(($seg['val']/$barTotal)*100, 1) ?>%" title="<?= $seg['label'] ?>: <?= fmtH($seg['val']) ?>"></div>
            <?php endif; endforeach; ?>
        </div>
        <!-- Legend -->
        <div class="grid grid-cols-2 gap-2">
            <?php foreach ($segments as $seg): ?>
            <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-sm <?= $seg['color'] ?> flex-shrink-0"></span>
                <span class="text-xs text-muted"><?= $seg['label'] ?></span>
                <span class="text-xs font-semibold text-dark ml-auto"><?= fmtH($seg['val']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Top Apps -->
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
            <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
            Top Aplicaciones
        </h3>
        <div class="space-y-2.5">
            <?php foreach ($topApps as $app):
                $pct = round(((int)$app['total_sec'] / $totalAppsSec) * 100);
                $sec = (int)$app['total_sec'];
                $dur = $sec >= 3600 ? floor($sec/3600).'h '.floor(($sec%3600)/60).'m' : floor($sec/60).'m';
            ?>
            <div>
                <div class="flex justify-between items-center mb-1">
                    <span class="text-xs font-medium text-dark truncate"><?= htmlspecialchars($app['process_name']) ?></span>
                    <span class="text-xs text-muted ml-2 whitespace-nowrap"><?= $dur ?> (<?= $pct ?>%)</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-corp-800 rounded-full" style="width:<?= $pct ?>%"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($topApps)): ?>
            <p class="text-xs text-muted text-center py-4">Sin datos de aplicaciones en este período</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Top Users -->
<div class="bg-white rounded-xl border border-gray-100 p-5 mb-6">
    <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1z"/></svg>
        Top Usuarios — Horario Laboral
    </h3>
    <?php if (empty($topUsers)): ?>
    <p class="text-xs text-muted text-center py-4">Sin actividad en este período</p>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($topUsers as $i => $tu):
            $work = (int)($tu['work_sec'] ?? 0);
            $active = (int)($tu['active_sec'] ?? 0);
            $barPct = $maxWork > 0 ? round(($work / $maxWork) * 100) : 0;
            $workStr = $work >= 3600 ? floor($work/3600).'h '.floor(($work%3600)/60).'m' : floor($work/60).'m';
        ?>
        <div class="flex items-center gap-3">
            <span class="text-xs text-muted w-5 text-right"><?= $i + 1 ?></span>
            <div class="w-7 h-7 bg-corp-50 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="text-xs font-bold text-corp-800"><?= strtoupper(mb_substr($tu['display_name'] ?? 'U', 0, 1)) ?></span>
            </div>
            <div class="flex-1 min-w-0">
                <a href="user-dashboard.php?id=<?= (int)$tu['id'] ?>" class="text-xs font-medium text-dark hover:text-corp-800 hover:underline truncate block"><?= htmlspecialchars($tu['display_name'] ?? '') ?></a>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden mt-1">
                    <div class="h-full bg-corp-800 rounded-full" style="width:<?= $barPct ?>%"></div>
                </div>
            </div>
            <span class="text-xs font-semibold text-dark whitespace-nowrap"><?= $workStr ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Devices -->
<div class="bg-white rounded-xl border border-gray-100 p-5">
    <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
        <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        Dispositivos Recientes
    </h3>
    <?php if (empty($recentDevices)): ?>
    <p class="text-xs text-muted text-center py-4">Sin dispositivos activos</p>
    <?php else: ?>
    <div class="space-y-2">
        <?php foreach ($recentDevices as $dev):
            $statusColor = match ($dev['device_status'] ?? 'inactive') {
                'active' => 'bg-emerald-500',
                'away'   => 'bg-amber-400',
                default  => 'bg-gray-300',
            };
            $seen = $dev['last_seen_at'] ? date('H:i', strtotime($dev['last_seen_at'])) : '--';
        ?>
        <div class="flex items-center gap-3 py-1.5">
            <span class="w-2 h-2 rounded-full <?= $statusColor ?> flex-shrink-0"></span>
            <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-dark truncate"><?= htmlspecialchars($dev['display_name'] ?? '') ?></p>
                <p class="text-[10px] text-muted truncate"><?= htmlspecialchars($dev['device_name'] ?? '') ?></p>
            </div>
            <span class="text-[10px] text-muted whitespace-nowrap"><?= $seen ?></span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php
    require_once __DIR__ . '/partials/layout_footer.php';
}
?>
