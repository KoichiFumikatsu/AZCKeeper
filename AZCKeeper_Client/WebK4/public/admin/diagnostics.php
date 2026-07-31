<?php
/**
 * Diagnóstico en vivo por persona.
 *
 * IT marca a una persona -> su cliente entra en modo diagnóstico (lo sabe en el próximo
 * handshake) y sube un snapshot cada ~4 s con el estado ESPERADO vs REAL de cada módulo, el
 * flujo de logs, la actividad en crudo y la salud de red. Esta página muestra el snapshot más
 * reciente y se auto-refresca por fetch cada ~4 s (el hosting no da streaming; esto es
 * near-real-time por sondeo). Todo efímero: los snapshots se purgan a las 2 h y el flag se
 * auto-apaga a las 4 h.
 *
 * Tres modos de entrada:
 *  - ?data=1&user=<id>  -> devuelve JSON {session, snapshot} para el auto-refresh (AJAX).
 *  - POST action=toggle -> enciende/apaga el flag de la persona.
 *  - GET normal         -> render de la página (selector + vista en vivo).
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/DiagnosticRepo.php';
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\DiagnosticRepo;
use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'diagnostics')) { header('Location: ' . panelLanding($adminUser)); exit; }

$adminId   = (int)$adminUser['admin_id'];
$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$firmScope = $firmScope !== null ? (int)$firmScope : null;
$scopeSql  = $firmScope !== null ? " AND a.firma_id = " . $firmScope . " " : "";

/** Confirma que una persona está en el alcance del admin (no ver fuera de la firma por URL). */
function diagUserInScope(PDO $pdo, int $userId, ?int $firmScope): bool {
    if ($userId <= 0) return false;
    if ($firmScope === null) {
        $st = $pdo->prepare("SELECT 1 FROM keeper_users WHERE id = :u");
        $st->execute([':u' => $userId]);
    } else {
        $st = $pdo->prepare("SELECT 1 FROM keeper_users u
                             JOIN keeper_user_assignments a ON a.user_id = u.id
                             WHERE u.id = :u AND a.firma_id = :f");
        $st->execute([':u' => $userId, ':f' => $firmScope]);
    }
    return (bool)$st->fetchColumn();
}

// ---- Modo AJAX: datos en vivo de una persona ----
if (isset($_GET['data'])) {
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int)($_GET['user'] ?? 0);
    if (!diagUserInScope($pdo, $uid, $firmScope)) { echo json_encode(['ok' => false]); exit; }

    $session  = DiagnosticRepo::activeSession($pdo, $uid);
    $snapRow  = DiagnosticRepo::latestSnapshot($pdo, $uid);
    $snapshot = null;
    if ($snapRow) {
        $snapshot = [
            'capturedAt' => $snapRow['captured_at'],
            'clientTs'   => $snapRow['client_ts'],
            'ageSeconds' => max(0, time() - (int)$snapRow['captured_epoch']),
            'payload'    => json_decode($snapRow['payload'], true),
        ];
    }
    echo json_encode([
        'ok'      => true,
        'active'  => $session !== null,
        'expires' => $session['expires_at'] ?? null,
        'snapshot'=> $snapshot,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---- Acción: encender / apagar el diagnóstico de una persona ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $on  = ($_POST['on'] ?? '') === '1';
    if (diagUserInScope($pdo, $uid, $firmScope)) {
        if ($on) {
            DiagnosticRepo::setSession($pdo, $uid, $adminId);
            AuditRepo::log($pdo, $adminId, $uid, null, 'admin', 'diagnostic_on',
                'Diagnóstico en vivo activado (4h)');
        } else {
            DiagnosticRepo::clearSession($pdo, $uid);
            AuditRepo::log($pdo, $adminId, $uid, null, 'admin', 'diagnostic_off',
                'Diagnóstico en vivo desactivado');
        }
    }
    header('Location: diagnostics.php?user=' . $uid); exit;
}

$pageTitle = 'Diagnóstico en vivo'; $currentPage = 'diagnostics';

// Personas del alcance (para el selector).
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
} catch (\Throwable $e) { error_log('diagnostics people: ' . $e->getMessage()); }

$userId = (int)($_GET['user'] ?? 0);
$person = null;
foreach ($people as $p) { if ((int)$p['id'] === $userId) { $person = $p; break; } }
if (!$person) $userId = 0;

$session = $userId ? DiagnosticRepo::activeSession($pdo, $userId) : null;

require __DIR__ . '/partials/layout_header.php';
?>

<!-- Selector de persona -->
<form method="get" class="mb-5 flex flex-wrap items-end gap-3">
  <div>
    <label class="block text-xs font-medium text-gray-600 mb-1">Persona a diagnosticar</label>
    <select name="user" onchange="this.form.submit()" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white min-w-[18rem]">
      <option value="0">— elegir persona —</option>
      <?php foreach ($people as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $userId === (int)$p['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['display_name'] ?: ('CC ' . $p['cc'])) ?><?= $p['firma'] ? ' · ' . htmlspecialchars($p['firma']) : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<?php if (!$userId): ?>
  <div class="bg-white rounded-xl border border-gray-100 p-10 text-center text-muted">
    Elige una persona para ver su diagnóstico en vivo. Recuerda: el modo diagnóstico consume
    red del equipo, enciéndelo solo cuando estés investigando.
  </div>
<?php else: ?>

<div x-data="diag(<?= (int)$userId ?>)" x-init="start()">

  <!-- Cabecera: persona + toggle + estado del canal -->
  <div class="bg-white rounded-xl border border-gray-100 p-5 mb-5 flex flex-wrap items-center justify-between gap-4">
    <div>
      <h2 class="text-base font-semibold text-dark"><?= htmlspecialchars($person['display_name'] ?: ('CC ' . $person['cc'])) ?></h2>
      <p class="text-xs text-muted mt-0.5">
        <span x-show="!active" class="text-gray-500">Diagnóstico apagado</span>
        <span x-show="active" class="text-emerald-600">
          En vivo · <span x-text="freshness"></span>
        </span>
      </p>
    </div>
    <form method="post" class="flex-none"><?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle">
      <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
      <input type="hidden" name="on" value="<?= $session ? '0' : '1' ?>">
      <button class="px-4 py-2 rounded-lg text-sm font-medium <?= $session ? 'bg-accent-500 hover:bg-accent-600 text-white' : 'bg-corp-800 hover:bg-corp-900 text-white' ?>">
        <?= $session ? 'Apagar diagnóstico' : 'Encender diagnóstico (4h)' ?>
      </button>
    </form>
  </div>

  <!-- Aviso mientras no llega el primer snapshot -->
  <div x-show="active && !snap" class="bg-amber-50 border border-amber-200 text-amber-700 rounded-xl p-4 mb-5 text-sm">
    Esperando el primer snapshot. El equipo entra en modo diagnóstico en su próximo handshake
    (hasta ~5 min). Si tras eso no aparece nada, el equipo puede estar apagado o sin red.
  </div>
  <div x-show="active && snap && stale" class="bg-amber-50 border border-amber-200 text-amber-700 rounded-xl p-4 mb-5 text-sm">
    El cliente no reporta hace <span x-text="ageText"></span>. Puede estar caído, sin red o en backoff.
  </div>

  <div class="grid lg:grid-cols-2 gap-5">

    <!-- Bloque 1: módulos esperado vs real -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100"><h3 class="text-sm font-semibold text-dark">Módulos — esperado vs real</h3></div>
      <div class="p-3">
        <template x-if="!snap"><p class="text-sm text-muted p-2">Sin datos.</p></template>
        <template x-for="m in (snap?.modules || [])" :key="m.code">
          <div class="flex items-center justify-between px-2 py-2 border-b border-gray-50 last:border-0">
            <div class="flex items-center gap-2">
              <span class="w-2.5 h-2.5 rounded-full"
                    :class="m.expected === m.running ? 'bg-emerald-500' : 'bg-accent-500'"></span>
              <span class="text-sm text-gray-700" x-text="m.code"></span>
            </div>
            <div class="flex items-center gap-3 text-xs">
              <span :class="m.expected ? 'text-gray-600' : 'text-gray-400'">esperado: <span x-text="m.expected ? 'ON' : 'OFF'"></span></span>
              <span :class="m.running ? 'text-emerald-600' : 'text-gray-400'">real: <span x-text="m.running ? 'ON' : 'OFF'"></span></span>
              <span x-show="m.lastError" class="text-accent-500 truncate max-w-[10rem]" :title="m.lastError" x-text="m.lastError"></span>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Bloque 4: salud de red (arriba a la derecha, es lo que más se mira) -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100"><h3 class="text-sm font-semibold text-dark">Salud de red / handshake / update</h3></div>
      <div class="p-4 grid grid-cols-2 gap-3 text-sm">
        <template x-if="!snap"><p class="text-sm text-muted col-span-2">Sin datos.</p></template>
        <template x-if="snap">
          <div class="col-span-2 grid grid-cols-2 gap-3">
            <div><p class="text-xs text-muted">Backoff</p><p :class="snap.net?.backingOff ? 'text-accent-500' : 'text-emerald-600'" x-text="snap.net?.backingOff ? ('sí · hasta ' + (snap.net?.backoffUntil||'')) : 'no'"></p></div>
            <div><p class="text-xs text-muted">Último handshake</p><p class="text-gray-700" x-text="(snap.net?.lastHandshakeStatus ?? '—')"></p></div>
            <div><p class="text-xs text-muted">Cola offline</p><p class="text-gray-700 tabular-nums" x-text="(snap.net?.queueDepth ?? 0)"></p></div>
            <div><p class="text-xs text-muted">Versión</p><p class="text-gray-700 font-mono" x-text="(snap.net?.version || '—')"></p></div>
          </div>
        </template>
      </div>
    </div>

    <!-- Bloque 3: actividad en crudo -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100"><h3 class="text-sm font-semibold text-dark">Actividad capturada ahora</h3></div>
      <div class="p-4 grid grid-cols-2 gap-3 text-sm">
        <template x-if="!snap"><p class="text-sm text-muted col-span-2">Sin datos.</p></template>
        <template x-if="snap">
          <div class="col-span-2 grid grid-cols-2 gap-3">
            <div><p class="text-xs text-muted">Proceso en foco</p><p class="text-gray-700 font-mono" x-text="(snap.activity?.process || '—')"></p></div>
            <div><p class="text-xs text-muted">Inactividad</p><p class="text-gray-700 tabular-nums" x-text="(snap.activity?.idleSeconds ?? '—') + ' s'"></p></div>
            <div class="col-span-2"><p class="text-xs text-muted">Título de la ventana</p><p class="text-gray-700 truncate" :title="snap.activity?.title" x-text="(snap.activity?.title || '—')"></p></div>
          </div>
        </template>
      </div>
    </div>

    <!-- Bloque 2: logs en vivo -->
    <div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
      <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <h3 class="text-sm font-semibold text-dark">Logs en vivo</h3>
        <span class="text-xs text-muted" x-text="(snap?.logs?.length || 0) + ' líneas'"></span>
      </div>
      <div class="p-2 font-mono text-[11px] leading-relaxed max-h-72 overflow-y-auto bg-gray-50">
        <template x-if="!snap?.logs?.length"><p class="text-muted p-2">Sin logs en este snapshot.</p></template>
        <template x-for="(l, i) in (snap?.logs || [])" :key="i">
          <div class="px-2 py-0.5 whitespace-pre-wrap"
               :class="{'text-accent-600': l.level==='Error', 'text-amber-600': l.level==='Warn', 'text-gray-600': l.level==='Info' || !l.level, 'text-gray-400': l.level==='Debug'}">
            <span class="text-gray-400" x-text="(l.ts||'').toString().substring(11,19)"></span>
            <span class="font-semibold" x-text="'[' + (l.level||'Info') + ']'"></span>
            <span x-show="l.source" class="text-corp-700" x-text="l.source"></span>
            <span x-text="l.message"></span>
          </div>
        </template>
      </div>
    </div>

  </div>

  <script>
    function diag(userId) {
      return {
        active: <?= $session ? 'true' : 'false' ?>,
        snap: null, ageSeconds: 0, timer: null,
        get stale() { return this.snap && this.ageSeconds > 30; },
        get ageText() { return this.ageSeconds + ' s'; },
        get freshness() { return this.snap ? ('último dato hace ' + this.ageSeconds + ' s') : 'esperando…'; },
        async tick() {
          try {
            const r = await fetch('diagnostics.php?data=1&user=' + userId, {headers:{'X-Requested-With':'fetch'}});
            const j = await r.json();
            if (!j.ok) return;
            this.active = j.active;
            this.snap = j.snapshot ? j.snapshot.payload : null;
            this.ageSeconds = j.snapshot ? j.snapshot.ageSeconds : 0;
          } catch (e) { /* transitorio: reintenta al próximo tick */ }
        },
        start() { this.tick(); this.timer = setInterval(() => this.tick(), 4000); }
      };
    }
  </script>
</div>
<?php endif; ?>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
