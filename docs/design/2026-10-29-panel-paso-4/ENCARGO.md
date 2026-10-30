# Encargo paso 4 — Alta de persona y equipo (incorporar a alguien y su computador)

Repo `C:\Users\FumiWork\Documents\AZCKeeper-deploy` (rama deploy-apagado = producción). Reglas base idénticas a
`docs/design/2026-10-29-panel-paso-1/ENCARGO.md`. Escribir SOLO en `docs/design/2026-10-29-panel-paso-4/`, partiendo
de una COPIA de la maqueta del paso 3 YA CERRADA (vuelta 2, `../2026-10-29-panel-paso-3/ENCARGO-2.md`). Sin
navegador, sin commit, no tocar backend-v4/ ni client-v4/.

## Problema
Koichi, desarrollador, se pierde para dar de alta a una persona nueva y dejar su equipo protegido. El recorrido hoy
cruza: crear/importar la persona, asignarla a cliente/sede/área, plan, aprobar la solicitud de inscripción del equipo
(enrollment_requests, con o sin cédula), clave de inscripción, instalador, asociar equipo↔persona, y verificar que las
protecciones se aplicaron. Además hay altas masivas (300 equipos) y bajas/reemplazos de equipo.

## Tarea
1. **Cómo es hoy** (por código, `archivo:línea`, etiquetas [Seguro]/[Probable]/[Suposición]): cada paso de alta de
   persona, de alta de equipo (instalador, clave de inscripción, solicitud pendiente, aprobación automática vs manual,
   emparejamiento por cédula), vínculo equipo↔persona, cambio de equipo (persona recibe un portátil nuevo), baja de
   persona (qué pasa con sus equipos y datos), y cuántos clics/pantallas cuesta hoy: (a) dar de alta 1 persona con 1
   equipo nuevo, (b) aprobar 20 solicitudes pendientes, (c) reemplazar el equipo de una persona. Fuentes:
   `backend-v4/src/` (enrollment, People, Devices), `backend-v4/public/assets/*.js`, `docs/architecture/openapi-v4.yaml`.
2. **Maqueta** (Administrar → «Personas y equipos»):
   - **Asistente «Nueva persona»** en pocos pasos: datos → dónde trabaja (cliente/sede/área, horario) → su equipo
     (ya inscrito / pendiente / todavía no instalado → instrucciones e instalador listos para enviar) → revisar. Termina
     en la ficha de la persona con el estado «Protegido: sí/no y qué falta».
   - **Solicitudes pendientes** como bandeja: agrupadas por coincidencia («14 equipos coinciden con una persona por
     cédula → Aprobar los 14»; «6 sin coincidencia → asignar»). Aprobar en lote con revisión.
   - **Cambio de equipo**: desde la ficha de persona, «Reemplazar equipo» (el viejo queda retirado, el nuevo hereda la
     persona y reglas) con lo que pasa con el viejo explicado en llano.
   - **Baja de persona**: confirmación escrita y qué pasa con sus equipos (bloquear/borrar/reasignar) y su historial.
   - **Alta masiva**: importar CSV con vista previa de errores fila por fila antes de guardar.
   - Seguimiento visible: «3 personas dadas de alta esta semana aún sin equipo protegido».
   - Nada de «tenant», «enrolar», «enforcer», «release» en la interfaz.
   - Actualizar la tarjeta del paso 4 en `administrar.html`.
3. **README.md** (diagnóstico, qué existe en la API y qué es nuevo, decisiones, dudas para Koichi), **REPORTE.md**,
   `verificar.cjs`.
