# Blueprint técnico — AZC Keeper v4

**Nueva propuesta; pendiente de selección.** Una lámina de arquitectura blanca que documenta equipos, alcance y revisiones.

[Abrir panorama](index.html) · [Abrir acceso](login.html) · [Índice y estado de las 15 propuestas](../README.md)

## Dirección de arte aplicada

### Retícula y jerarquía

Marco de lámina a 24 px del borde. Cabecera horizontal de proyecto, navegación y alcance. Registro de cuatro cifras encuadradas; dos detalles de operación en proporción 1.65:1. Cajetín final de seis celdas con contenido, revisión, empresa y corte. El fondo milimetrado de 24 px es gris muy tenue; campos, tablas y anotaciones tienen papel opaco.

### Motivo recurrente

Cota con extremos, llamada circular numerada y línea guía. Se repiten en los títulos, conectividad, incidencias, esquema del equipo, impacto, acceso y confirmación. El cajetín aparece en las siete páginas de trabajo.

### Lenguaje de componentes

Filas y celdas como registro técnico, tipografía monoespaciada solo donde facilita comparar identificadores. Specs en cuadrícula de pares etiqueta/valor, campos con límites definidos, vista de marca punteada como área de colocación. Diálogo con doble filete, sin relieve. Estado vacío mediante círculo de referencia.

El dashboard tiene una composición HTML propia: `.drawing-dimension, .blueprint-register, .drawing-callout, .drawing-title-block`. Las siete páginas de trabajo comparten número de sección, título, nota contextual y folio. Se reutilizan controles y datos para comparar los mismos flujos; la dirección de arte cambia su organización y tratamiento. No se limita a reemplazar un color sobre el dashboard anterior.

## Tokens de diseño

| Token | Valor / uso |
|---|---|
| `--shield` | #9A9A9A · escudo y gráficos; no texto informativo |
| `--wordmark` / `--ink` | #303030 · marca y texto principal |
| `--brand-white` | #F8F8F8 · blanco de marca |
| `--surface` | #F8F8F8 · fondo base |
| `--hover` | #ECECEC · interacción y superficie mínima |
| `--muted` | #555555 · texto secundario y placeholders |
| `--accent` | #275B80 · tinta única |
| `--accent-ink` | #F8F8F8 · texto sobre tinta |

**Acento:** Azul de delineación #275B80. Se usa como tinta de anotación, cotas y prioridad, sobre papel blanco. No hay paneles azules ni fondos oscuros.

**Tipografía y fallbacks de sistema:** Títulos: Bahnschrift, 'Arial Narrow', Arial, sans-serif. Texto: 'Segoe UI', Arial, sans-serif. IDs, cotas, fechas y cifras: Consolas, 'Courier New', monospace. No se solicitan fuentes externas. El fallback puede variar métricas entre sistemas; títulos fluidos y puntos de corte limitan ese efecto.

**Escala, espaciado y geometría:** Escala: 9, 10, 11, 15, 25, 46, 64, 69 px. Espaciado: 8, 12, 18, 24, 28, 32, 38 px. Papel #FFFFFF; cuadrícula #F0F1F2; líneas #BFC7CC, campos #697782. Radios 0 px; llamadas 50 %. Sombras none.

**Responsive:** A 1440 px: marco, cuatro cifras y dos detalles en paralelo. A 390 px: marco de 10 px, margen interior de 16 px, registro 2×2, detalles apilados y cajetín de dos columnas. El número de referencia sigue junto al título; las tablas conservan scroll local. A 800/900 px se reorganizan columnas de contenido. Las tablas tienen scroll horizontal propio con nombre accesible y foco por teclado; no se eliminan columnas. Las áreas táctiles no dependen de hover.

## Estados y accesibilidad

- Prioridad con palabra y símbolo, además de tinta: crítico destacado, alto con triángulo/contorno y OK neutro con verificación.
- Mínimo entre los pares de texto comprobados: **6.14:1**. Texto principal, secundario, tinta, texto sobre tinta y hover superan 4.5:1. No se aplica opacidad a texto informativo.
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
  --accent: #275B80;
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
- [styles.css](styles.css) — CSS local compilado de base.css + art-base.css + blueprint-tecnico.css.
- [README.md](README.md) — este documento.

Mismo conjunto ficticio: 180 equipos, 160 personas, 124 equipos de Grupo AZC y 56 de Atlas Consultores, más Nueva empresa vacía. Corte fijo del 15 de septiembre de 2026, 09:42 COT. No se realizan comandos, correo, autenticación ni llamadas API reales.

Abrir cualquier HTML directamente desde disco. JavaScript está incluido en cada archivo; el único CSS enlazado es styles.css. Se distribuye la carpeta junto a assets/ para mantener imágenes y comparación entre propuestas. Sin instalación, servidor, CDN ni framework.

## Generación y validación

El pipeline [build.cjs](../_tools/build.cjs) reutiliza [base.css](../_tools/base.css), añade [art-base.css](../_tools/art-base.css) y el CSS de esta dirección. [art-layouts.cjs](../_tools/art-layouts.cjs) compone las cinco estructuras; [art-docs.cjs](../_tools/art-docs.cjs) actualiza documentación y selección. Fuentes solo bajo la carpeta autorizada.

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/art-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
node docs/design/2026-09-15-propuestas/_tools/validate.cjs blueprint-tecnico
~~~

Los HTML entregados no dependen de Node. JSDOM y Playwright son herramientas de validación existentes del entorno; rutas configurables con JSDOM_MODULE y PLAYWRIGHT_MODULE. No se descargaron dependencias.
