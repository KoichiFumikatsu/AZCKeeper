<?php
declare(strict_types=1);
// Barra lateral única (orden de K3) para todas las cuentas. El grupo "Plataforma" solo lo muestran app.js/k3.js a las
// cuentas de superadmin. data-nav: app.js sondea el permiso y oculta lo que la cuenta no puede ver; solo se usa en los
// enlaces de la superficie de la página actual (los de la otra superficie se consultan contra otra empresa).
if (!isset($page)) { http_response_code(404); exit; }
$surface ??= 'portal';
$current = ['member' => 'users', 'device' => 'devices'][$page] ?? $page;
$portal = [
    'Principal' => [['home', 'Inicio', '/'], ['sites', 'Sedes', '/sedes.php', false], ['users', 'Personas', '/usuarios.php'],
        ['intake', 'Alta de equipos', '/alta.php'], ['devices', 'Equipos', '/equipos.php'], ['reports', 'Productividad', '/reportes.php']],
    'Gestión avanzada' => [['policies', 'Reglas', '/reglas.php'], ['organization', 'Organización', '/organizacion.php', false],
        ['assignments', 'Asignaciones', '/asignaciones.php', false], ['accounts', 'Cuentas del panel', '/cuentas.php', false],
        ['roles', 'Roles y permisos', '/roles.php', false], ['holidays', 'Festivos', '/festivos.php'], ['dual-job', 'Doble empleo', '/doble-empleo.php'],
        ['suspicious-apps', 'Apps sospechosas', '/apps-sospechosas.php'], ['coverage', 'Cobertura', '/cobertura.php']],
];
$platform = [['platform-home', 'Resumen de plataforma', '/admin/'], ['tenants', 'Empresas', '/admin/tenants.php'], ['tiers', 'Planes (tiers)', '/admin/tiers.php'],
    ['releases', 'Versiones del agente', '/admin/releases.php'], ['audit', 'Auditoría', '/admin/auditoria.php'], ['client-logs', 'Logs de los equipos', '/admin/logs.php'],
    ['server-health', 'Salud del servidor', '/admin/salud.php'], ['panel-settings', 'Ajustes', '/admin/ajustes.php']];
if ($surface === 'admin' && $current === 'home') { $current = 'platform-home'; }
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$link = static function (array $l, bool $probeSurface) use ($current, $h): string {
    [$key, $text, $url] = $l; $probe = ($l[3] ?? true) && $probeSurface && $key !== 'platform-home';
    return '      <a class="nav-link" href="' . $h($url) . '"' . ($probe ? ' data-nav="' . $h($key) . '"' : '') . ($key === $current ? ' aria-current="page"' : '') . '><span class="nav-label">' . $h($text) . "</span></a>\n";
};
?>
<aside class="sidebar" id="sidebar" aria-label="Navegación lateral">
  <a class="brand" href="/"><img src="/assets/brand/logo-mark.png" width="294" height="364" alt=""><span><strong>AZC<span>Keeper</span></strong><small>Administración · v4</small></span></a>
  <button class="icon-button sidebar-close" id="close-menu" type="button" aria-label="Cerrar menú">×</button>
  <nav id="navigation" aria-label="Secciones del panel">
<?php foreach ($portal as $heading => $links): ?>
    <section class="nav-group"><p class="nav-heading"><?= $h($heading) ?></p>
<?php foreach ($links as $l) { echo $link($l, $surface === 'portal'); } ?>
    </section>
<?php endforeach; ?>
    <section class="nav-group" id="platform-group"<?= $surface === 'admin' ? '' : ' hidden' ?>><p class="nav-heading">Plataforma</p>
<?php foreach ($platform as $l) { echo $link($l, $surface === 'admin'); } ?>
    </section>
  </nav>
  <div class="sidebar-bottom"><button type="button" id="logout">Cerrar sesión</button></div>
</aside>
<button class="backdrop" id="menu-backdrop" type="button" aria-label="Cerrar menú" hidden></button>
