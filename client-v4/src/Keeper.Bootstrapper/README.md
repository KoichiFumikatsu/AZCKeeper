# Bootstrapper del piloto v4

Consola .NET 8, Windows x64. Ofrece ejecucion directa desde el shell SYSTEM de DWService (primario) y auto-elevacion UAC para instalacion (fallback K3). Incluye instalacion, actualizacion en sitio, desinstalacion, ZIP self-contained y [endurecimiento Modo B](HARDENING.md) mediante `--harden`/`--unharden`. El endurecimiento espera comando explicito por defecto (`Panel`); `Auto` lo ejecuta despues de instalar. No modifica K3/K4.

**La instalacion real se ejecuta exclusivamente en el equipo desechable con snapshot de la Etapa 0 de `docs/PLAN-PRUEBAS-v4.md`. En FumiWork solo build, tests con fakes y `--dry-run`: no aceptar UAC, crear servicios ni escribir HKLM.**

## Paquete y configuracion

Desde `client-v4`, en PowerShell no elevado:

```powershell
$env:MSBUILDDISABLENODEREUSE = '1'
.\build-installer.ps1 -Version 4.0.0
```

Genera `artifacts/AZCKeeper_v4_bootstrap_4.0.0.zip`: `Keeper.Bootstrapper.exe`, `agent/Keeper.Agent.exe`, los archivos auxiliares de cada publish, `installation.json`, `install.cmd` y este README. Ambos EXE se publican `win-x64 --self-contained true -p:PublishSingleFile=true`. El runtime y los ensamblados administrados van en el bundle; no se presupone que `coreclr.dll` aparezca como archivo separado. `IncludeNativeLibrariesForSelfExtract=false` evita extraccion a temporales. Puede haber PDB para diagnostico. Distribuir el ZIP completo, no solamente el EXE. Referencia: [publicacion single-file de .NET](https://learn.microsoft.com/en-us/dotnet/core/deploying/single-file/overview).

El script verifica `includedFrameworks=Microsoft.NETCore.App` en runtimeconfig (sin dependencia `framework`), bundle mayor de 10 MB y presencia de `System.Private.CoreLib.dll`, `System.Runtime.dll` y runtimeconfig en el manifiesto embebido. Compila secuencialmente con `-maxcpucount:1 -nodeReuse:false`, deshabilita compiladores compartidos, cierra los build servers y elimina su staging en `finally`. No ejecuta el agente ni instala nada. El ZIP es un artefacto, no un temporal.

Extraer el ZIP. `installation.json` tiene datos de ejemplo no secretos y un dominio `.invalid`; permite probar `--dry-run`, pero rechaza una instalacion real hasta configurar el backend. Sustituir `api_base` por HTTPS terminado en `/v1/`, `tenant_id` y `device_id` por los UUID provisionados para el equipo piloto. `enable_hklm=true` habilita los enforcers cuando el servicio reciba politicas; usar `false` si se desea probar primero solo conectividad. Se permite el campo opcional `enrollment_ticket` en una copia local de aprovisionamiento; no agregar bearer, passwords ni claves privadas. El parser rechaza campos desconocidos.

## Enrolamiento por ticket

Desde una consola PowerShell **SYSTEM** en el equipo piloto con `device-key.dpapi` ya existente:

```powershell
& "$env:ProgramData\AZCKeeper\bin\Keeper.Agent.exe" --print-enrollment
```

Imprime JSON con `public_key_thumbprint`, `public_key` (JWK publico, incluidos `x`/`y`), `device_id` y `hostname`. El thumbprint es SHA-256 del JWK canonico en **base64url sin padding**, 43 caracteres que representan 32 bytes; es el formato que acepta `AdminApi` mediante `Util::unb64`, no hexadecimal ni base64 con `=`. No inicia el host, modulos ni red, no crea archivos/directorios y no genera una clave si falta. La clave DPAPI pertenece a SYSTEM. Lee `KEEPER_DATA_DIR` y `KEEPER_DEVICE_ID` del entorno de la consola o, si faltan, del bloque `Environment` del servicio `KeeperAgent`, en lectura. Para otra instalacion, definir esas dos variables en la consola antes de ejecutar el comando.

IT crea el enrolamiento con ese thumbprint y el `device_id` configurado en el agente. En la copia local de `installation.json`, agregar `"enrollment_ticket": "<ticket devuelto por el backend>"`. El bootstrapper escribe `KEEPER_ENROLLMENT_TICKET` en el `Environment` REG_MULTI_SZ del servicio, junto con las variables existentes; si se omite el campo, no escribe esa variable. Tambien se puede provisionar directamente `KEEPER_ENROLLMENT_TICKET` en ese bloque del servicio y reiniciarlo. Una variable del usuario interactivo no configura un servicio ya instalado.

El ticket es secreto, **de un solo uso y expira en 10 minutos**. **NO se debe versionar**, incluir en el ZIP distribuido ni conservar en ejemplos; retirarlo de la copia local tras usarlo. El log del bootstrapper y `--dry-run` muestran `[REDACTED]` en su lugar.

Sin token guardado y con ticket, el agente pide challenge por ticket y firma el login con `enrollment_ticket` y `public_key`. Guarda el token recibido en `device-token.dpapi` (DPAPI de SYSTEM, ligado a la API y clave), y deja de usar el ticket, incluso tras reiniciar o expirar el token. El valor del entorno del servicio no se borra automaticamente, pero el token persistido lo hace inactivo; retirarlo de la configuracion despues del enrolamiento. Sin ticket ni token, conserva el login por `device_id`. Un ticket rechazado produce `enrollment_rejected` sin exponer su valor y suspende nuevos intentos de enrolamiento durante ese proceso: corregir el ticket/configuracion y reiniciar el servicio. Errores transitorios y 429 conservan el backoff normal y `Retry-After`.

## Simulacion segura, incluso sin elevacion

Desde el paquete extraido:

```powershell
.\Keeper.Bootstrapper.exe --dry-run
.\Keeper.Bootstrapper.exe --uninstall --dry-run
```

Estos comandos del modo por defecto omiten incluso la comprobacion de elevacion. Solo leen configuracion, lista del payload y existencia del servicio (HKLM en lectura); no ejecutan `sc.exe`, no inician procesos elevados, no crean directorios ni escriben archivos/registro. El logger imprime las mismas operaciones y argumentos que ejecutaria la instalacion para el estado observado: cada copia, ACL, servicio y entorno. Si ya existe `KeeperAgent`, muestra stop/config en lugar de create; si no existe, uninstall omite stop/delete. El estado puede cambiar entre simulacion y ejecucion; el proceso elevado vuelve a validarlo. Una instalacion protegida existente puede exigir leer su contenido desde una consola ya administrativa para simularla; dry-run nunca eleva automaticamente.

En modo SYSTEM, `--system-install --dry-run` y `--system-uninstall --dry-run` comprueban primero la elevacion: con token alto/SYSTEM imprimen las operaciones sin mutar; sin elevacion terminan con **740**, mensaje de requisito y ningun prompt, antes de leer configuracion/payload. Este requisito tambien se aplica a la simulacion para detectar un shell remoto inadecuado.

Opciones: `--config RUTA_JSON`, `--payload DIRECTORIO`, `--system-install`, `--system-uninstall`, `--uninstall`, `--dry-run`, `--help`. `--system-install` no se puede combinar con `--uninstall` ni `--system-uninstall`. Las rutas por defecto son relativas al EXE, no al directorio de trabajo. Las rutas explicitamente relativas se conservan al elevar mediante el directorio de trabajo. `install.cmd` pasa los argumentos y propaga el codigo de salida.

`--config` y `--payload` requieren un valor no vacio que no comience con `-`; un valor ausente u otro flag devuelve error de argumentos (codigo 2) antes de instalar o solicitar elevacion. Para nombres que comiencen con `-`, usar una ruta absoluta o el prefijo `.\`.

## Instalacion real: solo Etapa 0

### Primario: shell SYSTEM de DWService, sin UAC

En el equipo desechable con snapshot, extraer el ZIP y configurar `installation.json`. Abrir el shell SYSTEM de DWService y situarse en la carpeta del paquete extraido. Comando exacto para pegar (CMD o PowerShell):

```powershell
.\Keeper.Bootstrapper.exe --system-install --config ".\installation.json" --payload ".\agent"
```

Para simular desde ese mismo shell:

```powershell
.\Keeper.Bootstrapper.exe --system-install --dry-run --config ".\installation.json" --payload ".\agent"
```

Es no interactivo: toda la entrada proviene de flags/config; no pide confirmaciones, credenciales ni input. Comprueba la integridad real mediante `GetTokenInformation(TokenIntegrityLevel)` y exige nivel alto o superior junto con identidad SYSTEM o rol administrador activo. La membresia de Administradores con token medio no basta. La comprobacion solo lee el token, sin pruebas de escritura en HKLM ni creacion de servicios. Ejecuta directamente en el proceso actual, sin `runas`, relanzamiento ni UAC. Sin elevacion devuelve **740** y `--system-install requiere ejecutarse ya elevado, p.ej. shell SYSTEM de DWService`. Tambien sirve desde una consola de administrador con token completo.

### Fallback K3: auto-elevacion UAC (modo por defecto)

En el equipo desechable, con snapshot y configuracion real, usar el modo existente cuando el lanzamiento parte de una sesion interactiva sin elevacion:

```powershell
.\install.cmd
```

Solicita un consentimiento UAC si el token actual no es administrativo. Espera al hijo elevado y devuelve su codigo. Cancelar devuelve **1223**, sin instalar; no reintenta ni entra en un bucle. Si la politica UAC exige credenciales, el bootstrapper no la cambia. Un hijo sin token administrativo devuelve 740. Configuracion/argumentos invalidos devuelven 2; fallos de operaciones devuelven 1.

Ambos modos ejecutan las mismas operaciones una vez elevada la ejecucion:

1. Detiene `KeeperAgent` si existe y espera `Stopped` antes de tocar sus binarios.
2. Crea `%ProgramData%\AZCKeeper`; propietario Administradores y DACL protegida con control total exclusivo para SYSTEM (`S-1-5-18`) y Administradores (`S-1-5-32-544`). Aplica la ACL una sola vez al arbol existente; crea `bin` y `v4` faltantes heredando esa ACL. Rechaza junctions/reparse points en payload/destino durante instalacion.
3. Copia el agente publicado a `bin`, sobrescribiendo binarios. Conserva la identidad DPAPI, outbox y cache existentes en `v4`. No escribe `config.json`.
4. Ejecuta `sc.exe create` o `sc.exe config` con nombre **KeeperAgent**, `type= own`, `start= auto`, `obj= LocalSystem` y ruta absoluta entre comillas a `bin\Keeper.Agent.exe`.
5. Escribe `HKLM64\SYSTEM\CurrentControlSet\Services\KeeperAgent\Environment` como REG_MULTI_SZ con `KEEPER_DATA_DIR`, `KEEPER_API_BASE`, `KEEPER_DEVICE_ID` y `KEEPER_ENABLE_HKLM`. Son variables del servicio, no del usuario ni del sistema entero.
6. Arranca el servicio y espera `Running` hasta 60 segundos. Running no acredita enrollment ni un sync correcto.

**Fuente de configuracion del agente:** `Program.cs` lee las variables `KEEPER_*` del bloque `Environment` del servicio. `installation.json` es solo la entrada del bootstrapper para configurar ese bloque; el agente no lo lee ni lee `config.json`. Un `config.json` residual de versiones anteriores no tiene efecto. Para cambiar estos valores, actualizar `installation.json` y reinstalar desde el paquete. Su nombre interno de WindowsService es `AZCKeeper v4`; el SCM se registra como `KeeperAgent`, de tipo proceso propio. Comprobar arranque real en Etapa 0. `tenant_id` solo se valida como dato de aprovisionamiento en la entrada; el agente obtiene el tenant efectivo del backend durante login/sync.

**Enrollment pendiente de aprovisionamiento:** este bootstrapper configura backend/device en el entorno del servicio, pero no consume tickets ni registra claves publicas. El agente genera su identidad DPAPI como SYSTEM y requiere esa clave autorizada en backend para hacer login. Usar el procedimiento IT de enrolamiento con prueba de posesion; no copiar la identidad DPAPI de otra maquina ni dar por enrolado un dispositivo solo por escribir un UUID. El paquete no incorpora el flujo de migracion `Keeper.Bridge` ni `Keeper.Session`, y no acredita captura/IPC, actualizacion automatica ni confianza de releases. Son integraciones separadas del instalador solicitado.

La actualizacion es repetible en sitio, no transaccional: si falla una copia o configuracion, termina con error y puede dejar el servicio detenido; corregir la causa y reejecutar desde el paquete. No borra datos para forzar una reinstalacion.

## Rollback real: solo equipo de prueba

Ejecutar desde el paquete conservado **fuera de ProgramData**. Desde el shell SYSTEM de DWService, sin UAC ni input:

```powershell
.\Keeper.Bootstrapper.exe --system-uninstall
```

Exige la misma elevacion previa, tambien con `--dry-run`; si falta, devuelve **740** sin relanzar. Para el fallback interactivo K3+UAC:

```powershell
.\install.cmd --uninstall
```

Detiene y elimina KeeperAgent, espera a que SCM lo elimine (incluido su `Environment`), limpia HKLM en vista de 64 bits y borra recursivamente `%ProgramData%\AZCKeeper`. Se puede repetir aunque el servicio o claves ya no existan. Ante fallo al detener/eliminar no borra archivos bajo un servicio activo. Cerrar `services.msc` si mantiene el servicio pendiente de eliminacion.

La desinstalacion elimina junctions/symlinks como enlaces, sin recorrer ni borrar sus destinos, incluso si el propio directorio de instalacion es un enlace o el destino ya no existe. Su presencia no bloquea la limpieza del resto del arbol. Se siguen rechazando enlaces en directorios ancestros para no operar fuera de la ubicacion prevista.

Limpieza exacta de los enforcers actuales:

- Chrome, Edge y Brave: subclaves `URLBlocklist`, `URLAllowlist`, valor `DownloadRestrictions`.
- `SOFTWARE\Policies\Microsoft\Windows\RemovableStorageDevices`: arbol completo.
- `SOFTWARE\Policies\Microsoft\Windows\Installer`: valores `DisableMSI`, `AlwaysInstallElevated`; conserva otros valores.

El rollback elimina estas politicas, no restaura valores anteriores de otro administrador. Por eso el plan exige snapshot/equipo desechable sin politicas compartidas que deban conservarse. Borrar ProgramData elimina identidad y datos locales v4; una futura instalacion requiere reprovisionar. No cambia cuentas, membresias, UAC ni configuracion de K3. Este flujo no debe usarse como rollback de la Etapa 5.

## Verificacion y limites de entrega

```powershell
$env:MSBUILDDISABLENODEREUSE = '1'
dotnet build Keeper.sln -maxcpucount:1 -nodeReuse:false
dotnet test Keeper.sln -maxcpucount:1 -nodeReuse:false
dotnet build-server shutdown
```

`build-server shutdown` es un comando CLI, no acepta switches de MSBuild. Las pruebas `Keeper.Bootstrapper.Tests` usan implementaciones FAKE para elevacion, servicio y registro. El filesystem se simula salvo las pruebas Windows de borrado, que usan directorios temporales aislados y junctions, con limpieza en `finally`; no instalan nada ni modifican ProgramData/HKLM. Cubren argumentos incompletos o seguidos de flags, ausencia de salida `config.json`, una sola aplicacion de ACL al reinstalar, desinstalacion con junctions (incluidos enlaces raiz y destinos ausentes), parametros SCM, idempotencia, rollback, dry-run frente a la ejecucion, UAC cancelado, propagacion de codigo y fallos que deben impedir continuar. Para ambos flags SYSTEM verifican equivalencia de operaciones con el modo elevado normal, rechazo del token medio sin mutaciones ni relanzamiento (tambien en dry-run) y simulacion elevada sin mutaciones, con y sin servicio existente.

Pendiente en Etapa 0: UAC real (aceptar/cancelar), ACL efectivas, servicio LocalSystem/auto, reinstalacion preservando identidad, enrollment y `/client/sync` por HTTPS, reinicio y desinstalacion/limpieza. Validar en Windows 11 Pro sin .NET preinstalado. Aqui nunca ejecutar el agente publicado: puede crear identidad/datos y habilitar operaciones de maquina.

El ZIP se transporta al equipo para ejecutarlo principalmente desde DWService SYSTEM. K3+UAC queda como fallback; `install.cmd` conserva su funcionamiento. El updater historico requiere su propio contrato de lanzamiento (`AZCKeeperUpdater.exe` y argumentos posicionales), no ejecuta automaticamente este `install.cmd`. La conexion de ese canal al bootstrapper y la convivencia con K3 deben validarse en Etapa 1; este trabajo no modifica K3 ni presenta esa integracion como probada. Paquete de piloto sin firma: la arquitectura exige establecer confianza/firma antes de produccion.
