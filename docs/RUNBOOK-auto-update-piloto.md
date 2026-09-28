# Runbook — prueba real del auto-update 4.0.0 → 4.0.1 (piloto, DESKTOP-949SGVE)

Preparado el 2026-09-28. Todo lo local está hecho y verificado (commits `5ab0ca8`, `e71de31`, `69e1119` y el throttle de hardening).
Lo que sigue requiere el servidor devkeep (`ssh azc-root`) y el equipo de prueba (shell SYSTEM de DWService).

## Artefactos listos (en `client-v4/artifacts/`, fuera de git)

| Archivo | Uso | SHA-256 |
|---|---|---|
| `AZCKeeper_v4_bootstrap_4.0.0.zip` | Reinstalar el equipo con trust (sequence 1) | `7e3aadbfb685aef31a241ff9fa39b23dc5d35079cda6d9cc493d1385473f69f6` |
| `AZCKeeper_v4_bootstrap_4.0.1.zip` | Paquete del update (sequence 2), 125.792.159 bytes | `db21e5bb26c3598aa4aa6c6239656ee99e051939ac20c5423f383f4605a60676` |
| `release-4.0.1.json` | Body de `POST /v1/releases` (manifest JWS firmado) | id `a3165c99-173b-4da4-8cf6-dae548dc1cce` |

Clave de release: `C:\Users\FumiWork\Documents\AZCKeeper-release-keys\` (privada DPAPI del usuario FumiWork en
ESTA máquina; key_id `MwoB43FowqNILNi2mdH0Cg8cOxRPMdGqTevRDPbatUg`). **Respaldarla** por un canal seguro: si se
pierde, los equipos instalados con este trust no aceptan más updates sin reinstalar.

La URL firmada en el manifest es `https://devkeep.azclegal.com/releases/AZCKeeper_v4_bootstrap_4.0.1.zip`.
Si el ZIP termina en otra URL, NO hay que recompilar: basta re-firmar (ver al final).

## 1. Backend devkeep

Docroot: `/home/keeper/web/devkeep.azclegal.com/public_html/`.

1. Revisar el `config/release-keys.json` del servidor. Si no es `{}`, fusionar a mano en vez de sobrescribir.
2. Subir los 3 archivos cambiados:
   ```bash
   DR=/home/keeper/web/devkeep.azclegal.com/public_html
   scp backend-v4/src/Resources.php backend-v4/src/Application.php azc-root:$DR/src/
   scp backend-v4/config/release-keys.json azc-root:$DR/config/
   ssh azc-root "chown keeper:keeper $DR/src/Resources.php $DR/src/Application.php $DR/config/release-keys.json"
   ```
3. Subir el ZIP y comprobar que se sirve con el tamaño exacto:
   ```bash
   ssh azc-root "mkdir -p $DR/public/releases"
   scp client-v4/artifacts/AZCKeeper_v4_bootstrap_4.0.1.zip azc-root:$DR/public/releases/
   ssh azc-root "chown -R keeper:keeper $DR/public/releases"
   curl -sI https://devkeep.azclegal.com/releases/AZCKeeper_v4_bootstrap_4.0.1.zip   # esperar 200 y Content-Length: 125792159
   ```
   Si da 404, probar `public_html/releases/` (nginx de Hestia sirve .zip desde el docroot) y repetir el curl.

## 2. Equipo de prueba — dejar 4.0.0 con trust (sin re-enrolar)

`--system-update` conserva entorno del servicio, `device-key.dpapi` y token: no hace falta nuevo ticket.
En la shell SYSTEM de DWService, con el ZIP 4.0.0 copiado y extraído en `C:\Temp\k400`:

```powershell
C:\Temp\k400\Keeper.Bootstrapper.exe --system-update --payload C:\Temp\k400\agent
Get-Content C:\ProgramData\AZCKeeper\v4\logs\agent-*.log -Tail 20
Get-Process Keeper.Session -ErrorAction SilentlyContinue   # debe existir si hay un usuario con sesión
```

Esperado en el log: `Agent 4.0.0 started: trust 1 clave(s), sequence 1, canal stable; HKLM enabled; sync configured`
y luego `sync_ok: ...` cada ~120 s. Es la primera vez que `Keeper.Session` corre en el equipo: antes no se
empaquetaba, así que el tracking de actividad/ventanas/presencia NO estaba funcionando.

## 3. Registrar y desplegar la release (API admin, platform admin)

Flujo de sesión: `GET /v1/auth/csrf` → `POST /v1/auth/login` con `X-CSRF-Token` y `Origin: https://devkeep.azclegal.com`
→ usar el csrf_token NUEVO de la respuesta. Todos los POST llevan además `Idempotency-Key: <uuid>`.

1. Registrar (tenant plataforma):
   `POST /v1/releases` con `X-Tenant-ID: 00000000-0000-4000-8000-000000000001` y body = `release-4.0.1.json`.
   Esperado 201. Un 422 aquí casi siempre es la clave pública ausente del `release-keys.json` del servidor.
2. Desplegar al tenant del equipo (Grupo AZC):
   `POST /v1/release-deployments` con `X-Tenant-ID: 00000000-0000-4000-8000-000000000002` y body
   `{"release_id":"a3165c99-173b-4da4-8cf6-dae548dc1cce","ring":"stable","percentage":100,"enabled":true}`.
   Si el tenant no ve la release, repetir primero el mismo deployment con el tenant plataforma (así lo hace `tests/admin.php`).

## 4. Observar el update en el equipo

En menos de 2 syncs el agente debe registrar, en `agent-YYYYMMDD.log`:

```
UpdateManager: descargando release 4.0.1 (sequence 2, 125792159 bytes)
UpdateManager info: package_verified
UpdateManager info: update_applying
UpdateManager: lanzando --system-update para 4.0.1; el servicio se detendra
```

y en `bootstrapper-YYYYMMDD.log` (lo escribe el proceso lanzado, que debe SOBREVIVIR al stop del servicio):

```
Keeper.Bootstrapper 4.0.1.0 pid ...: --system-update --payload
STOP KeeperAgent ... / COPY ... / SC FAILURE ... / DEL ...next-sync.json / START KeeperAgent ...
Actualizacion completada; ...
exit 0
```

Luego el agente arranca de nuevo con: `Agent 4.0.1 started: trust 1 clave(s), sequence 2, canal stable`.
Desde ahí, si el servidor sigue ofreciendo la 4.0.1, el agente la marca `current` sin descargarla.

Comprobaciones en el servidor: access log `/var/log/apache2/domains/devkeep.azclegal.com.log` debe mostrar UN
`GET /releases/AZCKeeper_v4_bootstrap_4.0.1.zip`; `devices.agent_version` del equipo pasa a `4.0.1`.

## Si algo falla

| Síntoma en el log | Causa probable |
|---|---|
| No aparece `descargando` tras varios syncs | La release no se ofrece: deployment ausente/deshabilitado, `release_ring` del device distinto de `stable`, o manifest no verificable (buscar `keeper: release_withheld` en el error log de PHP). |
| `download_failed` | URL no servida o tamaño distinto (revisar el curl del paso 1.3). |
| `release_verification_failed` | ZIP distinto del firmado o clave distinta a la del trust instalado. |
| `update_applying` pero no hay `bootstrapper-*.log` | El bootstrapper no arrancó o murió con el servicio: es exactamente lo que esta prueba debe descartar. |
| Servicio detenido tras el update | Recovery: `C:\Temp\k400\Keeper.Bootstrapper.exe --system-update --payload C:\Temp\k400\agent` restaura 4.0.0. |

## Re-firmar para otra URL (sin recompilar)

```powershell
$k = 'C:\Users\FumiWork\Documents\AZCKeeper-release-keys'
cd C:\Users\FumiWork\Documents\AZCKeeper\client-v4
dotnet run --project tools/Keeper.ReleaseTool -c Release -- sign --key "$k\release-signing-key.dpapi" --public "$k\release-key.public.json" `
  --package artifacts\AZCKeeper_v4_bootstrap_4.0.1.zip --version 4.0.1 --sequence 2 --url <NUEVA_URL_HTTPS> --out artifacts\release-4.0.1.json
```

Cada firma genera un id de release nuevo; registrar el JSON nuevo, no el anterior.
