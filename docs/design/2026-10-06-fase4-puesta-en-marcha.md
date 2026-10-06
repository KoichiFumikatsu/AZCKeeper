# Fase 4 — Puesta en marcha de AZCKeeper v4

Fecha: 2026-10-06. Diseño propuesto a partir de lectura del repositorio. No se consultó producción ni se probó una sesión del panel. Única escritura: este documento. Sin cambios de implementación ni commits. `k3.js` se edita en paralelo: las referencias usan funciones y archivos, no números de línea.

## 1. Decisión de diseño y base comprobada

**Puesta en marcha debe ser una lista de tareas por cliente que conduce a comprobar un primer equipo real.** Cada tarea abre la pantalla existente que administra esos datos. Se puede salir, trabajar desde el menú y regresar; el avance se comprueba con datos guardados. No es una segunda versión de Organización, Personas o Alta.

Distinguir dos hitos:

- **Primer equipo operativo:** estructura mínima válida, persona preparada, administración del cliente resuelta y equipo asignado que ya reporta. Los controles elegidos para ese piloto se comprueban por separado.
- **Despliegue completo:** instalación y validación de toda la población prevista. No se deduce de tener un equipo ni de una cola de solicitudes vacía. Se continúa desde Equipos, Registrar equipos y, si está contratada, Cobertura.

Para Grupo AZC se recomienda un piloto en una sede, con endurecimiento **desde el panel**, antes de ampliar por sedes. Tener todas las funciones contratadas no obliga a configurarlas ni ejecutarlas todas para arrancar.

### Evidencia y discrepancias relevantes

| Fuente / punto de entrada | Consecuencia para el diseño |
| --- | --- |
| `docs/architecture/v4-clientes-funciones-accesos.md`, §1, §5 y §9 | Cliente = tenant; empresas internas = `org_units` tipo `company`. Fase 4 pide tareas guiadas, creación contextual y vocabulario coherente. |
| `docs/design/2026-10-05-revision-ux-panel.md` | Q1–Q12, M1 y G1+M3 son base entregada. No se vuelven a presupuestar como rediseños completos. |
| `public/assets/k3.js`: `organization()`, `personPage()`, `unitField()`, `laborFields()` | Organización ya tiene pestañas, ubicación y dependencias. La ficha única crea empresa, sede, área y cargo sin salir. **Todavía falta + Nuevo horario en ese selector.** |
| OpenAPI `UserCreate`; `src/AdminApi.php` | **Sede, área y cargo son obligatorios para crear personas. Empresa interna es opcional.** Firma y horario pueden omitirse: el backend resuelve «Sin firma» / «Sin horario». No exigir crear firmas. |
| `people()` y `sourcesPage()` de `k3.js` | CSV de personas ya existe y hace `POST /users` por fila. One sincroniza datos con dueño por dominio. No son dos formatos del mismo importador. |
| `nextSteps()`; OpenAPI `TenantCreate` | Crear cliente y primer administrador son operaciones separadas. El contrato de creación del cliente no recibe un administrador. No prometer una operación atómica. |
| `public/assets/intake.js` | Descargar instalador incluye una clave nueva. El paso obligatorio «Crear clave» del diseño original debe desaparecer. Aprobación, registro y comunicación son hitos diferentes. |
| `public/assets/hardening-settings.js` | La cuenta IT es del cliente, con contraseña compartida y modos `panel` / `auto`. El GET devuelve máscara o `null`, suficiente para verificar configuración. |
| `src/PersonPlans.php`, `src/HolidayCalendars.php`, `src/ActivityCalendar.php` | Plan propio activo → predeterminado activo. Calendario propio activo → país de sede → predeterminado. Un horario con ID pero sin días no espera jornada. |
| `partials/sidebar.php`, ambos layouts y `empresas.php` | Persisten «Empresas» para clientes y «Unidades (firmas...)» para Organización. La fase 4 debe corregirlos. |
| `src/Features.php` frente al documento de arquitectura | El catálogo actual usa **`sitios`**, no el antiguo `titulos`; `api` y `webhooks` tienen `pending=true`. La lógica del flujo debe seguir el catálogo real. |

Rutas de presentación relativas a `backend-v4/public/`, salvo indicación contraria. Las rutas API se escriben como las usa `api.js`, sin prefijo `/v1`; se contrastaron con `docs/architecture/openapi-v4.yaml`. Las pantallas o parámetros nuevos se identifican como propuestas. **Requiere backend** señala evidencia o persistencia que no existe en el contrato actual.

## 2. Flujo recomendado y dependencias

### 2.1 Ocho pasos visibles

| Paso | Qué se prepara y por qué va aquí | Obligatorio, opcional o no aplica |
| --- | --- | --- |
| **1. Preparar el cliente** | Cliente, zona horaria, funciones, plan predeterminado y origen de datos. Determinan qué se puede editar y qué recibirá cada persona. Elegir One antes de cargar evita duplicaciones. | Base obligatoria. Funciones y conexión One corresponden a plataforma. Plan predeterminado recomendado antes de instalar si se usarán funciones de persona; núcleo puede funcionar sin plan. |
| **2. Organizar el cliente** | Subtareas: Empresas → País/ciudad/sedes → Áreas y cargos. Los catálogos deben existir antes de referenciarlos en personas/CSV. | Sede, área y cargo obligatorios para alta manual/CSV. Empresas opcionales en modelo, recomendadas para Grupo AZC. País necesario para el calendario automático por país; no es obligatorio universal del API. |
| **3. Jornadas y festivos** | Crear horarios y calendarios antes de asignarlos a personas y antes de interpretar puntualidad/ausencias. | Horario obligatorio para quien tendrá jornada medida; opcional para núcleo/personas sin jornada. Festivos no aplica sin esa función; si se utilizará, preparar antes de validar presencia. |
| **4. Personas y planes** | Sincronizar/importar/crear; revisar identificación, estructura, monitoreo, jornada y plan efectivo. Una persona debe existir antes de su cuenta y equipo. | Al menos una persona activa preparada para el piloto. Sin autogestión la tarea sigue siendo necesaria, pero la resuelve plataforma/One. |
| **5. Accesos del panel** | Cuenta del administrador sobre su persona, con un rol + un alcance. Plan y acceso son independientes. | Un administrador habilitado antes de entregar gestión al cliente. Más cuentas son opcionales; no crear cuenta a cada persona monitoreada. |
| **6. Protección inicial** | Cuenta IT antes de endurecer; modo automático revisado antes de instalar. Solo los bloqueos elegidos para el piloto. | Opcional para comenzar a inventariar/monitorear. Cuenta IT obligatoria si se endurece; reglas necesarias solo para bloqueos elegidos. Sin funciones correspondientes: No aplica. |
| **7. Instalar Keeper** | Revisar aprobación, elegir identificación, descargar y ejecutar en Windows. Plan y protección elegida ya revisados. | Obligatorio para piloto. Equipos esperados/CSV opcionales; clave manual en configuración avanzada. |
| **8. Comprobar el primer equipo** | Verificar registro, persona, comunicación y resultado de funciones elegidas. Después ampliar instalación. | Obligatorio. Descargar, aprobar, crear clave o guardar regla no completa esta comprobación. |

Es un orden orientador, no ocho puertas que bloqueen todo el panel. Calendarios y Organización pueden prepararse en paralelo. Se bloquea únicamente la acción que carece de su dependencia real: crear persona sin sede/área/cargo o endurecer sin cuenta IT.

### 2.2 Excepción: preparar al primer administrador antes de delegar

Desde el cliente recién creado, superadmin puede entrar a **Preparar acceso del administrador**, dentro del mismo recorrido:

1. Confirmar origen. Si One traerá al administrador, sincronizarlo primero.
2. Si será local, crear solo sede, área y cargo reales necesarios y su persona. Puede estar sin horario o sin empresa interna cuando corresponda; no crear cargos ficticios para llenar una barra.
3. Crear cuenta de tipo cliente, rol con permisos de administración y alcance Todo el cliente. Nunca superadmin para el cliente.
4. Entregar contraseña temporal mediante el mecanismo actual; cambiarla en primer ingreso.

El administrador continúa el resto después. Koichi puede preparar todo como superadmin, pero su sesión de plataforma no prueba que el cliente tenga administrador. La cuenta IT local de Windows es otra identidad.

### 2.3 Dependencias que no se deben inventar

- Grupo AZC es **un cliente**. AZC Legal, BilingueLaw, LawyerDesk, Matiz LegalTech y Grupo AZC holding son empresas internas; no cinco tenants ni un `company_group` entre tenants.
- País y ciudad son campos de sede (`country_code`, `city`). No tienen pantallas «Crear país/ciudad». Cali/Medellín corresponden a CO; Tegucigalpa a HN. En República Dominicana falta conocer ciudad y nombre de sede: no precargar Santo Domingo como hecho.
- Una sede puede compartirse entre empresas. No imponer una cadena Empresa → País → Ciudad → Sede → Área → Cargo: `parent_id` y referencias laborales no representan esa jerarquía obligatoria.
- Empresa interna nula es corporativo/sin empresa asignada; **no concede acceso a todo el cliente**.
- Horarios es núcleo, también útil para actividad laboral y reglas temporales. No ocultarlo porque no haya `presencia`.
- Un predeterminado evita personas sin funciones al instalar. El contrato también permite planes propios activos sin predeterminado: aceptar esa opción avanzada comprobando cada piloto. No exigir plan para solo núcleo.
- One puede materializar estructura, horarios y personas en una carga. Después se revisan los pasos ya poblados, sin recrear datos manualmente.

## 3. Aplicabilidad y criterios de «hecho»

### 3.1 Estados y alcance del progreso

**Hecho** se calcula con evidencia, nunca por visita o clic. Cada fila muestra motivo, alcance y fecha de comprobación. El objetivo es el piloto elegido, no toda la plantilla; los pendientes de otras personas se muestran aparte. Seleccionar piloto durante la sesión es frontend; compartir selección entre sesiones/administradores **requiere backend**.

| Estado | Significado |
| --- | --- |
| Pendiente | Falta un dato comprobado; CTA para resolverlo. |
| En curso | Datos parciales, carga en ejecución, alta aprobada sin registro o reporte pendiente. |
| Hecho | Criterio técnico cumplido para el alcance indicado. Permite Revisar. |
| Requiere atención | Conflicto, referencia inactiva, función ausente en plan, sincronización bloqueada o aplicación fallida. |
| Gestionado por plataforma | Responsable de una tarea necesaria; combinar con Pendiente/Hecho si se conoce. No es prueba de completitud. |
| No disponible para tu cuenta | Evidencia inaccesible por permiso/alcance. Nunca convertirla en cero, Hecho o No aplica. |
| No aplica | Función no contratada o condición de negocio explícita. Siempre explicar motivo. |
| Para después | Función aplicable aplazada voluntariamente. No equivale a Hecho. Persistir decisión compartida **requiere backend**. |

Un 403 debe distinguir `feature_not_contracted`, `scope_denied`, permiso insuficiente y reautenticación. Fallo de red: dato desactualizado + Reintentar. Contadores completos requieren alcance tenant y agotar `next_cursor` o `next_offset`, según recurso. Un listado parcial no demuestra ausencia de pendientes.

Contador: «X de Y pasos necesarios para el primer equipo». Excluir opcionales aplazados y No aplica, explicando cuáles. Sin evidencia no mostrar 100 %. Desglosar subtareas de un paso mixto: Festivos no aplica no elimina Horarios.

### 3.2 Evidencia, operaciones existentes y límites

| Paso / subpaso | Cuándo cuenta como hecho | Datos/operaciones existentes | Límite y backend necesario |
| --- | --- | --- | --- |
| 1a Cliente | `active`, no archivado, zona guardada y funciones actuales conocidas. | `GET /tenants/{id}`, `GET /me/access`; plataforma: `GET /tenants/{id}/features`, `GET /features`. | Leer no demuestra revisión humana. Persistir conformidad comercial **requiere backend**. Paquete de origen no sustituye funciones actuales. |
| 1b Plan | Predeterminado efectivo activo y funciones del piloto cubiertas; alternativamente plan propio activo que cubra lo elegido. Sin funciones de persona, no es requisito. | `GET /person-plans/available`, `GET /users`; plataforma guarda predeterminado con `PUT /tenants/{id}/features`; propio con `PUT /users/{id}/plan`. | Planes disponibles exige `planes.asignar`; sin permiso, estado desconocido. Resumen seguro para ese admin **requiere backend**. GET de funciones del tenant es solo plataforma. |
| 1c Origen | Local: dominios relevantes sin fuente. One: dueño asignado, fuente activa en modo real, carga completa `applied`, `simulate=false`, sin pendientes que afecten al piloto y datos materializados. | `GET /data-domains`, `/data-sources`, `/sync-runs`, `/sync-runs/{id}/items`; plataforma configura mediante `POST /data-sources`, `PATCH /data-sources/{id}`, `PUT /data-domains`, `POST /data-sources/{id}/runs`. | Fuente creada/simulación exitosa no equivale a importación. No asumir origen local si faltó permiso de lectura. Intención «One se conectará después» persistida **requiere backend**. |
| 2a Empresas | Empresa activa necesaria para el piloto, o decisión corporativa sin empresa. | `GET/POST /organization`, `PATCH /organization/{id}`, `GET /users`. | Una empresa no prueba que estén las cinco. Revisar visualmente la lista declarada por Koichi; catálogo esperado persistente **requiere backend**. No bloquea piloto corporativo. |
| 2b Sedes | Sede activa seleccionable para piloto; país si se usará calendario automático por país. Nombre/ciudad revisados para evitar ambigüedad en CSV. | Mismas operaciones de Organización. | Sin país es un registro permitido: mostrar respaldo de calendario, no inventar ubicación. No deducir cobertura geográfica total por conteos. |
| 2c Áreas/cargos | Al menos un área y cargo activos para el piloto; tras crear persona, IDs válidos del tipo correcto. | Organización + `GET /users`. | Existencia de «Sin…» no prueba estructura real revisada. No sembrar placeholders desde onboarding. |
| 3a Jornadas | Persona piloto que medirá jornada asignada a horario con `days` no vacío, zona y franjas válidas; semana efectiva visible. Antes de asignarla: Catálogo preparado, no Hecho final. | `GET/POST /schedules`, `GET/PUT /schedules/{id}`; asignación `POST /users` o `PATCH /users/{id}`. | «Sin horario» tiene ID real: verificar días, no solo ID. Conformidad con jornada laboral es humana. Persistir excepciones deliberadas/revisión **requiere backend**. |
| 3b Festivos | Calendario efectivo activo del piloto resuelto; días del año de puesta en marcha cargados y revisados. | `GET/POST /schedules/holiday-calendars`, `GET/POST /schedules/holidays`; `PATCH /users/{id}`; asignación múltiple `POST /schedules/holiday-calendars/{id}/people`. | Calendario vacío no vale. Tener días tampoco acredita lista anual completa. «Días cargados; revisión pendiente» hasta conformidad humana; persistir revisión calendario/año **requiere backend**. No existe descarga garantizada de feriados oficiales. |
| 4 Personas | Piloto `active`, referencias laborales válidas, identificación resoluble por método de alta, plan y monitoreo revisados. Documento para autoidentificación; documento/correo inequívoco para esperados/aprobación. | `GET/POST /users`, `GET/PATCH /users/{id}`, planes disponibles, `PUT /users/{id}/plan`, `GET /monitoring-exclusions`. | Nombre basta para crear, no para toda ruta de alta. `monitoring=excluded` se señala; no forzar monitoreo por completar. CSV actual no crea cuentas ni asigna planes propios. |
| 5 Administración | Cuenta cliente activa vinculada a persona activa, rol activo con permisos adecuados y alcance Todo el cliente. Preparado antes del primer ingreso; Probado si `last_login_at` existe y `must_change_password=false`. | `GET/POST /panel-accounts`, `PATCH /panel-accounts/{id}`, `GET /roles`, `PUT /users/{id}/access`, `GET /users`. | No basta nombre «Admin» ni `panel_login_enabled`. Si falta autogestión, plataforma verifica; estado limitado visible al admin cliente **requiere backend**. Probar una tarea humana no se deduce del login. |
| 6a Cuenta IT | `shared_password !== null`, nombre y modo guardados. Acredita IT configurada, nunca Windows endurecido. | `GET/PUT /admin/tenants/{id}/hardening`, `POST /auth/reauth`. | Leer máscara basta. Nunca usar reveal para progreso. Auto exige IT antes de ejecutar instalación; panel permite instalar y endurecer después. |
| 6b Reglas | Si se eligieron bloqueos: reglas guardadas, `enabled=true`, destino/tipo pertinentes e incluidas en resolución del piloto. | `GET/POST /policies`, `PATCH /policies/{id}`, `GET /users/{id}/policies`. | Una regla cualquiera no basta; compilación no acredita ejecución. Decisión compartida «sin bloqueos por ahora» **requiere backend**. |
| 7 Instalación | Equipo registrado del piloto con ID, persona correcta y estado activo. Solicitud, si la hay, `enrolled` y referencia a equipo verificada. | `GET /enrollments/settings`, `/enrollments/requests`, `/enrollments/expected-devices`, `/tenants/{id}/devices`, `/devices/{id}`; descarga `POST /enrollments/keys/installer`; aprobación `POST /enrollments/requests/{id}/approve`. | Descargar/approved son intermedios. Una clave no demuestra recepción ni ejecución del paquete. Verificar equipo también si aprobación fue automática. |
| 8a Comunicación | Mismo equipo activo y correctamente asignado, `last_seen_at` no nulo; para prueba piloto, avanza entre dos lecturas posteriores al inicio de comprobación. Mostrar hora exacta. | `GET /devices/{id}` o listado. | Reporte antiguo es evidencia histórica, no conexión actual. Comparación frontend posible; conservar evidencia y cierre **requiere backend**. No inventar cadencia de reporte. |
| 8b Funciones elegidas | Endurecimiento: `state=hardened` y reporte posterior a acción. Actividad/presencia: datos del piloto tras generar actividad y esperar procesamiento, con jornada/calendario revisados. | `GET /admin/devices/{id}/hardening`; orden `POST /admin/devices/{id}/hardening/command`; `GET /users/{id}/activity`, `GET /reports/presence`, `GET /users/{id}/policies`. | Orden aceptada no es aplicada. Web/USB/etc.: prueba controlada y resultado humano; certificación automática por control **requiere backend/agente**. `policy_version` aislada no certifica aplicación. |

**Cierre:** Finalizar puesta en marcha cuando dependencias del piloto y comunicación estén verificadas, exista administrador habilitado y cada función elegida tenga resultado o aplazamiento explícito. Declarar «Primer equipo operativo», enumerar pendientes y no certificar flota completa. Sin persistencia de cierre, ofrecer «Ir a Equipos» y avance calculado; no fingir cierre compartido en `localStorage`.

### 3.3 Matriz de funciones

| Función | Tratamiento |
| --- | --- |
| Núcleo | Estructura mínima, personas y equipos siempre. No hacer marca, inventario adicional o actualización tareas obligatorias de onboarding. |
| `autogestion_personas` | Habilita edición según permiso y dueño. Sin ella, Personas sigue siendo necesaria y gestionada por plataforma/One; no es No aplica. |
| `autogestion_accesos` | Habilita gestión según permisos. Sin ella, plataforma prepara administrador/cuentas; no exigir compra para que exista administrador. |
| `one` | Ofrece vía One, configurada solo por plataforma. Contratada no significa elegida para todos los dominios. |
| `presencia` | Jornada necesaria para medirla. Contratada sin plan que la incluya en piloto: «Aún sin persona piloto habilitada», no Hecho. |
| `festivos` | Calendarios aplicables si contratada. Sin ella, no prometer descuento de festivos; explicar límite al revisar Presencia. |
| `productividad`, `sitios`, `ubicacion` | Comprobar función efectiva y datos del piloto, sin pasos vacíos de configuración ni promesas de capturas/datos no habilitados. |
| `web`, `usb`, `descargas`, `instalaciones` | Subtarea Reglas. Ninguna contratada: No aplica. Contratada pero aplazada: Para después, sin fabricar regla permisiva. |
| `endurecimiento` | Cuenta IT y prueba si se activa. Aplazado no bloquea instalar en modo panel. Si ya está en auto, revisión previa obligatoria de efectos y plan. |
| `cobertura` | Seguimiento posterior; no sustituye prueba piloto ni inventario físico completo. |
| `clasificacion`, `ausencias`, `puertas`, `cumplimiento` | Bloque plegable Otras funciones. Opcionales al arranque; si se eligen, verificar sus dependencias. Puertas necesita sede y sistema de entrada. No crear ausencias/alertas ficticias para completar. |
| `comandos`, `borrado`, `auditoria` | Disponibilidad según permisos/plan. No exigir reiniciar, borrar ni sembrar eventos para onboarding. |
| `api`, `webhooks` | Hoy pendientes en catálogo: «Contratada · Pendiente de implementación». Fuera del denominador operativo, limitación visible. |

Aplicabilidad comercial = funciones contratadas; posibilidad de actuar = permisos + alcance; función del piloto = cliente ∩ plan activo. Son comprobaciones distintas. Bypass de superadmin no demuestra contratación.

## 4. Visibilidad, entrada y convivencia con el menú

### Ubicación y responsables

Propuesta nueva: **`public/puesta-en-marcha.php`**, en Principal bajo Inicio. Cabecera persistente «Cliente: Grupo AZC». Usar K3 y paleta actual índigo/gris; no recuperar el CSS histórico navy/rojo.

| Usuario | Qué ve y hace |
| --- | --- |
| Superadmin | Tras crear cliente: **Configurar este cliente**; secundario Preparar acceso del administrador. Desde Clientes abre el mismo flujo con contexto validado. Configura ventas, predeterminado y fuentes en pantallas existentes. |
| Admin cliente | Estado y responsable de cada tarea. Solo acciones autorizadas. Restricciones comerciales/fuentes: Gestionado por AZC Keeper con pendiente concreto. |
| IT delegado, alcance tenant | Tareas que puede resolver y dependencias permitidas; onboarding no le concede acceso adicional a personas/cuentas. |
| Coordinador limitado/colaborador | Panel habitual; sin banner ni redirección de configuración global. Sus listas parciales no sirven para calcular progreso tenant. |

Visibilidad por permisos reales de administración y alcance `tenant`, nunca por nombre de rol. Resumen seguro para responsable sin lectura de todos los recursos **requiere backend**; no conceder permisos amplios para resolver UX.

### Entrada y retorno

- **Alta del cliente:** abrir flujo estableciendo cliente mediante mecanismo actual (`setTenant`, selección y validación API). Un ID en URL no autoriza. Excluir tenant reservado de plataforma.
- **Primer ingreso del admin:** tras cambio obligatorio de contraseña, banner en Inicio «Prepara tu primer equipo» y **Continuar puesta en marcha**. No interceptar enlaces directos ni forzar tutorial. Sin persistencia, usar dependencias incompletas, no inventar «primer ingreso del cliente».
- **Banner posterior:** compacto en Inicio y páginas relacionadas: siguiente pendiente concreto y un CTA resoluble. Ocultar por sesión es preferencia de UI, no cierre del cliente.
- **Pantallas normales:** banda Puesta en marcha / Paso y Volver al progreso. Guardar/cancelar conserva cliente, filtros y pestaña; recalcular tras guardar.
- **Menú normal:** siempre disponible, no se reordena según progreso. Crear en contexto reutiliza editor y vuelve al formulario padre.
- **Cambiar cliente:** invalidar estado/solicitudes del cliente anterior y cargar autorización/evidencia nueva. Advertir solo si hay borrador sin guardar.

Parámetros de retorno y apertura contextual en Organización/Alta son **nuevos contratos frontend**, pendientes. Sí existen `miembro.php?nueva=1`, `miembro.php?id=…&tab=datos|acceso|actividad|equipos` y `cuentas.php?empresa=…&persona=…&crear=1`.

### Cuando ya está en marcha

Tras cierre persistido, retirar banner inicial y conservar **Revisar configuración inicial** en Ajustes y como enlace secundario del cliente para plataforma. Nuevas personas/sedes no reinician onboarding. Funciones nuevas agregan tarea específica.

Seguir calculando evidencia: si cambia plan o se invalida configuración, mostrar aviso concreto en su módulo y en revisión, conservando fecha histórica de cierre. Cliente con equipos existentes entra en modo Revisión, aprovecha datos y elige un equipo actual; nunca exigir reinstalar ni inventar fecha histórica.

## 5. Diseño de cada paso sobre pantallas existentes

### 1 — Preparar el cliente

**Muestra:** nombre, zona del cliente, funciones disponibles, predeterminado y tabla «Dato / Se administra en»: personas, sedes, áreas, cargos, horarios y festivos. Credenciales y parámetros de conexión permanecen en Fuentes.

**Primario:** superadmin, **Revisar funciones y plan** en editor actual de `empresas.php`, después **Preparar origen** en `fuentes.php`; admin cliente, **Continuar con Organización** si está resuelto. Si depende de plataforma, explicar pendiente y responsable. No usar «Solicitar conexión» como si existiera un envío/ticket automático.

**One/local:** elegir por dominio en la pantalla actual. One puede traer estructura y personas juntas; revisar datos resultantes y aprovechar pasos ya preparados. No asumir que el dominio histórico `firmas` importa empresas internas `company`: verificar mapeo y referencias. Completar mapeo faltante **requiere backend/conector**.

**Vacío/error:** «Todavía no hay una carga aplicada». Plataforma usa Simular → revisar diferencias → pasar a real/aplicar; cliente ve responsable. Si hay retiros bloqueados o vínculos pendientes, abrir detalle existente; no sugerir Aplicar igualmente para avanzar la barra. Simulación no completa carga.

**+ Nuevo:** planes/paquetes solo para plataforma en su pantalla, si realmente falta catálogo. No ofrecer crear otro cliente desde selector de empresa interna.

### 2 — Organizar el cliente

**Muestra:** Empresas / Sedes / Áreas y cargos con unidades activas y pendientes concretos. **Primario:** **Completar Organización**, abriendo pestaña pertinente del módulo ya entregado.

Grupo AZC: lista de las cinco empresas como referencia a confirmar, no datos sembrados. Sedes agrupadas por país/ciudad sin inventar oficinas ni hacerlas exclusivas de una empresa. No exigir completar todos los países antes del piloto en Cali.

**+ Nuevo:** + Nueva empresa, sede, área o cargo mediante `openOrgCreate()`. País y ciudad dentro de Sede. Conservar borrador padre y seleccionar el objeto creado. No crear firmas nuevas como requisito; mantener legado existente sin migración implícita.

**Vacío:** «Crea una sede, un área y un cargo para registrar a la primera persona». Si el dominio es One, cambiar a «Revisa las unidades sincronizadas» con responsable y lectura apropiada.

**Errores:** referencia inactiva, dependencia circular, duplicados o nombres ambiguos. Abrir registro afectado o elegir otro. Sede sin país muestra el efecto sobre calendario; sin permiso de personas, conteo No disponible y no cero. CSV de personas utiliza catálogos existentes: no anunciar importación CSV de Organización como herramienta ya disponible.

### 3 — Jornadas y festivos

**Muestra:** Horarios y Festivos como subtareas separadas. Horario con semana efectiva, almuerzo y zona; calendario con país/año y resolución del piloto. **Primario:** **Crear/Revisar horario**, luego **Preparar calendarios** cuando aplique.

Reutilizar `horarios.php` / `schedulesPage()` y `festivos.php` / `holidaysPage()`. Agregar **+ Nuevo horario** en `laborFields()` usando el mismo editor y `POST /schedules`. No usar la zona de Tokio del navegador para jornadas del cliente; mostrar la zona guardada en cada horario y distinguirla de la zona usada por reportes.

Festivos: **+ Nuevo calendario**, después **+ Agregar festivo** en ese calendario; año de puesta en marcha según contexto del cliente y opción de preparar el siguiente. País de sede resuelve calendario, no descarga fechas. One solo cuando sea dueño de ese dominio; no anunciar CSV de festivos como capacidad existente.

**Vacío:** «Sin horario: no se espera jornada ni se calcula tardanza», válido para excepciones, no recomendado para todos. Calendario vacío: «El calendario existe, faltan sus días».

**Errores:** zona inválida, almuerzo incompleto, calendario inactivo o respaldo de otro país. Horario nocturno se representa hasta el día siguiente. Cambiar horario compartido debe mostrar personas afectadas visibles. Resolver algún calendario no basta: uno extranjero por fallback exige revisión.

### 4 — Personas y planes

**Muestra:** origen, resultado/fecha de carga disponible, personas activas y faltantes del piloto. **Primario por contexto:** local vacío, **Crear primera persona**; volumen, **Importar CSV**; One, **Revisar personas sincronizadas**. Son vías alternativas, no tres tareas obligatorias.

Reutilizar `usuarios.php` y `miembro.php`: bloques Datos básicos → Ubicación laboral → Acceso/plan opcionales. Piloto puede ser distinto del administrador si el cargo de este está excluido de monitoreo.

**Manual:** documento rotulado como identificación en contexto multinacional; correo opcional para monitoreo. Sede, área y cargo requeridos; empresa interna opcional. Revisar jornada y plan efectivo: Heredado del cliente / Propio, con funciones concretas. Crear persona no crea cuenta del panel automáticamente.

**CSV ya existente:** plantilla `nombre;cedula;correo;firma;sede;area;cargo;horario;empresa`; Firma es legado opcional. Mostrar filas listas, omitidas y con errores; las unidades se resuelven por nombre. Antes de enviar, detectar coincidencias ambiguas, sin elegir silenciosamente la primera. Hacer visibles sugerencias «Sin…» y primer horario: no equivalen a asignación revisada. Documento existente se omite, no se actualiza. El importador usa un `POST /users` por fila, no una transacción ni endpoint masivo. Conservar resultado por fila y reintentar únicamente lo fallido.

**One:** registros `managed_by` se corrigen en origen. Actualmente Personas oculta creación/CSV cuando el dominio tiene dueño externo; respetar esa política y dirigir excepciones a plataforma. Si se necesita crear nuevas excepciones locales bajo ese dominio, verificar backend antes de habilitarlas; no asumir que basta mostrar un botón.

**+ Nuevo:** unidades ya implementadas desde ficha; horario mediante editor compartido. Guardar una unidad no guarda ni pierde automáticamente a la persona. No crear rol desde datos laborales.

**Vacío/error:** falta sede → creación contextual; documento existente → abrir persona; guardado parcial → «Datos guardados; no se pudo asignar el plan» y reintento solo de plan. Sin `planes.asignar`, estado gestionado por responsable, nunca Sin plan inferido de un 403. Jefe puede completarse después salvo que se esté preparando acceso Su equipo.

### 5 — Accesos del panel

**Muestra:** persona administradora, cuenta, rol, alcance y primer ingreso por separado. **Primario:** **Configurar acceso** en pestaña Acceso y plan de la ficha; **Crear cuenta** con persona preseleccionada en `cuentas.php`.

Reutilizar Roles, `scopeSummary()` y `scopeConditions()` de `access-scope.js`. Administrador inicial: Todo el cliente explícito y rol con permisos adecuados. Coordinadores: Empresa Y Ciudad Y Sede Y Área, o Su equipo con jerarquía preparada. Predeterminado `self` no equivale a administrador. No crear cuentas a todos los importados.

**+ Nuevo rol:** desde selector solo para quien pueda gestionarlo, reutilizando editor de `roles.php` y conservando borrador de acceso. Es integración frontend pendiente; no rehacer el modelo de roles. No crear otra persona para resolver Sin cuenta.

**Vacío/error:** «La persona existe, aún no puede iniciar sesión». `delegation_exceeds_own_access` dirige a elegir delegación válida o intervención del responsable. Cuenta activa sin rol/alcance no completa acceso. Contraseña temporal según flujo actual: no afirmar Invitación enviada ni prometer correo automático. Sin autogestión, plataforma completa la tarea.

### 6 — Protección inicial

Dos subtareas: Cuenta IT/endurecimiento y Reglas. Funciones no contratadas plegadas como No aplica; no llenar la pantalla de botones bloqueados o ventas.

**IT — primario:** **Configurar cuenta de IT** usando `openHardeningSettings()` desde Ajustes. Mostrar nombre, configuración de contraseña y modo, nunca contraseña en resumen. Explicar que el endurecimiento retira administración local, alcance efectivo por plan y efecto del bloqueo de inicio de sesión por red. Recomendar panel para piloto. Auto conserva confirmación existente; nunca se activa por completar el paso.

**Reglas — primario:** **Preparar regla del piloto** en `reglas.php`. Destino, efecto, función y estado legibles. Preparar inicialmente por Persona permite configurar antes de existir equipo; no poner bloqueos globales de ejemplo. Destinos actuales: cliente, sede, área, persona y equipo; no ofrecer empresa interna/cargo/ciudad como nuevos destinos sin backend.

**+ Nuevo:** sede/área desde selector de destino si corresponde, reutilizando Organización. No introducir tipos de destino ajenos al contrato. Ninguna importación CSV de reglas se da por existente.

**Vacío:** «Puedes empezar a monitorear sin bloqueos y añadirlos después de probar el primer equipo». Secundario Configurar después; persistencia compartida requiere backend. No crear reglas permisivas para convertir vacío en Hecho.

**Errores:** cuenta IT sin contraseña → completarla; reautenticación vencida → repetir operación; plan sin función → revisar plan; conflicto de regla → revisar destino/efecto. Antes de reutilizar edición simplificada, proteger políticas con horarios, prioridades o múltiples entradas que ese editor no represente: no simplificarlas silenciosamente al guardar. Guardar cuenta IT no ejecuta endurecimiento.

### 7 — Instalar Keeper

Reutilizar `alta.php` / `intake.js`. Dar prioridad a Instalar y agrupar Solicitudes, Equipos esperados y Configuración en pestañas/secciones secundarias; esa agrupación todavía es trabajo frontend. No duplicar motor de instalación dentro del onboarding.

**Antes de descargar:** cliente, piloto, funciones efectivas, modo IT y resumen de aprobación actual. **Primario:** **Descargar instalador** con reautenticación existente. Texto: «Descarga este paquete y ejecútalo en el equipo Windows de la persona elegida. Incluye la clave de registro de este cliente».

**Métodos:** pocos equipos → persona registrada y documento al instalar según reglas; volumen → esperados con persona + serie, por formulario o CSV ya existente. Placa sola puede guardarse, pero no acredita coincidencia automática por serie. Mensaje de aprobación derivado de `auto_approve_serial_match` y `self_identify_auto_confirm`, respetando exclusiones/alertas.

**+ Nuevo:** Registrar equipo esperado. Si falta persona, Crear persona con retorno a Alta. CSV de esperados no crea personas y usa otras columnas/errores que CSV de Personas.

**Tras descarga:** «Paquete descargado. Falta ejecutarlo en Windows». Primario pasa a **Comprobar registro**; nueva descarga secundaria advierte que crea otra clave. No generar descargas para sondear disponibilidad del paquete.

**Vacíos:** no hay solicitudes no significa no hay instalación; pudo aprobarse automáticamente. Enlazar equipos registrados y diagnóstico. Aprobada: Esperando conexión y seguimiento; nunca Terminado.

**Errores:** `installer_unavailable` → plataforma publica instalador; persona no encontrada → revisar documento/ficha; `person_ambiguous` → resolver duplicidad; serie registrada → revisar equipo existente/reinstalación; `person_excluded` → revisar decisión de monitoreo sin forzar aprobación. Cliente incorrecto → cambiar contexto antes de descargar. CSV parcial conserva creados y pendientes.

### 8 — Comprobar y continuar

**Muestra:** equipo piloto, persona, última conexión, inventario reportado y cada función elegida con resultado. **Primario:** **Comprobar conexión**; actualización mientras página esté visible, cancelada al cambiar cliente/equipo. Mantener botón Actualizar y no inventar tiempo estimado.

Reutilizar `equipo.php` / `app.js` para comunicación/endurecimiento y ficha de persona/Reportes para actividad/presencia. Reglas efectivas comprueban configuración; prueba controlada comprueba efecto. Para endurecimiento, esperar reporte posterior a acción y mostrar estado/diagnóstico reales; orden aceptada no es éxito.

Jornada/calendario preparados sin eventos: «Esperamos datos del equipo». Elegir período y día laboral apropiados para validar presencia; no esperar tardanza un día sin jornada. No ejecutar borrado/reinicios como verificación automática.

Cierre: **Primer equipo operativo**, pendientes visibles de despliegue y funciones aplazadas. CTA **Instalar en más equipos**, secundario Ver Equipos; Cobertura si contratada, aclarando población que mide. No requiere «+ Nuevo» ni importación propios: se continúa a los módulos anteriores.

## 6. Mockups ASCII

Nombres/cantidades ilustrativos, no consultados en producción. Los estados compartidos de revisión, piloto y cierre dependen del backend indicado. Estado con texto, no solo color; un primario por tarea; K3 existente, foco visible y lectura a 200 %.

### A. Pantalla Puesta en marcha

```text
+--------------------------------------------------------------------------------+
| AZC Keeper                         Cliente: Grupo AZC [cambiar si autorizado]   |
+----------------------+---------------------------------------------------------+
| Inicio               | Inicio / Puesta en marcha                               |
| > Puesta en marcha   | Prepara tu primer equipo                                |
| Personas             | 2 de 7 pasos necesarios listos · 1 opcional              |
| Equipos              |                                                         |
| Registrar equipos    | 1. Preparar el cliente                 [HECHO] [Revisar] |
|                      |    Origen: Keeper · Plan predeterminado disponible      |
| Organización         | 2. Organizar el cliente                [HECHO] [Revisar] |
| Horarios             |    Sede, área y cargo disponibles para el piloto        |
| Festivos             | 3. Jornadas y festivos              [EN CURSO] [Abrir]  |
| ...                  |    Catálogos preparados; falta asignar a la persona     |
|                      | 4. Personas y planes                [PENDIENTE]         |
|                      |    Falta registrar a quien usará el primer equipo       |
|                      |                               [CREAR PRIMERA PERSONA]   |
|                      | 5. Accesos del panel                [PENDIENTE] [Abrir] |
|                      |    Plataforma prepara al administrador sobre su persona |
|                      | 6. Protección inicial                [OPCIONAL] [Abrir] |
|                      |    Configúrala antes de activar los controles elegidos  |
|                      | 7. Instalar Keeper                  [PENDIENTE] [Ver]   |
|                      | 8. Comprobar el primer equipo       [PENDIENTE] [Ver]   |
|                      |                                                         |
|                      | > Otras funciones y pasos que no aplican                |
|                      | El avance es del piloto; no representa toda la flota.   |
+----------------------+---------------------------------------------------------+
```

El piloto se selecciona al crear/elegir persona; ninguna revisión de asignación se completa antes de guardarla. En esta variante Horarios/Festivos es necesario por las funciones elegidas; Protección se mantiene opcional. El denominador cambia explícitamente según la selección, no de forma arbitraria.

### B. Personas sobre lista y ficha existentes

```text
+--------------------------------------------------------------------------------+
| Cliente: Grupo AZC                                                              |
| Puesta en marcha / 4. Personas y planes                     [Volver al progreso] |
| Registra a quien usará el primer equipo                                          |
| Origen: Keeper · Plan heredado: [nombre efectivo, si es consultable]             |
|                                                                                |
| [CREAR PRIMERA PERSONA]   Importar CSV   Descargar plantilla                     |
| No necesita cuenta del panel para que su equipo sea monitoreado.                 |
+--------------------------------------------------------------------------------+
| Ficha existente: Nueva persona                                                  |
| 1. Datos básicos                                                                |
| Nombre * [____________________] Documento [____________] Correo [_____________]  |
| El documento se usa si la persona se identifica al instalar.                     |
|                                                                                |
| 2. Ubicación laboral                                                            |
| Empresa [Sin empresa / corporativo v]                    [+ Nueva empresa]      |
| Sede *  [Seleccione v]                                   [+ Nueva sede]         |
| Área *  [Seleccione v]                                   [+ Nueva área]         |
| Cargo * [Seleccione v]                                   [+ Nuevo cargo]        |
| Horario [Seleccione v]                                   [+ Nuevo horario]      |
| Festivos: [se resuelven según sede / excepción de la persona]                    |
|                                                                                |
| 3. Plan y acceso (bloques independientes, según permisos)                        |
| Plan: heredado del cliente · Recibe: [funciones efectivas]          [Cambiar]    |
| Acceso al panel: sin cuenta; configúralo si administrará el panel.                |
|                                                                                |
|                                   [Cancelar] [CREAR PERSONA]                    |
+--------------------------------------------------------------------------------+
| Si falla un bloque: Datos guardados. Plan no guardado. [Reintentar plan]          |
+--------------------------------------------------------------------------------+

Variante One:
  Personas gestionadas por AZC One · Última carga aplicada: [fecha disponible]
  [REVISAR PERSONAS]  Corrige los datos en AZC One.
  No mostrar Crear / CSV para esos registros.
```

+ Nuevo horario es ampliación pendiente. La ficha conserva los guardados separados ya implementados; onboarding no cambia su semántica ni finge una transacción única.

### C. Instalar Keeper sobre Alta existente

```text
+--------------------------------------------------------------------------------+
| Cliente: Grupo AZC                                                              |
| Puesta en marcha / 7. Instalar Keeper                       [Volver al progreso] |
| [INSTALAR] [Solicitudes] [Equipos esperados] [Configuración]                     |
|                                                                                |
| Persona del piloto: Ana Pérez · AZC Legal · Cali                    [Revisar]    |
| Identificación: documento registrado                                            |
| Endurecimiento: desde el panel · Cuenta IT configurada                           |
| Aprobación: [resumen de reglas actuales, incluidas excepciones]                  |
|                                                                                |
| 1. Descarga el paquete de este cliente                                           |
|    Contraseña del panel [****************] [DESCARGAR INSTALADOR]                |
|    Incluye una clave nueva; no necesitas crearla por separado.                  |
|                                                                                |
| 2. Ejecútalo en el equipo Windows de Ana                                         |
|    Descargarlo no instala Keeper. Identifica a la persona según el método elegido.|
|                                                                                |
| 3. Comprueba el registro                                                         |
|    [COMPROBAR REGISTRO] pasa a ser primario después de descargar                 |
|    Estado: solicitud aprobada · esperando conexión                              |
|    [Ver seguimiento]                                                           |
|                                                                                |
| > Instalar muchos equipos                                                       |
|   [+ Registrar equipo esperado] [Importar CSV de equipos]                       |
|   Coincidencia por serie según la configuración de aprobación.                  |
+--------------------------------------------------------------------------------+
| Después: ACT-0015 · Ana Pérez · Registrado                                       |
| Siguiente: comprobar comunicación y controles elegidos.                         |
|                                      [COMPROBAR PRIMER EQUIPO]                  |
+--------------------------------------------------------------------------------+
```

Los primarios representan momentos sucesivos, no tres botones dominantes simultáneos. Aprobada / Registrado / Reportando / Endurecido siguen siendo estados independientes.

## 7. Seis decisiones para Koichi

| Decisión / riesgo | Recomendación |
| --- | --- |
| **1. Progreso calculado o persistido.** Booleanos por paso se vuelven obsoletos; navegador no comparte avance. | **Híbrido:** calcular evidencia técnica y persistir únicamente piloto, decisiones/revisiones humanas, responsable y cierre con autor/fecha. Versionar criterios. No persistir porcentajes ni copiar catálogos. **Requiere backend**; entrega inicial puede mostrar solo avance calculado con límites claros. |
| **2. One o carga local.** Cargar antes de decidir origen puede duplicar o generar conflictos. | Elegir antes de volumen. Si One será maestro, sincronizar estructura/personas y revisar vínculos; completar localmente solo dominios de Keeper. Piloto local temporal requiere revisar vinculación posterior, no activar reconciliación a ciegas. |
| **3. Qué significa terminar.** Un equipo no prueba cobertura de cinco empresas y varios países. | Cerrar Primer equipo operativo y continuar despliegue por sede. Objetivo de toda la flota no es requisito de fase 4; persistir población objetivo si se quiere medirla **requiere backend**. |
| **4. Plan y funciones.** Compra no habilita automáticamente a cada persona ni completa funciones pendientes. | Revisar predeterminado/plan propio antes de instalar. Usar catálogo real e intersección con plan; ventas solo plataforma. API/webhooks pendientes no condicionan una barra operativa. |
| **5. Endurecimiento automático inicial.** Puede quitar privilegios al instalar y usa contraseña IT compartida del cliente. | Piloto en modo panel, credenciales custodiadas mediante flujo existente; comprobar reporte y procedimiento de recuperación antes de ampliar. Auto es decisión posterior de IT, nunca predeterminado implícito del asistente. |
| **6. Admin con permisos restringidos.** Sin autogestión sigue necesitando personas y cuenta, pero no debe leer recursos ajenos. | Plataforma resuelve tareas restringidas. Añadir resumen seguro de estado sin secretos/listados de cuentas. No ampliar permisos ni usar totales parciales del coordinador como progreso tenant. |

## 8. Implementación por tareas pequeñas

Rutas frontend relativas a `backend-v4/public/`; backend con ruta completa. Archivos nuevos son propuestas, no existentes. Coordinar integración en `k3.js` con su editor actual; no sustituir el archivo completo. Ninguna tarea de esta tabla se implementó durante este diseño.

### P0 — Base útil para el piloto

| ID / área | Entrega y archivos afectados | Criterio de aceptación |
| --- | --- | --- |
| F1 · Frontend | Vocabulario Cliente/Empresa interna en `partials/sidebar.php`, `partials/layout-k3.php`, `partials/layout.php`, `empresas.php` y textos tenant de `assets/k3.js` / `assets/app.js`. | Clientes lista tenants; Organización lista empresas internas. Grupo AZC no se convierte en cinco clientes. Empresa interna no cambia contexto tenant. |
| F2 · Frontend | Nuevos `puesta-en-marcha.php`, `assets/onboarding.js`; registro en `index.php`, integración mínima en `assets/k3.js`, estilos en `assets/k3.css`. | Ocho pasos con cliente autorizado, menú conservado y ninguna escritura de datos al abrir. No onboarding del tenant plataforma. |
| F3 · Frontend | Nuevo `assets/onboarding-state.js` para evaluador; reutilizar `api.js` / `nav-access.js`, consultas por grupos de tareas. | Núcleo sin plan, función no contratada, 403, red fallida y datos parciales producen estados distintos. Agotar paginación antes de totales; nunca `catch → []`. No suponer que permiso frontend sustituye servidor. |
| F4 · Frontend | Entrada tras alta en `nextSteps()` de `assets/k3.js`; banner de Inicio en `assets/app.js` / `assets/coordinator.js` según controlador activo; `partials/sidebar.php`. | Cliente recién creado es el seleccionado; admin ve continuar sin redirección forzada; colaborador sin banner. Cambio tenant no reutiliza evidencia ni respuestas anteriores. |
| F5 · Frontend | Retorno al recorrido y apertura contextual en `assets/onboarding.js`, `assets/k3.js`, `assets/intake.js` y layouts si requieren banda. | Guardar/cancelar conserva cliente y pestaña; se relee criterio. Parámetros de navegación solo destinos internos previstos, nunca autorización. |
| F6 · Frontend | Extraer editor de `schedulesPage()` y reutilizar en `laborFields()` de `assets/k3.js` o módulo compartido nuevo. | + Nuevo horario selecciona creado y conserva datos laborales/documento; cancelar no pierde borrador. Sin segundo formulario divergente. |
| F7 · Frontend | Origen/plan/faltantes del piloto y previsualización CSV en `assets/k3.js`, `usuarios.php`, `miembro.php`. | Plantilla documenta Empresa; detecta nombres ambiguos, muestra sugerencias y separa omitidos/errores. Reintento no duplica exitosos. One y falta de permiso no producen CTAs/planes engañosos. |
| F8 · Frontend | IT antes de ejecución y estados de descarga en `assets/intake.js`, `assets/hardening-settings.js`, `assets/app.js`. | Modo auto advertido antes de instalar; sin contraseña IT no se ofrece como preparado. Descargar conserva reauth y avisa clave nueva; no reveal ni endurecimiento automático al guardar configuración. |
| F9 · Frontend | Comprobación piloto en `assets/onboarding.js`, `assets/app.js` y ficha persona; completar paginación solicitudes/esperados en `assets/intake.js`. | Alta más allá de 100 filas localizable; approved no es enrolled; conexión exige avance de fecha; seguimiento se cancela al salir. Endurecido exige reporte. |

### P1 — Cierre compartido y delegación completa

| ID / área | Entrega y archivos afectados | Criterio de aceptación |
| --- | --- | --- |
| B1 · Backend · **requiere backend** | Persistencia tenant de piloto, decisiones/revisiones y cierre; nueva migración en `backend-v4/migrations/` con número disponible; servicio propuesto nuevo `backend-v4/src/Onboarding.php`, integración en `backend-v4/src/AdminApi.php`, contrato en `docs/architecture/openapi-v4.yaml`. La ruta API nueva se definirá en ese contrato; no se presenta aquí como existente. | Dos admins retoman mismo piloto/cierre. Autor, fecha, versión y concurrencia; aislamiento tenant. No secretos ni booleanos técnicos obsoletos. Cerrar revalida dependencias, conflicto no sobrescribe decisión ajena. |
| B2 · Backend · **requiere backend** | Resumen autorizado de tareas cuya evidencia el responsable no puede leer, en servicio anterior; revisión de `backend-v4/src/AdminAccess.php`, `backend-v4/src/Features.php` y contrato. | Admin ve Acceso preparado por plataforma sin credenciales/lista de cuentas. Coordinador no obtiene totales tenant. Fuente inaccesible no se interpreta como local. No ampliar permisos de APIs actuales. |
| F10 · Frontend | Persistencia, Finalizar y revisión posterior en `assets/onboarding.js`, `assets/onboarding-state.js`, `assets/k3.js`, `assets/app.js`; enlace desde Ajustes. Depende B1/B2. | Cierre y decisiones sobreviven a otro navegador. Nueva persona no reinicia onboarding; nueva función tiene tarea propia. Si backend no disponible, no fingir cierre persistido. |
| F11 · Frontend | + Nuevo rol en acceso y sede/área en reglas, compartiendo editores de `assets/k3.js`; proteger políticas no representables por editor simplificado. | Regresa a mismo borrador; no asigna rol por crearlo; solo destinos válidos. Guardar regla existente no pierde horarios, prioridades ni entradas. |
| F12 · Frontend | Alta agrupada en Instalar/Solicitudes/Esperados/Configuración: `alta.php`, `assets/intake.js`, `assets/k3.css`. | Primario según estado; éxito/seguimiento persisten tras aprobar; clave manual secundaria; CSV de equipos separado de personas. No cambia aprobación automáticamente. |

### P2 — Solo si el piloto demuestra necesidad

| ID / área | Entrega y archivos/área | Criterio de aceptación |
| --- | --- | --- |
| B3 · Backend/conector · **requiere backend si falta** | Verificar mapeo One de empresas internas y personas contra roster/dominios; revisar servicio de sincronización existente y `docs/architecture/openapi-v4.yaml` antes de fijar archivo concreto de cambio. | Empresas llegan como `company` con referencias correctas, sin convertir firmas por nombre ni duplicar tenants. Si ya existe, no agregar implementación. |
| B4 · Backend/agente · **requiere backend** | Si se exige certificación automática, reporte de aplicación por control/versión; revisar `backend-v4/src/PolicyCompiler.php`, contrato y reportes del agente antes de fijar alcance. | Distinguir compilada/recibida/aplicada; no certificar con orden aceptada o `policy_version` aislada. No bloquea entrega inicial con prueba humana explícita. |

### Aceptación del flujo

Pruebas de estados para evaluador y de integración para backend nuevo; revisión manual del recorrido. No repetir toda la auditoría UX anterior.

1. **Carga local Grupo AZC:** estructura mínima de una sede → jornada/calendario → persona/plan → cuenta admin → instalación/comprobación. Segunda empresa/sede no reinicia cierre.
2. **One:** simulación no completa Personas; carga aplicada con pendientes los mantiene visibles; sin edición local engañosa. Cinco empresas internas no generan cinco tenants.
3. **Solo núcleo:** puede instalar/verificar sin plan, reglas, festivos ni endurecimiento. No contratado no bloquea ni impulsa compra.
4. **Sin autogestión:** plataforma completa personas/acceso y admin ve estado permitido. 403 nunca cuenta como vacío o Hecho.
5. **Plan/jornada:** propio inactivo cae al predeterminado activo; sin ambos no promete funciones. Horario sin días no pasa jornada. Calendario extranjero por fallback se señala.
6. **Alta:** descargar no cuenta instalación, approved no cuenta registro, enrolled sin evidencia actual no cuenta comprobación. Coincidencia por serie respeta configuración/exclusión; paginación superior a 100 funciona.
7. **Protección:** cuenta configurada no implica endurecido; orden aceptada espera reporte; plan sin función se detecta antes de prueba. No comandos destructivos para completar.
8. **Continuidad/accesibilidad:** guardado parcial, cambio tenant, conflicto de versión, recarga, segundo admin, teclado y zoom 200 %. Errores conservan guardado y ofrecen acción concreta.

P0 permite orientar y verificar piloto con operaciones actuales. B1/B2 + F10 completan cierre persistente y delegación: implementar antes de presentar esas capacidades como terminadas.
