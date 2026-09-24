<?php
declare(strict_types=1);
if (!isset($page, $title, $surface)) { http_response_code(404); exit; }
define('KEEPER_PRESENTATION', true);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' https:; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
?>
<!doctype html>
<html lang="es">
<head><?php require __DIR__ . '/head.php'; ?></head>
<body data-page="<?= $page ?>" data-surface="<?= $surface ?>">
<a class="skip-link" href="#main">Ir al contenido</a>
<div class="canvas">
  <header class="site-header">
    <a class="brand" href="<?= $surface === 'admin' ? '/admin/' : '/' ?>" aria-label="AZCKeeper, inicio">
      <img class="brand-mark" src="/assets/brand/logo-mark.png" width="294" height="364" alt="">
      <span>AZC<span class="brand-light">Keeper</span><small><?= $surface === 'admin' ? 'Panel Keeper' : 'Portal de empresa' ?></small></span>
    </a>
    <div class="tenant-brand" id="tenant-brand" hidden><img id="tenant-logo" alt="" hidden><span id="tenant-name"></span></div>
    <?php if ($surface !== 'public'): ?>
    <button id="logout" type="button">Cerrar sesión</button>
    <?php endif; ?>
  </header>
  <?php if ($surface !== 'public'): ?>
  <div id="navigation" hidden><?php require __DIR__ . '/nav-' . $surface . '.php'; ?></div>
  <?php endif; ?>
  <main id="main" tabindex="-1">
  <?php if ($page === 'login'): ?>
    <div class="access-layout">
      <section class="access-intro" aria-labelledby="login-title">
        <img class="brand-wordmark" src="/assets/brand/logo-main.png" width="447" height="172" alt="Grupo AZC">
        <p class="eyebrow">AZCKeeper</p><h1 id="login-title">Tu empresa,<br>en un solo lugar.</h1>
        <p>Accede a los equipos, usuarios y reportes disponibles para tu cuenta.</p>
      </section>
      <section class="work-surface access-form" aria-labelledby="form-title">
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
  <?php elseif ($page === 'not-found'): ?>
    <div class="page-head"><h1>Página no encontrada</h1></div><p><a href="/">Volver al inicio</a></p>
  <?php else: ?>
    <div class="page-head"><div><p class="eyebrow"><?= $surface === 'admin' ? 'Administración de plataforma' : 'Tu empresa' ?></p><h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1></div><button id="refresh" type="button">Actualizar</button></div>
    <p id="page-status" class="help" role="status">Verificando acceso…</p>
    <p id="page-error" class="error-message" role="alert" hidden></p>
    <div id="workspace" hidden>
      <div id="tenant-control" class="toolbar" hidden><label for="tenant-select">Empresa</label><select id="tenant-select"></select></div>
      <?php if (in_array($page, ['reports', 'audit'], true)): ?>
      <form id="date-form" class="toolbar">
        <div><label for="from">Desde (incluido)</label><input id="from" name="from" type="date" required></div>
        <div><label for="to">Hasta (excluido)</label><input id="to" name="to" type="date" required></div>
        <button class="primary" type="submit">Consultar</button>
      </form>
      <?php endif; ?>
      <div id="content" aria-busy="true"></div>
    </div>
  <?php endif; ?>
  </main>
  <noscript><p class="error-message">Activa JavaScript para iniciar sesión y consultar los datos de tu empresa.</p></noscript>
  <footer class="site-footer">AZCKeeper · Grupo AZC</footer>
</div>
</body>
</html>
