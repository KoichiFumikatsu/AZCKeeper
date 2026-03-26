<?php
/**
 * Organización — CRUD para Sociedades, Firmas, Áreas, Cargos y Sedes.
 *
 * Keeper es fuente de verdad: crea/edita/elimina los registros.
 * Al crear, valida que no exista un registro con el mismo nombre.
 * Al eliminar, verifica si hay usuarios asignados y advierte.
 *
 * Tablas: keeper_sociedades, keeper_firmas, keeper_areas, keeper_cargos, keeper_sedes
 * Tabla de enlace: keeper_user_assignments (sociedad_id, firm_id, area_id, cargo_id, sede_id)
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('organization');

$pageTitle   = 'Organización';
$currentPage = 'organization';
$msg     = '';
$msgType = '';

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $entity = $_POST['entity'] ?? '';

    // Mapa de entidades permitidas → tabla, campo nombre, campo id
    $entities = [
        'sociedad' => ['table' => 'keeper_sociedades', 'name_col' => 'nombre', 'label' => 'Sociedad'],
        'firm'     => ['table' => 'keeper_firmas',     'name_col' => 'nombre', 'label' => 'Firma'],
        'area'     => ['table' => 'keeper_areas',      'name_col' => 'nombre', 'label' => 'Área'],
        'cargo'    => ['table' => 'keeper_cargos',     'name_col' => 'nombre', 'label' => 'Cargo'],
        'sede'     => ['table' => 'keeper_sedes',      'name_col' => 'nombre', 'label' => 'Sede'],
    ];

    if (!isset($entities[$entity])) {
        $msg = 'Entidad no válida.';
        $msgType = 'error';
    } else {
        $ent = $entities[$entity];
        try {
            if (!canDo('organization', 'can_edit')) throw new \Exception('Sin permisos');

            switch ($action) {
                /* ── Crear ── */
                case 'create':
                    $name = trim($_POST['name'] ?? '');
                    if ($name === '') throw new \Exception('El nombre es obligatorio.');

                    // Validar duplicado
                    $chk = $pdo->prepare("SELECT id FROM {$ent['table']} WHERE {$ent['name_col']} = ? LIMIT 1");
                    $chk->execute([$name]);
                    if ($chk->fetchColumn()) throw new \Exception("{$ent['label']} '{$name}' ya existe.");

                    if ($entity === 'sociedad') {
                        $nit  = trim($_POST['nit'] ?? '');
                        $desc = trim($_POST['descripcion'] ?? '');
                        $st = $pdo->prepare("INSERT INTO keeper_sociedades (nombre, nit, descripcion) VALUES (?, ?, ?)");
                        $st->execute([$name, $nit ?: null, $desc ?: null]);
                    } elseif ($entity === 'firm') {
                        $manager = trim($_POST['manager'] ?? '');
                        $mailMgr = trim($_POST['mail_manager'] ?? '');
                        $st = $pdo->prepare("INSERT INTO keeper_firmas (nombre, manager, mail_manager) VALUES (?, ?, ?)");
                        $st->execute([$name, $manager ?: null, $mailMgr ?: null]);
                    } elseif ($entity === 'area') {
                        $desc = trim($_POST['descripcion'] ?? '');
                        $st = $pdo->prepare("INSERT INTO keeper_areas (nombre, descripcion) VALUES (?, ?)");
                        $st->execute([$name, $desc ?: null]);
                    } elseif ($entity === 'sede') {
                        $codigo = trim($_POST['codigo'] ?? '');
                        $desc   = trim($_POST['descripcion'] ?? '');
                        if ($codigo === '') throw new \Exception('El código de sede es obligatorio.');
                        $chk2 = $pdo->prepare("SELECT id FROM keeper_sedes WHERE codigo = ? LIMIT 1");
                        $chk2->execute([$codigo]);
                        if ($chk2->fetchColumn()) throw new \Exception("Código de sede '{$codigo}' ya existe.");
                        $st = $pdo->prepare("INSERT INTO keeper_sedes (nombre, codigo, descripcion) VALUES (?, ?, ?)");
                        $st->execute([$name, $codigo, $desc ?: null]);
                    } elseif ($entity === 'cargo') {
                        $desc  = trim($_POST['descripcion'] ?? '');
                        $nivel = (int)($_POST['nivel_jerarquico'] ?? 0);
                        $st = $pdo->prepare("INSERT INTO keeper_cargos (nombre, descripcion, nivel_jerarquico) VALUES (?, ?, ?)");
                        $st->execute([$name, $desc ?: null, $nivel]);
                    }
                    $msg = "{$ent['label']} '{$name}' creado(a).";
                    $msgType = 'success';
                    break;

                /* ── Editar ── */
                case 'edit':
                    $id   = (int)($_POST['entity_id'] ?? 0);
                    $name = trim($_POST['name'] ?? '');
                    if ($id <= 0 || $name === '') throw new \Exception('ID y nombre son obligatorios.');

                    // Validar duplicado (excluyendo el propio registro)
                    $chk = $pdo->prepare("SELECT id FROM {$ent['table']} WHERE {$ent['name_col']} = ? AND id != ? LIMIT 1");
                    $chk->execute([$name, $id]);
                    if ($chk->fetchColumn()) throw new \Exception("{$ent['label']} '{$name}' ya existe.");

                    if ($entity === 'sociedad') {
                        $nit  = trim($_POST['nit'] ?? '');
                        $desc = trim($_POST['descripcion'] ?? '');
                        $st = $pdo->prepare("UPDATE keeper_sociedades SET nombre = ?, nit = ?, descripcion = ? WHERE id = ?");
                        $st->execute([$name, $nit ?: null, $desc ?: null, $id]);
                    } elseif ($entity === 'firm') {
                        $manager = trim($_POST['manager'] ?? '');
                        $mailMgr = trim($_POST['mail_manager'] ?? '');
                        $st = $pdo->prepare("UPDATE keeper_firmas SET nombre = ?, manager = ?, mail_manager = ? WHERE id = ?");
                        $st->execute([$name, $manager ?: null, $mailMgr ?: null, $id]);
                    } elseif ($entity === 'area') {
                        $desc = trim($_POST['descripcion'] ?? '');
                        $st = $pdo->prepare("UPDATE keeper_areas SET nombre = ?, descripcion = ? WHERE id = ?");
                        $st->execute([$name, $desc ?: null, $id]);
                    } elseif ($entity === 'sede') {
                        $codigo = trim($_POST['codigo'] ?? '');
                        $desc   = trim($_POST['descripcion'] ?? '');
                        if ($codigo === '') throw new \Exception('El código de sede es obligatorio.');
                        $chk2 = $pdo->prepare("SELECT id FROM keeper_sedes WHERE codigo = ? AND id != ? LIMIT 1");
                        $chk2->execute([$codigo, $id]);
                        if ($chk2->fetchColumn()) throw new \Exception("Código de sede '{$codigo}' ya existe.");
                        $st = $pdo->prepare("UPDATE keeper_sedes SET nombre = ?, codigo = ?, descripcion = ? WHERE id = ?");
                        $st->execute([$name, $codigo, $desc ?: null, $id]);
                    } elseif ($entity === 'cargo') {
                        $desc  = trim($_POST['descripcion'] ?? '');
                        $nivel = (int)($_POST['nivel_jerarquico'] ?? 0);
                        $st = $pdo->prepare("UPDATE keeper_cargos SET nombre = ?, descripcion = ?, nivel_jerarquico = ? WHERE id = ?");
                        $st->execute([$name, $desc ?: null, $nivel, $id]);
                    }
                    $msg = "{$ent['label']} actualizado(a).";
                    $msgType = 'success';
                    break;

                /* ── Eliminar ── */
                case 'delete':
                    if (!canDo('organization', 'can_delete')) throw new \Exception('Sin permisos para eliminar.');
                    $id = (int)($_POST['entity_id'] ?? 0);
                    if ($id <= 0) throw new \Exception('ID inválido.');

                    // Mapa de FK en keeper_user_assignments
                    $fkMap = ['sociedad' => 'sociedad_id', 'firm' => 'firm_id', 'area' => 'area_id', 'cargo' => 'cargo_id', 'sede' => 'sede_id'];
                    $fk = $fkMap[$entity] ?? null;
                    if ($fk) {
                        $cnt = $pdo->prepare("SELECT COUNT(*) FROM keeper_user_assignments WHERE {$fk} = ?");
                        $cnt->execute([$id]);
                        $usersLinked = (int)$cnt->fetchColumn();
                        if ($usersLinked > 0) {
                            // Limpiar FK en asignaciones (set null)
                            $pdo->prepare("UPDATE keeper_user_assignments SET {$fk} = NULL WHERE {$fk} = ?")->execute([$id]);
                        }
                    }

                    $pdo->prepare("DELETE FROM {$ent['table']} WHERE id = ?")->execute([$id]);
                    $msg = "{$ent['label']} eliminado(a)." . (isset($usersLinked) && $usersLinked > 0 ? " Se desvincularon {$usersLinked} usuario(s)." : '');
                    $msgType = 'success';
                    break;

                default:
                    throw new \Exception('Acción desconocida.');
            }
        } catch (\Exception $e) {
            $msg     = $e->getMessage();
            $msgType = 'error';
        }

        if ($msgType === 'success') {
            header("Location: organization.php?tab={$entity}&msg=" . urlencode($msg) . "&type=success");
            exit;
        }
    }
}

if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

$activeTab = $_GET['tab'] ?? 'sociedad';
if (!in_array($activeTab, ['sociedad', 'firm', 'area', 'cargo', 'sede'])) $activeTab = 'sociedad';

/* ==================== DATOS ==================== */

// Sociedades
$sociedades = $pdo->query("
    SELECT s.*, COUNT(ua.id) AS users_count
    FROM keeper_sociedades s
    LEFT JOIN keeper_user_assignments ua ON ua.sociedad_id = s.id
    GROUP BY s.id
    ORDER BY s.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Firmas
$firms = $pdo->query("
    SELECT f.*, COUNT(ua.id) AS users_count
    FROM keeper_firmas f
    LEFT JOIN keeper_user_assignments ua ON ua.firm_id = f.id
    GROUP BY f.id
    ORDER BY f.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Áreas
$areas = $pdo->query("
    SELECT a.*, COUNT(ua.id) AS users_count
    FROM keeper_areas a
    LEFT JOIN keeper_user_assignments ua ON ua.area_id = a.id
    GROUP BY a.id
    ORDER BY a.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Cargos
$cargos = $pdo->query("
    SELECT c.*, COUNT(ua.id) AS users_count
    FROM keeper_cargos c
    LEFT JOIN keeper_user_assignments ua ON ua.cargo_id = c.id
    GROUP BY c.id
    ORDER BY c.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Sedes
$sedes = $pdo->query("
    SELECT s.*, COUNT(ua.id) AS users_count
    FROM keeper_sedes s
    LEFT JOIN keeper_user_assignments ua ON ua.sede_id = s.id
    GROUP BY s.id
    ORDER BY s.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

$canEdit   = canDo('organization', 'can_edit');
$canDelete = canDo('organization', 'can_delete');

require_once __DIR__ . '/partials/layout_header.php';
?>

<div x-data="{
    tab: '<?= htmlspecialchars($activeTab) ?>',
    editModal: false,
    editEntity: '',
    editId: 0,
    editName: '',
    editManager: '',
    editMailManager: '',
    editDescripcion: '',
    editCodigo: '',
    editNit: '',
    editNivelJerarquico: 0,
    editAction: 'create',
    openCreate(entity) {
        this.editEntity = entity;
        this.editAction = 'create';
        this.editId = 0;
        this.editName = '';
        this.editManager = '';
        this.editMailManager = '';
        this.editDescripcion = '';
        this.editCodigo = '';
        this.editNit = '';
        this.editNivelJerarquico = 0;
        this.editModal = true;
    },
    openEdit(entity, id, name, extra) {
        this.editEntity = entity;
        this.editAction = 'edit';
        this.editId = id;
        this.editName = name;
        this.editManager = extra.manager || '';
        this.editMailManager = extra.mail_manager || '';
        this.editDescripcion = extra.descripcion || '';
        this.editCodigo = extra.codigo || '';
        this.editNit = extra.nit || '';
        this.editNivelJerarquico = extra.nivel_jerarquico || 0;
        this.editModal = true;
    },
    entityLabel() {
        return { sociedad: 'Sociedad', firm: 'Firma', area: 'Área', cargo: 'Cargo', sede: 'Sede' }[this.editEntity] || '';
    }
}">

<!-- Flash message -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium <?= $msgType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3 sm:gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5 text-center cursor-pointer transition-all" :class="tab === 'sociedad' ? 'ring-2 ring-corp-800 border-corp-200' : 'hover:border-gray-200'" @click="tab = 'sociedad'">
        <p class="text-xl sm:text-2xl font-bold text-corp-800"><?= count($sociedades) ?></p>
        <p class="text-[10px] sm:text-xs text-muted mt-0.5">Sociedades</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5 text-center cursor-pointer transition-all" :class="tab === 'firm' ? 'ring-2 ring-corp-800 border-corp-200' : 'hover:border-gray-200'" @click="tab = 'firm'">
        <p class="text-xl sm:text-2xl font-bold text-corp-800"><?= count($firms) ?></p>
        <p class="text-[10px] sm:text-xs text-muted mt-0.5">Firmas</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5 text-center cursor-pointer transition-all" :class="tab === 'area' ? 'ring-2 ring-corp-800 border-corp-200' : 'hover:border-gray-200'" @click="tab = 'area'">
        <p class="text-xl sm:text-2xl font-bold text-corp-800"><?= count($areas) ?></p>
        <p class="text-[10px] sm:text-xs text-muted mt-0.5">Áreas</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5 text-center cursor-pointer transition-all" :class="tab === 'cargo' ? 'ring-2 ring-corp-800 border-corp-200' : 'hover:border-gray-200'" @click="tab = 'cargo'">
        <p class="text-xl sm:text-2xl font-bold text-corp-800"><?= count($cargos) ?></p>
        <p class="text-[10px] sm:text-xs text-muted mt-0.5">Cargos</p>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5 text-center cursor-pointer transition-all" :class="tab === 'sede' ? 'ring-2 ring-corp-800 border-corp-200' : 'hover:border-gray-200'" @click="tab = 'sede'">
        <p class="text-xl sm:text-2xl font-bold text-corp-800"><?= count($sedes) ?></p>
        <p class="text-[10px] sm:text-xs text-muted mt-0.5">Sedes</p>
    </div>
</div>

<!-- ===================== TAB: SOCIEDADES ===================== -->
<div x-show="tab === 'sociedad'" x-transition class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4z"/></svg>
            <h3 class="text-base font-bold text-dark">Sociedades</h3>
            <span class="text-xs text-muted">(<?= count($sociedades) ?>)</span>
        </div>
        <?php if ($canEdit): ?>
        <button @click="openCreate('sociedad')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nueva Sociedad
        </button>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-100">
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Nombre</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">NIT</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Descripción</th>
                <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">Estado</th>
                <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Usuarios</th>
                <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($sociedades as $soc): ?>
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="py-2.5 px-3 font-medium text-dark">
                        <?= htmlspecialchars($soc['nombre']) ?>
                        <p class="text-[10px] text-muted sm:hidden"><?= htmlspecialchars($soc['nit'] ?? '') ?></p>
                    </td>
                    <td class="py-2.5 px-3 text-muted text-xs hidden sm:table-cell"><?= htmlspecialchars($soc['nit'] ?? '—') ?></td>
                    <td class="py-2.5 px-3 text-muted text-xs max-w-xs truncate hidden md:table-cell"><?= htmlspecialchars($soc['descripcion'] ?? '—') ?></td>
                    <td class="py-2.5 px-3 text-center hidden sm:table-cell">
                        <?php if ($soc['activa']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 text-xs font-medium rounded-full"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>Activa</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-50 text-gray-500 text-xs font-medium rounded-full"><span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-3 text-center">
                        <span class="inline-flex items-center px-2 py-0.5 bg-corp-50 text-corp-800 text-xs font-medium rounded-full"><?= (int)$soc['users_count'] ?></span>
                    </td>
                    <td class="py-2.5 px-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($canEdit): ?>
                            <button @click="openEdit('sociedad', <?= $soc['id'] ?>, <?= htmlspecialchars(json_encode($soc['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(['nit' => $soc['nit'] ?? '', 'descripcion' => $soc['descripcion'] ?? '']), ENT_QUOTES) ?>)"
                                    class="p-1.5 rounded-lg text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar sociedad &quot;<?= htmlspecialchars($soc['nombre'], ENT_QUOTES) ?>&quot;?<?= (int)$soc['users_count'] > 0 ? ' Tiene ' . (int)$soc['users_count'] . ' usuario(s) vinculados que serán desvinculados.' : '' ?>')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entity" value="sociedad">
                                <input type="hidden" name="entity_id" value="<?= $soc['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Eliminar">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($sociedades)): ?><tr><td colspan="6" class="py-6 text-center text-sm text-muted">Sin sociedades registradas</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== TAB: FIRMAS ===================== -->
<div x-show="tab === 'firm'" x-transition class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            <h3 class="text-base font-bold text-dark">Firmas</h3>
            <span class="text-xs text-muted">(<?= count($firms) ?>)</span>
        </div>
        <?php if ($canEdit): ?>
        <button @click="openCreate('firm')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nueva Firma
        </button>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-100">
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Nombre</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">Manager</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Email Manager</th>
                <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Usuarios</th>
                <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($firms as $f): ?>
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="py-2.5 px-3 font-medium text-dark">
                        <?= htmlspecialchars($f['nombre']) ?>
                        <p class="text-[10px] text-muted sm:hidden"><?= htmlspecialchars($f['manager'] ?? '') ?></p>
                    </td>
                    <td class="py-2.5 px-3 text-muted text-xs hidden sm:table-cell"><?= htmlspecialchars($f['manager'] ?? '—') ?></td>
                    <td class="py-2.5 px-3 text-muted text-xs hidden md:table-cell"><?= htmlspecialchars($f['mail_manager'] ?? '—') ?></td>
                    <td class="py-2.5 px-3 text-center">
                        <span class="inline-flex items-center px-2 py-0.5 bg-corp-50 text-corp-800 text-xs font-medium rounded-full"><?= (int)$f['users_count'] ?></span>
                    </td>
                    <td class="py-2.5 px-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($canEdit): ?>
                            <button @click="openEdit('firm', <?= $f['id'] ?>, <?= htmlspecialchars(json_encode($f['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(['manager' => $f['manager'] ?? '', 'mail_manager' => $f['mail_manager'] ?? '']), ENT_QUOTES) ?>)"
                                    class="p-1.5 rounded-lg text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar firma &quot;<?= htmlspecialchars($f['nombre'], ENT_QUOTES) ?>&quot;?<?= (int)$f['users_count'] > 0 ? ' Tiene ' . (int)$f['users_count'] . ' usuario(s) vinculados que serán desvinculados.' : '' ?>')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entity" value="firm">
                                <input type="hidden" name="entity_id" value="<?= $f['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Eliminar">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($firms)): ?><tr><td colspan="5" class="py-6 text-center text-sm text-muted">Sin firmas registradas</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== TAB: ÁREAS ===================== -->
<div x-show="tab === 'area'" x-transition class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6" style="display:none">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            <h3 class="text-base font-bold text-dark">Áreas</h3>
            <span class="text-xs text-muted">(<?= count($areas) ?>)</span>
        </div>
        <?php if ($canEdit): ?>
        <button @click="openCreate('area')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nueva Área
        </button>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-100">
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Nombre</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">Descripción</th>
                <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Usuarios</th>
                <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($areas as $a): ?>
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="py-2.5 px-3 font-medium text-dark"><?= htmlspecialchars($a['nombre']) ?></td>
                    <td class="py-2.5 px-3 text-muted text-xs max-w-xs truncate hidden sm:table-cell"><?= htmlspecialchars($a['descripcion'] ?? '—') ?></td>
                    <td class="py-2.5 px-3 text-center">
                        <span class="inline-flex items-center px-2 py-0.5 bg-corp-50 text-corp-800 text-xs font-medium rounded-full"><?= (int)$a['users_count'] ?></span>
                    </td>
                    <td class="py-2.5 px-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($canEdit): ?>
                            <button @click="openEdit('area', <?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(['descripcion' => $a['descripcion'] ?? '']), ENT_QUOTES) ?>)"
                                    class="p-1.5 rounded-lg text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar área &quot;<?= htmlspecialchars($a['nombre'], ENT_QUOTES) ?>&quot;?<?= (int)$a['users_count'] > 0 ? ' Tiene ' . (int)$a['users_count'] . ' usuario(s) vinculados.' : '' ?>')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entity" value="area">
                                <input type="hidden" name="entity_id" value="<?= $a['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Eliminar">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($areas)): ?><tr><td colspan="4" class="py-6 text-center text-sm text-muted">Sin áreas registradas</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ===================== TAB: CARGOS ===================== -->
<div x-show="tab === 'cargo'" x-transition class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6" style="display:none">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <h3 class="text-base font-bold text-dark">Cargos</h3>
            <span class="text-xs text-muted">(<?= count($cargos) ?>)</span>
        </div>
        <?php if ($canEdit): ?>
        <button @click="openCreate('cargo')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nuevo Cargo
        </button>
        <?php endif; ?>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <?php foreach ($cargos as $c): ?>
        <div class="flex items-center justify-between p-3 border border-gray-100 rounded-lg hover:bg-gray-50/50 transition-colors group">
            <div class="flex items-center gap-2.5 min-w-0">
                <div class="w-8 h-8 bg-corp-50 rounded-lg flex items-center justify-center flex-shrink-0">
                    <span class="text-xs font-bold text-corp-800"><?= strtoupper(substr($c['nombre'], 0, 2)) ?></span>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($c['nombre']) ?></p>
                    <p class="text-[10px] text-muted"><?= (int)$c['users_count'] ?> usuario(s) · Nivel <?= (int)($c['nivel_jerarquico'] ?? 0) ?></p>
                </div>
            </div>
            <div class="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                <?php if ($canEdit): ?>
                <button @click="openEdit('cargo', <?= $c['id'] ?>, <?= htmlspecialchars(json_encode($c['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(['descripcion' => $c['descripcion'] ?? '', 'nivel_jerarquico' => (int)($c['nivel_jerarquico'] ?? 0)]), ENT_QUOTES) ?>)"
                        class="p-1 rounded text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <?php endif; ?>
                <?php if ($canDelete): ?>
                <form method="post" class="inline" onsubmit="return confirm('¿Eliminar cargo &quot;<?= htmlspecialchars($c['nombre'], ENT_QUOTES) ?>&quot;?')">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="entity" value="cargo">
                    <input type="hidden" name="entity_id" value="<?= $c['id'] ?>">
                    <button type="submit" class="p-1 rounded text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Eliminar">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($cargos)): ?>
        <p class="col-span-full text-sm text-muted text-center py-6">Sin cargos registrados</p>
        <?php endif; ?>
    </div>
</div>

<!-- ===================== TAB: SEDES ===================== -->
<div x-show="tab === 'sede'" x-transition class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-6" style="display:none">
    <div class="flex items-center justify-between mb-4">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <h3 class="text-base font-bold text-dark">Sedes</h3>
            <span class="text-xs text-muted">(<?= count($sedes) ?>)</span>
        </div>
        <?php if ($canEdit): ?>
        <button @click="openCreate('sede')" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nueva Sede
        </button>
        <?php endif; ?>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="border-b border-gray-100">
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Nombre</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Código</th>
                <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Descripción</th>
                <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">Estado</th>
                <th class="text-center py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Usuarios</th>
                <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
            </tr></thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($sedes as $s): ?>
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="py-2.5 px-3 font-medium text-dark"><?= htmlspecialchars($s['nombre']) ?></td>
                    <td class="py-2.5 px-3"><span class="inline-flex items-center px-2 py-0.5 bg-gray-50 text-gray-600 text-xs font-mono rounded"><?= htmlspecialchars($s['codigo']) ?></span></td>
                    <td class="py-2.5 px-3 text-muted text-xs max-w-xs truncate hidden md:table-cell"><?= htmlspecialchars($s['descripcion'] ?? '—') ?></td>
                    <td class="py-2.5 px-3 text-center hidden sm:table-cell">
                        <?php if (($s['activa'] ?? 1)): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 text-xs font-medium rounded-full"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span>Activa</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-50 text-gray-500 text-xs font-medium rounded-full"><span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>Inactiva</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-3 text-center">
                        <span class="inline-flex items-center px-2 py-0.5 bg-corp-50 text-corp-800 text-xs font-medium rounded-full"><?= (int)$s['users_count'] ?></span>
                    </td>
                    <td class="py-2.5 px-3 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if ($canEdit): ?>
                            <button @click="openEdit('sede', <?= $s['id'] ?>, <?= htmlspecialchars(json_encode($s['nombre']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(['codigo' => $s['codigo'], 'descripcion' => $s['descripcion'] ?? '']), ENT_QUOTES) ?>)"
                                    class="p-1.5 rounded-lg text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar sede &quot;<?= htmlspecialchars($s['nombre'], ENT_QUOTES) ?>&quot;?<?= (int)$s['users_count'] > 0 ? ' Tiene ' . (int)$s['users_count'] . ' usuario(s) vinculados.' : '' ?>')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="entity" value="sede">
                                <input type="hidden" name="entity_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Eliminar">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($sedes)): ?><tr><td colspan="6" class="py-6 text-center text-sm text-muted">Sin sedes registradas</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- ===================== MODAL: Crear / Editar ===================== -->
<div x-show="editModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="editModal = false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display:none">
    <div class="fixed inset-0 bg-black/40" @click="editModal = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg" @click.stop>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" :value="editAction">
            <input type="hidden" name="entity" :value="editEntity">
            <input type="hidden" name="entity_id" :value="editId">

            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-dark">
                    <span x-text="editAction === 'create' ? 'Crear' : 'Editar'"></span>
                    <span x-text="entityLabel()"></span>
                </h3>
                <button type="button" @click="editModal = false" class="p-1.5 rounded-lg hover:bg-gray-100 text-muted hover:text-dark transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Nombre (siempre) -->
            <div>
                <label class="text-xs font-semibold text-dark block mb-1">Nombre</label>
                <input type="text" name="name" x-model="editName" required
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                       placeholder="Nombre…">
            </div>

            <!-- Campos específicos de Sociedad -->
            <template x-if="editEntity === 'sociedad'">
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">NIT</label>
                        <input type="text" name="nit" x-model="editNit"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                               placeholder="NIT o identificación fiscal (opcional)">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Descripción</label>
                        <textarea name="descripcion" x-model="editDescripcion" rows="2"
                                  class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"
                                  placeholder="Descripción de la sociedad (opcional)"></textarea>
                    </div>
                </div>
            </template>

            <!-- Campos específicos de Firma -->
            <template x-if="editEntity === 'firm'">
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Manager</label>
                        <input type="text" name="manager" x-model="editManager"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                               placeholder="Nombre del manager (opcional)">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Email Manager</label>
                        <input type="email" name="mail_manager" x-model="editMailManager"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                               placeholder="email@empresa.com (opcional)">
                    </div>
                </div>
            </template>

            <!-- Campos específicos de Área -->
            <template x-if="editEntity === 'area'">
                <div>
                    <label class="text-xs font-semibold text-dark block mb-1">Descripción</label>
                    <textarea name="descripcion" x-model="editDescripcion" rows="2"
                              class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"
                              placeholder="Descripción del área (opcional)"></textarea>
                </div>
            </template>

            <!-- Campos específicos de Cargo -->
            <template x-if="editEntity === 'cargo'">
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Descripción</label>
                        <textarea name="descripcion" x-model="editDescripcion" rows="2"
                                  class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"
                                  placeholder="Descripción del cargo (opcional)"></textarea>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Nivel Jerárquico</label>
                        <input type="number" name="nivel_jerarquico" x-model="editNivelJerarquico" min="0"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                               placeholder="0 = base, mayor = más autoridad">
                        <p class="text-[10px] text-muted mt-1">Define la jerarquía: 0 = base, valores mayores = más autoridad (ej: 1=Coord, 2=Director, 3=Gerente)</p>
                    </div>
                </div>
            </template>

            <!-- Campos específicos de Sede -->
            <template x-if="editEntity === 'sede'">
                <div class="space-y-3">
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Código</label>
                        <input type="text" name="codigo" x-model="editCodigo" required
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                               placeholder="Ej: BOG-01, MED-02">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-dark block mb-1">Descripción</label>
                        <textarea name="descripcion" x-model="editDescripcion" rows="2"
                                  class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"
                                  placeholder="Descripción de la sede (opcional)"></textarea>
                    </div>
                </div>
            </template>

            <div class="flex justify-end gap-3 pt-2">
                <button type="button" @click="editModal = false" class="px-4 py-2 text-sm text-muted hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900 transition-colors">
                    <span x-text="editAction === 'create' ? 'Crear' : 'Guardar'"></span>
                </button>
            </div>
        </form>
    </div>
</div>

</div><!-- /x-data -->

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
