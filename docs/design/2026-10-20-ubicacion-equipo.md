# Ubicación del equipo — diseño (2026-10-20)

Función del catálogo `ubicacion` (Features.php, grupo Presencia, nivel persona, permiso `presencia.ubicacion_ver`),
hoy `pending => true` y contratable por decisión de Koichi (2026-10-20). Este documento define cómo se construye.

## 1. Decisiones de Koichi (2026-10-20)

| Tema | Decisión |
|---|---|
| Objetivo | (A) saber si la persona está en su sede o fuera, y (C) la posición exacta del equipo |
| Cuándo se toma | **Solo en horario laboral** (cambio 2026-10-29, acordado con la dirección). Fuera de horario solo si el equipo está marcado «perdido/robado»: la marca enciende la toma 24/7 hasta cerrar el caso |
| Horario laboral | Horario de la persona en Keeper ± 30 min, en la zona de su horario. Festivos, días no laborables y ausencias aprobadas = fuera de horario. Persona sin horario = no se toma |
| Ubicación de Windows | El agente la mantiene encendida (como hoy la hora); se puede activar/desactivar desde el panel |
| Permiso para sacar el equipo | Se gestiona fuera de Keeper. Keeper no registra autorizaciones ni genera alertas de salida |
| Quién ve fuera de horario | Solo existen puntos fuera de horario de equipos marcados «perdido/robado»; los ven superadmin y admins de toda la empresa, y cada consulta se audita. Los supervisores con «Ver ubicación» ven solo puntos en horario laboral |
| Quién marca «perdido/robado» | Superadmin y admins de toda la empresa, con motivo escrito; marcar y cerrar el caso se auditan |
| Frecuencia | Un punto cada 15 min + un punto cada vez que cambia la red del equipo |
| Retención | 90 días; los puntos de un equipo marcado «perdido/robado» se conservan mientras el caso siga abierto |
| Detección de sede | Combinada: primero la red (IP pública de salida / Wi-Fi de la sede), si no coincide la geocerca de la sede |
| Mapa | Sin mapa embebido en esta versión: enlace «Ver en mapa» que abre Google Maps en otra pestaña |

**Riesgo legal y su mitigación.** La ubicación es dato personal (Ley 1581 de 2012). Desde 2026-10-29 fuera de horario no
se recoge nada salvo en un caso abierto de perdido/robado (fin informado: antirrobo); así no existe dato fuera de horario
que pueda verse sin necesidad. Cada marca, cierre de caso y consulta fuera de horario queda en la auditoría (quién, de qué
persona/equipo, cuándo). La autorización escrita de cada colaborador (acta de entrega) la gestiona RR.HH./legal; el
software no la sustituye.

## 2. Agente (versión nueva, módulo `Location`)

Solo actúa si la política trae `location_tracking: true` para ese equipo.

1. **Mantener la ubicación de Windows encendida** (servicio con SYSTEM): servicio de ubicación (`lfsvc`) en marcha y el
   consentimiento de ubicación del equipo y de «aplicaciones de escritorio» en Permitir. Cada 2 min revisa; si alguien lo
   apagó lo repone y reporta `location_restored`. Nada en equipos de dominio (criterio de TimeSync). Al apagar la regla
   devuelve los valores que había antes (guardados en disco del agente, como el resto de módulos de endurecimiento).
2. **Decidir si puede tomar el punto:** solo dentro de una ventana `location_windows` recibida del servidor (lista de
   intervalos UTC de las próximas 48 h, ya resueltos con horario ± 30 min, festivos y ausencias), o siempre si el servidor
   envía `location_always: true` (equipo perdido/robado). Si la última lista recibida tiene más de 48 h, no toma nada
   (fallo cerrado). Usa `server_time` del sync si el reloj local difiere > 5 min. Fuera de ventana tampoco lee SSID/BSSID.
3. **Tomar el punto** cada 15 min y en cada cambio de red, solo si el paso 2 lo permite:
   - Posición (latitud, longitud, precisión en m): la obtiene `Keeper.Session` (sesión del usuario) con la API de
     geolocalización de Windows y la pasa al servicio por el canal existente. Si Windows no da posición (escritorio con
     cable), el punto va «sin posición»; nunca se inventa una coordenada.
   - Red: SSID y BSSID de la Wi-Fi conectada, o `cable`.
4. **Envío** por el sync existente y el outbox durable (sin internet, los puntos esperan y salen al reconectar).
5. Sin interfaz visible para el usuario.

**A verificar en piloto (Windows real):** que `Keeper.Session` obtiene posición sin aviso al usuario con el consentimiento
del equipo en Permitir; que la lectura de SSID/BSSID funciona en Windows 11 24H2 (exige permiso de ubicación); comportamiento
en escritorios sin Wi-Fi; que reponer la configuración no choca con políticas locales.

## 3. Servidor

**Migración nueva:**
- `device_locations` (tenant, id, device_id, user_id, at UTC, lat/lon/accuracy_m opcionales, wifi_ssid, wifi_bssid o
  `cable`, public_ip, result `en_sede|fuera|sin_dato`, site_id, matched_by `red|geocerca`, in_work_hours). Índices por
  (tenant, user, at) y (tenant, device, at).
- Sedes: lista de IPs de salida y de redes Wi-Fi (SSID/BSSID); coordenadas y radio opcionales.
- Equipo: `lost_at`, `lost_by`, `lost_reason` (marca «perdido/robado»).

**IP pública:** la toma el servidor de la conexión del sync (detrás de nginx, cabecera del proxy de confianza); no la manda el
agente.

**Clasificación al ingerir** (orden): (1) IP o Wi-Fi de una sede → `en_sede` por `red`; (2) posición dentro de la geocerca
y precisión < radio → `en_sede` por `geocerca`; (3) hay posición o red sin coincidencia → `fuera`; (4) nada → `sin_dato`.
`in_work_hours` con la zona del horario de la persona (como la productividad). Al editar las redes o la geocerca de una
sede se reclasifican solo los últimos 7 días.

**Ventanas de toma (sync del agente):** el servidor calcula y envía `location_windows` (intervalos UTC de las próximas
48 h: horario de la persona ± 30 min, sin festivos, días no laborables ni ausencias aprobadas) y `location_always`
(= equipo con `lost_at` vigente). Persona sin horario → lista vacía. Mismo corte por versión que `location_tracking`.

**Descarte al ingerir (defensa en el servidor):** un punto con `at` fuera de horario de un equipo sin `lost_at` vigente en
ese instante se descarta sin guardarse y se cuenta en el log del sync (`location_off_hours_dropped`). Cubre agentes con
reloj o lista desfasados.

**Visibilidad:** permiso `presencia.ubicacion_ver` + alcance del admin → solo puntos `in_work_hours`. Los únicos puntos
fuera de horario (de casos perdido/robado) los ven superadmin y admins de toda la empresa; cada consulta que los incluye se
registra en `audit_log`. La API externa mantiene `location:read` + `include_location=true` y aplica la misma regla.

**Retención:** el borrado diario elimina puntos de más de 90 días salvo los de equipos con `lost_at` vigente.

**Regla:** campo `location_tracking` (default false) en la política compilada; solo se envía a agentes que lo soportan
(corte por versión, como `time_change_lock`). Solo asignable si el cliente tiene contratada `ubicacion`.

**Carga estimada:** ~100 puntos/equipo/día ≈ 22.000 filas/día con ~219 equipos, dentro de los syncs existentes.

## 4. Panel

1. **Ficha de persona y de equipo → bloque «Ubicación»:** estado actual (En sede «X» / Fuera de sede / Sin dato) con hora
   del último punto; línea del día con cada cambio de estado, hora y motivo («por red», «Wi-Fi Casa, precisión 35 m»);
   enlace «Ver en mapa» por punto con posición; selector de fecha (90 días). Interruptor «Incluir fuera de horario» solo
   para superadmin/admins de toda la empresa y solo si el equipo tiene o tuvo un caso perdido/robado en esas fechas, con
   aviso de que la consulta se audita.
2. **Equipo → «Marcar como perdido/robado»:** confirmación escrita y motivo; aviso «la ubicación se tomará también fuera
   de horario hasta cerrar el caso»; mientras está marcado se muestra arriba el último punto y se conservan sus puntos;
   «Cerrar caso» lo desmarca y la toma vuelve a solo horario laboral. Todo auditado.
3. **Sedes → «Cómo reconocer esta sede»:** IPs de salida, redes Wi-Fi, coordenadas y radio; sugiere con un clic las IPs
   desde donde ya se conectan los equipos de esa sede.
4. **Reglas → interruptor «Ubicación del equipo»** (empresa, persona o equipo), visible solo si la función está contratada.

## 5. Fuera de esta versión

Alertas por equipo fuera de sede, registro de permisos de salida, mapa embebido.

## 6. Pruebas

- Agente: regla apagada no hace nada; reposición de la configuración y su reversión; punto sin posición; cambio de red
  dispara punto; dominio no se toca; outbox sin conexión; fuera de ventana no toma punto ni lee Wi-Fi; dentro de ventana
  sí; `location_always` toma 24/7; lista de más de 48 h no toma nada; reloj desfasado usa `server_time`.
- Servidor: ventanas con horario ± 30 min, festivo, día no laborable, ausencia aprobada, persona sin horario y equipo
  perdido; descarte al ingerir de punto fuera de horario sin `lost_at` (y aceptación con `lost_at`); marcar/cerrar caso
  auditado y solo por superadmin/admin de toda la empresa;
  clasificación en los 4 casos y sus prioridades; IP tomada del servidor; `in_work_hours` con zona del horario;
  visibilidad por rol y auditoría de fuera de horario; retención con y sin `lost_at`; reclasificación de 7 días; corte por
  versión del campo de política; API externa con y sin `location:read`.
- Panel: Playwright de ficha, sedes, botón perdido/robado y regla.
- Piloto en 1–2 equipos reales (portátil con Wi-Fi y escritorio con cable) antes de repartir.
