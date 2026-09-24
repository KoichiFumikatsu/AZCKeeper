# Swiss datos — AZC Keeper v4

**Nueva propuesta; pendiente de selección.** Un cartel de datos que muestra su retícula y convierte la cifra en jerarquía.

[Abrir panorama](index.html) · [Abrir acceso](login.html) · [Índice y estado de las 15 propuestas](../README.md)

## Dirección de arte aplicada

### Retícula y jerarquía

Cabecera y título compartimentados en módulos 24 % / 53 % / 23 %. El tablero ocupa una matriz continua 2:1 con líneas de 1 px visibles; el total llega a 260 px y enfrenta tres registros. Conectividad y alertas son celdas de la misma matriz. Eventos ocupan el ancho completo. Las tablas y formularios prolongan esos ejes.

### Motivo recurrente

Celda visible, número de sección de gran escala y alineación izquierda constante. Se repiten en cabecera, datos, especificaciones, editor, gráfico, acceso y diálogo. El espacio se divide; no se envuelven datos en tarjetas con separación y sombras.

### Lenguaje de componentes

Grotesca en pesos 400/700, campos cuadrados, botones compactos con objetivo táctil de al menos 44 px salvo acciones de tabla de 40 px. Especificaciones cuadriculadas, editor e impacto adyacentes, filas con divisiones verticales. Modal dividido en título, contenido y acciones por filetes. El vacío usa símbolo tipográfico dominante.

El dashboard tiene una composición HTML propia: `.swiss-matrix, .swiss-primary, .swiss-secondary, .matrix-label`. Las siete páginas de trabajo comparten número de sección, título, nota contextual y folio. Se reutilizan controles y datos para comparar los mismos flujos; la dirección de arte cambia su organización y tratamiento. No se limita a reemplazar un color sobre el dashboard anterior.

## Tokens de diseño

| Token | Valor / uso |
|---|---|
| `--shield` | #9A9A9A · escudo y gráficos; no texto informativo |
| `--wordmark` / `--ink` | #303030 · marca y texto principal |
| `--brand-white` | #F8F8F8 · blanco de marca |
| `--surface` | #F8F8F8 · fondo base |
| `--hover` | #EAEAEA · interacción y superficie mínima |
| `--muted` | #555555 · texto secundario y placeholders |
| `--accent` | #B1242A · tinta única |
| `--accent-ink` | #F8F8F8 · texto sobre tinta |

**Acento:** Rojo suizo #B1242A. Marca prioridad y folios dentro de una composición principalmente blanca y carbón. Su oscuridad permite usarlo también en texto pequeño.

**Tipografía y fallbacks de sistema:** Display y texto: Helvetica, Arial, sans-serif. Identificadores: Consolas, 'Courier New', monospace. Cifras con font-variant-numeric: tabular-nums. No se solicitan fuentes externas. El fallback puede variar métricas entre sistemas; títulos fluidos y puntos de corte limitan ese efecto.

**Escala, espaciado y geometría:** Escala: 10, 11, 14, 27, 37, 65, 82, 115, 170, 260 px. Espaciado: 8, 14, 20, 24, 26, 38 px. Papel #FFFFFF; retícula #9A9A9A, campos #707070. Radio 0 px. Borde 1 px. Sombras none.

**Responsive:** A 1440 px: título en tres módulos y cifra total de hasta 260 px. A 390 px: folio en una columna de 52 px, título de 45 px, total de 150 px y registros apilados; retícula permanece visible. El informe de eventos y las tablas conservan el orden de lectura y los datos. A 800/900 px se reorganizan columnas de contenido. Las tablas tienen scroll horizontal propio con nombre accesible y foco por teclado; no se eliminan columnas. Las áreas táctiles no dependen de hover.

## Estados y accesibilidad

- Prioridad con palabra y símbolo, además de tinta: crítico destacado, alto con triángulo/contorno y OK neutro con verificación.
- Mínimo entre los pares de texto comprobados: **5.53:1**. Texto principal, secundario, tinta, texto sobre tinta y hover superan 4.5:1. No se aplica opacidad a texto informativo.
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
  --hover: #EAEAEA;
  --accent: #B1242A;
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
- [styles.css](styles.css) — CSS local compilado de base.css + art-base.css + swiss-datos.css.
- [README.md](README.md) — este documento.

Mismo conjunto ficticio: 180 equipos, 160 personas, 124 equipos de Grupo AZC y 56 de Atlas Consultores, más Nueva empresa vacía. Corte fijo del 15 de septiembre de 2026, 09:42 COT. No se realizan comandos, correo, autenticación ni llamadas API reales.

Abrir cualquier HTML directamente desde disco. JavaScript está incluido en cada archivo; el único CSS enlazado es styles.css. Se distribuye la carpeta junto a assets/ para mantener imágenes y comparación entre propuestas. Sin instalación, servidor, CDN ni framework.

## Generación y validación

El pipeline [build.cjs](../_tools/build.cjs) reutiliza [base.css](../_tools/base.css), añade [art-base.css](../_tools/art-base.css) y el CSS de esta dirección. [art-layouts.cjs](../_tools/art-layouts.cjs) compone las cinco estructuras; [art-docs.cjs](../_tools/art-docs.cjs) actualiza documentación y selección. Fuentes solo bajo la carpeta autorizada.

~~~powershell
node docs/design/2026-09-15-propuestas/_tools/build.cjs
node docs/design/2026-09-15-propuestas/_tools/art-docs.cjs
node docs/design/2026-09-15-propuestas/_tools/validate-static.cjs
node docs/design/2026-09-15-propuestas/_tools/validate.cjs swiss-datos
~~~

Los HTML entregados no dependen de Node. JSDOM y Playwright son herramientas de validación existentes del entorno; rutas configurables con JSDOM_MODULE y PLAYWRIGHT_MODULE. No se descargaron dependencias.
