# Endurecimiento Modo B — 2026-09-23

Implementación limitada a `client-v4/`. **En FumiWork solo build, tests con fakes y dry-run.** Los adaptadores Windows de cuentas, LSA, DPAPI y registro no se han ejecutado en esta máquina. Homologación nativa pendiente en VM desechable con snapshot.

Precedencia: la instrucción de Koichi del 2026-09-23 establece **contraseña compartida** protegida. El checkout de `docs/architecture/v4-requisitos-brief.md` no contiene la sección Modo B mencionada; `v4-client-agent.md` §7 todavía prescribe contraseña única por equipo y escrow. Este módulo sigue la instrucción actual; no implementa LAPS, escrow ni cambios en K3/K4.

## Uso y configuración

Simulación segura desde la raíz del repositorio, sin instalación ni payload:

```powershell
dotnet client-v4/src/Keeper.Bootstrapper/bin/Debug/net8.0/Keeper.Bootstrapper.dll --harden --dry-run
dotnet client-v4/src/Keeper.Bootstrapper/bin/Debug/net8.0/Keeper.Bootstrapper.dll --unharden --dry-run
```

El dry-run solo imprime el plan: no comprueba elevación, no abre journal/DPAPI/SAM/LSA/registro, no adquiere locks ni escribe archivos. Con `--hardening-config` únicamente lee ese JSON de parámetros no secretos. No simula una atestación de estado real.

En el equipo piloto ya instalado, `--harden --hardening-config RUTA` y `--unharden` son comandos explícitos que **requieren un token ya elevado**. Si falta, devuelven 740 sin pedir UAC. El journal exige los directorios de instalación y `v4` existentes con propietario/ACL privados antes de crear su subdirectorio. Son excluyentes con los modos de instalación/desinstalación. La clave nunca se admite como flag, variable de entorno ni propiedad JSON.

Ejemplo de `hardening.json` (sin secreto):

```json
{
  "hardening_mode": "Panel",
  "admin_name": "azcadmin",
  "password_file": "C:\\ProgramData\\AZCKeeper\\v4\\hardening\\password.dpapi",
  "no_session_target": "EnrolledAccounts",
  "enrolled_account_sids": [],
  "last_console_user_sid": null
}
```

Para instalar en modo Auto, agregar **este objeto bajo `hardening`** dentro de `installation.json`, con `hardening_mode: "Auto"`. No sustituye los campos de instalación existentes. Tras confirmar servicio Running, el bootstrapper invoca el coordinador. Running no acredita enrollment ni salud del backend: los prerrequisitos de migración continúan siendo responsabilidad del despliegue piloto.

`HardeningMode` admite `Auto|Panel` (serializado `hardening_mode`). Ausente equivale a Panel. En Panel no se accede a cuentas/políticas ni se lee la contraseña; se guarda estado de espera. Un fallo de Auto persiste Panel y no vuelve a intentarlo al reinstalar. `--harden` es la orden explícita que permite reintentar después de reparar la causa. Si hay una operación incompleta, primero exige `--unharden`.

Destinos (desde 4.0.10): además de las sesiones, **todas las administradoras locales** salvo la cuenta gestionada y la integrada RID-500 (`demote_all_local_admins`, true por defecto; false conserva el comportamiento anterior). Otra cuenta administradora con clave conocida anularía el endurecimiento. Todas quedan en `restore_admin_sids` y `--unharden` las restaura.

Desde el panel (comando `harden`/`unharden`, agente >= 4.0.10): el agente lanza este bootstrapper verificado contra el trust con `--hardening-config` (solo `admin_name`), lee el journal y, si hubo degradaciones, avisa en la sesión y la cierra a los 2 minutos: el token de la sesión abierta conserva Administradores hasta un nuevo inicio de sesión. `--harden`/`--unharden` escriben `logs/bootstrapper-*.log`.

Destinos: sesiones WTS **activas** (consola/RDP), deduplicadas por SID; solo cuentas de la SAM local. Si no existen, `EnrolledAccounts` usa la lista de SIDs configurada, o `LastConsoleUser` usa `last_console_user_sid` proporcionado por el enrolador/IT. No se adivina el último usuario a partir del nombre de quien ejecuta SYSTEM. Destinos vacíos, de dominio, desconocidos o protegidos abortan antes de degradar. Se asegura Usuarios también a destinos ya estándar.

## Contraseña sin exposición

El archivo binario DPAPI se genera **en el equipo destino**, scope `LocalMachine`, sin entropía adicional y plaintext UTF-8 sin BOM ni salto de línea añadido. Su contenido es la misma contraseña compartida elegida por IT; el blob cifrado es específico del equipo. El bootstrapper solo consume el archivo.

`Keeper.Agent` aprovisiona `hardening/password.dpapi` bajo su directorio de datos (por defecto `C:\ProgramData\AZCKeeper\v4`) después de cada sync aceptado: consulta `GET /client/hardening` con el bearer vigente y firma RFC 9421 del mismo `SyncClient`. Un 409 significa que el tenant no tiene clave configurada y no toca el filesystem. Con una clave, valida directorios privados y ausencia de reparse points; compara el plaintext descifrado y conserva el blob si no cambió. Para crear o rotar usa un temporal con ACL exacta `O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)` desde su creación, `Flush(true)` y move con reemplazo. Los buffers del JSON, de la clave UTF-8 y de la comparación se limpian en `finally`; la clave no se convierte a `string`. Los errores de transporte siguen el backoff del sync.

La instalación no espera ese primer sync: antes de ejecutar hardening, IT debe comprobar que el archivo ya está aprovisionado, o entregarlo manualmente en el formato indicado. Un `password_file` personalizado debe aprovisionarse por separado.

El lector valida ausencia de reparse points, propietario SYSTEM/Administradores y DACL que solo permite esos dos SIDs. Una DACL nula o permisos para otros principales abortan. ACL recomendada: `O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)`. DPAPI LocalMachine por sí solo **no** restringe qué usuario del equipo puede descifrar; por eso se exige además ACL privada. El directorio debe estar protegido por IT. No guardar texto claro en `installation.json`.

Para integración en memoria se inyecta `IHardeningSecret.Read(): SecureString` al coordinador. Los tests usan este punto con una contraseña ficticia. No existe inyección por argumentos de proceso.

La creación usa **`NetUserAdd` nivel 1**; una cuenta cuyo SID consta como propia en el journal usa **`NetUserSetInfo` nivel 1003**. Ambos reciben un puntero UTF-16 de `SecureString`; se limpia con `Marshal.ZeroFreeGlobalAllocUnicode` en `finally`. Los buffers administrados del descifrado también se limpian. La verificación usa `LogonUserW`, tipo **INTERACTIVE (2)**, proveedor DEFAULT, dominio máquina local, y comprueba el SID del token; cierra el handle sin iniciar procesos ni cargar perfil. No usa NETWORK (3), que sería incompatible con el paso 3. No hay `net user`, shell, argumentos ni mensajes con la clave; las excepciones de adaptadores no se imprimen. Windows puede auditar creación/cambio/logon de cuentas, sin incluir la contraseña proporcionada a estas APIs.

Si el nombre ya existe sin SID propio en el journal, aborta sin cambiarle contraseña. Un corte entre creación y guardado del SID deja una colisión que requiere revisión de IT; no toma propiedad por nombre. No se cambia, habilita, elimina ni renombra el Administrator integrado.

## Secuencia y compensación

| Paso | Acción y guarda |
|---|---|
| 1 | Preflight: RID-500 debe existir, seguir en Administradores y estar deshabilitado. Crear admin configurado o actualizar contraseña de SID propio; agregar a `S-1-5-32-544`. Resolver el nombre localizado del grupo **desde el SID**. |
| 2 | Confirmar habilitación, membresía y logon interactivo del SID esperado. Si falla, no se degrada. |
| 3 | Capturar estado previo y agregar `SeDenyNetworkLogonRight` a `S-1-5-114`; releer y verificar. |
| 4 | Resolver destinos; prohibir admin gestionado, nombre `azcadmin` y RID-500 aunque esté renombrado. Guardar membresías previas antes de la primera modificación. Revalidar admin antes de cada retiro; asegurar Usuarios (`S-1-5-32-545`) y retirar Administradores. Releer resultado. |
| 5 | Revalidar credencial/admin operativo y RID-500 deshabilitado. No contar al integrado deshabilitado como admin utilizable. |
| 6 | Capturar valor previo; escribir DWORD 0 bajo `HKLM64\SOFTWARE\Microsoft\Windows NT\CurrentVersion\Winlogon\SpecialAccounts\UserList\<admin_name>`; releer. |
| 7 | Persistir `hardened`, modo y necesidad de nuevo logon; Agent lo reporta mediante outbox. |

Journal protegido y sin secretos: `%ProgramData%\AZCKeeper\v4\hardening\state.json`. Escritura a temporal, `Flush(true)` y reemplazo atómico; limpieza en `finally`. Lock de archivo exclusivo contra dos coordinadores; handle y archivo se liberan al terminar. Antes de mutar se persiste modo Panel, de modo que un proceso interrumpido no vuelva a degradar automáticamente. El éxito de Auto conserva Auto; el fallo conserva Panel. Un journal ilegible falla cerrado.

Si falla cualquier paso, se restaura la membresía Administradores de los destinos registrados, se deshacen las altas en Usuarios hechas por esta operación y se restauran el derecho/ocultamiento anteriores. Por el orden obligatorio, un fallo de los pasos 5–7 puede ocurrir **después** de un retiro: se compensa inmediatamente; no se afirma que Windows proporcione una transacción atómica de SAM/LSA/registro. Si también falla la compensación, se conserva el journal, el admin de recuperación y `recovery_required=true`; no se anuncia rollback exitoso. Si falla una promoción, no se quita aún la protección de red. Corte eléctrico/terminación requiere `--unharden` explícito; no se repromueve por pérdida de conectividad.

Las guardas impiden retirar al único admin utilizable dentro de esta operación. No pueden impedir cambios simultáneos hechos por otro administrador externo. No se cierra ninguna sesión ni se fuerza reinicio: los tokens existentes conservan sus privilegios; se informa `logoff_required=true`. El estado `hardened` confirma cambios de cuentas/política, no revocación de tokens ya emitidos. La comprobación del token estándar tras nuevo logon queda para la homologación/flujo de sesiones.

## Mecanismo S-1-5-114

`WindowsSecurityPolicy` abre la política LSA local con `POLICY_VIEW_LOCAL_INFORMATION | POLICY_CREATE_ACCOUNT | POLICY_LOOKUP_NAMES`. Usa `LsaEnumerateAccountRights`, `LsaAddAccountRights` y `LsaRemoveAccountRights` para el SID **Local account and member of Administrators group (`S-1-5-114`)** y solo el derecho **`SeDenyNetworkLogonRight`**. No ejecuta `secedit`, no reemplaza otras listas de derechos y no depende del idioma del SO.

El derecho deniega logon de red de cuentas administrativas locales; no deshabilita su logon interactivo y no equivale a denegar RDP. Rollback elimina únicamente esta asignación si la agregó Keeper; si ya existía se conserva. Referencias: [derechos LSA](https://learn.microsoft.com/en-us/windows/win32/secauthz/account-rights-constants), [LsaRemoveAccountRights](https://learn.microsoft.com/en-us/windows/win32/api/ntsecapi/nf-ntsecapi-lsaremoveaccountrights), [LogonUserW](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-logonuserw), [NetUserSetInfo](https://learn.microsoft.com/en-us/windows/win32/netmgmt/changing-elements-of-user-information).

## Unharden y reporte

`--unharden` no requiere leer la contraseña ni volver a resolver sesiones: usa los SIDs del journal. Primero repromueve y verifica; luego restaura Usuarios, asignación LSA y valor/ausencia previa de UserList. Es repetible y deja modo Panel. **azcadmin permanece habilitado y administrador**, con la contraseña compartida; no existe borrado implícito. Quitar esa cuenta requeriría una acción separada expresamente solicitada. RID-500 permanece deshabilitado. Conservar journal y bootstrapper hasta completar la recuperación: `--uninstall` elimina los datos y **no sustituye a `--unharden`**.

Salida del bootstrapper: estado JSON sin secretos, con `status`, `step`, `error_code=failed_step_N`, `mode`, `recovery_required` y `logoff_required`. Exit codes: 0 éxito/espera/dry-run, 1 fallo, 2 argumentos inválidos, 740 token insuficiente. Un fallo de persistencia puede impedir escribir el reporte final; se conserva el último journal duradero.

`HardeningStatusModule` lee el journal, publica un LogEntry con estado/paso/modo por revisión y expone el control `LocalAccountHardening` a `AgentDiagnostics` → `SecurityReport` → outbox/sync existentes. Fallos se proyectan como Failed (`failed_step_N_panel`/`recovery_required`); `pending_logoff` y espera/rollback como Unknown, sin afirmar token estándar. El contrato de comandos actual **no define `harden` ni `unharden`**: este cambio ofrece el comando local elevado para la ejecución explícita de IT y el reporte mediante el protocolo existente. No añade un botón, endpoint ni orden remota sin contrato.

## Verificación segura

```powershell
$env:MSBUILDDISABLENODEREUSE = '1'
dotnet build client-v4/Keeper.sln -maxcpucount:1 -nodeReuse:false
# Desde client-v4:
dotnet test -maxcpucount:1 -nodeReuse:false
dotnet build-server shutdown
```

Tests nuevos exclusivamente con fakes: orden, credencial inválida, mínimo de admins, fallos antes/después del retiro, Auto→Panel persistente, Panel sin comando, cero acceso en dry-run, rollback/reintento/interrupción, política previa, cuentas protegidas, fallback sin sesión, integración postinstalación y reporte. Una prueba inspecciona IL/PInvoke del adaptador para comprobar `NetUserAdd`/`NetUserSetInfo`, contraseña por puntero, limpieza y ausencia de llamadas a procesos; no invoca las APIs.
