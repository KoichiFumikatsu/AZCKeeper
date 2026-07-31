<?php
/**
 * Versiones del cliente: el feed que lee GET /client/version (y con él, el auto-update).
 *
 * El asset NO se sube por aquí. El ZIP publicado pesa ~79 MB y el backend vive en hosting
 * compartido: subirlo por PHP choca con upload_max_filesize/post_max_size y luego lo serviría
 * la misma máquina que ya se gana baneos de CSF por carga. El flujo real es el que se usa en
 * producción desde 3.0.2.x: publicar el asset en GitHub Releases y REGISTRAR aquí su URL.
 *
 * Lo que sí se automatiza es el dato que se copiaba a mano y se equivocaba: "Verificar" hace
 * un HEAD contra la URL, confirma que el asset existe y trae Content-Length a size_bytes.
 *
 * Regla de activación: UNA sola versión activa por canal (estable / beta). ClientVersion toma
 * `is_active=1 ORDER BY created_at DESC LIMIT 1`, así que dos activas no fallan — eligen en
 * silencio, que es peor. Activar una desactiva la anterior del mismo canal.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
require_once __DIR__ . '/../../src/Repos/AuditRepo.php';

use Keeper\Repos\AuditRepo;

if (!panelCan($adminUser, 'releases')) { header('Location: ' . panelLanding($adminUser)); exit; }

$adminId = (int)$adminUser['admin_id'];
$flash   = null;

/**
 * HEAD al asset: ¿existe y cuánto pesa? Devuelve [httpCode, bytes|null, error|null].
 *
 * Los redirects se siguen A MANO: GitHub responde 302 hacia su CDN y este hosting tiene
 * open_basedir activo, condición en la que PHP DESACTIVA CURLOPT_FOLLOWLOCATION en silencio
 * (curl_setopt devuelve false y la petición se queda en el 302). Con FOLLOWLOCATION la
 * verificación reportaba "el asset no respondió (HTTP 302)" sobre un asset perfectamente vivo.
 */
function headAsset(string $url): array {
    for ($hop = 0; $hop < 5; $hop++) {
        if (!function_exists('curl_init')) break;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => 'KeeperPanel/4',
        ]);
        curl_exec($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $len   = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        $next  = (string)curl_getinfo($ch, CURLINFO_REDIRECT_URL);   // se llena aunque no se siga
        $errNo = curl_errno($ch);
        $err   = $errNo ? (curl_error($ch) ?: ('curl error ' . $errNo)) : null;
        curl_close($ch);

        if ($code >= 300 && $code < 400 && preg_match('#^https?://#i', $next)) { $url = $next; continue; }
        return [$code, ($len !== null && $len > 0) ? (int)$len : null, $err];
    }
    if (function_exists('curl_init')) return [0, null, 'Demasiadas redirecciones'];

    if (!ini_get('allow_url_fopen')) return [0, null, 'Sin curl ni allow_url_fopen en este PHP'];
    $ctx = stream_context_create(['http' => ['method' => 'HEAD', 'timeout' => 15, 'ignore_errors' => true]]);
    $hdr = @get_headers($url, true, $ctx);
    if (!$hdr) return [0, null, 'Sin respuesta'];
    $code = 0;
    if (preg_match('#HTTP/\S+\s+(\d{3})#', is_array($hdr[0]) ? end($hdr[0]) : $hdr[0], $m)) $code = (int)$m[1];
    $len = $hdr['Content-Length'] ?? null;
    if (is_array($len)) $len = end($len);
    return [$code, $len !== null ? (int)$len : null, null];
}

// ---- Acciones (POST) ----
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $version = trim((string)($_POST['version'] ?? ''));
        $url     = trim((string)($_POST['download_url'] ?? ''));
        $notes   = trim((string)($_POST['notes'] ?? ''));
        $isBeta  = ($_POST['is_beta'] ?? '') === '1' ? 1 : 0;

        if (!preg_match('/^\d+(\.\d+){1,3}$/', $version)) {
            $flash = ['err', 'Versión inválida: se espera 3.0.3.2 (2 a 4 números separados por punto).'];
        } elseif (!preg_match('#^https://#i', $url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            $flash = ['err', 'La URL de descarga debe ser https:// y estar bien formada.'];
        } else {
            try {
                // size_bytes entra NULL a propósito: lo llena "Verificar" con el Content-Length real.
                $pdo->prepare("INSERT INTO keeper_client_releases (version, download_url, size_bytes, is_beta, notes, is_active)
                               VALUES (:v, :u, NULL, :b, :n, 0)")
                    ->execute([':v' => $version, ':u' => $url, ':b' => $isBeta,
                               ':n' => $notes !== '' ? $notes : null]);
                $flash = ['ok', "Versión {$version} registrada (inactiva). Verifícala y actívala cuando quieras publicarla."];
                try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'release_add', "Release {$version} registrada",
                        ['version' => $version, 'is_beta' => $isBeta]); } catch (\Throwable $e) {}
            } catch (\PDOException $e) {
                $flash = ['err', str_contains($e->getMessage(), 'uq_release_version')
                    ? "La versión {$version} ya está registrada." : 'No se pudo registrar la versión.'];
                error_log('release add: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'activate') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT version, is_beta FROM keeper_client_releases WHERE id = :id");
        $st->execute([':id' => $id]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE keeper_client_releases SET is_active = 0 WHERE is_beta = :b AND id <> :id")
                ->execute([':b' => (int)$row['is_beta'], ':id' => $id]);
            $pdo->prepare("UPDATE keeper_client_releases SET is_active = 1 WHERE id = :id")->execute([':id' => $id]);
            $pdo->commit();
            $canal = $row['is_beta'] ? 'beta' : 'estable';
            $flash = ['ok', "Versión {$row['version']} activa en el canal {$canal}. Los equipos la verán en su próximo chequeo."];
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'release_activate',
                    "Release {$row['version']} activada ({$canal})", ['id' => $id]); } catch (\Throwable $e) {}
        }
    } elseif ($action === 'deactivate') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE keeper_client_releases SET is_active = 0 WHERE id = :id")->execute([':id' => $id]);
        try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'release_deactivate', "Release id {$id} desactivada"); } catch (\Throwable $e) {}
    } elseif ($action === 'verify') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT version, download_url, size_bytes FROM keeper_client_releases WHERE id = :id");
        $st->execute([':id' => $id]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            [$code, $bytes, $err] = headAsset($row['download_url']);
            if ($code >= 200 && $code < 300) {
                if ($bytes !== null) {
                    $pdo->prepare("UPDATE keeper_client_releases SET size_bytes = :s WHERE id = :id")
                        ->execute([':s' => $bytes, ':id' => $id]);
                    $prev  = $row['size_bytes'] !== null ? (int)$row['size_bytes'] : null;
                    $flash = ['ok', "Asset de {$row['version']} accesible (HTTP {$code}). Tamaño real: " . number_format($bytes) . ' bytes'
                        . ($prev !== null && $prev !== $bytes ? " — corregido, el registrado era " . number_format($prev) . '.' : '.')];
                } else {
                    $flash = ['warn', "Asset accesible (HTTP {$code}) pero el servidor no informó el tamaño."];
                }
            } elseif ($code === 0) {
                // Distinción que importa: esto NO dice que el asset esté mal, dice que el
                // servidor no salió a internet. Verificado el 2026-07-31: este hosting
                // compartido bloquea el egreso HTTP/HTTPS (ni github.com ni google.com).
                $flash = ['warn', 'El servidor no logró salir a internet para comprobar el asset'
                    . ($err ? " ({$err})" : '') . '. Este hosting tiene el egreso bloqueado: '
                    . 'registra el tamaño a mano en la columna Tamaño. El asset puede estar perfectamente bien.'];
            } else {
                $flash = ['err', "El asset de {$row['version']} NO respondió (HTTP {$code})" . ($err ? ": {$err}" : '.')];
            }
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'release_verify',
                    "Verificación de {$row['version']}: HTTP {$code}", ['bytes' => $bytes]); } catch (\Throwable $e) {}
        }
    } elseif ($action === 'set_size') {
        // Respaldo de "Verificar" mientras el hosting no tenga salida a internet.
        $id   = (int)($_POST['id'] ?? 0);
        $size = preg_replace('/\D/', '', (string)($_POST['size_bytes'] ?? ''));
        if ($id > 0 && $size !== '') {
            $pdo->prepare("UPDATE keeper_client_releases SET size_bytes = :s WHERE id = :id")
                ->execute([':s' => (int)$size, ':id' => $id]);
            $flash = ['ok', 'Tamaño registrado: ' . number_format((int)$size) . ' bytes.'];
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'release_size_set',
                    "Tamaño fijado a mano (release id {$id})", ['bytes' => (int)$size]); } catch (\Throwable $e) {}
        }

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT version, is_active FROM keeper_client_releases WHERE id = :id");
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && !(int)$row['is_active']) {
            $pdo->prepare("DELETE FROM keeper_client_releases WHERE id = :id")->execute([':id' => $id]);
            $flash = ['ok', "Versión {$row['version']} eliminada del feed."];
            try { AuditRepo::log($pdo, $adminId, null, null, 'admin', 'release_delete', "Release {$row['version']} eliminada"); } catch (\Throwable $e) {}
        } else {
            $flash = ['err', 'No se elimina una versión activa: desactívala primero.'];
        }
    }
    // Sin redirect: el resultado (sobre todo el de "Verificar") es el mensaje. Todas las
    // acciones son idempotentes o fallan con un aviso legible, así que reenviar no rompe nada.
}

$pageTitle = 'Versiones del cliente'; $currentPage = 'releases';

$rows = [];
try {
    $rows = $pdo->query("SELECT id, version, download_url, size_bytes, is_active, is_beta, notes, created_at
                         FROM keeper_client_releases ORDER BY is_active DESC, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('releases list: ' . $e->getMessage()); }

// Reparto de versiones en la flota: qué está corriendo de verdad frente a lo publicado.
$fleet = [];
try {
    $fleet = $pdo->query("SELECT COALESCE(client_version,'—') v, COUNT(*) c
                          FROM keeper_devices WHERE status='active'
                          GROUP BY client_version ORDER BY c DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {}
$fleetTotal = array_sum(array_column($fleet, 'c'));

function mb(?int $b): string { return $b === null ? '—' : number_format($b / 1048576, 1) . ' MB'; }

require __DIR__ . '/partials/layout_header.php';
?>

<?php if ($flash): [$kind, $msg] = $flash;
  $cls = $kind === 'ok' ? 'text-emerald-700 bg-emerald-50 border-emerald-200'
       : ($kind === 'warn' ? 'text-amber-700 bg-amber-50 border-amber-200' : 'text-accent-600 bg-accent-500/5 border-accent-500/20'); ?>
  <div class="mb-5 text-sm border rounded-lg px-4 py-2.5 <?= $cls ?>"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- Registrar una versión -->
<div class="bg-white rounded-xl border border-gray-100 p-5 mb-6">
  <h2 class="text-sm font-semibold text-dark mb-1">Registrar una versión</h2>
  <p class="text-xs text-muted mb-4">
    Publica primero el ZIP en GitHub Releases y pega aquí la URL del asset. El tamaño se registra
    haciendo clic sobre la columna <span class="font-medium">Tamaño</span>; "Verificar" lo llenaría solo,
    pero hoy este hosting no tiene salida a internet (verificado el 31/07/2026).
  </p>
  <form method="post" class="grid sm:grid-cols-12 gap-3 items-end">
    <input type="hidden" name="action" value="add">
    <div class="sm:col-span-2">
      <label class="block text-xs font-medium text-gray-600 mb-1">Versión</label>
      <input name="version" required placeholder="4.0.0.1" pattern="\d+(\.\d+){1,3}"
             class="w-full px-2.5 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200">
    </div>
    <div class="sm:col-span-5">
      <label class="block text-xs font-medium text-gray-600 mb-1">URL del asset (https)</label>
      <input name="download_url" required type="url" placeholder="https://github.com/…/AZCKeeper_v4.0.0.1.zip"
             class="w-full px-2.5 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200">
    </div>
    <div class="sm:col-span-3">
      <label class="block text-xs font-medium text-gray-600 mb-1">Notas (opcional)</label>
      <input name="notes" maxlength="255" placeholder="Qué trae"
             class="w-full px-2.5 py-2 border border-gray-200 rounded-lg text-sm outline-none focus:ring-2 focus:ring-corp-200">
    </div>
    <div class="sm:col-span-1 flex items-center gap-1.5 pb-2">
      <input type="checkbox" name="is_beta" value="1" id="beta" class="rounded border-gray-300">
      <label for="beta" class="text-xs text-gray-600">Beta</label>
    </div>
    <div class="sm:col-span-1">
      <button class="w-full px-3 py-2 bg-corp-800 hover:bg-corp-900 text-white text-sm font-medium rounded-lg">Registrar</button>
    </div>
  </form>
</div>

<!-- Feed -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden mb-6">
  <div class="px-5 py-4 border-b border-gray-100"><h2 class="text-sm font-semibold text-dark">Feed de versiones</h2></div>
  <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="bg-gray-50 text-gray-500 text-xs uppercase tracking-wide">
        <th class="text-left font-semibold px-5 py-2.5">Versión</th>
        <th class="text-left font-semibold px-5 py-2.5">Canal</th>
        <th class="text-left font-semibold px-5 py-2.5">Tamaño</th>
        <th class="text-left font-semibold px-5 py-2.5">Asset</th>
        <th class="text-left font-semibold px-5 py-2.5">Registrada</th>
        <th class="text-right font-semibold px-5 py-2.5">Acciones</th>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="6" class="px-5 py-10 text-center text-muted">Sin versiones registradas.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr class="border-b border-gray-100 last:border-0">
          <td class="px-5 py-3">
            <span class="font-medium text-dark font-mono"><?= htmlspecialchars($r['version']) ?></span>
            <?php if ($r['is_active']): ?>
              <span class="ml-1.5 text-xs font-medium text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full">Activa</span>
            <?php endif; ?>
            <?php if ($r['notes']): ?><div class="text-xs text-muted mt-0.5"><?= htmlspecialchars($r['notes']) ?></div><?php endif; ?>
          </td>
          <td class="px-5 py-3 text-gray-600"><?= $r['is_beta'] ? 'Beta' : 'Estable' ?></td>
          <td class="px-5 py-3" x-data="{edit:false}">
            <button @click="edit=!edit" class="tabular-nums text-gray-700 hover:text-corp-800"
                    title="<?= $r['size_bytes'] !== null ? number_format((int)$r['size_bytes']) . ' bytes — clic para corregir' : 'sin tamaño — clic para registrarlo' ?>">
              <?= mb($r['size_bytes'] !== null ? (int)$r['size_bytes'] : null) ?>
            </button>
            <form method="post" x-show="edit" style="display:none" class="mt-1 flex items-center gap-1">
              <input type="hidden" name="action" value="set_size"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <input name="size_bytes" inputmode="numeric" placeholder="bytes" value="<?= $r['size_bytes'] !== null ? (int)$r['size_bytes'] : '' ?>"
                     class="w-28 px-1.5 py-1 border border-gray-200 rounded text-xs tabular-nums">
              <button class="text-xs px-1.5 py-1 bg-corp-800 text-white rounded">OK</button>
            </form>
          </td>
          <td class="px-5 py-3">
            <a href="<?= htmlspecialchars($r['download_url']) ?>" target="_blank" rel="noopener"
               class="text-xs text-corp-800 hover:text-corp-600 break-all"><?= htmlspecialchars(substr($r['download_url'], 0, 60)) ?><?= strlen($r['download_url']) > 60 ? '…' : '' ?></a>
          </td>
          <td class="px-5 py-3 text-gray-600 whitespace-nowrap"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></td>
          <td class="px-5 py-3 text-right whitespace-nowrap">
            <form method="post" class="inline">
              <input type="hidden" name="action" value="verify"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="text-xs px-2 py-1 border border-gray-200 rounded hover:border-gray-300 text-gray-600">Verificar</button>
            </form>
            <?php if ($r['is_active']): ?>
              <form method="post" class="inline">
                <input type="hidden" name="action" value="deactivate"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="text-xs px-2 py-1 border border-gray-200 rounded hover:border-gray-300 text-gray-600">Desactivar</button>
              </form>
            <?php else: ?>
              <form method="post" class="inline">
                <input type="hidden" name="action" value="activate"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="text-xs px-2 py-1 bg-corp-800 hover:bg-corp-900 text-white rounded">Activar</button>
              </form>
              <form method="post" class="inline" onsubmit="return confirm('¿Eliminar la versión <?= htmlspecialchars($r['version']) ?> del feed?')">
                <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="text-xs px-2 py-1 text-muted hover:text-accent-500">Eliminar</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="px-5 py-3 bg-gray-50 border-t border-gray-100 text-xs text-muted">
    El cliente pregunta por <code>GET /client/version</code>: devuelve la versión ACTIVA más reciente del
    canal (con <code>?allowBeta=true</code> incluye las beta). Solo puede haber una activa por canal.
    <code>minimumVersion</code> y <code>forceUpdate</code> todavía no existen como columnas: el feed responde
    null/false y el cliente lo lee como "sin exigencia".
  </div>
</div>

<!-- Qué corre realmente la flota -->
<div class="bg-white rounded-xl border border-gray-100 overflow-hidden">
  <div class="px-5 py-4 border-b border-gray-100">
    <h2 class="text-sm font-semibold text-dark">Versiones en la flota</h2>
    <p class="text-xs text-muted mt-0.5">Lo que reportan los <?= (int)$fleetTotal ?> equipos activos. Publicar no es desplegar: el rollout se ve aquí.</p>
  </div>
  <div class="px-5 py-4 space-y-2">
    <?php if (!$fleet): ?><p class="text-sm text-muted">Sin equipos activos.</p><?php endif; ?>
    <?php foreach ($fleet as $f): $pct = $fleetTotal ? round(100 * $f['c'] / $fleetTotal) : 0; ?>
      <div class="flex items-center gap-3">
        <span class="w-20 text-xs font-mono text-gray-700"><?= htmlspecialchars($f['v']) ?></span>
        <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden">
          <div class="h-full bg-corp-800 rounded-full" style="width: <?= $pct ?>%"></div>
        </div>
        <span class="w-16 text-right text-xs tabular-nums text-gray-600"><?= (int)$f['c'] ?> · <?= $pct ?>%</span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/partials/layout_footer.php'; ?>
