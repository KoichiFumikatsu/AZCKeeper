<?php
/**
 * Administradores — gestión de cuentas del panel (keeper_admin_accounts).
 *
 * Cada admin es un keeper_user con un rol asignado y scope de firma/área.
 * Permisos: admin-users (can_view, can_create, can_edit, can_delete).
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('admin-users');

$pageTitle   = 'Administradores';
$currentPage = 'admin-users';
$msg     = '';
$msgType = '';

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            /* ── Crear cuenta admin ── */
            case 'create':
                if (!canDo('admin-users', 'can_create')) throw new \Exception('Sin permisos');

                $keeperUserId = (int)($_POST['keeper_user_id'] ?? 0);
                $panelRole    = trim($_POST['panel_role'] ?? '');
                $firmScopeId     = ($_POST['firm_scope_id'] ?? '') !== '' ? (int)$_POST['firm_scope_id'] : null;
                $areaScopeId     = ($_POST['area_scope_id'] ?? '') !== '' ? (int)$_POST['area_scope_id'] : null;
                $sedeScopeId     = ($_POST['sede_scope_id'] ?? '') !== '' ? (int)$_POST['sede_scope_id'] : null;
                $sociedadScopeId = ($_POST['sociedad_scope_id'] ?? '') !== '' ? (int)$_POST['sociedad_scope_id'] : null;

                if ($keeperUserId <= 0) throw new \Exception('Selecciona un usuario');
                if ($panelRole === '')  throw new \Exception('Selecciona un rol');

                // Validate role exists
                $roleInfo = getRoleInfo($panelRole);
                if (!$roleInfo) throw new \Exception("Rol '{$panelRole}' no existe");

                // Check not already an admin
                $dup = $pdo->prepare("SELECT COUNT(*) FROM keeper_admin_accounts WHERE keeper_user_id = ?");
                $dup->execute([$keeperUserId]);
                if ($dup->fetchColumn() > 0) throw new \Exception('Este usuario ya tiene cuenta de admin');

                // Hierarchy check — can't create an admin with equal or higher role
                $myRole = getRoleInfo($adminUser['panel_role']);
                $myLevel = $myRole ? (int)$myRole['hierarchy_level'] : 0;
                $targetLevel = (int)$roleInfo['hierarchy_level'];
                if ($targetLevel >= $myLevel && $adminUser['panel_role'] !== 'superadmin') {
                    throw new \Exception('No puedes asignar un rol de igual o mayor jerarquía');
                }

                $st = $pdo->prepare("
                    INSERT INTO keeper_admin_accounts
                    (keeper_user_id, panel_role, firm_scope_id, area_scope_id, sede_scope_id, sociedad_scope_id, is_active, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                ");
                $st->execute([$keeperUserId, $panelRole, $firmScopeId, $areaScopeId, $sedeScopeId, $sociedadScopeId, $adminUser['admin_id']]);

                $msg = 'Cuenta de administrador creada.';
                $msgType = 'success';
                break;

            /* ── Actualizar admin ── */
            case 'update':
                if (!canDo('admin-users', 'can_edit')) throw new \Exception('Sin permisos');

                $id           = (int)($_POST['admin_id'] ?? 0);
                $panelRole    = trim($_POST['panel_role'] ?? '');
                $firmScopeId     = ($_POST['firm_scope_id'] ?? '') !== '' ? (int)$_POST['firm_scope_id'] : null;
                $areaScopeId     = ($_POST['area_scope_id'] ?? '') !== '' ? (int)$_POST['area_scope_id'] : null;
                $sedeScopeId     = ($_POST['sede_scope_id'] ?? '') !== '' ? (int)$_POST['sede_scope_id'] : null;
                $sociedadScopeId = ($_POST['sociedad_scope_id'] ?? '') !== '' ? (int)$_POST['sociedad_scope_id'] : null;
                $isActive     = isset($_POST['is_active']) ? 1 : 0;

                if ($id <= 0) throw new \Exception('Admin inválido');
                if ($panelRole === '') throw new \Exception('Selecciona un rol');

                $roleInfo = getRoleInfo($panelRole);
                if (!$roleInfo) throw new \Exception("Rol '{$panelRole}' no existe");

                // Can't edit own role
                if ($id === (int)$adminUser['admin_id']) {
                    throw new \Exception('No puedes editar tu propia cuenta desde aquí');
                }

                // Hierarchy check
                $myRole = getRoleInfo($adminUser['panel_role']);
                $myLevel = $myRole ? (int)$myRole['hierarchy_level'] : 0;
                $targetLevel = (int)$roleInfo['hierarchy_level'];
                if ($targetLevel >= $myLevel && $adminUser['panel_role'] !== 'superadmin') {
                    throw new \Exception('No puedes asignar un rol de igual o mayor jerarquía');
                }

                $st = $pdo->prepare("
                    UPDATE keeper_admin_accounts SET
                        panel_role = ?, firm_scope_id = ?, area_scope_id = ?, sede_scope_id = ?, sociedad_scope_id = ?, is_active = ?
                    WHERE id = ?
                ");
                $st->execute([$panelRole, $firmScopeId, $areaScopeId, $sedeScopeId, $sociedadScopeId, $isActive, $id]);

                $msg = 'Cuenta actualizada.';
                $msgType = 'success';
                break;

            /* ── Toggle active ── */
            case 'toggle_active':
                if (!canDo('admin-users', 'can_edit')) throw new \Exception('Sin permisos');

                $id = (int)($_POST['admin_id'] ?? 0);
                if ($id <= 0) throw new \Exception('Admin inválido');
                if ($id === (int)$adminUser['admin_id']) throw new \Exception('No puedes desactivarte a ti mismo');

                $pdo->prepare("UPDATE keeper_admin_accounts SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
                $msg = 'Estado actualizado.';
                $msgType = 'success';
                break;

            /* ── Eliminar admin ── */
            case 'delete':
                if (!canDo('admin-users', 'can_delete')) throw new \Exception('Sin permisos');

                $id = (int)($_POST['admin_id'] ?? 0);
                if ($id <= 0) throw new \Exception('Admin inválido');
                if ($id === (int)$adminUser['admin_id']) throw new \Exception('No puedes eliminar tu propia cuenta');

                // Revoke all sessions
                $pdo->prepare("UPDATE keeper_admin_sessions SET revoked_at = NOW() WHERE admin_id = ? AND revoked_at IS NULL")->execute([$id]);
                // Delete account
                $pdo->prepare("DELETE FROM keeper_admin_accounts WHERE id = ?")->execute([$id]);

                $msg = 'Cuenta de administrador eliminada.';
                $msgType = 'success';
                break;

            default:
                throw new \Exception('Acción desconocida');
        }
    } catch (\Exception $e) {
        $msg     = $e->getMessage();
        $msgType = 'error';
    }

    if ($msgType === 'success') {
        header("Location: admin-users.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    }
}

/* Flash PRG */
if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== QUERIES ==================== */

/* All admin accounts */
$admins = $pdo->query("
    SELECT
        a.id AS admin_id,
        a.keeper_user_id,
        a.panel_role,
        a.firm_scope_id,
        a.area_scope_id,
        a.sede_scope_id,
        a.sociedad_scope_id,
        a.is_active,
        a.created_by,
        a.created_at,
        a.last_login_at,
        u.display_name,
        u.email,
        u.cc,
        f.nombre AS firm_name,
        ar.nombre AS area_name,
        s.nombre AS sede_name,
        soc.nombre AS sociedad_name
    FROM keeper_admin_accounts a
    INNER JOIN keeper_users u ON u.id = a.keeper_user_id
    LEFT JOIN keeper_firmas f ON f.id = a.firm_scope_id
    LEFT JOIN keeper_areas ar ON ar.id = a.area_scope_id
    LEFT JOIN keeper_sedes s ON s.id = a.sede_scope_id
    LEFT JOIN keeper_sociedades soc ON soc.id = a.sociedad_scope_id
    ORDER BY a.is_active DESC, a.panel_role ASC, u.display_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

/* Roles for dropdown */
$allRoles = getAllRoles();

/* Firms for dropdown */
$firms = $pdo->query("SELECT id, nombre FROM keeper_firmas WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* Areas for dropdown */
$areas = $pdo->query("SELECT id, nombre FROM keeper_areas WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* Sedes for dropdown */
$sedes = $pdo->query("SELECT id, nombre FROM keeper_sedes WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* Sociedades for dropdown */
$sociedades = $pdo->query("SELECT id, nombre FROM keeper_sociedades WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* Users without admin account (for create dropdown) */
$availableUsers = $pdo->query("
    SELECT u.id, u.display_name, u.email
    FROM keeper_users u
    WHERE u.status = 'active'
      AND u.id NOT IN (SELECT keeper_user_id FROM keeper_admin_accounts)
    ORDER BY u.display_name
")->fetchAll(PDO::FETCH_ASSOC);

/* KPIs */
$totalAdmins  = count($admins);
$activeAdmins = count(array_filter($admins, fn($a) => $a['is_active']));
$roleCounts   = [];
foreach ($admins as $a) {
    $r = $a['panel_role'];
    $roleCounts[$r] = ($roleCounts[$r] ?? 0) + 1;
}

function fmtDate(string $dt): string { return date('d M Y H:i', strtotime($dt)); }

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- ═══════════════════════════════════════════════════════
     Alpine root
     ═══════════════════════════════════════════════════════ -->
<div x-data="adminUsersPage()" x-cloak>

<!-- Flash -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-xl text-sm font-medium flex items-center gap-2
    <?= $msgType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' ?>
    <?= $msgType === 'error'   ? 'bg-red-50 text-accent-500 border border-red-200' : '' ?>"
    x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,5000)" x-transition>
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 sm:mb-6">
    <p class="text-xs sm:text-sm text-muted">Gestiona las cuentas con acceso al panel de administración.</p>
    <div class="flex items-center gap-2 sm:gap-3">
        <div class="relative flex-1 sm:flex-none">
            <svg class="absolute left-3 top-2 sm:top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-model="search" placeholder="Buscar admin..." class="w-full sm:w-48 pl-10 pr-3 py-1.5 sm:py-2 text-sm border border-gray-200 rounded-lg bg-white focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
        </div>
        <?php if (canDo('admin-users', 'can_create')): ?>
        <button @click="openCreate()" class="inline-flex items-center gap-1.5 px-3 py-1.5 sm:px-4 sm:py-2 bg-corp-800 text-white rounded-xl text-xs sm:text-sm font-medium hover:bg-corp-900 transition-colors flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span class="hidden sm:inline">Nuevo Admin</span>
            <span class="sm:hidden">Nuevo</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-blue-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $totalAdmins ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Total Admins</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-emerald-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $activeAdmins ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Activos</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gray-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636m12.728 12.728A9 9 0 015.636 5.636"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $totalAdmins - $activeAdmins ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Inactivos</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-purple-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= count($availableUsers) ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Disponibles</p>
            </div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Admin</th>
                    <th class="px-3 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Rol</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Scope</th>
                    <th class="px-3 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Estado</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Último Login</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Creado</th>
                    <th class="px-3 sm:px-5 py-3 text-right text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($admins as $idx => $a):
                    $role = $allRoles[$a['panel_role']] ?? null;
                    $isSelf = ($a['admin_id'] == $adminUser['admin_id']);
                ?>
                <tr x-show="matchesSearch(<?= $idx ?>)"
                    class="hover:bg-gray-50/50 transition-colors <?= !$a['is_active'] ? 'opacity-50' : '' ?>">
                    <!-- Admin -->
                    <td class="px-3 sm:px-5 py-3.5">
                        <div class="flex items-center gap-2 sm:gap-3">
                            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-corp-50 rounded-full flex items-center justify-center flex-shrink-0">
                                <span class="text-xs sm:text-sm font-bold text-corp-800"><?= strtoupper(substr($a['display_name'] ?? 'U', 0, 1)) ?></span>
                            </div>
                            <div class="min-w-0">
                                <p class="text-xs sm:text-sm font-medium text-dark truncate">
                                    <?= htmlspecialchars($a['display_name'] ?? '') ?>
                                    <?php if ($isSelf): ?>
                                    <span class="text-[10px] text-corp-600 font-normal">(tú)</span>
                                    <?php endif; ?>
                                </p>
                                <p class="text-[10px] sm:text-xs text-muted truncate"><?= htmlspecialchars($a['email'] ?? '') ?></p>
                            </div>
                        </div>
                    </td>
                    <!-- Rol -->
                    <td class="px-3 sm:px-5 py-3.5">
                        <?php if ($role): ?>
                        <span class="inline-flex items-center px-2.5 py-1 text-xs font-semibold rounded-full <?= $role['color_bg'] ?> <?= $role['color_text'] ?>">
                            <?= htmlspecialchars($role['label']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-muted"><?= htmlspecialchars($a['panel_role']) ?></span>
                        <?php endif; ?>
                    </td>
                    <!-- Scope -->
                    <td class="px-5 py-3.5 text-gray-600 text-xs hidden md:table-cell">
                        <?php if (!$a['firm_scope_id'] && !$a['area_scope_id'] && !$a['sede_scope_id'] && !$a['sociedad_scope_id']): ?>
                        <span class="text-emerald-600 font-medium">Global</span>
                        <?php else: ?>
                        <div>
                            <?php if ($a['firm_name']): ?>
                            <p><?= htmlspecialchars($a['firm_name']) ?></p>
                            <?php endif; ?>
                            <?php if ($a['area_name']): ?>
                            <p class="text-muted"><?= htmlspecialchars($a['area_name']) ?></p>
                            <?php endif; ?>
                            <?php if ($a['sede_name']): ?>
                            <p class="text-muted">
                                <svg class="inline w-3 h-3 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                                <?= htmlspecialchars($a['sede_name']) ?>
                            </p>
                            <?php endif; ?>
                            <?php if ($a['sociedad_name'] ?? null): ?>
                            <p class="text-indigo-600">
                                <svg class="inline w-3 h-3 mr-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <?= htmlspecialchars($a['sociedad_name']) ?>
                            </p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <!-- Estado -->
                    <td class="px-3 sm:px-5 py-3.5">
                        <?php if ($a['is_active']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 text-xs font-medium rounded-full">
                            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span> Activo
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-500 text-xs font-medium rounded-full">
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span> Inactivo
                        </span>
                        <?php endif; ?>
                    </td>
                    <!-- Último login -->
                    <td class="px-5 py-3.5 text-gray-600 text-xs hidden lg:table-cell"><?= $a['last_login_at'] ? fmtDate($a['last_login_at']) : '—' ?></td>
                    <!-- Creado -->
                    <td class="px-5 py-3.5 text-gray-600 text-xs hidden lg:table-cell"><?= fmtDate($a['created_at']) ?></td>
                    <!-- Acciones -->
                    <td class="px-3 sm:px-5 py-3.5 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if (canDo('admin-users', 'can_edit') && !$isSelf): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="admin_id" value="<?= $a['admin_id'] ?>">
                                <button type="submit" title="<?= $a['is_active'] ? 'Desactivar' : 'Activar' ?>"
                                        class="p-1.5 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors">
                                    <?php if ($a['is_active']): ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/></svg>
                                    <?php else: ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <button @click="openEdit(<?= $idx ?>)" title="Editar"
                                    class="p-1.5 text-gray-400 hover:text-corp-800 hover:bg-corp-50 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if (canDo('admin-users', 'can_delete') && !$isSelf): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar esta cuenta de admin? Se revocarán todas sus sesiones.')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= $a['admin_id'] ?>">
                                <button type="submit" title="Eliminar"
                                        class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if (empty($admins)): ?>
    <div class="text-center py-16">
        <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
        <h3 class="mt-3 text-sm font-medium text-dark">Sin administradores</h3>
        <p class="mt-1 text-sm text-muted">Crea la primera cuenta de admin.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Crear / Editar Admin
     ═══════════════════════════════════════════════════════ -->
<div x-show="showModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="showModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.stop>
        <!-- Header -->
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-dark" x-text="editMode ? 'Editar Administrador' : 'Nuevo Administrador'"></h3>
                <button @click="showModal=false" class="p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <form method="post" class="p-6 space-y-5">
            <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
            <input type="hidden" name="admin_id" :value="form.admin_id">

            <!-- User select (only on create) -->
            <div x-show="!editMode">
                <label class="block text-sm font-medium text-dark mb-1">Usuario <span class="text-red-500">*</span></label>
                <select name="keeper_user_id" x-model="form.keeper_user_id"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Seleccionar usuario —</option>
                    <?php foreach ($availableUsers as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['display_name']) ?> (<?= htmlspecialchars($u['email']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-muted">Solo se muestran usuarios activos sin cuenta de admin.</p>
            </div>

            <!-- Display name (readonly, only on edit) -->
            <div x-show="editMode">
                <label class="block text-sm font-medium text-dark mb-1">Usuario</label>
                <p class="px-3 py-2 bg-gray-50 rounded-lg text-sm text-dark" x-text="form.display_name"></p>
            </div>

            <!-- Role -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Rol del Panel <span class="text-red-500">*</span></label>
                <select name="panel_role" x-model="form.panel_role"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Seleccionar rol —</option>
                    <?php foreach ($allRoles as $slug => $r): ?>
                    <option value="<?= htmlspecialchars($slug) ?>"><?= htmlspecialchars($r['label']) ?> — <?= htmlspecialchars($r['description'] ?? '') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Scope -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Scope Firma</label>
                    <select name="firm_scope_id" x-model="form.firm_scope_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                        <option value="">Global (todas)</option>
                        <?php foreach ($firms as $f): ?>
                        <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Scope Área</label>
                    <select name="area_scope_id" x-model="form.area_scope_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                        <option value="">Todas las áreas</option>
                        <?php foreach ($areas as $ar): ?>
                        <option value="<?= $ar['id'] ?>"><?= htmlspecialchars($ar['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Scope Sede</label>
                    <select name="sede_scope_id" x-model="form.sede_scope_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                        <option value="">Todas las sedes</option>
                        <?php foreach ($sedes as $sd): ?>
                        <option value="<?= $sd['id'] ?>"><?= htmlspecialchars($sd['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Scope Sociedad</label>
                    <select name="sociedad_scope_id" x-model="form.sociedad_scope_id"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                        <option value="">Todas las sociedades</option>
                        <?php foreach ($sociedades as $soc): ?>
                        <option value="<?= $soc['id'] ?>"><?= htmlspecialchars($soc['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Active (only on edit) -->
            <div x-show="editMode">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" x-model="form.is_active" class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <div>
                        <span class="text-sm font-medium text-dark">Cuenta activa</span>
                        <p class="text-xs text-muted">Desactivar impide el login pero conserva la cuenta.</p>
                    </div>
                </label>
            </div>

            <!-- Submit -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="button" @click="showModal=false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors"
                        x-text="editMode ? 'Guardar Cambios' : 'Crear Admin'"></button>
            </div>
        </form>
    </div>
</div>

</div><!-- /x-data -->

<!-- Alpine.js logic -->
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('adminUsersPage', () => ({
        showModal: false,
        editMode: false,
        search: '',

        form: {
            admin_id: '',
            keeper_user_id: '',
            panel_role: '',
            firm_scope_id: '',
            area_scope_id: '',
            sede_scope_id: '',
            sociedad_scope_id: '',
            is_active: true,
            display_name: '',
        },

        admins: <?= json_encode(array_values($admins), JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        resetForm() {
            this.form = {
                admin_id: '',
                keeper_user_id: '',
                panel_role: '',
                firm_scope_id: '',
                area_scope_id: '',
                sede_scope_id: '',
                sociedad_scope_id: '',
                is_active: true,
                display_name: '',
            };
        },

        openCreate() {
            this.resetForm();
            this.editMode = false;
            this.showModal = true;
        },

        openEdit(idx) {
            const a = this.admins[idx];
            if (!a) return;
            this.editMode = true;
            this.form = {
                admin_id: a.admin_id,
                keeper_user_id: a.keeper_user_id,
                panel_role: a.panel_role,
                firm_scope_id: a.firm_scope_id || '',
                area_scope_id: a.area_scope_id || '',
                sede_scope_id: a.sede_scope_id || '',
                sociedad_scope_id: a.sociedad_scope_id || '',
                is_active: !!parseInt(a.is_active),
                display_name: a.display_name + ' (' + a.email + ')',
            };
            this.showModal = true;
        },

        matchesSearch(idx) {
            if (!this.search) return true;
            const a = this.admins[idx];
            if (!a) return false;
            const q = this.search.toLowerCase();
            const haystack = ((a.display_name || '') + ' ' + (a.email || '') + ' ' + (a.panel_role || '')).toLowerCase();
            return haystack.includes(q);
        },
    }));
});
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
