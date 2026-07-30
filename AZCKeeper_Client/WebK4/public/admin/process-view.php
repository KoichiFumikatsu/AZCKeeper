<?php
require_once __DIR__ . '/admin_auth.php';   // deja $adminUser y $pdo
require_once __DIR__ . '/../../src/Repos/ProcessViewRepo.php';

use Keeper\Repos\ProcessViewRepo;

if (!panelCan($adminUser, 'process-view')) { header('Location: ' . panelLanding($adminUser)); exit; }

$pageTitle   = 'Vista de procesos';
$currentPage = 'process-view';

$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$scopeSql  = $firmScope ? " AND a.firma_id = " . (int)$firmScope . " " : "";

// Rango: por defecto hoy.
$today = date('Y-m-d');
$from  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $today;
$to    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $from;
if ($to < $from) $to = $from;

// Lista de personas (con actividad o no) del alcance.
$people = [];
try {
    $people = $pdo->query("
        SELECT u.id, u.display_name, u.cc, f.nombre AS firma
        FROM keeper_users u
        LEFT JOIN keeper_user_assignments a ON a.user_id = u.id
        LEFT JOIN keeper_firmas f ON f.id = a.firma_id
        WHERE u.status = 'active' $scopeSql
        ORDER BY u.display_name IS NULL, u.display_name, u.cc
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('process-view people: ' . $e->getMessage()); }

$userId = (int)($_GET['user'] ?? ($people[0]['id'] ?? 0));
// Verificar que la persona esté en el alcance (evita ver fuera de la firma por URL).
$inScope = false; $person = null;
foreach ($people as $p) { if ((int)$p['id'] === $userId) { $inScope = true; $person = $p; break; } }
if (!$inScope) { $userId = 0; $person = null; }

// Datos de la persona seleccionada.
$summary = []; $day = null; $focus = null;
if ($userId) {
    try { $summary = ProcessViewRepo::summary($pdo, $userId, $from, $to); } catch (\Throwable $e) { error_log($e->getMessage()); }
    try {
        $st = $pdo->prepare("
            SELECT MAX(activity_tracked) activity_tracked, MAX(window_tracked) window_tracked, MAX(call_tracked) call_tracked,
                   SUM(active_seconds) active_seconds, SUM(idle_seconds) idle_seconds, SUM(call_seconds) call_seconds,
                   MIN(first_event_at) first_event_at, MAX(last_event_at) last_event_at
            FROM keeper_day_summary WHERE user_id = :u AND day_date BETWEEN :f AND :t");
        $st->execute([':u'=>$userId, ':f'=>$from, ':t'=>$to]);
        $day = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { error_log($e->getMessage()); }
    try {
        $st = $pdo->prepare("
            SELECT MAX(window_tracked) window_tracked, ROUND(AVG(focus_score)) focus_score,
                   ROUND(AVG(productivity_pct)) productivity_pct, SUM(deep_work_seconds) deep_work_seconds,
                   SUM(distraction_seconds) distraction_seconds, SUM(switch_count) switch_count,
                   COUNT(focus_score) measured_days
            FROM keeper_focus_daily WHERE user_id = :u AND day_date BETWEEN :f AND :t");
        $st->execute([':u'=>$userId, ':f'=>$from, ':t'=>$to]);
        $focus = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { error_log($e->getMessage()); }
}

// Detalle (ventanas recientes). window_title se ENMASCARA por secreto profesional: el panel
// no revela títulos por defecto (el reveal con permiso + auditoría data_access llega luego).
$detail = [];
if ($userId) {
    try { $detail = ProcessViewRepo::detail($pdo, $userId, $from, $to, 12, 0); } catch (\Throwable $e) { error_log($e->getMessage()); }
}

function hms(int $s): string { $h=intdiv($s,3600); $m=intdiv($s%3600,60); return $h>0 ? "{$h}h {$m}m" : "{$m}m"; }
function maskCC(?string $cc): string { return $cc ? '•••'.substr(preg_replace('/\D/','',$cc),-4) : ''; }

$totalSecs = array_sum(array_map(fn($r)=>(int)$r['total_seconds'], $summary));
$palette = ['#2563eb','#10b981','#003a5d','#7c3aed','#f59e0b','#0891b2','#be1622','#65a30d','#9333ea','#c2410c'];

require __DIR__ . '/partials/layout_header.php';
?>

<!-- Controles: persona + rango -->
<form method="get" class="flex flex-wrap items-end gap-3 mb-6">
  <div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Persona</label>
    <select name="user" onchange="this.form.submit()" class="min-w-[15rem] px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
      <?php foreach ($people as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id']===$userId?'selected':'' ?>>
          <?= htmlspecialchars($p['display_name'] ?: ('CC '.$p['cc'])) ?><?= $p['firma'] ? ' · '.htmlspecialchars($p['firma']) : '' ?>
        </option>
      <?php endforeach; ?>
      <?php if (!$people): ?><option>Sin personas en tu alcance</option><?php endif; ?>
    </select>
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Desde</label>
    <input type="date" name="from" value="<?= htmlspecialchars($from) ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
  </div>
  <div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Hasta</label>
    <input type="date" name="to" value="<?= htmlspecialchars($to) ?>" class="px-3 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
  </div>
  <button class="px-4 py-2 bg-corp-800 hover:bg-corp-900 text-white text-sm font-medium rounded-lg">Ver</button>
</form>

<?php if (!$userId): ?>
  <div class="bg-white rounded-xl border border-gray-100 p-10 text-center text-muted">Selecciona una persona para ver su actividad.</div>
<?php else: ?>

<!-- Encabezado de la persona -->
<div class="bg-white rounded-xl border border-gray-100 p-5 mb-6 flex items-center gap-4">
  <div class="w-12 h-12 rounded-xl bg-corp-800 text-white flex items-center justify-center text-lg font-bold">
    <?= strtoupper(substr($person['display_name'] ?: 'U', 0, 1)) ?>
  </div>
  <div class="flex-1 min-w-0">
    <div class="text-lg font-semibold text-dark"><?= htmlspecialchars($person['display_name'] ?: 'Sin nombre') ?></div>
    <div class="text-sm text-muted">
      <?= htmlspecialchars($person['firma'] ?: 'Sin firma') ?> · <span class="font-mono"><?= maskCC($person['cc']) ?></span>
      <?php if ($day && $day['first_event_at']): ?> · <?= htmlspecialchars(substr($day['first_event_at'],11,5)) ?>–<?= htmlspecialchars(substr($day['last_event_at'],11,5)) ?><?php endif; ?>
    </div>
  </div>
  <div class="hidden sm:flex gap-2">
    <?php
      $flags = [
        ['Actividad', $day && $day['activity_tracked']],
        ['Ventanas',  $day && $day['window_tracked']],
        ['Llamadas',  $day && $day['call_tracked']],
      ];
      foreach ($flags as [$lbl,$on]):
    ?>
      <span class="text-xs font-medium px-2.5 py-1 rounded-lg <?= $on ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-400' ?>">
        <?= $on ? '✓' : '×' ?> <?= $lbl ?>
      </span>
    <?php endforeach; ?>
  </div>
</div>

<!-- KPIs de tiempo -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
  <?php
    $activeS = (int)($day['active_seconds'] ?? 0);
    $idleS   = (int)($day['idle_seconds'] ?? 0);
    $callS   = (int)($day['call_seconds'] ?? 0);
    $kpi = [
      ['Tiempo activo', hms($activeS), 'corp'],
      ['Inactivo', hms($idleS), 'muted'],
      ['En llamada', $day && $day['call_tracked'] ? hms($callS) : 'no medido', 'em'],
      ['Procesos distintos', (string)count($summary), 'corp'],
    ];
    foreach ($kpi as [$lbl,$val,$c]):
  ?>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
      <div class="text-2xl font-bold text-dark tabular-nums"><?= htmlspecialchars($val) ?></div>
      <div class="text-sm text-gray-500 mt-1"><?= $lbl ?></div>
    </div>
  <?php endforeach; ?>
</div>

<div class="grid lg:grid-cols-3 gap-6">
  <!-- Top procesos (2 col) -->
  <div class="lg:col-span-2 bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
      <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
      <h2 class="text-sm font-semibold text-dark">En qué pasó el tiempo</h2>
    </div>
    <div class="p-5">
      <?php if (!$summary): ?>
        <div class="text-center text-muted py-8">Sin actividad registrada en el rango.</div>
      <?php else: $i=0; foreach (array_slice($summary,0,12) as $r): $sec=(int)$r['total_seconds']; $pct = $totalSecs>0 ? round($sec*100/$totalSecs) : 0; $col=$palette[$i%count($palette)]; $i++; ?>
        <div class="flex items-center gap-3 mb-3">
          <div class="w-40 flex items-center gap-2 flex-none">
            <span class="w-2.5 h-2.5 rounded-sm flex-none" style="background:<?= $col ?>"></span>
            <span class="text-sm text-gray-700 truncate" title="<?= htmlspecialchars($r['process_name']) ?>"><?= htmlspecialchars($r['process_name']) ?></span>
          </div>
          <div class="flex-1 h-2.5 rounded-full bg-gray-100 overflow-hidden">
            <div class="h-full rounded-full" style="width:<?= max(2,$pct) ?>%;background:<?= $col ?>"></div>
          </div>
          <span class="w-16 text-right text-xs font-mono text-gray-600 flex-none"><?= hms($sec) ?></span>
          <span class="w-10 text-right text-xs text-muted flex-none tabular-nums"><?= $pct ?>%</span>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- Foco (1 col) -->
  <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 flex items-center gap-2">
      <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
      <h2 class="text-sm font-semibold text-dark">Foco</h2>
    </div>
    <div class="p-5">
      <?php
        $measured = $focus && (int)($focus['measured_days'] ?? 0) > 0 && $focus['focus_score'] !== null;
        if (!$measured):
      ?>
        <div class="text-center py-6">
          <div class="w-16 h-16 rounded-full bg-gray-100 mx-auto flex items-center justify-center text-gray-400 text-sm font-medium">—</div>
          <p class="text-sm text-gray-500 mt-3 italic">No medido</p>
          <p class="text-xs text-muted mt-1">Requiere cobertura de ventanas. No se inventa un puntaje.</p>
        </div>
      <?php else: $fs=(int)$focus['focus_score']; ?>
        <div class="flex flex-col items-center py-2">
          <div class="relative w-28 h-28" style="background:conic-gradient(#10b981 <?= $fs ?>%,#f3f4f6 0);border-radius:50%">
            <div class="absolute inset-3 bg-white rounded-full flex flex-col items-center justify-center">
              <span class="text-2xl font-bold text-dark tabular-nums"><?= $fs ?></span>
              <span class="text-xs text-muted">/ 100</span>
            </div>
          </div>
          <div class="w-full mt-4 space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-gray-500">Productividad</span><span class="font-medium text-dark"><?= $focus['productivity_pct']!==null ? (int)$focus['productivity_pct'].'%' : '—' ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Trabajo profundo</span><span class="font-medium text-dark"><?= hms((int)$focus['deep_work_seconds']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Distracción</span><span class="font-medium text-dark"><?= hms((int)$focus['distraction_seconds']) ?></span></div>
            <div class="flex justify-between"><span class="text-gray-500">Cambios de ventana</span><span class="font-medium text-dark tabular-nums"><?= (int)$focus['switch_count'] ?></span></div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Ventanas recientes (títulos enmascarados) -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mt-6">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <div class="flex items-center gap-2">
      <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
      <h2 class="text-sm font-semibold text-dark">Actividad reciente</h2>
    </div>
    <span class="text-xs text-muted">Títulos de ventana protegidos por secreto profesional</span>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Hora</th>
        <th class="text-left font-semibold px-5 py-2.5">Proceso</th>
        <th class="text-left font-semibold px-5 py-2.5">Ventana</th>
        <th class="text-left font-semibold px-5 py-2.5">Duración</th>
        <th class="text-left font-semibold px-5 py-2.5">Llamada</th>
      </tr></thead>
      <tbody>
        <?php if (!$detail): ?>
          <tr><td colspan="5" class="px-5 py-8 text-center text-muted">Sin episodios en el rango.</td></tr>
        <?php endif; ?>
        <?php foreach ($detail as $e): ?>
          <tr class="border-b border-gray-100 last:border-0">
            <td class="px-5 py-2.5 font-mono text-xs text-gray-600"><?= htmlspecialchars(substr((string)$e['start_at'],11,5)) ?></td>
            <td class="px-5 py-2.5 text-gray-700"><?= htmlspecialchars($e['process_name']) ?></td>
            <td class="px-5 py-2.5 text-gray-400 italic">[protegido]</td>
            <td class="px-5 py-2.5 font-mono text-xs text-gray-600"><?= hms((int)$e['duration_seconds']) ?></td>
            <td class="px-5 py-2.5">
              <?php if ((int)$e['is_in_call']): ?><span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">en llamada</span><?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
