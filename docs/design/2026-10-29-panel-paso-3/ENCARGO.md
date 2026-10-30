# Encargo paso 3 — Qué se bloquea (reglas de sitios, apps, USB, descargas, instalación, horarios)

Repo `C:\Users\FumiWork\Documents\AZCKeeper-deploy` (rama deploy-apagado = producción). Reglas base idénticas a
`docs/design/2026-10-29-panel-paso-1/ENCARGO.md`. Escribir SOLO en `docs/design/2026-10-29-panel-paso-3/`, partiendo
de una COPIA de la maqueta del paso 2 ya cerrada (vuelta 2). Sin navegador, sin commit, no tocar backend-v4/ ni client-v4/.

## Problema
Diagnóstico 2026-10-14 (`docs/design/2026-10-14-superadmin-por-tareas.md` §0.5): lo que protege un equipo está
repartido en 5 lugares y ninguno muestra el resultado: qué compró el cliente (funciones), plan por defecto, plan de la
persona, reglas de apps y sitios, y Ajustes (cuenta de IT, PIN, Smart App Control). Koichi, desarrollador, no logra
cambiar lo que se bloquea a una persona o a un grupo sin perderse.

## Tarea
1. **Cómo es hoy** (por código, `archivo:línea`, etiquetas [Seguro]/[Probable]/[Suposición]): cómo se define hoy
   cada tipo de regla (bloqueo web, USB, descargas, instalación de programas/AppLocker, horarios de uso, bloqueo del
   cambio de hora, apagado programado semanal, ubicación), a qué nivel se asigna (cliente, empresa, área, sede,
   cargo, persona, equipo), cómo se combinan cuando hay varias (prioridad/herencia/composición), qué condiciona el
   plan/funciones del cliente, si existe modo de prueba (dry-run / «solo avisar»), y cuántos clics cuesta «bloquear
   YouTube a la persona X» y «permitir USB a toda el área Y». Fuentes: `backend-v4/src/PolicyCompiler.php`,
   `PolicyComposer.php`, `backend-v4/public/assets/*.js` (reglas, k3.js, power-schedules.js, location.js),
   `docs/architecture/openapi-v4.yaml`, `docs/architecture/v4-clientes-funciones-accesos.md`.
2. **Maqueta** (Administrar → «Qué se bloquea», y su acceso desde la ficha de persona/equipo):
   - **Resultado primero**: en la ficha de persona/equipo, un bloque «Qué tiene bloqueado y por qué» que lista cada
     protección efectiva en lenguaje llano con su **origen** («Bloqueo de YouTube — viene de la regla “Redes y video”
     aplicada a Área Litigios») y si el equipo ya la aplicó. Desde ahí: «Cambiar para esta persona» / «Ver la regla».
   - **Editor de reglas por tarea**: crear/editar una regla eligiendo (1) qué (sitios, apps, USB, descargas,
     instalación, horario), (2) a quién (cliente / empresa / área / sede / cargo / personas), (3) cuándo (siempre /
     en horario / franja). Antes de guardar: **vista previa de impacto** («afecta a 42 personas y 51 equipos; 3 ya
     tienen una excepción que gana») y opción **probar primero** (solo avisar / registrar sin bloquear) si existe.
   - **Excepciones** por persona visibles y con vencimiento opcional («permitir USB a Ana hasta el 15 de nov»).
   - **Conflictos** explicados: cuando dos reglas chocan, decir cuál gana y por qué, con la regla de combinación real.
   - Lo que el plan del cliente no incluye aparece deshabilitado con la razón en una línea.
   - **Apagado programado semanal** entra aquí como un tipo de regla de horario (la fecha única del paso 2 se enlaza).
   - Ajustes técnicos (cuenta de IT, PIN, Smart App Control) NO se mezclan con las reglas: dejarlos en «Más
     herramientas» o en el paso 5 y decir por qué en el README.
   - Actualizar la tarjeta del paso 3 en `administrar.html`.
3. **README.md** (diagnóstico, qué existe en la API y qué es nuevo, decisiones, dudas para Koichi), **REPORTE.md**,
   `verificar.cjs`.
