# API externa de reportes para MOAZC/One (`/api/external/reports/*`)

Contrato `k3-reports-1`. Solo lectura. Implementa los adaptadores K3-ADP-01…06 que One
(Portal AZC, entrega E08B) documentó como brechas: las páginas del panel no tenían API.

## Identidad y ámbito

| Cabecera | Valor |
|---|---|
| `X-Bridge-Secret` | `MOAZC_BRIDGE_SECRET` del `.env` (igual que `/api/external/roster`). Sin él o distinto: 401; sin configurar: 503. |
| `X-Acting-Admin` | Correo de una cuenta **activa** del panel (`keeper_admin_accounts` ⋈ `keeper_users.email`, usuario activo). Ausente/inválido: 400 `acting_admin_required`; desconocida o inactiva: 403 `acting_admin_unknown`. |

El ámbito lo aplica **Keeper** con las reglas del panel (`admin_auth.php`): rol, `firm_scope_id`,
`area_scope_id`, `sede_scope_id`, `sociedad_scope_id` y el piso de historial
`keeper_firmas.historial_desde` (`clampFrom`). El cliente puede pedir un recorte adicional con
`scope_kind` (`firm` | `area` | `sede` | `sociedad`) + `scope_ref`:

- firma / sociedad / sede distinta a la de la cuenta → 403 `scope_forbidden`;
- una cuenta con alcance de firma o sociedad no puede pedir otra firma/sociedad aunque no tenga fijada esa dimensión → 403;
- área → **intersección** con el ámbito de la cuenta (en K3 las áreas no pertenecen a una firma; un área sin gente de la firma devuelve 200 con cifras en cero, que es la respuesta correcta);
- `scope_kind` desconocido o `scope_ref` no numérico → 400 `invalid_scope`.

Zona horaria: America/Bogota en PHP y en MySQL (`SET time_zone = '-05:00'`), como el panel. La sesión
retira `ONLY_FULL_GROUP_BY` porque las consultas del panel lo requieren.

## Sobre (envelope)

```json
{ "ok": true, "contract": "k3-reports-1", "report": "summary",
  "generated_at": "2026-09-18T13:55:00-05:00",
  "period": {"from": "2026-09-15", "to": "2026-09-18"} | null,
  "scope": {"kind": "firm", "ref": 1} | null,
  "data": { … } }
```

Errores: `{ "ok": false, "error": "...", "reason"?: "..." }` con 400 (parámetros), 401, 403, 404, 405, 503 (BD).

## Periodo

`period` = `today` (defecto) | `week` (desde el lunes) | `month` (desde el 1) | `custom` (`from`, `to` en `AAAA-MM-DD`,
`from ≤ to`, fecha de calendario válida). Siempre se eleva al piso de historial de la firma de la cuenta.
`coverage` y `alerts` no llevan periodo (`period: null`).

## Rutas

| Ruta | Origen en el panel | `data` |
|---|---|---|
| `GET /external/reports/contract` | — | `contract`, `reports`, `acting_admin_header`, `scope_kinds`, `periods`, `page_size` (50), `timezone` |
| `GET /external/reports/summary?period=&from=&to=` | `index.php` | `kpis{total_users,total_devices,online_now(15 min),active_now(2 min),users_with_activity}`, `totals{active_seconds,idle_seconds,call_seconds,work_hours_active_seconds,lunch_active_seconds,after_hours_active_seconds,first_event_at}`, `top_apps[{process_name,duration_seconds,leisure}]`, `leisure_seconds`, `productivity_pct` (null sin horario laboral), `focus_avg` (null sin datos), `alerts_pending` |
| `GET /external/reports/users?period=&from=&to=&page=` | `users.php` (con periodo en vez de hoy) | `users[{keeper_user_id,full_name,email,firm_id,area_id,sede_id,sociedad_id,active_seconds,idle_seconds,call_seconds,work_seconds,focus_score(null sin datos),status online\|away\|offline\|no_device,last_seen_at}]`, `page`, `pages`, `total` |
| `GET /external/reports/users/{id}?period=&from=&to=&ep_from=&ep_to=&ep_page=` | `user-dashboard.php` (+`?ajax=episodes`) | `user{…,firm_name,area_name,sede_name}`, `days[{day_date,active_seconds,idle_seconds,call_seconds,work_hours_active_seconds,first_event_at,last_event_at}]`, `focus[{day_date,focus_score,productivity_pct,constancy_pct,context_switches,deep_work_seconds,distraction_seconds,punctuality_minutes}]`, `top_apps`, `episodes[{day_date,start_at,end_at,process_name,app_name,window_title,duration_seconds,is_in_call}]` (más recientes primero; rango `ep_from`/`ep_to`, 30 días por defecto, también elevado al piso), `episodes_page`, `episodes_pages`, `episodes_range`. Fuera del ámbito o inexistente: **404** (no se revela cuál). |
| `GET /external/reports/productivity?period=&from=&to=&page=&sort=asc\|desc` | `productivity.php` (`ProductivityRepo`) | `rows[{keeper_user_id,full_name,firm_id,area_id,focus_score,productivity_pct,constancy_pct,deep_work_seconds,context_switches,distraction_seconds,punctuality_minutes,days}]`, `weights` (pesos vigentes del Focus Score), `deep_work_threshold_minutes`, `kpis{users,focus_score,productivity_pct,constancy_pct,context_switches,deep_work_seconds,punctuality_minutes}` (`getGlobalKPIs`; `null` sin días con métricas), `trends[{week_start,focus_score,productivity_pct,constancy_pct,context_switches,users}]` (`getWeeklyTrends`, 8 semanas hasta el fin del periodo, sobre el piso), `page`, `pages`, `total`. Periodo por defecto `week`. |
| `GET /external/reports/coverage` | `install-coverage.php` sobre `keeper_users` del ámbito (sin cruzar el legacy) | `rows[{keeper_user_id,full_name,email,last_seen_at,latest_client_version,active_devices,coverage ok\|stale\|outdated\|no_device,exempt,note}]`, `heartbeat_days`, `active_release`. «Nunca instalado» lo deduce el consumidor comparando su plantilla con este listado. |
| `GET /external/reports/presence?period=&from=&to=` | `sedes-dashboard.php` (tarjetas) **añadiendo** el recorte por ámbito que la página no tiene | `sites[{sede_id,name,users,active,away,offline,without_device,users_with_activity,active_seconds,idle_seconds,work_seconds,call_seconds,first_login_at,leisure_seconds}]` |
| `GET /external/reports/alerts?type=&severity=&status=pending\|reviewed&page=` | `dual-job-alerts.php` (`ProductivityRepo::getAlerts`) | `alerts[{id,keeper_user_id,full_name,day_date,alert_type,severity,is_reviewed,reviewed_at (ISO o null),evidence}]`, `page`, `pages`, `total`. **Solo lectura**: revisar y anotar sigue en el panel. |

Instantes en ISO 8601 con desfase `-05:00`. Fechas `AAAA-MM-DD`. Enteros como enteros; «sin dato» como `null`, nunca `0`.

## Pruebas

`php tests/external_reports/run.php` crea la base `keeper_eval` desde `migrations/`, la siembra con datos
sintéticos (correos `.invalid`), levanta `php -S` con un `.env` propio (`KEEPER_ENV_FILE`, nunca el `Web/.env` habitual) y ejecuta 77 comprobaciones de autorización, ámbito, periodo,
piso de historial, paginación y forma de cada informe. `--serve` deja el entorno arriba para que un consumidor
(One) valide su lector contra él. Variables: `KEEPER_TEST_DB_HOST/USER/PASS/NAME`, `KEEPER_TEST_PORT`,
`KEEPER_TEST_SECRET`. Nunca usa producción ni la BD legacy.

## Sincronización compartida (K3-ADP-07/08/09) — `src/Endpoints/ExternalAssignments.php`

Misma identidad (`X-Bridge-Secret` + `X-Acting-Admin`). Migración `migrations/add_assignment_source.sql`:
`keeper_user_assignments.source` (`legacy` | `panel` | `one`), `source_version`, `source_applied_at`.

| Ruta | Qué hace |
|---|---|
| `PUT /external/assignments/{legacy_employee_id}` body `{firm_id, area_id, cargo_id, sede_id, sociedad_id, intent_version}` (todos los campos presentes; `null` para vaciar) | Aplica la asignación **actuando** como la cuenta (rol `superadmin` o `admin`; si tiene alcance, la persona debe estar dentro → si no, **404 `not_mapped`** sin revelar). Respuesta 200 `{result, keeper_user_id, legacy_employee_id, keeper_values{firm_id,area_id,cargo_id,sede_id,sociedad_id}, manual_override, source, source_version, updated_at}` con `result` = `applied` (queda `source='one'`), `unchanged` (misma `intent_version` ya aplicada: idempotente), `kept_override` (excepción manual del panel: **no se toca**, se devuelven los valores de Keeper). 404 `not_mapped` si no hay usuario Keeper con ese `legacy_employee_id`; 400 `invalid_intent_version` / `missing_field` / `invalid_field`; 422 `unknown_reference` con `field`. Auditoría en `keeper_audit_log` (`external_assignment_applied` / `_kept_override`). |
| `GET /external/assignments?updated_since=ISO&page=` | `assignments[{keeper_user_id, legacy_employee_id, cc, email, full_name, status, employment_status, firm_id, area_id, cargo_id, sede_id, sociedad_id, manual_override, source, source_version, updated_at}]`, 200 por página, en el ámbito de la cuenta, ordenado por `updated_at`. |
| `GET /external/roster` (K3-ADP-09) | Ahora incluye `legacy_employee_id`, `area_id`, `cargo_id`, `sociedad_id`, `manual_override` y `assignment_source`. |

**Precedencia de fuentes (K3-ADP-07c).** `LegacySyncService::syncOne()` (login) y `syncAllFromPanel()` (panel) **no
revierten** una asignación con `source = 'one'`; `manual_override = 1` sigue mandando sobre todas y se conserva
tal cual (no se convierte una asignación de One en excepción manual). `syncOne()` acepta `sociedad_id` sólo cuando
el origen la envía (el legacy no la tiene).

## Pendiente

Nada del contrato `k3-reports-1` queda fuera. Publicar y desplegar estos cambios en la instalación K3 es decisión
del dueño; el entorno de evaluación (`tests/external_reports/run.php`) los ejercita sin producción.
