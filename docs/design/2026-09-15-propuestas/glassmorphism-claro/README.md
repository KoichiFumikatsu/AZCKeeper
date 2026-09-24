# Glassmorphism claro — Transparencia contenida

[Dashboard](index.html) · [Acceso](login.html) · [Estados](states.html) · [Todas las propuestas](../README.md)

Estado: **nueva propuesta para evaluación**. La referencia aprobada por el cliente es neomorfismo; esta propuesta respeta la familia clara/profesional, con estructura propia.

## Fundamentos del estilo

Navegación superior flotante, paneles blancos al 90 % y un fondo de dos degradados radiales exclusivamente neutros. El blur de 18 px se limita a las superficies; tabla, formularios y diálogo tienen fondo casi opaco u opaco. Los bordes blancos y la sombra suave producen separación sin oscurecer la página.

La profundidad nace de la translucidez y la composición sobre el fondo claro, no de extrusión. No depende de ilustraciones ni de descargas. Sin backdrop-filter, el CSS usa blanco sólido. Con prefers-reduced-transparency, desactiva blur y degradados; conserva los mismos datos y controles.

### Un único acento: Azul verdoso

El azul verdoso #306D77 aporta un acento serio sobre el eje gris. Mantiene al menos 4.66:1 incluso frente al gris más oscuro permitido del fondo; no hay colores luminosos ni degradados cromáticos.

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
| Fondo | `--surface: #EFEFEF` |
| Tarjeta | `--card: #FFFFFF` · panel blanco al 90 %; fondo mínimo #E5E5E5 |
| Texto | `--ink: #303030`, `--muted: #555555` |
| Acento | `--accent: #306D77`, `--accent-ink: #F8F8F8` |
| Hover / divisiones | `--hover: #E5E5E5`, `--line: #BDBDBD` |
| Borde de campo | `--control-line: #7C7C7C` |
| Tipografías de sistema | `--font-body` y `--font-display`: `'Segoe UI', Arial, sans-serif`; `--font-mono`: `Consolas, 'Courier New', monospace` |
| Tamaños/pesos | Títulos 35–50 px, KPIs 47–53 px, cuerpo 12–14 px, pesos 550–620. |
| Espaciado | Base 4/8/12/16/24/32 px; margen externo 36 px; paneles 26 px; gap 20–27 px; móvil 14–18 px. |
| Radios | Panel 18 px; topbar flotante 20 px; campo/botón 10 px; badge 18 px. |
| Sombras | Panel: 0 8px 22px rgba(48,48,48,.065). Sin doble sombra. Blur: backdrop-filter de 18 px, con fallback blanco. |

Los tonos adicionales son neutros. No hay fondo carbón/negro, modo oscuro, acentos neón ni elementos de terminal/HUD en esta propuesta. Se conserva el logo original gris/carbón para superficies claras.

## Componentes

Masthead translúcido, tarjeta de vidrio, snapshot sobre superficie blanca, tabla de fondo al 97 %, campo opaco y fallback sin transparencia.

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

1440 px: lienzo centrado, margen externo 36 px, navegación flotante, cuatro KPIs y dos columnas de operación.

390 px: margen externo 14 px, navegación desplazable dentro del panel, dos KPIs por fila, tarjetas apiladas y formularios blancos.

Las tablas mantienen versión y último contacto mediante scroll en su propia región, sin ocultar esas columnas. Se incluyen movimiento reducido, colores forzados, salto al contenido y foco visible. No hay selector de modo oscuro; se declara `color-scheme: light`.

## Contraste de texto

| Par comprobado | Relación sRGB |
|---|---|
| Texto principal / fondo | 11.48:1 |
| Secundario / fondo | 6.48:1 |
| Blanco / acento | 5.52:1 |
| Acento / fondo | 5.10:1 |
| Acento / hover o fondo mínimo | 4.66:1 |
| Secundario / hover | 5.92:1 |

Todos estos pares superan 4.5:1. El mínimo #E5E5E5 cubre el extremo más oscuro del degradado; las capas blancas solo elevan su luminosidad. Los diálogos y campos son opacos. El gris del escudo se reserva a marca/gráficos, no a texto informativo pequeño.

## Parametrización por tenant

~~~css
html[data-tenant="azc"] {
  --surface: #EFEFEF;
  --ink: #303030;
  --muted: #555555;
  --accent: #306D77;
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
