<?php
declare(strict_types=1);
// Barra lateral única (orden de K3). data-nav: app.js sondea el permiso y oculta lo que la cuenta no puede ver; los
// enlaces sin data-nav se muestran siempre y la página explica si falta el permiso.
if (!isset($page)) { http_response_code(404); exit; }
$surface ??= 'portal';
$current = ['member' => 'users', 'device' => 'devices'][$page] ?? $page;
$groups = $surface === 'admin' ? [
    'Plataforma' => [['home', 'Inicio', '/admin/', true], ['tenants', 'Empresas', '/admin/tenants.php', true], ['roles', 'Roles', '/admin/roles.php', true],
        ['tiers', 'Tiers', '/admin/tiers.php', true], ['releases', 'Releases', '/admin/releases.php', true]],
    'Operación' => [['audit', 'Auditoría', '/admin/auditoria.php', true], ['client-logs', 'Logs del cliente', '/admin/logs.php', true],
        ['server-health', 'Salud del servidor', '/admin/salud.php', true], ['panel-settings', 'Ajustes', '/admin/ajustes.php', true],
        ['holidays', 'Festivos', '/admin/festivos.php', true], ['portal', 'Portal de empresa', '/', false]],
] : [
    'Principal' => [['home', 'Inicio', '/', true], ['sites', 'Sedes', '/sedes.php', false], ['users', 'Personas', '/usuarios.php', true],
        ['intake', 'Alta de equipos', '/alta.php', true], ['devices', 'Equipos', '/equipos.php', true], ['reports', 'Productividad', '/reportes.php', true]],
    'Gestión avanzada' => [['policies', 'Reglas', '/reglas.php', true], ['organization', 'Organización', '/organizacion.php', false],
        ['assignments', 'Asignaciones', '/asignaciones.php', false], ['holidays', 'Festivos', '/festivos.php', true], ['dual-job', 'Doble empleo', '/doble-empleo.php', true],
        ['suspicious-apps', 'Apps sospechosas', '/apps-sospechosas.php', true], ['coverage', 'Cobertura', '/cobertura.php', true]],
];
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
?>
<aside class="sidebar" id="sidebar" aria-label="Navegación lateral">
  <a class="brand" href="<?= $surface === 'admin' ? '/admin/' : '/' ?>"><img src="/assets/brand/logo-mark.png" width="294" height="364" alt=""><span><strong>AZC<span>Keeper</span></strong><small><?= $surface === 'admin' ? 'Panel Keeper' : 'Administración · v4' ?></small></span></a>
  <button class="icon-button sidebar-close" id="close-menu" type="button" aria-label="Cerrar menú">×</button>
  <nav id="navigation" aria-label="Secciones del panel">
<?php foreach ($groups as $heading => $links): ?>
    <section class="nav-group"><p class="nav-heading"><?= $h($heading) ?></p>
<?php foreach ($links as [$key, $text, $url, $probe]): ?>
      <a class="nav-link" href="<?= $h($url) ?>"<?= $probe ? ' data-nav="' . $h($key) . '"' : '' ?><?= $key === $current ? ' aria-current="page"' : '' ?>><span class="nav-label"><?= $h($text) ?></span></a>
<?php endforeach; ?>
    </section>
<?php endforeach; ?>
<?php if ($surface !== 'admin'): ?>
    <section class="nav-group"><a class="nav-link" href="/admin/" id="platform-link" hidden><span class="nav-label">Panel Keeper</span></a></section>
<?php endif; ?>
  </nav>
  <div class="sidebar-bottom"><button type="button" id="logout">Cerrar sesión</button></div>
</aside>
<button class="backdrop" id="menu-backdrop" type="button" aria-label="Cerrar menú" hidden></button>
