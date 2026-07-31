<?php
/**
 * Aterrizaje de un rol sin ningún módulo habilitado. Existe para que panelLanding()
 * siempre tenga a dónde mandar: sin esta página, un rol recortado a cero entraría en
 * un bucle de redirección entre la página que le niega el acceso y su propio landing.
 */
require_once __DIR__ . '/admin_auth.php';   // $adminUser, $pdo
?><!DOCTYPE html>
<html lang="es" class="h-full bg-gray-50">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sin acceso — Keeper Admin</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>tailwind.config={theme:{extend:{colors:{corp:{800:'#003a5d',900:'#002b47'},accent:{500:'#be1622'},dark:'#353132',muted:'#9d9d9c'}}}}</script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
  <style>body{font-family:'Inter',sans-serif}</style>
</head>
<body class="h-full">
  <div class="min-h-full flex items-center justify-center px-4">
    <div class="w-full max-w-md bg-white rounded-2xl border border-gray-100 shadow-sm p-8 text-center">
      <div class="w-11 h-11 rounded-xl bg-gray-100 flex items-center justify-center mx-auto mb-4">
        <svg class="w-6 h-6 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
      </div>
      <h1 class="text-base font-semibold text-dark mb-1">Tu rol no tiene módulos habilitados</h1>
      <p class="text-sm text-muted mb-6">
        La cuenta <span class="font-medium text-gray-600"><?= htmlspecialchars($adminUser['email'] ?? '') ?></span>
        entró con el rol <span class="font-medium text-gray-600"><?= htmlspecialchars($adminUser['panel_role'] ?? '') ?></span>,
        que hoy no da acceso a ninguna sección. Pídele a un superadministrador que lo ajuste en Roles y cuentas.
      </p>
      <a href="logout.php" class="inline-block px-4 py-2 bg-corp-800 hover:bg-corp-900 text-white text-sm font-medium rounded-lg">Cerrar sesión</a>
    </div>
  </div>
</body>
</html>
