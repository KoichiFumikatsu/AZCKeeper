<?php
/**
 * Gestión de Políticas — Solo Superadmin.
 *
 * CRUD completo sobre keeper_policy_assignments.
 * Permite editar la configuración efectiva que recibe cada cliente.
 */
require_once __DIR__ . '/admin_auth.php';

// ==================== GUARD: SOLO SUPERADMIN ====================
if (!hasRole('superadmin')) {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>403</title></head><body style="font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;background:#f9fafb"><div style="text-align:center"><h1 style="font-size:4rem;color:#be1622;margin:0">403</h1><p style="color:#9d9d9c">Acceso denegado. Solo superadmin puede gestionar políticas.</p><a href="index.php" style="color:#003a5d">Volver al Dashboard</a></div></body></html>';
    exit;
}

$pageTitle   = 'Políticas';
$currentPage = 'policies';
$msg = '';
$msgType = '';

// ==================== ACCIONES POST ====================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $csrfOk = true; // se puede agregar CSRF token en el futuro

    try {
        switch ($action) {
            // ---------- Activar una política (desactiva las demás del mismo scope/target) ----------
            case 'activate':
                $id = (int)($_POST['policy_id'] ?? 0);
                if ($id <= 0) throw new \Exception('ID inválido');

                // Obtener la política
                $st = $pdo->prepare("SELECT scope, user_id, device_id FROM keeper_policy_assignments WHERE id = ?");
                $st->execute([$id]);
                $pol = $st->fetch(PDO::FETCH_ASSOC);
                if (!$pol) throw new \Exception('Política no encontrada');

                // Desactivar todas del mismo scope/target
                if ($pol['scope'] === 'global') {
                    $pdo->exec("UPDATE keeper_policy_assignments SET is_active = 0 WHERE scope = 'global'");
                } elseif ($pol['scope'] === 'user') {
                    $st2 = $pdo->prepare("UPDATE keeper_policy_assignments SET is_active = 0 WHERE scope = 'user' AND user_id = ?");
                    $st2->execute([$pol['user_id']]);
                } elseif ($pol['scope'] === 'device') {
                    $st2 = $pdo->prepare("UPDATE keeper_policy_assignments SET is_active = 0 WHERE scope = 'device' AND device_id = ?");
                    $st2->execute([$pol['device_id']]);
                }

                // Activar la seleccionada
                $pdo->prepare("UPDATE keeper_policy_assignments SET is_active = 1, version = version + 1 WHERE id = ?")->execute([$id]);
                $msg = 'Política #' . $id . ' activada correctamente.';
                $msgType = 'success';
                break;

            // ---------- Desactivar ----------
            case 'deactivate':
                $id = (int)($_POST['policy_id'] ?? 0);
                if ($id <= 0) throw new \Exception('ID inválido');
                $pdo->prepare("UPDATE keeper_policy_assignments SET is_active = 0 WHERE id = ?")->execute([$id]);
                $msg = 'Política #' . $id . ' desactivada.';
                $msgType = 'success';
                break;

            // ---------- Eliminar ----------
            case 'delete':
                $id = (int)($_POST['policy_id'] ?? 0);
                if ($id <= 0) throw new \Exception('ID inválido');
                // No permitir eliminar la política global activa
                $st = $pdo->prepare("SELECT scope, is_active FROM keeper_policy_assignments WHERE id = ?");
                $st->execute([$id]);
                $check = $st->fetch(PDO::FETCH_ASSOC);
                if ($check && $check['scope'] === 'global' && $check['is_active']) {
                    throw new \Exception('No se puede eliminar la política global activa. Activa otra primero.');
                }
                $pdo->prepare("DELETE FROM keeper_policy_assignments WHERE id = ?")->execute([$id]);
                $msg = 'Política #' . $id . ' eliminada.';
                $msgType = 'success';
                break;

            // ---------- Crear nueva ----------
            case 'create':
                $scope    = $_POST['scope'] ?? 'global';
                $userId   = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
                $deviceId = !empty($_POST['device_id']) ? (int)$_POST['device_id'] : null;
                $priority = (int)($_POST['priority'] ?? 100);
                $jsonRaw  = $_POST['policy_json'] ?? '{}';

                if (!in_array($scope, ['global', 'user', 'device'])) throw new \Exception('Scope inválido');
                if ($scope === 'user' && !$userId) throw new \Exception('Seleccione un usuario');
                if ($scope === 'device' && !$deviceId) throw new \Exception('Seleccione un dispositivo');

                $decoded = json_decode($jsonRaw, true);
                if (!is_array($decoded)) throw new \Exception('JSON inválido');

                $st = $pdo->prepare("
                    INSERT INTO keeper_policy_assignments (scope, user_id, device_id, version, priority, is_active, policy_json)
                    VALUES (:scope, :uid, :did, 1, :prio, 0, :json)
                ");
                $st->execute([
                    ':scope' => $scope,
                    ':uid'   => $scope === 'user' ? $userId : null,
                    ':did'   => $scope === 'device' ? $deviceId : null,
                    ':prio'  => $priority,
                    ':json'  => $jsonRaw,
                ]);
                $msg = 'Política creada (ID: ' . $pdo->lastInsertId() . '). Recuerda activarla.';
                $msgType = 'success';
                break;

            // ---------- Actualizar JSON ----------
            case 'update':
                $id      = (int)($_POST['policy_id'] ?? 0);
                $jsonRaw = $_POST['policy_json'] ?? '{}';
                $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : null;

                if ($id <= 0) throw new \Exception('ID inválido');
                $decoded = json_decode($jsonRaw, true);
                if (!is_array($decoded)) throw new \Exception('JSON inválido');

                $sql = "UPDATE keeper_policy_assignments SET policy_json = :json, version = version + 1";
                $params = [':json' => $jsonRaw, ':id' => $id];
                if ($priority !== null) {
                    $sql .= ", priority = :prio";
                    $params[':prio'] = $priority;
                }
                $sql .= " WHERE id = :id";
                $pdo->prepare($sql)->execute($params);
                $msg = 'Política #' . $id . ' actualizada. La versión se incrementó.';
                $msgType = 'success';
                break;

            // ---------- Force Push (incrementar versión global) ----------
            case 'force_push':
                $st = $pdo->prepare("UPDATE keeper_policy_assignments SET version = version + 1 WHERE scope = 'global' AND is_active = 1");
                $st->execute();
                $affected = $st->rowCount();
                $msg = $affected > 0
                    ? 'Force Push enviado. Los clientes aplicarán la nueva configuración en el próximo handshake.'
                    : 'No hay política global activa para incrementar.';
                $msgType = $affected > 0 ? 'success' : 'warning';
                break;

            default:
                throw new \Exception('Acción desconocida');
        }
    } catch (\Exception $e) {
        $msg = $e->getMessage();
        $msgType = 'error';
    }

    // PRG
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $msgType === 'success') {
        $encoded = urlencode($msg);
        header("Location: policies.php?msg={$encoded}&type=success");
        exit;
    }
}

// Flash messages from redirect
if (isset($_GET['msg'])) {
    $msg = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

// ==================== QUERIES ====================
// Todas las políticas
$allPolicies = $pdo->query("
    SELECT
        p.*,
        u.display_name AS user_name,
        d.device_name,
        d.device_guid
    FROM keeper_policy_assignments p
    LEFT JOIN keeper_users u ON u.id = p.user_id
    LEFT JOIN keeper_devices d ON d.id = p.device_id
    ORDER BY p.scope ASC, p.is_active DESC, p.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Política global activa
$activeGlobal = null;
foreach ($allPolicies as $p) {
    if ($p['scope'] === 'global' && $p['is_active']) {
        $activeGlobal = $p;
        break;
    }
}

// Usuarios y dispositivos para los selects
$users = $pdo->query("SELECT id, display_name FROM keeper_users WHERE status = 'active' ORDER BY display_name")->fetchAll(PDO::FETCH_ASSOC);
$devices = $pdo->query("SELECT id, device_name, device_guid FROM keeper_devices WHERE status = 'active' ORDER BY device_name")->fetchAll(PDO::FETCH_ASSOC);

// Contar por scope
$countGlobal = 0;
$countUser   = 0;
$countDevice = 0;
$activeUserOverrides = 0;
$activeDeviceOverrides = 0;
foreach ($allPolicies as $p) {
    if ($p['scope'] === 'global') $countGlobal++;
    elseif ($p['scope'] === 'user') { $countUser++; if ($p['is_active']) $activeUserOverrides++; }
    elseif ($p['scope'] === 'device') { $countDevice++; if ($p['is_active']) $activeDeviceOverrides++; }
}

// ==================== HELPERS ====================
function scopeBadge(string $scope): string {
    return match ($scope) {
        'global' => '<span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-700 bg-blue-50 px-2.5 py-1 rounded-full">🌐 Global</span>',
        'user'   => '<span class="inline-flex items-center gap-1 text-xs font-semibold text-purple-700 bg-purple-50 px-2.5 py-1 rounded-full">👤 Usuario</span>',
        'device' => '<span class="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 bg-teal-50 px-2.5 py-1 rounded-full">💻 Dispositivo</span>',
        default  => '<span class="text-xs text-muted">' . htmlspecialchars($scope) . '</span>',
    };
}

function activeIndicator(bool $active): string {
    return $active
        ? '<span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full"><span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>Activa</span>'
        : '<span class="inline-flex items-center gap-1.5 text-xs font-medium text-gray-500 bg-gray-100 px-2.5 py-1 rounded-full"><span class="w-2 h-2 bg-gray-400 rounded-full"></span>Inactiva</span>';
}

function formatPolicyDate(string $dt): string {
    return date('d M Y H:i', strtotime($dt));
}

function summarizePolicy(array $json): string {
    $parts = [];
    if (isset($json['modules'])) {
        $m = $json['modules'];
        if (!empty($m['enableActivityTracking'])) $parts[] = 'Activity';
        if (!empty($m['enableWindowTracking']))    $parts[] = 'Windows';
        if (!empty($m['enableCallTracking']))      $parts[] = 'Calls';
        if (!empty($m['enableBlocking']))           $parts[] = 'Blocking';
    }
    if (isset($json['updates']['enableAutoUpdate']) && $json['updates']['enableAutoUpdate']) $parts[] = 'AutoUpdate';
    if (isset($json['blocking']['enableDeviceLock']) && $json['blocking']['enableDeviceLock']) $parts[] = '🔒Lock';
    return implode(' · ', $parts) ?: 'Sin módulos';
}

require_once __DIR__ . '/partials/layout_header.php';
?>

<!-- Alpine.js state for the whole page -->
<div x-data="policiesPage()" x-cloak>

<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-xl text-sm font-medium flex items-center gap-2
    <?= $msgType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' ?>
    <?= $msgType === 'error' ? 'bg-red-50 text-accent-500 border border-red-200' : '' ?>
    <?= $msgType === 'warning' ? 'bg-amber-50 text-amber-700 border border-amber-200' : '' ?>">
    <?php if ($msgType === 'success'): ?>
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php elseif ($msgType === 'error'): ?>
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php else: ?>
        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    <?php endif; ?>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- Header Row -->
<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-sm text-muted">Configuración de clientes AZCKeeper vía políticas JSON</p>
    </div>
    <div class="flex items-center gap-3">
        <!-- Force Push -->
        <form method="post" onsubmit="return confirm('¿Incrementar versión global? Los clientes re-aplicarán la configuración.')">
            <input type="hidden" name="action" value="force_push">
            <button type="submit"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-amber-50 border border-amber-200 text-amber-700 rounded-xl text-sm font-medium hover:bg-amber-100 transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Force Push
            </button>
        </form>
        <!-- Nueva Política -->
        <button @click="showCreate = true"
                class="inline-flex items-center gap-2 px-4 py-2 bg-corp-800 text-white rounded-xl text-sm font-medium hover:bg-corp-900 transition-colors shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Nueva Política
        </button>
    </div>
</div>

<!-- KPI Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-9 h-9 bg-blue-50 rounded-lg flex items-center justify-center">
                <span class="text-lg">🌐</span>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $countGlobal ?></p>
                <p class="text-xs text-muted">Globales</p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-9 h-9 bg-purple-50 rounded-lg flex items-center justify-center">
                <span class="text-lg">👤</span>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $countUser ?></p>
                <p class="text-xs text-muted">Overrides Usuario <span class="text-emerald-600">(<?= $activeUserOverrides ?> activas)</span></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-9 h-9 bg-teal-50 rounded-lg flex items-center justify-center">
                <span class="text-lg">💻</span>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark"><?= $countDevice ?></p>
                <p class="text-xs text-muted">Overrides Dispositivo <span class="text-emerald-600">(<?= $activeDeviceOverrides ?> activas)</span></p>
            </div>
        </div>
    </div>
    <div class="bg-white rounded-xl border border-gray-100 p-5">
        <div class="flex items-center gap-3 mb-2">
            <div class="w-9 h-9 bg-emerald-50 rounded-lg flex items-center justify-center">
                <span class="text-lg">📡</span>
            </div>
            <div>
                <p class="text-2xl font-bold text-dark">v<?= $activeGlobal ? (int)$activeGlobal['version'] : 0 ?></p>
                <p class="text-xs text-muted">Versión Global Activa</p>
            </div>
        </div>
    </div>
</div>

<?php if ($activeGlobal): ?>
<?php $gJson = json_decode($activeGlobal['policy_json'], true) ?? []; ?>
<!-- Active Global Policy Card -->
<div class="bg-white rounded-xl border-2 border-emerald-200 p-6 mb-8">
    <div class="flex items-center justify-between mb-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center">
                <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </div>
            <div>
                <h2 class="text-base font-bold text-dark">Política Global Activa</h2>
                <p class="text-xs text-muted">ID #<?= $activeGlobal['id'] ?> · v<?= $activeGlobal['version'] ?> · Creada <?= formatPolicyDate($activeGlobal['created_at']) ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            <button @click="openEditor(<?= (int)$activeGlobal['id'] ?>, <?= htmlspecialchars(json_encode($gJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>, <?= (int)$activeGlobal['priority'] ?>)"
                    class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-corp-800 bg-corp-50 rounded-lg hover:bg-corp-100 transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Editar
            </button>
        </div>
    </div>

    <!-- Summary Grid -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">
        <!-- Timers -->
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Timers</p>
            <p class="text-xs text-dark">Handshake: <span class="font-semibold"><?= $gJson['timers']['handshakeIntervalMinutes'] ?? '?' ?>m</span></p>
            <p class="text-xs text-dark">Flush: <span class="font-semibold"><?= $gJson['timers']['activityFlushIntervalSeconds'] ?? '?' ?>s</span></p>
            <p class="text-xs text-dark">Retry: <span class="font-semibold"><?= $gJson['timers']['offlineQueueRetrySeconds'] ?? '?' ?>s</span></p>
        </div>
        <!-- Logging -->
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Logging</p>
            <p class="text-xs text-dark">Nivel: <span class="font-semibold"><?= $gJson['logging']['globalLevel'] ?? '?' ?></span></p>
            <p class="text-xs text-dark">File: <span class="font-semibold"><?= !empty($gJson['logging']['enableFileLogging']) ? '✅' : '❌' ?></span></p>
            <p class="text-xs text-dark">Discord: <span class="font-semibold"><?= !empty($gJson['logging']['enableDiscordLogging']) ? '✅' : '❌' ?></span></p>
        </div>
        <!-- Modules -->
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Módulos</p>
            <p class="text-xs text-dark">Activity: <?= !empty($gJson['modules']['enableActivityTracking']) ? '✅' : '❌' ?></p>
            <p class="text-xs text-dark">Windows: <?= !empty($gJson['modules']['enableWindowTracking']) ? '✅' : '❌' ?></p>
            <p class="text-xs text-dark">Calls: <?= !empty($gJson['modules']['enableCallTracking']) ? '✅' : '❌' ?></p>
        </div>
        <!-- Startup -->
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Startup</p>
            <p class="text-xs text-dark">AutoStart: <?= !empty($gJson['startup']['enableAutoStartup']) ? '✅' : '❌' ?></p>
            <p class="text-xs text-dark">Minimized: <?= !empty($gJson['startup']['startMinimized']) ? '✅' : '❌' ?></p>
        </div>
        <!-- Updates -->
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Updates</p>
            <p class="text-xs text-dark">Auto: <?= !empty($gJson['updates']['enableAutoUpdate']) ? '✅' : '❌' ?></p>
            <p class="text-xs text-dark">Download: <?= !empty($gJson['updates']['autoDownload']) ? '✅' : '❌' ?></p>
            <p class="text-xs text-dark">Intervalo: <span class="font-semibold"><?= $gJson['updates']['checkIntervalMinutes'] ?? '?' ?>m</span></p>
        </div>
        <!-- Blocking -->
        <div class="bg-gray-50 rounded-lg p-3">
            <p class="text-[10px] font-semibold text-muted uppercase tracking-wider mb-1.5">Bloqueo</p>
            <p class="text-xs text-dark">Lock: <?= !empty($gJson['blocking']['enableDeviceLock']) ? '<span class="text-accent-500 font-semibold">🔒 SÍ</span>' : '❌' ?></p>
            <p class="text-xs text-dark">PIN: <?= !empty($gJson['blocking']['allowUnlockWithPin']) ? '✅' : '❌' ?></p>
            <p class="text-xs text-dark">Blocking: <?= !empty($gJson['modules']['enableBlocking']) ? '✅' : '❌' ?></p>
        </div>
    </div>

    <?php if (!empty($gJson['apiBaseUrl'])): ?>
    <div class="mt-3 px-3 py-2 bg-corp-50 rounded-lg">
        <p class="text-xs text-corp-800"><span class="font-semibold">API Base URL:</span> <?= htmlspecialchars($gJson['apiBaseUrl']) ?></p>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filter Tabs -->
<div class="flex items-center gap-2 mb-4">
    <button @click="filter = 'all'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
            :class="filter === 'all' ? 'bg-corp-800 text-white' : 'bg-white border border-gray-200 text-muted hover:text-dark'">
        Todas (<?= count($allPolicies) ?>)
    </button>
    <button @click="filter = 'global'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
            :class="filter === 'global' ? 'bg-blue-600 text-white' : 'bg-white border border-gray-200 text-muted hover:text-dark'">
        🌐 Global (<?= $countGlobal ?>)
    </button>
    <button @click="filter = 'user'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
            :class="filter === 'user' ? 'bg-purple-600 text-white' : 'bg-white border border-gray-200 text-muted hover:text-dark'">
        👤 Usuario (<?= $countUser ?>)
    </button>
    <button @click="filter = 'device'" class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors"
            :class="filter === 'device' ? 'bg-teal-600 text-white' : 'bg-white border border-gray-200 text-muted hover:text-dark'">
        💻 Dispositivo (<?= $countDevice ?>)
    </button>
</div>

<!-- Policies Table -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-8">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100 bg-gray-50/50">
                    <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">ID</th>
                    <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Scope</th>
                    <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Target</th>
                    <th class="text-center py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Estado</th>
                    <th class="text-center py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Ver.</th>
                    <th class="text-center py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Prio.</th>
                    <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Resumen</th>
                    <th class="text-left py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Creada</th>
                    <th class="text-right py-3 px-4 text-xs font-semibold text-muted uppercase tracking-wider">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($allPolicies as $p): ?>
                <?php
                    $pJson = json_decode($p['policy_json'], true) ?? [];
                    $target = match ($p['scope']) {
                        'global' => '—',
                        'user'   => htmlspecialchars($p['user_name'] ?? ('UID ' . $p['user_id'])),
                        'device' => htmlspecialchars($p['device_name'] ?? ('DID ' . $p['device_id'])),
                        default  => '?',
                    };
                ?>
                <tr class="hover:bg-gray-50/50 transition-colors <?= $p['is_active'] ? 'bg-emerald-50/30' : '' ?>"
                    x-show="filter === 'all' || filter === '<?= $p['scope'] ?>'">
                    <td class="py-3 px-4 font-mono text-xs text-muted">#<?= $p['id'] ?></td>
                    <td class="py-3 px-4"><?= scopeBadge($p['scope']) ?></td>
                    <td class="py-3 px-4 text-sm font-medium text-dark"><?= $target ?></td>
                    <td class="py-3 px-4 text-center"><?= activeIndicator((bool)$p['is_active']) ?></td>
                    <td class="py-3 px-4 text-center font-mono text-xs"><?= $p['version'] ?></td>
                    <td class="py-3 px-4 text-center font-mono text-xs"><?= $p['priority'] ?></td>
                    <td class="py-3 px-4 text-xs text-muted max-w-xs truncate"><?= summarizePolicy($pJson) ?></td>
                    <td class="py-3 px-4 text-xs text-muted whitespace-nowrap"><?= formatPolicyDate($p['created_at']) ?></td>
                    <td class="py-3 px-4">
                        <div class="flex items-center justify-end gap-1">
                            <!-- Editar -->
                            <button @click="openEditor(<?= (int)$p['id'] ?>, <?= htmlspecialchars(json_encode($pJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>, <?= (int)$p['priority'] ?>)"
                                    class="p-1.5 rounded-lg text-muted hover:text-corp-800 hover:bg-corp-50 transition-colors" title="Editar">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <!-- Activar / Desactivar -->
                            <?php if ($p['is_active']): ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="deactivate">
                                <input type="hidden" name="policy_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-amber-600 hover:bg-amber-50 transition-colors" title="Desactivar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="post" class="inline">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="policy_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-emerald-600 hover:bg-emerald-50 transition-colors" title="Activar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                            <!-- Duplicar -->
                            <button @click="duplicatePolicy(<?= (int)$p['id'] ?>, '<?= $p['scope'] ?>', <?= htmlspecialchars(json_encode($pJson, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>, <?= (int)$p['priority'] ?>)"
                                    class="p-1.5 rounded-lg text-muted hover:text-blue-600 hover:bg-blue-50 transition-colors" title="Duplicar">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                            <!-- Eliminar -->
                            <form method="post" class="inline" onsubmit="return confirm('¿Eliminar política #<?= $p['id'] ?>?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="policy_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="p-1.5 rounded-lg text-muted hover:text-accent-500 hover:bg-red-50 transition-colors" title="Eliminar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Info Card: Cómo funciona la jerarquía -->
<div class="bg-corp-50 rounded-xl border border-corp-100 p-5 mb-8">
    <h3 class="text-sm font-bold text-corp-800 mb-2 flex items-center gap-2">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Jerarquía de Políticas
    </h3>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs text-corp-800">
        <div>
            <p class="font-semibold mb-1">1. Global (Base)</p>
            <p class="text-muted">Se aplica a todos los clientes. Solo puede haber una activa.</p>
        </div>
        <div>
            <p class="font-semibold mb-1">2. Usuario (Override)</p>
            <p class="text-muted">Se mezcla (deep merge) sobre la global para un usuario específico.</p>
        </div>
        <div>
            <p class="font-semibold mb-1">3. Dispositivo (Override)</p>
            <p class="text-muted">Se mezcla sobre usuario+global para un dispositivo específico. Máxima prioridad.</p>
        </div>
    </div>
</div>

<!-- ==================== MODAL: CREAR POLÍTICA ==================== -->
<template x-if="showCreate">
<div class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showCreate = false">
    <div class="fixed inset-0 bg-black/40" @click="showCreate = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl flex items-center justify-between z-10">
            <h2 class="text-lg font-bold text-dark">Nueva Política</h2>
            <button @click="showCreate = false" class="p-1 rounded-lg hover:bg-gray-100 text-muted hover:text-dark transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="post" class="p-6 space-y-5">
            <input type="hidden" name="action" value="create">

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-dark mb-1.5">Scope</label>
                    <select name="scope" x-model="createScope"
                            class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                        <option value="global">🌐 Global</option>
                        <option value="user">👤 Usuario</option>
                        <option value="device">💻 Dispositivo</option>
                    </select>
                </div>
                <div x-show="createScope === 'user'">
                    <label class="block text-xs font-semibold text-dark mb-1.5">Usuario</label>
                    <select name="user_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['display_name']) ?> (ID: <?= $u['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div x-show="createScope === 'device'">
                    <label class="block text-xs font-semibold text-dark mb-1.5">Dispositivo</label>
                    <select name="device_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                        <option value="">Seleccionar...</option>
                        <?php foreach ($devices as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['device_name'] ?? $d['device_guid']) ?> (ID: <?= $d['id'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-dark mb-1.5">Prioridad</label>
                    <input type="number" name="priority" value="100" min="1" max="999"
                           class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    <p class="text-[10px] text-muted mt-1">Menor = más prioritaria (global=1, user=50, device=100)</p>
                </div>
            </div>

            <div>
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-semibold text-dark">Policy JSON</label>
                    <button type="button" @click="createJson = JSON.stringify(defaultPolicy(), null, 2)"
                            class="text-[10px] text-corp-800 hover:underline">Cargar plantilla por defecto</button>
                </div>
                <textarea name="policy_json" x-model="createJson" rows="18"
                          class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"
                          placeholder='{"timers": {...}, "modules": {...}, ...}'></textarea>
            </div>

            <div class="flex items-center justify-end gap-3">
                <button type="button" @click="showCreate = false" class="px-4 py-2 text-sm text-muted hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-5 py-2 bg-corp-800 text-white rounded-xl text-sm font-medium hover:bg-corp-900 transition-colors">
                    Crear Política
                </button>
            </div>
        </form>
    </div>
</div>
</template>

<!-- ==================== MODAL: EDITAR POLÍTICA ==================== -->
<template x-if="showEditor">
<div class="fixed inset-0 z-50 flex items-center justify-center p-4" @keydown.escape.window="showEditor = false">
    <div class="fixed inset-0 bg-black/40" @click="showEditor = false"></div>
    <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-y-auto">
        <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 rounded-t-2xl flex items-center justify-between z-10">
            <div class="flex items-center gap-3">
                <h2 class="text-lg font-bold text-dark">Editar Política #<span x-text="editId"></span></h2>
            </div>
            <div class="flex items-center gap-2">
                <!-- Tab toggle -->
                <div class="flex items-center bg-gray-100 rounded-lg p-0.5">
                    <button @click="editorTab = 'visual'" class="px-3 py-1 rounded-md text-xs font-medium transition-colors"
                            :class="editorTab === 'visual' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark'">Visual</button>
                    <button @click="editorTab = 'json'" class="px-3 py-1 rounded-md text-xs font-medium transition-colors"
                            :class="editorTab === 'json' ? 'bg-white shadow-sm text-dark' : 'text-muted hover:text-dark'">JSON</button>
                </div>
                <button @click="showEditor = false" class="p-1 rounded-lg hover:bg-gray-100 text-muted hover:text-dark transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <form method="post" @submit="syncJsonBeforeSubmit($event)">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="policy_id" :value="editId">

            <div class="p-6">
                <!-- Visual Editor -->
                <div x-show="editorTab === 'visual'" class="space-y-5">

                    <!-- Priority -->
                    <div class="flex items-center gap-4">
                        <label class="text-xs font-semibold text-dark">Prioridad:</label>
                        <input type="number" name="priority" x-model.number="editPriority" min="1" max="999"
                               class="w-24 px-3 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none">
                    </div>

                    <!-- API Base URL -->
                    <div>
                        <label class="block text-xs font-semibold text-dark mb-1.5">API Base URL</label>
                        <input type="text" x-model="editData.apiBaseUrl"
                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none"
                               placeholder="https://...">
                    </div>

                    <!-- Timers -->
                    <fieldset class="border border-gray-200 rounded-xl p-4">
                        <legend class="text-xs font-bold text-dark px-2 uppercase tracking-wider">⏱ Timers</legend>
                        <div class="grid grid-cols-3 gap-4 mt-2">
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Handshake (min)</label>
                                <input type="number" x-model.number="editData.timers.handshakeIntervalMinutes" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Activity Flush (s)</label>
                                <input type="number" x-model.number="editData.timers.activityFlushIntervalSeconds" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Offline Retry (s)</label>
                                <input type="number" x-model.number="editData.timers.offlineQueueRetrySeconds" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Logging -->
                    <fieldset class="border border-gray-200 rounded-xl p-4">
                        <legend class="text-xs font-bold text-dark px-2 uppercase tracking-wider">📋 Logging</legend>
                        <div class="grid grid-cols-2 gap-4 mt-2">
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Global Level</label>
                                <select x-model="editData.logging.globalLevel"
                                        class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                                    <option>Trace</option><option>Debug</option><option>Info</option><option>Warn</option><option>Error</option><option>Fatal</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Client Override Level</label>
                                <select x-model="editData.logging.clientOverrideLevel"
                                        class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                                    <option>Trace</option><option>Debug</option><option>Info</option><option>Warn</option><option>Error</option><option>Fatal</option>
                                </select>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-[10px] text-muted mb-1">Discord Webhook URL</label>
                                <input type="text" x-model="editData.logging.discordWebhookUrl"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.logging.enableFileLogging" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Archivo de log</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.logging.enableDiscordLogging" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Discord logging</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Modules -->
                    <fieldset class="border border-gray-200 rounded-xl p-4">
                        <legend class="text-xs font-bold text-dark px-2 uppercase tracking-wider">🧩 Módulos</legend>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-3 mt-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableActivityTracking" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Activity Tracking</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableWindowTracking" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Window Tracking</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableProcessTracking" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Process Tracking</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableCallTracking" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Call Tracking</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.countCallsAsActive" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Calls = Active</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableBlocking" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Blocking</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableUpdateManager" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Update Manager</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.modules.enableDebugWindow" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Debug Window</span>
                            </label>
                        </div>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 pt-3 border-t border-gray-100">
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Activity Interval (s)</label>
                                <input type="number" x-model.number="editData.modules.activityIntervalSeconds" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Window Track Interval (s)</label>
                                <input type="number" x-model.number="editData.modules.windowTrackingIntervalSeconds" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Inactivity Threshold (s)</label>
                                <input type="number" x-model.number="editData.modules.activityInactivityThresholdSeconds" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Call Max Idle (s)</label>
                                <input type="number" x-model.number="editData.modules.callActiveMaxIdleSeconds" min="1"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                        </div>
                        <!-- Keywords -->
                        <div class="grid grid-cols-2 gap-4 mt-4 pt-3 border-t border-gray-100">
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Call Process Keywords (separados por coma)</label>
                                <input type="text" x-model="callProcessKeywordsStr"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Call Title Keywords (separados por coma)</label>
                                <input type="text" x-model="callTitleKeywordsStr"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 outline-none">
                            </div>
                        </div>
                    </fieldset>

                    <!-- Startup -->
                    <fieldset class="border border-gray-200 rounded-xl p-4">
                        <legend class="text-xs font-bold text-dark px-2 uppercase tracking-wider">🚀 Startup</legend>
                        <div class="flex items-center gap-6 mt-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.startup.enableAutoStartup" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Auto-arranque con Windows</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.startup.startMinimized" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Iniciar minimizado</span>
                            </label>
                        </div>
                    </fieldset>

                    <!-- Updates -->
                    <fieldset class="border border-gray-200 rounded-xl p-4">
                        <legend class="text-xs font-bold text-dark px-2 uppercase tracking-wider">📦 Updates</legend>
                        <div class="flex items-center gap-6 mt-2 mb-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.updates.enableAutoUpdate" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Auto-actualizar</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.updates.autoDownload" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Auto-descargar</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.updates.allowBetaVersions" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Versiones beta</span>
                            </label>
                        </div>
                        <div class="w-48">
                            <label class="block text-[10px] text-muted mb-1">Check Interval (min)</label>
                            <input type="number" x-model.number="editData.updates.checkIntervalMinutes" min="1"
                                   class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-corp-800/20 outline-none">
                        </div>
                    </fieldset>

                    <!-- Blocking -->
                    <fieldset class="border border-gray-200 rounded-xl p-4">
                        <legend class="text-xs font-bold text-dark px-2 uppercase tracking-wider">🔒 Bloqueo</legend>
                        <div class="flex items-center gap-6 mt-2 mb-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.blocking.enableDeviceLock" class="rounded border-gray-300 text-accent-500 focus:ring-accent-500">
                                <span class="text-xs text-dark font-semibold">Bloquear equipo</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" x-model="editData.blocking.allowUnlockWithPin" class="rounded border-gray-300 text-corp-800 focus:ring-corp-800">
                                <span class="text-xs text-dark">Permitir desbloqueo con PIN</span>
                            </label>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-[10px] text-muted mb-1">PIN de desbloqueo</label>
                                <input type="text" x-model="editData.blocking.unlockPin"
                                       class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-sm font-mono focus:ring-2 focus:ring-corp-800/20 outline-none"
                                       placeholder="null">
                            </div>
                            <div>
                                <label class="block text-[10px] text-muted mb-1">Mensaje de bloqueo</label>
                                <textarea x-model="editData.blocking.lockMessage" rows="2"
                                          class="w-full px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs focus:ring-2 focus:ring-corp-800/20 outline-none resize-y"></textarea>
                            </div>
                        </div>
                    </fieldset>
                </div>

                <!-- JSON Raw Editor -->
                <div x-show="editorTab === 'json'">
                    <textarea name="policy_json" x-ref="jsonTextarea" x-model="editJsonRaw" rows="30"
                              class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs font-mono focus:ring-2 focus:ring-corp-800/20 focus:border-corp-800 outline-none resize-y"
                              @focus="syncToJsonRaw()"></textarea>
                    <p class="text-[10px] text-muted mt-1">Edita el JSON directamente. Los cambios aquí sobrescriben el editor visual.</p>
                </div>

                <!-- Hidden input that submits the actual JSON -->
                <input type="hidden" name="policy_json" :value="getFinalJson()">
            </div>

            <div class="sticky bottom-0 bg-white border-t border-gray-100 px-6 py-4 rounded-b-2xl flex items-center justify-end gap-3 z-10">
                <button type="button" @click="showEditor = false" class="px-4 py-2 text-sm text-muted hover:text-dark transition-colors">Cancelar</button>
                <button type="submit" class="px-5 py-2 bg-corp-800 text-white rounded-xl text-sm font-medium hover:bg-corp-900 transition-colors shadow-sm">
                    Guardar Cambios
                </button>
            </div>
        </form>
    </div>
</div>
</template>

</div><!-- end x-data -->

<script>
function policiesPage() {
    return {
        filter: 'all',
        showCreate: false,
        showEditor: false,
        createScope: 'global',
        createJson: '',
        editId: null,
        editPriority: 100,
        editData: {},
        editJsonRaw: '',
        editorTab: 'visual',
        callProcessKeywordsStr: '',
        callTitleKeywordsStr: '',

        defaultPolicy() {
            return {
                apiBaseUrl: 'https://one.azclegal.com/keeper/public/index.php/api/',
                timers: {
                    handshakeIntervalMinutes: 5,
                    offlineQueueRetrySeconds: 120,
                    activityFlushIntervalSeconds: 60
                },
                logging: {
                    globalLevel: 'Error',
                    discordWebhookUrl: '',
                    enableFileLogging: true,
                    clientOverrideLevel: 'Error',
                    enableDiscordLogging: false
                },
                modules: {
                    enableBlocking: true,
                    callTitleKeywords: ['meeting', 'call', 'reunión', 'llamada'],
                    enableDebugWindow: false,
                    countCallsAsActive: true,
                    enableCallTracking: true,
                    callProcessKeywords: ['zoom', 'teams', 'skype', 'meet', 'webex'],
                    enableUpdateManager: true,
                    enableWindowTracking: true,
                    enableProcessTracking: true,
                    enableActivityTracking: true,
                    activityIntervalSeconds: 30,
                    callActiveMaxIdleSeconds: 1800,
                    windowTrackingIntervalSeconds: 30,
                    activityInactivityThresholdSeconds: 900
                },
                startup: {
                    startMinimized: false,
                    enableAutoStartup: true
                },
                updates: {
                    autoDownload: true,
                    enableAutoUpdate: true,
                    allowBetaVersions: false,
                    checkIntervalMinutes: 360
                },
                blocking: {
                    unlockPin: null,
                    lockMessage: 'Este equipo ha sido bloqueado por Seguridad.\nPor Favor Contacta a tu jefe inmediato o Director de IT.',
                    enableDeviceLock: false,
                    allowUnlockWithPin: false
                }
            };
        },

        openEditor(id, jsonObj, priority) {
            this.editId = id;
            this.editPriority = priority;
            // Deep clone to avoid reference issues
            const base = this.defaultPolicy();
            this.editData = this.deepMerge(base, JSON.parse(JSON.stringify(jsonObj)));
            // Ensure nested objects
            this.editData.timers   = this.editData.timers   || base.timers;
            this.editData.logging  = this.editData.logging  || base.logging;
            this.editData.modules  = this.editData.modules  || base.modules;
            this.editData.startup  = this.editData.startup  || base.startup;
            this.editData.updates  = this.editData.updates  || base.updates;
            this.editData.blocking = this.editData.blocking || base.blocking;
            // Keyword strings
            this.callProcessKeywordsStr = (this.editData.modules.callProcessKeywords || []).join(', ');
            this.callTitleKeywordsStr = (this.editData.modules.callTitleKeywords || []).join(', ');
            this.editJsonRaw = JSON.stringify(this.editData, null, 2);
            this.editorTab = 'visual';
            this.showEditor = true;
        },

        duplicatePolicy(id, scope, jsonObj, priority) {
            this.createScope = scope;
            this.createJson = JSON.stringify(jsonObj, null, 2);
            this.showCreate = true;
        },

        syncToJsonRaw() {
            this.editJsonRaw = JSON.stringify(this.buildEditJson(), null, 2);
        },

        buildEditJson() {
            const d = JSON.parse(JSON.stringify(this.editData));
            // Sync keyword arrays
            d.modules.callProcessKeywords = this.callProcessKeywordsStr.split(',').map(s => s.trim()).filter(Boolean);
            d.modules.callTitleKeywords = this.callTitleKeywordsStr.split(',').map(s => s.trim()).filter(Boolean);
            // Normalize unlockPin
            if (!d.blocking.unlockPin || d.blocking.unlockPin === 'null') d.blocking.unlockPin = null;
            return d;
        },

        getFinalJson() {
            if (this.editorTab === 'json') {
                return this.editJsonRaw;
            }
            return JSON.stringify(this.buildEditJson());
        },

        syncJsonBeforeSubmit(event) {
            // Already handled by getFinalJson via :value binding
            return true;
        },

        deepMerge(target, source) {
            const out = Object.assign({}, target);
            for (const key of Object.keys(source)) {
                if (source[key] && typeof source[key] === 'object' && !Array.isArray(source[key])
                    && target[key] && typeof target[key] === 'object' && !Array.isArray(target[key])) {
                    out[key] = this.deepMerge(target[key], source[key]);
                } else {
                    out[key] = source[key];
                }
            }
            return out;
        }
    };
}
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
