# Paso 2 · Mandar órdenes a equipos

Abrir [index.html](index.html) con doble clic. Es una copia del paso 1 **después del cierre de ENCARGO-3**, extendida dentro de esta carpeta. HTML, CSS y JavaScript clásicos, fuente y logos locales, sin servicios ni dependencias de ejecución. Español de Colombia, horas de Bogotá, octubre de 2026 y datos ficticios.

## Recorrido de la maqueta

| Entrada | Tarea |
|---|---|
| [Inicio](index.html) → persona → Sus equipos | Consultar el día y mandar una orden al equipo elegido. |
| [Ficha de ejemplo](persona.html?id=p001) | Dos equipos; órdenes y seguimiento separados por equipo. |
| [Equipo conectado](equipo.html?device=DESKTOP-0001) | Efectos, versión, protección, plan, órdenes e historial junto a los botones. |
| [Equipo sin conexión](equipo.html?device=DESKTOP-0501) | Cola, vencimiento, versión atrasada y limitación para cancelar cuenta regresiva. |
| [Administrar](administrar.html) → [Equipos con problemas](problemas.html) | Protección fallida, desconexión prolongada, versión atrasada y órdenes fallidas/vencidas; selección múltiple y revisión previa. |
| [Asistente de IA](asistente-ia.html) | Configuración heredada del cierre del paso 1; previa en lenguaje llano y JSON plegado. |
| [Programar apagado](programacion.html?device=DESKTOP-0001) | Una sola fecha, revisión y guardado simulado; repetición semanal reservada al paso 3. |

La acción principal de la bandeja es **Revisar orden para selección**. Los botones de las fichas abren un único diálogo con una acción principal de envío; el borrado queda en un desplegable separado. El resultado aparece inmediatamente debajo. Todos los controles operan solo sobre datos en memoria y se restablecen al recargar o navegar; ninguna orden ni contraseña sale del navegador. La navegación conserva rol y contexto en la URL, no el historial modificado.

## Cómo es hoy: evidencia del repositorio

Convenciones: **[Seguro]** verificado en código/contrato; **[Probable]** inferencia sobre el recorrido; **[Suposición]** decisión de esta propuesta. Se inspeccionó el código local sin ejecutar servicios ni consultar datos reales. No se leyó ni modificó `client-v4/`; las compatibilidades siguientes provienen del panel y servidor, no de una prueba del agente.

### Pantallas, órdenes y cantidad de clics

| Certeza | Hallazgo | Evidencia |
|---|---|---|
| [Seguro] | El menú tiene Equipos y Apagado programado; Resumen de protección es otra entrada. | `backend-v4/public/partials/sidebar.php:14`, `:19` |
| [Seguro] | La lista de equipos enlaza `equipo.php?id=…`. La ficha inserta acciones, cambios de nombre, protección y finalmente historial. | `backend-v4/public/assets/app.js:124`, `:443`, `:461`, `:467` |
| [Seguro] | La ficha de persona ya tiene acciones en «Equipo y controles». No es una capacidad nueva. El historial allí muestra orden, estado y fecha; la cancelación completa está en la ficha del equipo. | `backend-v4/public/assets/coordinator.js:544`, `:555`, `:559`, `:560`; `backend-v4/public/assets/app.js:614` |
| [Probable] | Desde Inicio: Equipos → equipo → orden → confirmar = 4 clics mínimos, más desplegar el grupo del menú si estaba cerrado y elegir cliente si hace falta. Para borrar, abrir además la zona de peligro y escribir nombre; autenticación puede añadir un diálogo. | Composición de las rutas anteriores; `backend-v4/public/assets/device-actions.js:184`, `:207`, `:221`, `:256` |
| [Probable] | Desde Inicio: Personas → persona → Equipo y controles → orden → confirmar = 5 clics mínimos. Buscar/filtrar y autenticar no se cuentan como clics fijos. | `backend-v4/public/partials/sidebar.php:13`; `backend-v4/public/assets/coordinator.js:544` |
| [Seguro] | Hay bloquear, desbloquear, reiniciar, apagar y borrado remoto. Los textos explican bloqueo de IT, pérdida de trabajo y alcance del borrado. | `backend-v4/public/assets/device-actions.js:15`, `:27` |
| [Seguro] | «Aplicar reglas» está en la API y en las etiquetas del historial, pero **no se ofrece como botón**: el comentario del panel indica que las reglas ya llegan en el mismo ciclo de sincronización. No equivale a actualizar el software del agente. | `backend-v4/public/assets/device-actions.js:6`, `:31`; `backend-v4/src/AdminApi.php:52`; `docs/architecture/openapi-v4.yaml:7475` |
| [Seguro] | El historial al final de la ficha muestra orden, envío, estado, resultado, vencimiento y cancelación. Para saber quién y el motivo remite a Auditoría. | `backend-v4/public/assets/app.js:576`, `:599`, `:629` |
| [Probable] | Ver el resultado desde la ficha exige desplazarse al historial; «Actualizar historial» añade un clic. Cancelar desde allí son 2 clics: Cancelar → confirmar, más autenticación si es borrado. | `backend-v4/public/assets/app.js:580`, `:619`; `backend-v4/public/assets/device-actions.js:82`, `:105` |

### Permisos y plan efectivo

[Seguro] La API selecciona el permiso por tipo de orden y valida alcance sobre la persona dueña del equipo. Las funciones se calculan según su plan efectivo; sin dueño activo, se usan las del cliente. No basta con tener un botón visible ni con ser administrador para saltarse la función del plan (`backend-v4/src/AdminApi.php:20`, `:27`, `:35`, `:39`, `:63`). El panel consulta `/me/access` y `allowed_commands` (`backend-v4/public/assets/device-actions.js:145`, `:150`, `:185`).

| Orden | Permiso | Función del plan efectivo para crear |
|---|---|---|
| Bloquear | `equipos.bloquear` | `comandos` |
| Desbloquear | `equipos.desbloquear` | `comandos` |
| Reiniciar | `equipos.reiniciar` | `comandos` |
| Apagar | `equipos.apagar` | `comandos` |
| Borrado remoto | `equipos.borrar` | `borrado`, nombre exacto y autenticación reciente |
| Aplicar reglas | `reglas.aplicar` | Sin función de plan adicional en este mapeo |

[Seguro] Fuente de todas las filas: `backend-v4/src/AdminApi.php:51`, `:54`, `:886`; contrato: `docs/architecture/openapi-v4.yaml:7481`. Los nombres comerciales de planes no están fijados aquí: se informa la función efectiva, sin inventar un plan contratado.

[Seguro] También existen cambio de nombre y órdenes de configuración de cuentas de Windows, fuera del encargo puntual: `backend-v4/public/assets/device-actions.js:31`; `backend-v4/src/AdminApi.php:54`. No se añadieron flujos de esas tareas.

### Cola, avisos, cancelación y resultados

- [Seguro] Crear devuelve 202 y encola; no significa que se ejecutó. El panel usa vencimiento a 23 horas; el servidor exige fecha futura dentro de 24 horas y motivo no vacío. El equipo debe estar activo, pero no necesita estar conectado para encolar (`backend-v4/public/assets/device-actions.js:167`, `:281`; `backend-v4/src/AdminApi.php:884`).
- [Seguro] El servidor entrega pendientes durante la sincronización y las marca entregadas dentro de la transacción. Omite las vencidas, con excepción especial para solicitudes de cancelación. Por eso una orden desconectada puede esperar y vencer sin llegar (`backend-v4/src/Resources.php:43`, `:52`, `:61`, `:67`; `backend-v4/src/Application.php:197`).
- [Seguro] Aviso solo para reiniciar/apagar: 0, 60, 300 o 600 segundos; cuenta desde la recepción; aviso requiere agente 4.0.17. Bloquear/desbloquear/borrar no aceptan aviso arbitrario ni mensaje de cuenta regresiva (`backend-v4/public/assets/device-actions.js:10`, `:20`, `:235`; `backend-v4/src/AdminApi.php:897`; `docs/architecture/openapi-v4.yaml:22073`).
- [Seguro] Una pendiente se cancela antes de entregarse. Después de entregada, solo un reinicio/apagado con aviso puede detenerse, desde 4.0.27. Primero se pide cancelar; el agente puede confirmar cancelación o informar que ya ejecutó. El servidor no ofrece «deshacer» para una orden inmediata entregada (`backend-v4/src/AdminApi.php:834`; `backend-v4/src/Resources.php:52`; `backend-v4/src/Ingest.php:206`).
- [Seguro] Las traducciones actuales son Pendiente / Entregado / En curso / Ejecutado / Falló / Vencido / Cancelada. La maqueta usa En espera / Entregada / En curso / Hecha / Falló con motivo / Venció / Cancelada y distingue «Cancelación solicitada» (`backend-v4/public/assets/device-actions.js:32`, `:75`).
- [Seguro] **Discrepancia:** OpenAPI y comentarios del panel dicen «mismo permiso y plan» al cancelar (`docs/architecture/openapi-v4.yaml:7604`; `backend-v4/public/assets/device-actions.js:35`). El backend excluye explícitamente la comprobación del plan para cancelación, por decisión registrada en el código (`backend-v4/src/AdminApi.php:36`). La maqueta sigue al backend: perder el plan no impide cancelar si se conserva permiso y alcance. No se corrigieron estos archivos.
- [Seguro] El motivo es obligatorio en la API y el panel actual. [Suposición] Para cumplir el motivo opcional del encargo, la propuesta completa uno descriptivo cuando el usuario lo deja vacío; no cambia el contrato.

### Desbloqueo con PIN y apagado programado

[Seguro] El PIN es configuración de cliente, **no una orden remota con PIN**. Requiere función de órdenes, permiso de desbloqueo con alcance completo, y autenticación reciente para ver/cambiar. Admite 8–32 dígitos. Funciona sin red una vez sincronizado, desde 4.0.17; el equipo sin conexión conserva el PIN anterior. El panel documenta 5 intentos y espera de 15 minutos (`backend-v4/public/assets/unlock-pin.js:8`, `:15`, `:26`; `backend-v4/src/UnlockPin.php:21`, `:25`, `:29`). [Probable] Desde Inicio: Ajustes → Ver PIN → confirmar contraseña → confirmar = al menos 4 clics más apertura del grupo del menú. La maqueta permite consulta ficticia desde la ficha; configuración técnica queda fuera de las reglas y se ubica en Más herramientas.

[Seguro] **La premisa de «solo periódico» no coincide con el código:** existen `once` y `weekly`. Desde la ficha, Programar apagado abre una programación para ese equipo con `once`; la ruta general permite ambas (`backend-v4/public/assets/device-actions.js:205`; `backend-v4/public/assets/power-schedules.js:170`, `:322`). Son directivas separadas de `/devices/{id}/commands`, con resultados propios (`backend-v4/src/PowerSchedules.php:260`; `docs/architecture/openapi-v4.yaml:14660`, `:14947`).

[Seguro] Crear/editar/borrar programación exige `equipos.apagar` y función contratada `comandos`; reiniciar añade su permiso. La lectura exige `equipos.ver`; la ejecución necesita agente 4.0.30. El código de distribución usa función del cliente y no la comprobación de plan individual de las órdenes puntuales (`backend-v4/src/PowerSchedules.php:8`, `:18`, `:268`).

[Suposición] Se aplica la excepción del encargo: [programacion.html](programacion.html) maqueta **una sola fecha**, con equipo, fecha/hora, revisión, guardado y eliminación local. Los valores mostrados son válidos en el contrato: inactividad 5, aviso 5, aplazamiento 15 y ventana 60 minutos (`docs/architecture/openapi-v4.yaml:15908`). Si está inactivo se ejecuta sin aviso; si está en uso se muestra cuenta. El resto del editor y las reglas semanales quedan en paso 3. [Probable] Desde ficha actual, enlace → revisar formulario → Guardar = 2 clics mínimos más completar campos; en esta propuesta se añade revisión explícita.

## Qué existe en la API y qué sería nuevo

Rutas exactas relativas al servidor `/v1`. **No se llama a ninguna desde la maqueta.**

| Dato o capacidad | Contrato existente | Uso y límites |
|---|---|---|
| Permisos, funciones y alcance | `GET /me/access` (`openapi-v4.yaml:2883`) | EXISTE. El nombre del rol no determina por sí solo sus permisos. |
| Identidad de persona, organización | `GET /users/{id}`, `GET /organization` | EXISTE. Se conserva el mapa del paso 1. |
| Equipos de una persona / cliente | `GET /tenants/{id}/devices`, `GET /devices/{id}` (`:7073`, `:7131`) | EXISTE. Versión, señal y `allowed_commands`; no prueba que la persona esté presente. |
| Protección, versión atrasada y motivo | `GET /tenants/{id}/protection` (`:12402`, esquema `:20265`); `GET /devices/{id}/security` (`:7263`) | EXISTE. El resumen ya agrega controles, plan, versión y antigüedad del reporte. No proponerlo como endpoint inexistente. |
| Crear y consultar órdenes | `POST /devices/{id}/commands`, `GET /devices/{id}/commands` (`:7475`) | EXISTE. Envío individual con idempotencia, cola, resultados y paginación. |
| Cancelar | `POST /devices/{id}/commands/{commandId}/cancel` (`:7597`) | EXISTE. Mismo permiso y alcance; divergencia de documentación sobre el plan explicada arriba. |
| Autenticación sensible | `POST /auth/reauth` (`:2312`) | EXISTE. La maqueta la representa con una casilla de simulación; no solicita contraseñas. |
| Ver / configurar / revelar PIN | `GET` y `PUT /admin/tenants/{id}/unlock-pin` (`:851`); `POST /admin/tenants/{id}/unlock-pin/reveal` (`:968`) | EXISTE. No se transporta como parte de una orden. |
| Programaciones | `GET/POST /admin/power-schedules`; `GET/PATCH/DELETE /admin/power-schedules/{id}`; `GET /admin/power-schedules/{id}/runs` (`:14660`, `:14777`, `:14947`) | EXISTE. Una fecha o semanal, ventana, aviso, aplazamiento y resultados separados. |
| Quién envió, canceló y motivo | `GET /audit` (`:4441`) | Auditoría EXISTE. El DTO de orden no incluye autor/motivo ni hora de entrega (`AdminApi.php:819`). NUEVA composición o ampliación de respuesta para mostrarlos junto a cada orden, sin navegar a Auditoría. No se inventa endpoint. |
| Bandeja unificada | Protección + equipos + historial de órdenes | NUEVA composición: unión, filtros, conteos y paginación consistentes entre clientes. No se encontró un contrato único con estos cuatro problemas. Evitar una consulta por equipo al implementar. |
| «Desde cuándo» | Última señal, fecha del reporte, creación/vencimiento/resultado | EXISTEN las observaciones. NUEVO historial de inicio/resolución si se necesita la primera aparición real del fallo. Una fecha de último reporte no prueba cuándo empezó. |
| Orden a varios equipos | POST individual anterior | NUEVO flujo de selección, revisión y consolidación de resultados. No hay endpoint de lote supuesto. Producción debe revalidar cada destino y mostrar envíos parciales/reintentos idempotentes; la maqueta valida todo antes de encolar localmente. |
| Aplicar reglas ahora | Tipo `refresh_policy` existente | NUEVO botón; eficacia en agente POR VALIDAR. El diálogo dice expresamente que no adelanta la sincronización ni garantiza resolver el fallo. |

## Decisiones de la maqueta

- [Seguro] Se copió el cierre del paso 1: mismas 300 personas, alcance de supervisor de 25, 58 personas con alertas y 7 altas; ninguna alerta de protección en Supervisor. Se copiaron logos y fuente. No se copiaron capturas antiguas como evidencia de este paso.
- [Suposición] El supervisor de área del ejemplo es de lectura; ve una razón de una línea y ningún botón de envío/cancelación. El superadmin conserva las restricciones del plan efectivo. Los perfiles reales son configurables mediante permisos.
- [Suposición] Todos los equipos ficticios están registrados y activos. `DESKTOP-0005` ejemplifica plan sin órdenes; `DESKTOP-0009`, sin borrado. El equipo principal de Valeria se enriqueció a versión ficticia 4.0.30 para ejercitar cancelación entregada y programación. Los otros datos personales/actividad no cambian.
- [Seguro] Cierre aprobado: «Sin conexión prolongada» = 2 días hábiles sin señal, excluyendo fines de semana, festivos y ausencias aprobadas. En el ejemplo, última señal martes 27 a las 08:00, con martes y miércoles laborables completos al corte del jueves. El cómputo por fechas es una simplificación de maqueta; producción debe usar jornadas reales y horas de señal. Referencia ilustrativa de versión para la bandeja: 4.0.20. El resumen real usa mínimo 4.0.19 y vigencia de reporte de 7 días (`backend-v4/src/ProtectionSummary.php:25`, `:27`); no confundir esos umbrales con una versión publicada objetivo. Definirlos antes de implementar.
- [Suposición] Los eventos ficticios «desde cuándo» son fechas conocidas del escenario, no una inferencia de datos reales. Protección se mantiene hasta observar un reporte que confirme resolución; enviar una orden o verla hecha no elimina automáticamente el problema.
- [Suposición] Selección por problema agrupado, sin duplicar equipos aunque aparezcan en varios grupos y limpiada al cambiar búsqueda/empresa/tipo para evitar destinatarios ocultos. Revisión enumera equipos, personas, cantidad en horario y desconectados. Un equipo no autorizado bloquea el envío completo en la demostración; borrado solo individual.
- [Seguro] La pantalla conserva información de órdenes terminales y mensajes específicos cuando no se puede cancelar. Los controles «Solo maqueta · Simular respuesta» permiten entrega, éxito, fallo, vencimiento y ambas carreras de cancelación, sin temporizadores ni red. Avanzar al vencimiento afecta todas las pendientes del reloj simulado; no supone que una orden entregada vencida nunca se ejecutó.
- [Seguro] `backend-v4/public/assets/styles.css` citado por el encargo no existe. Se conserva el estilo claro del paso 1, derivado del real `assets/k3.css:3`, `:4`: fuente Inter local, índigo, tarjetas blancas y bordes. No se cambió branding por una interpretación de la paleta global.

## Verificación reproducible

Desde esta carpeta: `node verificar.cjs`. Revisa enlaces, scripts, vocabulario, datos del paso 1, cuatro problemas, planes/roles, los seis estados obligatorios y transiciones: confirmación de borrado, autenticación, aviso, lote inválido sin envío parcial, cancelación antes/después de entrega, versión vieja, cancelación tardía, pérdida de plan y vencimiento.

Recorrido de aceptación para Claude, a **1366×768 y 375 px**, con teclado y sin scroll horizontal:

1. Inicio → Valeria → Sus equipos: botones de orden; protección como información, sin alerta del supervisor. Abrir equipo, conservar rol y volver.
2. Reiniciar → 5 minutos → confirmar jornada → enviar. Ver En espera, simular entrega y Hecha; repetir con fallo y leer motivo.
3. Equipo sin conexión → Bloquear → enviar. Ver motivo de espera y vencimiento; cancelar pendiente y comprobar que no permite entregar. En otra orden, simular vencimiento.
4. Equipo principal → Reiniciar con aviso → entregar → cancelar. Ver «Cancelación solicitada», luego simular confirmación; repetir simulando ejecución antes de cancelar. Con el equipo 4.0.18 verificar razón por versión; sin aviso, razón de ejecución al recibir.
5. Borrado: nombre incorrecto, nombre correcto sin autenticación, ambas confirmaciones, envío; cancelar exige autenticación simulada. Cerrar con Escape y verificar que no envía.
6. Administrar → bandeja: los cuatro tipos, desde cuándo y acción sugerida. Abrir un problema agrupado, seleccionar todos o algunos equipos, cambiar filtros, revisar resumen y destinatarios antes de mandar. Seguir cada resultado sin salir.
7. Equipo 0005: razones por plan; 0009: borrado no incluido. Un lote con un destino no permitido no se envía parcialmente. El supervisor de lectura no puede mandar órdenes; ninguno de los supervisores puede acceder a Administrar; un equipo fuera del área tampoco se muestra.
8. Escenario vacío/error en bandeja y ficha; recuperar datos con el enlace. Filtro sin resultados ofrece mensaje explícito.
9. Programar apagado: ejemplo 0001 para guardar una fecha futura, revisar parámetros y eliminar. Equipo antiguo da razón de incompatibilidad. Las reglas semanales muestran «Se diseña en el paso 3».
10. Asistente: tarjeta en Administrar, vista previa legible, JSON inicialmente plegado; supervisor sin formulario. Confirmar herencia del paso 1.

No se ejecuta navegador por regla expresa del encargo. Los verificadores no acreditan geometría, foco real ni capturas; esa validación corresponde a Claude.

## Dudas para Koichi antes de implementar

1. [Suposición] ¿Mantener «Aplicar reglas ahora» como reintento explícito con soporte verificable del agente, o sustituirlo por consultar reporte/reglas? El botón actual es una propuesta, no una reparación garantizada.
2. [Seguro] Resuelto por Koichi: supervisor con permiso revocable para bloquear/desbloquear/reiniciar/apagar dentro de su alcance; nunca borrado. El supervisor de lectura se mantiene como otro ejemplo.
3. [Seguro] Umbral resuelto: 2 días hábiles. Pendiente definir versión objetivo y reporte que confirma resolución.
4. [Suposición] ¿Implementar agregación para autor/motivo e inicio del problema en la misma respuesta de seguimiento? Hoy hay que componer con Auditoría y observaciones.
5. [Seguro] El código permite cancelar sin función en el plan. Queda pendiente corregir la documentación de la API y comentarios del panel en un trabajo posterior; no se modifican aquí.

## Cierre de vuelta 2

- Problemas agrupados por tipo, mensaje y ventana de 15 minutos; casos aislados conservan una entrada de un equipo. Abrir «Ver equipos», seleccionar todos o algunos y revisar orden. La selección es única por equipo y se limpia al filtrar. La misma persona puede tener varios equipos.
- «Supervisor con órdenes» tiene las mismas 25 personas que el supervisor de lectura. Permite cuatro órdenes y su cancelación, respetando plan, alcance y revocación. No ofrece borrado, PIN, aplicar reglas ni administración.
- Administrar → Órdenes de supervisores activa/quita el permiso. El enlace al esbozo es `administrar.html#permisos`. El estado viaja en `ordersPermission` en las URL, sin almacenamiento ni permisos reales. Quitar permiso y elegir «Supervisor con órdenes» demuestra la revocación.
- Diálogo en dos columnas, destinatarios de lotes plegados, textos compactos y acciones al pie; una columna en móvil. El objetivo es tener Enviar visible a 1366×768. Sin navegador no se certifica la geometría.
- NUEVO para producción: agregación temporal de incidentes, cómputo de jornadas efectivamente laborables con festivos/ausencias y flujo de permiso específico sin borrado. No se modifica la API ni se supone que un permiso genérico actual ya ofrezca esa separación.

Aceptación adicional: abrir grupo de protección, enviar a todos y luego a un subconjunto; verificar que dos grupos del mismo equipo no duplican el envío; revocar permiso, cambiar de rol y probar acceso directo fuera de alcance; comprobar aviso de 2 días hábiles y diálogos de reinicio/borrado/lote en ambos tamaños.
