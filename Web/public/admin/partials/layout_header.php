<?php
/**
 * Layout base del panel admin.
 *
 * Variables esperadas:
 *   $pageTitle   → título de la pestaña
 *   $currentPage → slug para sidebar ('dashboard', 'users', etc.)
 *   $adminUser   → array del admin autenticado (viene de admin_auth.php)
 */
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Keeper Admin') ?></title>
    <link rel="icon" href="assets/icoMain.png" type="image/png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'corp': {
                            50:  '#f0f7fb',
                            100: '#dceef5',
                            200: '#b8dcea',
                            300: '#85c3db',
                            400: '#4ba3c5',
                            500: '#2d87ad',
                            600: '#236c92',
                            700: '#1f5977',
                            800: '#003a5d',
                            900: '#002b47',
                            950: '#001b2e',
                        },
                        'accent': {
                            500: '#be1622',
                            600: '#a0121d',
                            700: '#821019',
                        },
                        'dark': '#353132',
                        'muted': '#9d9d9c',
                    }
                }
            }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .sidebar-link.active { background-color: rgba(0, 58, 93, 0.08); color: #003a5d; font-weight: 600; }
        .sidebar-link:hover { background-color: rgba(0, 58, 93, 0.05); }
    </style>
</head>
<body class="h-full" x-data="{ sidebarOpen: false }">
    <div class="min-h-full flex">

        <!-- Overlay backdrop (mobile) -->
        <div x-show="sidebarOpen" @click="sidebarOpen = false"
             class="fixed inset-0 z-30 bg-black/40 lg:hidden"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
             style="display:none"></div>

        <!-- Sidebar -->
        <aside class="w-64 bg-white border-r border-gray-200 fixed inset-y-0 left-0 z-40 flex flex-col
                      transform transition-transform duration-200 ease-in-out
                      -translate-x-full lg:translate-x-0"
               :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">
            <!-- Logo -->
            <div class="h-14 lg:h-16 flex items-center justify-between px-6 border-b border-gray-100">
                <div class="flex items-center gap-3">
                    <img src="assets/icoMain.png" alt="Keeper" class="w-8 h-8">
                    <div>
                        <span class="text-lg font-bold text-corp-800">Keeper</span>
                        <span class="text-xs text-muted block -mt-1">Panel Admin</span>
                    </div>
                </div>
                <button @click="sidebarOpen = false" class="lg:hidden p-1.5 rounded-lg text-muted hover:text-dark hover:bg-gray-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Nav (dinámico según keeper_panel_settings) -->
            <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto" @click.delegate="if($event.target.closest('a')) sidebarOpen = false">
                <?php if (canAccessModule('dashboard')): ?>
                <a href="index.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    Dashboard
                </a>
                <?php endif; ?>
                <?php if (canAccessModule('sedes-dashboard')): ?>
                <a href="sedes-dashboard.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'sedes-dashboard' ? 'active' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    Sedes
                </a>
                <?php endif; ?>
                <?php if (canAccessModule('users')): ?>
                <a href="users.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'users' ? 'active' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    Usuarios
                </a>
                <?php endif; ?>
                <?php if (canAccessModule('devices')): ?>
                <a href="devices.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'devices' ? 'active' : '' ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    Dispositivos
                </a>
                <?php endif; ?>

                <?php
                // Sección avanzada: se muestra si el usuario puede ver al menos un módulo de esta sección
                $advModules = ['policies','releases','admin-users','assignments','organization','roles','settings','server-health'];
                $showAdvanced = false;
                foreach ($advModules as $_m) { if (canAccessModule($_m)) { $showAdvanced = true; break; } }
                if ($showAdvanced):
                ?>
                <div class="pt-4 mt-4 border-t border-gray-100">
                    <p class="px-3 text-xs font-semibold text-muted uppercase tracking-wider mb-2">Gestión avanzada</p>
                    <?php if (canAccessModule('policies')): ?>
                    <a href="policies.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'policies' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Políticas
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('releases')): ?>
                    <a href="releases.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'releases' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M9 19l3 3m0 0l3-3m-3 3V10"/></svg>
                        Releases
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('admin-users')): ?>
                    <a href="admin-users.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'admin-users' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Administradores
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('assignments')): ?>
                    <a href="assignments.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'assignments' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                        Asignaciones
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('organization')): ?>
                    <a href="organization.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'organization' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
                        Organización
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('roles')): ?>
                    <a href="roles.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'roles' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        Roles
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('settings')): ?>
                    <a href="panel-settings.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'settings' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                        Configuración
                    </a>
                    <?php endif; ?>
                    <?php if (canAccessModule('server-health')): ?>
                    <a href="server-health.php" class="sidebar-link flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-gray-600 transition-colors <?= ($currentPage ?? '') === 'server-health' ? 'active' : '' ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                        Salud del Servidor
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </nav>

            <!-- User info -->
            <div class="px-4 py-3 border-t border-gray-100">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-corp-100 rounded-full flex items-center justify-center">
                        <span class="text-sm font-semibold text-corp-800"><?= strtoupper(substr($adminUser['display_name'] ?? 'U', 0, 1)) ?></span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-dark truncate"><?= htmlspecialchars($adminUser['display_name'] ?? '') ?></p>
                        <p class="text-xs text-muted truncate"><?= htmlspecialchars($adminUser['panel_role'] ?? '') ?></p>
                    </div>
                    <a href="logout.php" class="text-muted hover:text-accent-500 transition-colors" title="Cerrar sesión">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    </a>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 lg:ml-64 overflow-x-hidden">
            <header class="h-14 lg:h-16 bg-white border-b border-gray-200 flex items-center justify-between px-4 lg:px-8 sticky top-0 z-20">
                <div class="flex items-center gap-3">
                    <!-- Hamburger (mobile) -->
                    <button @click="sidebarOpen = true" class="lg:hidden p-1.5 -ml-1 rounded-lg text-muted hover:text-dark hover:bg-gray-100 transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                    </button>
                    <h1 class="text-base lg:text-lg font-semibold text-dark"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
                </div>
                <div class="flex items-center gap-4">
                    <span class="text-xs text-muted hidden sm:inline"><?= date('d/m/Y H:i') ?></span>
                    <span class="text-xs text-muted sm:hidden"><?= date('d/m') ?></span>
                </div>
            </header>

            <main class="p-4 lg:p-8">
