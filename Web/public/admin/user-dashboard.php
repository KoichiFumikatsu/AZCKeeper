<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\InputValidator;
 
$userId = InputValidator::validateInt($_GET['id'] ?? 0, 0, 1);
if (!$userId) die('User ID requerido');
 
$pdo = Keeper\Db::pdo();
 
// Obtener usuario con prepared statement
$user = $pdo->prepare("SELECT id, cc, display_name, email FROM keeper_users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch(PDO::FETCH_ASSOC);
if (!$user) die('Usuario no encontrado');
 
// Filtros validados
$dateFrom = InputValidator::validateDate($_GET['date_from'] ?? '', date('Y-m-01'));
$dateTo = InputValidator::validateDate($_GET['date_to'] ?? '', date('Y-m-d'));
$export = InputValidator::validateEnum($_GET['export'] ?? '', ['csv'], '');
 
// Paginación validada
$pageDays = InputValidator::validateInt($_GET['page_days'] ?? 1, 1, 1, 1000);
$pageWindows = InputValidator::validateInt($_GET['page_windows'] ?? 1, 1, 1, 1000);
$perPageDays = 31;
$perPageWindows = 10;
 
// Dispositivos del usuario
$stmt = $pdo->prepare("SELECT id, device_guid, device_name FROM keeper_devices WHERE user_id = ?");
$stmt->execute([$userId]);
$devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
$deviceIds = array_column($devices, 'id');
 
if (empty($deviceIds)) {
    $deviceIdsPlaceholders = '0';
    $deviceIdsParams = [];
} else {
    $deviceIds = InputValidator::validateIntArray($deviceIds);
    $deviceIdsPlaceholders = implode(',', array_fill(0, count($deviceIds), '?'));
    $deviceIdsParams = $deviceIds;
}
 
// ==================== RESUMEN GENERAL ====================
$stmt = $pdo->prepare("
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
        COUNT(DISTINCT CASE WHEN is_workday = 1 THEN day_date END) as days_worked,
        COUNT(DISTINCT CASE WHEN is_workday = 0 THEN day_date END) as weekend_days
    FROM keeper_activity_day
    WHERE user_id = ?
    AND device_id IN ({$deviceIdsPlaceholders})
    AND day_date BETWEEN ? AND ?
");
$stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]));
$summary = $stmt->fetch(PDO::FETCH_ASSOC);
 
// ==================== ACTIVIDAD POR DÍA (CON PAGINACIÓN) ====================
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT day_date) as total
    FROM keeper_activity_day
    WHERE user_id = ?
    AND device_id IN ({$deviceIdsPlaceholders})
    AND day_date BETWEEN ? AND ?
");
$stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]));
$totalDays = $stmt->fetch()['total'];
 
$offsetDays = ($pageDays - 1) * $perPageDays;
$totalPagesDays = ceil($totalDays / $perPageDays);
 
$stmt = $pdo->prepare("
    SELECT 
        day_date,
        is_workday,
        SUM(active_seconds) as active,
        SUM(idle_seconds) as idle,
        SUM(work_hours_active_seconds) as work_active,
        SUM(work_hours_idle_seconds) as work_idle,
        SUM(lunch_active_seconds) as lunch_active,
        SUM(lunch_idle_seconds) as lunch_idle,
        SUM(after_hours_active_seconds) as after_active,
        SUM(call_seconds) as call_time
    FROM keeper_activity_day
    WHERE user_id = ?
    AND device_id IN ({$deviceIdsPlaceholders})
    AND day_date BETWEEN ? AND ?
    GROUP BY day_date, is_workday
    ORDER BY day_date DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo, $perPageDays, $offsetDays]));
$dailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== TOP VENTANAS ====================
$stmt = $pdo->prepare("
    SELECT 
        process_name,
        window_title,
        COUNT(*) as visit_count,
        SUM(duration_seconds) as total_duration
    FROM keeper_window_episode
    WHERE user_id = ?
    AND device_id IN ({$deviceIdsPlaceholders})
    AND day_date BETWEEN ? AND ?
    AND process_name IS NOT NULL
    GROUP BY process_name, window_title
    ORDER BY total_duration DESC
    LIMIT 50
");
$stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]));
$topWindows = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== VENTANAS RECIENTES (CON PAGINACIÓN) ====================
$stmt = $pdo->prepare("
    SELECT COUNT(*) as total
    FROM keeper_window_episode
    WHERE user_id = ?
    AND device_id IN ({$deviceIdsPlaceholders})
    AND day_date BETWEEN ? AND ?
");
$stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]));
$totalWindows = $stmt->fetch()['total'];
 
$offsetWindows = ($pageWindows - 1) * $perPageWindows;
$totalPagesWindows = ceil($totalWindows / $perPageWindows);
 
$stmt = $pdo->prepare("
    SELECT 
        process_name,
        window_title,
        start_at,
        end_at,
        duration_seconds,
        is_in_call
    FROM keeper_window_episode
    WHERE user_id = ?
    AND device_id IN ({$deviceIdsPlaceholders})
    AND day_date BETWEEN ? AND ?
    ORDER BY start_at DESC
    LIMIT ? OFFSET ?
");
$stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo, $perPageWindows, $offsetWindows]));
$recentWindows = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== EXPORTAR A CSV ====================
if ($export === 'csv') {
    // Obtener TODOS los datos sin paginación para export
    $stmt = $pdo->prepare("
        SELECT day_date, SUM(active_seconds) as active, SUM(idle_seconds) as idle,
               SUM(work_hours_active_seconds) as work_active, SUM(work_hours_idle_seconds) as work_idle,
               SUM(lunch_active_seconds) as lunch_active, SUM(lunch_idle_seconds) as lunch_idle,
               SUM(after_hours_active_seconds) as after_active, SUM(call_seconds) as call_time
        FROM keeper_activity_day
        WHERE user_id = ? AND device_id IN ({$deviceIdsPlaceholders})
        AND day_date BETWEEN ? AND ?
        GROUP BY day_date ORDER BY day_date DESC
    ");
    $stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]));
    $allDailyActivity = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT process_name, window_title, COUNT(*) as visit_count, SUM(duration_seconds) as total_duration
        FROM keeper_window_episode
        WHERE user_id = ? AND device_id IN ({$deviceIdsPlaceholders})
        AND day_date BETWEEN ? AND ? AND process_name IS NOT NULL
        GROUP BY process_name, window_title ORDER BY total_duration DESC
    ");
    $stmt->execute(array_merge([$userId], $deviceIdsParams, [$dateFrom, $dateTo]));
    $allTopWindows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="reporte_' . $user['cc'] . '_' . $dateFrom . '_' . $dateTo . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
    
    // Resumen
    fputcsv($output, ['RESUMEN GENERAL']);
    fputcsv($output, ['CC', $user['cc']]);
    fputcsv($output, ['Nombre', $user['display_name']]);
    fputcsv($output, ['Periodo', $dateFrom . ' a ' . $dateTo]);
    fputcsv($output, ['Días trabajados', $summary['days_worked']]);
    fputcsv($output, []);
    
    fputcsv($output, ['Métrica', 'Horas']);
    fputcsv($output, ['Tiempo activo total', round($summary['total_active'] / 3600, 2)]);
    fputcsv($output, ['Tiempo inactivo total', round($summary['total_idle'] / 3600, 2)]);
    fputcsv($output, ['Tiempo laboral activo', round($summary['work_active'] / 3600, 2)]);
    fputcsv($output, ['Tiempo almuerzo activo', round($summary['lunch_active'] / 3600, 2)]);
    fputcsv($output, ['Tiempo fuera horario activo', round($summary['after_active'] / 3600, 2)]);
    fputcsv($output, ['Tiempo en llamadas', round($summary['total_call'] / 3600, 2)]);
    fputcsv($output, []);
    
    // Actividad diaria
    fputcsv($output, ['ACTIVIDAD DIARIA']);
    fputcsv($output, ['Fecha', 'Activo Total (h)', 'Inactivo Total (h)', 'En Llamada (h)', 'Activo Laboral (h)', 'Inactivo Laboral (h)', 'Inactivo Almuerzo (h)']);
    foreach ($allDailyActivity as $day) {
        fputcsv($output, [
            $day['day_date'],
            round($day['active'] / 3600, 2),
            round($day['idle'] / 3600, 2),
            round(($day['call_time'] ?? 0) / 3600, 2),
            round($day['work_active'] / 3600, 2),
            round(($day['work_idle'] ?? 0) / 3600, 2),
            round($day['lunch_idle'] / 3600, 2)
        ]);
    }
    fputcsv($output, []);
    
    // Top ventanas
    fputcsv($output, ['TOP APLICACIONES']);
    fputcsv($output, ['Proceso', 'Título', 'Visitas', 'Duración (h)']);
    foreach ($allTopWindows as $win) {
        fputcsv($output, [
            $win['process_name'],
            $win['window_title'],
            $win['visit_count'],
            round($win['total_duration'] / 3600, 2)
        ]);
    }
    
    fclose($output);
    exit;
}
 
function formatSeconds($seconds) {
    // Convertir a entero y validar
    $seconds = (int)$seconds;
    if ($seconds < 0) $seconds = 0;
    
    $hours = floor($seconds / 3600);
    $minutes = floor(($seconds % 3600) / 60);
    $secs = $seconds % 60;
    
    // Si tiene horas, formato HH:MM:SS
    if ($hours > 0) {
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }
    
    // Si solo minutos, formato MM:SS
    if ($minutes > 0) {
        return sprintf('%02d:%02d', $minutes, $secs);
    }
    
    // Solo segundos
    return sprintf('00:%02d', $secs);
}
function formatHours($seconds) {
    return round($seconds / 3600, 2);
}
 
function renderPagination($currentPage, $totalPages, $baseParams, $pageParam = 'page') {
    if ($totalPages <= 1) return '';
    
    $html = '<div class="pagination">';
    
    if ($currentPage > 1) {
        $params = $baseParams;
        $params[$pageParam] = $currentPage - 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="page-link">« Anterior</a>';
    }
    
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $params = $baseParams;
        $params[$pageParam] = 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="page-link">1</a>';
        if ($start > 2) $html .= '<span class="page-ellipsis">...</span>';
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $params = $baseParams;
        $params[$pageParam] = $i;
        $active = ($i == $currentPage) ? 'active' : '';
        $html .= '<a href="?' . http_build_query($params) . '" class="page-link ' . $active . '">' . $i . '</a>';
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<span class="page-ellipsis">...</span>';
        $params = $baseParams;
        $params[$pageParam] = $totalPages;
        $html .= '<a href="?' . http_build_query($params) . '" class="page-link">' . $totalPages . '</a>';
    }
    
    if ($currentPage < $totalPages) {
        $params = $baseParams;
        $params[$pageParam] = $currentPage + 1;
        $html .= '<a href="?' . http_build_query($params) . '" class="page-link">Siguiente »</a>';
    }
    
    $html .= '</div>';
    return $html;
}
 
$baseParams = ['id' => $userId, 'date_from' => $dateFrom, 'date_to' => $dateTo];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dashboard: <?= htmlspecialchars($user['display_name']) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <style>
    .dashboard-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 2rem; border-radius: 8px; margin-bottom: 2rem; }
    .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
    .stat-card { background: white; padding: 1.5rem; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .stat-value { font-size: 2rem; font-weight: bold; color: #3498db; }
    .stat-label { color: #666; margin-top: 0.5rem; }
    .chart-container { background: white; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .data-table { width: 100%; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    .data-table th { background: #3498db; color: white; padding: 1rem; text-align: left; }
    .data-table td { padding: 0.8rem; border-bottom: 1px solid #eee; }
    .data-table tr:hover { background: #f8f9fa; }
    .filter-bar { background: white; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; display: flex; gap: 1rem; align-items: end; }
    .filter-bar input, .filter-bar button { padding: 0.6rem 1rem; border: 1px solid #ddd; border-radius: 4px; }
    .filter-bar button { background: #3498db; color: white; border: none; cursor: pointer; }
    .filter-bar button:hover { background: #2980b9; }
    
    /* PAGINACIÓN */
    .pagination { display: flex; gap: 0.5rem; justify-content: center; align-items: center; margin: 1.5rem 0; }
    .page-link { padding: 0.5rem 1rem; border: 1px solid #ddd; border-radius: 4px; text-decoration: none; color: #3498db; background: white; transition: all 0.2s; }
    .page-link:hover { background: #3498db; color: white; }
    .page-link.active { background: #3498db; color: white; font-weight: bold; border-color: #3498db; }
    .page-ellipsis { padding: 0.5rem; color: #666; }
    .pagination-info { text-align: center; color: #666; margin-top: 0.5rem; font-size: 0.9rem; }
    /* GRID PARA GRÁFICOS */
    .charts-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
        gap: 1.5rem;
        margin-bottom: 2rem;
    }
     
    @media (max-width: 1024px) {
        .charts-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* ESTADO EN TIEMPO REAL */
    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.4rem 0.8rem;
        border-radius: 20px;
        font-size: 0.85rem;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        display: inline-block;
        animation: pulse 2s infinite;
    }
    
    .status-active {
        background: #d4edda;
        color: #155724;
    }
    
    .status-active .status-dot {
        background: #28a745;
    }
    
    .status-away {
        background: #fff3cd;
        color: #856404;
    }
    
    .status-away .status-dot {
        background: #ffc107;
        animation: none;
    }
    
    .status-inactive {
        background: #f8d7da;
        color: #721c24;
    }
    
    .status-inactive .status-dot {
        background: #dc3545;
        animation: none;
    }
    
    .status-finished {
        background: #e2e3e5;
        color: #6c757d;
    }
    
    .status-finished .status-dot {
        background: #6c757d;
        animation: none;
    }
    
    .status-unknown {
        background: #e2e3e5;
        color: #383d41;
    }
    
    .status-unknown .status-dot {
        background: #6c757d;
    }
    
    .today-row {
        background: #f0f8ff !important;
        border-left: 4px solid #3498db;
    }
    
    @keyframes pulse {
        0%, 100% {
            opacity: 1;
            transform: scale(1);
        }
        50% {
            opacity: 0.6;
            transform: scale(1.2);
        }
    }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="users.php">Usuarios</a>
            <a href="user-dashboard.php?id=<?= $userId ?>" class="active">Reporte</a>
            <a href="releases.php">Releases</a>
        </div>
    </nav>
 
    <div class="container">
        <div class="dashboard-header">
            <h1>📊 Dashboard de Usuario</h1>
            <p style="font-size: 1.2rem; margin-top: 0.5rem;">
                <strong><?= htmlspecialchars($user['display_name']) ?></strong> (CC: <?= htmlspecialchars($user['cc']) ?>)
            </p>
            <p style="opacity: 0.9; margin-top: 0.5rem;">
                <?= htmlspecialchars($user['email'] ?? 'Sin email') ?> | 
                Dispositivos: <?= count($devices) ?>
            </p>
        </div>
 
        <!-- FILTROS -->
        <form method="GET" class="filter-bar">
            <input type="hidden" name="id" value="<?= $userId ?>">
            <div>
                <label>Desde:</label>
                <input type="date" name="date_from" value="<?= $dateFrom ?>" required>
            </div>
            <div>
                <label>Hasta:</label>
                <input type="date" name="date_to" value="<?= $dateTo ?>" required>
            </div>
            <button type="submit">🔍 Filtrar</button>
            <button type="submit" name="export" value="csv" style="background: #27ae60;">📥 Descargar CSV</button>
            <a href="user-config.php?id=<?= $userId ?>" class="btn btn-secondary">⚙️ Configuración</a>
        </form>
 
        <!-- RESUMEN -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value" style="color: #27ae60;"><?= $summary['days_worked'] ?></div>
                <div class="stat-label">Días Laborables</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: <?= $summary['weekend_days'] > 0 ? '#e67e22' : '#95a5a6' ?>;">
                    <?= $summary['weekend_days'] ?>
                    <?php if ($summary['weekend_days'] > 0): ?>
                        <i class="bi bi-exclamation-triangle-fill" style="font-size: 0.7em;"></i>
                    <?php endif; ?>
                </div>
                <div class="stat-label">Fines de Semana</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= formatHours($summary['total_active']) ?>h</div>
                <div class="stat-label">Tiempo activo total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= formatHours($summary['work_active']) ?>h</div>
                <div class="stat-label">Tiempo laboral activo</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= formatHours($summary['total_call']) ?>h</div>
                <div class="stat-label">Tiempo en llamadas</div>
            </div>
        </div>
 
        <!-- GRÁFICOS EN GRID 2 COLUMNAS -->
        <div class="charts-grid">
            <!-- GRÁFICO CATEGORÍAS -->
            <div class="chart-container">
                <h3>📊 Distribución de Tiempo</h3>
                <div id="categoryChart" style="height: 350px;"></div>
            </div>
 
            <!-- GRÁFICO ACTIVIDAD DIARIA -->
            <div class="chart-container">
                <h3>📈 Actividad Diaria</h3>
                <div id="dailyChart" style="height: 350px;"></div>
            </div>
        </div>
 
        <!-- TOP VENTANAS (ANCHO COMPLETO) -->
        <div class="chart-container">
            <h3>💻 Top 20 Aplicaciones Más Usadas</h3>
            <div id="topWindowsChart" style="height: 450px;"></div>
        </div>

        <!-- TABLA ACTIVIDAD DIARIA (CON PAGINACIÓN) -->
        <h2 style="margin-top: 2rem;">📅 Actividad por Día</h2>
        <table class="data-table" id="activityTable">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Estado Actual</th>
                    <th>Activo Total</th>
                    <th>Inactivo Total</th>
                    <th>En Llamada</th>
                    <th>Activo Laboral</th>
                    <th>Inactivo Laboral</th>
                    <th>Inactivo Almuerzo</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyActivity as $day): 
                    $isToday = ($day['day_date'] === date('Y-m-d'));
                ?>
                <tr <?= $isToday ? 'id="todayRow" class="today-row"' : '' ?>>
                    <td>
                        <strong><?= $day['day_date'] ?></strong>
                        <?php if ($isToday): ?>
                            <span class="badge bg-primary">HOY</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($isToday): ?>
                            <span id="currentStatus" class="status-badge status-unknown">
                                <span class="status-dot"></span>
                                Cargando...
                            </span>
                        <?php else: ?>
                            <span class="status-badge status-finished">
                                <span class="status-dot"></span>
                                Finalizado
                            </span>
                        <?php endif; ?>
                    </td>
                    <td id="<?= $isToday ? 'activeToday' : '' ?>"><?= formatSeconds($day['active']) ?></td>
                    <td id="<?= $isToday ? 'idleToday' : '' ?>"><?= formatSeconds($day['idle']) ?></td>
                    <td id="<?= $isToday ? 'callToday' : '' ?>"><?= formatSeconds($day['call_time'] ?? 0) ?></td>
                    <td id="<?= $isToday ? 'workActiveToday' : '' ?>"><?= formatSeconds($day['work_active']) ?></td>
                    <td id="<?= $isToday ? 'workIdleToday' : '' ?>"><?= formatSeconds($day['work_idle'] ?? 0) ?></td>
                    <td id="<?= $isToday ? 'lunchIdleToday' : '' ?>"><?= formatSeconds($day['lunch_idle']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo renderPagination($pageDays, $totalPagesDays, $baseParams, 'page_days');
        ?>
        <div class="pagination-info">
            Mostrando <?= $offsetDays + 1 ?> - <?= min($offsetDays + $perPageDays, $totalDays) ?> de <?= $totalDays ?> días
        </div>
 
        <!-- VENTANAS RECIENTES (CON PAGINACIÓN) -->
        <h2 style="margin-top: 2rem;">🪟 Ventanas Visitadas Recientemente</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha/Hora</th>
                    <th>Proceso</th>
                    <th>Título</th>
                    <th>Duración</th>
                    <th>En llamada</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentWindows as $win): ?>
                <tr>
                    <td><?= date('Y-m-d H:i:s', strtotime($win['start_at'])) ?></td>
                    <td><code><?= htmlspecialchars($win['process_name']) ?></code></td>
                    <td><?= htmlspecialchars(substr($win['window_title'], 0, 80)) ?></td>
                    <td><?= formatSeconds($win['duration_seconds']) ?></td>
                    <td><?= $win['is_in_call'] ? '📞' : '' ?></td>

                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        echo renderPagination($pageWindows, $totalPagesWindows, $baseParams, 'page_windows');
        ?>
        <div class="pagination-info">
            Mostrando <?= $offsetWindows + 1 ?> - <?= min($offsetWindows + $perPageWindows, $totalWindows) ?> de <?= $totalWindows ?> episodios
        </div>
    </div>
 
    <script>
    // GRÁFICO CATEGORÍAS
    const categoryChart = echarts.init(document.getElementById('categoryChart'));
    categoryChart.setOption({
        tooltip: { trigger: 'item' },
        legend: { bottom: '5%' },
        series: [{
            type: 'pie',
            radius: ['40%', '70%'],
            data: [
                { value: <?= round($summary['work_active'] / 3600, 2) ?>, name: 'Laboral Activo' },
                { value: <?= round($summary['work_idle'] / 3600, 2) ?>, name: 'Laboral Inactivo' },
                { value: <?= round($summary['lunch_idle'] / 3600, 2) ?>, name: 'Almuerzo' },
                { value: <?= round($summary['after_active'] / 3600, 2) ?>, name: 'Fuera de horario' }
            ],
            emphasis: { itemStyle: { shadowBlur: 10, shadowOffsetX: 0, shadowColor: 'rgba(0, 0, 0, 0.5)' }}
        }]
    });
 
    // GRÁFICO ACTIVIDAD DIARIA
    const dailyChart = echarts.init(document.getElementById('dailyChart'));
    dailyChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['Laboral', 'Almuerzo', 'Fuera horario', 'Llamadas'] },
        xAxis: { type: 'category', data: <?= json_encode(array_column(array_reverse($dailyActivity), 'day_date')) ?> },
        yAxis: { type: 'value', name: 'Horas' },
        series: [
            { name: 'Laboral', type: 'bar', stack: 'total', data: <?= json_encode(array_map(fn($d) => round($d['work_active']/3600, 2), array_reverse($dailyActivity))) ?> },
            { name: 'Almuerzo', type: 'bar', stack: 'total', data: <?= json_encode(array_map(fn($d) => round($d['lunch_idle']/3600, 2), array_reverse($dailyActivity))) ?> },
            { name: 'Fuera horario', type: 'bar', stack: 'total', data: <?= json_encode(array_map(fn($d) => round($d['after_active']/3600, 2), array_reverse($dailyActivity))) ?> },
            { name: 'Llamadas', type: 'line', data: <?= json_encode(array_map(fn($d) => round($d['call_time']/3600, 2), array_reverse($dailyActivity))) ?> }
        ]
    });
 
    // GRÁFICO TOP VENTANAS
    const topWindowsChart = echarts.init(document.getElementById('topWindowsChart'));
    topWindowsChart.setOption({
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        xAxis: { type: 'value', name: 'Horas' },
        yAxis: { 
            type: 'category', 
            data: <?= json_encode(array_map(fn($w) => $w['process_name'], array_slice($topWindows, 0, 20))) ?>,
            axisLabel: { interval: 0, fontSize: 10 }
        },
        series: [{ 
            type: 'bar', 
            data: <?= json_encode(array_map(fn($w) => round($w['total_duration']/3600, 2), array_slice($topWindows, 0, 20))) ?>,
            itemStyle: { color: '#3498db' }
        }]
    });
 
    window.addEventListener('resize', () => {
        categoryChart.resize();
        dailyChart.resize();
        topWindowsChart.resize();
    });

    // ==================== ACTUALIZACIÓN EN TIEMPO REAL ====================
    const userId = <?= $userId ?>;
    const todayDate = '<?= date('Y-m-d') ?>';
    
    function formatSeconds(seconds) {
        if (!seconds || seconds < 0) seconds = 0;
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        if (hours > 0) {
            return `${String(hours).padStart(2, '0')}:${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }
        if (minutes > 0) {
            return `${String(minutes).padStart(2, '0')}:${String(secs).padStart(2, '0')}`;
        }
        return `00:${String(secs).padStart(2, '0')}`;
    }
    
    async function updateRealTimeData() {
        try {
            const response = await fetch(`realtime-status.php?user_id=${userId}`);
            if (!response.ok) throw new Error('Error fetching data');
            
            const data = await response.json();
            
            if (data.ok) {
                // Actualizar estado
                const statusBadge = document.getElementById('currentStatus');
                if (statusBadge) {
                    const statusMap = {
                        'active': { class: 'status-active', text: 'Activo', dot: '●' },
                        'away': { class: 'status-away', text: 'Ausente', dot: '●' },
                        'inactive': { class: 'status-inactive', text: 'Sin Conexión', dot: '●' }
                    };
                    
                    const status = statusMap[data.status] || { class: 'status-unknown', text: 'Desconocido', dot: '○' };
                    
                    statusBadge.className = `status-badge ${status.class}`;
                    statusBadge.innerHTML = `<span class="status-dot"></span>${status.text}`;
                }
                
                // Actualizar contadores solo si hay datos de hoy
                if (data.todayData) {
                    const updates = {
                        'activeToday': data.todayData.active_seconds,
                        'idleToday': data.todayData.idle_seconds,
                        'callToday': data.todayData.call_seconds,
                        'workActiveToday': data.todayData.work_active_seconds,
                        'workIdleToday': data.todayData.work_idle_seconds,
                        'lunchIdleToday': data.todayData.lunch_idle_seconds
                    };
                    
                    for (const [id, seconds] of Object.entries(updates)) {
                        const elem = document.getElementById(id);
                        if (elem && seconds !== undefined) {
                            elem.textContent = formatSeconds(seconds);
                        }
                    }
                }
            }
        } catch (error) {
            console.error('Error updating real-time data:', error);
        }
    }
    
    // Actualizar cada 5 segundos
    updateRealTimeData();
    setInterval(updateRealTimeData, 5000);
    </script>
</body>
</html>