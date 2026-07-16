<?php
/**
 * Logs — historial de problemas reportados por los clientes Windows.
 *
 * Pestaña "cliente": keeper_client_log (Warn/Error + eventos de update que el cliente
 * reporta pegado al handshake). Es lo que permite ver por qué un equipo falla sin
 * pedirle a nadie que abra %APPDATA%\AZCKeeper\Logs\ en su máquina.
 *
 * Pestaña "auditoría": keeper_audit_log (login y enrolamiento, escrito por el backend).
 *
 * Paginación server-side: la tabla crece con cada incidente y no se puede traer entera.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('logs');

use Keeper\Repos\ClientLogRepo;

$pageTitle   = 'Logs';
$currentPage = 'logs';

$tab = ($_GET['tab'] ?? 'client') === 'audit' ? 'audit' : 'client';

$perPage = 50;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$filters = [
    'level'     => in_array($_GET['level'] ?? '', ClientLogRepo::LEVELS, true) ? $_GET['level'] : '',
    'source'    => in_array($_GET['source'] ?? '', ClientLogRepo::SOURCES, true) ? $_GET['source'] : '',
    'device_id' => (int)($_GET['device_id'] ?? 0) ?: '',
    'q'         => trim((string)($_GET['q'] ?? '')),
    'from'      => trim((string)($_GET['from'] ?? '')),
    'to'        => trim((string)($_GET['to'] ?? '')),
];

// Los inputs date entregan Y-m-d; el rango se interpreta como día completo.
if ($filters['from'] !== '') $filters['from'] .= ' 00:00:00';
if ($filters['to']   !== '') $filters['to']   .= ' 23:59:59';

$counts       = ClientLogRepo::countsByLevel($pdo, 24);
$topOffenders = ClientLogRepo::topOffenders($pdo, 24, 8);

$rows = [];
$total = 0;

if ($tab === 'client') {
    $rows  = ClientLogRepo::search($pdo, $filters, $perPage, $offset);
    $total = ClientLogRepo::countMatching($pdo, $filters);
} else {
    $st = $pdo->prepare("SELECT a.id, a.event_type, a.message, a.meta_json, a.created_at,
                                a.device_id, d.device_name, a.user_id, u.display_name
                         FROM keeper_audit_log a
                         LEFT JOIN keeper_devices d ON d.id = a.device_id
                         LEFT JOIN keeper_users   u ON u.id = a.user_id
                         ORDER BY a.created_at DESC, a.id DESC
                         LIMIT :lim OFFSET :off");
    $st->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $st->bindValue(':off', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows  = $st->fetchAll(PDO::FETCH_ASSOC);
    $total = (int)$pdo->query("SELECT COUNT(*) FROM keeper_audit_log")->fetchColumn();
}

$totalPages = max(1, (int)ceil($total / $perPage));

// Equipos que han reportado algo — alimenta el selector de filtro.
$devices = $pdo->query("SELECT DISTINCT l.device_id, d.device_name
                        FROM keeper_client_log l
                        JOIN keeper_devices d ON d.id = l.device_id
                        ORDER BY d.device_name")->fetchAll(PDO::FETCH_ASSOC);

/** Conserva los filtros al cambiar de página o pestaña. */
function logsUrl(array $overrides = []): string {
    $params = array_merge($_GET, $overrides);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'logs.php?' . http_build_query($params);
}

$levelBadge = [
    'error' => 'bg-red-100 text-red-800',
    'warn'  => 'bg-amber-100 text-amber-800',
    'info'  => 'bg-blue-100 text-blue-800',
];

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- KPIs — ventana de 24h -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-red-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.71-3.01L13.71 4a2 2 0 00-3.42 0L3.36 15.99A2 2 0 005.07 19z"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= (int)$counts['error'] ?></p>
                <p class="text-xs text-muted">Errores (24h)</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-amber-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= (int)$counts['warn'] ?></p>
                <p class="text-xs text-muted">Advertencias (24h)</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-blue-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= (int)$counts['info'] ?></p>
                <p class="text-xs text-muted">Eventos update (24h)</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-corp-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= count($topOffenders) ?></p>
                <p class="text-xs text-muted">Equipos con problemas (24h)</p>
            </div>
        </div>
    </div>
</div>

<?php if ($topOffenders): ?>
<div class="bg-white rounded-xl border border-gray-100 p-5 mb-6">
    <p class="text-sm font-semibold text-dark mb-3">Equipos que más reportan (24h)</p>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($topOffenders as $o): ?>
        <a href="<?= htmlspecialchars(logsUrl(['device_id' => $o['device_id'], 'page' => 1, 'tab' => 'client'])) ?>"
           class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg bg-gray-50 hover:bg-corp-50 border border-gray-100 text-xs transition-colors">
            <span class="font-medium text-dark"><?= htmlspecialchars($o['device_name'] ?? ('#' . $o['device_id'])) ?></span>
            <span class="text-muted"><?= htmlspecialchars($o['display_name'] ?? '—') ?></span>
            <span class="px-1.5 py-0.5 rounded bg-red-100 text-red-800 font-semibold"><?= (int)$o['n'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Pestañas -->
<div class="flex gap-1 mb-4 border-b border-gray-200">
    <a href="<?= htmlspecialchars(logsUrl(['tab' => 'client', 'page' => 1])) ?>"
       class="px-4 py-2 text-sm font-medium border-b-2 transition-colors <?= $tab === 'client' ? 'border-corp-800 text-corp-800' : 'border-transparent text-muted hover:text-dark' ?>">
        Cliente
    </a>
    <a href="<?= htmlspecialchars(logsUrl(['tab' => 'audit', 'page' => 1])) ?>"
       class="px-4 py-2 text-sm font-medium border-b-2 transition-colors <?= $tab === 'audit' ? 'border-corp-800 text-corp-800' : 'border-transparent text-muted hover:text-dark' ?>">
        Auditoría
    </a>
</div>

<?php if ($tab === 'client'): ?>
<!-- Filtros -->
<form method="get" class="bg-white rounded-xl border border-gray-100 p-4 mb-4">
    <input type="hidden" name="tab" value="client">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
        <div>
            <label class="block text-xs text-muted mb-1">Nivel</label>
            <select name="level" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                <option value="">Todos</option>
                <?php foreach (ClientLogRepo::LEVELS as $lv): ?>
                <option value="<?= $lv ?>" <?= $filters['level'] === $lv ? 'selected' : '' ?>><?= ucfirst($lv) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-muted mb-1">Origen</label>
            <select name="source" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                <option value="">Todos</option>
                <?php foreach (ClientLogRepo::SOURCES as $sc): ?>
                <option value="<?= $sc ?>" <?= $filters['source'] === $sc ? 'selected' : '' ?>><?= ucfirst($sc) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-muted mb-1">Equipo</label>
            <select name="device_id" class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
                <option value="">Todos</option>
                <?php foreach ($devices as $d): ?>
                <option value="<?= (int)$d['device_id'] ?>" <?= (string)$filters['device_id'] === (string)$d['device_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['device_name'] ?? ('#' . $d['device_id'])) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs text-muted mb-1">Desde</label>
            <input type="date" name="from" value="<?= htmlspecialchars(substr($filters['from'], 0, 10)) ?>"
                   class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-xs text-muted mb-1">Hasta</label>
            <input type="date" name="to" value="<?= htmlspecialchars(substr($filters['to'], 0, 10)) ?>"
                   class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
        </div>
        <div>
            <label class="block text-xs text-muted mb-1">Buscar en mensaje</label>
            <input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="p.ej. DNS, updater"
                   class="w-full text-sm border border-gray-200 rounded-lg px-3 py-2">
        </div>
    </div>
    <div class="flex items-center gap-2 mt-3">
        <button type="submit" class="px-4 py-2 rounded-lg bg-corp-800 text-white text-sm font-medium hover:opacity-90">Filtrar</button>
        <a href="logs.php" class="px-4 py-2 rounded-lg border border-gray-200 text-sm text-muted hover:text-dark">Limpiar</a>
        <span class="text-xs text-muted ml-auto"><?= number_format($total) ?> registros · retención <?= ClientLogRepo::RETENTION_DAYS ?> días</span>
    </div>
</form>

<!-- Tabla cliente -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr class="text-left text-xs text-muted uppercase tracking-wider">
                    <th class="px-4 py-3 whitespace-nowrap">Fecha (servidor)</th>
                    <th class="px-4 py-3">Nivel</th>
                    <th class="px-4 py-3">Origen</th>
                    <th class="px-4 py-3">Equipo</th>
                    <th class="px-4 py-3">Usuario</th>
                    <th class="px-4 py-3">Versión</th>
                    <th class="px-4 py-3">Mensaje</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php if (!$rows): ?>
                <tr><td colspan="7" class="px-4 py-10 text-center text-muted">
                    Sin registros. Los equipos reportan al hacer handshake, con la versión 3.0.2.9 o superior.
                </td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-gray-50/50">
                    <td class="px-4 py-3 whitespace-nowrap text-muted"><?= htmlspecialchars($r['created_at']) ?></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold <?= $levelBadge[$r['level']] ?? 'bg-gray-100 text-gray-700' ?>">
                            <?= htmlspecialchars($r['level']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-muted"><?= htmlspecialchars($r['source']) ?></td>
                    <td class="px-4 py-3 font-medium text-dark whitespace-nowrap"><?= htmlspecialchars($r['device_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-muted whitespace-nowrap"><?= htmlspecialchars($r['display_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-muted whitespace-nowrap"><?= htmlspecialchars($r['client_version'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-dark"><?= htmlspecialchars($r['message']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- Tabla auditoría -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr class="text-left text-xs text-muted uppercase tracking-wider">
                    <th class="px-4 py-3 whitespace-nowrap">Fecha (servidor)</th>
                    <th class="px-4 py-3">Evento</th>
                    <th class="px-4 py-3">Equipo</th>
                    <th class="px-4 py-3">Usuario</th>
                    <th class="px-4 py-3">Mensaje</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php if (!$rows): ?>
                <tr><td colspan="5" class="px-4 py-10 text-center text-muted">Sin registros de auditoría.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                <tr class="hover:bg-gray-50/50">
                    <td class="px-4 py-3 whitespace-nowrap text-muted"><?= htmlspecialchars($r['created_at']) ?></td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-0.5 rounded text-xs font-semibold <?= str_contains($r['event_type'], 'failed') || str_contains($r['event_type'], 'rejected') ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-700' ?>">
                            <?= htmlspecialchars($r['event_type']) ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 font-medium text-dark whitespace-nowrap"><?= htmlspecialchars($r['device_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-muted whitespace-nowrap"><?= htmlspecialchars($r['display_name'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-dark"><?= htmlspecialchars($r['message'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Paginación -->
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between mt-4">
    <p class="text-xs text-muted">Página <?= $page ?> de <?= $totalPages ?> · <?= number_format($total) ?> registros</p>
    <div class="flex gap-1">
        <?php if ($page > 1): ?>
        <a href="<?= htmlspecialchars(logsUrl(['page' => $page - 1])) ?>"
           class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-dark hover:bg-gray-50">Anterior</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
        <a href="<?= htmlspecialchars(logsUrl(['page' => $page + 1])) ?>"
           class="px-3 py-1.5 rounded-lg border border-gray-200 text-sm text-dark hover:bg-gray-50">Siguiente</a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
