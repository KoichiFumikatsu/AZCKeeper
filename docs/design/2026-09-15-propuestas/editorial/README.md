# Editorial — Cuaderno de operaciones

[Abrir dashboard](index.html) · [Acceso](login.html) · [Estados](states.html) · [Comparar industrial](../industrial/index.html)

## Fundamentos aplicados

La estructura toma recursos del informe editorial: masthead, cabecera con volumen/fecha, folio, retícula alineada, márgenes amplios y secciones separadas por líneas finas. Los títulos serif crean jerarquía; datos y controles sans mantienen precisión. Los números principales no dependen de tarjetas de color: aparecen en una banda continua con divisiones de 1 px.

La escala de títulos llega a 82 px y contrasta con etiquetas discretas. En dashboard, conectividad ocupa más ancho que la columna de alertas; la lista de eventos se lee como un registro al pie. El gráfico usa barras estrechas y el indicador circular líneas de 3 px. Botones y formularios tienen bordes finos; no hay elevaciones, gradientes ni radios ornamentales.

**Un acento: azul tinta #244AB8.** Funciona como una anotación del operador sobre el informe: señala riesgo, foco y acciones confirmables. El resto permanece en el eje gris/carbón/blanco. Es distinto del azul petróleo suave del neomorfismo y evita recuperar el navy histórico.

| Estado | Lenguaje visual |
|---|---|
| Crítico | `! Crítico`, recuadro de tinta con texto blanco |
| Alto | `△ Alto`, tinta y contorno discontinuo |
| OK | `✓ OK`, carbón y contorno neutro |

La tipografía y posición establecen la jerarquía habitual; el color se reserva a lo que requiere decisión. Los estados siempre se pueden leer sin interpretar el matiz.

## Tokens

| Token / propiedad | Valor |
|---|---|
| Marca | `--shield: #9A9A9A`, `--ink: #303030`, `--white: #F8F8F8` |
| Fondo / secundario | `--surface: #F8F8F8`, `--muted: #595959` |
| Acento | `--accent: #244AB8`, `--accent-ink: #F8F8F8` |
| Líneas / campos | `--line: #ABABAB`, `--control-line: #777777` |
| Hover / selección | `--hover: #EAEAEA`, `--active: #F8F8F8` |
| Títulos serif | `--font-display: Georgia, 'Times New Roman', serif` |
| Datos y controles | `--font-body: 'Segoe UI', Arial, sans-serif` |
| Metadatos | `--font-mono: Consolas, 'Courier New', monospace` |
| Escala | Metadatos 9–11 px, datos 12–15 px, títulos 27–82 px, cifras 45–60 px |
| Espaciado | Base 4/8/12/16/24/32/48 px; márgenes escritorio 58 px, móvil 22 px; separación de columnas 48–52 px |
| Radios | Paneles, controles y badges: `0` |
| Bordes | 1 px; retícula y banda de KPIs carbón, divisiones menores grises |
| Sombras | `--panel-shadow`, `--control-shadow`, `--inset-shadow`: `none` |

Contrastes sRGB: principal **12.43:1**, secundario **6.60:1**, texto sobre tinta **7.20:1**, tinta sobre fondo **7.20:1**, secundario sobre hover **5.82:1**. Gris #9A9A9A reservado al escudo y gráficos, nunca a texto pequeño informativo.

## Componentes y ocho páginas

| Archivo | Flujo |
|---|---|
| [login.html](login.html) | Portada serif y formulario corporativo con selector de empresa |
| [index.html](index.html) | Informe de flota con KPIs, presencia, alertas y eventos |
| [devices.html](devices.html) | Inventario con filtros, datos completos y comandos por fila |
| [device.html](device.html) | Ficha del activo, specs, políticas, cronología y comandos |
| [policies.html](policies.html) | Protección web/aplicaciones, horario y alcance con resumen de impacto |
| [users.html](users.html) | Directorio de personas, equipos y permisos |
| [settings.html](settings.html) | Identidad por empresa, contraste y credenciales ficticias |
| [states.html](states.html) | Vacío, carga, error y confirmación destructiva |

Componentes propios: masthead, cabecera de informe, folio, banda de cifras, columna de incidencias y ficha editorial. Componentes compartidos: tablas semánticas, filtros, paginación, campos etiquetados, estados anunciados, diálogo con destino/empresa/motivo y confirmación escrita.

## Parametrización por tenant

```css
html[data-tenant="azc"] {
  --surface: #F8F8F8;
  --ink: #303030;
  --muted: #595959;
  --accent: #244AB8;
  --accent-ink: #F8F8F8;
}
```

El tema vive en variables raíz y se genera desde `_tools/themes.cjs` junto con el resto de propuestas. El nombre y logo son contenido validado; la retícula y tipografía conservan continuidad aunque cambie la empresa. La personalización usa un único acento. El editor mantiene la superficie fija y comprueba contraste sobre botón y texto de acento antes de aplicar.

El selector transporta `tenant` mediante links relativos. Marca, política, edición de accesos y claves permanecen solo en la página abierta; no hay persistencia al recargar/navegar. La vista agregada no permite editar una marca sin elegir destino. Nada de esto reemplaza el aislamiento por tenant del servidor futuro.

## Responsive, datos y validación

1440 px: márgenes amplios de 58 px, navegación en masthead, títulos de escala editorial y KPIs en cuatro columnas. 390 px: márgenes de 22 px, cabecera compacta, títulos de 49 px, dos KPIs por fila y secciones apiladas. La navegación y las tablas desplazan localmente; versión y última conexión se conservan. El espacio en blanco separa tareas, sin esconder datos operativos.

Los formularios y atajos nativos son HTML estándar. Se incluye foco visible de 3 px, salto al contenido, `aria-current`, badges textuales, colores forzados y movimiento reducido. Las líneas finas no son el único indicador de un control: hay etiqueta, borde y foco.

180 equipos y 160 usuarios ficticios, 2 empresas con inventario y otra vacía, corte 15 sep 2026 09:42 COT. Dataset y flujos equivalentes a los otros estilos. Specs y eventos son fixtures; el panel no autentica ni ejecuta comandos. Publicación y comando pendiente no equivalen a aplicación confirmada. Borrado remoto e integraciones por API key son propuestas v4.

Abrir los HTML directamente, conservando la carpeta común de assets. Cada propuesta tiene un único CSS; scripts inline y fuentes de sistema. Reutiliza `_tools/base.css`, generador y validadores. Sin servidor, dependencias descargadas, frameworks ni CDNs.

[VALIDACION.md](../VALIDACION.md) contiene comprobaciones de estructura, navegación, DOM y contraste. **La apertura visual a 1440/390 px no pudo verificarse porque Chromium falló con `spawn EPERM`.** El CSS responde a esos tamaños, pero no se afirma revisión visual, lector de pantalla ni conformidad WCAG completa. La prueba Playwright está preparada para un entorno que permita iniciarla.
