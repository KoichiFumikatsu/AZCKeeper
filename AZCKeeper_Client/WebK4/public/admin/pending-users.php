<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'pending')) { header('Location: ' . panelLanding($adminUser)); exit; }

// Aprobar / rechazar.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $userId   = (int)($_POST['user_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    if ($userId > 0 && in_array($decision, ['approve','reject'], true)) {
        $newStatus = $decision === 'approve' ? 'active' : 'inactive';
        $pdo->prepare("UPDATE keeper_users SET status = :s WHERE id = :id AND status = 'pending'")
            ->execute([':s'=>$newStatus, ':id'=>$userId]);
        try {
            AuditRepo::log($pdo, (int)$adminUser['admin_id'], $userId, null, 'admin',
                $decision==='approve' ? 'enrollment_approved' : 'enrollment_rejected', "Enrolamiento {$decision} desde el panel");
        } catch (\Throwable $e) { error_log('pending audit: '.$e->getMessage()); }
    }
    header('Location: pending-users.php'); exit;
}

$pageTitle = 'Accesos pendientes'; $currentPage = 'pending';

$rows = [];
try {
    $rows = $pdo->query("
        SELECT u.id, u.cc, u.display_name, u.created_at,
               (SELECT device_name FROM keeper_devices d WHERE d.user_id = u.id ORDER BY d.created_at DESC LIMIT 1) AS device_name,
               (SELECT device_guid FROM keeper_devices d WHERE d.user_id = u.id ORDER BY d.created_at DESC LIMIT 1) AS device_guid
        FROM keeper_users u
        WHERE u.status = 'pending'
        ORDER BY u.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('pending list: '.$e->getMessage()); }

require __DIR__ . '/partials/layout_header.php';
?>
<p class="text-sm text-muted mb-6">Equipos que se enrolaron con una cédula desconocida y esperan aprobación. Aprobar activa a la persona; rechazar la deja inactiva.</p>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Cédula</th>
        <th class="text-left font-semibold px-5 py-2.5">Nombre</th>
        <th class="text-left font-semibold px-5 py-2.5">Equipo</th>
        <th class="text-left font-semibold px-5 py-2.5">Solicitado</th>
        <th class="text-right font-semibold px-5 py-2.5">Decisión</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="px-5 py-10 text-center text-muted">No hay solicitudes pendientes. 🎉</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b border-gray-100 last:border-0">
          <td class="px-5 py-3 font-mono text-gray-700"><?= htmlspecialchars($r['cc'] ?? '—') ?></td>
          <td class="px-5 py-3 text-dark"><?= htmlspecialchars($r['display_name'] ?: 'Sin nombre') ?></td>
          <td class="px-5 py-3 font-mono text-xs text-gray-600"><?= htmlspecialchars($r['device_name'] ?: substr((string)$r['device_guid'],0,8) ?: '—') ?></td>
          <td class="px-5 py-3 text-xs text-muted"><?= htmlspecialchars(substr((string)$r['created_at'],0,16)) ?></td>
          <td class="px-5 py-3">
            <div class="flex items-center justify-end gap-2">
              <form method="post" onsubmit="return confirm('¿Aprobar el acceso de esta persona?')">
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="decision" value="approve">
                <button class="px-3 py-1.5 text-xs font-medium rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white">Aprobar</button>
              </form>
              <form method="post" onsubmit="return confirm('¿Rechazar esta solicitud?')">
                <input type="hidden" name="user_id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="decision" value="reject">
                <button class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">Rechazar</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/layout_footer.php'; ?>
