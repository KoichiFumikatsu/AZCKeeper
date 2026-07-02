# Corte de historial por firma (`historial_desde`)

## Objetivo
Que cada firma pueda parametrizar una **fecha de inicio de historial**. Los admins
con scope de esa firma (firma-admins) no deben poder ver actividad anterior a esa
fecha. Ej.: Antonini & Cohen = 2026-07-01 → nada anterior es visible.

## Alcance (decidido con el usuario)
- **Aplica solo a firma-admins** (admins con `firm_scope_id`). El superadmin ve todo.
- **Cubre todo el historial**: dashboards de actividad, productividad/foco, episodios
  de ventana y alertas dual-job.

## Enfoque elegido: A — clamp del `from` + piso en alertas
Todas las vistas de actividad ya filtran por rango `day_date BETWEEN :from AND :to` y
ya restringen a la firma vía `scopeFilter()`. Subir el `from` al piso de la firma es
el chokepoint natural. Las pocas consultas sin rango (alertas) reciben el piso explícito.

## Cambios

### 1. Esquema
`migrations/add_firma_historial_desde.sql`:
```sql
ALTER TABLE keeper_firmas
  ADD COLUMN historial_desde DATE NULL DEFAULT NULL AFTER descripcion;
```
`NULL` = sin corte (comportamiento actual). Por defecto NULL → nada cambia hasta parametrizar.

### 2. UI (`public/admin/organization.php`)
- `<input type="date" name="historial_desde">` en el template de Firma del modal.
- Leerlo en INSERT y UPDATE de `keeper_firmas`.
- Incluirlo en el JSON `extra` de `openEdit('firm', …)` y en el reset de `openCreate`.

### 3. Carga del piso (`public/admin/admin_auth.php`)
Tras `validateSession`, si el admin no es superadmin y tiene `firm_scope_id`, cargar
`keeper_firmas.historial_desde` → `$adminUser['firm_floor']` (string `YYYY-MM-DD` o null).
Envuelto en try/catch por si la columna aún no está migrada.

### 4. Helper `clampFrom($from)` (`admin_auth.php`)
Devuelve `max($from, firm_floor)` (comparación lexicográfica de fechas ISO). Sin piso
→ devuelve `$from` sin cambios. No-op para superadmin.

### 5. Aplicación del clamp
- `index.php`: `$dateFrom = clampFrom($dateFrom);` tras el switch de período.
- `productivity.php`: idem.
- `user-dashboard.php`: clamp de `$dateFrom` **y** de `$epFrom` (rango de episodios).
- `sedes-dashboard.php`: **omitido** — módulo solo-superadmin (menu visibility), su
  `firm_floor` siempre sería null. Agrega un comentario `ponytail:` por si cambia.

### 6. Alertas (sin rango de fecha)
`src/Repos/ProductivityRepo.php`: `getAlerts()` y `getAlertCounts()` reciben un
parámetro opcional `?string $floorDate = null`; cuando hay piso añaden
`AND a.day_date >= :firm_floor`. `keeper_dual_job_alerts` ya tiene `day_date`.
- `dual-job-alerts.php`: pasa `$adminUser['firm_floor']`.
- `index.php`: el conteo inline de alertas pendientes añade el mismo `AND` cuando hay piso.

## Casos no cubiertos (deliberado, con comentario `ponytail:`)
- Widgets de "hoy"/"este mes" con `CURDATE()` (users.php, KPI mensual de index.php): su
  fecha siempre es ≥ piso salvo que se parametrice una firma con piso **futuro** (caso
  marginal). No se tocan.
- La recomputación nocturna/manual de focus (`productivity.php` POST, `day_date = :day`)
  no es una vista de historial; no se restringe.

## Verificación
Check `assert`-based: con `firm_floor = '2026-07-01'`,
`clampFrom('2026-06-01') === '2026-07-01'` y `clampFrom('2026-08-01') === '2026-08-01'`;
con `firm_floor = null`, `clampFrom('2026-06-01') === '2026-06-01'`.
