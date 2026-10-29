# Ubicación — revisión del código contra el diseño (2026-10-29)

Revisión en modo manual autorizada por Koichi. Código revisado: rama `deploy-apagado` (commits de Ubicación
`e5e00ce..a52993e`, hechos por Codex). Diseño de referencia: `2026-10-20-ubicacion-equipo.md` con el cambio del
2026-10-29 (solo en horario laboral; fuera de horario solo con caso perdido/robado).

## 1. Qué datos toma

| Dato | Dónde se obtiene | Código |
|---|---|---|
| Latitud, longitud, precisión (m) | `Keeper.Session` (sesión de la persona), `Geolocator` de Windows, precisión pedida 100 m, espera máx. 8 s; se descarta si la lectura difiere > 120 s de la hora del punto | `Keeper.Session/Modules/Location/LocationTracker.cs` `ReadAsync` |
| SSID y BSSID de la Wi-Fi | `wlanapi.dll`, solo si Windows ya concede `wiFiControl` (no pide permiso) | `LocationTracker.cs` `Wifi()` |
| Tipo de red (`wifi` / `cable` / `unknown`) | Interfaces de red activas | `Keeper.Shared/Contracts/LocationCapture.cs` `LocationNetwork.Type` |
| IP pública | La toma el servidor de la conexión del sync (no la manda el agente) | `backend-v4/src/DeviceLocations.php` `publicIp` |
| Persona | La asignada al equipo en ese instante | `DeviceLocations.php` `ingest` |

Además el agente **pone en «Permitir» la ubicación de Windows para todo el equipo** (consentimiento general y de
aplicaciones de escritorio, servicio `lfsvc`) y la revisa cada 2 min; guarda los valores anteriores y los devuelve al
apagar la regla (`Keeper.Shared/Location/LocationSettings.cs`). Efecto lateral: otras aplicaciones de escritorio del
equipo también pueden pedir ubicación mientras la regla esté activa. Equipos en dominio: no se toca nada.

## 2. Cada cuánto

Un punto cada 15 min y uno en cada cambio de red (`Keeper.Agent/Modules/Location/LocationModule.cs` `TickAsync`). Si la
sesión no responde en 20 s, el punto sale solo con el tipo de red (sin posición ni Wi-Fi). **Hoy no mira el horario:
toma 24/7** (correcto para el diseño viejo, incorrecto para el del 2026-10-29).

## 3. Dónde se guarda

- En el equipo: cola durable del agente (ProgramData), máx. 100 puntos por sync; `location-settings.json` con la
  configuración previa de Windows.
- En el servidor: tabla `device_locations` (migración `0068_device_locations.sql`) en la BD de devkeep; `in_work_hours`
  se calcula al guardar con el horario de la persona **sin margen** (`DeviceLocations.php` `workHours`).

## 4. Quién lo ve

- Panel: función `ubicacion` contratada + permiso `presencia.ubicacion_ver` + alcance del admin → solo puntos en horario.
- «Incluir fuera de horario»: solo admins de toda la empresa (`AdminAccess::full`) y superadmin; cada consulta se audita
  (`location.out_of_hours_read`).
- API externa (`externalLocations`): exige `location:read` (que a su vez exige `presence:read`) e
  `include_location=true`; toda la empresa, sin fuera de horario.
- Marcar/cerrar «perdido/robado»: el panel lo muestra con `equipos.editar`; el servidor solo exige alcance sobre la
  persona. Auditado (`location.lost_opened` / `location.lost_closed`).

## 5. Cuánto se conserva

90 días, siempre (aunque la empresa no tenga la retención en modo «aplicar»); los equipos con caso perdido/robado
abierto no se purgan (`DeviceLocations.php` `purge`, llamado desde `RetentionWorker`). El servidor rechaza puntos de
más de 90 días salvo caso abierto.

## 6. Diferencias con el diseño del 2026-10-29 (a corregir)

| # | Hallazgo | Severidad |
|---|---|---|
| 1 | El agente toma puntos 24/7; no existen `location_windows` / `location_always` | Bloqueante |
| 2 | El servidor guarda puntos fuera de horario sin caso abierto (no hay descarte) | Bloqueante |
| 3 | `in_work_hours` sin margen de ± 30 min | Alta |
| 4 | Marcar perdido/robado no exige admin de toda la empresa (solo alcance + `equipos.editar` en el panel) | Alta |
| 5 | Texto del panel: «también fuera del horario laboral» (`location.js:95`) | Media |
| 6 | «Incluir fuera de horario» se ofrece aunque el equipo nunca haya tenido caso | Media |
| 7 | `public_ip` guarda la IP del envío aunque el punto se haya tomado antes en otra red (`ip_at_capture=0`); no afecta la clasificación pero se muestra como dato del punto | Baja |
| 8 | Superadmin de plataforma ve la ubicación aunque la empresa no tenga contratada la función | Baja (decisión) |

## 7. Lo que está bien

- Sin regla activa no se toma nada: `location_tracking` está apagado por defecto y solo llega a agentes ≥ 4.0.29
  con la función contratada (`PolicyCompiler`, `PolicyComposer`). **Desplegar no enciende la ubicación por sí solo.**
- La Wi-Fi no se lee sin permiso previo de Windows (no aparece aviso a la persona).
- La coordenada nunca se inventa; ambigüedades de sede no eligen sede al azar.
- Reversión de la configuración de Windows al apagar la regla.
