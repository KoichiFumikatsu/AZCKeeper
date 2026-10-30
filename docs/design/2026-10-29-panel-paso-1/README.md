# Paso 1 · vuelta 3: dashboard, personas y asistente de IA

Maqueta local para revisar la tarea **«Ver cómo va una persona hoy»**. No implementa cambios en el producto. La respuesta completa a B1/B2, fórmulas, alertas, riesgos y contratos nuevos está en [PROPUESTA-2.md](PROPUESTA-2.md).

Abrir [index.html](index.html) con doble clic. No necesita servidor, conexión, instalación ni credenciales. También se puede abrir directamente [persona.html](persona.html) o [administrar.html](administrar.html).

## 1. Alcance y evidencia

- [Seguro] Inspección de código de la rama `deploy-apagado`, base `94ffa0b`, el 29 de octubre de 2026. Se revisaron las entradas PHP, ambos layouts, la navegación, `k3.js`, `app.js`, `coordinator.js`, el cliente de API y el contrato OpenAPI.
- [Seguro] Se leyó primero el diagnóstico [2026-10-14-superadmin-por-tareas.md](../2026-10-14-superadmin-por-tareas.md). Se usa como antecedente; sus números de línea y problemas no se trasladan automáticamente al estado actual.
- [Seguro] El encargo cita `backend-v4/public/assets/styles.css`, pero ese archivo no existe en esta revisión. Los dos layouts cargan `assets/k3.css`: `backend-v4/public/partials/head.php:8` y `backend-v4/public/partials/layout-k3.php:27`. Se conservaron sus superficies claras, Inter, bordes, radios y escala índigo (`backend-v4/public/assets/k3.css:1`, `:3`, `:4`). Se copiaron los logos y la fuente local con su licencia.
- [Seguro] La referencia visual del código actual es índigo, no la paleta corporativa navy/rojo citada en el contexto global. Esta maqueta sigue el v4 actual solicitado.
- [Probable] La rama corresponde a lo desplegado, según el encargo. No se contrastó contra el servidor de producción.
- [Suposición] Todas las identidades, empresas internas, equipos, versiones, mediciones y alertas de la maqueta son ejemplos inventados. «Grupo AZC» se usa solo porque lo pide el encargo; no se consultó la BD.

Convención de certeza: **[Seguro]** = visible en el código/contrato o comprobación local; **[Probable]** = recorrido o consecuencia inferidos sin navegador; **[Suposición]** = decisión de diseño o dato simulado pendiente de validar. Las citas `archivo:línea` parten de la raíz del repositorio y corresponden a esta revisión.

## 2. Diagnóstico del panel actual por código

### Lo que ya cambió desde el 14 de octubre

| Certeza | Estado actual | Evidencia |
|---|---|---|
| [Seguro] | Plataforma está primero y plegable; no al fondo como en el diagnóstico anterior. | `backend-v4/public/partials/sidebar.php:42` |
| [Seguro] | El menú ya dice Clientes, Qué vendemos y Versiones del agente; el selector superior ya dice Cliente. | `backend-v4/public/partials/sidebar.php:28`, `backend-v4/public/partials/layout-k3.php:37` |
| [Seguro] | El menú se reparte por grupos de tareas, pero sigue reuniendo supervisión y configuración en una única barra lateral. | `backend-v4/public/partials/sidebar.php:12` |
| [Seguro] | Existe Resumen de protección. No se debe proponer como si aún no existiera. | `backend-v4/public/partials/sidebar.php:19`, `backend-v4/public/proteccion.php:3` |
| [Seguro] | Personas ya tiene conexión, primer ingreso, último uso, tiempo trabajado, productividad y focus, además de filtros y búsqueda. | `backend-v4/public/assets/k3.js:1754`, `:1795`, `:1840` |
| [Seguro] | La ficha abre Resumen por defecto si hay permiso para ver reportes. No exige pasar primero por Datos y acceso. | `backend-v4/public/assets/k3.js:2357` |
| [Seguro] | La ficha del equipo ya traduce los identificadores de controles a nombres legibles. En la pestaña Equipo y controles de la persona todavía se muestra directamente `control_id`. | `backend-v4/public/assets/app.js:563`, `backend-v4/public/assets/log-components.js:2`, `backend-v4/public/assets/coordinator.js:562` |

### Recorrido actual: desde Inicio hasta el día de una persona

[Probable] Clics contados desde Inicio ya cargado, con el cliente correcto, menú abierto, permisos suficientes y persona visible. No cuentan teclear, desplazarse ni abrir el selector nativo. Cada página adicional del listado suma un clic; abrir un grupo cerrado del menú suma otro.

| Paso | Pantalla y gesto | Clics acumulados | Qué se obtiene y qué exige seguir buscando |
|---|---|---:|---|
| 0 | Inicio `/` | 0 | [Seguro] Indicadores del cliente/alcance, llegadas, problemas, tabla Personal activo ahora, tendencia, top de personas y apps. No hay buscador de persona en esta vista. La tabla de personal sí enlaza a su ficha. `backend-v4/public/assets/coordinator.js:275`, `:292`, `:299`, `:305`. |
| 1A | Clic en un nombre visible de Personal activo ahora | 1 | [Seguro] Abre `/miembro.php?id=…`, por defecto Resumen para quien puede ver reportes. `backend-v4/public/assets/coordinator.js:29`, `:301`; `backend-v4/public/assets/k3.js:2359`. |
| 1B | Si no está visible: Personas → escribir en la búsqueda | 1 | [Seguro] Entra en `/usuarios.php`. La lista permite buscar nombre, correo, documento, cargo o empresa y filtrar conexión, empresa, sede, área, etc. La búsqueda usa el filtro de tabla. `backend-v4/public/usuarios.php:3`; `backend-v4/public/assets/k3.js:88`, `:1848`. |
| 2B | Clic en el nombre de la lista | 2 | [Seguro] Enlace explícito a `tab=resumen`; Ver también abre la ficha y Editar abre Datos. `backend-v4/public/assets/k3.js:1768`, `:1841`, `:1842`. |
| 2 | Leer Resumen de la persona | 1 por ruta A / 2 por B | [Seguro] Primera actividad, horas activas, productividad, focus y puntualidad. Más abajo conexión, equipos/versiones, horario, actividad, inactivo/bloqueado, desglose horario, mes, apps y tabla diaria. `backend-v4/public/assets/coordinator.js:492`, `:502`, `:507`, `:512`, `:517`, `:520`. |
| 3 | Abrir Línea de tiempo | 2 / 3 | [Seguro] Cronología de apps y sitios de hoy; tiene su propio selector de día y Consulta. `backend-v4/public/assets/coordinator.js:539`. |
| 4 | Abrir Equipo y controles | 3 / 4 | [Seguro] Versión, última conexión, estado, controles, acciones e historial por equipo. Para la ficha completa del equipo hay otro enlace (+1 clic). `backend-v4/public/assets/coordinator.js:545`, `:554`, `:555`, `:562`. |
| Alternativas | Ventanas o Presencia | +1 por pestaña | [Seguro] Ventanas permite ordenar apps/sitios por uso e inactividad; Presencia muestra primera/última actividad y retraso. `backend-v4/public/assets/coordinator.js:526`, `:565`. |

[Seguro] `/` usa `partials/layout.php`, que carga `app.js`. Para Inicio del cliente, `app.js` delega en `coordinator.js`; la función `dashboard()` de `app.js` no es ese Inicio, sino el resumen genérico/plataforma. Evidencia: `backend-v4/public/index.php:49`, `backend-v4/public/partials/head.php:10`, `backend-v4/public/assets/app.js:907`, `:912`. Se evita diagnosticar la función equivocada.

[Seguro] Inicio de plataforma (`/admin/`) es otra pantalla con conteos generales; no es una lista conjunta de personas de todos los clientes. `backend-v4/public/assets/app.js:319`.

### Dónde están los datos y qué sigue confundiendo

| Certeza | Hallazgo | Evidencia e implicación |
|---|---|---|
| [Seguro] | El superadmin elige **un** cliente para las pantallas de personas. | `backend-v4/public/assets/k3.js:4544`, `:4548`, `:4553`, `:4569`. El modelo aprobado de todas las personas juntas no está resuelto por cambiar etiquetas. |
| [Seguro] | Conexión depende de la última señal de los equipos activos: ≤5 min en línea, >5–15 min ausente, >15 min desconectado. | `backend-v4/public/assets/coordinator.js:115`. Inicio advierte expresamente que no prueba presencia humana (`:300`). La persona sin equipo también cae en desconectado con esa función. |
| [Seguro] | Primer ingreso de Personas queda fijado a hoy, aunque se consulte otro período para tiempo y productividad. | `backend-v4/public/assets/k3.js:1756`, `:1762`, `:1763`, `:1799`. La explicación existe, pero la tabla mezcla temporalidades. |
| [Seguro] | La lista muestra el primer ingreso sin el horario esperado ni el margen en la misma celda. | `backend-v4/public/assets/k3.js:1844`. La API sí dispone de inicio esperado y límite de llegada; no es necesario inventar esa medición. |
| [Seguro] | En Resumen, el horario y la primera actividad están en bloques distintos. La puntualidad principal es un porcentaje del período. | `backend-v4/public/assets/coordinator.js:494`, `:503`. Para hoy la tarea necesita una comparación explícita «08:07 / 08:00, a tiempo con margen». |
| [Seguro] | Siete pestañas en la ficha, con períodos independientes al montarse. | `backend-v4/public/assets/k3.js:2155`, `:2387`; `backend-v4/public/assets/coordinator.js:477`, `:541`. Línea de tiempo inicia hoy incluso si antes se cambió el rango de Resumen. |
| [Seguro] | Resumen mezcla hoy, período elegido y resumen mensual en una sola lectura. | `backend-v4/public/assets/coordinator.js:488`, `:512`. |
| [Seguro] | Los errores parciales de conexión, productividad y primer ingreso se muestran por separado en Personas. | `backend-v4/public/assets/k3.js:1800`. Hay que conservar esa distinción; error de consulta no significa cero. |
| [Seguro] | Alertas no están unificadas en la lista ni en las siete pestañas de persona. | Columnas en `backend-v4/public/assets/k3.js:1840`; pestañas en `:2155`; problemas de Inicio en `backend-v4/public/assets/coordinator.js:295`; entradas de Alertas en `backend-v4/public/partials/sidebar.php:18`. |
| [Probable] | El problema restante es el costo de reunir señales y entender su fecha, no la ausencia total de métricas ni solo el menú. | Se deduce del recorrido anterior. La cantidad de desplazamiento y su impacto real requieren validación de uso. |
| [Suposición] | La vista conjunta de hoy puede resolver la tarea con búsqueda y un clic en la persona. | Es la hipótesis que esta maqueta pone a prueba; no una mejora medida. |

## 3. Mapa de los dos modos

```text
Entrar
└─ Supervisor (predeterminado)
   ├─ Personas hoy → buscar / filtrar → Persona: hoy, ayer o fecha
   │                                    ├─ llegada y jornada
   │                                    ├─ actividad y productividad
   │                                    ├─ apps, sitios y cronología
   │                                    ├─ alertas
   │                                    └─ equipos actuales; acciones reservadas al paso 2
   └─ Administrar (solo superadmin, apertura intencional)
      ├─ Mandar órdenes a equipos               [paso 2]
      ├─ Qué se bloquea                        [paso 3]
      ├─ Alta de persona y equipo              [paso 4]
      ├─ Clientes, funciones y planes          [paso 5]
      ├─ Asistente de IA → asistente-ia.html: proveedor, contexto y activación por cliente
      └─ Más herramientas (plegado)
         └─ versiones, salud, auditoría, conexiones y registros
      Volver a supervisar → conserva los filtros de la lista
```

- [Suposición] Acción principal de Inicio: **Buscar persona**. Acción principal de la ficha: **Consultar día**. Acción principal del apartado Asistente de IA: **Guardar configuración de demostración**. Volver a supervisar es navegación; las tareas futuras son tarjetas informativas.
- [Seguro] La demostración tiene un cliente, 300 personas y tres empresas internas ficticias. Superadmin parte de «Todos los clientes». Se distingue Cliente → Empresas → Personas en filtros, filas y ficha. No se inventó un segundo cliente para aparentar datos multicliente.
- [Seguro] Supervisor de área ve 25 personas de Grupo AZC → AZC Jurídica → Litigios. Cliente, empresa y área quedan fijados; puede buscar, filtrar sede/estado y abrir fichas. Administrar desaparece. Un enlace directo a una ficha fuera del alcance o al modo Administrar muestra un mensaje de acceso no disponible.
- [Suposición] En producto, el alcance debe salir de permisos reales y validarse en servidor. El conmutador es **solo maqueta**, no autenticación ni seguridad.

## 4. Mapa de datos contra OpenAPI

[Seguro] Todas las rutas de esta tabla son claves existentes de `docs/architecture/openapi-v4.yaml`. El servidor base del panel es `/v1` (`:13`); por ejemplo, `GET /users` se solicita como `GET /v1/users`. El cliente añade `X-Tenant-ID` (`backend-v4/public/assets/api.js:61`, `:63`, `:72`). Aquí se usa «cliente» en la interfaz; los identificadores técnicos se conservan solo al citar contratos.

**EXISTE** = dato declarado por API. **COMPOSICIÓN NUEVA** = unir/derivar presentación con datos existentes, sin implicar una nueva ruta. **NUEVO / POR DEFINIR** = contrato o regla no encontrada para garantizar exactamente lo dibujado. Que el dato exista no garantiza que todos los roles tengan acceso ni que haya un reporte reciente.

### Inicio supervisor

| Dato o comportamiento | Ruta exacta y evidencia en OpenAPI | Estado |
|---|---|---|
| Clientes y zona horaria | `GET /tenants` (`:2474`), `GET /tenants/{id}` (`:6868`) | EXISTE. Unificar personas de todos los clientes es COMPOSICIÓN NUEVA; las consultas actuales tienen contexto de un cliente. |
| Nombre, empresa, área, sede, cargo | `GET /users` (`:2671`, esquema `User :20728`), `GET /organization` (`:3436`) | EXISTE. Relacionar `company_id`, `area_id`, `site_id`, `position_id` con organización. Búsqueda/filtros locales de maqueta son presentación. |
| Alcance del usuario y botón Administrar | `GET /me/access` (`:2883`), `GET /me` (`:26`) | EXISTE para identidad/permisos. Dos modos y selector de demostración son COMPOSICIÓN NUEVA de interfaz. |
| Conexión ahora / última señal | `GET /tenants/{id}/devices` (`:7073`, esquema `Device :20947`); `GET /dashboard` (`:2800`, esquema `Dashboard :22586`) | EXISTE: `status`, `last_seen_at`, asignación y resumen de conexión. Derivar por persona con la señal más reciente de sus equipos activos. No sumar dispositivos como personas. |
| Contadores por estado | Mismas rutas anteriores | COMPOSICIÓN NUEVA de la lista completa filtrada. Sin equipo se explica aparte. Alerta es conjunto superpuesto, no cuarto estado excluyente. |
| Primera actividad, horario y tardanza | `GET /reports/presence?from=2026-10-29&to=2026-10-30` (`:5348`), `GET /schedules/settings` (`:414`) | EXISTE: `first_activity`, `scheduled_start`, `late_seconds`, `status`, `arrival_deadline_at` (`PresenceRow :24889`). Margen configurable; se fija 15 min solo en el ejemplo. |
| Tiempo activo y productividad por persona | `GET /reports/people?from=2026-10-29&to=2026-10-30` (`:11748`) | EXISTE: `totals.active_seconds`, `totals.productivity_percent`, `totals.workday_expected_seconds`, `totals.workday_productive_seconds`. Ya evita una consulta de actividad por persona. |
| Alertas de la lista | `GET /dashboard/problems` (`:663`), presencia anterior y catálogos de alertas | COMPOSICIÓN NUEVA. Se agrupan las cuatro clases aprobadas: llegada tarde, equipo sin conexión en horario, posible doble empleo y app sospechosa. Faltantes de medición son cobertura, no otra alerta. No hay aquí un contrato único de alertas de hoy que garantice cobertura, fecha y deduplicación entre todas las fuentes. |
| Vacío / error / sin registro | Respuestas 200 sin filas y errores 403/404/429/5xx de las rutas anteriores | EXISTE como resultado HTTP; presentación NUEVA. Error global usa «—», no cero. Un 403 debe convertirse en «Sin acceso», nunca «No trabaja». |

### Ficha de persona

| Dato | Ruta exacta y evidencia en OpenAPI | Estado y criterio |
|---|---|---|
| Identidad y organización | `GET /users/{id}` (`:7760`) + `GET /organization` (`:3436`) | EXISTE. Persona y cliente deben viajar juntos al abrir la ficha desde el agregado multicliente. |
| Horario efectivo y almuerzo | `GET /users/{id}` + `GET /schedules` (`:3555`, esquema `Schedule :20558`) | EXISTE. Resolver horario efectivo/default y `day_hours` del día (`:20611`); no asumir siempre 08:00–17:00 fuera de esta maqueta. |
| Margen de llegada | `GET /schedules/settings` (`:414`, esquema `:15664`) + `GET /reports/presence?day=2026-10-29&user_id={id}` | EXISTE. Usar el límite calculado por API; 08:15 no es una constante de producción. |
| Activo, inactivo y bloqueado | `GET /users/{id}/activity?from=2026-10-29&to=2026-10-30` (`:8023`, `ActivityReport :24692`) | EXISTE: `active_seconds`, `idle_seconds`, `locked_seconds`. Llamadas son subconjunto de activo (`:8030`); entrada simulada no se suma otra vez a inactivo. La consolidación entre equipos se debe conservar del cálculo servidor. |
| Productividad y denominador | Misma ruta; definición `:24767`, `:24873`, `:24879` | EXISTE. Productivo / jornada esperada transcurrida, límite 100 %. Sin jornada = no aplica; medición faltante = sin datos; día laborable confirmado sin actividad = 0 %. No usar productivo / activo. |
| Ausencia registrada | `GET /absences` (`:14446`, esquema `Absence :16266`) | EXISTE. Presentar ausencia laboral aparte de la etiqueta de conexión «Sin actividad reciente». |
| Apps y sitios por tiempo activo | `GET /users/{id}/windows?from=2026-10-29&to=2026-10-30` (`:530`, `PersonWindow :15680`) | EXISTE: `process_name`, `site`, `active_seconds`, `idle_seconds`. Ordenar por activo. `GET /reports/apps` (`:5230`) también existe, pero su porcentaje usa activo + inactivo (`:5236`), por lo que no se copia como porcentaje de tiempo activo. |
| Nombre legible y categoría efectiva por app/sitio | `GET /app-classification` (`:15003`), `GET /app-sites` (`:15242`); excepciones en `/app-classification/overrides` (`:15121`) | COMPOSICIÓN NUEVA / POR DEFINIR. `PersonWindow` no devuelve la categoría efectiva de esa persona ni un nombre amistoso. Resolver precedencia de clasificación y equivalencia proceso/sitio; no suponer que Word siempre es productivo. Las etiquetas de la maqueta son ficticias. |
| Línea de tiempo | `GET /users/{id}/timeline?day=2026-10-29&offset=0&limit=500` (`:593`, `PersonTimelineItem :15727`) | EXISTE: inicio, fin, proceso y sitio; recorrer `next_offset`. No incluye segmentos explícitos de inactividad, bloqueo o señal perdida. La maqueta muestra huecos «Sin detalle de apps»; una cronología con esos estados precisos requeriría datos NUEVOS. |
| Sus equipos, conexión y versión | `GET /tenants/{id}/devices?user_id={id}` (`:7073`, parámetro User `:7129`); `GET /devices/{id}` (`:7131`) | EXISTE. Se muestra siempre estado actual, aun al consultar ayer. No se promete historial de conexión o protección. |
| Bloqueo web, USB, instalación de programas | `GET /devices/{id}/security` (`:7263`, `SecurityControl :21920`) | EXISTE: `control_id`, `state`, `observed_at`. Traducción a nombres llanos y resumen de protección son COMPOSICIÓN NUEVA. 404 = aún sin reporte (`:7272`), no «protegido». El dato lo declara el equipo. |
| Protección aplicada / parcial / sin confirmar ahora | Mismo reporte; opcional `GET /admin/devices/{id}/hardening` (`:1435`) si se amplía a cuentas de Windows | COMPOSICIÓN NUEVA. Definir controles esperados, antigüedad máxima y permisos; tres controles reportados no equivalen a protección total. La maqueta muestra reporte antiguo como sin confirmar. |
| Alertas del día | Presencia, `/dashboard/problems`; otras fuentes existentes: `GET /reports/suspicious-apps` (`:6159`), `GET /reports/dual-job-alerts` (`:6065`) | COMPOSICIÓN NUEVA. Las cuatro clases se simulan con severidad, fecha, evidencia y enlaces a la ficha. «Doble empleo» es una señal para revisión humana, no conclusión (`:6071`). Fechado uniforme y deduplicación quedan POR DEFINIR. |
| Acciones reservadas al paso 2 | `POST /devices/{id}/commands` (`:7475`) | La capacidad EXISTE, pero no se integra ni se ejecuta. Botones deshabilitados y rotulados «paso 2». |
| Hoy, ayer y fecha | Parámetros `from` inclusivo / `to` exclusivo de activity (`:8029`); `day` de timeline | EXISTE. Hoy = 29→30 oct; ayer = 28→29 oct, en America/Bogota. Seleccionar otro día en la maqueta da un vacío explícito. |

### Entrada Administrar

[Seguro] Las tarjetas futuras son contenido de navegación. Asistente de IA permite simular configuración local, sin consultas ni persistencia en plataforma. Hay capacidades existentes para órdenes (`/devices/{id}/commands`), reglas (`/policies` y `/users/{id}/rules`), alta (`/users`, `/enrollments/expected-devices`), clientes (`/tenants`), funciones (`/features`, `/tenants/{id}/features`) y planes (`/person-plans`). Sus flujos **no se diseñan ni implementan** en este paso. Referencias OpenAPI: `:7475`, `:3674`, `:9473`, `:2671`, `:10835`, `:2474`, `:2921`, `:12212`, `:3178`.

[Seguro] Más herramientas reúne capacidades existentes: `/releases` (`:4069`), `/tenants/server-health` (`:6588`), `/audit` (`:4441`) e `/integrations` (`:4504`). La lista plegada no abre pantallas adicionales.

### Qué haría falta para llevarlo al producto

- [Seguro] Los endpoints consultados no ofrecen una lista única paginada de personas de todos los clientes con todas estas métricas. El panel actual fija un contexto por cliente; esto se observa también en `backend-v4/public/assets/api.js:63`.
- [Suposición] Se puede componer un primer agregado consultando cada cliente permitido y uniendo por **cliente + persona**, pero requiere gestionar paginación, errores parciales y límites de consultas. A mayor escala conviene evaluar un agregado servidor. **No se define ni inventa aquí una ruta nueva.**
- [Suposición] La vuelta 2 fija cinco clases de alertas; falta concretar deduplicación, cierre, correspondencia de severidades, categorías efectivas por persona y antigüedad de protección. No es necesario crear otra base de actividad para presentar la ficha.
- [Seguro] Las mediciones de actividad dependen del cálculo periódico y retención (`openapi-v4.yaml:8030`). «Corte 15:30» es un valor fijo ficticio, no una afirmación de actualización en vivo.
- [Suposición] Si se necesitan instantes exactos de inactividad/bloqueo en la cronología, historial de protección o una marca única de frescura de toda la ficha, esos contratos se deben diseñar después. Los contratos citados no garantizan esos datos.

## 5. Decisiones de diseño y límites

| Decisión | Motivo |
|---|---|
| [Suposición] Inicio con buscador, tres KPIs, estados compactos y lista por atención | Leer el alcance y priorizar casos; asistente plegado y sin pared de gráficos. |
| [Suposición] Una sola ficha de lectura del día | Evita repartir llegada, tiempos, apps, cronología y equipos en pestañas. El desplazamiento vertical sigue permitido. |
| [Seguro] «Sin actividad reciente», aprobado en la vuelta 2 | Última señal hace más de 5 y hasta 15 min; no se infiere ausencia laboral. |
| [Suposición] Alertas en lenguaje de observación | Sin rankings de desempeño ni conclusiones a partir de conectividad. |
| [Seguro] Contadores derivados de las mismas 300 filas | Los tres estados excluyentes suman el alcance filtrado; la vuelta 2 mantiene 224 conectadas, 39 sin conexión y 37 sin actividad reciente al inicio. «Personas con alertas» aparece solo en el KPI superior. Búsqueda y organización ajustan contadores; estado restringe solo la lista. |
| [Seguro] Caso principal p001 | Valeria Montes: entrada 08:07, margen hasta 08:15, activo 5 h 15 min, inactivo 48 min, bloqueado 1 h 20 min; productivo 4 h 36 min / jornada transcurrida 6 h 30 min = 71 %. Dos equipos: uno conectado con bloqueo web fallido y otro sin señal reciente. |
| [Seguro] Casos complementarios | p002 señal de hace 10 min; p003 sin conexión; p004 permiso de día completo; p005 llegada fuera del margen; p007 actividad sin datos; p008 sin equipo. Todos son sintéticos. |
| [Seguro] Contexto por URL | Rol, búsqueda, filtros y página viajan en enlaces y en Volver a personas. No requiere almacenamiento web, módulos ES, llamadas de red ni History API bajo `file://`. Al recargar la lista se recupera el estado inicial de su URL; los cambios en la página viajan al navegar mediante sus enlaces. |
| [Seguro] Una sola fuente tipográfica local | Inter del repo con OFL; fallback Segoe UI/Arial. Logos copiados, no referenciados fuera del entregable. |
| [Suposición] Diseño para 1366×768 y 375 px | Lista tabular en escritorio y filas apiladas con etiquetas en móvil. Filtros en dos columnas y buscador con botón a ancho completo en móvil. Sin anchos mínimos de tabla que obliguen a desplazar horizontalmente. Falta confirmación visual. |

## 6. Verificación y recorrido para Claude

Desde la raíz del repositorio:

```powershell
node docs/design/2026-10-29-panel-paso-1/verificar.cjs
node --check docs/design/2026-10-29-panel-paso-1/app.js
node --check docs/design/2026-10-29-panel-paso-1/data.js
```

[Seguro] El script comprueba referencias HTML/CSS y destinos de navegación escritos en JS, archivos locales, sintaxis JS, ausencia de solicitudes de red, 300 identidades únicas, alcance de 25 personas y coherencia de los tiempos de hoy/ayer. No ejecuta un navegador ni acredita el diseño visual.

Recorrido pendiente de validación en navegador, primero 1366×768 y después 375 px:

1. Abrir `index.html`: comprobar contadores y que los primeros nombres sean visibles sin un menú lateral.
2. Buscar «Valeria» y «Tomas» (sin tilde); filtrar empresa/área/sede y pulsar los contadores. Limpiar filtros. Recorrer Siguiente/Anterior.
3. Abrir Valeria; leer llegada, fórmula, apps, cronología, alerta y ambos equipos. Verificar que las acciones del paso 2 estén deshabilitadas.
4. Pulsar Ayer y consultar 20 de octubre: cambia el día completo o muestra vacío; los equipos siguen explícitamente actuales. Volver a Hoy.
5. Volver a personas: conserva búsqueda/filtros/página. Abrir Administrar y volver a supervisar.
6. Cambiar «Ver como» a Supervisor de área: 25 personas, alcance fijo y sin Administrar. Probar un acceso directo fuera de alcance con `persona.html?role=supervisor&id=p101`; no debe mostrar datos.
7. Probar `administrar.html?role=supervisor`: muestra alcance no disponible, sin tarjetas de administración.
8. Usar Escenario → Vacío / Error de carga en lista y ficha. Error no muestra cifras cero; Reintentar restaura el ejemplo normal. Buscar un nombre inexistente genera otro vacío, con limpiar filtros.
9. Recorrer con Tab/Shift+Tab, activar selects y botones con teclado, confirmar foco visible, sin contenido cortado ni desplazamiento horizontal. Capturar también estado vacío y error.

## 7. Dudas para Koichi

1. [Seguro] Nombre resuelto: **Sin actividad reciente**. No queda pendiente de aprobación.
2. [Suposición] Protección y versiones siguen como información en la ficha; protección fallida queda fuera de las alertas del supervisor (decisión aprobada en ENCARGO-3). El acceso de lectura de producción requiere concretar permisos.
3. [Seguro] Cuatro tipos de alertas resueltos en ENCARGO-3. [Suposición] Pendiente acordar cierre/deduplicación y correspondencia de severidades con el catálogo; ver PROPUESTA-2 §2.
4. [Suposición] ¿Cuándo caduca un reporte de protección y qué controles se exigen para el resumen? El ejemplo no equipara «tres controles aplicados» con equipo completamente protegido.
5. [Suposición] Para el superadmin, ¿se excluye siempre la empresa dueña de Keeper de la lista de clientes operativos? La maqueta muestra únicamente el cliente Grupo AZC.
6. [Suposición] ¿La comparación histórica de llegada debe usar el margen vigente en ese día? El contrato expone el margen actual y un límite calculado; conviene confirmar la política de cambios retroactivos.

Estas dudas no bloquearon la maqueta; quedan para validar el diseño antes de implementar el panel.

## 8. Vuelta 2: decisiones, API nueva y recorrido adicional

[Seguro] Se aplicaron A y se respondieron/maquetaron B1 y B2. [PROPUESTA-2.md](PROPUESTA-2.md) conserva las propuestas de la vuelta 2; ENCARGO-3 prevalece para alertas y navegación del asistente; el diagnóstico de §2 conserva la evidencia del código actual, incluido el nombre antiguo que todavía utiliza producción.

- **Dashboard:** tres KPIs, productividad ponderada con cobertura, tardanzas y personas con alertas. Comparación semanal en pp de 26–28 vs. 19–21 oct, mismos días cerrados y cohorte comparable; sin gráficos.
- **Lista:** por atención, con alternativas nombre/productividad/activo. Alertas altas antes de medias; texto, severidad, «y N más» y enlace a evidencia. 300 nombres únicos con apellidos variados.
- **Asistente:** bloque plegado con hasta tres plantillas de texto, evidencia y acciones locales. Ver personas filtra; generar informe abre borrador descargable en HTML; descartar oculta la sugerencia. Sin IA conectada ni envíos. En producción los cálculos y permisos pertenecen al servidor.
- **Administrar → Asistente de IA (página propia):** proveedor, modelo, clave de escritura, contexto, interruptor por cliente apagado y cuerpo exacto de solicitud ficticia. Guardado en memoria de la página; la clave se vacía y descarta. Sin persistencia de credenciales ni sincronización con otras páginas.
- **Ficha:** cuatro alertas con evidencia, enlaces a jornada/equipos y uso no productivo visible en apps y cronología.

### API: ampliación respecto de la vuelta 1

| Capacidad | Existe | Nuevo / por diseñar |
|---|---|---|
| KPI de hoy y comparación semanal | /reports/people, /reports/presence, /schedules/settings | Agregación ponderada, cohorte comparable, cobertura y corte común. |
| Alertas por atención | /tenants/{id}/devices, presencia, /reports/dual-job-alerts, /reports/suspicious-apps | Contrato único, severidad, vigencia/cierre, deduplicación y paginación después de ordenar. |
| Personas con >1 h no productiva y app de mayor uso | /users/{id}/windows y catálogos de clasificación | Clasificación efectiva, consolidación del uso y grupos deterministas por corte. |
| Asistente, configuración e informes | Fuentes de medición e identidad anteriores | Contratos específicos no encontrados: proveedor/clave/contexto, habilitación por cliente, sanitización, cohortes opacas, redacción, validación de cifras, caché/presupuesto y exportación. |

Rutas, parámetros, definiciones exactas y líneas del contrato: [PROPUESTA-2.md](PROPUESTA-2.md). No se inventaron rutas existentes. La propuesta evita enviar identificadores/evidencia textual y resuelve cohortes pequeñas con texto local. La vista multicliente no autoriza mezclar clientes en un envío externo.

### Recorrido adicional para Claude

1. Inicio: 63,7 % (297/300 con datos y jornada), 13 tardanzas, 58 personas con alertas, 7 con alguna alta. Semana: 68,7 % vs. 75,4 %, diferencia −6,7 pp. Estados: 224 / 39 / 37.
2. Revisar primera página por atención y apellidos variados. Cambiar los cuatro órdenes; faltantes al final por métrica. Abrir alerta principal y otras alertas de Valeria; regresar conserva filtros y orden.
3. Supervisor de área: 25 personas. Buscar una persona sin medición o un nombre inexistente: sin productividad/tendencia inventada. Error: guiones y sin sugerencias.
4. Abrir Asistente: evidencia coherente. «Ver personas» en >1 h muestra seis casos del cliente, limpia estado y conserva organización/búsqueda. Filtros posteriores recalculan la cohorte; Limpiar filtros quita la selección.
5. Generar cada informe, revisar alcance/evidencia, cerrar con botón/Escape; descargar HTML y abrirlo localmente. Solo contiene integrantes autorizados de esa sugerencia. Descartar y recargar.
6. Administrar → Asistente de IA: apagado, Groq, modelo ilustrativo. Cambiar contexto/modelo actualiza la vista previa; otro proveedor exige HTTPS. Guardar con clave ficticia limpia el campo y confirma simulación; recargar restablece apagado.
7. Acceso directo administrar.html?role=supervisor: sin formulario. Ficha fuera de alcance: sin datos. Revisar a 375 px etiquetas, diálogo, textarea, JSON, foco, teclado y ausencia de desbordamiento horizontal.

[Seguro] verificar.cjs amplía la revisión con tipos/severidades de alertas, destinos, orden, apellidos, faltantes, KPIs/cohortes, payload predeterminado sin identidades y rutas existentes. No prueba el DOM ni sustituye el navegador. Las capturas existentes son de la vuelta 1; no acreditan esta vuelta.

## Cierre de vuelta 3

[Seguro] Se ejecutó ENCARGO-3 después de la vuelta 2. La protección no participa en lista de alertas, KPI, orden por atención ni agregados del asistente. Sigue visible en Sus equipos como información. Quedan llegada fuera del margen, equipo sin conexión en horario, posible doble empleo y app sospechosa: 58 personas, 7 con alguna alta. La franja de estados tiene únicamente 224 conectadas, 39 sin conexión y 37 sin actividad reciente.

[Seguro] Administrar contiene una tarjeta que abre [asistente-ia.html](asistente-ia.html). La vista previa explica datos, alcance y exclusiones antes del desplegable «Ver detalle técnico». El supervisor no puede acceder al formulario por URL directa. El contexto escrito sí se incluiría: la exclusión de datos personales requiere no introducirlos allí.

Verificación: `node verificar.cjs`. Incluye invariancia de alertas al cambiar protección, cuatro tipos, enlaces de la página nueva y ausencia del contador duplicado. Validación visual y de interacción pendiente de Claude según el encargo; capturas de vueltas anteriores son históricas. PROPUESTA-2 es antecedente, no reemplaza este cierre.
