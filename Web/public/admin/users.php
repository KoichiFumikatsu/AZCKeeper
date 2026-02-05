<?php
//require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../src/bootstrap.php';
//require_once __DIR__ . '/../../../includes/validar_permiso.php';
/*
// Verificar autenticación
 $auth = new Auth();
 $auth->redirectIfNotLoggedIn();

// Verificar permisos
 $database = new Database();
 $conn = $database->getConnection();

// Obtener información del usuario actual
 $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name 
                 FROM employee e 
                 LEFT JOIN sedes s ON e.sede_id = s.id
                 LEFT JOIN firm f ON e.id_firm = f.id 
                 WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$_SESSION['user_id']]);
 $usuario_actual = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

// Verificar permiso para ver la página de usuarios
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'keeper', 'dashboard')) {
    header("Location: ../../../admin/dashboard.php");
    exit();
}

// Verificar permisos específicos para las acciones
 $permiso_ver_politicas = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'keeper', 'politicas');
 $permiso_ver_reportes = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'keeper', 'dashboard');
*/ 
 $pdo = Keeper\Db::pdo();

// Listar usuarios con conteo de dispositivos
 $users = $pdo->query("
    SELECT 
        u.id, 
        u.cc,
        u.display_name, 
        u.email,
        u.status,
        COUNT(DISTINCT d.id) as device_count,
        MAX(d.last_seen_at) as last_activity,
        (SELECT COUNT(*) FROM keeper_policy_assignments WHERE scope='user' AND user_id=u.id AND is_active=1) as has_policy
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id
    WHERE u.status = 'active'
    GROUP BY u.id
    ORDER BY u.display_name
")->fetchAll(PDO::FETCH_ASSOC);

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

// Definir la variable $current_page para el sidebar
 $current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keeper - Usuarios</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../../../assets/css/style.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.3/dist/echarts.min.js"></script>
    <style>
        /* ESTILOS ESPECÍFICOS PARA KEEPER */
        
        /* BOTONES - Colores corporativos */
        .btn-primary {
            background-color: #003a5d !important;
            border-color: #003a5d !important;
        }
        
        .btn-primary:hover {
            background-color: #002b47 !important;
            border-color: #002b47 !important;
        }
        
        .btn-outline-primary {
            border-color: #003a5d !important;
            color: #003a5d !important;
        }
        
        .btn-outline-primary:hover {
            background-color: #003a5d !important;
            border-color: #003a5d !important;
            color: white !important;
        }
        
        .btn-danger {
            background-color: #be1622 !important;
            border-color: #be1622 !important;
        }
        
        .btn-danger:hover {
            background-color: #a0121d !important;
            border-color: #a0121d !important;
        }
        
        /* BADGES - Colores corporativos */
        .badge.bg-success {
            background-color: #198754 !important;
        }
        
        .badge.bg-warning {
            background-color: #ffc107 !important;
            color: #353132 !important;
        }
        
        .badge.bg-danger {
            background-color: #be1622 !important;
        }
        
        .badge.bg-primary {
            background-color: #003a5d !important;
        }
        
        .badge.bg-secondary {
            background-color: #9d9d9c !important;
            color: #353132 !important;
        }
        
        .badge.bg-dark {
            background-color: #353132 !important;
        }
        
        .badge.bg-info {
            background-color: #003a5d !important;
            opacity: 0.8;
        }
        
        /* TABLAS */
        .table-hover tbody tr:hover {
            background-color: rgba(0, 58, 93, 0.05) !important;
        }
        
        .card-header {
            background-color: #003a5d !important;
            border-color: #003a5d;
        }
        
        /* Estilos específicos para Keeper */
        .dashboard-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 1.5rem; 
            margin: 2rem 0; 
        }
        
        .stat-card { 
            background: white; 
            padding: 1.5rem; 
            border-radius: 12px; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1); 
            text-align: center; 
            transition: transform 0.2s; 
            border: 2px solid #003a5d;
        }
        
        .stat-card:hover { 
            transform: translateY(-5px); 
            box-shadow: 0 6px 12px rgba(0,0,0,0.15); 
        }
        
        .stat-icon { 
            font-size: 2.5rem; 
            margin-bottom: 1rem; 
            color: #003a5d;
        }
        
        .stat-value { 
            font-size: 2.5rem; 
            font-weight: bold; 
            color: #003a5d; 
            margin: 0.5rem 0; 
        }
        
        .stat-label { 
            color: #666; 
            font-size: 0.9rem; 
        }
        
        .chart-container { 
            background: white; 
            padding: 1.5rem; 
            border-radius: 12px; 
            margin: 1.5rem 0; 
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            border: 2px solid #003a5d;
        }
        
        .data-table { 
            width: 100%; 
            background: white; 
            border-radius: 8px; 
            overflow: hidden; 
            border: 2px solid #003a5d;
        }
        
        .data-table th { 
            background: #003a5d; 
            color: white; 
            padding: 1rem; 
            text-align: left; 
        }
        
        .data-table td { 
            padding: 0.8rem; 
            border-bottom: 1px solid #eee; 
        }
        
        .data-table tr:hover { 
            background: #f8f9fa; 
        }
        
        .action-btn { 
            display: inline-block; 
            padding: 0.5rem 1rem; 
            background: #003a5d; 
            color: white; 
            text-align: center; 
            border-radius: 4px; 
            text-decoration: none; 
            font-weight: bold; 
            transition: all 0.2s; 
            margin-right: 0.5rem;
        }
        
        .action-btn:hover { 
            background: #002b47; 
            transform: scale(1.05); 
        }
        
        .action-btn.secondary { 
            background: #6c757d; 
        }
        
        .action-btn.secondary:hover { 
            background: #5a6268; 
        }
        
        .action-btn.tertiary { 
            background: #198754; 
        }
        
        .action-btn.tertiary:hover { 
            background: #157347; 
        }
        
        .alert-badge { 
            display: inline-block; 
            padding: 0.3rem 0.6rem; 
            border-radius: 4px; 
            font-size: 0.8rem; 
            font-weight: bold; 
        }
        
        .alert-warning { 
            background: #fff3cd; 
            color: #856404; 
        }
        
        .alert-danger { 
            background: #f8d7da; 
            color: #721c24; 
        }
        
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
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
        
        .sidebar-icon-keeper {
            width: 18px;
            height: 18px;
            margin-right: 10px;
            vertical-align: middle;
        }
         .icon-keeper {
            width: 25px;
            height: 25px;
            margin-right: 0px;
            vertical-align: middle;
        }
    </style>
</head>
<body>
    <?php //include '../../../includes/headerK.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php //include '../../../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
        <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
            <h1 class="h2">Keeper</h1>
            
            <?php //if ($permiso_ver_politicas || $permiso_ver_reportes): ?>
            <div class="btn-toolbar mb-2 mb-md-0">
                <div class="btn-group me-2">
                    <?php //if ($permiso_ver_politicas): ?>
                    <a href="policies.php" class="btn btn-primary">
                        <i class="bi bi-gear"></i> Política Global
                    </a>
                    <?php //endif; ?>
                    <?php //if ($permiso_ver_reportes): ?>
                    <button class="btn btn-outline-success" type="button" data-bs-toggle="collapse" data-bs-target="#dashboardCollapse">
                        <i class="bi bi-bar-chart"></i> Ver Reportes
                    </button>
                    <?php //endif; ?>
                </div>
            </div>
            <?php //endif; ?>
        </div>
                
                <!-- Dashboard Colapsable -->
                <div class="collapse" id="dashboardCollapse">
                    <div class="card">
                        <div class="card-header text-white">
                            <h5 class="card-title mb-0">
                                <img 
                                    src="../../../assets/images/keeper_white.png" 
                                    class="icon-keeper" 
                                    alt="Keeper Icon"> Dashboard Keeper
                            </h5>
                        </div>
                        <div class="card-body">
                            <!-- ESTADÍSTICAS GENERALES -->
                            <div class="dashboard-grid">
                                <div class="stat-card">
                                    <div class="stat-value"><?= $stats['total_users'] ?></div>
                                    <div class="stat-label">Usuarios Activos</div>
                                </div>
                                <div class="stat-card">

                                    <div class="stat-value"><?= $stats['total_devices'] ?></div>
                                    <div class="stat-label">Dispositivos Totales</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value"><?= $stats['active_devices_24h'] ?></div>
                                    <div class="stat-label">Activos (24h)</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value"><?= $stats['custom_policies'] ?></div>
                                    <div class="stat-label">Políticas Personalizadas</div>
                                </div>
                            </div>

                            <!-- DÍAS DE ACTIVIDAD -->
                            <div class="dashboard-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                                <div class="stat-card">
                                    <div class="stat-value" style="color: #27ae60;"><?= $activity['workdays'] ?></div>
                                    <div class="stat-label">Días Laborables</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value" style="color: #e67e22;">
                                        <?= $activity['non_workdays'] ?>
                                        <?php if ($activity['non_workdays'] > 0): ?>
                                            <i class="bi bi-exclamation-triangle-fill" style="font-size: 0.7em;" title="Actividad en fin de semana"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="stat-label">Fines de Semana Trabajados</div>
                                </div>
                                <div class="stat-card">
                                    <div class="stat-value" style="color: #3498db;"><?= $activity['total_days'] ?></div>
                                    <div class="stat-label">Total Días Registrados</div>
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
                                    <div class="stat-value" style="color: #f39c12;"><?= formatHours($activity['lunch_idle']) ?>h</div>
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

                            <!-- TOP USUARIOS -->
                            <div class="chart-container">
                                <h3>Top 5 Usuarios Más Activos</h3>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>CC</th>
                                                <th>Nombre</th>
                                                <th>Días Laborados</th>
                                                <th>Fines de Semana</th>
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
                                                <td>
                                                    <span class="badge bg-success"><?= $user['days_worked'] ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($user['weekend_days'] > 0): ?>
                                                        <span class="badge bg-warning text-dark" title="Trabajó en fin de semana">
                                                            <?= $user['weekend_days'] ?> 
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">0</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= formatSeconds($user['total_active']) ?></td>
                                                <td><?= formatSeconds($user['work_active']) ?></td>
                                                <td>
                                                    <a href="user-dashboard.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-bar-chart"></i> Dashboard</a>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Lista de Usuarios -->
                <div class="card mt-4">
                    <div class="card-header text-white">
                        <h5 class="card-title mb-0">
                            <img 
                                src="../../../assets/images/keeper_white.png" 
                                class="icon-keeper" 
                                alt="Keeper Icon"> Lista de Usuarios
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>CC (Cédula)</th>
                                        <th>Nombre</th>
                                        <th>Email</th>
                                        <th>Dispositivos</th>
                                        <th>Última Actividad</th>
                                        <th>Política</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($user['cc'] ?? 'N/A') ?></strong></td>
                                        <td><?= htmlspecialchars($user['display_name']) ?></td>
                                        <td><?= htmlspecialchars($user['email'] ?? 'N/A') ?></td>
                                        <td><?= $user['device_count'] ?> equipo(s)</td>
                                        <td><?= $user['last_activity'] ?? 'Nunca' ?></td>
                                        <td>
                                            <?php if ($user['has_policy']): ?>
                                                <span class="badge bg-success">✓ Personalizada</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Global</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="user-config.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-secondary" title="Configurar Política">
                                                <i class="bi bi-gear"></i> Política
                                            </a>
                                            <a href="user-dashboard.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-primary" title="Ver Dashboard">
                                                <i class="bi bi-bar-chart"></i> Dashboard
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Debug en consola para detectar errores
        window.addEventListener('error', function(e) {
            console.error('Error detectado:', e.error);
        });
        
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Promesa rechazada no manejada:', e.reason);
        });
        
        console.log('Página de usuarios Keeper cargada correctamente');
        
        // CORRECCIÓN: Sobreescribir las funciones del sidebar para usar rutas absolutas
        function loadPendingTicketsCount() {
            fetch('../../../includes/get_pending_tickets.php')
                .then(response => response.json())
                .then(data => {
                    if (data.count > 0) {
                        document.getElementById('pending-tickets-count').textContent = data.count;
                        document.getElementById('pending-tickets-count').style.display = 'flex';
                    } else {
                        document.getElementById('pending-tickets-count').style.display = 'none';
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        function loadPendingPermisosCount() {
            fetch('../../../includes/get_pending_permisos.php')
                .then(response => response.json())
                .then(data => {
                    const badge = document.getElementById('pending-permisos-count');
                    if (badge && data.count > 0) {
                        badge.textContent = data.count;
                        badge.style.display = 'flex';
                    } else if (badge) {
                        badge.style.display = 'none';
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        // Inicializar las funciones del sidebar
        document.addEventListener('DOMContentLoaded', function() {
            // Cargar contadores de tickets y permisos
            loadPendingTicketsCount();
            loadPendingPermisosCount();
            
            // Actualizar cada 2 minutos
            setInterval(loadPendingTicketsCount, 120000);
            setInterval(loadPendingPermisosCount, 120000);
        });
    </script>
</body>
</html>

