<?php
require_once __DIR__ . '/../../src/bootstrap.php';
$pdo = Keeper\Db::pdo();
 
$stats = $pdo->query("
    SELECT 
        (SELECT COUNT(*) FROM keeper_users WHERE status='active') as active_users,
        (SELECT COUNT(DISTINCT d.device_guid) FROM keeper_devices d WHERE d.status='active') as active_devices,
        (SELECT COUNT(*) FROM keeper_sessions WHERE revoked_at IS NULL) as active_sessions
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>AZCKeeper - Panel de Administración</title>
    <link rel="stylesheet" href="assets/style.css">
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
        </div>
 
        <div style="text-align: center; margin-top: 2rem;">
            <a href="users.php" class="btn btn-primary" style="font-size: 1.2rem; padding: 1rem 2rem;">👥 Gestionar Usuarios</a>
            <a href="policies.php" class="btn btn-secondary" style="font-size: 1.2rem; padding: 1rem 2rem;">⚙️ Política Global</a>
        </div>
    </div>
</body>
</html>