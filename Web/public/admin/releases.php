<?php
/**
 * Releases — Gestión de versiones del cliente AZCKeeper.
 *
 * CRUD completo sobre la tabla `keeper_client_releases`.
 * Permisos: releases (can_view, can_create, can_edit, can_delete).
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('releases');

$pageTitle   = 'Releases';
$currentPage = 'releases';
$msg     = '';
$msgType = '';

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            /* ── Crear release ── */
            case 'create':
                if (!canDo('releases', 'can_create')) throw new \Exception('Sin permisos');

                $version        = trim($_POST['version'] ?? '');
                $downloadUrl    = trim($_POST['download_url'] ?? '');
                $fileSize       = (int)($_POST['file_size'] ?? 0);
                $releaseNotes   = trim($_POST['release_notes'] ?? '');
                $isBeta         = isset($_POST['is_beta']) ? 1 : 0;
                $forceUpdate    = isset($_POST['force_update']) ? 1 : 0;
                $minimumVersion = trim($_POST['minimum_version'] ?? '') ?: null;
                $isActive       = isset($_POST['is_active']) ? 1 : 0;
                $releaseDate    = $_POST['release_date'] ?? date('Y-m-d');

                if ($version === '') throw new \Exception('La versión es obligatoria');
                if ($downloadUrl === '') throw new \Exception('La URL de descarga es obligatoria');

                // Check duplicate version
                $dup = $pdo->prepare("SELECT COUNT(*) FROM keeper_client_releases WHERE version = ?");
                $dup->execute([$version]);
                if ($dup->fetchColumn() > 0) throw new \Exception("La versión {$version} ya existe");

                $st = $pdo->prepare("
                    INSERT INTO keeper_client_releases
                    (version, download_url, file_size, release_notes, is_beta, force_update, minimum_version, is_active, release_date)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $st->execute([$version, $downloadUrl, $fileSize, $releaseNotes, $isBeta, $forceUpdate, $minimumVersion, $isActive, $releaseDate]);

                $msg = "Release {$version} creada.";
                $msgType = 'success';
                break;

            /* ── Actualizar release ── */
            case 'update':
                if (!canDo('releases', 'can_edit')) throw new \Exception('Sin permisos');

                $id             = (int)($_POST['release_id'] ?? 0);
                $version        = trim($_POST['version'] ?? '');
                $downloadUrl    = trim($_POST['download_url'] ?? '');
                $fileSize       = (int)($_POST['file_size'] ?? 0);
                $releaseNotes   = trim($_POST['release_notes'] ?? '');
                $isBeta         = isset($_POST['is_beta']) ? 1 : 0;
                $forceUpdate    = isset($_POST['force_update']) ? 1 : 0;
                $minimumVersion = trim($_POST['minimum_version'] ?? '') ?: null;
                $isActive       = isset($_POST['is_active']) ? 1 : 0;
                $releaseDate    = $_POST['release_date'] ?? date('Y-m-d');

                if ($id <= 0) throw new \Exception('Release inválida');
                if ($version === '') throw new \Exception('La versión es obligatoria');
                if ($downloadUrl === '') throw new \Exception('La URL de descarga es obligatoria');

                // Check duplicate version (excluding self)
                $dup = $pdo->prepare("SELECT COUNT(*) FROM keeper_client_releases WHERE version = ? AND id != ?");
                $dup->execute([$version, $id]);
                if ($dup->fetchColumn() > 0) throw new \Exception("La versión {$version} ya existe en otro registro");

                $st = $pdo->prepare("
                    UPDATE keeper_client_releases SET
                        version = ?, download_url = ?, file_size = ?, release_notes = ?,
                        is_beta = ?, force_update = ?, minimum_version = ?,
                        is_active = ?, release_date = ?
                    WHERE id = ?
                ");
                $st->execute([$version, $downloadUrl, $fileSize, $releaseNotes, $isBeta, $forceUpdate, $minimumVersion, $isActive, $releaseDate, $id]);

                $msg = "Release {$version} actualizada.";
                $msgType = 'success';
                break;

            /* ── Toggle active ── */
            case 'toggle_active':
                if (!canDo('releases', 'can_edit')) throw new \Exception('Sin permisos');

                $id = (int)($_POST['release_id'] ?? 0);
                if ($id <= 0) throw new \Exception('Release inválida');

                $st = $pdo->prepare("UPDATE keeper_client_releases SET is_active = NOT is_active WHERE id = ?");
                $st->execute([$id]);
                $msg = 'Estado actualizado.';
                $msgType = 'success';
                break;

            /* ── Eliminar release (hard delete) ── */
            case 'delete':
                if (!canDo('releases', 'can_delete')) throw new \Exception('Sin permisos');

                $id = (int)($_POST['release_id'] ?? 0);
                if ($id <= 0) throw new \Exception('Release inválida');

                $ver = $pdo->prepare("SELECT version FROM keeper_client_releases WHERE id = ?");
                $ver->execute([$id]);
                $delVersion = $ver->fetchColumn() ?: '#' . $id;

                $pdo->prepare("DELETE FROM keeper_client_releases WHERE id = ?")->execute([$id]);
                $msg = "Release {$delVersion} eliminada.";
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
        header("Location: releases.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    }
}

/* Flash PRG */
if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== QUERIES ==================== */
$releases = $pdo->query("
    SELECT * FROM keeper_client_releases ORDER BY created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* KPIs */
$totalReleases  = count($releases);
$activeReleases = 0;
$betaReleases   = 0;
$latestVersion  = '—';
$forceCount     = 0;

foreach ($releases as $r) {
    if ($r['is_active']) $activeReleases++;
    if ($r['is_beta'])   $betaReleases++;
    if ($r['force_update']) $forceCount++;
}
// Latest = first active non-beta by created_at DESC (already sorted)
foreach ($releases as $r) {
    if ($r['is_active'] && !$r['is_beta']) {
        $latestVersion = $r['version'];
        break;
    }
}

function fmtDate(string $dt): string { return date('d M Y', strtotime($dt)); }
function fmtSize(int $bytes): string {
    if ($bytes <= 0) return '—';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- ═══════════════════════════════════════════════════════
     Alpine root
     ═══════════════════════════════════════════════════════ -->
<div x-data="releasesPage()" x-cloak>

<!-- ──────── Flash message ──────── -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-xl text-sm font-medium flex items-center gap-2
    <?= $msgType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' ?>
    <?= $msgType === 'error'   ? 'bg-red-50 text-accent-500 border border-red-200' : '' ?>
    <?= $msgType === 'warning' ? 'bg-amber-50 text-amber-700 border border-amber-200' : '' ?>"
    x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,5000)" x-transition>
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ──────── Header ──────── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 sm:mb-6">
    <p class="text-xs sm:text-sm text-muted">Gestiona las versiones publicadas del cliente AZCKeeper para Windows.</p>
    <div class="flex items-center gap-2 sm:gap-3 flex-wrap">
        <!-- Filter -->
        <select x-model="filter" class="text-xs sm:text-sm border border-gray-200 rounded-lg px-2.5 py-1.5 sm:px-3 sm:py-2 bg-white focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
            <option value="all">Todas</option>
            <option value="active">Activas</option>
            <option value="inactive">Inactivas</option>
            <option value="beta">Beta</option>
            <option value="force">Force Update</option>
        </select>
        <!-- Search -->
        <div class="relative flex-1 sm:flex-none">
            <svg class="absolute left-3 top-2 sm:top-2.5 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" x-model="search" placeholder="Buscar versión..." class="w-full sm:w-48 pl-10 pr-3 py-1.5 sm:py-2 text-sm border border-gray-200 rounded-lg bg-white focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
        </div>
        <?php if (canDo('releases', 'can_create')): ?>
        <button @click="openCreate()" class="inline-flex items-center gap-1.5 px-3 py-1.5 sm:px-4 sm:py-2 bg-corp-800 text-white rounded-xl text-xs sm:text-sm font-medium hover:bg-corp-900 transition-colors flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span class="hidden sm:inline">Nueva Release</span>
            <span class="sm:hidden">Nueva</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- ──────── KPI Cards ──────── -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <?php
    $kpis = [
        ['<svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>','Total Releases',$totalReleases,'blue'],
        ['<svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>','Activas',$activeReleases,'emerald'],
        ['<svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>','Beta',$betaReleases,'purple'],
        ['<svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>','Force Update',$forceCount,'amber'],
        ['<svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>','Última Versión',$latestVersion,'corp'],
    ];
    foreach ($kpis as [$icon,$label,$value,$color]): ?>
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-<?= $color ?>-50 rounded-lg flex items-center justify-center"><?= $icon ?></div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $value ?></p>
                <p class="text-[10px] sm:text-xs text-muted"><?= $label ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ──────── Tabla de Releases ──────── -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-3 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Versión</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden sm:table-cell">Fecha</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Tamaño</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Flags</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Mín. Requerida</th>
                    <th class="px-3 sm:px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider">Estado</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Notas</th>
                    <th class="px-3 sm:px-5 py-3 text-right text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($releases as $idx => $r): ?>
                <tr x-show="matchesFilter(<?= $idx ?>)"
                    class="hover:bg-gray-50/50 transition-colors <?= !$r['is_active'] ? 'opacity-50' : '' ?>">
                    <!-- Versión -->
                    <td class="px-3 sm:px-5 py-3.5">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-mono font-semibold text-dark text-xs sm:text-sm"><?= htmlspecialchars($r['version']) ?></span>
                            <?php if ($r['version'] === $latestVersion): ?>
                            <span class="px-1.5 py-0.5 bg-emerald-50 text-emerald-700 text-[10px] font-bold rounded uppercase">Latest</span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] text-muted sm:hidden mt-0.5"><?= $r['release_date'] ? fmtDate($r['release_date']) : '—' ?></p>
                    </td>
                    <!-- Fecha -->
                    <td class="px-5 py-3.5 text-gray-600 hidden sm:table-cell"><?= $r['release_date'] ? fmtDate($r['release_date']) : '—' ?></td>
                    <!-- Tamaño -->
                    <td class="px-5 py-3.5 text-gray-600 hidden lg:table-cell"><?= fmtSize((int)$r['file_size']) ?></td>
                    <!-- Flags -->
                    <td class="px-5 py-3.5 hidden md:table-cell">
                        <div class="flex gap-1.5 flex-wrap">
                            <?php if ($r['is_beta']): ?>
                            <span class="px-2 py-0.5 bg-purple-50 text-purple-700 text-[10px] font-bold rounded-full uppercase">Beta</span>
                            <?php endif; ?>
                            <?php if ($r['force_update']): ?>
                            <span class="px-2 py-0.5 bg-amber-50 text-amber-700 text-[10px] font-bold rounded-full uppercase">Force</span>
                            <?php endif; ?>
                            <?php if (!$r['is_beta'] && !$r['force_update']): ?>
                            <span class="text-gray-400 text-xs">—</span>
                            <?php endif; ?>
                        </div>
                    </td>
                    <!-- Mín. Requerida -->
                    <td class="px-5 py-3.5 font-mono text-gray-600 text-xs hidden lg:table-cell"><?= $r['minimum_version'] ? htmlspecialchars($r['minimum_version']) : '—' ?></td>
                    <!-- Estado -->
                    <td class="px-3 sm:px-5 py-3.5">
                        <?php if ($r['is_active']): ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-emerald-50 text-emerald-700 text-xs font-medium rounded-full">
                            <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full"></span> Activa
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 text-gray-500 text-xs font-medium rounded-full">
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span> Inactiva
                        </span>
                        <?php endif; ?>
                    </td>
                    <!-- Notas (truncated) -->
                    <td class="px-5 py-3.5 max-w-[200px] hidden md:table-cell">
                        <?php if ($r['release_notes']): ?>
                        <p class="text-gray-600 text-xs truncate" title="<?= htmlspecialchars($r['release_notes']) ?>"><?= htmlspecialchars(mb_strimwidth($r['release_notes'], 0, 60, '...')) ?></p>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                        <?php endif; ?>
                    </td>
                    <!-- Acciones -->
                    <td class="px-3 sm:px-5 py-3.5 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <!-- Download link -->
                            <a href="<?= htmlspecialchars($r['download_url']) ?>" target="_blank" title="Descargar"
                               class="p-1.5 text-gray-400 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            </a>
                            <?php if (canDo('releases', 'can_edit')): ?>
                            <!-- Toggle active -->
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="release_id" value="<?= $r['id'] ?>">
                                <button type="submit" title="<?= $r['is_active'] ? 'Desactivar' : 'Activar' ?>"
                                        class="p-1.5 text-gray-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors">
                                    <?php if ($r['is_active']): ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21"/></svg>
                                    <?php else: ?>
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <!-- Edit -->
                            <button @click="openEdit(<?= $idx ?>)" title="Editar"
                                    class="p-1.5 text-gray-400 hover:text-corp-800 hover:bg-corp-50 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <?php endif; ?>
                            <?php if (canDo('releases', 'can_delete')): ?>
                            <!-- Delete -->
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar release <?= htmlspecialchars($r['version']) ?> permanentemente?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="release_id" value="<?= $r['id'] ?>">
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

    <!-- Empty state -->
    <?php if (empty($releases)): ?>
    <div class="text-center py-16">
        <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
        <h3 class="mt-3 text-sm font-medium text-dark">Sin releases</h3>
        <p class="mt-1 text-sm text-muted">Crea la primera release del cliente.</p>
    </div>
    <?php endif; ?>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Crear / Editar Release
     ═══════════════════════════════════════════════════════ -->
<div x-show="showModal" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <!-- Backdrop -->
    <div class="absolute inset-0 bg-black/40" @click="showModal=false"></div>

    <!-- Panel -->
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto" @click.stop>
        <!-- Header -->
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-dark" x-text="editMode ? 'Editar Release' : 'Nueva Release'"></h3>
                <button @click="showModal=false" class="p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Form -->
        <form method="post" class="p-6 space-y-5">
            <input type="hidden" name="action" :value="editMode ? 'update' : 'create'">
            <input type="hidden" name="release_id" :value="form.id">

            <!-- Version + Release Date -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Versión <span class="text-red-500">*</span></label>
                    <input type="text" name="version" x-model="form.version" required placeholder="3.0.1.4"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <p class="mt-1 text-xs text-muted">Formato: X.Y.Z.W (ej: 3.0.1.4)</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Fecha de Release</label>
                    <input type="date" name="release_date" x-model="form.release_date"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                </div>
            </div>

            <!-- Download URL -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">URL de Descarga <span class="text-red-500">*</span></label>
                <input type="url" name="download_url" x-model="form.download_url" required placeholder="https://github.com/.../.zip"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
            </div>

            <!-- File Size + Minimum Version -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Tamaño (bytes)</label>
                    <input type="number" name="file_size" x-model="form.file_size" min="0" placeholder="0"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-dark mb-1">Versión Mínima Requerida</label>
                    <input type="text" name="minimum_version" x-model="form.minimum_version" placeholder="3.0.0.1"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                    <p class="mt-1 text-xs text-muted">Clientes debajo de esta versión recibirán actualización forzada.</p>
                </div>
            </div>

            <!-- Release Notes -->
            <div>
                <label class="block text-sm font-medium text-dark mb-1">Notas de Release</label>
                <textarea name="release_notes" x-model="form.release_notes" rows="5" placeholder="Describe los cambios de esta versión..."
                          class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none resize-y"></textarea>
            </div>

            <!-- Flags (checkboxes) -->
            <div class="flex flex-wrap gap-6">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_beta" x-model="form.is_beta" class="w-4 h-4 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                    <div>
                        <span class="text-sm font-medium text-dark">Beta</span>
                        <p class="text-xs text-muted">No se enviará a clientes estables.</p>
                    </div>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="force_update" x-model="form.force_update" class="w-4 h-4 rounded border-gray-300 text-amber-600 focus:ring-amber-500">
                    <div>
                        <span class="text-sm font-medium text-dark">Force Update</span>
                        <p class="text-xs text-muted">Los clientes se actualizan inmediatamente.</p>
                    </div>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_active" x-model="form.is_active" class="w-4 h-4 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <div>
                        <span class="text-sm font-medium text-dark">Activa</span>
                        <p class="text-xs text-muted">Visible para clientes.</p>
                    </div>
                </label>
            </div>

            <!-- Submit -->
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-100">
                <button type="button" @click="showModal=false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors"
                        x-text="editMode ? 'Guardar Cambios' : 'Crear Release'"></button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL: Detalle de Release (Notas completas)
     ═══════════════════════════════════════════════════════ -->
<div x-show="showDetail" x-transition.opacity class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display:none">
    <div class="absolute inset-0 bg-black/40" @click="showDetail=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[80vh] overflow-y-auto" @click.stop>
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl z-10">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-dark">Release <span x-text="detailVersion" class="font-mono"></span></h3>
                <button @click="showDetail=false" class="p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-2 gap-3 mb-5 text-sm">
                <div class="bg-gray-50 rounded-lg p-3">
                    <p class="text-[10px] font-semibold text-muted uppercase mb-1">Fecha</p>
                    <p class="text-dark font-medium" x-text="detailDate"></p>
                </div>
                <div class="bg-gray-50 rounded-lg p-3">
                    <p class="text-[10px] font-semibold text-muted uppercase mb-1">Tamaño</p>
                    <p class="text-dark font-medium" x-text="detailSize"></p>
                </div>
            </div>
            <div class="mb-4">
                <p class="text-xs font-semibold text-muted uppercase mb-2">URL de Descarga</p>
                <a :href="detailUrl" target="_blank" class="text-sm text-blue-600 hover:underline break-all" x-text="detailUrl"></a>
            </div>
            <div>
                <p class="text-xs font-semibold text-muted uppercase mb-2">Notas de Release</p>
                <pre class="text-sm text-gray-700 whitespace-pre-wrap bg-gray-50 rounded-lg p-4 max-h-60 overflow-y-auto" x-text="detailNotes"></pre>
            </div>
        </div>
    </div>
</div>

</div><!-- /x-data -->

<!-- ═══════════════════════════════════════════════════════
     Alpine.js logic
     ═══════════════════════════════════════════════════════ -->
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('releasesPage', () => ({
        /* ── State ── */
        showModal: false,
        editMode: false,
        showDetail: false,
        filter: 'all',
        search: '',

        /* ── Form data ── */
        form: {
            id: '',
            version: '',
            download_url: '',
            file_size: 0,
            release_notes: '',
            is_beta: false,
            force_update: false,
            minimum_version: '',
            is_active: true,
            release_date: new Date().toISOString().split('T')[0],
        },

        /* ── Detail modal data ── */
        detailVersion: '',
        detailDate: '',
        detailSize: '',
        detailUrl: '',
        detailNotes: '',

        /* ── Release data from PHP ── */
        releases: <?= json_encode(array_values($releases), JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        /* ── Methods ── */
        resetForm() {
            this.form = {
                id: '',
                version: '',
                download_url: '',
                file_size: 0,
                release_notes: '',
                is_beta: false,
                force_update: false,
                minimum_version: '',
                is_active: true,
                release_date: new Date().toISOString().split('T')[0],
            };
        },

        openCreate() {
            this.resetForm();
            this.editMode = false;
            this.showModal = true;
        },

        openEdit(idx) {
            const r = this.releases[idx];
            if (!r) return;
            this.editMode = true;
            this.form = {
                id: r.id,
                version: r.version,
                download_url: r.download_url,
                file_size: parseInt(r.file_size) || 0,
                release_notes: r.release_notes || '',
                is_beta: !!parseInt(r.is_beta),
                force_update: !!parseInt(r.force_update),
                minimum_version: r.minimum_version || '',
                is_active: !!parseInt(r.is_active),
                release_date: r.release_date || '',
            };
            this.showModal = true;
        },

        openDetailModal(idx) {
            const r = this.releases[idx];
            if (!r) return;
            this.detailVersion = r.version;
            this.detailDate = r.release_date || '—';
            this.detailSize = this.formatSize(parseInt(r.file_size) || 0);
            this.detailUrl = r.download_url;
            this.detailNotes = r.release_notes || 'Sin notas.';
            this.showDetail = true;
        },

        matchesFilter(idx) {
            const r = this.releases[idx];
            if (!r) return false;

            // Text search
            if (this.search) {
                const q = this.search.toLowerCase();
                const haystack = (r.version + ' ' + (r.release_notes || '')).toLowerCase();
                if (!haystack.includes(q)) return false;
            }

            // Status filter
            switch (this.filter) {
                case 'active':   return !!parseInt(r.is_active);
                case 'inactive': return !parseInt(r.is_active);
                case 'beta':     return !!parseInt(r.is_beta);
                case 'force':    return !!parseInt(r.force_update);
                default:         return true;
            }
        },

        formatSize(bytes) {
            if (bytes <= 0) return '—';
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        },
    }));
});
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
