# Administración v4

Implementa 47 operaciones administrativas y las cuatro de sesión bajo `/v1`, conservando los schemas y métodos del OpenAPI. No modifica K3/K4. Requiere aplicar `0009_admin_surface.sql` mediante el runner existente; añade fechas/índices para paginación y una cola persistente de recompilación. No altera las ocho migraciones anteriores.

| Recurso | Métodos y rutas relativas a `/v1` |
|---|---|
| Sesión | `GET /auth/csrf`; `POST /auth/login`, `/auth/logout`, `/auth/reauth` |
| Empresas | `GET/POST /tenants`; `GET/PATCH /tenants/{id}`; `PATCH /tenants/{id}/rbac-gate` |
| Organización | `GET/POST /organization`; `GET/PATCH /organization/{id}` |
| Horarios | `GET/POST /schedules`; `GET/PUT /schedules/{id}` |
| Miembros | `GET/POST /users`; `GET/PATCH /users/{id}`; `PUT /users/{id}/roles` |
| Equipos | `GET /tenants/{id}/devices`; `GET/PATCH /devices/{id}`; `POST /devices/{id}/assignment`; `GET/POST /devices/{id}/commands`; `POST /enrollments` |
| Políticas | `GET/POST /policies`; `GET/PATCH /policies/{id}` |
| RBAC | `GET/POST /roles`; `GET/PATCH /roles/{id}`; `GET /permissions`; `GET/PATCH /permissions/{code}` |
| Planes | `GET/POST /tiers`; `GET/PATCH /tiers/{id}`; `GET/PUT /users/{id}/subscription` |
| Reportes | `GET /reports/productivity` |
| Releases | `GET/POST /releases`; `POST /release-deployments` |
| Auditoría | `GET /audit` |

## Autenticación y autorización

`Auth::admin()` integra `AdminAuth` en el router existente. Pre-sesión de cinco minutos, password hash, cookie Secure/HttpOnly/SameSite=Strict y CSRF HMAC-SHA256 ligado a la cookie, usando la clave de configuración fuera de BD. Login consume la pre-sesión y emite otra cookie. Todas las mutaciones exigen CSRF y Origin canónico. Cada petición comprueba cuenta, usuario y empresa activos, revisión de cuenta, revocación, ocho horas absolutas y treinta minutos de inactividad en `admin_sessions`. Reauth dura cinco minutos; logout revoca la sesión. No se habilita login al crear Colaboradores ni al asignar roles.

`x-permission` del contrato selecciona el permiso; comandos usan la correspondencia exacta documentada por acción. `AdminAccess` consulta `user_roles`, `roles`, `role_permissions`, `permissions` y las tablas de alcance en cada petición. El atributo de plataforma está en `admin_accounts`: un nombre de rol nunca lo concede. Las capacidades de plataforma siguen exigiendo un permiso existente en el catálogo.

`X-Tenant-ID` es obligatorio donde lo exige el contrato; en rutas de empresa manda el ID del path. Se verifica contra la cuenta antes del handler. Las consultas por ID, joins, referencias de cuerpos, filtros, colas, auditoría e idempotencia incluyen tenant. Los listados/detalles de miembros y equipos aplican alcance de área/sede/propio; productividad usa la asignación organizativa histórica. Operaciones de configuración de toda la empresa requieren alcance tenant. IDs fuera del alcance devuelven 404.

Autogestión de roles exige gate empresa y `roles.gestionar`; plataforma puede operar con el gate apagado. Solo plataforma activa el gate o concede el meta-permiso. Crear/clonar desde una definición, editar y asignar roles valida los permisos y el alcance contra los del actor; un delegado tampoco puede editar/asignar indirectamente un rol con `roles.gestionar`. No puede otorgar permisos de plataforma ni no delegables. Cambiar roles invalida sesiones de los afectados y revisiones de autorización; revocar el gate surte efecto en la petición siguiente.

Cuotas predeterminadas separadas de ingesta: admin 120/min, burst 30; tenant 600/min, burst 100. El harness configura cuotas mayores únicamente para pruebas. Auditoría append-only por actor real/tenant/recurso/request ID y nombres de campos; no contiene cuerpos, passwords, tokens, PIN o texto de motivos libre. `If-Match` obligatorio en las rutas indicadas; respuestas versionadas incluyen ETag. Idempotencia transaccional cifrada durante 24 horas para acciones, asignación de equipos y despliegues.

## Persistencia y compilación

`PolicyInput` administra documento, nueva versión inmutable, reglas y asignación en una transacción. Desactivar equivale a `enabled=false`; borrar reglas/asignaciones se expresa publicando otra revisión/target, conservando historia. Targets y horarios se validan dentro del tenant. No se admite bloquear el host configurado de gestión.

Los cambios de políticas, horarios, organización, usuarios, tiers y suscripciones registran trabajo en `admin_policy_recompiles`. Antes del commit se valida la composición; los conflictos retornan 409 y revierten la edición. Después del commit se llama `PolicyCompiler::recompileForTenant`: abarca equipos activos del tenant y solo publica versiones si cambia el hash de composición. Incluye equipos de targets anteriores al reasignar una política.

La cola se elimina comparando su revisión, para no perder una edición concurrente. Si falla la compilación tras el commit, retorna 503 y conserva el trabajo: una petición admin posterior vuelve a intentarlo. Para recuperación sin tráfico HTTP:

```powershell
php backend-v4/config/compile-pending.php
```

Para cambios temporales de vigencia de suscripciones se debe programar el compilador existente `config/compile.php --all`; la tarea de sistema no se instala desde esta implementación. Una suscripción futura se guarda como `scheduled`; el compilador evalúa sus fechas y las suscripciones activas/programadas. La primera asignación requiere `If-Match: "0"`; GET sin suscripción vigente devuelve 404. Las siguientes usan la versión devuelta. No se permiten solapamientos.

Los comandos se encolan con secuencia monotónica y expiración futura máxima de 24 horas; no ejecutan nada. Wipe exige reauth y hostname escrito. Los motivos no se exponen en respuestas. Baja de usuario invalida credenciales, revoca sus equipos/tickets pendientes y cancela comandos pendientes. Reasignar equipo conserva historia y revoca claves/sesiones previas; requiere nuevo ticket para recuperar acceso.

Productividad lee únicamente `day_summary`, `episode_daily` y `focus_daily`, con filtros históricos y agregación separada para evitar multiplicar métricas al unir procesos. Prefiere el resumen diario y usa episodios agregados cuando falta; no consulta tablas crudas. Todos los listados usan cursores cifrados de 15 minutos, vinculados a tenant/principal/revisión/filtros/ruta/snapshot y consultas SQL acotadas, sin OFFSET. Los catálogos con fechas usan `(created_at,id)`; permisos usa `code`.

Registrar una release exige SHA-256, key ID y manifest JWS ES256 verificado contra `RELEASE_KEYS_FILE`. Todos los campos deben coincidir con el payload firmado. Registro y publicación son el mismo POST del contrato; `published_at` controla disponibilidad. Solo plataforma registra manifests inmutables y hace visible una release a un tenant; después su admin autorizado puede cambiar anillo/porcentaje. Branding sin logo en las semillas usa el SVG público local.

## Discrepancias del alcance solicitado

Se conserva el OpenAPI sin ampliarlo. No existen rutas/schemas para reset de contraseña, CRUD de `sociedades`, edición de `firma_module_override`, selección de equipos individuales en deployments ni comandos `logoff`/`diag`. No se inventaron esas APIs. `firm` se administra mediante `/organization`; firma significa firma organizativa, no firma manuscrita. El compilador existente sí lee `firma_module_override`. Los comandos definidos son `lock`, `unlock`, `restart`, `shutdown`, `wipe` y `refresh_policy`; rollout es por tenant/anillo/porcentaje. Tampoco existe una ruta publish separada ni CRUD independiente de versiones/reglas/asignaciones.

Integraciones, webhooks, notificaciones, transferencia entre empresas, dashboard y actividad/check-ins administrativos quedan fuera de esta entrega enumerada. Sus rutas no se exportan como implementadas. El catálogo global de permisos permite lectura y edición de metadata solo por plataforma, tal como define el YAML.

## Verificación

`tests/admin.php` se integra en el smoke completo sobre MySQL aislado. Comprueba contratos de salida además del modo `KEEPER_DEBUG`, dos tenants, distintos roles, gates, autoescalada directa/indirecta, expiración, CSRF/origen, IDOR, scopes, cursores, versiones de edición, compilación y rollback de conflictos, comandos auditados/idempotentes, revocación y releases firmadas. Incluye datos agregados reales y verifica que no se duplican métricas ni se cruzan tenants.

También simula un fallo posterior al commit del cambio de horario: devuelve 503, conserva la solicitud de compilación y la procesa al recuperarse el compilador.

```powershell
rg --files backend-v4 -g '*.php' | ForEach-Object { php -l $_ }
powershell -NoProfile -ExecutionPolicy Bypass -File backend-v4/tests/mysql-smoke.ps1 -DebugResponses
```

El harness detiene PHP/MySQL y elimina su datadir en `finally`. No conecta a producción ni prueba ejecución del agente/updater Windows. No incluye pruebas de carga.

Validación de esta entrega: PHP lint completo; MySQL 8.4.3 con validación de respuestas; 503 aserciones del smoke (223 admin) y 6 de transporte, todas PASS.
