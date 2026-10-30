# Panel Supervisor y tres estados de protección — Plan de implementación

> Para quien implemente: ejecutar las tareas en orden, con sus pruebas antes de continuar. Los pasos usan casillas para registrar avance. Este documento no autoriza implementar ni desplegar en la sesión de planificación.

**Objetivo:** entregar Personas hoy de todos los clientes autorizados, ficha diaria de persona y protección por persona/equipo, conservando el panel administrativo actual.

**Arquitectura:** dos páginas PHP nuevas con módulos JS del panel; un GET agregado multicliente, paginado en servidor, y un GET de resumen diario individual. Reutilizar los GET de ventanas, cronología y reglas. Reutilizar cálculos, tablas y autorización existentes mediante servicios internos; no hacer una llamada HTTP ni una consulta de reportes por cada persona. Protección nueva aislada de `ProtectionSummary`.

**Stack comprobado:** PHP, MySQL/MariaDB, módulos JavaScript sin framework, OpenAPI y exportador Python/PyYAML. Pruebas PHP en el harness MySQL existente y pruebas Node. No requiere cambio del agente .NET.

**Encargo:** `docs/design/2026-10-30-implementacion-supervisor/ENCARGO.md`. Maquetas finales: `docs/design/2026-10-29-panel-paso-5/index.html`, `persona.html`, `app.js`. Decisiones: cierres de los README de pasos 1 y 4.

**Base inspeccionada:** rama `panel-supervisor`, HEAD `a21c7f54ef485575e93e7416818eb4280d3a190e`, 2026-10-30. Referencias `archivo:línea` relativas a la raíz y válidas en esa base. No se consultó producción ni una BD real.

**Formato:** cabecera, Global Constraints, archivos, interfaces, tareas con código/pruebas/comandos/resultados, despliegue y dudas. `superpowers:writing-plans` no está disponible en el catálogo ni en las rutas locales de skills consultadas; se reproduce el formato solicitado y el precedente `docs/superpowers/plans/2026-10-27-apagado-programado.md`, sin afirmar ejecución de esa skill.

## Global Constraints

- Esta vuelta solo escribe este plan y `docs/design/2026-10-30-implementacion-supervisor/REPORTE.md`. Sin navegador, commit, despliegue, cambios en `backend-v4/` o `client-v4/`, ni ejecución de herramientas que generen archivos allí. Todos los pasos siguientes son futuros.
- Solo lectura funcional: no órdenes, altas, edición de reglas, planes/clientes, revisión de alertas ni IA. No importar `app.js` o `data.js` de la maqueta como código productivo.
- La nueva UI usa el estilo real `backend-v4/public/assets/k3.css`; **`assets/styles.css` no existe**. No crear un reemplazo global ni cambiar la paleta actual por la del contexto corporativo. Estilos adicionales bajo un contenedor propio en `supervisor.css`.
- Mantener `/`, `/index.php`, `/admin/`, `/usuarios.php`, `/miembro.php`, `/proteccion.php` y sus contratos. Solo cambiar el destino predeterminado después del login a `/supervisor.php`; Administrar vuelve al inicio administrativo actual. No redirigir `/` automáticamente: produciría un bucle al volver a administrar.
- Autorización exclusivamente del servidor. Nunca aceptar `role=supervisor`, alcance, nombres de clientes ni IDs autorizados del navegador. `AccessScope` es obligatorio también para conteos, filtros, severidades, búsqueda, orden, detalles y cursores.
- Cada cliente conserva permisos, funciones contratadas, zona horaria y alcance propios. No propagar el permiso de un cliente a otro. Cuenta de grupo solo entra a los clientes que `AdminAuth::groupTenants()` autoriza.
- Clave de persona/equipo: `(tenant_id, id)`. Todos los JOIN incluyen `tenant_id`. No identificar por nombre/correo. Nunca devolver `users.*`, políticas completas ni información de sesión en los DTO nuevos.
- Estado de conexión, protección actual y datos del día son conceptos distintos. Al elegir Ayer/fecha, equipos y protección siguen siendo actuales y llevan aviso. Nunca presentar `last_seen_at` como una hora de desconexión exacta.
- Protección no agrega una quinta alerta, no modifica KPI de alertas ni orden por atención; decisión cerrada del paso 1.
- Mantener 15 minutos para la alerta de llegada de esta vista sin modificar `punctuality_margins` ni los reportes actuales. La API actual usa margen configurable: explicar la diferencia en la UI si no coincide (véase riesgos).
- No cambiar retención, cron, algoritmos de productividad ni contrato `/client/*`. No regenerar código de `client-v4/` por rutas exclusivas del panel.
- `null`/no disponible no es cero; un error de consulta nunca se convierte en cero personas, cero alertas o No protegido. Una persona sin equipos sí es No protegido, cuando la consulta de equipos fue autorizada y completa.
- Sin supuestos sobre reserva IT o retiro pendiente: el esquema actual tiene `active`, `revoked`, `decommissioned`; no crear procesos del paso 4. No inferir reserva por hostname o por llamarse IT.
- No modificar manualmente `config/contract.json`: editar YAML y ejecutar el exportador. Mantener YAML en LF para su hash. No agregar rutas al Portal ni a la API externa.
- Cada tarea conserva la compatibilidad anterior y tiene prueba específica. Ejecutar suite completa al integrar; no ejecutar cada suite completa en cada microedición. No realizar commits ni despliegues como parte de este encargo documental.

## 1. Evidencia y discrepancias

Etiquetas: **[Seguro]** leído en código/contrato; **[Probable]** inferencia técnica que exige prueba; **[Suposición]** decisión propuesta para cubrir un vacío. Una ruta marcada **NUEVO** no existe todavía.

| Certeza | Hallazgo y consecuencia |
|---|---|
| [Seguro] | `backend-v4/public/partials/head.php:8` carga `k3.css`; `backend-v4/public/partials/layout.php:13` construye import map con versiones y CSP. Conservar ese mecanismo en el layout nuevo. |
| [Seguro] | `backend-v4/src/AdminAuth.php:30` resuelve un cliente por request; `:53` autoriza clientes de grupo. `/tenants` es catálogo administrativo, no un permiso universal para enumerar clientes desde el supervisor. |
| [Seguro] | `backend-v4/src/AdminAccess.php:48` implementa all/self/team/filters; `:76` oculta personas fuera de alcance con 404. `backend-v4/src/AccessScope.php:85` cruza filtros por AND. |
| [Seguro] | `backend-v4/src/ProtectionSummary.php:27` usa siete días y `:28` cuatro estados. `:218` prioriza falta de reporte antes de falta de controles. No reutilizar su resultado final para las reglas aprobadas. |
| [Seguro] | `backend-v4/src/ActivityReports.php:104` ya agrega actividad por persona; `:76` completa campos faltantes con cero. `backend-v4/src/ProductivityFormula.php:13` define jornada sin actividad como cero en el reporte anterior. La nueva cobertura debe conservar evidencia de medición antes de decidir presentar cero. |
| [Seguro] | `backend-v4/src/PanelActivity.php:38` mezcla problemas actuales y alertas pendientes sin acotar estas últimas al día; no es el contrato de las cuatro alertas diarias. |
| [Seguro] | `backend-v4/src/OperationsApi.php:126` permite filtrar alertas/detecciones por fecha, persona y revisión; `backend-v4/src/ComplianceWorker.php:69` puede producir detección y señal a partir de la misma app. Hay que deduplicar. |
| [Seguro] | `backend-v4/src/PanelActivity.php:29` devuelve cronología con proceso/sitio/inicio/fin, sin clasificación ni inactividad inferida. No copiar los colores semánticos ficticios de la maqueta sin una fuente adicional. |
| [Seguro] | `/users/{id}/plan` solo admite PUT (`docs/architecture/openapi-v4.yaml:8842`), aunque un README de diseño lo menciona como GET/PUT. Leer `plan_id` en `/users/{id}` y resolver el efectivo con `PersonPlans`; no inventar un GET. |
| [Seguro] | Actividad filtra asignación histórica (`backend-v4/src/ActivityReports.php:24`), mientras ventanas/cronología autorizan la persona actual (`backend-v4/src/PanelActivity.php:11`). No afirmar que ambos endpoints ya aplican idéntica política histórica. Para la ficha nueva, no pedir detalle crudo si el resumen detecta asignaciones históricas fuera del alcance. |
| [Seguro] | `devices.last_seen_at` conserva última señal (`backend-v4/src/Application.php:243`); no se encontró historial completo de heartbeats. Ayer no permite reconstruir todos los intervalos sin conexión. |
| [Seguro] | `security_reports` guarda fecha y controles, sin versión aplicada (`backend-v4/migrations/0005_activity_presence.sql:237`). Comparar también `devices.policy_version` con política compilada, sin prometer correlación criptográfica entre ambos reportes. |
| [Seguro] | `backend-v4/tests/export-contract.py:9` lee YAML y `:42` escribe JSON: la dirección es YAML → JSON, no Python → YAML. Las rutas elegidas bajo `/reports` y `/users` ya pasan su filtro `admin_roots`. |
| [Seguro] | No existe `artifacts/deploy-files.sh` en este worktree. La referencia histórica `docs/superpowers/plans/2026-10-02-funciones-por-cliente.md:879` usa devkeep; no prueba el destino vigente de producción. |
| [Probable] | Los índices actuales permiten resolver 300 personas y 142+ equipos sin migración; debe medirse. No hay benchmark realizado en esta vuelta. |

## 2. Mapa de dato → fuente

Rutas API relativas a `/v1`. Los números 300, 142+, 63,7 %, 58, 224/39/37 y los nombres de la maqueta no se convierten en constantes ni fixtures de producción.

### Lista, filtros y KPIs

| Dato visible | Fuente real y evidencia | Integración y certeza |
|---|---|---|
| Identidad de la sesión, permisos, Administrar | `/me` y `/me/access`, sesión emitida por `/auth/login`; `backend-v4/src/AdminAuth.php:108`, `backend-v4/src/AdminApi.php:658`, `backend-v4/public/assets/nav-access.js:38` | [Seguro] existentes. [Suposición] Administrar visible solo a plataforma, según cierre del paso 1; destino `/admin/`. |
| Clientes visibles y nombres del selector | `tenants`, `branding`, `company_group_members`, `access_grants`; `backend-v4/src/AdminAuth.php:53`, `:60`; `/tenants` en `backend-v4/src/AdminApi.php:232` | [Seguro] fuentes. **NUEVO** facets del agregado autorizado, no descargar catálogo con permisos elevados. |
| Nombre, empresa, sede, área, cargo | `/users`, `/users/{id}`, `/organization`; `backend-v4/src/AdminApi.php:499`, `:537`, `:571`, `:405` | [Seguro] fuentes `users`/`org_units`. **NUEVO** proyección mínima por lote; facets solo desde personas visibles. |
| Buscador, total de resultados, páginas | `/users` paginado en `backend-v4/src/AdminApi.php:143`, `:537` | [Seguro] patrón reutilizable. **NUEVO** búsqueda global, orden y cursor después de aplicar alcance; no filtrar únicamente 50 filas descargadas. |
| Fecha y corte de información | `tenants.timezone`, `Util::now()`, `backend-v4/src/ActivityCalendar.php:44` | [Seguro] fuentes. **NUEVO** `generated_at`, fecha local por fila/cliente, estado y fecha de cálculo. No fijar Bogotá si el cliente tiene otra zona. |
| Conectadas / Sin actividad reciente / Sin conexión | `/dashboard`, `/tenants/{id}/devices`; `backend-v4/src/ActivityReports.php:144`, `:158` | [Seguro] `MAX(last_seen_at)` de equipos activos, límites 300/900 segundos. **NUEVO** sumas por persona de toda la cohorte. |
| Última señal y sin equipo | `devices.last_seen_at`, `devices.status`, `devices.user_id`; `backend-v4/src/AdminApi.php:781`, `:869` | [Seguro] fuentes. **NUEVO** derivación; sin equipo es offline con explicación, no ausencia laboral. |
| Llegada, hora esperada, límite 15 minutos | `/reports/presence`; `backend-v4/src/ActivityReports.php:59`, `:203`; `ScheduleSettings.php` | [Seguro] primera actividad y horario; puede haber fallback a `check_ins`. **NUEVO** límite fijo del supervisor calculado sobre inicio UTC, fuente de llegada y margen explícitos. |
| Activo hoy y productividad en cada fila | `/reports/people`, `/users/{id}/activity`; `backend-v4/src/ActivityReports.php:104`, `:192`, `backend-v4/src/ProductivityFormula.php:27` | [Seguro] segundos y fórmula existentes. **NUEVO** cobertura para no usar ceros sintetizados como medición. |
| KPI productividad, numerador/denominador y «n de N con medición y jornada» | `day_summary`, `episode_daily`, `focus_daily`, `user_assignments`; `backend-v4/src/ActivityReports.php:63`, `backend-v4/src/ProductivityFormula.php:103` | [Seguro] fuentes. **NUEVO** suma P/suma J de personas elegibles, cobertura explícita. |
| Tendencia semanal, ambos porcentajes, diferencia pp, cohorte | `/reports/people?from&to` y fórmula anterior | [Seguro] agregados disponibles. **NUEVO** comparación de días cerrados equivalentes y misma cohorte; ver §3. No existe serie multicliente ya calculada. |
| KPI llegadas fuera del margen y primeras actividades registradas | `/reports/presence`; `backend-v4/src/ActivityReports.php:203` | [Seguro] fuentes. **NUEVO** contar personas, comparar estrictamente `first_activity > start + 900 s`, no sumar equipos ni usar `status=tarde` configurable. |
| KPI personas con alertas, personas con alta, alerta principal y «n más» | Fuentes de las cuatro filas siguientes | **NUEVO** unión deduplicada por persona/día/tipo; [Suposición] política de prioridad en §3. |
| Llegada tarde | Primera actividad e inicio de jornada anteriores | **NUEVO** alerta derivada; [Seguro] no es marcación ni conclusión laboral. |
| Sin conexión en horario | `devices` + calendario, festivos, ausencias; `backend-v4/src/PanelActivity.php:47`, `:55`; `backend-v4/src/ActivityCalendar.php:11`, `:27` | [Seguro] fuentes actuales. **NUEVO** reducir por persona y excluir almuerzo/ausencia parcial; no copiar alerta por dispositivo. |
| Posible doble empleo | `/reports/dual-job-alerts`; `backend-v4/src/OperationsApi.php:126`, `backend-v4/src/ComplianceWorker.php:7` | [Seguro] `dual_job_alerts`, severidad, evidencia, fecha y revisión. **NUEVO** mapeo de tipos/deduplicación; no nuevo detector. |
| App sospechosa | `/reports/suspicious-apps`; `backend-v4/src/OperationsApi.php:132`, `backend-v4/src/ComplianceWorker.php:69` | [Seguro] `suspicious_app_detections`, proceso, tiempos, equipo. **NUEVO** agrupación por persona/día y exclusión de duplicados `foreign_app`. |
| Orden Atención / Nombre / Productividad / Activo | `docs/design/2026-10-29-panel-paso-5/data.js:85` y datos anteriores | [Seguro] referencia visual. **NUEVO** orden total servidor, métricas nulas al final, desempate estable. |
| Estado de carga/vacío/error/sin acceso/parcial | Errores API; `backend-v4/public/assets/api.js:57`, `:111` | [Seguro] infraestructura existente; **NUEVO** estado de cada sección. No representar un 403/503 como lista vacía. |

### Ficha diaria, equipos y protección

| Dato visible | Fuente real y evidencia | Integración y certeza |
|---|---|---|
| Nombre, organización, cargo y vínculo de retorno | `/users/{id}` y `/organization`; `backend-v4/src/AdminApi.php:499` | [Seguro] fuentes. **NUEVO** proyección del resumen; URL lleva tenant e id, retorno conserva solo filtros validados. |
| Hoy/Ayer/fecha, zona, día cerrado | `tenants.timezone`, rangos `[from,to)` en `backend-v4/src/ActivityReports.php:11` | [Seguro] convención. **NUEVO** un solo día controla todos los bloques históricos; equipos actuales aparte. |
| Llegada, jornada, almuerzo, ausencia aprobada | `/reports/presence`, `/schedules`, `/absences`; `backend-v4/src/ActivityReports.php:59`, `backend-v4/src/ActivityCalendar.php:44`, `backend-v4/src/AdminApi.php:1156` | [Seguro] tablas `user_assignments`, `schedules`, `schedule_days`, `absences`, festivos. **NUEVO** DTO diario ya resuelto sin exigir acceso completo a catálogos. No devolver motivo médico de ausencia. |
| Tiempo activo, inactivo, bloqueado | `/users/{id}/activity`; `backend-v4/src/ActivityReports.php:175`, `:203` | [Seguro] segundos; bloqueado puede ser null por versión. **NUEVO** cobertura explícita del resumen. Nunca sumarlo otra vez a inactivo. |
| Productividad y tiempos P/J | `backend-v4/src/ProductivityFormula.php:27`, `:35`; `backend-v4/src/ActivityCalendar.php:27` | [Seguro] fórmula reutilizada. Día sin jornada: null; sin medición: null en nueva vista, sin cambiar reporte anterior. |
| Apps y sitios más usados, duración y barras | `/users/{id}/windows?from&to`; `backend-v4/src/PanelActivity.php:18` | [Seguro] proceso/sitio y segundos activos/inactivos. Reutilizar GET; top 10 en ficha, botón Ver más local. Barra sobre suma activa de respuesta, no otro denominador incompatible. |
| Texto «productiva/neutra», colores de usos | Maqueta `docs/design/2026-10-29-panel-paso-5/app.js:214`; clasificación en `backend-v4/src/ProductivityWorker.php:90` | [Seguro] el GET anterior no lleva categoría. No inventarla: primera entrega usa barras neutras y omite ese calificativo. [Suposición] una ampliación posterior puede reutilizar el clasificador; no es requisito funcional para mostrar usos. |
| Cronología y siguiente página | `/users/{id}/timeline?day&limit&offset`; `backend-v4/src/PanelActivity.php:27` | [Seguro] `episodes`, `next_offset`. Reutilizar paginación (200 máximo por carga propuesto), no descargar todos los episodios al entrar. Sin colores de productividad ni huecos etiquetados como inactividad. |
| Alertas del día, evidencia, conteo | Las dos rutas de cumplimiento y derivación de llegada | [Seguro] reconstruibles dentro de retención. **NUEVO** listado unificado/paginado del resumen individual. Desconexión histórica: **sin fuente suficiente**, estado `unavailable`, no cero alertas. |
| Sus equipos: nombre, versión, conexión, última señal | `/tenants/{id}/devices`, `/devices/{id}`; `backend-v4/src/AdminApi.php:781`, `backend-v4/src/EffectiveRules.php:105` | [Seguro] equipo activo y asignación vigente. **NUEVO** lote acotado en resumen; sin botones de órdenes. |
| Plan con controles efectivos | `/person-plans/available`, `/users/{id}` (`plan_id`), funciones; `backend-v4/src/PersonPlans.php:10`, `:20`, `:32` | [Seguro] plan propio/default activo ∩ `tenant_features`. Reusar SQL efectivo por lote, no llamar `functions()` 300 veces. `/users/{id}/plan` es escritura, no fuente GET. |
| Protegido / Sin confirmar / No protegido por equipo | `/devices/{id}/security`, `/devices/{id}/rules`; `backend-v4/src/AdminApi.php:878`, `backend-v4/src/EffectiveRules.php:109`, `backend-v4/src/ProtectionSummary.php:16` | [Seguro] controles/reportes/versiones. **NUEVO** evaluador de tres estados, 24 h, motivos y fechas, sin reutilizar enum anterior. |
| Protección agregada por persona, equipo causante | Equipos activos y evaluador anterior | **NUEVO** fallo > pendiente > todos protegidos; [Seguro] regla aprobada en README paso 4. Sin equipo = No protegido. |
| «Desde cuándo», último reporte y reglas pendientes | `security_reports.observed_at`, `devices.last_seen_at`, `effective_policies.created_at`, `admin_policy_recompiles`; `backend-v4/src/EffectiveRules.php:77`, `backend-v4/src/Application.php:240` | [Seguro] fechas disponibles. [Probable] versión aplicada y fecha posterior a compilación son evidencia conservadora; no una atestación de versión en el reporte. Texto «Última señal…» si no hay fecha exacta de inicio. |
| «Qué tiene bloqueado», excepciones y diferencias por equipo | `/users/{id}/rules`; `backend-v4/src/EffectiveRules.php:39`, `:69`, `:105` | [Seguro] entradas ganadoras, excluidas, conflicto y equipos. Reutilizar ruta; resumen 1–2 líneas, «reglas configuradas» separado de confirmación. Excepciones de equipo no se presentan como comunes a toda la persona. |
| Sin equipo / sin plan / permiso insuficiente / dato no retenido | Tablas y autorización anteriores, `retention_settings`; `backend-v4/src/PanelActivity.php:16`, `backend-v4/src/ActivityReports.php:69` | [Seguro] fuentes. **NUEVO** estados de cobertura diferenciados. |

La maqueta incluye IA, informes, controles de demostración, acciones, enlaces a editar reglas y flujos de altas. Están **excluidos**; no requieren fuentes ni endpoints en esta entrega. Los conteos de bloqueos/excepciones se muestran solo cuando la respuesta permite calcularlos con exactitud, nunca usando los valores ficticios de la maqueta.

## 3. Reglas funcionales e interfaces

### 3.1 Cohorte, fechas, métricas y alertas

1. Universo inicial: personas activas visibles de clientes activos, no eliminados ni archivados; excluir el tenant técnico de plataforma. Un filtro explícito a cliente inaccesible devuelve 404, no una lista vacía. [Suposición] clientes suspendidos/archivados siguen consultables en Administrar, no en Personas hoy.
2. Cohorte base = alcance autorizado ∩ búsqueda ∩ cliente/empresa/área/sede. KPIs y tres contadores se calculan sobre ella. Estado y orden modifican solo la lista. `with_alerts` es filtro superpuesto, no cuarto estado de conexión. Facets se derivan de alcance autorizado y selección organizativa; no dependen de la página.
3. Conexión: señal de algún equipo activo hace ≤300 s = `connected`; >300 y ≤900 = `away`; >900 o ninguna = `offline`. El texto visible de away es «Sin actividad reciente», con explicación de que se basa en señal del equipo. No confundirlo con `idle_seconds`.
4. Métricas: conservar `measured`, `data_through` y `calculated_at` de fuentes antes de completar números. `measured=false`, función sin contratar, datos fuera de retención y consulta fallida son motivos distintos. Para medidos con J>0: porcentaje `min(100,100*P/J)`; P y J sumados antes de dividir. Cero real con evidencia de medición sigue siendo 0 %. No recortar P por fila antes de sumarlo.
5. Cobertura: `measured_people` cuenta personas con medición y J>0; `people` incluye toda la cohorte. J=0 es sin jornada, no falla de captura. Mantener contadores de sin jornada, sin medición y sin permiso. No usar el conteo de conectadas como cobertura de actividad.
6. Tendencia [Suposición]: lunes de semana actual hasta ayer frente a esos mismos días de la semana anterior, excluyendo hoy; lunes muestra «Sin días cerrados esta semana». Incluir solo personas con J>0 y medición en ambos intervalos; devolver ambas fechas, tamaño de cohorte, P/J de cada período y pp. Festivos/ausencias salen por J. En multizona, ventanas locales por cliente, sin fingir un único corte Bogotá.
7. Llegada: primera actividad según el orden de fuentes actual (focus/resumen/episodios y fallback de puerta). Devolver `arrival_source` para decir «Primera señal registrada» si proviene de puerta. Hay alerta si J del día >0, llegó y `first_activity > scheduled_at + 900`; exactamente 15:00 no es tarde, 15:01 sí. Sin registro no es tardanza. No reescribir margen histórico/configuración existente.
8. Sin conexión en horario: solo hoy; ninguna señal de los equipos activos supera el criterio de conexión de 15 min, y el corte está dentro de una franja efectiva de trabajo después del límite de llegada. Excluir almuerzo, festivo y ausencia aprobada en ese instante. Si un equipo está offline y otro conectado, no alertar a la persona. Sin equipo cuenta como offline, con causa explícita. Esta regla evita alertar antes de que termine el margen de llegada.
9. Cumplimiento: filtrar por `day` local y retención. Lista/KPI cuentan pendientes (`reviewed=false`); ficha puede mostrar revisadas identificadas, sin botón para revisarlas. `foreign_app` se agrupa como app sospechosa y se deduplica contra detecciones de persona/día/proceso/app; `remote_desktop`, `after_hours_pattern`, `suspicious_idle` se presentan como posible doble empleo con el tipo/evidencia original. No afirmar doble empleo confirmado.
10. Severidad: conservar high/medium/low de servidor; llegada y offline son medium; detección sin severidad es medium [Suposición]. No convertir low en high para imitar la maqueta. Orden Atención: high, medium de cumplimiento, llegada, offline/en horario y away, low restante, resto; desempatar por nombre normalizado, tenant_id, user_id. Protección nunca participa. Documentar prioridad y probar combinaciones.
11. Ficha histórica: llegada y cumplimiento corresponden a fecha seleccionada. Desconexión histórica se muestra «No disponible: no se conserva historial completo de conexión». `alerts.complete=false` evita «No hubo alertas». No inferir desconexión de huecos de episodios ni aplicar conexión actual a ayer. Reconstruir ese historial sería otro alcance de captura/persistencia.
12. Horarios nocturnos: resolver día de jornada, ventanas UTC y turno iniciado el día anterior; usar `ActivityCalendar`, no comparar strings HH:mm. Probar cruce de medianoche, cambio de horario y ausencia parcial. La fecha de listado es hoy local por cliente; mostrar la jornada asociada cuando comenzó ayer.

### 3.2 Tres estados de protección

Enum nuevo `protected | unconfirmed | unprotected`. Un error o permiso faltante se expresa fuera del enum (`availability`), no como un cuarto estado de protección.

Controles candidatos: `ProtectionSummary::CONTROLS` (web, USB, descargas, instalaciones, endurecimiento). Comandos y borrado no son controles continuamente reportados. SAC, cifrado y versión del agente permanecen informativos; no alteran por sí solos este semáforo. [Suposición] es el conjunto conservador a confirmar, no un cambio de política del sistema.

Orden obligatorio por equipo:

```php
// Esquema del evaluador nuevo, entrada ya autorizada y normalizada.
if (!$input['has_controls']) return result('unprotected', 'plan_without_controls');
if ($input['failed_controls']) return result('unprotected', 'control_failed');
if ($input['offline']) return result('unconfirmed', 'device_offline');
if ($input['report_at'] === null) return result('unconfirmed', 'no_report');
if ($input['now'] - $input['report_at'] > 86400) return result('unconfirmed', 'stale_report');
if (!$input['rules_current']) return result('unconfirmed', 'rules_pending');
if (!$input['all_required_applied']) return result('unconfirmed', 'controls_unknown');
return result('protected', 'all_confirmed');
```

`result()` representa construir `state` y `reasons[]`, no una función existente. La implementación conserva **todos** los motivos relevantes, ordenados, aunque el primero determine la etiqueta. Reporte de exactamente 24 h válido; >24 h vencido. Un fallo del último reporte sigue siendo fallo aunque esté viejo u offline, hasta un reporte que lo supere. Solo evaluar controles esperados por plan ∩ cliente; un fallo antiguo de un control ya excluido se informa aparte, no invalida el plan actual.

`all_required_applied`: todos los controles esperados presentes y `state=applied`; `unknown`, `unsupported`, ausente o endurecimiento pendiente ⇒ Sin confirmar con causa. Para controles con reglas, además deben existir reglas efectivas relevantes. Un `applied` sin reglas no prueba bloqueo. No convertir un registro de endurecimiento del servidor sin fecha de reporte válida en confirmación nueva.

`rules_current`: política compilada presente, `devices.policy_version >= compiled_version`, sin recompilación pendiente y reporte observado después de `effective_policies.created_at`. Evitar verde tras reasignación: reporte posterior al inicio de asignación vigente. Si no se puede comprobar, Sin confirmar. Es una aproximación conservadora: el reporte no incluye versión aplicada.

Por persona: sin equipos activos = No protegido; si cualquier equipo = No protegido, gana; si cualquiera = Sin confirmar, gana sobre Protegido; de lo contrario todos Protegidos. Incluir equipo causante, `report_at`, `last_seen_at`, control y código de motivo. No truncar la evaluación a los equipos de la primera página.

Equipos retirados/revocados no cuentan. Reserva y retiro pendiente no tienen representación fiable específica: no ocultar un equipo `active` por su nombre. La exclusión real de reservas necesita el modelo del paso 4 y queda pendiente explícitamente.

### 3.3 Contratos nuevos y reutilizados

**NUEVO `GET /v1/reports/supervisor`**, `operationId: getSupervisorOverview`, sesión del panel únicamente. `x-permission: usuarios.ver` como requisito de personas; la autorización multicliente ocurre dentro del servicio, antes de consultar cada cliente. No exigir `requireFull()`.

Parámetros: `tenant_id?`, `company_id?`, `site_id?`, `area_id?`, `q?` (máx. 120), `state?` (`connected|away|offline|with_alerts`), `order?` (`attention|name|productivity|active`, predeterminado attention), `limit` (1–100, predeterminado 50), `cursor?`. UUID organizativo exige `tenant_id`, de lo contrario 422. No requiere `X-Tenant-ID`; si llega una cabecera residual, no restringe ni amplía el universo del agregado. La selección válida se hace con `tenant_id` y autorización del servidor.

Respuesta `SupervisorOverview`:

```text
generated_at: date-time
cohort: {people, measured_people, no_measurement_people, no_workday_people}
capabilities: {admin_href: string|null, sections: objeto de disponibilidad por cliente}
facets: {tenants:[{id,name,timezone,today}], companies:[], areas:[], sites:[]}
kpis: {productivity:{percent,P,J}, arrivals:{late,recorded,margin_minutes:15},
       alerts:{people,high_people,complete}, trend:{availability,periods,people,current,previous,delta_pp}}
counts: {connected,away,offline,availability}
total: integer                         // tras filtro de estado, antes de paginar
data: [{tenant_id,user_id,display_name,company,area,site,position,day,timezone,
        connection:{state,last_seen_at,device_count,availability},
        arrival:{first_at,source,scheduled_at,deadline_at,availability},
        activity:{active_seconds,productivity_percent,P,J,availability,data_through},
        alerts:{pending_count,highest_severity,primary,complete}}]
next_cursor: string|null
```

Objetos organizativos llevan `{id,name}`, no solo textos. En JSON final usar nombres de campos completos `workday_productive_seconds`/`workday_expected_seconds` para P/J. `availability` es `{status,reason}` con status `available|unavailable|not_authorized|not_contracted|not_retained|no_measurement|no_workday`. Valores no disponibles son null, no 0. El listado no necesita descargar todos los detalles de protección: estos aparecen en ficha y equipos, como en el cierre de la maqueta.

**NUEVO `GET /v1/users/{id}/supervisor-summary`**, `operationId: getSupervisorPersonSummary`, `X-Tenant-ID`, `day` requerido, `devices_limit` 1–100 (50), `devices_cursor?`, `alerts_limit` 1–100 (50), `alerts_cursor?`. `x-permission: usuarios.ver`. Valida persona actual con `AdminAccess::user` antes de cualquier detalle.

Respuesta `SupervisorPersonSummary`: identidad mínima; `day`, `timezone`, `generated_at`; `sections`; `raw_detail_available` (boolean); llegada, actividad/cobertura, `schedule` (franjas, almuerzo, ausencia sin motivo sensible, inicio/fin UTC y margen 15); `alerts:{data,total,pending_count,complete,unavailable_types,next_cursor}`; `current:{connection,protection,devices:{data,total,next_cursor}}`. Cada equipo incluye id, hostname, agent_version, connection, protection con reasons y fechas. Protección persona calculada sobre todos los equipos antes de paginar.

`SupervisorAlert`: `{key,kind,severity,day,reviewed,evidence,device_ids}`. `kind` solo `late_arrival|offline_in_schedule|possible_dual_job|suspicious_app`. `evidence` es proyección validada, no JSON crudo sin límite: tipos, procesos, segundos, timestamps y explicación; sin títulos de ventana completos ni secretos. Clave estable por tenant/persona/día/tipo y evidencia agrupada. Contar personas únicas en KPIs.

**Reutilizar directamente en la ficha:**

- `/users/{id}/windows?from=D&to=D+1`: usos; permiso actual `reportes.ver`.
- `/users/{id}/timeline?day=D&limit=200&offset=0`: cronología y `next_offset`; permiso actual `reportes.ver`.
- `/users/{id}/rules`: resumen de reglas, bajo permiso vigente `reglas.ver`; no crear un editor ni copiar `own_policies` al nuevo resumen.
- `/me/access` para navegación y controles de interfaz; el servidor sigue autorizando todos los GET.

El resumen reutiliza internamente las mismas fuentes de `/users/{id}/activity` y `/reports/presence`, evitando duplicar su petición HTTP. No llamar a `EffectiveRules::dispatch()` por cada fila de lista; hacerlo solo para la persona abierta. No usar catálogos `/organization` o `/schedules` como vía para eludir `requireFull` de sus rutas.

Permisos por sección: personas `usuarios.ver`; actividad/usos/cronología `reportes.ver` + productividad; llegada/jornada `presencia.ver` + presencia; equipos/protección `equipos.ver`; alertas cumplimiento `cumplimiento.ver` + cumplimiento; reglas `reglas.ver`. Resolver con `new AdminAccess($db,$context,$permission,$existingOperationId)` para conservar `Features::forOperation`; `has()` solo no valida función. Intersectar permisos de sección con cohorte base y asignaciones históricas autorizadas. Sin una sección, mostrar «Sin acceso» y excluir su evidencia/contadores del orden.

Errores: 401 sesión vencida; 403 sin permiso en ningún cliente/operación; 404 persona/cliente fuera del alcance; 422 filtros/fecha/rango inválidos o cursor vencido; 429 y 503 según infraestructura. Vacío autorizado = 200 con data vacía. Fallo general de BD = error, nunca un 200 de ceros. `Cache-Control: no-store` para DTOs del panel.

### 3.4 Agregación, autorización y rendimiento

- `AdminAuth` autentica una vez. Para el agregado deriva clientes desde cuenta autenticada: plataforma → clientes activos; normal → origen; grupo → origen más `groupTenants`. Reutilizar validaciones de estado, contraseña temporal, sesión y rate limit. No llamar `identity()` por cada persona/cliente.
- La excepción al gate inicial de `AdminApi` solo corresponde a `getSupervisorOverview`, similar a las operaciones que delegan autorización por sección. `SupervisorReadModel` debe crear `AdminAccess` por cliente antes de leer datos. La ficha sigue el constructor ordinario y exige `usuarios.ver`. Una prueba bloquea cualquier rama que despache sin estas guardas.
- `AdminAuth::context()` reconoce exclusivamente esa operación para ignorar un `X-Tenant-ID` residual y usar el origen autenticado como contexto de entrada. El resolvedor construye contextos destino con timezone/auth_revision propios y `user_id=null` en cliente extranjero, igual que la regla actual. No copiar `platform=true` a una cuenta normal.
- Leer por lotes: roster/org/asignaciones, último reporte/effective policy por equipo, planes/items/features por cliente, actividad/presencia por intervalos, señales de cumplimiento. SQL siempre parametrizado. Reutilizar `PersonPlans::effectiveSql`, `ActivityCalendar` y `ProductivityFormula`.
- Refactor acotado de `ActivityReports`: extraer obtención de filas a método que recibe rango/filtros explícitos y retorna hechos sin respuesta HTTP. Conservar salida original de `dispatch()`. No encadenar `dispatch()` mutando `$_GET`, práctica que complica aislamiento multicliente.
- Primero filtrar personas actuales con `usersSql('u')`; para hechos históricos aplicar además `usersSql('a','user_id')` a asignaciones. No recuperar historia de otra área simplemente porque la persona se movió al área actual. La política coincide con la restricción histórica del reporte existente y debe probarse.
- Detalle crudo: los GET existentes de ventanas/cronología no filtran asignación histórica. Para mantenerlos intactos, el resumen calcula `raw_detail_available=false` si alguna asignación con datos del día queda fuera del alcance histórico, y la nueva ficha muestra «Detalle no disponible para este alcance» sin llamar a esos GET. Esta limitación conservadora evita mezclar detalle más amplio con totales restringidos. No constituye un cierre de la API anterior: endurecer sus rutas directas requeriría un cambio separado de autorización, documentado en riesgos.
- Agregar hechos por persona antes de unir equipos: tres equipos no triplican segundos. Resolver latest report/policy con índices existentes, como en `backend-v4/src/ProtectionSummary.php:68`; no enviar blobs de políticas a JS. Búsqueda y scopes antes de leer episodios. La lista no consulta `episodes` crudos.
- Para 300 personas se acepta ordenar en memoria los hechos agregados autorizados, patrón ya usado por `ProtectionSummary`, y cortar a `limit+1` después de KPIs/filtros. No cargar reportes completos por persona ni paginar primero y calcular KPIs sobre 50.
- Cursor cifrado mediante `Resources::encodeCursor/decodeCursor`, atado a cuenta, revisión de autorización de **todos** los clientes candidatos, permisos/alcances, filtros, orden, fecha local y límite. Expira en 15 min. Reevaluar alcance en cada página; revocación invalida cursor. No aceptar cursor de otro usuario/filtro/cliente.
- Como atención y métricas cambian, el cursor incluye firma de la secuencia ordenada autorizada (id y claves de orden) y corte. Si cambió la secuencia, devolver 422 `supervisor_cursor_stale` y la UI reinicia conservando filtros; no prometer snapshot histórico inexistente. Datos inmóviles deben paginar sin repetidos/omisiones. No usar offset para la lista global.
- Presupuesto propuesto de aceptación: 300 personas/142 y 600 equipos, 3 clientes, datos de 14 días; p95 ≤2 s para agregado y ≤1 s para resumen individual en entorno de prueba identificado, excluyendo arranque; respuesta lista de 50 ≤250 KiB. Consultas acotadas por clientes/intervalos/profundidad team, nunca por persona/equipo. Registrar conteos reales, memoria y planes SQL antes de afirmar cumplimiento.
- No agregar índices por intuición. Inspeccionar EXPLAIN y las claves de `devices`, `security_reports`, `effective_policies`, `day_summary` y alertas. Si una migración es necesaria, registrar evidencia y usar `0071_supervisor_read_indexes.sql` solo si sigue libre al implementar. Base del plan: **sin migración**.

## 4. Archivos previstos

Todos estos cambios corresponden a la implementación futura, no a esta vuelta.

| Acción | Archivo exacto | Responsabilidad |
|---|---|---|
| Crear | `backend-v4/src/SupervisorProtection.php` | Evaluador puro de tres estados y agregación por persona. |
| Crear | `backend-v4/src/SupervisorSignals.php` | Normalización de cobertura, llegada, conexión, alertas y orden. |
| Crear | `backend-v4/src/SupervisorReadModel.php` | Contextos autorizados, consultas por lotes, KPIs y DTOs nuevos. |
| Modificar | `backend-v4/src/AdminAuth.php` | Entrada agregada y derivación controlada de contextos por cliente. |
| Modificar | `backend-v4/src/AdminApi.php` | Dispatch de dos operaciones; excepción exacta del gate agregado. |
| Modificar | `backend-v4/src/ActivityReports.php` | Extraer lectura reutilizable; preservar contratos previos. |
| Modificar | `docs/architecture/openapi-v4.yaml` | Dos rutas, parámetros y schemas nuevos, errores. |
| Regenerar | `backend-v4/config/contract.json` | Exportación desde YAML. |
| Crear | `backend-v4/public/supervisor.php` | Página Personas hoy. |
| Crear | `backend-v4/public/supervisor-persona.php` | Ficha diaria. |
| Crear | `backend-v4/public/partials/layout-supervisor.php` | Shell mínimo con marca, CSP, import map, sesión y navegación. |
| Crear | `backend-v4/public/assets/supervisor.js` | Arranque, API, lista, filtros, KPIs, fechas, ficha. |
| Crear | `backend-v4/public/assets/supervisor-view.js` | Formato y renderizado seguro, funciones comprobables con DOM mínimo. |
| Crear | `backend-v4/public/assets/supervisor.css` | Ajustes acotados; cargar después de k3.css. |
| Modificar | `backend-v4/public/index.php` | Registrar rutas PHP nuevas para router integrado. |
| Modificar | `backend-v4/public/assets/app.js` | Solo destino por defecto de los tres caminos del login/cambio de clave. |
| Modificar | `backend-v4/public/assets/nav-access.js` | Claves supervisor y helper de acceso con tenant explícito; caché por tenant. |
| Crear | `backend-v4/tests/supervisor-unit.php` | Pruebas puras de protección/señales. |
| Crear | `backend-v4/tests/supervisor.php` | Fixtures e integración en harness existente. |
| Crear | `backend-v4/tests/supervisor-panel.cjs` | DOM mínimo y API simulada, sin navegador. |
| Modificar | `backend-v4/tests/smoke.php` | Registrar pruebas nuevas, incluidas regresiones previas. |
| Modificar | `backend-v4/tests/mysql-smoke.ps1` | Switch nuevo `-SupervisorOnly`, variable y restauración. |
| Condicional | `backend-v4/migrations/0071_supervisor_read_indexes.sql` | Solo si la prueba demuestra necesidad y el número está libre. |

No modificar `ProtectionSummary.php`, `EffectiveRules.php`, `ProductivityFormula.php`, `client-v4/`, catálogos de roles ni páginas administrativas para implantar este modo. Si la extracción de lectura exige otro archivo, documentar el cambio antes de ampliar el diff.

## 5. Tareas de implementación

Los fragmentos describen código a añadir; no son afirmaciones de que esos métodos/tests existan hoy. Comandos desde la raíz del repositorio salvo indicación expresa. RED = prueba nueva falla por comportamiento ausente; GREEN = prueba y regresiones pertinentes pasan.

### Tarea 1: Contratos y arnés específico

**Archivos:** modificar `docs/architecture/openapi-v4.yaml`, `backend-v4/tests/smoke.php`, `backend-v4/tests/mysql-smoke.ps1`; crear `backend-v4/tests/supervisor.php`; regenerar `backend-v4/config/contract.json`.

**Interfaces:** contratos §3.3; funciones nuevas `supervisorContractTests(Database $db)`, `supervisorTests(Database $db)`; switch nuevo `-SupervisorOnly` (no existe en la base).

- [ ] RED: registrar una prueba que busque las dos operaciones y valide DTO de ejemplo con `Keeper\Validator::named`. Debe fallar antes de agregar contrato. Definir fixtures con UUID válidos y nulls, nunca ejemplos con campos requeridos omitidos.
- [ ] Escribir todos los schemas anidados con propiedades/tipos/requeridos, límites, enums, nullabilidad y `additionalProperties:false`, siguiendo el contrato existente. Mantener `adminSession`, sin `portalBearer`. Añadir rutas bajo roots ya exportados.

```yaml
/reports/supervisor:
  get:
    operationId: getSupervisorOverview
    security:
    - adminSession: []
    x-permission: usuarios.ver
    # Completar parámetros, x-tenant-boundary, x-resources y respuestas del §3.3.
```

- [ ] Añadir `-SupervisorOnly` siguiendo `-PowerScheduleOnly`: guardar/restaurar `KEEPER_TEST_SUPERVISOR_ONLY`, asignarla a `1/0`, registrar require y ejecutar `supervisorTests($db)` tras preparar fixtures; emitir `SUPERVISOR SMOKE PASS: N assertions`. Suite normal también debe ejecutar los tests nuevos.
- [ ] Exportar y validar sin modificar rutas `/client/*`:

```powershell
python backend-v4/tests/export-contract.py
php -l backend-v4/tests/supervisor.php
```

**Esperado:** `Exported N paths and M schemas`, JSON contiene ambas rutas y hash correcto; lint sin errores. Aún no exigir HTTP 200 hasta tarea 7. Test de contrato rechaza enum inventado, limit 0/101 y fecha mal formada.

### Tarea 2: Evaluador puro de protección

**Archivos:** crear `backend-v4/src/SupervisorProtection.php`, `backend-v4/tests/supervisor-unit.php`.

**Interfaces:** `SupervisorProtection::device(array $input): array`, `::person(array $devices, bool $hasControls): array`; cada resultado `{state,reasons}`. Entrada usa epoch UTC y campos normalizados de §3.2; no consulta BD.

- [ ] RED: pruebas de 24 h exactas y +1 s, fallo viejo, fallo + offline, sin plan, sin equipo, unknown/unsupported, dos equipos de estados distintos y reporte anterior a reasignación. El script define un helper local `expectSame` y termina con código no cero ante fallo; carga bootstrap sin conectar BD.

```php
$now = 1800000000;
$base = ['now'=>$now, 'has_controls'=>true, 'failed_controls'=>[],
  'offline'=>false, 'report_at'=>$now-86400, 'rules_current'=>true,
  'all_required_applied'=>true];
expectSame('protected', SupervisorProtection::device($base)['state']);
expectSame('unconfirmed', SupervisorProtection::device(
  array_replace($base, ['report_at'=>$now-86401]))['state']);
expectSame('unprotected', SupervisorProtection::device(
  array_replace($base, ['offline'=>true,'failed_controls'=>['web']]))['state']);
```

- [ ] Implementar precedencia y motivos, conservando datos temporales necesarios para texto. `person([] , true)` devuelve No protegido. No heredar `partial`/`no_data` ni `STALE_DAYS`.
- [ ] GREEN: `php backend-v4/tests/supervisor-unit.php` → `SUPERVISOR UNIT PASS: N assertions`. Añadir prueba de invariancia: estado de protección no modifica alertas/orden (completar al hacer tarea 3).

### Tarea 3: Conexión, señales, cobertura y orden

**Archivos:** crear `backend-v4/src/SupervisorSignals.php`; ampliar `backend-v4/tests/supervisor-unit.php`.

**Interfaces:** `connection(?int $lastSeen,int $now)`, `arrival(array $day)`, `alerts(array $facts)`, `attentionKey(array $person)`, `weighted(array $days)`; valores de salida §3.

- [ ] RED: 300/301/900/901 segundos; sin equipo; exactamente 15 min de llegada y +1 s; cero medido vs sin medición; J=0; P/J ponderado con jornadas diferentes; deduplicación foreign_app/detección; severidad low; protección independiente.

```php
expectSame('connected', SupervisorSignals::connection($now-300,$now));
expectSame('away', SupervisorSignals::connection($now-900,$now));
expectSame('offline', SupervisorSignals::connection($now-901,$now));
// 60/120 + 60/360 = 120/480 = 25 %, no promedio 33,33 %.
expectSame(25.0, SupervisorSignals::weighted([
  ['measured'=>true,'P'=>60,'J'=>120],
  ['measured'=>true,'P'=>60,'J'=>360],
])['percent']);
```

- [ ] Implementar funciones puras con tipos y orden total; usar nombres JSON completos al serializar. Porcentaje null al carecer de elegibles; los motivos faltantes no cuentan como una alerta.
- [ ] GREEN: mismo comando de unit tests. Salida PASS y resultados reproducibles sin reloj real.

### Tarea 4: Contextos multicliente y permiso base

**Archivos:** modificar `backend-v4/src/AdminAuth.php`, `backend-v4/src/AdminApi.php`; crear esqueleto `backend-v4/src/SupervisorReadModel.php`; ampliar `backend-v4/tests/supervisor.php`.

**Interfaces:** `AdminAuth::supervisorContexts(array $origin): array` devuelve contextos derivados del origen autenticado; `SupervisorReadModel::authorizedContexts(array $origin, ?string $tenantId): array`. Registrar la operación de listado delegando autorización, sin desactivar gates de otras rutas.

- [ ] RED: fixtures A/B/C, cuenta plataforma, cuenta A, cuenta grupo A+B y C ajeno; cuenta A sin `usuarios.ver`; grant de B revocado; scope self sin user extranjero. Verificar lista de contextos antes de conectar consultas de métricas.
- [ ] Implementar selección conforme §3.4, `deleted_at/archived_at/status`, lectura de timezone/auth_revision. Scope extranjero self/team sin identidad local produce conjunto vacío, nunca all.

```php
foreach ($candidateContexts as $context) {
    try { $access = new AdminAccess($db,$context,'usuarios.ver','listUsers'); }
    catch (ApiError $e) {
        if ($e->status !== 403) throw $e;
        continue; // Solo denegación; no ocultar error de base de datos.
    }
    [$where,$args] = $access->usersSql('u');
    // Guardar contexto + predicado; consultar siempre con u.tenant_id = ?.
}
```

- [ ] `context()` reconoce solo `getSupervisorOverview` para seleccionar origen sin cabecera residual; mantiene contraseña temporal y límites. `AdminApi` no debe acceder a `$this->access` sin inicializar en esa rama; el servicio hace los gates por cliente. Ficha ordinaria conserva gate del constructor.
- [ ] GREEN: `powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -SupervisorOnly` → PASS. Probar cookie inválida, tenant UUID manipulado, grupo sin grant y ruta Portal nueva = 404. No modificar lista blanca del Portal.

### Tarea 5: Lectura por lotes de actividad, jornada y cobertura

**Archivos:** modificar `backend-v4/src/ActivityReports.php`, `backend-v4/src/SupervisorReadModel.php`, `backend-v4/tests/supervisor.php`.

**Interfaces:** método nuevo `ActivityReports::readRows(string $from,string $to,array $filters): array`, conserva scope del constructor; hechos internos incluyen evidencia de medición, inicio UTC, origen de llegada, periodos de trabajo y frescura. `dispatch()` adapta esos hechos al DTO antiguo sin agregar campos incompatibles.

- [ ] RED: mismo día/persona en `/reports/people` y resumen futuro conserva segundos P/J; dos asignaciones en el día no duplican jornada; persona actual visible con asignación histórica fuera de alcance no filtra historia ajena. Fixture de retención y sin telemetría debe producir unavailable, no falso cero medido.
- [ ] Extraer lectura manteniendo calendario, SQL agrupado y limpieza de temporales en finally. Inyectar filtros explícitos; no usar `$_GET` como bus multicliente. En cada cliente serializar uso de tablas temporales antes de ir al siguiente.
- [ ] Agregar proyección del calendario para jornada/almuerzo/ausencia aprobada sin catálogos completos, con asignación vigente en fecha. Usar inicio UTC real para margen fijo. Obtener `measured` mediante presencia de hechos de captura, no por `active_seconds>0`.

```sql
-- Proyección propuesta añadida a los hechos, no una tabla nueva.
d.user_id IS NOT NULL OR ed.user_id IS NOT NULL AS measured
```

- [ ] Calcular tendencia con dos rangos y cohorte común; nunca consultar actividad una vez por persona. Cuando no hay calendario/asignación válida, devolver estado explícito, sin inventar 08:00–17:00.
- [ ] GREEN: harness `-SupervisorOnly` y regresiones de actividad al integrar tarea 11. Casos: festivo, ausencia parcial/total, almuerzo, horario nocturno, frontera de zona horaria, cambio de área y persona con dos equipos. Salida esperada PASS; DTOs antiguos sin diferencias en fixtures equivalentes.

### Tarea 6: Lectura por lotes de equipos, controles y alertas

**Archivos:** modificar `backend-v4/src/SupervisorReadModel.php`, `backend-v4/src/SupervisorSignals.php`, `backend-v4/tests/supervisor.php`.

**Interfaces:** `deviceFacts(array $context,AdminAccess $access,array $userIds)`, `complianceFacts(...)`, `personSummary(...)`; resultados normalizados consumidos por tareas 2/3, no respuestas serializadas de otros controladores.

- [ ] RED: usuario con dos equipos y uno fallido; reporte reciente con versión vieja; fallo viejo; sin reporte; sin plan; recompilación pendiente; reasignación; equipo revocado; persona sin equipo; falta permiso equipos. Alertas: una detección y foreign_app equivalentes cuentan una sola app sospechosa, dos equipos no duplican personas con alerta.
- [ ] Consultar último reporte por índice, políticas/versiones, asignación y plan efectivo por lote; evitar `PersonPlans::functions` dentro de foreach. Reusar mapa de nombres de controles, no códigos técnicos visibles. Reporte futuro inconsistente produce Sin confirmar con motivo, no frescura válida.
- [ ] Consultar `dual_job_alerts`/`suspicious_app_detections` por intervalo, scope y retención; normalizar evidencia limitada. No ejecutar `ComplianceWorker::calculate` durante GET. Indicar frescura del cálculo y ausencia de fuente histórica de conexión.
- [ ] Evaluar jornada y offline después de agrupar equipos por persona; fuera de franja o durante ausencia parcial no alertar. Filtro de retiro solo sobre estados reales.
- [ ] GREEN: harness `-SupervisorOnly`. Ayer conserva alertas fechadas y `offline_in_schedule` unavailable; alerta revisada no suma pendientes; ningún valor de protección cambia KPI de alertas. No se escriben órdenes, grants, configuración ni estados de revisión al hacer GET.

### Tarea 7: API agregada, KPIs y paginación global

**Archivos:** modificar `backend-v4/src/SupervisorReadModel.php`, `backend-v4/src/AdminApi.php`, `backend-v4/tests/supervisor.php`.

**Interfaces:** `SupervisorReadModel::overview(): array`, `::person(string $user): array` producen DTO §3.3; `AdminApi::response` aplica headers habituales.

- [ ] RED: 120 personas en tres clientes, nombres repetidos, filtros y todas las páginas; caso de alerta alta en cliente tercero debe aparecer primero. KPIs de 120 no cambian por página o filtro de estado. Cambiar búsqueda sí cambia cohorte. Verificar total distinto de length(data).
- [ ] Implementar secuencia alcance → filtros base → hechos → KPIs/conteos → estado → orden → cursor → límite. No reutilizar el roster de `/dashboard`, que se corta a 20 (`backend-v4/src/ActivityReports.php:172`).

```php
$all = $this->authorizedFacts($filters);
$summary = $this->summarize($all);
$shown = $this->filterState($all,$filters['state']);
usort($shown, $this->comparator($filters['order']));
return $summary + $this->page($shown,$cursor,$limit);
```

- [ ] Cifrar cursor con `Resources`, atar revisión de todos los candidatos y firma de orden. Rechazar cursor alterado, otra sesión, otros filtros, expirado y datos reordenados. Cursor nuevo no expone filas fuera de alcance revocado.
- [ ] Serializar secciones faltantes con null/motivo; obtener protección de todos los equipos aun con `devices_limit=1`; alertas y equipos individuales usan sus propios cursores y conteos.
- [ ] GREEN: harness `-SupervisorOnly -DebugResponses` para validación real del contrato. Salida PASS; todas las páginas cubren exactamente el conjunto esperado si la fixture no cambia. Requests a rutas antiguas siguen autorizándose igual.

### Tarea 8: Páginas, layout y entrada por login

**Archivos:** crear `backend-v4/public/supervisor.php`, `supervisor-persona.php`, `partials/layout-supervisor.php`, `assets/supervisor.js`, `assets/supervisor-view.js`, `assets/supervisor.css`, `backend-v4/tests/supervisor-panel.cjs`; modificar `backend-v4/public/index.php`, `assets/app.js`, `assets/nav-access.js`.

**Interfaces:** páginas `data-page="supervisor"|"supervisor-person"`; shell carga marca existente y CSS k3, import map versionado y módulo supervisor. Reutilizar `api`, `readSession`, `refreshCsrf`, `goToLogin`. No cargar el bootstrap administrativo de `app.js` en la nueva página.

- [ ] RED: tests estáticos/render PHP verifican rutas, asset versions/CSP, ausencia de script de maqueta, POST de órdenes y selector de rol. Test login cubre sesión ya existente, login nuevo y cambio de clave; los tres terminan en Supervisor, salvo fallback sin permiso.

```php
<?php
declare(strict_types=1);
$page = 'supervisor'; $title = 'Personas hoy';
require __DIR__ . '/partials/layout-supervisor.php';
```

- [ ] El layout nuevo sigue headers/escaping/import map de `partials/layout.php`, reutiliza `partials/brand.php` y assets de marca. No incluir `head.php` sin adaptar: carga `app.js` de forma fija. No duplicar sidebar de Administración en modo Supervisor.
- [ ] `nav-access.js`: agregar claves nuevas y helper con tenant explícito, caché indexada por tenant. Conservar `loadAccess()` sin argumentos para consumidores antiguos; no cachear globalmente la primera respuesta de cliente A para cliente B. Capabilities del agregado controlan accesos en multicliente; `/me/access` sigue como patrón de navegación por contexto.
- [ ] Cambiar únicamente destinos de login en `backend-v4/public/assets/app.js:64`, `:71`, `:95`; no cambiar `/` ni selector de tenant administrativo. Si no hay permiso de personas, mostrar acceso no disponible con enlace al panel permitido; evitar redirección circular. Administrar de plataforma lleva `/admin/`, intacto.
- [ ] GREEN:

```powershell
php -l backend-v4/public/supervisor.php
php -l backend-v4/public/supervisor-persona.php
php -l backend-v4/public/partials/layout-supervisor.php
node backend-v4/tests/supervisor-panel.cjs
```

**Esperado:** lint sin errores y `SUPERVISOR PANEL PASS`. Test HTTP desde harness obtiene ambas páginas con CSP y no incluye datos personales sin sesión; la obtención de datos se autoriza siempre en API.

### Tarea 9: Lista y KPIs

**Archivos:** modificar `backend-v4/public/assets/supervisor.js`, `supervisor-view.js`, `supervisor.css`, `backend-v4/tests/supervisor-panel.cjs`.

**Interfaces:** `renderOverview(root,dto)`, serialización de filtros URL, petición `api('GET','/reports/supervisor?...',{tenant:null})` (api actual admite tenant explícito). Un ciclo de carga no lanza una petición por persona.

- [ ] RED: DOM con 50 filas y total 300; personas A/B con mismo id/nombre conservan tenant; search encuentra persona que no estaba en página 1; estado no altera KPIs; limpiar filtros vuelve a todos los clientes; 403/503 no dibujan ceros.
- [ ] Implementar formulario/buscador, facets, tres KPIs, tres estados, orden y siguiente/anterior con pila de cursores. «Anterior» usa cursor guardado, no convierte cursor en número de página arbitrario. Búsqueda con submit y debounce corto opcional; invalidar cursor al cambiar filtros.
- [ ] Conservar filtros en URL y retorno de ficha; no persistir datos personales en localStorage. Texto con `textContent`/nodos o escape probado; nombres como `<img onerror=...>` no ejecutan HTML. Estado de carga y `aria-live` para resultados.

```js
let requestVersion = 0;
async function reloadOverview(query) {
  const version = ++requestVersion;
  const dto = await api('GET', `/reports/supervisor?${query}`, {tenant:null});
  if (version !== requestVersion) return;
  renderOverview(root, dto);
}
```

- [ ] Manejar 422 de cursor vencido/reordenado reiniciando una vez y mostrando «Los datos cambiaron; lista actualizada». No recarga infinita. Error general deja mensaje/reintento y KPIs «—». Actualizar manual, sin polling agresivo.
- [ ] GREEN: `node backend-v4/tests/supervisor-panel.cjs` → PASS. Prueba de carreras de respuestas no deja visible resultado de filtros anteriores. CSS a 375 px y 1366 px se verificará visualmente en la implementación; no afirmar validación visual con DOM mínimo.

### Tarea 10: Ficha, fechas, equipos compactos y bloqueos

**Archivos:** modificar `backend-v4/public/assets/supervisor.js`, `supervisor-view.js`, `supervisor.css`, `backend-v4/tests/supervisor-panel.cjs`.

**Interfaces:** carga de summary + ventanas + cronología + reglas, siempre `{tenant:tenantId}` explícito. Mismos `day/from/to` para bloques históricos; equipos y reglas se identifican como actuales.

- [ ] RED: Hoy→Ayer→fecha cambia todos los bloques; respuesta tardía de Hoy no pisa Ayer; summary denegado cancela render de detalles. `raw_detail_available=false` impide pedir ventanas/cronología y muestra su limitación. Con datos históricos no se muestra protección como si fuera de ayer. Sin equipos muestra No protegido; error equipos no lo hace.
- [ ] Renderizar llegada, activo, inactivo, bloqueado, P/J, jornada, alertas. Top usos y cronología reutilizan endpoints existentes. «Cargar más» sigue next_offset; no ocultar truncamiento. No inventar categorías ni intervalos sin actividad; datos no retenidos tienen explicación.
- [ ] Sus equipos en filas con hostname, versión, conexión, última señal, estado de protección y motivo. Sin menú Acciones, botones de comandos ni enlaces obligatorios a configuración. Equipos paginados no cambian protección agregada de persona.
- [ ] «Qué tiene bloqueado» resume reglas efectivas en 1–2 líneas; por ejemplo «Reglas configuradas para web y USB; 1 excepción vigente. Confirmación por equipo arriba». Si hay diferencias, decirlo; no contar política excluida o conflictos como bloqueos activos. Sin permiso reglas mostrar Sin acceso; no pedir permisos de administración adicionales.
- [ ] Conservar retorno solo a `/supervisor.php` con query de filtros permitida; nunca un `returnUrl` externo. Fecha inválida/futura no se manda como consulta silenciosamente corregida.
- [ ] GREEN: `node backend-v4/tests/supervisor-panel.cjs` → PASS, con fixtures de HTML hostil, ausencia, dato faltante, sin jornada, partial alerts y más de una página de equipos/eventos. Solo se envían GET funcionales; sesión conserva su infraestructura habitual.

### Tarea 11: Matriz de AccessScope y regresión integral

**Archivos:** ampliar `backend-v4/tests/supervisor.php`, `supervisor-panel.cjs`; completar integración en `backend-v4/tests/smoke.php`.

**Interfaces:** fixtures locales aisladas del harness, usando patrones de `backend-v4/tests/access.php:94` y las funciones `request/expect/check` de smoke. No ejecutar PHP de integración suelto sin BD/fixtures.

- [ ] Crear al menos dos clientes autorizados y uno ajeno; personas con igual nombre y UUID distintos; empresas/sedes/áreas intersecadas; jefe/equipo con ciclo y persona fuera del equipo.
- [ ] Probar todas las superficies en la matriz:

| Acceso/caso | Aserción obligatoria |
|---|---|
| Plataforma | Ve clientes activos, sin técnico/archivados/eliminados. |
| all de A | No obtiene ninguna identidad, conteo, facet ni cursor de B. |
| filters empresa + ciudad + sede + área | Unión dentro de cada lista y AND entre filtros; nunca OR entre dimensiones. |
| filters vacío | Cero filas autorizadas, no todo el cliente. |
| self | Solo la persona de la cuenta. |
| team | Persona y subordinados transitivos, ciclo acotado; otros equipos excluidos. |
| Grupo A+B, distinto rol/scope por destino | Cada sección usa permisos de su cliente, C siempre ausente. |
| Falta cumplimiento / equipos / presencia / reglas | Datos y conteos de sección omitidos/null, sin efecto oculto en orden. |
| Persona fuera de scope por URL | 404 en summary y GET reutilizados; ningún detalle parcial renderizado. |
| Persona movida de área | Historia de asignación fuera de scope no aparece. |
| Tenant/query/header manipulados | No amplían acceso; UUID mal formado 422; destino ajeno 404. |
| Cursor ajeno/revocado/cambiado | Rechazado, sin filtrar fila siguiente ni total. |
| Sin sesión / clave temporal / cliente inactivo | Mismos errores/guardas de sesión existentes. |
| `/portal/v1/reports/supervisor` | 404; no se habilitó ruta del Portal. |
| GET funcional | No cambia políticas, planes, grants, comandos, revisión de alertas ni asignaciones. Renovación de sesión es infraestructura normal. |

- [ ] Agregar regresión de los cuatro estados del resumen antiguo; no cambiar fixtures anteriores para aceptar la nueva semántica. Verificar inicio administrativo, personas, ficha, login, navegación y APIs anteriores.
- [ ] Ejecutar:

```powershell
python backend-v4/tests/export-contract.py
php backend-v4/tests/supervisor-unit.php
node backend-v4/tests/supervisor-panel.cjs
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
git diff --check
```

**Esperado:** exportador consistente; unit/panel PASS; `SMOKE PASS: N assertions` y `Isolated MySQL processes and datadir removed.`; código de salida 0. Si se prueba MariaDB, usar `-MariaDbDirectory` con la instalación disponible, sin inventar ruta. No ejecutar contra el DSN productivo.

### Tarea 12: Capacidad y consultas

**Archivos:** ampliar `backend-v4/tests/supervisor.php`; migración condicional `backend-v4/migrations/0071_supervisor_read_indexes.sql` solo con evidencia.

**Interfaces:** fixture escalable 300 personas/142 equipos y 300/600, 3 clientes/14 días. Un modo de medición del test imprime tiempos y tamaño sin datos personales; no incluir un framework de observabilidad en producción.

- [ ] Medir 20 peticiones después de calentamiento con filtros y permisos representativos; separar tiempos de agregado, summary y endpoints de detalle. Registrar PHP/BD/hardware, p50/p95, peak memory, bytes y número de consultas mediante instrumentación de la BD de prueba.
- [ ] Comparar consultas de 30/14 y 300/142 con los mismos clientes y profundidad team. El conteo no debe crecer por fila; sí puede crecer por profundidad team o clientes. Inspeccionar EXPLAIN de latest report/policy, roster y alertas; demostrar acceso por índices y ausencia de consultas N+1.
- [ ] Si falla el presupuesto §3.4, corregir consulta y volver a medir. Solo después considerar índice; documentar SQL exacto/motivo, comprobar número libre, idempotencia del runner y compatibilidad MySQL/MariaDB. No añadir una tabla de snapshots de conexión para resolverlo.
- [ ] Comando base: `powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -SupervisorOnly`. **Esperado:** PASS de integridad y evidencia de medición con p95/tamaño dentro de presupuesto. No hardcodear una velocidad que el entorno no logra: registrar bloqueo de aceptación si no cumple.

### Tarea 13: Revisión de interfaz y cierre de implementación

**Archivos:** los PHP/JS/CSS y tests nuevos de las tareas 8–10; ajustar únicamente si se detectan defectos.

- [ ] Revisar en el entorno de implementación con navegador autorizado: 1366×768 y 375 px, teclado, foco, etiquetas de selects, lectores de estado, nombres largos, 300 personas, lista vacía, error parcial, permisos restringidos y fechas. Esta revisión está pendiente; el encargo actual prohíbe navegador.
- [ ] Confirmar recorrido entrada→buscar→persona→Ayer→volver, conservación de filtros y Administrar→panel anterior. Supervisor real no ve Administrar ni obtiene datos ajenos por URL directa.
- [ ] Verificar ausencia total de IA, acciones de equipos, edición de reglas y flujos de altas. Confirmar que número de peticiones iniciales es constante por pantalla, no proporcional a personas/equipos.
- [ ] Reejecutar solo tests afectados por las correcciones; si se modifica contrato/autorización, repetir suite integral. **Esperado:** pruebas verdes y defectos visuales resueltos; limitaciones de datos indicadas en UI.

## 6. Despliegue previsto y reversión — no ejecutar en esta vuelta

**Precondición verificable:** recuperar y revisar el `artifacts/deploy-files.sh` vigente. Aquí no existe; tampoco se verificó host/docroot. La receta antigua apunta a devkeep y no debe copiarse para producción. No crear un script nuevo por este plan ni asumir SSH disponible. Esta carencia bloquea el despliegue, no la planificación.

1. Fijar revisión de implementación verificada y lista explícita de archivos de §4, excluyendo tests y documentación del docroot. El contrato JSON, PHP nuevo y assets deben publicarse juntos; nunca publicar solo el HTML.
2. Construir artefacto con `git archive` de esa revisión (archivos deben estar versionados en la fase de implementación). En Git Bash/Linux, una vez definidos los valores reales:

```bash
# REVISION_VERIFICADA y ARCHIVOS_BACKEND deben provenir de la implementación revisada.
git archive --format=tar --output=panel-supervisor.tar "$REVISION_VERIFICADA" "${ARCHIVOS_BACKEND[@]}"
tar -tf panel-supervisor.tar
test -f artifacts/deploy-files.sh
bash -n artifacts/deploy-files.sh
```

`ARCHIVOS_BACKEND` es un array Bash de rutas `backend-v4/...`, no una cadena evaluada con `eval`. Esperado: archivo tar con manifiesto exacto y sintaxis de helper válida. No comprimir `.env`, llaves, logs, fixtures ni el directorio completo del servidor.

3. Usar **`artifacts/deploy-files.sh`** con host, docroot, timestamp y parámetros comprobados en ese script recuperado. El precedente histórico ejecutaba `bash -s "$TS" ...` por SSH después de extraer `git archive`; no fijar aquí su firma sin leerlo. Registrar backup y propietario correctos de archivos Keeper, sin operaciones sobre Nextcloud ni `chown -R` global.
4. Base del plan sin migración: no ejecutar migraciones por rutina. Si tarea 12 incorpora una, desplegar SQL primero y, desde el directorio del backend destino con su usuario/configuración ya definidos:

```bash
php config/migrate.php
```

Esperado `APPLIED <migración>` o `SKIP <migración>` en repetición, salida 0. Runner real en `backend-v4/config/migrate.php:5`; mensajes en `migrations/run.php:137` y `:153`. Un fallo detiene publicación del código dependiente.

5. Verificar staging: login, listado/ficha, AccessScope de cuenta restringida, `/me/access`, panel anterior, contrato y ausencia de 500/503. No usar como prueba un token de plataforma que eluda los alcances. Aprobar promoción sobre el artefacto comprobado, no sobre archivos editados en servidor.
6. Reversión: restaurar revisión anterior de PHP/assets/JSON y destino de login mediante helper y backups revisados. Mantener índices aditivos si los hubo; no ejecutar down destructivo ni borrar tablas de datos. Validar `/`, login y rutas antiguas. No alterar agentes: no hay cambio de protocolo ni instalador.

## 7. Criterios de terminación

- [ ] Persona de cualquiera de los clientes autorizados se encuentra desde una sola lista y abre ficha en un clic; KPIs no dependen de la página.
- [ ] Los cuatro tipos de alertas tienen fuente, día, permisos, evidencia y deduplicación; desconexión histórica no disponible se indica, sin evidencia inventada.
- [ ] Tres estados de protección cubren todos los equipos activos, límite 24 h exacto, fallo prioritario y causa/equipo/fechas legibles; errores de consulta no se convierten en No protegido.
- [ ] Hoy/Ayer/fecha sincroniza todas las métricas históricas; equipos y bloqueos actuales se distinguen.
- [ ] Todos los scopes y accesos multicliente pasan matriz, incluidos conteos, facets, orden y cursores.
- [ ] Panel anterior y semántica de protección anterior conservados; entrada predeterminada nueva y Administrar funcional.
- [ ] Contrato exportado, unit/DOM/smoke completos y prueba de capacidad registrados; revisión visual posterior realizada.
- [ ] Artefacto de despliegue reproducible y reversión revisada; sin afirmar despliegue hasta ejecutarlo en otra fase autorizada.

## 8. Riesgos y dudas para Koichi

1. **Historial de desconexión:** hoy puede calcularse; para ayer no hay fuente suficiente. Propuesta de esta entrega: «No disponible» para esa clase histórica. Si se exige reconstrucción completa, requiere diseño adicional de captura/historial; no se puede resolver con UI ni inventando eventos.
2. **Margen fijo 15 vs configuración:** se respeta el encargo en Supervisor y se conserva el margen configurado del panel anterior. Puede haber distinta etiqueta de puntualidad entre ambos. Confirmar si se quiere unificar después; no alterar configuración de clientes silenciosamente.
3. **Catálogo de controles:** se proponen los cinco controles de `ProtectionSummary::CONTROLS`; SAC/cifrado informativos. `unsupported` significa Sin confirmar, no fallo confirmado. Validar este detalle de producto antes de interpretar otros módulos como protección requerida.
4. **Relación reporte/política:** versión aplicada y reporte son señales separadas. La comparación temporal/versiones es conservadora, pero no demuestra que cada control reportado pertenece a esa versión exacta. Requerir garantía más fuerte implicaría cambiar telemetría, fuera del plan.
5. **Reservas y retiros:** no existe representación inequívoca para excluir reservas o identificar retiro pendiente. La primera entrega evalúa estados reales; no adivina por nombre. Integrar ese modelo cuando se implemente paso 4.
6. **Cobertura y productividad:** el panel anterior incluye ceros por falta de actividad; la maqueta excluye faltantes de medición. Aquí se separan explícitamente y no se cambia la fórmula base. Falta de registro no prueba que la persona no trabajó; un cron atrasado limita frescura.
7. **Tendencia y severidades:** ventanas equivalentes, medidos en ambos períodos y mapeo propuesto de tipos/severidades son decisiones de implementación [Suposición]. No se inventarán alertas altas para reproducir los números de maqueta.
8. **Administrar y clientes archivados:** propuesta fiel al paso 1: botón solo plataforma y lista operativa solo clientes activos. Otros usuarios con permisos de administración conservan el panel anterior por sus rutas; confirmar si se desea un botón también para ellos en una siguiente decisión.
9. **Rendimiento:** presupuestos son objetivos sin medición actual. Mucho historial crudo, muchos clientes o grafos team profundos pueden exigir ajustes. Aprobación de capacidad depende de prueba, no del número de endpoints.
10. **Despliegue:** helper ausente y destino no verificado. No es posible dejar una orden remota ejecutable fiable sin recuperar ese artefacto y validar su contrato. No se desplegó ni se accedió al servidor en esta vuelta.
11. **Alcance histórico desigual:** las rutas antiguas de ventanas/cronología autorizan por persona actual; actividad aplica además asignación histórica. La nueva ficha evita ese detalle si contiene historia fuera de alcance. Un usuario todavía puede consultar una ruta antigua según sus permisos actuales; cambiar esa política global requiere decidirlo expresamente y probar el impacto en el panel conservado. La garantía de esta entrega es no ampliar el acceso existente y no mostrar personas fuera del alcance actual.

## 9. Decisiones de Koichi (2026-10-30) — prevalecen sobre lo anterior del plan

1. **Entrada y acceso:** al iniciar sesión, superadmin y supervisores llegan a Supervisor; IT (y demás roles de
   administración) siguen llegando al panel actual. **Todos los roles con permiso de ver personas pueden abrir la vista
   Supervisor** desde un enlace visible en el panel actual (no solo superadmin/supervisores). El panel actual no cambia.
2. **Margen de llegada tarde:** el mismo que el panel actual: margen configurado del cliente; 15 min solo si el cliente
   no tiene ninguno. Se elimina el margen fijo de 15 de la sección 8.2 del plan: una sola regla en ambas pantallas.
3. **Estado de protección solo para superadmin e IT:** los tres estados (Protegido / Sin confirmar / No protegido),
   el resumen «Qué tiene bloqueado» y los motivos de protección de equipos NO se muestran ni se envían a supervisores
   (tampoco en KPIs, filtros, orden ni respuesta de la API: el servidor los omite según permiso, no solo la UI).
   Controles que cuentan: web, USB, descargas, instalación y endurecimiento; SAC y cifrado informativos.
   Supervisores sí ven sus equipos con conexión/última señal. Incluir este caso en la matriz de AccessScope (Tarea 11).
4. **Implementación autorizada** en esta rama; despliegue NO autorizado (fase aparte).
5. Quién es «supervisor» (2026-10-30): NO se decide por nombre de rol. Supervisor = la persona tiene gente a cargo en la jerarquía de One (`users.reports_to_user_id`, misma condición que `RoleRules::matching` 'manages', alcance `team` de `AdminAccess`). Al iniciar sesión: superadmin y quien tenga al menos un reporte activo → Supervisor (limitado a su alcance); los demás (IT, Admin Empresa, RRHH, etc.) → panel actual. Todos los que tengan `usuarios.ver` ven el enlace para cambiar de pantalla. No crear rol nuevo ni reglas en datos: eso lo configura Koichi después.
