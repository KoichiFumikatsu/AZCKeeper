<?php
require_once __DIR__ . '/../../src/bootstrap.php';

use Keeper\Db;
use Keeper\Repos\ReleaseRepo;

$pdo = Db::pdo();
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        try {
            // Validate version format
            $version = trim($_POST['version']);
            if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $version)) {
                throw new Exception('Formato de versión inválido. Use: X.Y.Z.W (ej: 3.0.0.1)');
            }
            
            // Check if version already exists
            if (ReleaseRepo::versionExists($version)) {
                throw new Exception('La versión ' . $version . ' ya existe');
            }
            
            $data = [
                'version' => $version,
                'download_url' => trim($_POST['download_url']),
                'file_size' => (int)($_POST['file_size'] ?? 0),
                'release_notes' => trim($_POST['release_notes']),
                'is_beta' => isset($_POST['is_beta']) ? 1 : 0,
                'force_update' => isset($_POST['force_update']) ? 1 : 0,
                'minimum_version' => !empty($_POST['minimum_version']) ? trim($_POST['minimum_version']) : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'release_date' => !empty($_POST['release_date']) ? $_POST['release_date'] : date('Y-m-d')
            ];
            
            ReleaseRepo::create($data);
            $message = 'Release ' . $version . ' creado exitosamente';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if ($action === 'edit') {
        try {
            $id = (int)$_POST['id'];
            $version = trim($_POST['version']);
            
            // Validate version format
            if (!preg_match('/^\d+\.\d+\.\d+\.\d+$/', $version)) {
                throw new Exception('Formato de versión inválido. Use: X.Y.Z.W (ej: 3.0.0.1)');
            }
            
            // Check if version already exists (excluding current)
            if (ReleaseRepo::versionExists($version, $id)) {
                throw new Exception('La versión ' . $version . ' ya existe');
            }
            
            $data = [
                'version' => $version,
                'download_url' => trim($_POST['download_url']),
                'file_size' => (int)($_POST['file_size'] ?? 0),
                'release_notes' => trim($_POST['release_notes']),
                'is_beta' => isset($_POST['is_beta']) ? 1 : 0,
                'force_update' => isset($_POST['force_update']) ? 1 : 0,
                'minimum_version' => !empty($_POST['minimum_version']) ? trim($_POST['minimum_version']) : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'release_date' => !empty($_POST['release_date']) ? $_POST['release_date'] : date('Y-m-d')
            ];
            
            ReleaseRepo::update($id, $data);
            $message = 'Release actualizado exitosamente';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if ($action === 'delete') {
        try {
            $id = (int)$_POST['id'];
            ReleaseRepo::delete($id);
            $message = 'Release eliminado exitosamente';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
    
    if ($action === 'toggle_active') {
        try {
            $id = (int)$_POST['id'];
            $release = ReleaseRepo::getById($id);
            if ($release) {
                ReleaseRepo::update($id, ['is_active' => $release['is_active'] ? 0 : 1]);
                $message = 'Estado actualizado';
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get all releases
$releases = ReleaseRepo::getAllReleases(false, false);
$editRelease = null;

// If editing, load release data
if (isset($_GET['edit'])) {
    $editRelease = ReleaseRepo::getById((int)$_GET['edit']);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Releases - AZCKeeper Admin</title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .release-form { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-full { grid-column: 1 / -1; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
        .form-group input[type="text"],
        .form-group input[type="number"],
        .form-group input[type="date"],
        .form-group textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .form-group textarea { min-height: 100px; font-family: monospace; }
        .checkbox-group { display: flex; align-items: center; gap: 10px; }
        .checkbox-group input { width: auto; }
        .btn-group { display: flex; gap: 10px; margin-top: 20px; }
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-secondary { background: #6c757d; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .alert-danger { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .badge-beta { background: #ffc107; color: #000; }
        .badge-force { background: #dc3545; color: white; }
        .badge-inactive { background: #6c757d; color: white; }
        .release-notes { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="nav-brand">🔒 AZCKeeper Admin</div>
        <div class="nav-links">
            <a href="index.php">Dashboard</a>
            <a href="devices.php">Dispositivos</a>
            <a href="policies.php">Políticas</a>
            <a href="releases.php" class="active">Releases</a>
        </div>
    </nav>
 
    <div class="container">
        <h1>📦 Gestión de Releases del Cliente</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Release Form -->
        <div class="release-form">
            <h2><?= $editRelease ? 'Editar Release' : 'Crear Nueva Release' ?></h2>
            <form method="POST">
                <input type="hidden" name="action" value="<?= $editRelease ? 'edit' : 'create' ?>">
                <?php if ($editRelease): ?>
                    <input type="hidden" name="id" value="<?= $editRelease['id'] ?>">
                <?php endif; ?>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Versión *</label>
                        <input type="text" name="version" placeholder="3.0.0.1" 
                               value="<?= $editRelease ? htmlspecialchars($editRelease['version']) : '' ?>" required>
                        <small>Formato: X.Y.Z.W (ej: 3.0.0.1)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Fecha de Release</label>
                        <input type="date" name="release_date" 
                               value="<?= $editRelease ? $editRelease['release_date'] : date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group form-full">
                        <label>URL de Descarga *</label>
                        <input type="text" name="download_url" 
                               placeholder="https://github.com/.../AZCKeeper_v3.0.0.1.zip"
                               value="<?= $editRelease ? htmlspecialchars($editRelease['download_url']) : '' ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Tamaño del Archivo (bytes)</label>
                        <input type="number" name="file_size" 
                               value="<?= $editRelease ? $editRelease['file_size'] : '0' ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Versión Mínima Requerida</label>
                        <input type="text" name="minimum_version" placeholder="3.0.0.0"
                               value="<?= $editRelease ? htmlspecialchars($editRelease['minimum_version'] ?? '') : '' ?>">
                        <small>Versiones menores serán forzadas a actualizar</small>
                    </div>
                    
                    <div class="form-group form-full">
                        <label>Notas de la Versión</label>
                        <textarea name="release_notes" placeholder="Descripción de cambios, mejoras, bugs corregidos..."><?= $editRelease ? htmlspecialchars($editRelease['release_notes']) : '' ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_beta" id="is_beta" 
                                   <?= $editRelease && $editRelease['is_beta'] ? 'checked' : '' ?>>
                            <label for="is_beta">Versión Beta</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="force_update" id="force_update"
                                   <?= $editRelease && $editRelease['force_update'] ? 'checked' : '' ?>>
                            <label for="force_update">Forzar Actualización</label>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active"
                                   <?= !$editRelease || $editRelease['is_active'] ? 'checked' : '' ?>>
                            <label for="is_active">Activo (disponible para clientes)</label>
                        </div>
                    </div>
                </div>
                
                <div class="btn-group">
                    <button type="submit" class="btn btn-primary">
                        <?= $editRelease ? 'Actualizar Release' : 'Crear Release' ?>
                    </button>
                    <?php if ($editRelease): ?>
                        <a href="releases.php" class="btn btn-secondary">Cancelar</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Releases Table -->
        <h2>Releases Disponibles</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Versión</th>
                    <th>Fecha</th>
                    <th>Estado</th>
                    <th>Flags</th>
                    <th>Min. Version</th>
                    <th>Tamaño</th>
                    <th>Notas</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($releases)): ?>
                <tr>
                    <td colspan="8" style="text-align:center;">No hay releases registrados</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($releases as $release): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($release['version']) ?></strong></td>
                        <td><?= htmlspecialchars($release['release_date'] ?? 'N/A') ?></td>
                        <td>
                            <?php if ($release['is_active']): ?>
                                <span class="badge badge-online">Activo</span>
                            <?php else: ?>
                                <span class="badge badge-inactive">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($release['is_beta']): ?>
                                <span class="badge badge-beta">BETA</span>
                            <?php endif; ?>
                            <?php if ($release['force_update']): ?>
                                <span class="badge badge-force">FORZADA</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($release['minimum_version'] ?? '-') ?></td>
                        <td><?= number_format($release['file_size'] / 1024 / 1024, 2) ?> MB</td>
                        <td class="release-notes" title="<?= htmlspecialchars($release['release_notes']) ?>">
                            <?= htmlspecialchars(substr($release['release_notes'], 0, 50)) ?>
                            <?= strlen($release['release_notes']) > 50 ? '...' : '' ?>
                        </td>
                        <td>
                            <a href="?edit=<?= $release['id'] ?>" class="btn btn-primary btn-sm">Editar</a>
                            
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= $release['id'] ?>">
                                <button type="submit" class="btn <?= $release['is_active'] ? 'btn-secondary' : 'btn-success' ?> btn-sm">
                                    <?= $release['is_active'] ? 'Desactivar' : 'Activar' ?>
                                </button>
                            </form>
                            
                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este release permanentemente?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $release['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Eliminar</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
