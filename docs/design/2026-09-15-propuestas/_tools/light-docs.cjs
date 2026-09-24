const fs=require('node:fs');
const path=require('node:path');
const {themes}=require('./themes.cjs');
const root=path.resolve(__dirname,'..');
const tick=String.fromCharCode(96),code=s=>tick+s+tick;
const specs={
 'flat-corporativo':{
  title:'Flat corporativo',concept:'Orden y lectura directa',accent:'Azul corporativo',why:'El azul #285AA8 da una señal sobria de acción y prioridad; acompaña una interfaz de trabajo empresarial sin teñir todas las métricas.',
  foundation:'Sidebar blanca persistente, tarjetas planas de borde gris, encabezado compacto y espacios regulares. Los bloques se delimitan con bordes, no con relieve. El estado activo de navegación usa una línea de acento y un fondo gris neutro. El dashboard mantiene cuatro KPIs y dos columnas de operación para escanear rápidamente la flota.',
  difference:'Respecto de neomorfismo, las tarjetas son blancas sobre fondo gris claro, sin pares de sombras ni inputs hundidos. Respecto de las otras variantes nuevas, conserva la estructura SaaS más directa: sidebar ancha, radios pequeños y tabla con cabecera definida.',
  radius:'Panel 10 px; campo/botón 6 px; badge 5 px.',shadow:'Panel: 0 1px 2px rgba(48,48,48,.04). Controles y campos sin sombra.',line:'#D4D4D4',control:'#858585',
  desktop:'1440 px: sidebar de 222 px, topbar blanca, cuatro tarjetas KPI, conectividad y alertas en dos columnas.',mobile:'390 px: navegación horizontal con scroll local, dos KPIs por fila y contenido en una columna; tarjetas de 9 px de radio.',
  components:'Sidebar con indicador lateral, tarjeta plana, cabecera de tabla gris, caja de impacto y botones textuales de borde.',spacing:'Base 4/8/12/16/24/32 px; margen de contenido 32 px; gap de tarjetas 18 px; móvil 18 px.',type:'Títulos 31–45 px, KPIs 44–49 px, cuerpo 12–14 px, metadatos 10–11 px.'
 },
 'material-claro':{
  title:'Material claro',concept:'Acciones y jerarquía por elevación',accent:'Violeta sobrio',why:'El violeta #675095 distingue los controles principales con una presencia contenida y contraste alto; evita reproducir otra variante azul con el mismo tratamiento.',
  foundation:'Rail de navegación con icono y etiqueta, topbar elevada, tarjetas blancas y campos de gris claro con línea inferior. La elevación tiene niveles: lecturas con sombra mínima, paneles con sombra media y diálogo/FAB con sombra mayor. Las formas redondeadas delimitan cada componente sin relieves interiores.',
  difference:'Es una interpretación estática de Material en tema claro, no una implementación certificada de su librería. Frente al neomorfismo, la sombra tiene una sola dirección y los componentes se separan del fondo. El FAB extendido «Buscar equipo» abre el inventario del tenant seleccionado; no representa una función inexistente.',
  radius:'Panel 16 px; campos 6 px en esquinas superiores; botones 22–24 px; FAB 18 px; diálogo 24 px.',shadow:'Panel: 0 2px 5px rgba(48,48,48,.10), 0 1px 2px rgba(48,48,48,.05). FAB: 0 5px 14px rgba(48,48,48,.20). Sin sombra inset.',line:'#C8C8C8',control:'#777777',
  desktop:'1440 px: rail de 116 px, tarjetas KPI en cuatro columnas, FAB extendido fijo en la esquina inferior derecha.',mobile:'390 px: rail convertido en navegación horizontal; paneles apilados y FAB dentro del flujo, sin tapar tablas ni acciones.',
  components:'Rail con etiquetas, topbar elevada, campo relleno, botón cápsula, tarjeta de lectura, FAB extendido y diálogo de mayor elevación.',spacing:'Base 4/8/12/16/24/32 px; contenido 36 px; paneles 27 px; gap KPI 20 px; móvil 18 px.',type:'Títulos 31–45 px, KPIs 48–53 px, cuerpo 12–14 px. Pesos de título 500–620.'
 },
 'glassmorphism-claro':{
  title:'Glassmorphism claro',concept:'Transparencia contenida',accent:'Azul verdoso',why:'El azul verdoso #306D77 aporta un acento serio sobre el eje gris. Mantiene al menos 4.66:1 incluso frente al gris más oscuro permitido del fondo; no hay colores luminosos ni degradados cromáticos.',
  foundation:'Navegación superior flotante, paneles blancos al 90 % y un fondo de dos degradados radiales exclusivamente neutros. El blur de 18 px se limita a las superficies; tabla, formularios y diálogo tienen fondo casi opaco u opaco. Los bordes blancos y la sombra suave producen separación sin oscurecer la página.',
  difference:'La profundidad nace de la translucidez y la composición sobre el fondo claro, no de extrusión. No depende de ilustraciones ni de descargas. Sin backdrop-filter, el CSS usa blanco sólido. Con prefers-reduced-transparency, desactiva blur y degradados; conserva los mismos datos y controles.',
  radius:'Panel 18 px; topbar flotante 20 px; campo/botón 10 px; badge 18 px.',shadow:'Panel: 0 8px 22px rgba(48,48,48,.065). Sin doble sombra. Blur: backdrop-filter de 18 px, con fallback blanco.',line:'#BDBDBD',control:'#7C7C7C',
  desktop:'1440 px: lienzo centrado, margen externo 36 px, navegación flotante, cuatro KPIs y dos columnas de operación.',mobile:'390 px: margen externo 14 px, navegación desplazable dentro del panel, dos KPIs por fila, tarjetas apiladas y formularios blancos.',
  components:'Masthead translúcido, tarjeta de vidrio, snapshot sobre superficie blanca, tabla de fondo al 97 %, campo opaco y fallback sin transparencia.',spacing:'Base 4/8/12/16/24/32 px; margen externo 36 px; paneles 26 px; gap 20–27 px; móvil 14–18 px.',type:'Títulos 35–50 px, KPIs 47–53 px, cuerpo 12–14 px, pesos 550–620.'
 },
 'minimal-lineas':{
  title:'Minimal líneas',concept:'Estructura con lo mínimo',accent:'Verde grisáceo',why:'El verde grisáceo #416A60 se reserva a prioridad, foco y acciones. La mayor parte de la jerarquía se expresa por alineación y líneas, con contraste de texto alto sobre blanco.',
  foundation:'Sidebar textual, tipografía sans en toda la interfaz, banda KPI dividida por líneas y secciones sin tarjetas de fondo. La tabla usa cabeceras discretas, filas espaciosas y separadores de 1 px. No hay ilustraciones añadidas ni sombras decorativas.',
  difference:'A diferencia de la editorial descartada, no utiliza serif, folios ni titulares de portada: es un panel administrativo de jerarquía contenida. Frente al neomorfismo y Soft cards, elimina el relieve de las superficies. La línea es el recurso de agrupación; el control conserva etiqueta y foco.',
  radius:'Secciones 0; controles y badge 3 px.',shadow:'Paneles, controles y campos sin sombra. Solo el diálogo usa sombra ligera de separación (0 8px 28px rgba(48,48,48,.08)).',line:'#C2C2C2',control:'#808080',
  desktop:'1440 px: sidebar textual de 198 px, márgenes de 42 px, KPIs en banda continua y tabla limpia.',mobile:'390 px: márgenes de 22 px, navegación textual horizontal, banda KPI en retícula 2×2 y separadores preservados.',
  components:'Navegación con línea activa, banda de métricas, sección sin caja, tabla con reglas finas, input de línea inferior y revisión de impacto.',spacing:'Base 4/8/12/16/24/32/40 px; contenido 42 px; gap 38 px; móvil 22 px.',type:'Títulos sans 31–44 px, KPIs 44–48 px, cuerpo 12–14 px, peso 450–550 en lecturas y títulos.'
 },
 'soft-cards':{
  title:'Soft cards',concept:'Claridad sobre gris perla',accent:'Oliva sobrio',why:'El oliva #526A43 añade una señal discreta, cercana a los neutros. Distingue decisiones y estados sin saturación alta; el texto sobre el acento y sobre hover supera 4.5:1.',
  foundation:'Fondo gris perla, tarjetas blancas de radios amplios y relieve muy tenue en una sola dirección. La navegación se aloja en una cápsula superior; el selector y los estados redondeados mantienen continuidad. El contenido tiene aire, con tablas y campos de bordes visibles.',
  difference:'Es la alternativa más próxima a la suavidad aprobada, pero no reproduce la doble sombra ni las superficies del mismo tono propias del neomorfismo. Las tarjetas blancas se distinguen del fondo perla; inputs y medidor carecen de sombra hundida. Suave no significa texto tenue.',
  radius:'Panel 28 px (24 px móvil), tarjeta KPI 24 px, campo 12 px, botón 14–24 px, badge 22 px.',shadow:'Panel: 0 4px 12px rgba(48,48,48,.045). Control: 0 2px 4px rgba(48,48,48,.035). Sin sombras opuestas ni inset.',line:'#CECECE',control:'#828282',
  desktop:'1440 px: navegación superior en cápsula, cuatro KPIs redondeados, tarjetas blancas y dos columnas de operación.',mobile:'390 px: cápsula con navegación horizontal, dos KPIs por fila, paneles de 24 px de radio y contenido apilado.',
  components:'Navegación cápsula, tarjeta blanca redondeada, avatar circular, medidor sobre disco claro, filtro agrupado y diálogo amable con destino explícito.',spacing:'Base 4/8/12/16/24/32 px; margen externo 32 px; paneles 28 px; gap 22–26 px; móvil 14–18 px.',type:'Títulos 34–49 px, KPIs 48–53 px, cuerpo 12–14 px, pesos 550–600.'
 }
};
const files={
 'login.html':'Empresa, correo, contraseña, mostrar/ocultar y acceso simulado.',
 'index.html':'Dashboard multiempresa, KPIs, equipos en línea, histórico, alertas y eventos.',
 'devices.html':'Tabla filtrable/paginada: estado, versión, contacto y bloquear/apagar/borrar.',
 'device.html':'Especificaciones, usuario, políticas aplicadas, cronología y comandos.',
 'policies.html':'Dominios, descarga/instalación, horario y alcance global/empresa/usuario/equipo.',
 'users.html':'Personas, equipos asignados, permisos, búsqueda y edición local de acceso.',
 'settings.html':'Nombre, logo y acento por tenant; vista previa y claves API ficticias.',
 'states.html':'Vacío, cargando, error y confirmación destructiva reproducibles.'
};
function ratio(a,b){const lum=h=>h.match(/[a-f\d]{2}/gi).map(v=>parseInt(v,16)/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);const x=lum(a),y=lum(b);return ((Math.max(x,y)+.05)/(Math.min(x,y)+.05)).toFixed(2);}
for(const [key,s]of Object.entries(specs)){
 const t=themes[key];
 const doc=`# ${s.title} — ${s.concept}

[Dashboard](index.html) · [Acceso](login.html) · [Estados](states.html) · [Todas las propuestas](../README.md)

Estado: **nueva propuesta para evaluación**. La referencia aprobada por el cliente es neomorfismo; esta propuesta respeta la familia clara/profesional, con estructura propia.

## Fundamentos del estilo

${s.foundation}

${s.difference}

### Un único acento: ${s.accent}

${s.why}

| Estado | Representación |
|---|---|
| Crítico | ${code('! Crítico')}, relleno de acento y texto blanco |
| Alto | ${code('△ Alto')}, texto de acento y borde discontinuo |
| OK | ${code('✓ OK')}, texto carbón y borde neutro |

Las etiquetas y símbolos conservan la diferencia sin depender del color. La conexión de un equipo no equivale a actividad del usuario ni confirma cumplimiento de políticas.

## Tokens de diseño

| Token / propiedad | Valor |
|---|---|
| Base de marca | ${code('--shield: #9A9A9A')}, ${code('--brand-charcoal: #303030')}, ${code('--white: #F8F8F8')} |
| Fondo | ${code('--surface: '+t.surface)} |
| Tarjeta | ${code('--card: #FFFFFF')}${key==='glassmorphism-claro'?' · panel blanco al 90 %; fondo mínimo #E5E5E5':''} |
| Texto | ${code('--ink: '+t.ink)}, ${code('--muted: '+t.muted)} |
| Acento | ${code('--accent: '+t.accent)}, ${code('--accent-ink: '+t.accentInk)} |
| Hover / divisiones | ${code('--hover: '+t.hover)}, ${code('--line: '+s.line)} |
| Borde de campo | ${code('--control-line: '+s.control)} |
| Tipografías de sistema | ${code('--font-body')} y ${code('--font-display')}: ${code("'Segoe UI', Arial, sans-serif")}; ${code('--font-mono')}: ${code("Consolas, 'Courier New', monospace")} |
| Tamaños/pesos | ${s.type} |
| Espaciado | ${s.spacing} |
| Radios | ${s.radius} |
| Sombras | ${s.shadow} |

Los tonos adicionales son neutros. No hay fondo carbón/negro, modo oscuro, acentos neón ni elementos de terminal/HUD en esta propuesta. Se conserva el logo original gris/carbón para superficies claras.

## Componentes

${s.components}

Se reutilizan los componentes funcionales del prototipo: selector de tenant, tabla semántica, badges textuales, formulario etiquetado, mensajes de estado, errores con salida, paginación y diálogo nativo. Campos y botones tienen nombre accesible; foco de 3 px con separación del contorno. Los controles principales miden al menos 44 px de alto; acciones compactas de tabla, 40 px.

## Ocho páginas, mismo alcance

| Página | Contenido |
|---|---|
${Object.entries(files).map(([f,d])=>'| ['+f+']('+f+') | '+d+' |').join('\n')}

### Estados que se pueden probar

- Vacío: seleccionar «Nueva empresa» en el inventario o usar una búsqueda inexistente.
- Cargando: ${code('devices.html?state=loading')}; contiene salida «Mostrar resultado».
- Error: ${code('devices.html?state=error')}; conserva el contexto y ofrece «Reintentar».
- Destructivo: «Borrar» en tabla o detalle abre diálogo con equipo, empresa, efecto y motivo; exige escribir el hostname. Al confirmar, se muestra «En cola», pendiente de respuesta del agente.

## Responsive

${s.desktop}

${s.mobile}

Las tablas mantienen versión y último contacto mediante scroll en su propia región, sin ocultar esas columnas. Se incluyen movimiento reducido, colores forzados, salto al contenido y foco visible. No hay selector de modo oscuro; se declara ${code('color-scheme: light')}.

## Contraste de texto

| Par comprobado | Relación sRGB |
|---|---|
| Texto principal / fondo | ${ratio(t.ink,t.surface)}:1 |
| Secundario / fondo | ${ratio(t.muted,t.surface)}:1 |
| Blanco / acento | ${ratio(t.accentInk,t.accent)}:1 |
| Acento / fondo | ${ratio(t.accent,t.surface)}:1 |
| Acento / hover o fondo mínimo | ${ratio(t.accent,t.hover)}:1 |
| Secundario / hover | ${ratio(t.muted,t.hover)}:1 |

Todos estos pares superan 4.5:1. ${key==='glassmorphism-claro'?'El mínimo #E5E5E5 cubre el extremo más oscuro del degradado; las capas blancas solo elevan su luminosidad. Los diálogos y campos son opacos.':'Las tarjetas blancas aumentan el contraste respecto del fondo de página.'} El gris del escudo se reserva a marca/gráficos, no a texto informativo pequeño.

## Parametrización por tenant

~~~css
html[data-tenant="azc"] {
  --surface: ${t.surface};
  --ink: ${t.ink};
  --muted: ${t.muted};
  --accent: ${t.accent};
  --accent-ink: ${t.accentInk};
}
~~~

El ejemplo representa la configuración validada que resolvería el servidor futuro. En el prototipo, ${code('settings.html')} aplica variables al elemento raíz mediante JavaScript local. Nombre y logo son contenido, nunca HTML/CSS arbitrario. Los defaults están en ${code('_tools/themes.cjs')}.

El editor mantiene fijos fondo y familia clara. Permite cambiar **un** acento y el texto sobre él; comprueba tanto texto/botón como acento/fondo y acento/hover antes de aplicar. Logo PNG/JPG/WebP local de hasta 2 MB. La vista agregada exige seleccionar una empresa antes de editar marca o claves.

La empresa viaja mediante links relativos con ${code('?tenant=all|azc|atlas|nueva')}. Ediciones de políticas, accesos, marca y claves viven solo mientras la página siga abierta. No persisten al navegar/recargar. La selección visual no constituye autorización: el backend futuro debe imponer aislamiento.

## Datos, uso y verificación

180 equipos y 160 usuarios ficticios; Grupo AZC tiene 124 equipos y Atlas 56; una empresa vacía permite probar el estado inicial. Corte fijo 15 sep 2026, 09:42 COT. Specs/eventos son fixtures. No hay autenticación, envío de correos, peticiones externas ni ejecución real de comandos. Borrado remoto y API keys por tenant son propuestas v4; no se atribuyen al K3 existente.

Abrir ${code('index.html')} por doble clic y conservar la carpeta compartida ${code('../assets/')}. Un CSS final por propuesta, JavaScript inline y fuentes del sistema; no necesita servidor, instalación, framework o CDN. El generador compone ${code('_tools/base.css')} + ${code('_tools/light-base.css')} + el CSS específico. JSDOM/Playwright se usan solo como herramientas de verificación, no como dependencias del prototipo.

Ver [VALIDACION.md](../VALIDACION.md) para estructura, referencias, interacción DOM y contrastes. **No se completó la inspección visual a 1440/390 px:** Chromium falló con ${code('spawn EPERM')}. Se deja preparado el recorrido de navegador. JSDOM no acredita layout real, lector de pantalla ni conformidad integral WCAG.
`;
 fs.writeFileSync(path.join(root,key,'README.md'),doc);
}
const labels={brutalista:'Brutalista',neomorfismo:'Neomorfismo',terminal:'Terminal',editorial:'Editorial',industrial:'Industrial',...Object.fromEntries(Object.entries(specs).map(([k,s])=>[k,s.title]))};
const discarded=['brutalista','terminal','editorial','industrial'];
const order=['neomorfismo',...Object.keys(specs),...discarded];
const rootDoc=`# AZC Keeper v4 — Diez propuestas de diseño

## Estado de la selección

**Neomorfismo es la referencia que gustó al cliente.** Las cinco variantes nuevas son claras, sobrias y corporativas. Brutalista, terminal, editorial e industrial están descartadas y se conservan como archivo de la evaluación.

| Propuesta | Estado | Dashboard | Login | Fundamentos y tokens |
|---|---|---|---|---|
${order.map(k=>'| '+labels[k]+' | '+(k==='neomorfismo'?'**Referencia aprobada**':discarded.includes(k)?'**Descartada**':'Nueva · por evaluar')+' | [Abrir]('+k+'/index.html) | [Acceso]('+k+'/login.html) | [README]('+k+'/README.md) |').join('\n')}

## Cinco variantes claras: qué cambia

| Carpeta | Criterio visual | Acento |
|---|---|---|
${Object.entries(specs).map(([k,s])=>'| '+code(k+'/')+' | '+s.concept+' | '+s.accent+' '+code(themes[k].accent)+' |').join('\n')}

Flat prioriza bordes y estructura; Material, componentes y elevación; Glass, transparencia sutil; Minimal, líneas y tipografía sans; Soft cards, radios amplios y relieve tenue. Las cinco usan fondos claros, superficies claras y texto de alto contraste. Comparten la marca ${code('#9A9A9A / #303030 / #F8F8F8')}. Solo hay un acento por variante.

Abrir por doble clic, sin servidor, frameworks, CDNs ni Internet. Mantener completa la carpeta de propuestas para conservar ${code('assets/')}. Cada estilo tiene un único CSS final, scripts inline y fuentes del sistema.

La comparación desde las páginas recorre únicamente **neomorfismo → flat → material → glass → minimal → soft → neomorfismo**, manteniendo módulo y tenant. El archivo de propuestas descartadas sigue accesible en la tabla superior.

## Exactamente las mismas ocho páginas

| Archivo, presente en las diez carpetas | Flujo |
|---|---|
${Object.entries(files).map(([f,d])=>'| '+code(f)+' | '+d+' |').join('\n')}

Cada carpeta incluye también ${code('styles.css')} y ${code('README.md')}. Son 80 HTML totales, 40 de esta ampliación. No se añadieron módulos ni páginas extra en las propuestas.

## Recorrido de evaluación

1. Cambiar empresa en el dashboard: cambian KPIs, alertas y eventos.
2. Filtrar inventario por nombre, prioridad, conexión o versión; navegar entre resultados.
3. Abrir un equipo, probar «Borrar», escribir hostname y motivo; observar «En cola».
4. Cambiar el alcance de una política, dominios y horario; revisar impacto antes de publicar.
5. En Configuración, seleccionar una empresa y probar nombre/logo/acento, comprobación de contraste y claves ficticias.
6. Abrir ${code('states.html')} para vacío, cargando, error y confirmación destructiva.

Mismo dataset en las diez variantes: 180 equipos, 160 usuarios, 124 equipos Grupo AZC, 56 Atlas y una empresa vacía. Corte fijo: 15 sep 2026, 09:42 COT. La empresa viaja en enlaces relativos; las ediciones no persisten al recargar/navegar. Las operaciones son locales y ficticias: no ejecutan comandos, no envían correo, no autentican ni implementan autorización multiempresa.

## Archivos y alcance

- Carpetas nuevas: ${Object.keys(specs).map(k=>code(k+'/')).join(', ')}; cada una contiene ocho HTML, CSS y README.
- Comunes: ${code('README.md')}, [USABILIDAD.md](USABILIDAD.md), [VALIDACION.md](VALIDACION.md), [VALIDACION-NAVEGADOR.md](VALIDACION-NAVEGADOR.md).
- Pipeline: ${code('_tools/build.cjs')}, ${code('_tools/themes.cjs')}, ${code('_tools/base.css')}, ${code('_tools/light-base.css')}, diez CSS específicos, ${code('_tools/light-docs.cjs')}, ${code('_tools/validate-static.cjs')} y ${code('_tools/validate.cjs')}.
- Los seis logos/assets originales se reutilizan sin modificación. No se modificó código de producción ni se ejecutó commit.

${code('base.css')} se mantiene intacto. La nueva capa ${code('light-base.css')} define componentes comunes de la familia clara; cada variante ajusta retícula, navegación, superficies, radios y elevación. El editor comprueba el acento sobre fondo y hover para evitar que la personalización rompa el contraste.

## Validación y límites

El informe [VALIDACION.md](VALIDACION.md) contiene el conteo final y cada comprobación: ocho páginas por estilo, DOM, sintaxis JS, referencias relativas existentes, etiquetas, filtros, paginación, tenant, estados, diálogos, políticas, usuarios, marca y claves. En las cinco nuevas se verifica además tema claro, ausencia de elementos terminal/HUD y contraste mínimo de texto ≥4.5:1, incluyendo hover.

Las búsquedas de HTTP en ${code('src/href')} y de imports/URLs remotas en CSS deben devolver cero coincidencias. La validación DOM abre documentos locales, sin dependencias descargadas.

**Pendiente visual:** Chromium no arrancó por ${code('spawn EPERM')}. No se afirma inspección de layout real a 1440/390 px, zoom, foco nativo ni lector de pantalla. El CSS contempla esos tamaños y el recorrido Playwright está preparado. No se generaron capturas. La ausencia de revisión visual está registrada en [VALIDACION-NAVEGADOR.md](VALIDACION-NAVEGADOR.md).

## Regenerar y verificar

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/light-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
~~~

Los generadores no requieren dependencias. El verificador usa JSDOM existente, configurable mediante ${code('JSDOM_MODULE')}; solo pertenece al pipeline, no al HTML entregado.

Navegador real (Playwright Core existente, configurable con ${code('PLAYWRIGHT_MODULE')}):

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/validate.cjs flat-corporativo material-claro glassmorphism-claro minimal-lineas soft-cards
~~~

Sin argumentos, verifica las diez variantes. Si Chromium puede iniciarse, inspecciona 1440 × 1100 y 390 × 844 px, prueba interacciones y genera capturas dentro de las carpetas. Su informe es independiente del informe DOM.

Desde esta carpeta:

~~~powershell
rg -n '(src|href)\\s*=\\s*["\\x27](https?:)?//' flat-corporativo material-claro glassmorphism-claro minimal-lineas soft-cards -g '*.html'
rg -n '@import|url\\(\\s*["\\x27]?(https?:)?//' flat-corporativo material-claro glassmorphism-claro minimal-lineas soft-cards -g '*.css'
~~~

Sin commit por instrucción expresa. Todas las escrituras de esta ampliación están dentro de ${code('docs/design/2026-09-15-propuestas/')}.
`;
if(Object.values(themes).some(t=>t.art))require('./art-docs.cjs');else fs.writeFileSync(path.join(root,'README.md'),rootDoc);
console.log('README de la familia clara actualizados; catálogo según la selección vigente.');
