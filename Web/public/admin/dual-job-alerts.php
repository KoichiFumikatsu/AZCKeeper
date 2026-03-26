<?php
/**
 * Alertas de Actividades — gestión de alertas generadas por DualJobDetector.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('dual_job');

$pageTitle   = 'Alertas de Actividades';
$currentPage = 'dual-job-alerts';

// ==================== SCOPE ====================
$scope  = scopeFilter();
$canReview = canDo('dual_job', 'can_review');

// ==================== ACCIÓN: REVISAR ALERTA ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canReview) {
    $action = $_POST['action'] ?? '';
    if ($action === 'review') {
        $alertId = filter_input(INPUT_POST, 'alert_id', FILTER_VALIDATE_INT);
        $result  = $_POST['result'] ?? '';
        $notes   = trim($_POST['notes'] ?? '');
        if ($alertId && in_array($result, ['productive', 'unproductive'], true) && mb_strlen($notes) <= 1000) {
            \Keeper\Repos\ProductivityRepo::reviewAlert($pdo, $alertId, (int)$adminUser['admin_id'], $result, $notes);
        }
        header('Location: dual-job-alerts.php?' . http_build_query(array_filter([
            'type'     => $_POST['_type'] ?? '',
            'severity' => $_POST['_severity'] ?? '',
            'status'   => $_POST['_status'] ?? '',
            'page'     => $_POST['_page'] ?? '1',
        ])));
        exit;
    }
    if ($action === 'add_classification') {
        $pat  = trim($_POST['app_pattern'] ?? '');
        $cls  = $_POST['classification'] ?? '';
        $desc = trim($_POST['description'] ?? '');
        if ($pat !== '' && mb_strlen($pat) <= 255 && mb_strlen($desc) <= 500) {
            \Keeper\Repos\ProductivityRepo::addAppClassification($pdo, $pat, $cls, $desc);
        }
        header('Location: dual-job-alerts.php?tab=classifications');
        exit;
    }
    if ($action === 'delete_classification') {
        $clsId = filter_input(INPUT_POST, 'cls_id', FILTER_VALIDATE_INT);
        if ($clsId) \Keeper\Repos\ProductivityRepo::deleteAppClassification($pdo, $clsId);
        header('Location: dual-job-alerts.php?tab=classifications');
        exit;
    }
    if ($action === 'toggle_classification') {
        $clsId  = filter_input(INPUT_POST, 'cls_id', FILTER_VALIDATE_INT);
        $active = (int)($_POST['active'] ?? 0);
        if ($clsId) \Keeper\Repos\ProductivityRepo::toggleAppClassification($pdo, $clsId, (bool)$active);
        header('Location: dual-job-alerts.php?tab=classifications');
        exit;
    }
}

// ==================== FILTROS ====================
$filterType     = $_GET['type'] ?? '';
$filterSeverity = $_GET['severity'] ?? '';
$filterStatus   = $_GET['status'] ?? '';

$typeVal     = in_array($filterType, ['after_hours_pattern','foreign_app','remote_desktop','suspicious_idle']) ? $filterType : null;
$severityVal = in_array($filterSeverity, ['low','medium','high']) ? $filterSeverity : null;
$reviewedVal = $filterStatus === 'pending' ? false : ($filterStatus === 'reviewed' ? true : null);

// ==================== QUERIES ====================
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;

$alertCounts = \Keeper\Repos\ProductivityRepo::getAlertCounts($pdo, $scope['sql'], $scope['params']);
$alerts = \Keeper\Repos\ProductivityRepo::getAlerts(
    $pdo, $scope['sql'], $scope['params'],
    $typeVal, $severityVal, $reviewedVal,
    $perPage, ($page - 1) * $perPage
);

$totalPending = (int)($alertCounts['pending'] ?? 0);
$highPending  = (int)($alertCounts['high_pending'] ?? 0);
$medPending   = (int)($alertCounts['medium_pending'] ?? 0);
$lowPending   = (int)($alertCounts['low_pending'] ?? 0);
$totalAlerts  = (int)($alertCounts['total'] ?? 0);

// ==================== APP CLASSIFICATIONS ====================
$activeTab = $_GET['tab'] ?? 'alerts';
$appClassifications = [];
try {
    $appClassifications = \Keeper\Repos\ProductivityRepo::getAppClassifications($pdo, false);
} catch (\Throwable $e) { /* table may not exist yet */ }

// ==================== HELPERS ====================
$alertTypeLabels = [
    'after_hours_pattern' => 'Patrón Fuera de Horario',
    'foreign_app'         => 'App Sospechosa',
    'remote_desktop'      => 'Escritorio Remoto',
    'suspicious_idle'     => 'Inactividad Sospechosa',
];

$alertTypeIcons = [
    'after_hours_pattern' => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>',
    'foreign_app'         => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    'remote_desktop'      => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>',
    'suspicious_idle'     => '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',
];

function severityBadge(string $severity): string {
    return match ($severity) {
        'high'   => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700"><span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span>Alta</span>',
        'medium' => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700"><span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>Media</span>',
        default  => '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-600"><span class="w-1.5 h-1.5 bg-gray-400 rounded-full"></span>Baja</span>',
    };
}

function reviewedBadge(bool $reviewed, ?string $result = null): string {
    if ($reviewed && $result === 'productive') return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Productivo</span>';
    if ($reviewed && $result === 'unproductive') return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>Improductivo</span>';
    if ($reviewed) return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>Revisada</span>';
    return '<span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700">Pendiente</span>';
}

function fmtDuration(int $seconds): string {
    if ($seconds <= 0) return '0 min';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    if ($h > 0 && $m > 0) return "{$h}h {$m}min";
    if ($h > 0) return "{$h}h";
    return "{$m} min";
}

function fmtDate(string $date): string {
    $ts = strtotime($date);
    if (!$ts) return $date;
    $dias = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
    return $dias[(int)date('w', $ts)] . ' ' . date('d/m/Y', $ts);
}

function dateLink(int $userId, string $date): string {
    $d = htmlspecialchars($date);
    return '<a href="user-dashboard.php?id=' . $userId . '&ep_from=' . $d . '&ep_to=' . $d . '" class="text-corp-800 hover:underline font-medium" title="Ver historial de este día">' . fmtDate($date) . '</a>';
}

function renderEvidence(string $alertType, ?array $evidence, int $userId, PDO $pdo): string {
    if (!$evidence) return '<p class="text-xs text-muted italic">Sin evidencia</p>';

    $html = '';

    switch ($alertType) {
        case 'after_hours_pattern':
            $days = $evidence['sample_days'] ?? [];
            $totalDays = (int)($evidence['days_with_after_hours'] ?? count($days));
            $minSec = (int)($evidence['min_seconds'] ?? 3600);

            $html .= '<p class="text-sm text-dark mb-3">Se detectaron <strong>' . $totalDays . ' días</strong> con actividad significativa fuera del horario laboral <span class="text-muted">(más de ' . fmtDuration($minSec) . ' cada día)</span>.</p>';

            // Layout 2 columnas: fechas | ventanas
            $html .= '<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">';

            // Col 1: Fechas
            $html .= '<div>';
            $html .= '<h5 class="text-xs font-semibold text-muted uppercase tracking-wider mb-2">Días detectados</h5>';
            if (!empty($days)) {
                usort($days, fn($a, $b) => ($b['seconds'] ?? 0) - ($a['seconds'] ?? 0));
                $top = array_slice($days, 0, 5);
                $html .= '<table class="w-full text-sm">';
                $html .= '<thead><tr class="border-b border-gray-200"><th class="text-left py-1 px-2 text-xs font-semibold text-muted">Fecha</th><th class="text-right py-1 px-2 text-xs font-semibold text-muted">Tiempo</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($top as $d) {
                    $html .= '<tr class="border-b border-gray-50">';
                    $html .= '<td class="py-1 px-2 text-xs">' . dateLink($userId, $d['date'] ?? '') . '</td>';
                    $html .= '<td class="py-1 px-2 text-xs text-right font-medium text-amber-700">' . fmtDuration((int)($d['seconds'] ?? 0)) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                if ($totalDays > 5) {
                    $html .= '<p class="text-xs text-muted mt-1 italic">Top 5 de ' . $totalDays . ' días.</p>';
                }
            }
            $html .= '</div>';

            // Col 2: Top ventanas fuera de horario
            $html .= '<div>';
            $topDates = array_column(array_slice($days, 0, 5), 'date');
            if (!empty($topDates)) {
                $schedule = \Keeper\Repos\PolicyRepo::getWorkSchedule($pdo, $userId);
                $workEnd = $schedule['workEndTime'] ?? '19:00:00';
                $workStart = $schedule['workStartTime'] ?? '07:00:00';
                $placeholders = implode(',', array_fill(0, count($topDates), '?'));
                $stW = $pdo->prepare("
                    SELECT w.process_name, w.window_title, w.day_date,
                           SUM(w.duration_seconds) AS total_sec
                    FROM keeper_window_episode w
                    WHERE w.user_id = ?
                      AND w.day_date IN ($placeholders)
                      AND (TIME(w.start_at) >= ? OR TIME(w.start_at) < ?)
                    GROUP BY w.process_name, w.window_title, w.day_date
                    ORDER BY total_sec DESC
                    LIMIT 30
                ");
                $stW->execute(array_merge([$userId], $topDates, [$workEnd, $workStart]));
                $windows = $stW->fetchAll(PDO::FETCH_ASSOC);

                $html .= '<h5 class="text-xs font-semibold text-muted uppercase tracking-wider mb-2">Top ventanas fuera de horario</h5>';
                if (!empty($windows)) {
                    $byProc = [];
                    foreach ($windows as $w) {
                        $proc = $w['process_name'] ?: 'Desconocido';
                        if (!isset($byProc[$proc])) {
                            $byProc[$proc] = ['process' => $proc, 'seconds' => 0, 'titles' => [], 'dates' => []];
                        }
                        $byProc[$proc]['seconds'] += (int)$w['total_sec'];
                        $title = mb_substr($w['window_title'] ?? '', 0, 60);
                        if ($title && !in_array($title, $byProc[$proc]['titles'])) {
                            $byProc[$proc]['titles'][] = $title;
                        }
                        if (!in_array($w['day_date'], $byProc[$proc]['dates'])) {
                            $byProc[$proc]['dates'][] = $w['day_date'];
                        }
                    }
                    usort($byProc, fn($a, $b) => $b['seconds'] - $a['seconds']);
                    $topProc = array_slice($byProc, 0, 3);

                    $html .= '<table class="w-full text-sm">';
                    $html .= '<thead><tr class="border-b border-gray-200"><th class="text-left py-1 px-2 text-xs font-semibold text-muted">App / Ventana</th><th class="text-right py-1 px-2 text-xs font-semibold text-muted">Tiempo</th></tr></thead>';
                    $html .= '<tbody>';
                    foreach ($topProc as $p) {
                        sort($p['dates']);
                        $html .= '<tr class="border-b border-gray-50 align-top">';
                        $html .= '<td class="py-1.5 px-2">';
                        $html .= '<span class="text-xs font-semibold text-dark">' . htmlspecialchars($p['process']) . '</span>';
                        if (!empty($p['titles'])) {
                            $html .= '<br><span class="text-xs text-muted truncate block max-w-[250px]" title="' . htmlspecialchars($p['titles'][0]) . '">' . htmlspecialchars(mb_substr($p['titles'][0], 0, 45)) . '</span>';
                        }
                        $html .= '<span class="text-xs text-gray-400">' . implode(', ', array_map(fn($d) => date('d/m', strtotime($d)), $p['dates'])) . '</span>';
                        $html .= '</td>';
                        $html .= '<td class="py-1.5 px-2 text-xs text-right font-bold text-red-600 whitespace-nowrap">' . fmtDuration($p['seconds']) . '</td>';
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                } else {
                    $html .= '<p class="text-xs text-muted italic">Sin datos de ventanas disponibles.</p>';
                }
            }
            $html .= '</div>';

            $html .= '</div>'; // cierre grid
            break;

        case 'foreign_app':
            $matches = $evidence['matches'] ?? [];
            $totalDays = (int)($evidence['distinct_days'] ?? 0);
            $totalSec = (int)($evidence['total_seconds'] ?? 0);

            $html .= '<p class="text-sm text-dark mb-3">Se detectó uso de <strong>aplicaciones sospechosas</strong> durante <strong>' . fmtDuration($totalSec) . '</strong> en <strong>' . $totalDays . ' día' . ($totalDays !== 1 ? 's' : '') . '</strong>.</p>';

            if (!empty($matches)) {
                $byProcess = [];
                foreach ($matches as $m) {
                    $proc = $m['process'] ?? 'Desconocido';
                    if (!isset($byProcess[$proc])) {
                        $byProcess[$proc] = ['process' => $proc, 'title' => $m['title'] ?? '', 'seconds' => 0, 'dates' => []];
                    }
                    $byProcess[$proc]['seconds'] += (int)($m['seconds'] ?? 0);
                    if (!empty($m['date']) && !in_array($m['date'], $byProcess[$proc]['dates'])) {
                        $byProcess[$proc]['dates'][] = $m['date'];
                    }
                }
                usort($byProcess, fn($a, $b) => $b['seconds'] - $a['seconds']);
                $top = array_slice($byProcess, 0, 3);

                $html .= '<table class="w-full text-sm">';
                $html .= '<thead><tr class="border-b border-gray-200"><th class="text-left py-1 px-2 text-xs font-semibold text-muted">App / Ventana</th><th class="text-left py-1 px-2 text-xs font-semibold text-muted">Fechas</th><th class="text-right py-1 px-2 text-xs font-semibold text-muted">Tiempo</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($top as $p) {
                    sort($p['dates']);
                    $html .= '<tr class="border-b border-gray-50 align-top">';
                    $html .= '<td class="py-1.5 px-2">';
                    $html .= '<span class="text-xs font-semibold text-dark">' . htmlspecialchars($p['process']) . '</span>';
                    if (!empty($p['title'])) {
                        $html .= '<br><span class="text-xs text-muted truncate block max-w-[200px]" title="' . htmlspecialchars($p['title']) . '">' . htmlspecialchars(mb_substr($p['title'], 0, 40)) . '</span>';
                    }
                    $html .= '</td>';
                    $html .= '<td class="py-1.5 px-2 text-xs text-gray-500">' . implode(', ', array_map(fn($dd) => '<a href="user-dashboard.php?id=' . $userId . '&ep_from=' . htmlspecialchars($dd) . '&ep_to=' . htmlspecialchars($dd) . '" class="text-corp-800 hover:underline">' . date('d/m', strtotime($dd)) . '</a>', array_slice($p['dates'], 0, 5))) . '</td>';
                    $html .= '<td class="py-1.5 px-2 text-xs text-right font-bold text-red-600 whitespace-nowrap">' . fmtDuration($p['seconds']) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                if (count($byProcess) > 3) {
                    $html .= '<p class="text-xs text-muted mt-1 italic">Top 3 de ' . count($byProcess) . ' apps detectadas.</p>';
                }
            }
            break;

        case 'remote_desktop':
            $days = $evidence['sample_days'] ?? [];
            $totalDays = (int)($evidence['days_detected'] ?? count($days));

            $html .= '<div class="mb-3">';
            $html .= '<p class="text-sm text-dark mb-1">Se detectó uso de <strong>escritorio remoto o máquina virtual</strong> en horario laboral durante <strong>' . $totalDays . ' día' . ($totalDays !== 1 ? 's' : '') . '</strong> <span class="text-muted">(más de 30 min por día)</span>.</p>';
            $html .= '</div>';

            if (!empty($days)) {
                usort($days, fn($a, $b) => ($b['seconds'] ?? $b['total_sec'] ?? 0) - ($a['seconds'] ?? $a['total_sec'] ?? 0));
                $top = array_slice($days, 0, 5);
                $html .= '<table class="w-full text-sm">';
                $html .= '<thead><tr class="border-b border-gray-200"><th class="text-left py-1.5 px-2 text-xs font-semibold text-muted">Fecha</th><th class="text-right py-1.5 px-2 text-xs font-semibold text-muted">Tiempo en remoto</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($top as $d) {
                    $sec = (int)($d['seconds'] ?? $d['total_sec'] ?? 0);
                    $html .= '<tr class="border-b border-gray-50">';
                    $html .= '<td class="py-1.5 px-2 text-xs">' . dateLink($userId, $d['date'] ?? '') . '</td>';
                    $html .= '<td class="py-1.5 px-2 text-xs text-right font-medium text-amber-700">' . fmtDuration($sec) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                if ($totalDays > 5) {
                    $html .= '<p class="text-xs text-muted mt-2 italic">Mostrando los 5 días con mayor uso de ' . $totalDays . ' detectados.</p>';
                }
            }
            break;

        case 'suspicious_idle':
            $days = $evidence['sample_days'] ?? [];
            $totalDays = (int)($evidence['days_detected'] ?? count($days));

            $html .= '<div class="mb-3">';
            $html .= '<p class="text-sm text-dark mb-1">Se detectaron <strong>' . $totalDays . ' días</strong> donde el empleado estuvo <strong>mayormente inactivo</strong> en horario laboral pero <strong>activo fuera de horario</strong>.</p>';
            $html .= '</div>';

            if (!empty($days)) {
                usort($days, fn($a, $b) => ($b['after_hours_sec'] ?? 0) - ($a['after_hours_sec'] ?? 0));
                $top = array_slice($days, 0, 5);
                $html .= '<table class="w-full text-sm">';
                $html .= '<thead><tr class="border-b border-gray-200"><th class="text-left py-1.5 px-2 text-xs font-semibold text-muted">Fecha</th><th class="text-right py-1.5 px-2 text-xs font-semibold text-muted">Inactividad laboral</th><th class="text-right py-1.5 px-2 text-xs font-semibold text-muted">Activo fuera horario</th></tr></thead>';
                $html .= '<tbody>';
                foreach ($top as $d) {
                    $html .= '<tr class="border-b border-gray-50">';
                    $html .= '<td class="py-1.5 px-2 text-xs">' . dateLink($userId, $d['date'] ?? '') . '</td>';
                    $html .= '<td class="py-1.5 px-2 text-xs text-right font-medium text-red-600">' . round((float)($d['idle_pct'] ?? 0), 0) . '% inactivo</td>';
                    $html .= '<td class="py-1.5 px-2 text-xs text-right font-medium text-amber-700">' . fmtDuration((int)($d['after_hours_sec'] ?? 0)) . '</td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
                if ($totalDays > 5) {
                    $html .= '<p class="text-xs text-muted mt-2 italic">Mostrando los 5 días más relevantes de ' . $totalDays . ' detectados.</p>';
                }
            }
            break;

        default:
            $html .= '<pre class="text-xs text-muted whitespace-pre-wrap">' . htmlspecialchars(json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) . '</pre>';
            break;
    }

    return $html;
}

function queryStr(array $overrides): string {
    $base = ['type' => '', 'severity' => '', 'status' => '', 'page' => '1'];
    $current = array_merge($base, array_intersect_key($_GET, $base));
    $merged = array_merge($current, $overrides);
    return http_build_query(array_filter($merged));
}

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Header -->
<div class="bg-gradient-to-b from-corp-50 to-white rounded-2xl border border-corp-100 px-4 py-4 sm:px-8 sm:py-6 mb-4 sm:mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-dark mb-1">Alertas de Actividades</h2>
            <p class="text-sm text-muted">Detecciones automáticas de actividad que requiere clasificación</p>
        </div>
        <?php if ($totalPending > 0): ?>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-semibold bg-amber-100 text-amber-800">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
                <?= $totalPending ?> pendiente<?= $totalPending !== 1 ? 's' : '' ?>
            </span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Tab Navigation -->
<div class="flex items-center gap-1 bg-white rounded-xl border border-gray-100 p-1 mb-4 sm:mb-6">
    <a href="?tab=alerts" class="flex-1 sm:flex-none px-4 py-2 rounded-lg text-sm font-medium text-center transition-colors <?= $activeTab === 'alerts' ? 'bg-corp-800 text-white shadow-sm' : 'text-muted hover:text-dark hover:bg-gray-50' ?>">
        <span class="flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
            Alertas
            <?php if ($totalPending > 0): ?><span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-xs font-bold <?= $activeTab === 'alerts' ? 'bg-white/20 text-white' : 'bg-amber-100 text-amber-800' ?>"><?= $totalPending ?></span><?php endif; ?>
        </span>
    </a>
    <a href="?tab=classifications" class="flex-1 sm:flex-none px-4 py-2 rounded-lg text-sm font-medium text-center transition-colors <?= $activeTab === 'classifications' ? 'bg-corp-800 text-white shadow-sm' : 'text-muted hover:text-dark hover:bg-gray-50' ?>">
        <span class="flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            Clasificación de Apps
        </span>
    </a>
</div>

<?php if ($activeTab === 'alerts'): ?>
<!-- KPI Badges -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gray-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-dark"><?= $totalAlerts ?></p>
        <p class="text-xs text-muted mt-1">Total Alertas</p>
    </div>

    <a href="?<?= queryStr(['severity' => 'high', 'status' => 'pending']) ?>" class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center hover:border-red-200 transition-colors">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-red-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-red-600"><?= $highPending ?></p>
        <p class="text-xs text-muted mt-1">Alta Prioridad</p>
    </a>

    <a href="?<?= queryStr(['severity' => 'medium', 'status' => 'pending']) ?>" class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center hover:border-amber-200 transition-colors">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-amber-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-amber-600"><?= $medPending ?></p>
        <p class="text-xs text-muted mt-1">Media Prioridad</p>
    </a>

    <a href="?<?= queryStr(['severity' => 'low', 'status' => 'pending']) ?>" class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center hover:border-gray-300 transition-colors">
        <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
            <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-2xl sm:text-3xl font-bold text-blue-600"><?= $lowPending ?></p>
        <p class="text-xs text-muted mt-1">Baja Prioridad</p>
    </a>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-4 mb-4 sm:mb-6">
    <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold text-muted uppercase tracking-wider mr-1">Filtros:</span>

        <!-- Type filter -->
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-0.5">
            <a href="?<?= queryStr(['type' => '', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= !$filterType ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Todos</a>
            <a href="?<?= queryStr(['type' => 'after_hours_pattern', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterType === 'after_hours_pattern' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Fuera Horario</a>
            <a href="?<?= queryStr(['type' => 'foreign_app', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterType === 'foreign_app' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">App Sosp.</a>
            <a href="?<?= queryStr(['type' => 'remote_desktop', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterType === 'remote_desktop' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Remoto</a>
            <a href="?<?= queryStr(['type' => 'suspicious_idle', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterType === 'suspicious_idle' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Inactivo</a>
        </div>

        <span class="text-gray-300">|</span>

        <!-- Severity filter -->
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-0.5">
            <a href="?<?= queryStr(['severity' => '', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= !$filterSeverity ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Todas</a>
            <a href="?<?= queryStr(['severity' => 'high', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterSeverity === 'high' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Alta</a>
            <a href="?<?= queryStr(['severity' => 'medium', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterSeverity === 'medium' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Media</a>
            <a href="?<?= queryStr(['severity' => 'low', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterSeverity === 'low' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Baja</a>
        </div>

        <span class="text-gray-300">|</span>

        <!-- Status filter -->
        <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-0.5">
            <a href="?<?= queryStr(['status' => '', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= !$filterStatus ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Todas</a>
            <a href="?<?= queryStr(['status' => 'pending', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterStatus === 'pending' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Pendientes</a>
            <a href="?<?= queryStr(['status' => 'reviewed', 'page' => '1']) ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $filterStatus === 'reviewed' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Revisadas</a>
        </div>
    </div>
</div>

<!-- Alerts Table -->
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-4 sm:mb-8" x-data="{ openAlert: null }">
    <?php if (empty($alerts)): ?>
        <div class="text-center py-12">
            <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p class="text-sm text-muted">No hay alertas con los filtros seleccionados</p>
        </div>
    <?php else: ?>
    <div class="space-y-3">
        <?php foreach ($alerts as $a):
            $isReviewed = (bool)($a['is_reviewed'] ?? false);
            $evidence = $a['evidence_json'] ? json_decode($a['evidence_json'], true) : null;
            $alertId = (int)$a['id'];
        ?>
        <div class="border <?= $isReviewed ? 'border-gray-100 bg-gray-50/50' : 'border-gray-200' ?> rounded-xl overflow-hidden">
            <!-- Alert row -->
            <div class="flex items-center gap-3 p-3 sm:p-4 cursor-pointer hover:bg-gray-50 transition-colors"
                 @click="openAlert = openAlert === <?= $alertId ?> ? null : <?= $alertId ?>">
                <!-- Type icon -->
                <div class="w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0 <?= $isReviewed ? 'bg-gray-100 text-gray-400' : ($a['severity'] === 'high' ? 'bg-red-50 text-red-600' : ($a['severity'] === 'medium' ? 'bg-amber-50 text-amber-600' : 'bg-blue-50 text-blue-600')) ?>">
                    <?= $alertTypeIcons[$a['alert_type']] ?? '' ?>
                </div>

                <!-- Info -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="user-dashboard.php?id=<?= (int)$a['user_id'] ?>" class="text-sm font-semibold text-dark hover:text-corp-800" onclick="event.stopPropagation()"><?= htmlspecialchars($a['display_name'] ?? '') ?></a>
                        <?= severityBadge($a['severity'] ?? 'low') ?>
                        <?= reviewedBadge($isReviewed, $a['review_result'] ?? null) ?>
                    </div>
                    <div class="flex items-center gap-3 mt-0.5">
                        <span class="text-xs text-muted"><?= $alertTypeLabels[$a['alert_type']] ?? $a['alert_type'] ?></span>
                        <span class="text-xs text-gray-300">·</span>
                        <span class="text-xs text-muted"><?= date('d/m/Y', strtotime($a['day_date'] ?? $a['created_at'])) ?></span>
                        <?php if (!empty($a['firma_nombre'])): ?>
                        <span class="text-xs text-gray-300">·</span>
                        <span class="text-xs text-muted"><?= htmlspecialchars($a['firma_nombre']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Expand icon -->
                <svg class="w-5 h-5 text-muted transition-transform flex-shrink-0" :class="openAlert === <?= $alertId ?> ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </div>

            <!-- Expanded detail -->
            <div x-show="openAlert === <?= $alertId ?>" x-collapse class="border-t border-gray-100">
                <div class="p-4 sm:p-5 space-y-4">
                    <!-- Evidence -->
                    <?php if ($evidence): ?>
                    <div>
                        <h4 class="text-xs font-semibold text-muted uppercase tracking-wider mb-2">Evidencia</h4>
                        <div class="bg-gray-50 rounded-lg p-3">
                            <?= renderEvidence($a['alert_type'], $evidence, (int)$a['user_id'], $pdo) ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Review info or form -->
                    <?php if ($isReviewed): ?>
                    <div class="<?= ($a['review_result'] ?? '') === 'unproductive' ? 'bg-red-50' : 'bg-emerald-50' ?> rounded-lg p-3">
                        <p class="text-xs <?= ($a['review_result'] ?? '') === 'unproductive' ? 'text-red-700' : 'text-emerald-700' ?>">
                            <span class="font-semibold">Clasificada como <?= ($a['review_result'] ?? '') === 'unproductive' ? 'Improductivo' : 'Productivo' ?></span>
                            por <?= htmlspecialchars($a['reviewed_by_name'] ?? 'Admin') ?>
                            · <?= $a['reviewed_at'] ? date('d/m/Y H:i', strtotime($a['reviewed_at'])) : '' ?>
                        </p>
                        <?php if (!empty($a['notes'])): ?>
                        <p class="text-xs <?= ($a['review_result'] ?? '') === 'unproductive' ? 'text-red-600' : 'text-emerald-600' ?> mt-1"><?= nl2br(htmlspecialchars($a['notes'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($canReview): ?>
                    <form method="post" class="bg-corp-50 rounded-lg p-3 space-y-3" x-data="{ result: '' }">
                        <input type="hidden" name="action" value="review">
                        <input type="hidden" name="alert_id" value="<?= $alertId ?>">
                        <input type="hidden" name="result" x-model="result">
                        <input type="hidden" name="_type" value="<?= htmlspecialchars($filterType) ?>">
                        <input type="hidden" name="_severity" value="<?= htmlspecialchars($filterSeverity) ?>">
                        <input type="hidden" name="_status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <input type="hidden" name="_page" value="<?= $page ?>">
                        <label class="text-xs font-semibold text-corp-800">Notas (opcional)</label>
                        <textarea name="notes" rows="2" maxlength="1000" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none resize-none" placeholder="Descripción o comentario..."></textarea>
                        <div class="flex items-center gap-2">
                            <button type="submit" @click="result = 'productive'" class="px-4 py-2 bg-emerald-600 text-white text-xs font-semibold rounded-lg hover:bg-emerald-700 transition-colors flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Marcar como Productivo
                            </button>
                            <button type="submit" @click="result = 'unproductive'" class="px-4 py-2 bg-red-600 text-white text-xs font-semibold rounded-lg hover:bg-red-700 transition-colors flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                Marcar como Improductivo
                            </button>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php
    $totalFilteredApprox = count($alerts) === $perPage ? ($page * $perPage + 1) : (($page - 1) * $perPage + count($alerts));
    $hasMore = count($alerts) === $perPage;
    if ($page > 1 || $hasMore):
    ?>
    <div class="flex items-center justify-center gap-2 mt-6">
        <?php if ($page > 1): ?>
        <a href="?<?= queryStr(['page' => $page - 1]) ?>" class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-medium text-muted hover:text-dark hover:border-gray-300 transition-colors">← Anterior</a>
        <?php endif; ?>
        <span class="text-xs text-muted">Página <?= $page ?></span>
        <?php if ($hasMore): ?>
        <a href="?<?= queryStr(['page' => $page + 1]) ?>" class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-medium text-muted hover:text-dark hover:border-gray-300 transition-colors">Siguiente →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php endif; ?><!-- end alerts tab -->

<?php if ($activeTab === 'classifications'): ?>
<!-- App Classification Management -->
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-4 sm:mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
        <div>
            <h3 class="text-base font-bold text-dark">Clasificación de Aplicaciones</h3>
            <p class="text-xs text-muted mt-0.5">Define qué aplicaciones o ventanas son productivas o improductivas para la evaluación de actividad</p>
        </div>
    </div>

    <?php if ($canReview): ?>
    <!-- Add form -->
    <form method="post" class="bg-gray-50 rounded-lg p-3 sm:p-4 mb-4">
        <input type="hidden" name="action" value="add_classification">
        <div class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-end">
            <div class="sm:col-span-4">
                <label class="text-xs font-semibold text-muted block mb-1">Patrón de aplicación / ventana</label>
                <input type="text" name="app_pattern" required maxlength="255" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" placeholder="Ej: Visual Studio, Excel, chrome.exe">
            </div>
            <div class="sm:col-span-3">
                <label class="text-xs font-semibold text-muted block mb-1">Clasificación</label>
                <select name="classification" required class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none bg-white">
                    <option value="productive">Productivo</option>
                    <option value="unproductive">Improductivo</option>
                </select>
            </div>
            <div class="sm:col-span-3">
                <label class="text-xs font-semibold text-muted block mb-1">Descripción (opcional)</label>
                <input type="text" name="description" maxlength="500" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" placeholder="Nota breve">
            </div>
            <div class="sm:col-span-2">
                <button type="submit" class="w-full px-4 py-2 bg-corp-800 text-white text-xs font-semibold rounded-lg hover:bg-corp-900 transition-colors">
                    Agregar
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <!-- Classifications table -->
    <?php if (empty($appClassifications)): ?>
        <div class="text-center py-8">
            <svg class="w-10 h-10 text-gray-300 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            <p class="text-sm text-muted">No hay clasificaciones definidas aún</p>
            <p class="text-xs text-muted mt-1">Agrega patrones de aplicaciones para clasificar la actividad como productiva o improductiva</p>
        </div>
    <?php else: ?>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-xs text-muted uppercase tracking-wider border-b border-gray-100">
                    <th class="text-left py-2 pr-4">Patrón</th>
                    <th class="text-left py-2 pr-4">Clasificación</th>
                    <th class="text-left py-2 pr-4">Descripción</th>
                    <th class="text-center py-2 pr-4">Estado</th>
                    <?php if ($canReview): ?><th class="text-right py-2">Acciones</th><?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($appClassifications as $cls): ?>
                <tr class="<?= $cls['is_active'] ? '' : 'opacity-50' ?>">
                    <td class="py-2 pr-4 font-mono text-xs text-dark"><?= htmlspecialchars($cls['app_pattern']) ?></td>
                    <td class="py-2 pr-4">
                        <?php if ($cls['classification'] === 'productive'): ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Productivo
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                Improductivo
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 pr-4 text-xs text-muted"><?= htmlspecialchars($cls['description'] ?? '') ?></td>
                    <td class="py-2 pr-4 text-center">
                        <?php if ($cls['is_active']): ?>
                            <span class="inline-flex w-2 h-2 rounded-full bg-emerald-500" title="Activo"></span>
                        <?php else: ?>
                            <span class="inline-flex w-2 h-2 rounded-full bg-gray-300" title="Inactivo"></span>
                        <?php endif; ?>
                    </td>
                    <?php if ($canReview): ?>
                    <td class="py-2 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="toggle_classification">
                                <input type="hidden" name="cls_id" value="<?= (int)$cls['id'] ?>">
                                <input type="hidden" name="active" value="<?= $cls['is_active'] ? '0' : '1' ?>">
                                <button type="submit" class="p-1.5 rounded-lg hover:bg-gray-100 transition-colors text-muted hover:text-dark" title="<?= $cls['is_active'] ? 'Desactivar' : 'Activar' ?>">
                                    <?php if ($cls['is_active']): ?>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.464 6.464m3.414 3.414l4.243 4.243m0 0L17.5 17.5M3 3l18 18"/></svg>
                                    <?php else: ?>
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    <?php endif; ?>
                                </button>
                            </form>
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar esta clasificación?')">
                                <input type="hidden" name="action" value="delete_classification">
                                <input type="hidden" name="cls_id" value="<?= (int)$cls['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg hover:bg-red-50 transition-colors text-muted hover:text-red-600" title="Eliminar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?><!-- end classifications tab -->

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>