<?php
/**
 * Log del cliente — histórico de Warn/Error que los equipos drenan al panel (keeper_client_log).
 * Complementa el diagnóstico en vivo (que es efímero, 2h): aquí queda el registro forense por
 * equipo para soporte. Misma audiencia que Auditoría (permiso 'audit').
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/ClientLogRepo.php';

use Keeper\Repos\ClientLogRepo;

if (!panelCan($adminUser, 'audit')) { header('Location: ' . panelLanding($adminUser)); exit; }

$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$firmScope = $firmScope !== null ? (int)$firmScope : null;

$pageTitle = 'Log del cliente'; $currentPage = 'audit';

$level  = (string)($_GET['level'] ?? '');
$source = (string)($_GET['source'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-7 days'));
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$page   = max(1, (int)($_GET['p'] ?? 1));
$per    = 50;

$filters = ['level'=>$level, 'source'=>$source, 'q'=>$q, 'from'=>$from, 'to'=>$to];
$total = 0; $rows = [];
try {
    $total = ClientLogRepo::count($pdo, $filters, $firmScope);
    $rows  = ClientLogRepo::search($pdo, $filters, $per, ($page-1)*$per, $firmScope);
} catch (\Throwable $e) { error_log('client-logs: ' . $e->getMessage()); }
$pages = max(1, (int)ceil($total / $per));

$lvlPill = ['error'=>'text-accent-600 bg-accent-500/10','warn'=>'text-amber-700 bg-amber-50','info'=>'text-gray-600 bg-gray-100','debug'=>'text-gray-400 bg-gray-50'];
function clLink(array $over = []): string {
    $base = ['level'=>$_GET['level']??'','source'=>$_GET['source']??'','q'=>$_GET['q']??'','from'=>$_GET['from']??'','to'=>$_GET['to']??'','p'=>$_GET['p']??''];
    return 'client-logs.php?' . http_build_query(array_filter(array_merge($base,$over), fn($v)=>$v!==''&&$v!==null));
}

require __DIR__ . '/partials/layout_header.php';
?>

<!-- Pestañas: Auditoría (actor) / Log del cliente -->
<div class="flex gap-1.5 mb-5">
  <a href="audit.php" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-white border border-gray-200 text-gray-600 hover:border-gray-300">Auditoría (acciones)</a>
  <a href="client-logs.php" class="px-3 py-1.5 rounded-lg text-xs font-medium bg-corp-800 text-white">Log del cliente</a>
</div>

<form method="get" class="bg-white rounded-xl border border-gray-100 p-4 mb-5 grid sm:grid-cols-12 gap-3 items-end">
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Nivel</label>
    <select name="level" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
      <option value="">Todos</option>
      <?php foreach (['error'=>'Error','warn'=>'Warn','info'=>'Info','debug'=>'Debug'] as $k=>$v): ?><option value="<?= $k ?>" <?= $level===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Fuente</label>
    <select name="source" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
      <option value="">Todas</option>
      <?php foreach (ClientLogRepo::SOURCES as $s): ?><option value="<?= $s ?>" <?= $source===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="sm:col-span-2"><label class="block text-xs font-medium text-gray-600 mb-1">Desde</label><input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm"></div>
  <div class="sm:col-span-2"><label class="block text-xs font-medium text-gray-600 mb-1">Hasta</label><input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm"></div>
  <div class="sm:col-span-4 flex gap-2">
    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Texto en el mensaje…" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
    <button class="px-3 py-1.5 bg-corp-800 hover:bg-corp-900 text-white text-sm rounded-lg">Filtrar</button>
  </div>
</form>

<div class="flex items-center justify-between mb-3">
  <p class="text-sm text-gray-600"><span class="tabular-nums font-medium"><?= number_format($total) ?></span> registros · página <?= $page ?> de <?= $pages ?></p>
  <?php if ($level||$source||$q): ?><a href="client-logs.php" class="text-xs text-muted hover:text-dark">Limpiar</a><?php endif; ?>
</div>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Cuándo</th>
        <th class="text-left font-semibold px-5 py-2.5">Equipo / persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Nivel</th>
        <th class="text-left font-semibold px-5 py-2.5">Fuente</th>
        <th class="text-left font-semibold px-5 py-2.5">Mensaje</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="px-5 py-10 text-center text-muted">Sin registros para este filtro.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b border-gray-100 last:border-0 align-top">
          <td class="px-5 py-3 text-xs text-gray-600 whitespace-nowrap tabular-nums"><?= htmlspecialchars(substr((string)$r['logged_at'],0,16)) ?></td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['device'] ?: '—') ?><?php if ($r['display_name']): ?><div class="text-xs text-muted"><?= htmlspecialchars($r['display_name']) ?></div><?php endif; ?></td>
          <td class="px-5 py-3"><span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $lvlPill[$r['level']] ?? '' ?>"><?= htmlspecialchars($r['level']) ?></span></td>
          <td class="px-5 py-3 text-xs font-mono text-gray-500"><?= htmlspecialchars($r['source']) ?></td>
          <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($r['message']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-sm">
      <?php if ($page>1): ?><a href="<?= htmlspecialchars(clLink(['p'=>$page-1])) ?>" class="text-corp-800 hover:text-corp-600">← Anteriores</a><?php else: ?><span></span><?php endif; ?>
      <span class="text-xs text-muted"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page<$pages): ?><a href="<?= htmlspecialchars(clLink(['p'=>$page+1])) ?>" class="text-corp-800 hover:text-corp-600">Siguientes →</a><?php else: ?><span></span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/partials/layout_footer.php'; ?>
