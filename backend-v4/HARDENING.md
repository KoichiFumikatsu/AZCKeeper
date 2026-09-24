# Modo B: backend de endurecimiento

Migración: `migrations/0020_hardening.sql`. Coexiste con
`0011_activity_rollups.sql`: el migrador identifica archivos por nombre completo.
Es reproducible desde cero y al repetir su SQL conserva configuración y estados.

- `tenant_hardening_settings`: configuración por tenant, contraseña cifrada,
  principal que actualizó y fecha UTC. Sin configuración, GET admin devuelve
  `azcadmin`, `panel`, `deny_network_logon=true` y contraseña null.
- `device_hardening_status`: una fila por tenant/dispositivo, FK compuesta,
  estado observado, paso, diagnóstico sanitizado y fechas del servidor.
- `device_command.type`: incorpora `harden` y `unharden`.
- Permiso `hardening.gestionar`, agregado a las plantillas y roles semilla IT y
  Super admin. La configuración y el revelado exigen alcance tenant. Los
  dispositivos respetan el alcance de usuarios del rol, también en replays.

Las rutas del OpenAPI usan la base existente `/v1`:

| Método | Ruta | Resultado |
|---|---|---|
| GET | `/client/hardening` | Configuración y clave descifrada del tenant del token |
| POST | `/client/hardening/report` | Guarda `state`, `last_step`, `detail` del dispositivo autenticado |
| GET | `/admin/tenants/{id}/hardening` | Configuración con máscara fija `********` |
| PUT | `/admin/tenants/{id}/hardening` | Guarda configuración; audita nombres de campos |
| POST | `/admin/tenants/{id}/hardening/reveal` | Revela y audita cada acceso |
| GET | `/admin/devices/{id}/hardening` | Estado observado; `none` si aún no reportó |
| POST | `/admin/devices/{id}/hardening/command` | Encola `{action: harden\|unharden}`; devuelve 202 |

PUT recibe `admin_name`, `hardening_mode` (`auto` o `panel`),
`deny_network_logon` y, al configurar inicialmente, `shared_password`.
Omitir la clave en actualizaciones la conserva. Se aceptan 12–128 caracteres sin
controles; una máscara no es una clave válida. Nunca se devuelve el ciphertext.

El cifrado usa AES-256-GCM con nonce aleatorio de 12 bytes y etiqueta de 16 bytes.
La clave de 32 bytes se deriva mediante HKDF-SHA256 de `Config::key()` con contexto
`keeper:hardening:v1`. El tenant forma parte de los datos autenticados, de modo que
copiar un ciphertext a otro tenant falla. El BLOB contiene versión, nonce,
etiqueta y ciphertext. Se utiliza el `KEEPER_RESPONSE_KEY` existente, generado por
`config/keygen.php`: debe conservarse y respaldarse fuera de la base de datos.
Cambiar ese secreto sin recifrar los datos hace ilegibles las claves guardadas.

Solo GET del agente y POST reveal descifran. Ambos devuelven `Cache-Control:
no-store`. El agente exige TLS, bearer y firma; el modo HTTP local existe solo
para pruebas. Admin usa sesión; las escrituras y reveal verifican CSRF y Origin.
Reveal no usa caché de idempotencia: la auditoría y la respuesta pertenecen a la
misma transacción. Auditoría guarda acción y nombres de campos, nunca valores.

Report y command requieren `Idempotency-Key`, como las escrituras existentes del
agente y la cola. Los comandos tienen secuencia creciente y TTL de 24 horas. No
incluyen la clave ni cambian el estado observado: el agente los recibe en
`/client/commands` o `/client/sync`. `harden` exige clave configurada y rechaza
`recovery_required`; `unharden` permite recuperación. Ambos requieren equipo activo.

El agente debe enviar diagnósticos sin secretos y traducir `running` a `pending`,
`unhardened` a `none` y errores que requieren recuperación a `recovery_required`.
`hardened_at` se conserva al repetir `hardened` y se limpia al salir de ese estado.
La validación del administrador, la degradación del usuario activo, RID-500 y el
rollback son responsabilidad del ejecutor local. `deny_network_logon` representa
`SeDenyNetworkLogonRight` para `S-1-5-114`.

El código local de Bootstrapper consultado aún recibe la clave mediante archivo
y no contiene un consumidor HTTP de estas rutas; este cambio solo entrega el
backend y su contrato. El brief local tampoco contiene la sección MODO B fechada
2026-09-23; se implementaron las decisiones indicadas en la solicitud.

Validación: `tests/mysql-smoke.ps1 -DebugResponses` ejecuta el smoke completo y
`tests/hardening.php` sobre MySQL temporal. El harness detiene MySQL/PHP y elimina
su datadir en `finally`. Las pruebas inspeccionan auditoría y logs HTTP sin
imprimir credenciales.

Resultado 2026-09-23: MySQL 8.4.3, `-DebugResponses`, smoke completo verde
(1223 aserciones, 162 de endurecimiento), 58 verificaciones de esquema, 6 de
transporte y `php -l` correcto en 83 archivos. El harness confirmó el cierre de
los procesos y la eliminación de su datadir.
