# Keeper 4 — Inventario de cobertura respecto a Keeper 3

**Fecha:** 2026-07-30
**Rama:** `feature/modulo-seguridad`
**Propósito:** mapa completo de qué de Keeper 3 se trae a Keeper 4 y en qué estado. Responde "¿se corrige,
se trae tal cual, o no se ha contemplado?" para cada pieza. Referencia de alcance del proyecto.

Marcas: **CORREGIDO** (está, con un fix de diseño) · **TAL CUAL** (está, sin cambios) · **REDISEÑADO**
(está, con otra forma) · **DESCARTADO** (no está, y es correcto: era código muerto) · **NO CONTEMPLADO**
(feature viva sin equivalente en K4 — el hueco).

Verificado leyendo el cliente C#, los 16 endpoints, las 22 páginas del panel y las 32 tablas del esquema
3, cruzados contra las 33 tablas de Keeper 4.

---

## Resumen ejecutivo

**Contemplado y corregido/portado: ~90% de Keeper 3.** Los defectos conocidos se arreglan en el modelo de
datos (doble seed, focus fabricado, IDs ambiguos, auditoría sin actor, tabla de episodios sin índices).
Cinco tablas muertas se descartaron con verificación de que nadie las lee.

**Cinco huecos (NO CONTEMPLADO), dos centrales:**

| # | Hueco | Impacto | Recomendación |
|---|---|---|---|
| 1 | **Detección de doble empleo** (dual-job) | Central — subsistema completo: 3 tablas, un servicio, cron, 3 páginas | Traer, rediseñar |
| 2 | **Enrolamiento / aprobación** (pending-users) | Central — sin esto un equipo nuevo no tiene ruta de alta | Traer, rediseñar |
| 3 | Cobertura de instalación (install-coverage) | Periférico — encaja en el import de `keeper_source` | Traer, corregido |
| 4 | UpdateManager y logging como módulos de catálogo | Decisión — son infra, no se tarifican | Descartar del catálogo, explícito |
| 5 | Logging a Discord | Periférico — un campo de config | Traer tal cual o descartar |

**Nota:** las cuatro features nuevas (networkDiagnostic, remoteShutdown, screenshots, location) tienen
tabla en K4 pero **no tienen implementación en el cliente C#** todavía. No son huecos de K3: son
build-forward, el agente que las produce está por construir.

---

## 1. Módulos del cliente C#

| Capacidad | Estado K4 | Detalle |
|---|---|---|
| ActivityTracking | **CORREGIDO** | `keeper_day_summary` sin `GREATEST` (mató el doble seed) + banderas de cobertura |
| WindowTracking | **CORREGIDO** | `keeper_episode` particionada + `keeper_episode_daily`; sin `app_name`/`call_app_hint` |
| CallTracking | TAL CUAL | `is_in_call` + `call_seconds` |
| ProcessTracking | **REDISEÑADO** | El flag era código muerto; `processView` renace como la vista de procesos (entregable #1) |
| DeviceLock | REDISEÑADO | `keeper_device_locks` era huérfana → descartada. **Bug pendiente: `tryUnlock` no valida PIN** |
| WebBlocking | TAL CUAL | El cliente ya no hace enforcement (delegado a `AZCKeeperAgent`); solo cachea |
| Security | TAL CUAL / CORREGIDO | `keeper_security_state`; su patrón se generaliza a `keeper_device_module_state` |
| UpdateManager | REDISEÑADO parcial | Sustrato `keeper_client_releases` existe; **no está en el catálogo de módulos** (ver hueco 4) |
| Logging a servidor | TAL CUAL | `keeper_client_log` |
| Logging a Discord | **NO CONTEMPLADO** (periférico) | Config hot-reload sin tabla (hueco 5) |
| Auto-Startup | TAL CUAL | Registro HKCU, solo cliente |
| DebugWindow | TAL CUAL | Solo cliente; en el seed de política |
| Auth / re-enroll | TAL CUAL | `keeper_sessions` + `keeper_devices` |
| Handshake / TimeSync | **CORREGIDO** | Añade recorte por tier y eco de estado observado (`keeper_device_module_state`) |

Código muerto del cliente correctamente ausente: `MasterTimer.cs`, `KeyboardHook`/`MouseHook` (stubs),
`EnableProcessTracking`, `SystemProxyManager.EnablePac()`.

## 2. Endpoints de la API (16)

Todos con sustrato en K4 salvo dos notas: `POST /client/event` (`EventIngest` devuelve 501) está
**DESCARTADO** correctamente; `POST /client/device-lock/unlock` arrastra el bug de PIN sin validar.
`POST /cron/productivity` está **CORREGIDO en su rama de focus** pero su rama de doble empleo escribe en
`keeper_dual_job_alerts`, que es el hueco 1.

Endpoints que K4 necesitará y no existen (build-forward): recogida de comandos, ingesta de
screenshots/location, reporte de estado de módulos.

## 3. Páginas del panel (22)

Todas con sustrato en K4 salvo las que tocan los huecos: `dual-job-alerts.php` (hueco 1, feature
completa), `pending-users.php` (hueco 2), `install-coverage.php` (hueco 3). `index.php`,
`productivity.php` y `user-dashboard.php` están corregidas salvo su rama de contador/alertas de doble
empleo. `policies_diagnostic_backup.php` es la única UI de política por dispositivo — K4 conserva el scope
`device`, pero conviene migrarla a `policies.php` antes de borrarla.

## 4. Tablas (32 del esquema 3)

Reemplazadas por mejores: `keeper_activity_day`→`keeper_day_summary`,
`keeper_window_episode`→`keeper_episode`+`keeper_episode_daily`, `keeper_data_sources`→`keeper_source`,
`keeper_module_catalog`→`keeper_module`, `keeper_user_assignments` (con FK), `keeper_audit_log` (con actor).

Descartadas por muertas (0 refs verificado): `keeper_daily_metrics`, `keeper_events`,
`keeper_device_locks`, `keeper_handshake_log`, y `keeper_module_catalog` (renace viva).

**Vivas y sin equivalente en K4 (los huecos):** `keeper_dual_job_alerts`, `keeper_suspicious_apps`,
`keeper_app_classifications` (las tres del subsistema doble empleo), `keeper_install_coverage_notes`,
`keeper_enrollment_requests` (esta además ya carecía de migración en K3: cableada punta a punta con el DDL
faltante).

---

## Los cinco huecos, en detalle

### 1. Detección de doble empleo — CENTRAL, el hueco más grande

Subsistema completo y vivo: tres tablas (`keeper_dual_job_alerts`, `keeper_suspicious_apps`,
`keeper_app_classifications`), un servicio (`src/Services/DualJobDetector.php`), disparo en el cron y en
`productivity.php:49`, y tres páginas que lo consumen (`dual-job-alerts.php`, contador en `index.php:308`,
alertas en `user-dashboard.php:220`). Cero sustrato en K4.

Es una capacidad de negocio visible: detecta agentes con un segundo empleo. En el nuevo modelo —Keeper
como servicio que se vende a firmas para monitorear a los agentes de AZC— es **más** relevante, no menos:
una firma que paga por monitoreo quiere saber que su agente no está trabajando para otro.

**Recomendación: traer, rediseñar.** Las alertas se recalculan desde `keeper_episode`/`keeper_episode_daily`
(que reemplazan a `keeper_window_episode`), y los catálogos de apps se atan a la identidad interna nueva.

### 2. Enrolamiento / aprobación de accesos — CENTRAL

Flujo de onboarding cableado punta a punta: un login con cédula desconocida crea una solicitud en
`keeper_enrollment_requests`; `pending-users.php` la aprueba o rechaza y crea el usuario. K4 contempló el
**estado** (`keeper_users.status` incluye `'pending'`) pero no la tabla de solicitudes ni el flujo de
aprobación auditado. Sin esto, un equipo nuevo con cédula no reconocida no tiene ruta de alta.

**Recomendación: traer, rediseñar.** Plegar la solicitud sobre `keeper_users(status='pending')` +
`keeper_audit_log` (evento `enrollment_approved/rejected`, con el actor en `admin_id`), evitando la tabla
suelta. Ojo con la interacción con el import multi-tenant: si los usuarios entran importados desde la BD
del cliente, el enrolamiento por cédula desconocida es la vía secundaria (equipos que no vinieron en el
import). Ambas rutas deben coexistir.

### 3. Cobertura de instalación — periférico

`install-coverage.php` + `keeper_install_coverage_notes`: cruza empleados activos contra devices Keeper
para saber a quién falta instalar, con exentos y notas.

**Recomendación: traer, corregido.** Encaja natural en el flujo de import de `keeper_source` — la
comparación "gente del cliente vs devices" es justo lo que la importación de primera clase ya toca.

### 4. UpdateManager y logging como módulos de catálogo — decisión

`updateManager`/auto-update y el logging a servidor no están en `keeper_module`, aunque su sustrato de
datos sí. Son infraestructura, no se tarifican por tier.

**Recomendación: descartar del catálogo, como decisión explícita** para que no reaparezcan como hueco.

### 5. Logging a Discord — periférico

`LocalLogger.SendToWebhookAsync` + `Logging.DiscordWebhookUrl`. Sin tabla; config que viaja en la política.

**Recomendación: traer tal cual** (es un campo) **o descartar** si el canal Discord ya no se usa.

---

## Lo que confirma que no hay más huecos ocultos

Las tablas huérfanas descartadas (`keeper_daily_metrics`, `keeper_events`, `keeper_device_locks`,
`keeper_module_catalog`, `keeper_handshake_log`) se verificaron con grep: cero código vivo las lee. El
resto de las 22 páginas y 16 endpoints tienen sustrato en las 33 tablas de K4. El mapa está completo.
