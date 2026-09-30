<?php
declare(strict_types=1);
// Marco común del panel con el diseño aprobado (orden de K3 + estilo v4 índigo). Conserva los identificadores que usa
// app.js (#workspace, #content, #tenant-select, #refresh, #date-form, #navigation...) para no reescribir las pantallas.
if (!isset($page, $title, $surface)) { http_response_code(404); exit; }
define('KEEPER_PRESENTATION', true);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
// Los modulos JS se piden con ?v=<fecha de modificacion>: sin esto el navegador reutilizaba una copia vieja de
// api.js (sin la cabecera Idempotency-Key) y la API respondia 422. El import map va inline, autorizado por su hash.
$assetDir = dirname(__DIR__) . '/assets';
$imports = [];
foreach (glob($assetDir . '/*.js') as $file) { $imports['/assets/' . basename($file)] = '/assets/' . basename($file) . '?v=' . filemtime($file); }
$importMap = json_encode(['imports' => $imports], JSON_UNESCAPED_SLASHES);
$importHash = base64_encode(hash('sha256', $importMap, true));
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'sha256-$importHash'; style-src 'self'; img-src 'self' https:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="es">
<head><?php require __DIR__ . '/head.php'; ?></head>
<body data-page="<?= $h($page) ?>" data-surface="<?= $h($surface) ?>" class="<?= $surface === 'public' ? 'public' : '' ?>">
<a class="skip" href="#main">Saltar al contenido</a>
<?php if ($surface === 'public'): ?>
<main id="main" tabindex="-1" class="access">
  <?php if ($page === 'login'): ?>
  <div class="access-layout">
    <section class="access-intro" aria-labelledby="login-title">
      <img class="brand-wordmark" src="/assets/brand/logo-main.png" width="447" height="172" alt="AZCKeeper">
      <p class="eyebrow">AZCKeeper</p><h1 id="login-title">Tu empresa,<br>en un solo lugar.</h1>
      <p>Accede a los equipos, personas y reportes disponibles para tu cuenta.</p>
    </section>
    <section class="surface access-form" aria-labelledby="form-title">
      <h2 id="form-title">Iniciar sesión</h2>
      <form id="login-form">
        <label for="email">Correo electrónico</label><input id="email" name="email" type="email" autocomplete="username" maxlength="254" required>
        <label for="password">Contraseña</label><input id="password" name="password" type="password" autocomplete="current-password" required>
        <p id="login-error" class="error-message" role="alert" hidden></p>
        <button class="primary" id="login-submit" type="submit">Iniciar sesión</button>
        <p id="login-status" class="help" role="status"></p>
      </form>
    </section>
  </div>
  <?php else: ?>
  <div class="page-head"><h1>Página no encontrada</h1></div><p><a href="/">Volver al inicio</a></p>
  <?php endif; ?>
</main>
<?php else: ?>
<?php require __DIR__ . '/sidebar.php'; ?>
<header class="topbar">
  <button class="icon-button mobile-menu" id="open-menu" type="button" aria-controls="sidebar" aria-expanded="false" aria-label="Abrir menú">☰</button>
  <div class="tenant" id="tenant-brand"><strong id="tenant-name"><?= $surface === 'admin' ? 'Panel Keeper' : 'Empresa' ?></strong><small><?= $surface === 'admin' ? 'Administración de plataforma' : 'Administración de empresa' ?></small><img id="tenant-logo" class="tenant-logo" alt="" hidden></div>
  <div class="top-meta"><button id="refresh" type="button">Actualizar</button></div>
</header>
<main id="main" tabindex="-1">
  <nav class="breadcrumb" aria-label="Ruta de navegación"><a href="<?= $surface === 'admin' ? '/admin/' : '/' ?>">Inicio</a><span>/</span><span><?= $h($title) ?></span></nav>
  <div class="page-head"><div><h1><?= $h($title) ?></h1></div></div>
  <p id="page-status" class="help" role="status">Verificando acceso…</p>
  <p id="page-error" class="error-message" role="alert" hidden></p>
  <div id="workspace" hidden>
    <div id="tenant-control" class="toolbar" hidden><label for="tenant-select">Empresa<select id="tenant-select"></select></label></div>
    <?php if (in_array($page, ['reports', 'audit'], true)): ?>
    <form id="date-form" class="toolbar">
      <label for="from">Desde (incluido)<input id="from" name="from" type="date" required></label>
      <label for="to">Hasta (excluido)<input id="to" name="to" type="date" required></label>
      <button class="primary" type="submit">Consultar</button>
    </form>
    <?php endif; ?>
    <div id="content" aria-busy="true"></div>
  </div>
  <footer><span>AZCKeeper v4</span><span>Fechas en la zona horaria del navegador</span></footer>
</main>
<?php endif; ?>
<noscript><p class="error-message">Activa JavaScript para usar el panel.</p></noscript>
</body>
</html>
