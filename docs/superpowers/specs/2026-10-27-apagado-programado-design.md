# Apagado y reinicio programados (Keeper v4) — diseño

Fecha: 2026-10-27. Aprobado por Koichi sección por sección en la sesión del 2026-10-27.

## Problema

Hay personas que nunca apagan el equipo. Hoy v4 solo permite apagar o reiniciar **un equipo, en el momento**, con aviso
opcional de 1, 5 o 10 min (`POST` de orden `shutdown`/`restart` con `notice_seconds`, agente ≥ 4.0.17,
`CommandExecutor` + `PowerNoticeWindow`). Faltan: un mensaje de TI, programar para una fecha/hora futura y un
programador semanal por alcance (empresa, sede, área, persona, equipo).

## Decisiones

| Tema | Decisión |
|---|---|
| Equipo en uso a la hora programada | Si está inactivo ≥ umbral, se ejecuta sin preguntar. Si está en uso, aviso con «Posponer» (una sola vez). Si pospone, cuando falten N minutos aparece la tarjeta abajo a la derecha, **sin** botón de posponer. |
| Acción | Apagar **o** reiniciar, por programación. |
| Exclusiones | Lista de excepciones en cada programación (equipos, personas, áreas). No hay marca global «nunca apagar». |
| Quién decide | El **agente** en el equipo (no órdenes encoladas por el servidor). El apagado puntual es una programación «Una vez». |
| Equipo suspendido | Ventana configurable (por defecto 3 h): si despierta dentro, misma lógica; fuera, se salta y se reporta. |
| Parámetros | Todo configurable por programación, con los valores por defecto de abajo. |

## 1. Qué configura TI en cada programación

| Campo | Valores | Defecto |
|---|---|---|
| `name` | texto 1–80 | — |
| `action` | `shutdown` / `restart` | `shutdown` |
| `recurrence` | `once` (fecha+hora) / `weekly` (días ISO 1–7, al menos uno, + hora) | — |
| `time_local` | `HH:MM` en la zona horaria de la empresa (`tenants.timezone`) | — |
| `run_on` | fecha (solo `once`) | — |
| `valid_from` / `valid_until` | fechas; `valid_until` opcional (solo `weekly`) | hoy / sin fin |
| Alcance | uno o más de: empresa, sede, área, persona, equipo (mismo modelo que `policy_assignments`) | — |
| Excepciones | equipos, personas, áreas | ninguna |
| `message` | texto 0–280, sin control | «TI programó el apagado de este equipo.» / «…el reinicio…» |
| `idle_minutes` | 5–120 | 15 |
| `notice_minutes` | 1–30 | 10 |
| `postpone_minutes` | `null` (sin posponer) o 15–240 | 60 |
| `window_minutes` | 0–720 | 180 |
| `status` | `active` / `paused` | `active` |

Reglas:
- Dos programaciones que coinciden en el **mismo minuto** para un equipo: se ejecuta una vez; gana `restart`.
  En minutos distintos, cada una sigue su curso.
- El apagado puntual desde la ficha del equipo abre este mismo formulario prellenado (`once`, ese equipo).
- Permisos: programaciones `shutdown` exigen `equipos.apagar`; `restart`, `equipos.reiniciar`; función contratada
  `comandos` (igual que `COMMAND_FEATURES`). Cada alcance/excepción elegido debe estar dentro del alcance de quien la
  crea (`scope_denied` si no). Crear, editar, pausar y borrar se auditan.

## 2. Arquitectura

### Servidor
- Migración nueva: `power_schedules` (campos de §1 + `tenant_id`, `id`, `version`, `created_by`, `created_at`,
  `updated_at`) y `power_schedule_targets` (`schedule_id`, `kind` ∈ tenant/site/area/user/device, `ref_id`,
  `excluded` bool). Tabla de resultados `power_schedule_runs` (`schedule_id`, `device_id`, `occurrence_at` UTC,
  `outcome`, `detail`, `reported_at`), clave única (`schedule_id`, `device_id`, `occurrence_at`).
- API admin (`/v1/admin/power-schedules`): listar, crear, ver, editar (con `If-Match`), pausar/reanudar, borrar;
  `GET …/{id}/runs` con la última ejecución por equipo. Contrato en `docs/architecture/openapi-v4.yaml`.
- Sincronización del agente: el servidor resuelve las programaciones que aplican **a ese equipo** (alcance menos
  excepciones, `status=active`, vigentes) y las envía resueltas (`power_schedules[]`: id, version, action, recurrence,
  days, time_local, run_on, valid_from/until, timezone, message, idle/notice/postpone/window). El agente nunca ve
  listas de excepciones. Se incluye `server_time` UTC.
- Solo se envía a agentes **≥ 4.0.30** (los anteriores rechazan campos desconocidos y dejarían de sincronizar). Para
  equipos más viejos el panel muestra «no soportado: actualizar agente».
- Endpoint del agente para reportar resultados (lote, idempotente por la clave única).

### Agente (servicio) — módulo `PowerScheduler`
- Tick cada 30 s. Calcula la próxima ocurrencia de cada programación en la zona horaria de la empresa. Usa
  `server_time` de la última sincronización si el reloj local difiere > 5 min.
- Al llegar la hora (o al despertar dentro de `window_minutes`):
  - consulta a cada sesión abierta los segundos desde la última entrada (`GetLastInputInfo`) por IPC;
  - sin sesiones, o todas inactivas ≥ `idle_minutes` → ejecuta con `shutdown.exe` (camino existente);
  - alguna activa → aviso con mensaje, cuenta regresiva `notice_minutes`, «Posponer» (si aplica y no se usó) y
    «Apagar/Reiniciar ahora». Al posponer: nueva hora = ahora + `postpone_minutes`; a `notice_minutes` de esa hora
    aparece la tarjeta compacta sin posponer; al llegar a 0 se ejecuta.
- Estado en curso (ocurrencia, hora efectiva, pospuesto sí/no) persistido en ProgramData para sobrevivir a reinicios
  del agente o de la sesión sin perder ni duplicar.
- Despertar fuera de la ventana → no hace nada, reporta `skipped_suspended`.

### Sesión (lo que ve la persona)
- Responde la consulta de inactividad.
- `PowerNoticeWindow` gana: mensaje de TI, botón «Posponer N» y botón «Apagar ahora»/«Reiniciar ahora».

### Resultados (panel)
`outcome` ∈ `executed_idle`, `executed_after_notice`, `postponed`, `executed_by_person` (pulsó «ahora»),
`cancelled_by_it`, `replaced` (orden manual), `skipped_suspended`, `not_needed` (equipo apagado antes),
`unsupported`. El panel muestra por programación la última ejecución por equipo.

## 3. Casos límite

| Caso | Comportamiento |
|---|---|
| Reloj del equipo desfasado (hay equipos con 4–7 h) | Se usa `server_time` si difiere > 5 min. |
| Equipo bloqueado por TI (DeviceLock) | Se ejecuta; el bloqueo no cuenta como uso. |
| Orden manual de apagar/reiniciar durante un aviso programado | Gana la manual; la programada → `replaced`. |
| TI pausa/edita/borra con aviso en pantalla | En la siguiente sync el aviso se cancela («TI canceló…») → `cancelled_by_it`. |
| Varias sesiones (escritorio remoto) | Basta una activa para avisar; posponer desde cualquiera aplica a todas. |
| Equipo apagado antes de la hora | Esa ocurrencia → `not_needed`. |
| Portátil con batería baja | Sin cambios. |

## 4. Pruebas
- Servidor (suite MariaDB): validación de campos, resolución alcance/excepciones, permisos + alcance, filtro de versión
  ≥ 4.0.30, auditoría, idempotencia de resultados, contrato exportado.
- Agente (reloj simulado): ocurrencias por días/vigencia/zona horaria/cambio de día, ventana de suspensión, inactivo vs
  activo, posponer una sola vez, persistencia tras reinicio del agente, conflicto con orden manual, desfase de reloj.
  Sin apagar Windows de verdad (acción inyectada).
- Prueba real en un equipo con una programación `once` a 5 minutos antes de desplegar.

## 5. Despliegue (cada paso con autorización de Koichi)
1. Servidor + panel (sin efecto hasta que haya agentes 4.0.30).
2. Agente 4.0.30 al anillo piloto, luego a todos.
3. Primera programación real («viernes 19:00, apagar») en una sola sede.
