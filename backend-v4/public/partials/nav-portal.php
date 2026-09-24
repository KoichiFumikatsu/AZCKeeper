<?php if (!defined('KEEPER_PRESENTATION')) { http_response_code(404); exit; } ?>
<nav class="surface-nav" aria-label="Portal de empresa">
  <a href="/" data-nav="home">Inicio</a>
  <a href="/equipos.php" data-nav="devices" hidden>Equipos</a>
  <a href="/usuarios.php" data-nav="users" hidden>Usuarios</a>
  <a href="/reglas.php" data-nav="policies" hidden>Reglas</a>
  <a href="/reportes.php" data-nav="reports" hidden>Reportes</a>
  <a href="/festivos.php" data-nav="holidays" hidden>Festivos</a>
  <a href="/doble-empleo.php" data-nav="dual-job" hidden>Doble empleo</a>
  <a href="/apps-sospechosas.php" data-nav="suspicious-apps" hidden>Apps sospechosas</a>
  <a href="/cobertura.php" data-nav="coverage" hidden>Cobertura</a>
  <a href="/admin/" id="platform-link" hidden>Panel Keeper</a>
</nav>
