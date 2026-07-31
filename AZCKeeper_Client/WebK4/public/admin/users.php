<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/UserRepo.php';
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\UserRepo;
use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'users')) { header('Location: ' . panelLanding($adminUser)); exit; }

$adminId   = (int)$adminUser['admin_id'];
$role      = $adminUser['panel_role'] ?? 'viewer';
$firmScope = ($role !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$scopeSql  = $firmScope ? " AND a.firma_id = " . (int)$firmScope . " " : "";
$q = trim((string)($_GET['q'] ?? ''));

// Gestion de credenciales solo para IT/superadmin (el gerente ve usuarios pero no toca claves).
$canManageCreds = in_array($role, ['superadmin', 'it', 'admin'], true);
$flash = null;

// ---- Accion: restablecer / limpiar contrasena ----
if ($canManageCreds && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    $uid  = (int)($_POST['user_id'] ?? 0);
    $mode = $_POST['mode'] ?? 'set';
    // La persona debe estar en el alcance del admin.
    $inScope = true;
    if ($firmScope) {
        $chk = $pdo->prepare("SELECT 1 FROM keeper_user_assignments WHERE user_id = :u AND firma_id = :f");
        $chk->execute([':u' => $uid, ':f' => (int)$firmScope]);
        $inScope = (bool)$chk->fetchColumn();
    }
    if ($uid > 0 && $inScope) {
        if ($mode === 'clear') {
            // Volver a NULL: el proximo login re-provisiona con el patron por defecto.
            UserRepo::setPassword($pdo, $uid, null);
            AuditRepo::log($pdo, $adminId, $uid, null, 'admin', 'password_clear',
                'Contrasena limpiada (re-provision en el proximo login)');
            $flash = ['ok', 'Contraseña limpiada: el equipo la re-fijará con la regla en el próximo inicio.'];
        } else {
            $pw = (string)($_POST['password'] ?? '');
            if (strlen($pw) < 6) $flash = ['err', 'La contraseña necesita al menos 6 caracteres.'];
            else {
                UserRepo::setPassword($pdo, $uid, password_hash($pw, PASSWORD_DEFAULT));
                AuditRepo::log($pdo, $adminId, $uid, null, 'admin', 'password_reset', 'Contrasena restablecida por IT');
                $flash = ['ok', 'Contraseña restablecida.'];
            }
        }
    }
}

$rows = [];
try {
    $sql = "
        SELECT u.id, u.cc, u.display_name, u.status, u.employment_status,
               (u.password_hash IS NULL) AS no_password,
               f.nombre AS firma, ar.nombre AS area, c.nombre AS cargo, se.nombre AS sede,
               (SELECT COUNT(*) FROM keeper_devices d WHERE d.user_id = u.id AND d.status='active') AS devices,
               (SELECT UNIX_TIMESTAMP(MAX(d2.last_seen_at)) FROM keeper_devices d2 WHERE d2.user_id = u.id AND d2.status='active') AS last_seen_epoch,
               (SELECT d3.last_idle_seconds FROM keeper_devices d3 WHERE d3.user_id = u.id AND d3.status='active' ORDER BY d3.last_seen_at DESC LIMIT 1) AS last_idle
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

<?php if ($flash): [$k,$m] = $flash; ?>
  <div class="mb-4 text-sm border rounded-lg px-4 py-2.5 <?= $k==='ok' ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-accent-600 bg-accent-500/5 border-accent-500/20' ?>"><?= htmlspecialchars($m) ?></div>
<?php endif; ?>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Presencia</th>
        <th class="text-left font-semibold px-5 py-2.5">Firma</th>
        <th class="text-left font-semibold px-5 py-2.5">Cargo / Área</th>
        <th class="text-left font-semibold px-5 py-2.5">Sede</th>
        <th class="text-left font-semibold px-5 py-2.5">Equipos</th>
        <th class="text-left font-semibold px-5 py-2.5">Estado</th>
        <th class="text-left font-semibold px-5 py-2.5">Credencial</th>
        <th class="text-right font-semibold px-5 py-2.5"></th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="9" class="px-5 py-10 text-center text-muted">Sin personas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b border-gray-100 last:border-0 align-top" x-data="{pw:false}">
          <td class="px-5 py-3">
            <div class="font-medium text-dark"><?= htmlspecialchars($r['display_name'] ?: 'Sin nombre') ?></div>
            <div class="text-xs text-muted font-mono"><?= htmlspecialchars($r['cc'] ? '•••'.substr(preg_replace('/\D/','',$r['cc']),-4) : '') ?></div>
          </td>
          <td class="px-5 py-3">
            <?php $pr = presence($r['last_seen_epoch'] !== null ? (int)$r['last_seen_epoch'] : null, $r['last_idle'] !== null ? (int)$r['last_idle'] : null, (int)$r['devices']); ?>
            <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $pr['cls'] ?>"><?= $pr['label'] ?></span>
          </td>
          <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($r['firma'] ?: '—') ?></td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['cargo'] ?: '—') ?><?php if ($r['area']): ?><span class="text-muted"> · <?= htmlspecialchars($r['area']) ?></span><?php endif; ?></td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['sede'] ?: '—') ?></td>
          <td class="px-5 py-3 tabular-nums text-gray-700"><?= (int)$r['devices'] ?></td>
          <td class="px-5 py-3"><?= statusPill($r['status'], $r['employment_status']) ?></td>
          <td class="px-5 py-3">
            <?php if ($r['no_password']): ?>
              <span class="text-xs font-medium text-amber-700 bg-amber-50 px-2 py-0.5 rounded-full" title="Sin contraseña: el primer login la fija con la regla z&lt;cédula&gt;Z@!$">Sin fijar</span>
            <?php else: ?>
              <span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Fijada</span>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 text-right whitespace-nowrap">
            <a href="process-view.php?user=<?= (int)$r['id'] ?>" class="text-xs text-corp-800 hover:text-corp-600 font-medium">Ver actividad →</a>
            <?php if ($canManageCreds): ?>
              <button @click="pw=!pw" class="text-xs text-muted hover:text-corp-800 ml-2">Contraseña</button>
              <div x-show="pw" style="display:none" class="mt-2 flex flex-col items-end gap-1.5">
                <form method="post" class="flex items-center gap-1.5"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="mode" value="set">
                  <input type="password" name="password" minlength="6" required placeholder="Nueva contraseña" class="text-xs border border-gray-200 rounded px-2 py-1 w-40">
                  <button class="text-xs px-2 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">Fijar</button>
                </form>
                <form method="post" onsubmit="return confirm('¿Limpiar la contraseña? El equipo la re-fijará con la regla por defecto en el próximo inicio.')"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="reset_password">
                  <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="mode" value="clear">
                  <button class="text-[11px] text-muted hover:text-accent-500">Limpiar (re-provisionar)</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/layout_footer.php'; ?>
