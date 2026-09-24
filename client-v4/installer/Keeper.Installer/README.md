# Installer y puente K3

**Todavía no es una release desplegable.** Están implementados los pasos del cliente, el lanzador y las herramientas de firma/empaquetado. Faltan el canal autorizado de control de IT, la integración de baseline/Session y los artefactos firmados. No se han instalado certificados, modificado cuentas ni ejecutado MSI/logoff en esta máquina.

## Contrato real y bloqueo de integración

Las rutas de MigrationApi requieren **adminSession + CSRF**, no aceptan el `device_token`. Autorizar y recuperar requieren reautenticación de menos de cinco minutos. El OpenAPI prohíbe enviar la cookie administrativa al bootstrap. No se inventó un bearer alternativo ni se modificó el backend.

El paquete debe aportar `Integration.ps1`, aprobado por IT y cubierto por el hash/JWS del ZIP:

| Función | Responsabilidad |
|---|---|
| `Invoke-KeeperMigrationControl($Method, $Path, $Body)` | Intermediario autorizado que ejecuta la operación exacta del backend. Devuelve el JSON como objeto. `/escrow/recover` debe devolver `password` como `SecureString`, junto con SID, revisión y audit_id, sin formar cadenas administradas con el secreto ni escribirlo en archivos/logs. No entregar cookies administrativas al equipo. |
| `Invoke-KeeperMigrationBaseline($DeviceId, $WorkSids)` | Aplicar/verificar baseline y comunicación IPC real con Session. Devolver `applied=true`, `session_ready=true`, `application_control_mode='audit'` solo tras comprobarlos. Existencia del proceso o confirmación manual no equivalen a esa prueba. |

Ese transporte no existe en los archivos revisados; no se incluye un adaptador ficticio. El empaquetador rechaza su ausencia. El intermediario debe recoger también las excepciones locales del lanzador anteriores a la instalación. Un archivo local no equivale a un ACK del panel. La reautenticación posterior al nuevo logon sigue siendo requisito para recuperar la credencial; sin ella se detiene la migración.

El adaptador .NET puede usar `ProtectedEscrowRecovery.ParseAndClear`: interpreta el password directamente desde bytes hacia SecureString y borra el buffer HTTP incluso cuando falla el parsing. No usar `ReadAsStringAsync`/`ConvertFrom-Json` sobre la respuesta sensible de recuperación.

`Migration.Enrollment.ps1` sí implementa autorización/validación/enrollment. Los modos `Keeper.Bridge --enrollment-key` y `--enroll` exigen SYSTEM, reciben configuración/ticket por stdin y reutilizan `DeviceKeyStore`, `HttpMessageSigner` y RFC 9421. Comprueban tenant/device, configuran y reinician el servicio con identidad propia. Nunca imprimen el bearer. Un login con la clave propia permite reanudar tras perder el ACK sin reenrolar la misma clave.

## Bootstrap y reanudación

Fases: `Plan`, `Install`, `ProvisionRecovery`, `Demote`, `Logoff`, `Verify`, `Report`, `All`. `Plan` y `-WhatIf` no modifican SO, journal ni red. Cuentas/tokens requieren SYSTEM. Los antiguos switches de confirmación no permiten degradar; las contraseñas externas se rechazan.

| Pasos | Implementación |
|---|---|
| 3–4 | MSI firmado/editor anclado; ProgramData y SCM protegidos por SID; servicio activo; enrollment con prueba de posesión propia. |
| 5–7 | Contraseña criptográfica de 32 caracteres en SecureString; no sobrescribe cuentas ajenas. Grupo Administradores por SID y prueba `LogonUserW(LOGON32_LOGON_NETWORK)` sin sesión interactiva. Se borran buffers temporales. |
| 8 | `crypto_box_seal` de libsodium liga tenant/device/SID/revisión/contraseña. Solo se persiste el sobre cifrado. ACK de persistencia, SHA-256 y revisión; verificación del servidor; recuperación auditada y prueba del secreto recuperado con LogonUser. |
| 9 | Gate de baseline/Session; el enforcer existente no implementa por sí solo AppLocker/WDAC completo. |
| 10 | RID 500 habilitado bloquea; deshabilitado se permite. Otros administradores no declarados bloquean. Prueba de contraseña vacía una vez por SID/PasswordLastSet, sin acumular intentos al reanudar. Restricción de NETWORK no prueba que la contraseña sea no vacía; resultado administrativo indeterminado bloquea. |
| 11 | Revalida recuperación/baseline/servicio. Guarda membresías previas antes de retirar Administradores y garantiza Users, siempre por SID. No restaura permisos automáticamente ante fallo. |
| 12 | Reporta `pendiente_reinicio`, avisa (120 s, mínimo 30 s) y ejecuta WTSLogoffSession. Comprueba AuthenticationId para no cerrar una nueva sesión que reutilice el ID WTS. El empleado debe guardar su trabajo durante el aviso. |
| 13 | WTS/WindowsIdentity/CheckTokenMembership sobre tokens reales. También rechaza el SID Administradores deny-only de UAC. Espera un token estándar de cada SID declarado, incluidas sesiones desconectadas; ausencia de sesión no es éxito. |
| 14–15 | Crea UserList si falta, DWORD azcadmin=0; es **cosmético, no seguridad**. PUT en cada transición con revisión CAS y ACK coincidente. |

Fases API: `instalado -> enrolado -> escrow_ok -> degradado -> pendiente_reinicio -> completo`, más `excepcion`. Journal schema 2 con lease exclusivo, flush y reemplazo atómico. Persiste transiciones antes de enviarlas; reintenta el mismo contenido/revisión en orden. `Report` vacía la cola sin modificar cuentas. Un fallo queda registrado localmente aunque no haya red y requiere asistencia.

No se transforma un journal antiguo/corrupto suponiendo verificaciones inexistentes. Un corte después de crear azcadmin y antes de guardar su SID/sobre exige recuperación asistida; no se rota ni recrea. Solo se conserva el sobre cifrado, nunca una segunda copia local de la contraseña.

## Lanzador y canal K3

`Keeper.Bridge --launch release.json bridge.zip` reutiliza ReleaseVerifier/InstalledTrust: ES256, hash/tamaño, canal, arquitectura, secuencia monótona y pin SHA-256/editor. Los anclajes se compilan en el ejecutable. La build sin provisión falla cerrada. Revalida después de UAC y bajo lease; el recibo protegido permite reintentar únicamente idéntica secuencia/hash.

Acepta además `AZCKeeperUpdater.exe <targetDir> <extractPath> <oldExe>`, el protocolo actual de K3. No copia archivos sobre K3. Registra el intento antes de `ShellExecute(runas)`; cancelar deja `excepcion` local y no repite UAC. Reinicia K3 y desactiva su auto-update local guardando el valor previo en `legacy-update-setting.json`, evitando el bucle de descarga/reinicio de la misma release. IT debe excluir esos equipos de nuevas ofertas y evitar que una política remota reactive el updater mientras atiende la excepción.

La reserva de intento es por equipo (`ProgramData/AZCKeeper/migration-attempt.json`) y también se registra en LocalAppData. Se endurece su propietario/DACL al concederse elevación. Si no puede persistirse la reserva, no se solicita UAC.

Extrae en staging protegido; rechaza escapes, ADS, duplicados, enlaces y tamaños excesivos. Propietario y DACL de archivos/registro/tarea quedan en Administradores/SYSTEM, para que el usuario degradado no conserve WRITE_DAC como propietario. `Entry.ps1` registra una tarea SYSTEM al arranque/logon y la inicia, sin otro UAC. La elimina tras el ACK de completo.

**La primera ejecución sigue siendo un límite de confianza:** K3 ejecuta el updater descargado antes de verificarlo. Autoverificar el puente después de arrancar no corrige ese fallo. Para verificar antes de ejecutar cualquier contenido descargado hace falta provisionar/corregir el receptor K3 o validar el primer lanzador por un canal independiente. `BridgePackageVerifier` permite esa integración, pero el K3 original no se modificó por la restricción de escritura. No se afirma que la entrega inicial esté asegurada.

## Certificado autofirmado sin coste

Generar en la estación de publicación:

```powershell
.\installer\Keeper.Installer\Sign-AzcRelease.ps1 -CreateCertificate -PublicOutputDirectory .\artifacts\public
```

Genera RSA code-signing `CN=Grupo AZC`, clave privada no exportable en CurrentUser/My. Solo exporta `publisher.cer` y metadatos públicos. Custodiar la estación; ninguna clave privada pertenece al paquete. El script no se ejecutó en esta entrega.

Proveer `bridge-trust.json` con las claves ES256 públicas ya confiables y el pin generado:

```json
{
  "Trust": {
    "ReleasePublicKeys": { "release-key-id": "BASE64_SPKI_P256" },
    "BinaryHashes": {}, "InstalledSequence": 1, "Channel": "stable"
  },
  "Publisher": { "CertificateSha256": "SHA256_DEL_CERTIFICADO", "Subject": "CN=Grupo AZC" }
}
```

```powershell
dotnet publish installer/Keeper.Bridge/Keeper.Bridge.csproj -c Release -r win-x64 --self-contained true -p:PublishSingleFile=true -p:IncludeNativeLibrariesForSelfExtract=true -p:BridgeTrustFile=C:\ruta\bridge-trust.json -o .\artifacts\bridge -maxcpucount:1 -nodeReuse:false
.\installer\Keeper.Installer\Sign-AzcRelease.ps1 -CertificateThumbprint HUELLA_SHA1_DEL_STORE -MsiPath .\artifacts\Keeper.msi -LauncherPath .\artifacts\bridge\Keeper.Bridge.exe
dotnet build-server shutdown
```

Requiere SignTool del Windows SDK. Firma SHA-256 y timestamp RFC 3161; `/sha1` selecciona el certificado del store, no el algoritmo. `-ScriptPaths` permite firmar también los scripts. Construir antes el MSI WiX 5+ de Package.wxs con publicaciones win-x64 de Agent/Session y installation-trust.json; firmar binarios antes del MSI. No se generó MSI en esta entrega.

**En la PRIMERA elevación UAC mostrará «editor desconocido»**, porque la raíz aún no está instalada. Durante la elevación concedida el bootstrap verifica la huella anclada e instala el certificado en LocalMachine/Root y LocalMachine/TrustedPublisher. Las siguientes firmas válidas podrán mostrar el editor esperado; no garantiza reputación SmartScreen ni elimina políticas corporativas.

Comunicación previa, anillos con piloto de 5–10 equipos y comparación de huellas/hash por canal independiente reducen errores de aceptación. No autentican por sí solos una descarga alterada, no hacen conocido al editor del primer UAC y no reparan el updater original. Esto documenta la limitación frente a la expectativa de editor conocido de §3 de la especificación.

## Publicación

`bridge.zip`, cubierto por el manifiesto Release/JWS actual, incluye en su raíz: Keeper.msi, Keeper.Bridge.exe single-file firmado, Bootstrap.ps1, Migration.Journal.ps1, Migration.Enrollment.ps1, Migration.Native.cs, Entry.ps1, Integration.ps1, libsodium.dll de la arquitectura correcta, publisher.cer y deployment.json. La DLL nativa queda ligada al hash del ZIP y se carga por ruta absoluta; no se incluyó una DLL sin verificar su procedencia.

deployment.json contiene únicamente tenant_id, device_id, api_base (HTTPS terminado en /v1/) y work_user_sids locales explícitos. Sin cookies, tickets, bearer, contraseñas ni claves privadas. Requiere una oferta por equipo/anillo desde IT; el canal K3 no crea por sí solo la autorización.

```powershell
.\installer\Keeper.Installer\New-K3BridgePackage.ps1 -LauncherPath .\artifacts\bridge\Keeper.Bridge.exe -PayloadZip .\artifacts\bridge.zip -ReleaseManifest .\artifacts\release.json -OutputZip .\artifacts\K3-bridge.zip
```

El ZIP exterior contiene AZCKeeperUpdater.exe, bridge.zip y release.json, con SHA-256 adicional para el canal independiente. Exige los archivos necesarios y el mínimo de 1 MB de K3. No publica ni modifica ofertas del servidor.

## Validación

Build/test con `-maxcpucount:1 -nodeReuse:false`, seguidos de `dotnet build-server shutdown`. Pruebas de enrollment sin credenciales administrativas, firma/editor/antirrollback, rutas ZIP, parser PowerShell, journal/CAS/reanudación, excepción, auditoría y Plan/WhatIf sin mutaciones.

Pendiente de VM Windows 11 Pro: logon/escrow real, UAC/certificados/MSI, permisos efectivos, tarea SYSTEM, consola/RDP, aviso/logoff, cortes en cada efecto, baseline audit y token tras nuevo logon. Las pruebas unitarias no sustituyen esos ensayos.

Referencias: [LogonUser](https://learn.microsoft.com/en-us/windows/win32/api/winbase/nf-winbase-logonuserw), [WTSQueryUserToken exige SYSTEM](https://learn.microsoft.com/en-us/windows/win32/api/wtsapi32/nf-wtsapi32-wtsqueryusertoken), [SignTool](https://learn.microsoft.com/en-us/windows/win32/seccrypto/signtool).
