<?php
require_once __DIR__ . '/../../src/bootstrap.php';
 
$userId = (int)($_GET['id'] ?? 0);
if (!$userId) die('User ID requerido');
 
$pdo = Keeper\Db::pdo();
 
// Obtener usuario
$user = $pdo->prepare("SELECT id, cc, display_name, email FROM keeper_users WHERE id = ?");
$user->execute([$userId]);
$user = $user->fetch(PDO::FETCH_ASSOC);
if (!$user) die('Usuario no encontrado');
 
// Filtros y paginación
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$export = $_GET['export'] ?? null;
 
// Paginación
$pageDays = max(1, (int)($_GET['page_days'] ?? 1));
$pageWindows = max(1, (int)($_GET['page_windows'] ?? 1));
$perPageDays = 31; // Mostrar hasta un mes
$perPageWindows = 10; // 50 ventanas por página
 
// Dispositivos del usuario
$devices = $pdo->prepare("SELECT id, device_guid, device_name FROM keeper_devices WHERE user_id = ?");
$devices->execute([$userId]);
$devices = $devices->fetchAll(PDO::FETCH_ASSOC);
$deviceIds = array_column($devices, 'id');
 
if (empty($deviceIds)) {
    $deviceIdsStr = '0';
} else {
    $deviceIdsStr = implode(',', $deviceIds);
}
 
// ==================== RESUMEN GENERAL ====================
$summary = $pdo->query("
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
        COUNT(DISTINCT day_date) as days_worked
    FROM keeper_activity_day
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
")->fetch(PDO::FETCH_ASSOC);
 
// ==================== ACTIVIDAD POR DÍA (CON PAGINACIÓN) ====================
$totalDays = $pdo->query("
    SELECT COUNT(DISTINCT day_date) as total
    FROM keeper_activity_day
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
")->fetch()['total'];
 
$offsetDays = ($pageDays - 1) * $perPageDays;
$totalPagesDays = ceil($totalDays / $perPageDays);
 
$dailyActivity = $pdo->query("
    SELECT 
        day_date,
        SUM(active_seconds) as active,
        SUM(idle_seconds) as idle,
        SUM(work_hours_active_seconds) as work_active,
        SUM(lunch_active_seconds) as lunch_active,
        SUM(lunch_idle_seconds) as lunch_idle,
        SUM(after_hours_active_seconds) as after_active,
        SUM(call_seconds) as call_time
    FROM keeper_activity_day
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
    GROUP BY day_date
    ORDER BY day_date DESC
    LIMIT {$perPageDays} OFFSET {$offsetDays}
")->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== TOP VENTANAS ====================
$topWindows = $pdo->query("
    SELECT 
        process_name,
        window_title,
        COUNT(*) as visit_count,
        SUM(duration_seconds) as total_duration
    FROM keeper_window_episode
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
    AND process_name IS NOT NULL
    GROUP BY process_name, window_title
    ORDER BY total_duration DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== VENTANAS RECIENTES (CON PAGINACIÓN) ====================
$totalWindows = $pdo->query("
    SELECT COUNT(*) as total
    FROM keeper_window_episode
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
")->fetch()['total'];
 
$offsetWindows = ($pageWindows - 1) * $perPageWindows;
$totalPagesWindows = ceil($totalWindows / $perPageWindows);
 
$recentWindows = $pdo->query("
    SELECT 
        process_name,
        window_title,
        start_at,
        end_at,
        duration_seconds,
        is_in_call
    FROM keeper_window_episode
    WHERE user_id = {$userId}
    AND device_id IN ({$deviceIdsStr})
    AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
    ORDER BY start_at DESC
    LIMIT {$perPageWindows} OFFSET {$offsetWindows}
")->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== EXPORTAR A CSV ====================
if ($export === 'csv') {
    // Obtener TODOS los datos sin paginación para export
    $allDailyActivity = $pdo->query("
        SELECT day_date, SUM(active_seconds) as active, SUM(idle_seconds) as idle,
               SUM(work_hours_active_seconds) as work_active, SUM(lunch_active_seconds) as lunch_active,
               SUM(after_hours_active_seconds) as after_active, SUM(call_seconds) as call_time
        FROM keeper_activity_day
        WHERE user_id = {$userId} AND device_id IN ({$deviceIdsStr})
        AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
        GROUP BY day_date ORDER BY day_date DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    $allTopWindows = $pdo->query("
        SELECT process_name, window_title, COUNT(*) as visit_count, SUM(duration_seconds) as total_duration
        FROM keeper_window_episode
        WHERE user_id = {$userId} AND device_id IN ({$deviceIdsStr})
        AND day_date BETWEEN '{$dateFrom}' AND '{$dateTo}' AND process_name IS NOT NULL
        GROUP BY process_name, window_title ORDER BY total_duration DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
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
    fputcsv($output, ['Fecha', 'Activo (h)', 'Inactivo (h)', 'Laboral (h)', 'Almuerzo (h)', 'Fuera horario (h)', 'Llamadas (h)']);
    foreach ($allDailyActivity as $day) {
        fputcsv($output, [
            $day['day_date'],
            round($day['active'] / 3600, 2),
            round($day['idle'] / 3600, 2),
            round($day['work_active'] / 3600, 2),
            round($day['lunch_active'] / 3600, 2),
            round($day['after_active'] / 3600, 2),
            round($day['call_time'] / 3600, 2)
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
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="users.php">Usuarios</a>
            <a href="user-dashboard.php?id=<?= $userId ?>" class="active">Reporte</a>
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
                <div class="stat-value"><?= $summary['days_worked'] ?></div>
                <div class="stat-label">Días trabajados</div>
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
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Activo</th>
                    <th>Inactivo</th>
                    <th>Laboral</th>
                    <th>Almuerzo</th>
                    <th>Fuera horario</th>
                    <th>Llamadas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($dailyActivity as $day): ?>
                <tr>
                    <td><strong><?= $day['day_date'] ?></strong></td>
                    <td><?= formatSeconds($day['active']) ?></td>
                    <td><?= formatSeconds($day['idle']) ?></td>
                    <td><?= formatSeconds($day['work_active']) ?></td>
                    <td><?= formatSeconds($day['lunch_idle']) ?></td>
                    <td><?= formatSeconds($day['after_active']) ?></td>
                    <td><?= formatSeconds($day['call_time']) ?></td>
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
    </script>
</body>
</html>