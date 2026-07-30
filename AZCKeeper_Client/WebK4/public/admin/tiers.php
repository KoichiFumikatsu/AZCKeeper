<?php
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Services/TierResolver.php';
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Services\TierResolver;
use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'tiers')) { header('Location: ' . panelLanding($adminUser)); exit; }
$adminId = (int)$adminUser['admin_id'];
$aud = function($type,$msg,$meta=null) use ($pdo,$adminId){ try{ AuditRepo::log($pdo,$adminId,null,null,'admin',$type,$msg,$meta);}catch(\Throwable $e){} };

// ---- Acciones (POST) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'set_global') {
        $on = ($_POST['enabled'] ?? '') === '1' ? '1' : '0';
        $pdo->prepare("INSERT INTO keeper_panel_settings (setting_key, setting_value, updated_by)
                       VALUES ('tier_enforcement_enabled', :v, :by)
                       ON DUPLICATE KEY UPDATE setting_value = :v2, updated_by = :by2")
            ->execute([':v'=>$on, ':by'=>$adminId, ':v2'=>$on, ':by2'=>$adminId]);
        $aud('tier_enforcement_set', "Enforcement de tiers = {$on}");
    } elseif ($action === 'set_firm_tier') {
        $fid = (int)($_POST['firma_id'] ?? 0);
        $tid = ($_POST['tier_id'] ?? '') !== '' ? (int)$_POST['tier_id'] : null;
        $pdo->prepare("UPDATE keeper_firmas SET tier_id = :t WHERE id = :f")->execute([':t'=>$tid, ':f'=>$fid]);
        $aud('firm_tier_set', "Firma {$fid} -> tier ".($tid ?? 'ninguno'), ['firma_id'=>$fid,'tier_id'=>$tid]);
    } elseif ($action === 'toggle_tier_module') {
        $tid = (int)($_POST['tier_id'] ?? 0); $mc = (string)($_POST['module_code'] ?? ''); $on = ($_POST['on'] ?? '')==='1';
        if ($tid && $mc !== '') {
            if ($on) $pdo->prepare("INSERT IGNORE INTO keeper_tier_module (tier_id, module_code) VALUES (:t,:m)")->execute([':t'=>$tid,':m'=>$mc]);
            else     $pdo->prepare("DELETE FROM keeper_tier_module WHERE tier_id=:t AND module_code=:m")->execute([':t'=>$tid,':m'=>$mc]);
            $aud('tier_module_set', "Tier {$tid} / {$mc} = ".($on?'1':'0'));
        }
    } elseif ($action === 'add_tier') {
        $code  = preg_replace('/[^a-z0-9_]/','', strtolower((string)($_POST['code'] ?? '')));
        $label = trim((string)($_POST['label'] ?? ''));
        if ($code !== '' && $label !== '') {
            $pdo->prepare("INSERT IGNORE INTO keeper_tier (code, label, sort_order) VALUES (:c,:l,50)")->execute([':c'=>$code,':l'=>$label]);
            $aud('tier_added', "Tier {$code}");
        }
    } elseif ($action === 'set_firm_override') {
        $fid = (int)($_POST['firma_id'] ?? 0); $mc = (string)($_POST['module_code'] ?? ''); $val = $_POST['val'] ?? 'clear';
        if ($fid && $mc !== '') {
            if ($val === 'clear') $pdo->prepare("DELETE FROM keeper_firma_module_override WHERE firma_id=:f AND module_code=:m")->execute([':f'=>$fid,':m'=>$mc]);
            else {
                $en = $val === 'grant' ? 1 : 0;
                $pdo->prepare("INSERT INTO keeper_firma_module_override (firma_id, module_code, enabled, updated_by)
                               VALUES (:f,:m,:e,:by) ON DUPLICATE KEY UPDATE enabled=:e2, updated_by=:by2")
                    ->execute([':f'=>$fid,':m'=>$mc,':e'=>$en,':by'=>$adminId,':e2'=>$en,':by2'=>$adminId]);
            }
            $aud('firm_override_set', "Firma {$fid} / {$mc} = {$val}", ['firma_id'=>$fid,'module'=>$mc,'val'=>$val]);
        }
    }
    header('Location: tiers.php'); exit;
}

$pageTitle = 'Tiers y módulos'; $currentPage = 'tiers';

$stmt = $pdo->query("SELECT setting_value FROM keeper_panel_settings WHERE setting_key='tier_enforcement_enabled'");
$enfOn = (($stmt->fetchColumn() ?: '1') === '1');

$modules = $pdo->query("SELECT code,label,category,is_sensitive FROM keeper_module WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$tiers   = $pdo->query("SELECT id,code,label FROM keeper_tier WHERE is_active=1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
$tierMods = [];
foreach ($pdo->query("SELECT tier_id,module_code FROM keeper_tier_module")->fetchAll(PDO::FETCH_ASSOC) as $tm)
    $tierMods[(int)$tm['tier_id']][$tm['module_code']] = true;

$firmas = $pdo->query("SELECT id,nombre,tier_id FROM keeper_firmas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
$overrides = [];
foreach ($pdo->query("SELECT firma_id,module_code,enabled FROM keeper_firma_module_override")->fetchAll(PDO::FETCH_ASSOC) as $o)
    $overrides[(int)$o['firma_id']][$o['module_code']] = (int)$o['enabled'];

$catColor = ['tracking'=>'text-corp-800 bg-corp-50','security'=>'text-emerald-700 bg-emerald-50','control'=>'text-amber-700 bg-amber-50','data'=>'text-accent-500 bg-accent-500/10'];

require __DIR__ . '/partials/layout_header.php';
?>

<!-- Interruptor global de enforcement -->
<div class="bg-white rounded-xl border border-gray-100 p-5 mb-6 flex items-center justify-between gap-4">
  <div>
    <h2 class="text-sm font-semibold text-dark">Enforcement de tiers</h2>
    <p class="text-xs text-muted mt-0.5">Encendido: cada firma solo recibe los módulos de su tier (± overrides). Apagado: todos los módulos activos para todos (modo interno AZC).</p>
  </div>
  <form method="post" class="flex-none">
    <input type="hidden" name="action" value="set_global">
    <input type="hidden" name="enabled" value="<?= $enfOn ? '0' : '1' ?>">
    <button class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors <?= $enfOn ? 'bg-corp-800' : 'bg-gray-300' ?>" title="<?= $enfOn?'Apagar':'Encender' ?>">
      <span class="inline-block h-5 w-5 transform rounded-full bg-white transition-transform <?= $enfOn ? 'translate-x-5' : 'translate-x-0.5' ?>"></span>
    </button>
  </form>
</div>

<!-- Firmas y su tier -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-6">
  <div class="px-5 py-4 border-b border-gray-100"><h2 class="text-sm font-semibold text-dark">Tier por firma</h2></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Firma</th>
        <th class="text-left font-semibold px-5 py-2.5">Tier</th>
        <th class="text-left font-semibold px-5 py-2.5">Módulos efectivos</th>
        <th class="text-right font-semibold px-5 py-2.5">Overrides</th>
      </tr></thead>
      <tbody>
      <?php if(!$firmas): ?><tr><td colspan="4" class="px-5 py-8 text-center text-muted">Sin firmas.</td></tr><?php endif; ?>
      <?php foreach ($firmas as $f):
        $eff = TierResolver::effectiveModules($pdo, (int)$f['id']);
        $ovr = $overrides[(int)$f['id']] ?? [];
      ?>
        <tr class="border-b border-gray-100 last:border-0 align-top" x-data="{open:false}">
          <td class="px-5 py-3 font-medium text-dark"><?= htmlspecialchars($f['nombre']) ?></td>
          <td class="px-5 py-3">
            <form method="post">
              <input type="hidden" name="action" value="set_firm_tier"><input type="hidden" name="firma_id" value="<?= (int)$f['id'] ?>">
              <select name="tier_id" onchange="this.form.submit()" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
                <option value="" <?= $f['tier_id']===null?'selected':'' ?>>— sin tier —</option>
                <?php foreach ($tiers as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (int)$f['tier_id']===(int)$t['id']?'selected':'' ?>><?= htmlspecialchars($t['label']) ?></option><?php endforeach; ?>
              </select>
            </form>
          </td>
          <td class="px-5 py-3">
            <div class="flex flex-wrap gap-1">
              <?php foreach ($eff as $mc): ?><span class="text-[11px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-600"><?= htmlspecialchars($mc) ?></span><?php endforeach; ?>
              <?php if(!$eff): ?><span class="text-xs text-muted">ninguno</span><?php endif; ?>
            </div>
          </td>
          <td class="px-5 py-3 text-right">
            <button @click="open=!open" class="text-xs text-corp-800 hover:text-corp-600 font-medium">
              <?= $ovr ? count($ovr).' override(s)' : 'Editar' ?> <span x-text="open?'▴':'▾'"></span>
            </button>
            <div x-show="open" class="mt-3 text-left border-t border-gray-100 pt-3" style="display:none">
              <p class="text-[11px] text-muted mb-2">Concede o revoca módulos más allá del tier. "Por tier" = sin excepción.</p>
              <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1">
                <?php foreach ($modules as $m): $st = $ovr[$m['code']] ?? null; ?>
                  <form method="post" class="flex items-center justify-between gap-2 py-0.5">
                    <input type="hidden" name="action" value="set_firm_override"><input type="hidden" name="firma_id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="module_code" value="<?= htmlspecialchars($m['code']) ?>">
                    <span class="text-xs text-gray-600 truncate"><?= htmlspecialchars($m['label']) ?></span>
                    <select name="val" onchange="this.form.submit()" class="text-[11px] border border-gray-200 rounded px-1 py-0.5 flex-none">
                      <option value="clear" <?= $st===null?'selected':'' ?>>Por tier</option>
                      <option value="grant" <?= $st===1?'selected':'' ?>>Conceder</option>
                      <option value="revoke" <?= $st===0?'selected':'' ?>>Revocar</option>
                    </select>
                  </form>
                <?php endforeach; ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Matriz tier × módulo (editable) -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
    <h2 class="text-sm font-semibold text-dark">Qué desbloquea cada tier</h2>
    <form method="post" class="flex items-center gap-2">
      <input type="hidden" name="action" value="add_tier">
      <input name="code" placeholder="codigo" class="px-2 py-1 border border-gray-200 rounded text-xs w-24" pattern="[a-z0-9_]+">
      <input name="label" placeholder="Nombre del tier" class="px-2 py-1 border border-gray-200 rounded text-xs w-36">
      <button class="text-xs px-2 py-1 bg-corp-800 text-white rounded">+ Tier</button>
    </form>
  </div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Módulo</th>
        <?php foreach ($tiers as $t): ?><th class="text-center font-semibold px-4 py-2.5"><?= htmlspecialchars($t['label']) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($modules as $m): ?>
        <tr class="border-b border-gray-100 last:border-0">
          <td class="px-5 py-2.5">
            <span class="text-gray-700"><?= htmlspecialchars($m['label']) ?></span>
            <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded <?= $catColor[$m['category']] ?? 'bg-gray-100 text-gray-500' ?>"><?= htmlspecialchars($m['category']) ?></span>
            <?php if ($m['is_sensitive']): ?><span class="ml-1 text-[10px] text-accent-500" title="Su consulta se audita">●</span><?php endif; ?>
          </td>
          <?php foreach ($tiers as $t): $on = isset($tierMods[(int)$t['id']][$m['code']]); ?>
            <td class="px-4 py-2.5 text-center">
              <form method="post" class="inline">
                <input type="hidden" name="action" value="toggle_tier_module"><input type="hidden" name="tier_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="module_code" value="<?= htmlspecialchars($m['code']) ?>"><input type="hidden" name="on" value="<?= $on?'0':'1' ?>">
                <button class="w-6 h-6 rounded <?= $on ? 'bg-emerald-500 text-white' : 'bg-gray-100 text-gray-300 hover:bg-gray-200' ?>" title="<?= $on?'Quitar del tier':'Agregar al tier' ?>"><?= $on ? '✓' : '' ?></button>
              </form>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-muted">El backend recorta la política efectiva contra esto antes de enviarla al cliente: un módulo fuera del tier no llega al equipo aunque la política lo pida.</div>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
