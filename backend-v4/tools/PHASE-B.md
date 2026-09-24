# Fase B: presencia, calendario, cumplimiento y operación

Aplicar `php backend-v4/config/migrate.php`. Migraciones nuevas:

- `0013_focus_presence.sql`: siete columnas perdidas de `focus_daily` y marcas horarias en `episode_daily`.
- `0014_holidays.sql`: `holidays` y `holiday_society_links`, reutilizando `sociedades` y `user_assignments.sociedad_id`.
- `0015_compliance_operations.sql`: catálogo y detecciones de apps, alertas de doble empleo, notas de cobertura, ajustes, estado del cron y diez permisos.

Las migraciones 0001–0012 se conservan. MySQL 8.0.30+ y MariaDB 10.5+; los bloqueos compartidos usan `LOCK IN SHARE MODE`. Los roles existentes requieren asignación explícita de los permisos nuevos; la migración no amplía roles personalizados.

| API (prefijo `/v1`) | Métodos | Permiso |
|---|---|---|
| `/reports/presence` | GET | `reportes.ver` |
| `/schedules/holidays`, `/schedules/holidays/{id}` | GET, POST; GET, PUT, DELETE | `festivos.ver`, `festivos.editar` |
| `/schedules/holiday-societies`, `/schedules/holiday-societies/{id}` | GET, POST; GET, PUT, DELETE | `festivos.ver`, `festivos.editar` |
| `/policies/suspicious-apps`, `/policies/suspicious-apps/{id}` | GET, POST; GET, PUT, DELETE | `cumplimiento.ver`, `cumplimiento.editar` |
| `/reports/dual-job-alerts`, `/reports/suspicious-apps`, `/reports/compliance-signals` | GET | `cumplimiento.ver` |
| `/devices/install-coverage` | GET | `cobertura.ver` |
| `/users/{id}/install-coverage` | PUT | `cobertura.editar` |
| `/audit/client-logs` | GET | `operacion.logs` |
| `/tenants/server-health` | GET | `operacion.salud` |
| `/tenants/panel-settings` | GET, PUT | `operacion.ajustes_ver`, `operacion.ajustes_editar` |

Tenant obligatorio en `X-Tenant-ID`, validado contra sesión. Escrituras requieren CSRF y generan auditoría. Los catálogos, calendario, salud y ajustes requieren alcance tenant. Consultas de personas, cobertura y logs aplican el alcance del rol; IDs ajenos devuelven 404. Las listas nuevas tienen `limit` (1–100), `offset` y `next_offset`; no prometen un snapshot entre páginas. No se exportan credenciales ni configuración arbitraria del servidor.

Vistas del portal: `/festivos.php`, `/doble-empleo.php`, `/apps-sospechosas.php`, `/cobertura.php`. Panel: `/admin/festivos.php`, `/admin/logs.php`, `/admin/salud.php`, `/admin/ajustes.php`. Navegación consulta permisos a través de la API. Las tablas permiten paginar; calendario y catálogo permiten altas, cambios y bajas; cobertura permite notas y excepciones; ajustes permiten configurar el umbral de heartbeat.

## Puntualidad y cálculo

Presencia usa primero `focus_daily.first_activity_time` en la zona del horario, luego `day_summary.first_activity`, luego las marcas reales de `episode_daily`; el check-in de entrada queda como respaldo adicional. La última actividad usa `day_summary` o `episode_daily`. Nunca se deduce una hora a partir del total de segundos ni de `data_through`.

`scheduled_start` conserva el valor importado de K3 y usa el horario de la asignación como respaldo. `punctuality_minutes` mantiene la convención K3: positivo temprano, negativo tarde. `late_seconds` solo expresa retraso y no puede ser negativo. Festivos y días fuera del horario devuelven `no_laborable`, puntualidad y retraso nulos. Un registro con contadores pero sin hora devuelve `actividad_sin_hora`, sin atribuirle puntualidad ni ausencia.

Un festivo sin sociedades se aplica al tenant completo. Con sociedades solo afecta a las asignaciones correspondientes. Crear, mover, cambiar alcance o eliminar un festivo actualiza los días esperados y la puntualidad de los agregados, sin requerir episodios históricos.

El cron `config/productivity-cron.php` mantiene su interfaz y presupuesto de trabajo. Calcula horas, puntualidad, productividad sobre activo+inactivo, bloques de constancia de 30 minutos, sesiones profundas de al menos 25 minutos y mayor racha de foco productivo. Conserva los agregados `k3-import-*`: el recorte de episodios del ETL no es una sustitución completa del día. Las detecciones sí se reconstruyen de los episodios disponibles, con IDs estables. Editar el catálogo marca los días retenidos para recálculo. Salud muestra las últimas ejecuciones, éxito o fallo del cron y pendientes del tenant.

Señales: catálogo literal sobre proceso o título; escritorio remoto y apps externas; inactividad de al menos 80% sobre dos horas; al menos una hora fuera del horario en cinco días de una ventana de siete días laborables observados. Son señales para revisión humana, no afirmaciones de doble empleo. Las consultas respetan la retención agregada; logs respetan `log_days`.

## Importación

`tools/import-k3.php` incorpora los grupos `holidays`, `compliance` y `coverage` al conjunto predeterminado. También admite `--only=holidays,daily,compliance,coverage` y `--dry-run`. Credenciales exclusivamente mediante las variables y archivos ya soportados; conexión fuente con transacciones de solo lectura, incluida MariaDB (`tx_read_only`).

Reejecutar `daily` rellena las columnas omitidas en importaciones anteriores. Conserva cambios de contexto, distracción, sesiones y racha; promedia los porcentajes por dispositivo y toma la primera hora y su horario del mismo registro. Importa `first_event_at` y `last_event_at` en ambos agregados de actividad.

El archivo `k3-prod-schema.txt` no documenta festivos. El importador consulta `information_schema.columns`: admite fecha `holiday_date`, `day_date`, `date` o `fecha`, y nombre `name`, `nombre`, `description` o `descripcion`; rechaza esquemas no reconocidos. La vinculación requiere `holiday_id` y `sociedad_id`. Verificar esos nombres contra la fuente real antes del despliegue; la suite usa el esquema sintético `holiday_date/name`.

K3 permite sociedades sin firma; v4 exige firma. Se crea una sociedad por pareja sociedad K3/firma de asignación, con referencia e ID deterministas. Un festivo vinculado a sociedades sin asignaciones importables se omite con motivo explícito; no se amplía su alcance a todo el tenant. Se importan alertas históricas, catálogo y notas de cobertura mediante `k3:legacy_employee_id`.

## Pruebas reproducibles

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tools/phase-b-smoke.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tools/phase-b-smoke.ps1 -MySqlPort 13385 -HttpPort 18085 -MariaDbDirectory C:\ruta\mariadb-10.5.29-winx64
```

El arnés ejecuta el smoke completo existente y `phase-b-tests.php` en bases desechables. Incluye el volumen de 238 personas/298 equipos/43.969 episodios y una fuente K3 sintética con 160 personas, 320 diarios, festivos por sociedad, catálogo y notas. Verifica reejecución y dry-run del ETL, reposición de columnas nulas, puntualidad real sin puertas, ambos respaldos de actividad, exclusión de festivos, 403/404, paginación, catálogo, notas, ajustes y detección idempotente. El arnés apaga sus procesos y elimina su directorio de datos en `finally`.
