<?php
require_once __DIR__ . '/admin_auth.php';   // deja $adminUser y $pdo

// El tablero de flota/seguridad es vista de IT/superadmin. Un gerente aterriza en procesos.
if (!panelCan($adminUser, 'dashboard')) { header('Location: process-view.php'); exit; }

$pageTitle   = 'Flota y seguridad';
$currentPage = 'dashboard';

// Scope por firma: firma-admin (no superadmin) solo ve su firma.
$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$scopeSql  = $firmScope ? " AND a.firma_id = " . (int)$firmScope . " " : "";

/** Estado del agente por equipo, con persona y firma. NULL en ss = nunca reportó. */
$rows = [];
try {
    $st = $pdo->query("
        SELECT d.id AS device_id, d.device_name, d.device_guid, d.last_seen_at,
               u.display_name, u.cc,
               f.nombre AS firma,
               ss.agent_present, ss.agent_elevated, ss.agent_can_enforce,
               ss.agent_self_test_error, ss.agent_version, ss.agent_report_at,
               ss.agent_applied_json, ss.agent_failed_json,
               TIMESTAMPDIFF(MINUTE, ss.agent_report_at, UTC_TIMESTAMP()) AS report_age_min
        FROM keeper_devices d
        INNER JOIN keeper_users u ON u.id = d.user_id
        LEFT JOIN keeper_user_assignments a ON a.user_id = u.id
        LEFT JOIN keeper_firmas f ON f.id = a.firma_id
        LEFT JOIN keeper_security_state ss ON ss.device_id = d.id
        WHERE d.status = 'active' $scopeSql
        ORDER BY (ss.agent_present = 1 AND ss.agent_can_enforce = 0) DESC,  -- rojos primero
                 ss.agent_report_at IS NULL, u.display_name
    ");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('dashboard query: ' . $e->getMessage()); }

/** Clasifica el estado que pinta el panel a partir de las columnas. */
function agentState(array $r): array {
    $present = (int)($r['agent_present'] ?? 0) === 1 && $r['agent_report_at'] !== null;
    if (!$present)                                   return ['gray','Sin agente'];
    if (($r['report_age_min'] ?? 999) > 15)          return ['am','Reporte viejo'];
    if ((int)$r['agent_can_enforce'] === 0)          return ['red','Sin privilegio'];
    return ['em','Aplicando'];
}
function jcount(?string $json): int {
    if (!$json) return 0;
    $a = json_decode($json, true);
    return is_array($a) ? count($a) : 0;
}

// KPIs
$total = count($rows);
$applying = $silent = $stalegone = 0;
foreach ($rows as $r) {
    [$k,] = agentState($r);
    if ($k === 'em') $applying++;
    elseif ($k === 'red') $silent++;
    else $stalegone++;
}

require __DIR__ . '/partials/layout_header.php';
?>

<div class="flex items-end justify-between flex-wrap gap-4 mb-6">
  <p class="text-sm text-muted">
    <?php if ($firmScope): ?>Firma en tu alcance<?php else: ?>Todas las firmas<?php endif; ?> ·
    <span class="font-semibold text-corp-800"><?= $total ?></span> equipos activos
  </p>
  <div class="inline-flex gap-1 bg-white rounded-lg border border-gray-200 p-1">
    <span class="px-3 py-1.5 rounded-md text-xs font-medium bg-corp-800 text-white">Hoy</span>
    <span class="px-3 py-1.5 rounded-md text-xs font-medium text-muted">Semana</span>
    <span class="px-3 py-1.5 rounded-md text-xs font-medium text-muted">Mes</span>
  </div>
</div>

<!-- STAT CARDS -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
    <div class="w-10 h-10 bg-corp-50 rounded-xl flex items-center justify-center mx-auto mb-2">
      <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
    </div>
    <div class="text-3xl font-bold text-dark"><?= $total ?></div>
    <div class="text-sm text-gray-500 mt-1">Equipos activos</div>
  </div>
  <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
    <div class="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center mx-auto mb-2">
      <svg class="w-5 h-5 text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div class="text-3xl font-bold text-dark"><?= $applying ?></div>
    <div class="text-sm text-gray-500 mt-1">Agente aplicando</div>
  </div>
  <div class="bg-white rounded-xl border p-5 text-center <?= $silent > 0 ? 'border-accent-500 ring-1 ring-accent-500' : 'border-gray-100' ?>">
    <div class="w-10 h-10 bg-accent-500/10 rounded-xl flex items-center justify-center mx-auto mb-2">
      <svg class="w-5 h-5 text-accent-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
    </div>
    <div class="text-3xl font-bold <?= $silent > 0 ? 'text-accent-500' : 'text-dark' ?>"><?= $silent ?></div>
    <div class="text-sm text-gray-500 mt-1">Fallo silencioso</div>
  </div>
  <div class="bg-white rounded-xl border border-gray-100 p-5 text-center">
    <div class="w-10 h-10 bg-amber-50 rounded-xl flex items-center justify-center mx-auto mb-2">
      <svg class="w-5 h-5 text-amber-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <div class="text-3xl font-bold text-dark"><?= $stalegone ?></div>
    <div class="text-sm text-gray-500 mt-1">Sin reportar / viejo</div>
  </div>
</div>

<!-- SECURITY BOARD -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-6">
  <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
    <div class="flex items-center gap-2">
      <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
      <h2 class="text-sm font-semibold text-dark">Estado del agente por equipo</h2>
    </div>
    <span class="text-xs text-muted hidden sm:inline">Reporta si entró con permisos de admin — o si falló</span>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead>
        <tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
          <th class="text-left font-semibold px-5 py-2.5">Equipo</th>
          <th class="text-left font-semibold px-5 py-2.5">Persona</th>
          <th class="text-left font-semibold px-5 py-2.5">Firma</th>
          <th class="text-left font-semibold px-5 py-2.5">Agente</th>
          <th class="text-left font-semibold px-5 py-2.5">Elevado</th>
          <th class="text-left font-semibold px-5 py-2.5">Auto-test</th>
          <th class="text-left font-semibold px-5 py-2.5">Controles</th>
          <th class="text-left font-semibold px-5 py-2.5">Reporte</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="8" class="px-5 py-8 text-center text-muted">Sin equipos activos todavía.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r):
        [$cls, $label] = agentState($r);
        $badge = ['em'=>'text-emerald-700 bg-emerald-50','red'=>'text-accent-500 bg-accent-500/10','am'=>'text-amber-700 bg-amber-50','gray'=>'text-gray-500 bg-gray-100'][$cls];
        $dot   = ['em'=>'bg-emerald-500','red'=>'bg-accent-500','am'=>'bg-amber-500','gray'=>'bg-gray-400'][$cls];
        $applied = jcount($r['agent_applied_json'] ?? null);
        $failed  = jcount($r['agent_failed_json'] ?? null);
        $ccMask  = $r['cc'] ? '•••'.substr(preg_replace('/\D/','',$r['cc']), -4) : '';
      ?>
        <tr class="border-b border-gray-100 last:border-0 <?= $cls==='red' ? 'bg-accent-500/5' : '' ?>">
          <td class="px-5 py-3 font-mono text-xs text-gray-600"><?= htmlspecialchars($r['device_name'] ?: substr($r['device_guid'],0,8)) ?></td>
          <td class="px-5 py-3">
            <div class="font-medium text-dark"><?= htmlspecialchars($r['display_name'] ?: 'Sin nombre') ?></div>
            <div class="text-xs text-muted font-mono"><?= htmlspecialchars($ccMask) ?></div>
          </td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($r['firma'] ?: '—') ?></td>
          <td class="px-5 py-3"><span class="inline-flex items-center gap-2 text-xs font-medium px-2.5 py-1 rounded-full <?= $badge ?>"><span class="w-1.5 h-1.5 rounded-full <?= $dot ?>"></span><?= $label ?></span></td>
          <td class="px-5 py-3 font-mono text-xs">
            <?php if ($cls==='em'): ?><span class="text-emerald-600">sí</span>
            <?php elseif ($cls==='red'): ?><span class="text-accent-500">no</span>
            <?php else: ?><span class="text-gray-400">—</span><?php endif; ?>
          </td>
          <td class="px-5 py-3">
            <?php if ($cls==='red'): ?>
              <span class="font-mono text-xs text-accent-500">falló</span>
              <div class="text-xs text-accent-500 font-mono"><?= htmlspecialchars($r['agent_self_test_error'] ?: '') ?></div>
            <?php elseif ($cls==='em'): ?><span class="font-mono text-xs text-emerald-600">pasó</span>
            <?php else: ?><span class="font-mono text-xs text-gray-400">—</span><?php endif; ?>
          </td>
          <td class="px-5 py-3 text-xs text-gray-600 tabular-nums">
            <?php if ($cls==='gray'): ?>—
            <?php else: ?><?= $applied ?> aplicados<?php if ($failed>0): ?> · <span class="text-accent-500 font-medium"><?= $failed ?> fallido<?= $failed>1?'s':'' ?></span><?php endif; ?><?php endif; ?>
          </td>
          <td class="px-5 py-3 font-mono text-xs <?= $cls==='am' ? 'text-amber-700' : ($cls==='gray' ? 'text-gray-400' : 'text-gray-600') ?>">
            <?php
              if ($r['agent_report_at'] === null) echo 'nunca';
              else { $m=(int)$r['report_age_min']; echo $m<1?'hace <1 min':($m<60?"hace {$m} min":'hace '.floor($m/60).' h'); }
            ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="flex flex-wrap gap-4 px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-gray-600">
    <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-emerald-500"></span><b class="text-dark">Verde</b> elevado y aplicando</span>
    <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-accent-500"></span><b class="text-dark">Rojo</b> corre sin privilegio (fallo visible)</span>
    <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-amber-500"></span><b class="text-dark">Ámbar</b> reporte vencido</span>
    <span class="inline-flex items-center gap-1.5"><span class="w-2 h-2 rounded-full bg-gray-400"></span><b class="text-dark">Gris</b> no instalado</span>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
