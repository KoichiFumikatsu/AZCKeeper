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
| `GET /external/reports/productivity?period=&from=&to=&page=&sort=asc\|desc` | `productivity.php` (`ProductivityRepo`) | `rows[{keeper_user_id,full_name,firm_id,area_id,focus_score,productivity_pct,constancy_pct,deep_work_seconds,context_switches,distraction_seconds,punctuality_minutes,days}]`, `weights` (pesos vigentes del Focus Score), `deep_work_threshold_minutes`, `kpis` (`getGlobalKPIs`), `page`, `pages`, `total`. Periodo por defecto `week`. |
| `GET /external/reports/coverage` | `install-coverage.php` sobre `keeper_users` del ámbito (sin cruzar el legacy) | `rows[{keeper_user_id,full_name,email,last_seen_at,latest_client_version,active_devices,coverage ok\|stale\|outdated\|no_device,exempt,note}]`, `heartbeat_days`, `active_release`. «Nunca instalado» lo deduce el consumidor comparando su plantilla con este listado. |
| `GET /external/reports/presence?period=&from=&to=` | `sedes-dashboard.php` (tarjetas) **añadiendo** el recorte por ámbito que la página no tiene | `sites[{sede_id,name,users,active,away,offline,without_device,users_with_activity,active_seconds,idle_seconds,work_seconds,call_seconds,first_login_at,leisure_seconds}]` |
| `GET /external/reports/alerts?type=&severity=&status=pending\|reviewed&page=` | `dual-job-alerts.php` (`ProductivityRepo::getAlerts`) | `alerts[{id,keeper_user_id,full_name,day_date,alert_type,severity,is_reviewed,reviewed_at (ISO o null),evidence}]`, `page`, `pages`, `total`. **Solo lectura**: revisar y anotar sigue en el panel. |

Instantes en ISO 8601 con desfase `-05:00`. Fechas `AAAA-MM-DD`. Enteros como enteros; «sin dato» como `null`, nunca `0`.

## Pruebas

`php tests/external_reports/run.php` crea la base `keeper_eval` desde `migrations/`, la siembra con datos
sintéticos (correos `.invalid`), levanta `php -S` con un `.env` propio (`KEEPER_ENV_FILE`, nunca el `Web/.env` habitual) y ejecuta 56 comprobaciones de autorización, ámbito, periodo,
piso de historial, paginación y forma de cada informe. `--serve` deja el entorno arriba para que un consumidor
(One) valide su lector contra él. Variables: `KEEPER_TEST_DB_HOST/USER/PASS/NAME`, `KEEPER_TEST_PORT`,
`KEEPER_TEST_SECRET`. Nunca usa producción ni la BD legacy.

## Pendiente (no incluido en `k3-reports-1`)

K3-ADP-07 (`PUT /external/assignments/{legacy_employee_id}` con `intent_version`, `sociedad_id` y precedencia de
fuentes), K3-ADP-08 (`GET /external/assignments?updated_since=`) y K3-ADP-09 (`roster` con
`legacy_employee_id` y `area_id`): los cubre la sincronización compartida (E08B-T05 en One).
