<?php if (!defined('KEEPER_PRESENTATION')) { http_response_code(404); exit; } ?>
<nav class="surface-nav" aria-label="Panel Keeper">
  <a href="/admin/" data-nav="home">Inicio</a>
  <a href="/admin/tenants.php" data-nav="tenants" hidden>Tenants</a>
  <a href="/admin/roles.php" data-nav="roles" hidden>Roles</a>
  <a href="/admin/tiers.php" data-nav="tiers" hidden>Tiers</a>
  <a href="/admin/releases.php" data-nav="releases" hidden>Releases</a>
  <a href="/admin/auditoria.php" data-nav="audit" hidden>Auditoría</a>
  <a href="/admin/logs.php" data-nav="client-logs" hidden>Logs del cliente</a>
  <a href="/admin/salud.php" data-nav="server-health" hidden>Salud del servidor</a>
  <a href="/admin/ajustes.php" data-nav="panel-settings" hidden>Ajustes</a>
  <a href="/admin/festivos.php" data-nav="holidays" hidden>Festivos</a>
  <a href="/">Portal</a>
</nav>
