<?php
/**
 * Usuarios — lista de personal activo estilo LawyerDesk.
 */
require_once __DIR__ . '/admin_auth.php';

use Keeper\Db;

$pageTitle   = 'Usuarios';
$currentPage = 'users';

$scope  = scopeFilter();
$params = $scope['params'];

// PDO legacy para consultas contra tabla employee
$legacyPdo = Db::legacyPdo();

// ──────── AJAX: buscar empleado por email ────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'search_employee') {
    header('Content-Type: application/json');
    $email = trim($_GET['email'] ?? '');
    if (strlen($email) < 3) {
        echo json_encode(['found' => false, 'message' => 'Escribe al menos 3 caracteres.']);
        exit;
    }
    $stE = $legacyPdo->prepare("
        SELECT e.id, e.CC, e.first_Name, e.second_Name, e.first_LastName, e.second_LastName, e.mail
        FROM employee e
        WHERE e.mail LIKE :email AND e.exit_status = 0
        ORDER BY e.first_Name
        LIMIT 5
    ");
    $stE->execute([':email' => '%' . $email . '%']);
    $results = $stE->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    foreach ($results as $r) {
        $chk = $pdo->prepare("SELECT id FROM keeper_users WHERE legacy_employee_id = ? LIMIT 1");
        $chk->execute([$r['id']]);
        $alreadyRegistered = (bool)$chk->fetch();
        $name = trim(implode(' ', array_filter([$r['first_Name'], $r['second_Name'], $r['first_LastName'], $r['second_LastName']])));
        $out[] = [
            'id'         => (int)$r['id'],
            'cc'         => $r['CC'],
            'name'       => $name,
            'email'      => $r['mail'],
            'registered' => $alreadyRegistered,
        ];
    }
    echo json_encode(['found' => !empty($out), 'results' => $out]);
    exit;
}

// ──────── AJAX: obtener datos de un usuario ────────
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_user') {
    header('Content-Type: application/json');
    $uid = (int)($_GET['id'] ?? 0);
    if ($uid <= 0) { echo json_encode(['ok' => false]); exit; }
    $st = $pdo->prepare("SELECT id, cc, display_name, email, status, legacy_employee_id FROM keeper_users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) { echo json_encode(['ok' => false]); exit; }
    echo json_encode(['ok' => true, 'user' => $u]);
    exit;
}

// ──────── POST: Crear usuario ────────
$flashMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_user') {
        if (!canDo('users', 'can_create')) {
            $flashMsg = 'error|Sin permisos para crear usuarios.';
        } else {
        $empId   = (int)($_POST['employee_id'] ?? 0);
        $rawPass = trim($_POST['password'] ?? '');

        if ($rawPass === '') {
            $flashMsg = 'error|La contraseña es requerida.';
        } elseif ($empId > 0) {
            // Mode 1: link to legacy employee
            $empSt = $legacyPdo->prepare("
                SELECT e.id, e.CC, e.first_Name, e.second_Name, e.first_LastName, e.second_LastName, e.mail
                FROM employee e
                WHERE e.id = ?
                LIMIT 1
            ");
            $empSt->execute([$empId]);
            $emp = $empSt->fetch(PDO::FETCH_ASSOC);

            // Verificar que no esté ya registrado en keeper
            $chk = $pdo->prepare("SELECT id FROM keeper_users WHERE legacy_employee_id = ? LIMIT 1");
            $chk->execute([$empId]);
            $alreadyRegistered = (bool)$chk->fetch();

            if (!$emp) {
                $flashMsg = 'error|Empleado no encontrado.';
            } elseif ($alreadyRegistered) {
                $flashMsg = 'error|Empleado ya registrado en Keeper.';
            } else {
                $displayName = trim(implode(' ', array_filter([
                    $emp['first_Name'], $emp['second_Name'], $emp['first_LastName'], $emp['second_LastName']
                ])));
                $hash = password_hash($rawPass, PASSWORD_BCRYPT);
                $ins = $pdo->prepare("
                    INSERT INTO keeper_users (legacy_employee_id, cc, display_name, email, password_hash, status, created_at)
                    VALUES (:lid, :cc, :dn, :em, :ph, 'active', NOW())
                ");
                $ins->execute([
                    'lid' => $emp['id'],
                    'cc'  => (string)$emp['CC'],
                    'dn'  => $displayName,
                    'em'  => $emp['mail'],
                    'ph'  => $hash,
                ]);
                $flashMsg = 'ok|Usuario "' . htmlspecialchars($displayName) . '" creado (vinculado a legacy).';
            }
        } else {
            // Mode 2: keeper-only (no legacy link)
            $manualName  = trim($_POST['manual_name'] ?? '');
            $manualCc    = trim($_POST['manual_cc'] ?? '');
            $manualEmail = trim($_POST['manual_email'] ?? '');

            if ($manualName === '' || $manualEmail === '') {
                $flashMsg = 'error|Nombre y email son requeridos para crear usuario sin legacy.';
            } else {
                $chk = $pdo->prepare("SELECT id FROM keeper_users WHERE email = ? LIMIT 1");
                $chk->execute([$manualEmail]);
                if ($chk->fetch()) {
                    $flashMsg = 'error|Ya existe un usuario con ese email.';
                } else {
                    $hash = password_hash($rawPass, PASSWORD_BCRYPT);
                    $ins = $pdo->prepare("
                        INSERT INTO keeper_users (legacy_employee_id, cc, display_name, email, password_hash, status, created_at)
                        VALUES (NULL, :cc, :dn, :em, :ph, 'active', NOW())
                    ");
                    $ins->execute([
                        'cc'  => $manualCc,
                        'dn'  => $manualName,
                        'em'  => $manualEmail,
                        'ph'  => $hash,
                    ]);
                    $flashMsg = 'ok|Usuario "' . htmlspecialchars($manualName) . '" creado (solo Keeper).';
                }
            }
        }
        } // end can_create check
    }

    if ($action === 'toggle_admin') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0 && canDo('users', 'can_toggle_admin')) {
            // Check if admin account exists
            $chk = $pdo->prepare("SELECT id, is_active FROM keeper_admin_accounts WHERE keeper_user_id = ?");
            $chk->execute([$userId]);
            $adm = $chk->fetch(PDO::FETCH_ASSOC);
            if ($adm) {
                // Toggle active
                $pdo->prepare("UPDATE keeper_admin_accounts SET is_active = NOT is_active WHERE id = ?")->execute([$adm['id']]);
                $flashMsg = $adm['is_active'] ? 'ok|Acceso al panel revocado.' : 'ok|Acceso al panel restaurado.';
            } else {
                // Create as viewer
                $pdo->prepare("
                    INSERT INTO keeper_admin_accounts (keeper_user_id, panel_role, is_active, created_by)
                    VALUES (?, 'viewer', 1, ?)
                ")->execute([$userId, $adminUser['id']]);
                $flashMsg = 'ok|Usuario agregado como administrador (viewer).';
            }
        }
    }

    if ($action === 'edit_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0 && canDo('users', 'can_edit')) {
            $st = $pdo->prepare("SELECT id, display_name FROM keeper_users WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            $target = $st->fetch(PDO::FETCH_ASSOC);
            if ($target) {
                $newName   = trim($_POST['edit_name'] ?? '');
                $newEmail  = trim($_POST['edit_email'] ?? '');
                $newCc     = trim($_POST['edit_cc'] ?? '');
                $newStatus = in_array($_POST['edit_status'] ?? '', ['active','inactive','locked']) ? $_POST['edit_status'] : 'active';
                $newPass   = trim($_POST['edit_password'] ?? '');

                if ($newName === '') {
                    $flashMsg = 'error|El nombre es requerido.';
                } else {
                    $sets = ['display_name = :dn', 'email = :em', 'cc = :cc', 'status = :st'];
                    $params = [':dn' => $newName, ':em' => $newEmail ?: null, ':cc' => $newCc ?: null, ':st' => $newStatus, ':id' => $userId];

                    if ($newPass !== '') {
                        $sets[] = 'password_hash = :ph';
                        $params[':ph'] = password_hash($newPass, PASSWORD_BCRYPT);
                    }

                    $pdo->prepare("UPDATE keeper_users SET " . implode(', ', $sets) . " WHERE id = :id")->execute($params);
                    $flashMsg = 'ok|Usuario "' . htmlspecialchars($newName) . '" actualizado.';
                }
            } else {
                $flashMsg = 'error|Usuario no encontrado.';
            }
        } else {
            $flashMsg = 'error|No tienes permiso para editar usuarios.';
        }
    }

    if ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId > 0 && canDo('users', 'can_delete')) {
            // Soft delete: set status='inactive' in keeper_users only (don't touch legacy)
            $st = $pdo->prepare("SELECT id, display_name FROM keeper_users WHERE id = ? AND status = 'active' LIMIT 1");
            $st->execute([$userId]);
            $target = $st->fetch(PDO::FETCH_ASSOC);
            if ($target) {
                $pdo->prepare("UPDATE keeper_users SET status = 'inactive' WHERE id = ?")->execute([$userId]);
                // Also deactivate admin account if exists
                $pdo->prepare("UPDATE keeper_admin_accounts SET is_active = 0 WHERE keeper_user_id = ?")->execute([$userId]);
                $flashMsg = 'ok|Usuario "' . htmlspecialchars($target['display_name']) . '" eliminado (desactivado).';
            } else {
                $flashMsg = 'error|Usuario no encontrado o ya inactivo.';
            }
        } else {
            $flashMsg = 'error|No tienes permiso para eliminar usuarios.';
        }
    }
}

// Todos los usuarios con métricas de hoy
$sql = "
    SELECT
        u.id,
        u.cc,
        u.display_name,
        u.email,
        u.status AS user_status,
        -- Asignación
        ua.firm_id,
        ua.area_id,
        ua.cargo_id,
        f.nombre AS firm_name,
        ar.nombre AS area_name,
        c.nombre AS cargo_name,
        soc.nombre AS sociedad_name,
        -- Dispositivo más reciente
        (SELECT d2.device_name FROM keeper_devices d2
         WHERE d2.user_id = u.id AND d2.status = 'active'
         ORDER BY d2.last_seen_at DESC LIMIT 1) AS device_name,
        (SELECT d2.last_seen_at FROM keeper_devices d2
         WHERE d2.user_id = u.id AND d2.status = 'active'
         ORDER BY d2.last_seen_at DESC LIMIT 1) AS last_seen_at,
        -- Actividad de hoy
        COALESCE(today.active_sec, 0) AS today_active,
        COALESCE(today.idle_sec, 0) AS today_idle,
        COALESCE(today.work_sec, 0) AS today_work,
        COALESCE(today.work_idle_sec, 0) AS today_work_idle,
        today.first_event AS first_event_today
    FROM keeper_users u
    LEFT JOIN keeper_user_assignments ua ON ua.keeper_user_id = u.id
    LEFT JOIN keeper_sociedades soc ON soc.id = ua.sociedad_id
    LEFT JOIN keeper_firmas f ON f.id = ua.firm_id
    LEFT JOIN keeper_areas ar ON ar.id = ua.area_id
    LEFT JOIN keeper_cargos c ON c.id = ua.cargo_id
    LEFT JOIN (
        SELECT
            a.user_id,
            SUM(a.active_seconds) AS active_sec,
            SUM(a.idle_seconds) AS idle_sec,
            SUM(a.work_hours_active_seconds) AS work_sec,
            SUM(a.work_hours_idle_seconds) AS work_idle_sec,
            (SELECT MIN(we.start_at) FROM keeper_window_episode we
                WHERE we.user_id = a.user_id AND we.day_date = CURDATE()
                  AND TIME(we.start_at) >= '05:00:00') AS first_event
        FROM keeper_activity_day a
        WHERE a.day_date = CURDATE()
        GROUP BY a.user_id
    ) today ON today.user_id = u.id
    WHERE u.status = 'active'
    {$scope['sql']}
    ORDER BY u.display_name ASC
";
$st = $pdo->prepare($sql);
$st->execute($params);
$allUsersRaw = $st->fetchAll(PDO::FETCH_ASSOC);

// ── Leisure apps+windows deduction (per user, today) ──
$leisureMap = [];
$leisureData = getLeisureApps();
$lApps = $leisureData['apps'];
$lWins = $leisureData['windows'];
if (!empty($lApps) || !empty($lWins)) {
    $conditions = [];
    $lParams    = [];
    if (!empty($lApps)) {
        $phA = implode(',', array_fill(0, count($lApps), '?'));
        $conditions[] = "w.process_name IN ($phA)";
        $lParams = array_merge($lParams, array_values($lApps));
    }
    if (!empty($lWins)) {
        $likes = [];
        foreach ($lWins as $win) {
            $likes[] = "w.window_title LIKE ?";
            $lParams[] = '%' . $win . '%';
        }
        $conditions[] = '(' . implode(' OR ', $likes) . ')';
    }
    $orClause = implode(' OR ', $conditions);
    $stL = $pdo->prepare("
        SELECT w.user_id, COALESCE(SUM(w.duration_seconds), 0) AS leisure_sec
        FROM keeper_window_episode w
        WHERE w.day_date = CURDATE()
          AND ($orClause)
        GROUP BY w.user_id
    ");
    $stL->execute($lParams);
    foreach ($stL->fetchAll(PDO::FETCH_ASSOC) as $lr) {
        $leisureMap[(int)$lr['user_id']] = (int)$lr['leisure_sec'];
    }
}

// Calcular status y métricas en PHP
foreach ($allUsersRaw as &$user) {
    $seenAgo = $user['last_seen_at'] ? time() - strtotime($user['last_seen_at']) : 99999;
    if ($seenAgo < 120)       $user['status_label'] = 'Online';
    elseif ($seenAgo < 900)   $user['status_label'] = 'Away';
    elseif ($seenAgo < 86400) $user['status_label'] = 'Offline';
    else                      $user['status_label'] = 'Offline';

    // Productividad: solo horario laboral, descontando apps de descanso
    $workActive  = (int)$user['today_work'];
    $workIdle    = (int)$user['today_work_idle'];
    $leisureSec  = $leisureMap[(int)$user['id']] ?? 0;
    $productive  = max(0, $workActive - $leisureSec);
    $workTotal   = $workActive + $workIdle;
    $user['productivity'] = $workTotal > 0 ? round(($productive / $workTotal) * 100) : 0;
    $user['focus_score']  = $workTotal > 0 ? round(($productive / $workTotal) * 10, 1) : 0;
    $user['first_login']  = $user['first_event_today']
        ? date('g:i A', strtotime($user['first_event_today']))
        : '--:--';
}
unset($user);

// Ordenar: Online primero, luego Ausente, luego Offline, y dentro de cada grupo por nombre
usort($allUsersRaw, function($a, $b) {
    $statusOrder = ['Online' => 0, 'Away' => 1, 'Offline' => 2];
    $aOrder = $statusOrder[$a['status_label']] ?? 9;
    $bOrder = $statusOrder[$b['status_label']] ?? 9;
    if ($aOrder !== $bOrder) return $aOrder - $bOrder;
    return strcasecmp($a['display_name'] ?? '', $b['display_name'] ?? '');
});
$users = $allUsersRaw;

// ──── Búsqueda global (server-side, filtra ANTES de paginar) ────
$searchQ = trim($_GET['q'] ?? '');
if ($searchQ !== '') {
    $qLow = mb_strtolower($searchQ);
    $users = array_values(array_filter($users, function($u) use ($qLow) {
        $haystack = mb_strtolower(
            ($u['display_name'] ?? '') . ' ' .
            ($u['cargo_name'] ?? '') . ' ' .
            ($u['sociedad_name'] ?? '') . ' ' .
            ($u['firm_name'] ?? '') . ' ' .
            ($u['area_name'] ?? '') . ' ' .
            ($u['cc'] ?? '') . ' ' .
            ($u['email'] ?? '')
        );
        return str_contains($haystack, $qLow);
    }));
}

// Paginación
$perPage    = 15;
$totalUsers = count($users);
$totalPages = max(1, ceil($totalUsers / $perPage));
$page       = max(1, min((int)($_GET['page'] ?? 1), $totalPages));
$pagedUsers = array_slice($users, ($page - 1) * $perPage, $perPage);

// Helpers
function fmtHM(int $seconds): string {
    if ($seconds <= 0) return '0h 0m';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return "{$h}h {$m}m";
}

function userStatusBadge(string $status): string {
    return match ($status) {
        'Online' => '<span class="inline-flex items-center text-xs font-semibold text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">Online</span>',
        'Away'   => '<span class="inline-flex items-center text-xs font-semibold text-amber-600 bg-amber-50 px-2 py-0.5 rounded-full">Ausente</span>',
        default  => '<span class="inline-flex items-center text-xs font-semibold text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">Offline</span>',
    };
}

function userStatusDot(string $status): string {
    return match ($status) {
        'Online' => 'bg-emerald-500',
        'Away'   => 'bg-amber-400',
        default  => 'bg-gray-300',
    };
}

function focusColor(float $score): string {
    if ($score >= 8) return 'text-emerald-600';
    if ($score >= 6) return 'text-corp-800';
    if ($score >= 4) return 'text-amber-600';
    return 'text-accent-500';
}

// Counters
$onlineCount  = count(array_filter($users, fn($u) => $u['status_label'] === 'Online'));
$awayCount    = count(array_filter($users, fn($u) => $u['status_label'] === 'Away'));
$offlineCount = count(array_filter($users, fn($u) => $u['status_label'] === 'Offline'));

// Admin status lookup for each user (keyed by keeper_user_id)
$adminMap = [];
$admSt = $pdo->query("SELECT keeper_user_id, panel_role, is_active FROM keeper_admin_accounts");
foreach ($admSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
    $adminMap[(int)$a['keeper_user_id']] = $a;
}

$canEditUser    = canDo('users', 'can_edit');
$canToggleAdmin = canDo('users', 'can_toggle_admin');
$canDeleteUser  = canDo('users', 'can_delete');

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Flash message -->
<?php if ($flashMsg):
    [$fType, $fText] = explode('|', $flashMsg, 2);
    $fBg = $fType === 'ok' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800';
?>
<div class="<?= $fBg ?> border rounded-xl px-4 py-3 mb-6 text-sm flex items-center gap-2">
    <?php if ($fType === 'ok'): ?>
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php else: ?>
    <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php endif; ?>
    <?= $fText ?>
</div>
<?php endif; ?>

<div x-data="{ showCreateModal: false, showEditModal: false, editUser: {id:0, display_name:'', email:'', cc:'', status:'active'}, async loadUser(id) { try { const r = await fetch('users.php?ajax=get_user&id='+id); const d = await r.json(); if(d.ok){ this.editUser = d.user; this.showEditModal = true; } } catch(e){} } }">

<!-- Header -->
<div class="mb-4 sm:mb-6">
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2">
            <svg class="w-5 h-5 sm:w-6 sm:h-6 text-corp-800 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
            <h2 class="text-lg sm:text-xl font-bold text-dark">Tu Personal Activo</h2>
        </div>
        <?php if (canDo('users', 'can_create')): ?>
        <button @click="showCreateModal = true" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-corp-800 text-white rounded-lg text-xs sm:text-sm font-medium hover:bg-corp-900 transition-colors shadow-sm flex-shrink-0">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
            <span class="hidden sm:inline">Crear Usuario</span>
            <span class="sm:hidden">Crear</span>
        </button>
        <?php endif; ?>
    </div>
    <p class="text-xs text-muted mb-3 hidden sm:block">Haz clic en un miembro del equipo para ver actividad detallada y opciones de gestión</p>
    <div class="flex items-center gap-3 sm:gap-4">
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
            <span class="text-xs text-muted"><?= $onlineCount ?> Online</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 bg-amber-400 rounded-full"></span>
            <span class="text-xs text-muted"><?= $awayCount ?> Ausentes</span>
        </div>
        <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 bg-gray-300 rounded-full"></span>
            <span class="text-xs text-muted"><?= $offlineCount ?> Offline</span>
        </div>
    </div>
</div>

<!-- Search / Filter (server-side) -->
<form method="get" class="mb-4 sm:mb-6 flex items-center gap-2 sm:gap-3">
    <div class="relative flex-1 sm:max-w-md">
        <svg class="w-4 h-4 text-muted absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input
            type="text"
            name="q"
            value="<?= htmlspecialchars($searchQ) ?>"
            placeholder="Buscar por nombre, cargo, firma, CC o email..."
            class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none transition-all placeholder:text-muted"
        >
    </div>
    <button type="submit" class="px-3 sm:px-4 py-2 bg-corp-800 text-white rounded-lg text-sm font-medium hover:bg-corp-900 transition-colors flex-shrink-0">Buscar</button>
    <?php if ($searchQ !== ''): ?>
    <a href="users.php" class="px-2 sm:px-3 py-2 text-xs font-medium text-muted hover:text-dark hover:bg-gray-100 rounded-lg transition-colors flex-shrink-0">Limpiar</a>
    <?php endif; ?>
</form>

<!-- User Cards -->
<div class="space-y-3" id="users-list">
    <?php if (empty($pagedUsers)): ?>
        <div class="bg-white rounded-xl border border-gray-100 p-12 text-center">
            <p class="text-muted">No hay usuarios en tu alcance</p>
        </div>
    <?php else: ?>
        <?php foreach ($pagedUsers as $user): ?>
        <a href="user-dashboard.php?id=<?= (int)$user['id'] ?>"
           class="user-card block bg-white rounded-xl border border-gray-100 hover:border-corp-200 hover:shadow-sm transition-all"
           data-search="<?= htmlspecialchars(strtolower(($user['display_name'] ?? '') . ' ' . ($user['cargo_name'] ?? '') . ' ' . ($user['sociedad_name'] ?? '') . ' ' . ($user['firm_name'] ?? '') . ' ' . ($user['area_name'] ?? ''))) ?>">
            <div class="flex items-center px-3 py-2.5 sm:px-6 sm:py-4">
                <!-- Avatar + Info -->
                <div class="flex items-center gap-2.5 sm:gap-4 flex-1 min-w-0 overflow-hidden">
                    <!-- Avatar with status dot -->
                    <div class="relative flex-shrink-0">
                        <div class="w-9 h-9 sm:w-14 sm:h-14 bg-gray-100 rounded-full flex items-center justify-center">
                            <svg class="w-4.5 h-4.5 sm:w-7 sm:h-7 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 sm:w-4 sm:h-4 <?= userStatusDot($user['status_label']) ?> rounded-full border-2 border-white"></span>
                    </div>

                    <!-- Name / Role / Location -->
                    <div class="min-w-0 flex-1 overflow-hidden">
                        <div class="flex items-center gap-1.5 sm:gap-2 mb-0.5">
                            <h3 class="text-xs sm:text-sm font-bold text-dark truncate"><?= htmlspecialchars($user['display_name'] ?? '') ?></h3>
                            <span class="flex-shrink-0"><?= userStatusBadge($user['status_label']) ?></span>
                            <?php if (isset($adminMap[(int)$user['id']])): ?>
                            <span class="hidden sm:inline-flex items-center gap-0.5 px-1.5 py-0.5 bg-indigo-50 text-indigo-700 text-[10px] font-bold rounded-full uppercase flex-shrink-0" title="<?= htmlspecialchars($adminMap[(int)$user['id']]['panel_role']) ?>">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                                <?= $adminMap[(int)$user['id']]['panel_role'] === 'superadmin' ? 'SA' : ($adminMap[(int)$user['id']]['panel_role'] === 'admin' ? 'Admin' : 'Viewer') ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-[11px] sm:text-xs text-gray-600 truncate"><?= htmlspecialchars($user['cargo_name'] ?? 'Sin cargo asignado') ?></p>
                        <div class="flex items-center gap-1 mt-0.5">
                            <span class="w-1.5 h-1.5 <?= userStatusDot($user['status_label']) ?> rounded-full flex-shrink-0"></span>
                            <p class="text-[11px] sm:text-xs text-muted truncate">
                                <?php if ($user['sociedad_name']): ?>
                                    <?= htmlspecialchars($user['sociedad_name']) ?> ·
                                <?php endif; ?>
                                <?= htmlspecialchars($user['firm_name'] ?? '') ?>
                                <?php if ($user['area_name']): ?>
                                    — <?= htmlspecialchars($user['area_name']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Metrics -->
                <div class="hidden md:flex items-center gap-8 flex-shrink-0 mr-4">
                    <!-- First Login -->
                    <div class="text-center w-20">
                        <p class="text-sm font-bold text-dark"><?= $user['first_login'] ?></p>
                        <p class="text-xs text-muted">Primer Ingreso</p>
                    </div>

                    <!-- Today Active -->
                    <div class="text-center w-20">
                        <p class="text-sm font-bold text-dark"><?= fmtHM((int)$user['today_active']) ?></p>
                        <p class="text-xs text-muted">Hoy</p>
                    </div>

                    <!-- Productive -->
                    <div class="text-center w-16">
                        <p class="text-sm font-bold text-dark"><?= $user['productivity'] ?>%</p>
                        <p class="text-xs text-muted">Productivo</p>
                    </div>

                    <!-- Focus -->
                    <div class="text-center w-12">
                        <p class="text-sm font-bold <?= focusColor($user['focus_score']) ?>"><?= number_format($user['focus_score'], 1) ?></p>
                        <p class="text-xs text-muted">Focus</p>
                    </div>
                </div>

                <!-- Admin toggle + Delete + Arrow -->
                <div class="flex items-center gap-1 sm:gap-2 flex-shrink-0 ml-1">
                    <?php if ($canEditUser): ?>
                    <button type="button" @click.stop.prevent="loadUser(<?= (int)$user['id'] ?>)"
                            class="p-1 sm:p-1.5 rounded-lg text-gray-400 hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar usuario">
                        <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <?php endif; ?>
                    <?php if ($canDeleteUser): ?>
                    <form method="post" class="inline" @click.stop @submit.stop
                          x-data="{ confirming: false }"
                          @submit.prevent="if(!confirming){ confirming=true; return; } $el.submit();">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <button type="submit"
                                class="p-1 sm:p-1.5 rounded-lg transition-colors"
                                :class="confirming ? 'text-white bg-red-500 hover:bg-red-600' : 'text-gray-400 hover:text-red-500 hover:bg-red-50'"
                                :title="confirming ? 'Confirmar eliminación' : 'Eliminar usuario'"
                                @mouseout="setTimeout(() => confirming = false, 3000)">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                    <?php if ($canToggleAdmin): ?>
                    <form method="post" class="inline" @click.stop @submit.stop>
                        <input type="hidden" name="action" value="toggle_admin">
                        <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">
                        <?php $isAdmin = isset($adminMap[(int)$user['id']]); ?>
                        <button type="submit" class="p-1 sm:p-1.5 rounded-lg transition-colors <?= $isAdmin ? 'text-indigo-600 bg-indigo-50 hover:bg-indigo-100' : 'text-gray-400 hover:text-indigo-600 hover:bg-indigo-50' ?>" title="<?= $isAdmin ? 'Revocar acceso admin' : 'Dar acceso admin' ?>">
                            <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                    <div class="text-gray-300 hidden sm:block">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </div>
                </div>
            </div>

            <!-- Mobile metrics (visible only on small screens) -->
            <div class="md:hidden px-3 pb-2.5 sm:px-6 sm:pb-4 grid grid-cols-4 gap-1 border-t border-gray-50 pt-2 sm:pt-3">
                <div class="text-center">
                    <p class="text-[11px] font-bold text-dark"><?= $user['first_login'] ?></p>
                    <p class="text-[10px] text-muted">Ingreso</p>
                </div>
                <div class="text-center">
                    <p class="text-[11px] font-bold text-dark"><?= fmtHM((int)$user['today_active']) ?></p>
                    <p class="text-[10px] text-muted">Hoy</p>
                </div>
                <div class="text-center">
                    <p class="text-[11px] font-bold text-dark"><?= $user['productivity'] ?>%</p>
                    <p class="text-[10px] text-muted">Productivo</p>
                </div>
                <div class="text-center">
                    <p class="text-[11px] font-bold <?= focusColor($user['focus_score']) ?>"><?= number_format($user['focus_score'], 1) ?></p>
                    <p class="text-[10px] text-muted">Focus</p>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Paginador -->
<?php
$qParam = $searchQ !== '' ? '&q=' . urlencode($searchQ) : '';
?>
<?php if ($totalPages > 1): ?>
<div class="flex items-center justify-between mt-6">
    <p class="text-xs text-muted">
        Mostrando <span class="font-semibold text-dark"><?= ($page - 1) * $perPage + 1 ?></span>–<span class="font-semibold text-dark"><?= min($page * $perPage, $totalUsers) ?></span> de <span class="font-semibold text-dark"><?= $totalUsers ?></span>
    </p>
    <div class="flex items-center gap-1">
        <?php if ($page > 1): ?>
        <a href="?page=<?= $page - 1 ?><?= $qParam ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-medium text-muted hover:text-dark hover:bg-gray-100 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <?php endif; ?>
        <?php
        $start = max(1, $page - 2);
        $end = min($totalPages, $page + 2);
        if ($start > 1) echo '<a href="?page=1' . $qParam . '" class="min-w-[32px] px-2 py-1.5 rounded-lg text-xs font-medium text-muted hover:text-dark hover:bg-gray-100 text-center">1</a>';
        if ($start > 2) echo '<span class="text-xs text-muted px-1">…</span>';
        for ($p = $start; $p <= $end; $p++):
        ?>
        <a href="?page=<?= $p ?><?= $qParam ?>" class="min-w-[32px] px-2 py-1.5 rounded-lg text-xs font-medium text-center transition-colors <?= $p === $page ? 'bg-corp-800 text-white' : 'text-muted hover:text-dark hover:bg-gray-100' ?>"><?= $p ?></a>
        <?php endfor;
        if ($end < $totalPages - 1) echo '<span class="text-xs text-muted px-1">…</span>';
        if ($end < $totalPages) echo '<a href="?page=' . $totalPages . $qParam . '" class="min-w-[32px] px-2 py-1.5 rounded-lg text-xs font-medium text-muted hover:text-dark hover:bg-gray-100 text-center">' . $totalPages . '</a>';
        ?>
        <?php if ($page < $totalPages): ?>
        <a href="?page=<?= $page + 1 ?><?= $qParam ?>" class="px-2.5 py-1.5 rounded-lg text-xs font-medium text-muted hover:text-dark hover:bg-gray-100 transition-colors">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Create User Modal -->
<div x-show="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showCreateModal=false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display:none">
    <div class="fixed inset-0 bg-black/40" @click="showCreateModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop
         x-data="{
            email: '',
            searching: false,
            searched: false,
            results: [],
            selected: null,
            manualMode: false,
            timer: null,
            async search() {
                if (this.email.length < 3) { this.results = []; this.searched = false; this.selected = null; return; }
                this.searching = true;
                try {
                    const r = await fetch('users.php?ajax=search_employee&email=' + encodeURIComponent(this.email));
                    const d = await r.json();
                    this.results = d.results || [];
                    this.searched = true;
                    this.selected = null;
                    this.manualMode = false;
                } catch(e) { this.results = []; this.searched = true; }
                this.searching = false;
            },
            debounce() {
                clearTimeout(this.timer);
                this.timer = setTimeout(() => this.search(), 400);
            },
            pick(r) {
                this.selected = r;
                this.manualMode = false;
            },
            goManual() {
                this.selected = null;
                this.manualMode = true;
            },
            reset() {
                this.email = ''; this.searching = false; this.searched = false;
                this.results = []; this.selected = null; this.manualMode = false;
            }
         }" x-init="$watch('showCreateModal', v => { if(v) reset(); })">
        <div class="border-b border-gray-100 px-6 py-4 flex items-center justify-between">
            <h2 class="text-lg font-bold text-dark">Crear Usuario Keeper</h2>
            <button @click="showCreateModal=false" class="p-1.5 rounded-lg text-muted hover:text-dark hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="p-6">
            <!-- Email search -->
            <div class="mb-4">
                <label class="text-xs font-semibold text-muted block mb-1">Buscar empleado por email</label>
                <div class="relative">
                    <svg class="w-4 h-4 text-muted absolute left-3 top-1/2 -translate-y-1/2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    <input type="text" x-model="email" @input="debounce()" placeholder="Escribe el email del empleado..."
                           class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    <template x-if="searching">
                        <svg class="w-4 h-4 text-corp-800 absolute right-3 top-1/2 -translate-y-1/2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    </template>
                </div>
            </div>

            <!-- Search results -->
            <template x-if="searched && results.length > 0 && !selected && !manualMode">
                <div class="mb-4 border border-gray-200 rounded-lg divide-y divide-gray-100 max-h-48 overflow-y-auto">
                    <template x-for="r in results" :key="r.id">
                        <button type="button" @click="r.registered ? null : pick(r)"
                                class="w-full px-3 py-2.5 text-left flex items-center justify-between hover:bg-gray-50 transition-colors"
                                :class="r.registered ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer'">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-dark truncate" x-text="r.name"></p>
                                <p class="text-xs text-muted" x-text="r.email + ' · CC: ' + r.cc"></p>
                            </div>
                            <template x-if="r.registered">
                                <span class="text-[10px] bg-amber-50 text-amber-700 px-2 py-0.5 rounded-full font-medium flex-shrink-0">Ya registrado</span>
                            </template>
                            <template x-if="!r.registered">
                                <span class="text-[10px] bg-emerald-50 text-emerald-700 px-2 py-0.5 rounded-full font-medium flex-shrink-0">Seleccionar</span>
                            </template>
                        </button>
                    </template>
                </div>
            </template>

            <!-- No results → offer manual -->
            <template x-if="searched && results.length === 0 && !manualMode">
                <div class="mb-4 bg-amber-50 border border-amber-200 rounded-lg p-3">
                    <p class="text-xs text-amber-800 mb-2">No se encontró ningún empleado con ese email en el sistema legacy.</p>
                    <button type="button" @click="goManual()" class="text-xs font-semibold text-corp-800 hover:underline">Crear usuario solo en Keeper →</button>
                </div>
            </template>

            <!-- Selected employee → form with legacy link -->
            <template x-if="selected">
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create_user">
                    <input type="hidden" name="employee_id" :value="selected.id">
                    <div class="bg-emerald-50 border border-emerald-200 rounded-lg p-3">
                        <p class="text-xs font-semibold text-emerald-800 mb-1">Empleado encontrado en legacy:</p>
                        <p class="text-sm font-bold text-dark" x-text="selected.name"></p>
                        <p class="text-xs text-muted" x-text="'CC: ' + selected.cc + ' · ' + selected.email"></p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-muted block mb-1">Contraseña</label>
                        <input type="text" name="password" required placeholder="Contraseña inicial"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                        <p class="text-[10px] text-muted mt-1">El usuario la usará para iniciar sesión en el cliente</p>
                    </div>
                    <div class="flex justify-between items-center pt-2">
                        <button type="button" @click="selected = null" class="text-xs text-muted hover:text-dark">← Cambiar selección</button>
                        <div class="flex gap-2">
                            <button type="button" @click="showCreateModal=false" class="px-4 py-2 text-sm font-medium text-muted hover:text-dark hover:bg-gray-100 rounded-lg transition-colors">Cancelar</button>
                            <button type="submit" class="px-6 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors shadow-sm">Crear</button>
                        </div>
                    </div>
                </form>
            </template>

            <!-- Manual mode → keeper-only user -->
            <template x-if="manualMode">
                <form method="post" class="space-y-4">
                    <input type="hidden" name="action" value="create_user">
                    <input type="hidden" name="employee_id" value="0">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-2">
                        <p class="text-xs text-blue-800">Este usuario se creará solo en Keeper, sin vínculo con el sistema legacy.</p>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-muted block mb-1">Nombre completo *</label>
                        <input type="text" name="manual_name" required placeholder="Ej: Juan Pérez López"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-muted block mb-1">Email *</label>
                        <input type="email" name="manual_email" required :value="email" placeholder="email@empresa.com"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-muted block mb-1">CC (opcional)</label>
                        <input type="text" name="manual_cc" placeholder="Cédula de ciudadanía"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-muted block mb-1">Contraseña *</label>
                        <input type="text" name="password" required placeholder="Contraseña inicial"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    </div>
                    <div class="flex justify-between items-center pt-2">
                        <button type="button" @click="manualMode = false" class="text-xs text-muted hover:text-dark">← Volver a buscar</button>
                        <div class="flex gap-2">
                            <button type="button" @click="showCreateModal=false" class="px-4 py-2 text-sm font-medium text-muted hover:text-dark hover:bg-gray-100 rounded-lg transition-colors">Cancelar</button>
                            <button type="submit" class="px-6 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors shadow-sm">Crear</button>
                        </div>
                    </div>
                </form>
            </template>

            <!-- All results registered → offer manual -->
            <template x-if="searched && results.length > 0 && results.every(r => r.registered) && !manualMode && !selected">
                <div class="mt-2">
                    <button type="button" @click="goManual()" class="text-xs font-semibold text-corp-800 hover:underline">Crear usuario solo en Keeper →</button>
                </div>
            </template>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div x-show="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showEditModal=false" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" style="display:none">
    <div class="fixed inset-0 bg-black/40" @click="showEditModal=false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md" @click.stop>
        <div class="border-b border-gray-100 px-6 py-4 flex items-center justify-between">
            <h2 class="text-lg font-bold text-dark">Editar Usuario</h2>
            <button @click="showEditModal=false" class="p-1.5 rounded-lg text-muted hover:text-dark hover:bg-gray-100 transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="post" class="p-6 space-y-4">
            <input type="hidden" name="action" value="edit_user">
            <input type="hidden" name="user_id" :value="editUser.id">
            <div>
                <label class="text-xs font-semibold text-muted block mb-1">Nombre completo *</label>
                <input type="text" name="edit_name" required x-model="editUser.display_name"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
            </div>
            <div>
                <label class="text-xs font-semibold text-muted block mb-1">Email</label>
                <input type="email" name="edit_email" x-model="editUser.email" placeholder="email@empresa.com"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
            </div>
            <div>
                <label class="text-xs font-semibold text-muted block mb-1">CC</label>
                <input type="text" name="edit_cc" x-model="editUser.cc" placeholder="Cédula de ciudadanía"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
            </div>
            <div>
                <label class="text-xs font-semibold text-muted block mb-1">Estado</label>
                <select name="edit_status" x-model="editUser.status"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none bg-white">
                    <option value="active">Activo</option>
                    <option value="inactive">Inactivo</option>
                    <option value="locked">Bloqueado</option>
                </select>
            </div>
            <div>
                <label class="text-xs font-semibold text-muted block mb-1">Nueva contraseña <span class="font-normal">(dejar vacío para no cambiar)</span></label>
                <input type="text" name="edit_password" placeholder="Nueva contraseña"
                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
            </div>
            <template x-if="editUser.legacy_employee_id">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                    <p class="text-[11px] text-blue-700">Vinculado a empleado legacy ID: <span x-text="editUser.legacy_employee_id" class="font-semibold"></span></p>
                </div>
            </template>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="showEditModal=false" class="px-4 py-2 text-sm font-medium text-muted hover:text-dark hover:bg-gray-100 rounded-lg transition-colors">Cancelar</button>
                <button type="submit" class="px-6 py-2 bg-corp-800 text-white text-sm font-medium rounded-lg hover:bg-corp-900 transition-colors shadow-sm">Guardar</button>
            </div>
        </form>
    </div>
</div>

</div><!-- /x-data -->

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
