# API del Portal de Keeper — guía para construir el Portal en Lovable

Fecha: 2026-10-13. Contrato formal: `docs/architecture/openapi-v4.yaml` (esquema de seguridad `portalBearer`).

## Qué es

El Portal es una aplicación aparte (la que se construye en Lovable) donde supervisores, administradores y gerentes ven la información de su gente: inicio, personas, ficha, ventanas, línea de tiempo, productividad, puntualidad, ausencias y equipos.

El Portal **no tiene datos propios**. Usa la misma API que el panel de Keeper, con una dirección distinta:

- Panel: `https://<servidor>/v1/<ruta>` (cookie de sesión)
- Portal: `https://<servidor>/portal/v1/<ruta>` (token en la cabecera `Authorization`)

Cada persona ve exactamente lo mismo que vería en el panel: su rol decide qué puede hacer y su alcance decide a quién ve (solo ella, su equipo, unos filtros o toda la empresa). Si una persona no se ve en el panel, tampoco se ve en el Portal.

## Quién puede entrar

Hacen falta dos cosas:

1. Tener cuenta del panel activa. Se entra con el mismo correo y la misma contraseña del panel.
2. Tener encendido el interruptor «Puede entrar al Portal» en su ficha (lo enciende un administrador del cliente).

Si la contraseña es correcta pero el interruptor está apagado, la respuesta es `403 portal_not_enabled`.

## Iniciar sesión

```http
POST /portal/v1/login
Content-Type: application/json

{"email": "ana@empresa.com", "password": "su-contraseña"}
```

Respuesta `200`:

```json
{
  "access_token": "Q2x2...43 caracteres",
  "token_type": "Bearer",
  "expires_in": 900,
  "refresh_token": "b3Jh...43 caracteres",
  "refresh_expires_at": "2026-10-13T22:15:00.000000Z",
  "user": {
    "id": "8f1c...", "admin_id": "1b2d...", "tenant_id": "4e5f...",
    "display_name": "Ana Pérez", "email": "ana@empresa.com", "must_change_password": false
  }
}
```

- `access_token` dura **15 minutos** (`expires_in` = 900 segundos). Va en cada pedido: `Authorization: Bearer <access_token>`.
- `refresh_token` sirve para pedir un par nuevo sin volver a escribir la contraseña. La sesión completa dura como máximo **8 horas** desde el login (`refresh_expires_at`); después hay que iniciar sesión otra vez.
- Si `must_change_password` es `true`, la persona tiene una contraseña temporal: solo puede cambiarla (`/portal/v1/password`) o cerrar sesión. Cualquier otra ruta responde `403 password_change_required`.
- Guarda los tokens en memoria o en almacenamiento de sesión. No uses cookies: el Portal no las usa.

## Renovar el token

Cuando el `access_token` venza (respuesta `401`) o un poco antes:

```http
POST /portal/v1/refresh
Content-Type: application/json

{"refresh_token": "<el último refresh_token recibido>"}
```

Responde lo mismo que el login, con **un par nuevo**. Reglas importantes:

- Cada `refresh_token` sirve **una sola vez**. Guarda siempre el último que recibiste.
- Si se usa un `refresh_token` viejo (ya usado), Keeper asume que alguien lo copió y **cierra toda esa sesión**: el token nuevo también deja de servir y hay que iniciar sesión otra vez.
- Al renovar, el `access_token` anterior deja de servir de inmediato.
- Si la app hace varios pedidos a la vez cuando vence el token, haz **una sola** renovación y que los demás esperen su resultado.

## Cerrar sesión

```http
POST /portal/v1/logout
Authorization: Bearer <access_token>
```

Responde `204` sin cuerpo. El `access_token` y el `refresh_token` de esa sesión dejan de servir.

## Cambiar la propia contraseña

```http
POST /portal/v1/password
Authorization: Bearer <access_token>
Content-Type: application/json

{"current_password": "actual", "new_password": "la-nueva-de-12-o-más"}
```

Responde como el login, con un par de tokens nuevo. La contraseña es la misma del panel: al cambiarla se cierran todas las sesiones anteriores de esa persona (Portal y panel). La nueva debe ser distinta de la actual y del correo (`422 weak_password`).

## Rutas disponibles

Todas van con `Authorization: Bearer <access_token>`. Los parámetros, filtros y respuestas son los mismos de la ruta equivalente del panel (ver `openapi-v4.yaml`, operaciones con seguridad `portalBearer`).

| Pantalla | Pedido | Qué devuelve |
|---|---|---|
| Quién soy | `GET /portal/v1/me` | Persona, rol, alcance, funciones de la empresa, permisos, empresa y marca (nombre, logo, colores) |
| Menú | `GET /portal/v1/me/access` | Permisos y alcance, para mostrar solo lo que la persona puede abrir |
| Inicio | `GET /portal/v1/dashboard` | Indicadores del día |
| Inicio | `GET /portal/v1/dashboard/problems` | Cosas por atender |
| Personas | `GET /portal/v1/users` | Personas visibles (paginado con `limit` y `cursor`) |
| Ficha | `GET /portal/v1/users/{id}` | Datos de una persona |
| Ficha: actividad | `GET /portal/v1/users/{id}/activity?from=AAAA-MM-DD&to=AAAA-MM-DD` | Días con llegada, tiempo activo y productividad |
| Ficha: ventanas | `GET /portal/v1/users/{id}/windows?from=...&to=...` | Aplicaciones y sitios usados |
| Ficha: línea de tiempo | `GET /portal/v1/users/{id}/timeline?day=AAAA-MM-DD` | Uso a lo largo del día |
| Reportes | `GET /portal/v1/reports/people` | Resumen por persona |
| Puntualidad | `GET /portal/v1/reports/presence` | Llegadas y tardanzas |
| Productividad | `GET /portal/v1/reports/productivity` | Productividad del periodo |
| Aplicaciones | `GET /portal/v1/reports/apps` | Aplicaciones más usadas |
| Ausencias | `GET /portal/v1/absences` | Ausencias de las personas visibles |
| Registrar ausencia | `POST /portal/v1/absences` | Solo para la propia persona (ver abajo) |
| Equipos | `GET /portal/v1/tenants/{tenant_id}/devices` | Equipos de las personas visibles (`tenant_id` = `user.tenant_id` del login) |
| Equipo | `GET /portal/v1/devices/{id}` | Datos de un equipo |
| Horario | `GET /portal/v1/schedules` | Con alcance «toda la empresa», todos los horarios; si no, solo el horario de la persona |

Cualquier otra ruta responde `404`, aunque exista en el panel (por ejemplo, enviar órdenes a equipos, editar personas o reglas).

### Registrar una ausencia propia

```http
POST /portal/v1/absences
Authorization: Bearer <access_token>
Content-Type: application/json

{"user_id": "<user.id del login>", "kind": "permiso", "starts_at": "2026-10-20T13:00:00Z", "ends_at": "2026-10-20T15:00:00Z", "all_day": false, "note": "Cita médica"}
```

`kind`: `vacaciones`, `licencia_remunerada`, `licencia_no_remunerada`, `incapacidad`, `permiso`, `calamidad` u `otro`. Responde `201` con la ausencia. Si `user_id` es otra persona: `403` con `detail: portal_self_only`, aunque su rol permita registrar ausencias a otros en el panel. Además el rol debe tener el permiso de editar personas; si no, `403 permission_denied`.

## Ejemplo de pedido y respuesta

```http
GET /portal/v1/users?limit=50
Authorization: Bearer Q2x2...
```

```json
{
  "data": [{"id": "8f1c...", "display_name": "Ana Pérez", "status": "active", "...": "..."}],
  "next_cursor": null,
  "generated_at": "2026-10-13T15:00:00Z",
  "data_through": "2026-10-13T15:00:00.000000Z"
}
```

Si `next_cursor` no es `null`, pide la página siguiente con `?cursor=<next_cursor>` y los mismos filtros.

## Errores

Todos los errores tienen el mismo formato (`application/problem+json`):

```json
{"type": "about:blank", "title": "session_expired", "status": 401, "code": "session_expired", "request_id": "9a0b..."}
```

| Estado | `code` | Qué hacer |
|---|---|---|
| 401 | `invalid_credentials` | Correo o contraseña incorrectos |
| 401 | `session_expired` | Token vencido, revocado o inválido: renovar; si la renovación también da 401, volver al login |
| 403 | `portal_not_enabled` | La persona no tiene encendido «Puede entrar al Portal» |
| 403 | `password_change_required` | Llevar a la pantalla de cambio de contraseña |
| 403 | `permission_denied` | Su rol no permite esa acción (o `detail: portal_self_only` en escrituras para otros) |
| 404 | `resource_not_found` | No existe, está fuera de su alcance o la ruta no está en el Portal |
| 422 | `validation_failed` / `weak_password` | Datos inválidos |
| 429 | `rate_limited` | Demasiados intentos: esperar los segundos de `Retry-After` |

El token deja de servir también si la persona se desactiva, si le apagan el interruptor del Portal o si cambia su contraseña (desde el Portal o el panel).

## Límites

- Login: pocos intentos seguidos por dirección IP y correo; después responde `429` con `Retry-After` (bloqueo temporal de segundos a minutos). Mostrar «Demasiados intentos, espera un momento».
- Pedidos de datos: los mismos límites por cuenta que el panel (del orden de 120 por minuto con ráfagas de 30). Las cabeceras `RateLimit-Remaining` y `RateLimit-Reset` dicen cuánto queda.
- Cada respuesta trae `X-Request-ID`; inclúyelo al reportar un problema.

## CORS (desde el navegador)

El navegador solo deja que el Portal lea las respuestas si su dirección está autorizada en Keeper. La autoriza un superadministrador en el panel: **Plataforma → Portal: direcciones autorizadas** (por ejemplo `https://portal.azclegal.com` o la dirección de vista previa de Lovable).

- Se escribe la dirección exacta: `https://` + dominio (+ `:puerto` si aplica), sin ruta ni barra final y sin comodines (`*`). Cada dirección de vista previa se agrega aparte.
- Para pruebas locales se acepta `http://localhost` y `http://127.0.0.1` (con puerto).
- Keeper responde a la pregunta previa del navegador (`OPTIONS`) y permite los métodos `GET` y `POST` con las cabeceras `Authorization` y `Content-Type`.
- Una dirección no autorizada no recibe `Access-Control-Allow-Origin`, así que el navegador bloquea la respuesta (en la consola aparece un error de CORS). La solución es pedir que la agreguen, no cambiar el código.
- El panel (`/v1`) nunca responde CORS: el Portal solo puede usar `/portal/v1`.
