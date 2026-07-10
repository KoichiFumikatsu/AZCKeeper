# Diseño: Web-blocking por PAC preciso + Ventana Debug enriquecida

- **Fecha:** 2026-07-10
- **Rama:** DevLinux (parte del commit `10f03cc`)
- **Versión objetivo:** próxima release (bump desde 3.0.2.6)
- **Autor:** Koichi + Claude

---

## 1. Contexto y motivación

En 3.0.2.5/3.0.2.6 el web-blocking se reescribió a `URLBlocklist` nativo de navegador
(`HKCU\SOFTWARE\Policies\{Chrome|Edge|Brave}\URLBlocklist`). **Está roto de raíz:** esa
subclave `Policies` es de solo-lectura para el usuario estándar por diseño de Windows, así
que `Registry.CurrentUser.CreateSubKey(...)` lanza `UnauthorizedAccessException` en cada
handshake. El cliente cree que bloquea (cache `Enabled=true`) pero no escribe nada →
**no bloquea, silenciosamente**. Confirmado en vivo (Access Denied) y en fuente
(`BrowserPolicyBlocker.cs:74`).

El mecanismo previo (`<= 3.0.2.4`) era un **PAC per-usuario** (`AutoConfigURL` en HKCU, sin
admin), que **sí funciona sin admin**. Se probó en vivo hoy: bloquea los dominios designados
y deja todo lo demás (incluido `irs.gov`) en `DIRECT`.

**Decisión:** volver al PAC como mecanismo de bloqueo sin admin, pero corrigiendo las causas
históricas de over-block y de cuelgues.

### Causas históricas de over-block (a eliminar por diseño)
1. **`HostsFileBlocker`** (ya borrado en `10f03cc`): bloqueaba por el archivo `hosts`, que
   afecta a **todo el sistema** (Teams/Office/gov), no solo el navegador.
2. **Proxy de sistema global** (pre-PAC): enrutaba toda la navegación por un proxy.
3. **Proxy local vivo** (`LocalWebBlockProxy`, 546 líneas): los dominios bloqueados pasaban
   por un proceso que reenviaba tráfico; si fallaba o cambiaba de puerto, se colgaban.
4. **Puerto efímero**: al reiniciar, `AutoConfigURL` apuntaba a un puerto viejo → PAC muerto
   colgado (bug observado el 2026-07-08).

---

## 2. Objetivos y no-objetivos

### Objetivos
- **Bloqueo por PAC que bloquee SOLO los dominios designados (+ subdominios) y nada más.**
- Sin admin, sin interacción del usuario.
- Robusto: sin cuelgues por PAC muerto; falla-abierto de forma limpia y **visible**.
- Enriquecer la ventana Debug con versión, API, cola/errores y estado de bloqueo/auth, más
  compacta.

### No-objetivos (YAGNI)
- Página de bloqueo / redirección (se eligió agujero negro).
- Firefox y Opera (decisión previa: fuera).
- URLBlocklist vía HKLM con instalador elevado (alternativa robusta *con* admin; se documenta
  como opción futura, no se implementa ahora).
- Contramedidas anti-VPN.

---

## 3. Feature 1 — Web-blocking por PAC preciso

### 3.1 Decisiones fijadas
| Tema | Decisión |
|---|---|
| Alcance del match | Dominio **+ subdominios**, anclado por sufijo exacto. Nada fuera del dominio. |
| Granularidad | Por-petición (host). Un recurso embebido bloqueado falla solo; la página contenedora carga. |
| Modo de bloqueo | **Agujero negro**: `PROXY 127.0.0.1:9` (puerto muerto) → fallo rápido. |
| Servido del PAC | Servidor loopback local que **solo sirve el `.pac`** (no reenvía tráfico). |
| Puerto | **Persistido** (estable entre reinicios); fallback efímero + reescribe `AutoConfigURL`. |
| Al cerrar el cliente | **Cierre limpio quita el `AutoConfigURL`** (evita PAC muerto colgado). Se re-aplica al reabrir (auto-start). |
| Permisos | Solo HKCU. Sin admin. |

### 3.2 Componentes (todos en `AZCKeeper_Client/Blocking/`)

**NUEVO `PacContentBuilder.cs`** (estático)
- `string Build(string[] domains)` — genera el texto del PAC.
- Saneo: `trim`, `ToLowerInvariant`, quitar `*` y `.` iniciales, descartar vacíos, `Distinct`.
- **Validación anti-inyección:** aceptar solo dominios que matcheen `^[a-z0-9.-]+$`; descartar
  el resto (no pueden entrar comillas ni saltos al JS del PAC).
- Matching **sin `shExpMatch`** (evita sorpresas de glob). PAC emitido:
  ```javascript
  function FindProxyForURL(url, host) {
    host = host.toLowerCase();
    if (host.charAt(host.length - 1) == ".") host = host.substring(0, host.length - 1);
    var blocked = ["facebook.com", "x.com" /* ... */];
    for (var i = 0; i < blocked.length; i++) {
      var b = blocked[i];
      if (host == b || host.substr(host.length - b.length - 1) == "." + b) {
        return "PROXY 127.0.0.1:9";
      }
    }
    return "DIRECT";
  }
  ```
- Si `domains` vacío → PAC que retorna siempre `DIRECT` (o no se sirve; ver WebBlockingManager).

**NUEVO `LocalPacServer.cs`** (~70 líneas)
- `TcpListener` en `127.0.0.1:<puerto>`. NO es proxy: cualquier request responde `200` con
  `Content-Type: application/x-ns-proxy-autoconfig` y el texto del PAC actual.
- Puerto: lee `pac_port` de un archivo en el cache dir; intenta ese primero; si ocupado, toma
  efímero y persiste el nuevo. Expone `Port`.
- `StartOrUpdate(pacContent)` (idempotente: si ya corre, solo swap del contenido servido),
  `UpdatePac(pacContent)`, `Stop()`, `IsRunning`.
- Loop de accept en task de fondo; cada conexión se atiende y cierra (`Connection: close`).

**MODIFICA `SystemProxyManager.cs`**
- Recuperar del historial (`10f03cc~1`) y reintegrar:
  - `EnablePac(string pacUrl)`: respalda proxy previo legítimo (`BackupCurrentSettingsIfNeeded`,
    que NO respalda si el AutoConfigURL/ProxyServer ya apunta a `127.0.0.1`), escribe
    `AutoConfigURL`, refresca WinInet.
  - `Restore()`: restaura backup o borra nuestro `AutoConfigURL`; refresca.
- **Quitar** de `WebBlockingManager.Initialize` la llamada a `MigrateAwayFromPac()` (ahora sí
  queremos un PAC; a máquinas viejas se les sobreescribe el `AutoConfigURL`). El método puede
  quedar como muerto o eliminarse; se **elimina** para no confundir.

**REESCRIBE `WebBlockingManager.cs`**
- Reemplaza `BrowserPolicyBlocker` por `PacContentBuilder` + `LocalPacServer` + `SystemProxyManager`.
- `Initialize(config, apiBaseUrl)`: carga cache; si `Enabled` y hay dominios → construir PAC,
  `LocalPacServer.StartOrUpdate`, `SystemProxyManager.EnablePac(http://127.0.0.1:{port}/proxy.pac)`.
  Si no → asegurar `Restore()` (sin PAC).
- `ApplyRemotePolicy(config, version, apiBaseUrl)`:
  - Cambio (version/hash/enabled distinto) → rearmar PAC + `UpdatePac` + `EnablePac`.
  - Sin cambio → `Reassert`: verificar que el server siga vivo y que `AutoConfigURL` siga siendo
    el nuestro (anti-manipulación); re-aplicar si alguien lo tocó.
  - Deshabilitado → `Stop()` server + `Restore()`.
- `Shutdown()`: **`Stop()` server + `Restore()`** (quita el PAC en cierre limpio).
- Expone estado para la ventana Debug: `Enabled`, `DomainCount`, `PacActive`
  (`server.IsRunning && AutoConfigURL == nuestro`), `Port`.

**BORRA `BrowserPolicyBlocker.cs`.**

### 3.3 Tabla de precisión del match (criterio de aceptación)
Dominio designado `facebook.com`:
| host de la petición | resultado |
|---|---|
| `facebook.com` | BLOQUEADO |
| `www.facebook.com`, `m.facebook.com` | BLOQUEADO |
| `notfacebook.com` | DIRECT |
| `facebook.com.evil.com` | DIRECT |
| `fbcdn.net` (CDN de FB, otro dominio) | DIRECT |
| `google.com`, `irs.gov` | DIRECT |

### 3.4 Manejo de errores / fail-open
- Fallo de bind del server → reintentar con puerto efímero; si aun falla → log `Error`, marcar
  `PacActive=false`. Falla-abierto (no bloquea) pero **visible en Debug**.
- Fallo de `EnablePac` → log `Error`, `PacActive=false`.
- Nada tumba al cliente (try/catch por operación).

### 3.5 Migración entre versiones
- Desde 3.0.2.5/3.0.2.6 (era URLBlocklist): el proxy está limpio (no había PAC) → se establece
  el nuestro. Limpieza best-effort del `URLBlocklist` residual (por si algún equipo con admin sí
  llegó a escribirlo): un borrado **inline** una sola vez en la migración —
  `Registry.CurrentUser.DeleteSubKeyTree("SOFTWARE\Policies\{Chrome|Edge|Brave}\URLBlocklist", false)`
  para los 3 navegadores— **sin** depender de `BrowserPolicyBlocker` (que se elimina). Vive en
  `WebBlockingManager` o en un helper estático corto.
- Desde `<= 3.0.2.4` (era PAC efímero): se sobreescribe `AutoConfigURL` con el PAC nuevo (puerto
  estable). El backup previo se respeta vía `BackupCurrentSettingsIfNeeded`.

### 3.6 Pruebas
- **Unit** `PacContentBuilder`: la tabla de 3.3 + saneo (mayúsculas, `*.`, dominio inválido
  descartado, lista vacía → siempre DIRECT).
- **Integración** (script, como hoy): tras aplicar, `[System.Net.WebRequest]::GetSystemWebProxy()`
  resuelve dominios bloqueados → `127.0.0.1:9` y permitidos → DIRECT.
- **Manual**: navegador (facebook no carga; google/gov sí; iframe embebido de facebook roto pero
  página contenedora OK). Reinicio del cliente: PAC re-servido en el mismo puerto. Cierre limpio:
  `AutoConfigURL` desaparece.

---

## 4. Feature 2 — Ventana Debug Activity enriquecida y compacta

### 4.1 Refactor
- Extraer `DebugWindowForm` de `CoreService.cs` (~líneas 1388-1600) a **`Core/DebugWindowForm.cs`**.

### 4.2 Layout
- Rejilla de **2 columnas** agrupada en secciones con encabezados; fuente **monoespaciada**
  (`Consolas ~8.5pt`); **color**: rojo = falla/faltante, verde = OK, gris = neutro.
- El tracking de tiempo actual se conserva, compactado en su propia sección.

### 4.3 Flujo de datos
- `CoreService` (tiene todas las referencias) construye un **`DebugSnapshot`** (struct/clase
  read-only con todos los campos) y pasa a la ventana un `Func<DebugSnapshot>`.
- El timer de 1s de la ventana hace *pull* y refresca labels. Read-only, sin interacción, sin
  impacto de performance. Cada campo con try/catch → si falla, muestra `—` (no rompe la UI).

### 4.4 Secciones y fuentes
1. **Versión + auto-update**: versión corriendo, disponible, mínima, resultado último update.
   Fuentes: `ConfigManager`, `UpdateManager`.
2. **API + conexión**: `ApiBaseUrl`, último handshake (OK / código HTTP / error), circuit-breaker
   (en backoff hasta X). Fuentes: `ConfigManager`, `CoreService` (`_lastHandshakeTime` + resultado),
   `ApiClient` (estado del circuit-breaker del WIP de 2026-07-08).
3. **Cola offline + errores**: pendientes en cola, últimos fallos de envío, y **últimos ~15
   errores/warnings del log en vivo**. Fuentes: `OfflineQueue`, buffer circular nuevo en `LocalLogger`.
4. **Web-blocking + Auth**: `Enabled`, nº dominios, **PAC activo** (server vivo + `AutoConfigURL`
   nuestro), `DeviceId`, usuario, token presente/ausente. Fuentes: `WebBlockingManager`,
   `ConfigManager`, `AuthManager`.

### 4.5 Adición transversal
- **Buffer circular en memoria en `LocalLogger`** de los últimos ~15 `Warn`/`Error`
  (mensaje + timestamp + nivel), thread-safe. Alimenta el panel de errores. Pequeño; no toca
  el logging a archivo/Discord existente.

### 4.6 Errores / pruebas
- El `DebugSnapshot` builder es testeable por separado (dado un estado simulado, produce los
  campos correctos).
- La UI en sí es manual: abrir la ventana (config `EnableDebugWindow=true`), verificar que las 4
  secciones pueblan, que un handshake fallido / cola con pendientes / PAC inactivo salen en rojo.

---

## 5. Riesgos y mitigaciones
| Riesgo | Mitigación |
|---|---|
| PAC muerto colgando WinInet | Puerto estable + re-servir en el mismo al reiniciar; cierre limpio quita `AutoConfigURL`. |
| Usuario mata el cliente (`taskkill /F`) | No corre `Shutdown` → PAC queda pero el server muere → falla-abierto. El auto-start relanza y re-sirve en el mismo puerto (ventana de cuelgue mínima). Aceptado (es software de monitoreo). |
| Proxy corporativo legítimo previo | `BackupCurrentSettingsIfNeeded` lo respalda; `Restore()` lo devuelve. |
| Inyección vía nombre de dominio en el PAC | Validación `^[a-z0-9.-]+$`; se descarta lo demás. |
| Debug window con dato faltante rompe UI | try/catch por campo → muestra `—`. |

## 6. Plan de pruebas end-to-end (desktop)
1. Build (`./build-release.sh` o Windows) de la próxima versión.
2. Instalar; verificar en `GetSystemWebProxy` que bloqueados→`127.0.0.1:9`, permitidos→DIRECT.
3. Navegador: facebook/instagram/netflix/x no cargan; google + páginas gov sí; iframe embebido
   roto sin romper la página.
4. Reiniciar cliente → PAC re-servido, mismo puerto, sin cuelgue.
5. Cierre limpio → `AutoConfigURL` desaparece.
6. Ventana Debug: 4 secciones pueblan; fallas en rojo.
7. (Opcional) auto-update desde 3.0.2.6 → esta versión.

## 7. Alternativa futura (no en este alcance)
URLBlocklist en **HKLM** escrito por el instalador elevado (que ya corre como SYSTEM) + tarea
programada SYSTEM que re-aplica desde el cache del cliente. Da persistencia sin proceso vivo y
sobrevive a VPN/DoH, a costa de requerir admin una vez en la instalación.
