# Terminal — Consola de operaciones

[Abrir dashboard](index.html) · [Acceso](login.html) · [Estados](states.html) · [Comparar editorial](../editorial/index.html)

## Fundamentos aplicados

La propuesta se organiza como una consola de trabajo: cabecera `operador@keeper`, ruta por empresa y módulo, prompt de consulta, navegación numerada, marcos con caracteres `┌─` y `─┘`, reglas discontinuas, tabla compacta y barra inferior `NORMAL`. La sintaxis de terminal identifica el contexto; las operaciones siguen siendo controles HTML accesibles.

Toda la tipografía es monoespaciada. Las cifras tienen ancho estable y las listas concentran más información por altura que las otras propuestas. El indicador de conectividad es una lectura rectangular etiquetada `[ CONTACTO < 2m ]`, no un medidor circular. No hay glow, animaciones CRT, textura de escaneo ni cursor parpadeante. El cursor fijo `▌` acompaña al prompt y no interfiere con la lectura.

**Un acento: verde fósforo #A7E66E.** Evoca las consolas de operaciones y mantiene contraste alto sobre carbón. Se reserva a prompt, foco, acción primaria y prioridad; el texto habitual es blanco/gris. El logotipo blanco proporcionado mantiene la marca legible en el fondo oscuro.

| Estado | Lenguaje visual |
|---|---|
| Crítico | `! Crítico`, fondo verde fósforo y texto carbón |
| Alto | `△ Alto`, texto verde y contorno discontinuo |
| OK | `✓ OK`, texto blanco y contorno gris |

El verde por sí solo no significa «todo correcto»: el nivel siempre lleva una palabra y un símbolo. Conexión, cumplimiento y confirmación de comandos se presentan como conceptos distintos.

## Tokens

| Token / propiedad | Valor |
|---|---|
| Marca original | `--shield: #9A9A9A`, `--brand-charcoal: #303030`, `--white: #F8F8F8` |
| Fondo | `--surface: #171717`; carbón oscurecido |
| Texto | `--ink: #F8F8F8`, `--muted: #B8B8B8` |
| Acento | `--accent: #A7E66E`, `--accent-ink: #303030` |
| Líneas / campos | `--line: #626262`, `--control-line: #858585` |
| Hover / selección | `--hover`, `--active`: `#303030` |
| Tipografía | `--font-body`, `--font-display`, `--font-mono`: `'Cascadia Mono', Consolas, 'Courier New', monospace` |
| Escala tipográfica | Contexto 9–12 px, datos 11–13 px, títulos 27–30 px, cifras 38–44 px |
| Espaciado | Base 4/8/12/16/24 px; paneles 19 px escritorio y 17/14 px móvil; separación 20–22 px |
| Radios | `--radius-panel`, `--radius-control`, `--radius-badge`: `0` |
| Bordes | `--border: 1px solid #626262`; cajas tipográficas y líneas discontinuas |
| Sombras | `--panel-shadow`, `--control-shadow`, `--inset-shadow`: `none` |

Contrastes sRGB: principal **16.88:1**, secundario **9.04:1**, carbón sobre fósforo **8.92:1**, fósforo sobre fondo **12.11:1**, secundario sobre hover **6.65:1**. Los marcos son secundarios; el foco usa el acento y no depende de ellos.

## Componentes y ocho páginas

| Archivo | Flujo |
|---|---|
| [login.html](login.html) | Prompt de acceso, empresa, correo, contraseña, mostrar/ocultar y ayuda |
| [index.html](index.html) | KPIs, disponibilidad, histórico horario, alertas y últimos eventos |
| [devices.html](devices.html) | Tabla TUI con búsqueda, conexión/prioridad/versión, paginación y comandos |
| [device.html](device.html) | Specs, usuario/empresa, políticas, cronología y control remoto |
| [policies.html](policies.html) | Dominios, bloqueo de descarga/instalación, jornada, alcance y publicación |
| [users.html](users.html) | Usuarios, varios equipos por persona, búsqueda, roles y acceso |
| [settings.html](settings.html) | Nombre/logo/acento por empresa, contraste, claves API ficticias |
| [states.html](states.html) | Vacío, cargando, error y confirmación destructiva reproducibles |

Componentes propios: prompt, breadcrumb de ruta, marco TUI, barra de estado, ayuda desplegable y atajos. Componentes compartidos: formularios etiquetados, tablas con encabezados, avisos, badges, paginación, diálogo nativo, confirmación escrita y retorno de foco.

### Atajos funcionales

- `Alt+1` a `Alt+5`: módulos de la navegación, en su orden visible.
- `/`: enfocar búsqueda en equipos/usuarios; en otro módulo, abrir Equipos con el tenant actual.
- `?`: abrir/cerrar la ayuda de teclado.
- `Esc`: cerrar un diálogo nativo sin confirmar.

Los atajos de la consola no se interceptan mientras se escribe en un campo ni dentro de un diálogo. En móvil están disponibles navegación, filtros y botones visibles; ninguna operación exige un teclado físico.

## Parametrización por tenant

```css
html[data-tenant="azc"] {
  --surface: #171717;
  --ink: #F8F8F8;
  --muted: #B8B8B8;
  --accent: #A7E66E;
  --accent-ink: #303030;
}
```

Las variables se aplican al elemento raíz. El nombre y el logo son contenido de texto/imagen; no se inyecta CSS arbitrario. Los valores iniciales de cada propuesta se declaran en `_tools/themes.cjs`. El color de fondo sigue fijo en el editor; se puede cambiar **un acento** y el color de texto sobre él.

La vista agregada exige elegir una empresa antes de cambiar marca o claves. La preview comprueba texto/acento y acento/superficie, y bloquea aplicar relaciones inferiores a 4.5:1. Los logos PNG/JPG/WebP se cargan localmente, con límite de 2 MB. La versión blanca del logo es el fallback oscuro.

`?tenant=all|azc|atlas|nueva` conserva la empresa entre enlaces relativos. Las ediciones de marca, políticas, accesos y claves son temporales; se pierden al recargar/navegar. El selector no implementa autorización multiempresa.

## Responsive, datos y validación

En 1440 px: sidebar de 194 px, cuatro KPIs, dos columnas de operación y tabla densa. En 390 px: navegación horizontal desplazable, dos KPIs por fila, paneles apilados, ruta con salto de línea y tabla con scroll **local**. No se ocultan versión ni último contacto. La densidad se reduce manteniendo controles táctiles de 36–40 px o más.

Mismos 180 equipos/160 usuarios ficticios que las otras propuestas, 124 equipos en Grupo AZC y 56 en Atlas, empresa nueva vacía y corte 15 sep 2026 09:42 COT. Specs/eventos son fixtures. Los comandos terminan en «En cola», pendientes del agente; ninguna acción llega a un equipo real. Borrar ilustra borrado remoto de datos propuesto para v4, distinto de eliminar el registro en K3.

Se abre por doble clic: HTML estático, `styles.css` único, JavaScript inline, fuentes del sistema y logos relativos. Reutiliza `_tools/base.css` y la validación existente. No requiere instalar ni descargar nada para explorarlo.

Validación estructural/DOM, recursos, interacciones, atajos y contrastes: [VALIDACION.md](../VALIDACION.md). El intento de Chromium volvió a fallar con `spawn EPERM`; **no se acredita inspección visual a 1440/390 px**, teclado nativo ni conformidad integral WCAG. Se incluye el recorrido Playwright para completar esa revisión en un entorno que permita iniciar navegador.
