<?php
/**
 * Doble empleo: alertas y su ciclo de revisión.
 *
 * Las alertas las produce el cron (DualJobDetector) desde keeper_episode contra las apps
 * marcadas como señal en keeper_app_classification. Por eso la página trae TAMBIÉN el
 * catálogo de señales: sin ninguna app marcada el detector no dispara nunca, y esa lista
 * arranca vacía (el seed clasifica ocio/productivo, pero ningún patrón como señal). Sin
 * esta sección la feature se ve encendida y no lo está.
 *
 * El recálculo del día no pisa lo revisado (DualJobRepo), así que marcar una alerta aquí
 * es una decisión que sobrevive al siguiente cron.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'dual-job')) { header('Location: ' . panelLanding($adminUser)); exit; }

$adminId   = (int)$adminUser['admin_id'];
$firmScope = (($adminUser['panel_role'] ?? '') !== 'superadmin') ? ($adminUser['firma_scope_id'] ?? null) : null;
$firmScope = $firmScope !== null ? (int)$firmScope : null;

$STATES = ['open' => 'Abierta', 'reviewed' => 'Revisada', 'confirmed' => 'Confirmada', 'dismissed' => 'Descartada'];

// ---- Acciones (POST) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_status') {
        $id  = (int)($_POST['alert_id'] ?? 0);
        $new = (string)($_POST['status'] ?? '');
        if ($id > 0 && isset($STATES[$new])) {
            // El scope de firma también manda al escribir.
            $sql = "SELECT a.id, a.user_id FROM keeper_dual_job_alert a";
            if ($firmScope !== null) $sql .= " JOIN keeper_user_assignments ua ON ua.user_id = a.user_id AND ua.firma_id = " . $firmScope;
            $sql .= " WHERE a.id = :id";
            $st = $pdo->prepare($sql); $st->execute([':id' => $id]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
                $pdo->prepare("UPDATE keeper_dual_job_alert
                               SET status = :s, reviewed_by = :by, reviewed_at = NOW() WHERE id = :id")
                    ->execute([':s' => $new, ':by' => $adminId, ':id' => $id]);
                try {
                    AuditRepo::log($pdo, $adminId, (int)$row['user_id'], null, 'security', 'dual_job_review',
                        'Alerta de doble empleo marcada como ' . $STATES[$new], ['alert_id' => $id, 'status' => $new]);
                } catch (\Throwable $e) {}
            }
        }
    } elseif ($action === 'add_signal') {
        $type    = ($_POST['match_type'] ?? 'process') === 'title_keyword' ? 'title_keyword' : 'process';
        $pattern = mb_strtolower(trim((string)($_POST['pattern'] ?? '')), 'UTF-8');
        $pattern = mb_substr($pattern, 0, 190, 'UTF-8');
        if ($pattern !== '') {
            // La app puede existir ya clasificada (p.ej. como 'neutral'): entonces solo se marca la señal.
            $pdo->prepare("INSERT INTO keeper_app_classification (match_type, pattern, category, is_dual_job_signal, updated_by)
                           VALUES (:t, :p, 'neutral', 1, :by)
                           ON DUPLICATE KEY UPDATE is_dual_job_signal = 1, updated_by = VALUES(updated_by)")
                ->execute([':t' => $type, ':p' => $pattern, ':by' => $adminId]);
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'dual_job_signal_add',
                    "Señal de doble empleo: {$type} = {$pattern}"); } catch (\Throwable $e) {}
        }
    } elseif ($action === 'drop_signal') {
        // Se quita la MARCA, no la clasificación: la app sigue contando como ocio/productiva.
        $id = (int)($_POST['class_id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("UPDATE keeper_app_classification SET is_dual_job_signal = 0, updated_by = :by WHERE id = :id")
                ->execute([':by' => $adminId, ':id' => $id]);
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'dual_job_signal_drop',
                    "Señal de doble empleo retirada (id {$id})"); } catch (\Throwable $e) {}
        }
    }
    $qs = http_build_query(array_filter(['s' => $_GET['s'] ?? '', 'd' => $_GET['d'] ?? '']));
    header('Location: dual-job.php' . ($qs ? "?$qs" : '')); exit;
}

$pageTitle = 'Doble empleo'; $currentPage = 'dual-job';

$fStatus = (string)($_GET['s'] ?? 'open');           // por defecto, lo que exige acción
$days    = max(1, min(365, (int)($_GET['d'] ?? 30)));
$since   = date('Y-m-d', strtotime("-{$days} days"));

$where  = ["a.day_date >= :since"];
$params = [':since' => $since];
if (isset($STATES[$fStatus])) { $where[] = "a.status = :st"; $params[':st'] = $fStatus; }
if ($firmScope !== null)      { $where[] = "ua.firma_id = " . $firmScope; }

$alerts = [];
try {
    $st = $pdo->prepare("
        SELECT a.id, a.user_id, a.device_id, a.day_date, a.alert_type, a.evidence_json,
               a.status, a.reviewed_at, u.display_name, u.cc,
               COALESCE(d.label, d.device_name) AS device, f.nombre AS firma,
               ad.display_name AS reviewer_name, ad.email AS reviewer_email
        FROM keeper_dual_job_alert a
        JOIN keeper_users u              ON u.id  = a.user_id
        LEFT JOIN keeper_user_assignments ua ON ua.user_id = a.user_id
        LEFT JOIN keeper_firmas f        ON f.id  = ua.firma_id
        LEFT JOIN keeper_devices d       ON d.id  = a.device_id
        LEFT JOIN keeper_admin_accounts ad ON ad.id = a.reviewed_by
        WHERE " . implode(' AND ', $where) . "
        ORDER BY a.day_date DESC, a.id DESC
        LIMIT 300");
    $st->execute($params);
    $alerts = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('dual-job list: ' . $e->getMessage()); }

// Conteo por estado dentro de la ventana (para las pestañas), con el mismo scope.
$tabCounts = [];
try {
    $sql = "SELECT a.status, COUNT(*) c FROM keeper_dual_job_alert a";
    if ($firmScope !== null) $sql .= " JOIN keeper_user_assignments ua ON ua.user_id = a.user_id AND ua.firma_id = " . $firmScope;
    $sql .= " WHERE a.day_date >= :since GROUP BY a.status";
    $st = $pdo->prepare($sql); $st->execute([':since' => $since]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $tabCounts[$r['status']] = (int)$r['c'];
} catch (\Throwable $e) {}

$signals = [];
try {
    $signals = $pdo->query("SELECT id, match_type, pattern, category FROM keeper_app_classification
                            WHERE is_dual_job_signal = 1 ORDER BY match_type, pattern")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}

$statusPill = [
    'open'      => 'text-amber-700 bg-amber-50',
    'confirmed' => 'text-accent-500 bg-accent-500/10',
    'reviewed'  => 'text-corp-800 bg-corp-50',
    'dismissed' => 'text-gray-500 bg-gray-100',
];
function dur(int $s): string {
    if ($s < 60) return "{$s}s";
    $m = intdiv($s, 60); if ($m < 60) return "{$m} min";
    return sprintf('%d h %02d', intdiv($m, 60), $m % 60);
}
function djLink(string $s, int $d): string { return 'dual-job.php?' . http_build_query(['s' => $s, 'd' => $d]); }

require __DIR__ . '/partials/layout_header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <div class="flex flex-wrap gap-1.5">
    <?php foreach (['open' => 'Abiertas', 'confirmed' => 'Confirmadas', 'reviewed' => 'Revisadas', 'dismissed' => 'Descartadas', '' => 'Todas'] as $k => $lbl):
      $on = $fStatus === $k; $c = $k === '' ? array_sum($tabCounts) : ($tabCounts[$k] ?? 0); ?>
      <a href="<?= htmlspecialchars(djLink($k, $days)) ?>"
         class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors <?= $on ? 'bg-corp-800 text-white' : 'bg-white border border-gray-200 text-gray-600 hover:border-gray-300' ?>">
        <?= $lbl ?> <span class="tabular-nums opacity-70">(<?= $c ?>)</span>
      </a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="flex items-center gap-2 text-sm">
    <input type="hidden" name="s" value="<?= htmlspecialchars($fStatus) ?>">
    <label class="text-xs text-muted">Últimos</label>
    <select name="d" onchange="this.form.submit()" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
      <?php foreach ([7, 30, 90, 365] as $opt): ?>
        <option value="<?= $opt ?>" <?= $days === $opt ? 'selected' : '' ?>><?= $opt ?> días</option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-6">
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Día</th>
        <th class="text-left font-semibold px-5 py-2.5">Evidencia</th>
        <th class="text-left font-semibold px-5 py-2.5">Equipo</th>
        <th class="text-left font-semibold px-5 py-2.5">Estado</th>
        <th class="text-right font-semibold px-5 py-2.5">Decisión</th>
      </tr></thead>
      <tbody>
      <?php if (!$alerts): ?>
        <tr><td colspan="6" class="px-5 py-10 text-center text-muted">
          Sin alertas en la ventana. <?= $signals ? '' : 'No hay ninguna app marcada como señal todavía — abajo se define.' ?>
        </td></tr>
      <?php endif; ?>
      <?php foreach ($alerts as $a):
        $ev    = json_decode((string)$a['evidence_json'], true) ?: [];
        $total = (int)($ev['total_seconds'] ?? 0);
        $apps  = is_array($ev['apps'] ?? null) ? $ev['apps'] : [];
      ?>
        <tr class="border-b border-gray-100 last:border-0 align-top">
          <td class="px-5 py-3">
            <div class="font-medium text-dark"><?= htmlspecialchars($a['display_name'] ?: 'Sin nombre') ?></div>
            <div class="text-xs text-muted"><?= htmlspecialchars($a['firma'] ?: '—') ?></div>
          </td>
          <td class="px-5 py-3 text-gray-600 whitespace-nowrap"><?= htmlspecialchars($a['day_date']) ?></td>
          <td class="px-5 py-3">
            <div class="text-gray-700 font-medium tabular-nums"><?= htmlspecialchars(dur($total)) ?> en señales</div>
            <div class="flex flex-wrap gap-1 mt-1">
              <?php foreach ($apps as $app => $secs): ?>
                <span class="text-[11px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">
                  <?= htmlspecialchars((string)$app) ?> · <?= htmlspecialchars(dur((int)$secs)) ?>
                </span>
              <?php endforeach; ?>
            </div>
            <a href="process-view.php?user=<?= (int)$a['user_id'] ?>&from=<?= htmlspecialchars($a['day_date']) ?>&to=<?= htmlspecialchars($a['day_date']) ?>"
               class="text-xs text-corp-800 hover:text-corp-600 font-medium mt-1 inline-block">Ver el día completo →</a>
          </td>
          <td class="px-5 py-3 text-gray-600"><?= htmlspecialchars($a['device'] ?: '—') ?></td>
          <td class="px-5 py-3">
            <span class="text-xs font-medium px-2 py-0.5 rounded-full <?= $statusPill[$a['status']] ?? '' ?>"><?= $STATES[$a['status']] ?? $a['status'] ?></span>
            <?php if ($a['reviewed_at']): ?>
              <div class="text-[11px] text-muted mt-1">
                <?= htmlspecialchars(substr((string)$a['reviewed_at'], 0, 16)) ?>
                <?php if ($a['reviewer_name'] || $a['reviewer_email']): ?><br><?= htmlspecialchars($a['reviewer_name'] ?: $a['reviewer_email']) ?><?php endif; ?>
              </div>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 text-right">
            <form method="post" class="inline-flex items-center gap-1">
              <input type="hidden" name="action" value="set_status">
              <input type="hidden" name="alert_id" value="<?= (int)$a['id'] ?>">
              <select name="status" onchange="this.form.submit()" class="text-xs border border-gray-200 rounded px-1.5 py-1 bg-white">
                <?php foreach ($STATES as $k => $lbl): ?>
                  <option value="<?= $k ?>" <?= $a['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Catálogo de señales: lo que hace que exista una alerta -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-sm font-semibold text-dark">Aplicaciones que disparan la alerta</h2>
      <p class="text-xs text-muted mt-0.5">CRM de la competencia, otras herramientas de fichaje, apps de trabajo remoto. Mínimo 60 s de uso en el día.</p>
    </div>
    <form method="post" class="flex items-center gap-2">
      <input type="hidden" name="action" value="add_signal">
      <select name="match_type" class="px-2 py-1 border border-gray-200 rounded text-xs bg-white">
        <option value="process">Proceso</option>
        <option value="title_keyword">Palabra en el título</option>
      </select>
      <input name="pattern" placeholder="competidorcrm.exe" maxlength="190" class="px-2 py-1 border border-gray-200 rounded text-xs w-44">
      <button class="text-xs px-2.5 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">+ Señal</button>
    </form>
  </div>
  <div class="px-5 py-4">
    <?php if (!$signals): ?>
      <p class="text-sm text-muted">Ninguna señal definida: el detector no va a generar alertas hasta que se agregue al menos una.</p>
    <?php else: ?>
      <div class="flex flex-wrap gap-2">
        <?php foreach ($signals as $s): ?>
          <form method="post" class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-full bg-gray-100 text-xs text-gray-700">
            <input type="hidden" name="action" value="drop_signal">
            <input type="hidden" name="class_id" value="<?= (int)$s['id'] ?>">
            <span class="font-mono"><?= htmlspecialchars($s['pattern']) ?></span>
            <span class="text-[10px] text-muted"><?= $s['match_type'] === 'title_keyword' ? 'título' : 'proceso' ?></span>
            <button title="Quitar la marca de señal" class="w-4 h-4 rounded-full text-muted hover:text-accent-500 hover:bg-white leading-none">×</button>
          </form>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
