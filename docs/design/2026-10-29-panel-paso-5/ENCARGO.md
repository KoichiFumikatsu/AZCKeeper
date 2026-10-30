# Encargo paso 5 — Clientes, qué tienen contratado, planes y quién puede hacer qué

Repo `C:\Users\FumiWork\Documents\AZCKeeper-deploy` (rama deploy-apagado = producción). Reglas base idénticas a
`docs/design/2026-10-29-panel-paso-1/ENCARGO.md`. Escribir SOLO en `docs/design/2026-10-29-panel-paso-5/`, partiendo
de una COPIA de la maqueta del paso 4 YA CERRADA (vuelta 2). Sin navegador, sin commit, no tocar backend-v4/ ni
client-v4/. Es el último paso del rediseño.

## Problema
Diagnóstico 2026-10-14 (`docs/design/2026-10-14-superadmin-por-tareas.md`): lo que un cliente puede usar está
repartido entre funciones contratadas (catálogo `backend-v4/src/Features.php`, paquetes, funciones por cliente),
planes/tiers (por defecto y por persona), roles y alcances del panel, y ajustes técnicos (cuenta de IT, PIN, Smart
App Control). Koichi no sabe en qué pantalla se cambia qué, ni ve el efecto de un cambio de plan.

## Tarea
1. **Cómo es hoy** (por código, `archivo:línea`, etiquetas): cómo se crea un cliente y su estructura
   (empresas/sedes/áreas/cargos), funciones contratadas y su efecto (`feature_not_contracted`, `/me/access`,
   `nav-access.js`), planes y su relación con las funciones, roles RBAC y alcances, cómo se da/quita a un supervisor el
   permiso de mandar órdenes, ajustes técnicos y marca por cliente. Clics hoy para: (a) cliente nuevo listo para
   inscribir equipos, (b) contratar una función nueva a un cliente y que llegue a sus equipos, (c) cambiar el plan de
   un área, (d) dar a un supervisor acceso a un área con permiso de órdenes. Fuentes: `docs/architecture/v4-clientes-funciones-accesos.md`,
   `openapi-v4.yaml`, `backend-v4/src/`, `backend-v4/public/assets/*.js`.
2. **Maqueta** (Administrar → «Clientes», «Planes», «Quién puede hacer qué», «Ajustes técnicos»):
   - **Ficha de cliente** como resumen: qué tiene contratado (funciones activas/no contratadas en llano), estructura,
     personas y equipos, plan por defecto, supervisores, marca. Una sola pantalla con secciones, no 6 pestañas.
   - **Cliente nuevo** en asistente: datos → estructura (importable) → qué contrata → plan por defecto → listo para
     inscribir (enlaza con el paso 4).
   - **Cambiar lo contratado / el plan** con **vista previa de impacto** («Activar Ubicación afecta 142 equipos; 12
     necesitan versión 4.0.30 o superior»; «Cambiar el plan de Litigios cambia estas 3 protecciones para 42 personas»).
   - **Planes**: qué protecciones incluye cada uno, comparados lado a lado, y a quién se aplican.
   - **Quién puede hacer qué**: roles en llano (Superadmin, Supervisor, Supervisor con órdenes, IT del cliente…) con
     su alcance (cliente/sede/área); dar y quitar el permiso de órdenes (decidido en paso 2: el superadmin puede
     quitarlo; nunca borrado remoto para supervisores). Vista «Ver como esta persona» para comprobar.
   - **Ajustes técnicos** (cuenta de IT, PIN de desbloqueo, Smart App Control, endurecimiento) separados, con
     explicación de riesgo en una línea cada uno.
   - Integrar el **asistente de IA** existente (`asistente-ia.html`) como función contratable por cliente (apagada
     por defecto).
   - Actualizar la tarjeta del paso 5 en `administrar.html`; dejar `administrar.html` como índice final coherente de
     los 5 pasos.
3. **README.md** (diagnóstico, qué existe en la API y qué es nuevo, decisiones, dudas para Koichi), **REPORTE.md**,
   `verificar.cjs`. Añadir al README una sección final **«Resumen del rediseño (pasos 1–5)»**: mapa de pantallas y
   lista priorizada de desarrollo de servidor/agente que exigen las maquetas.
