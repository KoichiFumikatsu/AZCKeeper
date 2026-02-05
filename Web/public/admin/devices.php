<?php
require_once __DIR__ . '/../../src/bootstrap.php';

$pdo = Keeper\Db::pdo();

// Query sin parámetros externos - no vulnerable pero convertido por consistencia
$devices = $pdo->query("
    SELECT 
        d.id, d.device_name, d.device_guid, d.status, d.last_seen_at,
        u.display_name, u.legacy_employee_id,
        (SELECT COUNT(*) FROM keeper_device_locks WHERE device_id=d.id AND is_active=1) as is_locked
    FROM keeper_devices d
    LEFT JOIN keeper_users u ON d.user_id = u.id
    ORDER BY d.last_seen_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Dispositivos - AZCKeeper Admin</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="devices.php" class="active">Dispositivos</a>
            <a href="policies.php">Políticas</a>
            <a href="releases.php">Releases</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>Gestión de Dispositivos</h1>
 
        <table class="data-table">
            <thead>
                <tr>
                    <th>Usuario (CC)</th>
                    <th>Dispositivo</th>
                    <th>Estado</th>
                    <th>Última Conexión</th>
                    <th>Bloqueado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($devices as $device): ?>
                <tr class="<?= $device['is_locked'] ? 'row-locked' : '' ?>">
                    <td>
                        <?= htmlspecialchars($device['display_name']) ?>
                        <small>(<?= htmlspecialchars($device['legacy_employee_id']) ?>)</small>
                    </td>
                    <td><?= htmlspecialchars($device['device_name']) ?></td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($device['status']) ?>">
                            <?= htmlspecialchars($device['status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($device['last_seen_at']) ?></td>
                    <td>
                        <?= $device['is_locked'] ? '🔒 Sí' : '✓ No' ?>
                    </td>
                    <td>
                        <!-- Administración de dispositivos individual eliminada -->
                        <span class="badge badge-secondary">Info</span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>