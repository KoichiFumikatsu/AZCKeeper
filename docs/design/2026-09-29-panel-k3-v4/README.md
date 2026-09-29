# AZCKeeper v4 · navegación K3

Prototipo estático local de la arquitectura de información del panel. Interfaz en español, datos ficticios y una única superficie de administración. No conecta con el backend ni ejecuta acciones sobre equipos.

## Abrir

Doble clic en [index.html](index.html). Todo funciona desde `file://`, sin servidor, instalación, CDN, fuentes remotas ni dependencias de ejecución. Mantener juntos los HTML, `styles.css`, `app.js`, `data.js`, `logo-main.png`, `logo-mark.png` y `favicon.ico`.

Los 39 HTML incluyen contenido inicial y navegación. Los scripts clásicos con `defer` habilitan filtros, paginación, fichas, formularios y diálogos. No se usan módulos ES, `fetch` ni importaciones que dependan de un origen HTTP.

## Mapa de navegación

```text
AZCKeeper · Grupo AZC
├─ Principal (orden de K3)
│  ├─ Inicio
│  ├─ Sedes [Próximamente]
│  ├─ Personas / Usuarios
│  │  └─ Ficha de persona (desde la tabla)
│  ├─ Accesos pendientes [contador]
│  ├─ Equipos
│  │  └─ Ficha de equipo (desde la tabla)
│  └─ Productividad / Reportes
├─ Seguimiento
│  ├─ Presencia y puntualidad
│  ├─ Rankings y top de apps [Próximamente]
│  ├─ Capturas de pantalla [Próximamente]
│  ├─ Puertas y check-in [Próximamente]
│  ├─ Turnos [Próximamente]
│  └─ Festivos
├─ Gestión avanzada (orden relativo de K3)
│  ├─ Reglas / Políticas
│  ├─ Releases
│  ├─ Cobertura de instalación
│  ├─ Usuarios admin
│  ├─ Asignaciones [Próximamente]
│  ├─ Organización [Próximamente]
│  ├─ Roles y permisos
│  ├─ Ajustes del panel
│  ├─ Salud del servidor
│  ├─ Doble empleo
│  ├─ Logs del cliente
│  ├─ Apps sospechosas
│  ├─ Endurecimiento · Modo B
│  └─ Bloqueo con PIN [Próximamente]
├─ Plataforma
│  ├─ Empresas / Tenants
│  ├─ Tiers
│  └─ Auditoría
├─ Próximamente (plegado inicialmente)
│  ├─ Suscripciones
│  ├─ Mensajes y notificaciones
│  ├─ Soporte
│  └─ Integraciones (claves API y webhooks)
└─ Portal cliente (futuro) (plegado inicialmente)
   ├─ Tareas
   ├─ Casos
   ├─ Recepción
   └─ Contratar
```

Cada grupo utiliza la misma barra lateral. No existe un selector «portal / admin» ni un segundo portal. El grupo del módulo actual permanece abierto. Las fichas conservan activo el elemento padre y muestran la ruta de regreso. En móvil, un botón abre la misma navegación como panel lateral.

## Qué se tomó de K3

Se revisaron `AZCKeeper_Client/Web/public/admin/partials/layout_header.php` y las páginas del directorio `admin/`: tablero, sedes, personas y ficha, equipos, pendientes, productividad, reglas, releases, cobertura, administradores, asignaciones, organización, roles, ajustes, salud, doble empleo y logs.

- Una barra lateral fija, barra superior y grupos según permisos.
- Orden del grupo principal: Dashboard → Sedes → Usuarios → Pendientes → Dispositivos → Productividad. Los rótulos se adaptaron a Inicio y Equipos para mantener el vocabulario de v4.
- Orden relativo de las once entradas originales de Gestión avanzada.
- Contador de solicitudes, indicadores superiores, tablas con filtros y fichas de persona/equipo.
- Ocultar grupos sin módulos visibles. No se copiaron Tailwind, Alpine, Google Fonts ni PHP.

## Qué se tomó de v4

Se revisaron `backend-v4/public/assets/styles.css`, los cuatro parciales de `backend-v4/public/partials/`, las páginas y el catálogo de recursos de `backend-v4/public/assets/app.js`, y las rutas de `docs/architecture/openapi-v4.yaml`.

Se conservaron los tokens de producción: superficie `#E4E4E4`, hover `#D9D9D9`, texto `#303030`, secundario `#505050`, blanco `#F8F8F8`, borde `#A6A6A6`, control `#686868`, navy `#003A5D`, rojo de marca `#BE1622`, error `#93121D`, Segoe UI / Arial, relieve exterior e interior. El relieve separa bloques de trabajo; las filas permanecen planas. Siempre hay fondo claro.

Los tres recursos de marca se copiaron sin modificación desde `backend-v4/public/assets/brand/`. El isotipo aparece en la navegación y el logotipo completo en ajustes y en la vista previa de bloqueo con PIN.

También se consultaron la propuesta aprobada `docs/design/2026-09-15-propuestas/panel-v4/`, su README, VALIDACION y las reglas de los dos requisitos de RBAC. La propuesta histórica utiliza algunos acentos distintos: para esta entrega prevalece la paleta navy/rojo del CSS actual de producción.

## Inventario A · Consolidado según el encargo

«Consolidado» identifica el grupo A del encargo; estas páginas siguen siendo simulaciones, no una certificación de la implementación real.

| Sección | Página | Contenido |
|---|---|---|
| Inicio | [index.html](index.html) | Equipos conectados/desconectados, actividad, alertas y pendientes |
| Equipos | [equipos.html](equipos.html) | 300 equipos, búsqueda, estado, sede, versión y paginación |
| Ficha de equipo | [equipo.html](equipo.html) | Inventario, política, persona, 12 controles, logs y acciones |
| Personas / Usuarios | [usuarios.html](usuarios.html) | 288 personas inventadas, sede, área y tier |
| Ficha de persona | [miembro.html](miembro.html) | Datos, tier, vigencia, equipos, actividad y presencia |
| Accesos pendientes | [pendientes.html](pendientes.html) | 8 solicitudes; revisión, aprobación y rechazo |
| Reglas / Políticas | [reglas.html](reglas.html) | Web, USB, descargas, AppLocker, horarios y excepciones |
| Productividad / Reportes | [reportes.html](reportes.html) | Tendencia semanal, actividad, foco, tabla y CSV |
| Presencia y puntualidad | [presencia.html](presencia.html) | Entradas, horario, puntualidad y falta de registro |
| Festivos | [festivos.html](festivos.html) | Excepciones ficticias por empresa/sede |
| Doble empleo | [doble-empleo.html](doble-empleo.html) | Señales y revisión humana documentada |
| Apps sospechosas | [apps-sospechosas.html](apps-sospechosas.html) | Aplicaciones, alcance y clasificación |
| Cobertura | [cobertura.html](cobertura.html) | 300 agentes para 312 puestos previstos |
| Logs del cliente | [logs.html](logs.html) | Filtros por nivel, módulo y búsqueda |
| Salud del servidor | [salud.html](salud.html) | CPU, memoria, disco, servicios y procesamiento |
| Ajustes del panel | [ajustes.html](ajustes.html) | Marca por empresa, vista previa y contraste |
| Empresas / Tenants | [empresas.html](empresas.html) | Empresa de muestra y autorización de autogestión |
| Roles | [roles.html](roles.html) | Dos requisitos, alcance y permisos explícitos |
| Tiers | [tiers.html](tiers.html) | Catálogo y capacidades de los planes |
| Releases | [releases.html](releases.html) | Piloto, Temprano y General; preparación de despliegue |
| Auditoría | [auditoria.html](auditoria.html) | Actor, acción, recurso, resultado y acciones simuladas |
| Modo B (parte de Ficha de equipo) | [endurecimiento.html](endurecimiento.html) | Cuenta de soporte, custodia, restauración y reversión |

Página adicional de continuidad K3: [Usuarios admin](administradores.html), con roles y revocación escrita. Se separa la identidad administrativa de la ficha laboral.

La ficha de equipo incluye WebEnforcer, UsbEnforcer, InstallEnforcer, DownloadEnforcer, DeviceLock, CommandExecutor, Inventory, UpdateManager, LocalAccountHardening, RestorePoint, TamperGuard y SessionSupervisor. La muestra muestra Aplicado, Fallido, No soportado y Desconocido, sin interpretarlos como equivalentes.

## Inventario B · Próximamente

Todas estas secciones tienen una página navegable, etiqueta visible y una vista previa con datos. Los formularios generan borradores locales.

| Sección | Página | Vista previa |
|---|---|---|
| Sedes | [sedes.html](sedes.html) | Indicadores por sede y enlace a sus equipos |
| Rankings / Top de apps | [rankings.html](rankings.html) | Comparación por área, aplicaciones y personas |
| Asignaciones | [asignaciones.html](asignaciones.html) | Persona, equipo, sede, área y cargo |
| Organización | [organizacion.html](organizacion.html) | Sedes, áreas y cargos filtrables por tipo |
| Capturas | [capturas.html](capturas.html) | Galería de documentos dibujados en HTML/CSS |
| Puertas / check-in | [puertas.html](puertas.html) | Punto de acceso, sede, ubicación y registro de entrada |
| Bloqueo con PIN | [pin.html](pin.html) | Código temporal, vigencia y pantalla prevista del agente |
| Turnos | [turnos.html](turnos.html) | Jornada, pausas y semana de ejemplo |
| Suscripciones | [suscripciones.html](suscripciones.html) | Cupos, asignación y renovación por tier |
| Mensajes / Notificaciones | [mensajes.html](mensajes.html) | Audiencia, canal, contenido y programación |
| Soporte | [soporte.html](soporte.html) | Solicitudes por equipo y prioridad |
| Integraciones | [integraciones.html](integraciones.html) | Clave API de muestra, revocación y entregas de webhook |
| Tareas | [tareas.html](tareas.html) | Responsables, prioridad y entrega |
| Casos | [casos.html](casos.html) | Servicio, hitos y revisión |
| Recepción | [recepcion.html](recepcion.html) | Atención, canal y derivación |
| Contratar | [contratar.html](contratar.html) | Solicitudes de perfiles y vacantes |

## Decisiones

- Se conservan las cuatro páginas de personal del portal futuro para cubrir el inventario completo. Están en un grupo separado, plegado inicialmente, dentro de la única navegación. Así pueden revisarse sin competir con las tareas diarias.
- Seguimiento reúne presencia, rankings y evidencias; Plataforma reúne operaciones centrales. Son agrupaciones de la misma superficie, sin saltos de contexto.
- Cada pantalla tiene una acción principal. Exportar es la acción principal de vistas de consulta; crear/preparar, la de gestión. Los comandos remotos se presentan como acciones secundarias en la ficha.
- Los filtros tienen etiquetas visibles, búsqueda sin distinguir acentos, paginación de 12 filas y un estado «No hay coincidencias» con recuperación.
- Los diálogos de borrado, revocación, rechazo y comandos requieren un identificador exacto y un motivo. El botón permanece deshabilitado hasta completar ambos. Una orden aparece «En cola», sin afirmar que un agente la ejecutó.
- En la muestra de Modo B falla RestorePoint. El flujo bloquea la aplicación y explica la corrección necesaria; la reversión también exige confirmación escrita.
- Las futuras capturas se representan con HTML/CSS. No se incluyen fotografías, datos personales reales ni capturas de usuarios. El PIN no se genera, los mensajes no se envían y los webhooks no hacen conexiones.
- Septiembre de 2026 y America/Bogota son el contexto fijo. Los correos usan dominios `.example`. Las dos fechas de Festivos son excepciones ficticias de empresa, no festivos nacionales inventados.
- El mapa de brechas del 17 de septiembre está desactualizado respecto del inventario y de algunas rutas actuales: identifica como pendientes capacidades con contratos existentes. Para A/B prevalece el encargo. Organización, asignaciones e integraciones permanecen «Próximamente» aunque haya contratos base. Tiers consolidados y Suscripciones futuras tienen páginas separadas.
- Todo el conjunto de muestras compartidas está en `data.js`. `app.js` contiene vistas e interacciones; `styles.css` es la única hoja. No se copiaron librerías.

## Permisos y estados de revisión

La barra superior contiene «Revisar prototipo», que permite elegir perfil y estado de pantalla. No representa un formulario de autenticación.

| Perfil de muestra | Visibilidad y alcance | Edición |
|---|---|---|
| Administrador | Todas las páginas de Grupo AZC | Gestión local de muestra |
| Soporte IT | Equipos, personas, enrolamiento, políticas, releases, cobertura, diagnóstico, Modo B, PIN y soporte | Acciones operativas de muestra |
| Coordinación · Cali | Seguimiento, personas y equipos de Cali; módulos asignados | Consulta |
| Solo lectura | Inicio, sedes, personas, equipos, reportes, presencia, rankings y festivos | Consulta |

El menú oculta módulos sin permiso; la URL directa también muestra un estado de acceso denegado. Coordinación solo ve registros de Cali y no puede abrir fichas de otras sedes. Los enlaces conservan el perfil mediante `?role=`. Esta es una simulación de presentación, no una barrera de seguridad: todos los datos son ficticios y están disponibles en el archivo local.

En Roles, la simulación exige **autogestión habilitada en la empresa Y permiso `roles.gestionar`**. Desactivar cualquiera bloquea la creación. Los interruptores son controles de revisión, no una propuesta para permitir autoautorización al administrador. Un rol nuevo parte sin permisos y no puede delegar el metapermiso central.

Ejemplos de revisión:

- [Equipos sin conexión](equipos.html?status=Sin%20conexi%C3%B3n).
- [Ficha del segundo equipo](equipo.html?id=d2) y [segunda persona](miembro.html?id=p2).
- [Equipos de Coordinación](equipos.html?role=coordinacion).
- [Acceso denegado a Roles](roles.html?role=lectura).
- [Estado vacío de Personas](usuarios.html?state=empty) y [error en Logs](logs.html?state=error).

Los cambios intentan conservarse en `localStorage` bajo una clave exclusiva del prototipo. La disponibilidad y el ámbito de ese almacenamiento en `file://` dependen del navegador. Si no está disponible, las acciones funcionan en memoria y el aviso indica que no persistirán al cambiar de página. El prototipo nunca necesita almacenamiento para abrirse. «Restablecer muestra» recupera los datos iniciales.

## Verificación

Sin navegador. La entrega incluye [VALIDACION.md](VALIDACION.md) y [VALIDACION-DOM.md](VALIDACION-DOM.md), generados por los verificadores.

```powershell
node docs/design/2026-09-29-panel-k3-v4/build.cjs
node docs/design/2026-09-29-panel-k3-v4/validate.cjs
node docs/design/2026-09-29-panel-k3-v4/validate-dom.cjs
```

`build.cjs` regenera únicamente los 39 HTML de esta carpeta desde `data.js` y `app.js`. No hace falta ejecutarlo para abrirlos. `validate.cjs` no tiene dependencias y comprueba inventario, enlaces, fragmentos, sintaxis, filtros, controles y SHA-256 de las tres copias de marca contra v4.

`validate-dom.cjs` usa la instalación local de JSDOM ya existente en `C:/Users/FumiWork/proyectos/portal-azc/node_modules/jsdom`; se puede señalar otra instalación mediante `JSDOM_MODULE`. Es una herramienta opcional de verificación, no una dependencia de los HTML. No descarga ni instala nada. Las pruebas de diálogos y descargas usan adaptadores DOM.

La revisión visual queda pendiente para el siguiente agente: recorrer las 39 páginas a 1440, 1024, 768, 390 y 320 px; revisar el menú y los diálogos con teclado, zoom 200/400 %, contraste de estados, tablas con scroll, foco y descargas nativas. Las pruebas DOM no demuestran estos aspectos.

No se ejecutó un navegador. No se modificó código fuera de esta carpeta. No se hizo commit.
