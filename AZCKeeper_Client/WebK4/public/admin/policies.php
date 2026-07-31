<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'policies')) { header('Location: ' . panelLanding($adminUser)); exit; }
$adminId = (int)$adminUser['admin_id'];

$modules = $pdo->query("SELECT code,label,category FROM keeper_module WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$flag = fn(string $code) => 'enable' . ucfirst($code);

// ---- Acciones ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_global') {
        $policy = [];
        foreach ($modules as $m) $policy[$flag($m['code'])] = isset($_POST['mod'][$m['code']]);
        $json = json_encode($policy, JSON_UNESCAPED_UNICODE);
        $cur = $pdo->query("SELECT id FROM keeper_policy_assignments WHERE scope='global' AND is_active=1 ORDER BY priority DESC, id DESC LIMIT 1")->fetchColumn();
        if ($cur) $pdo->prepare("UPDATE keeper_policy_assignments SET policy_json=:p, version=version+1, updated_by=:by WHERE id=:id")->execute([':p'=>$json,':by'=>$adminId,':id'=>$cur]);
        else      $pdo->prepare("INSERT INTO keeper_policy_assignments (scope,version,is_active,policy_json,updated_by) VALUES ('global',1,1,:p,:by)")->execute([':p'=>$json,':by'=>$adminId]);
        try { AuditRepo::log($pdo,$adminId,null,null,'admin','policy_global_saved','Política global actualizada',$policy); } catch(\Throwable $e){}
    } elseif ($action === 'add_user_override') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $policy = [];
        foreach ($modules as $m) { $v = $_POST['mod'][$m['code']] ?? 'inherit'; if ($v==='on') $policy[$flag($m['code'])]=true; elseif ($v==='off') $policy[$flag($m['code'])]=false; }
        if ($uid && $policy) {
            $pdo->prepare("INSERT INTO keeper_policy_assignments (scope,user_id,version,priority,is_active,policy_json,updated_by)
                           VALUES ('user',:u,1,10,1,:p,:by)")->execute([':u'=>$uid,':p'=>json_encode($policy,JSON_UNESCAPED_UNICODE),':by'=>$adminId]);
            try { AuditRepo::log($pdo,$adminId,$uid,null,'admin','policy_user_added','Override de persona',$policy); } catch(\Throwable $e){}
        }
    } elseif ($action === 'delete_override') {
        $pid = (int)($_POST['policy_id'] ?? 0);
        if ($pid) { $pdo->prepare("DELETE FROM keeper_policy_assignments WHERE id=:id AND scope='user'")->execute([':id'=>$pid]);
            try { AuditRepo::log($pdo,$adminId,null,null,'admin','policy_user_deleted','Override eliminado',['policy_id'=>$pid]); } catch(\Throwable $e){} }
    }
    header('Location: policies.php'); exit;
}

$pageTitle = 'Políticas'; $currentPage = 'policies';

// Política global vigente.
$gRow = $pdo->query("SELECT policy_json FROM keeper_policy_assignments WHERE scope='global' AND is_active=1 ORDER BY priority DESC, id DESC LIMIT 1")->fetchColumn();
$global = $gRow ? (json_decode($gRow, true) ?: []) : [];

// Overrides por persona.
$userOv = $pdo->query("
    SELECT p.id, p.user_id, p.policy_json, u.display_name, u.cc
    FROM keeper_policy_assignments p INNER JOIN keeper_users u ON u.id = p.user_id
    WHERE p.scope='user' AND p.is_active=1 ORDER BY u.display_name")->fetchAll(PDO::FETCH_ASSOC);

$people = $pdo->query("SELECT id, display_name, cc FROM keeper_users WHERE status='active' ORDER BY display_name IS NULL, display_name, cc LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

require __DIR__ . '/partials/layout_header.php';
?>
<p class="text-sm text-muted mb-6">La política dice qué módulos están <b>encendidos</b> operativamente. El servidor la recorta contra el <a href="tiers.php" class="text-corp-800">tier</a> de la firma antes de enviarla: si el tier no incluye un módulo, no llega aunque aquí esté encendido.</p>

<!-- Política global -->
<form method="post" class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-6"><?= csrf_field() ?>
  <input type="hidden" name="action" value="save_global">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <h2 class="text-sm font-semibold text-dark">Política global (base para todos)</h2>
    <button class="px-4 py-1.5 bg-corp-800 hover:bg-corp-900 text-white text-xs font-medium rounded-lg">Guardar</button>
  </div>
  <div class="p-5 grid sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php foreach ($modules as $m): $on = !empty($global[$flag($m['code'])]); ?>
      <label class="flex items-center justify-between gap-3 px-3 py-2 rounded-lg border border-gray-100 hover:bg-gray-50 cursor-pointer">
        <span class="text-sm text-gray-700"><?= htmlspecialchars($m['label']) ?></span>
        <input type="checkbox" name="mod[<?= htmlspecialchars($m['code']) ?>]" <?= $on?'checked':'' ?> class="w-4 h-4 accent-corp-800">
      </label>
    <?php endforeach; ?>
  </div>
</form>

<!-- Overrides por persona -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden" x-data="{add:false}">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <h2 class="text-sm font-semibold text-dark">Excepciones por persona</h2>
    <button type="button" @click="add=!add" class="text-xs text-corp-800 hover:text-corp-600 font-medium">+ Agregar excepción</button>
  </div>

  <form method="post" class="px-5 py-4 border-b border-gray-100 bg-gray-50" x-show="add" style="display:none"><?= csrf_field() ?>
    <input type="hidden" name="action" value="add_user_override">
    <div class="flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs font-medium text-gray-600 mb-1">Persona</label>
        <select name="user_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm bg-white min-w-[14rem]">
          <?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars($p['display_name'] ?: ('CC '.$p['cc'])) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="flex-1 grid sm:grid-cols-2 lg:grid-cols-3 gap-2">
        <?php foreach ($modules as $m): ?>
          <div class="flex items-center justify-between gap-2 text-xs">
            <span class="text-gray-600 truncate"><?= htmlspecialchars($m['label']) ?></span>
            <select name="mod[<?= htmlspecialchars($m['code']) ?>]" class="border border-gray-200 rounded px-1 py-0.5 flex-none">
              <option value="inherit">Heredar</option><option value="on">Forzar ON</option><option value="off">Forzar OFF</option>
            </select>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="px-4 py-2 bg-corp-800 hover:bg-corp-900 text-white text-xs font-medium rounded-lg flex-none">Guardar excepción</button>
    </div>
  </form>

  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Persona</th>
        <th class="text-left font-semibold px-5 py-2.5">Excepciones</th>
        <th class="text-right font-semibold px-5 py-2.5"></th>
      </tr></thead>
      <tbody>
      <?php if(!$userOv): ?><tr><td colspan="3" class="px-5 py-8 text-center text-muted">Sin excepciones por persona. Todos siguen la política global.</td></tr><?php endif; ?>
      <?php foreach ($userOv as $o): $p = json_decode($o['policy_json'], true) ?: []; ?>
        <tr class="border-b border-gray-100 last:border-0">
          <td class="px-5 py-3 text-dark"><?= htmlspecialchars($o['display_name'] ?: ('CC '.$o['cc'])) ?></td>
          <td class="px-5 py-3">
            <div class="flex flex-wrap gap-1">
              <?php foreach ($p as $k=>$v): $mc = lcfirst(substr($k,6)); ?>
                <span class="text-[11px] px-1.5 py-0.5 rounded <?= $v ? 'bg-emerald-50 text-emerald-700' : 'bg-accent-500/10 text-accent-500' ?>"><?= htmlspecialchars($mc) ?>: <?= $v?'ON':'OFF' ?></span>
              <?php endforeach; ?>
            </div>
          </td>
          <td class="px-5 py-3 text-right">
            <form method="post" onsubmit="return confirm('¿Eliminar esta excepción?')"><?= csrf_field() ?>
              <input type="hidden" name="action" value="delete_override"><input type="hidden" name="policy_id" value="<?= (int)$o['id'] ?>">
              <button class="text-xs text-gray-400 hover:text-accent-500">Eliminar</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/partials/layout_footer.php'; ?>
