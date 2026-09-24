const fs=require('node:fs');
const path=require('node:path');
const {themes}=require('./themes.cjs');
const root=path.resolve(__dirname,'..');
const tick=String.fromCharCode(96),code=s=>tick+s+tick;
const specs={
 'editorial-premium':{
  name:'Editorial premium',idea:'Un informe que se hojea: portada, pliego, cifras citadas y notas al margen.',
  accent:'Ciruela de imprenta #754A63. Una tinta sobria que distingue observaciones y prioridad sin convertir el informe en una colección de colores.',
  fonts:"Display: 'Palatino Linotype', Palatino, Georgia, serif. Datos: Arial, Helvetica, sans-serif. Identificadores: Consolas, 'Courier New', monospace.",
  grid:'Cabecera de publicación horizontal; número de capítulo, título dominante y marginalia. Pliego de dos columnas en proporción 2.15:1, con 60 px de calle. Los 165 equipos en línea se componen como una cita tipográfica, no como una tarjeta. Total y versión son dos datos secundarios al pie de esa cita. Las incidencias ocupan una columna de lectura separada.',
  motif:'Filete vertical y nota en cursiva. Reaparece en la cita, marginalia, impacto de política, avisos, estados vacíos y diálogo. Capitular en el texto de apertura. Folio y fecha permanecen visibles.',
  components:'Tablas con encabezados serif en cursiva, alineación numérica estable y filas espaciosas. Formularios sin contenedor elevado, campos rectangulares y columna de anotaciones. Confirmación como hoja de autorización con filete superior de tinta; vacío como nota editorial alineada a la izquierda. El acceso funciona como portada con número de edición.',
  tokens:'Escala: 10, 12, 14, 18, 28, 48, 70, 93 y 112 px. Espaciado: 8, 14, 20, 28, 34, 48, 54, 60 px. Líneas #BEBEBE, campos #777777. Radios 0 px. Todas las sombras: none.',
  mobile:'A 1440 px: pliego asimétrico, título de hasta 93 px y cita de 112 px. A 390 px: margen de 20 px, título de 47 px, índice en dos columnas; la marginalia pasa bajo el título y las incidencias siguen a la lectura principal. La cita mantiene una escala de 82 px.',
  selectors:'.premium-spread, .pull-kpi, .premium-column, .margin-caption',min:'6.09'
 },
 'bauhaus-geometrico':{
  name:'Bauhaus geométrico',idea:'Una composición de cartel donde la forma clasifica y el tamaño ordena.',
  accent:'Rojo óxido #B02E22. Conserva la energía de un primario de cartel con profundidad suficiente para texto pequeño y un contexto corporativo.',
  fonts:"Display: 'Arial Black', Arial, Helvetica, sans-serif. Texto: Arial, Helvetica, sans-serif. Índices auxiliares: Consolas, 'Courier New', monospace.",
  grid:'Índice blanco estrecho de 190 px; lienzo principal asimétrico en proporción 1.5:1. El total de equipos ocupa un bloque dominante con una circunferencia recortada. En la columna contigua, tres registros apilados asignan escalas distintas a disponibilidad, prioridad y versión. La segunda franja enfrenta conectividad e incidencias.',
  motif:'Círculo = estado normal, cuadrado = crítico, triángulo = alto. Las formas se repiten en la marca de sección, leyenda, etiquetas de prioridad, registro numérico y acceso. Las palabras acompañan siempre la forma. El cuadrado crítico es sólido incluso sin color.',
  components:'Tipografía de peso alto, títulos breves y numeración de índice. Botones rectangulares, campos geométricos, estado normal circular y sección de impacto con banda de tinta. Las tablas mantienen filas ordenadas; no se dibujan decoraciones dentro de las celdas de datos. El diálogo adopta la misma banda de impacto.',
  tokens:'Escala: 11, 13, 18, 24, 40, 54, 80 y 170 px. Espaciado: 8, 12, 18, 22, 28, 36 px. Filetes #BEBEBE, divisiones principales #303030 de 1 px. Campos #777777. Radios 0 px salvo círculos semánticos. Sombras none.',
  mobile:'A 1440 px: índice lateral blanco y cartel de dos columnas. A 390 px: índice pasa arriba, total domina una franja y los tres registros se apilan con cifra a la derecha. Circunferencia recortada confinada a su bloque. No se ocultan ni las prioridades ni las acciones remotas.',
  selectors:'.bauhaus-poster, .poster-circle, .bauhaus-stack, .shape-legend',min:'5.45'
 },
 'blueprint-tecnico':{
  name:'Blueprint técnico',idea:'Una lámina de arquitectura blanca que documenta equipos, alcance y revisiones.',
  accent:'Azul de delineación #275B80. Se usa como tinta de anotación, cotas y prioridad, sobre papel blanco. No hay paneles azules ni fondos oscuros.',
  fonts:"Títulos: Bahnschrift, 'Arial Narrow', Arial, sans-serif. Texto: 'Segoe UI', Arial, sans-serif. IDs, cotas, fechas y cifras: Consolas, 'Courier New', monospace.",
  grid:'Marco de lámina a 24 px del borde. Cabecera horizontal de proyecto, navegación y alcance. Registro de cuatro cifras encuadradas; dos detalles de operación en proporción 1.65:1. Cajetín final de seis celdas con contenido, revisión, empresa y corte. El fondo milimetrado de 24 px es gris muy tenue; campos, tablas y anotaciones tienen papel opaco.',
  motif:'Cota con extremos, llamada circular numerada y línea guía. Se repiten en los títulos, conectividad, incidencias, esquema del equipo, impacto, acceso y confirmación. El cajetín aparece en las siete páginas de trabajo.',
  components:'Filas y celdas como registro técnico, tipografía monoespaciada solo donde facilita comparar identificadores. Specs en cuadrícula de pares etiqueta/valor, campos con límites definidos, vista de marca punteada como área de colocación. Diálogo con doble filete, sin relieve. Estado vacío mediante círculo de referencia.',
  tokens:'Escala: 9, 10, 11, 15, 25, 46, 64, 69 px. Espaciado: 8, 12, 18, 24, 28, 32, 38 px. Papel #FFFFFF; cuadrícula #F0F1F2; líneas #BFC7CC, campos #697782. Radios 0 px; llamadas 50 %. Sombras none.',
  mobile:'A 1440 px: marco, cuatro cifras y dos detalles en paralelo. A 390 px: marco de 10 px, margen interior de 16 px, registro 2×2, detalles apilados y cajetín de dos columnas. El número de referencia sigue junto al título; las tablas conservan scroll local.',
  selectors:'.drawing-dimension, .blueprint-register, .drawing-callout, .drawing-title-block',min:'6.14'
 },
 'humanista-calido':{
  name:'Humanista cálido',idea:'Un cuaderno de gestión cuidado: papel hueso, ritmo humano y anotaciones de tinta.',
  accent:'Terracota #8A4938. Una tinta cálida y contenida para orientar la atención sin recurrir al color pastel de bajo contraste. El fondo crema es una superficie neutra cálida, no un segundo acento de estados.',
  fonts:"Títulos y texto: Candara, Calibri, 'Segoe UI', sans-serif. Notas manuscritas sugeridas: Georgia, serif en cursiva. IDs: Consolas, 'Courier New', monospace.",
  grid:'Cabecera abierta, navegación textual y cuaderno en proporción 1.9:1. Tres datos se integran en una sola banda de lectura; una nota lateral recoge los equipos críticos y su siguiente acción. El panel principal conserva aire y datos claros, sin filas de tarjetas flotantes.',
  motif:'Trazo curvo de tinta: subrayado del índice activo, óvalo irregular alrededor de la cifra crítica, nota cursiva y separación del acceso. El trazo acompaña la información, nunca la sustituye. Es un SVG inline propio y CSS, no una ilustración descargada.',
  components:'Sans humanista con títulos de peso medio, campos 8 px y notas de papel de 10–12 px. Sin sombras exteriores ni interiores. Tablas con encabezados en caja mixta; formularios con etiquetas amplias y columna de impacto como hoja adjunta. Vacío alineado a la izquierda con instrucción; diálogo como hoja de confirmación clara.',
  tokens:'Escala: 11, 12, 14, 17, 28, 40, 64, 80, 94 px. Espaciado: 8, 12, 18, 22, 26, 34, 44, 54 px. Fondo #F7F5F0; papel #FCFBF8; líneas #C9C3B8, campos #82776A. Radios 8, 10 y 12 px. Sombras none; ninguna doble sombra neomórfica.',
  mobile:'A 1440 px: cuaderno y nota paralelos, título de hasta 80 px. A 390 px: margen de 22 px, título de 49 px y tres registros verticales con cifra a la derecha. La nota pasa al flujo con su óvalo de tinta. Se conservan los mismos límites claros en campos.',
  selectors:'.human-overview, .human-counts, .human-note, .ink-loop, .hand-note',min:'5.52'
 },
 'swiss-datos':{
  name:'Swiss datos',idea:'Un cartel de datos que muestra su retícula y convierte la cifra en jerarquía.',
  accent:'Rojo suizo #B1242A. Marca prioridad y folios dentro de una composición principalmente blanca y carbón. Su oscuridad permite usarlo también en texto pequeño.',
  fonts:"Display y texto: Helvetica, Arial, sans-serif. Identificadores: Consolas, 'Courier New', monospace. Cifras con font-variant-numeric: tabular-nums.",
  grid:'Cabecera y título compartimentados en módulos 24 % / 53 % / 23 %. El tablero ocupa una matriz continua 2:1 con líneas de 1 px visibles; el total llega a 260 px y enfrenta tres registros. Conectividad y alertas son celdas de la misma matriz. Eventos ocupan el ancho completo. Las tablas y formularios prolongan esos ejes.',
  motif:'Celda visible, número de sección de gran escala y alineación izquierda constante. Se repiten en cabecera, datos, especificaciones, editor, gráfico, acceso y diálogo. El espacio se divide; no se envuelven datos en tarjetas con separación y sombras.',
  components:'Grotesca en pesos 400/700, campos cuadrados, botones compactos con objetivo táctil de al menos 44 px salvo acciones de tabla de 40 px. Especificaciones cuadriculadas, editor e impacto adyacentes, filas con divisiones verticales. Modal dividido en título, contenido y acciones por filetes. El vacío usa símbolo tipográfico dominante.',
  tokens:'Escala: 10, 11, 14, 27, 37, 65, 82, 115, 170, 260 px. Espaciado: 8, 14, 20, 24, 26, 38 px. Papel #FFFFFF; retícula #9A9A9A, campos #707070. Radio 0 px. Borde 1 px. Sombras none.',
  mobile:'A 1440 px: título en tres módulos y cifra total de hasta 260 px. A 390 px: folio en una columna de 52 px, título de 45 px, total de 150 px y registros apilados; retícula permanece visible. El informe de eventos y las tablas conservan el orden de lectura y los datos.',
  selectors:'.swiss-matrix, .swiss-primary, .swiss-secondary, .matrix-label',min:'5.53'
 }
};
const pageInfo={login:'Acceso con empresa y ayuda de credenciales.',index:'Flota multiempresa, KPIs, críticos, conexión e historial horario y de eventos.',devices:'Búsqueda y filtros por conexión, prioridad y versión; paginación y comandos.',device:'Specs, versiones, última conexión, políticas confirmadas/pendientes, cronología y comandos.',policies:'Dominios, descargas/instalación, días/horario y alcance global/empresa/usuario/equipo.',users:'Personas, equipos asignados, filtros, paginación y edición de acceso.',settings:'Marca por tenant, nombre/logo/acento, contraste y claves API ficticias.',states:'Vacío, cargando, error con reintento y confirmación destructiva reproducibles.'};
for(const [slug,s]of Object.entries(specs)){
 const t=themes[slug];
 const doc=`# ${s.name} — AZC Keeper v4

**Nueva propuesta; pendiente de selección.** ${s.idea}

[Abrir panorama](index.html) · [Abrir acceso](login.html) · [Índice y estado de las 15 propuestas](../README.md)

## Dirección de arte aplicada

### Retícula y jerarquía

${s.grid}

### Motivo recurrente

${s.motif}

### Lenguaje de componentes

${s.components}

El dashboard tiene una composición HTML propia: ${code(s.selectors)}. Las siete páginas de trabajo comparten número de sección, título, nota contextual y folio. Se reutilizan controles y datos para comparar los mismos flujos; la dirección de arte cambia su organización y tratamiento. No se limita a reemplazar un color sobre el dashboard anterior.

## Tokens de diseño

| Token | Valor / uso |
|---|---|
| ${code('--shield')} | #9A9A9A · escudo y gráficos; no texto informativo |
| ${code('--wordmark')} / ${code('--ink')} | #303030 · marca y texto principal |
| ${code('--brand-white')} | #F8F8F8 · blanco de marca |
| ${code('--surface')} | ${t.surface} · fondo base |
| ${code('--hover')} | ${t.hover} · interacción y superficie mínima |
| ${code('--muted')} | ${t.muted} · texto secundario y placeholders |
| ${code('--accent')} | ${t.accent} · tinta única |
| ${code('--accent-ink')} | ${t.accentInk} · texto sobre tinta |

**Acento:** ${s.accent}

**Tipografía y fallbacks de sistema:** ${s.fonts} No se solicitan fuentes externas. El fallback puede variar métricas entre sistemas; títulos fluidos y puntos de corte limitan ese efecto.

**Escala, espaciado y geometría:** ${s.tokens}

**Responsive:** ${s.mobile} A 800/900 px se reorganizan columnas de contenido. Las tablas tienen scroll horizontal propio con nombre accesible y foco por teclado; no se eliminan columnas. Las áreas táctiles no dependen de hover.

## Estados y accesibilidad

- Prioridad con palabra y símbolo, además de tinta: crítico destacado, alto con triángulo/contorno y OK neutro con verificación${slug==='bauhaus-geometrico'?' y círculo; el cuadrado sólido identifica crítico':''}.
- Mínimo entre los pares de texto comprobados: **${s.min}:1**. Texto principal, secundario, tinta, texto sobre tinta y hover superan 4.5:1. No se aplica opacidad a texto informativo.
- Foco visible de 3 px y separación de 4 px. Botones con foco carbón sobre superficies claras. Enlaces y campos con foco de la tinta de acento.
- Etiquetas persistentes, un h1 por documento, enlace para saltar al contenido, regiones de estado y controles nativos. Movimiento reducido y colores forzados contemplados en base.css.
- Borrar exige empresa, equipo, motivo y hostname escrito; el foco inicial va a Cancelar. El comando queda «En cola», separado de una ejecución confirmada.
- Error de inventario permite reintentar, carga se anuncia y vacío ofrece salida. ${code('states.html')} enlaza escenarios reproducibles.

El DOM y los callbacks están verificados. **Inspección visual en 1440/390 px y comportamiento nativo de foco/diálogo pendientes:** Chromium no inicia por ${code('spawn EPERM')}. No equivale a una certificación WCAG. Ver [VALIDACION.md](../VALIDACION.md) y [VALIDACION-NAVEGADOR.md](../VALIDACION-NAVEGADOR.md).

## Parametrización por tenant

El selector conserva la empresa en los enlaces relativos mediante ${code('?tenant=azc')}, ${code('?tenant=atlas')} y ${code('?tenant=nueva')}; ${code('all')} agrega la flota. En Configuración se elige una empresa antes de editar. Nombre y logo son contenido; color y superficies son variables CSS.

~~~css
/* Ejemplo de contrato de presentación para una futura integración. */
:root[data-tenant="azc"] {
  --shield: #9A9A9A;
  --wordmark: #303030;
  --brand-white: #F8F8F8;
  --ink: #303030;
  --muted: ${t.muted};
  --surface: ${t.surface};
  --hover: ${t.hover};
  --accent: ${t.accent};
  --accent-ink: #F8F8F8;
}
~~~

El prototipo aplica el acento con ${code('document.documentElement.style.setProperty')} y actualiza los nodos del nombre y logo. El ejemplo con ${code('data-tenant')} describe cómo integrarlo; no supone que ese atributo se persista hoy. Configuración comprueba contraste de botón y tinta sobre fondo/hover antes de permitir aplicar. Mantener una sola tinta por empresa. No cambiar la retícula, escala ni significado de las formas al personalizar.

Se puede seleccionar un logo local PNG/JPG/WebP de hasta 2 MB. Su vista previa usa FileReader, sin subida ni peticiones. La base reutiliza ${code('../assets/logo_main.png')} y ${code('../assets/favicon.ico')}. Las ediciones de marca, usuarios, claves y políticas se pierden al navegar o recargar; el contexto de empresa sí viaja en la URL. El prototipo no implementa autorización ni persistencia multiempresa.

## Ocho páginas y archivos

${Object.entries(pageInfo).map(([p,desc])=>'- ['+p+'.html]('+p+'.html) — '+desc).join('\n')}
- [styles.css](styles.css) — CSS local compilado de base.css + art-base.css + ${slug}.css.
- [README.md](README.md) — este documento.

Mismo conjunto ficticio: 180 equipos, 160 personas, 124 equipos de Grupo AZC y 56 de Atlas Consultores, más Nueva empresa vacía. Corte fijo del 15 de septiembre de 2026, 09:42 COT. No se realizan comandos, correo, autenticación ni llamadas API reales.

Abrir cualquier HTML directamente desde disco. JavaScript está incluido en cada archivo; el único CSS enlazado es styles.css. Se distribuye la carpeta junto a assets/ para mantener imágenes y comparación entre propuestas. Sin instalación, servidor, CDN ni framework.

## Generación y validación

El pipeline [build.cjs](../_tools/build.cjs) reutiliza [base.css](../_tools/base.css), añade [art-base.css](../_tools/art-base.css) y el CSS de esta dirección. [art-layouts.cjs](../_tools/art-layouts.cjs) compone las cinco estructuras; [art-docs.cjs](../_tools/art-docs.cjs) actualiza documentación y selección. Fuentes solo bajo la carpeta autorizada.

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/art-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
node docs/design/2026-09-15-propuestas/_tools/validate.cjs ${slug}
~~~

Los HTML entregados no dependen de Node. JSDOM y Playwright son herramientas de validación existentes del entorno; rutas configurables con JSDOM_MODULE y PLAYWRIGHT_MODULE. No se descargaron dependencias.
`;
 fs.writeFileSync(path.join(root,slug,'README.md'),doc);
}
const finalists=['neomorfismo','minimal-lineas'];
const discarded=['brutalista','terminal','editorial','industrial','flat-corporativo','material-claro','glassmorphism-claro','soft-cards'];
const catalog=[...finalists,...Object.keys(specs),...discarded];
const descriptions={neomorfismo:'Superficies claras con doble sombra y relieve.', 'minimal-lineas':'Sans sobria, blanco y separadores finos.',brutalista:'Bordes duros y lenguaje crudo.',terminal:'Consola oscura.',editorial:'Primera exploración editorial.',industrial:'Instrumentos de operaciones.', 'flat-corporativo':'Flat empresarial.', 'material-claro':'Elevación Material.', 'glassmorphism-claro':'Vidrio claro.', 'soft-cards':'Tarjetas perla redondeadas.'};
const rootDoc=`# AZC Keeper v4 — Propuestas de diseño

**Finalistas del cliente: neomorfismo y minimal-lineas.** Se presentan cinco direcciones de arte nuevas, aún sin selección. Las otras ocho propuestas quedan descartadas y se conservan como historial.

## Catálogo completo: 15 propuestas

| Propuesta | Estado | Dirección | Recorrido |
|---|---|---|---|
${catalog.map(k=>`| [${k}](${k}/README.md) | ${finalists.includes(k)?'**Finalista**':discarded.includes(k)?'Descartada':'**Nueva · por evaluar**'} | ${specs[k]?.idea||descriptions[k]} | [Panorama](${k}/index.html) · [Acceso](${k}/login.html) · [Estados](${k}/states.html) |`).join('\n')}

## Cinco direcciones nuevas

| Dirección | Retícula y motivo | Tinta única |
|---|---|---|
| Editorial premium | Pliego asimétrico, cita numérica, capitular y marginalia. | Ciruela #754A63 |
| Bauhaus geométrico | Cartel, cifra dominante; círculo, cuadrado y triángulo funcionales. | Rojo óxido #B02E22 |
| Blueprint técnico | Lámina blanca, cotas, llamadas, cajetín y papel milimetrado tenue. | Azul de delineación #275B80 |
| Humanista cálido | Cuaderno de papel hueso, sans humanista, nota y trazo irregular de tinta. | Terracota #8A4938 |
| Swiss datos | Matriz visible, alineación izquierda y cifras de gran escala. | Rojo #B1242A |

Cada dashboard tiene una composición HTML propia. Títulos, tablas, formularios, estados, acceso y confirmaciones repiten su sistema tipográfico y motivo. Las cinco nuevas tienen superficies claras, cero sombras y ningún modo oscuro. Marca común: escudo #9A9A9A, texto #303030 y blanco #F8F8F8.

## Páginas y archivos entregados

Cada una de las cinco carpetas nuevas contiene exactamente estos diez archivos (50 archivos de propuesta):

${Object.entries(pageInfo).map(([p,desc])=>'- '+code(p+'.html')+' — '+desc).join('\n')}
- ${code('styles.css')} — CSS local; incluye la base compartida y la dirección.
- ${code('README.md')} — fundamentos, retícula, motivos, tokens, componentes, comportamiento adaptable y contrato de tenant.

Herramientas nuevas: ${code('_tools/art-layouts.cjs')}, ${code('_tools/art-base.css')}, ${code('_tools/art-docs.cjs')} y cinco CSS fuente con el nombre de cada dirección. Se reutilizan ${code('_tools/base.css')} y los seis archivos originales de ${code('assets/')}. Documentación común: [USABILIDAD.md](USABILIDAD.md), [VALIDACION.md](VALIDACION.md) y [VALIDACION-NAVEGADOR.md](VALIDACION-NAVEGADOR.md).

## Abrir y comparar

Abrir el enlace «Panorama» o cualquier HTML desde disco. No requiere servidor, build, instalación, framework, fuentes descargadas ni CDN. Los recursos y la navegación usan rutas relativas. Conservar assets/ como carpeta hermana. El pie «Comparar estilo» recorre únicamente los dos finalistas y las cinco direcciones nuevas, manteniendo página y empresa.

1. Cambiar empresa entre Grupo AZC, Atlas, todas y Nueva empresa.
2. Filtrar inventario, paginar y abrir un equipo.
3. Probar Bloquear, Apagar y Borrar; verificar empresa, motivo y confirmación escrita.
4. Revisar impacto de política en alcance global, empresa, usuario y equipo.
5. En Configuración, seleccionar empresa y cambiar nombre, logo y tinta; probar rechazo por contraste insuficiente y claves ficticias.
6. En Estados, recorrer vacío, carga, error y confirmación.

Fixtures compartidos en las quince propuestas: 180 equipos, 160 personas, 124 equipos AZC y 56 Atlas. Corte: 15 sep 2026, 09:42 COT. No hay peticiones externas. Las acciones se simulan localmente; no autentican, no ejecutan comandos ni envían correo. La edición se pierde al recargar/navegar. La empresa seleccionada se conserva mediante query string. La configuración visual no implementa aislamiento ni autorización de backend.

## Validación

**696 comprobaciones estáticas/DOM aprobadas, cero fallos en las 15 propuestas.** Incluyen los 120 HTML, referencias relativas existentes, IDs, etiquetas, JavaScript, navegación por tenant, filtros, paginación, políticas, usuarios, marca, claves y confirmación de borrado. Los pares de texto de las cinco nuevas superan 4.5:1; mínimo medido 5.45:1. Búsqueda de HTTP externo en src/href y CSS: cero coincidencias en las cinco nuevas. Detalle en [VALIDACION.md](VALIDACION.md).

**Pendiente: inspección visual real en 1440 y 390 px.** El pipeline intenta abrir archivos file:// en Chromium, pero falla al arrancar con ${code('spawn EPERM')}. No se generaron capturas ni se verificaron medidas renderizadas, zoom o foco nativo. El CSS contempla ambas anchuras; JSDOM comprueba contenido e interacciones y no dibuja layout. [Registro del intento](VALIDACION-NAVEGADOR.md).

## Regenerar

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/art-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
node docs/design/2026-09-15-propuestas/_tools/validate.cjs editorial-premium bauhaus-geometrico blueprint-tecnico humanista-calido swiss-datos
~~~

Generadores sin dependencias. Validadores con las instalaciones existentes de JSDOM y Playwright Core, configurables por JSDOM_MODULE y PLAYWRIGHT_MODULE. Sin argumentos, el verificador de navegador recorre las quince propuestas; con los argumentos anteriores, solo las cinco nuevas.

Desde esta carpeta, comprobación adicional (cero resultados esperados):

~~~powershell
rg -n '(src|href)\\s*=\\s*["\\x27](https?:)?//' editorial-premium bauhaus-geometrico blueprint-tecnico humanista-calido swiss-datos -g '*.html'
rg -n '@import|url\\(\\s*["\\x27]?(https?:)?//' editorial-premium bauhaus-geometrico blueprint-tecnico humanista-calido swiss-datos -g '*.css'
~~~

Solo se crean o actualizan archivos bajo ${code('docs/design/2026-09-15-propuestas/')}. Sin cambios de producción ni commit, por instrucción expresa.
`;
fs.writeFileSync(path.join(root,'README.md'),rootDoc);
console.log('Cinco README de dirección de arte y catálogo de 15 propuestas actualizados.');
