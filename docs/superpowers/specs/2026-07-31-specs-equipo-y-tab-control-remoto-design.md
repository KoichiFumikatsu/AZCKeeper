# Keeper 4 — Specs del equipo (Vista de procesos) + Tab "Control remoto"

**Fecha:** 2026-07-31 · **Rama:** `feature/modulo-seguridad` · **DEV only.** Aprobado por Koichi.

## A. Specs del equipo en la Vista de procesos

El cliente recoge las especificaciones del equipo (una vez al arrancar) y se muestran como una
tarjeta "Equipo" en `process-view.php`, para que un manager vea con qué máquina trabaja la persona
SIN acceso a Dispositivos ni al control.

**Recolección (cliente):** SO+versión, CPU, RAM total, disco (total/libre), nombre de máquina,
fabricante/modelo, serial, IP local, MAC, GPU, monitores (cantidad+resolución). WMI
(`System.Management`) para fabricante/modelo/serial/GPU/RAM; DriveInfo (disco), NetworkInterface
(IP/MAC), `Screen.AllScreens` (monitores), registro/Environment (CPU/SO/host).

**Envío:** en el PRIMER handshake exitoso de cada arranque (las specs no cambian; se refrescan al
reiniciar/actualizar). CoreService lleva un flag `_specsReported`. K4ApiClient.HandshakeAsync gana
`object? specs`. Nada de reenvío en cada ciclo.

**Almacenamiento (mig 19):** `keeper_devices.specs_json JSON NULL, specs_at DATETIME NULL`.
ClientHandshake lee `specs` y `DeviceRepo::saveSpecs` lo guarda.

**Vista:** tarjeta "Equipo" en process-view (del equipo activo más reciente de la persona).
**IP/MAC solo para IT/superadmin** (rol in ['superadmin','it']); los admins de firma no ven esa fila.

## B. Tab "Control remoto" (reemplaza los botones en Dispositivos)

Página nueva `remote-control.php`. Modelo de acceso (decisión de Koichi): **el control remoto es
una capacidad que se VENDE por tier.**

- **Ver el tab:** rol con el permiso `remote-control` (RBAC editable; puede darse a supervisor/
  coordinador/gerente de una firma, no solo IT).
- **Contenido:** lista de personas/equipos del ALCANCE (scope por firma) cuyo **tier de la firma
  incluye** algún módulo de control. Un admin de firma ve SOLO su firma, y solo si la firma lo compró.
  Superadmin/IT ven todas las firmas que lo tengan.
- **Datos por fila:** persona, equipo (label/nombre), presencia (semáforo), versión del cliente,
  última conexión.
- **Botones grandes por fila:** Reiniciar, Apagar, Bloquear (cada uno visible solo si el tier de esa
  firma incluye su módulo: `remoteShutdown` para reiniciar/apagar, `deviceLock` para bloquear),
  y **Tomar screenshot** DESACTIVADO (gris + tooltip "disponible cuando se habilite el
  almacenamiento"). Destructivos con confirm() + csrf. Postean a `device-command.php` (mismo gate
  server-side RBAC+tier).
- **Nav:** navLink `remote-control.php` en "Gestión avanzada". `panelLanding` mapea
  `remote-control => remote-control.php`.

**Quitar de Dispositivos:** se elimina el bloque de botones de control (lock/shutdown/restart/logoff)
de `devices.php`; Dispositivos vuelve a ser gestión técnica (etiqueta/renombrar/revocar). El caché
`$firmMods`/`TierResolver` que se agregó para los botones se retira de devices.php.

## Alcance / no-alcance
Entra: colector de specs + System.Management, mig 19, specs en handshake, tarjeta Equipo (IP/MAC
IT-only), remote-control.php, nav, quitar botones de devices.php. NO entra: screenshot on-demand real
(botón desactivado), object storage.

## Verificación (devkeep)
1. Specs: correr el cliente → tarjeta Equipo en process-view con datos reales; IP/MAC oculto a gerente.
2. Tab: superadmin ve el tab con equipos de firmas con tier; gerente sin permiso no lo ve; con permiso
   y firma con tier, ve su firma; botones respetan el tier; screenshot desactivado; enqueue OK.
3. Dispositivos ya no muestra botones de control.

## Archivos
Cliente: `Platform/Platform.cs` (interfaz IDeviceSpecs) o nuevo contrato, `Platform/WinDeviceSpecs.cs`
(nuevo), `Core/CoreService.cs`, `Core/K4ApiClient.cs`, `Program.cs`, `AZCKeeper.K4.csproj` (+System.Management).
Backend: `Web/migrations/keeper4/19_device_specs.sql`, `ClientHandshake.php`, `DeviceRepo.php`.
Panel: `process-view.php` (tarjeta), `remote-control.php` (nuevo), `partials/layout_header.php` (nav),
`admin_auth_helpers.php` (panelLanding), `devices.php` (quitar botones + caché).
