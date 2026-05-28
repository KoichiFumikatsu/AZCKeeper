<?php
/**
 * Productividad — dashboard de métricas Focus Score, ranking y tendencias.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('productivity');

$pageTitle   = 'Productividad';
$currentPage = 'productivity';

// ==================== CALCULAR (MANUAL) ====================
$calcResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'calculate') {
    if (!canDo('productivity', 'can_view')) {
        $calcResult = ['ok' => false, 'error' => 'Sin permisos'];
    } elseif (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
        $calcResult = ['ok' => false, 'error' => 'Token CSRF inválido'];
    } else {
        $calcDate = $_POST['calc_date'] ?? '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $calcDate) || strtotime($calcDate) > time()) {
            $calcDate = date('Y-m-d', strtotime('yesterday'));
        }

        $startTime = microtime(true);
        $pdo = \Keeper\Db::pdo();

        // Obtener usuarios activos con actividad ese día
        $st = $pdo->prepare("
            SELECT DISTINCT a.user_id
            FROM keeper_activity_day a
            INNER JOIN keeper_users u ON u.id = a.user_id AND u.status = 'active'
            WHERE a.day_date = :day AND a.active_seconds > 0
        ");
        $st->execute([':day' => $calcDate]);
        $userIds = $st->fetchAll(\PDO::FETCH_COLUMN);

        $processed = 0;
        $errors    = 0;
        $alerts    = 0;
        $errMsgs   = [];

        foreach ($userIds as $userId) {
            try {
                if ((microtime(true) - $startTime) > 300) {
                    $errMsgs[] = "Timeout global alcanzado en usuario $userId";
                    break;
                }
                \Keeper\Services\ProductivityCalculator::calculateDay($pdo, $userId, $calcDate);
                $alerts += \Keeper\Services\DualJobDetector::analyze($pdo, $userId);
                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $errMsgs[] = "User $userId: " . $e->getMessage();
                error_log("[KEEPER CALC] Error usuario $userId: " . $e->getMessage());
            }
        }

        $duration = round(microtime(true) - $startTime, 2);
        $calcResult = [
            'ok'        => true,
            'date'      => $calcDate,
            'total'     => count($userIds),
            'processed' => $processed,
            'alerts'    => $alerts,
            'errors'    => $errors,
            'duration'  => $duration,
            'errMsgs'   => array_slice($errMsgs, 0, 10),
        ];
    }

    // Generar nuevo CSRF para siguiente request
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ==================== GUARDAR PESOS FOCUS SCORE ====================
$settingsMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_focus_weights') {
    if (!canDo('productivity', 'can_view')) {
        $settingsMsg = ['type' => 'error', 'text' => 'Sin permisos'];
    } elseif (!isset($_POST['csrf']) || $_POST['csrf'] !== ($_SESSION['csrf_token'] ?? '')) {
        $settingsMsg = ['type' => 'error', 'text' => 'Token CSRF inválido'];
    } else {
        $wKeys = ['context_switches', 'deep_work', 'distraction', 'punctuality', 'constancy'];
        $newWeights = [];
        $sumW = 0;
        foreach ($wKeys as $k) {
            $v = max(0, min(100, (int)($_POST['w_' . $k] ?? 0)));
            $newWeights[$k] = $v;
            $sumW += $v;
        }
        $dwThreshold = max(5, min(120, (int)($_POST['deep_work_threshold'] ?? 25)));

        if ($sumW !== 100) {
            $settingsMsg = ['type' => 'error', 'text' => 'Los pesos deben sumar exactamente 100. Actualmente suman ' . $sumW . '.'];
        } else {
            $weightsJson = json_encode($newWeights, JSON_UNESCAPED_UNICODE);
            $thresholdJson = json_encode($dwThreshold);

            $pdo->beginTransaction();
            try {
                $stCheck = $pdo->prepare("SELECT 1 FROM keeper_panel_settings WHERE setting_key = :k LIMIT 1");
                $stUp = $pdo->prepare("UPDATE keeper_panel_settings SET setting_value = :v, updated_at = NOW() WHERE setting_key = :k");
                $stIn = $pdo->prepare("INSERT INTO keeper_panel_settings (setting_key, setting_value) VALUES (:k, :v)");

                foreach ([['productivity.focus_weights', $weightsJson], ['productivity.deep_work_threshold_minutes', $thresholdJson]] as [$key, $val]) {
                    $stCheck->execute([':k' => $key]);
                    if ($stCheck->fetchColumn()) {
                        $stUp->execute([':v' => $val, ':k' => $key]);
                    } else {
                        $stIn->execute([':k' => $key, ':v' => $val]);
                    }
                }
                $pdo->commit();
                $settingsMsg = ['type' => 'success', 'text' => 'Configuración de Focus Score guardada correctamente.'];
            } catch (\Throwable $e) {
                $pdo->rollBack();
                $settingsMsg = ['type' => 'error', 'text' => 'Error al guardar: ' . $e->getMessage()];
            }
        }
    }
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// Asegurar token CSRF disponible
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

// ==================== CARGAR CONFIG FOCUS SCORE ====================
$focusWeightsDefaults = ['context_switches' => 20, 'deep_work' => 25, 'distraction' => 20, 'punctuality' => 15, 'constancy' => 20];
$focusWeights = $focusWeightsDefaults;
$deepWorkThresholdMin = 25;
try {
    $stCfg = $pdo->prepare("SELECT setting_key, setting_value FROM keeper_panel_settings WHERE setting_key IN ('productivity.focus_weights', 'productivity.deep_work_threshold_minutes')");
    $stCfg->execute();
    foreach ($stCfg->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($row['setting_key'] === 'productivity.focus_weights') {
            $decoded = json_decode($row['setting_value'], true);
            if (is_array($decoded)) $focusWeights = array_merge($focusWeightsDefaults, $decoded);
        }
        if ($row['setting_key'] === 'productivity.deep_work_threshold_minutes') {
            $deepWorkThresholdMin = max(5, (int)json_decode($row['setting_value'], true));
        }
    }
} catch (\Throwable $e) { /* table may not exist */ }

// ==================== PERÍODO ====================
$period = $_GET['period'] ?? 'week';
if (!in_array($period, ['today', 'week', 'month', 'custom'])) $period = 'week';

$customFrom = $_GET['from'] ?? '';
$customTo   = $_GET['to'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom)) $customFrom = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo))   $customTo = '';

switch ($period) {
    case 'today':
        $dateFrom = date('Y-m-d');
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Hoy';
        break;
    case 'month':
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Este Mes';
        break;
    case 'custom':
        $dateFrom = $customFrom ?: date('Y-m-d', strtotime('-7 days'));
        $dateTo   = $customTo ?: date('Y-m-d');
        $periodLabel = date('d/m', strtotime($dateFrom)) . ' — ' . date('d/m', strtotime($dateTo));
        break;
    default: // week
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo   = date('Y-m-d');
        $periodLabel = 'Esta Semana';
        break;
}

// ==================== SCOPE + QUERIES ====================
$scope  = scopeFilter();
$params = $scope['params'];

// KPIs globales
$kpis = \Keeper\Repos\ProductivityRepo::getGlobalKPIs($pdo, $dateFrom, $dateTo, $scope['sql'], $scope['params']);

// Team ranking
$teamGroup = $_GET['team'] ?? 'firm';
if (!in_array($teamGroup, ['firm', 'area'])) $teamGroup = 'firm';
$teamRanking = \Keeper\Repos\ProductivityRepo::getTeamRanking($pdo, $dateFrom, $dateTo, $teamGroup, $scope['sql'], $scope['params']);

// Individual ranking
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$sortOrder = ($_GET['sort'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
$totalUsers = \Keeper\Repos\ProductivityRepo::getUserRankingCount($pdo, $dateFrom, $dateTo, $scope['sql'], $scope['params']);
$totalPages = max(1, (int)ceil($totalUsers / $perPage));
$page = min($page, $totalPages);
$userRanking = \Keeper\Repos\ProductivityRepo::getUserRanking($pdo, $dateFrom, $dateTo, $scope['sql'], $scope['params'], $sortOrder, $perPage, ($page - 1) * $perPage);

// Tendencias semanales (últimas 8 semanas)
$trendFrom = date('Y-m-d', strtotime('-8 weeks monday'));
$trends = \Keeper\Repos\ProductivityRepo::getWeeklyTrends($pdo, $trendFrom, $dateTo, $scope['sql'], $scope['params']);

// ==================== HELPERS ====================
function focusColor(float $score): string {
    if ($score >= 75) return 'text-emerald-600';
    if ($score >= 50) return 'text-amber-600';
    return 'text-red-600';
}

function focusBg(float $score): string {
    if ($score >= 75) return 'bg-emerald-50';
    if ($score >= 50) return 'bg-amber-50';
    return 'bg-red-50';
}

function focusGradient(float $score): string {
    if ($score >= 75) return '#10b981';
    if ($score >= 50) return '#f59e0b';
    return '#be1622';
}

function fmtDeepWork(int $seconds): string {
    if ($seconds <= 0) return '0m';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
}

function trendArrow(?float $current, ?float $previous): string {
    if ($current === null || $previous === null || $previous == 0) return '<span class="text-muted">→</span>';
    $diff = $current - $previous;
    if ($diff > 2) return '<span class="text-emerald-600">↑</span>';
    if ($diff < -2) return '<span class="text-red-600">↓</span>';
    return '<span class="text-muted">→</span>';
}

// SVG chart prep
$chartData = [];
foreach ($trends as $t) {
    $chartData[] = [
        'label' => date('d/m', strtotime($t['week_start'])),
        'focus' => (float)($t['avg_focus'] ?? 0),
        'prod'  => (float)($t['avg_productivity'] ?? 0),
    ];
}

$avgFocus = (float)($kpis['avg_focus'] ?? 0);
$avgProd  = (float)($kpis['avg_productivity'] ?? 0);
$avgConst = (float)($kpis['avg_constancy'] ?? 0);
$avgSwitch = (float)($kpis['avg_switches'] ?? 0);
$avgDeepSec = (int)($kpis['avg_deep_work_sec'] ?? 0);
$avgPunct = (float)($kpis['avg_punctuality'] ?? 0);
$userCount = (int)($kpis['user_count'] ?? 0);

// SVG gauge angle (0-100 → 0-270°)
$gaugeAngle = ($avgFocus / 100) * 270;

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Flash: resultado del cálculo manual -->
<?php if ($calcResult): ?>
<?php if ($calcResult['ok']): ?>
<div class="mb-4 sm:mb-6 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-xl px-4 py-3 text-sm flex flex-col sm:flex-row sm:items-center gap-2">
    <svg class="w-5 h-5 text-emerald-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    <span><strong>Cálculo completado</strong> para <?= htmlspecialchars($calcResult['date']) ?>:
    <?= $calcResult['processed'] ?>/<?= $calcResult['total'] ?> usuarios procesados,
    <?= $calcResult['alerts'] ?> alertas,
    <?= $calcResult['errors'] ?> errores
    (<?= $calcResult['duration'] ?>s)</span>
</div>
<?php if (!empty($calcResult['errMsgs'])): ?>
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-xs">
    <strong>Errores:</strong>
    <ul class="mt-1 list-disc list-inside"><?php foreach ($calcResult['errMsgs'] as $em): ?><li><?= htmlspecialchars($em) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
<?php else: ?>
<div class="mb-4 sm:mb-6 bg-red-50 border border-red-200 text-red-700 rounded-xl px-4 py-3 text-sm">
    <strong>Error:</strong> <?= htmlspecialchars($calcResult['error'] ?? 'Error desconocido') ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Header -->
<div class="bg-gradient-to-b from-corp-50 to-white rounded-2xl border border-corp-100 px-4 py-4 sm:px-8 sm:py-6 mb-4 sm:mb-8">
    <div class="flex flex-col sm:flex-row sm:flex-wrap sm:items-center sm:justify-between gap-3 sm:gap-4">
        <div>
            <h2 class="text-xl sm:text-2xl font-bold text-dark mb-1">Productividad</h2>
            <p class="text-sm text-muted">Período: <span class="font-semibold text-corp-800"><?= $periodLabel ?></span> · <?= $userCount ?> usuarios</p>
        </div>
        <div class="flex flex-col sm:flex-row items-start sm:items-center gap-2 sm:gap-3">
            <!-- Calcular manualmente -->
            <form method="post" class="flex items-center gap-2" x-data="{ open: false }">
                <input type="hidden" name="action" value="calculate">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">
                <input type="date" name="calc_date" value="<?= date('Y-m-d', strtotime('yesterday')) ?>" max="<?= date('Y-m-d') ?>"
                       class="w-[7.5rem] px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" title="Fecha a calcular">
                <button type="submit" onclick="return confirm('¿Calcular productividad para la fecha seleccionada? Puede tardar unos minutos.')"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-accent-500 text-white text-xs font-medium rounded-lg hover:bg-red-700 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                    Calcular
                </button>
            </form>
            <div class="flex items-center gap-1 bg-white rounded-lg border border-gray-200 p-1">
                <a href="?period=today&team=<?= $teamGroup ?>" class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors <?= $period === 'today' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">Hoy</a>
                <a href="?period=week&team=<?= $teamGroup ?>" class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors <?= $period === 'week' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">Semana</a>
                <a href="?period=month&team=<?= $teamGroup ?>" class="px-3 py-1.5 rounded-md text-xs font-medium transition-colors <?= $period === 'month' ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark' ?>">Mes</a>
            </div>
            <form method="get" class="flex items-center gap-2">
                <input type="hidden" name="period" value="custom">
                <input type="hidden" name="team" value="<?= $teamGroup ?>">
                <input type="date" name="from" value="<?= htmlspecialchars($period === 'custom' ? $dateFrom : '') ?>" class="w-[7.5rem] px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" title="Desde">
                <input type="date" name="to" value="<?= htmlspecialchars($period === 'custom' ? $dateTo : '') ?>" class="w-[7.5rem] px-2 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none" title="Hasta">
                <button type="submit" class="px-3 py-1.5 bg-corp-800 text-white text-xs font-medium rounded-lg hover:bg-corp-900 transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </button>
            </form>
        </div>
    </div>
</div>

<!-- KPI Cards + Focus Gauge -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <!-- Focus Score Gauge -->
    <div class="lg:col-span-1 bg-white rounded-xl border border-gray-100 p-4 sm:p-6 flex flex-col items-center justify-center">
        <h3 class="text-sm font-semibold text-dark mb-3">Focus Score</h3>
        <div class="relative w-36 h-36">
            <svg viewBox="0 0 120 120" class="w-36 h-36">
                <!-- Background arc (270°) -->
                <path d="M 60 105 A 45 45 0 1 1 60.001 105" fill="none" stroke="#e5e7eb" stroke-width="10" stroke-linecap="round"
                      transform="rotate(135 60 60)"
                      stroke-dasharray="<?= round(2 * M_PI * 45 * 270 / 360) ?>"
                      stroke-dashoffset="0"/>
                <!-- Value arc -->
                <path d="M 60 105 A 45 45 0 1 1 60.001 105" fill="none" stroke="<?= focusGradient($avgFocus) ?>" stroke-width="10" stroke-linecap="round"
                      transform="rotate(135 60 60)"
                      stroke-dasharray="<?= round(2 * M_PI * 45 * 270 / 360) ?>"
                      stroke-dashoffset="<?= round(2 * M_PI * 45 * 270 / 360 * (1 - $avgFocus / 100)) ?>"/>
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-3xl font-bold <?= focusColor($avgFocus) ?>"><?= number_format($avgFocus, 0) ?></span>
                <span class="text-xs text-muted">de 100</span>
            </div>
        </div>
        <p class="text-xs text-muted mt-2 text-center">Promedio del equipo</p>
    </div>

    <!-- KPI mini cards -->
    <div class="lg:col-span-3 grid grid-cols-2 lg:grid-cols-3 gap-3 sm:gap-5">
        <!-- Productividad -->
        <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-emerald-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-dark"><?= number_format($avgProd, 0) ?>%</p>
            <p class="text-xs text-muted mt-1">Productividad</p>
        </div>

        <!-- Constancia -->
        <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-blue-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-dark"><?= number_format($avgConst, 0) ?>%</p>
            <p class="text-xs text-muted mt-1">Constancia</p>
        </div>

        <!-- Deep Work Promedio -->
        <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-corp-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-dark"><?= fmtDeepWork($avgDeepSec) ?></p>
            <p class="text-xs text-muted mt-1">Deep Work Prom.</p>
        </div>

        <!-- Context Switches -->
        <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-amber-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/></svg>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-dark"><?= number_format($avgSwitch, 0) ?></p>
            <p class="text-xs text-muted mt-1">Cambios App Prom.</p>
        </div>

        <!-- Puntualidad -->
        <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 <?= $avgPunct >= 0 ? 'bg-emerald-50' : 'bg-red-50' ?> rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 <?= $avgPunct >= 0 ? 'text-emerald-600' : 'text-red-600' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-dark"><?= $avgPunct >= 0 ? '+' : '' ?><?= number_format($avgPunct, 0) ?>m</p>
            <p class="text-xs text-muted mt-1">Puntualidad Prom.</p>
        </div>

        <!-- Usuarios rastreados -->
        <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-6 text-center">
            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gray-50 rounded-xl flex items-center justify-center mx-auto mb-2 sm:mb-3">
                <svg class="w-4 h-4 sm:w-5 sm:h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
            <p class="text-2xl sm:text-3xl font-bold text-dark"><?= $userCount ?></p>
            <p class="text-xs text-muted mt-1">Usuarios Rastreados</p>
        </div>
    </div>
</div>

<!-- Tendencias + Team Ranking -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <!-- Tendencia Semanal -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center gap-2 mb-1">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/></svg>
            <h3 class="text-base font-bold text-dark">Tendencia Semanal</h3>
        </div>
        <p class="text-xs text-muted mb-5">Focus Score y Productividad — últimas 8 semanas</p>

        <?php if (empty($chartData)): ?>
            <p class="text-sm text-muted text-center py-8">Sin datos de tendencias</p>
        <?php else: ?>
        <div class="overflow-x-auto">
            <svg viewBox="0 0 500 200" class="w-full" style="min-width:400px">
                <!-- Grid -->
                <line x1="50" y1="10" x2="50" y2="170" stroke="#e5e7eb" stroke-width="1"/>
                <line x1="50" y1="170" x2="490" y2="170" stroke="#e5e7eb" stroke-width="1"/>
                <?php for ($i = 0; $i <= 4; $i++):
                    $y = 170 - ($i * 40);
                    $val = $i * 25;
                ?>
                <line x1="50" y1="<?= $y ?>" x2="490" y2="<?= $y ?>" stroke="#f3f4f6" stroke-width="1"/>
                <text x="45" y="<?= $y + 4 ?>" text-anchor="end" fill="#9d9d9c" font-size="10"><?= $val ?></text>
                <?php endfor; ?>

                <?php
                $count = count($chartData);
                $stepX = $count > 1 ? 440 / ($count - 1) : 0;

                // Build polyline points
                $focusPoints = [];
                $prodPoints = [];
                foreach ($chartData as $i => $d) {
                    $x = 50 + ($i * $stepX);
                    $yFocus = 170 - ($d['focus'] / 100 * 160);
                    $yProd  = 170 - ($d['prod'] / 100 * 160);
                    $focusPoints[] = round($x, 1) . ',' . round($yFocus, 1);
                    $prodPoints[]  = round($x, 1) . ',' . round($yProd, 1);
                }
                ?>

                <!-- Focus Score line -->
                <polyline points="<?= implode(' ', $focusPoints) ?>" fill="none" stroke="#003a5d" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>
                <!-- Productivity line -->
                <polyline points="<?= implode(' ', $prodPoints) ?>" fill="none" stroke="#10b981" stroke-width="2" stroke-dasharray="6 3" stroke-linejoin="round" stroke-linecap="round"/>

                <!-- Dots + labels -->
                <?php foreach ($chartData as $i => $d):
                    $x = 50 + ($i * $stepX);
                    $yFocus = 170 - ($d['focus'] / 100 * 160);
                    $yProd  = 170 - ($d['prod'] / 100 * 160);
                ?>
                <circle cx="<?= round($x, 1) ?>" cy="<?= round($yFocus, 1) ?>" r="3.5" fill="#003a5d"/>
                <circle cx="<?= round($x, 1) ?>" cy="<?= round($yProd, 1) ?>" r="3" fill="#10b981"/>
                <text x="<?= round($x, 1) ?>" y="188" text-anchor="middle" fill="#9d9d9c" font-size="9"><?= $d['label'] ?></text>
                <?php endforeach; ?>
            </svg>
        </div>

        <div class="flex items-center justify-center gap-6 mt-3 text-xs">
            <div class="flex items-center gap-1.5">
                <span class="w-4 h-0.5 bg-corp-800 rounded inline-block"></span>
                <span class="text-muted">Focus Score</span>
            </div>
            <div class="flex items-center gap-1.5">
                <span class="w-4 h-0.5 bg-emerald-500 rounded inline-block" style="border-top: 2px dashed #10b981;height:0"></span>
                <span class="text-muted">Productividad</span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Ranking por Equipo -->
    <div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6">
        <div class="flex items-center justify-between mb-1">
            <div class="flex items-center gap-2">
                <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                <h3 class="text-base font-bold text-dark">Ranking por Equipo</h3>
            </div>
            <div class="flex items-center gap-1 bg-gray-50 rounded-lg p-0.5">
                <a href="?period=<?= $period ?>&team=firm<?= $period === 'custom' ? '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) : '' ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $teamGroup === 'firm' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Firma</a>
                <a href="?period=<?= $period ?>&team=area<?= $period === 'custom' ? '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) : '' ?>" class="px-2 py-1 rounded text-xs font-medium transition-colors <?= $teamGroup === 'area' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark' ?>">Área</a>
            </div>
        </div>
        <p class="text-xs text-muted mb-4">Promedio de Focus Score por <?= $teamGroup === 'firm' ? 'firma' : 'área' ?></p>

        <?php if (empty($teamRanking)): ?>
            <p class="text-sm text-muted text-center py-8">Sin datos de equipos</p>
        <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($teamRanking as $idx => $team):
                $teamFocus = (float)($team['avg_focus'] ?? 0);
            ?>
            <div class="flex items-center gap-3">
                <span class="w-6 h-6 <?= $idx === 0 ? 'bg-corp-800 text-white' : 'bg-gray-100 text-gray-600' ?> rounded-lg flex items-center justify-center text-xs font-bold flex-shrink-0"><?= $idx + 1 ?></span>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-center mb-1">
                        <span class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($team['group_name'] ?? 'Sin asignar') ?></span>
                        <div class="flex items-center gap-2 flex-shrink-0">
                            <span class="text-xs <?= focusColor($teamFocus) ?> font-semibold"><?= number_format($teamFocus, 0) ?></span>
                            <span class="text-xs text-muted"><?= (int)($team['user_count'] ?? 0) ?> usu.</span>
                        </div>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full transition-all" style="width: <?= min(100, $teamFocus) ?>%; background: <?= focusGradient($teamFocus) ?>;"></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Ranking Individual -->
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-4 sm:mb-8">
    <div class="flex items-center justify-between mb-1">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <h3 class="text-base font-bold text-dark">Ranking Individual</h3>
        </div>
        <a href="?period=<?= $period ?>&team=<?= $teamGroup ?>&sort=<?= $sortOrder === 'DESC' ? 'asc' : 'desc' ?><?= $period === 'custom' ? '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) : '' ?>" class="flex items-center gap-1 text-xs text-muted hover:text-dark transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"/></svg>
            <?= $sortOrder === 'DESC' ? 'Mayor → Menor' : 'Menor → Mayor' ?>
        </a>
    </div>
    <p class="text-xs text-muted mb-4"><?= $totalUsers ?> usuarios con métricas · Página <?= $page ?> de <?= $totalPages ?></p>

    <?php if (empty($userRanking)): ?>
        <p class="text-sm text-muted text-center py-8">Sin métricas de productividad en este período</p>
    <?php else: ?>
    <!-- Desktop table -->
    <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 text-xs text-muted uppercase tracking-wider">
                    <th class="text-left py-3 px-2">#</th>
                    <th class="text-left py-3 px-2">Usuario</th>
                    <th class="text-left py-3 px-2">Firma / Área</th>
                    <th class="text-center py-3 px-2">Focus</th>
                    <th class="text-center py-3 px-2">Productividad</th>
                    <th class="text-center py-3 px-2">Constancia</th>
                    <th class="text-center py-3 px-2">Deep Work</th>
                    <th class="text-center py-3 px-2">Cambios</th>
                    <th class="text-center py-3 px-2">Días</th>
                    <th class="text-center py-3 px-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($userRanking as $idx => $u):
                    $rank = ($page - 1) * $perPage + $idx + 1;
                    $uFocus = (float)($u['avg_focus'] ?? 0);
                    $uProd  = (float)($u['avg_productivity'] ?? 0);
                    $uConst = (float)($u['avg_constancy'] ?? 0);
                    $uDeep  = (int)($u['avg_deep_work_sec'] ?? 0);
                    $uSwitch = (int)($u['avg_switches'] ?? 0);
                    $uDays  = (int)($u['days_tracked'] ?? 0);
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="py-3 px-2">
                        <span class="w-6 h-6 <?= $rank <= 3 ? 'bg-corp-800 text-white' : 'bg-gray-100 text-gray-600' ?> rounded-lg flex items-center justify-center text-xs font-bold"><?= $rank ?></span>
                    </td>
                    <td class="py-3 px-2">
                        <a href="user-dashboard.php?id=<?= (int)$u['user_id'] ?>" class="text-sm font-medium text-dark hover:text-corp-800 transition-colors"><?= htmlspecialchars($u['display_name'] ?? '') ?></a>
                        <p class="text-xs text-muted truncate max-w-[180px]"><?= htmlspecialchars($u['email'] ?? '') ?></p>
                    </td>
                    <td class="py-3 px-2 text-xs text-muted">
                        <?= htmlspecialchars($u['firma_nombre'] ?? '—') ?>
                        <?php if (!empty($u['area_nombre'])): ?><br><span class="text-gray-400"><?= htmlspecialchars($u['area_nombre']) ?></span><?php endif; ?>
                    </td>
                    <td class="py-3 px-2 text-center">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold <?= focusBg($uFocus) ?> <?= focusColor($uFocus) ?>">
                            <?= number_format($uFocus, 0) ?>
                        </span>
                    </td>
                    <td class="py-3 px-2 text-center text-sm font-medium"><?= number_format($uProd, 0) ?>%</td>
                    <td class="py-3 px-2 text-center text-sm"><?= number_format($uConst, 0) ?>%</td>
                    <td class="py-3 px-2 text-center text-sm"><?= fmtDeepWork($uDeep) ?></td>
                    <td class="py-3 px-2 text-center text-sm"><?= $uSwitch ?></td>
                    <td class="py-3 px-2 text-center text-xs text-muted"><?= $uDays ?>d</td>
                    <td class="py-3 px-2 text-center">
                        <a href="user-dashboard.php?id=<?= (int)$u['user_id'] ?>" class="text-corp-800 hover:text-corp-600 transition-colors" title="Ver detalle">
                            <svg class="w-4 h-4 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile cards -->
    <div class="md:hidden space-y-3">
        <?php foreach ($userRanking as $idx => $u):
            $rank = ($page - 1) * $perPage + $idx + 1;
            $uFocus = (float)($u['avg_focus'] ?? 0);
            $uProd  = (float)($u['avg_productivity'] ?? 0);
        ?>
        <a href="user-dashboard.php?id=<?= (int)$u['user_id'] ?>" class="block bg-gray-50 rounded-xl p-3 hover:bg-gray-100 transition-colors">
            <div class="flex items-center justify-between mb-2">
                <div class="flex items-center gap-2">
                    <span class="w-6 h-6 <?= $rank <= 3 ? 'bg-corp-800 text-white' : 'bg-gray-200 text-gray-600' ?> rounded-lg flex items-center justify-center text-xs font-bold"><?= $rank ?></span>
                    <span class="text-sm font-medium text-dark truncate max-w-[180px]"><?= htmlspecialchars($u['display_name'] ?? '') ?></span>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold <?= focusBg($uFocus) ?> <?= focusColor($uFocus) ?>"><?= number_format($uFocus, 0) ?></span>
            </div>
            <div class="flex items-center gap-4 text-xs text-muted">
                <span>Prod: <?= number_format($uProd, 0) ?>%</span>
                <span>Deep: <?= fmtDeepWork((int)($u['avg_deep_work_sec'] ?? 0)) ?></span>
                <span><?= (int)($u['days_tracked'] ?? 0) ?>d</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="flex items-center justify-center gap-2 mt-6">
        <?php if ($page > 1): ?>
        <a href="?period=<?= $period ?>&team=<?= $teamGroup ?>&sort=<?= strtolower($sortOrder) ?>&page=<?= $page - 1 ?><?= $period === 'custom' ? '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) : '' ?>" class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-medium text-muted hover:text-dark hover:border-gray-300 transition-colors">← Anterior</a>
        <?php endif; ?>

        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        for ($p = $startPage; $p <= $endPage; $p++):
        ?>
        <a href="?period=<?= $period ?>&team=<?= $teamGroup ?>&sort=<?= strtolower($sortOrder) ?>&page=<?= $p ?><?= $period === 'custom' ? '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) : '' ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-xs font-medium transition-colors <?= $p === $page ? 'bg-corp-800 text-white' : 'text-muted hover:bg-gray-100' ?>"><?= $p ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
        <a href="?period=<?= $period ?>&team=<?= $teamGroup ?>&sort=<?= strtolower($sortOrder) ?>&page=<?= $page + 1 ?><?= $period === 'custom' ? '&from=' . urlencode($dateFrom) . '&to=' . urlencode($dateTo) : '' ?>" class="px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-medium text-muted hover:text-dark hover:border-gray-300 transition-colors">Siguiente →</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Settings: Focus Score Weights -->
<div class="bg-white rounded-xl border border-gray-100 mb-4 sm:mb-8" x-data="{ open: <?= $settingsMsg ? 'true' : 'false' ?> }">
    <button @click="open = !open" class="w-full flex items-center justify-between p-4 sm:p-6 hover:bg-gray-50 transition-colors rounded-xl">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 bg-corp-50 rounded-lg flex items-center justify-center">
                <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <div class="text-left">
                <h3 class="text-sm font-bold text-dark">Configuración del Focus Score</h3>
                <p class="text-xs text-muted">Ajusta los pesos de cada componente y el umbral de deep work</p>
            </div>
        </div>
        <svg class="w-5 h-5 text-muted transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
    </button>

    <div x-show="open" x-collapse>
        <div class="px-4 pb-4 sm:px-6 sm:pb-6 border-t border-gray-100 pt-4">
            <?php if ($settingsMsg): ?>
            <div class="mb-4 px-4 py-3 rounded-lg text-sm <?= $settingsMsg['type'] === 'success' ? 'bg-emerald-50 border border-emerald-200 text-emerald-800' : 'bg-red-50 border border-red-200 text-red-700' ?>">
                <?= htmlspecialchars($settingsMsg['text']) ?>
            </div>
            <?php endif; ?>

            <form method="post" x-data="{
                w_cs: <?= (int)$focusWeights['context_switches'] ?>,
                w_dw: <?= (int)$focusWeights['deep_work'] ?>,
                w_di: <?= (int)$focusWeights['distraction'] ?>,
                w_pu: <?= (int)$focusWeights['punctuality'] ?>,
                w_co: <?= (int)$focusWeights['constancy'] ?>,
                get total() { return this.w_cs + this.w_dw + this.w_di + this.w_pu + this.w_co },
                get isValid() { return this.total === 100 }
            }">
                <input type="hidden" name="action" value="save_focus_weights">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

                <div class="mb-4">
                    <p class="text-xs text-muted mb-3">El Focus Score se calcula como promedio ponderado de 5 componentes. Los pesos deben <strong>sumar exactamente 100</strong>.</p>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-5 gap-4 mb-4">
                    <!-- Context Switches -->
                    <div class="bg-gray-50 rounded-lg p-3">
                        <label class="text-xs font-semibold text-dark block mb-1">Cambios de Contexto</label>
                        <p class="text-[10px] text-muted mb-2">Menos cambios entre apps = mejor puntuación</p>
                        <div class="flex items-center gap-1">
                            <input type="number" name="w_context_switches" x-model.number="w_cs" min="0" max="100" step="5"
                                class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center font-semibold focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                            <span class="text-xs text-muted font-medium">%</span>
                        </div>
                    </div>

                    <!-- Deep Work -->
                    <div class="bg-gray-50 rounded-lg p-3">
                        <label class="text-xs font-semibold text-dark block mb-1">Deep Work</label>
                        <p class="text-[10px] text-muted mb-2">Tiempo en la misma app sin interrupciones</p>
                        <div class="flex items-center gap-1">
                            <input type="number" name="w_deep_work" x-model.number="w_dw" min="0" max="100" step="5"
                                class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center font-semibold focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                            <span class="text-xs text-muted font-medium">%</span>
                        </div>
                    </div>

                    <!-- Distraction -->
                    <div class="bg-gray-50 rounded-lg p-3">
                        <label class="text-xs font-semibold text-dark block mb-1">Distracción</label>
                        <p class="text-[10px] text-muted mb-2">Menos tiempo en leisure apps = mejor</p>
                        <div class="flex items-center gap-1">
                            <input type="number" name="w_distraction" x-model.number="w_di" min="0" max="100" step="5"
                                class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center font-semibold focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                            <span class="text-xs text-muted font-medium">%</span>
                        </div>
                    </div>

                    <!-- Punctuality -->
                    <div class="bg-gray-50 rounded-lg p-3">
                        <label class="text-xs font-semibold text-dark block mb-1">Puntualidad</label>
                        <p class="text-[10px] text-muted mb-2">Llegar a tiempo o temprano suma más</p>
                        <div class="flex items-center gap-1">
                            <input type="number" name="w_punctuality" x-model.number="w_pu" min="0" max="100" step="5"
                                class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center font-semibold focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                            <span class="text-xs text-muted font-medium">%</span>
                        </div>
                    </div>

                    <!-- Constancy -->
                    <div class="bg-gray-50 rounded-lg p-3">
                        <label class="text-xs font-semibold text-dark block mb-1">Constancia</label>
                        <p class="text-[10px] text-muted mb-2">Actividad en todos los bloques de 30 min</p>
                        <div class="flex items-center gap-1">
                            <input type="number" name="w_constancy" x-model.number="w_co" min="0" max="100" step="5"
                                class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center font-semibold focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
                            <span class="text-xs text-muted font-medium">%</span>
                        </div>
                    </div>
                </div>

                <!-- Sum indicator -->
                <div class="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-2.5 mb-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold text-muted">Total:</span>
                        <span class="text-sm font-bold" :class="isValid ? 'text-emerald-600' : 'text-red-600'" x-text="total + '%'"></span>
                        <template x-if="!isValid">
                            <span class="text-xs text-red-500 ml-1" x-text="total < 100 ? '(faltan ' + (100 - total) + '%)' : '(sobran ' + (total - 100) + '%)'"></span>
                        </template>
                        <template x-if="isValid">
                            <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </template>
                    </div>
                </div>

                <!-- Deep Work Threshold -->
                <div class="bg-blue-50 rounded-lg p-3 sm:p-4 mb-4">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        <div class="flex-1">
                            <label class="text-xs font-semibold text-dark block mb-0.5">Umbral de Deep Work</label>
                            <p class="text-[10px] text-muted">Minutos mínimos de trabajo continuo en la misma app para que cuente como una sesión de Deep Work (rango 5–120 min).</p>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <input type="number" name="deep_work_threshold" value="<?= $deepWorkThresholdMin ?>" min="5" max="120" step="5"
                                class="w-20 px-2 py-1.5 border border-gray-200 rounded-lg text-sm text-center font-semibold focus:ring-2 focus:ring-blue-200 focus:border-blue-400 outline-none">
                            <span class="text-xs text-muted font-medium">min</span>
                        </div>
                    </div>
                </div>

                <!-- Submit -->
                <div class="flex items-center gap-3">
                    <button type="submit" :disabled="!isValid"
                        class="px-5 py-2 bg-corp-800 text-white text-xs font-semibold rounded-lg hover:bg-corp-900 transition-colors disabled:opacity-50 disabled:cursor-not-allowed flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        Guardar Configuración
                    </button>
                    <p class="text-[10px] text-muted">Los cambios aplican a partir del próximo cálculo.</p>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
