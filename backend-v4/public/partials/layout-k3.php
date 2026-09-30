<?php
declare(strict_types=1);
// Layout del diseño nuevo (orden de K3 + estilo v4 índigo, docs/design/2026-09-29-panel-k3-v4). Se usa pantalla a
// pantalla: las páginas migradas lo incluyen; las demás siguen con partials/layout.php hasta el rediseño completo.
if (!isset($page, $title, $subtitle)) { http_response_code(404); exit; }
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
$e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
// [clave de pagina, texto, url]. La clave coincide con $page para marcar la actual.
$groups = [
    'Principal' => [['home', 'Inicio', '/'], ['sites', 'Sedes', '/sedes.php'], ['users', 'Personas', '/usuarios.php'],
        ['intake', 'Alta de equipos', '/alta.php'], ['devices', 'Equipos', '/equipos.php'], ['reports', 'Productividad', '/reportes.php']],
    'Gestión avanzada' => [['policies', 'Reglas', '/reglas.php'], ['organization', 'Organización', '/organizacion.php'],
        ['assignments', 'Asignaciones', '/asignaciones.php'], ['holidays', 'Festivos', '/festivos.php'], ['dual-job', 'Doble empleo', '/doble-empleo.php'],
        ['suspicious-apps', 'Apps sospechosas', '/apps-sospechosas.php'], ['coverage', 'Cobertura', '/cobertura.php']],
];
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title><?= $e($title) ?> · AZCKeeper</title>
<link rel="icon" href="/assets/brand/favicon.ico">
<link rel="stylesheet" href="/assets/k3.css?v=<?= filemtime($assetDir . '/k3.css') ?>">
<script type="importmap"><?= $importMap ?></script>
<script type="module" src="<?= $e($imports['/assets/k3.js']) ?>"></script>
</head>
<body data-page="<?= $e($page) ?>">
<a class="skip" href="#main">Saltar al contenido</a>
<aside class="sidebar" id="sidebar" aria-label="Navegación lateral">
  <a class="brand" href="/"><img src="/assets/brand/logo-mark.png" width="294" height="364" alt=""><span><strong>AZC<span>Keeper</span></strong><small>Administración · v4</small></span></a>
  <button class="icon-button sidebar-close" id="close-menu" type="button" aria-label="Cerrar menú">×</button>
  <nav aria-label="Secciones del panel">
<?php foreach ($groups as $heading => $links): ?>
    <section class="nav-group"><p class="nav-heading"><?= $e($heading) ?></p>
<?php foreach ($links as [$key, $text, $url]): ?>
      <a class="nav-link" href="<?= $e($url) ?>"<?= $key === $page ? ' aria-current="page"' : '' ?>><span class="nav-label"><?= $e($text) ?></span></a>
<?php endforeach; ?>
    </section>
<?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom"><button type="button" id="logout">Cerrar sesión</button></div>
</aside>
<header class="topbar">
  <button class="icon-button mobile-menu" id="open-menu" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú">☰</button>
  <div class="tenant"><strong id="tenant-name">Empresa</strong><small>Administración de empresa</small></div>
  <div class="top-meta"><span class="help time" id="now"></span></div>
</header>
<main id="main" tabindex="-1">
  <nav class="breadcrumb" aria-label="Ruta de navegación"><a href="/">Inicio</a><span>/</span><span><?= $e($title) ?></span></nav>
  <div class="page-head"><div><h1><?= $e($title) ?></h1><p><?= $e($subtitle) ?></p></div><button class="primary" id="page-action" type="button" hidden></button></div>
  <p id="page-status" class="help" role="status">Cargando…</p>
  <p id="page-error" class="error-text" role="alert" hidden></p>
  <div id="page-content"></div>
</main>
<noscript><p class="error-text">Activa JavaScript para usar el panel.</p></noscript>
</body>
</html>
