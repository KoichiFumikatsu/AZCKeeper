<?php
/**
 * Políticas — Vista centrada en empleados.
 *
 * 3 modos de operación:
 *   1. Política Global: configuración base que aplica a todos.
 *   2. Override por usuario: se mezcla (deep merge) sobre la global.
 *   3. Batch: seleccionar varios empleados y aplicar/quitar override.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('policies');

$pageTitle   = 'Políticas';
$currentPage = 'policies';
$msg     = '';
$msgType = '';

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {

            /* ── Actualizar política global ── */
            case 'update_global':
                $jsonRaw = $_POST['policy_json'] ?? '{}';
                $decoded = json_decode($jsonRaw, true);
                if (!is_array($decoded)) throw new \Exception('JSON inválido');

                $st = $pdo->prepare("SELECT id FROM keeper_policy_assignments WHERE scope='global' AND is_active=1 LIMIT 1");
                $st->execute();
                $existing = $st->fetchColumn();

                if ($existing) {
                    $pdo->prepare("UPDATE keeper_policy_assignments SET policy_json=:json, version=version+1 WHERE id=:id")
                         ->execute([':json' => $jsonRaw, ':id' => $existing]);
                } else {
                    $pdo->exec("UPDATE keeper_policy_assignments SET is_active=0 WHERE scope='global'");
                    $pdo->prepare("INSERT INTO keeper_policy_assignments (scope,user_id,device_id,version,priority,is_active,policy_json) VALUES ('global',NULL,NULL,1,1,1,:json)")
                         ->execute([':json' => $jsonRaw]);
                }
                $msg = 'Política global actualizada.';
                $msgType = 'success';
                break;

            /* ── Guardar override de un usuario ── */
            case 'save_user_override':
                $userId  = (int)($_POST['user_id'] ?? 0);
                $jsonRaw = $_POST['policy_json'] ?? '{}';
                if ($userId <= 0) throw new \Exception('Usuario inválido');
                $decoded = json_decode($jsonRaw, true);
                if (!is_array($decoded)) throw new \Exception('JSON inválido');

                $st = $pdo->prepare("SELECT id FROM keeper_policy_assignments WHERE scope='user' AND user_id=:uid AND is_active=1 LIMIT 1");
                $st->execute([':uid' => $userId]);
                $existingId = $st->fetchColumn();

                if ($existingId) {
                    $pdo->prepare("UPDATE keeper_policy_assignments SET policy_json=:json, version=version+1 WHERE id=:id")
                         ->execute([':json' => $jsonRaw, ':id' => $existingId]);
                } else {
                    $pdo->prepare("UPDATE keeper_policy_assignments SET is_active=0 WHERE scope='user' AND user_id=:uid")
                         ->execute([':uid' => $userId]);
                    $pdo->prepare("INSERT INTO keeper_policy_assignments (scope,user_id,device_id,version,priority,is_active,policy_json) VALUES ('user',:uid,NULL,1,50,1,:json)")
                         ->execute([':uid' => $userId, ':json' => $jsonRaw]);
                }
                $msg = 'Override del usuario guardado.';
                $msgType = 'success';
                break;

            /* ── Eliminar override ── */
            case 'remove_override':
                $userId = (int)($_POST['user_id'] ?? 0);
                if ($userId <= 0) throw new \Exception('Usuario inválido');
                $pdo->prepare("DELETE FROM keeper_policy_assignments WHERE scope='user' AND user_id=:uid")
                     ->execute([':uid' => $userId]);
                $msg = 'Override eliminado.';
                $msgType = 'success';
                break;

            /* ── Batch: aplicar override ── */
            case 'batch_apply':
                $userIds = $_POST['batch_users'] ?? [];
                $jsonRaw = $_POST['policy_json'] ?? '{}';
                $decoded = json_decode($jsonRaw, true);
                if (!is_array($decoded)) throw new \Exception('JSON inválido');
                if (empty($userIds))     throw new \Exception('Selecciona al menos un usuario');

                $count = 0;
                foreach ($userIds as $uid) {
                    $uid = (int)$uid;
                    if ($uid <= 0) continue;
                    $st = $pdo->prepare("SELECT id FROM keeper_policy_assignments WHERE scope='user' AND user_id=:uid AND is_active=1 LIMIT 1");
                    $st->execute([':uid' => $uid]);
                    $eid = $st->fetchColumn();
                    if ($eid) {
                        $pdo->prepare("UPDATE keeper_policy_assignments SET policy_json=:json, version=version+1 WHERE id=:id")
                             ->execute([':json' => $jsonRaw, ':id' => $eid]);
                    } else {
                        $pdo->prepare("UPDATE keeper_policy_assignments SET is_active=0 WHERE scope='user' AND user_id=:uid")->execute([':uid' => $uid]);
                        $pdo->prepare("INSERT INTO keeper_policy_assignments (scope,user_id,device_id,version,priority,is_active,policy_json) VALUES ('user',:uid,NULL,1,50,1,:json)")
                             ->execute([':uid' => $uid, ':json' => $jsonRaw]);
                    }
                    $count++;
                }
                $msg = "Override aplicado a {$count} usuario(s).";
                $msgType = 'success';
                break;

            /* ── Batch: quitar overrides ── */
            case 'batch_remove':
                $userIds = $_POST['batch_users'] ?? [];
                if (empty($userIds)) throw new \Exception('Selecciona al menos un usuario');
                $ph = implode(',', array_fill(0, count($userIds), '?'));
                $st = $pdo->prepare("DELETE FROM keeper_policy_assignments WHERE scope='user' AND user_id IN ({$ph})");
                $st->execute(array_map('intval', $userIds));
                $msg = "Override eliminado de {$st->rowCount()} registro(s).";
                $msgType = 'success';
                break;

            /* ── Force Push ── */
            case 'force_push':
                $st = $pdo->prepare("UPDATE keeper_policy_assignments SET version=version+1 WHERE scope='global' AND is_active=1");
                $st->execute();
                $msg = $st->rowCount() > 0 ? 'Force Push enviado.' : 'No hay política global activa.';
                $msgType = $st->rowCount() > 0 ? 'success' : 'warning';
                break;

            /* ── Guardar horario global ── */
            case 'save_global_schedule':
                $ws = $_POST['work_start'] ?? '07:00';
                $we = $_POST['work_end']   ?? '19:00';
                $ls = $_POST['lunch_start'] ?? '12:00';
                $le = $_POST['lunch_end']   ?? '13:00';
                $days = isset($_POST['applicable_days']) ? implode(',', $_POST['applicable_days']) : '1,2,3,4,5';
                // Upsert: Check if global schedule exists
                $st = $pdo->query("SELECT id FROM keeper_work_schedules WHERE user_id IS NULL AND is_active=1 LIMIT 1");
                $gid = $st->fetchColumn();
                if ($gid) {
                    $pdo->prepare("UPDATE keeper_work_schedules SET work_start_time=:ws, work_end_time=:we, lunch_start_time=:ls, lunch_end_time=:le, applicable_days=:days WHERE id=:id")
                         ->execute([':ws'=>$ws, ':we'=>$we, ':ls'=>$ls, ':le'=>$le, ':days'=>$days, ':id'=>$gid]);
                } else {
                    $pdo->prepare("INSERT INTO keeper_work_schedules (user_id, work_start_time, work_end_time, lunch_start_time, lunch_end_time, applicable_days, timezone, is_active) VALUES (NULL,:ws,:we,:ls,:le,:days,'America/Bogota',1)")
                         ->execute([':ws'=>$ws, ':we'=>$we, ':ls'=>$ls, ':le'=>$le, ':days'=>$days]);
                }
                $msg = 'Horario global actualizado.';
                $msgType = 'success';
                break;

            /* ── Guardar horario de usuario ── */
            case 'save_user_schedule':
                $uid = (int)($_POST['sched_user_id'] ?? 0);
                if ($uid <= 0) throw new \Exception('Usuario inválido');
                $ws = $_POST['work_start'] ?? '07:00';
                $we = $_POST['work_end']   ?? '19:00';
                $ls = $_POST['lunch_start'] ?? '12:00';
                $le = $_POST['lunch_end']   ?? '13:00';
                $days = isset($_POST['applicable_days']) ? implode(',', $_POST['applicable_days']) : '1,2,3,4,5';
                $st = $pdo->prepare("SELECT id FROM keeper_work_schedules WHERE user_id=:uid AND is_active=1 LIMIT 1");
                $st->execute([':uid'=>$uid]);
                $eid = $st->fetchColumn();
                if ($eid) {
                    $pdo->prepare("UPDATE keeper_work_schedules SET work_start_time=:ws, work_end_time=:we, lunch_start_time=:ls, lunch_end_time=:le, applicable_days=:days WHERE id=:id")
                         ->execute([':ws'=>$ws, ':we'=>$we, ':ls'=>$ls, ':le'=>$le, ':days'=>$days, ':id'=>$eid]);
                } else {
                    $pdo->prepare("INSERT INTO keeper_work_schedules (user_id, work_start_time, work_end_time, lunch_start_time, lunch_end_time, applicable_days, timezone, is_active) VALUES (:uid,:ws,:we,:ls,:le,:days,'America/Bogota',1)")
                         ->execute([':uid'=>$uid, ':ws'=>$ws, ':we'=>$we, ':ls'=>$ls, ':le'=>$le, ':days'=>$days]);
                }
                $msg = 'Horario del usuario actualizado.';
                $msgType = 'success';
                break;

            /* ── Eliminar horario de usuario ── */
            case 'remove_user_schedule':
                $uid = (int)($_POST['sched_user_id'] ?? 0);
                if ($uid <= 0) throw new \Exception('Usuario inválido');
                $pdo->prepare("DELETE FROM keeper_work_schedules WHERE user_id=:uid")->execute([':uid'=>$uid]);
                $msg = 'Horario personalizado eliminado. Aplica horario global.';
                $msgType = 'success';
                break;

            /* ── Guardar apps/ventanas de descanso/despeje ── */
            case 'save_leisure_apps':
                $rawApps = trim($_POST['leisure_apps_raw'] ?? '');
                $rawWins = trim($_POST['leisure_windows_raw'] ?? '');
                $apps = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawApps)))));
                $wins = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $rawWins)))));
                $jsonVal = json_encode(['apps' => $apps, 'windows' => $wins], JSON_UNESCAPED_UNICODE);
                $st = $pdo->prepare("SELECT id FROM keeper_panel_settings WHERE setting_key = 'leisure_apps' LIMIT 1");
                $st->execute();
                if ($st->fetchColumn()) {
                    $pdo->prepare("UPDATE keeper_panel_settings SET setting_value = :v WHERE setting_key = 'leisure_apps'")->execute([':v' => $jsonVal]);
                } else {
                    $pdo->prepare("INSERT INTO keeper_panel_settings (setting_key, setting_value) VALUES ('leisure_apps', :v)")->execute([':v' => $jsonVal]);
                }
                $msg = count($apps) . ' aplicación(es) y ' . count($wins) . ' ventana(s) de descanso guardadas.';
                $msgType = 'success';
                break;

            default:
                throw new \Exception('Acción desconocida');
        }
    } catch (\Exception $e) {
        $msg     = $e->getMessage();
        $msgType = 'error';
    }

    if ($msgType === 'success') {
        header("Location: policies.php?msg=" . urlencode($msg) . "&type=success");
        exit;
    }
}

/* Flash PRG */
if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== QUERIES ==================== */

/* Política global activa */
$activeGlobal = $pdo->query("
    SELECT * FROM keeper_policy_assignments
    WHERE scope='global' AND is_active=1
    ORDER BY version DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

$globalJson = $activeGlobal ? (json_decode($activeGlobal['policy_json'], true) ?? []) : [];

/* Overrides activos por usuario (indexados por user_id) */
$userOverrides = [];
$ovSt = $pdo->query("
    SELECT id, user_id, policy_json, version, is_active, created_at
    FROM keeper_policy_assignments WHERE scope='user' AND is_active=1 ORDER BY user_id
");
while ($ov = $ovSt->fetch(PDO::FETCH_ASSOC)) {
    $userOverrides[(int)$ov['user_id']] = $ov;
}

$scope = scopeFilter();

/* Empleados con assignment + dispositivo */
$usersQuery = "
    SELECT u.id, u.display_name, u.email, u.status,
           ua.firm_id, ua.area_id, ua.sociedad_id,
           soc.nombre AS sociedad_name, f.nombre AS firm_name, a.nombre AS area_name,
           d.device_name, d.client_version, d.last_seen_at
    FROM keeper_users u
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    LEFT JOIN keeper_sociedades soc ON soc.id = ua.sociedad_id
    LEFT JOIN keeper_firmas f  ON f.id = ua.firm_id
    LEFT JOIN keeper_areas a ON a.id = ua.area_id
    LEFT JOIN (
        SELECT user_id, MAX(device_name) AS device_name, MAX(client_version) AS client_version, MAX(last_seen_at) AS last_seen_at
        FROM keeper_devices WHERE status='active' GROUP BY user_id
    ) d ON d.user_id = u.id
    WHERE u.status='active' {$scope['sql']}
    ORDER BY u.display_name ASC
";
$usersSt = $pdo->prepare($usersQuery);
$usersSt->execute($scope['params']);
$allUsers = $usersSt->fetchAll(PDO::FETCH_ASSOC);

/* KPIs */
$totalUsers = count($allUsers);
$usersWithOverride = 0;
$usersGlobalOnly   = 0;
foreach ($allUsers as $u) {
    if (isset($userOverrides[$u['id']])) $usersWithOverride++;
    else $usersGlobalOnly++;
}

/* ── Helpers ── */
function fmtDate(string $dt): string { return date('d M Y H:i', strtotime($dt)); }

/* ── Horarios laborales ── */
$globalSchedule = $pdo->query("
    SELECT id, work_start_time, work_end_time, lunch_start_time, lunch_end_time, applicable_days
    FROM keeper_work_schedules WHERE user_id IS NULL AND is_active=1 LIMIT 1
")->fetch(PDO::FETCH_ASSOC);
if (!$globalSchedule) {
    $globalSchedule = ['work_start_time'=>'07:00:00','work_end_time'=>'19:00:00','lunch_start_time'=>'12:00:00','lunch_end_time'=>'13:00:00','applicable_days'=>'1,2,3,4,5'];
}

$userSchedules = [];
$schSt = $pdo->query("
    SELECT ws.id, ws.user_id, ws.work_start_time, ws.work_end_time, ws.lunch_start_time, ws.lunch_end_time, ws.applicable_days,
           u.display_name
    FROM keeper_work_schedules ws
    JOIN keeper_users u ON u.id = ws.user_id
    WHERE ws.user_id IS NOT NULL AND ws.is_active=1
    ORDER BY u.display_name ASC
");
while ($s = $schSt->fetch(PDO::FETCH_ASSOC)) {
    $userSchedules[(int)$s['user_id']] = $s;
}

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- ═══════════════════════════════════════════════════════
     Alpine root — contiene toda la lógica de la página
     ═══════════════════════════════════════════════════════ -->
<div x-data="policiesPage()" x-cloak>

<!-- ──────── Flash message ──────── -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-xl text-sm font-medium flex items-center gap-2
    <?= $msgType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' ?>
    <?= $msgType === 'error'   ? 'bg-red-50 text-accent-500 border border-red-200' : '' ?>
    <?= $msgType === 'warning' ? 'bg-amber-50 text-amber-700 border border-amber-200' : '' ?>"
    x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,5000)" x-transition>
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ──────── Header ──────── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 sm:mb-6">
    <p class="text-xs sm:text-sm text-muted">Configura la política global y overrides individuales por empleado.</p>
    <div class="flex items-center gap-2 sm:gap-3">
        <!-- Force Push -->
        <form method="post" onsubmit="return confirm('¿Incrementar versión global?')">
            <input type="hidden" name="action" value="force_push">
            <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1.5 sm:px-4 sm:py-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-xs sm:text-sm font-medium hover:bg-amber-100 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Force Push
            </button>
        </form>
        <!-- Batch button (visible when selection>0) -->
        <button @click="openBatchModal()" x-show="selectedUsers.length>0" x-transition
                class="inline-flex items-center gap-2 px-4 py-2 bg-purple-600 text-white rounded-xl text-sm font-medium hover:bg-purple-700 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            Batch (<span x-text="selectedUsers.length"></span>)
        </button>
    </div>
</div>

<!-- ──────── KPI Cards ──────── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-5 mb-4 sm:mb-8">
    <!-- Versión Global -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-emerald-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark">v<?= $activeGlobal ? (int)$activeGlobal['version'] : 0 ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Versión Global</p>
            </div>
        </div>
    </div>
    <!-- Empleados activos -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-blue-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $totalUsers ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Empleados activos</p>
            </div>
        </div>
    </div>
    <!-- Solo Global -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-gray-100 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $usersGlobalOnly ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Solo Global</p>
            </div>
        </div>
    </div>
    <!-- Con Override -->
    <div class="bg-white rounded-xl border border-gray-100 p-3 sm:p-5">
        <div class="flex items-center gap-2 sm:gap-3">
            <div class="w-8 h-8 sm:w-9 sm:h-9 bg-purple-50 rounded-lg flex items-center justify-center">
                <svg class="w-4 h-4 sm:w-4.5 sm:h-4.5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            </div>
            <div>
                <p class="text-xl sm:text-2xl font-bold text-dark"><?= $usersWithOverride ?></p>
                <p class="text-[10px] sm:text-xs text-muted">Con Override</p>
            </div>
        </div>
    </div>
</div>

<!-- ──────── Política Global Card ──────── -->
<?php if ($activeGlobal): $g = $globalJson; ?>
<div class="bg-white rounded-xl border-2 border-emerald-200 p-4 sm:p-6 mb-4 sm:mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4 sm:mb-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <h2 class="text-base font-bold text-dark">Política Global <span class="font-normal text-emerald-600">(base para todos)</span></h2>
                <p class="text-xs text-muted">ID #<?= $activeGlobal['id'] ?> · v<?= $activeGlobal['version'] ?> · <?= fmtDate($activeGlobal['updated_at'] ?? $activeGlobal['created_at']) ?></p>
            </div>
        </div>
        <button @click="openEditor('global')" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white bg-corp-800 rounded-lg hover:bg-corp-900 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            Editar Global
        </button>
    </div>
    <!-- Summary grid -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2 sm:gap-3">
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Timers</p>
            <p class="text-xs text-dark">Handshake: <b><?= $g['timers']['handshakeIntervalMinutes'] ?? '?' ?>m</b></p>
            <p class="text-xs text-dark">Flush: <b><?= $g['timers']['activityFlushIntervalSeconds'] ?? '?' ?>s</b></p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Logging</p>
            <p class="text-xs text-dark">Nivel: <b><?= $g['logging']['globalLevel'] ?? '?' ?></b></p>
            <p class="text-xs text-dark flex items-center gap-1">File: <?= !empty($g['logging']['enableFileLogging']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Módulos</p>
            <p class="text-xs text-dark flex items-center gap-1">Activity: <?= !empty($g['modules']['enableActivityTracking']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
            <p class="text-xs text-dark flex items-center gap-1">Windows: <?= !empty($g['modules']['enableWindowTracking']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
            <p class="text-xs text-dark flex items-center gap-1">Blocking: <?= !empty($g['modules']['enableBlocking']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Startup</p>
            <p class="text-xs text-dark flex items-center gap-1">AutoStart: <?= !empty($g['startup']['enableAutoStartup']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
            <p class="text-xs text-dark flex items-center gap-1">Minimized: <?= !empty($g['startup']['startMinimized']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Updates</p>
            <p class="text-xs text-dark flex items-center gap-1">Auto: <?= !empty($g['updates']['enableAutoUpdate']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
            <p class="text-xs text-dark">Intervalo: <b><?= $g['updates']['checkIntervalMinutes'] ?? '?' ?>m</b></p>
        </div>
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Bloqueo</p>
            <p class="text-xs text-dark flex items-center gap-1">Lock: <?= !empty($g['blocking']['enableDeviceLock']) ? '<span class="inline-flex items-center gap-1 text-accent-500 font-semibold"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> SÍ</span>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> No' ?></p>
            <p class="text-xs text-dark flex items-center gap-1">PIN: <?= !empty($g['blocking']['allowUnlockWithPin']) ? '<svg class="w-3.5 h-3.5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : '<svg class="w-3.5 h-3.5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' ?></p>
        </div>
    </div>
</div>
<?php else: ?>
<div class="bg-amber-50 border border-amber-200 rounded-xl p-6 mb-8 text-center">
    <p class="text-amber-700 font-medium mb-2">No hay política global activa</p>
    <p class="text-amber-600 text-sm mb-4">Los clientes no recibirán configuración. Crea una para empezar.</p>
    <button @click="openEditor('global')" class="px-5 py-2 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900">Crear Política Global</button>
</div>
<?php endif; ?>

<!-- ──────── Filtros y búsqueda ──────── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
    <div class="flex items-center gap-1.5 sm:gap-2 overflow-x-auto">
        <button @click="filterMode='all'" class="px-2.5 sm:px-3 py-1.5 rounded-lg text-[11px] sm:text-xs font-medium transition-colors whitespace-nowrap flex-shrink-0" :class="filterMode==='all'?'bg-corp-800 text-white':'bg-white border border-gray-200 text-muted hover:text-dark'">Todos (<?= $totalUsers ?>)</button>
        <button @click="filterMode='global'" class="px-2.5 sm:px-3 py-1.5 rounded-lg text-[11px] sm:text-xs font-medium transition-colors inline-flex items-center gap-1 whitespace-nowrap flex-shrink-0" :class="filterMode==='global'?'bg-blue-600 text-white':'bg-white border border-gray-200 text-muted hover:text-dark'"><svg class="w-3.5 h-3.5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Global (<?= $usersGlobalOnly ?>)</button>
        <button @click="filterMode='override'" class="px-2.5 sm:px-3 py-1.5 rounded-lg text-[11px] sm:text-xs font-medium transition-colors inline-flex items-center gap-1 whitespace-nowrap flex-shrink-0" :class="filterMode==='override'?'bg-purple-600 text-white':'bg-white border border-gray-200 text-muted hover:text-dark'"><svg class="w-3.5 h-3.5 hidden sm:block" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> Override (<?= $usersWithOverride ?>)</button>
    </div>
    <div class="relative">
        <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" x-model="search" @input="resetPage()" placeholder="Buscar empleado…" class="pl-10 pr-4 py-2 border border-gray-200 rounded-lg text-sm w-full sm:w-64 focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
    </div>
</div>

<!-- ──────── Tabla de Empleados ──────── -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-4 sm:mb-6">
<div class="overflow-x-auto">
<table class="w-full text-sm">
<thead>
    <tr class="border-b border-gray-100 bg-gray-50/50">
        <th class="py-3 px-2 sm:px-4 text-left"><input type="checkbox" @change="toggleSelectAll($event)" class="rounded border-gray-300 text-corp-600 focus:ring-corp-200"></th>
        <th class="text-left py-3 px-2 sm:px-4 text-xs font-semibold text-muted uppercase tracking-wider">Empleado</th>
        <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider hidden md:table-cell">Firma / Área</th>
        <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Dispositivo</th>
        <th class="text-center py-3 px-2 sm:px-4 text-xs font-semibold text-muted uppercase tracking-wider">Política</th>
        <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider hidden lg:table-cell">Versión</th>
        <th class="text-right py-3 px-2 sm:px-4 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
    </tr>
</thead>
<tbody class="divide-y divide-gray-50">
<?php foreach ($allUsers as $u):
    $uid = (int)$u['id'];
    $hasOv = isset($userOverrides[$uid]);
    $ovJson = $hasOv ? (json_decode($userOverrides[$uid]['policy_json'], true) ?? []) : [];
    $ovVer  = $hasOv ? (int)$userOverrides[$uid]['version'] : 0;
    $jsName = htmlspecialchars(json_encode($u['display_name']), ENT_QUOTES);
    $jsEmail = htmlspecialchars(json_encode($u['email'] ?? ''), ENT_QUOTES);
    $jsFirm = htmlspecialchars(json_encode($u['firm_name'] ?? ''), ENT_QUOTES);
    $jsSociedad = htmlspecialchars(json_encode($u['sociedad_name'] ?? ''), ENT_QUOTES);
    $jsOvJson = htmlspecialchars(json_encode($ovJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES);
?>
<tr class="hover:bg-gray-50/50 transition-colors <?= $hasOv ? 'bg-purple-50/20' : '' ?>"
    x-show="showRow(<?= $uid ?>, <?= $hasOv ? 'true' : 'false' ?>, <?= $jsName ?>, <?= $jsEmail ?>, <?= $jsFirm ?>, <?= $jsSociedad ?>)">
    <td class="py-3 px-2 sm:px-4">
        <input type="checkbox" :checked="selectedUsers.includes(<?= $uid ?>)" @change="toggleUser(<?= $uid ?>)" class="rounded border-gray-300 text-corp-600 focus:ring-corp-200">
    </td>
    <td class="py-3 px-2 sm:px-4">
        <p class="text-xs sm:text-sm font-medium text-dark truncate max-w-[120px] sm:max-w-none"><?= htmlspecialchars($u['display_name']) ?></p>
        <p class="text-[10px] sm:text-xs text-muted truncate max-w-[120px] sm:max-w-none"><?= htmlspecialchars($u['email'] ?? '') ?></p>
        <p class="text-[10px] text-muted md:hidden truncate"><?= htmlspecialchars($u['sociedad_name'] ?? '') ?><?= ($u['sociedad_name'] && $u['firm_name']) ? ' · ' : '' ?><?= htmlspecialchars($u['firm_name'] ?? '') ?></p>
    </td>
    <td class="py-3 px-4 hidden md:table-cell">
        <p class="text-xs text-dark"><?= htmlspecialchars($u['sociedad_name'] ?? '') ?><?= ($u['sociedad_name'] && $u['firm_name']) ? ' · ' : '' ?><?= htmlspecialchars($u['firm_name'] ?? '—') ?></p>
        <p class="text-xs text-muted"><?= htmlspecialchars($u['area_name'] ?? '') ?></p>
    </td>
    <td class="py-3 px-4 hidden lg:table-cell">
        <?php if ($u['device_name']): ?>
            <p class="text-xs text-dark"><?= htmlspecialchars($u['device_name']) ?></p>
            <?php if ($u['last_seen_at']): ?><p class="text-[10px] text-muted"><?= fmtDate($u['last_seen_at']) ?></p><?php endif; ?>
        <?php else: ?>
            <span class="text-xs text-muted">Sin dispositivo</span>
        <?php endif; ?>
    </td>
    <td class="py-3 px-2 sm:px-4 text-center">
        <?php if ($hasOv): ?>
        <span class="inline-flex items-center gap-1 text-xs font-semibold text-purple-700 bg-purple-50 px-2.5 py-1 rounded-full"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg> Override v<?= $ovVer ?></span>
        <?php else: ?>
        <span class="inline-flex items-center gap-1 text-xs font-medium text-blue-600 bg-blue-50 px-2.5 py-1 rounded-full"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Global</span>
        <?php endif; ?>
    </td>
    <td class="py-3 px-4 hidden lg:table-cell">
        <?php if (!empty($u['client_version'])): ?>
        <span class="inline-flex items-center text-xs font-mono font-medium text-dark bg-gray-100 px-2 py-0.5 rounded">v<?= htmlspecialchars($u['client_version']) ?></span>
        <?php else: ?>
        <span class="text-xs text-muted">—</span>
        <?php endif; ?>
    </td>
    <td class="py-3 px-2 sm:px-4">
        <div class="flex items-center justify-end gap-1">
            <button @click="openEditor('user', <?= $uid ?>, <?= $jsName ?>, <?= $jsOvJson ?>)"
                    class="p-1.5 rounded-lg text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="<?= $hasOv ? 'Editar override' : 'Crear override' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            </button>
            <?php if ($hasOv): ?>
            <form method="post" class="inline" onsubmit="return confirm('¿Quitar override?')">
                <input type="hidden" name="action" value="remove_override">
                <input type="hidden" name="user_id" value="<?= $uid ?>">
                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Quitar override">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>

<!-- ──────── Paginador de Tabla ──────── -->
<div class="flex items-center justify-between mb-6" x-show="totalFilteredPages > 1">
    <p class="text-xs text-muted">Página <span x-text="currentPage"></span> de <span x-text="totalFilteredPages"></span> — <span x-text="filteredIds.length"></span> empleado(s)</p>
    <div class="flex items-center gap-1">
        <button @click="currentPage = Math.max(1, currentPage - 1)" :disabled="currentPage <= 1"
                class="px-2.5 py-1.5 rounded-lg border text-xs font-medium transition-colors"
                :class="currentPage <= 1 ? 'border-gray-100 text-gray-300 cursor-not-allowed' : 'border-gray-200 text-muted hover:text-dark hover:bg-gray-50'">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <template x-for="p in totalFilteredPages" :key="p">
            <button @click="currentPage = p"
                    class="w-8 h-8 rounded-lg text-xs font-medium transition-colors"
                    :class="currentPage === p ? 'bg-corp-800 text-white' : 'text-muted hover:bg-gray-100'" x-text="p"></button>
        </template>
        <button @click="currentPage = Math.min(totalFilteredPages, currentPage + 1)" :disabled="currentPage >= totalFilteredPages"
                class="px-2.5 py-1.5 rounded-lg border text-xs font-medium transition-colors"
                :class="currentPage >= totalFilteredPages ? 'border-gray-100 text-gray-300 cursor-not-allowed' : 'border-gray-200 text-muted hover:text-dark hover:bg-gray-50'">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </button>
    </div>
</div>

<!-- ──────── Info card ──────── -->
<div class="bg-corp-50 rounded-xl border border-corp-100 p-5 mb-8">
    <h3 class="text-sm font-bold text-corp-800 mb-2 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Cómo funciona la jerarquía
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-corp-800">
        <div><p class="font-semibold mb-1">1. Global</p><p class="text-muted">Configuración base aplicada automáticamente a todos los empleados.</p></div>
        <div><p class="font-semibold mb-1">2. Override (deep merge)</p><p class="text-muted">Solo los campos que cambias se mezclan sobre la global. Los demás se heredan.</p></div>
        <div><p class="font-semibold mb-1">3. Batch</p><p class="text-muted">Selecciona varios empleados con los checkboxes y aplica un override en lote.</p></div>
    </div>
</div>


<!-- ──────── Horarios Laborales ──────── -->
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-4 sm:mb-8" x-data="{showSchedUser: false, schedUserId: ''}">
    <h3 class="text-sm font-bold text-dark mb-4 flex items-center gap-2">
        <svg class="w-5 h-5 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Horarios Laborales
    </h3>

    <!-- Global schedule -->
    <div class="bg-gray-50 rounded-xl p-4 mb-4">
        <p class="text-xs font-semibold text-muted uppercase tracking-wider mb-3">Horario Global (aplica a todos por defecto)</p>
        <?php
            $gDays = array_flip(explode(',', $globalSchedule['applicable_days'] ?? '1,2,3,4,5'));
            $dayLabels = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
        ?>
        <form method="post" class="space-y-3">
            <input type="hidden" name="action" value="save_global_schedule">
            <!-- Day checkboxes -->
            <div>
                <label class="text-[10px] text-muted block mb-1">Días que aplica</label>
                <div class="flex items-center gap-1.5">
                    <?php foreach ($dayLabels as $di => $dl): ?>
                    <label class="flex items-center gap-1 cursor-pointer">
                        <input type="checkbox" name="applicable_days[]" value="<?= $di ?>"
                               <?= isset($gDays[$di]) ? 'checked' : '' ?>
                               class="w-3.5 h-3.5 rounded border-gray-300 text-corp-600 focus:ring-corp-200">
                        <span class="text-xs <?= isset($gDays[$di]) ? 'text-dark font-medium' : 'text-muted' ?>"><?= $dl ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Time inputs -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 items-end">
                <div>
                    <label class="text-[10px] text-muted">Entrada</label>
                    <input type="time" name="work_start" value="<?= substr($globalSchedule['work_start_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <label class="text-[10px] text-muted">Salida</label>
                    <input type="time" name="work_end" value="<?= substr($globalSchedule['work_end_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <label class="text-[10px] text-muted">Almuerzo inicio</label>
                    <input type="time" name="lunch_start" value="<?= substr($globalSchedule['lunch_start_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <label class="text-[10px] text-muted">Almuerzo fin</label>
                    <input type="time" name="lunch_end" value="<?= substr($globalSchedule['lunch_end_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <button type="submit" class="w-full px-4 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">Guardar</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Per-user schedules table -->
    <?php if (!empty($userSchedules)):
        $dayMap = ['D','L','M','M','J','V','S'];
    ?>
    <div class="mb-4">
        <p class="text-xs font-semibold text-muted uppercase tracking-wider mb-2">Horarios personalizados (<?= count($userSchedules) ?>)</p>
        <div class="overflow-x-auto">
        <table class="w-full text-xs">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="text-left py-2 px-3 font-semibold text-muted">Empleado</th>
                    <th class="text-center py-2 px-3 font-semibold text-muted">Días</th>
                    <th class="text-center py-2 px-3 font-semibold text-muted">Entrada</th>
                    <th class="text-center py-2 px-3 font-semibold text-muted">Salida</th>
                    <th class="text-center py-2 px-3 font-semibold text-muted">Almuerzo</th>
                    <th class="text-right py-2 px-3 font-semibold text-muted">Acción</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
            <?php foreach ($userSchedules as $s):
                $sDays = array_flip(explode(',', $s['applicable_days'] ?? '1,2,3,4,5'));
            ?>
                <tr class="hover:bg-gray-50/50">
                    <td class="py-2 px-3 font-medium text-dark"><?= htmlspecialchars($s['display_name']) ?></td>
                    <td class="py-2 px-3 text-center">
                        <div class="inline-flex gap-0.5">
                        <?php foreach ($dayMap as $di => $dl): ?>
                            <span class="w-4 h-4 rounded text-[9px] font-bold flex items-center justify-center <?= isset($sDays[$di]) ? 'bg-corp-800 text-white' : 'bg-gray-100 text-gray-400' ?>"><?= $dl ?></span>
                        <?php endforeach; ?>
                        </div>
                    </td>
                    <td class="py-2 px-3 text-center"><?= substr($s['work_start_time'],0,5) ?></td>
                    <td class="py-2 px-3 text-center"><?= substr($s['work_end_time'],0,5) ?></td>
                    <td class="py-2 px-3 text-center"><?= substr($s['lunch_start_time'],0,5) ?> – <?= substr($s['lunch_end_time'],0,5) ?></td>
                    <td class="py-2 px-3 text-right">
                        <form method="post" class="inline" onsubmit="return confirm('¿Eliminar horario personalizado?')">
                            <input type="hidden" name="action" value="remove_user_schedule">
                            <input type="hidden" name="sched_user_id" value="<?= (int)$s['user_id'] ?>">
                            <button type="submit" class="p-1 rounded text-muted hover:text-red-600 hover:bg-red-50 transition-colors" title="Eliminar">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Add per-user schedule -->
    <div>
        <button @click="showSchedUser = !showSchedUser" class="inline-flex items-center gap-1.5 text-xs font-medium text-corp-800 hover:text-corp-900">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            Agregar horario personalizado
        </button>
        <form method="post" x-show="showSchedUser" x-transition class="mt-3 bg-blue-50/50 rounded-xl p-4 border border-blue-100 space-y-3" style="display:none">
            <input type="hidden" name="action" value="save_user_schedule">
            <div>
                <label class="text-[10px] text-muted">Empleado</label>
                <select name="sched_user_id" required class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                    <option value="">Seleccionar…</option>
                    <?php foreach ($allUsers as $u): ?>
                        <?php if (!isset($userSchedules[(int)$u['id']])): ?>
                        <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['display_name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="text-[10px] text-muted block mb-1">Días que aplica</label>
                <div class="flex gap-1.5">
                    <?php
                    $uDayLabels = ['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];
                    $uDefDays   = [1,2,3,4,5];
                    foreach ($uDayLabels as $di => $dl): ?>
                    <label class="flex flex-col items-center gap-0.5 cursor-pointer">
                        <input type="checkbox" name="applicable_days[]" value="<?= $di ?>" <?= in_array($di, $uDefDays) ? 'checked' : '' ?> class="w-3.5 h-3.5 rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                        <span class="text-[10px] text-muted"><?= $dl ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 items-end">
                <div>
                    <label class="text-[10px] text-muted">Entrada</label>
                    <input type="time" name="work_start" value="<?= substr($globalSchedule['work_start_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <label class="text-[10px] text-muted">Salida</label>
                    <input type="time" name="work_end" value="<?= substr($globalSchedule['work_end_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <label class="text-[10px] text-muted">Almuerzo inicio</label>
                    <input type="time" name="lunch_start" value="<?= substr($globalSchedule['lunch_start_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <label class="text-[10px] text-muted">Almuerzo fin</label>
                    <input type="time" name="lunch_end" value="<?= substr($globalSchedule['lunch_end_time'],0,5) ?>" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                </div>
                <div>
                    <button type="submit" class="w-full px-4 py-1.5 bg-blue-600 text-white rounded-lg text-xs font-medium hover:bg-blue-700 transition-colors">Guardar</button>
                </div>
            </div>
        </form>
    </div>
</div>


<!-- ──────── Aplicaciones / Ventanas de Descanso ──────── -->
<?php
$leisureData    = getLeisureApps();
$leisureAppsRaw = implode("\n", $leisureData['apps']);
$leisureWinsRaw = implode("\n", $leisureData['windows']);
?>
<div class="bg-white rounded-xl border border-gray-100 p-4 sm:p-6 mb-4 sm:mb-8">
    <h3 class="text-sm font-bold text-dark mb-1 flex items-center gap-2">
        <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Descanso / Despeje
    </h3>
    <p class="text-xs text-muted mb-4">Procesos y ventanas que se consideran <b>no productivas</b>. Su tiempo se descuenta del cálculo de productividad y Focus Score.</p>

    <form method="post" class="space-y-4">
        <input type="hidden" name="action" value="save_leisure_apps">

        <!-- Por Aplicación (process_name) -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
            <div>
                <label class="text-xs font-semibold text-dark block mb-1 flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-corp-800" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Por Aplicación
                </label>
                <p class="text-[10px] text-muted mb-1.5">Nombre del proceso tal como aparece en la columna <b>Aplicación</b> (ej: <code>chrome</code>, <code>spotify</code>). Coincidencia exacta.</p>
                <textarea name="leisure_apps_raw" rows="5" placeholder="chrome&#10;spotify&#10;WhatsApp" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"><?= htmlspecialchars($leisureAppsRaw) ?></textarea>
                <?php if (!empty($leisureData['apps'])): ?>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <?php foreach ($leisureData['apps'] as $app): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-amber-50 text-amber-700 text-[10px] font-medium rounded-full border border-amber-200">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                        <?= htmlspecialchars($app) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Por Ventana (window_title LIKE) -->
            <div>
                <label class="text-xs font-semibold text-dark block mb-1 flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6z"/></svg>
                    Por Ventana
                </label>
                <p class="text-[10px] text-muted mb-1.5">Texto que aparece en el <b>título de la ventana</b> (ej: <code>YouTube</code>, <code>Facebook</code>). Coincidencia parcial (contiene).</p>
                <textarea name="leisure_windows_raw" rows="5" placeholder="YouTube&#10;Facebook&#10;Instagram&#10;TikTok" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"><?= htmlspecialchars($leisureWinsRaw) ?></textarea>
                <?php if (!empty($leisureData['windows'])): ?>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <?php foreach ($leisureData['windows'] as $win): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 bg-purple-50 text-purple-700 text-[10px] font-medium rounded-full border border-purple-200">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5z"/></svg>
                        <?= htmlspecialchars($win) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div>
            <button type="submit" class="px-4 py-1.5 bg-corp-800 text-white rounded-lg text-xs font-medium hover:bg-corp-900 transition-colors">Guardar Configuración de Descanso</button>
        </div>
    </form>
</div>


<!-- ═══════════════════════════════════════════════════════════
     MODAL — Editor (Global ó User Override)
     Se usa el mismo modal con un solo form, cambiando action+fields
     ═══════════════════════════════════════════════════════════ -->
<div x-show="showEditor" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showEditor=false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display:none">
    <div class="fixed inset-0 bg-black/40" @click="showEditor=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col" @click.stop>
        <!-- Header -->
        <div class="shrink-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl flex items-center justify-between">
            <div>
                <h2 class="text-lg font-bold text-dark">
                    <span x-show="editorMode==='global'">Editar Política Global</span>
                    <span x-show="editorMode==='user'">Override: <span x-text="editUserName" class="text-corp-800"></span></span>
                </h2>
                <p x-show="editorMode==='user'" class="text-xs text-muted">Solo cambia los valores que necesites. Los campos sin modificar se heredan de la Global.</p>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex items-center bg-gray-100 rounded-lg p-0.5">
                    <button type="button" @click="editorTab='visual'" class="px-3 py-1 rounded-md text-xs font-medium transition-colors" :class="editorTab==='visual'?'bg-white shadow-sm text-dark':'text-muted hover:text-dark'">Visual</button>
                    <button type="button" @click="editorTab='json'; syncToJsonRaw()" class="px-3 py-1 rounded-md text-xs font-medium transition-colors" :class="editorTab==='json'?'bg-white shadow-sm text-dark':'text-muted hover:text-dark'">JSON</button>
                </div>
                <button @click="showEditor=false" class="p-1 rounded-lg hover:bg-gray-100 text-muted hover:text-dark transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Body scrollable -->
        <form method="post" class="flex-1 overflow-y-auto" id="editorForm">
            <input type="hidden" name="action"    :value="editorMode==='global'?'update_global':'save_user_override'">
            <input type="hidden" name="user_id"    :value="editUserId" x-show="editorMode==='user'">

            <div class="p-6">
                <!-- ═══ Visual Tab ═══ -->
                <div x-show="editorTab==='visual'" class="space-y-5">

                    <!-- API Base URL (solo global) -->
                    <div x-show="editorMode==='global'" class="bg-gray-50 rounded-xl p-4">
                        <label class="block text-xs font-semibold text-dark mb-1">API Base URL</label>
                        <input type="text" x-model="editData.apiBaseUrl" class="w-full px-3 py-1.5 border border-gray-200 rounded-lg text-xs">
                    </div>

                    <!-- ── Timers ── -->
                    <fieldset class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <legend class="text-xs font-bold text-dark uppercase tracking-wider flex items-center gap-2"><svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg> Timers</legend>
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="text-[10px] text-muted">Handshake (min)</label>
                                <input type="number" x-model.number="editData.timers.handshakeIntervalMinutes" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Flush (seg)</label>
                                <input type="number" x-model.number="editData.timers.activityFlushIntervalSeconds" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Retry offline (seg)</label>
                                <input type="number" x-model.number="editData.timers.offlineQueueRetrySeconds" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                        </div>
                    </fieldset>

                    <!-- ── Logging ── -->
                    <fieldset class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <legend class="text-xs font-bold text-dark uppercase tracking-wider flex items-center gap-2"><svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg> Logging</legend>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] text-muted">Global Level</label>
                                <select x-model="editData.logging.globalLevel" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                                    <option value="Info">Info</option><option value="Warn">Warn</option><option value="Error">Error</option><option value="None">None</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Override Level</label>
                                <select x-model="editData.logging.clientOverrideLevel" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                                    <option value="Info">Info</option><option value="Warn">Warn</option><option value="Error">Error</option><option value="None">None</option>
                                </select>
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.logging.enableFileLogging" class="rounded border-gray-300 text-corp-600">File Logging</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.logging.enableDiscordLogging" class="rounded border-gray-300 text-corp-600">Discord Logging</label>
                        </div>
                        <div>
                            <label class="text-[10px] text-muted">Discord Webhook URL</label>
                            <input type="text" x-model="editData.logging.discordWebhookUrl" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                        </div>
                    </fieldset>

                    <!-- ── Modules ── -->
                    <fieldset class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <legend class="text-xs font-bold text-dark uppercase tracking-wider flex items-center gap-2"><svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 4a2 2 0 114 0v1a1 1 0 001 1h3a1 1 0 011 1v3a1 1 0 01-1 1h-1a2 2 0 100 4h1a1 1 0 011 1v3a1 1 0 01-1 1h-3a1 1 0 01-1-1v-1a2 2 0 10-4 0v1a1 1 0 01-1 1H7a1 1 0 01-1-1v-3a1 1 0 00-1-1H4a2 2 0 110-4h1a1 1 0 001-1V7a1 1 0 011-1h3a1 1 0 001-1V4z"/></svg> Modules</legend>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableActivityTracking" class="rounded border-gray-300 text-corp-600">Activity Tracking</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableWindowTracking" class="rounded border-gray-300 text-corp-600">Window Tracking</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableProcessTracking" class="rounded border-gray-300 text-corp-600">Process Tracking</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableCallTracking" class="rounded border-gray-300 text-corp-600">Call Tracking</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableBlocking" class="rounded border-gray-300 text-corp-600">Blocking</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableUpdateManager" class="rounded border-gray-300 text-corp-600">Update Manager</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.enableDebugWindow" class="rounded border-gray-300 text-corp-600">Debug Window</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.modules.countCallsAsActive" class="rounded border-gray-300 text-corp-600">Calls as Active</label>
                        </div>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-2">
                            <div>
                                <label class="text-[10px] text-muted">Activity interval (seg)</label>
                                <input type="number" x-model.number="editData.modules.activityIntervalSeconds" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Inactivity threshold (seg)</label>
                                <input type="number" x-model.number="editData.modules.activityInactivityThresholdSeconds" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Window tracking (seg)</label>
                                <input type="number" x-model.number="editData.modules.windowTrackingIntervalSeconds" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Call idle max (seg)</label>
                                <input type="number" x-model.number="editData.modules.callActiveMaxIdleSeconds" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-2">
                            <div>
                                <label class="text-[10px] text-muted">Call process keywords (comma separated)</label>
                                <input type="text" x-model="callProcessKeywordsStr" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5" placeholder="zoom, teams, skype">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Call title keywords (comma separated)</label>
                                <input type="text" x-model="callTitleKeywordsStr" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5" placeholder="meeting, call, reunión">
                            </div>
                        </div>
                    </fieldset>

                    <!-- ── Startup ── -->
                    <fieldset class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <legend class="text-xs font-bold text-dark uppercase tracking-wider flex items-center gap-2"><svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Startup</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.startup.enableAutoStartup" class="rounded border-gray-300 text-corp-600">Auto Startup</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.startup.startMinimized" class="rounded border-gray-300 text-corp-600">Start Minimized</label>
                        </div>
                    </fieldset>

                    <!-- ── Updates ── -->
                    <fieldset class="bg-gray-50 rounded-xl p-4 space-y-3">
                        <legend class="text-xs font-bold text-dark uppercase tracking-wider flex items-center gap-2"><svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg> Updates</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.updates.enableAutoUpdate" class="rounded border-gray-300 text-corp-600">Auto Update</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.updates.autoDownload" class="rounded border-gray-300 text-corp-600">Auto Download</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.updates.allowBetaVersions" class="rounded border-gray-300 text-corp-600">Allow Beta</label>
                        </div>
                        <div class="w-48">
                            <label class="text-[10px] text-muted">Check interval (min)</label>
                            <input type="number" x-model.number="editData.updates.checkIntervalMinutes" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5">
                        </div>
                    </fieldset>

                    <!-- ── Blocking ── -->
                    <fieldset class="bg-red-50/50 rounded-xl p-4 space-y-3 border border-red-100">
                        <legend class="text-xs font-bold text-red-700 uppercase tracking-wider flex items-center gap-2"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg> Blocking</legend>
                        <div class="flex flex-wrap gap-4">
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.blocking.enableDeviceLock" class="rounded border-red-300 text-red-600">Device Lock</label>
                            <label class="flex items-center gap-2 text-xs"><input type="checkbox" x-model="editData.blocking.allowUnlockWithPin" class="rounded border-red-300 text-red-600">Unlock with PIN</label>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="text-[10px] text-muted">Unlock PIN</label>
                                <input type="text" x-model="editData.blocking.unlockPin" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5" placeholder="null">
                            </div>
                            <div>
                                <label class="text-[10px] text-muted">Lock Message</label>
                                <textarea x-model="editData.blocking.lockMessage" rows="2" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs mt-0.5 resize-y"></textarea>
                            </div>
                        </div>
                    </fieldset>

                </div><!-- end visual -->

                <!-- ═══ JSON Tab ═══ -->
                <div x-show="editorTab==='json'">
                    <textarea x-model="editJsonRaw" rows="28" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"></textarea>
                    <p x-show="editorMode==='user'" class="text-[10px] text-muted mt-1">Tip: Para un override, deja solo los campos que quieras cambiar respecto a la global.</p>
                </div>
            </div>

            <!-- Hidden final JSON -->
            <input type="hidden" name="policy_json" :value="getFinalJson()">

            <!-- Footer buttons -->
            <div class="shrink-0 bg-white border-t border-gray-100 px-6 py-4 rounded-b-2xl flex justify-end gap-3">
                <button type="button" @click="showEditor=false" class="px-4 py-2 text-sm text-muted hover:text-dark">Cancelar</button>
                <button type="submit" class="px-5 py-2 bg-corp-800 text-white rounded-xl text-sm font-medium hover:bg-corp-900 transition-colors shadow-sm">
                    <span x-show="editorMode==='global'">Guardar Global</span>
                    <span x-show="editorMode==='user'">Guardar Override</span>
                </button>
            </div>
        </form>
    </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     MODAL — Batch
     ═══════════════════════════════════════════════════════════ -->
<div x-show="showBatch" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showBatch=false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" style="display:none">
    <div class="fixed inset-0 bg-black/40" @click="showBatch=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto" @click.stop>
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl flex items-center justify-between z-10">
            <div>
                <h2 class="text-lg font-bold text-dark">Batch — <span x-text="selectedUsers.length" class="text-purple-600"></span> empleados</h2>
                <p class="text-xs text-muted">Aplica o quita un override para todos los seleccionados.</p>
            </div>
            <button @click="showBatch=false" class="p-1 rounded-lg hover:bg-gray-100 text-muted hover:text-dark transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6 space-y-6">
            <!-- Tabs: Aplicar / Quitar -->
            <div class="flex items-center gap-2">
                <button type="button" @click="batchAction='apply'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors" :class="batchAction==='apply'?'bg-purple-600 text-white':'bg-gray-100 text-muted'">Aplicar Override</button>
                <button type="button" @click="batchAction='remove'" class="px-4 py-2 rounded-lg text-sm font-medium transition-colors" :class="batchAction==='remove'?'bg-red-600 text-white':'bg-gray-100 text-muted'">Quitar Overrides</button>
            </div>

            <!-- Apply -->
            <form method="post" x-show="batchAction==='apply'">
                <input type="hidden" name="action" value="batch_apply">
                <template x-for="uid in selectedUsers" :key="uid"><input type="hidden" name="batch_users[]" :value="uid"></template>
                <div class="mb-4">
                    <div class="flex items-center justify-between mb-2">
                        <label class="text-xs font-semibold text-dark">Override JSON</label>
                        <button type="button" @click="batchJson=JSON.stringify(defaultOverrideTemplate(),null,2)" class="text-[10px] text-corp-800 hover:underline">Cargar plantilla</button>
                    </div>
                    <textarea name="policy_json" x-model="batchJson" rows="15" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 outline-none resize-y"></textarea>
                </div>
                <button type="submit" class="w-full px-5 py-2.5 bg-purple-600 text-white rounded-xl text-sm font-medium hover:bg-purple-700 transition-colors" onclick="return confirm('¿Aplicar override a los seleccionados?')">
                    Aplicar a <span x-text="selectedUsers.length"></span> empleado(s)
                </button>
            </form>

            <!-- Remove -->
            <form method="post" x-show="batchAction==='remove'">
                <input type="hidden" name="action" value="batch_remove">
                <template x-for="uid in selectedUsers" :key="uid"><input type="hidden" name="batch_users[]" :value="uid"></template>
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">
                    <p class="text-sm text-red-700 font-medium">Se eliminará el override de <span x-text="selectedUsers.length" class="font-bold"></span> empleado(s).</p>
                    <p class="text-xs text-red-600 mt-1">Volverán a recibir solo la política global.</p>
                </div>
                <button type="submit" class="w-full px-5 py-2.5 bg-red-600 text-white rounded-xl text-sm font-medium hover:bg-red-700 transition-colors" onclick="return confirm('¿Quitar override a todos los seleccionados?')">
                    Quitar overrides
                </button>
            </form>
        </div>
    </div>
</div>

</div><!-- end Alpine root x-data -->

<!-- ═══════════════════════════════════════════════════════════
     Alpine Component
     ═══════════════════════════════════════════════════════════ -->
<script>
function policiesPage() {
    return {
        /* ── State ── */
        filterMode: 'all',
        search: '',
        selectedUsers: [],
        showEditor: false,
        showBatch: false,
        editorMode: 'global',   // 'global' | 'user'
        editorTab: 'visual',
        editUserId: null,
        editUserName: '',
        editData: {
            apiBaseUrl: '',
            timers: { handshakeIntervalMinutes: 5, offlineQueueRetrySeconds: 120, activityFlushIntervalSeconds: 60 },
            logging: { globalLevel: 'Error', discordWebhookUrl: '', enableFileLogging: true, clientOverrideLevel: 'Error', enableDiscordLogging: false },
            modules: { enableBlocking: true, callTitleKeywords: [], enableDebugWindow: false, countCallsAsActive: true, enableCallTracking: true, callProcessKeywords: [], enableUpdateManager: true, enableWindowTracking: true, enableProcessTracking: true, enableActivityTracking: true, activityIntervalSeconds: 30, callActiveMaxIdleSeconds: 1800, windowTrackingIntervalSeconds: 30, activityInactivityThresholdSeconds: 900 },
            startup: { startMinimized: false, enableAutoStartup: true },
            updates: { autoDownload: true, enableAutoUpdate: true, allowBetaVersions: false, checkIntervalMinutes: 360 },
            blocking: { unlockPin: null, lockMessage: '', enableDeviceLock: false, allowUnlockWithPin: false }
        },
        editJsonRaw: '',
        batchAction: 'apply',
        batchJson: '',
        callProcessKeywordsStr: '',
        callTitleKeywordsStr: '',
        currentPage: 1,
        perPage: 15,

        /* ── Data from PHP ── */
        globalData: <?= json_encode($globalJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>,

        /* ── Default full policy ── */
        defaultPolicy() {
            return {
                apiBaseUrl: 'https://projects.k.azclegal.com/public/index.php/api/',
                timers: { handshakeIntervalMinutes: 5, offlineQueueRetrySeconds: 120, activityFlushIntervalSeconds: 60 },
                logging: { globalLevel: 'Error', discordWebhookUrl: '', enableFileLogging: true, clientOverrideLevel: 'Error', enableDiscordLogging: false },
                modules: { enableBlocking: true, callTitleKeywords: ['meeting','call','reunión','llamada'], enableDebugWindow: false, countCallsAsActive: true, enableCallTracking: true, callProcessKeywords: ['zoom','teams','skype','meet','webex'], enableUpdateManager: true, enableWindowTracking: true, enableProcessTracking: true, enableActivityTracking: true, activityIntervalSeconds: 30, callActiveMaxIdleSeconds: 1800, windowTrackingIntervalSeconds: 30, activityInactivityThresholdSeconds: 900 },
                startup: { startMinimized: false, enableAutoStartup: true },
                updates: { autoDownload: true, enableAutoUpdate: true, allowBetaVersions: false, checkIntervalMinutes: 360 },
                blocking: { unlockPin: null, lockMessage: 'Este equipo ha sido bloqueado por Seguridad.\nPor Favor Contacta a tu jefe inmediato o Director de IT.', enableDeviceLock: false, allowUnlockWithPin: false }
            };
        },
        defaultOverrideTemplate() {
            return { blocking: { enableDeviceLock: false } };
        },

        /* ── Deep merge ── */
        deepMerge(target, source) {
            const out = Object.assign({}, target);
            for (const k of Object.keys(source)) {
                if (source[k] && typeof source[k] === 'object' && !Array.isArray(source[k])
                    && target[k] && typeof target[k] === 'object' && !Array.isArray(target[k])) {
                    out[k] = this.deepMerge(target[k], source[k]);
                } else {
                    out[k] = source[k];
                }
            }
            return out;
        },

        /* ── Filtering ── */
        allUserMeta: <?= json_encode(array_map(fn($u) => [
            'id'   => (int)$u['id'],
            'hasOv'=> isset($userOverrides[(int)$u['id']]),
            'name' => $u['display_name'] ?? '',
            'email'=> $u['email'] ?? '',
            'firm' => $u['firm_name'] ?? '',
            'sociedad' => $u['sociedad_name'] ?? ''
        ], $allUsers), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,

        get filteredIds() {
            return this.allUserMeta
                .filter(u => this.matchRow(u.id, u.hasOv, u.name, u.email, u.firm, u.sociedad))
                .map(u => u.id);
        },
        get totalFilteredPages() {
            return Math.max(1, Math.ceil(this.filteredIds.length / this.perPage));
        },
        get paginatedIds() {
            const start = (this.currentPage - 1) * this.perPage;
            return this.filteredIds.slice(start, start + this.perPage);
        },
        showRow(uid, hasOv, name, email, firm, sociedad) {
            if (!this.matchRow(uid, hasOv, name, email, firm, sociedad)) return false;
            return this.paginatedIds.includes(uid);
        },
        resetPage() { this.currentPage = 1; },

        matchRow(uid, hasOv, name, email, firm, sociedad) {
            if (this.filterMode === 'global' && hasOv) return false;
            if (this.filterMode === 'override' && !hasOv) return false;
            if (this.search.trim()) {
                const q = this.search.toLowerCase();
                if (!(name + ' ' + email + ' ' + firm + ' ' + sociedad).toLowerCase().includes(q)) return false;
            }
            return true;
        },

        /* ── Selection ── */
        toggleUser(id) {
            const i = this.selectedUsers.indexOf(id);
            i >= 0 ? this.selectedUsers.splice(i, 1) : this.selectedUsers.push(id);
        },
        toggleSelectAll(ev) {
            this.selectedUsers = ev.target.checked
                ? <?= json_encode(array_map(fn($u) => (int)$u['id'], $allUsers)) ?>
                : [];
        },

        /* ── Open editor (unified) ── */
        openEditor(mode, userId, userName, overrideJson) {
            const base = this.defaultPolicy();
            const glob = JSON.parse(JSON.stringify(this.globalData || {}));
            const merged = this.deepMerge(base, glob);

            if (mode === 'global') {
                this.editorMode = 'global';
                this.editUserId = null;
                this.editUserName = '';
                this.editData = JSON.parse(JSON.stringify(merged));
                this.editJsonRaw = JSON.stringify(merged, null, 2);
            } else {
                this.editorMode = 'user';
                this.editUserId = userId;
                this.editUserName = userName;
                const ov = JSON.parse(JSON.stringify(overrideJson || {}));
                // Visual shows merged values (so user sees current effective state)
                this.editData = Object.keys(ov).length > 0
                    ? this.deepMerge(merged, ov)
                    : JSON.parse(JSON.stringify(merged));
                // JSON raw shows only the override delta
                this.editJsonRaw = Object.keys(ov).length > 0
                    ? JSON.stringify(ov, null, 2)
                    : JSON.stringify({}, null, 2);
            }

            this.callProcessKeywordsStr = (this.editData.modules?.callProcessKeywords || []).join(', ');
            this.callTitleKeywordsStr   = (this.editData.modules?.callTitleKeywords || []).join(', ');
            this.editorTab = 'visual';
            this.showEditor = true;
        },

        openBatchModal() {
            this.batchJson = JSON.stringify(this.defaultOverrideTemplate(), null, 2);
            this.batchAction = 'apply';
            this.showBatch = true;
        },

        /* ── JSON sync ── */
        syncToJsonRaw() {
            this.editJsonRaw = JSON.stringify(this.buildEditJson(), null, 2);
        },
        buildEditJson() {
            const d = JSON.parse(JSON.stringify(this.editData));
            if (d.modules) {
                d.modules.callProcessKeywords = this.callProcessKeywordsStr.split(',').map(s => s.trim()).filter(Boolean);
                d.modules.callTitleKeywords   = this.callTitleKeywordsStr.split(',').map(s => s.trim()).filter(Boolean);
            }
            if (d.blocking && (!d.blocking.unlockPin || d.blocking.unlockPin === 'null')) {
                d.blocking.unlockPin = null;
            }
            return d;
        },
        getFinalJson() {
            if (this.editorTab === 'json') return this.editJsonRaw;
            return JSON.stringify(this.buildEditJson());
        }
    };
}
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
