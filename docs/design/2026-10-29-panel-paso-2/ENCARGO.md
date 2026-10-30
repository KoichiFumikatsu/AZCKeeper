# Encargo paso 2 — Mandar órdenes a equipos (+ bandeja de equipos con problemas)

Repo `C:\Users\FumiWork\Documents\AZCKeeper-deploy` (rama deploy-apagado = producción). Reglas base idénticas a
`docs/design/2026-10-29-panel-paso-1/ENCARGO.md` (sin CDN, file://, datos ficticios, estilo v4 claro, vocabulario
Cliente → Empresas → Personas, nombres llanos, sin navegador, sin commit, no tocar backend-v4/ ni client-v4/).
Escribir SOLO en `docs/design/2026-10-29-panel-paso-2/`. Partir de una COPIA de la maqueta del paso 1 ya cerrada
(vuelta 3) para que el paso 2 se vea dentro del mismo panel (mismo encabezado, modos, datos).

## Contexto aprobado
- Dos modos: Supervisor (personas, al entrar) y Administrar (superadmin, se abre a propósito).
- En el paso 1 las acciones de la ficha quedaron deshabilitadas «paso 2». «Protección fallida» dejó de ser alerta del
  supervisor y debe vivir en Administrar en una **bandeja de equipos con problemas**.

## Tarea
1. **Cómo es hoy** (por código, `archivo:línea`, etiquetas [Seguro]/[Probable]/[Suposición]): qué órdenes existen
   (bloquear, desbloquear, reiniciar, apagar, borrado remoto, actualizar/aplicar reglas ahora, cancelar orden, aviso
   con cuenta regresiva opcional, desbloqueo con PIN, apagado programado), dónde se mandan, qué permisos y qué plan
   las habilitan, qué pasa si el equipo está sin conexión (cola, vencimiento), cómo se ve el resultado, cuántos clics.
   Fuentes: `backend-v4/public/assets/*.js`, `backend-v4/src/` (comandos remotos, cancelar), `docs/architecture/openapi-v4.yaml`.
2. **Maqueta**:
   - En la ficha de persona y en una ficha de equipo nueva o existente: botones de orden reales. Cada orden abre un
     diálogo corto: qué va a pasar en el equipo y para la persona, aviso con cuenta regresiva (sí/no, minutos),
     motivo opcional, y **confirmación escrita** para las destructivas (borrado remoto: escribir el nombre del
     equipo; apagar/reiniciar en horario laboral: advertencia). Mostrar solo las órdenes que el rol puede mandar;
     las que no, con la razón en una línea («Tu plan no incluye…», «Tu rol no permite…»).
   - **Seguimiento de la orden** visible sin buscar: estados en lenguaje llano (En espera porque el equipo está sin
     conexión / Entregada / Hecha / Falló con motivo / Cancelada / Venció), con hora, quién la mandó y botón Cancelar
     mientras no se haya hecho.
   - **Administrar → Equipos con problemas** (bandeja): protección fallida, sin conexión largo tiempo, versión del
     agente atrasada, órdenes fallidas o vencidas. Cada fila dice qué pasa, desde cuándo, y la acción sugerida
     (p. ej. «Aplicar reglas ahora», «Reiniciar», «Ver equipo»). Permitir seleccionar varios equipos y mandar la
     misma orden con un resumen previo («vas a reiniciar 7 equipos, 3 en horario laboral»).
   - Actualizar la tarjeta del paso 2 en `administrar.html` para que abra la bandeja real.
   - El apagado programado es una regla periódica, no una orden puntual: solo enlazarlo («Se diseña en el paso 3») salvo que el diagnóstico muestre otra cosa.
3. **README.md** (diagnóstico, qué existe en la API y qué es nuevo, decisiones, dudas para Koichi) y **REPORTE.md**.
   Verificador `verificar.cjs` de enlaces/datos como en el paso 1.
