<?php
/**
 * Cobertura de instalación: quién debería tener el cliente y no lo tiene.
 *
 * Es la pieza que hace sostenible la asignación manual de políticas (spec del Módulo de
 * Seguridad): responde "quién debería tener candado y no lo tiene" y "quién lo tiene y ya
 * no debería". El estado se DERIVA (nunca se escribe): equipos activos + último reporte.
 * Lo único que un admin fija a mano es el exento y su nota — y eso queda auditado.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/CoverageRepo.php';
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\CoverageRepo;
use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'coverage')) { header('Location: ' . panelLanding($adminUser)); exit; }

$adminId   = (int)$adminUser['admin_id'];
$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$firmScope = $firmScope !== null ? (int)$firmScope : null;

// ---- Acción (POST): exento / nota ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'set_note') {
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId > 0) {
        // El scope de firma también manda al escribir: un admin de firma no marca exento a ajenos.
        $allowed = true;
        if ($firmScope !== null) {
            $chk = $pdo->prepare("SELECT 1 FROM keeper_user_assignments WHERE user_id = :u AND firma_id = :f");
            $chk->execute([':u' => $userId, ':f' => $firmScope]);
            $allowed = (bool)$chk->fetchColumn();
        }
        if ($allowed) {
            $isExempt = ($_POST['is_exempt'] ?? '') === '1';
            $note     = trim((string)($_POST['note'] ?? ''));
            $note     = $note === '' ? null : mb_substr($note, 0, 512, 'UTF-8');
            CoverageRepo::setNote($pdo, $userId, $isExempt, $note, $adminId);
            try {
                AuditRepo::log($pdo, $adminId, $userId, null, 'admin', 'coverage_note',
                    $isExempt ? 'Marcado exento de cobertura' : 'Nota de cobertura actualizada',
                    ['is_exempt' => $isExempt ? 1 : 0]);
            } catch (\Throwable $e) {}
        }
    }
    $qs = http_build_query(array_filter(['r' => $_GET['r'] ?? '', 'q' => $_GET['q'] ?? '']));
    header('Location: coverage.php' . ($qs ? "?$qs" : '')); exit;
}

$pageTitle = 'Cobertura de instalación'; $currentPage = 'coverage';

$rows = [];
try { $rows = CoverageRepo::list($pdo, CoverageRepo::STALE_DAYS, $firmScope); }
catch (\Throwable $e) { error_log('coverage list: ' . $e->getMessage()); }

// Resumen sobre el universo completo del scope; los filtros solo recortan la tabla.
$counts = ['covered' => 0, 'never_enrolled' => 0, 'no_report' => 0, 'stale' => 0, 'exempt' => 0];
foreach ($rows as $r) { $counts[$r['reason']] = ($counts[$r['reason']] ?? 0) + 1; }
$total      = count($rows);
$accionable = $counts['never_enrolled'] + $counts['no_report'] + $counts['stale'];

$filter = (string)($_GET['r'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$view   = array_values(array_filter($rows, function ($r) use ($filter, $q) {
    if ($filter !== '' && $r['reason'] !== $filter) return false;
    if ($q !== '') {
        $hay = mb_strtolower(($r['display_name'] ?? '') . ' ' . ($r['cc'] ?? '') . ' ' . ($r['firma'] ?? ''), 'UTF-8');
        if (mb_strpos($hay, mb_strtolower($q, 'UTF-8')) === false) return false;
    }
    return true;
}));

$meta = [
    'covered'        => ['Cubierto',       'text-emerald-700 bg-emerald-50'],
    'stale'          => ['Sin reportar',   'text-amber-700 bg-amber-50'],
    'no_report'      => ['Nunca reportó',  'text-amber-700 bg-amber-50'],
    'never_enrolled' => ['Sin instalar',   'text-accent-500 bg-accent-500/10'],
    'exempt'         => ['Exento',         'text-gray-500 bg-gray-100'],
];

function coverageLink(string $r, string $q): string {
    $qs = http_build_query(array_filter(['r' => $r, 'q' => $q]));
    return 'coverage.php' . ($qs ? "?$qs" : '');
}
function hace(?string $ts): string {
    if (!$ts) return '—';
    $d = (int)floor((time() - strtotime($ts)) / 86400);
    if ($d <= 0) return 'hoy';
    return $d === 1 ? 'ayer' : "hace {$d} d";
}

require __DIR__ . '/partials/layout_header.php';
?>

<!-- Resumen (tarjetas clicables = filtro) -->
<div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-6">
  <?php
  $cards = [
    ['', 'Personas activas', $total, 'text-dark'],
    ['never_enrolled', 'Sin instalar', $counts['never_enrolled'], 'text-accent-500'],
    ['no_report', 'Nunca reportó', $counts['no_report'], 'text-amber-600'],
    ['stale', 'Sin reportar +' . CoverageRepo::STALE_DAYS . 'd', $counts['stale'], 'text-amber-600'],
    ['covered', 'Cubiertos', $counts['covered'], 'text-emerald-600'],
  ];
  foreach ($cards as [$key, $label, $val, $color]):
    $on = $filter === $key;
  ?>
    <a href="<?= htmlspecialchars(coverageLink($key, $q)) ?>"
       class="bg-white rounded-xl border p-4 transition-colors <?= $on ? 'border-corp-400 ring-1 ring-corp-200' : 'border-gray-100 hover:border-gray-200' ?>">
      <p class="text-xs text-muted"><?= htmlspecialchars($label) ?></p>
      <p class="text-2xl font-semibold tabular-nums mt-1 <?= $color ?>"><?= (int)$val ?></p>
    </a>
  <?php endforeach; ?>
</div>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <p class="text-sm text-gray-600">
    <?= $accionable ?> de <?= $total ?> personas activas necesitan atención.
    <?php if ($counts['exempt']): ?>
      <a href="<?= htmlspecialchars(coverageLink('exempt', $q)) ?>" class="text-muted hover:text-corp-800"><?= (int)$counts['exempt'] ?> exentas</a>.
    <?php endif; ?>
  </p>
  <form method="get" class="flex gap-2">
    <?php if ($filter !== ''): ?><input type="hidden" name="r" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
    <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Nombre, cédula o firma…"
           class="px-3 py-1.5 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200 focus:border-corp-400">
    <button class="px-3 py-1.5 bg-corp-800 hover:bg-corp-900 text-white text-sm rounded-lg">Buscar</button>
    <?php if ($q !== '' || $filter !== ''): ?><a href="coverage.php" class="px-3 py-1.5 text-sm text-muted hover:text-dark">Limpiar</a><?php endif; ?>
  </form>
</div>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Firma</th>
        <th class="text-left font-semibold px-5 py-2.5">Cargo / Sede</th>
        <th class="text-center font-semibold px-5 py-2.5">Equipos</th>
        <th class="text-left font-semibold px-5 py-2.5">Último reporte</th>
        <th class="text-left font-semibold px-5 py-2.5">Estado</th>
        <th class="text-right font-semibold px-5 py-2.5">Exento / nota</th>
      </tr></thead>
      <tbody>
      <?php if (!$view): ?><tr><td colspan="7" class="px-5 py-10 text-center text-muted">Sin personas para este filtro.</td></tr><?php endif; ?>
      <?php foreach ($view as $r): [$lbl, $cls] = $meta[$r['reason']]; ?>
        <tr class="border-b border-gray-100 last:border-0 align-top" x-data="{open:<?= $r['note'] ? 'true' : 'false' ?>}">
          <td class="px-5 py-3">
            <div class="font-medium text-dark"><?= htmlspecialchars($r['display_name'] ?: 'Sin nombre') ?></div>
            <div class="text-xs text-muted font-mono"><?= htmlspecialchars($r['cc'] ? '•••' . substr(preg_replace('/\D/', '', $r['cc']), -4) : '') ?></div>
          </td>
          <td class="px-5 py-3 text-gray-700"><?= htmlspecialchars($r['firma'] ?: '—') ?></td>
          <td class="px-5 py-3 text-gray-600">
            <?= htmlspecialchars($r['cargo'] ?: '—') ?>
            <?php if ($r['sede']): ?><span class="text-muted"> · <?= htmlspecialchars($r['sede']) ?></span><?php endif; ?>
          </td>
          <td class="px-5 py-3 text-center tabular-nums text-gray-700"><?= (int)$r['device_count'] ?></td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars(hace($r['last_seen'])) ?></td>
          <td class="px-5 py-3"><span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $cls ?>"><?= $lbl ?></span></td>
          <td class="px-5 py-3 text-right">
            <button @click="open=!open" class="text-xs text-corp-800 hover:text-corp-600 font-medium">
              <?= $r['is_exempt'] ? 'Exento' : ($r['note'] ? 'Con nota' : 'Editar') ?> <span x-text="open?'▴':'▾'"></span>
            </button>
            <form method="post" x-show="open" class="mt-2 text-left flex flex-col gap-2 min-w-[16rem]" style="display:none"><?= csrf_field() ?>
              <input type="hidden" name="action" value="set_note">
              <input type="hidden" name="user_id" value="<?= (int)$r['user_id'] ?>">
              <label class="flex items-center gap-2 text-xs text-gray-600">
                <input type="checkbox" name="is_exempt" value="1" <?= $r['is_exempt'] ? 'checked' : '' ?> class="rounded border-gray-300">
                No requiere cliente (exento)
              </label>
              <input name="note" value="<?= htmlspecialchars((string)$r['note']) ?>" maxlength="512" placeholder="Motivo o seguimiento…"
                     class="px-2 py-1 border border-gray-200 rounded text-xs">
              <button class="self-end text-xs px-2.5 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">Guardar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-muted">
    El estado se calcula, no se declara: "sin instalar" = ningún equipo activo; "nunca reportó" = equipo
    enrolado que jamás hizo handshake; "sin reportar" = más de <?= CoverageRepo::STALE_DAYS ?> días sin
    aparecer. Marcar exento saca a la persona del conteo accionable y queda en la bitácora.
  </div>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
