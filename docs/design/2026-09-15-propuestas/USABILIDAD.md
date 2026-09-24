# Usabilidad — Panel actual y propuestas v4

## Selección vigente y cinco direcciones de arte

**Finalistas: neomorfismo y minimal-lineas.** Descartadas: brutalista, terminal, editorial, industrial, flat-corporativo, material-claro, glassmorphism-claro y soft-cards. Las observaciones de rondas anteriores se conservan como historial, no como recomendaciones vigentes.

Se agregan editorial-premium, bauhaus-geometrico, blueprint-tecnico, humanista-calido y swiss-datos, pendientes de evaluación del cliente. Cada una tiene las mismas ocho páginas, datos y flujos, con una composición HTML del dashboard propia. Las cinco son claras y sin sombras. El cambio de dirección de arte no modifica las reglas de confirmación, alcance o conexión.

| Dirección nueva | Decisión visual y aplicación a los problemas del panel |
|---|---|
| Editorial premium | Pliego asimétrico: la cifra de disponibilidad es una cita; las incidencias tienen columna propia. La marginalia explica el significado de conexión y el alcance de la página. Tablas con ritmo editorial y formularios con anotaciones de impacto. |
| Bauhaus geométrico | Círculo, cuadrado y triángulo clasifican normal, crítico y alto. Etiquetas textuales permanecen junto a las formas. Número dominante para inventario y tres registros de distinto peso separan disponibilidad, prioridad y versión. |
| Blueprint técnico | Cotas, llamadas numeradas y cajetín explicitan empresa, revisión y corte. Specs y políticas se leen como documentación técnica. Anotaciones sobre papel opaco evitan que la cuadrícula interfiera con el texto. |
| Humanista cálido | Papel hueso y sans humanista, nota lateral para los equipos críticos y trazo de tinta para orientar el siguiente paso. Campos de 8 px con borde visible; texto de mayor cuerpo y ausencia de relieve. |
| Swiss datos | Retícula visible y cifras jerarquizadas; tabla, ficha y editor comparten ejes de alineación. El registro numérico distingue total, conexión, prioridad y versión por posición, etiqueta y tamaño. |

Se conservan las soluciones sustentadas en archivo:línea de la tabla original: selección de empresa, búsqueda explícita, fecha/unidad visible, comandos con destinatario y motivo, separación entre «En cola» y ejecución, precedencia de políticas y previsualización de marca con validación de contraste. En móvil, las tablas tienen desplazamiento local con nombre accesible; no se suprimen versión, conexión ni acciones.

**Validación de esta ampliación:** 696 comprobaciones aprobadas, cero fallos, sobre 120 HTML. Los pares de texto de las nuevas direcciones tienen mínimo 5.45:1, incluidos hover y texto de acento. Foco visible de 3 px. Estado vacío, carga, error y borrado se recorren desde states.html. Referencias locales verificadas, sin descargas ni llamadas externas.

**Límite pendiente:** Chromium falla al iniciar con `spawn EPERM`. No se ha realizado inspección visual renderizada a 1440/390 px ni validación de foco nativo. No se presenta JSDOM como prueba visual o certificación WCAG integral. Véanse [VALIDACION.md](VALIDACION.md) y [VALIDACION-NAVEGADOR.md](VALIDACION-NAVEGADOR.md).

## Historial — primera familia corporativa clara

La referencia aceptada es **neomorfismo**. **Brutalista, terminal, editorial e industrial están descartadas**; las observaciones de sus columnas se conservan como historial de evaluación, no como recomendaciones actuales.

Se añaden cinco variantes claras y corporativas. Todas conservan las ocho páginas, los mismos fixtures y las soluciones funcionales a los problemas de código citados en este documento. La comparación desde los prototipos aprobados/nuevos evita las variantes descartadas.

| Propuesta nueva | Diferencia aplicada a la operación administrativa |
|---|---|
| Flat corporativo | Sidebar blanca, tarjetas de borde y poca sombra: agrupación explícita sin exceso de relieve. |
| Material claro | Rail con etiquetas, campos rellenos y niveles de elevación: la acción principal se descubre por posición y forma. FAB de inventario en escritorio, dentro del flujo en móvil. |
| Glassmorphism claro | Transparencia solo en superficies; tablas, campos y diálogos casi opacos u opacos. Fondo mínimo #E5E5E5 y fallback blanco sin blur. |
| Minimal líneas | Sidebar textual, tipografía sans y separadores de 1 px. Conserva unidades, fechas y acciones legibles sin tarjetas decorativas. No reproduce la serif editorial descartada. |
| Soft cards | Tarjetas blancas sobre perla, radios amplios y una sombra muy tenue. Evita la doble sombra fuerte; los límites de campos y el texto mantienen contraste. |

En las cinco se separan conexión, prioridad y confirmación del agente; se preservan versión/fecha mediante scroll local de tabla; el diálogo nombra empresa y destinatario; el editor de política muestra impacto antes de publicar. Vacío, carga y fallo tienen texto y salida explícita. Se mantienen las referencias archivo:línea de las observaciones originales.

**Legibilidad:** fondo y superficies claros, texto ≥4.5:1 en los pares comprobados, foco de 3 px y estados con palabra/símbolo. El editor de marca valida el acento sobre fondo y hover. En Glass, los degradados son solo neutros y su extremo más oscuro también supera el umbral; hay fallback sólido y soporte de transparencia reducida.

La validación DOM/estática abarca las diez propuestas, incluidas las archivadas, y comprueba tema claro en cada HTML nuevo. La inspección visual real en 1440/390 px sigue pendiente por el fallo `spawn EPERM` al iniciar Chromium. No se presenta esta revisión de código/DOM como auditoría visual o conformidad WCAG integral.

Fecha: 15 de septiembre de 2026. Alcance: diseño estático. No se modificó código de producción.

## Base de evidencia

Se leyó [la auditoría K3, especialmente §2.3 y §7](../../audits/2026-09-15-auditoria-k3.md) y se contrastaron los flujos de `index.php`, `devices.php`, `users.php`, `policies.php` y `user-dashboard.php`, además del encabezado y CSS.

**Diferencia de revisión:** la auditoría principal cita `931fd12`; el checkout inspeccionado es `1638bd493d884a0741e4131ee2d8089b5b2d9b76`, rama `feature/moazc-bridge`. Las referencias siguientes son del checkout actual, no una afirmación sobre lo desplegado. La auditoría incluye un suplemento de esa revisión.

Prefijo de todos los archivos en la tabla: `AZCKeeper_Client/Web/public/admin/`. Las líneas se verificaron leyendo el archivo local. Son observaciones del código, no resultados de sesiones de usabilidad con usuarios.

## Problemas concretos y respuesta de diseño

| Problema y evidencia actual | Efecto sobre la operación | Brutalismo | Neomorfismo |
|---|---|---|---|
| Tema efectivo inline y duplicado: `partials/layout_header.php:18` carga Tailwind CDN, `:33` fija navy, `:38` rojo, `:49` Google Fonts, `:51` CSS inline. `assets/style.css:9` contiene un tema aparte; no se encontró referencia a ese archivo en los PHP del panel. | Cambiar el CSS nombrado en el contexto no basta para rebrand. Una carga externa fallida puede afectar apariencia. | Un CSS de propuesta, fuentes de sistema y recursos locales; el login comparte tema. | Misma centralización con tokens adicionales de relieve; sin CDN. |
| Texto secundario `#9d9d9c` en `partials/layout_header.php:43`, usado, por ejemplo, en `index.php:358` y `policies.php:583`. | El texto pequeño tiene contraste insuficiente sobre blanco. La jerarquía depende de aclarar texto hasta hacerlo difícil de leer. | Secundario #595959 sobre #F8F8F8: 6.60:1. Jerarquía por tamaño y bordes. | Secundario #595959 sobre #E4E4E4: 5.51:1. Relieve reservado a superficies, no al contraste de texto. |
| `index.php:55` cuenta IDs de **equipos** con contacto menor a 2 min; `index.php:358` etiqueta ese número como **Personal Activo**. | Personas con más de un equipo distorsionan la lectura de personal. | KPI «En línea» con unidad equipos y criterio temporal escrito. | Mismo KPI, más anillo porcentual con denominador de equipos. |
| `devices.php:116` usa 30 días para obsolescencia y `:123` devuelve `active`; `user-dashboard.php:122` distingue Online menor a 120 s y `:123` Ausente menor a 900 s; `index.php:54` cuenta online con 15 min. | «Activo» puede referirse a vigencia de registro, contacto reciente o persona; las pantallas sugieren conceptos diferentes. | Conexión explícita y última fecha; criterio único de demo menor a 2 min. Prioridad separada. | Mismos conceptos con leyenda al lado del anillo. El texto declara que conexión no equivale a actividad. |
| `devices.php:255` oculta versión debajo de lg; `:257` oculta último contacto debajo de sm. | En móvil faltan dos datos necesarios para evaluar una incidencia o una actualización. | Tabla completa con desplazamiento horizontal dentro de la región y detalle accesible por enlace. | Igual preservación de columnas y región desplazable; se apilan los filtros. |
| `devices.php:316` ofrece acciones mediante un botón de puntos sin etiqueta textual; `users.php:607` usa un control de edición pequeño basado en icono/title. | Menor descubribilidad y objetivos táctiles pequeños. | Acciones «Bloquear / Apagar / Borrar» visibles, nombre accesible con el equipo. Edición de acceso con texto. | Botones con relieve y texto; no se requiere reconocer un pictograma. Controles principales de 44 px o más; acciones compactas de 40 px. |
| `devices.php:358` confirma con `confirm(...)` genérico; `devices.php:52` ejecuta DELETE del registro. | El diálogo no nombra al equipo ni empresa. «Eliminar» puede confundirse con borrar físicamente sus datos. | Diálogo con nombre, empresa, propietario, efecto y motivo. Para borrado remoto, escribir hostname. | Mismo contenido en un diálogo de superficie elevada y foco visible. No depende del tono del botón. |
| `policies.php:123`–`:127` incrementa versión y muestra «Force Push enviado»; `:325` solo pregunta por incrementar versión global. | «Enviado» puede interpretarse como entrega/aplicación en la flota; no expresa cuántos equipos afecta. | Resumen de alcance y conteo antes de publicar; publicado, pendiente y confirmado separados. | Panel de impacto fijo en escritorio y resumen dentro del diálogo; misma semántica de revisión. |
| `policies.php:583`–`:585` explica global/override/batch, mientras `:857` abre el editor visual con API Base URL y `:864` con Timers. | La tarea habitual de proteger navegación compite con detalles de infraestructura; se debe conocer «deep merge» para anticipar resultado. | Orden por tarea: identidad/alcance → navegación → aplicaciones → jornada. Precedencia y reemplazo de listas escritos en lenguaje directo. | Secciones elevadas y controles hundidos; resumen persistente del destino y conteo. No expone URL de API ni timers al configurar bloqueo. |
| `user-dashboard.php:116` limita a un dispositivo activo por usuario, el más reciente; `:963` muestra «Dispositivo». | El resumen no presenta todos los equipos de una persona ni permite decidir cuál administrar. | Directorio con cantidad enlazada; inventario filtrado por persona y empresa. Detalle propio por equipo. | Mismo flujo desde una tabla de usuarios con acciones explícitas. |
| `users.php:389` calcula Focus en escala 0–10; `:598` lo presenta como «Focus». `user-dashboard.php:464` usa umbrales 75/50/30 para otra clasificación del score. | El mismo nombre puede sugerir que son valores comparables. | El dashboard de flota prioriza equipos y no reproduce esas métricas con definiciones distintas. | Igual decisión. Si productividad vuelve al alcance, necesita nombre, fórmula, escala y periodo explícitos. |
| `user-dashboard.php:329`–`:335` carga todos los episodios del periodo; `:1179` activa carga y `:1187`–`:1188` solo registra el error en consola. | La carga puede ser larga; al fallar se conservan filas previas sin aviso visible del fallo/frescura. | Estado de carga separado, error con fecha del último corte y reintento; no quedan comandos disponibles en una consulta fallida. | Mismos estados con placeholder de superficie hundida; no usa animación como único mensaje. La paginación del prototipo es local, no corrige la consulta K3. |
| `partials/layout_header.php:75`–`:78` muestra identidad fija «Keeper / Panel Admin»; `index.php:46` obtiene scope de sesión, sin un selector de empresa operativo en la cabecera de esa página. | Al operar varias empresas falta un contexto explícito junto a acciones y destinos. | Selector permanente, empresa en cada fila y en los diálogos; vista agregada diferenciada del destino de política. | Selector en topbar y empresa en sidebar; configuración de marca/keys exige empresa concreta. |

## Lo que el diseño propone y lo que no existe en K3

1. **Borrado remoto de datos, comandos por equipo, inventario de hardware y acuse de aplicación** se ilustran como flujos v4. `devices.php` actual ofrece revocar/activar/baja/eliminar registro. No se deduce soporte real de borrado remoto por tener un botón.
2. **Descargas e instalación, scope empresa/equipo, marca por tenant y API keys con scopes** son diseño solicitado para v4. La tabla de inventario de la auditoría distingue lo existente; no se inventaron endpoints, tablas ni migraciones.
3. **Multiempresa visual no implementa autorización.** Los problemas de scope de `devices.php:19` y `users.php:54`, documentados también por la auditoría, necesitan corrección en servidor. Filtrar filas o cambiar un selector no los resuelve.
4. **Confirmación de agente** es una semántica de producto propuesta. Publicar una política o mostrar un comando en cola no prueba enforcement. Los prototipos no hacen solicitudes de red.
5. **Rendimiento:** la paginación local demuestra el comportamiento deseado. La implementación futura requiere paginación en servidor; este trabajo no modifica SQL ni mediciones.

## Flujos para comparar

| Flujo | Recorrido en ambas propuestas |
|---|---|
| Detectar una incidencia | Dashboard → alerta crítica → detalle del equipo → revisión/cronología → comando confirmado |
| Operar una flota | Seleccionar empresa → Equipos → filtrar versión/conexión → acción con destinatario explícito |
| Cambiar protección | Políticas → elegir alcance/destino → dominios y jornada → revisar impacto → publicar → pendiente de agente |
| Administrar a una persona | Usuarios → buscar → revisar equipos asignados → editar acceso dentro de la empresa |
| Personalizar empresa | Configuración → seleccionar empresa → nombre/logo/acento → validar contraste → vista previa |
| Integrar un sistema | Configuración → crear clave ficticia con alcance y vencimiento → revocar con confirmación |

Los borradores y ediciones solo viven mientras la página está abierta; la selección de tenant viaja en enlaces relativos. Los números de flota se calculan de los mismos 180 registros ficticios en ambos estilos.

## Accesibilidad y verificación pendiente

### Ampliación: terminal, editorial e industrial

Las tres propuestas nuevas conservan las mismas ocho páginas y corrigen en diseño los mismos problemas citados arriba; no se amplía el alcance al backend. Comparten datos, filtros, confirmaciones, alcance de políticas y editor de marca, con estas diferencias de presentación:

| Problema ya documentado | Terminal | Editorial | Industrial |
|---|---|---|---|
| Contexto de empresa poco explícito | Ruta `~/empresas/.../módulo`, prompt y barra de estado | Cabecera de informe y selector al inicio de la lectura | Rail de operador, placa de módulo y alcance visible |
| Unidades y conexión ambiguas | Lecturas monoespaciadas y umbral escrito | Banda de KPIs con unidad y leyenda del indicador | Instrumento de contacto con corte, graduación y denominador |
| Información de tabla oculta en móvil | TUI completa con scroll local | Tabla de informe sin ocultar versión/fecha | Inventario técnico desplazable con todos los campos |
| Acciones pequeñas o poco descubribles | Botones textuales y atajos que no interfieren con edición | Acciones textuales separadas de los datos mediante reglas finas | Botonera e indicadores prominentes con texto y foco |
| Confirmación genérica / entrega confundida con aplicación | Diálogo con empresa, equipo y efecto; estado «En cola» | Revisión textual del impacto y destino antes de publicar | Instrumento de comandos pendiente del agente |
| Texto secundario de bajo contraste | 9.04:1 sobre fondo, 6.65:1 sobre hover | 6.60:1 sobre fondo, 5.82:1 sobre hover | 7.33:1 sobre fondo, 5.76:1 sobre hover |

Terminal usa caracteres de caja, prompt, rutas y atajos funcionales. Editorial cambia la retícula y jerarquía hacia títulos serif, blancos amplios y líneas finas. Industrial utiliza superficies carbón, medidor graduado, etiquetas condensadas y amarillo señal, sin efectos luminosos ni múltiples acentos.

El pipeline cubre las cinco propuestas. Chromium volvió a fallar con `spawn EPERM`; ver [registro del intento](VALIDACION-NAVEGADOR.md). Los 40 HTML y sus interacciones DOM están comprobados, pero los tamaños 1440/390 px y el foco nativo siguen pendientes de inspección visual.

Incluidos: nombres accesibles, tablas con encabezados, navegación actual, salto al contenido, foco visible, diálogos nativos, errores textuales, estado anunciado, scroll local en tablas, soporte de colores forzados y ausencia de animación obligatoria.

Los tokens de texto superan 4.5:1; ver [VALIDACION.md](VALIDACION.md). No se afirma conformidad integral WCAG. JSDOM comprobó estructura e interacciones, pero el entorno rechazó el lanzamiento del navegador; falta comprobar visualmente escritorio/móvil, zoom 200 %, teclado real y lector de pantalla. Los scripts de comprobación se incluyen para reproducirla cuando se permita ejecutar Chromium.
