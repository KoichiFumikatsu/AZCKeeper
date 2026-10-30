# Encargo paso 3 — vuelta 2 (cierre)

Mismas reglas de ENCARGO.md (escribir SOLO en `docs/design/2026-10-29-panel-paso-3/`, sin navegador, sin commit, no
tocar backend-v4/ ni client-v4/). Koichi decidió (2026-10-30):

1. **Ficha de persona reordenada**: Hoy → Sus equipos → «Qué tiene bloqueado y por qué» resumido en 1–2 líneas
   («3 bloqueos activos, 1 excepción hasta el 15 nov») con «Ver detalle» que despliega el bloque completo. Historial de
   órdenes: solo las 3 últimas + «Ver todo». Medir la altura antes/después en REPORTE.md (hoy ~4800 px a 1366 ancho).
2. **Excepciones con vencimiento: SÍ se desarrollan.** Mostrar en la maqueta: crear excepción con fecha de fin
   opcional, lista de excepciones con «vence en 3 días», aviso en la bandeja de problemas cuando vence una excepción
   de un equipo sin conexión: «Vence hoy; se aplicará cuando el equipo vuelva a conectarse». En README: qué falta en
   el servidor (campo de vencimiento, tarea que recompila políticas al vencer, auditoría) — sin implementarlo.
3. **Capacidades del agente que faltan, en este orden de prioridad** (en el editor de reglas, las no disponibles
   siguen deshabilitadas con su razón, pero ahora con la etiqueta «Planeado (1)», «Planeado (2)»…):
   1. Bloqueo web solo en horario/franja.
   2. Empresa y cargo como destino de una regla.
   3. «Probar primero» (solo avisar/registrar sin bloquear) por regla.
   4. Bloquear apps concretas (AppLocker por ejecutable) — la más cara.
   README: para cada una, qué cambia en servidor y en agente (archivo:línea donde aplique), en ese orden.
4. Limpiar el título técnico del grupo en la bandeja «Falló: Aplicar reglas ahora. El equipo no pudo…» → lenguaje
   llano («No pudo aplicar las reglas: 18 equipos»).

Actualizar README/REPORTE/verificar.cjs. Al terminar, seguir con `../2026-10-29-panel-paso-4/ENCARGO.md`.
