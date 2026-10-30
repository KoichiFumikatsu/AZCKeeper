# Encargo — Plan de implementación: vista de supervisor + tres estados de protección

Worktree `C:\Users\FumiWork\Documents\AZCKeeper-supervisor`, rama `panel-supervisor` (sale de deploy-apagado =
producción + las maquetas). En esta vuelta **solo se escribe el plan**, no código: escribir SOLO
`docs/superpowers/plans/2026-10-30-panel-supervisor.md` y `docs/design/2026-10-30-implementacion-supervisor/REPORTE.md`.
Sin navegador, sin commit, no tocar backend-v4/ ni client-v4/ en esta vuelta.

## Qué se implementa (decidido por Koichi, 2026-10-30)
Primera entrega real del rediseño, de solo lectura:
1. **Modo Supervisor** (paso 1, maqueta final en `docs/design/2026-10-29-panel-paso-5/index.html` y `persona.html`;
   decisiones en READMEs de pasos 1 y 4): al entrar, personas de TODOS los clientes visibles para el usuario con
   filtro por cliente; ordenadas por atención; KPIs generales; 4 alertas (llegada tarde con margen 15 min, sin conexión
   en horario, posible doble empleo, app sospechosa); «Sin actividad reciente» (señal hace 5–15 min); buscador.
   Ficha de persona: Hoy/Ayer/fecha → llegada, tiempo activo/inactivo, productividad, apps y sitios más usados,
   línea de tiempo, alertas del día, su jornada; Sus equipos en filas compactas (sin el menú de órdenes: eso es paso 2);
   «Qué tiene bloqueado» resumido en 1–2 líneas.
2. **Tres estados de protección** por persona y equipo: Protegido (todos sus equipos activos reportaron protecciones
   en las últimas 24 h y el plan tiene controles) / Sin confirmar (reporte >24 h o sin conexión, decir cuál y desde
   cuándo) / No protegido (sin equipo, plan sin controles o un control reportó fallo).
3. Botón «Administrar» que lleva al panel actual sin cambiarlo. El panel actual sigue funcionando intacto.
4. Un supervisor real ve lo mismo limitado a su alcance (AccessScope existente).

Fuera de alcance: asistente de IA, órdenes (paso 2), reglas (3), altas (4), clientes/planes (5).

## Qué debe tener el plan
- Formato de `superpowers:writing-plans` (cabecera, Global Constraints, tareas con archivos exactos, interfaces,
  pasos con código y tests, comandos y salida esperada). Tareas pequeñas, cada una con su prueba.
- **Mapa dato → fuente**: para cada número/lista de la maqueta, el endpoint o tabla real (`archivo:línea`) o «NUEVO»,
  con etiqueta [Seguro]/[Probable]/[Suposición]. Reutilizar endpoints existentes antes de crear nuevos; si hace falta
  uno nuevo, agregado y paginado, que respete AccessScope y que no tarde en 300 personas / 142+ equipos.
- Dónde vive la UI: nueva página(s) PHP en `backend-v4/public/` + JS en `assets/` siguiendo el patrón actual
  (partials, nav-access.js, /me/access, estilos de `assets/styles.css`); rutas y permisos.
- Pruebas: suite `tests/mysql-smoke.ps1`, contrato `python tests/export-contract.py` → `docs/architecture/openapi-v4.yaml`,
  y pruebas de AccessScope (supervisor no ve fuera de su alcance).
- Despliegue: `git archive` + `artifacts/deploy-files.sh` + `config/migrate.php` si hay migración; sin desplegar.
- Riesgos y dudas para Koichi al final.
