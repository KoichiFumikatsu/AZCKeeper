# Sincronización de calendarios de festivos de One

Fecha: 2026-11-03. Rama: `festivos-calendarios`. Base comprobada: `1580ca03e0c04bfb80b01959bcc0a0282c77f130`.

## Cambios

| Archivo:línea | Cambio |
| --- | --- |
| `backend-v4/src/DataSync.php:14` | `holiday-calendars` pertenece a `festivos`. También se consulta `people` cuando la fuente posee `festivos`, para asignar calendarios a personas ya vinculadas aunque no posea `personas`. |
| `backend-v4/src/DataSync.php:140` | Orden de aplicación: organización, horarios, calendarios, festivos, personas y asignaciones de calendario. Recálculo al terminar, dentro de la transacción/simulación existente. |
| `backend-v4/src/DataSync.php:416` | Alta y actualización por `entity_links`, tipo `holiday_calendar`. No se adoptan calendarios manuales por nombre. Una colisión de nombre detiene y revierte la vuelta con `holiday_calendar_name_conflict`. |
| `backend-v4/src/DataSync.php:453` | Asignación de `users.holiday_calendar_id` solo a personas vinculadas a esa fuente y tenant, bajo el dominio `festivos`. Referencia desconocida: `unknown_holiday_calendar`, con reversión completa. |
| `backend-v4/src/DataSync.php:472` | Festivos con `calendar_id`; una fecha puede aparecer en varios calendarios. Se elimina el emparejamiento automático por fecha con festivos manuales. Los festivos ya vinculados conservan su ID y reciben el calendario de One. |
| `backend-v4/src/DataSync.php:507` | Baja de festivos limitada a los vínculos de la fuente y tenant. Si otra fuente comparte el mismo registro, se conserva el registro y su otro vínculo. |
| `backend-v4/src/DataSync.php:154` | Recalcula fechas afectadas, incluida la fecha anterior cuando One mueve un festivo; las asignaciones y estados de calendario reutilizan `HolidayCalendars::refresh`. |
| `backend-v4/migrations/0071_sync_holiday_calendars.sql:1` | Amplía el ENUM de `entity_links.entity_type` agregando `holiday_calendar`, sin reordenar valores anteriores. |
| `backend-v4/tests/data-sync.php:304` | Regresión de calendarios integrada en la prueba existente de DataSync y en la suite completa. |
| `backend-v4/tests/hardening.php:182` | La prueba que repite la migración 0020 reutiliza `migrationCollation()`; antes fallaba en MariaDB 10.5 por ejecutar SQL con `utf8mb4_0900_ai_ci` sin traducción. Solo cambia la prueba. |

No se modificaron las fórmulas: `ActivityCalendar::expected` (`backend-v4/src/ActivityCalendar.php:11`) ya filtra por calendario efectivo; `workday` (`:27`) lo usa para productividad. `HolidayCalendars::effective` (`backend-v4/src/HolidayCalendars.php:14`) resuelve calendario personal activo, país de la sede y predeterminado, en ese orden. Se reutilizan ese modelo y su recálculo (`:67`); `HolidaySeeds` permanece sin cambios.

## Contrato y decisiones

Fuente de verdad leída, sin modificar: `C:\Users\FumiWork\proyectos\azc-one\includes\api_keeper.php`. Se verificó también el sobre/paginación en `api/keeper/v1.php` del mismo repo.

- Peticiones: `GET base/{dominio}?empresa=grupo`, con `updated_since` para incremental y `cursor` opaco. Se conserva `keeper.roster.v1` y el lector de páginas existente. One entrega páginas de hasta 500 elementos; una completa exige `complete=true` y `generated_at` consistente entre páginas. Las pruebas usan páginas de dos elementos para recorrer varias.
- `holiday-calendars`: `external_id`, `name`, `active`, `updated_at`. Se crean calendarios sin país y sin marca de predeterminado: solo se asignan explícitamente, sin sustituir los calendarios locales del país.
- `holidays[].calendar_external_id` resuelve el calendario de la misma fuente. Los IDs `fest-N` y `fest-N-comp` se conservan sin interpretar sus sufijos. One ya excluye recargos y entrega solamente días libres.
- `people[].holiday_calendar_external_id`: `festcal-N` asigna el calendario correspondiente. `KEEPER_SIN_CALENDARIO = festcal-0` crea y asigna el calendario activo vacío «Sin festivos»; **no se convierte a NULL**, para evitar la herencia del país.
- Valor de persona `null` o ausente: se guarda NULL al sincronizar `festivos`, usando el fallback existente país/predeterminado. También se aplica a las personas inactivas que One envía con NULL. Si la fuente no posee `festivos`, no se modifica la asignación.
- Compatibilidad: festivos de proveedores antiguos sin `calendar_external_id` siguen teniendo `calendar_id=NULL`. No se eliminan festivos manuales globales: la regla histórica de `ActivityCalendar` permite que estos afecten incluso al calendario vacío. «Sin festivos» evita heredar calendarios nacionales, pero no cancela esa excepción global existente.
- Calendario omitido en una vuelta completa: **baja lógica** (`active=FALSE`), conservando su vínculo y las filas manuales que pudiera contener. No se ejecuta DELETE del calendario porque la FK borraría todos sus festivos por cascada. Una reaparición permite reactivarlo con el mismo ID. Sus días vinculados ausentes sí se eliminan mediante la lista completa de `holidays`.
- Calendarios/fechas con ID duplicado o estructura inválida, y referencias desconocidas: la vuelta falla y revierte, sin interpretar el error como una baja masiva.
- Toda resolución, escritura y reconciliación de vínculos incluye `tenant_id`; las búsquedas de IDs externos incluyen además `source_id`. `company=grupo` no amplía el alcance de Keeper fuera del tenant configurado.

## Migración

Es imprescindible `0071_sync_holiday_calendars.sql` porque el ENUM anterior no acepta el nuevo tipo. No crea otro modelo de calendario, no mueve datos existentes y no modifica migraciones aplicadas.

Discrepancia del encargo: `backend-v4/config/migrations/` no existe en esta base. El ejecutor real, `backend-v4/migrations/run.php`, lee `backend-v4/migrations/NNNN_*.sql`; por eso la nueva migración está allí. Para una futura instalación corresponde subir solo esta migración nueva, además de los archivos de código cambiados. No se ejecutó ningún despliegue.

## Pruebas

| Verificación | MySQL 8.4.3 | MariaDB 10.5.29 |
| --- | --- | --- |
| Suite completa `mysql-smoke.ps1` | **PASS: 6.546 aserciones**, exit 0 | **PASS: 6.546 aserciones**, exit 0 |
| Nuevo bloque `holiday calendar sync` (incluido en el total) | 226 aserciones | 226 aserciones |
| DataSync / puente One / frenos (incluidos en el total) | 102 / 28 / 71 | 102 / 28 / 71 |
| Fórmulas de productividad (incluidas en el total) | 35 aserciones | 35 aserciones |
| Verificador de migraciones, previo al smoke | 64 controles | 64 controles |
| Transporte, previo al smoke | 10 aserciones | 10 aserciones |
| Migración 0071 y repetición del migrador | APPLIED y después SKIP | APPLIED y después SKIP |
| Limpieza del harness | Procesos y datadir eliminados | Procesos y datadir eliminados |

Sintaxis PHP: PASS en los tres archivos PHP modificados. `git diff --check`: PASS. Exportador: **187 paths y 320 schemas**; hash final comprobado y sin diff del contrato.

La regresión comprueba jornada 08:00–17:00 con una hora de almuerzo: `expected_seconds=32400` y jornada de productividad `28800` en día laborable, ambos cero en el festivo personal. Con 14.400 segundos productivos, la API devuelve 50 % para quien trabaja ese día y NULL para quien tiene festivo. Se comprueban también los resúmenes persistidos tras mover o quitar el festivo.

Comandos utilizados desde la raíz del worktree:

```powershell
php -l backend-v4/src/DataSync.php
php -l backend-v4/tests/data-sync.php
python backend-v4/tests/export-contract.py
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -MariaDbDirectory backend-v4/.validation/mariadb-runtime/mariadb-10.5.29-winx64
powershell -NoProfile -File backend-v4/tests/mysql-smoke.ps1 -MySqlPort 13385 -HttpPort 18085
git diff --check
```

MariaDB 10.5.29 portable se descargó del archivo oficial de MariaDB a `backend-v4/.validation/mariadb-runtime/`; MySQL 8.4.3 y PHP 8.3.30 estaban instalados localmente. El harness eliminó sus procesos y bases de datos. La revisión automática rechazó la eliminación posterior de la distribución descargada (`blocked by policy`), por lo que sus binarios y ZIP permanecen en ese directorio ignorado por Git. No se conectó a servidores de Grupo AZC ni se leyó información productiva. Los logs locales quedan en `backend-v4/.validation/mysql-festivos.log` y `backend-v4/.validation/mariadb-festivos.log` (también ignorados).

La prueba nueva incluye dos calendarios con festivos propios y fecha compartida, persona en cada calendario, persona con `festcal-0`, NULL y campo ausente; simulación y rollback; idempotencia completa/incremental; cambio de nombre, fecha y asignación; resúmenes diarios y productividad por API; paginación incompleta; referencias desconocidas; conflicto con calendario manual; eliminación de días ausentes; conservación de festivos manuales incluso dentro del calendario retirado; propiedad separada de `personas`/`festivos`; dos tenants con los mismos IDs externos y fuentes `company=grupo`.

Incidencias de la validación:

1. Primer intento MySQL detenido en `OpenAPI extract current`: el checkout Windows tenía el YAML con CRLF y el hash exportado correspondía a LF. Se normalizó localmente el YAML a LF y se ejecutó el exportador: **187 paths y 320 schemas**, sin cambios semánticos ni diff de contrato. No se añadió un hash dependiente de Windows al commit.
2. Primer intento MariaDB detenido en `hardening.php:184`, driver 1273 (collation desconocida). Se corrigió la repetición de migración en esa prueba usando la misma detección de collation del ejecutor real; las migraciones antiguas no se tocaron.

## Activación futura

1. Instalar primero la migración 0071 y el código, por el procedimiento habitual de despliegue. Esta sesión no lo ejecuta.
2. Configurar **una fuente de grupo**, `company=grupo`, con token de grupo de One. Sincronizar primero organización/horarios/personas y, si corresponde, jerarquía. Revisar simulación y aprobar los frenos normales de altas/dominios nuevos. Mantener el mismo `source_id` para conservar vínculos existentes.
3. Asignar después `festivos` a esa misma fuente. Ejecutar una **sincronización completa**, revisar calendarios, asignaciones y bajas; aprobar por el flujo existente. Esto rellena también calendarios en días importados anteriormente sin calendario.
4. Verificar al menos una persona de cada calendario y una con «Sin festivos» en un día libre diferente. Las vueltas incrementales posteriores reutilizan los vínculos; la reconciliación completa sigue siendo necesaria para detectar eliminaciones físicas en One.

## Riesgos y dudas para Koichi

- El modelo existente guarda el calendario actual en `users`, no un historial de asignaciones por fecha. Cambiarlo puede alterar reportes históricos. Se conserva esa semántica y la ventana de 400 días de `HolidayCalendars::refresh`; no se introduce historización en este encargo.
- Calendarios inactivos se ignoran en la resolución efectiva y la persona puede caer al país/predeterminado. Los festivos manuales de ese calendario se conservan físicamente, aunque el calendario retirado deja de aplicarlos.
- Si One usa un nombre ya ocupado por un calendario manual, la vuelta falla; hace falta diferenciar los nombres antes de activarla. No se vinculan automáticamente para evitar apropiarse de datos manuales.
- Los registros que la sincronización antigua ya hubiera vinculado por coincidencia de fecha siguen siendo registros vinculados y pueden actualizarse o retirarse. Este cambio impide nuevas adopciones automáticas; no puede reconstruir la procedencia histórica de esas filas. Revisar esos vínculos antes de la primera completa si se usó el dominio antiguo.
- El panel conserva sus reglas actuales de edición/asignación de calendarios; una edición local de una asignación gestionada por One será sustituida al sincronizar `festivos`. No se amplió el alcance a controles de interfaz ni a nuevos bloqueos de edición.
- One no exporta excepciones de festivo por persona. Además, los cambios cuyo `updated_at` no avance en One se verán en la siguiente completa, no necesariamente en la incremental. No se modificó One para resolver estos puntos.
- Los proveedores que posean `festivos` deben exponer también `holiday-calendars` y `people`. No se prueba HTTP contra One real: se verifica su contrato local y se ejercita DataSync con respuestas paginadas controladas.

## Estado de entrega y commit pendiente

Implementación y pruebas completas. **No se creó ningún commit**: después de aprobar ambas suites, `git add` falló con:

```text
fatal: Unable to create 'C:/Users/FumiWork/Documents/AZCKeeper/.git/worktrees/AZCKeeper-festivos/index.lock': Permission denied
```

Los metadatos compartidos de este worktree están fuera del directorio autorizado para escritura de la sesión. No se intentó eludir ese permiso. Los cambios quedan intactos y sin stage en `festivos-calendarios`; el único paso funcional pendiente es crear el commit desde una sesión que pueda escribir en esos metadatos:

```powershell
git add -- backend-v4/src/DataSync.php backend-v4/tests/data-sync.php backend-v4/tests/hardening.php backend-v4/migrations/0071_sync_holiday_calendars.sql docs/design/2026-11-03-festivos-calendarios/REPORTE.md
git commit -m "Sincroniza los festivos de One por calendario y persona" -m "Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

No se usó `git stash`, no se modificó `azc-one`, no se desplegó y no se accedió a servidores. Queda también pendiente la limpieza opcional de la distribución portable, rechazada por la política automática; las bases y procesos de prueba ya fueron eliminados por el harness.
