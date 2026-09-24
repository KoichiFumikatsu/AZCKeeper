# 01 — Brutalismo / Control con criterio

Abrir [index.html](index.html). Acceso: [login.html](login.html). Comparación: [neomorfismo](../neomorfismo/index.html).

## Fundamentos aplicados

La cuadrícula funciona como una hoja de operaciones: navegación horizontal numerada, líneas carbón de 2–3 px, cifras monoespaciadas grandes y encabezados grotescos de hasta 76 px. Las alertas forman una columna de trabajo junto a conectividad; la tabla conserva sus columnas y desplaza horizontalmente en pantallas estrechas.

No hay gradientes, sombras ni bordes redondeados. Los botones tienen texto y áreas definidas; la densidad está en la tabla, no en las etiquetas. La presencia de flota se presenta como un indicador rectangular, coherente con el resto de la composición. El logotipo original conserva proporción y color.

**Un acento: rojo óxido #B52A19.** Hace visible la urgencia sobre blanco y carbón sin convertir cada métrica en una categoría cromática. No intenta recuperar la antigua paleta navy/rojo corporativa. Las acciones primarias usan ese acento; la categoría de riesgo siempre se escribe.

| Estado | Tratamiento |
|---|---|
| Crítico | Fondo de acento, texto blanco e indicador `!` |
| Alto | Contorno discontinuo y texto de acento, indicador `△` |
| OK | Carbón sobre la superficie, contorno continuo e indicador `✓` |

## Tokens

| Token | Valor / uso |
|---|---|
| `--shield` | `#9A9A9A`; escudo y elementos gráficos |
| `--ink` | `#303030`; texto y estructura |
| `--white`, `--surface` | `#F8F8F8`; fondo |
| `--muted` | `#595959`; texto secundario |
| `--line` | `#B8B8B8`; divisiones de menor jerarquía |
| `--control-line` | `#303030`; límites de inputs |
| `--accent` / `--accent-ink` | `#B52A19` / `#F8F8F8` |
| `--hover` / `--active` | `#E8E8E8` / `#303030` |
| `--font-body`, `--font-display` | `Arial, Helvetica, sans-serif` |
| `--font-mono` | `'Cascadia Mono', Consolas, 'Courier New', monospace` |
| Espaciado | Escala principal 4, 8, 12, 16, 24, 32, 40 px; ajustes de composición de 18, 20, 26, 28, 38 px |
| Tipos | Etiquetas 10–12 px; contenido 13–15 px; títulos 23–76 px; cifras 48–70 px |
| Radios | `--radius-panel`, `--radius-control`, `--radius-badge`: `0` |
| Bordes | `--border: 2px solid #303030`; separaciones principales de 3 px |
| Sombras | `--panel-shadow`, `--control-shadow`, `--inset-shadow`: `none` |

Contraste calculado: texto principal 12.43:1; secundario 6.60:1; blanco sobre acento 5.97:1. El gris del escudo no se utiliza para texto pequeño. Ver [validación](../VALIDACION.md).

## Componentes y páginas

| Archivo | Componentes / interacción |
|---|---|
| [login.html](login.html) | Identidad, empresa, correo, contraseña visible/oculta, ayuda y acceso simulado |
| [index.html](index.html) | Selector de empresa, cuatro KPIs, presencia, histórico por hora, alertas críticas y eventos |
| [devices.html](devices.html) | Búsqueda, filtros por conexión/prioridad/versión, 8 filas por página, comandos por equipo |
| [device.html](device.html) | Ficha de hardware, propietario, políticas confirmadas/pendientes, cronología y comandos |
| [policies.html](policies.html) | Editor de dominios, descargas, instalación, jornada, alcance y revisión de impacto |
| [users.html](users.html) | Directorio, 12 usuarios por página, equipos por persona, roles, acceso e invitación simulada |
| [settings.html](settings.html) | Marca por empresa, vista previa, comprobador de contraste y claves API ficticias |
| [states.html](states.html) | Vacío, carga, error y confirmación destructiva reproducibles |

Componentes transversales: navegación con `aria-current`, enlace para saltar al contenido, badge semántico, tabla con título accesible, formulario etiquetado, mensaje de estado, aviso de error, diálogo nativo y confirmación escrita para borrado. Conexión, prioridad y confirmación del agente son conceptos distintos.

## Parametrización por tenant

El tema se resuelve con variables CSS del elemento raíz. En una implementación futura, el servidor establecería una configuración validada para el tenant autenticado:

```css
html[data-tenant="azc"] {
  --accent: #B52A19;
  --accent-ink: #F8F8F8;
  --surface: #F8F8F8;
  --ink: #303030;
}
```

Nombre y logo son contenido, no CSS: se asignan a texto e imágenes. El panel y login comparten `styles.css`. El prototipo usa `?tenant=all|azc|atlas|nueva` para transportar la selección entre enlaces. La página de configuración requiere elegir una empresa; la vista agregada no modifica marcas.

La vista previa permite cambiar nombre, cargar logo PNG/JPG/WebP de hasta 2 MB y cambiar **un** acento. Se comprueban las relaciones texto/acento y acento/superficie antes de aplicar. El tema se aplica a la página abierta; **las ediciones no persisten al recargar ni navegar**. El selector de tenant sí viaja en la URL. El selector no constituye una frontera de autorización; el backend deberá imponerla.

## Alcance de la demostración

- 180 equipos ficticios: 124 Grupo AZC y 56 Atlas; una empresa nueva vacía. 160 usuarios; algunas personas tienen dos equipos.
- Corte fijo: 15 sep 2026, 09:42 COT. En línea = último contacto menor a 2 min; conexión no equivale a actividad ni cumplimiento.
- Hardware, políticas y eventos son fixtures de diseño, no telemetría ni contratos de API existentes.
- Bloquear/apagar/borrar abren un diálogo; al confirmar, se muestra **en cola**, no ejecución confirmada. Borrar significa borrado remoto de datos propuesto para v4.
- Formularios, invitaciones, API keys y publicación son simulaciones locales. No hay llamadas de red, backend, autenticación real ni persistencia.
- Las sombras nativas del navegador en menús del sistema no son parte del tema CSS.

## Uso y verificación

Abrir los HTML directamente con doble clic; no se necesita servidor, instalación ni Internet. CSS en un único archivo, JavaScript inline, fuentes del sistema y logos relativos en `../assets/`. Copiar la carpeta de propuestas completa para conservar los recursos compartidos.

Responsive definido en CSS: navegación con desplazamiento local en móvil, KPIs en dos columnas y secciones apiladas; las tablas no ocultan versión ni último contacto. Foco de 3 px, movimiento reducido y colores forzados incluidos.

La validación estática y DOM pasó. **La inspección visual de escritorio/móvil queda pendiente porque el entorno bloqueó Chromium/Chrome.** No se afirma conformidad WCAG completa; los contrastes de texto sí están calculados.
