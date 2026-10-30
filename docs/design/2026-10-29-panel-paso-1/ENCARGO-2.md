# Encargo paso 1 — vuelta 2

Misma carpeta, mismas reglas de ENCARGO.md (solo escribir aquí, sin CDN, datos ficticios, sin commit, sin navegador:
las capturas las toma Claude). Koichi revisó la vuelta 1: «bastante intuitivo»; pasar a Administrar como superadmin
le resulta claro y fácil. Aprobó lo siguiente y pregunta lo demás.

## A. Correcciones aprobadas (aplicar)
1. «Ausentes» pasa a **«Sin actividad reciente»** (señal hace 5–15 min) en contadores, filtros, insignias y textos.
2. La lista se ordena **por atención** por defecto: primero personas con alertas (las más graves arriba), luego llegadas
   tarde, luego sin actividad reciente/sin conexión en horario, luego el resto. Permitir cambiar el orden (nombre,
   productividad, tiempo activo). Explicar el orden en una línea.
3. Datos: los apellidos se repiten por bloques (`data.js:15`, toda la primera página es «Montes»). Mezclar nombres y
   apellidos para que parezcan reales.
4. **Alertas** (definición de Koichi): llegada tarde (fuera del margen de 15 min), protección fallida en un equipo,
   equipo sin conexión en horario laboral, posible doble empleo, app sospechosa. Definir severidad de cada una
   (alta/media), texto llano, y a qué pantalla lleva. Una persona puede tener varias; la lista muestra la más grave + «y N más».

## B. Preguntas de Koichi a Codex (responder en PROPUESTA-2.md y maquetar)
1. **Dashboard del supervisor con KPIs generales y KPIs por persona.** ¿Cómo lo ordenarías y cuál sería el flujo del
   supervisor (qué mira primero, qué hace después, a dónde va)? Proponer qué KPIs generales (del equipo/área/cliente,
   hoy y tendencia de la semana) y qué KPIs por persona (en la lista y en la ficha), con definición exacta y si ya
   existe en la API (`docs/architecture/openapi-v4.yaml`, ruta) o es nuevo. Maquetar en `index.html` (y `persona.html`
   si cambia). No convertirlo en una pared de gráficos: máximo lo que un supervisor lee en 10 segundos arriba.
2. **Asistente con IA** en un lugar de la página que dé sugerencias: p. ej. «la productividad del área bajó 8 % frente a
   la semana pasada», «la app más usada hoy es X», «6 abogados pasan más de 1 h en sitios no productivos: ¿ver quiénes
   son? / ¿hacer un informe?», y que pueda generar el informe. En pruebas usaría Groq; en Administrar habría un apartado
   para cambiar el proveedor de IA y guardar en la plataforma el contexto (instrucciones) que usa.
   - Dar tu opinión: ¿es útil? riesgos (datos personales enviados a un tercero, conclusiones injustas sobre personas,
     errores/alucinaciones de cifras, costo, latencia) y cómo mitigarlos.
   - Restricción de diseño fijada por Claude (aplicarla salvo objeción razonada): las cifras y la lista de personas
     las calcula SIEMPRE el servidor de forma determinista; la IA solo redacta texto a partir de agregados
     **seudonimizados** (sin nombres, cédulas ni hostnames; ids opacos que el panel resuelve localmente). «¿Ver quiénes
     son?» abre una lista filtrada calculada por el servidor, no por la IA. Cada sugerencia muestra de qué cifra sale.
     La IA nunca califica a una persona («se distrae»); describe hechos medidos.
   - Maquetar el bloque del asistente en el dashboard (texto de ejemplo fijo, marcado «Sugerencias generadas por IA,
     revísalas») con sus acciones (ver personas, generar informe, descartar), y en `administrar.html` el apartado
     «Asistente de IA»: proveedor (Groq u otro compatible OpenAI), modelo, clave (solo se escribe, nunca se muestra),
     instrucciones/contexto guardados, interruptor por cliente (apagado por defecto), y vista previa exacta de los datos
     que se enviarían.
3. Actualizar README.md (decisiones, qué es nuevo en API) y REPORTE.md.
