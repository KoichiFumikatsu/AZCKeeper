# Encargo paso 1 — Esqueleto de dos modos + «ver cómo va una persona hoy»

Repo: `C:\Users\FumiWork\Documents\AZCKeeper-deploy` (rama deploy-apagado = lo que corre en producción).
Trabajar SOLO dentro de `docs/design/2026-10-29-panel-paso-1/`. No tocar `backend-v4/`, `client-v4/` ni nada más. No commitear.

## Problema
El panel v4 es robusto pero hasta su desarrollador (Koichi) se traba para hacer cualquier cosa. Ya hubo tres rediseños
centrados en menú/estilo (2026-09-29, 2026-10-13, 2026-10-14) que no lo resolvieron. Leer primero
`docs/design/2026-10-14-superadmin-por-tareas.md` (diagnóstico: menú de 30 entradas, «empresa» con 3 sentidos,
Plataforma al fondo, nombres técnicos). Esta vez se diseña POR TAREA, de punta a punta.

## Decisión aprobada (modelo A: dos modos)
- **Modo Supervisor** (lo que se ve al entrar): personas y su día. Superadmin ve personas de TODOS los clientes
  juntas con filtro por cliente (hoy hay un solo cliente real). Un supervisor real de un cliente ve la MISMA pantalla
  con menos alcance (solo su gente) y sin botón Administrar.
- **Modo Administrar** (se abre a propósito con un botón claro, y se vuelve con otro): configuración. En este paso
  solo el esqueleto: NO reproducir el menú de 30 entradas. Página de entrada con tareas en lenguaje llano; las que
  no son de este paso quedan como tarjetas «Se diseña en el paso N»: Mandar órdenes a equipos (2), Qué se bloquea
  (3), Alta de persona y equipo (4), Clientes, funciones y planes (5). Versiones del agente, Salud del servidor,
  Auditoría y similares: agruparlas en «Más herramientas» (una sola tarjeta/lista), no en el primer nivel.

## Tarea del paso: «Ver cómo va una persona hoy»
1. **Cómo es hoy**: recorrer el código del panel (`backend-v4/public/*.php`, `partials/`, `assets/k3.js`, `assets/app.js`)
   y documentar el camino actual desde Inicio hasta saber si una persona está conectada, su actividad y su
   productividad de hoy: pantallas, clics, qué datos están y dónde, qué falta o confunde. Citar `archivo:línea`.
   Etiquetar afirmaciones [Seguro]/[Probable]/[Suposición].
2. **Maqueta HTML** de cómo debería ser:
   - `index.html` Inicio supervisor: buscador grande de persona; contadores (conectadas, sin conexión, ausentes,
     con alertas); lista de personas con estado ahora, llegada vs horario, tiempo activo hoy, productividad, alerta;
     filtros cliente / empresa / área / sede / estado. Estado vacío y de error explícitos.
   - `persona.html` Ficha de persona «hoy»: llegada vs horario (margen 15 min), tiempo activo/inactivo, productividad,
     apps y sitios más usados, línea de tiempo del día, sus equipos con estado (conectado, versión, protección en
     lenguaje llano), alertas. Botones de acción visibles pero marcados «paso 2». Selector de día (hoy/ayer/fecha).
   - `administrar.html` Entrada al modo Administrar descrita arriba.
   - Conmutador de demostración (visible, marcado como «solo maqueta») «Ver como: Superadmin / Supervisor de área»
     que muestra cómo cambia el alcance y desaparece Administrar.
3. **README.md**: diagnóstico del punto 1; mapa de los dos modos; qué dato de cada pantalla ya existe en la API
   (`docs/architecture/openapi-v4.yaml`, ruta exacta) y cuál sería NUEVO; decisiones tomadas; dudas para Koichi.

## Reglas
- HTML + CSS + JS vanilla, sin CDN ni dependencias, abrible con doble clic (file://). Archivos: `styles.css`, `app.js`, `data.js`.
- Estilo del v4 actual: `backend-v4/public/assets/styles.css` y logos de `backend-v4/public/assets/brand/` (copiarlos).
  Fondo SIEMPRE claro; nada dark/neón/HUD; nada genérico tipo Bootstrap/Material.
- Vocabulario único: **Cliente → Empresas → Personas**. Nunca «tenant», «enforcer», «release», «enrolado».
  Nombres llanos: «Bloqueo web» no «WebEnforcer».
- Una acción principal clara por pantalla. Español de Colombia.
- Datos ficticios realistas (Grupo AZC con ~300 personas, nombres inventados, hostnames DESKTOP-XXXX,
  octubre 2026, America/Bogota). NO datos reales ni de la BD.
- Debe verse bien a 1366×768 y en móvil (375 px) sin scroll horizontal.
- No hay navegador en tu sandbox: la validación visual y las capturas las hace Claude después. Revisar enlaces internos con un script.
- Al terminar, escribir `REPORTE.md` con lo hecho, lo que no se pudo y dudas.
