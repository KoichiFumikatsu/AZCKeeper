# Control remoto del equipo — catálogo de visión (BACKLOG, sin specificar aún)

**Fecha:** 2026-07-31 · **Estado:** captura de intención de Koichi. NO es un spec aprobado ni un
plan; es el norte de hacia dónde queremos llevar el control remoto. Cuando se ataque, cada bloque
pasa por brainstorming → spec → plan.

## Lo que YA existe (no reinventar)

El **canal de comandos está construido y es extensible**:
- `keeper_device_command` (mig 06): `command_type`, `params_json`, `status`
  (pending→sent→acked→done/failed/expired/canceled), `created_by` (actor, auditado),
  `result_json`, `expires_at` (un comando viejo caduca, no se ejecuta días después).
- `CommandModule` (cliente per-user): hace polling (~30s) de comandos pendientes y ejecuta por
  `switch (command_type)`. Aísla errores por comando y reporta resultado.
- `AdminCommand::enqueue` (backend) + `ClientCommands` (poll/result) + `device-command.php` (panel).
- El **agente elevado** (`AZCKeeperAgent`, SYSTEM, proyecto aparte ya iniciado) es quien ejecuta lo
  que necesita privilegio (hoy: `rename_computer`).

**Agregar un control = (1) tipo nuevo, (2) handler en el cliente O en el agente elevado según el
privilegio, (3) botón en el panel, (4) — auditoría y expiración ya vienen gratis.**

## Doble candado del control remoto (decisión de Koichi, 2026-07-31)

El control remoto NO es libre: pasa por dos gates independientes.

1. **Tier (por firma) — ¿la firma lo tiene?** Ya modelado: el catálogo `keeper_module` tiene
   `remoteShutdown`, `deviceLock`, `networkDiagnostic`, `screenshots`, `location` (categorías
   control/data). La matriz tier×módulo (`tiers.php`) decide si la firma lo compró. Los nuevos
   controles (lock, restart, logoff, screenshot on-demand) se agregan como módulos de catálogo o
   se pliegan sobre los existentes (p.ej. `deviceLock` cubre bloquear pantalla; `remoteShutdown`
   cubre apagar/reiniciar/logoff).
2. **RBAC (por rol) — ¿este rol puede dispararlo?** Vía `keeper_panel_roles`/`panelCan`. Hoy
   `device-command.php` es solo IT/superadmin. Se añade un módulo de panel `remote-control`;
   **IT y el tier más alto lo tienen por defecto; supervisores, coordinadores y gerente PUEDEN
   tenerlo pero con el permiso DESMARCADO** (roles.php ya permite crear esos roles y marcar/
   desmarcar módulos). Regla efectiva: **firma con tier que lo incluya + rol con el permiso marcado.**

("Llamar" y "mensaje" quedan DESCARTADOS — eran ejemplos, no se implementan.)

## Catálogo deseado por Koichi

| Control | Estado hoy | Quién lo ejecuta | Notas / decisiones |
|---|---|---|---|
| **Apagar equipo** (`shutdown`) | PARCIAL: simulado en dev, código real comentado (`shutdown /s`) | Cliente per-user (apaga su sesión) | Falta: aviso al usuario + ventana de gracia. `expires_at` ya evita que un apagado viejo se ejecute tarde. |
| **Reiniciar** (`restart`) | NUEVO (trivial) | Cliente per-user | `shutdown /r`. |
| **Cerrar sesión** (`logoff`) | NUEVO (trivial) | Cliente per-user | `shutdown /l`. |
| **Bloquear pantalla** (`lock`) | NUEVO | Cliente per-user, **sin admin** | `user32.dll,LockWorkStation`. |
| **Tomar screenshot on-demand** (`screenshot_now`) | PARCIAL: `ScreenshotModule` captura+hashea; tipo en el esquema; falta cablear el comando + storage del blob (hoy es stub) | Cliente per-user | Depende del object storage pendiente (Nextcloud en cloud.azclegal.com). |
| **Diagnóstico de red** (`network_diag`) | EXISTE (real: ping 8.8.8.8 + DNS) | Cliente per-user | — |
| **Diagnóstico en vivo** (por persona) | EXISTE (2026-07-31, panel) | Cliente per-user | Se puede disparar también por comando. |
| ~~Mandar mensaje / llamar~~ | DESCARTADO | — | Eran ejemplos; Koichi los omite. |
| **Renombrar Windows** (`rename_computer`) | EXISTE | Agente elevado (admin + reboot) | Ya cableado. |
| Otros candidatos | — | según privilegio | matar proceso, ejecutar script acotado, reiniciar el agente/cliente, forzar update (ya vía releases). |

## Ejes de diseño que hay que decidir cuando se especifique

1. **Privilegio (per-user vs agente elevado).** La mayoría son per-user sin admin (lock, screenshot,
   message, logoff, shutdown de la sesión). Los que tocan TODAS las sesiones, el hostname, políticas
   HKLM o SRP van al **agente elevado**. Decidir por control.
2. **Latencia.** Los comandos se recogen por polling (~30s). Para que se sientan "inmediatos": acortar
   el poll cuando hay un comando pendiente, o colgarlos del modo diagnóstico en vivo (~4s).
3. **Guardas para destructivos.** Apagado/reinicio/logoff: confirmación en el panel, aviso al usuario
   con ventana de gracia, y `expires_at` (ya) para no ejecutar un comando rezagado.
4. **La superficie visible.** "Mensaje" y "llamar" son los ÚNICOS que rompen el diseño invisible del
   cliente BPO → exigen una UI deliberada. Definir cuál y si es bidireccional.
5. **Masivo vs 1-a-1.** ¿Algunos controles se lanzan a varios equipos / a una firma entera (p.ej.
   apagar todo al final del turno) o siempre 1-a-1? El canal soporta ambos; el panel decide.
6. **Object storage.** El screenshot on-demand necesita el almacén de blobs que sigue pendiente.

## Backlog aparte — Semáforo de PRESENCIA por persona (2026-07-31)

Koichi preguntó si existe el estado activa / ausente / desconectada de red / inactiva / sin keeper.
**Hoy NO existe como indicador unificado**; los ladrillos están dispersos:

| Estado deseado | Señal que ya existe | Dónde |
|---|---|---|
| **Sin keeper** (no instalado) | `never_enrolled` | Cobertura (`coverage.php`) ✅ |
| **Desconectada / offline** | `keeper_devices.last_seen_at` (último handshake) | usado para "stale +7d" en cobertura y en devices; **falta un "offline ahora"** (sin handshake en los últimos ~5-10 min) |
| **Inactiva / ausente** (idle) | inactividad del `WinIdleMonitor` | **solo se ve en vivo dentro del modo diagnóstico**; no hay estado de presencia fuera de ahí |
| **Activa** | online + input reciente | derivable de last_seen + idle |

**Falta juntarlos en un semáforo por persona.** Para que sea EN VIVO sin encender diagnóstico, el
cliente debería reportar la inactividad (segundos de idle / último input) en el **handshake normal**
(barato, ~1 campo). Con eso el panel calcula: sin-keeper (cobertura) → offline (last_seen viejo) →
ausente (online pero idle > umbral) → activo. Candidato natural: columna/semáforo en Usuarios o en
el dashboard, o una vista de presencia. Umbrales a definir (¿offline = >5 min sin handshake?,
¿ausente = >N min idle?).

## Preguntas abiertas (para el brainstorming cuando se ataque)

- "Llamar" = ¿telefonía real (UCM/troncal AZC) o solo un aviso/timbre? Es lo que más cambia el alcance.
- "Mensaje" = ¿toast discreto del sistema o ventana modal? ¿el usuario puede responder?
- Prioridad de los controles: ¿cuál primero? (lock + shutdown real + screenshot on-demand son los de
  menor esfuerzo y mayor uso inmediato; message/call son los grandes).
