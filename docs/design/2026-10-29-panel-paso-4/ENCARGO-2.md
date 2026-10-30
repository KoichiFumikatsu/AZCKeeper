# Encargo paso 4 — vuelta 2 (cierre)

Mismas reglas de ENCARGO.md (escribir SOLO en `docs/design/2026-10-29-panel-paso-4/`, sin navegador, sin commit, no
tocar backend-v4/ ni client-v4/). Koichi decidió (2026-10-30):

1. **Tres estados de protección** en lugar de sí/no, en lista, ficha, asistente y seguimiento:
   - **Protegido**: todos sus equipos activos reportaron sus protecciones en las últimas 24 h y el plan tiene controles.
   - **Sin confirmar**: tiene equipo y plan, pero algún reporte es más viejo de 24 h o el equipo está sin conexión
     (decir cuál y desde cuándo).
   - **No protegido**: sin equipo asignado, o plan sin controles, o un control reportó fallo.
   Los datos de ejemplo deben reflejar una flota realista (la mayoría Protegido; unos pocos Sin confirmar/No
   protegido). «Altas que necesitan seguimiento» sigue mostrando solo las altas recientes no protegidas.
2. **CSV**: se guardan las filas válidas y las inválidas quedan listadas para corregir y reintentar (como hoy), con
   confirmación «Se crearán 287 personas; 13 filas quedan para corregir».
3. **Reemplazo de equipo**: el viejo primero se BLOQUEA y solo se retira cuando el bloqueo se confirma; si está sin
   conexión, queda «Retiro pendiente: se bloqueará cuando vuelva a conectarse» y aparece en la bandeja de problemas.
4. **Baja**: el acceso de la persona al panel (si tiene) se quita EN EL ACTO; las acciones sobre sus equipos siguen
   su curso y el agente conserva su identidad hasta completarlas.
5. Compactar la ficha de persona (sigue en ~3660 px): cada equipo en una fila compacta (nombre, estado, última señal,
   protección) con un solo botón «Acciones» que abre el menú de órdenes; órdenes recientes como lista de una línea
   cada una (orden · equipo · estado), detalle al abrir. Objetivo: Hoy + equipos visibles sin desplazarse mucho.

Actualizar README/REPORTE/verificar. Al terminar, seguir con `../2026-10-29-panel-paso-5/ENCARGO.md`.
