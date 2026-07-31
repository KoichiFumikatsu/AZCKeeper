<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'devices')) { header('Location: ' . panelLanding($adminUser)); exit; }

// Revocar equipo (POST).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    $id = (int)($_POST['device_id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare("UPDATE keeper_devices SET status='revoked', decommissioned_at=NOW(), decommission_reason='Revocado desde el panel' WHERE id=:id")
            ->execute([':id'=>$id]);
        try { AuditRepo::log($pdo, (int)$adminUser['admin_id'], null, $id, 'admin', 'device_revoked', 'Equipo revocado desde el panel'); }
        catch (\Throwable $e) {}
    }
    header('Location: devices.php'); exit;
}

$pageTitle = 'Dispositivos'; $currentPage = 'devices';

$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$scopeSql  = $firmScope ? " AND a.firma_id = " . (int)$firmScope . " " : "";

$rows = [];
try {
    $rows = $pdo->query("
        SELECT d.id, d.device_name, d.label, d.device_guid, d.client_version, d.status, d.last_seen_at,
               u.display_name, u.cc, f.nombre AS firma,
               (SELECT COUNT(*) FROM keeper_device_command c WHERE c.device_id=d.id AND c.command_type='rename_computer' AND c.status='pending') AS rename_pending
        FROM keeper_devices d
        INNER JOIN keeper_users u ON u.id = d.user_id
        LEFT JOIN keeper_user_assignments a ON a.user_id = u.id
        LEFT JOIN keeper_firmas f ON f.id = a.firma_id
        WHERE 1=1 $scopeSql
        ORDER BY d.status='revoked', d.last_seen_at IS NULL, d.last_seen_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('devices list: '.$e->getMessage()); }

require __DIR__ . '/partials/layout_header.php';
?>
<p class="text-sm text-muted mb-6">
  El nombre editable aquí es la <b>etiqueta del panel</b>. Cambiar el <b>nombre real de Windows</b> se hace con el botón
  <span class="text-corp-800 font-medium">Renombrar equipo</span>: encola un comando que aplica el <b>agente elevado</b> (requiere admin y <b>reinicio</b>).
</p>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden" x-data="{cmd:0}">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Equipo</th>
        <th class="text-left font-semibold px-5 py-2.5">Persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Firma</th>
        <th class="text-left font-semibold px-5 py-2.5">Versión</th>
        <th class="text-left font-semibold px-5 py-2.5">Última señal</th>
        <th class="text-left font-semibold px-5 py-2.5">Estado</th>
        <th class="text-right font-semibold px-5 py-2.5">Acciones</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="7" class="px-5 py-10 text-center text-muted">Sin dispositivos.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
        $machine = $r['device_name'] ?: substr((string)$r['device_guid'],0,8);
        $shown   = $r['label'] ?: $machine;
        $revoked = $r['status'] === 'revoked';
      ?>
        <tr class="border-b border-gray-100 last:border-0 <?= $revoked ? 'opacity-60' : '' ?>" x-data="{edit:false, rename:false}">
          <td class="px-5 py-3">
            <!-- Nombre mostrado + editar ETIQUETA del panel -->
            <div x-show="!edit" class="flex items-center gap-1.5 group">
              <div>
                <span class="font-mono text-xs text-gray-700"><?= htmlspecialchars($shown) ?></span>
                <?php if ($r['label']): ?><div class="text-[10px] text-gray-400 font-mono"><?= htmlspecialchars($machine) ?></div><?php endif; ?>
              </div>
              <button @click="edit=true" class="opacity-0 group-hover:opacity-100 text-gray-400 hover:text-corp-800" title="Editar etiqueta del panel">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
              </button>
            </div>
            <form x-show="edit" method="post" action="device-rename.php" class="flex items-center gap-1" style="display:none"><?= csrf_field() ?>
              <input type="hidden" name="device_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="devices.php">
              <input name="label" value="<?= htmlspecialchars($r['label'] ?? '') ?>" placeholder="<?= htmlspecialchars($machine) ?>" class="px-2 py-1 border border-gray-300 rounded text-xs w-32">
              <button class="text-emerald-600" title="Guardar etiqueta">✓</button>
              <button type="button" @click="edit=false" class="text-gray-400">✕</button>
            </form>
          </td>
          <td class="px-5 py-3">
            <div class="text-dark"><?= htmlspecialchars($r['display_name'] ?: 'Sin nombre') ?></div>
            <div class="text-xs text-muted font-mono"><?= htmlspecialchars($r['cc'] ? '•••'.substr(preg_replace('/\D/','',$r['cc']),-4) : '') ?></div>
          </td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['firma'] ?: '—') ?></td>
          <td class="px-5 py-3 font-mono text-xs text-gray-600"><?= htmlspecialchars($r['client_version'] ?: '—') ?></td>
          <td class="px-5 py-3 text-xs text-gray-600"><?= $r['last_seen_at'] ? htmlspecialchars(substr($r['last_seen_at'],0,16)) : '<span class="text-muted">nunca</span>' ?></td>
          <td class="px-5 py-3">
            <?php if ($revoked): ?><span class="text-xs font-medium text-accent-500 bg-accent-500/10 px-2 py-0.5 rounded-full">Revocado</span>
            <?php else: ?><span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activo</span><?php endif; ?>
          </td>
          <td class="px-5 py-3">
            <?php if (!$revoked): ?>
            <div class="flex items-center justify-end gap-2">
              <?php if ((int)$r['rename_pending'] > 0): ?>
                <span class="text-xs text-amber-700 bg-amber-50 px-2 py-1 rounded-lg" title="El equipo aplicará el nuevo nombre en su próximo ciclo y reinicio">renombre pendiente</span>
              <?php else: ?>
                <button @click="rename=!rename" class="text-xs text-corp-800 hover:text-corp-600 font-medium">Renombrar equipo</button>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('¿Revocar este equipo? Dejará de reportar.')"><?= csrf_field() ?>
                <input type="hidden" name="action" value="revoke"><input type="hidden" name="device_id" value="<?= (int)$r['id'] ?>">
                <button class="text-xs text-gray-400 hover:text-accent-500">Revocar</button>
              </form>
            </div>
            <!-- Form de renombre de Windows (comando) -->
            <form x-show="rename" method="post" action="device-command.php" class="flex items-center justify-end gap-1 mt-2" style="display:none"
                  onsubmit="return confirm('Se enviará el nuevo nombre de Windows al equipo. Requiere permisos de admin y un reinicio para aplicarse. ¿Continuar?')"><?= csrf_field() ?>
              <input type="hidden" name="command_type" value="rename_computer"><input type="hidden" name="device_id" value="<?= (int)$r['id'] ?>">
              <input name="new_name" maxlength="15" placeholder="NUEVO-NOMBRE" class="px-2 py-1 border border-gray-300 rounded text-xs w-36 uppercase" pattern="[A-Za-z0-9\-]{1,15}" title="Máx 15, letras/números/guion (regla NetBIOS)">
              <button class="text-xs px-2 py-1 bg-corp-800 text-white rounded">Enviar</button>
              <button type="button" @click="rename=false" class="text-gray-400 text-xs">✕</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/layout_footer.php'; ?>
