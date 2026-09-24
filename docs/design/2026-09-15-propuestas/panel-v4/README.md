# AZC Keeper: panel v4 definitivo de diseño

Versión de diseño basada en el spec aprobado [USABILIDAD-IA-v4.md](../USABILIDAD-IA-v4.md) y en la estética elegida [neomorfismo-antislop](../neomorfismo-antislop/index.html). Fecha: 16 sep 2026.

**Entrega estática funcional para revisar la arquitectura y los flujos. El Delivery Gate visual sigue PENDIENTE**, por instrucción del cliente. No representa una implementación de autenticación, seguridad o control remoto de producción.

## Abrir

Abrir [Inicio](index.html) desde disco. No necesita servidor, instalación, conexión a Internet ni build. Conservar `app.js`, `styles.css` y la carpeta hermana `assets/`. Los veintiún HTML contienen contenido inicial renderizado; JavaScript local activa las interacciones del núcleo y las confirmaciones de Endurecimiento. Las páginas señaladas como placeholders no tienen módulos de negocio implementados.

### Lista completa de páginas y estado

| Archivo | Sección | Estado |
|---|---|---|
| [login.html](login.html) | Acceso y cuentas de revisión | Núcleo: auxiliar de acceso |
| [index.html](index.html) | Inicio y gráficos | Núcleo |
| [equipos.html](equipos.html) | Inventario de equipos | Núcleo |
| [equipo.html](equipo.html) | Detalle y comandos | Núcleo |
| [usuarios.html](usuarios.html) | Personas y asignaciones | Núcleo |
| [miembro.html](miembro.html?session=admin&tenant=azc&id=azc-1) | Dashboard por persona | Núcleo |
| [reglas.html](reglas.html) | Política de empresa | Núcleo |
| [reportes.html](reportes.html) | Productividad y presencia | Núcleo |
| [ajustes.html](ajustes.html) | Marca e integraciones | Núcleo |
| [roles.html](roles.html?session=super&tenant=azc) | Roles y matriz de permisos | Núcleo, según gates |
| [empresas.html](empresas.html?session=super&tenant=azc) | Empresas | Núcleo, administración AZC |
| [endurecimiento.html](endurecimiento.html?session=super&tenant=azc) | Endurecimiento, Modo B | Diseño estático; requiere `hardening.gestionar` |
| [states.html](states.html) | Estados y recorridos | Núcleo: auxiliar de revisión |
| [turnos.html](turnos.html) | Turnos y presencia | Próximamente: En desarrollo |
| [tiers.html](tiers.html) | Tiers y suscripción | Próximamente: En desarrollo |
| [mensajes.html](mensajes.html) | Mensajes y notificaciones | Próximamente: En desarrollo |
| [soporte.html](soporte.html) | Soporte y recursos | Próximamente: En desarrollo |
| [tareas.html](tareas.html) | Tareas y delegación | Portal cliente (futuro): En desarrollo |
| [casos.html](casos.html) | Case Outsourcing | Portal cliente (futuro): En desarrollo |
| [recepcion.html](recepcion.html) | BL+AI Reception | Portal cliente (futuro): En desarrollo |
| [contratar.html](contratar.html) | Hire Talent | Portal cliente (futuro): En desarrollo |

`roles.html`, `empresas.html` y `endurecimiento.html` sin parámetros abren la cuenta Super admin para mostrar sus componentes. Los enlaces de la aplicación conservan siempre la sesión de revisión; un Admin Empresa sin autorización recibe una explicación al abrir Roles o Endurecimiento, incluso por URL directa.

## Endurecimiento: Modo B (23 sep 2026)

Abrir [Endurecimiento](endurecimiento.html?session=super&tenant=azc). Es una pantalla de diseño estático: HTML con estilos y recorridos propios incluidos, más los archivos locales compartidos `styles.css` y `app.js`. No ejecuta comandos, no implementa una API y no guarda contraseñas. Recargar restablece sus ajustes y estados de muestra.

- **Empresa:** admin gestionado `azcadmin` por defecto, contraseña compartida de solo escritura con `••••` como máscara visual, almacenamiento cifrado descrito como requisito del producto y advertencia «misma para toda la flota; mitigada con bloqueo de acceso por red». La clave real nunca se simula como parte de la consulta normal. Revelar abre confirmación y muestra únicamente `DEMO-NoValida-2026!`, con auditoría explícitamente simulada; cerrar borra el contenido del diálogo.
- **Aplicación:** Panel espera una orden por equipo; Auto aplica al recibir la configuración y tiene confirmación de impacto al activarse. Bloqueo de red ON, con explicación del movimiento lateral y del alcance real de S-1-5-114: cuentas locales que pertenecen a Administradores. No se presenta como protección de todas las cuentas locales ni como eliminación del riesgo de la clave compartida.
- **Equipos:** cinco ejemplos del alcance y empresa actuales. Chips con texto para Sin endurecer, En progreso, Endurecido, Requiere recuperación y En espera de panel. Endurecer explica la degradación del usuario activo, exige el nombre exacto del equipo y avisa que aplica en el próximo inicio de sesión. Revertir explica la re-promoción break-glass y pide confirmación normal. Las órdenes simuladas quedan En progreso, sin inventar una respuesta exitosa del agente. Las acciones incompatibles se deshabilitan y se explica por qué.
- **Acceso:** enlace en Administración AZC, tanto móvil como escritorio, marcado con `data-permission="hardening.gestionar"`. El catálogo local permite delegar el permiso desde Roles; Admin Empresa e IT no lo reciben por defecto. Sin permiso, desaparece la entrada y el acceso directo muestra la autorización necesaria. El catálogo de demostraciones ya guardadas incorpora la definición sin concederla automáticamente a sus roles.
- **Estados:** selector de muestra y enlaces directos a [vacío](endurecimiento.html?session=super&tenant=azc&state=empty), [cargando](endurecimiento.html?session=super&tenant=azc&state=loading) y [error](endurecimiento.html?session=super&tenant=azc&state=error). Explican qué falta, qué hacer y por qué las órdenes no están disponibles. [Andina Servicios](endurecimiento.html?session=super&tenant=nueva) no tiene equipos; [Admin Empresa](endurecimiento.html?session=admin&tenant=azc) ilustra el acceso sin permiso.

### Referencias de contrato, sin conexión

Las rutas indicadas en el encargo se documentan como referencia; no hay llamadas HTTP ni se inventan métodos, payloads o respuestas:

| Flujo de diseño | Ruta de referencia |
|---|---|
| Ajustes por empresa | `/admin/tenants/{id}/hardening` |
| Revelación auditada | `/admin/tenants/{id}/hardening/reveal` |
| Estado de un equipo | `/admin/devices/{id}/hardening` |
| Endurecer / revertir | `/admin/devices/{id}/hardening/command` |

Discrepancia de fuentes: la copia local de `docs/architecture/v4-requisitos-brief.md` consultada no contiene Modo B y los documentos locales de arquitectura no incluyen estas rutas. Los requisitos y endpoints de esta pantalla proceden del encargo del 23 de septiembre; no se modificaron documentos de arquitectura ni código de producto.

### Decisiones de diseño y reglas anti-slop

Lectura de diseño: pantalla administrativa para IT, neomorfismo claro existente; **ENERGY 1 / RHYTHM 2 / MOTION 1**.

| Decisión | Motivo |
|---|---|
| Superficie perla y relieve selectivo heredados | Mantener la identidad elegida del panel; el relieve agrupa los ajustes, no cada fila. |
| Arial local y tamaños en rem | Continuidad con Roles y Empresas, legibilidad y apertura sin descargar fuentes. |
| Ajustes antes del registro de equipos | Revisar la cuenta, el modo y el riesgo antes de quitar privilegios. |
| Un formulario en dos columnas amplias; una columna en móvil | Relacionar credenciales y forma de aplicación sin comprimir explicaciones. |
| Tabla que se convierte en registros verticales bajo 700 px | Leer equipo, estado y acciones a 390 px sin desplazamiento horizontal de la tabla. |
| Espaciado de sección y divisores; sin tarjetas de métricas | Separar configuración y operación sin agregar cifras ni bloques de relleno. |
| Rojo reservado a riesgo y degradación; chips semánticos con texto | Distinguir gravedad y acciones destructivas sin depender solo del color. |
| Icono de protección existente solo en navegación | Identificar la sección con el lenguaje gráfico del panel; sin ilustraciones decorativas. |
| Diálogo nativo, foco inicial en Cancelar y contorno visible de 3 px | Revisar el impacto antes de confirmar y permitir navegación por teclado. |
| Sin animaciones añadidas ni selector de tema | Mantener el panel empresarial claro y la atención en las decisiones. |

### Validación de esta incorporación

Ejecutada el 23 sep 2026 con Node y JSDOM locales, sin servidor ni acceso HTTP. Los diálogos se sustituyeron por métodos de prueba DOM; esto comprueba transiciones y foco programático, no geometría, navegación nativa con Tab/Escape ni encierro de foco del navegador.

| Comprobación | Resultado y evidencia |
|---|---|
| R-24: enlaces y recursos locales | **PASS**: 948 referencias relativas de los 21 HTML resuelven desde disco; se comprobaron también los fragmentos. |
| JavaScript y DOM | **PASS**: `node --check app.js`, compilación del script inline, IDs únicos, controles etiquetados y recorridos sin errores JavaScript. |
| R-25: contraste | **PASS estático**: texto principal 10,38:1; secundario 6,34:1; enlaces 5,81:1; botón primario 6,96:1. Chips: advertencia 7,10:1, información 10,02:1, correcto 7,76:1 y recuperación 8,40:1. Botón destructivo 9,34:1. Cálculo WCAG sobre los colores declarados, no muestreo de captura. |
| R-26: confirmaciones | **PASS DOM**: nombre incorrecto mantiene Endurecer deshabilitado; el nombre exacto permite confirmar; cancelar devuelve el foco. Break-glass confirma sin escritura. La orden deja En progreso y bloquea duplicados. Equipo offline explica la espera. |
| Clave y ajustes | **PASS DOM**: campo vacío con máscara `••••`, bloqueo ON, advertencia al apagarlo; guardar vacía la entrada sin modificar almacenamiento. Auto exige confirmar. Revelar exige confirmación, muestra auditoría ficticia y limpia la clave al cerrar. |
| R-27: estados | **PASS DOM**: vacío/cargando/error desde selector y URL; carga completada y reintento recuperan la lista. Andina empieza vacía. |
| Permiso y alcance | **PASS DOM**: enlace de escritorio y móvil desde Roles, Empresas e Inicio; sin permiso se oculta y se deniega URL directa para Admin Empresa, IT, Gerencia y Colaborador. Concesión explícita permite entrar; se respeta empresa fija y sede del rol. Atlas muestra su propia flota. |
| Demostraciones locales anteriores | **PASS DOM**: se incorpora el permiso al catálogo anterior sin asignarlo a roles existentes. |
| Sin servicios ni dependencias externas | **PASS**: recursos locales y ausencia de llamadas `fetch`, XHR, WebSocket, EventSource, beacon e imports remotos en los archivos involucrados. |
| R-17/18/23/36/38: contenido y honestidad | **PASS**: equipos y credencial marcados como ficticios; acciones y auditoría como simuladas; no hay testimonios, métricas inventadas, garantía de seguridad ni resultado exitoso de un agente. |
| Purpose-Gate / diales / composición | **PASS de revisión de fuente**: decisiones justificadas en la tabla anterior; ajustes y registros con jerarquía distinta, sin ilustraciones, gradientes ni animaciones añadidas. |
| R-03/R-32/R-35: visual y teclado real | **PENDIENTE**: la ejecución de Playwright con Edge fue bloqueada por `spawn EPERM`. No se generaron capturas ni se afirma validación visual a 1440/390, zoom al 200 % o recorrido nativo Tab/Escape. CSS preparado con apilado, tabla vertical en móvil, foco visible y diálogo nativo. |

La validación estática solicitada pasa. El Delivery Gate visual de esta incorporación queda pendiente de abrir el HTML en un navegador que pueda ejecutarse; el estado histórico del resto del panel no cambia.

## Placeholders navegables: decisión vigente del cliente

El cliente pidió conservar el núcleo y tener el resto del portal disponible como estructura navegable. Por eso la navegación mantiene primero las seis secciones principales, después los accesos AZC cuando corresponden y, al final, dos grupos separados: **Próximamente** y **Portal cliente (futuro)**. Cada ítem nuevo lleva texto «Pronto» o «Futuro», sin puntos que aparenten actividad en vivo. El menú móvil contiene los mismos grupos, dentro de su contenedor desplazable existente.

Los ocho stubs comparten cabecera, breadcrumb, contexto de empresa, logo, neomorfismo perla y navegación. Cada uno tiene título, badge **En desarrollo**, descripción breve, bloque **Vista previa de estructura**, un estado vacío que explica qué falta y enlaces reales hacia tareas disponibles. Las tarjetas interiores usan bordes discontinuos y etiquetas explícitas; no imitan registros cargados ni ofrecen botones sin función. Tiers muestra los nombres Essential/Pro/Business y «Sin vincular», sin precios, cobros o asignaciones inventadas.

Las cuatro secciones futuras declaran **fuera del núcleo de Keeper** e **integración externa por definir**. No hay redirecciones a proveedores, llamadas, recepción con IA, perfiles, casos o tareas reales. Soporte no inventa emails, teléfonos o documentación; indica usar el canal ya acordado con AZC y consultar el equipo afectado.

### Visibilidad de los stubs

Se reutilizan permisos existentes para no cambiar roles, semillas ni matriz del núcleo. La comprobación es la misma al construir el menú y al abrir una URL directa. Las expresiones con «o» requieren al menos uno de esos permisos; los roles inactivos o sin acceso al panel siguen rechazados.

| Stub | Condición de visibilidad | Resultado con roles de fábrica |
|---|---|---|
| Turnos | `usuarios.gestionar` o `reportes.ver` | Admin Empresa, Super admin, IT, RRHH, Gerencia, Dirección y Coordinación |
| Tiers | `empresa.ajustes` o `tiers.gestionar` | Admin Empresa consulta estructura de su empresa; Super admin / IT acceden por permiso central |
| Mensajes | `inicio.ver` | Cuentas administrativas/internas autorizadas; no Colaborador |
| Soporte | `inicio.ver` | Cuentas administrativas/internas autorizadas; no Colaborador |
| Tareas, Casos, Recepción, Contratar | `empresa.ajustes` | Admin Empresa y Super admin; otras cuentas solo si se les concede ese permiso |

Estos permisos habilitan únicamente la **vista previa**. No conceden operaciones futuras. En particular, consultar Tiers desde `empresa.ajustes` no concede `tiers.gestionar`, y ni siquiera Super admin puede modificar suscripciones desde este stub. Cuando se implementen los módulos, sus operaciones y permisos granulares necesitarán una definición propia; esta iteración no amplía la autoridad de ningún rol.

### Conservación y validación

El contenido `<main>` de las doce páginas anteriores conserva exactamente su SHA-256. También permanecen idénticas las fuentes del modelo, runtime, gráficos y los dos CSS del núcleo. Solo se agregan navegación, registro de destinos, renderer de placeholders y CSS con clases exclusivas de estos stubs. Los datos locales, acciones, charts y guardrails siguen funcionando con el pipeline existente.

Las páginas son estados vacíos de funcionalidad no implementada: no simulan cargas, errores de servicios inexistentes ni actividad ficticia elaborada. Las pruebas verifican enlaces relativos, permisos por cuenta, acceso directo, persistencia del tenant en navegación, marca de desarrollo y ausencia de formularios o acciones aparentes en los stubs. Los escenarios de carga/error y pruebas de flujos del núcleo se conservan.

**Anti-slop:** esta extensión está solicitada explícitamente y no introduce enlaces fantasma (R-24), controles muertos (R-26), métricas inventadas (R-17/R-38) ni un nuevo estilo (R-20/R-37). El badge comunica el estado real de desarrollo (R-09). Se mantienen los tokens de contraste y foco. El cierre renderizado a 1440/390, zoom y teclado nativo continúa pendiente para R-03/R-32/R-35; no se declara PASS visual.

## Iteración de panel cliente: gráficos y miembro

### Alcance visual

Se mantiene el look elegido: perla, relieve selectivo, Segoe UI, marca monocromática y acento `#245D68`. La referencia funcional es la estructura del portal cliente/miembro de LawyerDesk descrita en el encargo: KPI, salida semanal, carga por área, actividad y roster, y detalle de persona con cuatro pestañas. No se dispuso de URL o archivos identificables de esa demo durante esta iteración; no se afirma haber inspeccionado ni copiado su render.

El núcleo de esta iteración no implementa negocio de Case Outsourcing, Hire Talent, BL+AI Reception, Tasks o Messages. Por la decisión posterior del cliente, sus entradas ahora existen como stubs agrupados, descritos arriba. No hay facturación ni cobros. El tier del miembro sigue siendo información y no sustituye RBAC.

### Inicio

- Fila de cuatro KPI con iconos funcionales: equipos en línea **20/24** en el fixture inicial AZC, horas activas de esta semana, porcentaje de productividad con Focus Score y cumplimiento de reglas. Los números cambian con el tenant/alcance y el estado de los equipos.
- Alertas accionables se mantienen encima del análisis; equipos desconectados enlazan al detalle y reglas pendientes conservan una salida hacia el inventario.
- **Salida semanal:** línea/área de horas activas lun–dom, semana del 14 al 20 sep. Miércoles es parcial a las 09:42; jueves–domingo quedan sin datos y no se presentan como ceros.
- **Carga por área/proceso:** donut con distribución de tiempo activo por área; no implica capacidad disponible o carga máxima.
- **Roster de hoy:** seis personas con barras de utilización, hora de entrada y sede/remoto; cada nombre enlaza al miembro. Utilización = minutos activos / minutos transcurridos desde check-in hasta las 09:42.
- **Actividad en vivo:** se identifica expresamente como simulación local. «Simular siguiente evento» agrega un evento al tenant actual, sin temporizadores ni peticiones y sin alterar las métricas del corte.

### Reportes

[7 días](reportes.html?session=admin&tenant=azc&period=7) y [30 días](reportes.html?session=admin&tenant=azc&period=30) son destinos reales. El periodo cambia tanto los gráficos como el CSV. Tendencia diaria, donut productivo/no productivo, ranking descendente por porcentaje y asistencia por jornadas programadas. Todos los datos se recortan al tenant, área y sede efectivos. No hay tablas crudas: las cifras exactas de las líneas se consultan en una lista desplegable accesible.

La tendencia compara el último día con actividad contra el primero del periodo y expresa **puntos porcentuales**, no un porcentaje de crecimiento ambiguo. Días sin actividad dejan huecos en línea y área. La asistencia excluye días no programados de su denominador y muestra check-ins/permisos del día. El ranking mantiene nombre, porcentaje, horas y Focus Score sin afirmar una evaluación laboral.

### Miembro

[miembro.html](miembro.html?session=admin&tenant=azc&id=azc-1) acepta `session`, `tenant`, `id` y `tab`. Cada nombre en Usuarios enlaza a esta página y muestra su tier Essential, Pro o Business. Se conserva el registro completo y las acciones anteriores de Usuarios.

Encabezado con nombre, cargo, tier, sede/remoto y hora/ubicación de check-in de hoy. Cuatro KPI: primer login, descanso, horas activas y productividad del día.

| Pestaña | Contenido | Permisos |
|---|---|---|
| `resumen` | Equipo y specs, cinco check-ins, área de siete días, gauge Focus Score y donut de productividad | Acceso base con `usuarios.ver`, `usuarios.gestionar` o `reportes.ver`; métricas solo con `reportes.ver`, equipo con `equipos.ver` |
| `actividad` | Muestra de apps, ventanas y eventos; mapeo conceptual `window_episode` / `logs` | `reportes.ver` o `diagnostico.ver` |
| `rrhh` | Vacaciones/permisos de ejemplo, estado y fechas; sin controles de aprobación falsos | `usuarios.gestionar` |
| `acceso` | Equipos vinculados, estado, bloquear/desbloquear y apagar | `equipos.ver`; comandos con `equipos.accionar` y su permiso granular |

Las pestañas son enlaces relativos con `aria-current`, navegables y compartibles sin un widget de teclado adicional. Se ocultan las que no corresponden; un acceso directo a una pestaña no autorizada tampoco muestra su contenido. Una persona de otro tenant/área/sede no se resuelve. Ninguna selección de tier concede permisos.

Los comandos reutilizan el diálogo de Equipo: destino/empresa, efecto, motivo y hostname exacto. El miembro conserva la pestaña Acceso al confirmar, muestra cola y permite simular la respuesta. Tras bloquear aparece Desbloquear. No se creó un segundo sistema de confirmación.

### SVG inline y datos

Todos los charts se construyen en código propio como **SVG inline**: `path` para líneas/áreas/gauge, `circle` para puntos y segmentos de donut, `rect` para barras. No hay canvas, imágenes de gráficos, librerías ni CDN. Los SVG ya existen en el HTML inicial, no dependen de una descarga o del montaje de un framework.

- Líneas con eje desde cero, unidades y etiquetas; porcentaje fijo 0–100. Sin suavizado que invente oscilaciones.
- Un acento para la serie principal y carbón para la segunda categoría. Sectores del donut separados por perla para conservar contraste; cada leyenda muestra etiqueta y porcentaje.
- Barras con nombre y porcentaje fuera del SVG; escala 0–100 constante entre personas. Gauge con extremos 0/100 y valor numérico.
- `role=img`, `title`, `desc` o nombre accesible para cada chart; las líneas además incluyen valores en HTML desplegable. Sin depender de hover, color o tooltips para leer los datos.
- Variantes SVG estrecha/amplia para que los ejes no sean una miniatura de escritorio. Composición móvil en una columna, KPI en dos columnas, sin fijar ancho al documento.
- SVG usa variables de tema; el acento del tenant conserva el mismo requisito de contraste de Ajustes. Los pares principales mantienen ≥4.5:1 para texto y ≥3:1 para trazos informativos.

Cada persona del fixture tiene treinta observaciones diarias entre 18 ago y 16 sep 2026, con actividad, minutos productivos/no productivos, concentración, pausas y asistencia. **Productividad** = minutos productivos / minutos activos. **Focus Score** = minutos productivos en bloques de 25 minutos o más / minutos activos. Descansos excluidos en ambos. Los valores de concentración son parte de los fixtures; los episodios de ventanas son una muestra parcial, no la fuente completa para reconstruir treinta días.

Agregados, KPI, porcentajes y CSV se calculan desde las mismas observaciones. Las horas de hoy más descansos no superan el tiempo transcurrido desde check-in. Las personas nuevas no reciben actividad inventada: muestran estados sin registro hasta añadir datos. Los datos locales previos se enriquecen sin borrar roles, marca, gates ni comandos pendientes.

**Anti-slop de esta iteración:** el cliente pidió explícitamente cuatro KPI, gráficos, roster y feed (R-05/R-14/R-17/R-38). Se implementan con propósito, datos ficticios rotulados y jerarquía; no como cuatro tarjetas flotantes. El badge tier corresponde a un dato solicitado (R-09). Se conserva ENERGY 2 / RHYTHM 2 / MOTION 1. La simulación de actividad es manual, sin pulsos decorativos (R-19).

## Aplicación del spec

### Contexto y navegación

Admin Empresa conserva arriba **Inicio, Equipos, Usuarios, Reglas, Reportes y Ajustes**. La ampliación solicitada agrega los dos grupos de placeholders al final, según permiso. Barra superior adherida al viewport con empresa fija, breadcrumb, búsqueda global y cuenta actual. El selector de empresa aparece únicamente en Super admin; se prioriza esa restricción sobre la mención a IT en §3 del spec. IT conserva su alcance asignado sin selector en este recorrido.

Roles de empresa se enlaza desde Ajustes únicamente si se cumplen ambos gates. Miembro es un detalle de Usuarios, con enlaces adicionales desde roster y ranking. Super admin y gestión central autorizada conservan sus accesos adicionales a Empresas/Roles. Los nuevos módulos aparecen separados de las seis secciones del núcleo y claramente marcados. Releases y Diagnóstico se documentan como permisos del catálogo, sin enlaces a páginas inexistentes.

La búsqueda encuentra equipos, personas y empresa dentro del alcance y los permisos de la cuenta. Los controles sin permiso se omiten. Cada pantalla de acción tiene una única acción primaria; el diálogo concentra la acción mientras el fondo queda inerte mediante `showModal()`.

### F1 a F7

| Flujo | Implementación y resultado |
|---|---|
| F1 · Revisar flota | Cuatro KPI, alertas accionables de desconexiones largas, salida semanal, distribución por área, roster y feed de actividad simulado. Las cifras se calculan desde los registros de ejemplo. |
| F2 · Agregar equipo | Guía de tres pasos exigida por el spec: código vinculado al tenant, instrucciones para Windows y llegada simulada del agente. La lista incorpora el equipo nuevo. |
| F3 · Acciones remotas | Bloquear/desbloquear, apagar y borrar comprueban empresa, permiso general y permiso granular. Nombre exacto del equipo, motivo y resumen de impacto obligatorios. Se distingue cola de confirmación del agente; bloqueo muestra después la acción Desbloquear. Wipe irreversible sin deshacer. |
| F4 · Reglas | Una política por empresa. Cuatro bloques con interruptor, explicación, dominios y días/horas. Restricciones nuevas apagadas. Confirmación del alcance y efecto diferido. Excepciones visibles solo con `reglas.excepciones`. Una cuenta con alcance parcial no puede aplicar a la empresa completa. |
| F5 · Personas | Registro de identidad, documento, correo, firma obligatoria, área, sede, cargo y horario; campo de extensión `extra`. Asignación por selector accesible. Baja con nombre escrito y decisión de liberar/reasignar equipo; conserva historial. Altas con rol Colaborador sin login. |
| F6 · Reportes | Gráficos de productividad y presencia a 7/30 días, donut, ranking por porcentaje de apps productivas y exportación CSV de la misma serie. Sin tablas crudas. La clasificación de aplicaciones no es una evaluación de desempeño. |
| F7 · Marca | Nombre, logo local y acento con preview. El color se valida antes de guardar; si falla, el preview conserva el último acento legible. Claves ficticias de integraciones con creación y revocación confirmada. |

### Dos gates y modelo de roles

1. `tenant.autonomiaRoles`: **OFF por defecto**. Solo autoridad Super admin puede activarlo/revocarlo, en Empresas, con confirmación de efecto.
2. `roles.gestionar`: requiere concesión explícita. El gate de empresa por sí solo no lo concede. Solo Super admin puede otorgar este meta-permiso.

Para la gestión local se requieren ambos. Super admin y IT central con autoridad explícita conservan la gestión central cuando la autonomía esté apagada; IT solo opera sobre empresas/sedes asignadas y permisos propios.

Roles semilla: Super admin, Gerencia, Dirección, Coordinación, IT, RRHH, Admin Empresa y Colaborador. Son datos del modelo, no comparaciones por nombre para mostrar opciones. Se pueden renombrar, crear, clonar y desactivar. Un rol nuevo empieza sin permisos. El alcance incluye empresa/área/sede/propio; si área y sede están presentes, se intersectan. El tenant sigue siendo una frontera independiente.

- La matriz solo ofrece permisos delegables. No se conceden permisos ajenos ni alcances superiores.
- No se modifica el propio rol. No se clona el meta-permiso desde una cuenta delegada.
- Desactivar un rol asignado exige reasignación; se impide reasignar la propia cuenta o la autoridad de plataforma.
- Catálogo maestro y semillas globales son editables solo por Super admin. La autoridad de plataforma puede gestionar nuevas definiciones del catálogo. Las semillas modificadas se copian a empresas nuevas; no sobrescriben roles ya asignados.
- Agregar una definición de permiso no crea por sí solo un módulo de negocio. En producción cada operación deberá comprobar su clave configurada en el servidor.
- Auditoría local: actor, operación, empresa y momento. Las fechas de cambios usan el reloj del navegador; el corte de datos de muestra permanece fijo.

### Mapa sección → permiso (§4)

| Sección del spec | Permiso | Acción de fábrica | Solo lectura de fábrica |
|---|---|---|---|
| Inicio | `inicio.ver` | Ninguna | Internos + Admin Empresa |
| Equipos | `equipos.ver` / `equipos.accionar` | Super admin, IT, Admin Empresa | Gerencia, Dirección, Coordinación |
| Usuarios | `usuarios.gestionar` | Super admin, RRHH, IT, Admin Empresa | Gerencia, Dirección |
| Reglas | `reglas.editar` | Super admin, IT, Admin Empresa | Ninguna |
| Reportes | `reportes.ver` | Exportar la lectura | Super admin, Gerencia, Dirección, Coordinación, RRHH, Admin Empresa |
| Ajustes | `empresa.ajustes` | Super admin, Admin Empresa | Ninguna |
| Empresas | `tenants.gestionar` | Super admin, IT | Ninguna |
| Tiers / licencias | `tiers.gestionar` | Super admin | IT |
| Auditoría | `auditoria.ver` | Consulta | Super admin, IT, Gerencia en su empresa |
| Releases | `releases.gestionar` | Super admin, IT | Ninguna |
| Diagnóstico / control avanzado | `diagnostico.ver` | Super admin, IT | Ninguna |
| Roles y permisos | `roles.gestionar` + gates | Super admin y delegación autorizada | Ninguna |

Para expresar la lectura de Usuarios que §4 asigna a Gerencia/Dirección, la matriz descompone su permiso en `usuarios.ver` y `usuarios.gestionar`; no concede gestión a esos roles. Operaciones de personas añaden `usuarios.alta`, `usuarios.baja`, `usuarios.asignar`. Las acciones de equipo requieren `equipos.accionar` y una clave específica (`equipos.bloquear`, `equipos.desbloquear`, `equipos.apagar`, `equipos.borrar`). Alta usa `equipos.agregar`. Son capacidades granulares coherentes con §1.5, sin cambiar las seis secciones.

Auditoría general, Releases y Diagnóstico se reservan en el catálogo y en este mapa, sin páginas adicionales. Auditoría de cambios de roles sí aparece dentro de Roles. Tiers ahora tiene una página placeholder de consulta, sin operaciones de suscripción. La tabla conserva el default de lectura de IT para Tiers; su stub no confunde lectura con escritura.

## Dirección visual conservada

**ENERGY 2 / RHYTHM 2 / MOTION 1.** No se cambia la estética elegida. `styles.css` comienza con una copia literal completa del CSS de neomorfismo-antislop, que ya contiene la base compartida. A continuación se agrega el layout de v4. El original no se modifica.

| Decisión | Propósito |
|---|---|
| Perla uniforme, sombra doble selectiva | Conservar el neomorfismo escogido y distinguir la superficie de trabajo principal. |
| Relieve hundido en navegación activa y foco de campos | Localización y respuesta al uso, sin relieve en cada celda o cifra. |
| Registro de cuatro KPI en Inicio | Requisito explícito de esta iteración: disponibilidad, horas, productividad/focus y cumplimiento. Se agrupan en una franja sin cuatro sombras equivalentes. |
| Alertas visibles antes de los gráficos | Mantener una ruta directa de revisión para las desconexiones y reglas pendientes. |
| Registro plano de eventos y tablas operativas | Densidad para gestión de equipos/personas; Reportes usa gráficos y listas, sin tablas crudas. |
| Nav lateral de seis tareas | Es una decisión explícita del spec aprobado; su identidad conserva la superficie perla y el control hundido. |
| Segoe UI y cifras tabulares | Lenguaje visual familiar al personal que administra equipos Windows; sin webfonts. |
| Un acento #245D68 | Continuidad del finalista; identifica acción primaria y enlaces. La gravedad se comunica mediante palabras e impacto, no mediante colores adicionales. |
| Icono funcional + texto | Cumple el spec sin depender de una librería ni usar iconos ornamentales. |
| Cambios inmediatos y sin animaciones decorativas | Hace visible el resultado operativo. Reduce movimiento a la respuesta nativa del control. |

### Tokens

| Token | Valor | Uso |
|---|---|---|
| `--brand-gray` | `#9A9A9A` | Identidad del escudo; no texto pequeño |
| `--ink` | `#303030` | Texto principal |
| `--brand-white` | `#F8F8F8` | Texto de acción primaria, luz y avisos |
| `--surface` | `#E4E4E4` | Perla de fondo y superficies |
| `--hover` | `#D9D9D9` | Interacción secundaria |
| `--muted` | `#505050` | Texto secundario |
| `--accent` | `#245D68` | Único acento del finalista |
| `--line` | `#A6A6A6` | Separadores no interactivos |
| `--control` | `#686868` | Contorno perceptible de controles |
| `--body`, `--display` | `'Segoe UI', Arial, sans-serif` | Interfaz, títulos y datos |
| Monoespaciada heredada | `Consolas, 'Courier New', monospace` | Claves de permisos e identificadores API |
| Espaciado | 4, 8, 12, 16, 24, 32, 48, 56 px | Ritmo: control, grupo, sección |
| Radios | 8 px controles; 16 px diálogo; 18/20 px superficie | Relieve moderado; sin cápsulas repetidas |
| `--relief` | `6px 6px 14px #C2C2C2, -6px -6px 14px #F8F8F8` | Superficie principal y diálogo |
| `--pressed` | `inset 2px 2px 4px #C2C2C2, inset -2px -2px 4px #F8F8F8` | Foco y navegación actual |
| Foco | 3 px sólido, separación 4 px | Teclado, visible sin depender de la sombra |
| Objetivos | 44 px mínimo | Botones, etiquetas de interruptor, enlaces principales |

Contrastes calculados en [VALIDACION.md](VALIDACION.md). El acento sobre hover es el par de texto más exigente; el umbral aplicado es 4.5:1. Los separadores decorativos no se usan como único contorno de un control.

### Parametrización por tenant

El registro de empresa contiene `name`, `logo` y `accent`. El contexto efectivo aplica `--accent` al documento y el nombre/logo a cabecera y preview. No existe selector para cambiar la empresa del Admin Empresa. El rebrand nunca modifica la tipografía, la estructura de navegación, los controles ni los colores base.

```css
:root {
  --brand-gray: #9A9A9A;
  --ink: #303030;
  --brand-white: #F8F8F8;
  --surface: #E4E4E4;
  --accent: #245D68;
}
```

Solo se guardan acentos que alcancen 4.5:1 frente a perla, blanco y fondo de hover. El preview no muestra una acción ilegible mientras se corrige el color. El logo usa recursos de `../assets/` inicialmente; una imagen elegida desde disco se lee mediante FileReader, sin subida ni petición externa. El archivo se valida por tipo y tamaño. El cliente deberá revisar visualmente cualquier logo de tenant final.

### Componentes y adaptación

Barra de contexto adherida, navegación de seis tareas, menú móvil etiquetado, búsqueda global, superficie de decisión, contador de flota, tabla con scroll local accesible, ficha de equipo, cronología, interruptor con explicación, formulario por bloques, agregados de lectura, preview de marca, doble gate y matriz de permisos.

Layout amplio: nav + área de trabajo; trabajo principal y margen de lectura asimétrico. Por debajo de 1150 px se apilan los bloques operativos. Por debajo de 760 px aparece el menú móvil y se apilan campos/cotas. Los gráficos tienen composición estrecha, intermedia desde 700 px y amplia desde 1180 px; la fila KPI pasa de dos a cuatro columnas. Las tablas operativas conservan relación entre columnas mediante scroll **local**, con región etiquetada y foco, sin imponer ancho al documento. ResizeObserver ajusta el offset de la barra al tamaño real. No se fija la altura de texto ni se trunca información.

Native dialog para foco/inercia/Escape; retorno de foco al cerrar; avisos de resultado persistentes, cerrables y enfocados; `aria-busy` en carga; `role=status` en resultados; reduced-motion y forced-colors. **La verificación renderizada de estos comportamientos no se declara realizada.**

## Datos y límites del prototipo

- Datos solicitados explícitamente por el cliente como ficticios: 24 equipos/personas de Grupo AZC, 12 de Atlas y una empresa vacía, Andina. El catálogo es una muestra enfocada en la nueva IA; no replica los 180 registros de las propuestas históricas.
- Empresas, áreas, sedes, correos, documentos y métricas se identifican como muestra. Dominios de correo de ejemplo, sin envío de mensajes.
- Las cuentas de `login.html` y `states.html` seleccionan escenarios, no autentican. Los parámetros `session`/`tenant` sirven para revisión. Cambiar el tenant sin autoridad Super no amplía el contexto del Admin en el modelo de demo.
- Los permisos se comprueban en el cliente para hacer verificables los flujos. Los datos están incluidos en JavaScript y pueden inspeccionarse o alterarse. **Esto no implementa una frontera de seguridad**. Autenticación, filtros y autorización de servidor, auditoría persistente, entrega de comandos y API reales quedan para implementación de producción.
- Las ediciones intentan persistir en `localStorage` con clave `azc-panel-v4-demo-1`. La política de almacenamiento `file:` depende del navegador; si no permite guardar, aparece aviso y la página sigue funcionando en memoria. Los flujos entre archivos se comprueban en pruebas DOM con almacenamiento compartido simulado.
- No se exportan documentos personales: el CSV incluye agregados diarios del alcance efectivo de la cuenta, con empresa, horas, productividad, focus y check-ins. Las claves API comienzan con `DEMO-SIN-ACCESO` y no dan acceso real.
- Restablecer datos desde Estados recupera fixtures y gates OFF. No toca ningún dato de producción.

## Anti-slop Delivery Gate

**Veredicto global: PENDIENTE de validación visual, no PASS global.** El cliente pidió expresamente esta entrega estática y hará el cierre con agent-browser. R-03, R-32 y R-35 quedan pendientes. Esto sustituye, para esta entrega, la obligación del ruleset de detener la entrega por falta de esa evidencia. No se afirma haber renderizado a 1440/390, aplicado zoom ni completado Tab/Enter/Escape nativos.

Las reglas se aplicaron durante la construcción, manteniendo la dirección elegida. No se instalaron skills, no se alteró AGENTS y no se crearon logos o ilustraciones nuevos. La navegación y los iconos funcionales fueron solicitados explícitamente.

| Regla | Estado | Evidencia / motivo |
|---|---|---|
| R-01 · Color / gradientes | PASS fuente | Paleta de marca y acento original; sin gradientes ni glows. |
| R-02 · Copy | PASS estático | Texto de tarea; verificador rechaza raya larga en el contenido. |
| R-03 · Reflow móvil | **PENDIENTE VISUAL** | CSS 1150/760 y scroll local listos; faltan medidas renderizadas 1440/390, intermedios y zoom. |
| R-04 · Iconos | PASS fuente | SVG funcional pequeño y etiqueta; requerimiento explícito, sin librería ni decoración. |
| R-05 · Layout / ritmo | PASS documental | ENERGY 2 / RHYTHM 2; prioridad, registros y formularios diferenciados. Tres pasos solo en F2 por el spec. |
| R-06 · Tipografía | PASS fuente | Segoe UI del finalista, afinidad con Windows, fallback local y cifras tabulares. |
| R-07 · Fondo | PASS fuente | Perla continua, sin tramas ni retículas ornamentales. |
| R-08 · Flechas | PASS fuente | No hay flechas repetidas en botones. Breadcrumb usa separador de ruta. |
| R-09 · Badges | PASS fuente | Badge de tier solicitado: Essential/Pro/Business, borde neutro y radio de 4 px. Comunica un dato, no publicidad; no altera permisos. |
| R-10 · Glass | PASS fuente | No se usa translucidez de superficies ni blur. |
| R-11 · Radios | PASS fuente | 8 px controles, 16 px diálogo, 18/20 px superficie; tablas rectas. |
| R-12 · Sombra | PASS documental | Doble sombra preserva el diseño elegido; aplicada a trabajo y diálogo, no a cada KPI/fila. |
| R-13 · Glow | PASS fuente | Ausente. |
| R-14 · Cards | PASS fuente | Cuatro KPI solicitados, agrupados en registro continuo sin sombras individuales. Gráficos con distinta jerarquía y pregunta explícita. |
| R-15 · CTA | PASS DOM | Una primaria en el contenido de cada pantalla; verbos concretos y confirmación de efecto. |
| R-16 · Buzzwords | PASS contenido | Lenguaje administrativo, sin promesas publicitarias. |
| R-17 · Datos | PASS con instrucción explícita | Fixtures ficticios pedidos por el cliente, identificados en todas las páginas; métricas derivadas, sin alegar rendimiento real. |
| R-18 · Testimonios | PASS contenido | No hay testimonios ni reseñas. |
| R-19 · Movimiento | PASS fuente | MOTION 1, respuesta al control y preferencias reducidas; sin animaciones de plantilla. |
| R-20 · Identidad | PASS documental; revisión visual abierta | Copia literal del CSS elegido, perla, relieve selectivo, asimetría de decisión. La aceptación óptica la hace el cliente. |
| R-21 · Tema oscuro | PASS fuente | Solo tema claro; `color-scheme: light`. |
| R-22 · Ilustraciones | PASS fuente | No se añaden ilustraciones genéricas. |
| R-23 · Assets / dirección | PASS documental | Logos existentes, dirección y nav suministradas por el cliente; sin generación de activos de marca. |
| R-24 · Navegación | PASS estático | Referencias relativas existentes; ocho stubs enlazados con «Pronto»/«Futuro» y estado «En desarrollo». |
| R-25 · Contraste | PASS cálculo; visual pendiente | Pares de texto ≥4.5:1, controles ≥3:1; rebrand rechaza acentos ilegibles. Evidencia numérica en VALIDACION. |
| R-26 · Controles | PASS DOM en recorridos registrados | F1–F7, búsqueda, gates, matriz, clonación, catálogo, empresa, estados, periodos 7/30, pestañas de miembro, feed manual y comandos desde miembro; nativos pendientes en R-35. |
| R-27 · Estados | PASS DOM | Núcleo: vacío/carga/error, cola y confirmación destructiva. Stubs: estado vacío explicativo de funcionalidad aún no implementada, sin simular servicios o cargas. |
| R-28 · FAQ | PASS contenido | Sin FAQ de relleno. |
| R-29 · Paleta | PASS fuente | Tres colores de marca, neutros derivados y un acento. No color como único significado. |
| R-30 · Clones | PASS documental | Se conserva la dirección elegida; no se incorpora un tema SaaS/Material externo. |
| R-31 · Motivos | PASS documental | Tabla de decisiones, tokens, estructura y propósito de componentes en este README. |
| R-32 · Teclado | **PENDIENTE NATIVO** | Labels/foco, Escape y retorno probados en DOM; falta Tab/Shift+Tab/Enter/Escape con navegador real, zoom y lector. |
| R-33 · Edición | PASS proceso | Fuentes nuevas editadas directamente. Generador compone HTML/CSS/JS; no reescribe código de producción ni parchea originales por sustituciones. |
| R-34 · Temas | PASS fuente | Un único tema claro, sin conmutador incompleto. |
| R-35 · Ejecutar y verificar | **PENDIENTE VISUAL** | Build y pruebas DOM registradas; falta recorrido renderizado completo y descargas/diálogos nativos. |
| R-36 · Claims | PASS contenido | Sin promesas de seguridad/productividad; las limitaciones del prototipo se declaran. |
| R-37 · Dirección | PASS documental | Diseño elegido por el cliente, spec aprobado, ENERGY 2 / RHYTHM 2 / MOTION 1. |
| R-38 · Contenido | PASS con instrucción explícita | Datos de demostración solicitados, rotulados como ficticios; sin enlaces fantasma ni supuestos clientes reales. |

### Craftsmanship y liveliness

- C-1: cada decisión tiene propósito en la tabla; no se añade otra dirección estética.
- C-2: controles conectados a navegación o cambio local comprobable; pruebas registradas, pendiente recorrido nativo total.
- C-3: contenido y jerarquía proceden de tareas del spec, sin secciones de marketing.
- C-4: estados en DOM y reglas responsive presentes; **pendiente** robustez renderizada y teclado nativo.
- C-5: datos etiquetados, cálculos desde fixtures; ningún claim de aislamiento de servidor ni de pruebas visuales.
- ENERGY 2: cifra prioritaria grande y contraste de acción, sin saturar la página.
- RHYTHM 2: superficie principal elevada junto a lectura plana; tablas, registro y formularios tienen su propio ritmo.
- MOTION 1: feedback de controles y cambios de estado sin movimiento ornamental.

## Verificación reproducible

Desde la raíz del repo:

```powershell
node docs/design/2026-09-15-propuestas/_tools/panel-v4-build.cjs
node docs/design/2026-09-15-propuestas/_tools/panel-v4-validate.cjs
rg -n '(src|href)\s*=\s*["\x27](https?:)?//' docs/design/2026-09-15-propuestas/panel-v4 -g '*.html'
rg -n '@import|url\(\s*["\x27]?(https?:)?//' docs/design/2026-09-15-propuestas/panel-v4 -g '*.css'
```

Las búsquedas deben producir cero coincidencias (rg sale con 1 si no encuentra). El build aislado escribe solo los veinte HTML, `styles.css` y `app.js` de panel-v4. La prueba escribe `VALIDACION.md`. Reutiliza el criterio y JSDOM del pipeline existente; no ejecutar los generadores históricos para esta versión porque regeneran otras propuestas.

Fuentes en `_tools/`: `panel-v4-model.js`, `panel-v4-view.js`, `panel-v4-runtime.js`, `panel-v4.css`, `panel-v4-build.cjs`, `panel-v4-validate.cjs`, `panel-v4-original-hashes.json`; gráficos en `panel-v4-insights.js` y `panel-v4-insights.css`. Los stubs agregan `panel-v4-placeholders.js`, `panel-v4-placeholders.css` y `panel-v4-stubs-baseline.json`. JSDOM solo es dependencia del verificador, nunca del HTML entregado. Puede configurarse su ubicación con `JSDOM_MODULE`. Se comprueban los diez SHA-256 del original y la conservación del contenido del núcleo anterior a los stubs.

### Pendiente para agent-browser del cliente

1. Abrir los veinte archivos y las cuatro pestañas de Miembro a 1440 y 390 px; comprobar también 768/1024 y 320 CSS px. Medir overflow del documento y distinguirlo del scroll deliberado de las tablas. Revisar ejes, leyendas y lectura del gauge sin depender del color. Recorrer los dos grupos nuevos del menú móvil y verificar las vistas previas apiladas.
2. Zoom 200% y 400%, textos/nombres largos, barra fija, diálogos y mensajes sin superposición o recorte. Verificar que el encabezado no tape el elemento enfocado.
3. Recorrer solo con Tab/Shift+Tab/Enter/Espacio/Escape: navegación, búsqueda, menú, filtros, switches, matriz, confirmación escrita y retorno de foco. Verificar trap/inercia nativos del diálogo.
4. Probar reduced-motion, forced-colors, zoom de texto y lector de pantalla en labels, estado, errores y grupos de permisos.
5. Repetir F1–F7 y recorrido de ambos gates desde Estados. Probar cancelación, datos inválidos, retardo/offline y wipe. Validar descarga CSV, selector de archivo y persistencia local entre HTML.
6. Registrar capturas y medidas. Resolver cualquier hallazgo y cerrar explícitamente R-03/R-32/R-35; hasta entonces el Gate global sigue pendiente.

Sin cambios de producción, sin modificar neomorfismo-antislop y sin commit.
