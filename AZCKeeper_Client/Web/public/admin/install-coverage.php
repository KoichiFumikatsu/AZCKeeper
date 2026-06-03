<?php
/**
 * Cobertura de instalación — checklist de empleados activos en BD legacy
 * que no tienen instalación correcta de Keeper.
 *
 * Cruza pipezafra_soporte_db.employee (exit_status=0) con keeper_users +
 * keeper_devices + keeper_client_releases y clasifica cada empleado en uno
 * de 4 motivos (never_enrolled, no_active_device, stale_heartbeat,
 * outdated_version). Permite anotación manual por empleado.
 */
require_once __DIR__ . '/admin_auth.php';

use Keeper\Db;

requireModule('install_coverage');

$pageTitle   = 'Cobertura de instalación';
$currentPage = 'install_coverage';
$msg     = '';
$msgType = '';

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'update_note':
                if (!canDo('install_coverage', 'can_edit')) throw new \Exception('Sin permisos');
                $eid  = (int)($_POST['legacy_employee_id'] ?? 0);
                $note = trim((string)($_POST['note_text'] ?? ''));
                if ($eid <= 0) throw new \Exception('Empleado inválido');
                if ($note === '') {
                    $pdo->prepare("DELETE FROM keeper_install_coverage_notes WHERE legacy_employee_id = :eid")
                        ->execute(['eid' => $eid]);
                    $msg = 'Nota eliminada.';
                } else {
                    $st = $pdo->prepare("
                        INSERT INTO keeper_install_coverage_notes (legacy_employee_id, note_text, updated_by)
                        VALUES (:eid, :note, :uid)
                        ON DUPLICATE KEY UPDATE note_text = :note2, updated_by = :uid2, updated_at = NOW()
                    ");
                    $st->execute([
                        'eid'   => $eid,
                        'note'  => $note,
                        'note2' => $note,
                        'uid'   => $adminUser['admin_id'] ?? null,
                        'uid2'  => $adminUser['admin_id'] ?? null,
                    ]);
                    $msg = 'Nota guardada.';
                }
                $msgType = 'success';
                break;

            case 'toggle_exempt':
                if (!canDo('install_coverage', 'can_edit')) throw new \Exception('Sin permisos');
                $eid = (int)($_POST['legacy_employee_id'] ?? 0);
                $newValue = (int)($_POST['is_exempt'] ?? 0) === 1 ? 1 : 0;
                if ($eid <= 0) throw new \Exception('Empleado inválido');
                $st = $pdo->prepare("
                    INSERT INTO keeper_install_coverage_notes (legacy_employee_id, note_text, is_exempt, updated_by)
                    VALUES (:eid, NULL, :ex, :uid)
                    ON DUPLICATE KEY UPDATE is_exempt = :ex2, updated_by = :uid2, updated_at = NOW()
                ");
                $st->execute([
                    'eid'  => $eid,
                    'ex'   => $newValue,
                    'ex2'  => $newValue,
                    'uid'  => $adminUser['admin_id'] ?? null,
                    'uid2' => $adminUser['admin_id'] ?? null,
                ]);
                $msg = $newValue ? 'Empleado marcado como excepción.' : 'Excepción retirada.';
                $msgType = 'success';
                break;

            case 'update_threshold':
                if (!canDo('install_coverage', 'can_edit')) throw new \Exception('Sin permisos');
                $days = (int)($_POST['threshold_days'] ?? 0);
                if ($days < 1 || $days > 365) throw new \Exception('Umbral debe estar entre 1 y 365 días');
                $st = $pdo->prepare("
                    INSERT INTO keeper_panel_settings (setting_key, setting_value, updated_by)
                    VALUES ('install_coverage_heartbeat_days', :val, :uid)
                    ON DUPLICATE KEY UPDATE setting_value = :val2, updated_by = :uid2, updated_at = NOW()
                ");
                $st->execute([
                    'val'  => (string)$days,
                    'val2' => (string)$days,
                    'uid'  => $adminUser['admin_id'] ?? null,
                    'uid2' => $adminUser['admin_id'] ?? null,
                ]);
                $msg = "Umbral actualizado a {$days} día(s).";
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
        header("Location: install-coverage.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    }
}

if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== DATOS ==================== */

// 1) Umbral configurado (días)
$thresholdDays = 7;
try {
    $st = $pdo->prepare("SELECT setting_value FROM keeper_panel_settings WHERE setting_key = 'install_coverage_heartbeat_days' LIMIT 1");
    $st->execute();
    $row = $st->fetch(\PDO::FETCH_ASSOC);
    if ($row && is_numeric($row['setting_value'])) {
        $thresholdDays = max(1, (int)$row['setting_value']);
    }
} catch (\Throwable $e) { /* default 7 */ }

// 2) Snapshot Keeper: usuarios + último heartbeat + versión cliente
$keeperRows = $pdo->query("
    SELECT
        ku.id                                                    AS keeper_user_id,
        ku.legacy_employee_id,
        ku.email,
        ku.display_name,
        MAX(kd.last_seen_at)                                     AS last_seen_at,
        MAX(CASE WHEN kd.status='active' THEN kd.client_version END) AS latest_client_version,
        SUM(CASE WHEN kd.status='active' THEN 1 ELSE 0 END)      AS active_devices
    FROM keeper_users ku
    LEFT JOIN keeper_devices kd ON kd.user_id = ku.id
    WHERE ku.legacy_employee_id IS NOT NULL
    GROUP BY ku.id
")->fetchAll(PDO::FETCH_ASSOC);

$byLegacyId = [];
foreach ($keeperRows as $r) {
    $byLegacyId[(int)$r['legacy_employee_id']] = $r;
}

// 3) Versión de release activo más reciente
$latestRelease = $pdo->query(
    "SELECT version FROM keeper_client_releases WHERE is_active = 1 ORDER BY id DESC LIMIT 1"
)->fetchColumn() ?: null;

// 4) Catálogos para nombrar sede/área/cargo/firma — mapeados por legacy_*_id
//    porque employee.{company,area_id,position_id,sede_id} usan los IDs legacy,
//    mientras que keeper_*.id es la PK propia de Keeper.
$sedes  = $pdo->query("SELECT legacy_sede_id  AS lid, nombre FROM keeper_sedes  WHERE legacy_sede_id  IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$areas  = $pdo->query("SELECT legacy_area_id  AS lid, nombre FROM keeper_areas  WHERE legacy_area_id  IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$cargos = $pdo->query("SELECT legacy_cargo_id AS lid, nombre FROM keeper_cargos WHERE legacy_cargo_id IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);
$firmas = $pdo->query("SELECT legacy_firm_id  AS lid, nombre FROM keeper_firmas WHERE legacy_firm_id  IS NOT NULL")->fetchAll(PDO::FETCH_KEY_PAIR);

// 5) Empleados activos en legacy
$legacyPdo = Db::legacyPdo();
$legacyRows = [];
$legacyError = null;
try {
    $legacyRows = $legacyPdo->query("
        SELECT id, cc, first_Name, second_Name, first_LastName, second_LastName,
               mail, personal_mail, company, area_id, position_id, sede_id
        FROM employee
        WHERE exit_status = 0
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $legacyError = $e->getMessage();
}

// 6) Notas y excepciones existentes
$notesByEid  = [];
$exemptByEid = [];
try {
    foreach ($pdo->query("SELECT legacy_employee_id, note_text, is_exempt FROM keeper_install_coverage_notes")->fetchAll(PDO::FETCH_ASSOC) as $n) {
        $eid = (int)$n['legacy_employee_id'];
        $notesByEid[$eid] = $n['note_text'] ?? '';
        if ((int)$n['is_exempt'] === 1) $exemptByEid[$eid] = true;
    }
} catch (\Throwable $e) { /* tabla aún sin is_exempt o sin migración — degradación */ }

// 7) Scope filter cross-DB: empleados que el admin puede ver según su scope
//    (los "nunca enrolados" no están en keeper_user_assignments, por eso filtramos
//    contra columnas legacy directo)
$canSeeLegacy = function (array $emp) use ($adminUser): bool {
    if (($adminUser['panel_role'] ?? '') === 'superadmin') return true;
    if (!empty($adminUser['firm_scope_id'])     && (int)$emp['company']   !== (int)$adminUser['firm_scope_id'])    return false;
    if (!empty($adminUser['area_scope_id'])     && (int)$emp['area_id']   !== (int)$adminUser['area_scope_id'])    return false;
    if (!empty($adminUser['sede_scope_id'])     && (int)$emp['sede_id']   !== (int)$adminUser['sede_scope_id'])    return false;
    // sociedad no tiene columna directa en employee — se ignora (queda al filtro de firma)
    return true;
};

// 8) Clasificar
$thresholdTs = strtotime("-{$thresholdDays} days");
$rows = [];
$counts = ['never_enrolled' => 0, 'no_active_device' => 0, 'stale_heartbeat' => 0, 'outdated_version' => 0, 'exempt' => 0];

foreach ($legacyRows as $emp) {
    if (!$canSeeLegacy($emp)) continue;

    $eid = (int)$emp['id'];
    $k   = $byLegacyId[$eid] ?? null;
    $isExempt = isset($exemptByEid[$eid]);

    $reason = null;
    if (!$k) {
        $reason = 'never_enrolled';
    } elseif ((int)$k['active_devices'] === 0) {
        $reason = 'no_active_device';
    } elseif (!$k['last_seen_at'] || strtotime($k['last_seen_at']) < $thresholdTs) {
        $reason = 'stale_heartbeat';
    } elseif ($latestRelease && !empty($k['latest_client_version']) && version_compare($k['latest_client_version'], $latestRelease, '<')) {
        $reason = 'outdated_version';
    }

    // Empleado OK y NO exempt → no se muestra
    if ($reason === null && !$isExempt) continue;

    // Si es exempt, sobrescribe el motivo (tiene prioridad visual)
    // pero conservamos el motivo original para auditoría futura.
    $originalReason = $reason;
    if ($isExempt) $reason = 'exempt';
    $counts[$reason]++;

    $fullName = trim(implode(' ', array_filter([
        $emp['first_Name'], $emp['second_Name'], $emp['first_LastName'], $emp['second_LastName']
    ])));

    $rows[] = [
        'legacy_employee_id' => $eid,
        'cc'                 => $emp['cc'],
        'name'               => $fullName ?: ('Empleado #' . $eid),
        'mail'               => $emp['mail'] ?: ($emp['personal_mail'] ?: null),
        'firma'              => $firmas[(int)$emp['company']]    ?? null,
        'sede'               => $sedes[(int)$emp['sede_id']]     ?? null,
        'area'               => $areas[(int)$emp['area_id']]     ?? null,
        'cargo'              => $cargos[(int)$emp['position_id']] ?? null,
        'reason'             => $reason,
        'original_reason'    => $originalReason,
        'is_exempt'          => $isExempt,
        'last_seen_at'       => $k['last_seen_at'] ?? null,
        'client_version'     => $k['latest_client_version'] ?? null,
        'note'               => $notesByEid[$eid] ?? '',
    ];
}

// Ordenar por gravedad: motivos activos primero, excepciones al final
$reasonOrder = ['never_enrolled' => 0, 'no_active_device' => 1, 'stale_heartbeat' => 2, 'outdated_version' => 3, 'exempt' => 9];
usort($rows, function ($a, $b) use ($reasonOrder) {
    $ra = $reasonOrder[$a['reason']] ?? 9;
    $rb = $reasonOrder[$b['reason']] ?? 9;
    if ($ra !== $rb) return $ra - $rb;
    return strcmp($a['name'], $b['name']);
});

$reasonLabels = [
    'never_enrolled'   => ['label' => 'Nunca enrolado',      'bg' => 'bg-red-50',     'text' => 'text-red-700',     'dot' => 'bg-red-500'],
    'no_active_device' => ['label' => 'Sin dispositivo',     'bg' => 'bg-orange-50',  'text' => 'text-orange-700',  'dot' => 'bg-orange-500'],
    'stale_heartbeat'  => ['label' => 'Sin marcar reciente', 'bg' => 'bg-amber-50',   'text' => 'text-amber-700',   'dot' => 'bg-amber-500'],
    'outdated_version' => ['label' => 'Versión obsoleta',    'bg' => 'bg-blue-50',    'text' => 'text-blue-700',    'dot' => 'bg-blue-500'],
    'exempt'           => ['label' => 'Excepción',           'bg' => 'bg-gray-100',   'text' => 'text-gray-600',    'dot' => 'bg-gray-400'],
];

// Lista única de sedes (para filtro)
$sedeFilter = [];
foreach ($rows as $r) {
    if ($r['sede']) $sedeFilter[$r['sede']] = true;
}
$sedeFilter = array_keys($sedeFilter);
sort($sedeFilter);

$totalRows = count($rows);

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Flash message -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium <?= $msgType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<?php if ($legacyError): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium bg-red-50 text-red-700 border border-red-200">
    BD legacy no disponible: <?= htmlspecialchars($legacyError) ?>. Solo se muestra info de Keeper.
</div>
<?php endif; ?>

<!-- KPI Cards: 1 contador por motivo + excepciones -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
    <?php foreach (['never_enrolled', 'no_active_device', 'stale_heartbeat', 'outdated_version', 'exempt'] as $r):
        $rl = $reasonLabels[$r];
    ?>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 <?= $rl['bg'] ?> rounded-lg flex items-center justify-center">
                <span class="w-3 h-3 rounded-full <?= $rl['dot'] ?>"></span>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $counts[$r] ?></p>
                <p class="text-xs text-muted"><?= $rl['label'] ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Configuración del umbral -->
<?php if (canDo('install_coverage', 'can_edit')): ?>
<div class="mb-6 bg-white rounded-xl border border-gray-100 p-5">
    <form method="post" class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <input type="hidden" name="action" value="update_threshold">
        <div class="flex items-center gap-2 flex-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <label class="text-sm font-medium text-dark">Umbral de inactividad (días sin marcar):</label>
        </div>
        <div class="flex items-center gap-2">
            <input type="number" name="threshold_days" value="<?= $thresholdDays ?>" min="1" max="365"
                   class="w-24 px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
            <button type="submit" class="px-4 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors">
                Aplicar
            </button>
        </div>
    </form>
</div>
<?php endif; ?>

<div x-data="{
    search: '',
    reasonFilters: { never_enrolled: true, no_active_device: true, stale_heartbeat: true, outdated_version: true, exempt: false },
    sedeFilter: 'all',
    visibleCount: <?= $totalRows ?>,
    noteModal: { open: false, eid: 0, name: '', text: '' },
    openNote(eid, name, text) { this.noteModal = { open: true, eid, name, text: text || '' }; },
    closeNote() { this.noteModal.open = false; },
    applyFilters() {
        const rows = this.$refs.coverageTable.querySelectorAll('tr[data-row]');
        let count = 0;
        rows.forEach(r => {
            const text   = r.dataset.search || '';
            const reason = r.dataset.reason || '';
            const sede   = r.dataset.sede || '';
            const matchSearch = !this.search || text.includes(this.search.toLowerCase());
            const matchReason = !!this.reasonFilters[reason];
            const matchSede   = this.sedeFilter === 'all' || sede === this.sedeFilter;
            const show = matchSearch && matchReason && matchSede;
            r.style.display = show ? '' : 'none';
            if (show) count++;
        });
        this.visibleCount = count;
    }
}" x-effect="applyFilters()">

<!-- Filtros -->
<div class="mb-6 flex flex-col gap-3">
    <div class="flex flex-col sm:flex-row items-start sm:items-center gap-3">
        <div class="relative flex-1 max-w-md">
            <svg class="w-4 h-4 text-muted absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-model.debounce.200ms="search" placeholder="Buscar por nombre, cédula, correo, sede…"
                   class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none transition-all placeholder:text-muted">
        </div>
        <select x-model="sedeFilter" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
            <option value="all">Todas las sedes</option>
            <?php foreach ($sedeFilter as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="text-xs text-muted">
            <span x-text="visibleCount"></span> de <?= $totalRows ?> resultado(s)
        </span>
    </div>

    <!-- Chips de motivos (excepción off por default) -->
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs text-muted font-medium uppercase tracking-wider">Mostrar:</span>
        <?php foreach (['never_enrolled', 'no_active_device', 'stale_heartbeat', 'outdated_version', 'exempt'] as $r):
            $rl = $reasonLabels[$r];
        ?>
        <label class="inline-flex items-center gap-2 px-3 py-1 <?= $rl['bg'] ?> rounded-full cursor-pointer text-xs font-medium <?= $rl['text'] ?> select-none"
               :class="reasonFilters.<?= $r ?> ? 'opacity-100' : 'opacity-40'">
            <input type="checkbox" x-model="reasonFilters.<?= $r ?>" class="hidden">
            <span class="w-1.5 h-1.5 rounded-full <?= $rl['dot'] ?>"></span>
            <?= $rl['label'] ?> (<?= $counts[$r] ?>)
        </label>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tabla -->
<div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6">
    <div class="flex items-center gap-2 mb-4">
        <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <h3 class="text-base font-bold text-dark">Empleados sin cobertura</h3>
        <span class="text-xs text-muted">(<?= $totalRows ?>)</span>
    </div>

    <div class="overflow-x-auto" x-ref="coverageTable">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Empleado</th>
                    <th class="hidden md:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Sede / Cargo</th>
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Motivo</th>
                    <th class="hidden lg:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Último heartbeat</th>
                    <th class="hidden lg:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Versión</th>
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Nota</th>
                    <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Excepción</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php if (empty($rows)): ?>
                <tr><td colspan="7" class="py-8 text-center text-sm text-muted">No hay empleados sin cobertura. 🎉</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r):
                    $rl = $reasonLabels[$r['reason']];
                    $searchData = strtolower($r['name'] . ' ' . ($r['cc'] ?? '') . ' ' . ($r['mail'] ?? '') . ' ' . ($r['sede'] ?? '') . ' ' . ($r['firma'] ?? '') . ' ' . ($r['cargo'] ?? '') . ' ' . ($r['note'] ?? ''));
                ?>
                <tr class="<?= $r['is_exempt'] ? 'bg-gray-50/50 opacity-70' : 'hover:bg-gray-50/50' ?> transition-colors"
                    data-row
                    data-reason="<?= $r['reason'] ?>"
                    data-sede="<?= htmlspecialchars($r['sede'] ?? '') ?>"
                    data-search="<?= htmlspecialchars($searchData) ?>">
                    <td class="py-3 px-3">
                        <p class="font-medium text-dark"><?= htmlspecialchars($r['name']) ?></p>
                        <p class="text-[10px] text-muted mt-0.5">
                            CC <?= htmlspecialchars($r['cc'] ?? '—') ?>
                            <?php if ($r['mail']): ?> · <?= htmlspecialchars($r['mail']) ?><?php endif; ?>
                        </p>
                    </td>
                    <td class="hidden md:table-cell py-3 px-3">
                        <p class="text-dark"><?= htmlspecialchars($r['sede'] ?? '—') ?></p>
                        <p class="text-[10px] text-muted mt-0.5">
                            <?= htmlspecialchars($r['cargo'] ?? '') ?>
                            <?php if ($r['firma'] && $r['cargo']): ?> · <?php endif; ?>
                            <?= htmlspecialchars($r['firma'] ?? '') ?>
                        </p>
                    </td>
                    <td class="py-3 px-3">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 <?= $rl['bg'] ?> <?= $rl['text'] ?> text-xs font-medium rounded-full whitespace-nowrap">
                            <span class="w-1.5 h-1.5 rounded-full <?= $rl['dot'] ?>"></span>
                            <?= $rl['label'] ?>
                        </span>
                    </td>
                    <td class="hidden lg:table-cell py-3 px-3 text-xs text-muted whitespace-nowrap">
                        <?= $r['last_seen_at'] ? date('d/m/Y H:i', strtotime($r['last_seen_at'])) : '—' ?>
                    </td>
                    <td class="hidden lg:table-cell py-3 px-3">
                        <?php if ($r['client_version']): ?>
                        <span class="inline-flex items-center px-2 py-0.5 bg-corp-50 text-corp-800 text-xs font-medium rounded-full">
                            v<?= htmlspecialchars($r['client_version']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3">
                        <?php if (canDo('install_coverage', 'can_edit')): ?>
                        <button type="button"
                                data-eid="<?= $r['legacy_employee_id'] ?>"
                                data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                                data-note="<?= htmlspecialchars($r['note'] ?? '', ENT_QUOTES) ?>"
                                @click="openNote(parseInt($el.dataset.eid), $el.dataset.name, $el.dataset.note)"
                                class="text-left text-xs <?= $r['note'] ? 'text-dark' : 'text-corp-800 italic' ?> hover:underline max-w-xs truncate block">
                            <?= $r['note'] ? htmlspecialchars($r['note']) : 'Agregar nota' ?>
                        </button>
                        <?php else: ?>
                        <span class="text-xs text-muted"><?= $r['note'] ? htmlspecialchars($r['note']) : '—' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3 text-right">
                        <?php if (canDo('install_coverage', 'can_edit')): ?>
                        <form method="post" class="inline">
                            <input type="hidden" name="action" value="toggle_exempt">
                            <input type="hidden" name="legacy_employee_id" value="<?= $r['legacy_employee_id'] ?>">
                            <input type="hidden" name="is_exempt" value="<?= $r['is_exempt'] ? 0 : 1 ?>">
                            <?php if ($r['is_exempt']): ?>
                            <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-full transition-colors" title="Quitar excepción">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                Quitar
                            </button>
                            <?php else: ?>
                            <button type="submit" class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-full transition-colors" title="No aplica Keeper (gerencia, servicio, etc.)">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                Marcar
                            </button>
                            <?php endif; ?>
                        </form>
                        <?php else: ?>
                        <span class="text-xs text-muted"><?= $r['is_exempt'] ? 'Excepción' : '—' ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de nota -->
<div x-show="noteModal.open" x-transition.opacity
     class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4"
     @click.self="closeNote()" style="display:none">
    <div class="bg-white rounded-xl shadow-2xl w-full max-w-lg p-6" @keydown.escape.window="closeNote()">
        <form method="post" @submit="closeNote()">
            <input type="hidden" name="action" value="update_note">
            <input type="hidden" name="legacy_employee_id" :value="noteModal.eid">

            <h3 class="text-lg font-bold text-dark mb-1">Nota de instalación</h3>
            <p class="text-xs text-muted mb-4" x-text="noteModal.name"></p>

            <textarea name="note_text" x-model="noteModal.text" rows="5"
                      placeholder="Ej: PC en proceso de cambio, equipo prestado, pendiente RR.HH., etc."
                      class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-none"></textarea>

            <p class="text-[11px] text-muted mt-2">Deja vacío para eliminar la nota.</p>

            <div class="mt-5 flex items-center justify-end gap-2">
                <button type="button" @click="closeNote()" class="px-4 py-2 text-sm font-medium text-muted hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-4 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors">Guardar</button>
            </div>
        </form>
    </div>
</div>

</div><!-- /x-data -->

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
