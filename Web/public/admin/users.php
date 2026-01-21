<?php
require_once __DIR__ . '/../../src/bootstrap.php';
 
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
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Usuarios - AZCKeeper Admin</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="users.php" class="active">Usuarios</a>
            <a href="policies.php">Política Global</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>👥 Gestión de Usuarios</h1>
 
        <table class="data-table">
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
                            <span class="badge" style="background: #d4edda; color: #155724;">✓ Personalizada</span>
                        <?php else: ?>
                            <span class="badge" style="background: #e7e7e7; color: #666;">Global</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="user-dashboard.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-primary" title="Ver Dashboard">
                            📊 Dashboard
                        </a>
                        <a href="user-config.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-secondary" title="Configurar Política">
                            ⚙️ Política
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>