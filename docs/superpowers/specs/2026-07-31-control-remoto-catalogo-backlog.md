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
| **Mandar mensaje al usuario** | NUEVO | Cliente per-user | **ROMPE la premisa invisible** → necesita una superficie de UI deliberada (toast del sistema, o ventanita). ¿Unidireccional o el usuario responde? |
| **Llamar al usuario** | NUEVO, GRANDE | ¿? | Definir qué es "llamar": ¿VoIP por la telefonía AZC (UCM6308/troncal), abrir Teams, o un timbre/aviso? Scope mucho mayor; probablemente su propio proyecto. |
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

## Preguntas abiertas (para el brainstorming cuando se ataque)

- "Llamar" = ¿telefonía real (UCM/troncal AZC) o solo un aviso/timbre? Es lo que más cambia el alcance.
- "Mensaje" = ¿toast discreto del sistema o ventana modal? ¿el usuario puede responder?
- Prioridad de los controles: ¿cuál primero? (lock + shutdown real + screenshot on-demand son los de
  menor esfuerzo y mayor uso inmediato; message/call son los grandes).
