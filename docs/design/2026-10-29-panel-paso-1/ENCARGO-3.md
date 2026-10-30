# Encargo paso 1 — vuelta 3 (cierre)

Mismas reglas de ENCARGO.md. Koichi aprobó:
1. «Protección fallida en un equipo» SALE de las alertas del supervisor (lista, contadores, KPI «Personas con alertas»,
   orden por atención, asistente). Quedan 4: llegada tarde, sin conexión en horario, posible doble empleo, app
   sospechosa. En la ficha de persona la protección del equipo sigue visible como información, no como alerta.
2. Quitar el contador duplicado «Con alertas» de la franja de estados (queda solo en los indicadores de arriba).
3. El apartado «Asistente de IA» sale de `administrar.html` como bloque largo: pasa a ser una tarjeta más que abre su
   página propia `asistente-ia.html`. Ahí la vista previa de lo que se envía va en lenguaje llano (qué datos, de qué
   alcance, qué NO se envía) y el JSON queda en un desplegable «Ver detalle técnico».
Actualizar README/REPORTE y verificar.cjs.
