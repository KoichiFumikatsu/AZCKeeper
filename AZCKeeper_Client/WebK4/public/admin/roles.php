<?php
/**
 * Roles del panel y cuentas de administración.
 *
 * Hasta la rebanada 4 los permisos vivían en un mapa dentro de admin_auth_helpers.php:
 * cambiar quién ve qué exigía editar código y redesplegar. Aquí la matriz rol × módulo se
 * edita y se guarda en keeper_panel_roles.permissions_json, que es lo que panelCan() lee.
 *
 * Rieles de seguridad (el modo de fallo de un RBAC editable es quedarse sin quien administre):
 *  - Los roles de sistema (superadmin) no se editan ni se borran: conservan {"all": true}.
 *  - Nadie cambia su propio rol ni se desactiva a sí mismo.
 *  - No se borra un rol que alguna cuenta esté usando.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'roles')) { header('Location: ' . panelLanding($adminUser)); exit; }

$adminId = (int)$adminUser['admin_id'];
$modules = panelModules();
$flash   = null;

/** Lee permissions_json de un rol como lista de módulos (o null si es un rol 'all'). */
function roleModules(PDO $pdo, string $code): ?array {
    $st = $pdo->prepare("SELECT permissions_json FROM keeper_panel_roles WHERE role_code = :c");
    $st->execute([':c' => $code]);
    $p = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($p) || !empty($p['all'])) return null;
    return isset($p['modules']) && is_array($p['modules']) ? array_values(array_filter($p['modules'], 'is_string')) : [];
}

// ---- Acciones (POST) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_module') {
        $code = (string)($_POST['role_code'] ?? '');
        $mod  = (string)($_POST['module'] ?? '');
        $on   = ($_POST['on'] ?? '') === '1';
        $st = $pdo->prepare("SELECT is_system FROM keeper_panel_roles WHERE role_code = :c");
        $st->execute([':c' => $code]);
        $isSystem = $st->fetchColumn();
        if ($isSystem === false) {
            $flash = ['err', 'Ese rol ya no existe.'];
        } elseif ((int)$isSystem === 1) {
            $flash = ['err', 'Los roles de sistema no se editan.'];
        } elseif (!isset($modules[$mod])) {
            $flash = ['err', 'Módulo desconocido.'];
        } else {
            $cur = roleModules($pdo, $code) ?? [];
            $new = $on ? array_values(array_unique(array_merge($cur, [$mod])))
                       : array_values(array_diff($cur, [$mod]));
            $pdo->prepare("UPDATE keeper_panel_roles SET permissions_json = :p, updated_by = :by WHERE role_code = :c")
                ->execute([':p' => json_encode(['modules' => $new], JSON_UNESCAPED_UNICODE), ':by' => $adminId, ':c' => $code]);
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'panel_role_module',
                    "Rol {$code}: módulo {$mod} " . ($on ? 'concedido' : 'retirado'),
                    ['role' => $code, 'module' => $mod, 'on' => $on ? 1 : 0]); } catch (\Throwable $e) {}
        }

    } elseif ($action === 'add_role') {
        $code  = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($_POST['code'] ?? '')));
        $label = trim((string)($_POST['label'] ?? ''));
        if ($code === '' || $label === '') {
            $flash = ['err', 'El rol necesita código y nombre.'];
        } else {
            try {
                $pdo->prepare("INSERT INTO keeper_panel_roles (role_code, label, permissions_json, is_system, updated_by)
                               VALUES (:c, :l, JSON_OBJECT('modules', JSON_ARRAY()), 0, :by)")
                    ->execute([':c' => $code, ':l' => $label, ':by' => $adminId]);
                $flash = ['ok', "Rol {$code} creado sin permisos: márcale los módulos en la matriz."];
                try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'panel_role_add', "Rol {$code} creado"); } catch (\Throwable $e) {}
            } catch (\PDOException $e) {
                $flash = ['err', "Ya existe un rol con el código {$code}."];
            }
        }

    } elseif ($action === 'delete_role') {
        $code = (string)($_POST['role_code'] ?? '');
        $st = $pdo->prepare("SELECT is_system FROM keeper_panel_roles WHERE role_code = :c");
        $st->execute([':c' => $code]);
        $isSystem = $st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM keeper_admin_accounts WHERE panel_role = :c");
        $st->execute([':c' => $code]);
        $inUse = (int)$st->fetchColumn();
        if ($isSystem === false)        $flash = ['err', 'Ese rol ya no existe.'];
        elseif ((int)$isSystem === 1)   $flash = ['err', 'Un rol de sistema no se borra.'];
        elseif ($inUse > 0)             $flash = ['err', "El rol {$code} lo usan {$inUse} cuenta(s): reasígnalas primero."];
        else {
            $pdo->prepare("DELETE FROM keeper_panel_roles WHERE role_code = :c")->execute([':c' => $code]);
            $flash = ['ok', "Rol {$code} eliminado."];
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'panel_role_delete', "Rol {$code} eliminado"); } catch (\Throwable $e) {}
        }

    } elseif ($action === 'add_account') {
        $email = trim((string)($_POST['email'] ?? ''));
        $name  = trim((string)($_POST['display_name'] ?? ''));
        $role  = (string)($_POST['panel_role'] ?? '');
        $pass  = (string)($_POST['password'] ?? '');
        $firma = ($_POST['firma_scope_id'] ?? '') !== '' ? (int)$_POST['firma_scope_id'] : null;
        $st = $pdo->prepare("SELECT 1 FROM keeper_panel_roles WHERE role_code = :c");
        $st->execute([':c' => $role]);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))       $flash = ['err', 'Correo inválido.'];
        elseif (!$st->fetchColumn())                          $flash = ['err', 'Rol desconocido.'];
        elseif (strlen($pass) < 8)                            $flash = ['err', 'La contraseña necesita al menos 8 caracteres.'];
        else {
            try {
                $pdo->prepare("INSERT INTO keeper_admin_accounts (email, display_name, password_hash, panel_role, firma_scope_id, created_by)
                               VALUES (:e, :n, :h, :r, :f, :by)")
                    ->execute([':e' => $email, ':n' => $name !== '' ? $name : null,
                               ':h' => password_hash($pass, PASSWORD_DEFAULT), ':r' => $role,
                               ':f' => $firma, ':by' => $adminId]);
                $flash = ['ok', "Cuenta {$email} creada."];
                try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'admin_account_add',
                        "Cuenta {$email} creada con rol {$role}", ['role' => $role, 'firma_scope_id' => $firma]); } catch (\Throwable $e) {}
            } catch (\PDOException $e) {
                $flash = ['err', "Ya existe una cuenta con el correo {$email}."];
            }
        }

    } elseif ($action === 'update_account') {
        $id    = (int)($_POST['id'] ?? 0);
        $role  = (string)($_POST['panel_role'] ?? '');
        $firma = ($_POST['firma_scope_id'] ?? '') !== '' ? (int)$_POST['firma_scope_id'] : null;
        $st = $pdo->prepare("SELECT 1 FROM keeper_panel_roles WHERE role_code = :c");
        $st->execute([':c' => $role]);
        if ($id === $adminId)        $flash = ['err', 'No puedes cambiar tu propio rol ni tu alcance.'];
        elseif (!$st->fetchColumn()) $flash = ['err', 'Rol desconocido.'];
        else {
            $pdo->prepare("UPDATE keeper_admin_accounts SET panel_role = :r, firma_scope_id = :f WHERE id = :id")
                ->execute([':r' => $role, ':f' => $firma, ':id' => $id]);
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'admin_account_update',
                    "Cuenta id {$id} -> rol {$role}", ['id' => $id, 'role' => $role, 'firma_scope_id' => $firma]); } catch (\Throwable $e) {}
        }

    } elseif ($action === 'toggle_account') {
        $id = (int)($_POST['id'] ?? 0);
        $on = ($_POST['on'] ?? '') === '1';
        if ($id === $adminId) {
            $flash = ['err', 'No puedes desactivar tu propia cuenta.'];
        } else {
            $pdo->prepare("UPDATE keeper_admin_accounts SET is_active = :a WHERE id = :id")
                ->execute([':a' => $on ? 1 : 0, ':id' => $id]);
            // Desactivar sin cortar sesiones dejaría a la cuenta dentro hasta que expire la cookie.
            if (!$on) $pdo->prepare("UPDATE keeper_admin_sessions SET revoked_at = UTC_TIMESTAMP()
                                     WHERE admin_id = :id AND revoked_at IS NULL")->execute([':id' => $id]);
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'admin_account_toggle',
                    "Cuenta id {$id} " . ($on ? 'activada' : 'desactivada (sesiones revocadas)')); } catch (\Throwable $e) {}
        }

    } elseif ($action === 'reset_password') {
        $id   = (int)($_POST['id'] ?? 0);
        $pass = (string)($_POST['password'] ?? '');
        if (strlen($pass) < 8) {
            $flash = ['err', 'La contraseña necesita al menos 8 caracteres.'];
        } else {
            $pdo->prepare("UPDATE keeper_admin_accounts SET password_hash = :h WHERE id = :id")
                ->execute([':h' => password_hash($pass, PASSWORD_DEFAULT), ':id' => $id]);
            $pdo->prepare("UPDATE keeper_admin_sessions SET revoked_at = UTC_TIMESTAMP()
                           WHERE admin_id = :id AND revoked_at IS NULL")->execute([':id' => $id]);
            $flash = ['ok', 'Contraseña cambiada; las sesiones abiertas de esa cuenta se cerraron.'];
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'admin_password_reset',
                    "Contraseña restablecida (cuenta id {$id})"); } catch (\Throwable $e) {}
        }
    }
}

$pageTitle = 'Roles y cuentas'; $currentPage = 'roles';

$roles = $pdo->query("SELECT role_code, label, permissions_json, is_system FROM keeper_panel_roles ORDER BY is_system DESC, role_code")->fetchAll(PDO::FETCH_ASSOC);
$rolePerms = [];
foreach ($roles as $r) {
    $p = json_decode((string)$r['permissions_json'], true);
    $rolePerms[$r['role_code']] = (is_array($p) && !empty($p['all']))
        ? null                                   // null = todo
        : (isset($p['modules']) && is_array($p['modules']) ? $p['modules'] : []);
}

$accounts = $pdo->query("
    SELECT a.id, a.email, a.display_name, a.panel_role, a.firma_scope_id, a.is_active, a.created_at,
           f.nombre AS firma,
           (SELECT COUNT(*) FROM keeper_admin_sessions s
             WHERE s.admin_id = a.id AND s.revoked_at IS NULL
               AND (s.expires_at IS NULL OR s.expires_at > UTC_TIMESTAMP())) AS live_sessions
    FROM keeper_admin_accounts a
    LEFT JOIN keeper_firmas f ON f.id = a.firma_scope_id
    ORDER BY a.is_active DESC, a.email")->fetchAll(PDO::FETCH_ASSOC);

$firmas = $pdo->query("SELECT id, nombre FROM keeper_firmas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$usage  = [];
foreach ($accounts as $a) $usage[$a['panel_role']] = ($usage[$a['panel_role']] ?? 0) + 1;

require __DIR__ . '/partials/layout_header.php';
?>

<?php if ($flash): [$kind, $msg] = $flash; ?>
  <div class="mb-5 text-sm border rounded-lg px-4 py-2.5 <?= $kind === 'ok' ? 'text-emerald-700 bg-emerald-50 border-emerald-200' : 'text-accent-600 bg-accent-500/5 border-accent-500/20' ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Matriz rol × módulo -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-6">
  <div class="px-5 py-4 border-b border-gray-100 flex flex-wrap items-center justify-between gap-3">
    <div>
      <h2 class="text-sm font-semibold text-dark">Qué ve cada rol</h2>
      <p class="text-xs text-muted mt-0.5">Se aplica al siguiente clic: el panel lee esta tabla en cada carga.</p>
    </div>
    <form method="post" class="flex items-center gap-2"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_role">
      <input name="code" placeholder="codigo" pattern="[a-z0-9_-]+" class="px-2 py-1 border border-gray-200 rounded text-xs w-24">
      <input name="label" placeholder="Nombre del rol" class="px-2 py-1 border border-gray-200 rounded text-xs w-36">
      <button class="text-xs px-2 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">+ Rol</button>
    </form>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Módulo</th>
        <?php foreach ($roles as $r): ?>
          <th class="text-center font-semibold px-4 py-2.5 whitespace-nowrap">
            <?= htmlspecialchars($r['label']) ?>
            <div class="font-normal normal-case text-[10px] text-muted mt-0.5">
              <?= (int)($usage[$r['role_code']] ?? 0) ?> cuenta(s)
              <?php if ((int)$r['is_system']): ?> · sistema<?php endif; ?>
            </div>
          </th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($modules as $code => $label): ?>
        <tr class="border-b border-gray-100 last:border-0">
          <td class="px-5 py-2.5 text-gray-700"><?= htmlspecialchars($label) ?>
            <span class="text-[10px] text-muted font-mono ml-1"><?= htmlspecialchars($code) ?></span>
          </td>
          <?php foreach ($roles as $r):
            $perms = $rolePerms[$r['role_code']];
            $all   = $perms === null;
            $on    = $all || in_array($code, $perms, true);
          ?>
            <td class="px-4 py-2.5 text-center">
              <?php if ($all || (int)$r['is_system']): ?>
                <span class="inline-flex w-6 h-6 rounded bg-emerald-100 text-emerald-600 items-center justify-center" title="Rol de sistema: acceso total, no editable">✓</span>
              <?php else: ?>
                <form method="post" class="inline"><?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle_module">
                  <input type="hidden" name="role_code" value="<?= htmlspecialchars($r['role_code']) ?>">
                  <input type="hidden" name="module" value="<?= htmlspecialchars($code) ?>">
                  <input type="hidden" name="on" value="<?= $on ? '0' : '1' ?>">
                  <button class="w-6 h-6 rounded <?= $on ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-300 hover:bg-gray-200' ?>"
                          title="<?= $on ? 'Quitar acceso' : 'Dar acceso' ?>"><?= $on ? '✓' : '' ?></button>
                </form>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <tr class="bg-gray-50">
        <td class="px-5 py-2.5 text-xs text-muted">Eliminar rol</td>
        <?php foreach ($roles as $r): ?>
          <td class="px-4 py-2.5 text-center">
            <?php if (!(int)$r['is_system'] && (int)($usage[$r['role_code']] ?? 0) === 0): ?>
              <form method="post" class="inline" onsubmit="return confirm('¿Eliminar el rol <?= htmlspecialchars($r['role_code']) ?><?= csrf_field() ?>?')">
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="role_code" value="<?= htmlspecialchars($r['role_code']) ?>">
                <button class="text-xs text-muted hover:text-accent-500">eliminar</button>
              </form>
            <?php else: ?><span class="text-xs text-gray-300">—</span><?php endif; ?>
          </td>
        <?php endforeach; ?>
      </tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Cuentas -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100"><h2 class="text-sm font-semibold text-dark">Cuentas de administración</h2></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Cuenta</th>
        <th class="text-left font-semibold px-5 py-2.5">Rol y alcance</th>
        <th class="text-left font-semibold px-5 py-2.5">Sesiones</th>
        <th class="text-left font-semibold px-5 py-2.5">Estado</th>
        <th class="text-right font-semibold px-5 py-2.5">Acciones</th>
      </tr></thead>
      <tbody>
      <?php foreach ($accounts as $a): $self = (int)$a['id'] === $adminId; ?>
        <tr class="border-b border-gray-100 last:border-0 align-top" x-data="{pw:false}">
          <td class="px-5 py-3">
            <div class="font-medium text-dark"><?= htmlspecialchars($a['display_name'] ?: $a['email']) ?><?php if ($self): ?><span class="ml-1.5 text-[10px] text-muted">(tú)</span><?php endif; ?></div>
            <div class="text-xs text-muted"><?= htmlspecialchars($a['email']) ?></div>
          </td>
          <td class="px-5 py-3">
            <?php if ($self): ?>
              <div class="text-gray-700"><?= htmlspecialchars($a['panel_role']) ?></div>
              <div class="text-xs text-muted"><?= htmlspecialchars($a['firma'] ?: 'Todas las firmas') ?></div>
            <?php else: ?>
              <form method="post" class="flex flex-wrap items-center gap-1.5"><?= csrf_field() ?>
                <input type="hidden" name="action" value="update_account">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <select name="panel_role" class="text-xs border border-gray-200 rounded px-1.5 py-1 bg-white">
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= htmlspecialchars($r['role_code']) ?>" <?= $a['panel_role'] === $r['role_code'] ? 'selected' : '' ?>><?= htmlspecialchars($r['label']) ?></option>
                  <?php endforeach; ?>
                </select>
                <select name="firma_scope_id" class="text-xs border border-gray-200 rounded px-1.5 py-1 bg-white">
                  <option value="">Todas las firmas</option>
                  <?php foreach ($firmas as $f): ?>
                    <option value="<?= (int)$f['id'] ?>" <?= (int)$a['firma_scope_id'] === (int)$f['id'] ? 'selected' : '' ?>><?= htmlspecialchars($f['nombre']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="text-xs px-2 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">Guardar</button>
              </form>
            <?php endif; ?>
          </td>
          <td class="px-5 py-3 tabular-nums text-gray-600"><?= (int)$a['live_sessions'] ?></td>
          <td class="px-5 py-3">
            <?= (int)$a['is_active']
              ? '<span class="text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activa</span>'
              : '<span class="text-xs font-medium text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">Inactiva</span>' ?>
          </td>
          <td class="px-5 py-3 text-right whitespace-nowrap">
            <button @click="pw=!pw" class="text-xs text-corp-800 hover:text-corp-600">Contraseña</button>
            <?php if (!$self): ?>
              <form method="post" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_account">
                <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                <input type="hidden" name="on" value="<?= (int)$a['is_active'] ? '0' : '1' ?>">
                <button class="text-xs px-2 py-1 border border-gray-200 rounded hover:border-gray-300 text-gray-600 ml-1"><?= (int)$a['is_active'] ? 'Desactivar' : 'Activar' ?></button>
              </form>
            <?php endif; ?>
            <form method="post" x-show="pw" style="display:none" class="mt-2 flex items-center justify-end gap-1.5"><?= csrf_field() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
              <input type="password" name="password" minlength="8" required placeholder="Nueva contraseña" class="text-xs border border-gray-200 rounded px-2 py-1 w-40">
              <button class="text-xs px-2 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">Cambiar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="px-5 py-4 border-t border-gray-100 bg-gray-50">
    <h3 class="text-xs font-semibold text-dark mb-2">Nueva cuenta</h3>
    <form method="post" class="grid sm:grid-cols-12 gap-2 items-end"><?= csrf_field() ?>
      <input type="hidden" name="action" value="add_account">
      <div class="sm:col-span-3">
        <label class="block text-[11px] text-gray-600 mb-1">Correo</label>
        <input name="email" type="email" required class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
      </div>
      <div class="sm:col-span-3">
        <label class="block text-[11px] text-gray-600 mb-1">Nombre</label>
        <input name="display_name" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
      </div>
      <div class="sm:col-span-2">
        <label class="block text-[11px] text-gray-600 mb-1">Rol</label>
        <select name="panel_role" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
          <?php foreach ($roles as $r): ?><option value="<?= htmlspecialchars($r['role_code']) ?>"><?= htmlspecialchars($r['label']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-2">
        <label class="block text-[11px] text-gray-600 mb-1">Firma</label>
        <select name="firma_scope_id" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm bg-white">
          <option value="">Todas</option>
          <?php foreach ($firmas as $f): ?><option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nombre']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="sm:col-span-1">
        <label class="block text-[11px] text-gray-600 mb-1">Clave</label>
        <input name="password" type="password" minlength="8" required class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-sm">
      </div>
      <div class="sm:col-span-1">
        <button class="w-full px-2 py-1.5 bg-corp-800 hover:bg-corp-900 text-white text-sm rounded-lg">Crear</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
