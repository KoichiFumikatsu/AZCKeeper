<?php
/**
 * roles.php — Gestión de Roles del Panel
 *
 * Permite crear, editar y eliminar roles del panel.
 * Cada rol tiene: slug, label, nivel jerárquico, color y
 * permisos granulares por módulo (can_view, can_edit, can_delete, etc.).
 *
 * Solo accesible por quienes tengan el módulo 'roles' habilitado.
 */
require __DIR__ . '/admin_auth.php';
requireModule('roles');

$pageTitle   = 'Roles del Panel';
$currentPage = 'roles';

/* ───── Definición de permisos por módulo ───── */
$modulePermissions = [
    'users' => [
        'label' => 'Usuarios',
        'actions' => [
            'can_view'         => 'Ver listado',
            'can_create'       => 'Crear usuario',
            'can_edit'         => 'Editar usuario',
            'can_delete'       => 'Eliminar usuario',
            'can_export'       => 'Exportar CSV',
            'can_toggle_admin' => 'Toggle acceso admin',
        ],
    ],
    'devices' => [
        'label' => 'Dispositivos',
        'actions' => [
            'can_view'   => 'Ver listado',
            'can_edit'   => 'Editar dispositivo',
            'can_delete' => 'Eliminar dispositivo',
        ],
    ],
    'policies' => [
        'label' => 'Políticas',
        'actions' => [
            'can_view'       => 'Ver listado',
            'can_create'     => 'Crear política',
            'can_edit'       => 'Editar política',
            'can_delete'     => 'Eliminar política',
            'can_force_push' => 'Forzar push',
        ],
    ],
    'releases' => [
        'label' => 'Releases',
        'actions' => [
            'can_view'   => 'Ver listado',
            'can_create' => 'Crear release',
            'can_edit'   => 'Editar release',
            'can_delete' => 'Eliminar release',
        ],
    ],
    'admin-users' => [
        'label' => 'Administradores',
        'actions' => [
            'can_view'   => 'Ver listado',
            'can_create' => 'Crear admin',
            'can_edit'   => 'Editar admin',
            'can_delete' => 'Eliminar admin',
        ],
    ],
    'assignments' => [
        'label' => 'Asignaciones',
        'actions' => [
            'can_view' => 'Ver listado',
            'can_edit' => 'Editar asignaciones',
        ],
    ],
    'organization' => [
        'label' => 'Organización',
        'actions' => [
            'can_view'   => 'Ver entidades',
            'can_edit'   => 'Crear/Editar entidades',
            'can_delete' => 'Eliminar entidades',
        ],
    ],
    'roles' => [
        'label' => 'Roles',
        'actions' => [
            'can_view'   => 'Ver listado',
            'can_create' => 'Crear rol',
            'can_edit'   => 'Editar rol',
            'can_delete' => 'Eliminar rol',
        ],
    ],
    'settings' => [
        'label' => 'Configuración',
        'actions' => [
            'can_view' => 'Ver configuración',
            'can_edit' => 'Editar configuración',
        ],
    ],
    'server-health' => [
        'label' => 'Salud del Servidor',
        'actions' => [
            'can_view' => 'Ver salud del servidor',
        ],
    ],
    'productivity' => [
        'label' => 'Productividad',
        'actions' => [
            'can_view'   => 'Ver métricas y ranking',
            'can_export' => 'Exportar datos',
        ],
    ],
    'dual_job' => [
        'label' => 'Alertas de Actividades',
        'actions' => [
            'can_view'   => 'Ver alertas',
            'can_review' => 'Clasificar alertas',
        ],
    ],
    'pending_users' => [
        'label' => 'Accesos Pendientes',
        'actions' => [
            'can_view' => 'Ver solicitudes',
            'can_edit' => 'Aprobar / Rechazar',
        ],
    ],
];

/* ───── POST actions ───── */
$flash = '';
$flashType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ─── Crear rol ───
    if ($action === 'create') {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(trim($_POST['slug'] ?? '')));
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $hierarchy = max(1, min(99, (int)($_POST['hierarchy_level'] ?? 1)));
        $colorBg = trim($_POST['color_bg'] ?? 'bg-gray-100');
        $colorText = trim($_POST['color_text'] ?? 'text-gray-700');

        if (!$slug || !$label) {
            $flash = 'Slug y nombre son obligatorios.';
            $flashType = 'error';
        } elseif (in_array($slug, ['superadmin'])) {
            $flash = 'No puedes crear un rol con el slug "superadmin".';
            $flashType = 'error';
        } else {
            // Parsear permisos del form
            $permissions = [];
            foreach (array_keys($modulePermissions) as $mod) {
                $modKey = str_replace('-', '_', $mod);
                $permissions[$mod] = [];
                foreach (array_keys($modulePermissions[$mod]['actions']) as $act) {
                    $permissions[$mod][$act] = !empty($_POST["perm_{$modKey}_{$act}"]);
                }
            }

            try {
                $st = $pdo->prepare("
                    INSERT INTO keeper_panel_roles (slug, label, description, hierarchy_level, color_bg, color_text, is_system, permissions)
                    VALUES (:slug, :label, :desc, :hier, :cbg, :ctx, 0, :perms)
                ");
                $st->execute([
                    ':slug'  => $slug,
                    ':label' => $label,
                    ':desc'  => $description,
                    ':hier'  => $hierarchy,
                    ':cbg'   => $colorBg,
                    ':ctx'   => $colorText,
                    ':perms' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
                ]);

                // Agregar el nuevo rol a menu_visibility para los módulos donde tiene can_view
                $vis = getMenuVisibility();
                foreach ($permissions as $mod => $acts) {
                    if (!empty($acts['can_view']) && !in_array($slug, $vis[$mod] ?? [])) {
                        $vis[$mod][] = $slug;
                    }
                }
                $pdo->prepare("
                    INSERT INTO keeper_panel_settings (setting_key, setting_value, updated_by)
                    VALUES ('menu_visibility', :val, :uid)
                    ON DUPLICATE KEY UPDATE setting_value = :val2, updated_by = :uid2, updated_at = NOW()
                ")->execute([
                    ':val' => json_encode($vis, JSON_UNESCAPED_UNICODE),
                    ':uid' => $adminUser['id'],
                    ':val2' => json_encode($vis, JSON_UNESCAPED_UNICODE),
                    ':uid2' => $adminUser['id'],
                ]);

                header('Location: roles.php?msg=created');
                exit;
            } catch (\PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate entry')) {
                    $flash = "Ya existe un rol con el slug \"{$slug}\".";
                } else {
                    $flash = 'Error al crear: ' . $e->getMessage();
                }
                $flashType = 'error';
            }
        }
    }

    // ─── Editar rol ───
    if ($action === 'edit') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $hierarchy = max(1, min(99, (int)($_POST['hierarchy_level'] ?? 1)));
        $colorBg = trim($_POST['color_bg'] ?? 'bg-gray-100');
        $colorText = trim($_POST['color_text'] ?? 'text-gray-700');

        // No permitir editar superadmin hierarchy
        $existing = $pdo->prepare("SELECT * FROM keeper_panel_roles WHERE id = :id");
        $existing->execute([':id' => $roleId]);
        $existingRole = $existing->fetch(\PDO::FETCH_ASSOC);

        if (!$existingRole) {
            $flash = 'Rol no encontrado.';
            $flashType = 'error';
        } else {
            if ($existingRole['slug'] === 'superadmin') {
                $hierarchy = 100; // Forzar
            }

            $permissions = [];
            foreach (array_keys($modulePermissions) as $mod) {
                $modKey = str_replace('-', '_', $mod);
                $permissions[$mod] = [];
                foreach (array_keys($modulePermissions[$mod]['actions']) as $act) {
                    if ($existingRole['slug'] === 'superadmin') {
                        $permissions[$mod][$act] = true; // Superadmin siempre todo true
                    } else {
                        $permissions[$mod][$act] = !empty($_POST["perm_{$modKey}_{$act}"]);
                    }
                }
            }

            $st = $pdo->prepare("
                UPDATE keeper_panel_roles
                SET label = :label, description = :desc, hierarchy_level = :hier,
                    color_bg = :cbg, color_text = :ctx, permissions = :perms
                WHERE id = :id
            ");
            $st->execute([
                ':label' => $label,
                ':desc'  => $description,
                ':hier'  => $hierarchy,
                ':cbg'   => $colorBg,
                ':ctx'   => $colorText,
                ':perms' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
                ':id'    => $roleId,
            ]);

            header('Location: roles.php?msg=edited');
            exit;
        }
    }

    // ─── Eliminar rol ───
    if ($action === 'delete') {
        $roleId = (int)($_POST['role_id'] ?? 0);
        $existing = $pdo->prepare("SELECT * FROM keeper_panel_roles WHERE id = :id");
        $existing->execute([':id' => $roleId]);
        $existingRole = $existing->fetch(\PDO::FETCH_ASSOC);

        if (!$existingRole) {
            $flash = 'Rol no encontrado.';
            $flashType = 'error';
        } elseif ($existingRole['is_system']) {
            $flash = 'No se pueden eliminar roles del sistema.';
            $flashType = 'error';
        } else {
            // Check if any admin is using this role
            $usage = $pdo->prepare("SELECT COUNT(*) FROM keeper_admin_accounts WHERE panel_role = :slug");
            $usage->execute([':slug' => $existingRole['slug']]);
            $count = (int)$usage->fetchColumn();

            if ($count > 0) {
                $flash = "No se puede eliminar: hay {$count} administrador(es) usando este rol.";
                $flashType = 'error';
            } else {
                $pdo->prepare("DELETE FROM keeper_panel_roles WHERE id = :id")->execute([':id' => $roleId]);

                // Limpiar de menu_visibility
                $vis = getMenuVisibility();
                foreach ($vis as $mod => &$roleSlugs) {
                    $roleSlugs = array_values(array_filter($roleSlugs, fn($s) => $s !== $existingRole['slug']));
                }
                unset($roleSlugs);
                $pdo->prepare("
                    INSERT INTO keeper_panel_settings (setting_key, setting_value, updated_by)
                    VALUES ('menu_visibility', :val, :uid)
                    ON DUPLICATE KEY UPDATE setting_value = :val2, updated_by = :uid2, updated_at = NOW()
                ")->execute([
                    ':val' => json_encode($vis, JSON_UNESCAPED_UNICODE),
                    ':uid' => $adminUser['id'],
                    ':val2' => json_encode($vis, JSON_UNESCAPED_UNICODE),
                    ':uid2' => $adminUser['id'],
                ]);

                header('Location: roles.php?msg=deleted');
                exit;
            }
        }
    }
}

/* ───── Leer roles desde DB ───── */
$allRoles = getAllRoles();

/* Contar admins por rol */
$adminCounts = [];
try {
    $st = $pdo->query("SELECT panel_role, COUNT(*) as cnt FROM keeper_admin_accounts WHERE is_active = 1 GROUP BY panel_role");
    while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
        $adminCounts[$row['panel_role']] = (int)$row['cnt'];
    }
} catch (\Throwable $e) {}

/* Flash por PRG */
$msgs = [
    'created' => ['Rol creado correctamente.', 'success'],
    'edited'  => ['Rol actualizado correctamente.', 'success'],
    'deleted' => ['Rol eliminado correctamente.', 'success'],
];
if (isset($_GET['msg']) && isset($msgs[$_GET['msg']])) {
    $flash = $msgs[$_GET['msg']][0];
    $flashType = $msgs[$_GET['msg']][1];
}

/* Colores predefinidos para el selector */
$colorPresets = [
    ['bg' => 'bg-red-100',    'text' => 'text-red-800',    'label' => 'Rojo'],
    ['bg' => 'bg-blue-100',   'text' => 'text-blue-800',   'label' => 'Azul'],
    ['bg' => 'bg-green-100',  'text' => 'text-green-800',  'label' => 'Verde'],
    ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'label' => 'Amarillo'],
    ['bg' => 'bg-purple-100', 'text' => 'text-purple-800', 'label' => 'Morado'],
    ['bg' => 'bg-pink-100',   'text' => 'text-pink-800',   'label' => 'Rosa'],
    ['bg' => 'bg-orange-100', 'text' => 'text-orange-800', 'label' => 'Naranja'],
    ['bg' => 'bg-teal-100',   'text' => 'text-teal-800',   'label' => 'Teal'],
    ['bg' => 'bg-indigo-100', 'text' => 'text-indigo-800', 'label' => 'Índigo'],
    ['bg' => 'bg-gray-100',   'text' => 'text-gray-600',   'label' => 'Gris'],
];

require __DIR__ . '/partials/layout_header.php';
?>

<?php if ($flash): ?>
<div class="mb-6 <?= $flashType === 'error' ? 'bg-red-50 border-red-200 text-red-800' : 'bg-green-50 border-green-200 text-green-800' ?> border px-4 py-3 rounded-lg flex items-center gap-2" x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false, 5000)" x-transition>
    <?php if ($flashType === 'error'): ?>
    <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php else: ?>
    <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    <?php endif; ?>
    <span class="text-sm font-medium"><?= htmlspecialchars($flash) ?></span>
</div>
<?php endif; ?>

<!-- Header -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6 sm:mb-8" x-data="{showCreate: false}">
    <div class="flex items-center gap-3">
        <div class="w-9 h-9 sm:w-10 sm:h-10 bg-corp-100 rounded-lg flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
        </div>
        <div>
            <h2 class="text-lg sm:text-xl font-bold text-dark">Roles del Panel</h2>
            <p class="text-xs sm:text-sm text-muted">Define roles y permisos granulares para los administradores.</p>
        </div>
    </div>
    <button @click="showCreate = true"
            class="px-4 py-2.5 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900 transition-colors flex items-center gap-2 self-start sm:self-auto">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        <span class="hidden sm:inline">Nuevo Rol</span><span class="sm:hidden">Nuevo</span>
    </button>

    <!-- ═══════ Modal: Crear Rol ═══════ -->
    <div x-show="showCreate" x-transition.opacity class="fixed inset-0 bg-black/50 z-50 flex items-start justify-center pt-10 overflow-y-auto" @keydown.escape.window="showCreate = false" style="display:none">
        <div @click.outside="showCreate = false" class="bg-white rounded-xl shadow-2xl w-full max-w-3xl mx-4 my-8">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-dark">Crear nuevo rol</h3>
                    <button type="button" @click="showCreate = false" class="text-muted hover:text-dark"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg></button>
                </div>
                <div class="px-6 py-5 space-y-5 max-h-[70vh] overflow-y-auto">
                    <!-- Básicos -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Slug (identificador) *</label>
                            <input type="text" name="slug" required pattern="[a-z0-9\-]+" placeholder="ej: auditor" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
                            <p class="text-[10px] text-muted mt-1">Solo minúsculas, números y guiones.</p>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nombre visible *</label>
                            <input type="text" name="label" required placeholder="ej: Auditor" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
                        </div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Descripción</label>
                        <input type="text" name="description" placeholder="Describe qué puede hacer este rol..." class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-600 mb-1">Nivel jerárquico (1-99)</label>
                            <input type="number" name="hierarchy_level" min="1" max="99" value="25" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
                            <p class="text-[10px] text-muted mt-1">Mayor = más poder. Superadmin=100, Admin=50, Viewer=10.</p>
                        </div>
                        <div x-data="{selectedBg: 'bg-gray-100', selectedText: 'text-gray-700'}">
                            <label class="block text-xs font-medium text-gray-600 mb-1">Color del badge</label>
                            <div class="flex flex-wrap gap-2 mt-1">
                                <?php foreach ($colorPresets as $cp): ?>
                                <button type="button"
                                        @click="selectedBg = '<?= $cp['bg'] ?>'; selectedText = '<?= $cp['text'] ?>'"
                                        class="w-7 h-7 rounded-full <?= $cp['bg'] ?> border-2 transition-all"
                                        :class="selectedBg === '<?= $cp['bg'] ?>' ? 'border-corp-600 scale-110' : 'border-transparent'"
                                        title="<?= $cp['label'] ?>">
                                </button>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="color_bg" :value="selectedBg">
                            <input type="hidden" name="color_text" :value="selectedText">
                            <div class="mt-2">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="selectedBg + ' ' + selectedText">Preview</span>
                            </div>
                        </div>
                    </div>

                    <!-- Permisos granulares -->
                    <div>
                        <h4 class="text-sm font-semibold text-dark mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-corp-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            Permisos por módulo
                        </h4>
                        <div class="space-y-3">
                            <?php foreach ($modulePermissions as $mod => $modDef): $modKey = str_replace('-', '_', $mod); ?>
                            <div class="bg-gray-50 rounded-lg px-4 py-3">
                                <p class="text-xs font-semibold text-dark mb-2"><?= htmlspecialchars($modDef['label']) ?></p>
                                <div class="flex flex-wrap gap-x-2 sm:gap-x-5 gap-y-1.5">
                                    <?php foreach ($modDef['actions'] as $act => $actLabel): ?>
                                    <label class="flex items-center gap-1.5 cursor-pointer">
                                        <input type="checkbox" name="perm_<?= $modKey ?>_<?= $act ?>" value="1" class="w-3.5 h-3.5 rounded border-gray-300 text-corp-600 focus:ring-corp-200">
                                        <span class="text-[11px] sm:text-xs text-gray-600"><?= htmlspecialchars($actLabel) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="px-6 py-4 border-t border-gray-200 flex justify-end gap-3">
                    <button type="button" @click="showCreate = false" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Crear Rol
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Info card -->
<div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-6 flex items-start gap-3">
    <svg class="w-5 h-5 text-blue-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <div class="text-sm text-blue-800">
        <p><strong>Superadmin</strong> siempre tiene acceso total (no editable). Los roles del sistema no pueden eliminarse.
        Usa <a href="panel-settings.php" class="underline font-medium">Configuración</a> para controlar qué roles ven cada sección del menú.</p>
    </div>
</div>

<!-- KPI cards -->
<div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-200 px-3 sm:px-5 py-3 sm:py-4">
        <p class="text-[10px] sm:text-xs text-muted font-medium uppercase tracking-wide">Total Roles</p>
        <p class="text-xl sm:text-2xl font-bold text-dark mt-1"><?= count($allRoles) ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 px-3 sm:px-5 py-3 sm:py-4">
        <p class="text-[10px] sm:text-xs text-muted font-medium uppercase tracking-wide">Del Sistema</p>
        <p class="text-xl sm:text-2xl font-bold text-dark mt-1"><?= count(array_filter($allRoles, fn($r) => $r['is_system'])) ?></p>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 px-3 sm:px-5 py-3 sm:py-4">
        <p class="text-[10px] sm:text-xs text-muted font-medium uppercase tracking-wide">Custom</p>
        <p class="text-xl sm:text-2xl font-bold text-corp-800 mt-1"><?= count(array_filter($allRoles, fn($r) => !$r['is_system'])) ?></p>
    </div>
</div>

<!-- Roles list -->
<div class="space-y-4" x-data="{editRole: null}">
    <?php foreach ($allRoles as $slug => $role): ?>
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <div class="px-4 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3 sm:gap-4 min-w-0">
                <span class="inline-flex items-center px-2.5 sm:px-3 py-1 rounded-full text-xs font-semibold flex-shrink-0 <?= htmlspecialchars(($role['color_bg'] ?? 'bg-gray-100') . ' ' . ($role['color_text'] ?? 'text-gray-600')) ?>">
                    <?= htmlspecialchars($role['label']) ?>
                </span>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-1.5 sm:gap-2">
                        <code class="text-xs bg-gray-100 px-1.5 py-0.5 rounded text-gray-500"><?= htmlspecialchars($slug) ?></code>
                        <?php if ($role['is_system']): ?>
                        <span class="text-[10px] bg-amber-50 text-amber-700 px-1.5 py-0.5 rounded font-medium">SISTEMA</span>
                        <?php endif; ?>
                        <span class="text-[10px] text-muted">Nivel <?= (int)$role['hierarchy_level'] ?></span>
                    </div>
                    <?php if ($role['description']): ?>
                    <p class="text-xs text-muted mt-0.5"><?= htmlspecialchars($role['description']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-muted">
                    <?= $adminCounts[$slug] ?? 0 ?> admin<?= ($adminCounts[$slug] ?? 0) !== 1 ? 's' : '' ?>
                </span>
                <button @click="editRole = editRole === '<?= $slug ?>' ? null : '<?= $slug ?>'"
                        class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs text-gray-600 hover:bg-gray-50 transition-colors flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                    Editar
                </button>
                <?php if (!$role['is_system']): ?>
                <form method="POST" x-data="{confirming:false}" class="inline">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
                    <button type="button" @click="confirming=true" x-show="!confirming"
                            class="px-3 py-1.5 border border-red-200 rounded-lg text-xs text-red-600 hover:bg-red-50 transition-colors">
                        Eliminar
                    </button>
                    <div x-show="confirming" x-transition class="inline-flex items-center gap-1.5">
                        <button type="submit" class="px-2.5 py-1.5 bg-red-600 text-white rounded-md text-xs font-medium">Sí</button>
                        <button type="button" @click="confirming=false" class="px-2.5 py-1.5 border border-gray-200 rounded-md text-xs">No</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Panel de edición expandible -->
        <div x-show="editRole === '<?= $slug ?>'" x-transition x-cloak class="border-t border-gray-100">
            <form method="POST" class="px-6 py-5 space-y-5">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nombre visible</label>
                        <input type="text" name="label" value="<?= htmlspecialchars($role['label']) ?>" required class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Descripción</label>
                        <input type="text" name="description" value="<?= htmlspecialchars($role['description'] ?? '') ?>" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-gray-600 mb-1">Nivel jerárquico</label>
                        <input type="number" name="hierarchy_level" min="1" max="<?= $slug === 'superadmin' ? '100' : '99' ?>" value="<?= (int)$role['hierarchy_level'] ?>"
                               <?= $slug === 'superadmin' ? 'readonly' : '' ?>
                               class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 <?= $slug === 'superadmin' ? 'bg-gray-50' : '' ?>">
                    </div>
                </div>

                <!-- Color picker -->
                <div x-data="{selBg: '<?= htmlspecialchars($role['color_bg'] ?? 'bg-gray-100') ?>', selText: '<?= htmlspecialchars($role['color_text'] ?? 'text-gray-600') ?>'}">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Color del badge</label>
                    <div class="flex items-center gap-3">
                        <div class="flex gap-1.5">
                            <?php foreach ($colorPresets as $cp): ?>
                            <button type="button"
                                    @click="selBg='<?= $cp['bg'] ?>'; selText='<?= $cp['text'] ?>'"
                                    class="w-6 h-6 rounded-full <?= $cp['bg'] ?> border-2 transition-all"
                                    :class="selBg === '<?= $cp['bg'] ?>' ? 'border-corp-600 scale-110' : 'border-transparent'">
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="selBg + ' ' + selText"><?= htmlspecialchars($role['label']) ?></span>
                    </div>
                    <input type="hidden" name="color_bg" :value="selBg">
                    <input type="hidden" name="color_text" :value="selText">
                </div>

                <!-- Permisos -->
                <div>
                    <h4 class="text-xs font-semibold text-dark mb-3 uppercase tracking-wide">Permisos granulares</h4>
                    <?php $perms = $role['permissions'] ?? []; ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <?php foreach ($modulePermissions as $mod => $modDef):
                            $modKey = str_replace('-', '_', $mod);
                            $modPerms = $perms[$mod] ?? [];
                        ?>
                        <div class="bg-gray-50 rounded-lg px-4 py-3">
                            <p class="text-xs font-semibold text-dark mb-2"><?= htmlspecialchars($modDef['label']) ?></p>
                            <div class="flex flex-wrap gap-x-2 sm:gap-x-4 gap-y-1.5">
                                <?php foreach ($modDef['actions'] as $act => $actLabel):
                                    $checked = !empty($modPerms[$act]);
                                    $disabled = ($slug === 'superadmin');
                                ?>
                                <label class="flex items-center gap-1.5 <?= $disabled ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' ?>">
                                    <input type="checkbox" name="perm_<?= $modKey ?>_<?= $act ?>" value="1"
                                           <?= $checked ? 'checked' : '' ?>
                                           <?= $disabled ? 'disabled' : '' ?>
                                           class="w-3.5 h-3.5 rounded border-gray-300 text-corp-600 focus:ring-corp-200">
                                    <span class="text-[11px] sm:text-xs text-gray-600"><?= htmlspecialchars($actLabel) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="editRole = null" class="px-4 py-2 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" class="px-5 py-2 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Guardar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
