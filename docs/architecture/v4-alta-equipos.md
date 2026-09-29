# AZCKeeper v4 — Alta de equipos a escala (diseño, 2026-09-29)

Decisión de Koichi (2026-09-29): el alta de equipos debe funcionar para 1.000 equipos y para **cualquier empresa**,
tenga o no inventario/API propia. Keeper mantiene su **propio registro de equipos esperados**; el cruce automático se
hace siempre contra ese registro, nunca contra un sistema externo. Los sistemas externos solo son una forma más de
llenarlo.

## Piezas y orden

| # | Pieza | Estado |
|---|---|---|
| 1 | Registro de **equipos esperados** (persona + placa + serie) con alta manual y carga masiva CSV | hecho (`expected_devices`, panel `alta.php`) |
| 2 | **Alta genérica**: paquete igual para todos con clave de alta de la empresa; el equipo pide alta con su serie; si coincide con un esperado se aprueba solo; si no, queda **pendiente para IT** | hecho (`POST /client/enrollment-requests`, agente `IntakeEnrollment`) |
| 3 | Panel: equipos esperados, carga CSV, cola de solicitudes (aprobar eligiendo persona / rechazar), clave de alta | hecho (`public/alta.php` + `assets/intake.js`) |
| 4 | API de escritura para sistemas externos (`/ext/v1`) sobre equipos esperados | hecho (`POST /ext/v1/expected-devices:import`, scope `expected-devices:write`) |
| 5 | **Autoidentificación** opcional por empresa: Keeper.Session pide la cédula al primer usuario; queda como sugerencia o se confirma sola según la empresa | hecho (`ask_document` + Keeper.Session modo identificación) |
| 6 | Conectores que Keeper consulta (p. ej. Portal AZC) | opcional, después (requiere API de inventario en el portal) |

## Flujo

1. IT (o un sistema externo) registra equipos esperados: `documento` o `email` de la persona, `placa` (ACT_0015) y,
   si la tiene, `serie` del fabricante.
2. El paquete lleva `enrollment_key` de la empresa (no un ticket por equipo). Se instala igual en todos.
3. El agente llama `POST /client/enrollment-requests` con la clave, su clave pública, su serie (SMBIOS), hostname y,
   si la empresa lo activa, la cédula que escribió el usuario.
4. El backend:
   - serie de un esperado pendiente y la empresa auto-aprueba ⇒ **aprobada** (persona y placa del esperado);
   - cédula de una persona (autoidentificación) y la empresa confirma sola ⇒ **aprobada**; si no, **pendiente con sugerencia**;
   - nada coincide ⇒ **pendiente** para IT.
5. Mientras está pendiente, el agente reintenta cada pocos minutos (misma solicitud, idempotente por clave pública).
6. Aprobada ⇒ el backend emite un **ticket** de enrolamiento normal para esa persona y esa clave pública; el agente
   hace el login por ticket que ya existe. Tras enrolar: se guarda la placa en el equipo y se encola el renombre
   `ACT_0015 → ACT-0015` (comando `rename_computer`, pendiente de reinicio).

Un equipo siempre pertenece a una persona (`devices.user_id` es obligatorio); por eso lo no identificado es una
**solicitud de alta**, no un equipo.

## Seguridad (riesgos asumidos y mitigaciones)

- La serie no es secreta y la clave de alta va en el paquete: alguien con ambos podría hacerse pasar por un equipo
  esperado. Mitigaciones: cada esperado se **consume** con el primer equipo que coincide; un segundo equipo con la
  misma serie queda **pendiente con alerta**; la clave de alta se **rota** desde el panel; cada empresa decide si la
  coincidencia por serie **aprueba sola** o exige confirmación.
- La prueba de posesión se mantiene: el ticket queda atado a la huella de la clave pública de la solicitud y el login
  exige firma con la privada.
- Seriales de relleno (`To be filled by O.E.M.`…) nunca cruzan: el agente ya no los reporta.
- Límites: máximo de solicitudes pendientes por empresa y limitador por empresa (nunca por IP).
- Autoidentificación: cualquiera que sepa la cédula de otro podría reclamar un equipo; por eso por defecto queda como
  **sugerencia** que IT confirma.

## Identidad de la persona

Documento de identidad en `user_external_refs` con origen `document`. En AZC se acepta también `k3:cc` (importado de K3).

## Uso (operación)

1. Panel → **Alta de equipos** → *Clave de alta y reglas* → crear clave (pide la contraseña). Se muestra una vez.
2. Paquete genérico: `installation.json` con solo `api_base` y `enrollment_key` (sin `tenant_id`, `device_id` ni
   `enrollment_ticket`; el bootstrapper rechaza mezclarlos). La clave queda en el entorno del servicio
   (`KEEPER_ENROLLMENT_KEY`) y el log del bootstrapper la muestra como `[REDACTED]`.
3. Cargar los equipos esperados (manual, CSV `cedula;placa;serie`, o la API externa). Puede hacerse antes o
   después de instalar: el agente reintenta cada 5 min y cada reintento vuelve a cruzar.
4. El agente sin `device_id` solo pide alta (no aplica reglas ni captura) hasta que queda aprobado; al aprobarse
   hace login por ticket, guarda `device-id.txt` en su carpeta de datos y arranca normal.
5. Si la solicitud trae placa, al enrolar se guarda en `devices.asset_code` y se encola `rename_computer`
   (ACT_0015 → ACT-0015) sin reinicio inmediato; se aplica en el próximo reinicio. Agentes < 4.0.8: solo placa.

## Decisiones de implementación (2026-09-29)

- Aprobación manual por cédula/correo de la persona (mismo formato que el CSV), no por selector de usuarios.
- Un equipo con la misma serie que un equipo ya enrolado nunca se aprueba solo (`serial_already_enrolled`):
  cubre reinstalación y suplantación. Si IT aprueba la reinstalación para la misma persona, el ticket recupera el
  equipo existente (misma serie + misma persona) en vez de crear uno nuevo.
- La solicitud va firmada con la clave del equipo; limitadores por equipo y por empresa, nunca por IP.
- `ENROLLMENT_PENDING_MAX` (5000 por defecto) acota la cola; `ENROLLMENT_RETRY_SECONDS` (300) el reintento.
