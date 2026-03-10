<?php
/**
 * Asignaciones — vincular keeper_users con sociedad/firma/área/cargo.
 *
 * Tabla: keeper_user_assignments
 *   keeper_user_id → keeper_users.id
 *   sociedad_id    → keeper_sociedades.id
 *   firm_id        → keeper_firmas.id
 *   area_id        → keeper_areas.id
 *   cargo_id       → keeper_cargos.id
 *
 * Permisos: assignments (can_view, can_edit).
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('assignments');

$pageTitle   = 'Asignaciones';
$currentPage = 'assignments';
$msg     = '';
$msgType = '';

/**
 * Interpreta un campo del batch: '' = no cambiar (null), 'clear' = quitar (0), número = asignar.
 * Retorna: null (skip), 0 (set to NULL in DB), int (set to value).
 */
function parseBatchField(string $val): ?int {
    if ($val === '') return null;        // no cambiar
    if ($val === 'clear') return 0;      // quitar → se convierte a NULL en el UPDATE
    return (int)$val;
}

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            /* ── Guardar asignación (create or update) ── */
            case 'save':
                if (!canDo('assignments', 'can_edit')) throw new \Exception('Sin permisos');

                $keeperUserId = (int)($_POST['keeper_user_id'] ?? 0);
                $sociedadId   = ($_POST['sociedad_id'] ?? '') !== '' ? (int)$_POST['sociedad_id'] : null;
                $firmId       = ($_POST['firm_id'] ?? '') !== '' ? (int)$_POST['firm_id'] : null;
                $areaId       = ($_POST['area_id'] ?? '') !== '' ? (int)$_POST['area_id'] : null;
                $cargoId      = ($_POST['cargo_id'] ?? '') !== '' ? (int)$_POST['cargo_id'] : null;
                $sedeId       = ($_POST['sede_id'] ?? '') !== '' ? (int)$_POST['sede_id'] : null;

                if ($keeperUserId <= 0) throw new \Exception('Usuario inválido');

                // Check if assignment already exists
                $existing = $pdo->prepare("SELECT id FROM keeper_user_assignments WHERE keeper_user_id = ?");
                $existing->execute([$keeperUserId]);
                $existingId = $existing->fetchColumn();

                if ($existingId) {
                    $st = $pdo->prepare("
                        UPDATE keeper_user_assignments SET
                            sociedad_id = ?, firm_id = ?, area_id = ?, cargo_id = ?, sede_id = ?, assigned_by = ?, manual_override = 1
                        WHERE id = ?
                    ");
                    $st->execute([$sociedadId, $firmId, $areaId, $cargoId, $sedeId, $adminUser['admin_id'], $existingId]);
                    $msg = 'Asignación actualizada (override manual activado).';
                } else {
                    $st = $pdo->prepare("
                        INSERT INTO keeper_user_assignments
                        (keeper_user_id, sociedad_id, firm_id, area_id, cargo_id, sede_id, assigned_by, manual_override)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $st->execute([$keeperUserId, $sociedadId, $firmId, $areaId, $cargoId, $sedeId, $adminUser['admin_id']]);
                    $msg = 'Asignación creada (override manual activado).';
                }
                $msgType = 'success';
                break;

            /* ── Batch: asignar firma/área/cargo a varios usuarios ── */
            case 'batch_assign':
                if (!canDo('assignments', 'can_edit')) throw new \Exception('Sin permisos');

                $userIds    = $_POST['batch_users'] ?? [];
                $sociedadId = parseBatchField($_POST['sociedad_id'] ?? '');
                $firmId     = parseBatchField($_POST['firm_id'] ?? '');
                $areaId     = parseBatchField($_POST['area_id'] ?? '');
                $cargoId    = parseBatchField($_POST['cargo_id'] ?? '');
                $sedeId     = parseBatchField($_POST['sede_id'] ?? '');

                if (empty($userIds)) throw new \Exception('Selecciona al menos un usuario');

                $count = 0;
                foreach ($userIds as $uid) {
                    $uid = (int)$uid;
                    if ($uid <= 0) continue;

                    $ex = $pdo->prepare("SELECT id FROM keeper_user_assignments WHERE keeper_user_id = ?");
                    $ex->execute([$uid]);
                    $eid = $ex->fetchColumn();

                    if ($eid) {
                        $sets = [];
                        $vals = [];
                        if ($sociedadId !== null) { $sets[] = 'sociedad_id = ?'; $vals[] = $sociedadId === 0 ? null : $sociedadId; }
                        if ($firmId !== null)  { $sets[] = 'firm_id = ?';  $vals[] = $firmId === 0 ? null : $firmId; }
                        if ($areaId !== null)  { $sets[] = 'area_id = ?';  $vals[] = $areaId === 0 ? null : $areaId; }
                        if ($cargoId !== null) { $sets[] = 'cargo_id = ?'; $vals[] = $cargoId === 0 ? null : $cargoId; }
                        if ($sedeId !== null)  { $sets[] = 'sede_id = ?';  $vals[] = $sedeId === 0 ? null : $sedeId; }
                        if (!empty($sets)) {
                            $sets[] = 'assigned_by = ?';
                            $vals[] = $adminUser['admin_id'];
                            $sets[] = 'manual_override = 1';
                            $vals[] = $eid;
                            $pdo->prepare("UPDATE keeper_user_assignments SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
                        }
                    } else {
                        $pdo->prepare("INSERT INTO keeper_user_assignments (keeper_user_id, sociedad_id, firm_id, area_id, cargo_id, sede_id, assigned_by, manual_override) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
                             ->execute([$uid, $sociedadId ?: null, $firmId ?: null, $areaId ?: null, $cargoId ?: null, $sedeId ?: null, $adminUser['admin_id']]);
                    }
                    $count++;
                }
                $msg = "Asignación aplicada a {$count} usuario(s).";
                $msgType = 'success';
                break;

            /* ── Eliminar asignación ── */
            case 'delete':
                if (!canDo('assignments', 'can_edit')) throw new \Exception('Sin permisos');

                $id = (int)($_POST['assignment_id'] ?? 0);
                if ($id <= 0) throw new \Exception('Asignación inválida');

                $pdo->prepare("DELETE FROM keeper_user_assignments WHERE id = ?")->execute([$id]);
                $msg = 'Asignación eliminada.';
                $msgType = 'success';
                break;

            /* ── Reset override: volver a sincronizar desde legacy ── */
            case 'reset_override':
                if (!canDo('assignments', 'can_edit')) throw new \Exception('Sin permisos');

                $id = (int)($_POST['assignment_id'] ?? 0);
                if ($id <= 0) throw new \Exception('Asignación inválida');

                // Get keeper_user_id for this assignment
                $st = $pdo->prepare("SELECT keeper_user_id FROM keeper_user_assignments WHERE id = ?");
                $st->execute([$id]);
                $uid = $st->fetchColumn();
                if (!$uid) throw new \Exception('Asignación no encontrada');

                // Pull fresh data from legacy employee
                $leg = $pdo->prepare("
                    SELECT e.company AS firm_id, e.area_id, e.position_id AS cargo_id, e.sede_id
                    FROM keeper_users ku
                    JOIN employee e ON e.id = ku.legacy_employee_id
                    WHERE ku.id = ?
                ");
                $leg->execute([$uid]);
                $legacy = $leg->fetch(PDO::FETCH_ASSOC);

                if ($legacy) {
                    $pdo->prepare("
                        UPDATE keeper_user_assignments SET
                            firm_id = ?, area_id = ?, cargo_id = ?, sede_id = ?, manual_override = 0
                        WHERE id = ?
                    ")->execute([$legacy['firm_id'], $legacy['area_id'], $legacy['cargo_id'], $legacy['sede_id'], $id]);
                    $msg = 'Override removido. Datos restaurados desde legacy.';
                } else {
                    // No legacy data, just remove the flag
                    $pdo->prepare("UPDATE keeper_user_assignments SET manual_override = 0 WHERE id = ?")->execute([$id]);
                    $msg = 'Override removido (sin datos legacy disponibles).';
                }
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
        header("Location: assignments.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    }
}

/* Flash PRG */
if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== QUERIES ==================== */
$scope = scopeFilter();

/* All users with their assignments */
$usersQuery = "
    SELECT
        u.id AS user_id,
        u.display_name,
        u.email,
        u.status AS user_status,
        ua.id AS assignment_id,
        ua.sociedad_id,
        ua.firm_id,
        ua.area_id,
        ua.cargo_id,
        ua.sede_id,
        ua.manual_override,
        soc.nombre AS sociedad_name,
        f.nombre AS firm_name,
        ar.nombre AS area_name,
        c.nombre AS cargo_name,
        s.nombre AS sede_name,
        ua.assigned_at,
        ua.updated_at AS assignment_updated
    FROM keeper_users u
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    LEFT JOIN keeper_sociedades soc ON soc.id = ua.sociedad_id
    LEFT JOIN keeper_firmas f ON f.id = ua.firm_id
    LEFT JOIN keeper_areas ar ON ar.id = ua.area_id
    LEFT JOIN keeper_cargos c ON c.id = ua.cargo_id
    LEFT JOIN keeper_sedes s ON s.id = ua.sede_id
    WHERE u.status = 'active' {$scope['sql']}
    ORDER BY u.display_name ASC
";
$usersSt = $pdo->prepare($usersQuery);
$usersSt->execute($scope['params']);
$users = $usersSt->fetchAll(PDO::FETCH_ASSOC);

/* Dropdown data */
$sociedades = $pdo->query("SELECT id, nombre FROM keeper_sociedades WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$firms      = $pdo->query("SELECT id, nombre FROM keeper_firmas WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$areas      = $pdo->query("SELECT id, nombre FROM keeper_areas WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$cargos     = $pdo->query("SELECT id, nombre FROM keeper_cargos WHERE activo = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$sedes      = $pdo->query("SELECT id, nombre FROM keeper_sedes WHERE activa = 1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

/* KPIs */
$totalUsers    = count($users);
$assigned      = count(array_filter($users, fn($u) => $u['assignment_id']));
$unassigned    = $totalUsers - $assigned;
$overrideCount = count(array_filter($users, fn($u) => !empty($u['manual_override'])));
$firmCounts     = [];
$sociedadCounts = [];
foreach ($users as $u) {
    if ($u['firm_name']) {
        $firmCounts[$u['firm_name']] = ($firmCounts[$u['firm_name']] ?? 0) + 1;
    }
    if ($u['sociedad_name'] ?? null) {
        $sociedadCounts[$u['sociedad_name']] = ($sociedadCounts[$u['sociedad_name']] ?? 0) + 1;
    }
}

function fmtDate(string $dt): string { return date('d M Y', strtotime($dt)); }

require_once __DIR__ . '/partials/layout_header.php';
?>

<div x-data="assignmentsPage()" x-cloak>

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
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
    <p class="text-sm text-muted">Asigna sociedad, firma, área y cargo a cada usuario del sistema.</p>
    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
        <!-- Filter -->
        <select x-model="filter" class="text-sm border border-gray-200 rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
            <option value="all">Todos</option>
            <option value="assigned">Con asignación</option>
            <option value="unassigned">Sin asignación</option>
            <?php foreach ($sociedades as $soc): ?>
            <option value="sociedad_<?= $soc['id'] ?>">Soc: <?= htmlspecialchars($soc['nombre']) ?></option>
            <?php endforeach; ?>
            <?php foreach ($firms as $f): ?>
            <option value="firm_<?= $f['id'] ?>"><?= htmlspecialchars($f['nombre']) ?></option>
            <?php endforeach; ?>
            <?php foreach ($sedes as $s): ?>
            <option value="sede_<?= $s['id'] ?>">Sede: <?= htmlspecialchars($s['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
        <!-- Search -->
        <div class="relative">
            <svg class="absolute left-3 top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-model="search" placeholder="Buscar usuario..." class="w-full sm:w-48 pl-10 pr-3 py-2 text-sm border border-gray-200 rounded-lg bg-white focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
        </div>
        <?php if (canDo('assignments', 'can_edit')): ?>
        <button @click="openBatch()" x-show="selectedUsers.length > 0" x-transition
                class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-xl text-sm font-medium hover:bg-purple-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Batch (<span x-text="selectedUsers.length"></span>)
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 sm:gap-5 mb-6 sm:mb-8">
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-blue-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
            <div><p class="text-xl sm:text-2xl font-bold text-dark"><?= $totalUsers ?></p><p class="text-[10px] sm:text-xs text-muted">Total Usuarios</p></div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-emerald-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div><p class="text-xl sm:text-2xl font-bold text-dark"><?= $assigned ?></p><p class="text-[10px] sm:text-xs text-muted">Con Asignación</p></div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-amber-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            </div>
            <div><p class="text-xl sm:text-2xl font-bold text-dark"><?= $unassigned ?></p><p class="text-[10px] sm:text-xs text-muted">Sin Asignación</p></div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-corp-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </div>
            <div><p class="text-xl sm:text-2xl font-bold text-dark"><?= $overrideCount ?></p><p class="text-[10px] sm:text-xs text-muted">Override Keeper</p></div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-indigo-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div><p class="text-xl sm:text-2xl font-bold text-dark"><?= count($sociedades) ?></p><p class="text-[10px] sm:text-xs text-muted">Sociedades</p></div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-purple-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </div>
            <div><p class="text-xl sm:text-2xl font-bold text-dark"><?= count($firms) ?></p><p class="text-[10px] sm:text-xs text-muted">Firmas</p></div>
        </div>
    </div>
</div>

<!-- Sociedad + Firm breakdown -->
<?php if (!empty($sociedadCounts) || !empty($firmCounts)): ?>
<div class="flex flex-wrap gap-2 mb-6">
    <?php foreach ($sociedadCounts as $sname => $cnt): ?>
    <span class="px-3 py-1 bg-indigo-50 border border-indigo-200 rounded-full text-xs font-medium text-indigo-700">
        <?= htmlspecialchars($sname) ?> <span class="font-bold"><?= $cnt ?></span>
    </span>
    <?php endforeach; ?>
    <?php foreach ($firmCounts as $fname => $cnt): ?>
    <span class="px-3 py-1 bg-white border border-gray-200 rounded-full text-xs font-medium text-gray-600">
        <?= htmlspecialchars($fname) ?> <span class="font-bold text-dark"><?= $cnt ?></span>
    </span>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Table -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <?php if (canDo('assignments', 'can_edit')): ?>
                    <th class="px-3 py-3 w-10">
                        <input type="checkbox" @change="toggleAll($event)" class="w-4 h-4 rounded border-gray-300 text-corp-800 focus:ring-corp-200">
                    </th>
                    <?php endif; ?>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Usuario</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Sociedad</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Firma</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Área</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Cargo</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Sede</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Fuente</th>
                    <th class="px-2 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Actualizado</th>
                    <th class="px-2 sm:px-5 py-3 text-right text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($users as $idx => $u): ?>
                <tr x-show="matchesFilter(<?= $idx ?>)"
                    class="hover:bg-gray-50/50 transition-colors <?= !$u['assignment_id'] ? 'bg-amber-50/30' : '' ?>">
                    <?php if (canDo('assignments', 'can_edit')): ?>
                    <td class="px-3 py-3.5">
                        <input type="checkbox" value="<?= $u['user_id'] ?>" x-model="selectedUsers" class="w-4 h-4 rounded border-gray-300 text-corp-800 focus:ring-corp-200">
                    </td>
                    <?php endif; ?>
                    <!-- Usuario -->
                    <td class="px-2 sm:px-5 py-3.5">
                        <div class="min-w-0">
                            <p class="font-medium text-dark truncate"><?= htmlspecialchars($u['display_name'] ?? '') ?></p>
                            <p class="text-xs text-muted truncate"><?= htmlspecialchars($u['email'] ?? '') ?></p>
                            <p class="text-xs text-gray-400 truncate md:hidden"><?= htmlspecialchars($u['area_name'] ?? '') ?></p>
                        </div>
                    </td>
                    <!-- Sociedad -->
                    <td class="px-2 sm:px-5 py-3.5 hidden md:table-cell">
                        <?php if ($u['sociedad_name'] ?? null): ?>
                        <span class="text-indigo-700 font-medium"><?= htmlspecialchars($u['sociedad_name']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- Firma -->
                    <td class="px-2 sm:px-5 py-3.5">
                        <?php if ($u['firm_name']): ?>
                        <span class="text-dark font-medium"><?= htmlspecialchars($u['firm_name']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">Sin firma</span>
                        <?php endif; ?>
                    </td>
                    <!-- Área -->
                    <td class="px-2 sm:px-5 py-3.5 hidden md:table-cell">
                        <?php if ($u['area_name']): ?>
                        <span class="text-gray-600"><?= htmlspecialchars($u['area_name']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- Cargo -->
                    <td class="px-2 sm:px-5 py-3.5 hidden lg:table-cell">
                        <?php if ($u['cargo_name']): ?>
                        <span class="text-gray-600"><?= htmlspecialchars($u['cargo_name']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- Sede -->
                    <td class="px-2 sm:px-5 py-3.5 hidden lg:table-cell">
                        <?php if (!empty($u['sede_name'])): ?>
                        <span class="text-gray-600"><?= htmlspecialchars($u['sede_name']) ?></span>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- Fuente -->
                    <td class="px-2 sm:px-5 py-3.5 hidden lg:table-cell">
                        <?php if (!$u['assignment_id']): ?>
                        <span class="text-gray-400 text-xs">—</span>
                        <?php elseif ($u['manual_override']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-blue-50 text-blue-700 text-[10px] font-bold rounded-full uppercase">Keeper</span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-500 text-[10px] font-bold rounded-full uppercase">Legacy</span>
                        <?php endif; ?>
                    </td>
                    <!-- Actualizado -->
                    <td class="px-2 sm:px-5 py-3.5 text-gray-500 text-xs hidden md:table-cell">
                        <?= $u['assignment_updated'] ? fmtDate($u['assignment_updated']) : '—' ?>
                    </td>
                    <!-- Acciones -->
                    <td class="px-2 sm:px-5 py-3.5 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <?php if (canDo('assignments', 'can_edit')): ?>
                            <button @click="openEdit(<?= $idx ?>)" title="Editar asignación"
                                    class="p-1.5 text-gray-400 hover:text-corp-800 hover:bg-corp-50 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if ($u['assignment_id'] && $u['manual_override'] && canDo('assignments', 'can_edit')): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Restaurar a datos legacy? Se quitará el override manual.')">
                                <input type="hidden" name="action" value="reset_override">
                                <input type="hidden" name="assignment_id" value="<?= $u['assignment_id'] ?>">
                                <button type="submit" title="Restaurar a Legacy"
                                        class="p-1.5 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                            <?php if ($u['assignment_id'] && canDo('assignments', 'can_edit')): ?>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la asignación de <?= htmlspecialchars($u['display_name'] ?? '') ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="assignment_id" value="<?= $u['assignment_id'] ?>">
                                <button type="submit" title="Eliminar asignación"
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

    <?php if (empty($users)): ?>
    <div class="text-center py-16">
        <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
        <h3 class="mt-3 text-sm font-medium text-dark">Sin usuarios</h3>
        <p class="mt-1 text-sm text-muted">No hay usuarios en tu alcance.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Editar Asignación Individual
     ═══════════════════════════════════════════════════════ -->
<div x-show="showModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="showModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-dark">Editar Asignación</h3>
                <button @click="showModal=false" class="p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <form method="post" class="p-6 space-y-5">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="keeper_user_id" :value="form.user_id">

            <!-- User (readonly) -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Usuario</label>
                <p class="px-3 py-2 bg-gray-50 rounded-lg text-sm text-dark" x-text="form.display_name"></p>
            </div>

            <!-- Sociedad -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Sociedad</label>
                <select name="sociedad_id" x-model="form.sociedad_id"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Sin sociedad —</option>
                    <?php foreach ($sociedades as $soc): ?>
                    <option value="<?= $soc['id'] ?>"><?= htmlspecialchars($soc['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Firma -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Firma</label>
                <select name="firm_id" x-model="form.firm_id"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Sin firma —</option>
                    <?php foreach ($firms as $f): ?>
                    <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Cargo -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Cargo</label>
                <select name="cargo_id" x-model="form.cargo_id"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Sin cargo —</option>
                    <?php foreach ($cargos as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Área -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Área</label>
                <select name="area_id" x-model="form.area_id"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Sin área —</option>
                    <?php foreach ($areas as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sede -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Sede</label>
                <select name="sede_id" x-model="form.sede_id"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— Sin sede —</option>
                    <?php foreach ($sedes as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="button" @click="showModal=false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Batch Assign
     ═══════════════════════════════════════════════════════ -->
<div x-show="showBatch" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="showBatch=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-dark">Asignación Masiva</h3>
                <button @click="showBatch=false" class="p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <form method="post" class="p-6 space-y-5">
            <input type="hidden" name="action" value="batch_assign">
            <template x-for="uid in selectedUsers" :key="uid">
                <input type="hidden" name="batch_users[]" :value="uid">
            </template>

            <div class="bg-purple-50 border border-purple-200 rounded-lg px-4 py-3">
                <p class="text-sm text-purple-800 font-medium"><span x-text="selectedUsers.length"></span> usuario(s) seleccionado(s)</p>
                <p class="text-xs text-purple-600 mt-0.5">Solo se actualizarán los campos que selecciones (los vacíos no se modifican en registros existentes).</p>
            </div>

            <!-- Sociedad -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Sociedad</label>
                <select name="sociedad_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— No cambiar —</option>
                    <option value="clear" class="text-red-500">— Sin sociedad —</option>
                    <?php foreach ($sociedades as $soc): ?>
                    <option value="<?= $soc['id'] ?>"><?= htmlspecialchars($soc['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Firma -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Firma</label>
                <select name="firm_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— No cambiar —</option>
                    <option value="clear" class="text-red-500">— Sin firma —</option>
                    <?php foreach ($firms as $f): ?>
                    <option value="<?= $f['id'] ?>"><?= htmlspecialchars($f['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Cargo -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Cargo</label>
                <select name="cargo_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— No cambiar —</option>
                    <option value="clear" class="text-red-500">— Sin cargo —</option>
                    <?php foreach ($cargos as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Área -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Área</label>
                <select name="area_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— No cambiar —</option>
                    <option value="clear" class="text-red-500">— Sin área —</option>
                    <?php foreach ($areas as $a): ?>
                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Sede -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Sede</label>
                <select name="sede_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <option value="">— No cambiar —</option>
                    <option value="clear" class="text-red-500">— Sin sede —</option>
                    <?php foreach ($sedes as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="button" @click="showBatch=false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-purple-600 text-white text-sm font-medium rounded-lg hover:bg-purple-700 transition-colors">Aplicar a Seleccionados</button>
            </div>
        </form>
    </div>
</div>

</div><!-- /x-data -->

<!-- Alpine.js logic -->
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('assignmentsPage', () => ({
        showModal: false,
        showBatch: false,
        filter: 'all',
        search: '',
        selectedUsers: [],

        form: {
            user_id: '',
            display_name: '',
            sociedad_id: '',
            firm_id: '',
            area_id: '',
            cargo_id: '',
            sede_id: '',
        },

        users: <?= json_encode(array_values($users), JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        openEdit(idx) {
            const u = this.users[idx];
            if (!u) return;
            this.form = {
                user_id: u.user_id,
                display_name: u.display_name + ' (' + u.email + ')',
                sociedad_id: u.sociedad_id || '',
                firm_id: u.firm_id || '',
                area_id: u.area_id || '',
                cargo_id: u.cargo_id || '',
                sede_id: u.sede_id || '',
            };
            this.showModal = true;
        },

        openBatch() {
            this.showBatch = true;
        },

        toggleAll(event) {
            if (event.target.checked) {
                this.selectedUsers = this.users
                    .filter((_, i) => this.matchesFilter(i))
                    .map(u => String(u.user_id));
            } else {
                this.selectedUsers = [];
            }
        },

        matchesFilter(idx) {
            const u = this.users[idx];
            if (!u) return false;

            // Text search
            if (this.search) {
                const q = this.search.toLowerCase();
                const haystack = ((u.display_name || '') + ' ' + (u.email || '') + ' ' + (u.sociedad_name || '') + ' ' + (u.firm_name || '') + ' ' + (u.area_name || '') + ' ' + (u.cargo_name || '')).toLowerCase();
                if (!haystack.includes(q)) return false;
            }

            // Filter
            switch (this.filter) {
                case 'assigned':   return !!u.assignment_id;
                case 'unassigned': return !u.assignment_id;
                default:
                    if (this.filter.startsWith('sociedad_')) {
                        const socid = this.filter.replace('sociedad_', '');
                        return String(u.sociedad_id) === socid;
                    }
                    if (this.filter.startsWith('firm_')) {
                        const fid = this.filter.replace('firm_', '');
                        return String(u.firm_id) === fid;
                    }
                    if (this.filter.startsWith('sede_')) {
                        const sid = this.filter.replace('sede_', '');
                        return String(u.sede_id) === sid;
                    }
                    return true;
            }
        },
    }));
});
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
