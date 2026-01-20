<?php
require_once __DIR__ . '/../../src/Db.php';
 
$pdo = Keeper\Db::pdo();
 
// Estadísticas rápidas
$statsQuery = "
    SELECT 
        (SELECT COUNT(*) FROM keeper_users WHERE status='active') as active_users,
        (SELECT COUNT(*) FROM keeper_devices WHERE status='active') as active_devices,
        (SELECT COUNT(*) FROM keeper_sessions WHERE revoked_at IS NULL) as active_sessions,
        (SELECT COUNT(*) FROM keeper_device_locks WHERE is_active=1) as locked_devices
";
$stats = $pdo->query($statsQuery)->fetch(PDO::FETCH_ASSOC);
 
// Dispositivos activos recientemente
$recentDevices = $pdo->query("
    SELECT d.id, d.device_name, d.device_guid, d.last_seen_at, u.display_name
    FROM keeper_devices d
    LEFT JOIN keeper_users u ON d.user_id = u.id
    WHERE d.status = 'active'
    ORDER BY d.last_seen_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AZCKeeper - Panel de Administración</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php" class="active">Dashboard</a>
            <a href="devices.php">Dispositivos</a>
            <a href="policies.php">Políticas</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>Dashboard</h1>
 
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $stats['active_users'] ?></div>
                <div class="stat-label">Usuarios Activos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['active_devices'] ?></div>
                <div class="stat-label">Dispositivos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $stats['active_sessions'] ?></div>
                <div class="stat-label">Sesiones Activas</div>
            </div>
            <div class="stat-card alert">
                <div class="stat-value"><?= $stats['locked_devices'] ?></div>
                <div class="stat-label">Dispositivos Bloqueados</div>
            </div>
        </div>
 
        <h2>Dispositivos Recientes</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Usuario</th>
                    <th>Dispositivo</th>
                    <th>Última Conexión</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentDevices as $device): ?>
                <tr>
                    <td><?= htmlspecialchars($device['display_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($device['device_name']) ?></td>
                    <td><?= $device['last_seen_at'] ?></td>
                    <td>
                        <a href="device-detail.php?id=<?= $device['id'] ?>" class="btn btn-sm">Ver</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>