# Reporte — Plan de implementación de Supervisor

Fecha: 2026-10-30. Worktree: `C:\Users\FumiWork\Documents\AZCKeeper-supervisor`. Rama: `panel-supervisor`. Base: `a21c7f54ef485575e93e7416818eb4280d3a190e`.

## Entrega

Plan en [2026-10-30-panel-supervisor.md](../../superpowers/plans/2026-10-30-panel-supervisor.md). Incluye:

- Cabecera, Global Constraints, mapa dato → fuente con certeza y referencias al código actual, interfaces y archivos exactos.
- Trece tareas con pasos, fragmentos de código, pruebas, comandos y resultados esperados.
- Agregado multicliente paginado, autorización por cliente/sección, ficha que reutiliza ventanas/cronología/reglas y cálculo aislado de tres estados de protección.
- Pruebas de AccessScope, cobertura/fechas, protección, regresión, contrato, rendimiento y futura verificación visual.
- Despliegue previsto con `git archive`, helper y migración solo si se necesita; reversión, criterios de terminación y dudas para Koichi.

## Hallazgos que condicionan la implementación

| Certeza | Hallazgo | Tratamiento en el plan |
|---|---|---|
| [Seguro] | `assets/styles.css` no existe; el panel carga `k3.css` (`backend-v4/public/partials/head.php:8`). | Reutilizar estilo vigente y agregar CSS acotado. |
| [Seguro] | El contexto actual es de un cliente por petición; existen accesos de grupo (`backend-v4/src/AdminAuth.php:30`, `:53`). | Nuevo agregado servidor con contextos autorizados por cliente; no bucle de llamadas por persona. |
| [Seguro] | Protección actual tiene cuatro estados y vigencia de siete días (`backend-v4/src/ProtectionSummary.php:27`). | Evaluador nuevo de tres estados/24 h; conservar resumen administrativo anterior. |
| [Seguro] | El reporte de presencia usa margen configurable (`backend-v4/src/ActivityReports.php:84`, `:203`). | Supervisor aplica los 15 minutos pedidos sin reconfigurar clientes; diferencia documentada. |
| [Seguro] | Algunos campos de actividad sin registros se completan con cero (`backend-v4/src/ActivityReports.php:76`). | Exponer cobertura para distinguir medición cero, ausencia de medición, sin jornada y error. |
| [Seguro] | Problemas de Inicio no son cuatro alertas diarias unificadas (`backend-v4/src/PanelActivity.php:38`). | Reutilizar fuentes por fecha; deduplicar cumplimiento y separar conexión actual de histórica. |
| [Seguro] | No se encontró historial completo de heartbeats; `devices.last_seen_at` se actualiza (`backend-v4/src/Application.php:243`). | Desconexión histórica marcada no disponible; no reconstruirla desde huecos de actividad. |
| [Seguro] | El reporte de controles no guarda versión aplicada (`backend-v4/migrations/0005_activity_presence.sql:237`). | Confirmación conservadora combinando fecha, política y versión aplicada; limitación explícita. |
| [Seguro] | No hay modelo inequívoco de reserva/retirada pendiente en los estados actuales de devices. | No inventar enums ni excluir equipos por nombre; registrar dependencia del paso 4. |
| [Seguro] | Cronología/usos no traen la clasificación coloreada de la maqueta (`backend-v4/src/PanelActivity.php:18`, `:29`). | Mostrar datos reales con estilo neutro; no inventar categorías. |
| [Seguro] | `/users/{id}/plan` solo admite PUT (`docs/architecture/openapi-v4.yaml:8842`); el GET citado en un antecedente no existe. | Leer plan desde persona y resolución efectiva del servidor. |
| [Seguro] | Ventanas/cronología autorizan persona actual; actividad también filtra asignación histórica. | Nueva ficha omite detalle crudo si hay historia fuera de alcance; endurecer rutas antiguas queda como decisión separada para conservar el panel actual. |
| [Seguro] | El exportador lee OpenAPI YAML y genera `backend-v4/config/contract.json` (`backend-v4/tests/export-contract.py:9`, `:42`). | Corregir dirección del flujo y no generar código cliente por endpoints de panel. |
| [Seguro] | No existe `artifacts/deploy-files.sh` en este worktree. | Despliegue condicionado a recuperar/revisar helper y destino; no copiar receta antigua de devkeep a producción. |

## Verificación de esta vuelta

- Lectura estática del encargo, maquetas finales, decisiones de pasos 1/4, plan precedente, autenticación/alcances, reportes, protección, políticas, tablas, contrato, navegación, harness y runner de migraciones.
- Búsqueda de la skill `superpowers:writing-plans`: no disponible en catálogo ni en las rutas locales consultadas. Se siguió el formato solicitado y el precedente del repositorio; no se afirmó usar una skill ausente.
- Comprobación documental de rutas/referencias, estructura y alcance de archivos al finalizar. Las rutas nuevas y pruebas propuestas están identificadas como futuras.
- No se ejecutaron el exportador ni `mysql-smoke.ps1`: generan/escriben archivos bajo backend, prohibido en esta vuelta. Los PASS y presupuestos del plan son resultados esperados, no resultados obtenidos.
- Sin navegador, revisión visual, conexión a producción, despliegue, ejecución de código de implementación ni commit.

## Estado

Encargo documental completado. Solo se crean el plan y este reporte; `ENCARGO.md` era un archivo no versionado preexistente y se conserva. No se modifican `backend-v4/` ni `client-v4/`.

Pendiente para implementación: ejecutar tareas/pruebas, validar interfaz y medir capacidad. Pendiente para despliegue: recuperar helper y confirmar destino. Las dudas de producto y límites de datos quedan al final del plan; no se pidió confirmación rutinaria ni se implementaron soluciones fuera de alcance.
