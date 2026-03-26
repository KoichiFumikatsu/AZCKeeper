<?php
/**
 * Dispositivos — listado de keeper_devices con info del usuario asociado.
 * Permite ver, revocar y eliminar dispositivos.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('devices');

$pageTitle   = 'Dispositivos';
$currentPage = 'devices';
$msg     = '';
$msgType = '';

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {
            case 'revoke':
                if (!canDo('devices', 'can_edit')) throw new \Exception('Sin permisos');
                $deviceId = (int)($_POST['device_id'] ?? 0);
                if ($deviceId <= 0) throw new \Exception('Dispositivo inválido');
                $pdo->prepare("UPDATE keeper_devices SET status = 'revoked' WHERE id = ?")->execute([$deviceId]);
                $msg = 'Dispositivo revocado.';
                $msgType = 'success';
                break;

            case 'activate':
                if (!canDo('devices', 'can_edit')) throw new \Exception('Sin permisos');
                $deviceId = (int)($_POST['device_id'] ?? 0);
                if ($deviceId <= 0) throw new \Exception('Dispositivo inválido');
                $pdo->prepare("UPDATE keeper_devices SET status = 'active' WHERE id = ?")->execute([$deviceId]);
                $msg = 'Dispositivo reactivado.';
                $msgType = 'success';
                break;

            case 'delete':
                if (!canDo('devices', 'can_delete')) throw new \Exception('Sin permisos');
                $deviceId = (int)($_POST['device_id'] ?? 0);
                if ($deviceId <= 0) throw new \Exception('Dispositivo inválido');
                $pdo->prepare("DELETE FROM keeper_devices WHERE id = ?")->execute([$deviceId]);
                $msg = 'Dispositivo eliminado permanentemente.';
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
        header("Location: devices.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    }
}

if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== DATOS ==================== */
$scope  = scopeFilter();
$params = $scope['params'];

$sql = "
    SELECT
        d.id,
        d.user_id,
        d.device_guid,
        d.device_name,
        d.client_version,
        d.serial_hint,
        d.status,
        d.last_seen_at,
        d.created_at,
        u.display_name,
        u.cc,
        u.email,
        f.nombre AS firm_name,
        ar.nombre AS area_name,
        s.nombre AS sede_name,
        soc.nombre AS sociedad_name
    FROM keeper_devices d
    INNER JOIN keeper_users u ON u.id = d.user_id
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    LEFT JOIN keeper_sociedades soc ON soc.id = ua.sociedad_id
    LEFT JOIN keeper_firmas f ON f.id = ua.firm_id
    LEFT JOIN keeper_areas ar ON ar.id = ua.area_id
    LEFT JOIN keeper_sedes s ON s.id = ua.sede_id
    WHERE 1=1 {$scope['sql']}
    ORDER BY d.last_seen_at DESC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$devices = $st->fetchAll(PDO::FETCH_ASSOC);

// Stats
$totalDevices = count($devices);
$activeCount  = count(array_filter($devices, fn($d) => $d['status'] === 'active'));
$revokedCount = $totalDevices - $activeCount;
$onlineCount  = count(array_filter($devices, fn($d) => $d['last_seen_at'] && (time() - strtotime($d['last_seen_at'])) < 120));

// Version breakdown
$versionMap = [];
foreach ($devices as $d) {
    $v = $d['client_version'] ?: 'Sin versión';
    $versionMap[$v] = ($versionMap[$v] ?? 0) + 1;
}
arsort($versionMap);

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Flash message -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-lg text-sm font-medium <?= $msgType === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- KPI Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-corp-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $totalDevices ?></p>
                <p class="text-xs text-muted">Total Dispositivos</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-emerald-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 13l4 4L19 7"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $onlineCount ?></p>
                <p class="text-xs text-muted">Online Ahora</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $activeCount ?></p>
                <p class="text-xs text-muted">Activos</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $revokedCount ?></p>
                <p class="text-xs text-muted">Revocados</p>
            </div>
        </div>
    </div>
</div>

<div x-data="{
    search: '',
    statusFilter: 'all',
    visibleCount: <?= $totalDevices ?>,
    applyFilters() {
        const rows = this.$refs.deviceTable.querySelectorAll('tr[data-device]');
        let count = 0;
        rows.forEach(r => {
            const text = r.dataset.search || '';
            const st   = r.dataset.status || '';
            const matchSearch = !this.search || text.includes(this.search.toLowerCase());
            const matchStatus = this.statusFilter === 'all' || st === this.statusFilter;
            const show = matchSearch && matchStatus;
            r.style.display = show ? '' : 'none';
            if (show) count++;
        });
        this.visibleCount = count;
    }
}" x-effect="applyFilters()">

<!-- Search / Filter -->
<div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center gap-3">
    <div class="relative flex-1 max-w-md">
        <svg class="w-4 h-4 text-muted absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" x-model.debounce.200ms="search" placeholder="Buscar por dispositivo, usuario, firma, sede…"
               class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none transition-all placeholder:text-muted">
    </div>
    <select x-model="statusFilter" class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
        <option value="all">Todos los estados</option>
        <option value="active">Activos</option>
        <option value="revoked">Revocados</option>
    </select>
    <span class="text-xs text-muted" x-show="search || statusFilter !== 'all'" x-transition>
        <span x-text="visibleCount"></span> resultado(s)
    </span>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-5">

<!-- Main Table -->
<div class="lg:col-span-3 bg-white rounded-xl border border-gray-100 p-3 sm:p-6">
    <div class="flex items-center gap-2 mb-4">
        <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        <h3 class="text-base font-bold text-dark">Dispositivos</h3>
        <span class="text-xs text-muted">(<?= $totalDevices ?>)</span>
    </div>

    <div class="overflow-x-auto" x-ref="deviceTable">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Dispositivo</th>
                    <th class="hidden md:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Usuario</th>
                    <th class="hidden lg:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Versión</th>
                    <th class="text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Estado</th>
                    <th class="hidden sm:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Último contacto</th>
                    <th class="hidden lg:table-cell text-left py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Registrado</th>
                    <th class="text-right py-2.5 px-3 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($devices as $d):
                    $seenAgo = $d['last_seen_at'] ? time() - strtotime($d['last_seen_at']) : 99999;
                    if ($d['status'] === 'revoked') { $connStatus = 'Revocado'; $connColor = 'text-red-500'; $connBg = 'bg-red-50'; }
                    elseif ($seenAgo < 120) { $connStatus = 'Online'; $connColor = 'text-emerald-700'; $connBg = 'bg-emerald-50'; }
                    elseif ($seenAgo < 900) { $connStatus = 'Ausente'; $connColor = 'text-amber-700'; $connBg = 'bg-amber-50'; }
                    else { $connStatus = 'Offline'; $connColor = 'text-gray-500'; $connBg = 'bg-gray-50'; }

                    $searchData = strtolower(($d['device_name'] ?? '') . ' ' . ($d['display_name'] ?? '') . ' ' . ($d['email'] ?? '') . ' ' . ($d['client_version'] ?? '') . ' ' . ($d['device_guid'] ?? '') . ' ' . ($d['sociedad_name'] ?? '') . ' ' . ($d['firm_name'] ?? '') . ' ' . ($d['sede_name'] ?? ''));
                ?>
                <tr class="hover:bg-gray-50/50 transition-colors" data-device data-search="<?= htmlspecialchars($searchData) ?>" data-status="<?= $d['status'] ?>">
                    <td class="py-3 px-3">
                        <div>
                            <p class="font-medium text-dark"><?= htmlspecialchars($d['device_name'] ?? 'Sin nombre') ?></p>
                            <p class="text-[10px] text-muted font-mono mt-0.5 hidden sm:block"><?= htmlspecialchars(substr($d['device_guid'], 0, 18)) ?>…</p>
                            <!-- Mobile: show user name under device -->
                            <p class="text-[10px] text-muted mt-0.5 md:hidden"><?= htmlspecialchars($d['display_name'] ?? '--') ?></p>
                        </div>
                    </td>
                    <td class="hidden md:table-cell py-3 px-3">
                        <a href="user-dashboard.php?id=<?= $d['user_id'] ?>" class="hover:text-corp-800 transition-colors">
                            <p class="font-medium text-dark"><?= htmlspecialchars($d['display_name'] ?? '--') ?></p>
                            <p class="text-[10px] text-muted"><?= htmlspecialchars($d['sociedad_name'] ?? '') ?><?= ($d['sociedad_name'] && $d['firm_name']) ? ' · ' : '' ?><?= htmlspecialchars($d['firm_name'] ?? '') ?><?= $d['sede_name'] ? ' · ' . htmlspecialchars($d['sede_name']) : '' ?></p>
                        </a>
                    </td>
                    <td class="hidden lg:table-cell py-3 px-3">
                        <?php if ($d['client_version']): ?>
                        <span class="inline-flex items-center px-2 py-0.5 bg-corp-50 text-corp-800 text-xs font-medium rounded-full">
                            v<?= htmlspecialchars($d['client_version']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-xs text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-3">
                        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 <?= $connBg ?> <?= $connColor ?> text-xs font-medium rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full <?= $d['status'] === 'revoked' ? 'bg-red-400' : ($seenAgo < 120 ? 'bg-emerald-500' : ($seenAgo < 900 ? 'bg-amber-400' : 'bg-gray-400')) ?>"></span>
                            <?= $connStatus ?>
                        </span>
                    </td>
                    <td class="hidden sm:table-cell py-3 px-3 text-xs text-muted whitespace-nowrap">
                        <?= $d['last_seen_at'] ? date('d/m/Y H:i', strtotime($d['last_seen_at'])) : '—' ?>
                    </td>
                    <td class="hidden lg:table-cell py-3 px-3 text-xs text-muted whitespace-nowrap">
                        <?= $d['created_at'] ? date('d/m/Y', strtotime($d['created_at'])) : '—' ?>
                    </td>
                    <td class="py-3 px-3 text-right">
                        <?php if (canDo('devices', 'can_edit')): ?>
                        <div class="flex items-center justify-end gap-1" x-data="{ open: false }">
                            <div class="relative">
                                <button @click="open = !open" class="p-1.5 rounded-lg text-muted hover:text-dark hover:bg-gray-100 transition-colors">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 5v.01M12 12v.01M12 19v.01"/></svg>
                                </button>
                                <div x-show="open" @click.away="open = false" x-transition class="absolute right-0 mt-1 w-44 bg-white rounded-lg shadow-lg border border-gray-100 py-1 z-10" style="display:none">
                                    <?php if ($d['status'] === 'active'): ?>
                                    <form method="post" class="block">
                                        <input type="hidden" name="action" value="revoke">
                                        <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="w-full text-left px-3 py-2 text-xs text-amber-700 hover:bg-amber-50 transition-colors flex items-center gap-2">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            Revocar
                                        </button>
                                    </form>
                                    <?php else: ?>
                                    <form method="post" class="block">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="w-full text-left px-3 py-2 text-xs text-emerald-700 hover:bg-emerald-50 transition-colors flex items-center gap-2">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Reactivar
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php if (canDo('devices', 'can_delete')): ?>
                                    <form method="post" class="block" onsubmit="return confirm('¿Eliminar este dispositivo permanentemente? Se perderán sesiones y registros asociados.')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="w-full text-left px-3 py-2 text-xs text-red-600 hover:bg-red-50 transition-colors flex items-center gap-2">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            Eliminar
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($devices)): ?>
                <tr>
                    <td colspan="7" class="py-8 text-center text-sm text-muted">Sin dispositivos registrados</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Sidebar: Version Breakdown -->
<div class="space-y-5">
    <div class="bg-white rounded-xl border border-gray-100 p-6">
        <div class="flex items-center gap-2 mb-4">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
            <h3 class="text-sm font-bold text-dark">Versiones del Cliente</h3>
        </div>
        <div class="space-y-2.5">
            <?php
            $vColors = ['#003a5d', '#2d87ad', '#198754', '#f59e0b', '#be1622', '#9d9d9c'];
            $vi = 0;
            foreach ($versionMap as $ver => $cnt):
                $pct = $totalDevices > 0 ? round(($cnt / $totalDevices) * 100) : 0;
                $color = $vColors[$vi % count($vColors)];
                $vi++;
            ?>
            <div>
                <div class="flex justify-between items-center text-xs mb-1">
                    <span class="font-medium text-dark"><?= htmlspecialchars($ver) ?></span>
                    <span class="text-muted"><?= $cnt ?> (<?= $pct ?>%)</span>
                </div>
                <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full rounded-full" style="width: <?= $pct ?>%; background: <?= $color ?>;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($versionMap)): ?>
            <p class="text-xs text-muted text-center">Sin datos</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- GUID info -->
    <div class="bg-white rounded-xl border border-gray-100 p-6">
        <div class="flex items-center gap-2 mb-3">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <h3 class="text-sm font-bold text-dark">Info</h3>
        </div>
        <div class="space-y-2 text-xs text-muted">
            <p>Cada dispositivo se identifica por un <b>GUID</b> único generado en la primera instalación del cliente.</p>
            <p><b>Revocar</b> impide que el dispositivo se comunique con el servidor pero conserva los datos.</p>
            <p><b>Eliminar</b> borra el dispositivo y sus sesiones asociadas de forma permanente.</p>
        </div>
    </div>
</div>

</div><!-- /x-data -->

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
