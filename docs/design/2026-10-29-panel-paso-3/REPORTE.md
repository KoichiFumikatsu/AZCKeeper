# Reporte · paso 3, cierre vuelta 2

## Entregado

Ficha Hoy → Sus equipos → resumen de bloqueos con «Ver detalle» → historial unificado, tres órdenes recientes y «Ver todo». Excepciones con fecha opcional, etiquetas de vencimiento relativo, ejemplo que vence en 3 días y aviso para equipo sin conexión. Grupo de fallos en lenguaje llano. Editor con cuatro capacidades deshabilitadas y prioridades Planeado (1)…(4).

README actualizado con vencimiento aprobado, campo pendiente, tarea de recompilación y auditoría; cambios por capacidad en servidor/agente con referencias y distinción entre evidencia e implementación futura. Se retira la selección fija por empresa/cargo.

## Altura antes/después

Referencia anterior comprobada leyendo la cabecera PNG de `capturas/02-ficha-que-tiene-bloqueado.png`: **1366 × 4806 px**. Es evidencia anterior al cierre; no se generaron capturas nuevas. El encargo estima aproximadamente 4800 px a 1366 de ancho.

Después: **altura renderizada no medible sin navegador**. No se presenta una estimación como medición. Reducción estructural verificada: 6 → 3 órdenes visibles en Valeria, 2 → 1 encabezados de historial y detalle de 5 controles efectivos → resumen de 1–2 líneas con detalle cerrado. El contenido de Hoy se conserva. La altura final y el ajuste a 375 px quedan para la revisión visual indicada por las reglas base.

Para completar la medición externa: abrir persona.html?id=p001 a 1366 × 768, rol superadmin, detalles cerrados; registrar document.documentElement.scrollHeight y comparar con la captura anterior. Repetir a 375 px y comprobar scrollWidth <= clientWidth.

## Verificación y límites

`node verificar.cjs`: aprobado. Sintaxis, enlaces/recursos locales, roles, planes, composición, conflictos, edición, vencimiento inclusivo, fechas inválidas, aviso de desconexión y regresiones previas. Datos ficticios; sin red ni cambios productivos. Los cambios se restablecen al recargar/navegar.

Sin navegador, sin commit, sin escrituras fuera de esta carpeta. La verificación de geometría e interacción real queda pendiente por la prohibición expresa de navegador. Capturas existentes conservadas como antecedentes.

Cierre funcional y documental de vuelta 2 terminado antes de copiar archivos al paso 4. Dudas restantes: precedencias de empresa/cargo, identidad de ejecutables y alcance de prueba por regla; no bloquean la maqueta.
