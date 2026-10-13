# Encargo 5: aspecto de K3 con el color de Keeper (solo estilos)

Decisión del dueño (2026-10-13, opción B): el panel v4 vuelve al ASPECTO de K3 — fondo claro, tarjetas blancas planas con borde fino y sombra muy suave, letra Inter, tablas compactas, menú lateral blanco y limpio con el ítem activo resaltado, botones redondeados tipo Tailwind — pero con el color propio de Keeper: índigo (`--p-50`…`--p-900`, acento `#3C4082`). PROHIBIDO usar el azul `#003A5D` / escala «corp» ni el rojo `#BE1622` de AZC Legal. Verde/ámbar/rojo apagados solo para estados.

## Referencia
- K3: `AZCKeeper_Client/Web/public/admin/partials/layout_header.php` (config Tailwind, Inter, `.sidebar-link.active`) y las páginas `AZCKeeper_Client/Web/public/admin/*.php` (clases Tailwind de tarjetas `bg-white rounded-xl border border-gray-200 shadow-sm`, tablas, KPIs, badges). Extrae de ahí espaciados, radios, sombras, tamaños de letra y grises.
- v4: `backend-v4/public/assets/k3.css` (hoja que cargan los dos layouts; variables en `:root`: `--surface:#E4E4E4`, `--relief`, `--pressed` = el neomorfismo a retirar), `styles.css`, `onboarding.css`, `device-reports.css`.

## Qué hacer
1. Reemplazar el neomorfismo por el estilo plano de K3: fondo de página gris muy claro (≈ Tailwind gray-50), tarjetas y tablas en blanco con borde gray-200 y `shadow-sm`, campos con borde fino y foco índigo, sin relieves ni sombras internas. Barra lateral blanca con borde derecho, ítem activo con fondo índigo muy suave y texto índigo.
2. Letra **Inter** servida LOCALMENTE (woff2 en `backend-v4/public/assets/fonts/`, licencia OFL incluida). La política de seguridad del panel no permite CDNs ni Google Fonts. No uses Tailwind por CDN: traduce a CSS propio.
3. Mantener contraste AA, foco visible, tamaños táctiles ≥ 44 px, y el resto del comportamiento.
4. Cambia SOLO hojas de estilo, fuentes y, si hace falta, la línea `<link>` en `partials/head.php` / `partials/layout-k3.php`. **No toques ningún .js ni otros .php**: otro agente está trabajando ahora en el panel (portal, comandos). Al commitear, añade SOLO tus archivos (`git add` con rutas explícitas); si aparece `index.lock`, espera y reintenta.
5. Capturas antes/después con el harness: desde backend-v4 `php -S 127.0.0.1:8781 -t public public/index.php` y `KEEPER_PLAYWRIGHT_MODULE="C:/Users/FumiWork/AppData/Local/Temp/claude/C--Users-FumiWork/940c289c-56f1-42c7-9403-51f24a16b3c1/scratchpad/hs/node_modules/playwright" node tests/k3-panel.cjs` (si el puerto 8780 está fijo en el test, usa una copia del test o variable; no dejes servidores corriendo al terminar: detén solo el que tú arrancaste, por PID). Guarda las capturas nuevas en `docs/design/2026-10-13-capturas-estilo-k3/` e incluye una de K3 de referencia si puedes servirla, si no, describe la comparación.

## Pruebas y reglas
- `node tests/k3-panel.cjs` debe seguir pasando (16 pantallas). Smoke PHP no debería cambiar (línea base 3967) — córrelo al final.
- Commit en español con línea final `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Sin push ni despliegue.
- Reporte breve en `C:\Users\FumiWork\AppData\Local\Temp\claude\C--Users-FumiWork\940c289c-56f1-42c7-9403-51f24a16b3c1\scratchpad\estilo-k3-report.md`. Devuelve solo: estado, commit, pruebas y dudas.
