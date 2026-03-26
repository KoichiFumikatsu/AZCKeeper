<?php
/**
 * panel-settings.php — Configuración del Panel
 * 
 * Solo superadmin. Permite configurar qué módulos del sidebar
 * son visibles para cada rol (admin, viewer) desde la UI.
 */
require __DIR__ . '/admin_auth.php';
requireModule('settings');

$pageTitle   = 'Configuración del Panel';
$currentPage = 'settings';

/* ───── Módulos disponibles con labels ───── */
$allModules = [
    'dashboard'        => ['label' => 'Dashboard',       'icon' => 'home',     'description' => 'Página principal con métricas'],
    'sedes-dashboard'  => ['label' => 'Sedes',           'icon' => 'building', 'description' => 'KPIs y métricas agrupadas por sede'],
    'users'            => ['label' => 'Usuarios',        'icon' => 'users',    'description' => 'Gestión de usuarios rastreados'],
    'devices'     => ['label' => 'Dispositivos',     'icon' => 'monitor',  'description' => 'Gestión de equipos registrados'],
    'productivity'=> ['label' => 'Productividad',    'icon' => 'shield',   'description' => 'Focus Score, ranking y métricas de productividad'],
    'policies'    => ['label' => 'Políticas',        'icon' => 'shield',   'description' => 'Config. de políticas de bloqueo'],
    'releases'    => ['label' => 'Releases',         'icon' => 'download', 'description' => 'Gestión de versiones del cliente'],
    'admin-users' => ['label' => 'Administradores',  'icon' => 'lock',     'description' => 'Cuentas de administración del panel'],
    'assignments'   => ['label' => 'Asignaciones',     'icon' => 'building', 'description' => 'Mapeo firma → área → cargo → usuario'],
    'organization' => ['label' => 'Organización',      'icon' => 'grid',     'description' => 'CRUD de firmas, áreas, cargos y sedes'],
    'roles'        => ['label' => 'Roles',             'icon' => 'users-cog','description' => 'Gestión de roles y permisos del panel'],
    'settings'    => ['label' => 'Configuración',    'icon' => 'sliders',  'description' => 'Esta misma página de ajustes'],
    'server-health'=> ['label' => 'Salud del Servidor', 'icon' => 'activity', 'description' => 'Recursos del servidor, queries y estado de la API'],
    'dual_job'    => ['label' => 'Alertas de Actividades', 'icon' => 'alert-triangle', 'description' => 'Detección y clasificación de actividades'],
];

/* Cargar roles dinámicamente desde keeper_panel_roles */
$allRolesData = getAllRoles();
$roles = array_keys($allRolesData);
$roleLabels = [];
$roleColors = [];
foreach ($allRolesData as $slug => $r) {
    $roleLabels[$slug] = $r['label'];
    $roleColors[$slug] = ($r['color_bg'] ?? 'bg-gray-100') . ' ' . ($r['color_text'] ?? 'text-gray-600');
}

/* ───── POST: guardar visibilidad ───── */
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_visibility'])) {
    $newVisibility = [];
    foreach (array_keys($allModules) as $mod) {
        $selected = $_POST['mod_' . str_replace('-', '_', $mod)] ?? [];
        // Superadmin siempre incluido (safety)
        if (!in_array('superadmin', $selected)) {
            array_unshift($selected, 'superadmin');
        }
        $newVisibility[$mod] = array_values($selected);
    }

    $json = json_encode($newVisibility, JSON_UNESCAPED_UNICODE);
    $st = $pdo->prepare("
        INSERT INTO keeper_panel_settings (setting_key, setting_value, updated_by)
        VALUES ('menu_visibility', :val, :uid)
        ON DUPLICATE KEY UPDATE setting_value = :val2, updated_by = :uid2, updated_at = NOW()
    ");
    $st->execute([
        ':val'  => $json,
        ':uid'  => $adminUser['id'],
        ':val2' => $json,
        ':uid2' => $adminUser['id'],
    ]);

    // Invalidar cache en memoria (para este mismo request de redirect)
    header('Location: panel-settings.php?saved=1');
    exit;
}

/* ───── Leer la configuración actual ───── */
$visibility = getMenuVisibility();

/* Flash message por PRG */
$showSaved = isset($_GET['saved']);

/* ───── Leer info de última modificación ───── */
$lastUpdate = null;
try {
    $st = $pdo->prepare("
        SELECT ps.updated_at, ps.updated_by, aa.display_name
        FROM keeper_panel_settings ps
        LEFT JOIN keeper_admin_accounts aa ON aa.id = ps.updated_by
        WHERE ps.setting_key = 'menu_visibility'
        LIMIT 1
    ");
    $st->execute();
    $lastUpdate = $st->fetch(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

require __DIR__ . '/partials/layout_header.php';
?>

<?php if ($showSaved): ?>
<div class="mb-6 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-lg flex items-center gap-2" x-data="{show: true}" x-show="show" x-init="setTimeout(() => show = false, 4000)" x-transition>
    <svg class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    <span class="text-sm font-medium">Configuración de visibilidad guardada correctamente.</span>
</div>
<?php endif; ?>

<!-- Encabezado -->
<div class="mb-6 sm:mb-8">
    <div class="flex items-center gap-3 mb-2">
        <div class="w-9 h-9 sm:w-10 sm:h-10 bg-corp-100 rounded-lg flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
        </div>
        <div>
            <h2 class="text-lg sm:text-xl font-bold text-dark">Configuración del Panel</h2>
            <p class="text-xs sm:text-sm text-muted">Controla qué secciones del menú son visibles para cada rol de administrador.</p>
        </div>
    </div>
    <?php if ($lastUpdate && $lastUpdate['updated_at']): ?>
    <div class="text-xs text-muted mt-2">
        Última modificación: <?= date('d/m/Y H:i', strtotime($lastUpdate['updated_at'])) ?>
        <?php if ($lastUpdate['display_name']): ?>
            por <span class="font-medium text-dark"><?= htmlspecialchars($lastUpdate['display_name']) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Info card -->
<div class="bg-blue-50 border border-blue-200 rounded-lg px-4 py-3 mb-6 flex items-start gap-3">
    <svg class="w-5 h-5 text-blue-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <div class="text-sm text-blue-800">
        <p class="font-medium mb-1">¿Cómo funciona?</p>
        <p>Cada módulo del sidebar puede ser visible para uno o más roles. <strong>Superadmin</strong> siempre tiene acceso a todo (no se puede desactivar). Los cambios aplican inmediatamente al guardar.</p>
    </div>
</div>

<!-- Form -->
<form method="POST" x-data="settingsForm()" @submit.prevent="submitForm($event)">
    <input type="hidden" name="save_visibility" value="1">

    <!-- Matrix Card -->
    <div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
        <!-- Table header -->
        <div class="bg-gray-50 border-b border-gray-200 px-4 sm:px-6 py-3 sm:py-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-dark">Visibilidad de Módulos por Rol</h3>
                <div class="hidden sm:flex items-center gap-4">
                    <?php foreach ($roles as $role): ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium <?= $roleColors[$role] ?>">
                        <?= $roleLabels[$role] ?>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Module rows -->
        <div class="divide-y divide-gray-100">
            <?php foreach ($allModules as $modSlug => $modInfo): ?>
            <div class="px-4 sm:px-6 py-3 sm:py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 hover:bg-gray-50/50 transition-colors">
                <!-- Module info -->
                <div class="flex items-center gap-3 flex-1 min-w-0">
                    <div class="w-9 h-9 bg-corp-50 rounded-lg flex items-center justify-center flex-shrink-0">
                        <?php
                        // Iconos inline SVG por módulo
                        $icons = [
                            'dashboard'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>',
                            'users'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',
                            'devices'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
                            'policies'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>',
                            'releases'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/>',
                            'admin-users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
                            'assignments'   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>',
                            'organization'  => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>',
                            'settings'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>',
                        ];
                        $svgPath = $icons[$modSlug] ?? $icons['settings'];
                        ?>
                        <svg class="w-4.5 h-4.5 text-corp-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><?= $svgPath ?></svg>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-dark"><?= htmlspecialchars($modInfo['label']) ?></p>
                        <p class="text-xs text-muted"><?= htmlspecialchars($modInfo['description']) ?></p>
                    </div>
                </div>

                <!-- Role toggles -->
                <div class="flex flex-wrap items-center gap-3 sm:gap-6 pl-12 sm:pl-0">
                    <?php
                    $fieldName = 'mod_' . str_replace('-', '_', $modSlug);
                    foreach ($roles as $role):
                        $isChecked  = in_array($role, $visibility[$modSlug] ?? []);
                        $isSuperadmin = ($role === 'superadmin');
                    ?>
                    <label class="flex flex-row sm:flex-col items-center gap-2 sm:gap-1 cursor-<?= $isSuperadmin ? 'not-allowed' : 'pointer' ?>">
                        <div class="relative">
                            <input
                                type="checkbox"
                                name="<?= $fieldName ?>[]"
                                value="<?= $role ?>"
                                <?= $isChecked ? 'checked' : '' ?>
                                <?= $isSuperadmin ? 'disabled checked' : '' ?>
                                class="sr-only peer"
                                @change="markDirty()"
                            >
                            <!-- Hidden input para superadmin (disabled no envía en POST) -->
                            <?php if ($isSuperadmin): ?>
                            <input type="hidden" name="<?= $fieldName ?>[]" value="superadmin">
                            <?php endif; ?>
                            <div class="w-10 h-5 bg-gray-200 rounded-full peer peer-checked:bg-corp-600 peer-checked:after:translate-x-full after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all <?= $isSuperadmin ? 'opacity-60' : '' ?>"></div>
                        </div>
                        <span class="text-[10px] text-muted font-medium uppercase tracking-wide sm:hidden lg:block"><?= $roleLabels[$role] ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Actions bar -->
    <div class="mt-6 flex items-center justify-between">
        <div x-show="dirty" x-transition class="flex items-center gap-2 text-amber-600">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            <span class="text-sm font-medium">Tienes cambios sin guardar</span>
        </div>
        <div x-show="!dirty"></div>
        <div class="flex items-center gap-3">
            <button type="button"
                    @click="resetForm()"
                    x-show="dirty"
                    class="px-4 py-2.5 border border-gray-200 rounded-lg text-sm text-gray-600 hover:bg-gray-50 transition-colors">
                Descartar
            </button>
            <button type="submit"
                    class="px-6 py-2.5 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900 disabled:opacity-40 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                    :disabled="!dirty">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Guardar cambios
            </button>
        </div>
    </div>
</form>

<!-- Danger zone: Reset to defaults -->
<div class="mt-10 bg-white rounded-xl border border-red-200 shadow-sm overflow-hidden">
    <div class="px-6 py-4 border-b border-red-100 bg-red-50/50">
        <h3 class="text-sm font-semibold text-red-800">Zona de peligro</h3>
    </div>
    <div class="px-4 sm:px-6 py-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <p class="text-sm font-medium text-dark">Restaurar valores por defecto</p>
            <p class="text-xs text-muted">Restablece la visibilidad del menú a la configuración original: solo superadmin ve secciones avanzadas.</p>
        </div>
        <form method="POST" x-data="{confirming: false}">
            <input type="hidden" name="save_visibility" value="1">
            <?php
            // Hidden inputs con defaults
            $defaults = [
                'dashboard'   => ['superadmin', 'admin', 'viewer'],
                'users'       => ['superadmin', 'admin', 'viewer'],
                'devices'     => ['superadmin', 'admin'],
                'policies'    => ['superadmin'],
                'releases'    => ['superadmin'],
                'admin-users' => ['superadmin'],
                'assignments'   => ['superadmin'],
                'organization'  => ['superadmin'],
                'roles'         => ['superadmin'],
                'settings'    => ['superadmin'],
                'server-health' => ['superadmin'],
            ];
            foreach ($defaults as $dmod => $droles):
                $dname = 'mod_' . str_replace('-', '_', $dmod);
                foreach ($droles as $dr):
            ?>
            <input type="hidden" name="<?= $dname ?>[]" value="<?= $dr ?>">
            <?php endforeach; endforeach; ?>

            <button type="button" @click="confirming = true" x-show="!confirming"
                    class="px-4 py-2 border border-red-300 text-red-700 rounded-lg text-sm hover:bg-red-50 transition-colors">
                Restaurar
            </button>
            <div x-show="confirming" x-transition class="flex items-center gap-2">
                <span class="text-xs text-red-600 font-medium">¿Seguro?</span>
                <button type="submit" class="px-3 py-1.5 bg-red-600 text-white rounded-md text-xs font-medium hover:bg-red-700">Sí, restaurar</button>
                <button type="button" @click="confirming = false" class="px-3 py-1.5 border border-gray-200 text-gray-600 rounded-md text-xs hover:bg-gray-50">Cancelar</button>
            </div>
        </form>
    </div>
</div>

<!-- Alpine.js logic -->
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
<script>
function settingsForm() {
    return {
        dirty: false,
        initialState: null,
        init() {
            this.$nextTick(() => {
                this.initialState = this.getFormState();
            });
        },
        getFormState() {
            const form = this.$el;
            const data = {};
            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                data[cb.name + '|' + cb.value] = cb.checked;
            });
            return JSON.stringify(data);
        },
        markDirty() {
            this.dirty = this.getFormState() !== this.initialState;
        },
        resetForm() {
            const initial = JSON.parse(this.initialState);
            const form = this.$el;
            form.querySelectorAll('input[type="checkbox"]:not([disabled])').forEach(cb => {
                const key = cb.name + '|' + cb.value;
                cb.checked = initial[key] || false;
            });
            this.dirty = false;
        },
        submitForm(e) {
            e.target.submit();
        }
    };
}
</script>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
