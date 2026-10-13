<?php
declare(strict_types=1);
// Barra lateral única (orden de K3) para todas las cuentas. El grupo "Plataforma" solo lo muestran app.js/k3.js a las
// cuentas de superadmin. data-nav: app.js sondea el permiso y oculta lo que la cuenta no puede ver; solo se usa en los
// enlaces de la superficie de la página actual (los de la otra superficie se consultan contra otra empresa).
if (!isset($page)) { http_response_code(404); exit; }
$surface ??= 'portal';
$current = ['member' => 'users', 'device' => 'devices'][$page] ?? $page;
$portal = [
    'Principal' => [['home', 'Inicio', '/'], ['users', 'Personas', '/usuarios.php'], ['devices', 'Equipos', '/equipos.php'], ['reports', 'Productividad', '/reportes.php']],
    'Administración' => [['schedules', 'Horarios', '/horarios.php', false],
        ['holidays', 'Festivos', '/festivos.php', false], ['absences', 'Ausencias', '/ausencias.php', false], ['doors', 'Puertas', '/puertas.php', false],
        ['policies', 'Reglas de apps y sitios', '/reglas.php', false], ['classification', 'Clasificación', '/clasificacion.php', false],
        ['organization', 'Organización: firmas, sedes, áreas y cargos', '/organizacion.php', false], ['sites', 'Sedes', '/sedes.php', false],
        ['roles', 'Roles y visibilidad de módulos', '/roles.php', false], ['accounts', 'Administradores', '/cuentas.php', false],
        ['intake', 'Registrar equipos nuevos', '/alta.php'], ['assignments', 'Asignación de equipos', '/asignaciones.php', false],
        ['suspicious-apps', 'Apps sospechosas', '/apps-sospechosas.php', false], ['dual-job', 'Doble empleo', '/doble-empleo.php'],
        ['coverage', 'Cobertura de instalación', '/cobertura.php'], ['onboarding', 'Puesta en marcha', '/puesta-en-marcha.php', false],
        ['audit', 'Auditoría', '/auditoria.php', false], ['client-logs', 'Logs de los equipos', '/logs.php', false], ['panel-settings', 'Ajustes', '/ajustes.php', false]],
];
$platform = [['platform-home', 'Resumen de plataforma', '/admin/'], ['tenants', 'Empresas', '/empresas.php', false],
    ['plans', 'Funciones y planes', '/planes.php', false], ['sources', 'Integraciones', '/fuentes.php', false],
    ['releases', 'Versiones y mudanzas', '/admin/releases.php'], ['server-health', 'Salud del servidor', '/admin/salud.php', false],
    ['portal-origins', 'Portal: direcciones autorizadas', '/admin/portal.php', false]];
if ($surface === 'admin' && $current === 'home') { $current = 'platform-home'; }
$h = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
require_once __DIR__ . '/brand.php';
$link = static function (array $l, bool $probeSurface) use ($current, $h): string {
    [$key, $text, $url] = $l; $probe = ($l[3] ?? true) && $probeSurface && $key !== 'platform-home';
    return '      <a class="nav-link" data-key="' . $h($key) . '" href="' . $h($url) . '"' . ($probe ? ' data-nav="' . $h($key) . '"' : '') . ($key === $current ? ' aria-current="page"' : '') . '><span class="nav-label">' . $h($text) . "</span></a>\n";
};
?>
<aside class="sidebar" id="sidebar" aria-label="Navegación lateral">
  <a class="brand" href="/"><?php if ($brand['logo']): ?><img class="custom-logo" src="<?= $h($brand['logo']) ?>" alt=""><?php else: ?><img src="/assets/brand/logo-mark.png" width="294" height="364" alt=""><?php endif; ?><span><strong><?= $h($brand['name']) ?></strong><small>Administración · v4</small></span></a>
  <button class="icon-button sidebar-close" id="close-menu" type="button" aria-label="Cerrar menú">×</button>
  <nav id="navigation" aria-label="Secciones del panel">
<?php foreach ($portal as $heading => $links): ?>
    <section class="nav-group"><p class="nav-heading"><?= $h($heading) ?></p>
<?php foreach ($links as $l) { echo $link($l, $surface === 'portal'); } ?>
<?php if ($heading === 'Principal'): ?>
      <div id="group-group" hidden><?= $link(['group', 'Tablero del grupo', '/grupo.php', false], false) ?></div>
<?php endif; ?>
    </section>
<?php endforeach; ?>
    <section class="nav-group" id="platform-group"<?= $surface === 'admin' ? '' : ' hidden' ?>><p class="nav-heading">Plataforma</p>
<?php foreach ($platform as $l) { echo $link($l, $surface === 'admin'); } ?>
    </section>
  </nav>
  <div class="sidebar-bottom"><button type="button" id="logout">Cerrar sesión</button></div>
</aside>
<button class="backdrop" id="menu-backdrop" type="button" aria-label="Cerrar menú" hidden></button>
