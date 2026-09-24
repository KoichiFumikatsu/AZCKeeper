# AZCKeeper v4 — Plan de pruebas + requerimientos (piloto controlado)

> Objetivo: validar en equipos reales lo que no se puede probar en la máquina de desarrollo — enforcement
> real (HKLM), servicio SYSTEM, y la entrega **desde K3** con una elevación (UAC "Sí") por equipo.
> Contexto: K3 queda **instalado y corriendo**; v4 se entrega como actualización que el usuario acepta.
> Fecha: 2026-09-22.

---

## 0. Dos bloqueadores a resolver ANTES de tocar un equipo

| # | Hecho verificado | Consecuencia | Qué hay que construir |
|---|---|---|---|
| B1 | El updater de K3 corre en **integridad media** (asInvoker, sin `runas`) — no eleva | El "Sí" de UAC **no aparece solo** al traer v4 desde K3 | El **bootstrapper v4** debe disparar su propia elevación (`ShellExecute verb=runas`). Como el usuario es admin, es un **consentimiento** (clic Sí), no contraseña |
| B2 | El **bootstrapper v4 no existe** (Fase 1 = servicio+enforcers+sync, sin instalador) | No hay artefacto que K3 pueda entregar ni que instale el servicio | Construir `Keeper.Bootstrapper` + empaquetado self-contained + registro del servicio |

**Sin B1 y B2 no arranca el piloto.** El plan de abajo asume que se construyen primero (Etapa 0).

---

## 1. Requerimientos (prerequisitos del piloto)

### 1.1 Equipos
- **2–3 equipos piloto**, Windows 11 **Pro**, representativos de la flota (usuario = **admin local**, como el resto).
- **K3 instalado y corriendo** en ellos (es el estado de partida).
- Uno de ellos debe ser **desechable/reversible** (snapshot o equipo de repuesto) para la Etapa 0.

### 1.2 Backend v4 alcanzable
- Backend `backend-v4/` desplegado en un servidor de prueba con **HTTPS real** (dominio + certificado), porque el agente firma y usa bearer sobre TLS. [Probable] `devkeep.azclegal.com` o un subdominio nuevo sirve.
- BD MySQL migrada (`run.php`) + un **tenant/usuario/dispositivo de prueba** provisionado, con la clave pública del equipo registrada en `device_keys` durante el enrolamiento.
- **Nota**: el arnés de integración probó HTTP local, **no TLS**. La primera vez que el agente hable por HTTPS real es en este piloto — vigilar certificados/SNI/proxy.

### 1.3 Artefactos v4 (a construir en Etapa 0)
- **Agente self-contained** (`dotnet publish --self-contained`, win-x64) — la flota **no** tiene garantizado el runtime .NET; el paquete debe traerlo (igual que K3).
- **Bootstrapper** que, elevado, instale el servicio (`sc create` / `New-Service`), cree `%ProgramData%\AZCKeeper`, configure autoarranque y apunte el agente al backend de prueba.
- **Firma de código**: [Suposición] para un piloto interno en equipos propios **no es estrictamente necesaria**, pero SmartScreen mostrará advertencia al elevar un `.exe` sin firmar. Si molesta, firmar con un cert OV/EV. Registrar la decisión.

### 1.4 Canal de entrega desde K3
- K3 baja el ZIP de `keeper_client_releases.download_url` (GitHub Releases) y lo ejecuta. Para el piloto:
  - Publicar el **bootstrapper v4** como una release que K3 pueda jalar.
  - **Rollout selectivo** (patrón ya usado en K3): desactivar `EnableAutoUpdate`/`AutoDownload` para toda la flota **excepto los equipos piloto**, para que solo ellos lo traigan.
- [Seguro] Recordatorio de seguridad: el updater de K3 **no verifica firma del ZIP** (crítico de la auditoría). Entregar por ahí ejecuta lo que pongamos como el usuario. Para el piloto controlado es aceptable; en producción, firmar el updater es prerequisito.

### 1.5 Reversibilidad (obligatorio antes de instalar)
- **Snapshot** o imagen del equipo piloto.
- **Desinstalador documentado**: detener y `sc delete` el servicio, borrar `%ProgramData%\AZCKeeper`, limpiar las claves HKLM que escriban los enforcers, y (si se degradó al usuario) restaurar su membresía de administradores.
- **Break-glass**: dejar la cuenta `Administrator` integrada operativa por si algo bloquea el acceso.

---

## 2. Canal de instalación — DWService SYSTEM (primario) vs K3+UAC (fallback)

Decisión (2026-09-23): el canal **primario** es el **shell SYSTEM de DWService**, que ya corre elevado en la
flota (registrado como canal de IT: `sc create` como SYSTEM). Desde ahí se instala **sin UAC** con el
bootstrapper en modo `--system-install`. K3+UAC queda como **fallback** para equipos sin DWService.

- **Verificar el canal antes**: correr `client-v4/tools/verificar-dwservice.{ps1,cmd}` **dentro del shell de
  DWService** que se usará. Hace pruebas privilegiadas reales (escribe HKLM + crea servicio, se limpian) y da
  veredicto **APTO** (elevado → sirve sin UAC) o **NO APTO** (token medio → usar shell SYSTEM o fallback K3).
  Trampa: DWService puede dar un shell de *usuario* aunque su agente sea SYSTEM — probar el shell exacto.
- **Instalar (primario)**: en el shell SYSTEM de DWService → `Keeper.Bootstrapper --system-install` (no interactivo,
  sin UAC; aborta con `ERROR_ELEVATION_REQUIRED` si no está realmente elevado).
- **Instalar (fallback K3)**: el bootstrapper por defecto se auto-eleva (UAC "Sí") — ver §2.1.
- **Costo real** (no es UAC): DWService es **por equipo, manual** (~intervención individual por máquina). Para la
  flota completa hay que ver si DWService permite ejecución en lote; para el piloto es trivial.

### 2.1 Fallback: cómo "se trae desde K3" con el Sí

```
K3 (medio) --auto-update--> baja ZIP bootstrapper v4 --ejecuta--> Bootstrapper (medio)
   Bootstrapper detecta que necesita SYSTEM --> ShellExecute verb=runas --> UAC "¿Permitir?" [Sí]
   --> Bootstrapper (elevado): instala servicio SYSTEM + ProgramData + autoarranque + apunta al backend
   --> a partir de aquí el servicio corre como SYSTEM y se auto-actualiza sin más prompts
```
- El **único** prompt en la vida del equipo es ese "Sí". IT puede darlo por sesión remota si el usuario no está.
- **Piloto = SIN degradar al usuario todavía.** azcadmin/LAPS/degradación a estándar es una etapa **posterior y separada** (Etapa 5), porque rompe flujos de usuario y no debe mezclarse con la validación del enforcement.

---

## 3. Plan por etapas

Cada etapa: **hacer → esperado → verificar → rollback**. No avanzar de etapa si la anterior no cierra.

### Etapa 0 — Bootstrapper e instalación MANUAL (1 equipo desechable, sin K3)
Prueba el instalador aislado del canal K3.
- **Hacer**: copiar el paquete v4 al equipo, correr el bootstrapper a mano (aceptar UAC).
- **Esperado**: servicio `KeeperAgent` en estado *Running* como *LocalSystem*; `%ProgramData%\AZCKeeper` creado; agente conecta al backend.
- **Verificar**:
  - `Get-Service KeeperAgent` → Running; `sc qc KeeperAgent` → `SERVICE_START_NAME: LocalSystem`.
  - Log del agente + tabla `device_sessions`/`device_sync_state` en el backend muestran el device.
  - Un `/client/sync` exitoso (política entregada) en los logs del backend.
- **Rollback**: desinstalador (§1.5).

### Etapa 1 — Entrega desde K3 + elevación (equipo piloto con K3)
- **Hacer**: registrar la release v4 en el panel K3; activar auto-update **solo** para este equipo; esperar/forzar el ciclo de update de K3.
- **Esperado**: K3 baja y ejecuta el bootstrapper; aparece **un** prompt UAC; al dar Sí, queda el servicio instalado como en Etapa 0. **K3 sigue corriendo** (no se desinstala en el piloto; conviven).
- **Verificar**: mismo checklist de Etapa 0 + confirmar que el disparo vino del updater de K3 (log de K3).
- **Riesgo a observar**: que K3 en integridad media logre lanzar el bootstrapper y que el UAC aparezca. Si **no** aparece el prompt, B1 no está bien resuelto.
- **Rollback**: desinstalador; K3 permanece.

### Etapa 2 — Enforcement web (HKLM)
- **Hacer**: en el panel, activar bloqueo de dominios (p. ej. `facebook.com`) para ese device; esperar el sync.
- **Esperado**: el servicio escribe `HKLM\SOFTWARE\Policies\{Google\Chrome,Microsoft\Edge,BraveSoftware\Brave}\URLBlocklist`.
- **Verificar**:
  - `reg query "HKLM\SOFTWARE\Policies\Google\Chrome\URLBlocklist"` muestra el dominio.
  - `chrome://policy` / `edge://policy` → URLBlocklist con el dominio; navegar al dominio → **bloqueado**; otro sitio → navega.
  - Firefox (si aplica): `policies.json`.
- **Rollback**: desactivar en panel → sync → verificar que la clave se limpia y el dominio vuelve a cargar.

### Etapa 3 — Enforcement USB + comandos remotos
- **Hacer**: activar bloqueo/solo-lectura de USB; luego encolar un comando remoto (bloquear equipo) desde el panel.
- **Esperado**: `HKLM\...\RemovableStorageDevices` aplicado; el comando llega y se ejecuta; resultado vuelve a `device_command_results`.
- **Verificar**: insertar un USB → denegado/solo-lectura; el comando de bloqueo surte efecto y queda auditado en `audit_log`.
- **Rollback**: desactivar; verificar reversión.

### Etapa 4 — Resiliencia (red y reinicio)
- **Hacer**: reiniciar el equipo; desconectar de la red de oficina (simular remoto); dejarlo un rato.
- **Esperado**: el servicio arranca solo tras reboot; la política cacheada **sigue aplicada sin red**; al reconectar, retoma sync sin tormenta (medir req/min).
- **Verificar**: servicio Running tras reboot; bloqueo web sigue activo offline; contar requests al backend por minuto (objetivo ~0,5/equipo).

### Etapa 5 — (SEPARADA, posterior) Endurecimiento: azcadmin + degradación
No hacer hasta que 0–4 estén verdes en los 2–3 equipos.
- **Hacer**: correr el paso de endurecimiento: crear `azcadmin` (contraseña **única por equipo**, reportada a Keeper tipo LAPS), verificar que funciona, degradar al usuario a estándar, ocultar azcadmin.
- **Esperado**: el usuario pasa a estándar en el próximo login; azcadmin oculto y gestionado; el enforcement ahora es **irreversible por el usuario**.
- **Verificar**: `Get-LocalGroupMember Administradores` ya no lista al usuario; azcadmin existe y su clave está en Keeper; el usuario no puede parar el servicio.
- **Rollback**: restaurar membresía admin del usuario desde azcadmin/break-glass.
- **Barandas** (§ ya definidas): crear/verificar azcadmin **antes** de degradar; nunca degradar el último admin.

---

## 4. Criterios de salida del piloto
- Etapas 0–4 verdes en los 2–3 equipos.
- Bloqueo web/USB aplican y revierten desde el panel.
- El servicio sobrevive reboot y aplica política offline.
- Tráfico medido ≈ objetivo (~0,5 req/min/equipo), sin ban por IP.
- TLS real funciona (sin errores de certificado/proxy).
- Desinstalador probado (deja el equipo limpio).
- **Solo entonces** planear Etapa 5 y el rollout a más equipos.

## 5. Qué NO cubre este piloto
- Firma del updater en producción (crítico auditoría) — pendiente aparte.
- Rollout masivo (los 1000) — se define tras el piloto.
- Portal cliente / API externa consumida por terceros.
