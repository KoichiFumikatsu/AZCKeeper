# Flat corporativo — Orden y lectura directa

[Dashboard](index.html) · [Acceso](login.html) · [Estados](states.html) · [Todas las propuestas](../README.md)

Estado: **nueva propuesta para evaluación**. La referencia aprobada por el cliente es neomorfismo; esta propuesta respeta la familia clara/profesional, con estructura propia.

## Fundamentos del estilo

Sidebar blanca persistente, tarjetas planas de borde gris, encabezado compacto y espacios regulares. Los bloques se delimitan con bordes, no con relieve. El estado activo de navegación usa una línea de acento y un fondo gris neutro. El dashboard mantiene cuatro KPIs y dos columnas de operación para escanear rápidamente la flota.

Respecto de neomorfismo, las tarjetas son blancas sobre fondo gris claro, sin pares de sombras ni inputs hundidos. Respecto de las otras variantes nuevas, conserva la estructura SaaS más directa: sidebar ancha, radios pequeños y tabla con cabecera definida.

### Un único acento: Azul corporativo

El azul #285AA8 da una señal sobria de acción y prioridad; acompaña una interfaz de trabajo empresarial sin teñir todas las métricas.

| Estado | Representación |
|---|---|
| Crítico | `! Crítico`, relleno de acento y texto blanco |
| Alto | `△ Alto`, texto de acento y borde discontinuo |
| OK | `✓ OK`, texto carbón y borde neutro |

Las etiquetas y símbolos conservan la diferencia sin depender del color. La conexión de un equipo no equivale a actividad del usuario ni confirma cumplimiento de políticas.

## Tokens de diseño

| Token / propiedad | Valor |
|---|---|
| Base de marca | `--shield: #9A9A9A`, `--brand-charcoal: #303030`, `--white: #F8F8F8` |
| Fondo | `--surface: #F6F6F6` |
| Tarjeta | `--card: #FFFFFF` |
| Texto | `--ink: #303030`, `--muted: #595959` |
| Acento | `--accent: #285AA8`, `--accent-ink: #F8F8F8` |
| Hover / divisiones | `--hover: #EBEBEB`, `--line: #D4D4D4` |
| Borde de campo | `--control-line: #858585` |
| Tipografías de sistema | `--font-body` y `--font-display`: `'Segoe UI', Arial, sans-serif`; `--font-mono`: `Consolas, 'Courier New', monospace` |
| Tamaños/pesos | Títulos 31–45 px, KPIs 44–49 px, cuerpo 12–14 px, metadatos 10–11 px. |
| Espaciado | Base 4/8/12/16/24/32 px; margen de contenido 32 px; gap de tarjetas 18 px; móvil 18 px. |
| Radios | Panel 10 px; campo/botón 6 px; badge 5 px. |
| Sombras | Panel: 0 1px 2px rgba(48,48,48,.04). Controles y campos sin sombra. |

Los tonos adicionales son neutros. No hay fondo carbón/negro, modo oscuro, acentos neón ni elementos de terminal/HUD en esta propuesta. Se conserva el logo original gris/carbón para superficies claras.

## Componentes

Sidebar con indicador lateral, tarjeta plana, cabecera de tabla gris, caja de impacto y botones textuales de borde.

Se reutilizan los componentes funcionales del prototipo: selector de tenant, tabla semántica, badges textuales, formulario etiquetado, mensajes de estado, errores con salida, paginación y diálogo nativo. Campos y botones tienen nombre accesible; foco de 3 px con separación del contorno. Los controles principales miden al menos 44 px de alto; acciones compactas de tabla, 40 px.

## Ocho páginas, mismo alcance

| Página | Contenido |
|---|---|
| [login.html](login.html) | Empresa, correo, contraseña, mostrar/ocultar y acceso simulado. |
| [index.html](index.html) | Dashboard multiempresa, KPIs, equipos en línea, histórico, alertas y eventos. |
| [devices.html](devices.html) | Tabla filtrable/paginada: estado, versión, contacto y bloquear/apagar/borrar. |
| [device.html](device.html) | Especificaciones, usuario, políticas aplicadas, cronología y comandos. |
| [policies.html](policies.html) | Dominios, descarga/instalación, horario y alcance global/empresa/usuario/equipo. |
| [users.html](users.html) | Personas, equipos asignados, permisos, búsqueda y edición local de acceso. |
| [settings.html](settings.html) | Nombre, logo y acento por tenant; vista previa y claves API ficticias. |
| [states.html](states.html) | Vacío, cargando, error y confirmación destructiva reproducibles. |

### Estados que se pueden probar

- Vacío: seleccionar «Nueva empresa» en el inventario o usar una búsqueda inexistente.
- Cargando: `devices.html?state=loading`; contiene salida «Mostrar resultado».
- Error: `devices.html?state=error`; conserva el contexto y ofrece «Reintentar».
- Destructivo: «Borrar» en tabla o detalle abre diálogo con equipo, empresa, efecto y motivo; exige escribir el hostname. Al confirmar, se muestra «En cola», pendiente de respuesta del agente.

## Responsive

1440 px: sidebar de 222 px, topbar blanca, cuatro tarjetas KPI, conectividad y alertas en dos columnas.

390 px: navegación horizontal con scroll local, dos KPIs por fila y contenido en una columna; tarjetas de 9 px de radio.

Las tablas mantienen versión y último contacto mediante scroll en su propia región, sin ocultar esas columnas. Se incluyen movimiento reducido, colores forzados, salto al contenido y foco visible. No hay selector de modo oscuro; se declara `color-scheme: light`.

## Contraste de texto

| Par comprobado | Relación sRGB |
|---|---|
| Texto principal / fondo | 12.21:1 |
| Secundario / fondo | 6.48:1 |
| Blanco / acento | 6.34:1 |
| Acento / fondo | 6.23:1 |
| Acento / hover o fondo mínimo | 5.65:1 |
| Secundario / hover | 5.88:1 |

Todos estos pares superan 4.5:1. Las tarjetas blancas aumentan el contraste respecto del fondo de página. El gris del escudo se reserva a marca/gráficos, no a texto informativo pequeño.

## Parametrización por tenant

~~~css
html[data-tenant="azc"] {
  --surface: #F6F6F6;
  --ink: #303030;
  --muted: #595959;
  --accent: #285AA8;
  --accent-ink: #F8F8F8;
}
~~~

El ejemplo representa la configuración validada que resolvería el servidor futuro. En el prototipo, `settings.html` aplica variables al elemento raíz mediante JavaScript local. Nombre y logo son contenido, nunca HTML/CSS arbitrario. Los defaults están en `_tools/themes.cjs`.

El editor mantiene fijos fondo y familia clara. Permite cambiar **un** acento y el texto sobre él; comprueba tanto texto/botón como acento/fondo y acento/hover antes de aplicar. Logo PNG/JPG/WebP local de hasta 2 MB. La vista agregada exige seleccionar una empresa antes de editar marca o claves.

La empresa viaja mediante links relativos con `?tenant=all|azc|atlas|nueva`. Ediciones de políticas, accesos, marca y claves viven solo mientras la página siga abierta. No persisten al navegar/recargar. La selección visual no constituye autorización: el backend futuro debe imponer aislamiento.

## Datos, uso y verificación

180 equipos y 160 usuarios ficticios; Grupo AZC tiene 124 equipos y Atlas 56; una empresa vacía permite probar el estado inicial. Corte fijo 15 sep 2026, 09:42 COT. Specs/eventos son fixtures. No hay autenticación, envío de correos, peticiones externas ni ejecución real de comandos. Borrado remoto y API keys por tenant son propuestas v4; no se atribuyen al K3 existente.

Abrir `index.html` por doble clic y conservar la carpeta compartida `../assets/`. Un CSS final por propuesta, JavaScript inline y fuentes del sistema; no necesita servidor, instalación, framework o CDN. El generador compone `_tools/base.css` + `_tools/light-base.css` + el CSS específico. JSDOM/Playwright se usan solo como herramientas de verificación, no como dependencias del prototipo.

Ver [VALIDACION.md](../VALIDACION.md) para estructura, referencias, interacción DOM y contrastes. **No se completó la inspección visual a 1440/390 px:** Chromium falló con `spawn EPERM`. Se deja preparado el recorrido de navegador. JSDOM no acredita layout real, lector de pantalla ni conformidad integral WCAG.
