<?php
/**
 * Control remoto — la capacidad que se VENDE por tier.
 *
 * Acceso: rol con el permiso 'remote-control' (RBAC editable). El contenido se filtra por
 * ALCANCE (scope de firma) y por TIER: solo aparecen equipos cuya firma compró algún módulo
 * de control (deviceLock / remoteShutdown). Un admin de firma ve SOLO su firma, y solo si la
 * firma lo tiene; superadmin/IT ven todas las firmas que lo tengan.
 *
 * Los botones (Reiniciar/Apagar/Bloquear) aparecen por equipo según el tier de SU firma.
 * "Tomar screenshot" va desactivado hasta que exista el object storage. Todo postea a
 * device-command.php, que re-valida RBAC+tier server-side.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Services/TierResolver.php';

use Keeper\Services\TierResolver;

if (!panelCan($adminUser, 'remote-control')) { header('Location: ' . panelLanding($adminUser)); exit; }

$pageTitle = 'Control remoto'; $currentPage = 'remote-control';

$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$firmScope = $firmScope !== null ? (int)$firmScope : null;
$scopeSql  = $firmScope !== null ? " AND a.firma_id = " . $firmScope . " " : "";

// Equipos activos del alcance, con su persona/firma/presencia. Un equipo por fila (el equipo
// es la unidad de control). Se filtran despues por tier de la firma.
$rows = [];
try {
    $rows = $pdo->query("
        SELECT d.id, COALESCE(d.label, d.device_name) AS equipo, d.client_version,
               UNIX_TIMESTAMP(d.last_seen_at) AS last_seen_epoch, d.last_idle_seconds,
               u.id AS user_id, u.display_name, u.cc,
               a.firma_id AS firma_id, f.nombre AS firma
        FROM keeper_devices d
        INNER JOIN keeper_users u ON u.id = d.user_id
        LEFT JOIN keeper_user_assignments a ON a.user_id = u.id
        LEFT JOIN keeper_firmas f ON f.id = a.firma_id
        WHERE d.status = 'active' $scopeSql
        ORDER BY u.display_name IS NULL, u.display_name, equipo
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('remote-control list: ' . $e->getMessage()); }

// Modulos efectivos por firma (cache). Solo se muestran equipos cuya firma tiene ALGUN
// modulo de control.
$firmMods = [];
foreach (array_unique(array_filter(array_map(fn($r) => $r['firma_id'] !== null ? (int)$r['firma_id'] : null, $rows))) as $fid) {
    $firmMods[$fid] = TierResolver::effectiveModules($pdo, $fid);
}
$controlModules = ['deviceLock', 'remoteShutdown'];
$view = array_values(array_filter($rows, function ($r) use ($firmMods, $controlModules) {
    $mods = $r['firma_id'] !== null ? ($firmMods[(int)$r['firma_id']] ?? []) : [];
    return (bool)array_intersect($controlModules, $mods);
}));

function rcHace(?int $epoch): string {
    if ($epoch === null) return 'nunca';
    $s = time() - $epoch;
    if ($s < 60) return 'hace instantes';
    $m = intdiv($s, 60); if ($m < 60) return "hace {$m} min";
    $h = intdiv($m, 60); if ($h < 24) return "hace {$h} h";
    return 'hace ' . intdiv($h, 24) . ' d';
}

require __DIR__ . '/partials/layout_header.php';
?>

<p class="text-sm text-muted mb-6">
  Solo aparecen los equipos de firmas con control remoto en su tier. Las acciones se avisan al
  usuario (60 s de gracia en apagado/reinicio) y quedan en la bitácora.
</p>

<?php if (!$view): ?>
  <div class="bg-white rounded-xl border border-gray-100 p-10 text-center text-muted">
    No hay equipos con control remoto en tu alcance. El control se habilita comprando el tier
    correspondiente para la firma (en <b>Tiers y módulos</b>).
  </div>
<?php else: ?>

<div class="space-y-3">
  <?php foreach ($view as $r):
    $mods  = $firmMods[(int)$r['firma_id']] ?? [];
    $canLock  = in_array('deviceLock', $mods, true);
    $canPower = in_array('remoteShutdown', $mods, true);
    $pr = presence($r['last_seen_epoch'] !== null ? (int)$r['last_seen_epoch'] : null,
                   $r['last_idle_seconds'] !== null ? (int)$r['last_idle_seconds'] : null, 1);
  ?>
  <div class="bg-white rounded-xl border border-gray-100 p-4 flex flex-wrap items-center gap-4">
    <!-- Identidad -->
    <div class="min-w-[14rem] flex-1">
      <div class="flex items-center gap-2">
        <span class="font-semibold text-dark"><?= htmlspecialchars($r['display_name'] ?: ('CC ' . $r['cc'])) ?></span>
        <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $pr['cls'] ?>"><?= $pr['label'] ?></span>
      </div>
      <div class="text-xs text-muted mt-0.5">
        <?= htmlspecialchars($r['equipo'] ?: 'equipo') ?>
        <?php if ($r['firma']): ?> · <?= htmlspecialchars($r['firma']) ?><?php endif; ?>
        · v<?= htmlspecialchars($r['client_version'] ?: '—') ?>
        · <?= htmlspecialchars(rcHace($r['last_seen_epoch'] !== null ? (int)$r['last_seen_epoch'] : null)) ?>
      </div>
    </div>

    <!-- Botones grandes de gestión -->
    <div class="flex flex-wrap items-center gap-2">
      <?php if ($canPower): ?>
        <form method="post" action="device-command.php" onsubmit="return confirm('¿Reiniciar el equipo de <?= htmlspecialchars(addslashes($r['display_name'] ?: 'esta persona')) ?>? 60 s de gracia.')"><?= csrf_field() ?>
          <input type="hidden" name="command_type" value="restart"><input type="hidden" name="device_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="grace_seconds" value="60"><input type="hidden" name="back" value="remote-control.php">
          <button class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            Reiniciar
          </button>
        </form>
        <form method="post" action="device-command.php" onsubmit="return confirm('¿APAGAR el equipo de <?= htmlspecialchars(addslashes($r['display_name'] ?: 'esta persona')) ?>? Se avisa al usuario con 60 s de gracia.')"><?= csrf_field() ?>
          <input type="hidden" name="command_type" value="shutdown"><input type="hidden" name="device_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="grace_seconds" value="60"><input type="hidden" name="back" value="remote-control.php">
          <button class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium border border-accent-500/40 text-accent-600 hover:bg-accent-500/5">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M5.636 5.636a9 9 0 1012.728 0M12 3v9"/></svg>
            Apagar
          </button>
        </form>
      <?php endif; ?>
      <?php if ($canLock): ?>
        <form method="post" action="device-command.php"><?= csrf_field() ?>
          <input type="hidden" name="command_type" value="lock"><input type="hidden" name="device_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="remote-control.php">
          <button class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            Bloquear
          </button>
        </form>
      <?php endif; ?>
      <!-- Screenshot: desactivado hasta el object storage -->
      <button type="button" disabled title="Disponible cuando se habilite el almacenamiento de capturas"
              class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-lg text-sm font-medium border border-gray-100 text-gray-300 cursor-not-allowed">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.7" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        Tomar screenshot
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
