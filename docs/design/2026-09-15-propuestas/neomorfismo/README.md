# 02 — Neomorfismo / Un espacio conectado

Abrir [index.html](index.html). Acceso: [login.html](login.html). Comparación: [brutalismo](../brutalista/index.html).

## Fundamentos aplicados

Fondo y paneles comparten #E4E4E4. La luz llega desde arriba a la izquierda: cada superficie elevada combina sombra clara y oscura; campos, navegación seleccionada e indicador de conectividad invierten esas sombras para parecer hundidos. Los radios amplios mantienen continuidad entre paneles, controles y el indicador circular.

La estructura cambia respecto del brutalismo: navegación lateral en escritorio, espacio entre paneles, cifras sin contenedores oscuros y una composición de lectura más pausada. Los elementos interactivos conservan texto oscuro, borde visible y foco independiente de las sombras. El escudo gris es una marca, no un sustituto de las etiquetas.

**Un acento: azul petróleo #245D68.** Se integra con los grises y permite texto pequeño legible sobre la superficie. El matiz no codifica por sí solo peligro o normalidad: la prioridad la expresan palabra, símbolo, relleno y contorno. La alerta crítica conserva un área sólida para que no se pierda entre relieves.

| Estado | Tratamiento |
|---|---|
| Crítico | Fondo azul petróleo, texto blanco, indicador `!` |
| Alto | Texto y borde discontinuo azul petróleo, indicador `△` |
| OK | Carbón y borde continuo neutro, indicador `✓` |

## Tokens

| Token | Valor / uso |
|---|---|
| `--shield` | `#9A9A9A`; gris original del escudo |
| `--ink` | `#303030`; texto principal |
| `--white` | `#F8F8F8`; luz y texto sobre acento |
| `--surface` | `#E4E4E4`; fondo y superficies, gris aclarado del mismo eje neutro |
| `--muted` | `#595959`; texto secundario |
| `--line` | `#BDBDBD`; separaciones |
| `--control-line` | `#777777`; perímetro de campos |
| `--accent` / `--accent-ink` | `#245D68` / `#F8F8F8` |
| `--hover` / `--active` | `#D9D9D9` / `#E4E4E4` |
| `--font-body`, `--font-display` | `'Segoe UI', Arial, sans-serif` |
| `--font-mono` | `Consolas, 'Courier New', monospace` |
| Espaciado | Escala principal 4, 8, 12, 16, 24, 32, 40 px; separación de paneles 22–28 px |
| Tipos | Etiquetas 10–12 px; contenido 13–15 px; títulos 22–52 px; cifras 44–64 px |
| `--radius-panel` | `24px`; 22 px en móvil |
| `--radius-control` / `--radius-badge` | `12px` / `30px` |
| `--panel-shadow` | `8px 8px 18px #C2C2C2, -8px -8px 18px #F8F8F8` |
| `--control-shadow` | `4px 4px 9px #C2C2C2, -4px -4px 9px #F8F8F8` |
| `--inset-shadow` | `inset 4px 4px 8px #C2C2C2, inset -4px -4px 8px #F8F8F8` |

Los tonos #E4E4E4, #C2C2C2, #BDBDBD y #595959 son variaciones del gris original, no acentos adicionales. El carbón y blanco de marca permanecen en el sistema.

### Legibilidad

Texto principal sobre superficie: **10.38:1**. Secundario: **5.51:1**. Blanco sobre acento: **6.96:1**. Texto de acento sobre superficie: **5.81:1**. Secundario sobre hover: **4.96:1**.

Las sombras son decorativas; el foco usa un contorno de 3 px, los inputs tienen borde propio y los estados llevan palabras/símbolos. En modo de colores forzados se añaden bordes del sistema. No se utiliza #9A9A9A como texto informativo pequeño.

## Componentes y páginas

| Archivo | Componentes / interacción |
|---|---|
| [login.html](login.html) | Logotipo, superficie de acceso elevada, inputs hundidos y contraseña visible/oculta |
| [index.html](index.html) | Selector multiempresa, KPIs, anillo de presencia, histórico, alertas y eventos |
| [devices.html](devices.html) | Filtros hundidos, tabla completa, 8 filas por página y acciones remotas etiquetadas |
| [device.html](device.html) | Hardware, propietario, revisiones de política, cronología y comandos extruidos |
| [policies.html](policies.html) | Reglas web, descargas, instalación, días/horas, alcance y panel de impacto |
| [users.html](users.html) | Directorio de 12 filas por página, varios equipos por usuario, roles e invitación |
| [settings.html](settings.html) | Rebrand por empresa, vista previa, contraste calculado y claves API ficticias |
| [states.html](states.html) | Vacío, cargando, error y confirmación de borrado |

Componentes comunes: sidebar con estado activo hundido, topbar, panel elevado, input con relieve interior, botón extruido, badge, tabla semántica, diálogo nativo, mensaje de estado y error con reintento. Escape/retorno de foco se apoyan en `dialog`; su comportamiento nativo requiere la comprobación manual indicada al final.

## Parametrización por tenant

La aplicación futura resolvería la configuración validada del tenant y establecería variables en el elemento raíz:

```css
html[data-tenant="atlas"] {
  --surface: #E4E4E4;
  --ink: #303030;
  --accent: #245D68;
  --accent-ink: #F8F8F8;
  --panel-shadow: 8px 8px 18px #C2C2C2, -8px -8px 18px #F8F8F8;
  --inset-shadow: inset 4px 4px 8px #C2C2C2, inset -4px -4px 8px #F8F8F8;
}
```

Si se parametriza el gris de fondo en una implementación posterior, deben derivarse nuevamente ambos tonos de sombra y verificarse los contrastes. El editor de esta propuesta mantiene el gris fijo y permite **un acento**; así evita que la personalización elimine el relieve o introduzca estados ilegibles.

Nombre y logo se asignan como contenido de texto/imagen. El editor acepta PNG/JPG/WebP de hasta 2 MB. La base monocromática permanece; se bloquea aplicar colores con contraste menor a 4.5:1 tanto en el botón como en texto de acento sobre la superficie.

La selección viaja entre páginas como `?tenant=all|azc|atlas|nueva`. Marca y ediciones viven solo en la página abierta; **no persisten al navegar o recargar**. La vista agregada exige seleccionar una empresa para editar marca o claves. Estas decisiones de UI no implementan aislamiento de seguridad: eso corresponde al backend.

## Datos, alcance y operación

- Mismo dataset que brutalismo: 180 equipos, 160 usuarios, 2 empresas con equipos y una vacía. Comparación visual con contenido equivalente.
- Corte fijo: 15 sep 2026, 09:42 COT. En línea significa contacto menor a 2 min, separado de actividad del usuario y cumplimiento.
- Especificaciones, cronologías y políticas son fixtures; no son lecturas de producción ni una API implementada.
- Los comandos terminan en «En cola / pendiente de confirmación» en la demostración. Borrar representa borrado remoto de datos, funcionalidad propuesta para v4; el panel K3 elimina registros del inventario.
- Login, invitaciones, publicación, branding y claves API son locales y ficticios. No hay llamadas de red ni operaciones reales.
- El formulario de políticas valida dominios y una jornada dentro del mismo día. No implementa turnos que cruzan medianoche.

## Uso y verificación

Abrir los HTML directamente. Sin servidor, frameworks, CDNs, tipografías descargadas ni instalación. Cada página carga `styles.css` y recursos relativos `../assets/`; el JavaScript va inline. Conservar la carpeta de propuestas completa al copiar.

CSS responsive: sidebar pasa a navegación horizontal, KPIs a dos columnas y secciones a una columna; tablas con scroll local conservan versión y último contacto. Soporta foco visible, colores forzados y movimiento reducido.

Las verificaciones estáticas y DOM pasaron. **La inspección visual real en escritorio/móvil quedó bloqueada por las restricciones de lanzamiento de Chromium/Chrome.** Los contrastes de tokens están calculados; no se afirma una auditoría completa de accesibilidad. Evidencia en [VALIDACION.md](../VALIDACION.md).
