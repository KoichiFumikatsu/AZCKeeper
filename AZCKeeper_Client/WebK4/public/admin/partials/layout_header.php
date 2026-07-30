<?php
/**
 * Layout base del panel admin K4 — identidad visual del panel K3 (marca AZC/lawyerdesk):
 * Tailwind + Inter, sidebar blanco, corp navy #003a5d, acento rojo #be1622.
 * Variables esperadas: $pageTitle, $currentPage (slug), $adminUser (de admin_auth.php).
 */
$cur = $currentPage ?? '';
function navLink(string $href, string $slug, string $label, string $svg, string $cur, array $adminUser, string $mod): void {
    if (!panelCan($adminUser, $mod)) return;
    $active = $cur === $slug ? 'active' : '';
    echo "<a href=\"{$href}\" class=\"sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors {$active}\">{$svg}<span>{$label}</span></a>";
}
?><!DOCTYPE html>
<html lang="es" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Keeper Admin') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
      tailwind.config = { theme: { extend: { colors: {
        corp: {50:'#f0f7fb',100:'#dceef5',200:'#b8dcea',300:'#85c3db',400:'#4ba3c5',500:'#2d87ad',600:'#236c92',700:'#1f5977',800:'#003a5d',900:'#002b47',950:'#001b2e'},
        accent: {500:'#be1622',600:'#a0121d',700:'#821019'}, dark:'#353132', muted:'#9d9d9c' } } } }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
      body{font-family:'Inter',sans-serif}
      .sidebar-link.active{background-color:rgba(0,58,93,.08);color:#003a5d;font-weight:600}
      .sidebar-link:hover{background-color:rgba(0,58,93,.05)}
    </style>
</head>
<body class="h-full" x-data="{ sidebarOpen:false }">
<div class="min-h-full flex">
  <div x-show="sidebarOpen" @click="sidebarOpen=false" class="fixed inset-0 z-30 bg-black/40 lg:hidden" style="display:none"></div>

  <!-- Sidebar -->
  <aside class="w-64 bg-white border-r border-gray-200 fixed inset-y-0 left-0 z-40 flex flex-col transform transition-transform duration-200 -translate-x-full lg:translate-x-0"
         :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
    <div class="h-16 flex items-center px-6 border-b border-gray-100">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 rounded-lg bg-corp-800 flex items-center justify-center">
          <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l7 3v5c0 4.4-3 8-7 10-4-2-7-5.6-7-10V6l7-3z"/></svg>
        </div>
        <div><span class="text-lg font-bold text-corp-800">Keeper</span><span class="text-xs text-muted block -mt-1">Panel Admin</span></div>
      </div>
    </div>

    <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
      <?php
      navLink('index.php','dashboard','Flota y seguridad','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>',$cur,$adminUser,'dashboard');
      navLink('process-view.php','process-view','Vista de procesos','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>',$cur,$adminUser,'process-view');
      navLink('users.php','users','Usuarios','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>',$cur,$adminUser,'users');
      // Accesos pendientes: con badge de conteo (enrolamientos por aprobar).
      if (panelCan($adminUser,'pending')) {
          $pend = 0;
          try { $pend = (int)$pdo->query("SELECT COUNT(*) FROM keeper_users WHERE status='pending'")->fetchColumn(); } catch (\Throwable $e) {}
          $active = ($cur==='pending') ? 'active' : '';
          $badge = $pend>0 ? "<span class=\"badge ml-auto min-w-[1.25rem] h-5 px-1 inline-flex items-center justify-center text-xs rounded-full bg-accent-500 text-white\">{$pend}</span>" : '';
          echo "<a href=\"pending-users.php\" class=\"sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors {$active}\"><svg class=\"w-5 h-5\" fill=\"none\" stroke=\"currentColor\" viewBox=\"0 0 24 24\"><path stroke-linecap=\"round\" stroke-linejoin=\"round\" stroke-width=\"1.5\" d=\"M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z\"/></svg><span>Accesos pendientes</span>{$badge}</a>";
      }
      navLink('devices.php','devices','Dispositivos','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',$cur,$adminUser,'devices');
      ?>
      <?php if (panelCan($adminUser,'tiers') || panelCan($adminUser,'coverage') || panelCan($adminUser,'dual-job') || panelCan($adminUser,'audit')): ?>
      <div class="pt-4 mt-4 border-t border-gray-100">
        <p class="px-3 text-xs font-semibold text-muted uppercase tracking-wider mb-2">Gestión avanzada</p>
        <?php
        navLink('tiers.php','tiers','Tiers y módulos','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z"/></svg>',$cur,$adminUser,'tiers');
        navLink('policies.php','policies','Políticas','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',$cur,$adminUser,'policies');
        navLink('coverage.php','coverage','Cobertura','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',$cur,$adminUser,'coverage');
        navLink('dual-job.php','dual-job','Doble empleo','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>',$cur,$adminUser,'dual-job');
        navLink('audit.php','audit','Auditoría','<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',$cur,$adminUser,'audit');
        ?>
      </div>
      <?php endif; ?>
    </nav>

    <div class="px-4 py-3 border-t border-gray-100">
      <div class="flex items-center gap-3">
        <div class="w-8 h-8 bg-corp-100 rounded-full flex items-center justify-center">
          <span class="text-sm font-semibold text-corp-800"><?= strtoupper(substr($adminUser['display_name'] ?? $adminUser['email'] ?? 'U', 0, 1)) ?></span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($adminUser['display_name'] ?? $adminUser['email'] ?? '') ?></p>
          <p class="text-xs text-muted truncate"><?= htmlspecialchars($adminUser['panel_role'] ?? '') ?></p>
        </div>
        <a href="logout.php" class="text-muted hover:text-accent-500 transition-colors" title="Cerrar sesión">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
        </a>
      </div>
    </div>
  </aside>

  <!-- Main -->
  <div class="flex-1 lg:ml-64 overflow-x-hidden">
    <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-4 lg:px-8 sticky top-0 z-20">
      <div class="flex items-center gap-3">
        <button @click="sidebarOpen=true" class="lg:hidden p-1.5 -ml-1 rounded-lg text-muted hover:text-dark hover:bg-gray-100">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
        </button>
        <h1 class="text-base lg:text-lg font-semibold text-dark"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
      </div>
      <span class="text-xs text-muted"><?= date('d/m/Y H:i') ?></span>
    </header>
    <main class="p-4 lg:p-8">
