# AZC Keeper v4: propuestas de diseño

**Versión definitiva de diseño: [panel-v4](panel-v4/index.html)**, construida sobre el diseño elegido por el cliente, **neomorfismo-antislop**, y el [spec de usabilidad aprobado](USABILIDAD-IA-v4.md). Las propuestas anteriores se conservan para comparación.

## Panel v4: versión definitiva de la arquitectura aprobada

[Abrir Inicio](panel-v4/index.html) · [Acceso](panel-v4/login.html) · [Empresas: Super admin](panel-v4/empresas.html) · [Roles y permisos](panel-v4/roles.html) · [README y Delivery Gate](panel-v4/README.md) · [Validación estática/DOM](panel-v4/VALIDACION.md).

Doce páginas: login, index, equipos, equipo, usuarios, miembro, reglas, reportes, ajustes, roles, empresas y states. Inicio y Reportes incluyen gráficos SVG inline; [Miembro](panel-v4/miembro.html) reúne métricas, equipo, registros y ausencias por persona. Admin Empresa dispone de seis secciones; empresa fija y permisos controlan contenido y acciones. La gestión de roles requiere gate de empresa y meta-permiso; Super admin controla la autonomía. Acciones remotas con destino explícito, confirmación escrita, cola y resultado simulado. Rebrand con preview y contraste validado.

HTML/CSS/JavaScript locales, sin peticiones externas ni dependencias de ejecución. El neomorfismo elegido conserva sus archivos originales. **Delivery Gate global PENDIENTE**: validación estática y DOM registrada; R-03/R-32/R-35 quedan para reflow 1440/390, zoom y teclado nativo con agent-browser del cliente, según su instrucción. Es la versión definitiva de diseño e IA; no una implementación de producción.

```powershell
node docs/design/2026-09-15-propuestas/_tools/panel-v4-build.cjs
node docs/design/2026-09-15-propuestas/_tools/panel-v4-validate.cjs
```

El pipeline anterior solo regenera `panel-v4/`. Los detalles siguientes documentan las propuestas históricas y sus verificaciones de cada ronda.

## Variantes antislop: 16 de septiembre de 2026

- [Neomorfismo antislop](neomorfismo-antislop/index.html): relieve selectivo, superficie perla y prioridad de equipos como foco; desaparecen las tarjetas KPI equivalentes y el relieve aplicado a todo. [Cambios y Gate](neomorfismo-antislop/README.md).
- [Minimal líneas antislop](minimal-lineas-antislop/index.html): registro abierto, contraste tipográfico y lectura por tarea; desaparecen la sidebar genérica, las flechas ornamentales y los gráficos sin una pregunta operativa. [Cambios y Gate](minimal-lineas-antislop/README.md).

Ambas: ENERGY 2 / RHYTHM 2 / MOTION 1; acentos originales; ocho HTML, styles.css y README por carpeta. Menú móvil etiquetado, estados por cada vista, controles táctiles de 44 px y foco explícito. Los datos se identifican como ficticios en cada página. Solo el contexto de empresa persiste en enlaces; las acciones y ediciones son simulaciones locales.

**Validación local: 151 comprobaciones aprobadas, cero fallos.** Referencias relativas existentes, sin peticiones externas; contraste mínimo de texto calculado 5.24:1. Los 20 archivos originales de los finalistas conservan su SHA-256. [Informe DOM y contrastes](VALIDACION-ANTISLOP.md).

**Delivery Gate global: FAIL, pendiente de aprobación.** Chromium no inicia por `spawn EPERM`; faltan reflow medido a 1440/390 px y anchos intermedios, zoom y recorrido nativo completo por teclado. R-03, R-32 y R-35 no se marcan PASS sin esa evidencia. Los archivos se conservan como borradores revisables, no como entrega aprobada por el ruleset. [Registro del bloqueo](VALIDACION-ANTISLOP-NAVEGADOR.md).

Fuentes nuevas: `_tools/antislop-build.cjs`, `antislop-pages.cjs`, `antislop-runtime.js`, `antislop-base.css`, `neomorfismo-antislop.css`, `minimal-lineas-antislop.css`, `antislop-validate.cjs`, `antislop-browser.cjs`, `antislop-docs.cjs` y `antislop-original-hashes.json`. Los README incluyen las 38 reglas, motivos, componentes, tokens y parametrización por tenant.

Regeneración aislada de estas dos variantes, conservando originales:

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/antislop-build.cjs
node docs/design/2026-09-15-propuestas/_tools/antislop-validate.cjs
node docs/design/2026-09-15-propuestas/_tools/antislop-browser.cjs
node docs/design/2026-09-15-propuestas/_tools/antislop-docs.cjs
~~~

Desde esta carpeta, búsquedas de dependencias externas (cero coincidencias):

~~~powershell
rg -n '(src|href)\s*=\s*["\x27](https?:)?//' neomorfismo-antislop minimal-lineas-antislop -g '*.html'
rg -n '@import|url\(\s*["\x27]?(https?:)?//' neomorfismo-antislop minimal-lineas-antislop -g '*.css'
~~~

## Catálogo completo: 18 propuestas, variantes y versión definitiva

| Propuesta | Estado | Dirección | Recorrido |
|---|---|---|---|
| [panel-v4](panel-v4/README.md) | **Versión definitiva de diseño; Gate visual pendiente** | Neomorfismo elegido + IA aprobada, seis secciones, roles parametrizables y flujos F1–F7. | [Inicio](panel-v4/index.html) · [Acceso](panel-v4/login.html) · [Estados](panel-v4/states.html) |
| [neomorfismo](neomorfismo/README.md) | **Finalista** | Superficies claras con doble sombra y relieve. | [Panorama](neomorfismo/index.html) · [Acceso](neomorfismo/login.html) · [Estados](neomorfismo/states.html) |
| [minimal-lineas](minimal-lineas/README.md) | **Finalista** | Sans sobria, blanco y separadores finos. | [Panorama](minimal-lineas/index.html) · [Acceso](minimal-lineas/login.html) · [Estados](minimal-lineas/states.html) |
| [neomorfismo-antislop](neomorfismo-antislop/README.md) | **Diseño elegido; referencia conservada** | Superficie de decisión con relieve selectivo y registros planos. | [Panorama](neomorfismo-antislop/index.html) · [Acceso](neomorfismo-antislop/login.html) · [Estados](neomorfismo-antislop/states.html) |
| [minimal-lineas-antislop](minimal-lineas-antislop/README.md) | **Variante nueva; Gate pendiente** | Ficha de control abierta, cifras con jerarquía y acciones explícitas. | [Panorama](minimal-lineas-antislop/index.html) · [Acceso](minimal-lineas-antislop/login.html) · [Estados](minimal-lineas-antislop/states.html) |
| [editorial-premium](editorial-premium/README.md) | Descartada | Un informe que se hojea: portada, pliego, cifras citadas y notas al margen. | [Panorama](editorial-premium/index.html) · [Acceso](editorial-premium/login.html) · [Estados](editorial-premium/states.html) |
| [bauhaus-geometrico](bauhaus-geometrico/README.md) | Descartada | Una composición de cartel donde la forma clasifica y el tamaño ordena. | [Panorama](bauhaus-geometrico/index.html) · [Acceso](bauhaus-geometrico/login.html) · [Estados](bauhaus-geometrico/states.html) |
| [blueprint-tecnico](blueprint-tecnico/README.md) | Descartada | Una lámina de arquitectura blanca que documenta equipos, alcance y revisiones. | [Panorama](blueprint-tecnico/index.html) · [Acceso](blueprint-tecnico/login.html) · [Estados](blueprint-tecnico/states.html) |
| [humanista-calido](humanista-calido/README.md) | Descartada | Un cuaderno de gestión cuidado: papel hueso, ritmo humano y anotaciones de tinta. | [Panorama](humanista-calido/index.html) · [Acceso](humanista-calido/login.html) · [Estados](humanista-calido/states.html) |
| [swiss-datos](swiss-datos/README.md) | Descartada | Un cartel de datos que muestra su retícula y convierte la cifra en jerarquía. | [Panorama](swiss-datos/index.html) · [Acceso](swiss-datos/login.html) · [Estados](swiss-datos/states.html) |
| [brutalista](brutalista/README.md) | Descartada | Bordes duros y lenguaje crudo. | [Panorama](brutalista/index.html) · [Acceso](brutalista/login.html) · [Estados](brutalista/states.html) |
| [terminal](terminal/README.md) | Descartada | Consola oscura. | [Panorama](terminal/index.html) · [Acceso](terminal/login.html) · [Estados](terminal/states.html) |
| [editorial](editorial/README.md) | Descartada | Primera exploración editorial. | [Panorama](editorial/index.html) · [Acceso](editorial/login.html) · [Estados](editorial/states.html) |
| [industrial](industrial/README.md) | Descartada | Instrumentos de operaciones. | [Panorama](industrial/index.html) · [Acceso](industrial/login.html) · [Estados](industrial/states.html) |
| [flat-corporativo](flat-corporativo/README.md) | Descartada | Flat empresarial. | [Panorama](flat-corporativo/index.html) · [Acceso](flat-corporativo/login.html) · [Estados](flat-corporativo/states.html) |
| [material-claro](material-claro/README.md) | Descartada | Elevación Material. | [Panorama](material-claro/index.html) · [Acceso](material-claro/login.html) · [Estados](material-claro/states.html) |
| [glassmorphism-claro](glassmorphism-claro/README.md) | Descartada | Vidrio claro. | [Panorama](glassmorphism-claro/index.html) · [Acceso](glassmorphism-claro/login.html) · [Estados](glassmorphism-claro/states.html) |
| [soft-cards](soft-cards/README.md) | Descartada | Tarjetas perla redondeadas. | [Panorama](soft-cards/index.html) · [Acceso](soft-cards/login.html) · [Estados](soft-cards/states.html) |

## Historial: cinco direcciones de arte

| Dirección | Retícula y motivo | Tinta única |
|---|---|---|
| Editorial premium | Pliego asimétrico, cita numérica, capitular y marginalia. | Ciruela #754A63 |
| Bauhaus geométrico | Cartel, cifra dominante; círculo, cuadrado y triángulo funcionales. | Rojo óxido #B02E22 |
| Blueprint técnico | Lámina blanca, cotas, llamadas, cajetín y papel milimetrado tenue. | Azul de delineación #275B80 |
| Humanista cálido | Cuaderno de papel hueso, sans humanista, nota y trazo irregular de tinta. | Terracota #8A4938 |
| Swiss datos | Matriz visible, alineación izquierda y cifras de gran escala. | Rojo #B1242A |

Cada dashboard de esta ronda tiene una composición HTML propia. Las cinco direcciones de arte tienen superficies claras, cero sombras y ningún modo oscuro. Se conservan como historial descartado. Marca común: escudo #9A9A9A, texto #303030 y blanco #F8F8F8.

## Páginas y archivos entregados

Las diecisiete propuestas históricas incluyen estos ocho HTML, styles.css y README. `panel-v4/` incluye los doce destinos de la IA aprobada y su ampliación de panel cliente, documentados en su propio README. La descripción siguiente corresponde al conjunto histórico; la organización visual varía:

- `login.html` — Acceso con empresa y ayuda de credenciales.
- `index.html` — Flota multiempresa, KPIs, críticos, conexión e historial horario y de eventos.
- `devices.html` — Búsqueda y filtros por conexión, prioridad y versión; paginación y comandos.
- `device.html` — Specs, versiones, última conexión, políticas confirmadas/pendientes, cronología y comandos.
- `policies.html` — Dominios, descargas/instalación, días/horario y alcance global/empresa/usuario/equipo.
- `users.html` — Personas, equipos asignados, filtros, paginación y edición de acceso.
- `settings.html` — Marca por tenant, nombre/logo/acento, contraste y claves API ficticias.
- `states.html` — Vacío, cargando, error con reintento y confirmación destructiva reproducibles.
- `styles.css` — CSS local; incluye la base compartida y la dirección.
- `README.md` — fundamentos, retícula, motivos, tokens, componentes, comportamiento adaptable y contrato de tenant.

Herramientas nuevas: `_tools/art-layouts.cjs`, `_tools/art-base.css`, `_tools/art-docs.cjs` y cinco CSS fuente con el nombre de cada dirección. Se reutilizan `_tools/base.css` y los seis archivos originales de `assets/`. Documentación común: [USABILIDAD.md](USABILIDAD.md), [VALIDACION.md](VALIDACION.md) y [VALIDACION-NAVEGADOR.md](VALIDACION-NAVEGADOR.md).

## Abrir y comparar

Abrir el enlace «Panorama» o cualquier HTML desde disco. No requiere servidor, build, instalación, framework, fuentes descargadas ni CDN. Los recursos y la navegación usan rutas relativas. Conservar assets/ como carpeta hermana. Las variantes antislop enlazan directamente a su original y a la otra variante. Los pies de las propuestas históricas mantienen el recorrido de su ronda porque no se modificaron.

1. Cambiar empresa entre Grupo AZC, Atlas, todas y Nueva empresa.
2. Filtrar inventario, paginar y abrir un equipo.
3. Probar Bloquear, Apagar y Borrar; verificar empresa, motivo y confirmación escrita.
4. Revisar impacto de política en alcance global, empresa, usuario y equipo.
5. En Configuración, seleccionar empresa y cambiar nombre, logo y tinta; probar rechazo por contraste insuficiente y claves ficticias.
6. En Estados, recorrer vacío, carga, error y confirmación.

Fixtures compartidos en las diecisiete propuestas y variantes: 180 equipos, 160 personas, 124 equipos AZC y 56 Atlas. Corte: 15 sep 2026, 09:42 COT. No hay peticiones externas. Las acciones se simulan localmente; no autentican, no ejecutan comandos ni envían correo. La edición se pierde al recargar/navegar. La empresa seleccionada se conserva mediante query string. La configuración visual no implementa aislamiento ni autorización de backend.

## Validación histórica de las primeras quince propuestas

**696 comprobaciones estáticas/DOM aprobadas, cero fallos en las 15 propuestas.** Incluyen los 120 HTML, referencias relativas existentes, IDs, etiquetas, JavaScript, navegación por tenant, filtros, paginación, políticas, usuarios, marca, claves y confirmación de borrado. Los pares de texto de las cinco nuevas superan 4.5:1; mínimo medido 5.45:1. Búsqueda de HTTP externo en src/href y CSS: cero coincidencias en las cinco nuevas. Detalle en [VALIDACION.md](VALIDACION.md).

**Pendiente: inspección visual real en 1440 y 390 px.** El pipeline intenta abrir archivos file:// en Chromium, pero falla al arrancar con `spawn EPERM`. No se generaron capturas ni se verificaron medidas renderizadas, zoom o foco nativo. El CSS contempla ambas anchuras; JSDOM comprueba contenido e interacciones y no dibuja layout. [Registro del intento](VALIDACION-NAVEGADOR.md).

## Pipeline histórico de las primeras quince propuestas

Este generador regenera las propuestas anteriores. Para trabajar en la iteración antislop y conservar intactos los originales, usar exclusivamente los comandos aislados de la sección inicial. El generador documental histórico escribe el catálogo de su ronda; este README mantiene la selección vigente.

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/art-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
node docs/design/2026-09-15-propuestas/_tools/validate.cjs editorial-premium bauhaus-geometrico blueprint-tecnico humanista-calido swiss-datos
~~~

Generadores sin dependencias. Validadores con las instalaciones existentes de JSDOM y Playwright Core, configurables por JSDOM_MODULE y PLAYWRIGHT_MODULE. Sin argumentos, el verificador de navegador recorre las quince propuestas; con los argumentos anteriores, solo las cinco nuevas.

Desde esta carpeta, comprobación adicional (cero resultados esperados):

~~~powershell
rg -n '(src|href)\s*=\s*["\x27](https?:)?//' editorial-premium bauhaus-geometrico blueprint-tecnico humanista-calido swiss-datos -g '*.html'
rg -n '@import|url\(\s*["\x27]?(https?:)?//' editorial-premium bauhaus-geometrico blueprint-tecnico humanista-calido swiss-datos -g '*.css'
~~~

Solo se crean o actualizan archivos bajo `docs/design/2026-09-15-propuestas/`. Sin cambios de producción ni commit, por instrucción expresa.
