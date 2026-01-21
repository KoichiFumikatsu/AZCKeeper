<?php
require_once __DIR__ . '/../../src/bootstrap.php';
 
$pdo = Keeper\Db::pdo();
 
// Filtros
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
 
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
        COUNT(DISTINCT user_id) as users_with_activity
    FROM keeper_activity_day
    WHERE day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
")->fetch(PDO::FETCH_ASSOC);
 
// ==================== ACTIVIDAD DIARIA AGREGADA ====================
$dailyActivity = $pdo->query("
    SELECT 
        day_date,
        SUM(active_seconds) as active,
        SUM(work_hours_active_seconds) as work_active,
        SUM(lunch_active_seconds) as lunch_active,
        SUM(after_hours_active_seconds) as after_active,
        SUM(call_seconds) as call_time,
        COUNT(DISTINCT user_id) as active_users
    FROM keeper_activity_day
    WHERE day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
    GROUP BY day_date
    ORDER BY day_date DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== TOP USUARIOS ACTIVOS ====================
$topUsers = $pdo->query("
    SELECT 
        u.id,
        u.cc,
        u.display_name,
        SUM(a.active_seconds) as total_active,
        SUM(a.work_hours_active_seconds) as work_active,
        COUNT(DISTINCT a.day_date) as days_worked
    FROM keeper_users u
    INNER JOIN keeper_activity_day a ON a.user_id = u.id
    WHERE a.day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
    GROUP BY u.id
    ORDER BY total_active DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== TOP APLICACIONES ====================
$topApps = $pdo->query("
    SELECT 
        process_name,
        COUNT(DISTINCT user_id) as users_count,
        COUNT(*) as usage_count,
        SUM(duration_seconds) as total_duration
    FROM keeper_window_episode
    WHERE day_date BETWEEN '{$dateFrom}' AND '{$dateTo}'
    AND process_name IS NOT NULL
    GROUP BY process_name
    ORDER BY total_duration DESC
    LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);
 
// ==================== USUARIOS CON ALERTAS ====================
$alerts = $pdo->query("
    SELECT 
        u.id,
        u.cc,
        u.display_name,
        COUNT(DISTINCT d.id) as device_count,
        MAX(d.last_seen_at) as last_seen,
        (SELECT COUNT(*) FROM keeper_policy_assignments WHERE scope='user' AND user_id=u.id AND is_active=1) as has_custom_policy
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id
    WHERE u.status = 'active'
    GROUP BY u.id
    HAVING device_count = 0 OR last_seen < NOW() - INTERVAL 7 DAY
    ORDER BY last_seen ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
 
function formatSeconds($seconds) {
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return sprintf('%02dh %02dm', $h, $m);
}
 
function formatHours($seconds) {
    return round($seconds / 3600, 1);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>AZCKeeper - Dashboard General</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <style>
    .dashboard-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1.5rem; margin: 2rem 0; }
    .stat-card { background: white; padding: 2rem; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); text-align: center; transition: transform 0.2s; }
    .stat-card:hover { transform: translateY(-5px); box-shadow: 0 6px 12px rgba(0,0,0,0.15); }
    .stat-icon { font-size: 3rem; margin-bottom: 1rem; }
    .stat-value { font-size: 2.5rem; font-weight: bold; color: #3498db; margin: 0.5rem 0; }
    .stat-label { color: #666; font-size: 0.9rem; }
    .chart-container { background: white; padding: 2rem; border-radius: 12px; margin: 1.5rem 0; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
    .quick-actions { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin: 2rem 0; }
    .action-btn { display: block; padding: 1.5rem; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; text-align: center; border-radius: 8px; text-decoration: none; font-weight: bold; transition: transform 0.2s; }
    .action-btn:hover { transform: scale(1.05); }
    .action-btn.secondary { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .action-btn.tertiary { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .table-responsive { overflow-x: auto; }
    .data-table { width: 100%; background: white; border-radius: 8px; overflow: hidden; }
    .data-table th { background: #3498db; color: white; padding: 1rem; text-align: left; }
    .data-table td { padding: 0.8rem; border-bottom: 1px solid #eee; }
    .data-table tr:hover { background: #f8f9fa; }
    .filter-bar { background: white; padding: 1.5rem; border-radius: 8px; margin-bottom: 2rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; }
    .filter-bar input, .filter-bar button { padding: 0.6rem 1rem; border: 1px solid #ddd; border-radius: 4px; }
    .filter-bar button { background: #3498db; color: white; border: none; cursor: pointer; font-weight: bold; }
    .filter-bar button:hover { background: #2980b9; }
    .alert-badge { display: inline-block; padding: 0.3rem 0.6rem; border-radius: 4px; font-size: 0.8rem; font-weight: bold; }
    .alert-warning { background: #fff3cd; color: #856404; }
    .alert-danger { background: #f8d7da; color: #721c24; }
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
            <a href="index.php" class="active">Dashboard</a>
            <a href="users.php">Usuarios</a>
            <a href="policies.php">Política Global</a>
        </div>
    </nav>
 
    <div class="container">
        <h1 style="margin-bottom: 0.5rem;">📊 Dashboard General</h1>
        <p style="color: #666; margin-bottom: 2rem;">Resumen de actividad y métricas del sistema AZCKeeper</p>
 
        <!-- ACCIONES RÁPIDAS -->
        <div class="quick-actions">
            <a href="users.php" class="action-btn">
                👥 Gestionar Usuarios
            </a>
            <a href="policies.php" class="action-btn secondary">
                ⚙️ Política Global
            </a>
            <a href="#activity" class="action-btn tertiary">
                📈 Ver Reportes
            </a>
        </div>
 
        <!-- FILTRO DE FECHAS -->
        <form method="GET" class="filter-bar">
            <div>
                <label>Desde:</label>
                <input type="date" name="date_from" value="<?= $dateFrom ?>" required>
            </div>
            <div>
                <label>Hasta:</label>
                <input type="date" name="date_to" value="<?= $dateTo ?>" required>
            </div>
            <button type="submit">🔍 Filtrar</button>
        </form>
 
        <!-- ESTADÍSTICAS GENERALES -->
        <div class="dashboard-grid">
            <div class="stat-card">
                <div class="stat-icon">👥</div>
                <div class="stat-value"><?= $stats['total_users'] ?></div>
                <div class="stat-label">Usuarios Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🖥️</div>
                <div class="stat-value"><?= $stats['total_devices'] ?></div>
                <div class="stat-label">Dispositivos Totales</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🟢</div>
                <div class="stat-value"><?= $stats['active_devices_24h'] ?></div>
                <div class="stat-label">Activos (24h)</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚙️</div>
                <div class="stat-value"><?= $stats['custom_policies'] ?></div>
                <div class="stat-label">Políticas Personalizadas</div>
            </div>
        </div>
 
        <!-- MÉTRICAS DE TIEMPO -->
        <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="stat-card">
                <div class="stat-value" style="color: #27ae60;"><?= formatHours($activity['total_active']) ?>h</div>
                <div class="stat-label">Tiempo Activo Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #2980b9;"><?= formatHours($activity['work_active']) ?>h</div>
                <div class="stat-label">Tiempo Laboral</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #f39c12;"><?= formatHours($activity['lunch_active']) ?>h</div>
                <div class="stat-label">Tiempo Almuerzo</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #e74c3c;"><?= formatHours($activity['after_active']) ?>h</div>
                <div class="stat-label">Fuera de Horario</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: #9b59b6;"><?= formatHours($activity['total_call']) ?>h</div>
                <div class="stat-label">En Llamadas</div>
            </div>
        </div>
 
        <!-- GRÁFICOS EN GRID 2 COLUMNAS -->
        <div class="charts-grid">
            <!-- GRÁFICO ACTIVIDAD DIARIA -->
            <div class="chart-container">
                <h3>📈 Actividad Diaria (Últimos 30 días)</h3>
                <div id="dailyChart" style="height: 350px;"></div>
            </div>
 
            <!-- GRÁFICO DISTRIBUCIÓN -->
            <div class="chart-container">
                <h3>🥧 Distribución de Tiempo</h3>
                <div id="categoryChart" style="height: 350px;"></div>
            </div>
        </div>
 
        <!-- TOP USUARIOS -->
        <div class="chart-container">
            <h3>🏆 Top 10 Usuarios Más Activos</h3>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>CC</th>
                            <th>Nombre</th>
                            <th>Días Trabajados</th>
                            <th>Tiempo Total</th>
                            <th>Tiempo Laboral</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topUsers as $idx => $user): ?>
                        <tr>
                            <td><strong><?= $idx + 1 ?></strong></td>
                            <td><?= htmlspecialchars($user['cc']) ?></td>
                            <td><?= htmlspecialchars($user['display_name']) ?></td>
                            <td><?= $user['days_worked'] ?> días</td>
                            <td><?= formatSeconds($user['total_active']) ?></td>
                            <td><?= formatSeconds($user['work_active']) ?></td>
                            <td>
                                <a href="user-dashboard.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-primary">📊 Ver Dashboard</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
 
        <!-- TOP APLICACIONES -->
        <div class="chart-container">
            <h3>💻 Aplicaciones Más Utilizadas</h3>
            <div id="topAppsChart" style="height: 400px;"></div>
        </div>
 
        <!-- ALERTAS -->
        <?php if (!empty($alerts)): ?>
        <div class="chart-container" style="border-left: 4px solid #e74c3c;">
            <h3>⚠️ Usuarios con Alertas</h3>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>CC</th>
                            <th>Nombre</th>
                            <th>Dispositivos</th>
                            <th>Última Actividad</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alerts as $alert): ?>
                        <tr>
                            <td><?= htmlspecialchars($alert['cc']) ?></td>
                            <td><?= htmlspecialchars($alert['display_name']) ?></td>
                            <td><?= $alert['device_count'] ?></td>
                            <td><?= $alert['last_seen'] ?? 'Nunca' ?></td>
                            <td>
                                <?php if ($alert['device_count'] == 0): ?>
                                    <span class="alert-badge alert-danger">Sin dispositivos</span>
                                <?php else: ?>
                                    <span class="alert-badge alert-warning">Inactivo 7+ días</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
 
    <script>
    // GRÁFICO ACTIVIDAD DIARIA
    const dailyChart = echarts.init(document.getElementById('dailyChart'));
    dailyChart.setOption({
        tooltip: { trigger: 'axis' },
        legend: { data: ['Laboral', 'Almuerzo', 'Fuera horario', 'Llamadas', 'Usuarios'] },
        xAxis: { type: 'category', data: <?= json_encode(array_column(array_reverse($dailyActivity), 'day_date')) ?> },
        yAxis: [
            { type: 'value', name: 'Horas' },
            { type: 'value', name: 'Usuarios' }
        ],
        series: [
            { name: 'Laboral', type: 'bar', stack: 'total', data: <?= json_encode(array_map(fn($d) => formatHours($d['work_active']), array_reverse($dailyActivity))) ?> },
            { name: 'Almuerzo', type: 'bar', stack: 'total', data: <?= json_encode(array_map(fn($d) => formatHours($d['lunch_active']), array_reverse($dailyActivity))) ?> },
            { name: 'Fuera horario', type: 'bar', stack: 'total', data: <?= json_encode(array_map(fn($d) => formatHours($d['after_active']), array_reverse($dailyActivity))) ?> },
            { name: 'Llamadas', type: 'line', data: <?= json_encode(array_map(fn($d) => formatHours($d['call_time']), array_reverse($dailyActivity))) ?> },
            { name: 'Usuarios', type: 'line', yAxisIndex: 1, data: <?= json_encode(array_column(array_reverse($dailyActivity), 'active_users')) ?> }
        ]
    });
 
    // GRÁFICO CATEGORÍAS
    const categoryChart = echarts.init(document.getElementById('categoryChart'));
    categoryChart.setOption({
        tooltip: { trigger: 'item', formatter: '{b}: {c}h ({d}%)' },
        legend: { bottom: '5%' },
        series: [{
            type: 'pie',
            radius: ['40%', '70%'],
            data: [
                { value: <?= formatHours($activity['work_active']) ?>, name: 'Laboral', itemStyle: { color: '#2980b9' } },
                { value: <?= formatHours($activity['lunch_active']) ?>, name: 'Almuerzo', itemStyle: { color: '#f39c12' } },
                { value: <?= formatHours($activity['after_active']) ?>, name: 'Fuera horario', itemStyle: { color: '#e74c3c' } },
                { value: <?= formatHours($activity['total_call']) ?>, name: 'Llamadas', itemStyle: { color: '#9b59b6' } }
            ]
        }]
    });
 
    // GRÁFICO TOP APPS
    const topAppsChart = echarts.init(document.getElementById('topAppsChart'));
    topAppsChart.setOption({
        tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
        xAxis: { type: 'value', name: 'Horas' },
        yAxis: { type: 'category', data: <?= json_encode(array_reverse(array_column($topApps, 'process_name'))) ?> },
        series: [{ 
            type: 'bar', 
            data: <?= json_encode(array_reverse(array_map(fn($a) => formatHours($a['total_duration']), $topApps))) ?>,
            itemStyle: { color: '#3498db' }
        }]
    });
 
    window.addEventListener('resize', () => {
        dailyChart.resize();
        categoryChart.resize();
        topAppsChart.resize();
    });
    </script>
</body>
</html>