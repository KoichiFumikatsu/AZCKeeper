<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo

if (!panelCan($adminUser, 'users')) { header('Location: ' . panelLanding($adminUser)); exit; }

$pageTitle = 'Usuarios'; $currentPage = 'users';

$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$scopeSql  = $firmScope ? " AND a.firma_id = " . (int)$firmScope . " " : "";
$q = trim((string)($_GET['q'] ?? ''));

$rows = [];
try {
    $sql = "
        SELECT u.id, u.cc, u.display_name, u.status, u.employment_status,
               f.nombre AS firma, ar.nombre AS area, c.nombre AS cargo, se.nombre AS sede,
               (SELECT COUNT(*) FROM keeper_devices d WHERE d.user_id = u.id AND d.status='active') AS devices
        FROM keeper_users u
        LEFT JOIN keeper_user_assignments a ON a.user_id = u.id
        LEFT JOIN keeper_firmas f  ON f.id  = a.firma_id
        LEFT JOIN keeper_areas  ar ON ar.id = a.area_id
        LEFT JOIN keeper_cargos c  ON c.id  = a.cargo_id
        LEFT JOIN keeper_sedes  se ON se.id = a.sede_id
        WHERE u.status <> 'pending' $scopeSql";
    if ($q !== '') $sql .= " AND (u.display_name LIKE :q OR u.cc LIKE :q) ";
    $sql .= " ORDER BY u.display_name IS NULL, u.display_name, u.cc LIMIT 500";
    $st = $pdo->prepare($sql);
    if ($q !== '') $st->bindValue(':q', '%'.$q.'%');
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('users list: '.$e->getMessage()); }

function statusPill(string $s, string $emp): string {
    if ($emp === 'retired') return '<span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">Retirado</span>';
    return $s === 'active'
        ? '<span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activo</span>'
        : '<span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">Inactivo</span>';
}

require __DIR__ . '/partials/layout_header.php';
?>
<form method="get" class="mb-5 flex gap-2">
  <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre o cédula…"
         class="w-full max-w-xs px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
  <button class="px-4 py-2 bg-corp-800 hover:bg-corp-900 text-white text-sm font-medium rounded-lg">Buscar</button>
  <?php if ($q!==''): ?><a href="users.php" class="px-4 py-2 text-sm text-muted hover:text-dark">Limpiar</a><?php endif; ?>
</form>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Firma</th>
        <th class="text-left font-semibold px-5 py-2.5">Cargo / Área</th>
        <th class="text-left font-semibold px-5 py-2.5">Sede</th>
        <th class="text-left font-semibold px-5 py-2.5">Equipos</th>
        <th class="text-left font-semibold px-5 py-2.5">Estado</th>
        <th class="text-right font-semibold px-5 py-2.5"></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="px-5 py-10 text-center text-muted">Sin personas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b border-gray-100 last:border-0">
          <td class="px-5 py-3">
            <div class="font-medium text-dark"><?= htmlspecialchars($r['display_name'] ?: 'Sin nombre') ?></div>
            <div class="text-xs text-muted font-mono"><?= htmlspecialchars($r['cc'] ? '•••'.substr(preg_replace('/\D/','',$r['cc']),-4) : '') ?></div>
          </td>
          <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($r['firma'] ?: '—') ?></td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['cargo'] ?: '—') ?><?php if ($r['area']): ?><span class="text-muted"> · <?= htmlspecialchars($r['area']) ?></span><?php endif; ?></td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['sede'] ?: '—') ?></td>
          <td class="px-5 py-3 tabular-nums text-gray-700"><?= (int)$r['devices'] ?></td>
          <td class="px-5 py-3"><?= statusPill($r['status'], $r['employment_status']) ?></td>
          <td class="px-5 py-3 text-right">
            <a href="process-view.php?user=<?= (int)$r['id'] ?>" class="text-xs text-corp-800 hover:text-corp-600 font-medium">Ver actividad →</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/layout_footer.php'; ?>
