# Bauhaus geométrico — AZC Keeper v4

**Nueva propuesta; pendiente de selección.** Una composición de cartel donde la forma clasifica y el tamaño ordena.

[Abrir panorama](index.html) · [Abrir acceso](login.html) · [Índice y estado de las 15 propuestas](../README.md)

## Dirección de arte aplicada

### Retícula y jerarquía

Índice blanco estrecho de 190 px; lienzo principal asimétrico en proporción 1.5:1. El total de equipos ocupa un bloque dominante con una circunferencia recortada. En la columna contigua, tres registros apilados asignan escalas distintas a disponibilidad, prioridad y versión. La segunda franja enfrenta conectividad e incidencias.

### Motivo recurrente

Círculo = estado normal, cuadrado = crítico, triángulo = alto. Las formas se repiten en la marca de sección, leyenda, etiquetas de prioridad, registro numérico y acceso. Las palabras acompañan siempre la forma. El cuadrado crítico es sólido incluso sin color.

### Lenguaje de componentes

Tipografía de peso alto, títulos breves y numeración de índice. Botones rectangulares, campos geométricos, estado normal circular y sección de impacto con banda de tinta. Las tablas mantienen filas ordenadas; no se dibujan decoraciones dentro de las celdas de datos. El diálogo adopta la misma banda de impacto.

El dashboard tiene una composición HTML propia: `.bauhaus-poster, .poster-circle, .bauhaus-stack, .shape-legend`. Las siete páginas de trabajo comparten número de sección, título, nota contextual y folio. Se reutilizan controles y datos para comparar los mismos flujos; la dirección de arte cambia su organización y tratamiento. No se limita a reemplazar un color sobre el dashboard anterior.

## Tokens de diseño

| Token | Valor / uso |
|---|---|
| `--shield` | #9A9A9A · escudo y gráficos; no texto informativo |
| `--wordmark` / `--ink` | #303030 · marca y texto principal |
| `--brand-white` | #F8F8F8 · blanco de marca |
| `--surface` | #F8F8F8 · fondo base |
| `--hover` | #ECECEC · interacción y superficie mínima |
| `--muted` | #555555 · texto secundario y placeholders |
| `--accent` | #B02E22 · tinta única |
| `--accent-ink` | #F8F8F8 · texto sobre tinta |

**Acento:** Rojo óxido #B02E22. Conserva la energía de un primario de cartel con profundidad suficiente para texto pequeño y un contexto corporativo.

**Tipografía y fallbacks de sistema:** Display: 'Arial Black', Arial, Helvetica, sans-serif. Texto: Arial, Helvetica, sans-serif. Índices auxiliares: Consolas, 'Courier New', monospace. No se solicitan fuentes externas. El fallback puede variar métricas entre sistemas; títulos fluidos y puntos de corte limitan ese efecto.

**Escala, espaciado y geometría:** Escala: 11, 13, 18, 24, 40, 54, 80 y 170 px. Espaciado: 8, 12, 18, 22, 28, 36 px. Filetes #BEBEBE, divisiones principales #303030 de 1 px. Campos #777777. Radios 0 px salvo círculos semánticos. Sombras none.

**Responsive:** A 1440 px: índice lateral blanco y cartel de dos columnas. A 390 px: índice pasa arriba, total domina una franja y los tres registros se apilan con cifra a la derecha. Circunferencia recortada confinada a su bloque. No se ocultan ni las prioridades ni las acciones remotas. A 800/900 px se reorganizan columnas de contenido. Las tablas tienen scroll horizontal propio con nombre accesible y foco por teclado; no se eliminan columnas. Las áreas táctiles no dependen de hover.

## Estados y accesibilidad

- Prioridad con palabra y símbolo, además de tinta: crítico destacado, alto con triángulo/contorno y OK neutro con verificación y círculo; el cuadrado sólido identifica crítico.
- Mínimo entre los pares de texto comprobados: **5.45:1**. Texto principal, secundario, tinta, texto sobre tinta y hover superan 4.5:1. No se aplica opacidad a texto informativo.
- Foco visible de 3 px y separación de 4 px. Botones con foco carbón sobre superficies claras. Enlaces y campos con foco de la tinta de acento.
- Etiquetas persistentes, un h1 por documento, enlace para saltar al contenido, regiones de estado y controles nativos. Movimiento reducido y colores forzados contemplados en base.css.
- Borrar exige empresa, equipo, motivo y hostname escrito; el foco inicial va a Cancelar. El comando queda «En cola», separado de una ejecución confirmada.
- Error de inventario permite reintentar, carga se anuncia y vacío ofrece salida. `states.html` enlaza escenarios reproducibles.

El DOM y los callbacks están verificados. **Inspección visual en 1440/390 px y comportamiento nativo de foco/diálogo pendientes:** Chromium no inicia por `spawn EPERM`. No equivale a una certificación WCAG. Ver [VALIDACION.md](../VALIDACION.md) y [VALIDACION-NAVEGADOR.md](../VALIDACION-NAVEGADOR.md).

## Parametrización por tenant

El selector conserva la empresa en los enlaces relativos mediante `?tenant=azc`, `?tenant=atlas` y `?tenant=nueva`; `all` agrega la flota. En Configuración se elige una empresa antes de editar. Nombre y logo son contenido; color y superficies son variables CSS.

~~~css
/* Ejemplo de contrato de presentación para una futura integración. */
:root[data-tenant="azc"] {
  --shield: #9A9A9A;
  --wordmark: #303030;
  --brand-white: #F8F8F8;
  --ink: #303030;
  --muted: #555555;
  --surface: #F8F8F8;
  --hover: #ECECEC;
  --accent: #B02E22;
  --accent-ink: #F8F8F8;
}
~~~

El prototipo aplica el acento con `document.documentElement.style.setProperty` y actualiza los nodos del nombre y logo. El ejemplo con `data-tenant` describe cómo integrarlo; no supone que ese atributo se persista hoy. Configuración comprueba contraste de botón y tinta sobre fondo/hover antes de permitir aplicar. Mantener una sola tinta por empresa. No cambiar la retícula, escala ni significado de las formas al personalizar.

Se puede seleccionar un logo local PNG/JPG/WebP de hasta 2 MB. Su vista previa usa FileReader, sin subida ni peticiones. La base reutiliza `../assets/logo_main.png` y `../assets/favicon.ico`. Las ediciones de marca, usuarios, claves y políticas se pierden al navegar o recargar; el contexto de empresa sí viaja en la URL. El prototipo no implementa autorización ni persistencia multiempresa.

## Ocho páginas y archivos

- [login.html](login.html) — Acceso con empresa y ayuda de credenciales.
- [index.html](index.html) — Flota multiempresa, KPIs, críticos, conexión e historial horario y de eventos.
- [devices.html](devices.html) — Búsqueda y filtros por conexión, prioridad y versión; paginación y comandos.
- [device.html](device.html) — Specs, versiones, última conexión, políticas confirmadas/pendientes, cronología y comandos.
- [policies.html](policies.html) — Dominios, descargas/instalación, días/horario y alcance global/empresa/usuario/equipo.
- [users.html](users.html) — Personas, equipos asignados, filtros, paginación y edición de acceso.
- [settings.html](settings.html) — Marca por tenant, nombre/logo/acento, contraste y claves API ficticias.
- [states.html](states.html) — Vacío, cargando, error con reintento y confirmación destructiva reproducibles.
- [styles.css](styles.css) — CSS local compilado de base.css + art-base.css + bauhaus-geometrico.css.
- [README.md](README.md) — este documento.

Mismo conjunto ficticio: 180 equipos, 160 personas, 124 equipos de Grupo AZC y 56 de Atlas Consultores, más Nueva empresa vacía. Corte fijo del 15 de septiembre de 2026, 09:42 COT. No se realizan comandos, correo, autenticación ni llamadas API reales.

Abrir cualquier HTML directamente desde disco. JavaScript está incluido en cada archivo; el único CSS enlazado es styles.css. Se distribuye la carpeta junto a assets/ para mantener imágenes y comparación entre propuestas. Sin instalación, servidor, CDN ni framework.

## Generación y validación

El pipeline [build.cjs](../_tools/build.cjs) reutiliza [base.css](../_tools/base.css), añade [art-base.css](../_tools/art-base.css) y el CSS de esta dirección. [art-layouts.cjs](../_tools/art-layouts.cjs) compone las cinco estructuras; [art-docs.cjs](../_tools/art-docs.cjs) actualiza documentación y selección. Fuentes solo bajo la carpeta autorizada.

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/art-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
node docs/design/2026-09-15-propuestas/_tools/validate.cjs bauhaus-geometrico
~~~

Los HTML entregados no dependen de Node. JSDOM y Playwright son herramientas de validación existentes del entorno; rutas configurables con JSDOM_MODULE y PLAYWRIGHT_MODULE. No se descargaron dependencias.
