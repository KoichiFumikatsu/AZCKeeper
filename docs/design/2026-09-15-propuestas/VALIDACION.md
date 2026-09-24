# Validación de las propuestas

Fecha: 15 de septiembre de 2026.

## Resultado comprobado

- 696 comprobaciones aprobadas; 0 fallos.
- 120 HTML parseados y ejecutados en JSDOM; JavaScript inline comprobado con el parser de Node.
- Recursos y navegación: todos los atributos src/href inspeccionados resuelven a archivos locales existentes; CSS sin imports ni recursos remotos.
- Semántica: idioma, viewport, un h1/main por página, IDs únicos, imágenes con alt y nombres de controles.
- Interacción: tenant, KPIs, filtros, paginación, estados, destinatario de comandos, confirmación escrita, políticas, usuarios, branding y claves ficticias.
- Contrastes calculados desde los tokens sRGB. El editor impide aplicar un acento que incumpla 4.5:1 en texto sobre el botón o en texto de acento sobre la superficie.

## Límite de la comprobación

**No se completó una inspección visual en navegador.** El pipeline de Playwright no pudo iniciar Chromium por «spawn EPERM». El intento más reciente está documentado en VALIDACION-NAVEGADOR.md. No se capturaron pantallas ni se midió el layout móvil real.

JSDOM verifica DOM e interacciones, pero no renderiza. Sus diálogos están emulados para probar callbacks; no acredita confinamiento de foco, Escape, lector de pantalla, navegación nativa ni comportamiento visual. El CSS contempla 600/700/900/1150 px, scroll local de tablas, foco visible, movimiento reducido y colores forzados; falta contrastarlo visualmente en 390 px, 1440 px y zoom 200 %.

La validación de archivos locales confirma ausencia de dependencias externas; no sustituye abrirlos manualmente en el navegador. El archivo _tools/validate.cjs deja preparada esa comprobación con Playwright, incluyendo capturas e interacciones.

## Contraste de texto

| Estilo | Uso | Texto | Fondo | Relación |
|---|---|---|---|---|
| brutalista | Principal | #303030 | #F8F8F8 | 12.43:1 |
| brutalista | Secundario | #595959 | #F8F8F8 | 6.60:1 |
| brutalista | Sobre acento | #F8F8F8 | #B52A19 | 5.97:1 |
| brutalista | Acento como texto | #B52A19 | #F8F8F8 | 5.97:1 |
| brutalista | Secundario en hover | #595959 | #E8E8E8 | 5.72:1 |
| neomorfismo | Principal | #303030 | #E4E4E4 | 10.38:1 |
| neomorfismo | Secundario | #595959 | #E4E4E4 | 5.51:1 |
| neomorfismo | Sobre acento | #F8F8F8 | #245D68 | 6.96:1 |
| neomorfismo | Acento como texto | #245D68 | #E4E4E4 | 5.81:1 |
| neomorfismo | Secundario en hover | #595959 | #D9D9D9 | 4.96:1 |
| terminal | Principal | #F8F8F8 | #171717 | 16.88:1 |
| terminal | Secundario | #B8B8B8 | #171717 | 9.04:1 |
| terminal | Sobre acento | #303030 | #A7E66E | 8.92:1 |
| terminal | Acento como texto | #A7E66E | #171717 | 12.11:1 |
| terminal | Secundario en hover | #B8B8B8 | #303030 | 6.65:1 |
| editorial | Principal | #303030 | #F8F8F8 | 12.43:1 |
| editorial | Secundario | #595959 | #F8F8F8 | 6.60:1 |
| editorial | Sobre acento | #F8F8F8 | #244AB8 | 7.20:1 |
| editorial | Acento como texto | #244AB8 | #F8F8F8 | 7.20:1 |
| editorial | Secundario en hover | #595959 | #EAEAEA | 5.82:1 |
| industrial | Principal | #F8F8F8 | #303030 | 12.43:1 |
| industrial | Secundario | #C1C1C1 | #303030 | 7.33:1 |
| industrial | Sobre acento | #303030 | #FFD24A | 9.16:1 |
| industrial | Acento como texto | #FFD24A | #303030 | 9.16:1 |
| industrial | Secundario en hover | #C1C1C1 | #404040 | 5.76:1 |
| flat-corporativo | Acento sobre hover / mínimo | #285AA8 | #EBEBEB | 5.65:1 |
| flat-corporativo | Principal | #303030 | #F6F6F6 | 12.21:1 |
| flat-corporativo | Secundario | #595959 | #F6F6F6 | 6.48:1 |
| flat-corporativo | Sobre acento | #F8F8F8 | #285AA8 | 6.34:1 |
| flat-corporativo | Acento como texto | #285AA8 | #F6F6F6 | 6.23:1 |
| flat-corporativo | Secundario en hover | #595959 | #EBEBEB | 5.88:1 |
| material-claro | Acento sobre hover / mínimo | #675095 | #E8E8E8 | 5.43:1 |
| material-claro | Principal | #303030 | #F3F3F3 | 11.89:1 |
| material-claro | Secundario | #595959 | #F3F3F3 | 6.31:1 |
| material-claro | Sobre acento | #F8F8F8 | #675095 | 6.26:1 |
| material-claro | Acento como texto | #675095 | #F3F3F3 | 5.99:1 |
| material-claro | Secundario en hover | #595959 | #E8E8E8 | 5.72:1 |
| glassmorphism-claro | Acento sobre hover / mínimo | #306D77 | #E5E5E5 | 4.66:1 |
| glassmorphism-claro | Principal | #303030 | #EFEFEF | 11.48:1 |
| glassmorphism-claro | Secundario | #555555 | #EFEFEF | 6.48:1 |
| glassmorphism-claro | Sobre acento | #F8F8F8 | #306D77 | 5.52:1 |
| glassmorphism-claro | Acento como texto | #306D77 | #EFEFEF | 5.10:1 |
| glassmorphism-claro | Secundario en hover | #555555 | #E5E5E5 | 5.92:1 |
| minimal-lineas | Acento sobre hover / mínimo | #416A60 | #EEEEEE | 5.24:1 |
| minimal-lineas | Principal | #303030 | #F8F8F8 | 12.43:1 |
| minimal-lineas | Secundario | #595959 | #F8F8F8 | 6.60:1 |
| minimal-lineas | Sobre acento | #F8F8F8 | #416A60 | 5.72:1 |
| minimal-lineas | Acento como texto | #416A60 | #F8F8F8 | 5.72:1 |
| minimal-lineas | Secundario en hover | #595959 | #EEEEEE | 6.04:1 |
| soft-cards | Acento sobre hover / mínimo | #526A43 | #E6E6E6 | 4.81:1 |
| soft-cards | Principal | #303030 | #F1F1F1 | 11.69:1 |
| soft-cards | Secundario | #595959 | #F1F1F1 | 6.20:1 |
| soft-cards | Sobre acento | #F8F8F8 | #526A43 | 5.65:1 |
| soft-cards | Acento como texto | #526A43 | #F1F1F1 | 5.31:1 |
| soft-cards | Secundario en hover | #595959 | #E6E6E6 | 5.61:1 |
| editorial-premium | Acento sobre hover / mínimo | #754A63 | #EFEFEF | 6.26:1 |
| editorial-premium | Principal | #303030 | #F8F8F8 | 12.43:1 |
| editorial-premium | Secundario | #595959 | #F8F8F8 | 6.60:1 |
| editorial-premium | Sobre acento | #F8F8F8 | #754A63 | 6.78:1 |
| editorial-premium | Acento como texto | #754A63 | #F8F8F8 | 6.78:1 |
| editorial-premium | Secundario en hover | #595959 | #EFEFEF | 6.09:1 |
| bauhaus-geometrico | Acento sobre hover / mínimo | #B02E22 | #ECECEC | 5.45:1 |
| bauhaus-geometrico | Principal | #303030 | #F8F8F8 | 12.43:1 |
| bauhaus-geometrico | Secundario | #555555 | #F8F8F8 | 7.02:1 |
| bauhaus-geometrico | Sobre acento | #F8F8F8 | #B02E22 | 6.07:1 |
| bauhaus-geometrico | Acento como texto | #B02E22 | #F8F8F8 | 6.07:1 |
| bauhaus-geometrico | Secundario en hover | #555555 | #ECECEC | 6.31:1 |
| blueprint-tecnico | Acento sobre hover / mínimo | #275B80 | #ECECEC | 6.14:1 |
| blueprint-tecnico | Principal | #303030 | #F8F8F8 | 12.43:1 |
| blueprint-tecnico | Secundario | #555555 | #F8F8F8 | 7.02:1 |
| blueprint-tecnico | Sobre acento | #F8F8F8 | #275B80 | 6.83:1 |
| blueprint-tecnico | Acento como texto | #275B80 | #F8F8F8 | 6.83:1 |
| blueprint-tecnico | Secundario en hover | #555555 | #ECECEC | 6.31:1 |
| humanista-calido | Acento sobre hover / mínimo | #8A4938 | #ECE7DF | 5.52:1 |
| humanista-calido | Principal | #303030 | #F7F5F0 | 12.11:1 |
| humanista-calido | Secundario | #5D574F | #F7F5F0 | 6.55:1 |
| humanista-calido | Sobre acento | #F8F8F8 | #8A4938 | 6.40:1 |
| humanista-calido | Acento como texto | #8A4938 | #F7F5F0 | 6.24:1 |
| humanista-calido | Secundario en hover | #5D574F | #ECE7DF | 5.80:1 |
| swiss-datos | Acento sobre hover / mínimo | #B1242A | #EAEAEA | 5.53:1 |
| swiss-datos | Principal | #303030 | #F8F8F8 | 12.43:1 |
| swiss-datos | Secundario | #555555 | #F8F8F8 | 7.02:1 |
| swiss-datos | Sobre acento | #F8F8F8 | #B1242A | 6.27:1 |
| swiss-datos | Acento como texto | #B1242A | #F8F8F8 | 6.27:1 |
| swiss-datos | Secundario en hover | #555555 | #EAEAEA | 6.20:1 |

El gris del escudo #9A9A9A se reserva a marca y gráficos, no a texto informativo pequeño.

En todas las variantes corporativas claras se verifica fondo claro y contraste del acento sobre hover. El umbral de superficie admite papel cálido (canales ≥ 220); cada par de texto se comprueba además por luminancia WCAG, con mínimo 4.5:1. Las cinco direcciones de arte agregan composiciones HTML propias y mantienen los mismos flujos. La revisión visual sigue pendiente de navegador.

## Comprobaciones

- OK: brutalista — conjunto exacto de páginas
- OK: brutalista/login — HTML y JavaScript
- OK: brutalista/login — recursos locales y enlaces
- OK: brutalista/login — nombres de controles
- OK: brutalista/index — HTML y JavaScript
- OK: brutalista/index — recursos locales y enlaces
- OK: brutalista/index — nombres de controles
- OK: brutalista/devices — HTML y JavaScript
- OK: brutalista/devices — recursos locales y enlaces
- OK: brutalista/devices — nombres de controles
- OK: brutalista/device — HTML y JavaScript
- OK: brutalista/device — recursos locales y enlaces
- OK: brutalista/device — nombres de controles
- OK: brutalista/policies — HTML y JavaScript
- OK: brutalista/policies — recursos locales y enlaces
- OK: brutalista/policies — nombres de controles
- OK: brutalista/users — HTML y JavaScript
- OK: brutalista/users — recursos locales y enlaces
- OK: brutalista/users — nombres de controles
- OK: brutalista/settings — HTML y JavaScript
- OK: brutalista/settings — recursos locales y enlaces
- OK: brutalista/settings — nombres de controles
- OK: brutalista/states — HTML y JavaScript
- OK: brutalista/states — recursos locales y enlaces
- OK: brutalista/states — nombres de controles
- OK: brutalista — tenant, KPIs y navegación
- OK: brutalista — filtros, paginación, vacío, carga y error
- OK: brutalista — detalle del equipo y confirmación de borrado
- OK: brutalista — política: ámbitos, dominio, horario y revisión
- OK: brutalista — usuarios: búsqueda, paginación y edición de acceso
- OK: brutalista — rebrand y contraste insuficiente
- OK: brutalista — API keys: crear y revocar
- OK: brutalista — login: validación de entrada y mostrar contraseña
- OK: brutalista — CSS local, adaptable y foco visible
- OK: brutalista — tokens CSS coinciden con los defaults del tema
- OK: brutalista — contraste Principal
- OK: brutalista — contraste Secundario
- OK: brutalista — contraste Sobre acento
- OK: brutalista — contraste Acento como texto
- OK: brutalista — contraste Secundario en hover
- OK: neomorfismo — conjunto exacto de páginas
- OK: neomorfismo/login — HTML y JavaScript
- OK: neomorfismo/login — recursos locales y enlaces
- OK: neomorfismo/login — nombres de controles
- OK: neomorfismo/index — HTML y JavaScript
- OK: neomorfismo/index — recursos locales y enlaces
- OK: neomorfismo/index — nombres de controles
- OK: neomorfismo/devices — HTML y JavaScript
- OK: neomorfismo/devices — recursos locales y enlaces
- OK: neomorfismo/devices — nombres de controles
- OK: neomorfismo/device — HTML y JavaScript
- OK: neomorfismo/device — recursos locales y enlaces
- OK: neomorfismo/device — nombres de controles
- OK: neomorfismo/policies — HTML y JavaScript
- OK: neomorfismo/policies — recursos locales y enlaces
- OK: neomorfismo/policies — nombres de controles
- OK: neomorfismo/users — HTML y JavaScript
- OK: neomorfismo/users — recursos locales y enlaces
- OK: neomorfismo/users — nombres de controles
- OK: neomorfismo/settings — HTML y JavaScript
- OK: neomorfismo/settings — recursos locales y enlaces
- OK: neomorfismo/settings — nombres de controles
- OK: neomorfismo/states — HTML y JavaScript
- OK: neomorfismo/states — recursos locales y enlaces
- OK: neomorfismo/states — nombres de controles
- OK: neomorfismo — tenant, KPIs y navegación
- OK: neomorfismo — filtros, paginación, vacío, carga y error
- OK: neomorfismo — detalle del equipo y confirmación de borrado
- OK: neomorfismo — política: ámbitos, dominio, horario y revisión
- OK: neomorfismo — usuarios: búsqueda, paginación y edición de acceso
- OK: neomorfismo — rebrand y contraste insuficiente
- OK: neomorfismo — API keys: crear y revocar
- OK: neomorfismo — login: validación de entrada y mostrar contraseña
- OK: neomorfismo — CSS local, adaptable y foco visible
- OK: neomorfismo — tokens CSS coinciden con los defaults del tema
- OK: neomorfismo — contraste Principal
- OK: neomorfismo — contraste Secundario
- OK: neomorfismo — contraste Sobre acento
- OK: neomorfismo — contraste Acento como texto
- OK: neomorfismo — contraste Secundario en hover
- OK: terminal — conjunto exacto de páginas
- OK: terminal/login — HTML y JavaScript
- OK: terminal/login — recursos locales y enlaces
- OK: terminal/login — nombres de controles
- OK: terminal/index — HTML y JavaScript
- OK: terminal/index — recursos locales y enlaces
- OK: terminal/index — nombres de controles
- OK: terminal/devices — HTML y JavaScript
- OK: terminal/devices — recursos locales y enlaces
- OK: terminal/devices — nombres de controles
- OK: terminal/device — HTML y JavaScript
- OK: terminal/device — recursos locales y enlaces
- OK: terminal/device — nombres de controles
- OK: terminal/policies — HTML y JavaScript
- OK: terminal/policies — recursos locales y enlaces
- OK: terminal/policies — nombres de controles
- OK: terminal/users — HTML y JavaScript
- OK: terminal/users — recursos locales y enlaces
- OK: terminal/users — nombres de controles
- OK: terminal/settings — HTML y JavaScript
- OK: terminal/settings — recursos locales y enlaces
- OK: terminal/settings — nombres de controles
- OK: terminal/states — HTML y JavaScript
- OK: terminal/states — recursos locales y enlaces
- OK: terminal/states — nombres de controles
- OK: terminal — tenant, KPIs y navegación
- OK: terminal — filtros, paginación, vacío, carga y error
- OK: terminal — detalle del equipo y confirmación de borrado
- OK: terminal — política: ámbitos, dominio, horario y revisión
- OK: terminal — usuarios: búsqueda, paginación y edición de acceso
- OK: terminal — rebrand y contraste insuficiente
- OK: terminal — API keys: crear y revocar
- OK: terminal — login: validación de entrada y mostrar contraseña
- OK: terminal — atajos, contexto, ayuda y campos de texto
- OK: terminal — CSS local, adaptable y foco visible
- OK: terminal — tokens CSS coinciden con los defaults del tema
- OK: terminal — contraste Principal
- OK: terminal — contraste Secundario
- OK: terminal — contraste Sobre acento
- OK: terminal — contraste Acento como texto
- OK: terminal — contraste Secundario en hover
- OK: editorial — conjunto exacto de páginas
- OK: editorial/login — HTML y JavaScript
- OK: editorial/login — recursos locales y enlaces
- OK: editorial/login — nombres de controles
- OK: editorial/index — HTML y JavaScript
- OK: editorial/index — recursos locales y enlaces
- OK: editorial/index — nombres de controles
- OK: editorial/devices — HTML y JavaScript
- OK: editorial/devices — recursos locales y enlaces
- OK: editorial/devices — nombres de controles
- OK: editorial/device — HTML y JavaScript
- OK: editorial/device — recursos locales y enlaces
- OK: editorial/device — nombres de controles
- OK: editorial/policies — HTML y JavaScript
- OK: editorial/policies — recursos locales y enlaces
- OK: editorial/policies — nombres de controles
- OK: editorial/users — HTML y JavaScript
- OK: editorial/users — recursos locales y enlaces
- OK: editorial/users — nombres de controles
- OK: editorial/settings — HTML y JavaScript
- OK: editorial/settings — recursos locales y enlaces
- OK: editorial/settings — nombres de controles
- OK: editorial/states — HTML y JavaScript
- OK: editorial/states — recursos locales y enlaces
- OK: editorial/states — nombres de controles
- OK: editorial — tenant, KPIs y navegación
- OK: editorial — filtros, paginación, vacío, carga y error
- OK: editorial — detalle del equipo y confirmación de borrado
- OK: editorial — política: ámbitos, dominio, horario y revisión
- OK: editorial — usuarios: búsqueda, paginación y edición de acceso
- OK: editorial — rebrand y contraste insuficiente
- OK: editorial — API keys: crear y revocar
- OK: editorial — login: validación de entrada y mostrar contraseña
- OK: editorial — CSS local, adaptable y foco visible
- OK: editorial — tokens CSS coinciden con los defaults del tema
- OK: editorial — contraste Principal
- OK: editorial — contraste Secundario
- OK: editorial — contraste Sobre acento
- OK: editorial — contraste Acento como texto
- OK: editorial — contraste Secundario en hover
- OK: industrial — conjunto exacto de páginas
- OK: industrial/login — HTML y JavaScript
- OK: industrial/login — recursos locales y enlaces
- OK: industrial/login — nombres de controles
- OK: industrial/index — HTML y JavaScript
- OK: industrial/index — recursos locales y enlaces
- OK: industrial/index — nombres de controles
- OK: industrial/devices — HTML y JavaScript
- OK: industrial/devices — recursos locales y enlaces
- OK: industrial/devices — nombres de controles
- OK: industrial/device — HTML y JavaScript
- OK: industrial/device — recursos locales y enlaces
- OK: industrial/device — nombres de controles
- OK: industrial/policies — HTML y JavaScript
- OK: industrial/policies — recursos locales y enlaces
- OK: industrial/policies — nombres de controles
- OK: industrial/users — HTML y JavaScript
- OK: industrial/users — recursos locales y enlaces
- OK: industrial/users — nombres de controles
- OK: industrial/settings — HTML y JavaScript
- OK: industrial/settings — recursos locales y enlaces
- OK: industrial/settings — nombres de controles
- OK: industrial/states — HTML y JavaScript
- OK: industrial/states — recursos locales y enlaces
- OK: industrial/states — nombres de controles
- OK: industrial — tenant, KPIs y navegación
- OK: industrial — filtros, paginación, vacío, carga y error
- OK: industrial — detalle del equipo y confirmación de borrado
- OK: industrial — política: ámbitos, dominio, horario y revisión
- OK: industrial — usuarios: búsqueda, paginación y edición de acceso
- OK: industrial — rebrand y contraste insuficiente
- OK: industrial — API keys: crear y revocar
- OK: industrial — login: validación de entrada y mostrar contraseña
- OK: industrial — CSS local, adaptable y foco visible
- OK: industrial — tokens CSS coinciden con los defaults del tema
- OK: industrial — contraste Principal
- OK: industrial — contraste Secundario
- OK: industrial — contraste Sobre acento
- OK: industrial — contraste Acento como texto
- OK: industrial — contraste Secundario en hover
- OK: flat-corporativo — conjunto exacto de páginas
- OK: flat-corporativo/login — HTML y JavaScript
- OK: flat-corporativo/login — recursos locales y enlaces
- OK: flat-corporativo/login — nombres de controles
- OK: flat-corporativo/login — familia corporativa clara
- OK: flat-corporativo/index — HTML y JavaScript
- OK: flat-corporativo/index — recursos locales y enlaces
- OK: flat-corporativo/index — nombres de controles
- OK: flat-corporativo/index — familia corporativa clara
- OK: flat-corporativo/devices — HTML y JavaScript
- OK: flat-corporativo/devices — recursos locales y enlaces
- OK: flat-corporativo/devices — nombres de controles
- OK: flat-corporativo/devices — familia corporativa clara
- OK: flat-corporativo/device — HTML y JavaScript
- OK: flat-corporativo/device — recursos locales y enlaces
- OK: flat-corporativo/device — nombres de controles
- OK: flat-corporativo/device — familia corporativa clara
- OK: flat-corporativo/policies — HTML y JavaScript
- OK: flat-corporativo/policies — recursos locales y enlaces
- OK: flat-corporativo/policies — nombres de controles
- OK: flat-corporativo/policies — familia corporativa clara
- OK: flat-corporativo/users — HTML y JavaScript
- OK: flat-corporativo/users — recursos locales y enlaces
- OK: flat-corporativo/users — nombres de controles
- OK: flat-corporativo/users — familia corporativa clara
- OK: flat-corporativo/settings — HTML y JavaScript
- OK: flat-corporativo/settings — recursos locales y enlaces
- OK: flat-corporativo/settings — nombres de controles
- OK: flat-corporativo/settings — familia corporativa clara
- OK: flat-corporativo/states — HTML y JavaScript
- OK: flat-corporativo/states — recursos locales y enlaces
- OK: flat-corporativo/states — nombres de controles
- OK: flat-corporativo/states — familia corporativa clara
- OK: flat-corporativo — tenant, KPIs y navegación
- OK: flat-corporativo — filtros, paginación, vacío, carga y error
- OK: flat-corporativo — detalle del equipo y confirmación de borrado
- OK: flat-corporativo — política: ámbitos, dominio, horario y revisión
- OK: flat-corporativo — usuarios: búsqueda, paginación y edición de acceso
- OK: flat-corporativo — rebrand y contraste insuficiente
- OK: flat-corporativo — API keys: crear y revocar
- OK: flat-corporativo — login: validación de entrada y mostrar contraseña
- OK: flat-corporativo — CSS local, adaptable y foco visible
- OK: flat-corporativo — contraste de acento sobre hover / fondo mínimo
- OK: flat-corporativo — tokens CSS coinciden con los defaults del tema
- OK: flat-corporativo — contraste Principal
- OK: flat-corporativo — contraste Secundario
- OK: flat-corporativo — contraste Sobre acento
- OK: flat-corporativo — contraste Acento como texto
- OK: flat-corporativo — contraste Secundario en hover
- OK: material-claro — conjunto exacto de páginas
- OK: material-claro/login — HTML y JavaScript
- OK: material-claro/login — recursos locales y enlaces
- OK: material-claro/login — nombres de controles
- OK: material-claro/login — familia corporativa clara
- OK: material-claro/index — HTML y JavaScript
- OK: material-claro/index — recursos locales y enlaces
- OK: material-claro/index — nombres de controles
- OK: material-claro/index — familia corporativa clara
- OK: material-claro/devices — HTML y JavaScript
- OK: material-claro/devices — recursos locales y enlaces
- OK: material-claro/devices — nombres de controles
- OK: material-claro/devices — familia corporativa clara
- OK: material-claro/device — HTML y JavaScript
- OK: material-claro/device — recursos locales y enlaces
- OK: material-claro/device — nombres de controles
- OK: material-claro/device — familia corporativa clara
- OK: material-claro/policies — HTML y JavaScript
- OK: material-claro/policies — recursos locales y enlaces
- OK: material-claro/policies — nombres de controles
- OK: material-claro/policies — familia corporativa clara
- OK: material-claro/users — HTML y JavaScript
- OK: material-claro/users — recursos locales y enlaces
- OK: material-claro/users — nombres de controles
- OK: material-claro/users — familia corporativa clara
- OK: material-claro/settings — HTML y JavaScript
- OK: material-claro/settings — recursos locales y enlaces
- OK: material-claro/settings — nombres de controles
- OK: material-claro/settings — familia corporativa clara
- OK: material-claro/states — HTML y JavaScript
- OK: material-claro/states — recursos locales y enlaces
- OK: material-claro/states — nombres de controles
- OK: material-claro/states — familia corporativa clara
- OK: material-claro — tenant, KPIs y navegación
- OK: material-claro — filtros, paginación, vacío, carga y error
- OK: material-claro — detalle del equipo y confirmación de borrado
- OK: material-claro — política: ámbitos, dominio, horario y revisión
- OK: material-claro — usuarios: búsqueda, paginación y edición de acceso
- OK: material-claro — rebrand y contraste insuficiente
- OK: material-claro — API keys: crear y revocar
- OK: material-claro — login: validación de entrada y mostrar contraseña
- OK: material-claro — CSS local, adaptable y foco visible
- OK: material-claro — contraste de acento sobre hover / fondo mínimo
- OK: material-claro — tokens CSS coinciden con los defaults del tema
- OK: material-claro — contraste Principal
- OK: material-claro — contraste Secundario
- OK: material-claro — contraste Sobre acento
- OK: material-claro — contraste Acento como texto
- OK: material-claro — contraste Secundario en hover
- OK: glassmorphism-claro — conjunto exacto de páginas
- OK: glassmorphism-claro/login — HTML y JavaScript
- OK: glassmorphism-claro/login — recursos locales y enlaces
- OK: glassmorphism-claro/login — nombres de controles
- OK: glassmorphism-claro/login — familia corporativa clara
- OK: glassmorphism-claro/index — HTML y JavaScript
- OK: glassmorphism-claro/index — recursos locales y enlaces
- OK: glassmorphism-claro/index — nombres de controles
- OK: glassmorphism-claro/index — familia corporativa clara
- OK: glassmorphism-claro/devices — HTML y JavaScript
- OK: glassmorphism-claro/devices — recursos locales y enlaces
- OK: glassmorphism-claro/devices — nombres de controles
- OK: glassmorphism-claro/devices — familia corporativa clara
- OK: glassmorphism-claro/device — HTML y JavaScript
- OK: glassmorphism-claro/device — recursos locales y enlaces
- OK: glassmorphism-claro/device — nombres de controles
- OK: glassmorphism-claro/device — familia corporativa clara
- OK: glassmorphism-claro/policies — HTML y JavaScript
- OK: glassmorphism-claro/policies — recursos locales y enlaces
- OK: glassmorphism-claro/policies — nombres de controles
- OK: glassmorphism-claro/policies — familia corporativa clara
- OK: glassmorphism-claro/users — HTML y JavaScript
- OK: glassmorphism-claro/users — recursos locales y enlaces
- OK: glassmorphism-claro/users — nombres de controles
- OK: glassmorphism-claro/users — familia corporativa clara
- OK: glassmorphism-claro/settings — HTML y JavaScript
- OK: glassmorphism-claro/settings — recursos locales y enlaces
- OK: glassmorphism-claro/settings — nombres de controles
- OK: glassmorphism-claro/settings — familia corporativa clara
- OK: glassmorphism-claro/states — HTML y JavaScript
- OK: glassmorphism-claro/states — recursos locales y enlaces
- OK: glassmorphism-claro/states — nombres de controles
- OK: glassmorphism-claro/states — familia corporativa clara
- OK: glassmorphism-claro — tenant, KPIs y navegación
- OK: glassmorphism-claro — filtros, paginación, vacío, carga y error
- OK: glassmorphism-claro — detalle del equipo y confirmación de borrado
- OK: glassmorphism-claro — política: ámbitos, dominio, horario y revisión
- OK: glassmorphism-claro — usuarios: búsqueda, paginación y edición de acceso
- OK: glassmorphism-claro — rebrand y contraste insuficiente
- OK: glassmorphism-claro — API keys: crear y revocar
- OK: glassmorphism-claro — login: validación de entrada y mostrar contraseña
- OK: glassmorphism-claro — CSS local, adaptable y foco visible
- OK: glassmorphism-claro — contraste de acento sobre hover / fondo mínimo
- OK: glassmorphism-claro — tokens CSS coinciden con los defaults del tema
- OK: glassmorphism-claro — contraste Principal
- OK: glassmorphism-claro — contraste Secundario
- OK: glassmorphism-claro — contraste Sobre acento
- OK: glassmorphism-claro — contraste Acento como texto
- OK: glassmorphism-claro — contraste Secundario en hover
- OK: minimal-lineas — conjunto exacto de páginas
- OK: minimal-lineas/login — HTML y JavaScript
- OK: minimal-lineas/login — recursos locales y enlaces
- OK: minimal-lineas/login — nombres de controles
- OK: minimal-lineas/login — familia corporativa clara
- OK: minimal-lineas/index — HTML y JavaScript
- OK: minimal-lineas/index — recursos locales y enlaces
- OK: minimal-lineas/index — nombres de controles
- OK: minimal-lineas/index — familia corporativa clara
- OK: minimal-lineas/devices — HTML y JavaScript
- OK: minimal-lineas/devices — recursos locales y enlaces
- OK: minimal-lineas/devices — nombres de controles
- OK: minimal-lineas/devices — familia corporativa clara
- OK: minimal-lineas/device — HTML y JavaScript
- OK: minimal-lineas/device — recursos locales y enlaces
- OK: minimal-lineas/device — nombres de controles
- OK: minimal-lineas/device — familia corporativa clara
- OK: minimal-lineas/policies — HTML y JavaScript
- OK: minimal-lineas/policies — recursos locales y enlaces
- OK: minimal-lineas/policies — nombres de controles
- OK: minimal-lineas/policies — familia corporativa clara
- OK: minimal-lineas/users — HTML y JavaScript
- OK: minimal-lineas/users — recursos locales y enlaces
- OK: minimal-lineas/users — nombres de controles
- OK: minimal-lineas/users — familia corporativa clara
- OK: minimal-lineas/settings — HTML y JavaScript
- OK: minimal-lineas/settings — recursos locales y enlaces
- OK: minimal-lineas/settings — nombres de controles
- OK: minimal-lineas/settings — familia corporativa clara
- OK: minimal-lineas/states — HTML y JavaScript
- OK: minimal-lineas/states — recursos locales y enlaces
- OK: minimal-lineas/states — nombres de controles
- OK: minimal-lineas/states — familia corporativa clara
- OK: minimal-lineas — tenant, KPIs y navegación
- OK: minimal-lineas — filtros, paginación, vacío, carga y error
- OK: minimal-lineas — detalle del equipo y confirmación de borrado
- OK: minimal-lineas — política: ámbitos, dominio, horario y revisión
- OK: minimal-lineas — usuarios: búsqueda, paginación y edición de acceso
- OK: minimal-lineas — rebrand y contraste insuficiente
- OK: minimal-lineas — API keys: crear y revocar
- OK: minimal-lineas — login: validación de entrada y mostrar contraseña
- OK: minimal-lineas — CSS local, adaptable y foco visible
- OK: minimal-lineas — contraste de acento sobre hover / fondo mínimo
- OK: minimal-lineas — tokens CSS coinciden con los defaults del tema
- OK: minimal-lineas — contraste Principal
- OK: minimal-lineas — contraste Secundario
- OK: minimal-lineas — contraste Sobre acento
- OK: minimal-lineas — contraste Acento como texto
- OK: minimal-lineas — contraste Secundario en hover
- OK: soft-cards — conjunto exacto de páginas
- OK: soft-cards/login — HTML y JavaScript
- OK: soft-cards/login — recursos locales y enlaces
- OK: soft-cards/login — nombres de controles
- OK: soft-cards/login — familia corporativa clara
- OK: soft-cards/index — HTML y JavaScript
- OK: soft-cards/index — recursos locales y enlaces
- OK: soft-cards/index — nombres de controles
- OK: soft-cards/index — familia corporativa clara
- OK: soft-cards/devices — HTML y JavaScript
- OK: soft-cards/devices — recursos locales y enlaces
- OK: soft-cards/devices — nombres de controles
- OK: soft-cards/devices — familia corporativa clara
- OK: soft-cards/device — HTML y JavaScript
- OK: soft-cards/device — recursos locales y enlaces
- OK: soft-cards/device — nombres de controles
- OK: soft-cards/device — familia corporativa clara
- OK: soft-cards/policies — HTML y JavaScript
- OK: soft-cards/policies — recursos locales y enlaces
- OK: soft-cards/policies — nombres de controles
- OK: soft-cards/policies — familia corporativa clara
- OK: soft-cards/users — HTML y JavaScript
- OK: soft-cards/users — recursos locales y enlaces
- OK: soft-cards/users — nombres de controles
- OK: soft-cards/users — familia corporativa clara
- OK: soft-cards/settings — HTML y JavaScript
- OK: soft-cards/settings — recursos locales y enlaces
- OK: soft-cards/settings — nombres de controles
- OK: soft-cards/settings — familia corporativa clara
- OK: soft-cards/states — HTML y JavaScript
- OK: soft-cards/states — recursos locales y enlaces
- OK: soft-cards/states — nombres de controles
- OK: soft-cards/states — familia corporativa clara
- OK: soft-cards — tenant, KPIs y navegación
- OK: soft-cards — filtros, paginación, vacío, carga y error
- OK: soft-cards — detalle del equipo y confirmación de borrado
- OK: soft-cards — política: ámbitos, dominio, horario y revisión
- OK: soft-cards — usuarios: búsqueda, paginación y edición de acceso
- OK: soft-cards — rebrand y contraste insuficiente
- OK: soft-cards — API keys: crear y revocar
- OK: soft-cards — login: validación de entrada y mostrar contraseña
- OK: soft-cards — CSS local, adaptable y foco visible
- OK: soft-cards — contraste de acento sobre hover / fondo mínimo
- OK: soft-cards — tokens CSS coinciden con los defaults del tema
- OK: soft-cards — contraste Principal
- OK: soft-cards — contraste Secundario
- OK: soft-cards — contraste Sobre acento
- OK: soft-cards — contraste Acento como texto
- OK: soft-cards — contraste Secundario en hover
- OK: editorial-premium — conjunto exacto de páginas
- OK: editorial-premium/login — HTML y JavaScript
- OK: editorial-premium/login — recursos locales y enlaces
- OK: editorial-premium/login — nombres de controles
- OK: editorial-premium/login — familia corporativa clara
- OK: editorial-premium/index — HTML y JavaScript
- OK: editorial-premium/index — recursos locales y enlaces
- OK: editorial-premium/index — nombres de controles
- OK: editorial-premium/index — familia corporativa clara
- OK: editorial-premium/devices — HTML y JavaScript
- OK: editorial-premium/devices — recursos locales y enlaces
- OK: editorial-premium/devices — nombres de controles
- OK: editorial-premium/devices — familia corporativa clara
- OK: editorial-premium/device — HTML y JavaScript
- OK: editorial-premium/device — recursos locales y enlaces
- OK: editorial-premium/device — nombres de controles
- OK: editorial-premium/device — familia corporativa clara
- OK: editorial-premium/policies — HTML y JavaScript
- OK: editorial-premium/policies — recursos locales y enlaces
- OK: editorial-premium/policies — nombres de controles
- OK: editorial-premium/policies — familia corporativa clara
- OK: editorial-premium/users — HTML y JavaScript
- OK: editorial-premium/users — recursos locales y enlaces
- OK: editorial-premium/users — nombres de controles
- OK: editorial-premium/users — familia corporativa clara
- OK: editorial-premium/settings — HTML y JavaScript
- OK: editorial-premium/settings — recursos locales y enlaces
- OK: editorial-premium/settings — nombres de controles
- OK: editorial-premium/settings — familia corporativa clara
- OK: editorial-premium/states — HTML y JavaScript
- OK: editorial-premium/states — recursos locales y enlaces
- OK: editorial-premium/states — nombres de controles
- OK: editorial-premium/states — familia corporativa clara
- OK: editorial-premium — tenant, KPIs y navegación
- OK: editorial-premium — filtros, paginación, vacío, carga y error
- OK: editorial-premium — detalle del equipo y confirmación de borrado
- OK: editorial-premium — política: ámbitos, dominio, horario y revisión
- OK: editorial-premium — usuarios: búsqueda, paginación y edición de acceso
- OK: editorial-premium — rebrand y contraste insuficiente
- OK: editorial-premium — API keys: crear y revocar
- OK: editorial-premium — login: validación de entrada y mostrar contraseña
- OK: editorial-premium — CSS local, adaptable y foco visible
- OK: editorial-premium — invitar usuario conserva el contexto de empresa
- OK: editorial-premium — contraste de acento sobre hover / fondo mínimo
- OK: editorial-premium — tokens CSS coinciden con los defaults del tema
- OK: editorial-premium — contraste Principal
- OK: editorial-premium — contraste Secundario
- OK: editorial-premium — contraste Sobre acento
- OK: editorial-premium — contraste Acento como texto
- OK: editorial-premium — contraste Secundario en hover
- OK: bauhaus-geometrico — conjunto exacto de páginas
- OK: bauhaus-geometrico/login — HTML y JavaScript
- OK: bauhaus-geometrico/login — recursos locales y enlaces
- OK: bauhaus-geometrico/login — nombres de controles
- OK: bauhaus-geometrico/login — familia corporativa clara
- OK: bauhaus-geometrico/index — HTML y JavaScript
- OK: bauhaus-geometrico/index — recursos locales y enlaces
- OK: bauhaus-geometrico/index — nombres de controles
- OK: bauhaus-geometrico/index — familia corporativa clara
- OK: bauhaus-geometrico/devices — HTML y JavaScript
- OK: bauhaus-geometrico/devices — recursos locales y enlaces
- OK: bauhaus-geometrico/devices — nombres de controles
- OK: bauhaus-geometrico/devices — familia corporativa clara
- OK: bauhaus-geometrico/device — HTML y JavaScript
- OK: bauhaus-geometrico/device — recursos locales y enlaces
- OK: bauhaus-geometrico/device — nombres de controles
- OK: bauhaus-geometrico/device — familia corporativa clara
- OK: bauhaus-geometrico/policies — HTML y JavaScript
- OK: bauhaus-geometrico/policies — recursos locales y enlaces
- OK: bauhaus-geometrico/policies — nombres de controles
- OK: bauhaus-geometrico/policies — familia corporativa clara
- OK: bauhaus-geometrico/users — HTML y JavaScript
- OK: bauhaus-geometrico/users — recursos locales y enlaces
- OK: bauhaus-geometrico/users — nombres de controles
- OK: bauhaus-geometrico/users — familia corporativa clara
- OK: bauhaus-geometrico/settings — HTML y JavaScript
- OK: bauhaus-geometrico/settings — recursos locales y enlaces
- OK: bauhaus-geometrico/settings — nombres de controles
- OK: bauhaus-geometrico/settings — familia corporativa clara
- OK: bauhaus-geometrico/states — HTML y JavaScript
- OK: bauhaus-geometrico/states — recursos locales y enlaces
- OK: bauhaus-geometrico/states — nombres de controles
- OK: bauhaus-geometrico/states — familia corporativa clara
- OK: bauhaus-geometrico — tenant, KPIs y navegación
- OK: bauhaus-geometrico — filtros, paginación, vacío, carga y error
- OK: bauhaus-geometrico — detalle del equipo y confirmación de borrado
- OK: bauhaus-geometrico — política: ámbitos, dominio, horario y revisión
- OK: bauhaus-geometrico — usuarios: búsqueda, paginación y edición de acceso
- OK: bauhaus-geometrico — rebrand y contraste insuficiente
- OK: bauhaus-geometrico — API keys: crear y revocar
- OK: bauhaus-geometrico — login: validación de entrada y mostrar contraseña
- OK: bauhaus-geometrico — CSS local, adaptable y foco visible
- OK: bauhaus-geometrico — invitar usuario conserva el contexto de empresa
- OK: bauhaus-geometrico — contraste de acento sobre hover / fondo mínimo
- OK: bauhaus-geometrico — tokens CSS coinciden con los defaults del tema
- OK: bauhaus-geometrico — contraste Principal
- OK: bauhaus-geometrico — contraste Secundario
- OK: bauhaus-geometrico — contraste Sobre acento
- OK: bauhaus-geometrico — contraste Acento como texto
- OK: bauhaus-geometrico — contraste Secundario en hover
- OK: blueprint-tecnico — conjunto exacto de páginas
- OK: blueprint-tecnico/login — HTML y JavaScript
- OK: blueprint-tecnico/login — recursos locales y enlaces
- OK: blueprint-tecnico/login — nombres de controles
- OK: blueprint-tecnico/login — familia corporativa clara
- OK: blueprint-tecnico/index — HTML y JavaScript
- OK: blueprint-tecnico/index — recursos locales y enlaces
- OK: blueprint-tecnico/index — nombres de controles
- OK: blueprint-tecnico/index — familia corporativa clara
- OK: blueprint-tecnico/devices — HTML y JavaScript
- OK: blueprint-tecnico/devices — recursos locales y enlaces
- OK: blueprint-tecnico/devices — nombres de controles
- OK: blueprint-tecnico/devices — familia corporativa clara
- OK: blueprint-tecnico/device — HTML y JavaScript
- OK: blueprint-tecnico/device — recursos locales y enlaces
- OK: blueprint-tecnico/device — nombres de controles
- OK: blueprint-tecnico/device — familia corporativa clara
- OK: blueprint-tecnico/policies — HTML y JavaScript
- OK: blueprint-tecnico/policies — recursos locales y enlaces
- OK: blueprint-tecnico/policies — nombres de controles
- OK: blueprint-tecnico/policies — familia corporativa clara
- OK: blueprint-tecnico/users — HTML y JavaScript
- OK: blueprint-tecnico/users — recursos locales y enlaces
- OK: blueprint-tecnico/users — nombres de controles
- OK: blueprint-tecnico/users — familia corporativa clara
- OK: blueprint-tecnico/settings — HTML y JavaScript
- OK: blueprint-tecnico/settings — recursos locales y enlaces
- OK: blueprint-tecnico/settings — nombres de controles
- OK: blueprint-tecnico/settings — familia corporativa clara
- OK: blueprint-tecnico/states — HTML y JavaScript
- OK: blueprint-tecnico/states — recursos locales y enlaces
- OK: blueprint-tecnico/states — nombres de controles
- OK: blueprint-tecnico/states — familia corporativa clara
- OK: blueprint-tecnico — tenant, KPIs y navegación
- OK: blueprint-tecnico — filtros, paginación, vacío, carga y error
- OK: blueprint-tecnico — detalle del equipo y confirmación de borrado
- OK: blueprint-tecnico — política: ámbitos, dominio, horario y revisión
- OK: blueprint-tecnico — usuarios: búsqueda, paginación y edición de acceso
- OK: blueprint-tecnico — rebrand y contraste insuficiente
- OK: blueprint-tecnico — API keys: crear y revocar
- OK: blueprint-tecnico — login: validación de entrada y mostrar contraseña
- OK: blueprint-tecnico — CSS local, adaptable y foco visible
- OK: blueprint-tecnico — invitar usuario conserva el contexto de empresa
- OK: blueprint-tecnico — contraste de acento sobre hover / fondo mínimo
- OK: blueprint-tecnico — tokens CSS coinciden con los defaults del tema
- OK: blueprint-tecnico — contraste Principal
- OK: blueprint-tecnico — contraste Secundario
- OK: blueprint-tecnico — contraste Sobre acento
- OK: blueprint-tecnico — contraste Acento como texto
- OK: blueprint-tecnico — contraste Secundario en hover
- OK: humanista-calido — conjunto exacto de páginas
- OK: humanista-calido/login — HTML y JavaScript
- OK: humanista-calido/login — recursos locales y enlaces
- OK: humanista-calido/login — nombres de controles
- OK: humanista-calido/login — familia corporativa clara
- OK: humanista-calido/index — HTML y JavaScript
- OK: humanista-calido/index — recursos locales y enlaces
- OK: humanista-calido/index — nombres de controles
- OK: humanista-calido/index — familia corporativa clara
- OK: humanista-calido/devices — HTML y JavaScript
- OK: humanista-calido/devices — recursos locales y enlaces
- OK: humanista-calido/devices — nombres de controles
- OK: humanista-calido/devices — familia corporativa clara
- OK: humanista-calido/device — HTML y JavaScript
- OK: humanista-calido/device — recursos locales y enlaces
- OK: humanista-calido/device — nombres de controles
- OK: humanista-calido/device — familia corporativa clara
- OK: humanista-calido/policies — HTML y JavaScript
- OK: humanista-calido/policies — recursos locales y enlaces
- OK: humanista-calido/policies — nombres de controles
- OK: humanista-calido/policies — familia corporativa clara
- OK: humanista-calido/users — HTML y JavaScript
- OK: humanista-calido/users — recursos locales y enlaces
- OK: humanista-calido/users — nombres de controles
- OK: humanista-calido/users — familia corporativa clara
- OK: humanista-calido/settings — HTML y JavaScript
- OK: humanista-calido/settings — recursos locales y enlaces
- OK: humanista-calido/settings — nombres de controles
- OK: humanista-calido/settings — familia corporativa clara
- OK: humanista-calido/states — HTML y JavaScript
- OK: humanista-calido/states — recursos locales y enlaces
- OK: humanista-calido/states — nombres de controles
- OK: humanista-calido/states — familia corporativa clara
- OK: humanista-calido — tenant, KPIs y navegación
- OK: humanista-calido — filtros, paginación, vacío, carga y error
- OK: humanista-calido — detalle del equipo y confirmación de borrado
- OK: humanista-calido — política: ámbitos, dominio, horario y revisión
- OK: humanista-calido — usuarios: búsqueda, paginación y edición de acceso
- OK: humanista-calido — rebrand y contraste insuficiente
- OK: humanista-calido — API keys: crear y revocar
- OK: humanista-calido — login: validación de entrada y mostrar contraseña
- OK: humanista-calido — CSS local, adaptable y foco visible
- OK: humanista-calido — invitar usuario conserva el contexto de empresa
- OK: humanista-calido — contraste de acento sobre hover / fondo mínimo
- OK: humanista-calido — tokens CSS coinciden con los defaults del tema
- OK: humanista-calido — contraste Principal
- OK: humanista-calido — contraste Secundario
- OK: humanista-calido — contraste Sobre acento
- OK: humanista-calido — contraste Acento como texto
- OK: humanista-calido — contraste Secundario en hover
- OK: swiss-datos — conjunto exacto de páginas
- OK: swiss-datos/login — HTML y JavaScript
- OK: swiss-datos/login — recursos locales y enlaces
- OK: swiss-datos/login — nombres de controles
- OK: swiss-datos/login — familia corporativa clara
- OK: swiss-datos/index — HTML y JavaScript
- OK: swiss-datos/index — recursos locales y enlaces
- OK: swiss-datos/index — nombres de controles
- OK: swiss-datos/index — familia corporativa clara
- OK: swiss-datos/devices — HTML y JavaScript
- OK: swiss-datos/devices — recursos locales y enlaces
- OK: swiss-datos/devices — nombres de controles
- OK: swiss-datos/devices — familia corporativa clara
- OK: swiss-datos/device — HTML y JavaScript
- OK: swiss-datos/device — recursos locales y enlaces
- OK: swiss-datos/device — nombres de controles
- OK: swiss-datos/device — familia corporativa clara
- OK: swiss-datos/policies — HTML y JavaScript
- OK: swiss-datos/policies — recursos locales y enlaces
- OK: swiss-datos/policies — nombres de controles
- OK: swiss-datos/policies — familia corporativa clara
- OK: swiss-datos/users — HTML y JavaScript
- OK: swiss-datos/users — recursos locales y enlaces
- OK: swiss-datos/users — nombres de controles
- OK: swiss-datos/users — familia corporativa clara
- OK: swiss-datos/settings — HTML y JavaScript
- OK: swiss-datos/settings — recursos locales y enlaces
- OK: swiss-datos/settings — nombres de controles
- OK: swiss-datos/settings — familia corporativa clara
- OK: swiss-datos/states — HTML y JavaScript
- OK: swiss-datos/states — recursos locales y enlaces
- OK: swiss-datos/states — nombres de controles
- OK: swiss-datos/states — familia corporativa clara
- OK: swiss-datos — tenant, KPIs y navegación
- OK: swiss-datos — filtros, paginación, vacío, carga y error
- OK: swiss-datos — detalle del equipo y confirmación de borrado
- OK: swiss-datos — política: ámbitos, dominio, horario y revisión
- OK: swiss-datos — usuarios: búsqueda, paginación y edición de acceso
- OK: swiss-datos — rebrand y contraste insuficiente
- OK: swiss-datos — API keys: crear y revocar
- OK: swiss-datos — login: validación de entrada y mostrar contraseña
- OK: swiss-datos — CSS local, adaptable y foco visible
- OK: swiss-datos — invitar usuario conserva el contexto de empresa
- OK: swiss-datos — contraste de acento sobre hover / fondo mínimo
- OK: swiss-datos — tokens CSS coinciden con los defaults del tema
- OK: swiss-datos — contraste Principal
- OK: swiss-datos — contraste Secundario
- OK: swiss-datos — contraste Sobre acento
- OK: swiss-datos — contraste Acento como texto
- OK: swiss-datos — contraste Secundario en hover

