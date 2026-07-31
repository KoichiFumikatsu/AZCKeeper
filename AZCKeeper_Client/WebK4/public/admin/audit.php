<?php
/**
 * Bitácora. La diferencia con la de Keeper 3 es el ACTOR: allí la tabla tenía sujeto
 * (user_id/device_id) pero no admin_id, así que podía decir a quién pero nunca quién.
 * Aquí las dos columnas conviven, y `event_category` separa lo administrativo de las
 * consultas a datos sensibles (`data_access`), que se auditan por secreto profesional.
 *
 * Paginación server-side: esta tabla crece sin techo y es la que se consulta cuando algo
 * ya salió mal — traerla entera al navegador sería la peor forma de descubrirlo.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo

if (!panelCan($adminUser, 'audit')) { header('Location: ' . panelLanding($adminUser)); exit; }

$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$firmScope = $firmScope !== null ? (int)$firmScope : null;

$pageTitle = 'Auditoría'; $currentPage = 'audit';

$CATS = ['admin' => 'Administrativo', 'security' => 'Seguridad', 'data_access' => 'Acceso a datos',
         'import' => 'Importación', 'system' => 'Sistema'];

$cat   = (string)($_GET['cat'] ?? '');
$type  = trim((string)($_GET['type'] ?? ''));
$actor = (int)($_GET['actor'] ?? 0);
$q     = trim((string)($_GET['q'] ?? ''));
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : date('Y-m-d');
$page  = max(1, (int)($_GET['p'] ?? 1));
$per   = 50;

$where  = ["l.created_at >= :from", "l.created_at < DATE_ADD(:to, INTERVAL 1 DAY)"];
$params = [':from' => $from, ':to' => $to];
if (isset($CATS[$cat]))  { $where[] = "l.event_category = :cat";  $params[':cat']  = $cat; }
if ($type !== '')        { $where[] = "l.event_type = :type";     $params[':type'] = $type; }
if ($actor > 0)          { $where[] = "l.admin_id = :actor";      $params[':actor'] = $actor; }
if ($q !== '')           { $where[] = "(l.message LIKE :q OR u.display_name LIKE :q)"; $params[':q'] = '%' . $q . '%'; }
// Un admin de firma ve lo suyo: eventos de su gente y los que no tienen sujeto (globales).
if ($firmScope !== null) { $where[] = "(l.user_id IS NULL OR ua.firma_id = " . $firmScope . ")"; }

$joins = "
    LEFT JOIN keeper_admin_accounts a ON a.id = l.admin_id
    LEFT JOIN keeper_users u          ON u.id = l.user_id
    LEFT JOIN keeper_user_assignments ua ON ua.user_id = l.user_id
    LEFT JOIN keeper_devices d        ON d.id = l.device_id";
$sqlWhere = implode(' AND ', $where);

$total = 0; $rows = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM keeper_audit_log l {$joins} WHERE {$sqlWhere}");
    $st->execute($params);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("
        SELECT l.id, l.created_at, l.event_category, l.event_type, l.message, l.meta_json, l.ip,
               l.user_id, a.email AS actor_email, a.display_name AS actor_name,
               u.display_name AS subject_name, COALESCE(d.label, d.device_name) AS device
        FROM keeper_audit_log l {$joins}
        WHERE {$sqlWhere}
        ORDER BY l.created_at DESC, l.id DESC
        LIMIT {$per} OFFSET " . (($page - 1) * $per));
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('audit list: ' . $e->getMessage()); }

$pages = max(1, (int)ceil($total / $per));

// Tipos y actores presentes, para que los filtros ofrezcan lo que existe y no una lista inventada.
$types = []; $actors = [];
try {
    $types  = $pdo->query("SELECT DISTINCT event_type FROM keeper_audit_log ORDER BY event_type")->fetchAll(PDO::FETCH_COLUMN);
    $actors = $pdo->query("SELECT a.id, COALESCE(a.display_name, a.email) AS name
                           FROM keeper_admin_accounts a
                           WHERE EXISTS (SELECT 1 FROM keeper_audit_log l WHERE l.admin_id = a.id)
                           ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$catPill = [
    'admin'       => 'text-corp-800 bg-corp-50',
    'security'    => 'text-accent-500 bg-accent-500/10',
    'data_access' => 'text-amber-700 bg-amber-50',
    'import'      => 'text-emerald-700 bg-emerald-50',
    'system'      => 'text-gray-500 bg-gray-100',
];
function auditUrl(array $over = []): string {
    $base = ['cat' => $_GET['cat'] ?? '', 'type' => $_GET['type'] ?? '', 'actor' => $_GET['actor'] ?? '',
             'q' => $_GET['q'] ?? '', 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'p' => $_GET['p'] ?? ''];
    return 'audit.php?' . http_build_query(array_filter(array_merge($base, $over), fn($v) => $v !== '' && $v !== null));
}

require __DIR__ . '/partials/layout_header.php';
?>

<form method="get" class="bg-white rounded-xl border border-gray-100 p-4 mb-5 grid sm:grid-cols-12 gap-3 items-end">
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Categoría</label>
    <select name="cat" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
      <option value="">Todas</option>
      <?php foreach ($CATS as $k => $lbl): ?><option value="<?= $k ?>" <?= $cat === $k ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Tipo</label>
    <select name="type" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
      <option value="">Todos</option>
      <?php foreach ($types as $t): ?><option value="<?= htmlspecialchars($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= htmlspecialchars($t) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Actor</label>
    <select name="actor" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
      <option value="">Cualquiera</option>
      <?php foreach ($actors as $a): ?><option value="<?= (int)$a['id'] ?>" <?= $actor === (int)$a['id'] ? 'selected' : '' ?>><?= htmlspecialchars($a['name']) ?></option><?php endforeach; ?>
    </select>
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Desde</label>
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
  </div>
  <div class="sm:col-span-2">
    <label class="block text-xs font-medium text-gray-600 mb-1">Hasta</label>
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
  </div>
  <div class="sm:col-span-2 flex gap-2">
    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Texto…" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
    <button class="px-3 py-1.5 bg-corp-800 hover:bg-corp-900 text-white text-sm rounded-lg">Filtrar</button>
  </div>
</form>

<div class="flex items-center justify-between mb-3">
  <p class="text-sm text-gray-600"><span class="tabular-nums font-medium"><?= number_format($total) ?></span> eventos · página <?= $page ?> de <?= $pages ?></p>
  <?php if ($cat || $type || $actor || $q): ?><a href="audit.php" class="text-xs text-muted hover:text-dark">Limpiar filtros</a><?php endif; ?>
</div>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Cuándo</th>
        <th class="text-left font-semibold px-5 py-2.5">Quién</th>
        <th class="text-left font-semibold px-5 py-2.5">Qué</th>
        <th class="text-left font-semibold px-5 py-2.5">Sobre quién</th>
        <th class="text-left font-semibold px-5 py-2.5">Origen</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="5" class="px-5 py-10 text-center text-muted">Sin eventos para este filtro.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
        $meta = $r['meta_json'] ? json_decode((string)$r['meta_json'], true) : null; ?>
        <tr class="border-b border-gray-100 last:border-0 align-top" x-data="{open:false}">
          <td class="px-5 py-3 text-gray-600 whitespace-nowrap text-xs tabular-nums"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
          <td class="px-5 py-3">
            <?php if ($r['actor_email'] || $r['actor_name']): ?>
              <div class="text-gray-700"><?= htmlspecialchars($r['actor_name'] ?: $r['actor_email']) ?></div>
            <?php else: ?>
              <span class="text-xs text-muted italic">sistema</span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3">
            <span class="text-[10px] font-medium px-1.5 py-0.5 rounded <?= $catPill[$r['event_category']] ?? 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars($CATS[$r['event_category']] ?? $r['event_category']) ?></span>
            <span class="ml-1 text-xs font-mono text-gray-500"><?= htmlspecialchars($r['event_type']) ?></span>
            <?php if ($r['message']): ?><div class="text-gray-700 mt-0.5"><?= htmlspecialchars($r['message']) ?></div><?php endif; ?>
            <?php if ($meta): ?>
              <button @click="open=!open" class="text-[11px] text-corp-800 hover:text-corp-600 mt-0.5">detalle <span x-text="open?'▴':'▾'"></span></button>
              <pre x-show="open" style="display:none" class="mt-1 text-[11px] bg-gray-50 border border-gray-100 rounded p-2 overflow-x-auto text-gray-600"><?= htmlspecialchars(json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 text-gray-600">
            <?php if ($r['subject_name']): ?>
              <a href="process-view.php?user=<?= (int)$r['user_id'] ?>" class="text-corp-800 hover:text-corp-600"><?= htmlspecialchars($r['subject_name']) ?></a>
            <?php else: ?>—<?php endif; ?>
            <?php if ($r['device']): ?><div class="text-xs text-muted"><?= htmlspecialchars($r['device']) ?></div><?php endif; ?>
          </td>
          <td class="px-5 py-3 text-xs text-muted font-mono"><?= htmlspecialchars($r['ip'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 flex items-center justify-between text-sm">
      <?php if ($page > 1): ?><a href="<?= htmlspecialchars(auditUrl(['p' => $page - 1])) ?>" class="text-corp-800 hover:text-corp-600">← Anteriores</a><?php else: ?><span></span><?php endif; ?>
      <span class="text-xs text-muted"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= htmlspecialchars(auditUrl(['p' => $page + 1])) ?>" class="text-corp-800 hover:text-corp-600">Siguientes →</a><?php else: ?><span></span><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
