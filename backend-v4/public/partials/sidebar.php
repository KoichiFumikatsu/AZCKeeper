<?php
declare(strict_types=1);
// Barra lateral única para todas las cuentas. El grupo "Plataforma" va arriba y plegable (decisión Q2, 2026-10-15) y
// solo lo muestran app.js/k3.js a las cuentas de superadmin. Cada grupo es un <details> que menu.js recuerda abierto o
// cerrado. data-nav: app.js sondea el permiso y oculta lo que la cuenta no puede ver; solo se usa en los
// enlaces de la superficie de la página actual (los de la otra superficie se consultan contra otra empresa).
if (!isset($page)) { http_response_code(404); exit; }
$surface ??= 'portal';
$current = ['member' => 'users', 'device' => 'devices'][$page] ?? $page;
// Orden por tareas (docs/design/2026-10-14-superadmin-por-tareas.md §2.1): cada grupo es la tarea que se busca y cada
// enlace se llama igual que el título de su página. Aquí no se fusiona ninguna pantalla: solo orden, nombres y grupos.
$portal = [
    'principal' => ['Principal', [['home', 'Inicio', '/'], ['users', 'Personas', '/usuarios.php']]],
    'equipos' => ['Equipos', [['devices', 'Equipos', '/equipos.php'], ['intake', 'Registrar equipos nuevos', '/alta.php'],
        ['assignments', 'Asignación de equipos', '/asignaciones.php', false], ['coverage', 'Cobertura de instalación', '/cobertura.php'],
        ['client-logs', 'Logs de los equipos', '/logs.php', false]]],
    'productividad' => ['Productividad', [['reports', 'Productividad', '/reportes.php'], ['sites', 'Comparar sedes', '/sedes.php', false]]],
    'alertas' => ['Alertas', [['dual-job', 'Doble empleo', '/doble-empleo.php'], ['suspicious-apps', 'Apps sospechosas', '/apps-sospechosas.php', false]]],
    'seguridad' => ['Seguridad de los equipos', [['protection', 'Resumen de protección', '/proteccion.php', false], ['policies', 'Reglas de apps, sitios y USB', '/reglas.php', false]]],
    'jornada' => ['Jornada', [['schedules', 'Horarios', '/horarios.php', false], ['holidays', 'Festivos', '/festivos.php', false],
        ['absences', 'Ausencias', '/ausencias.php', false], ['doors', 'Puertas', '/puertas.php', false]]],
    'accesos' => ['Estructura y accesos', [['organization', 'Estructura del cliente', '/organizacion.php', false],
        ['accounts', 'Administradores', '/cuentas.php', false], ['roles', 'Roles y permisos', '/roles.php', false]]],
    'configuracion' => ['Configuración', [['classification', 'Clasificación de apps', '/clasificacion.php', false],
        ['panel-settings', 'Ajustes del cliente', '/ajustes.php', false], ['audit', 'Auditoría', '/auditoria.php', false],
        ['onboarding', 'Puesta en marcha', '/puesta-en-marcha.php', false]]],
];
$platform = [['platform-home', 'Inicio de plataforma', '/admin/'], ['tenants', 'Clientes', '/empresas.php', false],
    ['plans', 'Qué vendemos', '/planes.php', false], ['releases', 'Versiones del agente', '/admin/releases.php'],
    ['server-health', 'Salud del servidor', '/admin/salud.php', false], ['portal-origins', 'Portal: direcciones autorizadas', '/admin/portal.php', false],
    ['sources', 'Fuentes de datos', '/fuentes.php', false], ['integrations', 'Integraciones (API)', '/integraciones.php', false]];
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
    <details class="nav-group" id="platform-group" data-group="plataforma"<?= $surface === 'admin' ? ' open' : ' hidden' ?>><summary class="nav-heading">Plataforma</summary>
<?php foreach ($platform as $l) { echo $link($l, $surface === 'admin'); } ?>
    </details>
<?php foreach ($portal as $key => [$heading, $links]): ?>
    <details class="nav-group" data-group="<?= $h($key) ?>" open><summary class="nav-heading"><?= $h($heading) ?></summary>
<?php foreach ($links as $l) { echo $link($l, $surface === 'portal'); } ?>
<?php if ($key === 'principal'): ?>
      <div id="group-group" hidden><?= $link(['group', 'Tablero del grupo', '/grupo.php', false], false) ?></div>
<?php endif; ?>
    </details>
<?php endforeach; ?>
  </nav>
  <div class="sidebar-bottom"><button type="button" id="logout">Cerrar sesión</button></div>
</aside>
<button class="backdrop" id="menu-backdrop" type="button" aria-label="Cerrar menú" hidden></button>
