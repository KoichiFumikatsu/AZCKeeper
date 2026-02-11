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

// Paginación
$perPage = 25;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// Contar total de usuarios
$totalUsers = $pdo->query("
    SELECT COUNT(DISTINCT u.id) as total
    FROM keeper_users u
    WHERE u.status = 'active'
")->fetch(PDO::FETCH_ASSOC)['total'];

$totalPages = ceil($totalUsers / $perPage);

// Listar usuarios con info de dispositivo actual y última actividad
// Usar TIMESTAMPDIFF de MySQL para evitar problemas de timezone
$users = $pdo->query("
    SELECT 
        u.id, 
        u.cc,
        u.display_name, 
        u.email,
        u.status,
        MAX(d.last_seen_at) as last_activity,
        MAX(d.last_seen_at) as last_seen,
        TIMESTAMPDIFF(SECOND, MAX(d.last_seen_at), NOW()) as seconds_since_seen,
        (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
         WHERE a.user_id = u.id AND a.day_date = CURDATE()) as last_event,
        TIMESTAMPDIFF(SECOND, 
            (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
             WHERE a.user_id = u.id AND a.day_date = CURDATE()), 
            NOW()) as seconds_since_event,
        (SELECT COUNT(*) FROM keeper_policy_assignments WHERE scope='user' AND user_id=u.id AND is_active=1) as has_policy
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id
    WHERE u.status = 'active'
    GROUP BY u.id
    ORDER BY u.display_name
    LIMIT {$perPage} OFFSET {$offset}
")->fetchAll(PDO::FETCH_ASSOC);

// Calcular estado de conexión para cada usuario usando los segundos calculados por MySQL
foreach ($users as &$user) {
    $user['connection_status'] = calculateUserStatus($user['seconds_since_seen'], $user['seconds_since_event']);
}
unset($user);

// Calcular contadores de estado (de todos los usuarios, no solo la página actual)
$allUsersForCount = $pdo->query("
    SELECT 
        u.id,
        TIMESTAMPDIFF(SECOND, MAX(d.last_seen_at), NOW()) as seconds_since_seen,
        TIMESTAMPDIFF(SECOND, 
            (SELECT MAX(a.last_event_at) FROM keeper_activity_day a 
             WHERE a.user_id = u.id AND a.day_date = CURDATE()), 
            NOW()) as seconds_since_event
    FROM keeper_users u
    LEFT JOIN keeper_devices d ON d.user_id = u.id
    WHERE u.status = 'active'
    GROUP BY u.id
")->fetchAll(PDO::FETCH_ASSOC);

$statusCounts = [
    'active' => 0,
    'away' => 0,
    'inactive' => 0,
    'offline' => 0
];
foreach ($allUsersForCount as $userCount) {
    $status = calculateUserStatus($userCount['seconds_since_seen'], $userCount['seconds_since_event']);
    $statusCounts[$status]++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AZCKeeper - Usuarios</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        /* Estilos del indicador de estado */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.3rem 0.6rem;
            border-radius: 15px;
            font-size: 0.75rem;
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
            background: #fff3cd;
            color: #856404;
        }
        
        .status-away .status-dot {
            background: #ffc107;
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
        
        /* Contadores de estado */
        .status-counters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .status-counter-card {
            background: white;
            border: 2px solid #94A3B8;
            border-radius: 8px;
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .status-counter-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(15, 23, 42, 0.15);
        }
        
        .status-counter-card.active-card { border-color: #059669; }
        .status-counter-card.away-card { border-color: #F59E0B; }
        .status-counter-card.inactive-card { border-color: #94A3B8; }
        .status-counter-card.offline-card { border-color: #94A3B8; }
        
        .status-counter-icon {
            font-size: 2rem;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .status-counter-card.active-card .status-counter-icon {
            background: #ECFDF5;
            color: #059669;
        }
        
        .status-counter-card.away-card .status-counter-icon {
            background: #FEF3C7;
            color: #F59E0B;
        }
        
        .status-counter-card.inactive-card .status-counter-icon {
            background: #F3F4F6;
            color: #94A3B8;
        }
        
        .status-counter-card.offline-card .status-counter-icon {
            background: #F3F4F6;
            color: #94A3B8;
        }
        
        .status-counter-info h3 {
            margin: 0;
            font-size: 1.8rem;
            font-weight: bold;
        }
        
        .status-counter-info p {
            margin: 0;
            font-size: 0.85rem;
            color: #94A3B8;
        }
        
        /* Filtros */
        .filters {
            background: white;
            border: 1px solid #94A3B8;
            border-radius: 8px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        
        .filter-group label {
            font-weight: 600;
            font-size: 0.85rem;
            color: #0F172A;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 0.6rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 0.9rem;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #1E3A8A;
            box-shadow: 0 0 0 3px rgba(30, 58, 138, 0.1);
        }
        
        .filter-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn-filter {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn-filter.primary {
            background: #1E3A8A;
            color: white;
        }
        
        .btn-filter.primary:hover {
            background: #1E40AF;
        }
        
        .btn-filter.secondary {
            background: transparent;
            color: #1E3A8A;
            border: 1px solid #94A3B8;
        }
        
        .btn-filter.secondary:hover {
            background: #F3F4F6;
        }
        
        .no-results {
            text-align: center;
            padding: 3rem;
            color: #999;
        }
        
        .no-results i {
            font-size: 3rem;
            margin-bottom: 1rem;
            display: block;
        }
        
        /* Paginación */
        .pagination {
            display: flex;
            gap: 0.5rem;
            justify-content: center;
            align-items: center;
            margin: 1.5rem 0 0.5rem;
        }
        
        .page-link {
            padding: 0.5rem 1rem;
            border: 1px solid #94A3B8;
            border-radius: 4px;
            text-decoration: none;
            color: #1E3A8A;
            background: white;
            transition: all 0.2s;
            font-weight: 500;
        }
        
        .page-link:hover {
            background: #1E3A8A;
            color: white;
            border-color: #1E3A8A;
        }
        
        .page-link.active {
            background: #1E3A8A;
            color: white;
            font-weight: 600;
            border-color: #1E3A8A;
        }
        
        .pagination-info {
            text-align: center;
            color: #94A3B8;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand"><img src="assets/Icon White.png" alt="AZC" style="height: 24px; vertical-align: middle; margin-right: 8px;"> AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="users.php" class="active">Usuarios</a>
            <a href="policies.php">Política Global</a>
            <a href="releases.php">Releases</a>
        </div>
    </nav>

    <div class="container">
        <h1><i class="bi bi-people-fill"></i> Gestión de Usuarios</h1>
        
        <!-- Contadores de estado -->
        <div class="status-counters">
            <div class="status-counter-card active-card">
                <div class="status-counter-icon">✓</div>
                <div class="status-counter-info">
                    <h3><?= $statusCounts['active'] ?></h3>
                    <p>Activos</p>
                </div>
            </div>
            <div class="status-counter-card away-card">
                <div class="status-counter-icon">⏸</div>
                <div class="status-counter-info">
                    <h3><?= $statusCounts['away'] ?></h3>
                    <p>Ausentes</p>
                </div>
            </div>
            <div class="status-counter-card inactive-card">
                <div class="status-counter-icon">⏹</div>
                <div class="status-counter-info">
                    <h3><?= $statusCounts['inactive'] ?></h3>
                    <p>Sin Conexión</p>
                </div>
            </div>
            <div class="status-counter-card offline-card">
                <div class="status-counter-icon">○</div>
                <div class="status-counter-info">
                    <h3><?= $statusCounts['offline'] ?></h3>
                    <p>Sin Dispositivo</p>
                </div>
            </div>
        </div>
        
        <!-- Filtros -->
        <div class="filters">
            <div class="filters-grid">
                <div class="filter-group">
                    <label for="filterName">
                        <i class="bi bi-search"></i> Buscar por Nombre/CC
                    </label>
                    <input type="text" id="filterName" placeholder="Escribe para buscar...">
                </div>
                <div class="filter-group">
                    <label for="filterStatus">
                        <i class="bi bi-broadcast"></i> Estado
                    </label>
                    <select id="filterStatus">
                        <option value="">Todos los estados</option>
                        <option value="active">Activo</option>
                        <option value="away">Ausente</option>
                        <option value="inactive">Sin Conexión</option>
                        <option value="offline">Sin Dispositivo</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filterPolicy">
                        <i class="bi bi-shield-check"></i> Política
                    </label>
                    <select id="filterPolicy">
                        <option value="">Todas las políticas</option>
                        <option value="personalizada">Personalizada</option>
                        <option value="global">Global</option>
                    </select>
                </div>
            </div>
            <div class="filter-actions">
                <button class="btn-filter secondary" id="clearFilters">
                    <i class="bi bi-x-circle"></i> Limpiar Filtros
                </button>
            </div>
        </div>
        
        <div class="section">
            <div class="table-container">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Estado</th>
                            <th>Nombre</th>
                            <th>Última Actividad</th>
                            <th>Política</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="usersTableBody">
                        <?php 
                        $statusTextMap = [
                            'active' => 'Activo',
                            'away' => 'Ausente',
                            'inactive' => 'Sin Conexión',
                            'offline' => 'Sin Dispositivo'
                        ];
                        foreach ($users as $user): 
                            $status = $user['connection_status'];
                            $statusText = $statusTextMap[$status] ?? $status;
                        ?>
                        <tr class="user-row" 
                            data-name="<?= htmlspecialchars(strtolower($user['display_name'])) ?>" 
                            data-cc="<?= htmlspecialchars(strtolower($user['cc'] ?? '')) ?>" 
                            data-status="<?= $status ?>" 
                            data-policy="<?= $user['has_policy'] ? 'personalizada' : 'global' ?>">
                            <td>
                                <span id="status-<?= htmlspecialchars($user['id']) ?>" class="status-indicator status-<?= $status ?>">
                                    <span class="status-dot"></span>
                                    <span class="status-text"><?= $statusText ?></span>
                                </span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($user['display_name']) ?></strong>
                                <br>
                                <small style="color: #666;"><?= htmlspecialchars($user['cc'] ?? 'N/A') ?></small>
                            </td>
                            <td>
                                <?php if ($user['last_event']): ?>
                                    <?= htmlspecialchars($user['last_event']) ?>
                                <?php else: ?>
                                    <span style="color: #999;">Sin actividad hoy</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['has_policy']): ?>
                                    <span class="badge badge-active">✓ Personalizada</span>
                                <?php else: ?>
                                    <span class="badge badge-revoked">Global</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="user-dashboard.php?id=<?= htmlspecialchars($user['id']) ?>" class="btn btn-sm btn-primary" title="Ver Dashboard">
                                    <i class="bi bi-bar-chart"></i>
                                </a>
                                <a href="user-config.php?id=<?= htmlspecialchars($user['id']) ?>" class="btn btn-sm btn-secondary" title="Configurar Política">
                                    <i class="bi bi-gear"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div id="noResults" class="no-results" style="display: none;">
                    <i class="bi bi-search"></i>
                    <p>No se encontraron usuarios que coincidan con los filtros</p>
                </div>
            </div>
            
            <!-- Paginación -->
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1" class="page-link">Primera</a>
                    <a href="?page=<?= $page - 1 ?>" class="page-link">Anterior</a>
                <?php endif; ?>
                
                <?php 
                $startPage = max(1, $page - 2);
                $endPage = min($totalPages, $page + 2);
                
                for ($i = $startPage; $i <= $endPage; $i++): 
                ?>
                    <a href="?page=<?= $i ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="page-link">Siguiente</a>
                    <a href="?page=<?= $totalPages ?>" class="page-link">Última</a>
                <?php endif; ?>
            </div>
            <div class="pagination-info">
                Mostrando <?= min($offset + 1, $totalUsers) ?> - <?= min($offset + $perPage, $totalUsers) ?> de <?= $totalUsers ?> usuarios (Página <?= $page ?> de <?= $totalPages ?>)
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filtrado con búsqueda en todos los usuarios (AJAX)
        const filterName = document.getElementById('filterName');
        const filterStatus = document.getElementById('filterStatus');
        const filterPolicy = document.getElementById('filterPolicy');
        const clearFiltersBtn = document.getElementById('clearFilters');
        const userRows = document.querySelectorAll('.user-row');
        const noResults = document.getElementById('noResults');
        const table = document.querySelector('.data-table');
        const tableBody = document.getElementById('usersTableBody');
        const pagination = document.querySelector('.pagination');
        const paginationInfo = document.querySelector('.pagination-info');
        
        const statusTextMap = {
            'active': 'Activo',
            'away': 'Ausente',
            'inactive': 'Sin Conexión',
            'offline': 'Sin Dispositivo'
        };
        
        let searchTimeout;
        
        async function applyFilters() {
            const nameValue = filterName.value.trim();
            const statusValue = filterStatus.value;
            const policyValue = filterPolicy.value;
            
            const hasActiveFilters = nameValue || statusValue || policyValue;
            
            // Si no hay filtros, recargar página para volver a paginación normal
            if (!hasActiveFilters) {
                window.location.href = 'users.php';
                return;
            }
            
            // Ocultar paginación cuando hay filtros
            pagination.style.display = 'none';
            paginationInfo.style.display = 'none';
            
            // Hacer búsqueda AJAX en todos los usuarios
            try {
                const params = new URLSearchParams();
                if (nameValue) params.append('name', nameValue);
                if (statusValue) params.append('status', statusValue);
                if (policyValue) params.append('policy', policyValue);
                
                const response = await fetch(`search-users.php?${params.toString()}`);
                const data = await response.json();
                
                if (data.success) {
                    // Limpiar tabla
                    tableBody.innerHTML = '';
                    
                    if (data.users.length === 0) {
                        table.style.display = 'none';
                        noResults.style.display = 'block';
                    } else {
                        table.style.display = 'table';
                        noResults.style.display = 'none';
                        
                        // Renderizar usuarios filtrados
                        data.users.forEach(user => {
                            const statusText = statusTextMap[user.connection_status] || user.connection_status;
                            const row = document.createElement('tr');
                            row.className = 'user-row';
                            row.innerHTML = `
                                <td>
                                    <span class="status-indicator status-${user.connection_status}">
                                        <span class="status-dot"></span>
                                        <span class="status-text">${statusText}</span>
                                    </span>
                                </td>
                                <td>
                                    <strong>${escapeHtml(user.display_name)}</strong>
                                    <br>
                                    <small style="color: #666;">${escapeHtml(user.cc)}</small>
                                </td>
                                <td>
                                    ${user.last_event ? escapeHtml(user.last_event) : '<span style="color: #999;">Sin actividad hoy</span>'}
                                </td>
                                <td>
                                    ${user.has_policy ? 
                                        '<span class="badge badge-active">✓ Personalizada</span>' : 
                                        '<span class="badge badge-revoked">Global</span>'}
                                </td>
                                <td>
                                    <a href="user-dashboard.php?id=${user.id}" class="btn btn-sm btn-primary" title="Ver Dashboard">
                                        <i class="bi bi-bar-chart"></i>
                                    </a>
                                    <a href="user-config.php?id=${user.id}" class="btn btn-sm btn-secondary" title="Configurar Política">
                                        <i class="bi bi-gear"></i>
                                    </a>
                                </td>
                            `;
                            tableBody.appendChild(row);
                        });
                    }
                }
            } catch (error) {
                console.error('Error al buscar usuarios:', error);
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        function clearFilters() {
            window.location.href = 'users.php';
        }
        
        // Event listeners con debounce para el input de nombre
        filterName.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(applyFilters, 300);
        });
        
        filterStatus.addEventListener('change', applyFilters);
        filterPolicy.addEventListener('change', applyFilters);
        clearFiltersBtn.addEventListener('click', clearFilters);
    </script>
</body>
</html>

