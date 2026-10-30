<?php
declare(strict_types=1);
if (!isset($page, $title)) { http_response_code(404); exit; }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
$assetDir = dirname(__DIR__) . '/assets';
$imports = [];
foreach (glob($assetDir . '/*.js') as $file) { $imports['/assets/' . basename($file)] = '/assets/' . basename($file) . '?v=' . filemtime($file); }
$importMap = json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES);
$importHash = base64_encode(hash('sha256', $importMap, true));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-$importHash'; style-src 'self'; img-src 'self' https:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/brand.php';
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light">
<title><?= $h($title . ' · ' . $brand['name']) ?></title>
<link rel="icon" href="/assets/brand/favicon.ico">
<link rel="stylesheet" href="/assets/k3.css?v=<?= filemtime($assetDir . '/k3.css') ?>">
<link rel="stylesheet" href="/assets/supervisor.css?v=<?= filemtime($assetDir . '/supervisor.css') ?>">
<script type="importmap"><?= $importMap ?></script>
<script type="module" src="<?= $h($imports['/assets/supervisor.js']) ?>"></script>
</head>
<body class="supervisor" data-page="<?= $h($page) ?>">
<a class="skip" href="#main">Saltar al contenido</a>
<header class="supervisor-header">
  <a class="brand" href="/supervisor.php"><img src="<?= $h($brand['logo'] ?? '/assets/brand/logo-mark.png') ?>" alt=""><span><strong><?= $h($brand['name']) ?></strong><small>Supervisor</small></span></a>
  <nav aria-label="Navegación"><a id="administrar" class="button" href="/admin/" hidden>Administrar</a><a class="button" href="/">Panel actual</a><button id="refresh" type="button">Actualizar</button><button id="logout" type="button">Cerrar sesión</button></nav>
</header>
<main id="main" tabindex="-1">
  <p id="page-status" role="status" aria-live="polite">Verificando acceso…</p>
  <div id="page-error" role="alert" hidden></div>
  <div id="content" aria-busy="true"></div>
</main>
<noscript><p>Activa JavaScript para consultar las personas.</p></noscript>
</body></html>
