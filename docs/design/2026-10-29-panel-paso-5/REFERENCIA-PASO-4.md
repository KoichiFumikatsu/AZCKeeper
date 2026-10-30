# Paso 4 · Personas y equipos

Abrir `administrar.html` → **Personas y equipos**, o `personas-equipos.html` directamente. HTML/CSS/JS vanilla, recursos locales, file://, sin red ni dependencias. Español de Colombia, datos ficticios de Grupo AZC, octubre de 2026, America/Bogota.

Se copió el paso 3 después de cerrar y verificar ENCARGO-2. `ORIGEN-PASO-3.json` registra hashes del origen; `REFERENCIA-PASO-3.md` conserva su diagnóstico. No se copiaron capturas como evidencia nueva ni se volvió a modificar el paso 3.

## Evidencia y discrepancias

Referencias relativas a la raíz del repositorio. [Seguro] observado en código/contrato; [Probable] recorrido inferido sin navegador; [Suposición] decisión de diseño o simulación.

- [Seguro] No existen People.php ni Devices.php: el CRUD vive en `backend-v4/src/AdminApi.php:571` y `backend-v4/src/AdminApi.php:869`. Solicitudes e instalador: `backend-v4/src/DeviceIntake.php:50`. People/Devices en el encargo son dominios, no archivos.
- [Seguro] **El asistente de alta y la vista previa CSV ya existen.** Tres bloques de alta en `backend-v4/public/assets/k3.js:2575`; errores por fila en `backend-v4/public/assets/k3.js:1930`. Este diseño los integra con instalación y protección.
- [Seguro] Aprobar deja esperando el próximo intento del equipo (`backend-v4/public/assets/intake.js:402`); no garantiza inscripción ni protección.
- [Seguro] **Retirar persona revoca equipos y cancela órdenes pendientes/entregadas** (`backend-v4/src/AdminApi.php:621`). No puede prometerse «retirar y luego borrar» con el comportamiento actual.
- [Seguro] Reasignar también revoca credenciales y cancela órdenes pendientes/entregadas (`backend-v4/src/AdminApi.php:916`, `backend-v4/src/AdminApi.php:812`). Las órdenes previas deben revisarse antes de reasignar/reemplazar.
- [Seguro] La API admite retirar un equipo; no se encontró un asistente completo de reemplazo. `DevicePatch.status` incluye active/revoked/decommissioned (`docs/architecture/openapi-v4.yaml:21148`).
- [Seguro] El styles.css productivo citado por las reglas base no existe en este checkout. Se conserva el estilo claro heredado, basado en k3.css, con fuentes y logos locales.

## Cómo es hoy

### Alta de persona, ubicación y plan

1. [Seguro] Inicio → Personas (`backend-v4/public/partials/sidebar.php:13`) → Crear persona → miembro.php?nueva=1 (`backend-v4/public/assets/k3.js:2149`).
2. [Seguro] Datos básicos → Ubicación laboral → Acceso y plan. Nombre requerido; sede/área/cargo obligatorios en UI, con creación de catálogos faltantes. Empresa y horario se preparan en ubicación (`backend-v4/public/assets/k3.js:2578`, `backend-v4/public/assets/k3.js:2590`).
3. [Seguro] El servidor normaliza cédula, evita duplicados, valida alcance/organización y guarda asignación histórica. Una fuente externa dueña de Personas puede impedir el alta o la edición (`backend-v4/src/AdminApi.php:575`, `backend-v4/src/AdminApi.php:589`, `backend-v4/src/AdminApi.php:610`). No se debe inventar libertad de crear localmente cuando lo administra otra fuente.
4. [Seguro] POST /users crea la persona; plan y acceso explícitos se guardan después por separado. Si fallan, se informa que la persona ya existe y se reintenta desde la ficha (`backend-v4/public/assets/k3.js:2647`, `backend-v4/public/assets/k3.js:2654`). El plan por defecto no crea una cuenta de administrador.
5. [Seguro] Funciones contratadas del cliente y plan efectivo se cruzan en `backend-v4/src/PersonPlans.php:20`. El plan habilita controles/comandos; no concede permisos de administración.

### Instalador, clave, solicitudes y emparejamiento

1. [Seguro] Registrar equipos nuevos es otra entrada (`backend-v4/public/partials/sidebar.php:14`), con Instalar, Solicitudes, Equipos esperados y Configuración (`backend-v4/public/assets/intake.js:128`).
2. [Seguro] Descargar requiere reautenticación y POST /enrollments/keys/installer. El ZIP incluye clave nueva del cliente; si falla la entrega, el panel intenta revocarla (`backend-v4/public/assets/intake.js:293`, `backend-v4/public/assets/intake.js:313`). No hace falta copiar una clave manual en el camino ordinario.
3. [Seguro] El servidor usa el paquete publicado estable x64, conserva su contenido firmado y cambia la configuración de URL/clave. Sin artefacto válido devuelve error sin crear clave (`backend-v4/src/DeviceIntake.php:338`). También hay claves manuales revocables; no son la cédula ni una contraseña del panel (`backend-v4/src/DeviceIntake.php:326`, `backend-v4/public/assets/intake.js:568`).
4. [Seguro] El equipo presenta huella, serie, nombre, versión y cédula opcional; una petición existente se actualiza por huella. Puede pedir cédula si está habilitado (`backend-v4/src/DeviceIntake.php:66`, `backend-v4/src/DeviceIntake.php:84`). Ruta POST /client/enrollment-requests.
5. [Seguro] Se intenta primero serie de equipo esperado. Si coincide con persona activa y está habilitada la opción, se autoaprueba. Serie ya utilizada genera alerta. Sin ese match, una cédula con una sola coincidencia sugiere persona; solo se autoaprueba si la opción correspondiente está activa (`backend-v4/src/DeviceIntake.php:104`, `backend-v4/src/DeviceIntake.php:115`, `backend-v4/src/DeviceIntake.php:121`). Busca en referencias documentales de personas activas (`backend-v4/src/DeviceIntake.php:167`). Ambigüedad, documento desconocido y exclusión de monitoreo no equivalen a match válido.
6. [Seguro] Defaults: aprobar por serie sí; pedir cédula y confirmar por cédula no (`backend-v4/src/DeviceIntake.php:98`). Es incorrecto prometer autoaprobación por solo escribir una cédula.
7. [Seguro] Aprobación manual por fila: cédula/correo opcional con sugerencia, placa opcional, Aprobar. Sin sugerencia hay que identificar a la persona. Valida persona activa y alcance (`backend-v4/public/assets/intake.js:379`, `backend-v4/src/DeviceIntake.php:255`). La cédula declarada no verifica identidad.
8. [Seguro] La aprobación genera ticket temporal de 10 minutos, entregado en la siguiente consulta. El registro efectivo actualiza el estado con onEnrolled (`backend-v4/src/DeviceIntake.php:175`, `backend-v4/src/DeviceIntake.php:194`, `backend-v4/src/Auth.php:137`). Existe POST /enrollments para aprovisionamiento más técnico, ligado a persona y equipo opcional (`backend-v4/src/AdminApi.php:933`). No se pone delante del usuario en esta tarea.

### Asignación, reemplazo, baja y datos

| Tarea | Evidencia |
|---|---|
| Equipo ↔ persona | [Seguro] Asignación de equipos → Reasignar → nueva persona activa y motivo. POST /devices/{id}/assignment con versión e idempotencia (`backend-v4/public/assets/k3.js:687`, `backend-v4/public/assets/k3.js:719`). Servidor cierra asignación previa, abre nueva, cambia dueño, revoca credenciales y recompila (`backend-v4/src/AdminApi.php:911`). |
| Nuevo portátil para persona existente | [Seguro] Aprobar otro equipo para la persona no retira el anterior. La deduplicación por serie sirve para reinstalación, no reemplazo general (`backend-v4/src/DeviceIntake.php:184`). [Probable] Hay que combinar inscripción/asignación, retiro administrativo por API y consulta de protección. |
| Retirar viejo | [Seguro] PATCH de estado no activo revoca credenciales y no permite volver a activo por el mismo método (`backend-v4/src/AdminApi.php:918`, `backend-v4/src/AdminApi.php:925`). No borra ni bloquea Windows. |
| Baja de persona | [Seguro] Datos y acceso → Estado laboral Retirado → Guardar datos → Retirar y guardar (`backend-v4/public/assets/k3.js:2457`, `backend-v4/public/assets/k3.js:2473`). PATCH traduce a inactive/retirado, invalida sesiones, revoca equipos activos y cancela órdenes pendientes/entregadas (`backend-v4/src/AdminApi.php:585`, `backend-v4/src/AdminApi.php:558`, `backend-v4/src/AdminApi.php:621`). |
| Historial y datos | [Seguro] La baja cambia estados, no elimina actividad/personas/equipos. La retención afecta actividad y registros técnicos según configuración, nunca auditoría, personas, equipos, reglas ni altas (`backend-v4/src/RetentionWorker.php:5`). Borrado del disco no equivale a borrado del historial del servidor. |
| Protección | [Seguro] La ficha monta resumen y controles (`backend-v4/public/assets/app.js:457`, `backend-v4/public/assets/app.js:466`). GET /devices/{id}/security da último reporte o 404 (`backend-v4/src/AdminApi.php:876`); /rules da reglas/versiones. Se debe verificar reporte posterior y coherente con dueño/reglas nuevos. |

### Clics y pantallas actuales

[Probable] Acciones desde Inicio, cliente seleccionado, permisos y catálogos disponibles, menú desplegado. No cuenta escribir, scroll, diálogos del sistema ni instalación remota. Cada cambio de selección cuenta como una acción; no se midió en navegador.

| Caso | Camino y costo |
|---|---|
| (a) 1 persona y 1 equipo nuevo, aprobación manual | Personas (1), Crear persona (2), Siguiente (3), Siguiente (4), Crear (5), Registrar equipos nuevos (6), Descargar instalador tras escribir contraseña (7), Solicitudes (8), Aprobar (9), Equipos (10), abrir equipo y sus controles (11). **11 acciones base**, +1 para Reglas, +1 si hay advertencia de endurecimiento al descargar. Además elegir sede/área/cargo/empresa/horario y plan si no se aceptan sugerencias. **6 páginas**: lista, alta, ficha de persona automática, Registrar equipos con dos pestañas, Equipos y ficha de equipo. Con autoaprobación desaparecen Solicitudes/Aprobar. Fuentes: k3.js:2149/2618/2647, intake.js:293/398, app.js:124/466. |
| (b) Aprobar 20 pendientes | Registrar equipos nuevos (1), Solicitudes (2), Aprobar cada fila (20): **22 acciones**, una página y dos vistas. Las 6 sin coincidencia exigen además escribir cédula/correo y corregir errores. No hay lote de aprobación en la tabla leída (`backend-v4/public/assets/intake.js:379`). |
| (c) Reemplazar por nuevo ya inscrito en IT | Asignación de equipos (1), Reasignar nuevo (2), elegir persona (3), Reasignar (4), Equipos (5), abrir nuevo (6): **6 acciones para reasignar y consultar**, 3 páginas y diálogo. Falta retiro del viejo: no se encontró control de reemplazo/retiro individual en los módulos leídos. Requiere PATCH de estado; no es honesto inventar otro clic existente ni un total cerrado. Si falta instalar nuevo, sumar instalación/solicitud del caso (a), sin crear persona. |

Abrir menús plegados, refrescar tras esperar conexión, crear catálogos, resolver permisos/fuentes o modificar plan agrega acciones. Son recorridos inferidos de código, no métricas de usabilidad.

## Maqueta y decisiones

### Navegación

Administrar → Personas y equipos. Ficha heredada → Gestionar persona y equipo / Reemplazar equipo / Baja. Rutas locales #inicio, #nueva, #solicitudes, #importar, #persona/p001, #reemplazar/p001 y #baja/p001. Estado en memoria durante esos recorridos; recargar o salir restaura ejemplos y se advierte en pantalla.

Superadmin administra; ambos supervisores reciben acceso denegado incluso por URL. En producción cada operación se mapeará a permisos, no solo al nombre del rol de ejemplo. Escenarios ?scenario=empty/error. Búsqueda sobre todo el catálogo, hasta 15 resultados visibles para acotar.

### Nueva persona y ficha

[Suposición] Cuatro etapas: Datos → Dónde trabaja → Su equipo → Revisar. Cliente fijo, empresa, sede, área, cargo, horario y plan. Cédula opcional; sin ella, asignación manual. Datos y duplicados validados antes de crear.

- **Ya inscrito:** equipo de reserva de IT; revisión de reasignación y conservación del historial previo. Pendiente de reglas/reporte nuevo.
- **Solicitud pendiente:** una sin otra persona sugerida; al crear queda aprobada para la nueva persona. Simular conexión crea el equipo; aún no confirma protección.
- **No instalado:** instrucciones preparadas, simulación de paquete y descarga TXT con pasos/contexto. Persona creada sin equipo, visible en seguimiento.

Termina en su ficha dentro de la tarea: Protegido, Sin confirmar o No protegido, con la causa concreta. No inventa actividad/productividad de personas recién creadas. Permite completar instalación/asignación, revisar plan sin controles y simular reportes correctos.

**Límite:** preparar muestra AZCKeeper-Grupo-AZC.zip como paquete previsto, pero no entrega ZIP falso, clave ni ejecutable real. Se descarga TXT identificado como demostración sin validez para instalar. No se envían mensajes. La descarga real existe en API.

### Solicitudes

[Suposición] 20 ejemplos: 14 matches por cédula y 6 sin match. Revisar los 14 muestra equipo/persona/cliente y exige confirmación. Las otras 6 se asignan explícitamente y revisan juntas o parcialmente. Rechaza solicitudes repetidas/personas inválidas. Aprobadas esperan conexión; inscritas esperan reporte. Ninguna se marca protegida por aprobarla.

### Reemplazo

[Suposición] Viejo, nuevo, motivo y revisión. Nuevo puede estar en reserva de IT o ya asignado a la misma persona tras aprobar su solicitud. No admite mismo equipo. Viejo pendiente de bloqueo: solo se retira al confirmar su ejecución. Sin conexión queda en la bandeja de problemas de incorporación. Nuevo con reglas pendientes. Se conservan persona, organización, excepciones e historial; no se copian reglas exclusivas del viejo ni se borra el disco.

La reserva son cuatro equipos ficticios activos asignados a IT; no presupone dispositivos sin dueño, que la API no permite asumir. No son cuatro personas adicionales.

### Baja

[Suposición] Una elección por equipo: bloquear, borrar o reasignar. Motivo, confirmación escrita RETIRAR Nombre Apellido y revisión. Borrado exige además nombre exacto del equipo y autenticación reciente simulada; respeta la exclusión del plan de ejemplo. No pide contraseñas reales.

Confirmar quita el acceso al panel en el acto y deja la baja pendiente: bloqueo/borrado esperan ejecución. El agente conserva su identidad hasta completarlas. Fallo bloquea finalización y permite reintento; equipo desconectado no confirma ejecución. Reasignación conserva historia previa y mantiene equipo activo para otro dueño. Finalizar solo tras completar todo: persona retirada, equipos no reasignados retirados, historial conservado. El borrado afecta disco, no historial del servidor.

**NUEVO:** proceso persistente de baja y coordinación concurrente. No existe hoy transacción para toda la secuencia. No se debe hacer PATCH inactive antes de completar comandos porque los cancela. Separar suspensión de acceso al panel de identidad necesaria para ejecutar comandos requiere diseño de servidor.

### Alta masiva

[Suposición] CSV de personas; instalación/aprobación de equipos sigue después. No se confunde con CSV de equipos esperados. Plantilla, ejemplo con errores y 300 personas; archivo o texto editable, coma/punto y coma, BOM, CRLF y comillas escapadas. Vista previa con número de fila, identidad, ubicación y errores. Detecta columnas/catálogos inválidos, cédulas existentes/repetidas, nombre/correo inválidos y cantidad incorrecta de columnas.

Guarda las filas válidas y deja las inválidas listadas para corregir/reintentar; editar invalida revisión y guardar revalida. El ejemplo de 300 tiene 287 válidas y 13 con sede incorrecta. Cada alta queda pendiente de instalar y enlazada a su ficha. Importar dos veces no duplica.

El importador actual sí admite guardar filas válidas y fallos parciales. **Todo o nada en memoria no demuestra atomicidad de POST /users.** Producción necesita validar en servidor y orquestar resultados/reintentos, o un endpoint transaccional si se exige atomicidad.

## API existente y trabajo nuevo

No se inventan rutas disponibles; la maqueta no llama ninguna.

| Necesidad | API existente | Trabajo pendiente |
|---|---|---|
| Personas | GET/POST /users, GET/PATCH /users/{id} (`docs/architecture/openapi-v4.yaml:2671`, `docs/architecture/openapi-v4.yaml:7760`) | Integración y recuperación del alta parcial; no duplicar persona si falla plan/equipo. |
| Ubicación/horario | GET /organization, GET /schedules (`docs/architecture/openapi-v4.yaml:3436`, `docs/architecture/openapi-v4.yaml:3555`) | Selectores según cliente/alcance/dependencias; respetar fuente externa. |
| Plan | GET /person-plans/available, GET/PUT /users/{id}/plan, GET /tenants/{id}/features (`docs/architecture/openapi-v4.yaml:3305`, `docs/architecture/openapi-v4.yaml:8842`, `docs/architecture/openapi-v4.yaml:12212`) | Explicar controles efectivos y conservar errores parciales. |
| Instalador | POST /enrollments/keys/installer y POST /auth/reauth (`docs/architecture/openapi-v4.yaml:11541`) | Integrar instrucciones, descarga real y error de artefacto ausente sin exponer clave. Descargar no significa instalado. |
| Claves/configuración | GET/POST /enrollments/keys, DELETE /enrollments/keys/{id}, GET/PUT /enrollments/settings (`docs/architecture/openapi-v4.yaml:11424`, `docs/architecture/openapi-v4.yaml:11620`, `docs/architecture/openapi-v4.yaml:11305`) | Resumen de aprobación vigente; configuración avanzada fuera del asistente. |
| Equipos esperados | GET/POST /enrollments/expected-devices, POST /enrollments/expected-devices:import (`docs/architecture/openapi-v4.yaml:10835`, `docs/architecture/openapi-v4.yaml:10975`) | Integrar después de crear personas; no es importación de personas. |
| Solicitudes | GET /enrollments/requests, POST /enrollments/requests/{id}/approve y /reject (`docs/architecture/openapi-v4.yaml:11098`, `docs/architecture/openapi-v4.yaml:11176`, `docs/architecture/openapi-v4.yaml:11247`) | Agrupación/revisión de lote nuevas. Una aprobación por API: orquestación, errores parciales/reintentos e idempotencia; no afirmar lote atómico existente. |
| Equipos/asignación | GET /tenants/{id}/devices, GET /devices/{id}, POST /devices/{id}/assignment (`docs/architecture/openapi-v4.yaml:7073`, `docs/architecture/openapi-v4.yaml:7131`, `docs/architecture/openapi-v4.yaml:7316`) | Reserva autorizada, versiones, revisar órdenes previas que se cancelarán. |
| Reemplazo | PATCH /devices/{id} y POST /devices/{id}/assignment | NUEVO flujo coordinado/persistente y recuperación si falla retiro o asignación. No hay ruta de reemplazo atómico identificada. |
| Baja/borrado/bloqueo | PATCH /users/{id}, POST/GET /devices/{id}/commands y POST /auth/reauth (`docs/architecture/openapi-v4.yaml:7475`) | NUEVO proceso auditado: suspensión inmediata del panel separada de la identidad del agente; esperar confirmación de acciones para retirar equipos. |
| Protección | GET /devices/{id}/security, /devices/{id}/rules, /tenants/{id}/protection (`docs/architecture/openapi-v4.yaml:7263`, `docs/architecture/openapi-v4.yaml:9525`, `docs/architecture/openapi-v4.yaml:12402`) | Componer fecha, versión, funciones y estado de cada control. Auditoría/sin reporte no es protegido. |
| Seguimiento | GET /devices/install-coverage y /users/{id}/install-coverage (`docs/architecture/openapi-v4.yaml:6358`, `docs/architecture/openapi-v4.yaml:10695`) | Cobertura ya existe. Cohorte de incorporaciones de semana + protección y pendientes requiere composición/estado adicional. |
| CSV personas | POST /users por fila; POST /users/batch edita existentes (`docs/architecture/openapi-v4.yaml:7684`) | Reusar validación y ampliar presentación/orquestación. No usar batch de edición como creación. |

## Cierre aprobado — vuelta 2, 30 de octubre

Las decisiones de ENCARGO-2.md sustituyen los supuestos de la primera vuelta:

- **Protegido:** todos los equipos activos reportaron controles en las últimas 24 h y el plan incluye controles. **Sin confirmar:** equipo sin conexión, reporte ausente/viejo o reglas pendientes; se indica equipo y fecha. **No protegido:** sin equipo, sin controles o fallo reportado. Un fallo tiene prioridad sobre un reporte viejo. Reserva y retirados no cuentan; los retiros en curso se siguen aparte. La lista inicial tiene mayoría protegida y tres altas recientes pendientes (incluye Sin confirmar).
- **CSV parcial:** se guardan válidas, se listan inválidas con número de fila, motivo y botón para llevar únicamente las pendientes al editor. Ejemplo de 300: «Se crearán 287 personas; 13 filas quedan para corregir». Revalidar antes del reintento; las cédulas existentes impiden duplicados.
- **Reemplazo:** el viejo queda en retiro pendiente y conserva identidad; solo bloqueo confirmado lo retira. Sin conexión: «Retiro pendiente: se bloqueará cuando vuelva a conectarse». Bandeja local en Personas y equipos → Problemas de incorporación, enlazada desde Equipos con problemas. Nuevo equipo recibe persona/reglas y espera reporte propio.
- **Baja:** confirmar revoca el acceso de la persona al panel inmediatamente. El agente conserva identidad para ejecutar las acciones; finalizar espera todas las confirmaciones. Historial conservado.
- **Ficha compacta:** resumen del día, equipos en filas y un menú Acciones por equipo; órdenes como filas plegables con orden, equipo y estado. Actividad detallada después del resumen y equipos.

Los modelos de esta maqueta son locales: salir de una tarea o recargar restablece sus ejemplos. La bandeja de incorporación vive en la misma página que el reemplazo para conservar el seguimiento sin almacenamiento del navegador. Producción necesita procesos persistentes y recuperación de operaciones parciales.

Dudas restantes: representación de reserva IT; políticas de retención del historial; origen contractual de los controles y estados desconocidos por versión. Las decisiones de vigencia, CSV, bloqueo previo y revocación inmediata ya están cerradas.

## Verificación y aceptación

Ejecutar `node verificar.cjs`. Incluye las regresiones heredadas, sintaxis, enlaces/recursos locales, referencias a código, modelos y eventos con DOM mínimo. `verificar-altas.cjs` verifica tres caminos de alta, lote 14 + 6, reemplazo pendiente/confirmado/fallido, baja inmediata con acciones pendientes, CSV parcial y reintentos sin duplicar, límite exacto de 24 h y mayoría protegida.

Revisión visual a 1366×768 y 375 px pendiente: no se usó navegador ni se generaron capturas nuevas. Las capturas preexistentes corresponden a la primera vuelta y no certifican el cierre. CSS adaptable revisado por código; altura final y foco nativo requieren validación visual externa.
