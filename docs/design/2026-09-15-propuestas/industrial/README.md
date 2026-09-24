# Industrial — Centro de operaciones KPR—04

[Abrir dashboard](index.html) · [Acceso](login.html) · [Estados](states.html) · [Comparar brutalismo](../brutalista/index.html)

## Fundamentos aplicados

Una consola de instrumentos físicos: rail superior con corte y operador, placas numeradas de módulo, sidebar estrecha, paneles con cantos, tornillos tipográficos discretos, cifras condensadas, indicadores prominentes y medidor circular graduado. Los controles se separan visualmente de las lecturas; las acciones remotas se presentan como una botonera con destinatario explícito.

El carbón #303030 es la superficie principal. Los receptáculos #242424 y las sombras duras de 2–3 px producen profundidad mecánica. Es distinto del fondo casi negro monoespaciado de terminal y del relieve difuso del neomorfismo. Títulos condensados y etiquetas técnicas en mayúsculas; descripciones y datos conservan sans normal para legibilidad.

**Un acento: amarillo señal #FFD24A.** Identifica instrumentos de atención, foco y acciones primarias sobre carbón. El contraste facilita escanear una consola densa. No hay RGB, glow, gradientes luminosos, animaciones ni retículas decorativas de combate.

| Estado | Lenguaje visual |
|---|---|
| Crítico | `! CRÍTICO`, amarillo sólido con texto carbón y doble perímetro |
| Alto | `△ ALTO`, texto amarillo y contorno discontinuo |
| OK | `✓ OK`, blanco y borde gris |

Los símbolos, palabras y tipo de contorno permiten distinguir riesgo sin depender del amarillo. «Corte válido» describe el snapshot ficticio, no salud o disponibilidad de un servicio real.

## Tokens

| Token / propiedad | Valor |
|---|---|
| Marca | `--shield: #9A9A9A`, `--brand-charcoal: #303030`, `--white: #F8F8F8` |
| Fondo / texto | `--surface: #303030`, `--ink: #F8F8F8`, `--muted: #C1C1C1` |
| Receptáculo / sombra | `#242424` / `#202020` y `#171717`; derivados neutros del carbón |
| Acento | `--accent: #FFD24A`, `--accent-ink: #303030` |
| Línea / campo | `--line: #737373`, `--control-line: #A0A0A0` |
| Hover / selección | `--hover: #404040`, `--active: #242424` |
| Condensada | `--font-display: 'Bahnschrift Condensed', 'Arial Narrow', Impact, sans-serif` |
| Datos y descripciones | `--font-body: 'Segoe UI', Arial, sans-serif` |
| Etiquetas / referencias | `--font-mono: 'Cascadia Mono', Consolas, 'Courier New', monospace` |
| Escala | Etiquetas 9–11 px, datos 12–14 px, títulos 27–70 px, lecturas 49–82 px |
| Espaciado | Base 4/8/12/16/24/32 px; paneles 24 px escritorio y 21/17 px móvil; gap 18–26 px |
| Radios | `--radius-panel: 3px`, `--radius-control: 2px`, `--radius-badge: 2px` |
| Bordes | `--border: 1px solid #737373`; cantos superiores de 3 px |
| Paneles | `--panel-shadow: 0 3px 0 #202020` |
| Controles | `--control-shadow: 0 2px 0 #202020` |
| Hundido | `--inset-shadow: inset 0 2px 0 #202020` |

Contraste: principal **12.43:1**, secundario **7.33:1**, carbón sobre amarillo **9.16:1**, amarillo sobre carbón **9.16:1**, secundario sobre hover **5.76:1**. Los receptáculos oscuros aumentan la relación de contraste frente a esos valores.

## Componentes y ocho páginas

| Archivo | Flujo |
|---|---|
| [login.html](login.html) | Placa de acceso y formulario de operador |
| [index.html](index.html) | Instrumentos KPI, disponibilidad, gráfico, prioridades y registro de eventos |
| [devices.html](devices.html) | Inventario técnico con filtros y comandos de cada unidad |
| [device.html](device.html) | Hardware, asignación, revisiones, cronología y botonera remota |
| [policies.html](policies.html) | Bloqueo web/descarga/instalación, jornada, alcance y revisión |
| [users.html](users.html) | Personal, equipos asignados, roles e invitaciones ficticias |
| [settings.html](settings.html) | Identidad por tenant, preview de color/logo y claves API ficticias |
| [states.html](states.html) | Vacío, carga, fallo y confirmación destructiva |

Componentes propios: rail, placa de módulo, instrumento KPI, medidor graduado, indicador de corte, panel de comandos y canto de prioridad. Componentes compartidos: navegación, formularios, filtros, paginación, tabla accesible, mensajes de estado y diálogo nativo con motivo/destinatario/confirmación escrita.

## Parametrización por tenant

```css
html[data-tenant="atlas"] {
  --surface: #303030;
  --ink: #F8F8F8;
  --muted: #C1C1C1;
  --accent: #FFD24A;
  --accent-ink: #303030;
}
```

Variables raíz para el tema, nombre/logo como contenido. `_tools/themes.cjs` define los defaults y el logo blanco de fallback. La edición mantiene fija la familia de superficies; cada empresa puede cambiar un acento y su texto, con umbral obligatorio de 4.5:1 para botón y acento sobre superficie. El logo local acepta PNG/JPG/WebP de hasta 2 MB.

Los enlaces transportan `?tenant=all|azc|atlas|nueva`. El alcance aparece junto al módulo, en filas y en confirmaciones. Se exige empresa concreta para branding y claves. Las ediciones se conservan solo mientras la página siga abierta y no modifican backend ni implementan autorización.

## Responsive, datos y validación

En 1440 px: sidebar de instrumentos de 126 px, cuatro lecturas, medidor de 184 px y alertas en columna propia. En 390 px: menú horizontal, rail apilado, KPIs 2×2, medidor de 135 px y paneles en una columna. El inventario mantiene todos sus campos con desplazamiento local. Objetivos de interacción principales de 43 px o más; comandos compactos de 38 px.

Los controles y campos tienen borde visible, foco de 3 px y texto explícito. Se incluyen `aria-current`, salto al contenido, anuncios de estado, etiquetas de campos, colores forzados y movimiento reducido. Los detalles de instrumento nunca son el único medio para identificar un control.

Mismos 180 equipos, 160 usuarios, 2 empresas y una empresa vacía que las otras propuestas; corte 15 sep 2026 09:42 COT. Datos, hardware, eventos y credenciales ficticios. Comandos simulados en cola, pendientes de agente; no hay ejecución real ni API. El borrado remoto de datos es una propuesta v4 y no debe confundirse con eliminar registros K3.

HTML estático abrible por doble clic, CSS único, JavaScript inline y recursos relativos a `../assets/`. Reutiliza `_tools/base.css`, generador y validación existentes. No usa frameworks, CDNs ni fuentes de red.

Ver [VALIDACION.md](../VALIDACION.md). Estructura, flujos DOM y contraste comprobados. **La inspección visual en 1440/390 px está pendiente: Chromium volvió a fallar con `spawn EPERM`.** El script de navegador cubre esos tamaños y queda listo para ejecutarse cuando el entorno lo permita; no se afirma auditoría completa de accesibilidad.
