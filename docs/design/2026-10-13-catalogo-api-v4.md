# Catálogo API AZCKeeper v4 — para el portal (Lovable)

Generado 2026-10-13, solo lectura. Fuentes: `docs/architecture/openapi-v4.yaml` (contrato), `backend-v4/config/contract.json` (lo que el router acepta), `backend-v4/src/*.php`.

## Lo primero: la API no autentica personas

[Seguro] Ninguna credencial pensada para el portal identifica a una persona. Las integraciones (API key / OAuth `client_credentials`) son por empresa y ven todo el tenant. El único login por persona es la cookie del panel, que solo funciona desde el mismo origen que keep.azclegal.com. Sin cambios en el backend, Lovable no puede mostrarle a cada colaborador solo sus datos con aislamiento que imponga Keeper. Detalle y citas en §3.

## Cifras

| Concepto | Valor |
|---|---|
| Rutas (paths) en el contrato | **158** |
| Operaciones (método + ruta) | **210** |
| Operaciones que el router acepta (`contract.json`) | 198 en 148 paths ([Seguro], `Request.php:55` devuelve 404 a lo que no esté ahí) |
| Operaciones en el contrato **sin implementar** | **12**: `GET /dashboard`, `GET/POST /integrations`, `POST /integrations/{id}/rotate`, `DELETE /integrations/{id}`, `GET/POST /webhooks`, `DELETE /webhooks/{id}`, `GET /notifications`, `POST /devices/{id}/transfer`, `GET /users/{id}/dashboard`, `GET /users/{id}/check-ins` |
| Útiles para el portal HOY | **12 operaciones**: 11 `GET /ext/v1/*` + `POST /oauth/token` |
| Solo admin | 184 |
| Solo agente (`/client/*`) | 14 |

Leyenda de autenticación: **Sesión admin** = cookie `__Host-keeper_admin` del panel; **+ CSRF** = además `X-CSRF-Token` y `Origin` igual a `ORIGIN`; **Dispositivo** = bearer del equipo + firma; **API key u OAuth** = `X-API-Key` o `Authorization: Bearer` de `/oauth/token` (una de las dos, nunca ambas). «(solo plataforma)» = permiso que solo tiene el superadmin (`Features.php` `PLATFORM_PERMISSIONS`).

Nota: el grafo graphify del repo (`graphify-out/`) cubre sobre todo el cliente K3 (C#) y el panel legacy. No aportó nada a backend-v4, así que todo se verificó leyendo el código.

## 1. Catálogo por grupo funcional

### Autenticación y sesión (7)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/auth/csrf` | Abre la pre-sesión de login del panel y entrega el token CSRF | Pública | — | csrf_token + cookie pre-login | No-solo admin | Cookie __Host- SameSite=Strict y Origin fijo: no sirve a otro origen (Lovable) |
| POST | `/auth/login` | Inicia sesión en el panel con correo y contraseña | Pre-sesión + CSRF | — | admin_id, tenant_id, expiración, csrf_token, grupo | No-solo admin | Es el login del panel; ver Brechas: cookie same-site, sin CORS |
| POST | `/auth/logout` | Cierra la sesión del panel | Sesión admin + CSRF | — | 204 | No-solo admin | Sesión del panel |
| POST | `/auth/reauth` | Reconfirma la contraseña antes de una acción crítica (5 min) | Sesión admin + CSRF | — | estado de sesión | No-solo admin | Acciones críticas del panel |
| POST | `/oauth/token` | Emite un token OAuth client_credentials (15 min) para una integración | Cliente OAuth (Basic) | — | access_token, expires_in=900, scope | Sí | Puerta de entrada del BFF del portal a /ext/v1 |
| GET | `/me/access` | Dice qué permisos, alcance y funciones tiene la sesión actual | Sesión admin | sesion | platform, features[], permissions[{code,scopes}] | No-solo admin | Modelo de menú por rol; útil como referencia, pero solo con sesión de panel |
| POST | `/auth/password` | Cambia la contraseña propia del panel | Sesión admin + CSRF | — | estado de sesión | No-solo admin | Cuenta del panel |

### Personas y cuentas (14)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/users` | Lista personas del alcance (filtros empresa/sede/área) | Sesión admin | usuarios.ver | personas: ids org, horario, acceso, plan, jefe | No-solo admin | Con alcance self/team/filters ya filtra; solo vía sesión de panel |
| POST | `/users` | Crea un colaborador (registro, sin login) | Sesión admin + CSRF | usuarios.crear | persona | No-solo admin | Alta RR. HH. |
| GET | `/devices/install-coverage` | Quién tiene Keeper instalado/activo (cobertura) | Sesión admin | cobertura.ver | por persona: equipos, estado | No-solo admin | Supervisión; scoped por alcance |
| POST | `/users/batch` | Edita personas en lote (con dry-run) | Sesión admin + CSRF | usuarios.editar | resultados por persona | No-solo admin | Admin |
| GET | `/users/{id}` | Lee la ficha completa de una persona | Sesión admin | usuarios.ver | persona completa (cédula, cargo, horario, jefe) | No-solo admin | Sería «Mi perfil»; hoy solo vía sesión de panel con alcance self |
| PATCH | `/users/{id}` | Edita o inactiva a una persona | Sesión admin + CSRF | usuarios.editar | persona | No-solo admin | Admin |
| PUT | `/users/{id}/access` | Asigna a una persona 1 rol + 1 alcance (self/team/filters/all) | Sesión admin + CSRF | roles.gestionar | persona | No-solo admin | Define qué vería cada persona |
| PUT | `/users/{id}/install-coverage` | Nota/exención de cobertura de una persona | Sesión admin + CSRF | cobertura.editar | fila de cobertura | No-solo admin | Admin |
| GET | `/panel-accounts` | Lista cuentas de acceso al panel | Sesión admin | usuarios.editar | cuentas | No-solo admin | Admin |
| POST | `/panel-accounts` | Crea cuenta de login al panel para una persona | Sesión admin + CSRF | usuarios.editar | cuenta + contraseña temporal | No-solo admin | Único mecanismo de login por persona que existe hoy |
| PATCH | `/panel-accounts/{id}` | Activa/desactiva/edita cuenta del panel | Sesión admin + CSRF | usuarios.editar | cuenta | No-solo admin | Admin |
| POST | `/panel-accounts/{id}/reset-password` | Restablece contraseña de una cuenta del panel | Sesión admin + CSRF | usuarios.editar | contraseña temporal | No-solo admin | Admin |
| GET | `/monitoring-exclusions` | Cargos que no se monitorean | Sesión admin | usuarios.ver | lista de cargos | No-solo admin | Admin |
| PUT | `/monitoring-exclusions` | Cambia cargos que no se monitorean | Sesión admin + CSRF | usuarios.editar | lista de cargos | No-solo admin | Admin |

### Organización y puesta en marcha (10)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/organization` | Lee empresas, sedes, áreas y cargos | Sesión admin | organizacion.ver | unidades organizativas | No-solo admin | Equivalente externo: /ext/v1/organization |
| POST | `/organization` | Crea una unidad organizativa | Sesión admin + CSRF | organizacion.editar | unidad | No-solo admin | Admin |
| GET | `/organization/{id}` | Lee una unidad organizativa | Sesión admin | organizacion.ver | unidad | No-solo admin | Admin |
| PATCH | `/organization/{id}` | Edita/desactiva una unidad | Sesión admin + CSRF | organizacion.editar | unidad | No-solo admin | Admin |
| DELETE | `/organization/{id}` | Elimina una unidad | Sesión admin + CSRF | organizacion.editar | 204 | No-solo admin | Admin |
| GET | `/onboarding` | Estado de la puesta en marcha del cliente | Sesión admin | organizacion.editar | pasos y estado | No-solo admin | Admin |
| PATCH | `/onboarding` | Guarda decisiones de la puesta en marcha | Sesión admin + CSRF | organizacion.editar | estado | No-solo admin | Admin |
| POST | `/onboarding/close` | Cierra la puesta en marcha | Sesión admin + CSRF | organizacion.editar | estado | No-solo admin | Admin |
| POST | `/onboarding/reopen` | Reabre la puesta en marcha | Sesión admin + CSRF | organizacion.editar | estado | No-solo admin | Admin |
| GET | `/onboarding/summary` | Resumen de la puesta en marcha | Sesión admin | organizacion.editar | resumen | No-solo admin | Admin |

### Actividad, productividad y reportes (8)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/dashboard` **[NO implementado]** | KPIs, gráficas y roster del panel | Sesión admin | reportes.ver | dashboard | No-solo admin | NO implementado; equivalente vivo: /ext/v1/dashboard |
| GET | `/reports/productivity` | Productividad y focus agregado (filtro área/sede/persona) | Sesión admin | reportes.ver | series de productividad | No-solo admin | Respeta alcance self/team/filters; solo sesión de panel |
| GET | `/reports/apps` | Uso por aplicación (activo/inactivo) | Sesión admin | reportes.ver | apps con tiempos y usuarios | No-solo admin | Respeta alcance; útil para «Mis apps»/equipo, sin equivalente /ext |
| GET | `/reports/presence` | Presencia y puntualidad (llegada/salida vs horario) | Sesión admin | reportes.ver | filas por persona/día | No-solo admin | Respeta alcance; sin equivalente /ext |
| GET | `/users/{id}/dashboard` **[NO implementado]** | Dashboard por miembro | Sesión admin | reportes.ver | persona + productividad | No-solo admin | NO implementado |
| GET | `/users/{id}/activity` | Actividad de una persona (detalle diario) | Sesión admin | reportes.ver | reporte de actividad | No-solo admin | Respeta alcance; equivalente externo parcial /ext/v1/members/{id}/activity |
| GET | `/users/{id}/check-ins` **[NO implementado]** | Histórico de check-in de una persona | Sesión admin | presencia.ver | check-ins | No-solo admin | NO implementado; equivalente vivo: /ext/v1/members/{id}/check-ins |
| GET | `/reports/people` | Totales de actividad por persona | Sesión admin | reportes.ver | totales por persona | No-solo admin | Respeta alcance; sin equivalente /ext |

### Ausencias, horarios y festivos (22)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/schedules` | Lista horarios | Sesión admin | horarios.ver | horarios | No-solo admin | Exige alcance «todo»; sin equivalente /ext |
| POST | `/schedules` | Crea horario | Sesión admin + CSRF | horarios.editar | horario | No-solo admin | Admin |
| GET | `/schedules/holidays` | Lista festivos | Sesión admin | festivos.ver | festivos | No-solo admin | Exige alcance «todo» |
| POST | `/schedules/holidays` | Crea festivo | Sesión admin + CSRF | festivos.editar | festivo | No-solo admin | Admin |
| GET | `/schedules/holiday-societies` | Lista sociedades (para festivos) | Sesión admin | festivos.ver | sociedades | No-solo admin | Admin |
| POST | `/schedules/holiday-societies` | Crea sociedad | Sesión admin + CSRF | festivos.editar | sociedad | No-solo admin | Admin |
| GET | `/schedules/{id}` | Lee horario | Sesión admin | horarios.ver | horario | No-solo admin | Exige alcance «todo» |
| PUT | `/schedules/{id}` | Actualiza horario | Sesión admin + CSRF | horarios.editar | horario | No-solo admin | Admin |
| GET | `/schedules/holidays/{id}` | Lee festivo | Sesión admin | festivos.ver | festivo | No-solo admin | Admin |
| PUT | `/schedules/holidays/{id}` | Edita festivo | Sesión admin + CSRF | festivos.editar | festivo | No-solo admin | Admin |
| DELETE | `/schedules/holidays/{id}` | Elimina festivo | Sesión admin + CSRF | festivos.editar | 204 | No-solo admin | Admin |
| GET | `/schedules/holiday-societies/{id}` | Lee sociedad | Sesión admin | festivos.ver | sociedad | No-solo admin | Admin |
| PUT | `/schedules/holiday-societies/{id}` | Edita sociedad | Sesión admin + CSRF | festivos.editar | sociedad | No-solo admin | Admin |
| DELETE | `/schedules/holiday-societies/{id}` | Elimina sociedad | Sesión admin + CSRF | festivos.editar | 204 | No-solo admin | Admin |
| GET | `/schedules/holiday-calendars` | Lista calendarios de festivos por país | Sesión admin | festivos.ver | calendarios | No-solo admin | Admin |
| POST | `/schedules/holiday-calendars` | Crea calendario de festivos | Sesión admin + CSRF | festivos.editar | calendario | No-solo admin | Admin |
| GET | `/schedules/holiday-calendars/{id}` | Lee calendario de festivos | Sesión admin | festivos.ver | calendario | No-solo admin | Admin |
| PUT | `/schedules/holiday-calendars/{id}` | Edita calendario de festivos | Sesión admin + CSRF | festivos.editar | calendario | No-solo admin | Admin |
| POST | `/schedules/holiday-calendars/{id}/people` | Asigna calendario a personas | Sesión admin + CSRF | festivos.editar | calendario | No-solo admin | Admin |
| GET | `/absences` | Lista ausencias (vacaciones, incapacidad…) del alcance | Sesión admin | presencia.ver | ausencias (máx. 500) | No-solo admin | Respeta alcance; sin equivalente /ext |
| POST | `/absences` | Registra una ausencia | Sesión admin + CSRF | usuarios.editar | ausencia | No-solo admin | RR. HH. |
| PATCH | `/absences/{id}` | Edita o cancela una ausencia | Sesión admin + CSRF | usuarios.editar | ausencia | No-solo admin | RR. HH. |

### Equipos (dispositivos), alta y seguridad (40)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/admin/tenants/{id}/unlock-pin` | Estado del PIN local de desbloqueo | Sesión admin | equipos.desbloquear | estado sin PIN | No-solo admin | IT |
| PUT | `/admin/tenants/{id}/unlock-pin` | Cambia PIN local (reauth) | Sesión admin + CSRF | equipos.desbloquear | estado | No-solo admin | IT |
| POST | `/admin/tenants/{id}/unlock-pin/reveal` | Revela el PIN local (reauth, auditado) | Sesión admin + CSRF | equipos.desbloquear | PIN | No-solo admin | IT |
| GET | `/admin/tenants/{id}/windows-security` | Ajustes de Windows del cliente (Smart App Control) | Sesión admin | equipos.desbloquear | ajustes | No-solo admin | IT |
| PUT | `/admin/tenants/{id}/windows-security` | Cambia ajustes de Windows del cliente | Sesión admin + CSRF | equipos.desbloquear | ajustes | No-solo admin | IT |
| GET | `/admin/tenants/{id}/hardening` | Configuración de endurecimiento (clave enmascarada) | Sesión admin | hardening.gestionar | config | No-solo admin | IT |
| PUT | `/admin/tenants/{id}/hardening` | Configura endurecimiento | Sesión admin + CSRF | hardening.gestionar | config | No-solo admin | IT |
| POST | `/admin/tenants/{id}/hardening/reveal` | Revela clave compartida de endurecimiento | Sesión admin + CSRF | hardening.gestionar | clave | No-solo admin | IT |
| GET | `/admin/devices/{id}/hardening` | Estado de endurecimiento reportado por un equipo | Sesión admin | hardening.gestionar | estado | No-solo admin | IT |
| POST | `/admin/devices/{id}/hardening/command` | Encola harden/unharden en un equipo | Sesión admin + CSRF | hardening.gestionar | comando aceptado | No-solo admin | IT |
| POST | `/enrollments` | Emite ticket de enrolamiento ligado a clave | Sesión admin + CSRF | equipos.enrolar | ticket | No-solo admin | Admin/IT |
| POST | `/migration/authorizations` | Emite autorización de migración K3→K4 | Sesión admin + CSRF | migracion.gestionar (solo plataforma) | token | No-solo admin | Plataforma |
| POST | `/migration/authorizations/validate` | Valida autorización de migración | Sesión admin + CSRF | migracion.gestionar (solo plataforma) | validación | No-solo admin | Plataforma |
| GET | `/migration/escrow-key` | Clave pública de escrow de migración | Sesión admin | migracion.gestionar (solo plataforma) | clave | No-solo admin | Plataforma |
| GET | `/tenants/{id}/devices` | Lista equipos de la empresa (scoped por alcance) | Sesión admin | equipos.ver | equipos | No-solo admin | Equivalente externo por miembro: /ext/v1/members/{id}/devices |
| GET | `/devices/{id}` | Lee un equipo, specs y capacidades | Sesión admin | equipos.ver | equipo | No-solo admin | Admin |
| PATCH | `/devices/{id}` | Renombra, revoca o da de baja un equipo | Sesión admin + CSRF | equipos.editar | equipo | No-solo admin | Admin |
| GET | `/devices/{id}/security` | Último reporte de controles de seguridad del equipo | Sesión admin | equipos.ver | reporte | No-solo admin | Admin/IT |
| POST | `/devices/{id}/assignment` | Reasigna un equipo a otra persona | Sesión admin + CSRF | equipos.asignar | equipo | No-solo admin | Admin |
| POST | `/devices/{id}/transfer` **[NO implementado]** | Transfiere equipo entre empresas (nuevo enrolamiento) | Sesión admin + CSRF | equipos.transferir | equipo | No-solo admin | Plataforma |
| POST | `/devices/{id}/commands` | Encola acción remota (bloquear, reiniciar, apagar, borrar) | Sesión admin + CSRF | equipos.<acción> | comando | No-solo admin | Admin/IT |
| GET | `/devices/{id}/commands` | Estado de comandos de un equipo | Sesión admin | equipos.ver | comandos | No-solo admin | Admin/IT |
| POST | `/devices/{id}/escrow` | Guarda escrow de un equipo | Sesión admin + CSRF | migracion.gestionar (solo plataforma) | ACK | No-solo admin | Plataforma |
| POST | `/devices/{id}/escrow/verify` | Verifica escrow de un equipo | Sesión admin + CSRF | migracion.gestionar (solo plataforma) | verificación | No-solo admin | Plataforma |
| POST | `/devices/{id}/escrow/recover` | Recupera escrow de un equipo | Sesión admin + CSRF | migracion.recuperar (solo plataforma) | recuperación | No-solo admin | Plataforma |
| GET | `/devices/{id}/migration` | Estado de migración del equipo | Sesión admin | migracion.ver (solo plataforma) | estado | No-solo admin | Plataforma |
| PUT | `/devices/{id}/migration` | Cambia estado de migración del equipo | Sesión admin + CSRF | migracion.gestionar (solo plataforma) | estado | No-solo admin | Plataforma |
| GET | `/enrollments/expected-devices` | Lista equipos esperados (pre-registro) | Sesión admin | equipos.enrolar | equipos esperados | No-solo admin | Admin/IT |
| POST | `/enrollments/expected-devices` | Registra un equipo esperado | Sesión admin + CSRF | equipos.enrolar | equipo esperado | No-solo admin | Admin/IT |
| POST | `/enrollments/expected-devices:import` | Carga masiva de equipos esperados | Sesión admin + CSRF | equipos.enrolar | resumen de importación | No-solo admin | Admin/IT |
| DELETE | `/enrollments/expected-devices/{id}` | Cancela un equipo esperado | Sesión admin + CSRF | equipos.enrolar | 204/estado | No-solo admin | Admin/IT |
| GET | `/enrollments/requests` | Solicitudes de alta de equipos | Sesión admin | equipos.enrolar | solicitudes | No-solo admin | Admin/IT |
| POST | `/enrollments/requests/{id}/approve` | Aprueba solicitud de alta | Sesión admin + CSRF | equipos.enrolar | solicitud | No-solo admin | Admin/IT |
| POST | `/enrollments/requests/{id}/reject` | Rechaza solicitud de alta | Sesión admin + CSRF | equipos.enrolar | solicitud | No-solo admin | Admin/IT |
| GET | `/enrollments/settings` | Configuración de alta automática | Sesión admin | equipos.enrolar | config | No-solo admin | Admin/IT |
| PUT | `/enrollments/settings` | Cambia configuración de alta | Sesión admin + CSRF | equipos.enrolar | config | No-solo admin | Admin/IT |
| GET | `/enrollments/keys` | Claves de registro de la empresa | Sesión admin | equipos.enrolar | claves (sin secreto) | No-solo admin | Admin/IT |
| POST | `/enrollments/keys` | Crea clave de registro | Sesión admin + CSRF | equipos.enrolar | clave | No-solo admin | Admin/IT |
| POST | `/enrollments/keys/installer` | Descarga instalador con clave nueva embebida | Sesión admin + CSRF | equipos.enrolar | binario ZIP | No-solo admin | Admin/IT |
| DELETE | `/enrollments/keys/{id}` | Revoca clave de registro | Sesión admin + CSRF | equipos.enrolar | 204/estado | No-solo admin | Admin/IT |

### Roles, permisos y accesos (10)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/roles` | Lista roles (semilla y propios) | Sesión admin | roles.ver | roles | No-solo admin | Admin |
| POST | `/roles` | Crea o clona un rol | Sesión admin + CSRF | roles.gestionar | rol | No-solo admin | Admin |
| GET | `/permissions` | Catálogo maestro de permisos | Sesión admin | roles.ver | permisos | No-solo admin | Admin |
| GET | `/roles/{id}` | Lee un rol | Sesión admin | roles.ver | rol | No-solo admin | Admin |
| PATCH | `/roles/{id}` | Edita/desactiva rol | Sesión admin + CSRF | roles.gestionar | rol | No-solo admin | Admin |
| GET | `/permissions/{code}` | Lee un permiso | Sesión admin | roles.ver | permiso | No-solo admin | Admin |
| PATCH | `/permissions/{code}` | Edita metadata/delegabilidad de un permiso | Sesión admin + CSRF | permisos.gestionar | permiso | No-solo admin | Plataforma |
| GET | `/role-rules` | Reglas de asignación automática de rol por cargo | Sesión admin | roles.reglas | reglas | No-solo admin | Admin |
| POST | `/role-rules` | Crea regla de rol por cargo | Sesión admin + CSRF | roles.reglas | regla | No-solo admin | Admin |
| PATCH | `/role-rules/{id}` | Edita regla de rol por cargo | Sesión admin + CSRF | roles.reglas | regla | No-solo admin | Admin |

### Reglas/políticas y cumplimiento (13)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/policies` | Lista políticas/reglas del agente (alcance todo) | Sesión admin | reglas.ver | políticas | No-solo admin | Admin |
| POST | `/policies` | Crea política (web, USB, descargas, instalaciones…) | Sesión admin + CSRF | reglas.editar | política | No-solo admin | Admin |
| GET | `/policies/suspicious-apps` | Catálogo de apps sospechosas | Sesión admin | cumplimiento.ver | apps | No-solo admin | Cumplimiento |
| POST | `/policies/suspicious-apps` | Agrega app sospechosa | Sesión admin + CSRF | cumplimiento.editar | app | No-solo admin | Cumplimiento |
| GET | `/reports/dual-job-alerts` | Alertas de doble empleo por persona/día | Sesión admin | cumplimiento.ver | alertas con evidencia | No-solo admin | Sensible; nunca al colaborador |
| GET | `/reports/suspicious-apps` | Detecciones de apps sospechosas | Sesión admin | cumplimiento.ver | detecciones | No-solo admin | Sensible |
| GET | `/reports/compliance-signals` | Catálogo de señales de cumplimiento | Sesión admin | cumplimiento.ver | señales | No-solo admin | Cumplimiento |
| GET | `/policies/{id}` | Lee una política | Sesión admin | reglas.ver | política | No-solo admin | Admin |
| PATCH | `/policies/{id}` | Publica nueva revisión de política | Sesión admin + CSRF | reglas.editar | política | No-solo admin | Admin |
| GET | `/users/{id}/policies` | Política efectiva compilada de una persona | Sesión admin | reglas.ver | reglas efectivas | No-solo admin | Podría servir a «Mis reglas»; solo con sesión de panel |
| GET | `/policies/suspicious-apps/{id}` | Lee app sospechosa | Sesión admin | cumplimiento.ver | app | No-solo admin | Cumplimiento |
| PUT | `/policies/suspicious-apps/{id}` | Edita app sospechosa | Sesión admin + CSRF | cumplimiento.editar | app | No-solo admin | Cumplimiento |
| DELETE | `/policies/suspicious-apps/{id}` | Elimina app sospechosa | Sesión admin + CSRF | cumplimiento.editar | 204 | No-solo admin | Cumplimiento |

### Puertas (4)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/doors` | Lista puertas de control de acceso | Sesión admin | organizacion.ver | puertas | No-solo admin | Admin |
| POST | `/doors` | Registra una puerta | Sesión admin + CSRF | organizacion.editar | puerta | No-solo admin | Admin |
| PATCH | `/doors/{id}` | Edita una puerta | Sesión admin + CSRF | organizacion.editar | puerta | No-solo admin | Admin |
| GET | `/doors/quarantine` | Entradas por puerta sin emparejar con persona | Sesión admin | organizacion.ver | eventos en cuarentena | No-solo admin | Admin |

### Clasificación de apps (5)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/app-classification` | Clasificación productiva/no productiva de apps y sitios | Sesión admin | reportes.ver | clasificación + excepciones | No-solo admin | Exige alcance «todo» |
| PUT | `/app-classification` | Clasifica una app o sitio | Sesión admin + CSRF | actividad.clasificar | clasificación | No-solo admin | Admin |
| POST | `/app-classification/overrides` | Excepción de clasificación por área o cargo | Sesión admin + CSRF | actividad.clasificar | excepción | No-solo admin | Admin |
| DELETE | `/app-classification/overrides/{id}` | Quita excepción | Sesión admin + CSRF | actividad.clasificar | 204 | No-solo admin | Admin |
| POST | `/app-sites` | Agrega un sitio al catálogo | Sesión admin + CSRF | actividad.clasificar | sitio | No-solo admin | Admin |

### Releases del agente (3)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/releases` | Lista versiones del agente visibles | Sesión admin | releases.ver (solo plataforma) | releases y despliegues | No-solo admin | Plataforma |
| POST | `/releases` | Registra manifest firmado del pipeline | Sesión admin + CSRF | releases.publicar (solo plataforma) | release | No-solo admin | Plataforma |
| POST | `/release-deployments` | Elige anillo/rollout de una versión para la empresa | Sesión admin + CSRF | releases.desplegar (solo plataforma) | despliegue | No-solo admin | Plataforma |

### Integraciones, fuentes de datos, webhooks y notificaciones (19)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/integrations` **[NO implementado]** | Lista integraciones (API keys/OAuth) sin secreto | Sesión admin | integraciones.gestionar (solo plataforma) | integraciones | No-solo admin | NO implementado: hoy se provisionan por SQL |
| POST | `/integrations` **[NO implementado]** | Crea API key o cliente OAuth con scopes | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | integración + secreto | No-solo admin | NO implementado |
| GET | `/webhooks` **[NO implementado]** | Lista destinos de webhooks | Sesión admin | notificaciones.gestionar | webhooks | No-solo admin | NO implementado |
| POST | `/webhooks` **[NO implementado]** | Registra destino HTTPS de webhooks | Sesión admin + CSRF | notificaciones.gestionar | webhook | No-solo admin | NO implementado |
| GET | `/notifications` **[NO implementado]** | Lee notificaciones internas | Sesión admin | notificaciones.ver | notificaciones | No-solo admin | NO implementado |
| POST | `/integrations/{id}/rotate` **[NO implementado]** | Rota el secreto (solape 24 h) | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | integración | No-solo admin | NO implementado |
| DELETE | `/integrations/{id}` **[NO implementado]** | Revoca integración y sus tokens | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | 204 | No-solo admin | NO implementado |
| DELETE | `/webhooks/{id}` **[NO implementado]** | Desactiva un webhook | Sesión admin + CSRF | notificaciones.gestionar | 204 | No-solo admin | NO implementado |
| GET | `/data-sources` | Fuentes de datos externas (p. ej. AZC One) | Sesión admin | integraciones.ver | fuentes | No-solo admin | Plataforma |
| POST | `/data-sources` | Crea fuente de datos | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | fuente | No-solo admin | Plataforma |
| PATCH | `/data-sources/{id}` | Edita fuente de datos | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | fuente | No-solo admin | Plataforma |
| POST | `/data-sources/{id}/runs` | Sincroniza ahora una fuente | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | vuelta | No-solo admin | Plataforma |
| GET | `/data-domains` | Qué fuente es dueña de cada dominio de datos | Sesión admin | integraciones.ver | dominios | No-solo admin | Plataforma |
| PUT | `/data-domains` | Cambia dueños de dominios | Sesión admin + CSRF | integraciones.gestionar (solo plataforma) | dominios | No-solo admin | Plataforma |
| GET | `/sync-runs` | Historial de sincronizaciones | Sesión admin | integraciones.ver | vueltas | No-solo admin | Plataforma |
| GET | `/sync-runs/{id}/items` | Detalle de una sincronización | Sesión admin | integraciones.ver | items | No-solo admin | Plataforma |
| POST | `/sync-runs/{id}/approve` | Aplica una vuelta bloqueada | Sesión admin + CSRF | integraciones.aprobar (solo plataforma) | vuelta | No-solo admin | Plataforma |
| GET | `/sync-items/pending` | Pendientes de aprobación | Sesión admin | integraciones.ver | items | No-solo admin | Plataforma |
| POST | `/sync-items/{id}/resolve` | Resuelve un pendiente | Sesión admin + CSRF | integraciones.aprobar (solo plataforma) | item | No-solo admin | Plataforma |

### Auditoría y logs (2)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/audit` | Consulta la auditoría (alcance todo) | Sesión admin | auditoria.ver | entradas de auditoría | No-solo admin | Admin |
| GET | `/audit/client-logs` | Logs técnicos del agente (scoped) | Sesión admin | operacion.logs | filas de log | No-solo admin | IT; no es «actividad» |

### Empresas, funciones y planes (23)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/tenants` | Lista las empresas/clientes visibles a la sesión | Sesión admin | empresas.ver | empresas con branding | No-solo admin | Gestión de clientes |
| POST | `/tenants` | Crea una empresa cliente | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | empresa | No-solo admin | Plataforma |
| GET | `/features` | Catálogo de funciones vendibles | Sesión admin | empresas.gestionar (solo plataforma) | funciones | No-solo admin | Plataforma |
| GET | `/feature-packages` | Lista paquetes de funciones | Sesión admin | empresas.gestionar (solo plataforma) | paquetes | No-solo admin | Plataforma |
| POST | `/feature-packages` | Crea un paquete de funciones | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | paquete | No-solo admin | Plataforma |
| PATCH | `/feature-packages/{id}` | Edita un paquete de funciones | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | paquete | No-solo admin | Plataforma |
| GET | `/person-plans` | Catálogo de planes por persona (plataforma) | Sesión admin | empresas.gestionar (solo plataforma) | planes | No-solo admin | Plataforma |
| POST | `/person-plans` | Crea un plan por persona | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | plan | No-solo admin | Plataforma |
| GET | `/person-plans/available` | Planes asignables en la empresa | Sesión admin | planes.asignar | planes | No-solo admin | Equivalente externo: /ext/v1/plans |
| PATCH | `/person-plans/{id}` | Edita un plan por persona | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | plan | No-solo admin | Plataforma |
| GET | `/tenants/{id}` | Lee empresa y su marca (logo, colores) | Sesión admin | empresas.ver | empresa + branding | No-solo admin | El branding sería útil al portal, pero no hay equivalente /ext |
| PATCH | `/tenants/{id}` | Edita configuración de la empresa (o archiva) | Sesión admin + CSRF | empresas.editar | empresa | No-solo admin | Admin |
| PATCH | `/tenants/{id}/rbac-gate` | Activa/revoca la autogestión de roles del cliente | Sesión admin + CSRF | empresas.rbac_habilitar (solo plataforma) | empresa | No-solo admin | Plataforma |
| PUT | `/users/{id}/plan` | Asigna un plan a una persona | Sesión admin + CSRF | planes.asignar | persona | No-solo admin | Admin |
| POST | `/tenants/{id}/purge` | Elimina definitivamente una empresa archivada | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | 204 | No-solo admin | Plataforma |
| GET | `/tenants/{id}/features` | Funciones activas de un cliente | Sesión admin | empresas.gestionar (solo plataforma) | funciones del cliente | No-solo admin | Plataforma |
| PUT | `/tenants/{id}/features` | Enciende/apaga funciones de un cliente | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | funciones del cliente | No-solo admin | Plataforma |
| GET | `/tenants/{id}/plan-usage` | Conteo de personas por plan (facturación) | Sesión admin | empresas.gestionar (solo plataforma) | uso por plan | No-solo admin | Plataforma |
| POST | `/tenants/{id}/logo` | Sube el logo de la empresa | Sesión admin + CSRF | empresas.editar | empresa | No-solo admin | Admin |
| GET | `/company-groups` | Lista grupos empresariales (varias empresas) | Sesión admin | empresas.gestionar (solo plataforma) | grupos | No-solo admin | Plataforma |
| POST | `/company-groups` | Crea grupo empresarial | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | grupo | No-solo admin | Plataforma |
| PATCH | `/company-groups/{id}` | Edita grupo empresarial | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | grupo | No-solo admin | Plataforma |
| DELETE | `/company-groups/{id}` | Elimina grupo empresarial | Sesión admin + CSRF | empresas.gestionar (solo plataforma) | 204 | No-solo admin | Plataforma |

### Operación del panel (3)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/tenants/server-health` | Salud del servidor/cron/colas del cliente | Sesión admin | operacion.salud (solo plataforma) | métricas de salud | No-solo admin | Operación de plataforma |
| GET | `/tenants/panel-settings` | Lee ajustes del panel (retención, heartbeat de cobertura) | Sesión admin | operacion.ajustes_ver | ajustes | No-solo admin | Operación |
| PUT | `/tenants/panel-settings` | Cambia ajustes del panel | Sesión admin + CSRF | operacion.ajustes_editar | ajustes | No-solo admin | Operación |

### API externa /ext/v1 (13)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/ext/v1/dashboard` | KPIs de empresa: online/total, horas activas, focus, productividad, carga por área, roster de 20 | API key u OAuth | dashboard:read | Dashboard | Sí | Pantalla «Inicio / Command Center» (vista empresa, filtrable por sede/área/persona) |
| GET | `/ext/v1/my-team` | Lista paginada de personas con rol, plan, última conexión, focus y productividad | API key u OAuth | team:read | TeamMember[] paginado | Sí | «Mi equipo» / ranking; filtros area_id, site_id, company_id, user_id, country, city |
| GET | `/ext/v1/plans` | Planes de persona disponibles en la empresa | API key u OAuth | plans:read | PersonPlan[] | Sí | Etiquetas/badges de plan |
| GET | `/ext/v1/productivity` | Serie diaria de productividad/focus (hasta 366 días) con cobertura y frescura | API key u OAuth | productivity:read | ProductivityReport | Sí | Gráficas de productividad (empresa, área, sede o persona vía user_id) |
| GET | `/ext/v1/organization` | Catálogo de empresas, sedes, áreas y cargos | API key u OAuth | organization:read | OrgUnit[] paginado | Sí | Filtros del portal y árbol organizacional |
| GET | `/ext/v1/notifications` | Feed saneado de eventos | API key u OAuth | notifications:read | Notification[] paginado | Sí | Feed de avisos (hoy vacío: nada inserta notificaciones) |
| GET | `/ext/v1/members/{id}` | Perfil mínimo de una persona + tendencia de productividad | API key u OAuth | members:read | member + trend | Sí | Ficha «Resumen» del colaborador |
| GET | `/ext/v1/members/{id}/devices` | Equipos de la persona con specs/estado (sin secretos) | API key u OAuth | members:read, devices:read | Device[] paginado | Sí | Pestaña «Mis equipos» |
| GET | `/ext/v1/members/{id}/activity` | Episodios de actividad (app, inicio/fin, activo/inactivo); títulos opcionales | API key u OAuth | members:read, activity:read | Episode[] paginado, rango ≤31 días | Sí | Pestaña «Actividad» del colaborador |
| GET | `/ext/v1/members/{id}/check-ins` | Check-ins (entrada/salida, fuente, sede); GPS opcional | API key u OAuth | members:read, presence:read | CheckIn[] paginado, rango ≤31 días | Sí | Pestaña «Asistencia / Check-in» |
| GET | `/ext/v1/members/{id}/plan` | Plan efectivo de la persona y funciones que le aplica | API key u OAuth | members:read, plans:read | plan + features | Sí | Badge de plan en el perfil |
| POST | `/ext/v1/expected-devices:import` | Carga equipos esperados desde otro sistema (escritura) | API key u OAuth | expected-devices:write | resumen de importación | No-solo admin | Integración IT/inventario, no pantalla del portal |
| POST | `/ext/v1/check-ins:batch` | Envía eventos de puertas (control de acceso) como check-ins (escritura) | API key u OAuth | check-ins:write | aceptados/rechazados | No-solo admin | Lo usa el puente de puertas (uhppoted), no el portal |

### Agente /client (14)

| Método | Ruta | Para qué sirve | Autenticación | Permiso / scope | Datos que devuelve | ¿Útil para el portal? | Por qué |
|---|---|---|---|---|---|---|---|
| GET | `/client/hardening` | Lee configuración de endurecimiento y clave compartida del tenant | Dispositivo (token+firma) | — | config de hardening | No-solo agente | Agente |
| POST | `/client/hardening/report` | Reporta el estado observado del endurecimiento | Dispositivo (token+firma) | — | ACK | No-solo agente | Agente |
| POST | `/client/auth/challenges` | Pide un nonce de un solo uso para firmar el login del equipo | Pública | — | nonce, TTL 60 s | No-solo agente | Protocolo del agente Windows |
| POST | `/client/login` | Alta/recuperación/renovación de la sesión del equipo con su clave | Firma de dispositivo | — | token de dispositivo | No-solo agente | Protocolo del agente |
| POST | `/client/sync` | Sincronización cada 120 s: sube actividad/logs/seguridad, baja política/comandos/release | Dispositivo (token+firma) | — | política, comandos, ACKs, release, token | No-solo agente | Protocolo del agente |
| GET | `/client/policy` | Lee la política compilada del equipo (ETag) | Dispositivo (token+firma) | — | política y horario | No-solo agente | Protocolo del agente |
| POST | `/client/episodes:batch` | Sube actividad atrasada (episodios) con ACK por registro | Dispositivo (token+firma) | — | acks[] | No-solo agente | Protocolo del agente |
| GET | `/client/commands` | Lista comandos pendientes del propio equipo | Dispositivo (token+firma) | — | comandos | No-solo agente | Protocolo del agente |
| POST | `/client/security/report` | Declara el estado de controles de seguridad del equipo | Dispositivo (token+firma) | — | ACK | No-solo agente | Protocolo del agente |
| POST | `/client/logs` | Sube logs técnicos saneados del agente | Dispositivo (token+firma) | — | acks[] | No-solo agente | Protocolo del agente |
| POST | `/client/check-ins` | Registra un check-in (entrada/salida) del miembro del equipo | Dispositivo (token+firma) | — | check-in creado | No-solo agente | Lo emite el agente, no el portal |
| POST | `/client/commands/{id}/result` | Reporta el resultado de un comando ejecutado | Dispositivo (token+firma) | — | ACK | No-solo agente | Protocolo del agente |
| GET | `/client/releases/{id}` | Descarga el manifest firmado de una actualización autorizada | Dispositivo (token+firma) | — | manifest JWS | No-solo agente | Updater del agente |
| POST | `/client/enrollment-requests` | El equipo solicita alta con la clave de registro de la empresa | Firma de dispositivo | — | estado de la solicitud | No-solo agente | Protocolo del agente |

## 2. Lo que el portal puede consumir HOY

Requisitos previos [Seguro]:
- **Que el cliente tenga la función `api` encendida.** `ExternalAuth.php:107-109` exige `Features::has(..., 'api')`, o `puertas` para `check-ins:batch`. Si no la tiene, responde 403 `feature_not_contracted:api`. En el catálogo, `api` sigue marcada `pending => true` (`Features.php:33`).
- **Una integración creada a mano en la base de datos.** `/integrations` no está implementado (ver la tabla) y `EXTERNAL.md:26-28` lo confirma: «El aprovisionamiento existente debe registrar la integración, su principal…, los `integration_scopes` y la credencial». Hoy no hay pantalla ni endpoint que lo haga.
- **Todo pasa por un BFF.** El navegador nunca guarda la API key ni el client secret (`docs/architecture/v4-api.md:86`). Además, Keeper no envía cabeceras CORS: `Access-Control` no aparece en ningún archivo de `backend-v4/src` ni de `config`. Lovable tiene que llamar a su backend (Supabase Edge Function o similar) y ese backend llama a Keeper de servidor a servidor.
- Límites: token de 15 min sin refresh (`ExternalAuth.php:60-63`), 120 req/min por integración, 4 consultas simultáneas por integración (`EXTERNAL.md:71-85`). Las respuestas GET llevan `Cache-Control: private, max-age=30` (`Application.php:25`).

| Endpoint | Scope | Pantalla del portal que alimentaría | Notas |
|---|---|---|---|
| `POST /v1/oauth/token` | (los concedidos) | Ninguna; lo usa el BFF para autenticarse | `client_secret_basic`, form-urlencoded |
| `GET /ext/v1/dashboard?from&to` | `dashboard:read` | **Inicio / Command Center** (empresa, sede o área) | Roster limitado a 20; responde 422 con más de 100 áreas (`ExternalApi.php:127`) |
| `GET /ext/v1/my-team` | `team:read` | **Mi equipo / ranking** (supervisor) | Filtros `area_id`, `site_id`, `company_id`, `user_id`, `country`, `city` (`ExternalApi.php:32-39`). **No** filtra por jefe (`reports_to_user_id`) |
| `GET /ext/v1/productivity?from&to` | `productivity:read` | **Gráficas de productividad/focus** (empresa, área, sede o persona con `user_id`) | Hasta 366 días (`ExternalApi.php:44`); incluye `coverage_percent`, `data_through` |
| `GET /ext/v1/organization` | `organization:read` | **Filtros y árbol organizacional** (empresas, sedes, áreas, cargos) | Paginado |
| `GET /ext/v1/plans` | `plans:read` | Badges/etiquetas de plan | — |
| `GET /ext/v1/members/{id}` | `members:read` | **Perfil / Resumen** del colaborador | Perfil mínimo + tendencia; sin cédula, horario, cargo ni jefe |
| `GET /ext/v1/members/{id}/activity?from&to` | `members:read`+`activity:read` (+`activity-titles:read` si `include_titles=true`) | **Mi actividad** (apps, tiempo activo/inactivo) | Máximo 31 días; títulos solo con la función `sitios` |
| `GET /ext/v1/members/{id}/check-ins?from&to` | `members:read`+`presence:read` (+`location:read` para GPS) | **Asistencia / check-in** (incluye puertas) | GPS solo con la función `ubicacion` |
| `GET /ext/v1/members/{id}/devices` | `members:read`+`devices:read` | **Mis equipos** | Sin secretos |
| `GET /ext/v1/members/{id}/plan` | `members:read`+`plans:read` | Badge del plan en el perfil | Plan propio o el por defecto del cliente |
| `GET /ext/v1/notifications` | `notifications:read` | Feed de avisos | [Seguro] **Siempre vacío hoy**: en `src/` no hay ningún `INSERT INTO notifications` |

Fuera del portal: `POST /ext/v1/expected-devices:import` (inventario de IT) y `POST /ext/v1/check-ins:batch` (puente de puertas). Son escrituras de integración, no pantallas.

## 3. Brechas para el portal (verificadas en código)

### 3.1 ¿Puede un colaborador autenticarse y obtener SOLO sus datos?

**Por la API externa, no** [Seguro]:
- La credencial es de la **integración**, no de una persona. `ExternalAuth.php:10-16` resuelve `tenant_id`, `integration_id` y `principal kind='integration'`. La tabla `integrations` (`migrations/0003_devices_access.sql:205-219`) no tiene `user_id`, solo `owner_admin_id`.
- El **único** grant es `client_credentials` (`ExternalAuth.php:33-67`). En `src/`, `migrations/` y `docs/architecture/` no aparece `authorization_code`, PKCE, OIDC ni `openid`.
- La persona la elige quien llama: `user_id` como filtro libre (`ExternalApi.php:34-39`) o `{id}` en la ruta (`ExternalApi.php:40`, que solo comprueba `tenant_id` en `ExternalApi.php:15-18`). Cualquier BFF con la credencial ve a **cualquier persona del tenant**.
- El propio diseño lo prohíbe como mecanismo de aislamiento: «`client_credentials` identifica una aplicación, no al usuario final. Si el portal requiere autoservicio individual con aislamiento impuesto por Keeper, debe añadirse Authorization Code + PKCE/delegación… no simularlo pasando un `user_id` libre con una credencial empresarial» (`docs/architecture/v4-api.md:86`).

**Por el panel, sí existe login por persona, pero no sirve a Lovable** [Seguro]:
- Hay cuentas por persona (`POST /panel-accounts`, `PanelAccounts.php:79-86` activa `panel_login_enabled`). El login (`AdminAuth.php:96`) exige persona activa con `panel_login_enabled=TRUE` (también `AdminAuth.php:25`).
- Cada persona tiene **1 rol + 1 alcance**: `self` (por defecto), `team` (quienes le reportan, directa o indirectamente, por `reports_to_user_id`), `filters` (empresa/ciudad/sede/área) o `all` (`AccessScope.php:5-10`, `AdminAccess.php:42-50`, `AdminAccess.php:54-67`). Una persona nueva recibe Colaborador + `self` (`AccessGrants.php:69-74`).
- Ese alcance **sí se aplica** en: `/users`, `/users/{id}`, `/reports/productivity` (`AdminApi.php:787`), `/reports/people`, `/reports/presence`, `/reports/apps`, `/users/{id}/activity` (`ActivityReports.php:21,46`), `/absences` (`Absences.php:39`), `/tenants/{id}/devices`, `/devices/install-coverage`, `/reports/dual-job-alerts` (`OperationsApi.php:115-152`).
- Por qué no lo puede usar Lovable: la cookie es `__Host-…; SameSite=Strict` (`AdminAuth.php:12`); toda mutación exige `Origin == ORIGIN` (`AdminAuth.php:17`); no hay CORS. Funciona solo en el mismo origen que el panel, y la idea es que ese origen quede 100 % para administración. [Probable] Un BFF que reenvíe la contraseña del usuario y falsifique `Origin` funcionaría técnicamente. No estoy de acuerdo con ese camino porque convierte al BFF en un proxy de credenciales de panel. En lugar de eso haría un flujo de delegación propio (ver 3.4). El riesgo de usarlo es que una fuga del BFF expone sesiones de panel con permisos de escritura y no solo lecturas.
- No hay endpoint «quién soy»: `/me/access` devuelve permisos y funciones pero **no** `user_id` (`AdminApi.php:507-519`). `/auth/login` devuelve `admin_id`, no `user_id` (`AdminAuth.php:108`).

### 3.2 Qué falta para cada pantalla

| Necesidad del portal | Hoy en /ext/v1 | Hoy solo en panel (con alcance) | Falta |
|---|---|---|---|
| Colaborador: mi actividad | `members/{id}/activity` (sin aislamiento) | `/users/{id}/activity` | Endpoint `me/*` atado al token de la persona |
| Colaborador: mi productividad / focus | `productivity?user_id=` y `members/{id}` (tendencia) | `/reports/productivity` | Idem |
| Colaborador: mis ausencias | **No existe** | `/absences` (alcance aplicado) | `GET /ext/v1/members/{id}/absences` o `me/absences` |
| Colaborador: mi horario y festivos | **No existe** | `/schedules`, `/schedules/holidays*` exigen alcance «todo» (`AdminApi.php:877`, `HolidayCalendars.php:32`) | Endpoint de horario efectivo + festivos por persona |
| Colaborador: mi presencia/puntualidad (llegada vs horario) | Solo check-ins crudos | `/reports/presence` | Exponer el cálculo de puntualidad en /ext |
| Colaborador: mis apps | Episodios crudos | `/reports/apps` | Agregado por app en /ext |
| Colaborador: mis equipos | `members/{id}/devices` | `/tenants/{id}/devices` | Solo el aislamiento |
| Colaborador: mis reglas | **No existe** | `/users/{id}/policies` | Opcional |
| «Mis equipos de trabajo» (área/sede/jefe) | `members/{id}` trae `firm_id`/`area_id`/`site_id`, pero no cargo, jefe ni horario | `/users/{id}` (ficha completa) | Perfil extendido: `position_id`, `schedule_id`, `reports_to_user_id` |
| Supervisor: su equipo | `my-team` filtra por área/sede, **no por jefe** | Alcance `team` por `reports_to_user_id` | Filtro/alcance `team` en /ext (o token delegado con alcance) |
| Branding del cliente (logo/colores) | **No existe** | `/tenants/{id}` | `GET /ext/v1/branding` |
| Avisos en tiempo real | `notifications` siempre vacío | `/notifications` y `/webhooks` sin implementar | Productor de eventos + webhooks |
| Gestión de credenciales | — | `/integrations*` sin implementar | Implementar o seguir con SQL a mano |

### 3.3 ¿Cómo se filtraría hoy por rol/área/sede?

- **Con /ext/v1 (lo único consumible):** el filtrado lo hace el **BFF**, no Keeper. El BFF autentica a la persona (p. ej. Supabase Auth), busca en una tabla propia su `user_id` de Keeper, su área/sede y su rol, y fuerza `user_id`/`area_id`/`site_id`/`company_id`/`city`/`country` en cada llamada (`ExternalApi.php:32-39`). Keeper no valida nada de eso: si el BFF se equivoca o lo manipulan, filtra datos de todo el tenant. Además, `team` por jefe directo/indirecto no se puede expresar.
- **Con el panel:** el aislamiento por `self/team/filters/all` ya existe y está bien hecho (404 fuera de alcance, `AdminAccess.php:70-76`; 403 `scope_denied` en filtros fuera de alcance, `AdminAccess.php:94-101`), pero no es alcanzable desde otro origen.
- [Probable] Una opción intermedia: varias integraciones por audiencia (una con `team:read`+`productivity:read` para supervisores, otra con `members:read`+`activity:read`). Reduce scopes, pero **no** aísla por persona.

### 3.4 Qué haría

No estoy de acuerdo con lanzar el portal de colaboradores sobre `client_credentials` + `user_id` del BFF, porque el aislamiento quedaría solo en código de Lovable, fuera de Keeper. En lugar de eso haría:
1. **Fase supervisor/gerencia** (vista agregada, pocos usuarios de confianza): BFF + /ext/v1, aceptando que el BFF filtra. Es viable hoy.
2. **Para colaboradores:** añadir a Keeper un grant delegado. Sería OAuth Authorization Code + PKCE (o token-exchange firmado por el BFF) cuyo token lleve `user_id` y reutilice `AccessGrants`/`AdminAccess::usersSql`. Más endpoints `/ext/v1/me/*` (perfil, actividad, productividad, ausencias, horario, check-ins, equipos) y `team` para supervisores.

El riesgo de saltarse el punto 2 es concreto: un bug o un parámetro manipulado en el BFF expone actividad y títulos de ventana de cualquier persona del tenant. Y eso no queda en la auditoría como acceso de la persona, sino como acceso de la integración (`ExternalApi.php:23,28` audita con el contexto de la integración).

## 4. Webhooks

[Seguro] **No hay webhooks operativos.**
- Contrato: `GET/POST /webhooks` y `DELETE /webhooks/{id}` (permiso `notificaciones.gestionar`) están en el YAML pero **no** en `contract.json` ni en el despacho de `AdminApi.php:131-167`. Responden 404.
- La función `webhooks` está marcada `pending => true` (`Features.php:35`).
- En `src/` no hay código de entrega (ni cola, ni firma, ni reintentos) ni ningún `INSERT INTO notifications`. Por eso `/ext/v1/notifications` responde vacío.
- Eventos **diseñados** (no emitidos): `device.status_changed`, `command.completed`, `policy.changed`, `subscription.changed` (obsoleto: las suscripciones se retiraron el 2026-10-07), `report.ready`. La carga útil propuesta es `event_id`, `tenant_id`, `type`, `resource_id`, `occurred_at`, firmada con HTTP Signatures y con reintentos hasta 24 h (`docs/architecture/v4-api.md:344`; títulos de los eventos en `ExternalApi.php:186`).
- Para el portal: hoy se refresca por sondeo (60 s visible, respetando `data_through`), como sugiere `v4-api.md` §8.
