# Neomorfismo antislop

**Estado: rediseño implementado, pendiente de aprobación del Delivery Gate.**

[Abrir revisión de flota](index.html) · [Abrir acceso](login.html) · [Comparar original](../neomorfismo/index.html) · [Catálogo](../README.md)

## Qué cambia respecto al original

Se elimina la doble sombra de cada KPI, barra, icono, avatar, botón y etiqueta. El relieve queda en el área de decisión y en el diálogo. Desaparecen el anillo y las barras de conectividad: la pregunta sobre qué equipos están desconectados se responde con una cifra etiquetada y un enlace al filtro.

**Liveliness añadido:** La cifra de equipos críticos domina una sola vez y comparte frase con la tarea. Filas de incidencias, registro de alcance y cronología tienen composiciones distintas. El encabezado deja más aire que las filas de trabajo; el acento petróleo identifica prioridad o confirmación, no cada elemento.

Se mantienen las mismas ocho páginas, los mismos 180 equipos y el acento del finalista. La lectura cambia de «cuántas tarjetas tiene el dashboard» a «qué equipo requiere revisión y qué acción se puede solicitar». Los originales se verifican por SHA-256 y no se regeneran con este pipeline.

## Design Read y diales

Lectura: panel de control de equipos Windows para personal administrativo, con lenguaje neomórfico claro y relieve selectivo. **ENERGY 2 / RHYTHM 2 / MOTION 1.** Aplicación durante el rediseño, conforme a la tarea explícita de crear dos variantes nuevas.

- ENERGY 2: un foco por pantalla: prioridad de flota, inventario, identidad del equipo, destino de política, acceso de personas, edición de marca o formulario de acceso.
- RHYTHM 2: encabezado con aire, registros densos y una columna secundaria de contexto; variación justificada por la tarea, sin mosaico de tarjetas.
- MOTION 1: estados de hover, active y foco; cambios de contenido inmediatos. No hay scroll-reveal, animaciones de entrada ni movimiento perpetuo.
- Motivo: Una superficie de trabajo moldeada sobre perla, con filas planas de control dentro y el registro de alcance apoyado directamente en el fondo. La superficie identifica dónde se toma la decisión; el relieve no compite con sus datos.

## Decisiones y propósito (R-31)

| Decisión | Razón |
|---|---|
| Color #245D68 | Conservar reconocimiento del finalista; reservar la tinta para prioridad y decisión principal. |
| Composición | El área en relieve concentra la revisión; el registro plano permite contrastar el alcance sin elevar cinco bloques equivalentes. |
| Tipografía | Segoe UI conserva la familiaridad del entorno Windows y permite distinguir pesos en cifras y nombres sin añadir tipografía promocional. |
| Cifras | Tabulares para comparar valores; una sola cifra de prioridad dominante y totales secundarios. |
| Espacio | 8–16 px relacionan controles, 20–32 px agrupan trabajo y 40–64 px separan tareas; no son márgenes iguales en todas las secciones. |
| Contenedores | Una superficie elevada identifica el área de trabajo; filas y datos se mantienen planos. |
| Iconos e imágenes | El logo aportado identifica el producto; el texto explica acciones y estados, sin set genérico de iconos o nuevas ilustraciones. |
| Gráficos | Se eliminan: el inventario y un recuento con filtro responden mejor a la pregunta de disponibilidad que un anillo decorativo. |
| Móvil | Menú etiquetado en lugar de una fila horizontal comprimida; una columna, después agrupaciones intermedias y finalmente columnas de trabajo/contexto. |

## Tokens y componentes

| Token | Valor |
|---|---|
| --shield | #9A9A9A, escudo; no texto informativo |
| --ink | #303030 |
| --white | #F8F8F8 |
| --surface | #E4E4E4 |
| --hover | #D9D9D9 |
| --muted | #535353 |
| --line | #B5B5B5, separador no interactivo |
| --control | #686868, límite de controles |
| --accent | #245D68 |
| --body / --display | 'Segoe UI', Arial, sans-serif |
| --mono | Consolas, Courier New, monospace; identificadores |

**Radios:** 20 px en la superficie principal, 16 px en diálogo, 12 px en comandos y 8 px en campos/botones. Estados sin cápsulas.

**Sombras:** Superficie de trabajo y diálogo: 6px 6px 14px #C2C2C2, -6px -6px 14px #F8F8F8. Solo el campo enfocado usa doble sombra inset de 2/4 px. Navegación, filas, botones, registro numérico y marca no se elevan.

Texto base 1rem; secundario 0.8125–0.9375rem; títulos mediante clamp() con mínimos en rem. Espaciado: 0.25, 0.5, 0.75, 1, 1.25, 1.5, 2, 2.5, 3 y 4rem. Foco de 3 px separado 4 px. Objetivos interactivos mínimos 44 × 44 px; checkbox nativo dentro de una etiqueta táctil de 44 px. Avisos cerrables, con espacio inferior reservado solo mientras están visibles.

Componentes definidos: cabecera de marca/empresa, menú móvil etiquetado, aviso visible de muestra, fila de incidencia, registro de alcance, tabla desplazable con caption, ficha de equipo, lista de comandos, formulario por secciones, resumen de impacto, editor de acceso, prueba tipográfica de marca, claves de muestra, estado de consulta, diálogo nativo y aviso de resultado cerrable.

**Responsive diseñado:** base estrecha con escala móvil; a 44em caben agrupaciones de campos y registros en dos columnas, y a 70em se separan área de trabajo y contexto. El menú móvil permanece en el flujo, sin barras fijas que cubran contenido. Las tablas conservan columnas en una región con scroll local y foco. Los campos permiten scroll y no hay contenedores de texto con overflow:hidden. **La medida real a 390/1440 px, zoom y teclado de pantalla sigue pendiente.**

### Contraste calculado

| Uso | Texto | Fondo | Relación |
|---|---|---|---|
| Principal | #303030 | #E4E4E4 | 10.38:1 |
| Secundario | #535353 | #E4E4E4 | 6.05:1 |
| Secundario hover | #535353 | #D9D9D9 | 5.45:1 |
| Acento | #245D68 | #E4E4E4 | 5.81:1 |
| Acento hover | #245D68 | #D9D9D9 | 5.24:1 |
| Sobre acento | #F8F8F8 | #245D68 | 6.96:1 |

Mínimo de texto: **5.24:1**. Bordes #686868 y foco #303030 también se calculan sobre fondo y hover, con mínimo no textual de 3:1. No se hace una afirmación de conformidad WCAG integral: falta verificar el comportamiento renderizado y con ayudas técnicas.

## Parametrización por tenant

`?tenant=azc`, `?tenant=atlas`, `?tenant=nueva` y `?tenant=all` conservan contexto al navegar. El runtime establece `data-tenant` y aplica la tinta con variables CSS. Nombre y logo son contenido, no CSS.

~~~css
:root[data-tenant="azc"] {
  --shield: #9A9A9A;
  --ink: #303030;
  --white: #F8F8F8;
  --surface: #E4E4E4;
  --hover: #D9D9D9;
  --muted: #535353;
  --control: #686868;
  --accent: #245D68;
}
~~~

El formulario valida el acento frente a #F8F8F8 y la superficie más oscura (hover) antes de aplicar. Se admite logo PNG/JPG/WebP local de hasta 2 MB mediante FileReader, sin subida. La vista previa se presenta como muestra tipográfica, no como botón falso.

Solo persiste el contexto de empresa en enlaces. Marca, política, roles, comandos y claves cambian en memoria de esta página y se pierden al navegar/recargar. No hay backend, autenticación, envío de correo, comandos remotos, peticiones externas ni implementación de permisos reales. Los comandos locales se separan por equipo, incluso al cambiar empresa en la misma ficha.

## Anti-slop Delivery Gate

### Veredicto global: FAIL, pendiente de aprobación

Los archivos quedan como **borrador revisable**, no como entrega aprobada por el ruleset. R-03, R-32 y R-35 no tienen evidencia completa de navegador porque Chromium falla al iniciar con `spawn EPERM`. La cláusula de R-35 permite informar inspección de código cuando no se puede ejecutar; se aporta esa inspección y la prueba DOM, sin convertirla en aprobación de reflow, teclado nativo o zoom.

La condición de [antislop-core.md, Delivery Gate](../_antislop-rules/antislop-core.md) es explícita: **“A report containing a FAIL must never be shipped.”** Por esa regla los archivos quedan disponibles para revisión, con aprobación retenida hasta completar las comprobaciones pendientes.

**Evidencia ejecutada:** 151 comprobaciones locales aprobadas, 0 fallos en las dos variantes. [Recorrido y contrastes](../VALIDACION-ANTISLOP.md). [Intento de navegador](../VALIDACION-ANTISLOP-NAVEGADOR.md).

Reglas leídas completas: [core](../_antislop-rules/antislop-core.md), [UI](../_antislop-rules/antislop-ui.md), [human](../_antislop-rules/antislop-human.md) y [layoutmobile](../_antislop-rules/antislop-layoutmobile.md). No se instaló un wizard ni se modificó AGENTS.md: el usuario aportó reglas/dirección y limitó las escrituras a esta carpeta.

| Regla | Resultado | Evidencia / cambio |
|---|---|---|
| R-01 | **PASS** | Un acento heredado #245D68. Sin degradados, manchas ni colores añadidos. La tinta distingue prioridad/acción. |
| R-02 | **PASS** | El validador inspecciona body.textContent de los ocho HTML: no hay U+2014. Copy directo en español. |
| R-03 | **FAIL** | CSS móvil propio, controles de 44 px y reflow a 44em/70em implementados. Falta medir overflow, colisiones y teclado móvil en navegador: spawn EPERM. |
| R-04 | **PASS** | No hay set de iconos, emoji, avatar ni marcas decorativas. El signo ! acompaña la palabra Crítico y tiene significado de prioridad. |
| R-05 | **PASS** | La decisión de revisar un equipo ordena la página: incidencias, alcance y registro de eventos. Formularios e impacto siguen su propia estructura; no hay cuatro tarjetas KPI. |
| R-06 | **PASS** | Segoe UI conserva la familiaridad del entorno Windows y permite distinguir pesos en cifras y nombres sin añadir tipografía promocional. No hay titulares de terminal ni tracking expandido. |
| R-07 | **PASS** | Fondo sólido. Los filetes separan registros o campos; no existe patrón de fondo. |
| R-08 | **PASS** | Se retiran las flechas de botones y enlaces. Los verbos y el nombre del destino explican la acción. |
| R-09 | **PASS** | Crítico/Alto/OK son prioridades textuales, sin cápsula, glow o puntos. No existe una etiqueta redundante sobre el h1. |
| R-10 | **PASS** | Cero blur/backdrop-filter. Todas las superficies son opacas. |
| R-11 | **PASS** | 20 px en la superficie principal, 16 px en diálogo, 12 px en comandos y 8 px en campos/botones. Estados sin cápsulas. |
| R-12 | **PASS** | Superficie de trabajo y diálogo: 6px 6px 14px #C2C2C2, -6px -6px 14px #F8F8F8. Solo el campo enfocado usa doble sombra inset de 2/4 px. Navegación, filas, botones, registro numérico y marca no se elevan. El propósito de elevación es identificar el área de decisión. |
| R-13 | **PASS** | No hay glow. El foco es un contorno carbón de 3 px, separado 4 px. |
| R-14 | **PASS** | Las incidencias son filas; el alcance es un dl y los eventos una lista temporal. La información no se reparte en tarjetas uniformes. |
| R-15 | **PASS** | Acciones específicas: Revisar equipo, Revisar publicación, Aplicar identidad local, Revocar clave. No hay CTAs genéricas. |
| R-16 | **PASS** | Texto operativo. Sin promesas promocionales, buzzwords o afirmaciones de seguridad. |
| R-17 | **PASS*** | Alcance autorizado: fixtures ficticios exigidos por el usuario, identificados en cada página y con fuente local rastreable. No se presentan como estadísticas reales. Véase excepción de alcance. |
| R-18 | **PASS** | No hay testimonios, fotos de personas, avatares ni prueba social. Las personas son registros ficticios identificados, solicitados por el usuario. |
| R-19 | **PASS** | MOTION 1: cambios inmediatos de hover/active/foco, sin animaciones, pulsos, parallax o revelado al hacer scroll. |
| R-20 | **PASS** | Una superficie de trabajo moldeada sobre perla, con filas planas de control dentro y el registro de alcance apoyado directamente en el fondo. La superficie identifica dónde se toma la decisión; el relieve no compite con sus datos. |
| R-21 | **PASS** | Tema claro fijo por instrucción expresa y contexto administrativo. No se crea un selector de tema innecesario. |
| R-22 | **PASS** | Sin ilustraciones nuevas. Se reutiliza exclusivamente el logo suministrado. |
| R-23 | **PASS** | Ocho páginas, navegación y datos de muestra autorizados expresamente. Logo original; ningún asset gráfico nuevo o descarga. |
| R-24 | **PASS** | Todos los src/href resuelven a archivos o anclas existentes. Comparación directa al original y a la otra variante, sin alterar los originales. |
| R-25 | **PASS** | Contraste sRGB calculado para texto, hover, acento, texto sobre acento, bordes y foco. Tablas numéricas en VALIDACION-ANTISLOP.md; no se estimó a ojo. |
| R-26 | **PASS** | Handlers y destinos comprobados en DOM: empresa, filtros, páginas, comandos, políticas, roles, invitación, marca, claves, ayuda y contraseña. Validación nativa y cancelación presentes; recorrido de navegador pendiente en R-35. |
| R-27 | **PASS** | Cada una de las seis vistas de datos admite ?state=empty, loading y error. Hay mensajes por consulta, salida y empresa conservada. Filtros sin coincidencias y claves vacías tienen estados propios. |
| R-28 | **PASS** | No se inventa una FAQ. La ayuda de acceso explica el alcance del prototipo y el contacto con el administrador. |
| R-29 | **PASS** | Base #9A9A9A/#303030/#F8F8F8, grises derivados y una sola tinta heredada. No se añaden colores para éxito o error. |
| R-30 | **PASS** | Dirección tomada de los dos finalistas del usuario, no de un producto ajeno. Composición por tarea, sin adoptar un kit SaaS. |
| R-31 | **PASS** | Razones de color, retícula, tipografía, separación, relieve e iconografía documentadas en la tabla de decisiones de este README. |
| R-32 | **FAIL** | Controles nativos, skip link, menú con Escape y restauración de foco implementados y comprobados en DOM. Falta recorrer Tab/Shift+Tab y confinamiento/Escape del dialog nativo en navegador. |
| R-33 | **PASS** | Fuente nueva escrita directamente en antislop-pages.cjs, runtime.js y CSS. El generador compone plantillas y concatena CSS; no reescribe HTML/CSS originales con reemplazos. Solo lee sus fixtures. |
| R-34 | **PASS** | Solo se entrega tema claro. Cada variante tiene tokens y contraste propios; el rebrand rechaza tintas que incumplen 4.5:1. Sin modo alternativo incompleto. |
| R-35 | **FAIL** | Build y recorrido DOM ejecutados y registrados. Chromium no arranca: spawn EPERM. No hay capturas ni click-through nativo, reflow/zoom medidos o teclado móvil probado. No se atribuye a JSDOM esa evidencia. |
| R-36 | **PASS** | No se inventan certificaciones, rendimiento, seguridad o clientes. La muestra no afirma ejecutar comandos, autenticar ni enviar correo. |
| R-37 | **PASS** | Dirección recibida antes de diseñar: finalista neomorfismo, claro, empresarial y acento conservado. Design Read declarado en conversación y abajo. |
| R-38 | **PASS*** | Muestra ficticia explícita en cada documento, tablas y escenarios. Se mantiene la instrucción específica del usuario; no se disfraza la muestra como producción. |

**Excepción de alcance, R-17/R-38:** el core prohíbe estadísticas inventadas; esta tarea exige datos ficticios realistas. Prevalece esa instrucción expresa. Los 180 equipos y 160 personas se leen de los fixtures del finalista original, las empresas y eventos son ejemplos y cada pantalla lo dice antes del contenido. PASS* aplica al alcance de prototipo autorizado, no afirma conformidad literal con la prohibición absoluta de cifras sin fuente real. No hay métricas de adopción o rendimiento usadas como publicidad.

### Cuatro bloques del Gate

| Bloque | Resultado | Motivo |
|---|---|---|
| Hard Gate | **FAIL** | Falta evidencia nativa para R-03/R-32/R-35. R-17/R-38 con excepción expresa de muestra. |
| Purpose-Gate | **PASS documental** | Colores, tipos, relieve, líneas, estados y ausencia de gráficos tienen propósito escrito. |
| Liveliness | **PASS documental** | Diales declarados antes de generar, foco por tarea, ritmo y motivo implementados. Inspección visual pendiente. |
| Craftsmanship / Quality Locks | **FAIL** | C-4 (resiliencia completa) no está acreditado sin navegador. C-1, C-2 y C-3 documentados y verificados en código/DOM; C-5 restringido a muestra explícita. |

### Suplementos

| Checklist | Resultado |
|---|---|
| UI: propósito, dosis, acento, copy, composición y ausencia de controles muertos | PASS por código y recorrido DOM; aspecto renderizado pendiente. |
| Human: cálculo de texto, bordes y foco; etiquetas y estados perceptibles | PASS por cálculo/DOM. |
| Human: Tab/Shift+Tab, foco atrapado en diálogo, zoom y teclado móvil | FAIL por falta de ejecución en navegador. |
| Layoutmobile: composición estrecha/intermedia/amplia, tamaños, menú, scroll local y objetivos definidos | PASS por implementación e inspección de CSS. |
| Layoutmobile: medida de overflow, colisiones y targets en viewport real | FAIL por falta de ejecución en navegador. |

### Registro de controles

| Control | Acción y evidencia |
|---|---|
| Navegación y comparación | Todos los destinos existen y son relativos; resolución automática de enlaces de las 16 páginas. Navegación nativa pendiente. |
| Empresa | 180 equipos agregados, 56 Atlas, Nueva empresa muestra vacío; query de empresa se conserva. DOM ejecutado. |
| Menú | Details/summary nativos; Escape cierra y devuelve foco. Callback DOM ejecutado, Tab nativo pendiente. |
| Buscar / filtrar / limpiar / paginar | Resultados cambian; vacío diferenciado; anterior/siguiente cambian filas y rango. DOM ejecutado. |
| Bloquear / Apagar / Borrar | Diálogo con destinatario; motivo requerido; Borrar exige hostname exacto; cancelar conserva datos; confirmar añade cola local. DOM ejecutado. |
| Política | Alcances 180/124/2/1; rechaza URL como dominio, horario invertido y ausencia de días; confirmación publica revisión local. DOM y handler inspeccionados. |
| Roles / invitación | Cambia rol local, conserva empresa y anuncia invitación sin enviar correo. Restauración de foco tras reemplazar filas verificada. |
| Marca / color | Nombre y tinta aplicados; acento claro bloquea submit; restablecer vuelve al original. DOM ejecutado. |
| Logo | Handler inspeccionado: tipo/tamaño, FileReader y proporción de imagen. Selección real de archivo pendiente del recorrido preparado en navegador. |
| Claves | Crear añade fila, cancelar revocación la conserva y confirmar la elimina; vacío explícito. DOM ejecutado. |
| Acceso / contraseña / ayuda | Validación de email/8 caracteres, mostrar/ocultar y diálogo de ayuda ejecutados en DOM. Redirección local inspeccionada, navegación real pendiente. |
| Escenarios | 18 combinaciones por variante, cada una con mensaje y salida conservando empresa. DOM ejecutado. |
| Aviso de resultado | Cerrar oculta aviso y retorna foco a un control válido, incluso si la fila fue renderizada de nuevo. DOM ejecutado. |

## Archivos y validación

- [login.html](login.html): Acceso por empresa, validación nativa, mostrar contraseña y ayuda local.
- [index.html](index.html): Equipos críticos primero, alcance de flota y eventos ficticios identificados.
- [devices.html](devices.html): Búsqueda, conexión, prioridad, agente, paginación y comandos por equipo.
- [device.html](device.html): Identidad, specs, políticas, cronología y solicitudes separadas por equipo.
- [policies.html](policies.html): Dominios, descargas, instalación, días, horario y alcance con revisión de impacto.
- [users.html](users.html): Personas, equipos, rol por empresa e invitación simulada.
- [settings.html](settings.html): Nombre, logo local, acento con comprobación de contraste y claves ficticias.
- [states.html](states.html): Enlaces a vacío, carga y error de cada vista, más borrado y error de acceso.
- [styles.css](styles.css): CSS local completo.
- [README.md](README.md): dirección, tokens, parametrización y gate.

El generador aislado lee los fixtures del original y escribe solo `neomorfismo-antislop/` y `minimal-lineas-antislop/`. Se usa una base nueva, `_tools/antislop-base.css`, porque heredar `_tools/base.css` introduciría el shell de dashboard, objetivos de 40 px y reglas de escritorio que se están retirando. Se reutilizan logos, paleta, datos y flujos; no se modifica la base histórica.

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/antislop-build.cjs
node docs/design/2026-09-15-propuestas/_tools/antislop-validate.cjs
node docs/design/2026-09-15-propuestas/_tools/antislop-browser.cjs
node docs/design/2026-09-15-propuestas/_tools/antislop-docs.cjs
~~~

HTML estático abrible desde disco, CSS local, scripts inline y sin frameworks/CDNs. JSDOM/Playwright solo pertenecen a la validación, usando instalaciones existentes configurables por JSDOM_MODULE y PLAYWRIGHT_MODULE; el entregable no los requiere. No se descargaron dependencias.

Para aprobar el gate, completar el recorrido de navegador a 390/1440 px, anchos intermedios, zoom 200 %, teclado y teclado móvil, revisar capturas y registrar la evidencia. El generador de README no aprueba el gate automáticamente. Sin commit ni cambios fuera de la carpeta autorizada.
