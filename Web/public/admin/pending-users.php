<?php
/**
 * Usuarios Pendientes — Solicitudes de acceso para revisión.
 *
 * Cuando un usuario intenta hacer login con una CC que no existe en legacy
 * ni en keeper_users, ClientLogin guarda la solicitud aquí.
 * El admin revisa, completa nombre/email y aprueba o rechaza.
 */
require_once __DIR__ . '/admin_auth.php';
requireModule('pending_users');

$pageTitle   = 'Usuarios Pendientes';
$currentPage = 'pending_users';
$msg         = '';
$msgType     = '';

use Keeper\Repos\PendingEnrollmentRepo;
use Keeper\Repos\AuditRepo;

/* ==================== ACCIONES POST ==================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        $id = (int)($_POST['enrollment_id'] ?? 0);
        if ($id <= 0) throw new \Exception('Solicitud inválida.');

        $adminId = (int)($adminUser['admin_id'] ?? 0);
        $notes   = trim($_POST['notes'] ?? '');

        switch ($action) {

            case 'approve':
                if (!canDo('pending_users', 'can_edit')) throw new \Exception('Sin permisos para aprobar usuarios.');
                $displayName = trim($_POST['display_name'] ?? '');
                $email       = trim($_POST['email'] ?? '') ?: null;

                $keeperUserId = PendingEnrollmentRepo::approve($pdo, $id, $adminId, $displayName, $email, $notes);
                AuditRepo::log($pdo, $keeperUserId, null, 'enrollment_approved',
                    "Solicitud #{$id} aprobada por admin #{$adminId}", [
                        'enrollment_id' => $id,
                        'admin_id'      => $adminId,
                    ]);

                $msg     = "Solicitud #{$id} aprobada. Usuario creado con ID #{$keeperUserId}.";
                $msgType = 'success';
                break;

            case 'reject':
                if (!canDo('pending_users', 'can_edit')) throw new \Exception('Sin permisos para rechazar solicitudes.');
                PendingEnrollmentRepo::reject($pdo, $id, $adminId, $notes);
                AuditRepo::log($pdo, null, null, 'enrollment_rejected',
                    "Solicitud #{$id} rechazada por admin #{$adminId}", [
                        'enrollment_id' => $id,
                        'admin_id'      => $adminId,
                    ]);

                $msg     = "Solicitud #{$id} rechazada.";
                $msgType = 'warning';
                break;

            default:
                throw new \Exception('Acción no reconocida.');
        }

        header("Location: pending-users.php?msg=" . urlencode($msg) . "&type=" . urlencode($msgType));
        exit;

    } catch (\Exception $e) {
        $msg     = $e->getMessage();
        $msgType = 'error';
    }
}

/* Flash PRG */
if (isset($_GET['msg'])) {
    $msg     = $_GET['msg'];
    $msgType = $_GET['type'] ?? 'success';
}

/* ==================== QUERIES ==================== */
$filter   = $_GET['filter'] ?? 'pending';
try {
    $allItems = PendingEnrollmentRepo::getAll($pdo);
} catch (\Throwable $e) {
    $allItems = [];
    $msg     = 'La tabla de solicitudes no existe aún. Ejecuta la migración add_enrollment_requests.sql en la BD.';
    $msgType = 'error';
}

$items = array_filter($allItems, function ($r) use ($filter) {
    return $filter === 'all' || $r['status'] === $filter;
});

$countPending  = count(array_filter($allItems, fn($r) => $r['status'] === 'pending'));
$countApproved = count(array_filter($allItems, fn($r) => $r['status'] === 'approved'));
$countRejected = count(array_filter($allItems, fn($r) => $r['status'] === 'rejected'));

require_once __DIR__ . '/partials/layout_header.php';
?>

<div x-data="pendingUsersPage()" x-cloak>

<!-- ──────── Flash message ──────── -->
<?php if ($msg): ?>
<div class="mb-6 px-4 py-3 rounded-xl text-sm font-medium flex items-center gap-2
    <?= $msgType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : '' ?>
    <?= $msgType === 'error'   ? 'bg-red-50 text-red-700 border border-red-200' : '' ?>
    <?= $msgType === 'warning' ? 'bg-amber-50 text-amber-700 border border-amber-200' : '' ?>"
    x-data="{show:true}" x-show="show" x-init="setTimeout(()=>show=false,6000)" x-transition>
    <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
    </svg>
    <?= htmlspecialchars($msg) ?>
</div>
<?php endif; ?>

<!-- ──────── Header ──────── -->
<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-6">
    <p class="text-xs sm:text-sm text-muted">
        Usuarios que intentaron iniciar sesión pero no existen en ninguna base de datos.
    </p>
    <div class="flex items-center gap-2">
        <a href="?filter=pending"
           class="text-xs px-3 py-1.5 rounded-lg border font-medium transition-colors
               <?= $filter === 'pending' ? 'bg-corp-800 text-white border-corp-800' : 'bg-white text-gray-600 border-gray-200 hover:border-corp-400' ?>">
            Pendientes
            <?php if ($countPending > 0): ?>
            <span class="ml-1 inline-flex items-center justify-center w-4 h-4 text-xs rounded-full bg-red-500 text-white"><?= $countPending ?></span>
            <?php endif; ?>
        </a>
        <a href="?filter=approved"
           class="text-xs px-3 py-1.5 rounded-lg border font-medium transition-colors
               <?= $filter === 'approved' ? 'bg-corp-800 text-white border-corp-800' : 'bg-white text-gray-600 border-gray-200 hover:border-corp-400' ?>">
            Aprobados
        </a>
        <a href="?filter=rejected"
           class="text-xs px-3 py-1.5 rounded-lg border font-medium transition-colors
               <?= $filter === 'rejected' ? 'bg-corp-800 text-white border-corp-800' : 'bg-white text-gray-600 border-gray-200 hover:border-corp-400' ?>">
            Rechazados
        </a>
        <a href="?filter=all"
           class="text-xs px-3 py-1.5 rounded-lg border font-medium transition-colors
               <?= $filter === 'all' ? 'bg-corp-800 text-white border-corp-800' : 'bg-white text-gray-600 border-gray-200 hover:border-corp-400' ?>">
            Todos
        </a>
    </div>
</div>

<!-- ──────── KPI Cards ──────── -->
<div class="grid grid-cols-3 gap-3 sm:gap-5 mb-6">
    <?php foreach ([
        ['Pendientes', $countPending,  'amber',  'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'],
        ['Aprobados',  $countApproved, 'emerald', 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
        ['Rechazados', $countRejected, 'red',    'M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z'],
    ] as [$label, $value, $color, $icon]): ?>
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-4 sm:p-5 flex items-center gap-3 sm:gap-4">
        <div class="p-2 sm:p-3 bg-<?= $color ?>-50 rounded-xl shrink-0">
            <svg class="w-5 h-5 text-<?= $color ?>-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="<?= $icon ?>"/>
            </svg>
        </div>
        <div>
            <p class="text-xs text-muted"><?= $label ?></p>
            <p class="text-xl sm:text-2xl font-bold text-gray-800"><?= $value ?></p>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ──────── Tabla ──────── -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <?php if (empty($items)): ?>
    <div class="py-16 text-center text-muted text-sm">
        <svg class="mx-auto mb-3 w-10 h-10 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        No hay solicitudes <?= $filter !== 'all' ? htmlspecialchars($filter === 'pending' ? 'pendientes' : ($filter === 'approved' ? 'aprobadas' : 'rechazadas')) : '' ?>.
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wide">CC</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wide">Dispositivo</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wide">IP</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wide">Fecha</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-muted uppercase tracking-wide">Estado</th>
                    <?php if ($filter === 'pending' || $filter === 'all'): ?>
                    <th class="px-4 py-3 text-right text-xs font-semibold text-muted uppercase tracking-wide">Acciones</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                <?php foreach ($items as $req): ?>
                <?php
                    $statusClasses = [
                        'pending'  => 'bg-amber-100 text-amber-700',
                        'approved' => 'bg-emerald-100 text-emerald-700',
                        'rejected' => 'bg-red-100 text-red-700',
                    ];
                    $statusLabels = [
                        'pending'  => 'Pendiente',
                        'approved' => 'Aprobado',
                        'rejected' => 'Rechazado',
                    ];
                ?>
                <tr class="hover:bg-gray-50/50 transition-colors">
                    <td class="px-4 py-3 font-mono font-semibold text-gray-800">
                        <?= htmlspecialchars($req['cc']) ?>
                    </td>
                    <td class="px-4 py-3 text-gray-600 max-w-[180px] truncate" title="<?= htmlspecialchars($req['device_name'] ?? '') ?>">
                        <?= htmlspecialchars($req['device_name'] ?? '—') ?>
                        <?php if ($req['device_guid']): ?>
                        <span class="block text-xs text-muted font-mono truncate"><?= htmlspecialchars(substr($req['device_guid'], 0, 18)) ?>…</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-gray-500 font-mono text-xs"><?= htmlspecialchars($req['attempted_ip'] ?? '—') ?></td>
                    <td class="px-4 py-3 text-gray-500 text-xs whitespace-nowrap">
                        <?= date('d M Y H:i', strtotime($req['created_at'])) ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium
                            <?= $statusClasses[$req['status']] ?? '' ?>">
                            <?= $statusLabels[$req['status']] ?? $req['status'] ?>
                        </span>
                        <?php if ($req['notes']): ?>
                        <p class="mt-1 text-xs text-muted italic"><?= htmlspecialchars($req['notes']) ?></p>
                        <?php endif; ?>
                    </td>
                    <?php if ($filter === 'pending' || $filter === 'all'): ?>
                    <td class="px-4 py-3 text-right">
                        <?php if ($req['status'] === 'pending'): ?>
                        <button
                            @click="openApprove(<?= htmlspecialchars(json_encode($req)) ?>)"
                            class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 text-white rounded-lg text-xs font-medium hover:bg-emerald-700 transition-colors mr-1">
                            Aprobar
                        </button>
                        <button
                            @click="openReject(<?= (int)$req['id'] ?>, '<?= htmlspecialchars($req['cc']) ?>')"
                            class="inline-flex items-center gap-1 px-3 py-1.5 bg-red-100 text-red-700 rounded-lg text-xs font-medium hover:bg-red-200 transition-colors">
                            Rechazar
                        </button>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ──────── Modal Aprobar ──────── -->
<div x-show="approveModal" x-cloak
     class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
     @click.self="approveModal=false">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md" @click.stop>
        <div class="p-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Aprobar solicitud</h3>
            <button @click="approveModal=false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="enrollment_id" :value="current.id">

            <div>
                <p class="text-xs text-muted mb-1">CC</p>
                <p class="font-mono font-semibold text-gray-800" x-text="current.cc"></p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    Nombre a mostrar <span class="text-muted">(opcional — si vacío usa la CC)</span>
                </label>
                <input type="text" name="display_name"
                       :placeholder="current.cc"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">
                    Email <span class="text-muted">(opcional)</span>
                </label>
                <input type="email" name="email"
                       class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none">
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Notas</label>
                <textarea name="notes" rows="2"
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none resize-none"
                          placeholder="Ej: empleado nuevo área jurídica"></textarea>
            </div>

            <p class="text-xs text-muted bg-amber-50 border border-amber-100 rounded-xl p-3">
                El usuario podrá hacer login inmediatamente con la contraseña que usó al intentar acceder.
            </p>

            <div class="flex gap-3 pt-1">
                <button type="button" @click="approveModal=false"
                        class="flex-1 px-4 py-2 text-sm border border-gray-200 rounded-xl text-gray-600 hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2 text-sm bg-emerald-600 text-white rounded-xl font-medium hover:bg-emerald-700 transition-colors">
                    Aprobar acceso
                </button>
            </div>
        </form>
    </div>
</div>

<!-- ──────── Modal Rechazar ──────── -->
<div x-show="rejectModal" x-cloak
     class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4"
     @click.self="rejectModal=false">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm" @click.stop>
        <div class="p-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="font-semibold text-gray-800">Rechazar solicitud</h3>
            <button @click="rejectModal=false" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <form method="POST" class="p-5 space-y-4">
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="enrollment_id" :value="rejectId">

            <p class="text-sm text-gray-700">
                ¿Rechazar la solicitud de <strong x-text="rejectCc" class="font-mono"></strong>?
            </p>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Motivo <span class="text-muted">(opcional)</span></label>
                <textarea name="notes" rows="2"
                          class="w-full px-3 py-2 text-sm border border-gray-200 rounded-xl focus:ring-2 focus:ring-corp-200 focus:border-corp-400 outline-none resize-none"
                          placeholder="Ej: usuario no registrado en la empresa"></textarea>
            </div>

            <div class="flex gap-3">
                <button type="button" @click="rejectModal=false"
                        class="flex-1 px-4 py-2 text-sm border border-gray-200 rounded-xl text-gray-600 hover:bg-gray-50 transition-colors">
                    Cancelar
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2 text-sm bg-red-600 text-white rounded-xl font-medium hover:bg-red-700 transition-colors">
                    Rechazar
                </button>
            </div>
        </form>
    </div>
</div>

</div><!-- /x-data -->

<script>
function pendingUsersPage() {
    return {
        approveModal: false,
        rejectModal: false,
        current: {},
        rejectId: null,
        rejectCc: '',

        openApprove(req) {
            this.current = req;
            this.approveModal = true;
        },
        openReject(id, cc) {
            this.rejectId = id;
            this.rejectCc = cc;
            this.rejectModal = true;
        },
    };
}
</script>

<?php require_once __DIR__ . '/partials/layout_footer.php'; ?>
